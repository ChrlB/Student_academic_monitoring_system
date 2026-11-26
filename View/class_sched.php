<?php
require_once __DIR__ . "/../PageLogic/class_sched_logic.php";

$logic = new ScheduleLogic();
$logic->handlePost(); // handles create/update, then redirects (must run before any output)

function sched_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function sched_icon($name)
{
    $p = [
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'book'     => '<path d="M6 4h11a2 2 0 0 1 2 2v14H8a2 2 0 0 1-2-2z"/><path d="M6 18a2 2 0 0 1 2-2h11"/><path d="M10 8h5"/>',
        'pencil'   => '<path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/>',
        'search'   => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.2-4.2"/>',
        'right'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'left'     => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
        'laptop'   => '<rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M3 19h18"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
        'warn'     => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/>',
        'trash'    => '<path d="M5 7h14M10 11v6M14 11v6M8 7l1-3h6l1 3M7 7l1 13h8l1-13"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
    return '<svg class="sp-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($p[$name] ?? '') . '</svg>';
}

$self = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
function sched_url($q)
{
    global $self;
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return $self . ($q ? '?' . http_build_query($q) : '');
}

function sched_time_list($v)
{
    $times = [];
    for ($m = 7 * 60; $m <= 21 * 60; $m += 30) {
        $times[sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60)] = date('g:i A', mktime(intdiv($m, 60), $m % 60));
    }
    $start = !empty($v['start_time']) ? substr($v['start_time'] . ':00', 0, 8) : '';
    $end   = !empty($v['end_time'])   ? substr($v['end_time'] . ':00', 0, 8)   : '';
    foreach ([$start, $end] as $t) {
        if ($t !== '' && !isset($times[$t])) $times[$t] = ScheduleLogic::fmtTime($t);
    }
    ksort($times);
    return [$times, $start, $end];
}

function sched_status_tag($status)
{
    $labels = ['draft' => 'Draft', 'assigned' => 'Assigned', 'deployed' => 'Deployed'];
    return '<span class="sp-tag sp-tag--' . sched_e($status) . '">' . sched_e($labels[$status] ?? $status) . '</span>';
}

function sched_status_form($action, $sched, $csrf, $semKey, $label, $icon, $back, $class)
{
    return '<form method="post" action="' . sched_e(sched_url(['sem' => $semKey])) . '" class="sched-inline">'
        . '<input type="hidden" name="csrf" value="' . sched_e($csrf) . '">'
        . '<input type="hidden" name="sched_action" value="' . sched_e($action) . '">'
        . '<input type="hidden" name="schedID" value="' . (int)$sched['schedID'] . '">'
        . '<input type="hidden" name="semester" value="' . sched_e($semKey) . '">'
        . '<input type="hidden" name="back" value="' . sched_e($back) . '">'
        . '<button type="submit" class="' . sched_e($class) . '">' . sched_icon($icon) . ' ' . sched_e($label) . '</button></form>';
}

