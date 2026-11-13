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

// Fields shared by the Add modal and Edit page.
function sched_form_fields($uid, $blocks, $subjects, $instructors, $rooms, $v)
{
    $times = [];
    for ($m = 6 * 60; $m <= 22 * 60; $m += 30) {
        $times[sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60)] = date('g:i A', mktime(intdiv($m, 60), $m % 60));
    }
    $startSel = isset($v['start_time']) ? substr($v['start_time'] . ':00', 0, 8) : '';
    $endSel   = isset($v['end_time'])   ? substr($v['end_time'] . ':00', 0, 8)   : '';
    foreach ([$startSel, $endSel] as $t) {              // keep legacy times (e.g. 07:15) selectable
        if ($t !== '' && !isset($times[$t])) $times[$t] = ScheduleLogic::fmtTime($t);
    }
    ksort($times);
    $type = $v['class_type'] ?? 'LEC';
    $days = (array)($v['days'] ?? []);
    $kind = $v['subject_kind'] ?? null;
    if ($kind === null) {
        $kind = 'normal';
        foreach ($subjects as $s) {
            if (($v['subject'] ?? null) === $s['subjectID']) { $kind = $s['kind']; break; }
        }
    }
    ob_start(); ?>
    <div class="sp-field">
      <label for="<?= $uid ?>-block">Block / Section <span class="sp-req">*</span></label>
      <select id="<?= $uid ?>-block" name="block" required>
        <option value="">Select block/section</option>
        <?php foreach ($blocks as $b): ?>
          <option value="<?= sched_e($b['value']) ?>" <?= ($v['block'] ?? '') === $b['value'] ? 'selected' : '' ?>><?= sched_e($b['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sp-field">
      <span class="sp-label">Class Type <span class="sp-req">*</span></span>
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
      <label for="<?= $uid ?>-subject">Subject <span class="sp-req">*</span></label>
      <select id="<?= $uid ?>-subject" name="subject" required>
        <option value="">Select subject</option>
        <?php foreach ($subjects as $s): ?>
          <option value="<?= sched_e($s['subjectID']) ?>" data-type="<?= sched_e($s['type']) ?>" data-kind="<?= sched_e($s['kind']) ?>"
            <?= ($v['subject'] ?? null) === $s['subjectID'] ? 'selected' : '' ?>>
            <?= sched_e(trim($s['subjectID']) . ' — ' . $s['name'] . ($s['requires'] ? ' (requires ' . $s['requires'] . ')' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sp-field">
      <label for="<?= $uid ?>-instructor">Instructor <span class="sp-req">*</span></label>
      <select id="<?= $uid ?>-instructor" name="instructor" required>
        <option value="">Select instructor</option>
        <?php foreach ($instructors as $i): ?>
          <option value="<?= (int)$i['facultyID'] ?>" <?= (int)($v['instructor'] ?? 0) === (int)$i['facultyID'] ? 'selected' : '' ?>>
            <?= sched_e(ScheduleLogic::properName($i['first_name'] . ' ' . $i['last_name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sp-field">
      <label for="<?= $uid ?>-room">Room <span class="sp-req">*</span></label>
      <select id="<?= $uid ?>-room" name="room" required>
        <option value="">Select room</option>
        <?php foreach ($rooms as $r): ?>
          <option value="<?= sched_e($r['roomID']) ?>" <?= (string)($v['room'] ?? '') === (string)$r['roomID'] ? 'selected' : '' ?>>Room <?= sched_e($r['roomID']) ?></option>
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
          <?php foreach ($times as $val => $lab): ?><option value="<?= $val ?>" <?= $startSel === $val ? 'selected' : '' ?>><?= $lab ?></option><?php endforeach; ?>
        </select>
        <span>to</span>
        <select name="end_time" aria-label="End time" required>
          <option value="">End time</option>
          <?php foreach ($times as $val => $lab): ?><option value="<?= $val ?>" <?= $endSel === $val ? 'selected' : '' ?>><?= $lab ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

$group     = $logic->group;
$canManage = $logic->canManage();
$semesters = $logic->semesters();
$semKey    = $logic->selectedSemester($_GET['sem'] ?? '');
$view      = $_GET['view'] ?? 'list';
$flash     = $logic->takeFlash();
$old       = $logic->takeOld();
$csrf      = $logic->csrf();

$sched = null;
if ($view === 'class' || $view === 'edit') {
    $sched = ($group === 'manager') ? $logic->find((int)($_GET['id'] ?? 0)) : null;
    if (!$sched || ($view === 'edit' && !$canManage)) {
        $view = 'list';
        $sched = null;
    } else {
        $semKey = $sched['acad_year'] . '|' . $sched['sem'];
    }
}

$dayName = ucfirst(strtolower($_GET['d'] ?? ''));
if ($view === 'day' && ($group === 'manager' || !in_array($dayName, ScheduleLogic::WEEK, true))) {
    $view = 'list';
}

$search = trim((string)($_GET['q'] ?? ''));
$rows   = ($view === 'list' || $view === 'day') ? $logic->schedules($semKey, $search) : [];
$week   = $logic->week($rows);

$needForm = $canManage && ($view === 'list' || $view === 'edit');
$blocks = $subjects = $instructors = $rooms = [];
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
  <title>AMS-Schedule</title>
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

<?php /* =============================== MANAGER: LIST =============================== */ ?>
<?php if ($group === 'manager' && $view === 'list'): ?>
          <div class="sched-toolbar">
            <h2 class="sched-title">Teaching Schedule</h2>
            <form class="sched-sem" method="get" action="<?= sched_e($self) ?>">
              <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= sched_e($search) ?>"><?php endif; ?>
              <select name="sem" aria-label="Semester" onchange="this.form.submit()">
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= $s['key'] === $semKey ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <noscript><button class="sp-btn" type="submit">Go</button></noscript>
            </form>
            <?php if ($canManage): ?>
              <button type="button" class="sp-btn sp-btn--primary" data-open-add><?= sched_icon('plus') ?> Add Schedule</button>
            <?php endif; ?>
          </div>

          <form class="sched-search" method="get" action="<?= sched_e($self) ?>" role="search">
            <input type="hidden" name="sem" value="<?= sched_e($semKey) ?>">
            <label class="sched-search__box">
              <?= sched_icon('search') ?>
              <input type="search" name="q" value="<?= sched_e($search) ?>" placeholder="Search block (e.g., BSCS 1A)" aria-label="Search block">
            </label>
            <button type="submit" class="sp-btn sp-btn--primary">Search</button>
          </form>

          <?php if (!$rows): ?>
            <p class="sched-empty">
              <?= $search !== '' ? 'No classes found for “' . sched_e($search) . '”.' : 'No classes scheduled for this semester yet.' ?>
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
                        <span class="sp-tag"><?= sched_e($it['type']) ?></span>
                        <strong class="class-item__name"><?= sched_e($it['subject_code']) ?> • <?= sched_e($it['block_label']) ?></strong>
                        <span><?= sched_e($it['range']) ?></span>
                        <span>Room <?= sched_e($it['roomID']) ?></span>
                        <div class="class-item__actions">
                          <a class="sp-btn sp-btn--grow" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $it['schedID']])) ?>">View Class</a>
                          <?php if ($canManage): ?>
                            <a class="sp-btn sp-btn--icon" href="<?= sched_e(sched_url(['view' => 'edit', 'id' => $it['schedID']])) ?>" aria-label="Edit <?= sched_e($it['subject_code']) ?>"><?= sched_icon('pencil') ?></a>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

<?php /* =============================== MANAGER: VIEW CLASS =============================== */ ?>
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
                <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'edit', 'id' => $sched['schedID']])) ?>"><?= sched_icon('pencil') ?> Edit Class</a>
                <button type="button" class="sp-btn sp-btn--danger" data-open-delete><?= sched_icon('trash') ?> Delete</button>
              </span>
            <?php endif; ?>
          </div>

          <header class="class-head">
            <div class="class-head__icon"><?= str_replace('width="16" height="16"', 'width="40" height="40"', sched_icon('laptop')) ?></div>
            <div>
              <h2 class="sched-title"><?= sched_e($sched['subject_code']) ?> &nbsp;-&nbsp; <?= sched_e($sched['block_label']) ?></h2>
              <p class="class-head__sub"><?= sched_e($sched['subject_name']) ?></p>
              <span class="sp-pill"><?= sched_e(ScheduleLogic::typeLabel($sched['type'])) ?></span>
            </div>
          </header>

          <dl class="class-meta">
            <div><dt>Instructor</dt><dd><?= sched_e($sched['faculty_name']) ?></dd></div>
            <div><dt>Room</dt><dd>Room <?= sched_e($sched['roomID']) ?></dd></div>
            <div><dt>Days</dt><dd><?= sched_e(implode(', ', $sched['days'])) ?></dd></div>
            <div><dt>Time</dt><dd><?= sched_e($sched['range']) ?></dd></div>
          </dl>

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

<?php /* =============================== MANAGER: EDIT CLASS =============================== */ ?>
<?php elseif ($view === 'edit' && $sched):
        $vals = $old ?: [
            'block'      => $sched['program'] . '|' . $sched['block'],
            'class_type' => $sched['type'],
            'subject'    => $sched['subjectID'],
            'instructor' => $sched['facultyID'],
            'room'       => $sched['roomID'],
            'days'       => $sched['days'],
            'start_time' => $sched['time_start'],
            'end_time'   => $sched['time_end'],
            'semester'   => $semKey,
        ];
?>
          <div class="sched-bar">
            <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $sched['schedID']])) ?>"><?= sched_icon('left') ?> Back to Class</a>
          </div>
          <header class="class-head class-head--compact">
            <div class="class-head__icon"><?= sched_icon('laptop') ?></div>
            <div>
              <h2 class="sched-title">Edit Class</h2>
              <p class="class-head__sub">Update the details for <?= sched_e($sched['subject_code']) ?> - <?= sched_e($sched['block_label']) ?></p>
            </div>
          </header>

          <form method="post" action="<?= sched_e(sched_url(['view' => 'edit', 'id' => $sched['schedID']])) ?>" class="sp-form" data-sched-form>
            <input type="hidden" name="csrf" value="<?= sched_e($csrf) ?>">
            <input type="hidden" name="sched_action" value="update">
            <input type="hidden" name="schedID" value="<?= (int)$sched['schedID'] ?>">
            <div class="sp-field">
              <label for="edit-semester">Semester <span class="sp-req">*</span></label>
              <select id="edit-semester" name="semester" required>
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= sched_e($s['key']) ?>" <?= ($vals['semester'] ?? $semKey) === $s['key'] ? 'selected' : '' ?>><?= sched_e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?= sched_form_fields('edit', $blocks, $subjects, $instructors, $rooms, $vals) ?>
            <div class="sp-form__actions">
              <button type="button" class="sp-btn sp-btn--danger" data-open-delete><?= sched_icon('trash') ?> Delete</button>
              <a class="sp-btn" href="<?= sched_e(sched_url(['view' => 'class', 'id' => $sched['schedID']])) ?>">Cancel</a>
              <button type="submit" class="sp-btn sp-btn--primary"><?= sched_icon('check') ?> Save Changes</button>
            </div>
          </form>

