<?php
ob_start();
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
        return '<span class="pj-badge ' . $class . '">' . $label . '</span>';
    }
}

// A project counts as completed when it has been submitted OR every task is Done
if (!function_exists('proj_is_done')) {
    function proj_is_done($p) {
        return (int)($p['submitted'] ?? 0) === 1
            || ((int)($p['task_total'] ?? 0) > 0 && (int)($p['task_open'] ?? 0) === 0);
    }
}
if (!function_exists('proj_tasks_done')) {
    function proj_tasks_done($tasks) {
        $total = count($tasks['todo']) + count($tasks['progress']) + count($tasks['review']) + count($tasks['done']);
        return $total > 0 && !$tasks['todo'] && !$tasks['progress'] && !$tasks['review'];
    }
}

$formAction = proj_e(ProjectsLogic::url());
$group      = $logic->group;
$error      = $logic->error;
$flash      = $logic->takeFlash();
$csrf       = $logic->csrf();
$stats      = $logic->stats;
$selected   = $logic->selected;

// Kanban columns: key => [label, css class]
$columns = [
    'todo'     => ['To Do',       'col-todo'],
    'progress' => ['In Progress', 'col-progress'],
    'review'   => ['Review',      'col-review'],
    'done'     => ['Done',        'col-done'],
];
$chevron = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

// Instructor: reopen the Assign modal when creating a project failed
$reopenModal = $group === 'faculty' && $error !== '' && ($_POST['project_action'] ?? '') === 'create_project';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Projects</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">

  <style>