// Step 1: program, room, days, time.
function sched_form_step1($uid, $programs, $rooms, $v, $lockProgram = false)
{
    list($times, $startSel, $endSel) = sched_time_list($v);
    $days = (array)($v['days'] ?? []);
    $prog = (string)($v['program'] ?? '');
    $delivery = ($v['delivery'] ?? 'onsite') === 'blended' ? 'blended' : 'onsite';
    $weeks    = ($v['onsite_weeks'] ?? 'odd') === 'even' ? 'even' : 'odd';
    ob_start(); ?>
    <div class="sp-field">
      <label for="<?= $uid ?>-program">Program <span class="sp-req">*</span></label>
      <?php if ($lockProgram): ?>
        <input type="hidden" name="program" value="<?= sched_e($prog) ?>">
        <select id="<?= $uid ?>-program" disabled><option><?= sched_e($prog) ?></option></select>
      <?php else: ?>
        <select id="<?= $uid ?>-program" name="program" required>
          <option value="">Select program</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= sched_e($p['program']) ?>" <?= $prog === $p['program'] ? 'selected' : '' ?>><?= sched_e($p['program']) ?> — <?= sched_e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>

    <div class="sp-field">
      <label for="<?= $uid ?>-room">Room <span class="sp-req">*</span></label>
      <select id="<?= $uid ?>-room" name="room" required>
        <option value="">Select room</option>
        <?php foreach ($rooms as $r):
            $off = strtolower(trim((string)($r['status'] ?? 'available'))) !== 'available';
            $cur = (string)($v['room'] ?? '') === (string)$r['roomID']; ?>
          <option value="<?= sched_e($r['roomID']) ?>" <?= $cur ? 'selected' : '' ?> <?= ($off && !$cur) ? 'disabled' : '' ?>>Room <?= sched_e($r['roomID']) ?><?= $off ? ' (' . sched_e(trim((string)$r['status'])) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <fieldset class="sp-field sp-days">
      <legend class="sp-label">Day(s) <span class="sp-req">*</span></legend>
      <div class="sp-checks">
        <?php foreach (ScheduleLogic::WEEK as $d): ?>
          <label class="sp-check"><input type="checkbox" name="days[]" value="<?= $d ?>" <?= in_array($d, $days, true) ? 'checked' : '' ?>> <span><?= substr($d, 0, 3) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="sp-field">
      <span class="sp-label">Time <span class="sp-req">*</span></span>
      <div class="sp-time">
        <select name="start_time" aria-label="Start time" required>
          <option value="">Start time</option>
          <?php foreach ($times as $val => $lab):
              if ($val >= ScheduleLogic::CLOSE_TIME) continue;
              $lunch = $val >= ScheduleLogic::LUNCH_START && $val < ScheduleLogic::LUNCH_END; ?>
            <option value="<?= $val ?>" <?= $startSel === $val ? 'selected' : '' ?> <?= $lunch ? 'disabled' : '' ?>><?= $lab ?><?= $lunch ? ' (lunch break)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <span>to</span>
        <select name="end_time" aria-label="End time" required>
          <option value="">End time</option>
          <?php foreach ($times as $val => $lab):
              if ($val <= ScheduleLogic::OPEN_TIME) continue;
              $lunch = $val > ScheduleLogic::LUNCH_START && $val <= ScheduleLogic::LUNCH_END; ?>
            <option value="<?= $val ?>" <?= $endSel === $val ? 'selected' : '' ?> <?= $lunch ? 'disabled' : '' ?>><?= $lab ?><?= $lunch ? ' (lunch break)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="sp-hint">Classes run from 7:00 AM to 9:00 PM. 12:00 – 1:00 PM is the lunch break, and an instructor needs at least 30 minutes between classes.</p>
    </div>
    <div class="sp-field">
      <span class="sp-label">Class Format</span>
      <div>
        <div class="sp-radios" role="radiogroup" aria-label="Class format">
          <label class="sp-radio"><input type="radio" name="delivery" value="onsite" <?= $delivery === 'onsite' ? 'checked' : '' ?>> <span>FULL ONSITE</span></label>
          <label class="sp-radio"><input type="radio" name="delivery" value="blended" <?= $delivery === 'blended' ? 'checked' : '' ?>> <span>ALTERNATING ONLINE / ONSITE</span></label>
        </div>
        <div class="sp-weeks" data-weeks>
          <label for="<?= $uid ?>-weeks">Onsite on</label>
          <select id="<?= $uid ?>-weeks" name="onsite_weeks">
            <option value="odd" <?= $weeks === 'odd' ? 'selected' : '' ?>>Weeks 1, 3, 5,...</option>
            <option value="even" <?= $weeks === 'even' ? 'selected' : '' ?>>Weeks 2, 4, 6,...</option>
          </select>
        </div>
        <p class="sp-hint">Applies to the whole semester. The room of an alternating class is free on its online weeks, so another alternating class on the opposite weeks can use it.</p>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// Step 2: block, subject, instructor (all optional until the dean is ready to assign).
function sched_form_step2($uid, $blocks, $subjects, $instructors, $v)
{
    $type = $v['class_type'] ?? 'LEC';
    $kind = $v['subject_kind'] ?? null;
    if ($kind === null) {
        $kind = 'normal';
        foreach ($subjects as $s) {
            if (($v['subject'] ?? null) === $s['subjectID']) { $kind = $s['kind']; break; }
        }
    }
    ob_start(); ?>
    <div class="sp-field">
      <label for="<?= $uid ?>-block">Block / Section</label>
      <select id="<?= $uid ?>-block" name="block">
        <option value="">No block yet</option>
        <?php foreach ($blocks as $b): ?>
          <option value="<?= sched_e($b['value']) ?>" <?= ($v['block'] ?? '') === $b['value'] ? 'selected' : '' ?>><?= sched_e($b['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sp-field">
      <span class="sp-label">Class Type</span>
      <div class="sp-radios" role="radiogroup" aria-label="Class type">
        <label class="sp-radio"><input type="radio" name="class_type" value="LEC" <?= $type === 'LEC' ? 'checked' : '' ?>> <span>LECTURE (LEC)</span></label>
        <label class="sp-radio"><input type="radio" name="class_type" value="LAB" <?= $type === 'LAB' ? 'checked' : '' ?>> <span>LABORATORY (LAB)</span></label>
        <label class="sp-radio"><input type="radio" name="class_type" value="GEC" <?= $type === 'GEC' ? 'checked' : '' ?>> <span>GENERAL EDUCATION (GEC)</span></label>
      </div>
    </div>

    <div class="sp-field">
      <span class="sp-label">Subject Category</span>
      <div class="sp-radios" role="radiogroup" aria-label="Subject category">
        <label class="sp-radio"><input type="radio" name="subject_kind" value="normal" <?= $kind === 'normal' ? 'checked' : '' ?>> <span>NORMAL SUBJECT</span></label>
        <label class="sp-radio"><input type="radio" name="subject_kind" value="prereq" <?= $kind === 'prereq' ? 'checked' : '' ?>> <span>PREREQUISITE SUBJECT</span></label>
      </div>
    </div>

    <div class="sp-field">
      <label for="<?= $uid ?>-subject">Subject</label>
      <select id="<?= $uid ?>-subject" name="subject">
        <option value="">No subject yet</option>
        <?php foreach ($subjects as $s): ?>
          <option value="<?= sched_e($s['subjectID']) ?>" data-type="<?= sched_e($s['type']) ?>" data-kind="<?= sched_e($s['kind']) ?>" data-programs="<?= sched_e($s['programs'] ? implode(',', $s['programs']) : '*') ?>"
            <?= ($v['subject'] ?? null) === $s['subjectID'] ? 'selected' : '' ?>>
            <?= sched_e(trim($s['subjectID']) . ' — ' . $s['name'] . ($s['requires'] ? ' (requires ' . $s['requires'] . ')' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sp-field">
      <label for="<?= $uid ?>-instructor">Instructor</label>
      <select id="<?= $uid ?>-instructor" name="instructor">
        <option value="">No instructor yet</option>
        <?php foreach ($instructors as $i): ?>
          <option value="<?= (int)$i['facultyID'] ?>" <?= (int)($v['instructor'] ?? 0) === (int)$i['facultyID'] ? 'selected' : '' ?>>
            <?= sched_e(ScheduleLogic::properName($i['first_name'] . ' ' . $i['last_name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
    return ob_get_clean();
}

$group     = $logic->group;
$isDean    = $logic->isDean();
$isCoord   = $logic->isCoordinator();
$canManage = $logic->canManage();
$semesters = $logic->semesters();
$semKey    = $logic->selectedSemester($_GET['sem'] ?? '');
$view      = $_GET['view'] ?? 'list';
$flash     = $logic->takeFlash();
$old       = $logic->takeOld();
$csrf      = $logic->csrf();

$sched = null;
if ($view === 'class' || $view === 'edit') {
    $sched = $isDean ? $logic->find((int)($_GET['id'] ?? 0)) : null;
    if (!$sched || !$logic->canAccess($sched) || ($view === 'edit' && !$canManage)) {
        $view = 'list';
        $sched = null;
    } else {
        $semKey = $sched['acad_year'] . '|' . $sched['sem'];
    }
}

$dayName = ucfirst(strtolower($_GET['d'] ?? ''));
if ($view === 'day' && ($isDean || !in_array($dayName, ScheduleLogic::WEEK, true))) {
    $view = 'list';
}
if ($view === 'availability' && !$isDean) {
    $view = 'list';
}

$search = $isDean ? '' : trim((string)($_GET['q'] ?? ''));
$programs = $isDean ? $logic->programs() : [];
$fl = ['program' => '', 'block' => '', 'status' => '', 'instructor' => ''];
if ($isDean && $view === 'list') $fl = $logic->deanFilters($_GET, $semKey);
$filterActive = implode('', $fl) !== '';
$scopeLabel = $isDean ? $logic->assignedDepartment() : ($isCoord ? $logic->assignedProgram() : '');

// Coordinator search: blocks and instructors of the assigned program.
$progLabel   = $isCoord ? $logic->assignedProgram() : '';
$monitoring  = $isCoord && $logic->isMonitoring($search);
$searchPlaceholder = 'Search subject, room or instructor';
$searchHint  = '';
if ($isCoord) {
    if ($progLabel !== '') {
        $searchPlaceholder = "Search a block (e.g., $progLabel 1A) or an instructor of $progLabel";
        $searchHint = "Type a block (for example “$progLabel 1A” or just “1A”) or the name of an instructor assigned to $progLabel to monitor the program’s deployed schedule. Clear the search to see your own classes again.";
    } else {
        $searchPlaceholder = 'No program is assigned to your account';
        $searchHint = 'Your account has no assigned program yet, so only your own classes can be shown.';
    }
}
$rows = ($view === 'list' || $view === 'day') ? $logic->schedules($semKey, $search, $fl) : [];
$week   = $logic->week($rows);

$needForm = $canManage && ($view === 'list' || $view === 'edit');
$blocks = $subjects = $instructors = $rooms = [];
$filterBlocks = $filterInstructors = [];
$statusCounts = [];
if ($isDean && $view === 'list') {
    $filterBlocks      = $logic->filterBlocks();
    $filterInstructors = $logic->scheduleInstructors($semKey);
    $statusCounts      = $logic->statusCounts($semKey, $fl);
}

// Weekly hours of the filtered block (dean list).
$hoursReport = null;
if ($isDean && $view === 'list' && $fl['block'] !== '' && $fl['block'] !== 'none') {
    $bp = explode('|', $fl['block'], 2);
    $hoursReport = $logic->blockHours($semKey, $bp[0], $bp[1] ?? '');
}

// Room / instructor availability (dean).
$av = null; $avFree = null; $avKind = 'rooms'; $avDay = 'Monday'; $avFrom = ''; $avTo = '';
if ($isDean && $view === 'availability') {
    $avKind = (($_GET['t'] ?? '') === 'instructors') ? 'instructors' : 'rooms';
    $avDay  = ucfirst(strtolower((string)($_GET['d'] ?? 'Monday')));
    if (!in_array($avDay, ScheduleLogic::WEEK, true)) $avDay = 'Monday';
    $av = $logic->availability($semKey, $avDay, $avKind);
    $f = (string)($_GET['from'] ?? ''); $t = (string)($_GET['to'] ?? '');
    if (preg_match('/^\d\d:\d\d:00$/', $f) && preg_match('/^\d\d:\d\d:00$/', $t)
        && $f >= ScheduleLogic::OPEN_TIME && $t <= ScheduleLogic::CLOSE_TIME && $f < $t) {
        $avFrom = $f; $avTo = $t;
        $avFree = $logic->freeFor($av, $avFrom, $avTo, $avKind);
    }
}
if ($needForm) {
    $blocks      = $logic->blocks();
    $subjects    = $logic->subjects();
    $instructors = $logic->instructors();
    $rooms       = $logic->rooms();
}
$semLabel = '';
foreach ($semesters as $s) { if ($s['key'] === $semKey) $semLabel = $s['label']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | Schedule</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/schedule.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
</head>
<body>
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="sched-page">
        <section class="sched-panel">

          <?php if ($flash && $flash['type'] !== 'conflict' && !isset($_GET['add'])): ?>
            <div class="sched-flash sched-flash--<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" role="alert">
              <?php foreach ($flash['msgs'] as $m): ?><p><?= sched_e($m) ?></p><?php endforeach; ?>
            </div>
          <?php endif; ?>

<?php /* =============================== MANAGER (DEAN): LIST =============================== */ ?>
<?php if ($group === 'none'): ?>
          <h2 class="sched-title">Schedule</h2>
          <p class="sched-empty">The schedule is not available for your role.</p>

<?php elseif ($isDean && $view === 'list'): ?>
          <div class="sched-toolbar">
            <h2 class="sched-title">Teaching Schedule<?php if ($scopeLabel !== ''): ?> - <?= sched_e($scopeLabel) ?><?php endif; ?></h2>
            <form class="sched-sem" method="get" action="<?= sched_e($self) ?>">
              <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= sched_e($search) ?>"><?php endif; ?>
              <?php if ($isDean): ?>
                <input type="hidden" name="f_program" value="<?= sched_e($fl['program']) ?>">
                <input type="hidden" name="f_block" value="<?= sched_e($fl['block']) ?>">
                <input type="hidden" name="f_status" value="<?= sched_e($fl['status']) ?>">
                <input type="hidden" name="f_instructor" value="<?= sched_e($fl['instructor']) ?>">
              <?php endif; ?>
              <select name="sem" aria-label="Semester" onchange="this.form.submit()">
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= $s['key'] === $semKey ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <noscript><button class="sp-btn" type="submit">Go</button></noscript>
            </form>
            <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'availability', 'sem' => $semKey])) ?>"><?= sched_icon('clock') ?> Availability</a>
            <?php if ($canManage): ?>
              <button type="button" class="sp-btn sp-btn--primary" data-open-add><?= sched_icon('plus') ?> Add Schedule</button>
            <?php endif; ?>
          </div>

          <?php if ($isDean): ?>
          <form class="sched-filters" id="dean-filters" method="get" action="<?= sched_e($self) ?>" aria-label="Filter schedules">
            <input type="hidden" name="sem" value="<?= sched_e($semKey) ?>">
            <div class="sched-filter">
              <label for="f-program">Program</label>
              <select id="f-program" name="f_program">
                <option value="">All programs</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?= sched_e($p['program']) ?>" <?= $fl['program'] === $p['program'] ? 'selected' : '' ?>><?= sched_e($p['program']) ?> — <?= sched_e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sched-filter">
              <label for="f-block">Block</label>
              <select id="f-block" name="f_block">
                <option value="">All blocks</option>
                <?php foreach ($filterBlocks as $b): ?>
                  <option value="<?= sched_e($b['value']) ?>" data-program="<?= sched_e($b['program']) ?>" <?= $fl['block'] === $b['value'] ? 'selected' : '' ?>><?= sched_e($b['label']) ?></option>
                <?php endforeach; ?>
                <option value="none" <?= $fl['block'] === 'none' ? 'selected' : '' ?>>No block yet</option>
              </select>
            </div>
            <div class="sched-filter">
              <label for="f-status">Status</label>
              <select id="f-status" name="f_status">
                <option value="">All statuses</option>
                <?php foreach (ScheduleLogic::STATUSES as $k => $lab): ?>
                  <option value="<?= $k ?>" <?= $fl['status'] === $k ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sched-filter">
              <label for="f-instructor">Instructor</label>
              <select id="f-instructor" name="f_instructor">
                <option value="">All instructors</option>
                <?php foreach ($filterInstructors as $i): ?>
                  <option value="<?= (int)$i['facultyID'] ?>" <?= $fl['instructor'] === (string)(int)$i['facultyID'] ? 'selected' : '' ?>><?= sched_e(ScheduleLogic::properName($i['last_name'] . ', ' . $i['first_name'])) ?></option>
                <?php endforeach; ?>
                <option value="none" <?= $fl['instructor'] === 'none' ? 'selected' : '' ?>>No instructor yet</option>
              </select>
            </div>
            <?php if ($filterActive): ?>
              <a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey, 'reset' => 1])) ?>"><?= sched_icon('close') ?> Reset filters</a>
            <?php endif; ?>
            <noscript><button type="submit" class="sp-btn sp-btn--primary">Apply</button></noscript>
          </form>
          <p class="sched-summary" role="status">
            <strong><?= count($rows) ?> class<?= count($rows) === 1 ? '' : 'es' ?></strong>
            <?= $filterActive ? 'match the filters' : 'in ' . sched_e($semLabel ?: 'this semester') ?>
            <span class="sched-summary__tags">
              <?php foreach (ScheduleLogic::STATUSES as $k => $lab): ?>
                <span class="sp-tag sp-tag--<?= $k ?>"><?= $lab ?>: <?= (int)($statusCounts[$k] ?? 0) ?></span>
              <?php endforeach; ?>
            </span>
          </p>
          <?php if ($hoursReport !== null): ?>
            <section class="sched-hours" aria-label="Weekly hours of the block">
              <h3 class="sched-hours__title">Weekly hours — <?= sched_e(str_replace('|', ' ', $fl['block'])) ?></h3>
              <p class="sp-hint">Every lecture and laboratory subject needs exactly 4 hours per week (100%). The hours can be spread over different days (for example 2 hrs Monday + 2 hrs Wednesday, or 1 hr on four days). A subject can only be deployed when it reaches 100%.</p>
              <?php if (!$hoursReport): ?>
                <p class="sched-empty">No subject has been scheduled for this block yet.</p>
              <?php else: ?>
                <ul class="hours-list">
                  <?php foreach ($hoursReport as $h): $done = abs($h['scheduled'] - $h['required']) < 0.001; ?>
                    <li class="hours-row">
                      <span class="hours-row__name"><strong><?= sched_e($h['subject']) ?></strong> <span class="sp-tag"><?= sched_e($h['type']) ?></span><small class="hours-row__days"><?= sched_e(ScheduleLogic::fmtDayHours($h['by_day'])) ?></small></span>
                      <span class="hours-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, $h['pct']) ?>"><span class="<?= $h['scheduled'] > $h['required'] ? 'is-over' : ($done ? 'is-full' : '') ?>" style="width: <?= min(100, $h['pct']) ?>%"></span></span>
                      <span class="hours-row__val"><?= ScheduleLogic::fmtHours($h['scheduled']) ?> / <?= ScheduleLogic::fmtHours($h['required']) ?> hrs · <?= $h['pct'] ?>%</span>
                      <?php if ($done): ?><span class="sp-tag sp-tag--deployed">Complete</span>
                      <?php elseif ($h['scheduled'] > $h['required']): ?><span class="sp-tag sp-tag--assigned">Over by <?= ScheduleLogic::fmtHours($h['scheduled'] - $h['required']) ?> hr</span>
                      <?php else: ?><span class="sp-tag sp-tag--draft">Needs <?= ScheduleLogic::fmtHours($h['left']) ?> hr</span><?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          <?php endif; ?>
          <?php endif; ?>

          <?php if (!$rows): ?>
            <p class="sched-empty">
              <?php if ($isDean && $filterActive): ?>
                No classes match the selected filters. Try a different combination or reset the filters.
              <?php else: ?>
                <?= $search !== '' ? 'No classes found for “' . sched_e($search) . '”.' : 'No classes scheduled for this semester yet.' ?>
              <?php endif; ?>
            </p>
          <?php else: ?>
            <div class="sched-week">
              <?php foreach ($week as $day => $items): ?>
                <article class="day-card <?= $day === 'Sunday' ? 'day-card--sun' : '' ?>">
                  <h3><?= $day ?></h3>
                  <div class="day-card__body">
                    <?php if (!$items): ?>
                      <p class="day-card__none">No assigned class</p>
                      <?php if ($canManage): ?>
                        <button type="button" class="sp-btn sp-btn--block" data-open-add data-day="<?= $day ?>"><?= sched_icon('plus') ?> Add Schedule</button>
                      <?php endif; ?>
                    <?php else: foreach ($items as $it): ?>
                      <div class="class-item">
                        <span class="class-item__tags">
                          <?php if ($isDean): ?><?= sched_status_tag($it['status']) ?><?php endif; ?>
                          <?php if ($it['subject_code'] !== ''): ?><span class="sp-tag"><?= sched_e($it['type']) ?></span><?php endif; ?>
                          <?php if ($it['class_format'] !== 'onsite'): ?><span class="sp-tag"><?= $it['class_format'] === 'blended_even' ? 'Blended - even wks' : 'Blended - odd wks' ?></span><?php endif; ?>
                        </span>
                        <strong class="class-item__name"><?= sched_e($it['subject_code'] !== '' ? $it['subject_code'] : 'No subject yet') ?> • <?= sched_e($it['block_label']) ?></strong>
                        <span><?= sched_e($it['range']) ?></span>
                        <span>Room <?= sched_e($it['roomID']) ?></span>
                        <span><?= sched_e($it['faculty_name']) ?></span>
                        <div class="class-item__actions">
                          <a class="sp-btn sp-btn--grow" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $it['schedID']])) ?>">View Class</a>
                          <?php if ($canManage): ?>
                            <a class="sp-btn sp-btn--icon" href="<?= sched_e(sched_url(['view' => 'edit', 'id' => $it['schedID']])) ?>" aria-label="Edit schedule <?= (int)$it['schedID'] ?>"><?= sched_icon('pencil') ?></a>
                          <?php endif; ?>
                          <?php if ($canManage && $it['status'] === 'assigned'): ?>
                            <?= sched_status_form('deploy', $it, $csrf, $semKey, 'Deploy', 'check', 'list', 'sp-btn sp-btn--primary') ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

