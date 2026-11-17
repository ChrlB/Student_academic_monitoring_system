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

$taskID     = (int) ($_GET['id'] ?? 0);
$setupError = false;
$topic      = null;
$contents   = [];
$assess     = ['quiz' => null, 'exam' => null];
$progress   = null;
$flow       = null;
$progressRows = [];
$canManage  = false;
$preview    = false;
$canEdit    = false;
$mode       = 'observer';
$lockedForMe = false;

try {
    $topic = $taskID > 0 ? $sl->topicPage($taskID) : null;
    if ($topic) {
        $canManage = $sl->canManageClass((int) $topic['schedID']);
        $preview   = ($canManage && ($_GET['preview'] ?? '') === '1');
        $canEdit   = ($canManage && !$preview);
        $mode      = $canEdit ? 'staff' : ($preview ? 'preview' : ($sl->isStudent ? 'student' : 'observer'));
        $lockedForMe = ($topic['locked'] && !$canEdit);

        if (!$lockedForMe) {
            if ($mode === 'student') {
                $sl->recordOpen($taskID);              // the student clicked it -> In Progress
                $progress = $sl->progressFor($taskID);
            }
            $contents = $sl->contentsForTask($taskID, $canEdit);
            $assess   = $sl->assessmentsFor($taskID, $canEdit ? 'staff' : ($mode === 'student' ? 'student' : 'other'));
            if ($mode === 'student') {
                $hasFiles = false;
                foreach ($contents as $c) {
                    if (in_array($c['kind'], ['pdf', 'video'], true)) { $hasFiles = true; break; }
                }
                $flow = $sl->studentFlow($assess, $progress, $hasFiles);
            }
            if ($canEdit) {
                $progressRows = $sl->topicProgress($taskID, (int) $topic['schedID']);
            }
        }
    } else {
        http_response_code(404);
    }
} catch (PDOException $ex) {
    error_log('Syllabus topic page: ' . $ex->getMessage());
    $setupError = true;
}

$backUrl  = BASE_PATH . '/syllabus' . ($topic ? '?class=' . (int) $topic['schedID'] . ($preview ? '&preview=1' : '') : '');
$topicUrl = $topic ? BASE_PATH . '/syllabus-topic?id=' . (int) $topic['taskID'] : '';

$badgeLabels = ['completed' => 'COMPLETED', 'progress' => 'IN PROGRESS', 'not-started' => 'NOT STARTED', 'upcoming' => 'UPCOMING', 'open' => 'OPEN', 'closed' => 'PAST DEADLINE'];
$badgeClass  = ['completed' => 'status-completed', 'progress' => 'status-progress', 'not-started' => 'status-not-started', 'upcoming' => 'status-upcoming', 'open' => 'status-open', 'closed' => 'status-closed'];
$kindIcon    = ['pdf' => '&#128196;', 'video' => '&#127916;', 'note' => '&#128221;'];
$kindLabel   = ['pdf' => 'PDF document', 'video' => 'Video', 'note' => 'Text note'];
$quizTitle   = ['quiz' => 'Mini quiz', 'exam' => 'Mock exam'];
$stateLabel  = ['not-started' => 'Not started', 'progress' => 'In progress', 'completed' => 'Completed', 'restudy' => 'Must study again'];

// the badge in the header
$headBadge = 'not-started';
if ($topic) {
    if ($topic['locked'])                         $headBadge = 'upcoming';
    elseif ($mode === 'student')                  $headBadge = ($progress && $progress['status'] === 'completed') ? 'completed' : 'progress';
    elseif ($mode === 'staff')                    $headBadge = ($topic['due_date'] && $topic['due_date'] < $sl->today()) ? 'closed' : 'open';
}

