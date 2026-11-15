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
$canManage  = false;
$preview    = false;
$canEdit    = false;

try {
    $topic = $taskID > 0 ? $sl->topicPage($taskID) : null;
    if ($topic) {
        $canManage = $sl->canManageClass((int) $topic['schedID']);
        $preview   = ($canManage && ($_GET['preview'] ?? '') === '1');
        $canEdit   = ($canManage && !$preview);
        $contents  = $sl->contentsForTask($taskID, $canEdit);
    } else {
        http_response_code(404);
    }
} catch (PDOException $ex) {
    error_log('Syllabus topic page: ' . $ex->getMessage());
    $setupError = true;
}

$backUrl     = BASE_PATH . '/syllabus' . ($topic ? '?class=' . (int) $topic['schedID'] . ($preview ? '&preview=1' : '') : '');
$topicUrl    = $topic ? BASE_PATH . '/syllabus-topic?id=' . (int) $topic['taskID'] : '';
$badgeLabels = ['completed' => 'COMPLETED', 'progress' => 'IN PROGRESS', 'not-started' => 'NOT STARTED', 'upcoming' => 'UPCOMING'];
$badgeClass  = ['completed' => 'status-completed', 'progress' => 'status-progress', 'not-started' => 'status-not-started', 'upcoming' => 'status-upcoming'];
$dbStatuses  = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'];
$kindIcon    = ['pdf' => '&#128196;', 'video' => '&#127916;', 'note' => '&#128221;'];
$kindLabel   = ['pdf' => 'PDF document', 'video' => 'Video', 'note' => 'Text note'];

$deltaJson = 'null';
$lessonHtml = '';
if ($topic) {
    if ($canEdit && $topic['delta']) {
        $arr = json_decode($topic['delta'], true);
        if (is_array($arr)) {
            $deltaJson = json_encode($arr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        }
    }
    $lessonHtml = $sl->sanitizeHtml((string) $topic['html']);   // defence in depth: sanitized on save AND on read
}
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
  <?php if ($canEdit): ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
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
             <?= $canEdit ? 'data-edit="1"' : '' ?>>

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
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="course-info"><?= $e($topic['week_label']) ?> &middot; <?= $e($topic['module_title']) ?><?= $topic['class_label'] ? ' &middot; ' . $e($topic['class_label']) : '' ?></div>
                </div>
                <div class="topic-header-side">
                    <?php if ($canEdit): ?>
                        <select class="status-select" data-task="<?= (int) $topic['taskID'] ?>" aria-label="Topic status">
                            <?php foreach ($dbStatuses as $val => $label): ?>
                            <option value="<?= $e($val) ?>"<?= $topic['status'] === $val ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="status-badge <?= $e($badgeClass[$topic['view']]) ?>"><?= $e($badgeLabels[$topic['view']]) ?></div>
                    <?php endif; ?>
                    <?php if ($topic['due_date']): ?>
                        <div class="milestone-subtext" style="<?= $topic['overdue'] ? 'color:#ff6b6b;' : '' ?>">Due <?= $e($fmt($topic['due_date'])) ?><?= $topic['overdue'] ? ' (overdue)' : '' ?></div>
                    <?php endif; ?>
                </div>
            </div>

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

                <!-- Learning materials -->
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

            <?php endif; ?>
        </div>

      </main>
    </div>
  </div>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
  <?php if ($canEdit): ?>
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
  <?php endif; ?>
  <script src="<?= BASE_PATH ?>/View/script/syllabus_topic.js" defer></script>
</body>
</html>