<?php /* =============================== MANAGER (DEAN): AVAILABILITY =============================== */ ?>
<?php elseif ($isDean && $view === 'availability'):
        $avUrl = function (array $o) use ($semKey, $avKind, $avDay, $avFrom, $avTo) {
            return sched_url(array_merge(['view' => 'availability', 'sem' => $semKey, 't' => $avKind, 'd' => $avDay, 'from' => $avFrom, 'to' => $avTo], $o));
        };
        list($avTimes) = sched_time_list([]);
        $avCells = 28;   // 7:00 AM - 9:00 PM in 30-minute cells
?>
          <div class="sched-bar">
            <a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey])) ?>"><?= sched_icon('left') ?> Back to Teaching Schedule</a>
            <form class="sched-sem" method="get" action="<?= sched_e($self) ?>">
              <input type="hidden" name="view" value="availability">
              <input type="hidden" name="t" value="<?= sched_e($avKind) ?>">
              <input type="hidden" name="d" value="<?= sched_e($avDay) ?>">
              <select name="sem" aria-label="Semester" onchange="this.form.submit()">
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= $s['key'] === $semKey ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <h2 class="sched-title">Availability</h2>

          <nav class="sched-tabs" aria-label="Availability type">
            <a class="sched-tab <?= $avKind === 'rooms' ? 'is-active' : '' ?>" href="<?= sched_e($avUrl(['t' => 'rooms'])) ?>">Rooms</a>
            <a class="sched-tab <?= $avKind === 'instructors' ? 'is-active' : '' ?>" href="<?= sched_e($avUrl(['t' => 'instructors'])) ?>">Instructors</a>
          </nav>
          <nav class="sched-tabs sched-tabs--days" aria-label="Day">
            <?php foreach (ScheduleLogic::WEEK as $d): ?>
              <a class="sched-tab <?= $avDay === $d ? 'is-active' : '' ?>" href="<?= sched_e($avUrl(['d' => $d])) ?>"><?= substr($d, 0, 3) ?></a>
            <?php endforeach; ?>
          </nav>

          <form class="sched-filters" method="get" action="<?= sched_e($self) ?>" aria-label="Find free <?= $avKind ?>">
            <input type="hidden" name="view" value="availability">
            <input type="hidden" name="sem" value="<?= sched_e($semKey) ?>">
            <input type="hidden" name="t" value="<?= sched_e($avKind) ?>">
            <input type="hidden" name="d" value="<?= sched_e($avDay) ?>">
            <div class="sched-filter">
              <label for="av-from">From</label>
              <select id="av-from" name="from" required>
                <option value="">Start time</option>
                <?php foreach ($avTimes as $val => $lab): if ($val >= ScheduleLogic::CLOSE_TIME) continue; ?>
                  <option value="<?= $val ?>" <?= $avFrom === $val ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sched-filter">
              <label for="av-to">To</label>
              <select id="av-to" name="to" required>
                <option value="">End time</option>
                <?php foreach ($avTimes as $val => $lab): if ($val <= ScheduleLogic::OPEN_TIME) continue; ?>
                  <option value="<?= $val ?>" <?= $avTo === $val ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="sp-btn sp-btn--primary"><?= sched_icon('search') ?> Find free <?= $avKind === 'rooms' ? 'rooms' : 'instructors' ?></button>
            <?php if ($avFree !== null): ?><a class="sp-btn" href="<?= sched_e($avUrl(['from' => '', 'to' => ''])) ?>">Clear</a><?php endif; ?>
          </form>

          <?php if ($avFree !== null): ?>
            <p class="sched-summary" role="status">
              <strong><?= count($avFree) ?> <?= $avKind === 'rooms' ? 'room' : 'instructor' ?><?= count($avFree) === 1 ? '' : 's' ?> free</strong>
              on <?= $avDay ?>, <?= sched_e(ScheduleLogic::fmtRange($avFrom, $avTo)) ?>
              <?= $avKind === 'instructors' ? '(including the 30-minute rest before and after other classes)' : '' ?>
              <?php if ($avFrom < ScheduleLogic::LUNCH_END && $avTo > ScheduleLogic::LUNCH_START): ?>
                <span class="sp-tag sp-tag--assigned">Overlaps the 12:00 – 1:00 PM lunch break</span>
              <?php endif; ?>
            </p>
            <?php if ($avFree): ?>
              <p class="av-free-list"><?php foreach ($avFree as $name): ?><span class="sp-tag sp-tag--deployed"><?= sched_e($name) ?></span> <?php endforeach; ?></p>
            <?php else: ?>
              <p class="sched-empty">No <?= $avKind === 'rooms' ? 'room' : 'instructor' ?> is free for that whole time. Try a shorter range or another day.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="sp-hint">Pick a day and a time range to list the <?= $avKind === 'rooms' ? 'rooms' : 'instructors' ?> that are free, or read the grid below.</p>
          <?php endif; ?>

          <?php if (!$av): ?>
            <p class="sched-empty">No <?= $avKind === 'rooms' ? 'rooms' : 'instructors' ?> found.</p>
          <?php else: ?>
            <div class="av-wrap">
              <table class="av-grid">
                <thead>
                  <tr>
                    <th class="av-name" scope="col"><?= $avKind === 'rooms' ? 'Room' : 'Instructor' ?></th>
                    <?php for ($h = 7; $h < 21; $h++): ?><th colspan="2" class="av-hour" scope="col"><?= date('g A', mktime($h, 0)) ?></th><?php endfor; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($av as $e):
                      $at = array_fill(0, $avCells, -1);
                      foreach ($e['busy'] as $bi => $b) {
                          $c0 = max(0, (int)floor((ScheduleLogic::minutes($b['start']) - 420) / 30));
                          $c1 = min($avCells, (int)ceil((ScheduleLogic::minutes($b['end']) - 420) / 30));
                          for ($c = $c0; $c < $c1; $c++) { if ($at[$c] < 0) $at[$c] = $bi; }
                      } ?>
                    <tr class="<?= $e['blocked'] ? 'is-blocked' : '' ?>">
                      <th scope="row" class="av-name"><?= sched_e($e['label']) ?><?php if ($e['note'] !== ''): ?><small><?= sched_e($e['note']) ?></small><?php endif; ?></th>
                      <?php
                      for ($c = 0; $c < $avCells; ) {
                          if ($at[$c] >= 0) {
                              $bi = $at[$c]; $span = 1;
                              while ($c + $span < $avCells && $at[$c + $span] === $bi) $span++;
                              $b = $e['busy'][$bi];
                              $tip = $b['label'] . ' — ' . $b['who'] . ' — ' . $b['range'] . ($b['alt'] ? ' (alternating online/onsite)' : '');
                              echo '<td colspan="' . $span . '" class="av-busy' . ($b['alt'] ? ' av-busy--alt' : '') . '" title="' . sched_e($tip) . '"><span>' . sched_e($b['label']) . '</span></td>';
                              $c += $span;
                          } else {
                              $m = 420 + $c * 30;
                              echo '<td class="' . (($m >= 720 && $m < 780) ? 'av-lunch' : ($e['blocked'] ? 'av-off' : 'av-free')) . '"></td>';
                              $c++;
                          }
                      } ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <ul class="av-legend" aria-label="Legend">
              <li><i class="av-key av-key--free"></i> Free</li>
              <li><i class="av-key av-key--busy"></i> Booked</li>
              <li><i class="av-key av-key--alt"></i> Alternating online / onsite</li>
              <li><i class="av-key av-key--lunch"></i> Lunch break</li>
            </ul>
          <?php endif; ?>