// quiz-builder / editor helpers
$deltaJson  = 'null';
$lessonHtml = '';
if ($topic && !$lockedForMe) {
    if ($canEdit && $topic['delta']) {
        $arr = json_decode($topic['delta'], true);
        if (is_array($arr)) {
            $deltaJson = json_encode($arr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        }
    }
    $lessonHtml = $sl->sanitizeHtml((string) $topic['html']);   // defence in depth: sanitized on save AND on read
}

// Prefer a local copy of Quill (View/lib/quill/) so the editor works offline; fall back to the CDN.
$quillLocal = is_file(__DIR__ . '/lib/quill/quill.js') && is_file(__DIR__ . '/lib/quill/quill.snow.css');
$quillCss   = $quillLocal ? BASE_PATH . '/View/lib/quill/quill.snow.css' : 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css';
$quillJs    = $quillLocal ? BASE_PATH . '/View/lib/quill/quill.js'       : 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - <?= $topic ? $e($topic['title']) : 'Topic' ?></title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/syllabus.css">
  <?php if ($canEdit && !$lockedForMe): ?>
  <link rel="stylesheet" href="<?= $e($quillCss) ?>">
  <?php endif; ?>
</head>
<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar">

        <div class="syllabus-wrapper topic-page"
             data-endpoint="<?= BASE_PATH ?>/syllabus"
             data-csrf="<?= $e(csrfToken()) ?>"
             data-task="<?= $topic ? (int) $topic['taskID'] : 0 ?>"
             <?= $canEdit ? 'data-edit="1"' : '' ?>
             <?= ($mode === 'student') ? 'data-student="1"' : '' ?>>

            <?php if ($setupError): ?>

                <div class="syl-empty">The topic could not be loaded. Import the latest <strong>syllabus_tracker.sql</strong> into <strong>ams_db</strong> (phpMyAdmin), then refresh.
                    <br><br><a class="back-link" href="<?= $e($backUrl) ?>">&larr; Back to syllabus</a></div>

            <?php elseif (!$topic): ?>

                <div class="syl-empty">This topic was not found, or you don't have access to it.
                    <br><br><a class="back-link" href="<?= $e($backUrl) ?>">&larr; Back to syllabus</a></div>

            <?php else: ?>

            <!-- Header -->
            <div class="syl-header">
                <div>
                    <a class="back-link" href="<?= $e($backUrl) ?>">&larr; Back to syllabus</a>
                    <div class="title-row">
                        <h1><?= $e($topic['title']) ?></h1>
                        <?php if ($canManage): ?>
                            <?php if ($preview): ?>
                                <span class="role-chip preview">STUDENT PREVIEW</span>
                                <a class="preview-link" href="<?= $e($topicUrl) ?>">Back to faculty view</a>
                            <?php else: ?>
                                <span class="role-chip">FACULTY VIEW</span>
                                <a class="preview-link" href="<?= $e($topicUrl . '&preview=1') ?>">Preview as student</a>
                                <a class="preview-link" href="#checkpoints">Quiz &amp; exam builder &darr;</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="course-info"><?= $e($topic['week_label']) ?> &middot; <?= $e($topic['module_title']) ?><?= $topic['class_label'] ? ' &middot; ' . $e($topic['class_label']) : '' ?></div>
                </div>
                <div class="topic-header-side">
                    <div class="status-badge <?= $e($badgeClass[$headBadge]) ?>"><?= $e($badgeLabels[$headBadge]) ?></div>
                    <?php if ($topic['available_from']): ?>
                        <div class="milestone-subtext"><?= $topic['locked'] ? 'Opens' : 'Opened' ?> <?= $e($fmt($topic['available_from'])) ?></div>
                    <?php endif; ?>
                    <?php if ($topic['due_date']): ?>
                        <?php $late = ($mode === 'student' && $headBadge !== 'completed' && $topic['due_date'] < $sl->today()); ?>
                        <div class="milestone-subtext" style="<?= $late ? 'color:#ff6b6b;' : '' ?>">Deadline <?= $e($fmt($topic['due_date'])) ?><?= $late ? ' (overdue)' : '' ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($lockedForMe): ?>

                <div class="syl-empty">
                    &#128274; <strong>This topic is not open yet.</strong><br>
                    <?= $topic['available_from'] ? 'It opens on ' . $e($fmt($topic['available_from'])) . '.' : '' ?>
                    <br><br><a class="back-link" href="<?= $e($backUrl) ?>">&larr; Back to syllabus</a>
                </div>

            <?php else: ?>

            <?php if ($mode === 'student' && $flow && $flow['restudy']): ?>
            <div class="restudy-banner" id="restudyBanner" data-seconds="<?= (int) $flow['seconds_left'] ?>" data-needfiles="<?= $flow['need_files'] ? '1' : '0' ?>">
                <strong>Study this topic again before you retake.</strong><br>
                You did not reach the pass mark on <?= $flow['failed_title'] ? '&ldquo;' . $e($flow['failed_title']) . '&rdquo;' : 'the checkpoint' ?>.
                Re-read the lesson and go through the learning materials<?= $flow['need_files'] ? ' (open at least one PDF or video again)' : '' ?>.
                <div style="margin-top:6px;">Retake unlocks in <strong id="restudyTimer">--:--</strong></div>
                <button type="button" class="syl-btn" id="finishReviewBtn" data-action="finish-review" data-task="<?= (int) $topic['taskID'] ?>" disabled>I've reviewed this topic</button>
            </div>
            <?php endif; ?>

            <div class="topic-grid">

                <!-- Lesson document -->
                <div>
                    <?php if ($canEdit): ?>
                    <div class="doc-card">
                        <div id="toolbar">
                            <span class="ql-formats">
                                <button type="button" class="ql-undo txt" title="Undo">Undo</button>
                                <button type="button" class="ql-redo txt" title="Redo">Redo</button>
                            </span>
                            <span class="ql-formats">
                                <select class="ql-header" title="Text style">
                                    <option value="1">Heading 1</option>
                                    <option value="2">Heading 2</option>
                                    <option value="3">Heading 3</option>
                                    <option selected>Normal</option>
                                </select>
                                <select class="ql-font" title="Font">
                                    <option selected>Sans Serif</option>
                                    <option value="serif">Serif</option>
                                    <option value="monospace">Monospace</option>
                                </select>
                                <select class="ql-size" title="Size">
                                    <option value="small">Small</option>
                                    <option selected>Normal</option>
                                    <option value="large">Large</option>
                                    <option value="huge">Huge</option>
                                </select>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-bold" title="Bold"></button>
                                <button type="button" class="ql-italic" title="Italic"></button>
                                <button type="button" class="ql-underline" title="Underline"></button>
                                <button type="button" class="ql-strike" title="Strikethrough"></button>
                            </span>
                            <span class="ql-formats">
                                <select class="ql-color" title="Text color"></select>
                                <select class="ql-background" title="Highlight color"></select>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-script" value="sub" title="Subscript"></button>
                                <button type="button" class="ql-script" value="super" title="Superscript"></button>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-list" value="ordered" title="Numbered list"></button>
                                <button type="button" class="ql-list" value="bullet" title="Bulleted list"></button>
                                <button type="button" class="ql-indent" value="-1" title="Decrease indent"></button>
                                <button type="button" class="ql-indent" value="+1" title="Increase indent"></button>
                            </span>
                            <span class="ql-formats">
                                <select class="ql-align" title="Alignment"></select>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-blockquote" title="Quote"></button>
                                <button type="button" class="ql-code-block" title="Code block"></button>
                                <button type="button" class="ql-link" title="Link"></button>
                                <button type="button" class="ql-image" title="Insert picture"></button>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-table-insert txt" title="Insert a 3x3 table">Table</button>
                                <button type="button" class="ql-row-add txt" title="Add row below">+Row</button>
                                <button type="button" class="ql-col-add txt" title="Add column right">+Col</button>
                                <button type="button" class="ql-row-del txt" title="Delete row">-Row</button>
                                <button type="button" class="ql-col-del txt" title="Delete column">-Col</button>
                                <button type="button" class="ql-table-del txt" title="Delete table">Del table</button>
                            </span>
                            <span class="ql-formats">
                                <button type="button" class="ql-clean" title="Clear formatting"></button>
                            </span>
                        </div>
                        <div id="editor"></div>
                        <div class="doc-statusbar">
                            <span id="docStatus"><?= $topic['updated_at'] ? 'Last saved ' . $e(date('M j, g:i A', strtotime($topic['updated_at']))) : 'Not saved yet' ?></span>
                            <span id="docWords"></span>
                            <button type="button" class="syl-btn inline" id="saveBtn">Save now</button>
                        </div>
                    </div>
                    <script type="application/json" id="initialDelta"><?= $deltaJson ?></script>

                    <?php else: ?>
                    <div class="doc-card">
                        <?php if ($lessonHtml !== ''): ?>
                            <div class="lesson-body"><?= $lessonHtml ?></div>
                        <?php else: ?>
                            <div class="doc-empty">The instructor hasn't written a lesson for this topic yet.</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right column: checkpoints (students) + materials -->
                <div>

                    <?php if ($mode === 'student' && $flow): ?>
                    <div class="syl-widget checkpoint-card" style="margin-bottom:20px;">
                        <h3>Check your understanding</h3>

                        <?php $hasQ = ($assess['quiz'] || $assess['exam']); ?>

                        <?php if ($flow['completed']): ?>
                            <div class="ok-banner">&#9989; You completed this topic<?= ($progress && $progress['completed_at']) ? ' on ' . $e(date('M j, Y', strtotime($progress['completed_at']))) : '' ?>.</div>
                        <?php endif; ?>

                        <?php if ($hasQ): ?>
                            <p class="path-line">Study &rarr; <?= $assess['quiz'] ? 'Mini quiz' : '' ?><?= ($assess['quiz'] && $assess['exam']) ? ' &rarr; ' : '' ?><?= $assess['exam'] ? 'Mock exam' : '' ?> &rarr; Completed</p>
                        <?php elseif (!$flow['completed']): ?>
                            <p class="path-line">This topic has no quiz. When you are done studying, mark it as finished.</p>
                            <button type="button" class="syl-btn" data-action="mark-finished" data-task="<?= (int) $topic['taskID'] ?>">Mark topic as finished</button>
                        <?php endif; ?>

                        <?php foreach (['quiz', 'exam'] as $k):
                            $q = $assess[$k];
                            if (!$q) continue;
                            $st = $flow['state'][$k];
                        ?>
                        <div class="assess-row">
                            <div>
                                <strong><?= $k === 'quiz' ? '&#129514;' : '&#128221;' ?> <?= $e($q['title']) ?></strong>
                                <small><?= (int) $q['question_count'] ?> question<?= $q['question_count'] === 1 ? '' : 's' ?> &middot; pass mark <?= (int) $q['pass_percent'] ?>%<?= ($k === 'exam' && $q['draw_count']) ? ' &middot; ' . (int) $q['draw_count'] . ' random per attempt' : '' ?></small>
                                <?php if ($q['attempts'] > 0): ?><small>Attempts: <?= (int) $q['attempts'] ?> &middot; best score: <?= (int) $q['best'] ?>%</small><?php endif; ?>
                            </div>
                            <div>
                                <?php if ($st === 'passed'): ?>
                                    <span class="chip ok">Passed</span>
                                <?php elseif ($st === 'ready'): ?>
                                    <button type="button" class="syl-btn" data-action="start-quiz" data-quiz="<?= (int) $q['quizID'] ?>"><?= $q['attempts'] > 0 ? 'Retake' : 'Start' ?></button>
                                <?php elseif ($st === 'restudy'): ?>
                                    <span class="lock-note">&#128274; Study again first</span>
                                <?php elseif ($st === 'needs_quiz'): ?>
                                    <span class="lock-note">&#128274; Pass the mini quiz first</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($mode === 'preview' || $mode === 'observer'): ?>
                        <?php if ($assess['quiz'] || $assess['exam']): ?>
                        <div class="syl-widget" style="margin-bottom:20px;">
                            <h3>Checkpoints</h3>
                            <?php foreach (['quiz', 'exam'] as $k): if (!$assess[$k]) continue; ?>
                                <div class="stats-text"><?= $k === 'quiz' ? '&#129514;' : '&#128221;' ?> <?= $e($assess[$k]['title']) ?> &middot; pass mark <?= (int) $assess[$k]['pass_percent'] ?>%</div>
                            <?php endforeach; ?>
                            <div class="milestone-subtext">Students take these here. Quizzes are not available in preview.</div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="syl-widget materials-card">
                        <h3>Learning materials (<?= count($contents) ?>)</h3>

                        <?php if (!$contents): ?>
                            <div class="milestone-subtext"><?= $canEdit ? 'No materials yet. Add a PDF, a video or a text note below.' : 'No materials for this topic yet.' ?></div>
                        <?php endif; ?>

                        <?php foreach ($contents as $c):
                            $isPub = ((int) $c['is_published'] === 1);
                        ?>
                        <div class="content-item<?= $isPub ? '' : ' is-draft' ?>">
                            <div class="ci-head">
                                <span class="ci-icon"><?= $kindIcon[$c['kind']] ?></span>
                                <div class="ci-meta">
                                    <strong><?= $e($c['title']) ?></strong>
                                    <small><?= $e($kindLabel[$c['kind']]) ?><?= $c['file_size'] ? ' &middot; ' . $e($fmtSize($c['file_size'])) : '' ?><?= $isPub ? '' : ' &middot; Hidden from students' ?></small>
                                </div>
                                <div class="ci-actions">
                                    <?php if ($c['kind'] === 'pdf'): ?>
                                        <a class="syl-btn inline" href="<?= $e($fileUrl($c['contentID'])) ?>" target="_blank" rel="noopener">View</a>
                                        <a class="syl-btn inline" href="<?= $e($fileUrl($c['contentID'], true)) ?>">Download</a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="icon-btn" data-action="toggle-publish" data-content="<?= (int) $c['contentID'] ?>" data-publish="<?= $isPub ? '0' : '1' ?>" title="<?= $isPub ? 'Hide from students' : 'Publish to students' ?>"><?= $isPub ? 'Hide' : 'Publish' ?></button>
                                        <button type="button" class="icon-btn" data-action="move-content" data-content="<?= (int) $c['contentID'] ?>" data-dir="up" title="Move up">&#9650;</button>
                                        <button type="button" class="icon-btn" data-action="move-content" data-content="<?= (int) $c['contentID'] ?>" data-dir="down" title="Move down">&#9660;</button>
                                        <button type="button" class="icon-btn" data-action="edit-content" title="Edit">&#9998;</button>
                                        <button type="button" class="icon-btn" data-action="delete-content" data-content="<?= (int) $c['contentID'] ?>" title="Delete">&#10005;</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($c['description']): ?>
                                <p class="ci-desc"><?= $e($c['description']) ?></p>
                            <?php endif; ?>

                            <?php if ($c['kind'] === 'video'): ?>
                                <video class="ci-video" controls preload="none" data-src="<?= $e($fileUrl($c['contentID'])) ?>">
                                    Your browser cannot play this video.
                                </video>
                            <?php elseif ($c['kind'] === 'note'): ?>
                                <div class="ci-note"><?= nl2br($e($c['body'])) ?></div>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                            <div class="ci-edit">
                                <input class="syl-input grow" type="text" name="title" maxlength="200" value="<?= $e($c['title']) ?>" aria-label="Material title">
                                <input class="syl-input grow" type="text" name="description" maxlength="500" value="<?= $e($c['description']) ?>" placeholder="Short description (optional)" aria-label="Description">
                                <?php if ($c['kind'] === 'note'): ?>
                                <textarea class="syl-input" name="body" maxlength="20000" aria-label="Note text"><?= $e($c['body']) ?></textarea>
                                <?php endif; ?>
                                <button type="button" class="syl-btn inline" data-action="save-content" data-content="<?= (int) $c['contentID'] ?>">Save</button>
                                <button type="button" class="syl-btn inline" data-action="cancel-content-edit">Cancel</button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($canEdit): ?>
                        <form class="content-form">
                            <h4>Add a material</h4>
                            <div class="cf-row">
                                <select class="syl-input" name="kind" aria-label="Material type">
                                    <option value="pdf">PDF document</option>
                                    <option value="video">Video (MP4 / WebM)</option>
                                    <option value="note">Text note</option>
                                </select>
                                <input class="syl-input grow" type="text" name="title" maxlength="200" placeholder="Title (e.g. Lecture slides)" aria-label="Material title">
                            </div>
                            <input class="syl-input" type="text" name="description" maxlength="500" placeholder="Short description for students (optional)" aria-label="Description">
                            <div class="cf-file">
                                <input class="syl-input file" type="file" name="file" accept=".pdf,application/pdf" aria-label="Choose file">
                                <div class="hint">PDF up to 50 MB. Video: MP4 (H.264) or WebM up to 500 MB, and never above your server's upload limit.</div>
                            </div>
                            <div class="cf-note is-hidden">
                                <textarea class="syl-input" name="body" maxlength="20000" placeholder="Write a short note, reminder or instructions..." aria-label="Note text"></textarea>
                            </div>
                            <div class="cf-row">
                                <button type="submit" class="syl-btn inline">Add material</button>
                                <span class="upload-label"></span>
                            </div>
                            <div class="upload-progress" hidden><div></div></div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <?php if ($canEdit): ?>
            <!-- Faculty: checkpoint builder -->
            <h2 id="checkpoints" style="margin:28px 0 4px;font-size:20px;">&#129514; Quiz &amp; exam builder</h2>
            <div class="hint" style="margin-bottom:6px;">Create a mini quiz and/or a mock exam for this topic, add questions, then tick &ldquo;Published to students&rdquo;.</div>
            <div class="assess-wrap">
                <?php foreach (['quiz', 'exam'] as $k):
                    $q = $assess[$k];
                ?>
                <div class="assess-card" data-kind="<?= $k ?>" data-quiz="<?= $q ? (int) $q['quizID'] : 0 ?>">
                    <h3>
                        <?= $k === 'quiz' ? '&#129514; Mini quiz' : '&#128221; Mock exam' ?>
                        <?php if ($q): ?><?= (int) $q['is_published'] === 1 ? '<span class="chip ok">Published</span>' : '<span class="chip warn">Draft</span>' ?><?php endif; ?>
                    </h3>
                    <div class="hint" style="margin-bottom:10px;">
                        <?= $k === 'quiz'
                            ? 'A short checkpoint students take after studying. Passing it unlocks the mock exam (if you add one), or completes the topic.'
                            : 'The proficiency check. Students must pass it to complete the topic. It can draw random questions from a bigger bank each attempt.' ?>
                    </div>

                    <form class="quiz-settings" data-task="<?= (int) $topic['taskID'] ?>" data-kind="<?= $k ?>">
                        <label class="mini" style="flex-basis:100%;">Title
                            <input class="syl-input" type="text" name="title" maxlength="200" value="<?= $q ? $e($q['title']) : '' ?>" placeholder="<?= $k === 'exam' ? 'Mock exam' : 'Mini quiz' ?>">
                        </label>
                        <label class="mini">Pass mark (%)
                            <input class="syl-input" type="number" name="pass_percent" min="1" max="100" value="<?= $q ? (int) $q['pass_percent'] : 70 ?>">
                        </label>
                        <label class="mini">Study time after a fail (min)
                            <input class="syl-input" type="number" name="cooldown_minutes" min="0" max="1440" value="<?= $q ? (int) $q['cooldown_minutes'] : 5 ?>">
                        </label>
                        <?php if ($k === 'exam'): ?>
                        <label class="mini">Random questions per attempt
                            <input class="syl-input" type="number" name="draw_count" min="1" max="500" value="<?= ($q && $q['draw_count']) ? (int) $q['draw_count'] : '' ?>" placeholder="All">
                        </label>
                        <?php endif; ?>
                        <label class="mini check"><input type="checkbox" name="published" value="1"<?= ($q && (int) $q['is_published'] === 1) ? ' checked' : '' ?>> Published to students</label>
                        <button type="submit" class="syl-btn inline"><?= $q ? 'Save settings' : 'Create ' . ($k === 'exam' ? 'mock exam' : 'mini quiz') ?></button>
                        <?php if ($q): ?><button type="button" class="icon-btn" data-action="delete-quiz" data-quiz="<?= (int) $q['quizID'] ?>">Delete</button><?php endif; ?>
                    </form>

                    <?php if ($q): ?>
                        <div class="q-list">
                            <?php foreach ($q['questions'] as $i => $qq):
                                $labels  = array_column($qq['choices'], 'label');
                                $correct = 0;
                                foreach ($qq['choices'] as $ci => $ch) { if ((int) $ch['is_correct'] === 1) { $correct = $ci; break; } }
                                $payload = json_encode(['id' => (int) $qq['questionID'], 'prompt' => $qq['prompt'], 'choices' => $labels, 'correct' => $correct], JSON_UNESCAPED_UNICODE);
                            ?>
                            <div class="q-item" data-q="<?= $e($payload) ?>">
                                <div><strong>Q<?= $i + 1 ?>.</strong> <?= nl2br($e($qq['prompt'])) ?></div>
                                <ol class="q-choices" type="A">
                                    <?php foreach ($qq['choices'] as $ci => $ch): ?>
                                    <li class="<?= (int) $ch['is_correct'] === 1 ? 'correct' : '' ?>"><?= $e($ch['label']) ?></li>
                                    <?php endforeach; ?>
                                </ol>
                                <div class="q-actions">
                                    <button type="button" class="icon-btn" data-action="edit-question">Edit</button>
                                    <button type="button" class="icon-btn" data-action="delete-question" data-question="<?= (int) $qq['questionID'] ?>">Delete</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (!$q['questions']): ?><div class="hint">No questions yet. Students will not see this checkpoint until it has at least one question and is published.</div><?php endif; ?>
                        </div>

                        <form class="question-form" data-quiz="<?= (int) $q['quizID'] ?>">
                            <h4 class="qf-title">Add a question</h4>
                            <input type="hidden" name="questionID" value="0">
                            <textarea class="syl-input" name="prompt" maxlength="2000" placeholder="Question"></textarea>
                            <div class="choice-rows">
                                <?php for ($i = 0; $i < 4; $i++): ?>
                                <label class="choice-row"><input type="radio" name="correct" value="<?= $i ?>"<?= $i === 0 ? ' checked' : '' ?>><input class="syl-input grow" type="text" name="choice" maxlength="500" placeholder="Answer choice <?= chr(65 + $i) ?>"></label>
                                <?php endfor; ?>
                            </div>
                            <div class="hint">Select the circle next to the correct answer. Empty choices are ignored (minimum 2).</div>
                            <div class="cf-row">
                                <button type="button" class="icon-btn" data-action="add-choice">+ Add choice</button>
                                <button type="submit" class="syl-btn inline">Save question</button>
                                <button type="button" class="syl-btn inline is-hidden" data-action="cancel-question">Cancel edit</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="hint">Create it first, then add questions.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Faculty: how the class is doing on this topic -->
            <div class="progress-card">
                <h3>Student progress on this topic</h3>
                <?php if (!$progressRows): ?>
                    <div class="milestone-subtext">No students are enrolled in this class yet.</div>
                <?php else: ?>
                <table class="progress-table">
                    <thead>
                        <tr><th>Student</th><th>Status</th><th>Mini quiz</th><th>Mock exam</th><th>Last opened</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($progressRows as $pr): ?>
                        <tr>
                            <td><?= $e($pr['name']) ?></td>
                            <td><span class="chip <?= $pr['state'] === 'completed' ? 'ok' : ($pr['state'] === 'restudy' ? 'warn' : '') ?>"><?= $e($stateLabel[$pr['state']]) ?></span></td>
                            <?php foreach (['quiz', 'exam'] as $k): $a = $pr[$k]; ?>
                            <td><?php if ($a): ?><?= (int) $a['best'] ?>% <span class="milestone-subtext">(<?= (int) $a['n'] ?> tr<?= (int) $a['n'] === 1 ? 'y' : 'ies' ?>)</span> <?= (int) $a['passed'] === 1 ? '<span class="chip ok">Passed</span>' : '<span class="chip bad">Not passed</span>' ?><?php else: ?>&mdash;<?php endif; ?></td>
                            <?php endforeach; ?>
                            <td><?= $pr['last'] ? $e(date('M j, g:i A', strtotime($pr['last']))) : '&mdash;' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
            <?php endif; ?>
        </div>

      </main>
    </div>
  </div>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
  <?php if ($canEdit && !$lockedForMe && $topic): ?>
  <script src="<?= $e($quillJs) ?>"></script>
  <?php endif; ?>
  <script src="<?= BASE_PATH ?>/View/script/syllabus_topic.js" defer></script>
</body>
</html>