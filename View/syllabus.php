<?php
require_once __DIR__ . "/../PageLogic/syllabus_logic.php";

$sl  = new SyllabusLogic();
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = fn($d) => $d ? date('M j, Y', strtotime($d)) : '';
$fmtShort = fn($d) => $d ? date('M j', strtotime($d)) : '';

$setupError  = false;
$classes     = [];
$current     = null;
$tracker     = null;
$calendar    = null;
$canManage   = false;
$preview     = false;
$canEdit     = false;
$mode        = 'observer';
$copySources = [];
$topicCounts = [];

try {
    $classes   = $sl->classes();
    $current   = $sl->selectedClass($classes);
    $canManage = ($current && $sl->canEdit($current));
    $preview   = ($canManage && ($_GET['preview'] ?? '') === '1');
    $canEdit   = ($canManage && !$preview);
    // staff = faculty editing | student = own progress | preview = faculty looking as a student | observer = anyone else
    $mode      = $canEdit ? 'staff' : ($preview ? 'preview' : ($sl->isStudent ? 'student' : 'observer'));
    if ($current) {
        $tracker  = $sl->tracker((int) $current['schedID'], $mode);
        $calendar = $tracker['calendar'] ?? null;
    }
    if ($canEdit) {   // other classes this person can copy a syllabus from (only those that have one)
        foreach ($classes as $c) {
            if ((int) $c['schedID'] !== (int) $current['schedID']) $copySources[] = $c;
        }
        $topicCounts = $sl->topicCounts(array_column($copySources, 'schedID'));
        $copySources = array_values(array_filter($copySources, fn($c) => ($topicCounts[(int) $c['schedID']] ?? 0) > 0));
    }
} catch (PDOException $ex) {
    error_log('Syllabus page: ' . $ex->getMessage());
    $setupError = true;
}

$topicUrl = fn($id) => BASE_PATH . '/syllabus-topic?id=' . (int) $id . ($preview ? '&preview=1' : '');
$sum = $tracker['summary'] ?? [
    'mode' => $mode, 'total' => 0, 'done' => 0, 'progress' => 0, 'upcoming' => 0, 'remaining' => 0, 'pct' => 0,
    'overdue' => 0, 'materials' => 0, 'students' => 0, 'current' => null, 'next' => null, 'next_open' => null, 'people' => [],
    'open_topics' => 0, 'help' => [], 'hard' => [],
    'terms' => [
        'midterm' => ['modules' => 0, 'total' => 0, 'done' => 0, 'class_done' => 0, 'pct' => 0],
        'final'   => ['modules' => 0, 'total' => 0, 'done' => 0, 'class_done' => 0, 'pct' => 0],
    ],
];

$badgeLabels = ['completed' => 'COMPLETED', 'progress' => 'IN PROGRESS', 'not-started' => 'NOT STARTED', 'upcoming' => 'UPCOMING', 'open' => 'OPEN', 'closed' => 'PAST DEADLINE'];
$badgeClass  = ['completed' => 'status-completed', 'progress' => 'status-progress', 'not-started' => 'status-not-started', 'upcoming' => 'status-upcoming', 'open' => 'status-open', 'closed' => 'status-closed'];

$filters = ($mode === 'staff')
    ? [['open', 'dot-green', 'Open'], ['upcoming', 'dot-purple', 'Upcoming'], ['closed', 'dot-gray', 'Past deadline']]
    : [['completed', 'dot-green', 'Completed'], ['progress', 'dot-yellow', 'In Progress'], ['not-started', 'dot-gray', 'Not Started'], ['upcoming', 'dot-purple', 'Upcoming']];

$completionLabel = ($mode === 'student') ? 'Your Completion' : (($mode === 'staff') ? 'Class Average' : 'Overall Completion');
$classUrl = $current ? BASE_PATH . '/syllabus?class=' . (int) $current['schedID'] : BASE_PATH . '/syllabus';

$modulesByTerm = ['midterm' => [], 'final' => []];
if ($tracker && !empty($tracker['modules'])) {
    foreach ($tracker['modules'] as $m) {
        $tk = ($m['term'] ?? 'midterm') === 'final' ? 'final' : 'midterm';
        $modulesByTerm[$tk][] = $m;
    }
}