<?php /* =============================== MANAGER (DEAN): VIEW CLASS =============================== */ ?>
<?php elseif ($view === 'class' && $sched):
        $page = (int)($_GET['sp'] ?? 1);
        $sq   = trim((string)($_GET['sq'] ?? ''));
        $roster = $logic->classStudents($sched, $sq, $page);
        $pageUrl = function ($p) use ($sched, $sq) {
            return sched_url(['view' => 'class', 'id' => $sched['schedID'], 'sq' => $sq, 'sp' => $p]);
        };
?>
          <div class="sched-bar">
            <a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey])) ?>"><?= sched_icon('left') ?> Back to Teaching Schedule</a>
            <?php if ($canManage): ?>
              <span class="sched-bar__nav">
                <?php if ($sched['status'] === 'assigned'): ?>
                  <?= sched_status_form('deploy', $sched, $csrf, $semKey, 'Deploy', 'check', 'class', 'sp-btn sp-btn--primary') ?>
                <?php elseif ($sched['status'] === 'deployed'): ?>
                  <?= sched_status_form('undeploy', $sched, $csrf, $semKey, 'Undeploy', 'close', 'class', 'sp-btn') ?>
                <?php endif; ?>
                <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'edit', 'id' => $sched['schedID']])) ?>"><?= sched_icon('pencil') ?> Edit Class</a>
                <button type="button" class="sp-btn sp-btn--danger" data-open-delete><?= sched_icon('trash') ?> Delete</button>
              </span>
            <?php endif; ?>
          </div>

          <div class="class-head">
            <div class="class-head__icon"><?= str_replace('width="16" height="16"', 'width="40" height="40"', sched_icon('laptop')) ?></div>
            <div>
              <h2 class="sched-title"><?= sched_e($sched['subject_code'] !== '' ? $sched['subject_code'] : 'No subject yet') ?> &nbsp;-&nbsp; <?= sched_e($sched['block_label']) ?></h2>
              <p class="class-head__sub"><?= sched_e($sched['subject_name']) ?></p>
              <?php if ($sched['subject_code'] !== ''): ?><span class="sp-pill"><?= sched_e(ScheduleLogic::typeLabel($sched['type'])) ?></span><?php endif; ?>
              <?php if ($isDean): ?><?= sched_status_tag($sched['status']) ?><?php endif; ?>
            </div>
          </div>

          <dl class="class-meta">
            <div><dt>Instructor</dt><dd><?= sched_e($sched['faculty_name']) ?></dd></div>
            <div><dt>Room</dt><dd>Room <?= sched_e($sched['roomID']) ?></dd></div>
            <div><dt>Days</dt><dd><?= sched_e(implode(', ', $sched['days'])) ?></dd></div>
            <div><dt>Time</dt><dd><?= sched_e($sched['range']) ?></dd></div>
            <div><dt>Format</dt><dd><?= sched_e($sched['format_label']) ?></dd></div>
          </dl>

          <?php $hrs = $logic->hoursFor($sched); if ($hrs): $full = abs($hrs['scheduled'] - $hrs['required']) < 0.001; ?>
            <section class="sched-hours sched-hours--inline" aria-label="Weekly hours">
              <ul class="hours-list">
                <li class="hours-row">
                  <span class="hours-row__name"><strong>Weekly hours</strong> <span class="sp-tag"><?= sched_e($hrs['type']) ?></span></span>
                  <span class="hours-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, $hrs['pct']) ?>"><span class="<?= $hrs['scheduled'] > $hrs['required'] ? 'is-over' : ($full ? 'is-full' : '') ?>" style="width: <?= min(100, $hrs['pct']) ?>%"></span></span>
                  <span class="hours-row__val"><?= ScheduleLogic::fmtHours($hrs['scheduled']) ?> / <?= ScheduleLogic::fmtHours($hrs['required']) ?> hrs · <?= $hrs['pct'] ?>%</span>
                  <?php if ($full): ?><span class="sp-tag sp-tag--deployed">Complete</span>
                  <?php elseif ($hrs['scheduled'] > $hrs['required']): ?><span class="sp-tag sp-tag--assigned">Over</span>
                  <?php else: ?><span class="sp-tag sp-tag--draft">Needs <?= ScheduleLogic::fmtHours($hrs['left']) ?> hr</span><?php endif; ?>
                </li>
              </ul>
              <p class="sp-hint">Counts every day and slot of <?= sched_e($sched['subject_code']) ?> for <?= sched_e($sched['block_label']) ?>: <strong><?= sched_e(ScheduleLogic::fmtDayHours($hrs['by_day'])) ?></strong>. The hours can be spread over different days, and the subject can only be deployed at 100%.</p>
            </section>
          <?php endif; ?>

          <div class="class-cols">
            <section class="sp-box">
              <h3 class="sp-box__title"><?= sched_icon('calendar') ?> Class Schedule</h3>
              <div class="sp-tablewrap">
                <table class="sp-table">
                  <thead><tr><th>Day</th><th>Time</th><th>Room</th></tr></thead>
                  <tbody>
                    <?php foreach ($sched['days'] as $d): ?>
                      <tr><td><?= $d ?></td><td><?= sched_e($sched['range']) ?></td><td>Room <?= sched_e($sched['roomID']) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>

            <section class="sp-box">
              <div class="sp-box__head">
                <h3 class="sp-box__title">Student List (<?= (int)$roster['total'] ?>)</h3>
                <form method="get" action="<?= sched_e($self) ?>" class="sp-mini-search" role="search">
                  <input type="hidden" name="view" value="class">
                  <input type="hidden" name="id" value="<?= (int)$sched['schedID'] ?>">
                  <input type="search" name="sq" value="<?= sched_e($sq) ?>" placeholder="Search student..." aria-label="Search student">
                </form>
              </div>
              <div class="sp-tablewrap">
                <table class="sp-table sp-table--zebra">
                  <thead><tr><th>#</th><th>Student ID</th><th>Student Name</th></tr></thead>
                  <tbody>
                    <?php if (!$roster['rows']): ?>
                      <tr><td colspan="3" class="sp-table__empty">No students found.</td></tr>
                    <?php else: foreach ($roster['rows'] as $i => $st): ?>
                      <tr><td><?= $roster['offset'] + $i + 1 ?></td><td><?= (int)$st['studentID'] ?></td><td><?= sched_e($st['name']) ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($roster['pages'] > 1): ?>
                <nav class="sp-pager" aria-label="Student list pages">
                  <?php if ($roster['page'] > 1): ?><a href="<?= sched_e($pageUrl($roster['page'] - 1)) ?>" aria-label="Previous page">‹</a><?php endif; ?>
                  <?php for ($p = 1; $p <= $roster['pages']; $p++): ?>
                    <a href="<?= sched_e($pageUrl($p)) ?>" <?= $p === $roster['page'] ? 'aria-current="page" class="is-current"' : '' ?>><?= $p ?></a>
                  <?php endfor; ?>
                  <?php if ($roster['page'] < $roster['pages']): ?><a href="<?= sched_e($pageUrl($roster['page'] + 1)) ?>" aria-label="Next page">›</a><?php endif; ?>
                </nav>
              <?php endif; ?>
            </section>
          </div>

