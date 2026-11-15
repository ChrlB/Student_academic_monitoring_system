<?php
require_once __DIR__ . "/../PageLogic/syllabus_logic.php";

$sl  = new SyllabusLogic();
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = fn($d) => $d ? date('M j, Y', strtotime($d)) : '';
$fmtSize = function ($b) {
    $b = (float) $b;
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024) . ' KB';
    return (int) $b . ' B';
};
$fileUrl = fn($id, $dl = false) => BASE_PATH . '/syllabus-file?id=' . (int) $id . ($dl ? '&download=1' : '');

$setupError = false;
$classes    = [];
$current    = null;
$tracker    = null;
$canManage  = false;
$preview    = false;
$canEdit    = false;

try {
    $classes   = $sl->classes();
    $current   = $sl->selectedClass($classes);
    $canManage = ($current && $sl->canEdit($current));
    $preview   = ($canManage && ($_GET['preview'] ?? '') === '1');
    $canEdit   = ($canManage && !$preview);
    if ($current) {
        $tracker = $sl->tracker((int) $current['schedID'], $canEdit);
    }
} catch (PDOException $ex) {
    error_log('Syllabus page: ' . $ex->getMessage());
    $setupError = true;
}

$topicUrl = fn($id) => BASE_PATH . '/syllabus-topic?id=' . (int) $id . ($preview ? '&preview=1' : '');

$sum = $tracker['summary'] ?? ['total' => 0, 'done' => 0, 'progress' => 0, 'remaining' => 0, 'pct' => 0, 'overdue' => 0, 'materials' => 0, 'current' => null, 'next' => null];

$badgeLabels = ['completed' => 'COMPLETED', 'progress' => 'IN PROGRESS', 'not-started' => 'NOT STARTED', 'upcoming' => 'UPCOMING'];
$badgeClass  = ['completed' => 'status-completed', 'progress' => 'status-progress', 'not-started' => 'status-not-started', 'upcoming' => 'status-upcoming'];
$dbStatuses  = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'];
$kindIcon    = ['pdf' => '&#128196;', 'video' => '&#127916;', 'note' => '&#128221;'];
$kindLabel   = ['pdf' => 'PDF document', 'video' => 'Video', 'note' => 'Text note'];