<?php /* =============================== FACULTY / STUDENT: DAY PAGE =============================== */ ?>
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
                    <p class="tile-row"><strong>Room:</strong> <?= sched_e($it['roomID']) ?></p>
                    <?php if ($group === 'faculty'): ?>
                      <p class="tile-row"><strong>Grade &amp; Section:</strong> <?= sched_e($it['block_label']) ?></p>
                    <?php else: ?>
                      <p class="tile-row"><strong>Prof:</strong> <?= sched_e($it['faculty_name']) ?></p>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

<?php /* =============================== FACULTY / STUDENT: WEEK =============================== */ ?>
<?php else: ?>
          <div class="sched-toolbar">
            <h2 class="sched-title">My Schedule</h2>
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
              <input type="search" name="q" value="<?= sched_e($search) ?>" placeholder="Search subject, room or instructor" aria-label="Search schedule">
            </label>
            <button type="submit" class="sp-btn sp-btn--primary">Search</button>
            <?php if ($search !== ''): ?><a class="sp-btn" href="<?= sched_e(sched_url(['sem' => $semKey])) ?>">Clear</a><?php endif; ?>
          </form>
          <?php if (!$rows): ?>
            <p class="sched-empty">
              <?= $search !== '' ? 'No classes found for “' . sched_e($search) . '”.' : 'No classes found for ' . sched_e($semLabel) . '. Try another semester from the dropdown.' ?>
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
                        <li><?= sched_icon('book') ?> <span><?= sched_e($it['subject_name']) ?></span></li>
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
        $addVals = $old ?: ['semester' => $semKey, 'class_type' => 'LEC']; ?>
      <dialog class="sched-modal" id="add-schedule" aria-labelledby="add-title" <?= isset($_GET['add']) ? 'data-autoopen' : '' ?>>
        <form method="post" action="<?= sched_e(sched_url(['sem' => $semKey])) ?>" class="sp-form" data-sched-form>
          <header class="sched-modal__head">
            <?= str_replace('width="16" height="16"', 'width="26" height="26"', sched_icon('calendar')) ?>
            <div>
              <h2 id="add-title">Add Schedule</h2>
              <p>Create a new class schedule for <?= sched_e($semLabel ?: 'the selected semester') ?>.</p>
            </div>
            <button type="button" class="sp-btn sp-btn--icon" data-close-add aria-label="Close"><?= sched_icon('close') ?></button>
          </header>
          <?php if ($flash && $flash['type'] !== 'conflict' && isset($_GET['add'])): ?>
            <div class="sched-flash sched-flash--error" role="alert">
              <?php foreach ($flash['msgs'] as $m): ?><p><?= sched_e($m) ?></p><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <input type="hidden" name="csrf" value="<?= sched_e($csrf) ?>">
          <input type="hidden" name="sched_action" value="create">
          <input type="hidden" name="semester" value="<?= sched_e($semKey) ?>">
          <?= sched_form_fields('add', $blocks, $subjects, $instructors, $rooms, $addVals) ?>
          <div class="sp-form__actions">
            <button type="button" class="sp-btn" data-close-add>Cancel</button>
            <button type="submit" class="sp-btn sp-btn--primary"><?= sched_icon('plus') ?> Add Schedule</button>
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
          <p>Delete <strong><?= sched_e($sched['subject_code']) ?> • <?= sched_e($sched['block_label']) ?></strong> (<?= sched_e(implode(', ', $sched['days'])) ?>, <?= sched_e($sched['range']) ?>)?<br>This cannot be undone.</p>
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
  // Filter subjects by class type.
  document.querySelectorAll('[data-sched-form]').forEach(function (form) {
    var subject = form.querySelector('select[name="subject"]');
    function val(name, fallback) {
      var c = form.querySelector('input[name="' + name + '"]:checked');
      return c ? c.value : fallback;
    }
    function apply() {
      var type = val('class_type', 'LEC'), kind = val('subject_kind', 'normal');
      Array.prototype.forEach.call(subject.options, function (o) {
        if (!o.value) return;
        var hide = o.dataset.type !== type || o.dataset.kind !== kind;
        o.hidden = hide; o.disabled = hide;
        if (hide && o.selected) subject.value = '';
      });
    }
    form.querySelectorAll('input[name="class_type"], input[name="subject_kind"]').forEach(function (r) { r.addEventListener('change', apply); });
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
</body>
</html>