<?php /* =============================== MANAGER (DEAN): EDIT CLASS =============================== */ ?>
<?php elseif ($view === 'edit' && $sched):
        $vals = $old ?: [
            'block'      => $sched['block'] ? $sched['program'] . '|' . $sched['block'] : '',
            'class_type' => $sched['subject_code'] !== '' ? $sched['type'] : 'LEC',
            'subject'    => $sched['subjectID'],
            'instructor' => $sched['facultyID'],
            'room'       => $sched['roomID'],
            'days'       => $sched['days'],
            'start_time' => $sched['time_start'],
            'end_time'   => $sched['time_end'],
            'semester'   => $semKey,
            'delivery'     => $sched['class_format'] === 'onsite' ? 'onsite' : 'blended',
            'onsite_weeks' => $sched['class_format'] === 'blended_even' ? 'even' : 'odd',
        ];
        $vals['program'] = $sched['program'];
        $editBlocks = array_values(array_filter($blocks, function ($b) use ($sched) {
            return $b['program'] === $sched['program'];
        }));
?>
          <div class="sched-bar">
            <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $sched['schedID']])) ?>"><?= sched_icon('left') ?> Back to Class</a>
            <span class="sched-bar__nav">
              <?= sched_status_tag($sched['status']) ?>
              <?php if ($sched['status'] === 'assigned'): ?>
                <?= sched_status_form('deploy', $sched, $csrf, $semKey, 'Deploy', 'check', 'class', 'sp-btn sp-btn--primary') ?>
              <?php elseif ($sched['status'] === 'deployed'): ?>
                <?= sched_status_form('undeploy', $sched, $csrf, $semKey, 'Undeploy', 'close', 'class', 'sp-btn') ?>
              <?php endif; ?>
            </span>
          </div>
          <div class="class-head class-head--compact">
            <div class="class-head__icon"><?= sched_icon('laptop') ?></div>
            <div>
              <h2 class="sched-title">Edit Class</h2>
              <p class="class-head__sub">Update the details for <?= sched_e($sched['subject_code'] !== '' ? $sched['subject_code'] . ' - ' . $sched['block_label'] : $sched['program'] . ' time slot') ?></p>
            </div>
          </div>

          <form method="post" action="<?= sched_e(sched_url(['view' => 'edit', 'id' => $sched['schedID']])) ?>" class="sp-form" data-sched-form>
            <input type="hidden" name="csrf" value="<?= sched_e($csrf) ?>">
            <input type="hidden" name="sched_action" value="update">
            <input type="hidden" name="schedID" value="<?= (int)$sched['schedID'] ?>">
            <h3 class="sp-step">Step 1 — Day, time and room</h3>
            <div class="sp-field">
              <label for="edit-semester">Semester <span class="sp-req">*</span></label>
              <select id="edit-semester" name="semester" required>
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= ($vals['semester'] ?? $semKey) === $s['key'] ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?= sched_form_step1('edit', $programs, $rooms, $vals, true) ?>
            <h3 class="sp-step">Step 2 — Assign block, subject and instructor</h3>
            <?= sched_form_step2('edit', $editBlocks, $subjects, $instructors, $vals) ?>
            <p class="sp-hint">Step 3 — <?= $sched['status'] === 'deployed' ? 'This schedule is deployed, so changes are visible to students and professors right away.' : 'Once the block, subject and instructor are set, use Deploy to make it visible.' ?></p>
            <div class="sp-form__actions">
              <button type="button" class="sp-btn sp-btn--danger" data-open-delete><?= sched_icon('trash') ?> Delete</button>
              <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $sched['schedID']])) ?>">Cancel</a>
              <button type="submit" class="sp-btn sp-btn--primary"><?= sched_icon('check') ?> Save Changes</button>
            </div>
          </form>

