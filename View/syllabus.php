<?php
require_once __DIR__ . "/../PageLogic/syllabus_logic.php";

$sl  = new SyllabusLogic();
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = fn($d) => $d ? date('M j, Y', strtotime($d)) : '';

$setupError = false;
$classes    = [];
$current    = null;
$tracker    = null;

try {
    $classes = $sl->classes();
    $current = $sl->selectedClass($classes);
    if ($current) {
        $tracker = $sl->tracker((int) $current['schedID']);
    }
} catch (PDOException $ex) {
    error_log('Syllabus page: ' . $ex->getMessage());
    $setupError = true;
}

$canEdit = (!$setupError && $current && $sl->canEdit($current));
$sum     = $tracker['summary'] ?? ['total' => 0, 'done' => 0, 'progress' => 0, 'remaining' => 0, 'pct' => 0, 'overdue' => 0, 'current' => null, 'next' => null];

$badgeLabels = ['completed' => 'COMPLETED', 'progress' => 'IN PROGRESS', 'not-started' => 'NOT STARTED', 'upcoming' => 'UPCOMING'];
$badgeClass  = ['completed' => 'status-completed', 'progress' => 'status-progress', 'not-started' => 'status-not-started', 'upcoming' => 'status-upcoming'];
$dbStatuses  = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Syllabus Tracker</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">

  <style>
    /* --- Syllabus Tracker Specific Styles --- */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

    .syllabus-wrapper {
        font-family: 'Inter', sans-serif;
        color: #ffffff;
        display: flex;
        flex-direction: column;
        gap: 20px;
        width: 100%;
        box-sizing: border-box;
        padding: 10px;
    }

    .syl-header {
        background-color: #2c1a38;
        padding: 20px;
        border-radius: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .syl-header h1 {
        margin: 0;
        font-size: 24px;
        letter-spacing: 1px;
    }

    .course-info {
        background-color: #1a0f2e;
        padding: 10px 20px;
        border-radius: 20px;
        font-size: 14px;
        margin-top: 10px;
        display: inline-block;
    }

    .completion-bar-container {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .completion-text {
        font-size: 14px;
    }

    .progress-bg {
        width: 200px;
        height: 8px;
        background-color: #1a0f2e;
        border-radius: 10px;
        border: 1px solid #4a2b5e;
        overflow: hidden;
    }

    .progress-fill {
        width: 0;
        height: 100%;
        background-color: #ffffff;
        border-radius: 10px;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
    }

    .left-column {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Search & Filter Bar */
    .search-filter {
        background-color: #2c1a38;
        padding: 15px;
        border-radius: 15px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .search-bar {
        width: 100%;
        padding: 10px;
        background-color: #000000;
        border: 1px solid #4a2b5e;
        border-radius: 8px;
        color: #fff;
        margin-bottom: 15px;
        box-sizing: border-box;
    }

    .filters {
        display: flex;
        gap: 15px;
    }

    .filter-item {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        color: #b0a3c2;
    }

    .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }
    .dot-green { background-color: #00ff00; }
    .dot-yellow { background-color: #ffcc00; }
    .dot-gray { background-color: #888888; }
    .dot-purple { background-color: #a855f7; }

    /* Modules */
    .syl-module {
        background-color: #2c1a38;
        border-radius: 15px;
        padding: 20px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .syl-module h2 {
        margin: 0 0 15px 0;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .week-item {
        display: grid;
        grid-template-columns: 80px 1fr 120px;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #3a1c4a;
    }

    .week-item:last-child {
        border-bottom: none;
    }

    .week-label {
        font-weight: 600;
        font-size: 14px;
    }

    .topic-name {
        font-size: 14px;
        color: #d1c4e9;
    }

    .status-badge {
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 10px;
        font-weight: 600;
        text-align: center;
        text-transform: uppercase;
    }

    .status-completed {
        border: 1px solid #00ff00;
        color: #00ff00;
    }

    .status-progress {
        background-color: #ffcc00;
        color: #000;
    }

    .status-upcoming {
        border: 1px solid #a855f7;
        color: #a855f7;
    }

    .status-not-started {
        background-color: #e0e0e0;
        color: #000;
    }

    /* Right Column Widgets */
    .right-column {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .syl-widget {
        background-color: #2c1a38;
        border-radius: 15px;
        padding: 20px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .syl-widget h3 {
        margin: 0 0 15px 0;
        font-size: 16px;
    }

    .stats-text {
        font-size: 14px;
        margin: 10px 0;
        color: #d1c4e9;
    }

    .syl-btn {
        display: block;
        width: 100%;
        padding: 10px;
        margin-top: 10px;
        background-color: #3a1c4a;
        border: 1px solid #5a3b7a;
        color: #fff;
        border-radius: 8px;
        cursor: pointer;
        text-align: center;
        font-size: 14px;
        transition: background 0.2s;
    }

    .syl-btn:hover {
        background-color: #4a2b5e;
    }

    .milestone-subtext {
        font-size: 12px;
        color: #b0a3c2;
        margin-top: 5px;
    }

    /* Responsive adjustments if the screen gets too small */
    @media (max-width: 1024px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
    }
  
    /* ---------- Functional additions ---------- */
    .is-hidden { display: none !important; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }

    .course-info { padding: 6px 14px; max-width: 100%; }
    .course-info select {
        background: transparent; color: #fff; border: none; outline: none;
        font: inherit; font-size: 14px; cursor: pointer; max-width: 100%;
    }
    .course-info select option, .course-info select optgroup { background: #1a0f2e; color: #fff; }

    .filter-item { cursor: pointer; user-select: none; padding: 3px 10px; border-radius: 14px; border: 1px solid transparent; }
    .filter-item:hover { color: #fff; }
    .filter-item.active { border-color: #a855f7; color: #fff; background: rgba(168, 85, 247, 0.15); }

    .syl-module h2 .mod-title { flex: 1; }
    .mod-pct { font-size: 12px; font-weight: 400; color: #b0a3c2; white-space: nowrap; }
    .mod-actions { display: flex; gap: 6px; }
    .mod-bar { height: 6px; background: #1a0f2e; border-radius: 6px; overflow: hidden; margin-bottom: 10px; }
    .mod-bar > div { height: 100%; background: #a855f7; border-radius: 6px; }

    .week-item.editable { grid-template-columns: 80px 1fr 150px 70px; gap: 10px; }
    .topic-cell small.due { display: block; color: #b0a3c2; font-size: 11px; margin-top: 2px; }
    .topic-cell small.due.overdue { color: #ff6b6b; }

    .status-select {
        background: #1a0f2e; color: #fff; border: 1px solid #4a2b5e; border-radius: 15px;
        padding: 5px 8px; font-size: 12px; width: 100%; cursor: pointer;
    }
    .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
    .icon-btn {
        background: none; border: 1px solid #4a2b5e; color: #d1c4e9; border-radius: 6px;
        cursor: pointer; padding: 3px 8px; font-size: 13px;
    }
    .icon-btn:hover { background: #4a2b5e; color: #fff; }

    .edit-fields { display: none; gap: 8px; flex-wrap: wrap; align-items: center; }
    .week-item.editing .edit-fields { display: flex; }
    .week-item.editing .view-fields { display: none; }

    .syl-input {
        background: #000; border: 1px solid #4a2b5e; border-radius: 8px; color: #fff;
        padding: 8px 10px; font-size: 13px; box-sizing: border-box; font-family: inherit;
    }
    .syl-input.grow { flex: 1; min-width: 160px; }
    .syl-input.sm { width: 90px; }
    .syl-input.date { width: 150px; color-scheme: dark; }

    .task-form, .module-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .task-form { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #4a2b5e; }
    .syl-btn.inline { display: inline-block; width: auto; margin-top: 0; padding: 8px 14px; }

    .syl-empty {
        background: #2c1a38; border: 1px dashed #5a3b7a; border-radius: 15px;
        padding: 30px; text-align: center; color: #d1c4e9; font-size: 14px;
    }
    .syl-toast {
        position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
        background: #2c1a38; border: 1px solid #a855f7; color: #fff;
        padding: 10px 18px; border-radius: 10px; z-index: 9999; font-size: 14px;
    }
    .syl-toast.error { border-color: #ff6b6b; }

    @media (max-width: 700px) {
        .week-item, .week-item.editable { grid-template-columns: 1fr; gap: 6px; }
        .row-actions { justify-content: flex-start; }
    }
</style>
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
                    <h1>SYLLABUS TRACKER</h1>
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
                        <noscript><button type="submit">Go</button></noscript>
                    </form>
                    <?php else: ?>
                    <div class="course-info">No classes available</div>
                    <?php endif; ?>
                </div>
                <div class="completion-bar-container">
                    <span class="completion-text">Overall Completion <?= (int) $sum['pct'] ?>%</span>
                    <div class="progress-bg">
                        <div class="progress-fill" style="width: <?= (int) $sum['pct'] ?>%;"></div>
                    </div>
                </div>
            </div>

            <?php if ($setupError): ?>

                <div class="syl-empty">The syllabus data could not be loaded. Import <strong>syllabus_tracker.sql</strong> into <strong>ams_db</strong> (phpMyAdmin), then refresh.</div>

            <?php elseif (!$current): ?>

                <div class="syl-empty">
                    <?php if ($sl->isProf): ?>You have no scheduled classes yet. Ask the program coordinator to assign your classes in Class Schedule.
                    <?php else: ?>No classes found for your account yet.<?php endif; ?>
                </div>

            <?php else: ?>

            <div class="content-grid">

                <!-- Left column -->
                <div class="left-column">

                    <div class="search-filter">
                        <input type="text" class="search-bar" placeholder="Search topics..." aria-label="Search topics">
                        <div class="filters">
                            <div class="filter-item" data-filter="completed" role="button" tabindex="0"><div class="dot dot-green"></div> Completed</div>
                            <div class="filter-item" data-filter="progress" role="button" tabindex="0"><div class="dot dot-yellow"></div> In Progress</div>
                            <div class="filter-item" data-filter="not-started" role="button" tabindex="0"><div class="dot dot-gray"></div> Not Started</div>
                            <div class="filter-item" data-filter="upcoming" role="button" tabindex="0"><div class="dot dot-purple"></div> Upcoming</div>
                        </div>
                    </div>

                    <?php if (!$tracker['modules']): ?>
                        <div class="syl-empty">
                            <?php if ($canEdit): ?>No modules yet. Add your first module below to start building this class's syllabus.
                            <?php else: ?>The instructor hasn't published a syllabus for this class yet.<?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($tracker['modules'] as $m): ?>
                    <div class="syl-module">
                        <h2>
                            <span class="mod-title">&#128230; <?= $e($m['title']) ?></span>
                            <span class="mod-pct"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> tasks &middot; <?= (int) $m['pct'] ?>%</span>
                            <?php if ($canEdit): ?>
                            <span class="mod-actions">
                                <button type="button" class="icon-btn" data-action="rename-module" data-module="<?= (int) $m['moduleID'] ?>" data-title="<?= $e($m['title']) ?>" title="Rename module">&#9998;</button>
                                <button type="button" class="icon-btn" data-action="delete-module" data-module="<?= (int) $m['moduleID'] ?>" title="Delete module">&#10005;</button>
                            </span>
                            <?php endif; ?>
                        </h2>
                        <div class="mod-bar"><div style="width: <?= (int) $m['pct'] ?>%;"></div></div>

                        <?php foreach ($m['tasks'] as $t): ?>
                        <div class="week-item<?= $canEdit ? ' editable' : '' ?>"
                             data-status="<?= $e($t['view']) ?>"
                             data-text="<?= $e(mb_strtolower($t['title'] . ' ' . $t['week_label'])) ?>">
                            <div class="week-label"><?= $e($t['week_label']) ?></div>

                            <div class="topic-cell">
                                <div class="view-fields">
                                    <div class="topic-name"><?= $e($t['title']) ?></div>
                                    <?php if ($t['due_date']): ?>
                                    <small class="due<?= $t['overdue'] ? ' overdue' : '' ?>">Due <?= $e($fmt($t['due_date'])) ?><?= $t['overdue'] ? ' (overdue)' : '' ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canEdit): ?>
                                <div class="edit-fields">
                                    <input class="syl-input grow" type="text" name="title" maxlength="250" value="<?= $e($t['title']) ?>" aria-label="Task title">
                                    <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" value="<?= $t['week_no'] !== null ? (int) $t['week_no'] : '' ?>" aria-label="Week number">
                                    <input class="syl-input date" type="date" name="due_date" value="<?= $e($t['due_date']) ?>" aria-label="Due date">
                                    <button type="button" class="syl-btn inline" data-action="save-task" data-task="<?= (int) $t['taskID'] ?>">Save</button>
                                    <button type="button" class="syl-btn inline" data-action="cancel-edit">Cancel</button>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($canEdit): ?>
                                <div>
                                    <select class="status-select" data-task="<?= (int) $t['taskID'] ?>" aria-label="Task status">
                                        <?php foreach ($dbStatuses as $val => $label): ?>
                                        <option value="<?= $e($val) ?>"<?= $t['status'] === $val ? ' selected' : '' ?>><?= $e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" data-action="edit-task" title="Edit task">&#9998;</button>
                                    <button type="button" class="icon-btn" data-action="delete-task" data-task="<?= (int) $t['taskID'] ?>" title="Delete task">&#10005;</button>
                                </div>
                            <?php else: ?>
                                <div class="status-badge <?= $e($badgeClass[$t['view']]) ?>"><?= $e($badgeLabels[$t['view']]) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <?php if (!$m['tasks'] && !$canEdit): ?>
                            <div class="milestone-subtext">No tasks in this module yet.</div>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                        <form class="task-form" data-module="<?= (int) $m['moduleID'] ?>">
                            <input class="syl-input grow" type="text" name="title" maxlength="250" placeholder="New task title" aria-label="New task title">
                            <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" aria-label="Week number">
                            <input class="syl-input date" type="date" name="due_date" aria-label="Due date">
                            <button type="submit" class="syl-btn inline">Add task</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($canEdit): ?>
                    <div class="syl-module">
                        <h2><span class="mod-title">&#10133; Add a module</span></h2>
                        <form class="module-form">
                            <input class="syl-input grow" type="text" name="title" maxlength="200" placeholder="e.g. Module 1: Introduction to OOP" aria-label="New module title">
                            <button type="submit" class="syl-btn inline">Add module</button>
                        </form>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Right column -->
                <div class="right-column">

                    <div class="syl-widget">
                        <h3>Quick Stats</h3>
                        <div class="stats-text"><strong><?= (int) $sum['done'] ?>/<?= (int) $sum['total'] ?></strong> Tasks Completed</div>
                        <div class="stats-text"><strong><?= (int) $sum['progress'] ?></strong> In Progress</div>
                        <div class="stats-text"><strong><?= (int) $sum['remaining'] ?></strong> Remaining</div>
                        <?php if ($sum['overdue'] > 0): ?>
                        <div class="stats-text" style="color:#ff6b6b;"><strong><?= (int) $sum['overdue'] ?></strong> Overdue</div>
                        <?php endif; ?>
                    </div>

                    <div class="syl-widget">
                        <h3>Current Task</h3>
                        <?php $cur = $sum['current']; ?>
                        <?php if ($cur): ?>
                            <div class="stats-text"><?= $e($cur['week_label']) ?>: <?= $e($cur['title']) ?></div>
                            <div class="milestone-subtext" style="margin-bottom: 15px;">
                                <?= $e($cur['module_title']) ?><br>
                                <?= $cur['due_date'] ? 'Deadline: ' . $e($fmt($cur['due_date'])) : 'No deadline set' ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <button type="button" class="syl-btn" data-action="mark-done" data-task="<?= (int) $cur['taskID'] ?>">Mark as Done</button>
                            <?php endif; ?>
                        <?php elseif ($sum['total'] > 0): ?>
                            <div class="stats-text">All tasks are completed.</div>
                        <?php else: ?>
                            <div class="stats-text">No tasks yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="syl-widget">
                        <h3>Upcoming Deadline</h3>
                        <?php $next = $sum['next']; ?>
                        <?php if ($next): ?>
                            <div class="stats-text" style="margin-bottom: 0;"><?= $e($next['title']) ?></div>
                            <div class="milestone-subtext">
                                <?= $e($fmt($next['due_date'])) ?><br>
                                <?php if ($next['days_left'] === 0): ?>due today
                                <?php else: ?>in <?= (int) $next['days_left'] ?> day<?= $next['days_left'] === 1 ? '' : 's' ?><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="milestone-subtext">No upcoming deadlines.</div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php endif; ?>
        </div>

      </main>
    </div>
  </div>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
  <script src="<?= BASE_PATH ?>/View/script/syllabus.js" defer></script>
</body>
</html>