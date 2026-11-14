<?php
require_once __DIR__ . "/../PageLogic/projects_logic.php";

$logic = new ProjectsLogic();
$logic->handlePost();
$logic->loadData();


if (!function_exists('proj_e')) {
    function proj_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('proj_date')) {
    function proj_date($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
}
if (!function_exists('proj_badge')) {
    function proj_badge($submitted) {
        $class = $submitted ? 'done' : 'pending';
        $label = $submitted ? 'Completed' : 'Pending';
        return '<span class="proj-badge ' . $class . '">' . $label . '</span>';
    }
}

$formAction = proj_e(ProjectsLogic::url());

$group    = $logic->group;
$error    = $logic->error;
$flash    = $logic->takeFlash();
$csrf     = $logic->csrf();
$stats    = $logic->stats;
$selected = $logic->selected;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Projects</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">

  <style>
.projects-page { padding: 30px !important; color: #f7f1ff; }
.projects-page h1 { color: #fff; font-size: clamp(25px, 3vw, 32px); font-weight: 700 !important; margin: 0; }
.projects-page h2 { color: #fff; font-size: 19px !important; font-weight: 650; margin-top: 0; margin-bottom: 18px; }
.proj-card { background: linear-gradient(145deg, rgba(67, 32, 83, 0.72), rgba(28, 12, 43, 0.78)) !important; border: 1px solid rgba(220, 185, 255, 0.18) !important; border-radius: 16px !important; padding: 24px !important; box-shadow: 0 10px 30px rgba(10, 0, 20, 0.18); backdrop-filter: blur(14px); min-width: 0; }
.proj-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
.proj-search { width: min(360px, 100%); min-height: 40px; padding: 9px 18px; box-sizing: border-box; border: 1px solid rgba(220, 185, 255, 0.24); border-radius: 20px; background: rgba(14, 5, 24, 0.42); color: #fff; font-family: inherit; font-size: 13px; outline: none; }
.proj-search:focus { border-color: #bd85ed; box-shadow: 0 0 0 3px rgba(189, 133, 237, 0.13); }
.proj-search::placeholder { color: rgba(255, 255, 255, 0.45); }
.proj-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin-bottom: 22px; }
.proj-stat { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border: 1px solid rgba(220, 185, 255, 0.28); border-radius: 14px; background: rgba(255, 255, 255, 0.06); }
.proj-stat span { display: block; font-size: 13px; font-weight: 700; color: #e6d3f7; }
.proj-stat strong { display: block; margin-top: 4px; font-size: 34px; line-height: 1; }
.table-responsive { width: 100%; overflow-x: auto; border-radius: 10px; }
.proj-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; min-width: 640px; }
.proj-table th { text-align: left; padding: 15px 13px; background: rgba(14, 5, 24, 0.42); border-bottom: 1px solid rgba(210, 174, 255, 0.22); color: #d8b8f2; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; white-space: nowrap; }
.proj-table td { padding: 14px 13px; border-bottom: 1px solid rgba(255, 255, 255, 0.07); color: rgba(255, 255, 255, 0.9); vertical-align: middle; }
.proj-table tbody tr:hover { background: rgba(190, 130, 255, 0.09); }
.proj-table tbody tr:last-child td { border-bottom: none; }
.proj-empty { text-align: center; color: rgba(255, 255, 255, 0.6); }
.proj-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 78px; padding: 4px 11px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.proj-badge.pending { background: rgba(218, 164, 65, 0.18); border: 1px solid rgba(255, 204, 112, 0.45); color: #ffd27e; }
.proj-badge.done { background: rgba(46, 180, 120, 0.16); border: 1px solid rgba(73, 207, 148, 0.45); color: #7ce5b5; }
.btn-proj { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 7px 14px; border-radius: 8px; border: 1px solid rgba(216, 184, 242, 0.3); background: rgba(160, 105, 207, 0.16); color: #fff; font-family: inherit; font-size: 12px; font-weight: 650; text-decoration: none; cursor: pointer; white-space: nowrap; transition: all 0.2s ease; }
.btn-proj:hover { background: rgba(177, 119, 255, 0.28); border-color: #c99aff; transform: translateY(-1px); }
.btn-proj:focus-visible { outline: 2px solid #d9b5ff; outline-offset: 3px; }
.btn-proj:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
.btn-proj.primary { background: #277b58; border-color: #3da879; }
.btn-proj.primary:hover:not(:disabled) { background: #32976b; border-color: #63d4a0; }
.btn-view { padding: 4px 16px; min-height: 26px; border-radius: 14px; background: rgba(14, 5, 24, 0.6); }
.btn-view-icon { display: block; width: 20px; height: auto; }
.form-group { margin-bottom: 16px; min-width: 0; }
.form-group label { display: block; color: #dfc8f2; margin-bottom: 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.7px; }
.form-control { display: block; width: 100%; min-height: 42px; padding: 10px 12px; box-sizing: border-box; border: 1px solid rgba(220, 185, 255, 0.24); border-radius: 9px; background: rgba(14, 5, 24, 0.42); color: #fff; font-family: inherit; font-size: 13px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
.form-control:focus { border-color: #bd85ed; box-shadow: 0 0 0 3px rgba(189, 133, 237, 0.13); }
.form-control:disabled, .form-control[readonly] { opacity: 0.75; }
textarea.form-control { min-height: 110px; resize: vertical; }
.proj-backdrop { position: fixed; inset: 0; z-index: 50; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 4, 25, 0.62); }
.proj-backdrop.open { display: flex; }
.proj-modal { position: relative; width: min(600px, 100%); max-height: 90vh; overflow-y: auto; padding: 24px; border: 2px solid #fff; border-radius: 22px; background: #4d2a52; }
.proj-chip { display: inline-block; padding: 5px 14px; border: 1px solid rgba(220, 185, 255, 0.35); border-radius: 6px; background: #1f1035; font-size: 12px; font-weight: 700; }
.proj-modal h2 { font-size: 28px !important; margin: 8px 0 2px; }
.proj-modal .by { margin: 0 0 14px; font-size: 13px; }
.proj-close { position: absolute; top: 16px; right: 16px; width: 32px; height: 32px; border: 0; border-radius: 50%; background: #1b0c24; color: #fff; font-size: 16px; cursor: pointer; }
.proj-label { margin: 14px 0 8px; font-size: 15px; font-weight: 700; }
.proj-field { padding: 13px 14px; border-radius: 8px; background: #3a1d44; font-size: 13px; min-height: 20px; word-break: break-all; }
.proj-meta { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: #3a1d44; font-size: 13px; }
.proj-detail { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(300px, 0.8fr); gap: 22px; align-items: start; margin-top: 22px; }
.proj-stack { display: grid; gap: 20px; min-width: 0; }
.proj-section-title { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 13px; font-weight: 700; letter-spacing: 0.5px; }
.proj-divider { border: 0; border-top: 1px solid rgba(255, 255, 255, 0.25); margin: 18px 0; }
.proj-member { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; padding: 10px 18px; border: 1px solid rgba(220, 185, 255, 0.3); border-radius: 10px; background: rgba(40, 12, 55, 0.5); }
.proj-member small { display: block; color: #d9c4ea; font-size: 10px; }
.proj-role { margin-left: auto; padding: 4px 16px; border: 1px solid #fff; border-radius: 5px; font-size: 11px; font-weight: 700; }
.proj-avatar { flex: none; width: 32px; height: 32px; border-radius: 50%; background: #fff; color: #4d2a52; display: grid; place-items: center; font-size: 13px; font-weight: 700; }
.proj-board { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
.proj-col { min-width: 0; padding: 12px; border: 1px solid rgba(220, 185, 255, 0.3); border-radius: 10px; background: rgba(30, 8, 45, 0.45); }
.proj-col h4 { margin: 0 0 10px; font-size: 14px; }
.proj-task { margin-bottom: 8px; padding: 10px 12px; border: 1px solid rgba(255, 255, 255, 0.35); border-radius: 10px; }
.proj-task p { margin: 0 0 8px; padding-bottom: 8px; border-bottom: 1px solid rgba(255, 255, 255, 0.35); font-size: 12.5px; font-weight: 700; }
.proj-task-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; }
.proj-task select { padding: 3px 6px; border: 1px solid #fff; border-radius: 7px; background: #2a1235; color: #fff; font-family: inherit; font-size: 11px; }
.proj-top-tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1; justify-content: flex-end; }
.btn-proj.assign { background: #1b1035; border-color: #1b1035; border-radius: 20px; padding: 8px 18px; }
.btn-proj.assign:hover { background: #2a1850; }
.proj-assign .form-control { background: #3a1d44; border-color: transparent; }
.proj-assign textarea.form-control { min-height: 80px; }
.proj-picker { position: relative; }
.proj-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.proj-chips:not(:empty) { margin-bottom: 8px; }
.proj-chip-sel { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px 4px 12px; border-radius: 14px; background: #1b1035; font-size: 12px; font-weight: 700; }
.proj-chip-sel button { padding: 0 2px; border: 0; background: none; color: #fff; font-size: 15px; line-height: 1; cursor: pointer; }
.proj-nomatch { display: none; margin: 6px; font-size: 13px; }
.proj-picker-list { display: none; position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% + 4px); max-height: 190px; overflow-y: auto; padding: 8px; border: 1px solid rgba(220, 185, 255, 0.35); border-radius: 9px; background: #2a1235; }
.proj-check { display: flex; align-items: center; gap: 10px; padding: 7px 8px; border-radius: 6px; font-size: 13px; cursor: pointer; }
.proj-check:hover { background: rgba(190, 130, 255, 0.15); }
.proj-check input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
.proj-check:has(input:checked) { background: rgba(190, 130, 255, 0.28); font-weight: 700; }
.proj-check small { margin-left: auto; color: #d9c4ea; }
.proj-actions { display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 18px; }
.proj-link-btn { padding: 8px; background: none; border: 0; color: #fff; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
.projects-page .popup { width: fit-content; max-width: min(420px, 100%); min-height: unset; padding: 10px 16px !important; margin: 0 0 14px auto; border: 1px solid rgba(120, 255, 180, 0.3); border-radius: 10px; background: rgba(25, 170, 90, 0.16) !important; color: #a8ffd0 !important; font-size: 13px; font-weight: 600; }

/* NEW CSS FOR FILE CLEAR BUTTON */
.file-input-wrapper { position: relative; display: flex; align-items: center; }
.file-input-wrapper .form-control { padding-right: 35px; }
.file-clear-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 60, 60, 0.8);
    color: white;
    border: none;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    line-height: 1;
    transition: background 0.2s;
    z-index: 10;
}
.file-clear-btn:hover { background: rgba(255, 60, 60, 1); }

@media (max-width: 1100px) { .proj-detail { grid-template-columns: 1fr; } }
@media (max-width: 700px) { .projects-page { padding: 16px !important; } .proj-card { padding: 17px !important; border-radius: 13px !important; } .proj-stats { grid-template-columns: 1fr; gap: 12px; } }
  </style>
</head>
<body data-page="PROJECTS">

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>

  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar projects-page" style="padding: 30px;">

        <?php if ($flash): ?>
            <div class="popup <?= $flash['type'] === 'error' ? 'error' : '' ?>" style="position: relative; top: 0; right: 0; margin-bottom: 15px; display: inline-block; animation: none;">
                <?php foreach ($flash['msgs'] as $m): ?><p><?= proj_e($m) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="popup error" style="position: relative; top: 0; right: 0; margin-bottom: 15px; display: inline-block; animation: none;">
                <p><?= proj_e($error) ?></p>
            </div>
        <?php endif; ?>

        <?php /* ===================== STUDENT VIEW ===================== */ ?>
        <?php if ($group === 'student'): ?>

            <?php if (!$selected): ?>
            <?php /* ---------- LIST PAGE ---------- */ ?>

            <div class="proj-top">
                <h1>Projects</h1>
                <input type="search" id="proj-search" class="proj-search" placeholder="Search projects..." aria-label="Search projects">
            </div>

            <div class="proj-stats">
                <div class="proj-stat"><div><span>Assigned Projects</span><strong><?= (int)$stats['total'] ?></strong></div></div>
                <div class="proj-stat"><div><span>Pending Submission</span><strong><?= (int)$stats['pending'] ?></strong></div></div>
                <div class="proj-stat"><div><span>Submitted</span><strong><?= (int)$stats['submitted'] ?></strong></div></div>
            </div>

            <div class="proj-card">
                <h2>Coursework &amp; Assigned Projects</h2>
                <div class="table-responsive">
                    <table class="proj-table" id="proj-table">
                        <thead>
                            <tr><th>PROJECT ID</th><th>PROJECT NAME</th><th>ASSIGNED BY</th><th>DEADLINE</th><th>STATUS</th><th>ACTION</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logic->projects)): ?>
                                <tr><td colspan="6" class="proj-empty">No projects have been assigned to you yet.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($logic->projects as $p): $done = (int)$p['submitted'] === 1; ?>
                                <tr>
                                    <td><?= proj_e($p['code']) ?></td>
                                    <td><?= proj_e($p['name']) ?></td>
                                    <td><?= proj_e($p['assigned_by']) ?></td>
                                    <td><?= proj_e(proj_date($p['deadline'])) ?></td>
                                    <td><?= proj_badge($done) ?></td>
                                    <td>
                                        <button type="button" class="btn-proj btn-view js-view-project" aria-label="View <?= proj_e($p['name']) ?>"
                                            data-id="<?= (int)$p['id'] ?>"
                                            data-code="<?= proj_e($p['code']) ?>"
                                            data-name="<?= proj_e($p['name']) ?>"
                                            data-by="<?= proj_e($p['assigned_by']) ?>"
                                            data-deadline="<?= proj_e(proj_date($p['deadline'])) ?>"
                                            data-done="<?= $done ? 1 : 0 ?>"
                                            data-file="<?= proj_e($p['file_url'] ?? '') ?>"
                                            data-attachment="<?= proj_e($p['attachment'] ?? '') ?>"
                                            data-notes="<?= proj_e($p['notes'] ?? '') ?>"><img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="" class="btn-view-icon"></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- POPUP -->
            <div class="proj-backdrop" id="proj-backdrop">
                <div class="proj-modal" role="dialog" aria-modal="true" aria-labelledby="pm-name">
                    <button type="button" class="proj-close" id="pm-close" aria-label="Close">&times;</button>
                    <span class="proj-chip" id="pm-code"></span>
                    <h2 id="pm-name"></h2>
                    <p class="by">Assigned by: <strong id="pm-by"></strong></p>

                    <div class="proj-label">Project File / Repository URL</div>
                    <div class="proj-field" id="pm-file"></div>

                    <div class="proj-meta">
                        <span>Deadline: <strong id="pm-deadline"></strong></span>
                        <span>Status: <span id="pm-status"></span></span>
                    </div>

                    <div class="proj-label">File / Attachment</div>
                    <div class="proj-field" id="pm-attachment"></div>

                    <div class="proj-label">Submission Notes / Comments</div>
                    <div class="proj-field" id="pm-notes" style="min-height: 80px;"></div>

                    <div style="margin-top: 18px; text-align: right;">
                        <a href="#" class="btn-proj" id="pm-open">Open project</a>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <?php /* ---------- DETAIL PAGE ---------- */
                $submitted = $logic->submission !== null;
                $locked    = $submitted || !$logic->isLead;
            ?>

            <a href="<?= proj_e(ProjectsLogic::url()) ?>" class="btn-proj" style="margin-bottom: 20px; width: fit-content;">← Back to projects</a>

            <div class="proj-detail">
                <div class="proj-stack">

                    <!-- PROJECT INFO -->
                    <div class="proj-card">
                        <span class="proj-chip"><?= proj_e($selected['code']) ?></span>
                        <h1 style="margin-top: 8px;"><?= proj_e($selected['name']) ?></h1>
                        <p style="font-size: 13px;">Assigned by: <?= proj_e($selected['assigned_by']) ?></p>

                        <hr class="proj-divider">
                        <h3 class="proj-section-title">PROJECT INSTRUCTIONS</h3>
                        <p style="font-size: 14px; margin: 0;"><?= nl2br(proj_e($selected['instructions'])) ?></p>

                        <hr class="proj-divider">
                        <p style="font-size: 13px; margin: 0;">Deadline: <?= proj_e(proj_date($selected['deadline'])) ?> &middot; <?= proj_badge($submitted) ?></p>
                    </div>

                    <!-- TEAM + TASKS -->
                    <div class="proj-card">
                        <h3 class="proj-section-title">TEAM MEMBERS (<?= count($logic->team) ?>)</h3>

                        <?php foreach ($logic->team as $m):
                            $initial = strtoupper(substr($m['first_name'], 0, 1)); ?>
                            <div class="proj-member">
                                <span class="proj-avatar"><?= proj_e($initial) ?></span>
                                <div>
                                    <strong><?= proj_e($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                    <small>Student ID: <?= (int)$m['studentID'] ?></small>
                                </div>
                                <span class="proj-role"><?= $m['role'] === 'lead' ? 'TEAM LEAD' : 'MEMBER' ?></span>
                            </div>
                        <?php endforeach; ?>

                        <hr class="proj-divider">
                        
                        <!-- UPDATED: Task Board with Add Button -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h3 class="proj-section-title" style="margin: 0;">TASK BREAKDOWN &amp; PROGRESS</h3>
                            <?php if (!$submitted): ?>
                                <button type="button" class="btn-proj assign" id="open-add-task" style="padding: 4px 12px; font-size: 11px; border-radius: 8px;">+ Add Task</button>
                            <?php endif; ?>
                        </div>

                        <?php
                        $columns = ['todo' => 'TO DO', 'progress' => 'IN PROGRESS', 'done' => 'COMPLETED'];
                        $options = ['todo' => 'To Do', 'progress' => 'In Progress', 'done' => 'Completed'];
                        ?>
                        <div class="proj-board">
                            <?php foreach ($columns as $key => $label): ?>
                                <div class="proj-col">
                                    <h4><?= $label ?>:</h4>

                                    <?php foreach ($logic->tasks[$key] as $t): ?>
                                        <div class="proj-task">
                                            <p><?= proj_e($t['title']) ?></p>
                                            <div class="proj-task-foot">
                                                <span>Due <?= proj_e(proj_date($t['deadline'])) ?></span>

                                                <form method="POST" action="<?= $formAction ?>">
                                                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                    <input type="hidden" name="project_action" value="update_task">
                                                    <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                    <select name="status" onchange="this.form.submit()" aria-label="Task status">
                                                        <?php foreach ($options as $val => $text): ?>
                                                            <option value="<?= $val ?>" <?= $val === $key ? 'selected' : '' ?>><?= $text ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- SUBMIT PANEL -->
                <aside class="proj-card">
                    <h3 class="proj-section-title" style="font-size: 17px;">Pass / Submit Project</h3>
                    <hr class="proj-divider" style="margin-top: 0;">

                    <!-- ADDED enctype FOR FILE UPLOAD -->
                    <form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                        <input type="hidden" name="project_action" value="submit_project">
                        <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">

                        <div class="form-group">
                            <label>PROJECT FILE / REPOSITORY URL</label>
                            <input type="text" name="file_url" class="form-control" placeholder="WebAppDashboard.pdf"
                                   value="<?= proj_e($logic->submission['file_url'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?> required>
                        </div>

                        <div class="form-group">
                            <label>SUBMISSION NOTES / COMMENTS</label>
                            <textarea name="notes" class="form-control" <?= $locked ? 'disabled' : '' ?>><?= proj_e($logic->submission['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>FILE / ATTACHMENT</label>
                            
                            <!-- CHANGED TO type="file" WITH X BUTTON WRAPPER -->
                            <div class="file-input-wrapper">
                                <input type="file" name="attachment" id="student-file-input" class="form-control" <?= $locked ? 'disabled' : '' ?> style="padding-top: 8px;">
                                <button type="button" id="clear-file-btn" class="file-clear-btn" style="display: none;" title="Remove selected file">&times;</button>
                            </div>
                            
                            <?php if (!empty($logic->submission['attachment'])): ?>
                                <a href="<?= BASE_PATH ?>/<?= proj_e($logic->submission['attachment']) ?>" target="_blank" class="btn-proj" style="margin-top: 10px; width: 100%; background: #3a1d44; border-color: #d8b8f2;">
                                    📁 View Uploaded File
                                </a>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn-proj primary" style="width: 100%;" <?= $locked ? 'disabled' : '' ?>>
                            <?= $submitted ? '✔ Submitted to Professor' : '✔ Pass Project to Professor' ?>
                        </button>

                        <?php if (!$submitted && !$logic->isLead): ?>
                            <p style="margin: 12px 0 0; font-size: 12px; color: rgba(255,255,255,0.7);">Only the team lead can submit this project.</p>
                        <?php endif; ?>
                    </form>
                </aside>
            </div>

            <!-- NEW: ADD TASK POPUP -->
            <?php if (!$submitted): ?>
            <div class="proj-backdrop" id="add-task-backdrop">
                <form class="proj-modal" method="POST" action="<?= $formAction ?>" role="dialog" aria-modal="true">
                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                    <input type="hidden" name="project_action" value="add_task">
                    <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">

                    <button type="button" class="proj-close" id="add-task-close" aria-label="Close">&times;</button>
                    <h2 style="margin-bottom: 14px;">Add New Task</h2>
                    <hr class="proj-divider" style="margin-top: 0;">

                    <div class="form-group">
                        <label for="t-name">TASK TITLE</label>
                        <input type="text" id="t-name" name="task_title" class="form-control" placeholder="e.g. Create database schema" required>
                    </div>

                    <div class="form-group">
                        <label for="t-deadline">DEADLINE DATE</label>
                        <input type="date" id="t-deadline" name="task_deadline" class="form-control" required>
                    </div>

                    <div class="proj-actions">
                        <button type="button" class="proj-link-btn" id="add-task-cancel">Cancel</button>
                        <button type="submit" class="btn-proj primary">Add Task</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>

        <?php /* ===================== INSTRUCTOR VIEW ===================== */ ?>
        <?php if ($group === 'faculty'): ?>

            <?php if (!$selected): ?>
            <?php /* ---------- INSTRUCTOR LIST PAGE ---------- */ ?>
            <div class="proj-top">
                <h1>Projects</h1>
                <div class="proj-top-tools">
                    <input type="search" id="proj-search" class="proj-search" placeholder="Search projects..." aria-label="Search projects">
                    <button type="button" class="btn-proj assign" id="open-assign">＋ Assign Project</button>
                </div>
            </div>

            <div class="proj-stats">
                <div class="proj-stat"><div><span>Assigned Projects</span><strong><?= (int)$stats['total'] ?></strong></div></div>
                <div class="proj-stat"><div><span>Pending Submission</span><strong><?= (int)$stats['pending'] ?></strong></div></div>
                <div class="proj-stat"><div><span>Submitted</span><strong><?= (int)$stats['submitted'] ?></strong></div></div>
            </div>

            <div class="proj-card">
                <h2>Coursework &amp; Assigned Projects</h2>
                <div class="table-responsive">
                    <table class="proj-table" id="proj-table">
                        <thead>
                            <tr><th>PROJECT ID</th><th>PROJECT NAME</th><th>ASSIGNED BY</th><th>DEADLINE</th><th>STATUS</th><th>ACTION</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logic->projects)): ?>
                                <tr><td colspan="6" class="proj-empty">You haven't assigned any projects yet.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($logic->projects as $p): $done = (int)$p['submitted'] === 1; ?>
                                <tr>
                                    <td><?= proj_e($p['code']) ?></td>
                                    <td><?= proj_e($p['name']) ?></td>
                                    <td><?= proj_e($p['assigned_by']) ?></td>
                                    <td><?= proj_e(proj_date($p['deadline'])) ?></td>
                                    <td><?= proj_badge($done) ?></td>
                                    <td>
                                        <button type="button" class="btn-proj btn-view js-view-project" aria-label="View <?= proj_e($p['name']) ?>"
                                            data-id="<?= (int)$p['id'] ?>"
                                            data-code="<?= proj_e($p['code']) ?>"
                                            data-name="<?= proj_e($p['name']) ?>"
                                            data-by="<?= proj_e($p['assigned_by']) ?>"
                                            data-deadline="<?= proj_e(proj_date($p['deadline'])) ?>"
                                            data-done="<?= $done ? 1 : 0 ?>"
                                            data-file="<?= proj_e($p['file_url'] ?? '') ?>"
                                            data-attachment="<?= proj_e($p['attachment'] ?? '') ?>"
                                            data-notes="<?= proj_e($p['notes'] ?? '') ?>"><img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="" class="btn-view-icon"></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- VIEW POPUP (Read-only) -->
            <div class="proj-backdrop" id="proj-backdrop">
                <div class="proj-modal" role="dialog" aria-modal="true" aria-labelledby="pm-name">
                    <button type="button" class="proj-close" id="pm-close" aria-label="Close">&times;</button>
                    <span class="proj-chip" id="pm-code"></span>
                    <h2 id="pm-name"></h2>
                    <p class="by">Assigned by: <strong id="pm-by"></strong></p>

                    <div class="proj-label">Project File / Repository URL</div>
                    <div class="proj-field" id="pm-file"></div>

                    <div class="proj-meta">
                        <span>Deadline: <strong id="pm-deadline"></strong></span>
                        <span>Status: <span id="pm-status"></span></span>
                    </div>

                    <div class="proj-label">File / Attachment</div>
                    <div class="proj-field" id="pm-attachment"></div>

                    <div class="proj-label">Submission Notes / Comments</div>
                    <div class="proj-field" id="pm-notes" style="min-height: 80px;"></div>

                    <div style="margin-top: 18px; text-align: right;">
                        <a href="#" class="btn-proj" id="pm-open">Open project</a>
                    </div>
                </div>
            </div>

            <!-- ASSIGN NEW PROJECT POPUP -->
            <div class="proj-backdrop" id="assign-backdrop">
                <form class="proj-modal proj-assign" method="POST" action="<?= $formAction ?>" role="dialog" aria-modal="true" aria-labelledby="assign-title">
                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                    <input type="hidden" name="project_action" value="create_project">

                    <button type="button" class="proj-close" id="assign-close" aria-label="Close">&times;</button>
                    <h2 id="assign-title" style="margin-bottom: 14px;">Assign New Project</h2>
                    <hr class="proj-divider" style="margin-top: 0;">

                    <div class="form-group">
                        <label for="a-name">PROJECT NAME</label>
                        <input type="text" id="a-name" name="name" class="form-control" placeholder="e.g. Academic System" maxlength="250" required>
                    </div>

                    <div class="form-group">
                        <label for="a-code">PROJECT ID</label>
                        <input type="text" id="a-code" class="form-control" value="<?= proj_e($logic->nextCode) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="a-by">INSTRUCTOR / ASSIGNED BY</label>
                        <input type="text" id="a-by" class="form-control" value="<?= proj_e($logic->instructorName) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>ASSIGN TEAM LEADER</label>
                        <div class="proj-picker" id="a-leader" data-single="1">
                            <div class="proj-chips"></div>
                            <input type="text" class="form-control proj-pick-input" placeholder="Type the team leader's name, then press Enter..." autocomplete="off" aria-label="Assign Team Leader">
                            <?php if (empty($logic->studentOptions)): ?>
                                <p style="margin: 6px 0 0; font-size: 13px;">No students are enrolled in your classes yet.</p>
                            <?php endif; ?>
                            <div class="proj-picker-list" role="listbox">
                                <?php foreach ($logic->studentOptions as $o): ?>
                                    <label class="proj-check">
                                        <input type="radio" name="leader" value="<?= (int)$o['enrolled_subjectID'] ?>" data-last="<?= proj_e($o['last_name']) ?>">
                                        <?= proj_e($o['last_name'] . ', ' . $o['first_name']) ?>
                                        <small><?= proj_e(trim($o['subjectID'])) ?></small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small style="display:block; margin-top:6px; font-size:11px; color:#d9c4ea;">The team leader is the one who submits the project to you.</small>
                    </div>

                    <div class="form-group">
                        <label>ASSIGN MEMBERS</label>
                        <div class="proj-picker" id="a-members" data-single="0">
                            <div class="proj-chips"></div>
                            <input type="text" class="form-control proj-pick-input" placeholder="Type a member's name, then press Enter..." autocomplete="off" aria-label="Assign Members">
                            <?php if (empty($logic->studentOptions)): ?>
                                <p style="margin: 6px 0 0; font-size: 13px;">No students are enrolled in your classes yet.</p>
                            <?php endif; ?>
                            <div class="proj-picker-list" role="listbox">
                                <?php foreach ($logic->studentOptions as $o): ?>
                                    <label class="proj-check">
                                        <input type="checkbox" name="members[]" value="<?= (int)$o['enrolled_subjectID'] ?>" data-last="<?= proj_e($o['last_name']) ?>">
                                        <?= proj_e($o['last_name'] . ', ' . $o['first_name']) ?>
                                        <small><?= proj_e(trim($o['subjectID'])) ?></small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small style="display:block; margin-top:6px; font-size:11px; color:#d9c4ea;">Optional. Add as many members as you need.</small>
                    </div>

                    <div class="form-group">
                        <label for="a-deadline">DEADLINE DATE</label>
                        <input type="date" id="a-deadline" name="deadline" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="a-instr">INSTRUCTIONS DESCRIPTION</label>
                        <textarea id="a-instr" name="instructions" class="form-control" placeholder="Describe what the students need to do"></textarea>
                    </div>

                    <div class="proj-actions">
                        <button type="button" class="proj-link-btn" id="assign-cancel">Cancel</button>
                        <button type="submit" class="btn-proj assign">Create &amp; Assign</button>
                    </div>
                </form>
            </div>

            <script>
            (function () {
                var backdrop = document.getElementById('assign-backdrop');
                if (!backdrop) return;

                function makePicker(el, onChange) {
                    var single  = el.dataset.single === '1';
                    var input   = el.querySelector('.proj-pick-input');
                    var chips   = el.querySelector('.proj-chips');
                    var boxes   = el.querySelectorAll('.proj-check input');

                    function change(box, state) {
                        box.checked = state;
                        box.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    function render() {
                        chips.innerHTML = '';
                        boxes.forEach(function (c) {
                            if (!c.checked) return;
                            var chip = document.createElement('span');
                            chip.className = 'proj-chip-sel';
                            chip.textContent = c.dataset.last;
                            var x = document.createElement('button');
                            x.type = 'button';
                            x.innerHTML = '&times;';
                            x.setAttribute('aria-label', 'Remove ' + c.dataset.last);
                            x.addEventListener('click', function () { change(c, false); input.focus(); });
                            chip.appendChild(x);
                            chips.appendChild(chip);
                        });
                    }

                    function filter() {
                        var q = input.value.trim().toLowerCase();
                        var shown = 0;
                        el.querySelectorAll('.proj-check').forEach(function (label) {
                            var match = !label.dataset.taken && label.textContent.toLowerCase().indexOf(q) !== -1;
                            label.style.display = match ? '' : 'none';
                            if (match) shown++;
                        });
                    }

                    input.addEventListener('focus', function () { el.classList.add('open'); });
                    input.addEventListener('input', function () { el.classList.add('open'); filter(); });

                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (input.value.trim() === '') return;
                            var first = null;
                            el.querySelectorAll('.proj-check').forEach(function (label) {
                                if (!first && label.style.display !== 'none') first = label.querySelector('input');
                            });
                            if (first) change(first, single ? true : !first.checked);
                        }
                        if (e.key === 'Backspace' && input.value === '') {
                            var on = el.querySelectorAll('.proj-check input:checked');
                            if (on.length) change(on[on.length - 1], false);
                        }
                    });

                    el.addEventListener('change', function () {
                        render();
                        input.value = '';
                        filter();
                        if (single) el.classList.remove('open'); else input.focus();
                        if (onChange) onChange();
                    });

                    return {
                        el: el,
                        boxes: boxes,
                        input: input,
                        render: render,
                        filter: filter,
                        value: function () {
                            var on = el.querySelector('.proj-check input:checked');
                            return on ? on.value : null;
                        }
                    };
                }

                var members;
                var leader = makePicker(document.getElementById('a-leader'), function () {
                    var chosen = leader.value();
                    members.boxes.forEach(function (c) {
                        var taken = chosen !== null && c.value === chosen;
                        if (taken) c.checked = false;
                        c.closest('.proj-check').dataset.taken = taken ? '1' : '';
                    });
                    members.render();
                    members.filter();
                });
                members = makePicker(document.getElementById('a-members'));
                var pickers = [leader, members];

                function closeLists() {
                    pickers.forEach(function (p) { p.el.classList.remove('open'); });
                }

                function open()  { backdrop.classList.add('open'); document.getElementById('a-name').focus(); }
                function close() { backdrop.classList.remove('open'); closeLists(); }

                document.getElementById('open-assign').addEventListener('click', open);
                document.getElementById('assign-close').addEventListener('click', close);
                document.getElementById('assign-cancel').addEventListener('click', close);

                backdrop.addEventListener('click', function (e) {
                    if (e.target === backdrop) close();
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') close();
                });
                document.addEventListener('click', function (e) {
                    pickers.forEach(function (p) {
                        if (!p.el.contains(e.target)) p.el.classList.remove('open');
                    });
                });

                backdrop.querySelector('form').addEventListener('submit', function (e) {
                    if (leader.value() === null) {
                        e.preventDefault();
                        leader.el.classList.add('open');
                        leader.input.focus();
                    }
                });

                var d = new Date();
                d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
                document.getElementById('a-deadline').min = d.toISOString().slice(0, 10);
            })();
            </script>

            <?php else: ?>
            <?php /* ---------- INSTRUCTOR DETAIL PAGE (Read-Only) ---------- */ 
                $submitted = $logic->submission !== null;
            ?>

            <a href="<?= proj_e(ProjectsLogic::url()) ?>" class="btn-proj" style="margin-bottom: 20px; width: fit-content;">← Back to projects</a>

            <div class="proj-detail">
                <div class="proj-stack">

                    <!-- PROJECT INFO -->
                    <div class="proj-card">
                        <span class="proj-chip"><?= proj_e($selected['code']) ?></span>
                        <h1 style="margin-top: 8px;"><?= proj_e($selected['name']) ?></h1>
                        <p style="font-size: 13px;">Assigned by: <?= proj_e($selected['assigned_by']) ?></p>

                        <hr class="proj-divider">
                        <h3 class="proj-section-title">PROJECT INSTRUCTIONS</h3>
                        <p style="font-size: 14px; margin: 0;"><?= nl2br(proj_e($selected['instructions'])) ?></p>

                        <hr class="proj-divider">
                        <p style="font-size: 13px; margin: 0;">Deadline: <?= proj_e(proj_date($selected['deadline'])) ?> &middot; <?= proj_badge($submitted) ?></p>
                    </div>

                    <!-- TEAM + TASKS -->
                    <div class="proj-card">
                        <h3 class="proj-section-title">TEAM MEMBERS (<?= count($logic->team) ?>)</h3>

                        <?php foreach ($logic->team as $m):
                            $initial = strtoupper(substr($m['first_name'], 0, 1)); ?>
                            <div class="proj-member">
                                <span class="proj-avatar"><?= proj_e($initial) ?></span>
                                <div>
                                    <strong><?= proj_e($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                    <small>Student ID: <?= (int)$m['studentID'] ?></small>
                                </div>
                                <span class="proj-role"><?= $m['role'] === 'lead' ? 'TEAM LEAD' : 'MEMBER' ?></span>
                            </div>
                        <?php endforeach; ?>

                        <hr class="proj-divider">
                        <h3 class="proj-section-title">TASK BREAKDOWN &amp; PROGRESS</h3>

                        <?php $columns = ['todo' => 'TO DO', 'progress' => 'IN PROGRESS', 'done' => 'COMPLETED']; ?>
                        <div class="proj-board">
                            <?php foreach ($columns as $key => $label): ?>
                                <div class="proj-col">
                                    <h4><?= $label ?>:</h4>

                                    <?php foreach ($logic->tasks[$key] as $t): ?>
                                        <div class="proj-task">
                                            <p><?= proj_e($t['title']) ?></p>
                                            <div class="proj-task-foot">
                                                <span>Due <?= proj_e(proj_date($t['deadline'])) ?></span>
                                                <!-- Removed select element for read-only view -->
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- SUBMISSION PANEL (Read-Only for Instructor) -->
                <aside class="proj-card">
                    <h3 class="proj-section-title" style="font-size: 17px;">Student Submission</h3>
                    <hr class="proj-divider" style="margin-top: 0;">

                    <?php if ($logic->submission): ?>
                        <div class="form-group">
                            <label>PROJECT FILE / REPOSITORY URL</label>
                            <div class="proj-field" style="background: rgba(14, 5, 24, 0.6);">
                                <?= proj_e($logic->submission['file_url']) ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>SUBMISSION NOTES / COMMENTS</label>
                            <div class="proj-field" style="background: rgba(14, 5, 24, 0.6); min-height: 80px;">
                                <?= nl2br(proj_e($logic->submission['notes'])) ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>FILE / ATTACHMENT</label>
                            <?php if (!empty($logic->submission['attachment'])): ?>
                                <!-- Uses BASE_PATH to download from server -->
                                <a href="<?= BASE_PATH ?>/<?= proj_e($logic->submission['attachment']) ?>" target="_blank" class="btn-proj" style="width: 100%; background: #3a1d44; border-color: #d8b8f2;">
                                    📁 Download / Open File
                                </a>
                            <?php else: ?>
                                <div class="proj-field" style="background: rgba(14, 5, 24, 0.6);">—</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 13px; color: rgba(255,255,255,0.7); margin: 0;">The team has not submitted their project yet.</p>
                    <?php endif; ?>
                </aside>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($group !== 'student' && $group !== 'faculty'): ?>
            <div class="proj-card">
                <p style="margin: 0;">This page is not available for your account type.</p>
            </div>
        <?php endif; ?>

        <?php if (($group === 'student' && !$selected) || ($group === 'faculty' && !$selected)): ?>
        <script>
        (function () {
            var backdrop = document.getElementById('proj-backdrop');
            var searchBox = document.getElementById('proj-search');
            if (!backdrop) return;

            function setText(id, value, fallback) {
                document.getElementById(id).textContent = value || fallback || '';
            }

            function openModal(btn) {
                var d = btn.dataset;
                var done = d.done === '1';

                setText('pm-code', d.code);
                setText('pm-name', d.name);
                setText('pm-by', d.by);
                setText('pm-deadline', d.deadline);
                setText('pm-file', d.file, 'Nothing submitted yet.');
                
                // --- UPDATED ATTACHMENT LOGIC ---
                var attContainer = document.getElementById('pm-attachment');
                if (d.attachment && d.attachment.trim() !== '') {
                    var attUrl = d.attachment.startsWith('http') ? d.attachment : '<?= BASE_PATH ?>/' + d.attachment;
                    attContainer.innerHTML = '<a href="' + attUrl + '" target="_blank" class="btn-proj" style="width: 100%; background: #3a1d44; border-color: #d8b8f2;">📁 Open Attachment / Link</a>';
                } else {
                    attContainer.innerHTML = '—';
                }
                // --------------------------------

                setText('pm-notes', d.notes, '—');

                var status = document.getElementById('pm-status');
                status.className = 'proj-badge ' + (done ? 'done' : 'pending');
                status.textContent = done ? 'Completed' : 'Pending';

                var openLink = document.getElementById('pm-open');
                if (openLink) openLink.href = '?project=' + encodeURIComponent(d.id);

                backdrop.classList.add('open');
                document.getElementById('pm-close').focus();
            }

            function closeModal() {
                backdrop.classList.remove('open');
            }

            document.querySelectorAll('.js-view-project').forEach(function (btn) {
                btn.addEventListener('click', function () { openModal(btn); });
            });

            document.getElementById('pm-close').addEventListener('click', closeModal);

            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) closeModal();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });

            if (searchBox) {
                searchBox.addEventListener('input', function () {
                    var q = searchBox.value.trim().toLowerCase();
                    document.querySelectorAll('#proj-table tbody tr').forEach(function (row) {
                        row.style.display = row.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
                    });
                });
            }
        })();
        </script>
        <?php endif; ?>

        <!-- SCRIPT FOR FILE CLEAR BUTTON -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('student-file-input');
            const clearBtn = document.getElementById('clear-file-btn');
            
            if (fileInput && clearBtn) {
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        clearBtn.style.display = 'flex'; 
                    } else {
                        clearBtn.style.display = 'none';
                    }
                });
                
                clearBtn.addEventListener('click', function() {
                    fileInput.value = ''; 
                    clearBtn.style.display = 'none'; 
                });
            }
        });
        </script>

        <!-- NEW SCRIPT FOR ADD TASK MODAL -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const taskBackdrop = document.getElementById('add-task-backdrop');
            const openTaskBtn = document.getElementById('open-add-task');
            
            if (taskBackdrop && openTaskBtn) {
                const closeBtn = document.getElementById('add-task-close');
                const cancelBtn = document.getElementById('add-task-cancel');

                function toggleModal(show) {
                    if (show) {
                        taskBackdrop.classList.add('open');
                        document.getElementById('t-name').focus();
                    } else {
                        taskBackdrop.classList.remove('open');
                    }
                }

                openTaskBtn.addEventListener('click', () => toggleModal(true));
                closeBtn.addEventListener('click', () => toggleModal(false));
                cancelBtn.addEventListener('click', () => toggleModal(false));
                
                taskBackdrop.addEventListener('click', (e) => {
                    if (e.target === taskBackdrop) toggleModal(false);
                });
                
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && taskBackdrop.classList.contains('open')) {
                        toggleModal(false);
                    }
                });

                // Prevent past dates for new tasks
                var d = new Date();
                d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
                document.getElementById('t-deadline').min = d.toISOString().slice(0, 10);
            }
        });
        </script>

      </main>
    </div>
  </div>

</body>
</html>