<?php /* =============================== FACULTY / STUDENT / COORDINATOR: DAY PAGE =============================== */ ?>
<?php elseif ($view === 'day'):
        $idx  = array_search($dayName, ScheduleLogic::WEEK, true);
        $next = ScheduleLogic::WEEK[($idx + 1) % 7];
        $items = $week[$dayName];
?>
          <div class="sched-bar">
            <h2 class="sched-title">Class Schedule | <?= $dayName ?></h2>
            <div class="sched-bar__nav">
              <a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey, 'q' => $search])) ?>"><?= sched_icon('left') ?> All days</a>
              <a class="sp-btn sp-btn--icon" href="<?= sched_e(sched_url(['view' => 'day', 'd' => $next, 'sem' => $semKey, 'q' => $search])) ?>" aria-label="Next day: <?= $next ?>"><?= sched_icon('right') ?></a>
            </div>
          </div>
          <?php if (!$items): ?>
            <p class="sched-empty">No classes scheduled on <?= $dayName ?>.</p>
          <?php else: ?>
            <div class="tile-grid">
              <?php foreach ($items as $it): ?>
                <article class="day-card">
                  <h3><?= sched_e($it['subject_name']) ?></h3>
                  <div class="day-card__body day-card__body--left">
                    <p class="tile-row"><strong>Time:</strong> <?= sched_e($it['range']) ?></p>
                    <?php if ($it['class_format'] !== 'onsite'): ?><p class="tile-row"><strong>Format:</strong> <?= sched_e($it['format_label']) ?></p><?php endif; ?>
                    <p class="tile-row"><strong>Room:</strong> <?= sched_e($it['roomID']) ?></p>
                    <?php if ($isCoord): ?>
                      <p class="tile-row"><strong>Block:</strong> <?= sched_e($it['block_label']) ?></p>
                      <p class="tile-row"><strong>Prof:</strong> <?= sched_e($it['faculty_name']) ?></p>
                    <?php elseif ($group === 'faculty'): ?>
                      <p class="tile-row"><strong>Grade &amp; Section:</strong> <?= sched_e($it['block_label']) ?></p>
                    <?php else: ?>
                      <p class="tile-row"><strong>Prof:</strong> <?= sched_e($it['faculty_name']) ?></p>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