$classUrl = $current ? BASE_PATH . '/syllabus?class=' . (int) $current['schedID'] : BASE_PATH . '/syllabus';
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
                    <span class="completion-text">Overall Completion <?= (int) $sum['pct'] ?>%</span>
                    <div class="progress-bg">
                        <div class="progress-fill" style="width: <?= (int) $sum['pct'] ?>%;"></div>
                    </div>
                </div>
            </div>

            <?php if ($setupError): ?>

                <div class="syl-empty">The syllabus data could not be loaded. Import the latest <strong>syllabus_tracker.sql</strong> into <strong>ams_db</strong> (phpMyAdmin), then refresh.</div>

            <?php elseif (!$current): ?>

                <div class="syl-empty">
                    <?php if ($sl->isProf): ?>You have no scheduled classes yet. Ask the program coordinator to assign your classes in Class Schedule.
                    <?php elseif ($sl->isFaculty): ?>No classes have been scheduled yet.
                    <?php else: ?>No classes found for your account yet.<?php endif; ?>
                </div>

            <?php else: ?>

            <div class="content-grid">

                <!-- Left column -->
                <div class="left-column">

                    <div class="search-filter">
                        <input type="text" class="search-bar" placeholder="Search topics or materials..." aria-label="Search topics">
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
                            <span class="mod-pct"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> topics &middot; <?= (int) $m['pct'] ?>%</span>
                            <?php if ($canEdit): ?>
                            <span class="mod-actions">
                                <button type="button" class="icon-btn" data-action="rename-module" data-module="<?= (int) $m['moduleID'] ?>" data-title="<?= $e($m['title']) ?>" title="Rename module">&#9998;</button>
                                <button type="button" class="icon-btn" data-action="delete-module" data-module="<?= (int) $m['moduleID'] ?>" title="Delete module">&#10005;</button>
                            </span>
                            <?php endif; ?>
                        </h2>
                        <div class="mod-bar"><div style="width: <?= (int) $m['pct'] ?>%;"></div></div>

                        <?php foreach ($m['tasks'] as $t):
                            $nMat = count($t['contents']);
                            $searchText = mb_strtolower($t['title'] . ' ' . $t['week_label'] . ' ' . implode(' ', array_column($t['contents'], 'title')));
                        ?>
                        <div class="topic" id="topic-<?= (int) $t['taskID'] ?>" data-status="<?= $e($t['view']) ?>" data-text="<?= $e($searchText) ?>">

                            <div class="week-item<?= $canEdit ? ' editable' : '' ?>" data-href="<?= $e($topicUrl($t['taskID'])) ?>">
                                <div class="week-label"><?= $e($t['week_label']) ?></div>

                                <div class="topic-cell">
                                    <div class="view-fields">
                                        <a class="topic-name topic-link" href="<?= $e($topicUrl($t['taskID'])) ?>"><?= $e($t['title']) ?></a>
                                        <?php if ($t['due_date']): ?>
                                        <small class="due<?= $t['overdue'] ? ' overdue' : '' ?>">Due <?= $e($fmt($t['due_date'])) ?><?= $t['overdue'] ? ' (overdue)' : '' ?></small>
                                        <?php endif; ?>
                                        <small class="mat-meta">
                                            <?php if (!empty($t['has_page'])): ?>&#128196; Lesson page<?php endif; ?>
                                            <?php if ($nMat > 0): ?><?= !empty($t['has_page']) ? ' &middot; ' : '' ?>&#128206; <?= $nMat ?> material<?= $nMat === 1 ? '' : 's' ?><?php endif; ?>
                                            <?php if (empty($t['has_page']) && $nMat === 0): ?><?= $canEdit ? 'Click to add a lesson &amp; materials' : 'Open topic' ?><?php endif; ?>
                                        </small>
                                    </div>
                                    <?php if ($canEdit): ?>
                                    <div class="edit-fields">
                                        <input class="syl-input grow" type="text" name="title" maxlength="250" value="<?= $e($t['title']) ?>" aria-label="Topic title">
                                        <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" value="<?= $t['week_no'] !== null ? (int) $t['week_no'] : '' ?>" aria-label="Week number">
                                        <input class="syl-input date" type="date" name="due_date" value="<?= $e($t['due_date']) ?>" aria-label="Due date">
                                        <button type="button" class="syl-btn inline" data-action="save-task" data-task="<?= (int) $t['taskID'] ?>">Save</button>
                                        <button type="button" class="syl-btn inline" data-action="cancel-edit">Cancel</button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canEdit): ?>
                                    <div>
                                        <select class="status-select" data-task="<?= (int) $t['taskID'] ?>" aria-label="Topic status">
                                            <?php foreach ($dbStatuses as $val => $label): ?>
                                            <option value="<?= $e($val) ?>"<?= $t['status'] === $val ? ' selected' : '' ?>><?= $e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" data-action="edit-task" title="Edit topic">&#9998;</button>
                                        <button type="button" class="icon-btn" data-action="delete-task" data-task="<?= (int) $t['taskID'] ?>" title="Delete topic">&#10005;</button>
                                    </div>
                                <?php else: ?>
                                    <div class="status-badge <?= $e($badgeClass[$t['view']]) ?>"><?= $e($badgeLabels[$t['view']]) ?></div>
                                <?php endif; ?>
                            </div>
</div>
                        <?php endforeach; ?>

                        <?php if (!$m['tasks'] && !$canEdit): ?>
                            <div class="milestone-subtext">No topics in this module yet.</div>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                        <form class="task-form" data-module="<?= (int) $m['moduleID'] ?>">
                            <input class="syl-input grow" type="text" name="title" maxlength="250" placeholder="New topic title" aria-label="New topic title">
                            <input class="syl-input sm" type="number" name="week_no" min="1" max="52" placeholder="Week" aria-label="Week number">
                            <input class="syl-input date" type="date" name="due_date" aria-label="Due date">
                            <button type="submit" class="syl-btn inline">Add topic</button>
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
                        <div class="stats-text"><strong><?= (int) $sum['done'] ?>/<?= (int) $sum['total'] ?></strong> Topics Completed</div>
                        <div class="stats-text"><strong><?= (int) $sum['progress'] ?></strong> In Progress</div>
                        <div class="stats-text"><strong><?= (int) $sum['remaining'] ?></strong> Remaining</div>
                        <div class="stats-text"><strong><?= (int) $sum['materials'] ?></strong> Learning Material<?= $sum['materials'] === 1 ? '' : 's' ?></div>
                        <?php if ($sum['overdue'] > 0): ?>
                        <div class="stats-text" style="color:#ff6b6b;"><strong><?= (int) $sum['overdue'] ?></strong> Overdue</div>
                        <?php endif; ?>
                    </div>

                    <div class="syl-widget">
                        <h3>Current Topic</h3>
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
                            <div class="stats-text">All topics are completed.</div>
                        <?php else: ?>
                            <div class="stats-text">No topics yet.</div>
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
