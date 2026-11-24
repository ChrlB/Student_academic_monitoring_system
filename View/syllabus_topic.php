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
$fileUrl    = fn($id, $dl = false) => BASE_PATH . '/syllabus-file?id=' . (int) $id . ($dl ? '&download=1' : '');
$projSubUrl = fn($id, $dl = true)  => BASE_PATH . '/syllabus-file?project_sub=' . (int) $id . ($dl ? '&download=1' : '');

$taskID       = (int) ($_GET['id'] ?? 0);
$setupError   = false;
$topic        = null;
$contents     = [];
$assess       = ['quiz' => null, 'exam' => null, 'project' => null];
$progress     = null;
$flow         = null;
$progressRows = [];
$canManage    = false;
$preview      = false;
$canEdit      = false;
$mode         = 'observer';
$lockedForMe  = false;
$practice     = ['pool' => 0, 'review' => 0];

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
                $flow     = $sl->studentFlow($assess, $progress);
                $practice = $sl->practiceInfo($taskID);
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
$kindLabel   = ['pdf' => 'PDF document', 'video' => 'Video', 'note' => 'Note'];
$stateLabel  = ['not-started' => 'Not started', 'progress' => 'In progress', 'completed' => 'Completed', 'restudy' => 'Must study again'];
$modeName    = ['standard' => 'Standard', 'instant' => 'Instant feedback', 'timed' => 'Timed', 'confidence' => 'Confidence marking', 'adaptive' => 'Adaptive', 'survival' => 'Survival'];
$subTypeLabel = ['file' => 'File upload', 'link' => 'Link / URL', 'both' => 'File upload or link'];

// the badge in the header
$headBadge = 'not-started';
if ($topic) {
    if ($topic['locked'])         $headBadge = 'upcoming';
    elseif ($mode === 'student')  $headBadge = ($progress && $progress['status'] === 'completed') ? 'completed' : 'progress';
    elseif ($mode === 'staff')    $headBadge = ($topic['due_date'] && $topic['due_date'] < $sl->today()) ? 'closed' : 'open';
}

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

// professor checklist
$lessonDone = ($lessonHtml !== '');
$matsDone   = (count($contents) > 0);
$qq         = $assess['quiz'];
$ee         = $assess['exam'];
$pp         = $assess['project'];
$assessDone = ($qq && (int) $qq['is_published'] === 1 && (int) $qq['question_count'] >= 1)
           || ($ee && (int) $ee['is_published'] === 1 && (int) $ee['question_count'] >= 1)
           || ($pp && (int) $pp['is_published'] === 1);