<?php /* =============================== FACULTY / STUDENT / COORDINATOR: WEEK =============================== */ ?>
<?php else: ?>
          <div class="sched-toolbar">
            <h2 class="sched-title"><?= $monitoring ? 'Program Schedule - ' . sched_e($progLabel) : 'My Schedule' ?></h2>
            <form class="sched-sem" method="get" action="<?= sched_e($self) ?>">
              <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= sched_e($search) ?>"><?php endif; ?>
              <select name="sem" aria-label="Semester" onchange="this.form.submit()">
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= $s['key'] === $semKey ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <form class="sched-search" method="get" action="<?= sched_e($self) ?>" role="search">
            <input type="hidden" name="sem" value="<?= sched_e($semKey) ?>">
            <label class="sched-search__box">
              <?= sched_icon('search') ?>
              <input type="search" name="q" value="<?= sched_e($search) ?>" placeholder="<?= sched_e($searchPlaceholder) ?>" aria-label="Search schedule">
            </label>
            <button type="submit" class="sp-btn sp-btn--primary">Search</button>
            <?php if ($search !== ''): ?><a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey])) ?>">Clear</a><?php endif; ?>
          </form>
          <?php if ($searchHint !== ''): ?><p class="sp-hint sched-search__hint"><?= sched_e($searchHint) ?></p><?php endif; ?>
          <?php if ($monitoring): ?>
            <p class="sched-summary" role="status">
              <strong><?= count($rows) ?> deployed class<?= count($rows) === 1 ? '' : 'es' ?></strong>
              of <?= sched_e($progLabel) ?> match “<?= sched_e($search) ?>”<?= $semLabel ? ' in ' . sched_e($semLabel) : '' ?>.
            </p>
          <?php endif; ?>
          <?php if (!$rows): ?>
            <p class="sched-empty">
              <?php if ($isCoord && $search !== ''): ?>
                No block or instructor matching “<?= sched_e($search) ?>” was found in <?= sched_e($progLabel !== '' ? $progLabel : 'your program') ?> for <?= sched_e($semLabel) ?>. Check the spelling or try a block like “<?= sched_e($progLabel) ?> 1A” or an instructor’s last name. Only deployed schedules are shown.
              <?php elseif ($isCoord): ?>
                You have no classes of your own for <?= sched_e($semLabel) ?>. Search for a block or an instructor of <?= sched_e($progLabel !== '' ? $progLabel : 'your program') ?> above to monitor the program’s schedule.
              <?php else: ?>
                <?= $search !== '' ? 'No classes found for “' . sched_e($search) . '”.' : 'No classes found for ' . sched_e($semLabel) . '. Try another semester from the dropdown.' ?>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <div class="sched-week">
            <?php foreach ($week as $day => $items): ?>
              <article class="day-card <?= $day === 'Sunday' ? 'day-card--sun' : '' ?>">
                <h3><?= $day ?></h3>
                <div class="day-card__body day-card__body--left">
                  <?php if (!$items): ?>
                    <p class="day-card__time"><?= sched_icon('clock') ?> No classes scheduled</p>
                  <?php else:
                      $first = $items[0]['time_start']; $last = $items[0]['time_end'];
                      foreach ($items as $it) {
                          if ($it['time_start'] < $first) $first = $it['time_start'];
                          if ($it['time_end'] > $last)    $last  = $it['time_end'];
                      } ?>
                    <p class="day-card__time"><?= sched_icon('clock') ?> <?= sched_e(ScheduleLogic::fmtRange($first, $last)) ?></p>
                    <ul class="day-card__list">
                      <?php foreach ($items as $it): ?>
                        <li><?= sched_icon('book') ?> <span><?= sched_e($it['subject_name']) ?><?= $isCoord ? ' • ' . sched_e($it['block_label']) . ($monitoring ? ' • ' . sched_e($it['faculty_name']) : '') : '' ?></span></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <a class="sp-btn sp-btn--block" href="<?= sched_e(sched_url(['view' => 'day', 'd' => $day, 'sem' => $semKey, 'q' => $search])) ?>">See Schedule</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
<?php endif; ?>

        </section>
      </main>

<?php if ($canManage && $view === 'list'):
        $addVals = $old ?: ['semester' => $semKey, 'program' => $fl['program']]; ?>
      <dialog class="sched-modal" id="add-schedule" aria-labelledby="add-title" <?= isset($_GET['add']) ? 'data-autoopen' : '' ?>>
        <form method="post" action="<?= sched_e(sched_url(['sem' => $semKey])) ?>" class="sp-form" data-sched-form>
          <div class="sched-modal__head">
            <?= str_replace('width="16" height="16"', 'width="26" height="26"', sched_icon('calendar')) ?>
            <div>
              <h2 id="add-title">Add Schedule</h2>
              <p>Step 1 of 3 for <?= sched_e($semLabel ?: 'the selected semester') ?>: set the program, room, day and time. Assign the block, subject and instructor later.</p>
            </div>
            <button type="button" class="sp-btn sp-btn--icon" data-close-add aria-label="Close"><?= sched_icon('close') ?></button>
          </div>
          <?php if ($flash && $flash['type'] !== 'conflict' && isset($_GET['add'])): ?>
            <div class="sched-flash sched-flash--error" role="alert">
              <?php foreach ($flash['msgs'] as $m): ?><p><?= sched_e($m) ?></p><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <input type="hidden" name="csrf" value="<?= sched_e($csrf) ?>">
          <input type="hidden" name="sched_action" value="create">
          <input type="hidden" name="semester" value="<?= sched_e($semKey) ?>">
          <?= sched_form_step1('add', $programs, $rooms, $addVals) ?>
          <div class="sp-form__actions">
            <button type="button" class="sp-btn" data-close-add>Cancel</button>
            <button type="submit" class="sp-btn sp-btn--primary"><?= sched_icon('plus') ?> Add Time Slot</button>
          </div>
        </form>
      </dialog>