.projects-page { padding: 30px !important; color: #fff; }

/* ---------- panel ---------- */
.pj { box-sizing: border-box; min-width: 0; }
.pj > * { flex-shrink: 0; } /* <main> is a fixed-height flex column: keep the banner and board from squashing */
.pj h1 { margin: 0; font-size: clamp(26px, 3vw, 34px); font-weight: 800; }
.pj-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
.pj-search { position: relative; width: min(420px, 100%); }
.pj-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #fff; pointer-events: none; }
.pj-search input { width: 100%; box-sizing: border-box; height: 44px; padding: 0 18px 0 46px; border: 1.5px solid rgba(255, 255, 255, 0.85); border-radius: 22px; background: rgba(60, 35, 75, 0.35); color: #fff; font: inherit; font-size: 14px; outline: none; }
.pj-search input::placeholder { color: rgba(255, 255, 255, 0.65); }
.pj-search input:focus { border-color: #fff; box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2); }

.pj-flash { width: fit-content; max-width: 100%; margin: 0 0 16px auto; padding: 10px 16px; border-radius: 12px; font-size: 13px; font-weight: 600; }
.pj-flash.ok  { border: 1px solid rgba(120, 255, 180, 0.4); background: rgba(25, 170, 90, 0.25); color: #c9ffe0; }
.pj-flash.err { border: 1px solid rgba(255, 130, 130, 0.5); background: rgba(190, 40, 40, 0.3); color: #ffd0d0; }
.pj-flash p { margin: 0; }

/* ---------- list page ---------- */
.pj-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; margin-bottom: 26px; }
.pj-stat { display: flex; flex-direction: column; justify-content: space-between; min-height: 112px; padding: 14px 20px; box-sizing: border-box; border: 1.5px solid rgba(255, 255, 255, 0.8); border-radius: 20px; background: rgba(255, 255, 255, 0.1); }
.pj-stat span { font-size: 15px; font-weight: 700; }
.pj-stat div { display: flex; align-items: flex-end; justify-content: space-between; }
.pj-stat strong { font-size: 40px; line-height: 1; }
.pj-stat svg { width: 38px; height: 38px; }

.pj-card { padding: 22px; box-sizing: border-box; border: 1.5px solid rgba(255, 255, 255, 0.7); border-radius: 22px; background: linear-gradient(180deg, rgba(95, 65, 108, 0.8), rgba(34, 11, 44, 0.92)); }
.pj-card h2 { margin: 0 0 16px; font-size: 20px; font-weight: 700; }
.pj-table-wrap { overflow-x: auto; max-height: 56vh; overflow-y: auto; }
.pj-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 14px; }
.pj-table th { position: sticky; top: 0; padding: 14px 14px; text-align: left; background: rgba(50, 25, 62, 0.95); font-size: 14px; font-weight: 700; white-space: nowrap; }
.pj-table td { padding: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.55); }
.pj-table tbody tr:hover { background: rgba(190, 130, 255, 0.12); }
.pj-empty { text-align: center; color: rgba(255, 255, 255, 0.7); }
.pj-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 82px; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.pj-badge.pending { background: #8a5a1a; color: #ffe2ad; }
.pj-badge.done    { background: #1f7a52; color: #d3ffe9; }
.pj-eye { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 26px; border-radius: 14px; background: #1a0f22; color: #fff; text-decoration: none; transition: background 0.2s; }
.pj-eye:hover, .pj-eye:focus-visible { background: #3a1d52; outline: none; }

/* ---------- detail page ---------- */
.pj-banner { display: flex; align-items: stretch; gap: 0; margin: 22px 0 26px; border-radius: 12px; background: rgba(255, 255, 255, 0.14); overflow: hidden; }
.pj-banner-icon { flex: none; display: grid; place-items: center; width: 104px; background: #c21bff; color: #fff; }
.pj-banner-icon svg { width: 62px; height: 62px; }
.pj-banner-text { flex: 1; min-width: 0; padding: 14px 22px; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
.pj-banner-text h1 { font-size: clamp(24px, 3vw, 34px); font-weight: 700; }
.pj-team { display: flex; align-items: center; gap: 10px; font-size: 15px; }
.pj-banner-tools { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 14px 20px; justify-content: flex-end; }
.pj-meta { padding: 0 22px 14px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-size: 13px; }

.pj-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-sizing: border-box; min-height: 34px; padding: 6px 16px; border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 10px; background: rgba(26, 12, 38, 0.75); color: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; white-space: nowrap; cursor: pointer; transition: background 0.2s; }
.pj-btn:hover, .pj-btn:focus-visible { background: rgba(70, 30, 100, 0.95); outline: none; }
.pj-btn.primary { background: #277b58; border-color: #3da879; }
.pj-btn.primary:hover { background: #32976b; }
.pj-btn.block { display: flex; width: 100%; }
.pj-back { align-self: flex-start; margin-bottom: 18px; }

.pj-board { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 26px; align-items: start; }
.pj-col { min-height: 460px; border-radius: 20px; background: linear-gradient(180deg, rgba(90, 60, 100, 0.55), rgba(30, 10, 40, 0.85)); overflow: hidden; }
.pj-col-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; font-size: 18px; font-weight: 700; }
.pj-col-head i { width: 22px; height: 22px; border-radius: 50%; background: rgba(255, 255, 255, 0.7); }
.pj-col-body { padding: 10px 8px 12px; }
.col-todo     { --accent: #a35cc0; } .col-todo .pj-col-head { background: #a35cc0; }
.col-progress { --accent: #4a2450; } .col-progress .pj-col-head { background: #4a2450; }
.col-review   { --accent: #6a0dad; } .col-review .pj-col-head { background: #6a0dad; }
.col-done     { --accent: #1f1b3f; } .col-done .pj-col-head { background: #1f1b3f; }
.pj-col-empty { margin: 8px; font-size: 13px; color: rgba(255, 255, 255, 0.55); }

.pj-task { margin-bottom: 10px; border-radius: 12px; background: rgba(45, 22, 55, 0.85); overflow: hidden; }
.pj-task-main { display: flex; gap: 12px; align-items: stretch; }
.pj-task-bar { flex: none; width: 32px; border-radius: 12px; background: var(--accent); }
.pj-task-body { flex: 1; min-width: 0; padding: 10px 12px 8px 0; }
.pj-task-title { margin: 0 0 4px; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
.pj-task-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12px; color: rgba(255, 255, 255, 0.8); }
.pj-start { padding: 3px 18px; border: 0; border-radius: 4px; background: #1d1923; color: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.pj-start:hover, .pj-start:focus-visible { background: #3a1d52; outline: none; }
.pj-chev { display: grid; place-items: center; padding: 2px; border: 0; background: none; color: #fff; cursor: pointer; transition: transform 0.2s; }
.pj-task.open .pj-chev { transform: rotate(180deg); }
.pj-task-more { display: none; padding: 10px 12px; border-top: 1px solid rgba(255, 255, 255, 0.2); background: #25212a; font-size: 12px; }
.pj-task.open .pj-task-more { display: block; }
.pj-task-more label { display: block; margin-bottom: 6px; font-weight: 700; }
.pj-task-more input[type=file] { width: 100%; box-sizing: border-box; margin-bottom: 8px; padding: 6px; border-radius: 6px; background: #151218; color: #fff; font-size: 11px; }
.pj-file { display: block; margin-bottom: 8px; padding: 8px 10px; border-radius: 6px; background: #151218; color: #fff; text-decoration: none; overflow-wrap: anywhere; }
a.pj-file:hover { background: #2a1d38; }
.pj-more-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pj-pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.pj-pill.ok   { background: #2f7d1f; }
.pj-pill.wait { background: #8a5a1a; }
.pj-pass, .pj-approve { padding: 3px 14px; border: 0; border-radius: 4px; background: #cfb6e3; color: #25122f; font: inherit; font-size: 11px; font-weight: 800; cursor: pointer; }
.pj-approve { display: inline-flex; align-items: center; gap: 4px; border-radius: 12px; }
.pj-pass:hover, .pj-approve:hover { background: #fff; }

/* ---------- modals ---------- */
.pj-backdrop { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 4, 25, 0.65); }
.pj-backdrop.open { display: flex; }
.pj-modal { position: relative; box-sizing: border-box; width: min(520px, 100%); max-height: 90vh; overflow-y: auto; padding: 26px; border: 2px solid #fff; border-radius: 24px; background: #4d2a52; }
.pj-modal h2 { margin: 0 0 16px; font-size: 24px; }
.pj-close { position: absolute; top: 16px; right: 16px; width: 32px; height: 32px; border: 0; border-radius: 50%; background: #1b0c24; color: #fff; font-size: 18px; cursor: pointer; }
.pj-field { margin-bottom: 14px; }
.pj-field label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; }
.pj-field input, .pj-field textarea { display: block; width: 100%; box-sizing: border-box; min-height: 42px; padding: 10px 12px; border: 1px solid transparent; border-radius: 9px; background: #3a1d44; color: #fff; font: inherit; font-size: 13px; outline: none; }
.pj-field input:focus, .pj-field textarea:focus { border-color: #bd85ed; }
.pj-field textarea { min-height: 90px; resize: vertical; }
.pj-actions { display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 18px; }
.pj-link { padding: 8px; border: 0; background: none; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }

/* ---------- instructor view ---------- */
.pj-top-tools { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1; justify-content: flex-end; }
.pj-top-tools .pj-search { flex: 1; max-width: 420px; }
.pj-assign { height: 44px; padding: 0 22px; border-radius: 22px; background: #1a1535; border-color: rgba(255,255,255,.35); }
.pj-actions-cell { display: flex; gap: 8px; align-items: center; }
.pj-del { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 26px; border: 0; border-radius: 14px; background: #5a1a2a; color: #fff; cursor: pointer; }
.pj-del:hover, .pj-del:focus-visible { background: #8a2540; outline: none; }
.pj-field select, .pj-field input[type=date], .pj-field input[type=file] { display: block; width: 100%; box-sizing: border-box; min-height: 42px; padding: 10px 12px; border: 1px solid transparent; border-radius: 9px; background: #3a1d44; color: #fff; font: inherit; font-size: 13px; outline: none; }
.pj-field select:focus, .pj-field input[type=date]:focus { border-color: #bd85ed; }
.pj-field input[readonly] { opacity: .75; cursor: not-allowed; }
.pj-field input[type=date] { color-scheme: dark; }
.pj-dd { position: relative; }
.pj-dd-btn { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; box-sizing: border-box; min-height: 42px; padding: 10px 12px; border: 1px solid transparent; border-radius: 9px; background: #3a1d44; color: #fff; font: inherit; font-size: 13px; text-align: left; cursor: pointer; }
.pj-dd-btn:focus-visible, .pj-dd.open .pj-dd-btn { border-color: #bd85ed; outline: none; }
.pj-dd-btn span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pj-dd-btn svg { flex: none; transition: transform .2s; }
.pj-dd.open .pj-dd-btn svg { transform: rotate(180deg); }
.pj-members { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 5; max-height: 160px; overflow-y: auto; padding: 6px 12px; border: 1px solid #bd85ed; border-radius: 9px; background: #3a1d44; box-shadow: 0 8px 20px rgba(0, 0, 0, .4); }
.pj-dd.open .pj-members { display: block; }
.pj-members label { display: flex; align-items: center; gap: 8px; margin: 0; padding: 5px 0; font-size: 13px; font-weight: 500; cursor: pointer; }
.pj-members input { width: auto; min-height: 0; margin: 0; }
.pj-hint { margin: 4px 0 0; font-size: 11px; color: rgba(255,255,255,.65); }
.pj-btn.solid { background: #1a1535; }
.pj-col-head .pj-add { margin-left: auto; display: grid; place-items: center; width: 26px; height: 26px; border: 0; border-radius: 50%; background: rgba(255,255,255,.7); color: #a35cc0; font-size: 20px; line-height: 1; font-weight: 700; cursor: pointer; }
.pj-col-head .pj-add:hover { background: #fff; }
.pj-addtask { display: none; margin: 0 0 10px; padding: 12px; border-radius: 10px; background: #25212a; font-size: 12px; }
.pj-addtask.open { display: block; }
.pj-addtask label { display: block; margin: 0 0 4px; font-weight: 700; }
.pj-addtask input[type=text], .pj-addtask input[type=date] { display: block; width: 100%; box-sizing: border-box; height: 30px; margin-bottom: 10px; padding: 0 10px; border: 0; border-radius: 15px; background: #151218; color: #fff; font: inherit; font-size: 12px; outline: none; color-scheme: dark; }
.pj-addtask-foot { display: flex; justify-content: flex-end; align-items: center; gap: 10px; }
.pj-addtask-foot .pj-link { padding: 4px; font-size: 11px; }
.pj-mini { padding: 5px 14px; border: 0; border-radius: 14px; background: #1a1535; color: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.pj-mini:hover { background: #3a1d52; }
.pj-decline, .pj-approve2 { padding: 3px 16px; border: 0; border-radius: 12px; color: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.pj-decline  { background: #8a5a1a; } .pj-decline:hover  { background: #a8701f; }
.pj-approve2 { background: #2f7d1f; } .pj-approve2:hover { background: #3a9926; }
.pj-review-actions { display: flex; justify-content: space-between; gap: 8px; }
.pj-review-actions form { margin: 0; }
.pj-submission { margin-top: 26px; }
.pj-submission dl { display: grid; grid-template-columns: 140px 1fr; gap: 10px 16px; margin: 0; font-size: 14px; }
.pj-submission dt { font-weight: 700; }
.pj-submission dd { margin: 0; overflow-wrap: anywhere; }
.pj-submission a { color: #e2c4ff; }
.pj-teamlist { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 2px; }
.pj-chip { padding: 2px 10px; border-radius: 12px; background: rgba(0,0,0,.3); font-size: 12px; }
.pj-chip.lead { background: #6a0dad; }

@media (max-width: 1200px) { .pj-board { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) {
    .pj { padding: 16px; border-radius: 24px; }
    .pj-stats { grid-template-columns: 1fr; gap: 12px; }
    .pj-board { grid-template-columns: 1fr; }
    .pj-banner { flex-wrap: wrap; }
    .pj-banner-icon { width: 76px; }
    .pj-submission dl { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
  </style>
</head>
<body data-page="PROJECTS">

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>

  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar projects-page pj">

        <?php if ($flash): ?>
            <div class="pj-flash <?= $flash['type'] === 'error' ? 'err' : 'ok' ?>" role="status">
                <?php foreach ($flash['msgs'] as $m): ?><p><?= proj_e($m) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="pj-flash err" role="alert"><p><?= proj_e($error) ?></p></div>
        <?php endif; ?>

        <?php /* ===================== STUDENT VIEW ===================== */ ?>
        <?php if ($group === 'student'): ?>

            <?php if (!$selected): ?>
            <?php /* ---------- LIST PAGE ---------- */ ?>

            <div class="pj-top">
                <h1>Projects</h1>
                <div class="pj-search">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="pj-search" placeholder="Search projects..." aria-label="Search projects">
                </div>
            </div>

            <div class="pj-stats">
                <div class="pj-stat"><span>Assigned Projects</span><div><strong><?= (int)$stats['total'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="m12 2-10 5 10 5 10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg></div></div>
                <div class="pj-stat"><span>Pending Submission</span><div><strong><?= (int)$stats['pending'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></div></div>
                <div class="pj-stat"><span>Submitted</span><div><strong><?= (int)$stats['submitted'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg></div></div>
            </div>

            <div class="pj-card">
                <h2>Coursework &amp; Assigned Projects</h2>
                <div class="pj-table-wrap">
                    <table class="pj-table" id="pj-table">
                        <thead>
                            <tr><th>Project ID</th><th>Project Name</th><th>Assigned By</th><th>Deadline</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logic->projects)): ?>
                                <tr><td colspan="6" class="pj-empty">No projects have been assigned to you yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($logic->projects as $p): $done = proj_is_done($p); ?>
                                <tr>
                                    <td><?= proj_e($p['code']) ?></td>
                                    <td><?= proj_e($p['name']) ?></td>
                                    <td><?= proj_e($p['assigned_by']) ?></td>
                                    <td><?= proj_e(proj_date($p['deadline'])) ?></td>
                                    <td><?= proj_badge($done) ?></td>
                                    <td>
                                        <a class="pj-eye" href="<?= proj_e(ProjectsLogic::url(['project' => (int)$p['id']])) ?>" aria-label="Open <?= proj_e($p['name']) ?>">
                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
            (function () {
                var box = document.getElementById('pj-search');
                if (!box) return;
                box.addEventListener('input', function () {
                    var q = box.value.trim().toLowerCase();
                    document.querySelectorAll('#pj-table tbody tr').forEach(function (row) {
                        row.style.display = row.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
                    });
                });
            })();
            </script>

            <?php else: ?>
            <?php /* ---------- DETAIL / KANBAN PAGE ---------- */
                $submitted = $logic->submission !== null || proj_tasks_done($logic->tasks);
                $isLead    = $logic->isLead;
            ?>

            <a href="<?= proj_e(ProjectsLogic::url()) ?>" class="pj-btn pj-back">← Back to projects</a>

            <div class="pj-top" style="margin-bottom: 0;">
                <div class="pj-search">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="pj-task-search" placeholder="Search tasks..." aria-label="Search tasks">
                </div>
            </div>

            <div class="pj-banner">
                <div class="pj-banner-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="3" width="15" height="12" rx="2"/><path d="m9 11 3-3 2 2 3-3"/><path d="M3 7v11a3 3 0 0 0 3 3h12"/></svg>
                </div>
                <div class="pj-banner-text">
                    <h1><?= proj_e($selected['name']) ?></h1>
                    <div class="pj-team">
                        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/><circle cx="17" cy="9" r="2.5"/><path d="M18 14.2a5 5 0 0 1 4 4.8v1"/></svg>
                        Team: <?= count($logic->team) ?> member<?= count($logic->team) === 1 ? '' : 's' ?>
                    </div>
                    <div style="font-size: 13px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <span><?= proj_e($selected['code']) ?> · Assigned by <?= proj_e($selected['assigned_by']) ?></span>
                        <span>Due <?= proj_e(proj_date($selected['deadline'])) ?></span>
                        <?= proj_badge($submitted) ?>
                    </div>
                </div>
                <div class="pj-banner-tools">
                    <?php if (!empty($selected['instruction_file'])): ?>
                        <a class="pj-btn" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($selected['instruction_file']) ?>">Instructions</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pj-board">
                <?php foreach ($columns as $key => $col): ?>
                    <section class="pj-col <?= $col[1] ?>" aria-label="<?= proj_e($col[0]) ?>">
                        <div class="pj-col-head"><i></i><?= proj_e($col[0]) ?></div>
                        <div class="pj-col-body">

                            <?php if (empty($logic->tasks[$key])): ?>
                                <p class="pj-col-empty">No tasks</p>
                            <?php endif; ?>

                            <?php foreach ($logic->tasks[$key] as $t): $att = trim((string)($t['attachment'] ?? '')); ?>
                                <article class="pj-task" data-title="<?= proj_e(strtolower($t['title'])) ?>">
                                    <div class="pj-task-main">
                                        <span class="pj-task-bar"></span>
                                        <div class="pj-task-body">
                                            <p class="pj-task-title"><?= proj_e($t['title']) ?></p>
                                            <div class="pj-task-row">
                                                <span><?= proj_e(proj_date($t['deadline'])) ?></span>

                                                <?php if ($key === 'todo'): ?>
                                                    <form method="POST" action="<?= $formAction ?>">
                                                        <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                        <input type="hidden" name="project_action" value="start_task">
                                                        <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                        <button type="submit" class="pj-start">Start</button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="pj-chev js-expand" aria-expanded="false" aria-label="Show details for <?= proj_e($t['title']) ?>"><?= $chevron ?></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($key === 'progress'): ?>
                                        <form class="pj-task-more" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                            <input type="hidden" name="project_action" value="pass_task">
                                            <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                            <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                            <label for="att-<?= (int)$t['id'] ?>">Screenshot/File attachment:</label>
                                            <input type="file" id="att-<?= (int)$t['id'] ?>" name="attachment" required>
                                            <div class="pj-more-foot"><span></span><button type="submit" class="pj-pass">PASS</button></div>
                                        </form>

                                    <?php elseif ($key === 'review'): ?>
                                        <div class="pj-task-more">
                                            <?php if ($att !== ''): ?>
                                                <a class="pj-file" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($att) ?>">File attachment</a>
                                            <?php else: ?>
                                                <span class="pj-file">No attachment</span>
                                            <?php endif; ?>
                                            <div class="pj-more-foot"><span class="pj-pill wait">Waiting for approval</span></div>
                                        </div>

                                    <?php elseif ($key === 'done'): ?>
                                        <div class="pj-task-more">
                                            <?php if ($att !== ''): ?>
                                                <a class="pj-file" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($att) ?>">File attachment</a>
                                            <?php else: ?>
                                                <span class="pj-file">No attachment</span>
                                            <?php endif; ?>
                                            <div class="pj-more-foot"><span class="pj-pill ok">✔ Approved</span></div>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>

                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <script>
            (function () {
                // expand / collapse task details
                document.querySelectorAll('.js-expand').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var task = btn.closest('.pj-task');
                        var open = task.classList.toggle('open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                });

                // task search
                var box = document.getElementById('pj-task-search');
                if (box) box.addEventListener('input', function () {
                    var q = box.value.trim().toLowerCase();
                    document.querySelectorAll('.pj-task').forEach(function (t) {
                        t.style.display = t.dataset.title.indexOf(q) === -1 ? 'none' : '';
                    });
                });
            })();
            </script>

            <?php endif; ?>
        <?php endif; ?>

        <?php /* ===================== INSTRUCTOR VIEW ===================== */ ?>
        <?php if ($group === 'faculty'): ?>

            <?php if (!$selected): ?>
            <?php /* ---------- INSTRUCTOR LIST ---------- */ ?>

            <div class="pj-top">
                <h1>Projects</h1>
                <div class="pj-top-tools">
                    <div class="pj-search">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" id="pj-search" placeholder="Search projects..." aria-label="Search projects">
                    </div>
                    <button type="button" class="pj-btn pj-assign" id="pj-open-assign">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
                        Assign Project
                    </button>
                </div>
            </div>

            <div class="pj-stats">
                <div class="pj-stat"><span>Assigned Projects</span><div><strong><?= (int)$stats['total'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="m12 2-10 5 10 5 10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg></div></div>
                <div class="pj-stat"><span>Pending Submission</span><div><strong><?= (int)$stats['pending'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></div></div>
                <div class="pj-stat"><span>Submitted</span><div><strong><?= (int)$stats['submitted'] ?></strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg></div></div>
            </div>

            <div class="pj-card">
                <h2>Coursework &amp; Assigned Projects</h2>
                <div class="pj-table-wrap">
                    <table class="pj-table" id="pj-table">
                        <thead>
                            <tr><th>Project ID</th><th>Project Name</th><th>Assigned By</th><th>Deadline</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logic->projects)): ?>
                                <tr><td colspan="6" class="pj-empty">You haven't assigned any projects yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($logic->projects as $p): $done = proj_is_done($p); ?>
                                <tr>
                                    <td><?= proj_e($p['code']) ?></td>
                                    <td><?= proj_e($p['name']) ?></td>
                                    <td><?= proj_e($p['assigned_by']) ?></td>
                                    <td><?= proj_e(proj_date($p['deadline'])) ?></td>
                                    <td><?= proj_badge($done) ?></td>
                                    <td>
                                        <div class="pj-actions-cell">
                                            <a class="pj-eye" href="<?= proj_e(ProjectsLogic::url(['project' => (int)$p['id']])) ?>" aria-label="Open <?= proj_e($p['name']) ?>">
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <form method="POST" action="<?= $formAction ?>" onsubmit="return confirm('Delete this project and all of its tasks? This cannot be undone.');" style="margin:0;">
                                                <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                <input type="hidden" name="project_action" value="delete_project">
                                                <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="pj-del" aria-label="Delete <?= proj_e($p['name']) ?>">
                                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php /* ---------- Assign New Project modal ---------- */ ?>
            <div class="pj-backdrop<?= $reopenModal ? ' open' : '' ?>" id="pj-assign-modal" role="dialog" aria-modal="true" aria-labelledby="pj-assign-title">
                <form class="pj-modal" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
                    <button type="button" class="pj-close" data-close aria-label="Close">&times;</button>
                    <h2 id="pj-assign-title">Assign New Project</h2>

                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                    <input type="hidden" name="project_action" value="create_project">

                    <div class="pj-field">
                        <label for="pj-name">Project Name</label>
                        <input type="text" id="pj-name" name="name" required maxlength="150" value="<?= $reopenModal ? proj_e($_POST['name'] ?? '') : '' ?>">
                    </div>

                    <div class="pj-field">
                        <label for="pj-code">Project ID</label>
                        <input type="text" id="pj-code" value="<?= proj_e($logic->nextCode) ?>" readonly>
                    </div>

                    <div class="pj-field">
                        <label for="pj-instructor">Instructor / Assigned By</label>
                        <input type="text" id="pj-instructor" value="<?= proj_e($logic->instructorName) ?>" readonly>
                    </div>

                    <div class="pj-field">
                        <label for="pj-leader">Assign Leader</label>
                        <select id="pj-leader" name="leader" required>
                            <option value="">Select a leader…</option>
                            <?php foreach ($logic->studentOptions as $s): ?>
                                <option value="<?= (int)$s['enrolled_subjectID'] ?>"><?= proj_e($s['last_name'] . ', ' . $s['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pj-field">
                        <label id="pj-dd-label">Assign Members</label>
                        <div class="pj-dd" id="pj-dd">
                        <button type="button" class="pj-dd-btn" id="pj-dd-btn" aria-haspopup="true" aria-expanded="false" aria-labelledby="pj-dd-label">
                            <span id="pj-dd-text">Select members…</span>
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="pj-members" id="pj-members">
                            <?php if (empty($logic->studentOptions)): ?>
                                <span class="pj-hint">No students available.</span>
                            <?php endif; ?>
                            <?php foreach ($logic->studentOptions as $s): ?>
                                <label data-id="<?= (int)$s['enrolled_subjectID'] ?>">
                                    <input type="checkbox" name="members[]" value="<?= (int)$s['enrolled_subjectID'] ?>">
                                    <?= proj_e($s['last_name'] . ', ' . $s['first_name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        </div>
                        <p class="pj-hint">The leader is added to the team automatically.</p>
                    </div>

                    <div class="pj-field">
                        <label for="pj-deadline">Deadline Date</label>
                        <input type="date" id="pj-deadline" name="deadline" required min="<?= date('Y-m-d') ?>" value="<?= $reopenModal ? proj_e($_POST['deadline'] ?? '') : '' ?>">
                    </div>

                    <div class="pj-field">
                        <label for="pj-instr">Instructions (optional file)</label>
                        <input type="file" id="pj-instr" name="instruction_file">
                    </div>

                    <div class="pj-actions">
                        <button type="button" class="pj-link" data-close>Cancel</button>
                        <button type="submit" class="pj-btn solid">Create &amp; Assign</button>
                    </div>
                </form>
            </div>

            <script>
            (function () {
                // search
                var box = document.getElementById('pj-search');
                if (box) box.addEventListener('input', function () {
                    var q = box.value.trim().toLowerCase();
                    document.querySelectorAll('#pj-table tbody tr').forEach(function (row) {
                        row.style.display = row.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
                    });
                });

                // modal
                var modal = document.getElementById('pj-assign-modal');
                var open  = document.getElementById('pj-open-assign');
                function close() { modal.classList.remove('open'); }
                open.addEventListener('click', function () { modal.classList.add('open'); });
                modal.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
                modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

                // members dropdown
                var dd      = document.getElementById('pj-dd');
                var ddBtn   = document.getElementById('pj-dd-btn');
                var ddText  = document.getElementById('pj-dd-text');
                var members = document.getElementById('pj-members');
                function updateMembersLabel() {
                    var names = [];
                    members.querySelectorAll('input:checked').forEach(function (i) { names.push(i.parentNode.textContent.trim()); });
                    ddText.textContent = names.length ? names.join(' • ') : 'Select members…';
                }
                function setDd(open) {
                    dd.classList.toggle('open', open);
                    ddBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                ddBtn.addEventListener('click', function () { setDd(!dd.classList.contains('open')); });
                members.addEventListener('change', updateMembersLabel);
                document.addEventListener('mousedown', function (e) { if (!dd.contains(e.target)) setDd(false); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setDd(false); });
                modal.addEventListener('mousedown', function (e) { if (e.target === modal) setDd(false); });

                // the chosen leader can't also be picked as a member
                var leader = document.getElementById('pj-leader');
                function syncLeader() {
                    members.querySelectorAll('label').forEach(function (l) {
                        var same = l.dataset.id === leader.value && leader.value !== '';
                        l.style.display = same ? 'none' : '';
                        if (same) l.querySelector('input').checked = false;
                    });
                    updateMembersLabel();
                }
                leader.addEventListener('change', syncLeader);
            })();
            </script>

            <?php else: ?>
            <?php /* ---------- INSTRUCTOR DETAIL / KANBAN ---------- */
                $sub = $logic->submission;
            ?>

            <a href="<?= proj_e(ProjectsLogic::url()) ?>" class="pj-btn pj-back">← Back to projects</a>

            <div class="pj-banner">
                <div class="pj-banner-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="3" width="15" height="12" rx="2"/><path d="m9 11 3-3 2 2 3-3"/><path d="M3 7v11a3 3 0 0 0 3 3h12"/></svg>
                </div>
                <div class="pj-banner-text">
                    <h1><?= proj_e($selected['name']) ?></h1>
                    <div class="pj-team">
                        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/><circle cx="17" cy="9" r="2.5"/><path d="M18 14.2a5 5 0 0 1 4 4.8v1"/></svg>
                        Team: <?= count($logic->team) ?> member<?= count($logic->team) === 1 ? '' : 's' ?>
                    </div>
                    <div style="font-size: 13px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <span><?= proj_e($selected['code']) ?> · Assigned by <?= proj_e($selected['assigned_by']) ?></span>
                        <span>Due <?= proj_e(proj_date($selected['deadline'])) ?></span>
                        <?= proj_badge($sub !== null || proj_tasks_done($logic->tasks)) ?>
                    </div>
                </div>
                <div class="pj-banner-tools">
                    <?php if (!empty($selected['instruction_file'])): ?>
                        <a class="pj-btn" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($selected['instruction_file']) ?>">Instructions</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pj-board">
                <?php foreach ($columns as $key => $col): ?>
                    <section class="pj-col <?= $col[1] ?>" aria-label="<?= proj_e($col[0]) ?>">
                        <div class="pj-col-head">
                            <i></i><?= proj_e($col[0]) ?>
                            <?php if ($key === 'todo'): ?>
                                <button type="button" class="pj-add" id="pj-add-toggle" aria-label="Add task" aria-expanded="false">+</button>
                            <?php endif; ?>
                        </div>
                        <div class="pj-col-body">

                            <?php if ($key === 'todo'): ?>
                                <form class="pj-addtask" id="pj-addtask" method="POST" action="<?= $formAction ?>">
                                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                    <input type="hidden" name="project_action" value="add_task">
                                    <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                    <label for="pj-task-title">Task Title</label>
                                    <input type="text" id="pj-task-title" name="task_title" required maxlength="150">
                                    <label for="pj-task-deadline">Submission</label>
                                    <input type="date" id="pj-task-deadline" name="task_deadline" required min="<?= date('Y-m-d') ?>">
                                    <div class="pj-addtask-foot">
                                        <button type="button" class="pj-link" id="pj-addtask-cancel">Cancel</button>
                                        <button type="submit" class="pj-mini">Create Task</button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if (empty($logic->tasks[$key])): ?>
                                <p class="pj-col-empty">No tasks</p>
                            <?php endif; ?>

                            <?php foreach ($logic->tasks[$key] as $t): $att = trim((string)($t['attachment'] ?? '')); ?>
                                <article class="pj-task" data-title="<?= proj_e(strtolower($t['title'])) ?>">
                                    <div class="pj-task-main">
                                        <span class="pj-task-bar"></span>
                                        <div class="pj-task-body">
                                            <p class="pj-task-title"><?= proj_e($t['title']) ?></p>
                                            <div class="pj-task-row">
                                                <span><?= proj_e(proj_date($t['deadline'])) ?></span>
                                                <?php if ($key === 'review' || $key === 'done'): ?>
                                                    <button type="button" class="pj-chev js-expand" aria-expanded="false" aria-label="Show details for <?= proj_e($t['title']) ?>"><?= $chevron ?></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($key === 'review'): ?>
                                        <div class="pj-task-more">
                                            <label>File/Attachment</label>
                                            <?php if ($att !== ''): ?>
                                                <a class="pj-file" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($att) ?>"><?= proj_e(preg_replace('/^[0-9a-f]{12}_/', '', basename($att))) ?></a>
                                            <?php else: ?>
                                                <span class="pj-file">No attachment</span>
                                            <?php endif; ?>
                                            <div class="pj-review-actions">
                                                <form method="POST" action="<?= $formAction ?>">
                                                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                    <input type="hidden" name="project_action" value="decline_task">
                                                    <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                    <button type="submit" class="pj-decline">Decline</button>
                                                </form>
                                                <form method="POST" action="<?= $formAction ?>">
                                                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                    <input type="hidden" name="project_action" value="approve_task">
                                                    <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                    <button type="submit" class="pj-approve2">Approve</button>
                                                </form>
                                            </div>
                                        </div>

                                    <?php elseif ($key === 'done'): ?>
                                        <div class="pj-task-more">
                                            <label>File/Attachment</label>
                                            <?php if ($att !== ''): ?>
                                                <a class="pj-file" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($att) ?>"><?= proj_e(preg_replace('/^[0-9a-f]{12}_/', '', basename($att))) ?></a>
                                            <?php else: ?>
                                                <span class="pj-file">No attachment</span>
                                            <?php endif; ?>
                                            <div class="pj-more-foot"><span class="pj-pill ok">✔ Approved</span></div>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>

                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="pj-card pj-submission">
                <h2>Final Submission</h2>
                <?php if ($sub): ?>
                    <dl>
                        <dt>Project file / repo</dt>
                        <dd><?php if (!empty($sub['file_url'])): ?><a href="<?= proj_e($sub['file_url']) ?>" target="_blank" rel="noopener noreferrer"><?= proj_e($sub['file_url']) ?></a><?php else: ?>—<?php endif; ?></dd>
                        <dt>Attachment</dt>
                        <dd><?php if (!empty($sub['attachment'])): ?><a href="<?= BASE_PATH ?>/<?= proj_e($sub['attachment']) ?>" target="_blank" rel="noopener">Download attachment</a><?php else: ?>—<?php endif; ?></dd>
                        <dt>Notes</dt>
                        <dd><?= !empty($sub['notes']) ? nl2br(proj_e($sub['notes'])) : '—' ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="pj-col-empty" style="margin:0;">The team hasn't submitted this project yet.</p>
                <?php endif; ?>
            </div>

            <script>
            (function () {
                // expand / collapse task details
                document.querySelectorAll('.js-expand').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var task = btn.closest('.pj-task');
                        var open = task.classList.toggle('open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                });

                // add-task popup
                var toggle = document.getElementById('pj-add-toggle');
                var form   = document.getElementById('pj-addtask');
                var cancel = document.getElementById('pj-addtask-cancel');
                if (toggle && form) {
                    toggle.addEventListener('click', function () {
                        var open = form.classList.toggle('open');
                        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                        if (open) document.getElementById('pj-task-title').focus();
                    });
                    cancel.addEventListener('click', function () {
                        form.classList.remove('open');
                        toggle.setAttribute('aria-expanded', 'false');
                    });
                }
            })();
            </script>

            <?php endif; ?>
        <?php endif; ?>

        <?php if ($group !== 'student' && $group !== 'faculty'): ?>
            <p style="margin: 0;">This page is not available for your account type.</p>
        <?php endif; ?>

      </main>
    </div>
  </div>

</body>
</html>