$calWeeksJson = json_encode($calendar ? $calendar['weeks'] : new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$semLabel = $current ? ($current['acad_year'] . ' – Semester ' . (int) $current['sem']) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Syllabus Tracker</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/syllabus.css">
</head>
<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar">

        <div class="syllabus-wrapper"
             data-endpoint="<?= BASE_PATH ?>/syllabus"
             data-csrf="<?= $e(csrfToken()) ?>"
             data-sched="<?= $current ? (int) $current['schedID'] : 0 ?>"
             <?= $canEdit ? 'data-edit="1"' : '' ?>>

            <!-- Header: class dropdown + overall completion -->
            <div class="syl-header">
                <div>
                    <div class="title-row">
                        <h1>SYLLABUS TRACKER</h1>
                        <?php if ($canManage): ?>
                            <?php if ($preview): ?>
                                <span class="role-chip preview">STUDENT PREVIEW</span>
                                <a class="preview-link" href="<?= $e($classUrl) ?>">Back to faculty view</a>
                            <?php else: ?>
                                <span class="role-chip">FACULTY VIEW</span>
                                <a class="preview-link" href="<?= $e($classUrl . '&preview=1') ?>">Preview as student</a>
                                <a class="preview-link" href="#" data-action="open-calendar">Academic calendar</a>
                                <?php if ($copySources): ?><a class="preview-link" href="#" data-action="open-copy">Copy from another class</a><?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($classes): ?>
                    <form method="get" action="<?= BASE_PATH ?>/syllabus" class="course-info">
                        <label for="classSelect" class="sr-only">Course, year and section</label>
                        <select id="classSelect" name="class">
                            <?php
                            $group = null;
                            foreach ($classes as $c):
                                $g = $c['acad_year'] . ' - Semester ' . $c['sem'];
                                if ($g !== $group):
                                    if ($group !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . $e($g) . '">';
                                    $group = $g;
                                endif;
                                $sel = ($current && (int) $current['schedID'] === (int) $c['schedID']) ? ' selected' : '';
                            ?>
                                <option value="<?= (int) $c['schedID'] ?>"<?= $sel ?>><?= $e($c['label']) ?></option>
                            <?php endforeach;
                            if ($group !== null) echo '</optgroup>'; ?>
                        </select>
                        <?php if ($preview): ?><input type="hidden" name="preview" value="1"><?php endif; ?>
                        <noscript><button type="submit">Go</button></noscript>
                    </form>
                    <?php else: ?>
                    <div class="course-info">No classes available</div>
                    <?php endif; ?>
                </div>
                <div class="completion-bar-container">
                    <span class="completion-text"><?= $e($completionLabel) ?> <?= (int) $sum['pct'] ?>%<?= $mode === 'staff' ? ' (' . (int) $sum['students'] . ' student' . ($sum['students'] === 1 ? '' : 's') . ')' : '' ?></span>
                    <div class="progress-bg">
                        <div class="progress-fill" style="width: <?= (int) $sum['pct'] ?>%;"></div>
                    </div>
                </div>
            </div>

            <?php if ($setupError): ?>

                <div class="syl-empty">The syllabus data could not be loaded. Import the latest <strong>syllabus_tracker_v5.sql</strong> into <strong>ams_db</strong> (phpMyAdmin), then refresh.</div>

            <?php elseif (!$current): ?>

                <div class="syl-empty">
                    <?php if ($sl->isProf): ?>You have no scheduled classes yet. Ask the program coordinator to assign your classes in Class Schedule.
                    <?php elseif ($sl->isFaculty): ?>No classes have been scheduled yet.
                    <?php else: ?>No classes found for your account yet.<?php endif; ?>
                </div>

            <?php else: ?>

            <?php if ($canEdit && !$calendar): ?>
            <div class="cal-banner">
                <div>
                    <strong>Set up the Academic Calendar for <?= $e($semLabel) ?></strong>
                    <div class="hint">Connect your syllabus to the school calendar so Midterm &amp; Final weeks and topic dates auto-fill instead of being typed by hand.</div>
                </div>
                <button type="button" class="syl-btn inline cta" data-action="open-calendar">Configure Academic Calendar</button>
            </div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
            <!-- Faculty: Academic Calendar panel (shared per acad_year + sem) -->
            <div class="cal-panel is-hidden" id="calendarPanel">
                <div class="cal-panel-head">
                    <div>
                        <strong>&#128197; Academic Calendar &mdash; <?= $e($semLabel) ?></strong>
                        <div class="hint">Shared across all classes in this semester. Picking a calendar week when adding or editing a topic automatically fills its opening date and deadline.</div>
                    </div>
                    <button type="button" class="icon-btn" data-action="close-calendar">Close</button>
                </div>

                <form class="cal-form" id="calendarForm">
                    <div class="cal-grid">
                        <label class="mini">Semester start (Week 1 Day 1)
                            <input class="syl-input date" type="date" name="semester_start" value="<?= $calendar ? $e($calendar['semester_start']) : '' ?>" required>
                        </label>
                        <label class="mini">Midterm weeks (1 to N)
                            <input class="syl-input" type="number" name="midterm_weeks" min="1" max="26" value="<?= $calendar ? (int) $calendar['midterm_weeks'] : 9 ?>">
                        </label>
                        <label class="mini">Total semester weeks
                            <input class="syl-input" type="number" name="total_weeks" min="4" max="26" value="<?= $calendar ? (int) $calendar['total_weeks'] : 18 ?>">
                        </label>
                        <label class="mini">Midterm exam week
                            <input class="syl-input" type="number" name="midterm_exam_week" min="1" max="26" value="<?= ($calendar && $calendar['midterm_exam_week']) ? (int) $calendar['midterm_exam_week'] : 9 ?>" placeholder="e.g. 9">
                        </label>
                        <label class="mini">Final exam week
                            <input class="syl-input" type="number" name="final_exam_week" min="1" max="26" value="<?= ($calendar && $calendar['final_exam_week']) ? (int) $calendar['final_exam_week'] : 18 ?>" placeholder="e.g. 18">
                        </label>
                    </div>
                    <div class="cf-row">
                        <label class="mini check"><input type="checkbox" name="sync_dates" value="1" checked> Update existing topic dates in this class to match</label>
                        <button type="submit" class="syl-btn inline">Save calendar</button>
                        <?php if ($calendar): ?>
                        <button type="button" class="icon-btn" data-action="sync-calendar" title="Recalculate opening dates and deadlines for all topics with a week number">Sync topic dates</button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($calendar): ?>
                <div class="cal-sub">
                    <div class="cal-sub-col">
                        <h4>Start from this calendar</h4>
                        <div class="hint">Create Midterm and Final modules based on this semester&rsquo;s calendar so you have an immediate starting structure for your topics.</div>
                        <div class="cf-row" style="margin-top:6px;">
                            <label class="mini check"><input type="checkbox" id="scaffoldWeeks" checked> Include Week 1&ndash;<?= (int) $calendar['total_weeks'] ?> starter topics</label>
                            <button type="button" class="syl-btn inline" data-action="scaffold-calendar">Generate structure from calendar</button>
                        </div>
                    </div>

                    <div class="cal-sub-col">
                        <h4>Holidays &amp; breaks</h4>
                        <?php if ($calendar['breaks']): ?>
                        <div class="break-list">
                            <?php foreach ($calendar['breaks'] as $bk): ?>
                            <div class="break-item">
                                <span>
                                    <strong><?= $e($bk['title']) ?></strong>
                                    <small><?= $e($fmtShort($bk['start_date'])) ?><?= $bk['end_date'] !== $bk['start_date'] ? ' – ' . $e($fmtShort($bk['end_date'])) : '' ?><?= (int) $bk['skip_week'] === 1 ? ' · shifts weeks' : '' ?></small>
                                </span>
                                <button type="button" class="icon-btn" data-action="delete-break" data-break="<?= (int) $bk['breakID'] ?>" title="Remove break">&#10005;</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="hint">No holidays or breaks added yet.</div>
                        <?php endif; ?>
                        <form class="break-form" id="breakForm">
                            <input class="syl-input grow" type="text" name="title" maxlength="150" placeholder="e.g. Semestral Break or Holy Week">
                            <input class="syl-input date" type="date" name="start_date" aria-label="Start date">
                            <input class="syl-input date" type="date" name="end_date" aria-label="End date">
                            <label class="mini check"><input type="checkbox" name="skip_week" value="1"> Shift later weeks</label>
                            <button type="submit" class="syl-btn inline">Add break</button>
                        </form>
                    </div>
                </div>

                <details class="more-opts" style="margin-top:8px;">
                    <summary>View week-by-week calendar (<?= count($calendar['weeks']) ?> weeks)</summary>
                    <div class="cal-weeks-grid">
                        <?php foreach ($calendar['weeks'] as $cw): ?>
                        <div class="cal-week-pill <?= $cw['term'] === 'final' ? 'term-final' : 'term-midterm' ?><?= $cw['is_exam_week'] ? ' is-exam' : '' ?>">
                            <strong>Week <?= (int) $cw['week_no'] ?></strong>
                            <span><?= $e($fmtShort($cw['start_date'])) ?> &ndash; <?= $e($fmtShort($cw['end_date'])) ?></span>
                            <small><?= $cw['term'] === 'final' ? 'Final' : 'Midterm' ?><?= $cw['exam_label'] ? ' · ' . $e($cw['exam_label']) : '' ?><?= $cw['breaks'] ? ' · ' . $e(implode(', ', $cw['breaks'])) : '' ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($canEdit && $copySources): ?>
            <!-- Faculty: copy a syllabus from another class -->
            <div class="copy-panel<?= $tracker['modules'] ? ' is-hidden' : '' ?>" id="copyPanel">
                <strong>Copy a syllabus from another class</strong>
                <div class="hint">Teaching the same subject to another section? Copy it instead of building it again.</div>
                <select class="syl-input" id="copySource" aria-label="Class to copy from">
                    <?php foreach ($copySources as $c): ?>
                    <option value="<?= (int) $c['schedID'] ?>"><?= $e($c['label']) ?> (<?= (int) ($topicCounts[(int) $c['schedID']] ?? 0) ?> topics)</option>
                    <?php endforeach; ?>
                </select>
                <label class="mini check"><input type="checkbox" id="copyPages" checked> Lesson pages</label>
                <label class="mini check"><input type="checkbox" id="copyFiles" checked> Materials (PDFs and video links)</label>
                <label class="mini check"><input type="checkbox" id="copyQuizzes" checked> Assessments (quizzes, exams &amp; projects, copied as drafts)</label>
                <div class="hint"><?= $calendar ? 'Opening dates and deadlines will be filled from this semester\'s academic calendar.' : 'Opening dates and deadlines are not copied. Set them for this class after copying.' ?></div>
                <div class="cf-row">
                    <button type="button" class="syl-btn inline" data-action="run-copy">Copy into this class</button>
                    <button type="button" class="icon-btn" data-action="close-copy">Close</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="content-grid">

                <!-- Left column -->
                <div class="left-column">

                    <div class="search-filter">
                        <input type="text" class="search-bar" placeholder="Search topics..." aria-label="Search topics">
                        <div class="filter-bar-row">
                            <div class="term-tabs" role="tablist" aria-label="Filter by term">
                                <button type="button" class="term-tab active" data-term-filter="">All</button>
                                <button type="button" class="term-tab" data-term-filter="midterm">Midterm (<?= (int) $sum['terms']['midterm']['total'] ?>)</button>
                                <button type="button" class="term-tab" data-term-filter="final">Final (<?= (int) $sum['terms']['final']['total'] ?>)</button>
                            </div>
                            <div class="filters">
                                <?php foreach ($filters as $f): ?>
                                <div class="filter-item" data-filter="<?= $e($f[0]) ?>" role="button" tabindex="0"><div class="dot <?= $e($f[1]) ?>"></div> <?= $e($f[2]) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$tracker['modules']): ?>
                        <div class="syl-empty">
                            <?php if ($canEdit): ?>
                                No modules yet. Add your first module below<?= $calendar ? ', generate a Midterm &amp; Final structure from the Academic Calendar above' : '' ?><?= $copySources ? ', or copy a syllabus from another class' : '' ?>.
                            <?php else: ?>
                                The instructor hasn't published a syllabus for this class yet.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach (['midterm' => 'Midterm', 'final' => 'Final'] as $termKey => $termTitle):
                        $termMods = $modulesByTerm[$termKey];
                        if (!$termMods) continue;
                        $ts = $sum['terms'][$termKey];
                        $termRange = '';
                        if ($calendar) {
                            $mw = (int) $calendar['midterm_weeks'];
                            $tw = (int) $calendar['total_weeks'];
                            $termRange = ($termKey === 'midterm')
                                ? 'Weeks 1–' . $mw
                                : ($tw > $mw ? 'Weeks ' . ($mw + 1) . '–' . $tw : '');
                        }
                    ?>
                    <div class="term-section" data-term-section="<?= $termKey ?>">
                        <div class="term-header">
                            <div class="term-header-left">
                                <span class="term-pill <?= $termKey === 'final' ? 'is-final' : 'is-midterm' ?>"><?= strtoupper($termTitle) ?></span>
                                <?php if ($termRange !== ''): ?><span class="term-range"><?= $e($termRange) ?></span><?php endif; ?>
                            </div>
                            <div class="term-header-right">
                                <span class="mod-pct">
                                    <?php if ($mode === 'student'): ?><?= (int) $ts['done'] ?>/<?= (int) $ts['total'] ?> topics &middot; <?= (int) $ts['pct'] ?>%
                                    <?php elseif ($mode === 'staff'): ?><?= (int) $ts['total'] ?> topic<?= $ts['total'] === 1 ? '' : 's' ?> &middot; class avg <?= (int) $ts['pct'] ?>%
                                    <?php else: ?><?= (int) $ts['total'] ?> topic<?= $ts['total'] === 1 ? '' : 's' ?><?php endif; ?>
                                </span>
                                <div class="term-bar"><div style="width: <?= (int) $ts['pct'] ?>%;"></div></div>
                            </div>
                        </div>

                        <?php foreach ($termMods as $m):
                            $otherTerm = ($termKey === 'midterm') ? 'final' : 'midterm';
                            $otherLabel = ($termKey === 'midterm') ? 'Final' : 'Midterm';
                        ?>
                        <div class="syl-module" data-term="<?= $termKey ?>">
                            <h2>
                                <span class="mod-title">&#128230; <?= $e($m['title']) ?></span>
                                <span class="mod-pct">
                                    <?php if ($mode === 'student'): ?><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> topics &middot; <?= (int) $m['pct'] ?>%
                                    <?php elseif ($mode === 'staff'): ?><?= (int) $m['total'] ?> topic<?= $m['total'] === 1 ? '' : 's' ?> &middot; class avg <?= (int) $m['pct'] ?>%
                                    <?php else: ?><?= (int) $m['total'] ?> topic<?= $m['total'] === 1 ? '' : 's' ?><?php endif; ?>
                                </span>
                                <?php if ($canEdit): ?>
                                <span class="mod-actions">
                                    <button type="button" class="icon-btn" data-action="move-module-term" data-module="<?= (int) $m['moduleID'] ?>" data-term="<?= $otherTerm ?>" title="Move module to <?= $otherLabel ?>">&rarr; <?= $otherLabel ?></button>
                                    <button type="button" class="icon-btn" data-action="rename-module" data-module="<?= (int) $m['moduleID'] ?>" data-title="<?= $e($m['title']) ?>" title="Rename module">&#9998;</button>
                                    <button type="button" class="icon-btn" data-action="delete-module" data-module="<?= (int) $m['moduleID'] ?>" title="Delete module">&#10005;</button>
                                </span>
                                <?php endif; ?>
                            </h2>
                            <div class="mod-bar"><div style="width: <?= (int) $m['pct'] ?>%;"></div></div>

                            <?php foreach ($m['tasks'] as $t):
                                $locked = ($t['locked'] && $mode !== 'staff');
                                $search = mb_strtolower($t['title'] . ' ' . $t['week_label'] . ' ' . $termTitle);

                                $hasQuiz = !empty($t['quizzes']['quiz']);
                                $hasExam = !empty($t['quizzes']['exam']);
                                $hasProj = !empty($t['project']);

                                // the one thing a student should do next on this topic
                                $action = '';
                                if ($mode === 'student' && !$locked && !$t['done']) {
                                    if ($t['restudy'])                    $action = 'Study again';
                                    elseif ($t['view'] === 'progress') {
                                        if ($hasQuiz)                     $action = 'Take the quiz';
                                        elseif ($hasExam)                 $action = 'Take the exam';
                                        elseif ($hasProj)                 $action = 'Submit project';
                                        else                              $action = 'Continue';
                                    } else {
                                        $action = 'Start';
                                    }
                                }
                            ?>
                            <div class="topic" id="topic-<?= (int) $t['taskID'] ?>" data-status="<?= $e($t['view']) ?>" data-term="<?= $termKey ?>" data-text="<?= $e($search) ?>">

                                <div class="week-item<?= $canEdit ? ' editable' : '' ?><?= $locked ? ' is-locked' : '' ?>"<?= $locked ? '' : ' data-href="' . $e($topicUrl($t['taskID'])) . '"' ?>>
                                    <div class="week-label">
                                        <?= $e($t['week_label']) ?>
                                        <?php if (!empty($t['cal_week']['exam_label'])): ?>
                                            <small class="week-sub exam"><?= $e($t['cal_week']['exam_label']) ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="topic-cell">
                                        <div class="view-fields">
                                            <?php if ($locked): ?>
                                                <span class="topic-name">&#128274; <?= $e($t['title']) ?></span>
                                            <?php else: ?>
                                                <a class="topic-name topic-link" href="<?= $e($topicUrl($t['taskID'])) ?>"><?= $e($t['title']) ?></a>
                                            <?php endif; ?>

                                            <?php if ($hasQuiz || $hasExam || $hasProj): ?>
                                            <div class="assess-chips">
                                                <?php if ($hasQuiz): ?><span class="chip assess-chip">Quiz</span><?php endif; ?>
                                                <?php if ($hasExam): ?><span class="chip assess-chip exam">Exam</span><?php endif; ?>
                                                <?php if ($hasProj): ?><span class="chip assess-chip proj">Project</span><?php endif; ?>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($t['available_from'] || $t['due_date']): ?>
                                            <small class="due<?= $t['overdue'] ? ' overdue' : '' ?>">
                                                <?php if ($t['available_from'] && ($t['locked'] || $canEdit)): ?><?= $t['locked'] ? 'Opens' : 'Opened' ?> <?= $e($fmt($t['available_from'])) ?><?php endif; ?>
                                                <?= ($t['available_from'] && ($t['locked'] || $canEdit) && $t['due_date']) ? ' &middot; ' : '' ?>
                                                <?php if ($t['due_date']): ?>Due <?= $e($fmt($t['due_date'])) ?><?= $t['overdue'] ? ' (overdue)' : '' ?><?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($canEdit): ?>
                                        <div class="edit-fields">
                                            <input class="syl-input grow" type="text" name="title" maxlength="250" value="<?= $e($t['title']) ?>" aria-label="Topic title">
                                            <?php if ($calendar): ?>
                                            <select class="syl-input cal-week-select" name="week_no" aria-label="Academic calendar week">
                                                <option value="">No week</option>
                                                <optgroup label="Midterm">
                                                    <?php foreach ($calendar['weeks'] as $cw): if ($cw['term'] !== 'midterm') continue; ?>
                                                    <option value="<?= (int) $cw['week_no'] ?>"<?= ((int) $t['week_no'] === (int) $cw['week_no']) ? ' selected' : '' ?>>Week <?= (int) $cw['week_no'] ?> (<?= $e($fmtShort($cw['start_date'])) ?>–<?= $e($fmtShort($cw['end_date'])) ?>)<?= $cw['exam_label'] ? ' ★' : '' ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <optgroup label="Final">
                                                    <?php foreach ($calendar['weeks'] as $cw): if ($cw['term'] !== 'final') continue; ?>
                                                    <option value="<?= (int) $cw['week_no'] ?>"<?= ((int) $t['week_no'] === (int) $cw['week_no']) ? ' selected' : '' ?>>Week <?= (int) $cw['week_no'] ?> (<?= $e($fmtShort($cw['start_date'])) ?>–<?= $e($fmtShort($cw['end_date'])) ?>)<?= $cw['exam_label'] ? ' ★' : '' ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                            <?php else: ?>
                                            <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" value="<?= $t['week_no'] !== null ? (int) $t['week_no'] : '' ?>" aria-label="Week number">
                                            <?php endif; ?>
                                            <label class="mini">Opens on <input class="syl-input date" type="date" name="available_from" value="<?= $e($t['available_from']) ?>"></label>
                                            <label class="mini">Deadline <input class="syl-input date" type="date" name="due_date" value="<?= $e($t['due_date']) ?>"></label>
                                            <button type="button" class="syl-btn inline" data-action="save-task" data-task="<?= (int) $t['taskID'] ?>">Save</button>
                                            <button type="button" class="syl-btn inline" data-action="cancel-edit">Cancel</button>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($canEdit): ?>
                                        <div>
                                            <div class="agg">
                                                <strong><?= (int) $t['done_n'] ?>/<?= (int) $sum['students'] ?></strong> completed<br>
                                                <?= (int) $t['opened_n'] ?> opened
                                            </div>
                                            <div class="status-badge <?= $e($badgeClass[$t['view']]) ?>"><?= $e($badgeLabels[$t['view']]) ?></div>
                                        </div>
                                        <div class="row-actions">
                                            <button type="button" class="icon-btn" data-action="edit-task" title="Edit topic and dates">&#9998;</button>
                                            <button type="button" class="icon-btn" data-action="delete-task" data-task="<?= (int) $t['taskID'] ?>" title="Delete topic">&#10005;</button>
                                        </div>
                                    <?php else: ?>
                                        <div class="status-cell">
                                            <div class="status-badge <?= $e($badgeClass[$t['view']]) ?>"><?= $e($badgeLabels[$t['view']]) ?></div>
                                            <?php if ($action !== ''): ?><div class="next-action"><?= $e($action) ?> &rsaquo;</div><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                            <?php endforeach; ?>

                            <?php if (!$m['tasks'] && !$canEdit): ?>
                                <div class="milestone-subtext">No topics in this module yet.</div>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                            <form class="task-form" data-module="<?= (int) $m['moduleID'] ?>" data-term="<?= $termKey ?>">
                                <input class="syl-input grow" type="text" name="title" maxlength="250" placeholder="New topic title" aria-label="New topic title">
                                <?php if ($calendar): ?>
                                <select class="syl-input cal-week-select" name="week_no" aria-label="Academic calendar week">
                                    <option value="">Pick calendar week...</option>
                                    <optgroup label="<?= $termTitle ?> (this term)">
                                        <?php foreach ($calendar['weeks'] as $cw): if ($cw['term'] !== $termKey) continue; ?>
                                        <option value="<?= (int) $cw['week_no'] ?>">Week <?= (int) $cw['week_no'] ?> (<?= $e($fmtShort($cw['start_date'])) ?>–<?= $e($fmtShort($cw['end_date'])) ?>)<?= $cw['exam_label'] ? ' · ' . $e($cw['exam_label']) : '' ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="<?= $otherLabel ?> (other term)">
                                        <?php foreach ($calendar['weeks'] as $cw): if ($cw['term'] === $termKey) continue; ?>
                                        <option value="<?= (int) $cw['week_no'] ?>">Week <?= (int) $cw['week_no'] ?> (<?= $e($fmtShort($cw['start_date'])) ?>–<?= $e($fmtShort($cw['end_date'])) ?>)<?= $cw['exam_label'] ? ' · ' . $e($cw['exam_label']) : '' ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <?php else: ?>
                                <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" aria-label="Week number">
                                <?php endif; ?>
                                <label class="mini">Opens on <input class="syl-input date" type="date" name="available_from"></label>
                                <label class="mini">Deadline <input class="syl-input date" type="date" name="due_date"></label>
                                <button type="submit" class="syl-btn inline">Add topic</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($canEdit): ?>
                    <div class="syl-module add-module-card">
                        <h2><span class="mod-title">&#10133; Add a module</span></h2>
                        <form class="module-form">
                            <select class="syl-input" name="term" aria-label="Term">
                                <option value="midterm">Midterm</option>
                                <option value="final">Final</option>
                            </select>
                            <input class="syl-input grow" type="text" name="title" maxlength="200" placeholder="e.g. Module 1: Introduction to OOP" aria-label="New module title">
                            <button type="submit" class="syl-btn inline">Add module</button>
                        </form>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Right column -->
                <div class="right-column">

                    <?php if ($calendar): ?>
                    <div class="syl-widget cal-widget">
                        <h3>&#128197; Academic Calendar</h3>
                        <?php if ($calendar['current_week']): $cw = $calendar['current_week']; ?>
                            <div class="stats-text">
                                <span class="term-pill sm <?= $cw['term'] === 'final' ? 'is-final' : 'is-midterm' ?>"><?= $cw['term'] === 'final' ? 'FINAL' : 'MIDTERM' ?></span>
                                <strong>Week <?= (int) $cw['week_no'] ?> of <?= (int) $calendar['total_weeks'] ?></strong>
                            </div>
                            <div class="milestone-subtext"><?= $e($fmt($cw['start_date'])) ?> &ndash; <?= $e($fmt($cw['end_date'])) ?></div>
                            <?php if ($cw['exam_label']): ?>
                                <div class="chip warn" style="margin-top:6px;"><?= $e($cw['exam_label']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="stats-text"><?= $e($fmt($calendar['semester_start'])) ?> &ndash; <?= $e($fmt($calendar['semester_end'])) ?></div>
                            <div class="milestone-subtext">Midterm: Weeks 1&ndash;<?= (int) $calendar['midterm_weeks'] ?> &middot; Final: Weeks <?= min((int) $calendar['total_weeks'], (int) $calendar['midterm_weeks'] + 1) ?>&ndash;<?= (int) $calendar['total_weeks'] ?></div>
                        <?php endif; ?>
                        <?php if ($calendar['midterm_exam_week'] && isset($calendar['weeks'][(int) $calendar['midterm_exam_week']])):
                            $mew = $calendar['weeks'][(int) $calendar['midterm_exam_week']]; ?>
                            <div class="milestone-subtext" style="margin-top:8px;">Midterm Exams: Week <?= (int) $mew['week_no'] ?> (<?= $e($fmtShort($mew['start_date'])) ?>&ndash;<?= $e($fmtShort($mew['end_date'])) ?>)</div>
                        <?php endif; ?>
                        <?php if ($calendar['final_exam_week'] && isset($calendar['weeks'][(int) $calendar['final_exam_week']])):
                            $few = $calendar['weeks'][(int) $calendar['final_exam_week']]; ?>
                            <div class="milestone-subtext">Final Exams: Week <?= (int) $few['week_no'] ?> (<?= $e($fmtShort($few['start_date'])) ?>&ndash;<?= $e($fmtShort($few['end_date'])) ?>)</div>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <button type="button" class="syl-btn" data-action="open-calendar">Edit academic calendar</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="syl-widget">
                        <h3>Quick Stats</h3>
                        <?php if ($mode === 'student'): ?>
                            <div class="stats-text"><strong><?= (int) $sum['done'] ?>/<?= (int) $sum['total'] ?></strong> Topics Completed</div>
                            <div class="stats-text"><strong><?= (int) $sum['terms']['midterm']['pct'] ?>%</strong> Midterm &middot; <strong><?= (int) $sum['terms']['final']['pct'] ?>%</strong> Final</div>
                            <div class="stats-text"><strong><?= (int) $sum['progress'] ?></strong> In Progress</div>
                            <div class="stats-text"><strong><?= (int) $sum['remaining'] ?></strong> Not Started</div>
                            <div class="stats-text"><strong><?= (int) $sum['upcoming'] ?></strong> Upcoming</div>
                            <?php if ($sum['overdue'] > 0): ?>
                            <div class="stats-text" style="color:#ff6b6b;"><strong><?= (int) $sum['overdue'] ?></strong> Overdue</div>
                            <?php endif; ?>
                        <?php elseif ($mode === 'staff'): ?>
                            <div class="stats-text"><strong><?= (int) $sum['total'] ?></strong> Topic<?= $sum['total'] === 1 ? '' : 's' ?> (<?= (int) $sum['terms']['midterm']['total'] ?> Midterm, <?= (int) $sum['terms']['final']['total'] ?> Final)</div>
                            <div class="stats-text"><strong><?= (int) $sum['students'] ?></strong> Enrolled Student<?= $sum['students'] === 1 ? '' : 's' ?></div>
                            <div class="stats-text"><strong><?= (int) $sum['pct'] ?>%</strong> Class Average Completion</div>
                            <div class="stats-text"><strong><?= (int) $sum['upcoming'] ?></strong> Upcoming (locked)</div>
                        <?php else: ?>
                            <div class="stats-text"><strong><?= (int) $sum['total'] ?></strong> Topic<?= $sum['total'] === 1 ? '' : 's' ?></div>
                            <div class="stats-text"><strong><?= (int) $sum['upcoming'] ?></strong> Upcoming (locked)</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($mode === 'student'): ?>
                    <div class="syl-widget">
                        <h3>Current Topic</h3>
                        <?php $cur = $sum['current']; ?>
                        <?php if ($cur): ?>
                            <div class="stats-text"><?= $e($cur['week_label']) ?>: <?= $e($cur['title']) ?></div>
                            <div class="milestone-subtext" style="margin-bottom: 15px;">
                                <?= $e($cur['module_title']) ?><br>
                                <?= $cur['due_date'] ? 'Deadline: ' . $e($fmt($cur['due_date'])) : 'No deadline set' ?>
                            </div>
                            <a class="syl-btn cta" href="<?= $e($topicUrl($cur['taskID'])) ?>"><?= $cur['view'] === 'progress' ? 'Continue' : 'Start' ?></a>
                        <?php elseif ($sum['total'] > 0 && $sum['done'] === $sum['total']): ?>
                            <div class="stats-text">All topics are completed. Well done!</div>
                        <?php elseif ($sum['total'] > 0): ?>
                            <div class="stats-text">Nothing is open right now.</div>
                            <?php if ($sum['next_open']): ?>
                            <div class="milestone-subtext">Next topic opens <?= $e($fmt($sum['next_open']['available_from'])) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="stats-text">No topics yet.</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($mode === 'staff'): ?>
                    <?php if ($sum['help']): ?>
                    <div class="syl-widget">
                        <h3>Who needs help</h3>
                        <div class="help-list">
                            <?php foreach ($sum['help'] as $h): ?>
                            <div class="help-item">
                                <strong><?= $e($h['name']) ?></strong>
                                <?php foreach ($h['reasons'] as $rs): ?><span class="chip warn"><?= $e($rs) ?></span><?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($sum['hard']): ?>
                    <div class="syl-widget">
                        <h3>Most-missed questions</h3>
                        <?php foreach ($sum['hard'] as $hq): ?>
                        <div class="help-item">
                            <div><?= $e(mb_strimwidth($hq['prompt'], 0, 110, '...')) ?></div>
                            <span class="chip bad">Missed <?= (int) $hq['rate'] ?>% of the time</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="syl-widget">
                        <h3>Class Progress</h3>
                        <?php if (!$sum['people']): ?>
                            <div class="milestone-subtext">No students are enrolled in this class yet.</div>
                        <?php else: ?>
                        <div class="people-list">
                            <?php foreach ($sum['people'] as $pp): ?>
                            <div class="person">
                                <div class="person-head"><?= $e($pp['name']) ?> <span><?= (int) $pp['done'] ?>/<?= (int) $sum['total'] ?> &middot; <?= (int) $pp['pct'] ?>%</span></div>
                                <div class="mod-bar"><div style="width: <?= (int) $pp['pct'] ?>%;"></div></div>
                                <?php if ($pp['last']): ?><small class="milestone-subtext">Last active <?= $e(date('M j, g:i A', strtotime($pp['last']))) ?></small><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="syl-widget">
                        <h3>Upcoming Deadline</h3>
                        <?php $next = $sum['next']; ?>
                        <?php if ($next): ?>
                            <div class="stats-text" style="margin-bottom: 0;"><?= $e($next['title']) ?></div>
                            <div class="milestone-subtext<?= $next['days_left'] <= 1 ? ' soon' : '' ?>">
                                <?= $e($fmt($next['due_date'])) ?><br>
                                <?php if ($next['days_left'] === 0): ?>due today
                                <?php else: ?>in <?= (int) $next['days_left'] ?> day<?= $next['days_left'] === 1 ? '' : 's' ?><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="milestone-subtext">No upcoming deadlines.</div>
                        <?php endif; ?>
                        <?php if ($sum['next_open']): ?>
                            <div class="milestone-subtext" style="margin-top:10px;">Next to open: <?= $e($sum['next_open']['title']) ?> on <?= $e($fmt($sum['next_open']['available_from'])) ?></div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <script type="application/json" id="calendarWeeksData"><?= $calWeeksJson ?></script>

            <?php endif; ?>
        </div>

      </main>
    </div>
  </div>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
  <script src="<?= BASE_PATH ?>/View/script/syllabus.js" defer></script>
</body>
</html>