<?php endif; ?>
<?php if ($canManage && $sched && ($view === 'class' || $view === 'edit')): ?>
      <dialog class="sched-alert" id="delete-schedule" aria-labelledby="delete-title">
        <form method="post" action="<?= sched_e(sched_url(['sem' => $semKey])) ?>">
          <input type="hidden" name="csrf" value="<?= sched_e($csrf) ?>">
          <input type="hidden" name="sched_action" value="delete">
          <input type="hidden" name="schedID" value="<?= (int)$sched['schedID'] ?>">
          <h2 id="delete-title"><?= sched_icon('trash') ?> Delete Schedule</h2>
          <?php $sylN = $logic->syllabusCount($sched['schedID']); ?>
          <p>Delete <strong><?= sched_e($sched['subject_code'] !== '' ? $sched['subject_code'] : 'this time slot') ?> • <?= sched_e($sched['block_label']) ?></strong> (<?= sched_e(implode(', ', $sched['days'])) ?>, <?= sched_e($sched['range']) ?>)?<?php if ($sylN > 0): ?><br>This also deletes its <?= $sylN ?> syllabus module<?= $sylN > 1 ? 's' : '' ?>.<?php endif; ?><br>This cannot be undone.</p>
          <div class="sched-alert__actions">
            <button type="button" class="sp-btn" data-close-delete>Cancel</button>
            <button type="submit" class="sp-btn sp-btn--danger">Delete</button>
          </div>
        </form>
      </dialog>
      <script>
      (function () {
        var d = document.getElementById('delete-schedule');
        if (!d || typeof d.showModal !== 'function') return;
        document.querySelectorAll('[data-open-delete]').forEach(function (b) { b.addEventListener('click', function () { d.showModal(); }); });
        d.querySelectorAll('[data-close-delete]').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
      })();
      </script>
<?php endif; ?>
<?php if ($flash && $flash['type'] === 'conflict' && $canManage):
        $cancelUrl = ($view === 'edit' && $sched)
            ? sched_url(['view' => 'class', 'id' => $sched['schedID']])
            : sched_url(['sem' => $semKey]); ?>
      <dialog class="sched-alert" id="conflict-alert" aria-labelledby="conflict-title" aria-describedby="conflict-desc">
        <h2 id="conflict-title"><?= sched_icon('warn') ?> Schedule Conflict</h2>
        <div id="conflict-desc">
          <p>This schedule overlaps with an existing class.<br>Please adjust the day, time, room, or instructor before trying again.</p>
          <ul class="sched-alert__list">
            <?php foreach ($flash['msgs'] as $m): ?><li><?= sched_e($m) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <div class="sched-alert__actions">
          <a class="sp-btn" href="<?= sched_e($cancelUrl) ?>">Cancel</a>
          <button type="button" class="sp-btn sp-btn--primary" data-close-alert autofocus>Edit Again</button>
        </div>
      </dialog>
<?php endif; ?>
    </div>
  </div>

<?php if ($needForm): ?>
<script>
(function () {
  document.querySelectorAll('[data-sched-form]').forEach(function (form) {
    var weeks = form.querySelector('[data-weeks]');
    if (!weeks) return;
    function toggle() {
      var c = form.querySelector('input[name="delivery"]:checked');
      weeks.hidden = !(c && c.value === 'blended');
    }
    form.querySelectorAll('input[name="delivery"]').forEach(function (r) { r.addEventListener('change', toggle); });
    toggle();
  });

  // Filter subjects by class type, category and program.
  document.querySelectorAll('[data-sched-form]').forEach(function (form) {
    var subject = form.querySelector('select[name="subject"]');
    if (!subject) return;
    var blockSel = form.querySelector('select[name="block"]');
    function val(name, fallback) {
      var c = form.querySelector('input[name="' + name + '"]:checked');
      return c ? c.value : fallback;
    }
    function apply() {
      var type = val('class_type', 'LEC'), kind = val('subject_kind', 'normal');
      var progField = form.querySelector('[name="program"]');
      var prog = progField && progField.value ? progField.value : (blockSel && blockSel.value ? blockSel.value.split('|')[0] : '');
      var semField = form.querySelector('[name="semester"]');
      var sem = semField && semField.value ? semField.value.split('|')[1] : '';
      subject.disabled = (prog === '');
      subject.options[0].textContent = prog ? 'No subject yet' : 'Program missing';
      Array.prototype.forEach.call(subject.options, function (o) {
        if (!o.value) return;
        var list = (o.dataset.programs || '*').split(',');
        var withSem = list.some(function (x) { return x.indexOf(':') !== -1; });
        var offered = list.indexOf('*') !== -1 || list.indexOf(withSem ? prog + ':' + sem : prog) !== -1;
        var hide = o.dataset.type !== type || o.dataset.kind !== kind || !offered;
        o.hidden = hide; o.disabled = hide;
        if (hide && o.selected) subject.value = '';
      });
    }
    form.querySelectorAll('input[name="class_type"], input[name="subject_kind"], select[name="block"], select[name="semester"]').forEach(function (r) { r.addEventListener('change', apply); });
    apply();
  });
  
  var alertDlg = document.getElementById('conflict-alert');
  var dlg = document.getElementById('add-schedule');
  function showConflict() {
    if (!alertDlg || typeof alertDlg.showModal !== 'function') return;
    alertDlg.querySelector('[data-close-alert]').addEventListener('click', function () { alertDlg.close(); });
    alertDlg.showModal();
  }
  if (!dlg || typeof dlg.showModal !== 'function') { showConflict(); return; }
  document.querySelectorAll('[data-open-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var day = btn.getAttribute('data-day');
      if (day) {
        dlg.querySelectorAll('input[name="days[]"]').forEach(function (c) { c.checked = (c.value === day); });
      }
      dlg.showModal();
    });
  });
  dlg.querySelectorAll('[data-close-add]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  if (dlg.hasAttribute('data-autoopen')) dlg.showModal();
  showConflict();
})();
</script>
<?php endif; ?>
<?php if ($isDean && $view === 'list'): ?>
<script>
(function () {
  var form = document.getElementById('dean-filters');
  if (!form) return;
  var prog  = form.querySelector('[name="f_program"]');
  var block = form.querySelector('[name="f_block"]');

  // Show only the blocks of the chosen program.
  function syncBlocks() {
    Array.prototype.forEach.call(block.options, function (o) {
      if (!o.dataset.program) return;
      var hide = prog.value !== '' && o.dataset.program !== prog.value;
      o.hidden = hide; o.disabled = hide;
      if (hide && o.selected) block.value = '';
    });
  }
  syncBlocks();
  prog.addEventListener('change', function () { syncBlocks(); form.submit(); });
  form.querySelectorAll('select').forEach(function (s) {
    if (s !== prog) s.addEventListener('change', function () { form.submit(); });
  });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . "/components/logout.php"; ?>
</body>
</html>