$topicTerm = ($topic && ($topic['term'] ?? 'midterm') === 'final') ? 'final' : 'midterm';

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

                <div class="syl-empty">The topic could not be loaded. Import the latest <strong>syllabus_tracker_v5.sql</strong> into <strong>ams_db</strong> (phpMyAdmin), then refresh.
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
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="course-info">
                        <span class="term-pill sm <?= $topicTerm === 'final' ? 'is-final' : 'is-midterm' ?>"><?= $topicTerm === 'final' ? 'FINAL' : 'MIDTERM' ?></span>
                        <?= $e($topic['week_label']) ?> &middot; <?= $e($topic['module_title']) ?><?= $topic['class_label'] ? ' &middot; ' . $e($topic['class_label']) : '' ?>
                    </div>
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

            <?php if ($mode === 'student' && $flow): ?>

                <?php if ($flow['flagged']): ?>
                <!-- failed a checkpoint: short wait, then retake -->
                <div class="restudy-banner" id="restudyBanner" data-seconds="<?= (int) $flow['seconds_left'] ?>">
                    <strong>Review the lesson, then try again.</strong><br>
                    You did not reach the pass mark<?= $flow['failed_title'] ? ' on &ldquo;' . $e($flow['failed_title']) . '&rdquo;' : '' ?>.
                    <a href="#lesson" class="banner-link">Re-read the lesson &darr;</a>
                    <?php if ($flow['missed']): ?>
                    <div class="missed-box">
                        <div>Questions to review:</div>
                        <ul>
                            <?php foreach ($flow['missed'] as $mp): ?><li><?= $e($mp) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div id="restudyWait" style="margin-top:6px;<?= $flow['waiting'] ? '' : 'display:none;' ?>">You can retake in <strong id="restudyTimer">--:--</strong></div>
                    <button type="button" class="syl-btn" id="retakeBtn" data-action="start-quiz" data-quiz="<?= (int) $flow['failed_quiz'] ?>"<?= $flow['waiting'] ? ' disabled' : '' ?>>Retake</button>
                </div>

                <?php elseif ($flow['completed']): ?>
                <div class="ok-banner" style="margin-bottom:18px;">&#9989; You completed this topic<?= ($progress && $progress['completed_at']) ? ' on ' . $e(date('M j, Y', strtotime($progress['completed_at']))) : '' ?>. You can still re-read it or practise any time.</div>

                <?php else: ?>
                <!-- the one next step -->
                <div class="next-step">
                    <div>
                        <strong>Next step</strong>
                        <?php if ($flow['next'] === 'quiz'): $nq = $assess['quiz']; ?>
                            <div>Read the lesson and materials, then take the quiz.</div>
                        <?php elseif ($flow['next'] === 'exam'): $ne = $assess['exam']; ?>
                            <div>Read the lesson and materials, then take the exam.</div>
                        <?php elseif ($flow['next'] === 'project'): ?>
                            <div><?= ($flow['state']['project'] === 'revision') ? 'Your instructor requested a revision on your project. Update and resubmit below.' : 'Complete and submit the project below.' ?></div>
                        <?php elseif ($assess['project'] && $flow['state']['project'] === 'submitted'): ?>
                            <div>Your project has been submitted and is waiting for instructor review.</div>
                        <?php else: ?>
                            <div>Read the lesson and materials, then mark the topic as finished.</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($flow['next'] === 'quiz'): ?>
                        <button type="button" class="syl-btn primary" data-action="start-quiz" data-quiz="<?= (int) $nq['quizID'] ?>"><?= $nq['attempts'] > 0 ? 'Retake' : 'Take' ?> the quiz</button>
                    <?php elseif ($flow['next'] === 'exam'): ?>
                        <button type="button" class="syl-btn primary" data-action="start-quiz" data-quiz="<?= (int) $ne['quizID'] ?>"><?= $ne['attempts'] > 0 ? 'Retake' : 'Take' ?> the exam</button>
                    <?php elseif ($flow['next'] === 'project'): ?>
                        <a class="syl-btn primary" href="#projectCard"><?= ($flow['state']['project'] === 'revision') ? 'Resubmit project' : 'Submit project' ?></a>
                    <?php elseif (!($assess['project'] && $flow['state']['project'] === 'submitted')): ?>
                        <button type="button" class="syl-btn primary" data-action="mark-finished" data-task="<?= (int) $topic['taskID'] ?>">Mark as finished</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <?php elseif ($canEdit): ?>
                <!-- professor checklist -->
                <div class="checklist">
                    <a href="#lesson" class="check-step<?= $lessonDone ? ' done' : '' ?>"><span class="tick"><?= $lessonDone ? '&#10003;' : '1' ?></span> Write the lesson</a>
                    <a href="#materials" class="check-step<?= $matsDone ? ' done' : '' ?>"><span class="tick"><?= $matsDone ? '&#10003;' : '2' ?></span> Add materials <em>(optional)</em></a>
                    <a href="#checkpoints" class="check-step<?= $assessDone ? ' done' : '' ?>"><span class="tick"><?= $assessDone ? '&#10003;' : '3' ?></span> Add assessment <em>(quiz / exam / project)</em></a>
                    <span class="check-hint">Opening dates and deadlines follow the Academic Calendar on the syllabus page.</span>
                </div>
            <?php endif; ?>

            <div class="topic-grid">

                <!-- Lesson document -->
                <div id="lesson">
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

                <!-- Right column: materials, then assessments (quiz / exam / project) + practice -->
                <div>

                    <div class="syl-widget materials-card" id="materials" style="margin-bottom:20px;">
                        <h3>Learning materials (<?= count($contents) ?>)</h3>

                        <?php if (!$contents): ?>
                            <div class="milestone-subtext"><?= $canEdit ? 'No materials yet. Add a PDF or a video below (optional).' : 'No materials for this topic yet.' ?></div>
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
                                        <button type="button" class="icon-btn" data-action="edit-content" title="Edit">&#9998;</button>
                                        <button type="button" class="icon-btn" data-action="delete-content" data-content="<?= (int) $c['contentID'] ?>" title="Delete">&#10005;</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($c['description']): ?>
                                <p class="ci-desc"><?= $e($c['description']) ?></p>
                            <?php endif; ?>

                            <?php if ($c['kind'] === 'video'): ?>
                                <?php if ((int) $c['has_file'] === 1): ?>
                                    <video class="ci-video" controls preload="none" data-src="<?= $e($fileUrl($c['contentID'])) ?>">
                                        Your browser cannot play this video.
                                    </video>
                                <?php else: $vl = SyllabusLogic::parseVideoLink((string) $c['body']); ?>
                                    <?php if ($vl['embed']): ?>
                                    <div class="ci-embed">
                                        <iframe src="<?= $e($vl['embed']) ?>" title="<?= $e($c['title']) ?>" loading="lazy" allowfullscreen
                                                allow="accelerometer; encrypted-media; picture-in-picture; fullscreen"
                                                referrerpolicy="strict-origin-when-cross-origin"></iframe>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($vl['ok']): ?>
                                    <a class="ci-link" href="<?= $e($vl['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $vl['embed'] ? 'Open in a new tab' : 'Open the recording' ?> &rsaquo;</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php elseif ($c['kind'] === 'note'): ?>
                                <div class="ci-note"><?= nl2br($e($c['body'])) ?></div>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                            <div class="ci-edit">
                                <input class="syl-input grow" type="text" name="title" maxlength="200" value="<?= $e($c['title']) ?>" aria-label="Material title">
                                <input class="syl-input grow" type="text" name="description" maxlength="500" value="<?= $e($c['description']) ?>" placeholder="Short description (optional)" aria-label="Description">
                                <?php if ($c['kind'] === 'video' && (int) $c['has_file'] !== 1): ?>
                                <input class="syl-input grow" type="url" name="url" maxlength="500" value="<?= $e($c['body']) ?>" placeholder="Video link" aria-label="Video link">
                                <?php endif; ?>
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
                                    <option value="video">Recorded lesson (video link)</option>
                                </select>
                                <input class="syl-input grow" type="text" name="title" maxlength="200" placeholder="Title (e.g. Lecture slides)" aria-label="Material title">
                            </div>
                            <input class="syl-input" type="text" name="description" maxlength="500" placeholder="Short description for students (optional)" aria-label="Description">
                            <div class="cf-file">
                                <input class="syl-input file" type="file" name="file" accept=".pdf,application/pdf" aria-label="Choose file">
                                <div class="hint">PDF up to 50 MB.</div>
                            </div>
                            <div class="cf-url is-hidden">
                                <input class="syl-input" type="url" name="url" maxlength="500" placeholder="Paste a YouTube, Vimeo or Google Drive link" aria-label="Video link">
                                <div class="hint">Recorded lessons play from a link, so nothing is stored on the school server. YouTube: set the video to Unlisted. Google Drive: share it as &ldquo;Anyone with the link&rdquo;. Any other link (Zoom, Teams) opens in a new tab.</div>
                            </div>
                            <div class="cf-row">
                                <button type="submit" class="syl-btn inline">Add material</button>
                                <span class="upload-label"></span>
                            </div>
                            <div class="upload-progress" hidden><div></div></div>
                        </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($mode === 'student' && $flow && ($assess['quiz'] || $assess['exam'] || $assess['project'] || $practice['pool'] > 0)): ?>
                    <!-- student: assessments (quiz, exam, project) + practice -->
                    <div class="syl-widget checkpoint-card" id="quiz" style="margin-bottom:20px;">
                        <h3>Assessments &amp; practice</h3>

                        <?php foreach (['quiz', 'exam'] as $k):
                            $q = $assess[$k];
                            if (!$q) continue;
                            $st = $flow['state'][$k];
                            $mn = $modeName[$q['mode']] ?? 'Standard';
                            if ($q['mode'] === 'timed') $mn .= ' &middot; ' . ($q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] : 20) . ' min';
                        ?>
                        <div class="assess-row">
                            <div>
                                <strong><?= $k === 'quiz' ? '&#129514;' : '&#128221;' ?> <?= $e($q['title']) ?></strong>
                                <span class="chip"><?= $k === 'quiz' ? 'Quiz' : 'Exam' ?> &middot; <?= $mn ?></span>
                                <small><?= (int) $q['question_count'] ?> question<?= $q['question_count'] === 1 ? '' : 's' ?> &middot; pass mark <?= (int) $q['pass_percent'] ?>%<?= $q['attempts'] > 0 ? ' &middot; best ' . (int) $q['best'] . '%' : '' ?></small>
                            </div>
                            <div>
                                <?php if ($st === 'passed'): ?>
                                    <span class="chip ok">Passed</span>
                                <?php elseif ($st === 'ready'): ?>
                                    <button type="button" class="syl-btn inline" data-action="start-quiz" data-quiz="<?= (int) $q['quizID'] ?>"><?= $q['attempts'] > 0 ? 'Retake' : 'Start' ?></button>
                                <?php elseif ($st === 'restudy'): ?>
                                    <span class="lock-note">&#128274; Wait, then retake</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($assess['project']): $pr = $assess['project']; $pst = $flow['state']['project']; ?>
                        <div class="assess-row">
                            <div>
                                <strong>&#128193; <?= $e($pr['title']) ?></strong>
                                <span class="chip assess-chip proj">Project</span>
                                <small><?= $e($subTypeLabel[$pr['submission_type']] ?? 'File or link') ?> &middot; <?= (int) $pr['max_score'] ?> pts</small>
                            </div>
                            <div>
                                <?php if ($pst === 'passed'): ?>
                                    <span class="chip ok">Approved</span>
                                <?php elseif ($pst === 'submitted'): ?>
                                    <span class="chip">Submitted</span>
                                <?php elseif ($pst === 'revision'): ?>
                                    <span class="chip warn">Revision</span>
                                <?php else: ?>
                                    <a class="syl-btn inline" href="#projectCard">Submit</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($practice['pool'] > 0): ?>
                        <div class="practice-box">
                            <div class="section-label">Practice <span>(doesn't count, use it to learn)</span></div>
                            <div class="practice-btns">
                                <button type="button" class="syl-btn inline" data-action="start-practice" data-mode="study" data-task="<?= (int) $topic['taskID'] ?>" title="Answers and explanations after each question">Study mode</button>
                                <button type="button" class="syl-btn inline" data-action="start-practice" data-mode="review" data-task="<?= (int) $topic['taskID'] ?>"<?= $practice['review'] > 0 ? '' : ' disabled' ?> title="Questions you missed, plus the ones due to come back">Review (<?= (int) $practice['review'] ?>)</button>
                            </div>
                            <label class="mini check"><input type="checkbox" id="hideChoices"> Hide the choices until I reveal them</label>
                            <div class="hint">Review unlocks after you have answered some questions.</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($assess['project']):
                        $pr  = $assess['project'];
                        $sub = $pr['submission'] ?? null;
                        $canSubmitProj = (!$sub || $sub['status'] !== 'approved');
                    ?>
                    <div class="syl-widget project-card" id="projectCard">
                        <h3>&#128193; <?= $e($pr['title']) ?></h3>
                        <?php if ($pr['instructions']): ?>
                        <div class="proj-instructions"><?= nl2br($e($pr['instructions'])) ?></div>
                        <?php endif; ?>
                        <div class="hint" style="margin-bottom:10px;">
                            Submission: <?= $e($subTypeLabel[$pr['submission_type']] ?? 'File or link') ?> &middot;
                            Max score: <?= (int) $pr['max_score'] ?> pts &middot;
                            <?= (int) $pr['require_approval'] === 1 ? 'Instructor approval required' : 'Completes upon submission' ?>
                        </div>

                        <?php if ($sub): ?>
                        <div class="proj-sub-status">
                            <div class="cf-row" style="justify-content:space-between;">
                                <strong>Your submission</strong>
                                <?php if ($sub['status'] === 'approved'): ?>
                                    <span class="chip ok">Approved<?= $sub['score'] !== null ? ' · ' . (int) $sub['score'] . '/' . (int) $pr['max_score'] : '' ?></span>
                                <?php elseif ($sub['status'] === 'revision'): ?>
                                    <span class="chip warn">Revision requested</span>
                                <?php else: ?>
                                    <span class="chip">Submitted &middot; Pending review</span>
                                <?php endif; ?>
                            </div>
                            <div class="hint">Submitted <?= $e(date('M j, Y g:i A', strtotime($sub['submitted_at']))) ?></div>
                            <?php if ($sub['link_url']): ?>
                                <div><a class="ci-link" href="<?= $e($sub['link_url']) ?>" target="_blank" rel="noopener noreferrer">Open submitted link &rsaquo;</a></div>
                            <?php endif; ?>
                            <?php if ($sub['file_name']): ?>
                                <div><a class="ci-link" href="<?= $e($projSubUrl($sub['submissionID'])) ?>">Download <?= $e($sub['file_name']) ?><?= $sub['file_size'] ? ' (' . $e($fmtSize($sub['file_size'])) . ')' : '' ?> &rsaquo;</a></div>
                            <?php endif; ?>
                            <?php if ($sub['notes']): ?>
                                <div class="ci-note" style="margin-left:0;"><?= nl2br($e($sub['notes'])) ?></div>
                            <?php endif; ?>
                            <?php if ($sub['feedback']): ?>
                                <div class="proj-feedback">
                                    <strong>Instructor feedback:</strong>
                                    <div><?= nl2br($e($sub['feedback'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($canSubmitProj): ?>
                        <form class="project-submit-form" data-project="<?= (int) $pr['projectID'] ?>" data-sub-type="<?= $e($pr['submission_type']) ?>">
                            <h4><?= $sub ? 'Update submission' : 'Submit your project' ?></h4>
                            <?php if ($pr['submission_type'] === 'link' || $pr['submission_type'] === 'both'): ?>
                            <label class="mini">Project link (GitHub, Google Drive, Figma, live URL...)
                                <input class="syl-input" type="url" name="link_url" maxlength="500" value="<?= $sub ? $e($sub['link_url']) : '' ?>" placeholder="https://...">
                            </label>
                            <?php endif; ?>
                            <?php if ($pr['submission_type'] === 'file' || $pr['submission_type'] === 'both'): ?>
                            <label class="mini">Attach file (PDF, ZIP, DOCX, PPTX, XLSX, TXT, or image — up to 50 MB)
                                <input class="syl-input file" type="file" name="file" accept=".pdf,.zip,.doc,.docx,.pptx,.xlsx,.txt,.png,.jpg,.jpeg,.webp">
                            </label>
                            <?php endif; ?>
                            <label class="mini">Notes for instructor (optional)
                                <textarea class="syl-input" name="notes" maxlength="4000" placeholder="Add any notes or instructions for running/viewing your work..."><?= $sub ? $e($sub['notes']) : '' ?></textarea>
                            </label>
                            <div class="cf-row">
                                <button type="submit" class="syl-btn inline cta"><?= $sub ? 'Resubmit project' : 'Submit project' ?></button>
                                <span class="upload-label"></span>
                            </div>
                            <div class="upload-progress" hidden><div></div></div>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php elseif ($mode === 'preview' || $mode === 'observer'): ?>
                        <?php if ($assess['quiz'] || $assess['exam'] || $assess['project']): ?>
                        <div class="syl-widget">
                            <h3>Assessments</h3>
                            <?php foreach (['quiz', 'exam'] as $k): if (!$assess[$k]) continue; ?>
                                <div class="stats-text"><?= $k === 'quiz' ? '&#129514;' : '&#128221;' ?> <?= $e($assess[$k]['title']) ?> <span class="chip"><?= $k === 'quiz' ? 'Quiz' : 'Exam' ?> &middot; <?= $e($modeName[$assess[$k]['mode']] ?? 'Standard') ?></span> &middot; pass mark <?= (int) $assess[$k]['pass_percent'] ?>%</div>
                            <?php endforeach; ?>
                            <?php if ($assess['project']): ?>
                                <div class="stats-text">&#128193; <?= $e($assess['project']['title']) ?> <span class="chip assess-chip proj">Project</span> &middot; <?= (int) $assess['project']['max_score'] ?> pts</div>
                            <?php endif; ?>
                            <div class="milestone-subtext">Students complete assessments here. Submissions are disabled in preview.</div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>

            <?php if ($canEdit): ?>
            <!-- Faculty: Assessments builder (Quiz, Exam, Project) -->
            <div class="assess-section-head" id="checkpoints">
                <div>
                    <h2 style="margin:28px 0 4px;font-size:20px;">&#129514; Assessments (Quiz, Exam, Project)</h2>
                    <div class="hint">Add a quiz, an exam, and/or a project for this topic. Tick &ldquo;Published to students&rdquo; when ready.</div>
                </div>
                <div class="assess-add-btns">
                    <?php if (!$assess['exam']): ?>
                    <button type="button" class="syl-btn inline" data-action="reveal-assess" data-target="card-exam">+ Add Exam</button>
                    <?php endif; ?>
                    <?php if (!$assess['project']): ?>
                    <button type="button" class="syl-btn inline" data-action="reveal-assess" data-target="card-project">+ Add Project</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="assess-wrap">
                <?php foreach (['quiz', 'exam'] as $k):
                    $q = $assess[$k];
                    $hiddenByDefault = ($k === 'exam' && !$q);
                    $curMode   = $q ? $q['mode'] : ($k === 'exam' ? 'timed' : 'instant');
                    $allowPrac = $q ? ((int) $q['allow_practice'] === 1) : ($k === 'quiz');
                    $modeOpts  = ['standard' => 'Standard', 'instant' => 'Instant feedback', 'timed' => ($k === 'exam' ? 'Timed exam' : 'Timed quiz')];
                    if ($q && !isset($modeOpts[$q['mode']])) $modeOpts[$q['mode']] = ($modeName[$q['mode']] ?? $q['mode']) . ' (older mode)';
                    $labelTitle = ($k === 'exam') ? 'Exam' : 'Quiz';
                ?>
                <div class="assess-card<?= $hiddenByDefault ? ' is-hidden' : '' ?>" id="card-<?= $k ?>" data-kind="<?= $k ?>" data-quiz="<?= $q ? (int) $q['quizID'] : 0 ?>">
                    <h3>
                        <?= $k === 'quiz' ? '&#129514;' : '&#128221;' ?> <?= $labelTitle ?>
                        <?php if ($q): ?><?= (int) $q['is_published'] === 1 ? '<span class="chip ok">Published</span>' : '<span class="chip warn">Draft</span>' ?><?php endif; ?>
                    </h3>
                    <div class="hint" style="margin-bottom:10px;">
                        <?= $k === 'exam'
                            ? 'Use for Midterm, Final, or major graded exams. Defaults to Timed mode.'
                            : 'Students take this after studying. Passing all published assessments completes the topic.' ?>
                    </div>

                    <form class="quiz-settings" data-task="<?= (int) $topic['taskID'] ?>" data-kind="<?= $k ?>">
                        <label class="mini" style="flex-basis:100%;">Title
                            <input class="syl-input" type="text" name="title" maxlength="200" value="<?= $q ? $e($q['title']) : '' ?>" placeholder="<?= $k === 'exam' ? 'e.g. Midterm Exam' : 'Quiz' ?>">
                        </label>
                        <label class="mini" style="flex-basis:100%;">How is it run?
                            <select class="syl-input mode-select" name="mode">
                                <?php foreach ($modeOpts as $mv => $ml): ?>
                                <option value="<?= $e($mv) ?>"<?= $curMode === $mv ? ' selected' : '' ?>><?= $e($ml) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="mode-desc hint" style="flex-basis:100%;"></div>

                        <label class="mini">Pass mark (%)
                            <input class="syl-input" type="number" name="pass_percent" min="1" max="100" value="<?= $q ? (int) $q['pass_percent'] : 70 ?>">
                        </label>
                        <label class="mini mode-only mode-timed">Time limit (minutes)
                            <input class="syl-input" type="number" name="time_limit_minutes" min="1" max="300" value="<?= ($q && $q['time_limit_minutes']) ? (int) $q['time_limit_minutes'] : ($k === 'exam' ? 60 : 20) ?>">
                        </label>
                        <label class="mini check" style="flex-basis:100%;"><input type="checkbox" name="published" value="1"<?= ($q && (int) $q['is_published'] === 1) ? ' checked' : '' ?>> Published to students</label>

                        <details class="more-opts" style="flex-basis:100%;">
                            <summary>Advanced</summary>
                            <div class="adv-grid">
                                <label class="mini">Wait before retake (minutes)
                                    <input class="syl-input" type="number" name="cooldown_minutes" min="0" max="1440" value="<?= $q ? (int) $q['cooldown_minutes'] : ($k === 'exam' ? 10 : 2) ?>">
                                </label>
                                <label class="mini">Random questions to ask (blank = all)
                                    <input class="syl-input" type="number" name="draw_count" min="1" max="500" value="<?= ($q && $q['draw_count']) ? (int) $q['draw_count'] : '' ?>" placeholder="All">
                                </label>
                                <label class="mini check" style="grid-column:1 / -1;"><input type="checkbox" name="allow_practice" value="1"<?= $allowPrac ? ' checked' : '' ?>> Let students practise with these questions</label>
                            </div>
                        </details>

                        <button type="submit" class="syl-btn inline"><?= $q ? 'Save settings' : 'Create ' . strtolower($labelTitle) ?></button>
                        <?php if ($q): ?><button type="button" class="icon-btn" data-action="delete-quiz" data-quiz="<?= (int) $q['quizID'] ?>">Delete</button><?php endif; ?>
                    </form>

                    <?php if ($q): ?>
                        <div class="q-list">
                            <?php foreach ($q['questions'] as $i => $qqItem):
                                $labels  = array_column($qqItem['choices'], 'label');
                                $correct = 0;
                                foreach ($qqItem['choices'] as $ci => $ch) { if ((int) $ch['is_correct'] === 1) { $correct = $ci; break; } }
                                $payload = json_encode([
                                    'id' => (int) $qqItem['questionID'], 'prompt' => $qqItem['prompt'], 'choices' => $labels, 'correct' => $correct,
                                    'explanation' => (string) $qqItem['explanation'], 'difficulty' => (int) $qqItem['difficulty'],
                                ], JSON_UNESCAPED_UNICODE);
                            ?>
                            <div class="q-item" data-q="<?= $e($payload) ?>">
                                <div><strong>Q<?= $i + 1 ?>.</strong> <?= nl2br($e($qqItem['prompt'])) ?>
                                    <?php if (trim((string) $qqItem['explanation']) !== ''): ?><span class="chip">Has explanation</span><?php endif; ?>
                                </div>
                                <ol class="q-choices" type="A">
                                    <?php foreach ($qqItem['choices'] as $ci => $ch): ?>
                                    <li class="<?= (int) $ch['is_correct'] === 1 ? 'correct' : '' ?>"><?= $e($ch['label']) ?></li>
                                    <?php endforeach; ?>
                                </ol>
                                <div class="q-actions">
                                    <button type="button" class="icon-btn" data-action="edit-question">Edit</button>
                                    <button type="button" class="icon-btn" data-action="duplicate-question" data-question="<?= (int) $qqItem['questionID'] ?>">Duplicate</button>
                                    <button type="button" class="icon-btn" data-action="delete-question" data-question="<?= (int) $qqItem['questionID'] ?>">Delete</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (!$q['questions']): ?><div class="hint">No questions yet. Students will not see this until it has at least one question and is published.</div><?php endif; ?>
                        </div>

                        <form class="question-form" data-quiz="<?= (int) $q['quizID'] ?>">
                            <h4 class="qf-title">Add a question</h4>
                            <input type="hidden" name="questionID" value="0">
                            <input type="hidden" name="difficulty" value="2">
                            <textarea class="syl-input" name="prompt" maxlength="2000" placeholder="Question"></textarea>
                            <div class="choice-rows">
                                <?php for ($i = 0; $i < 4; $i++): ?>
                                <label class="choice-row"><input type="radio" name="correct" value="<?= $i ?>"<?= $i === 0 ? ' checked' : '' ?>><input class="syl-input grow" type="text" name="choice" maxlength="500" placeholder="Answer choice <?= chr(65 + $i) ?>"><button type="button" class="icon-btn choice-remove" data-action="remove-choice" title="Remove this choice" aria-label="Remove this choice">&#10005;</button></label>
                                <?php endfor; ?>
                            </div>
                            <div class="hint">Select the circle next to the correct answer. Use &#10005; to remove a choice (minimum 2) or + Add choice (up to 6).</div>
                            <details class="more-opts">
                                <summary>Add an explanation (optional)</summary>
                                <textarea class="syl-input" name="explanation" maxlength="1500" placeholder="Shown to students after they answer. It helps them learn from a wrong answer."></textarea>
                            </details>
                            <div class="cf-row">
                                <button type="button" class="icon-btn" data-action="add-choice">+ Add choice</button>
                                <button type="button" class="icon-btn" data-action="true-false" title="Replace the choices with True / False">True / False</button>
                                <button type="submit" class="syl-btn inline">Save question</button>
                                <button type="button" class="syl-btn inline is-hidden" data-action="cancel-question">Cancel edit</button>
                            </div>
                        </form>

                        <details class="more-opts import-box" data-quiz="<?= (int) $q['quizID'] ?>">
                            <summary>Import many questions at once</summary>
                            <div class="hint">Paste your questions below, or upload a CSV file. Put a blank line between questions and add a <strong>*</strong> after the correct choice:</div>
                            <pre class="import-example">What is 2 + 2?
A) 3
B) 4 *
C) 5
Explanation: Basic addition.</pre>
                            <textarea class="syl-input" name="paste" placeholder="Paste your questions here"></textarea>
                            <div class="cf-row">
                                <input class="syl-input file" type="file" name="csv" accept=".csv,text/csv">
                                <button type="button" class="icon-btn" data-action="csv-template">Download CSV template</button>
                            </div>
                            <div class="cf-row">
                                <button type="button" class="syl-btn inline" data-action="import-questions">Import questions</button>
                            </div>
                        </details>
                    <?php else: ?>
                        <div class="hint">Create it first, then add questions.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Project assessment builder card -->
                <?php $pr = $assess['project']; ?>
                <div class="assess-card<?= !$pr ? ' is-hidden' : '' ?>" id="card-project" data-kind="project">
                    <h3>
                        &#128193; Project
                        <?php if ($pr): ?><?= (int) $pr['is_published'] === 1 ? '<span class="chip ok">Published</span>' : '<span class="chip warn">Draft</span>' ?><?php endif; ?>
                    </h3>
                    <div class="hint" style="margin-bottom:10px;">
                        Students submit a file, a link (GitHub, Drive, Figma), or both. Review and grade submissions in the progress table below.
                    </div>

                    <form class="project-settings" data-task="<?= (int) $topic['taskID'] ?>">
                        <label class="mini" style="flex-basis:100%;">Title
                            <input class="syl-input" type="text" name="title" maxlength="200" value="<?= $pr ? $e($pr['title']) : '' ?>" placeholder="e.g. Midterm Project: Database Design">
                        </label>
                        <label class="mini" style="flex-basis:100%;">Instructions / rubric
                            <textarea class="syl-input" name="instructions" maxlength="10000" rows="4" placeholder="Describe deliverables, requirements, and grading criteria..."><?= $pr ? $e($pr['instructions']) : '' ?></textarea>
                        </label>
                        <label class="mini">Submission type
                            <select class="syl-input" name="submission_type">
                                <?php $curSub = $pr ? $pr['submission_type'] : 'both'; ?>
                                <option value="both"<?= $curSub === 'both' ? ' selected' : '' ?>>File upload or link</option>
                                <option value="file"<?= $curSub === 'file' ? ' selected' : '' ?>>File upload only</option>
                                <option value="link"<?= $curSub === 'link' ? ' selected' : '' ?>>Link / URL only</option>
                            </select>
                        </label>
                        <label class="mini">Max score
                            <input class="syl-input" type="number" name="max_score" min="1" max="1000" value="<?= $pr ? (int) $pr['max_score'] : 100 ?>">
                        </label>
                        <label class="mini">Pass score
                            <input class="syl-input" type="number" name="pass_score" min="1" max="1000" value="<?= $pr ? (int) $pr['pass_score'] : 70 ?>">
                        </label>
                        <label class="mini check" style="flex-basis:100%;">
                            <input type="checkbox" name="require_approval" value="1"<?= (!$pr || (int) $pr['require_approval'] === 1) ? ' checked' : '' ?>> Require instructor approval to mark topic completed
                        </label>
                        <label class="mini check" style="flex-basis:100%;">
                            <input type="checkbox" name="published" value="1"<?= ($pr && (int) $pr['is_published'] === 1) ? ' checked' : '' ?>> Published to students
                        </label>

                        <button type="submit" class="syl-btn inline"><?= $pr ? 'Save project' : 'Create project' ?></button>
                        <?php if ($pr): ?><button type="button" class="icon-btn" data-action="delete-project" data-project="<?= (int) $pr['projectID'] ?>">Delete</button><?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Faculty: how the class is doing on this topic -->
            <div class="progress-card">
                <h3>Student progress on this topic</h3>
                <?php if (!$progressRows): ?>
                    <div class="milestone-subtext">No students are enrolled in this class yet.</div>
                <?php else:
                    $showExamCol = (bool) $assess['exam'];
                    $showProjCol = (bool) $assess['project'];
                ?>
                <table class="progress-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Quiz</th>
                            <?php if ($showExamCol): ?><th>Exam</th><?php endif; ?>
                            <?php if ($showProjCol): ?><th>Project</th><?php endif; ?>
                            <th>Last opened</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($progressRows as $prRow): ?>
                        <tr>
                            <td><?= $e($prRow['name']) ?></td>
                            <td><span class="chip <?= $prRow['state'] === 'completed' ? 'ok' : ($prRow['state'] === 'restudy' ? 'warn' : '') ?>"><?= $e($stateLabel[$prRow['state']]) ?></span></td>
                            <td>
                                <?php $aq = $prRow['quiz']; if ($aq): ?>
                                    <?= (int) $aq['best'] ?>% <span class="milestone-subtext">(<?= (int) $aq['n'] ?> tr<?= (int) $aq['n'] === 1 ? 'y' : 'ies' ?>)</span> <?= (int) $aq['passed'] === 1 ? '<span class="chip ok">Passed</span>' : '<span class="chip bad">Not passed</span>' ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <?php if ($showExamCol): ?>
                            <td>
                                <?php $ae = $prRow['exam']; if ($ae): ?>
                                    <?= (int) $ae['best'] ?>% <span class="milestone-subtext">(<?= (int) $ae['n'] ?> tr<?= (int) $ae['n'] === 1 ? 'y' : 'ies' ?>)</span> <?= (int) $ae['passed'] === 1 ? '<span class="chip ok">Passed</span>' : '<span class="chip bad">Not passed</span>' ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($showProjCol): ?>
                            <td>
                                <?php $ps = $prRow['project']; if ($ps):
                                    $gradePayload = json_encode([
                                        'id'       => (int) $ps['submissionID'],
                                        'student'  => $prRow['name'],
                                        'status'   => $ps['status'],
                                        'score'    => $ps['score'],
                                        'max'      => (int) $assess['project']['max_score'],
                                        'feedback' => (string) ($ps['feedback'] ?? ''),
                                        'link'     => (string) ($ps['link_url'] ?? ''),
                                        'file'     => (string) ($ps['file_name'] ?? ''),
                                        'fileUrl'  => !empty($ps['stored_name']) ? $projSubUrl($ps['submissionID']) : '',
                                        'notes'    => (string) ($ps['notes'] ?? ''),
                                        'date'     => date('M j, Y g:i A', strtotime($ps['submitted_at'])),
                                    ], JSON_UNESCAPED_UNICODE);
                                ?>
                                    <?php if ($ps['status'] === 'approved'): ?>
                                        <span class="chip ok">Approved<?= $ps['score'] !== null ? ' · ' . (int) $ps['score'] . '/' . (int) $assess['project']['max_score'] : '' ?></span>
                                    <?php elseif ($ps['status'] === 'revision'): ?>
                                        <span class="chip warn">Revision</span>
                                    <?php else: ?>
                                        <span class="chip">Submitted</span>
                                    <?php endif; ?>
                                    <button type="button" class="icon-btn" data-action="grade-project" data-sub="<?= $e($gradePayload) ?>">Review</button>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?= $prRow['last'] ? $e(date('M j, g:i A', strtotime($prRow['last']))) : '&mdash;' ?></td>
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
