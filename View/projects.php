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
if (!function_exists('proj_date_long')) {
    function proj_date_long($d) { return $d ? date('F j, Y', strtotime($d)) : '—'; }
}
if (!function_exists('proj_person')) {
    function proj_person($r) { return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); }
}
if (!function_exists('proj_old')) {
    /** Value the user typed before a failed submit (only when that form is the one being re-shown). */
    function proj_old($key, $active) { return $active ? proj_e($_POST[$key] ?? '') : ''; }
}
if (!function_exists('proj_bar')) {
    function proj_bar($pct, $dark = false, $extra = '') {
        $pct  = max(0, min(100, (int)$pct));
        $cls  = 'pj-bar' . ($dark ? ' dark' : '') . ($extra !== '' ? ' ' . $extra : '');
        $zero = $pct === 0 ? ' class="zero"' : '';
        return '<div class="' . $cls . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '">'
             . '<i' . $zero . ' style="width:' . $pct . '%"></i><span>' . $pct . '%</span></div>';
    }
}
if (!function_exists('proj_file')) {
    function proj_file($path) {
        $path = trim((string)$path);
        if ($path === '') return '<span class="pj-file">No attachment</span>';
        $name = preg_replace('/^[0-9a-f]{12}_/', '', basename($path));
        return '<a class="pj-file" target="_blank" rel="noopener" href="' . BASE_PATH . '/' . proj_e($path) . '">' . proj_e($name) . '</a>';
    }
}

$formAction = proj_e(ProjectsLogic::url());
$group      = $logic->group;
$isFaculty  = $group === 'faculty';
$isStudent  = $group === 'student';
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

// Instructor: re-open the panel that was just submitted when it failed
$postedAction = $_POST['project_action'] ?? '';
$reopenAssign = $isFaculty && $error !== '' && $postedAction === 'create_project';
$reopenGroup  = $isFaculty && $error !== '' && $postedAction === 'create_group';

// Toast (error wins over a flash message)
$toast = null;
if ($error !== '')  $toast = ['err', [$error]];
elseif ($flash)     $toast = [$flash['type'] === 'error' ? 'err' : 'ok', (array)$flash['msgs']];

// Icons
$chevron   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
$icoPlus   = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 7.5v9M7.5 12h9" stroke="#1a1535" stroke-width="2.4" stroke-linecap="round"/></svg>';
$icoLayers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="m12 2-10 5 10 5 10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg>';
$icoDoc    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>';
$icoCheck  = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="m7.5 12.5 3 3 6-7" fill="none" stroke="#4a3558" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$icoTrash  = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Projects</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Serif+Text&family=Inter:wght@400;500;600;700;800&display=swap">

  <style>
/* ---------- tokens (modals + toast live outside <main>, so they get them too) ---------- */
.pj, .pj-backdrop, .pj-toast {
    --pj-serif: "DM Serif Text", "Noto Serif", Georgia, "Times New Roman", serif;
    --pj-sans: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    --pj-purple: #8b4fc7;
    --pj-navy: #1a1535;
}

.projects-page { padding: 32px 52px !important; color: #fff; }
.pj { box-sizing: border-box; min-width: 0; }
.pj > * { flex-shrink: 0; } /* <main> is a fixed-height flex column: keep the blocks from squashing */
.pj-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* ---------- top row ---------- */
.pj-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.pj-search { width: min(368px, 100%); }
.pj-search input { display: block; width: 100%; box-sizing: border-box; height: 34px; padding: 0 18px; border: 0; border-radius: 17px; background: rgba(60, 38, 72, 0.55); color: #fff; font: 700 17px/1 var(--pj-sans); outline: none; }
.pj-search input::placeholder { color: rgba(225, 210, 235, 0.7); font-weight: 700; }
.pj-search input:focus { box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.35); }
.pj-top-tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.pj-pillbtn { display: inline-flex; align-items: center; gap: 8px; height: 34px; padding: 0 18px; border: 0; border-radius: 17px; background: var(--pj-navy); color: #fff; font: 400 13px var(--pj-serif); white-space: nowrap; cursor: pointer; transition: background 0.2s; }
.pj-pillbtn:hover, .pj-pillbtn:focus-visible { background: #2d2460; outline: none; }

/* ---------- stats + overall progress ---------- */
.pj-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: clamp(14px, 4.4vw, 58px); margin-bottom: 26px; }
.pj-stat { display: flex; flex-direction: column; justify-content: space-between; min-height: 117px; padding: 12px 14px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.85); border-radius: 12px; background: rgba(255, 255, 255, 0.08); }
.pj-stat > span { font: 400 21px/1.1 var(--pj-serif); }
.pj-stat > div { display: flex; align-items: flex-end; justify-content: space-between; }
.pj-stat strong { font: 700 48px/1 var(--pj-sans); }
.pj-stat svg { width: 40px; height: 40px; }

.pj-overall { display: flex; align-items: center; gap: 16px; margin-bottom: 26px; }
.pj-overall > span { flex: none; font: 400 21px var(--pj-serif); }

.pj-bar { position: relative; flex: 1; height: 34px; border-radius: 17px; background: rgba(40, 22, 52, 0.55); overflow: hidden; }
.pj-bar > i { position: absolute; left: 0; top: 0; bottom: 0; min-width: 16px; border-radius: 17px; background: var(--pj-purple); }
.pj-bar > span { position: absolute; inset: 0; display: grid; place-items: center; font: 700 14px var(--pj-sans); }
.pj-bar.dark { height: 31px; border-radius: 16px; background: rgba(44, 30, 54, 0.9); }
.pj-bar.dark > i { min-width: 46px; border-radius: 16px; }
.pj-bar.dark > i.zero { background: rgba(20, 12, 25, 0.85); }
/* directly inside <main> (a column flexbox) `flex: 1` would collapse the bar's height to 0 */
.pj > .pj-bar { flex: none; width: 100%; box-sizing: border-box; min-height: 31px; margin-bottom: 4px; }

/* outlined bar used on the student detail page */
.pj-bar.line { height: 25px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 13px; background: transparent; box-sizing: border-box; }
.pj-bar.line > i { min-width: 42px; border-radius: 13px; background: var(--pj-purple); }
.pj-bar.line > i.zero { background: #2a1a33; }
.pj-bar.line > span { font-size: 13px; }
.pj > .pj-bar.line { min-height: 25px; margin-bottom: 18px; }

/* ---------- student detail header ---------- */
.pj-detail-top .pj-back-link { margin: 0; text-decoration: none; }
.pj-sd { margin-bottom: 18px; padding: 16px 22px 22px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.85); border-radius: 36px; background: linear-gradient(180deg, rgba(112, 86, 124, 0.55), rgba(70, 50, 82, 0.5)); }
.pj-sd-code { display: inline-block; padding: 0 12px; border: 1px solid #fff; border-radius: 14px; font: 400 17px/26px var(--pj-sans); }
.pj-sd h1 { margin: 0 0 6px; font: 400 clamp(34px, 4.4vw, 56px)/1.05 var(--pj-serif); overflow-wrap: anywhere; }
.pj-sd-instr { max-width: 560px; margin: 0 0 14px; font: 400 15px/1.25 var(--pj-sans); overflow-wrap: anywhere; }
.pj-sd-file { display: flex; align-items: center; width: min(576px, 100%); min-height: 54px; padding: 8px 20px; box-sizing: border-box; margin-bottom: 18px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 16px; background: rgba(60, 42, 70, 0.7); color: #fff; font: 400 14px var(--pj-sans); text-decoration: none; overflow-wrap: anywhere; }
a.pj-sd-file:hover { background: rgba(86, 60, 100, 0.8); }
.pj-sd-file.empty { color: rgba(255, 255, 255, 0.55); }
.pj-sd-meta { margin: 0 0 14px; font: 400 16px var(--pj-sans); }
.pj-sd-prof { margin: 0; font: 400 18px var(--pj-serif); }

/* student board: tall rounded accent on each task, no outline (as in the mockup) */
.pj-board.stu .pj-task-card { border: 0; }
.pj-board.stu .pj-task-bar { width: 32px; border-radius: 16px; }
.pj-field { display: block; min-height: 22px; box-sizing: border-box; margin: 0 0 10px; padding: 3px 12px; border-radius: 12px; background: #111; font-size: 11px; line-height: 1.5; overflow-wrap: anywhere; }

.pj-empty { margin: 8px 0 0; color: rgba(255, 255, 255, 0.7); }

/* ---------- project cards ---------- */
.pj-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 280px)); gap: 28px 46px; }
.pj-pcard { display: flex; min-height: 254px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 8px; overflow: hidden; background: linear-gradient(180deg, rgba(90, 64, 104, 0.75), rgba(60, 36, 74, 0.85)); }
.pj-pcard::before { content: ""; flex: none; width: 8px; background: var(--pj-purple); }
.pj-pcard-body { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 12px 12px 10px 10px; font-family: var(--pj-serif); }
.pj-pcard-by, .pj-pcard-code { font-size: 13px; }
.pj-pcard-title { margin: 2px 0 8px; font: 400 26px/1.1 var(--pj-serif); overflow-wrap: anywhere; }
.pj-pcard-meta { margin: 0 0 3px 3px; font-size: 13px; color: rgba(255, 255, 255, 0.82); }
.pj-pcard-prog { display: flex; align-items: center; gap: 8px; margin-top: auto; padding-top: 12px; font: 400 10px var(--pj-serif); white-space: nowrap; }
.pj-pcard-prog .pj-bar { height: 12px; border-radius: 6px; background: #2a1a33; }
.pj-pcard-prog .pj-bar > i { min-width: 0; border-radius: 6px; }
.pj-pcard-prog .pj-bar > span { font-size: 8px; }
.pj-pcard-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.pj-view { display: inline-flex; align-items: center; gap: 8px; min-height: 22px; padding: 0 14px; border-radius: 11px; background: var(--pj-purple); color: #fff; font: 500 11px var(--pj-sans); text-decoration: none; transition: background 0.2s; }
.pj-view:hover, .pj-view:focus-visible { background: #a064e0; outline: none; }
.pj-inline { margin: 0; }
.pj-del { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 22px; border: 0; border-radius: 11px; background: #5a1a2a; color: #fff; cursor: pointer; }
.pj-del:hover, .pj-del:focus-visible { background: #8a2540; outline: none; }

/* ---------- detail ---------- */
.pj-detail-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 26px; }
.pj-backbtn { display: inline-flex; align-items: center; gap: 6px; height: 45px; padding: 0 14px; box-sizing: border-box; border-radius: 10px; background: var(--pj-navy); color: #fff; font: 400 16px var(--pj-serif); text-decoration: none; transition: background 0.2s; }
.pj-backbtn:hover, .pj-backbtn:focus-visible { background: #2d2460; outline: none; }

.pj-banner { display: flex; align-items: stretch; min-height: 100px; margin-bottom: 20px; border-radius: 12px; background: rgba(58, 42, 68, 0.85); overflow: hidden; }
.pj-banner-icon { flex: none; display: grid; place-items: center; width: 104px; background: #c21bff; color: #fff; }
.pj-banner-icon svg { width: 62px; height: 62px; }
.pj-banner-text { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 4px; padding: 10px 18px; }
.pj-banner-text h1 { margin: 0; font: 400 34px/1.1 var(--pj-serif); overflow-wrap: anywhere; }
.pj-team { display: flex; align-items: center; gap: 10px; font: 400 17px var(--pj-serif); }
.pj-banner-meta { font: 400 12px var(--pj-sans); color: rgba(255, 255, 255, 0.8); }
.pj-banner-tools { display: flex; align-items: center; padding: 10px 18px; }
.pj-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 6px 16px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 10px; background: rgba(26, 12, 38, 0.75); color: #fff; font: 600 13px var(--pj-sans); text-decoration: none; white-space: nowrap; cursor: pointer; }
.pj-btn:hover, .pj-btn:focus-visible { background: rgba(70, 30, 100, 0.95); outline: none; }

.pj-instr { margin: 0 0 18px; padding: 10px 16px; border-radius: 10px; background: rgba(40, 24, 50, 0.6); font: 400 14px/1.5 var(--pj-sans); }
.pj-instr summary { cursor: pointer; font: 400 16px var(--pj-serif); }
.pj-instr p { margin: 8px 0 0; overflow-wrap: anywhere; }

.pj-section-title { margin: 0 0 8px; font: 400 28px/1.1 var(--pj-serif); }

/* ---------- kanban ---------- */
.pj-board { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 28px; align-items: start; margin-top: 14px; }
.pj-col { min-height: 438px; border-radius: 18px; background: linear-gradient(180deg, rgba(74, 50, 86, 0.65), rgba(24, 10, 32, 0.95)); overflow: hidden; }
.pj-col-head { display: flex; align-items: center; gap: 12px; height: 62px; padding: 0 14px; box-sizing: border-box; border-radius: 16px; font: 600 20px var(--pj-sans); }
.pj-col-head i { flex: none; width: 20px; height: 20px; border-radius: 50%; background: #d6c8de; }
.pj-col-body { position: relative; padding: 12px 8px; }
.col-todo     { --accent: #a35cc0; } .col-todo .pj-col-head     { background: #a35cc0; }
.col-progress { --accent: #4c2a55; } .col-progress .pj-col-head { background: #4c2a55; }
.col-review   { --accent: #6a0dad; } .col-review .pj-col-head   { background: #6a0dad; }
.col-done     { --accent: #1f1b3f; } .col-done .pj-col-head     { background: #1f1b3f; }
.pj-col-empty { margin: 8px; font: 400 13px var(--pj-sans); color: rgba(255, 255, 255, 0.55); }
.pj-add { margin-left: auto; display: grid; place-items: center; width: 26px; height: 26px; padding: 0; border: 0; border-radius: 50%; background: rgba(255, 255, 255, 0.75); color: #a35cc0; font: 700 20px/1 var(--pj-sans); cursor: pointer; }
.pj-add:hover, .pj-add:focus-visible { background: #fff; outline: none; }

.pj-task { margin-bottom: 10px; }
.pj-task-card { display: flex; align-items: stretch; border: 1px solid rgba(255, 255, 255, 0.45); border-radius: 10px; background: rgba(36, 24, 44, 0.95); overflow: hidden; }
.pj-task.open .pj-task-card { border-color: #fff; }
.pj-task-bar { flex: none; width: 6px; background: var(--accent); }
.pj-task-body { flex: 1; min-width: 0; padding: 10px 6px 9px 12px; }
.pj-task-title { margin: 0 0 4px; font: 600 13px/1.25 var(--pj-sans); overflow-wrap: anywhere; }
.pj-task-sub { margin: 0 0 2px; font: 400 11px/1.3 var(--pj-sans); color: rgba(255, 255, 255, 0.85); overflow-wrap: anywhere; }
.pj-task-side { flex: none; display: flex; align-items: flex-end; padding: 8px 10px; }
.pj-task-side form { margin: 0; }
.pj-chev { display: grid; place-items: center; padding: 2px; border: 0; background: none; color: #fff; cursor: pointer; transition: transform 0.2s; }
.pj-task.open .pj-chev { transform: rotate(180deg); }
.pj-start { padding: 3px 16px; border: 0; border-radius: 6px; background: #1d1923; color: #fff; font: 700 11px var(--pj-sans); cursor: pointer; }
.pj-start:hover, .pj-start:focus-visible { background: #3a1d52; outline: none; }

.pj-task-more { display: none; margin: 6px 0 0; padding: 10px 12px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.25); border-radius: 6px; background: #262626; font: 400 12px var(--pj-sans); }
.pj-task.open .pj-task-more { display: block; }
.pj-task-more label { display: block; margin: 0 0 4px; font-weight: 700; }
.pj-task-more input[type=file] { display: block; width: 100%; box-sizing: border-box; margin-bottom: 8px; padding: 6px; border-radius: 8px; background: #111; color: #fff; font-size: 11px; }
.pj-task-more textarea { display: block; width: 100%; box-sizing: border-box; min-height: 64px; margin-bottom: 10px; padding: 8px 10px; border: 0; border-radius: 10px; background: #111; color: #fff; font: 400 12px var(--pj-sans); resize: vertical; outline: none; }
.pj-task-more textarea:focus { box-shadow: 0 0 0 2px rgba(199, 156, 240, 0.5); }
.pj-file { display: block; margin-bottom: 10px; padding: 6px 12px; border-radius: 12px; background: #111; color: #fff; font-size: 11px; text-decoration: none; overflow-wrap: anywhere; }
a.pj-file:hover { background: #2a1d38; }
.pj-comment { margin: 0 0 8px; font-size: 11px; line-height: 1.4; color: rgba(255, 255, 255, 0.85); overflow-wrap: anywhere; }
.pj-more-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pj-pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.pj-pill.ok   { background: #2f7d1f; }
.pj-pill.wait { background: #8a5a1a; }
.pj-pass { padding: 3px 14px; border: 0; border-radius: 6px; background: #cfb6e3; color: #25122f; font: 800 11px var(--pj-sans); cursor: pointer; }
.pj-pass:hover { background: #fff; }
.pj-review-actions { display: flex; justify-content: space-between; gap: 8px; }
.pj-decline, .pj-approve { min-height: 24px; padding: 0 18px; border: 0; border-radius: 12px; color: #fff; font: 600 11px var(--pj-sans); cursor: pointer; }
.pj-decline { background: #9a5a14; } .pj-decline:hover { background: #b46d1c; }
.pj-approve { background: #2f7d1f; } .pj-approve:hover { background: #3a9926; }

.pj-addtask { display: none; position: absolute; z-index: 10; top: 0; left: 8px; right: 8px; box-sizing: border-box; padding: 10px 12px 12px; border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 4px; background: #262626; font: 400 12px var(--pj-sans); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.45); }
.pj-addtask.open { display: block; }
.pj-addtask label { display: block; margin: 0 0 4px; font-weight: 700; }
.pj-addtask input, .pj-addtask select { display: block; width: 100%; box-sizing: border-box; height: 28px; margin-bottom: 10px; padding: 0 12px; border: 0; border-radius: 14px; background: #111; color: #fff; font: 400 12px var(--pj-sans); outline: none; color-scheme: dark; }
.pj-addtask input:focus, .pj-addtask select:focus { box-shadow: 0 0 0 2px rgba(199, 156, 240, 0.5); }
.pj-addtask-foot { display: flex; justify-content: flex-end; align-items: center; gap: 10px; }
.pj-link { padding: 4px 6px; border: 0; background: none; color: #fff; font: 600 11px var(--pj-sans); cursor: pointer; }
.pj-mini { min-height: 26px; padding: 0 14px; border: 0; border-radius: 13px; background: #111; color: #fff; font: 700 11px var(--pj-sans); cursor: pointer; }
.pj-mini:hover { background: #3a1d52; }

.pj-card { margin-top: 26px; padding: 20px 22px; box-sizing: border-box; border: 1px solid rgba(255, 255, 255, 0.7); border-radius: 18px; background: linear-gradient(180deg, rgba(90, 62, 104, 0.6), rgba(34, 11, 44, 0.85)); }
.pj-card h2 { margin: 0 0 14px; font: 400 24px var(--pj-serif); }
.pj-card dl { display: grid; grid-template-columns: 160px 1fr; gap: 10px 16px; margin: 0; font: 400 14px var(--pj-sans); }
.pj-card dt { font-weight: 700; }
.pj-card dd { margin: 0; overflow-wrap: anywhere; }
.pj-card a { color: #e2c4ff; }

/* ---------- panels (Assign Project / Create Group) ---------- */
.pj-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 900; display: none; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; background: rgba(40, 20, 50, 0.35); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); overflow: auto; }
.pj-backdrop.open { display: flex; }
.pj-panel { position: relative; box-sizing: border-box; width: min(800px, 100%); max-height: 100%; overflow-y: auto; padding: 24px 34px 28px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 44px; background: linear-gradient(180deg, rgba(78, 58, 86, 0.97), rgba(22, 12, 28, 0.98)); color: #fff; }
.pj-back-link { display: inline-flex; align-items: center; gap: 10px; margin: 0 0 22px; padding: 4px; border: 0; background: none; color: #fff; font: 400 15px var(--pj-sans); cursor: pointer; }
.pj-back-link:hover, .pj-back-link:focus-visible { text-decoration: underline; outline: none; }

.pj-row { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 12px; align-items: center; margin-bottom: 12px; }
.pj-row.top { align-items: start; }
.pj-row > label, .pj-row > .pj-lbl { font: 400 20px/1.15 var(--pj-serif); }
.pj-row input[type=text], .pj-row input[type=date], .pj-row select, .pj-row textarea, .pj-dd-btn { display: block; width: 100%; box-sizing: border-box; min-height: 36px; padding: 6px 12px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 8px; background: #150d1b; color: #fff; font: 400 16px var(--pj-sans); outline: none; color-scheme: dark; }
.pj-row textarea { min-height: 130px; resize: vertical; }
.pj-row input:focus, .pj-row select:focus, .pj-row textarea:focus, .pj-dd-btn:focus-visible, .pj-dd.open .pj-dd-btn { border-color: #c79cf0; box-shadow: 0 0 0 2px rgba(199, 156, 240, 0.3); }
.pj-row input[readonly] { opacity: 0.85; cursor: default; }
.pj-hint { margin: 4px 0 0; font: 400 12px var(--pj-sans); color: rgba(255, 255, 255, 0.65); }

.pj-attach { position: relative; display: inline-flex; align-items: center; gap: 8px; height: 30px; padding: 0 18px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 15px; background: #150d1b; font: 400 14px var(--pj-sans); cursor: pointer; }
.pj-attach input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.pj-attach:focus-within, .pj-attach:hover { border-color: #c79cf0; }
.pj-filename { margin-left: 10px; font: 400 13px var(--pj-sans); opacity: 0.8; overflow-wrap: anywhere; }

.pj-dd { position: relative; }
.pj-dd-btn { display: flex; align-items: center; justify-content: space-between; gap: 10px; text-align: left; cursor: pointer; }
.pj-dd-btn span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pj-dd-btn svg { flex: none; transition: transform 0.2s; }
.pj-dd.open .pj-dd-btn svg { transform: rotate(180deg); }
.pj-members { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 5; max-height: 170px; overflow-y: auto; padding: 6px 12px; border: 1px solid #c79cf0; border-radius: 8px; background: #150d1b; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.45); }
.pj-dd.open .pj-members { display: block; }
.pj-members label { display: flex; align-items: center; gap: 8px; margin: 0; padding: 5px 0; font: 400 14px var(--pj-sans); cursor: pointer; }
.pj-members input { width: auto; min-height: 0; margin: 0; }

.pj-panel-foot { display: flex; justify-content: flex-end; margin-top: 18px; }
.pj-submit { min-height: 40px; padding: 0 22px; border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 8px; background: rgba(10, 5, 14, 0.8); color: #fff; font: 400 17px var(--pj-sans); cursor: pointer; transition: background 0.2s; }
.pj-submit:hover, .pj-submit:focus-visible { background: #2c1b38; outline: none; }
.pj-submit:disabled { opacity: 0.5; cursor: not-allowed; }

/* ---------- toast ---------- */
.pj-toast { position: fixed; top: 88px; left: 50%; transform: translateX(-50%); z-index: 950; box-sizing: border-box; max-width: min(560px, 92vw); padding: 16px 34px; border-radius: 16px; background: #0a8317; color: #fff; font: 800 clamp(16px, 2vw, 26px)/1.2 var(--pj-sans); text-align: center; text-transform: uppercase; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4); transition: opacity 0.3s; }
.pj-toast.err { background: #a3202f; font-size: 15px; font-weight: 600; text-transform: none; }
.pj-toast.hide { opacity: 0; pointer-events: none; }

@media (max-width: 1200px) { .pj-board { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) {
    .projects-page { padding: 18px 16px !important; }
    .pj-stats { grid-template-columns: 1fr; gap: 12px; }
    .pj-board { grid-template-columns: 1fr; }
    .pj-cards { grid-template-columns: 1fr; }
    .pj-overall { flex-wrap: wrap; }
    .pj-banner { flex-wrap: wrap; }
    .pj-banner-icon { width: 76px; }
    .pj-panel { padding: 20px 18px 24px; border-radius: 28px; }
    .pj-row { grid-template-columns: 1fr; gap: 6px; }
    .pj-card dl { grid-template-columns: 1fr; }
}
/* scoped on purpose: a global `*` rule here would also switch off the sidebar's own transitions */
@media (prefers-reduced-motion: reduce) { .pj *, .pj-backdrop *, .pj-toast { transition: none !important; } }
  </style>
</head>
<body data-page="PROJECTS">

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>

  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar projects-page pj">

<?php if (!$isStudent && !$isFaculty): ?>

        <p style="margin: 0;">This page is not available for your account type.</p>

<?php elseif (!$selected): ?>
<?php /* ===================== PROJECT LIST (student + instructor) ===================== */ ?>

        <div class="pj-top">
            <div class="pj-search">
                <input type="search" id="pj-search" placeholder="Search....." aria-label="Search projects" autocomplete="off">
            </div>
            <?php if ($isFaculty): ?>
            <div class="pj-top-tools">
                <button type="button" class="pj-pillbtn" data-open="pj-group-modal"><?= $icoPlus ?> Create Group</button>
                <button type="button" class="pj-pillbtn" data-open="pj-assign-modal"><?= $icoPlus ?> Assign Project</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="pj-stats">
            <div class="pj-stat"><span>Assigned Projects</span><div><strong><?= (int)$stats['total'] ?></strong><?= $icoLayers ?></div></div>
            <div class="pj-stat"><span>Pending Submission</span><div><strong><?= (int)$stats['pending'] ?></strong><?= $icoDoc ?></div></div>
            <div class="pj-stat"><span>Submitted</span><div><strong><?= (int)$stats['submitted'] ?></strong><?= $icoCheck ?></div></div>
        </div>

        <div class="pj-overall">
            <span>Overall Progress:</span>
            <?= proj_bar($logic->overall) ?>
        </div>

        <?php if (empty($logic->projects)): ?>
            <p class="pj-empty"><?= $isFaculty ? "You haven't assigned any projects yet." : 'No projects have been assigned to you yet.' ?></p>
        <?php else: ?>
        <div class="pj-cards" id="pj-cards">
            <?php foreach ($logic->projects as $p):
                $search = strtolower(implode(' ', [$p['code'], $p['name'], $p['assigned_by'], $p['section'] ?? '']));
            ?>
                <article class="pj-pcard" data-search="<?= proj_e($search) ?>">
                    <div class="pj-pcard-body">
                        <span class="pj-pcard-by"><?= proj_e($p['assigned_by']) ?></span>
                        <span class="pj-pcard-code"><?= proj_e($p['code']) ?></span>
                        <h3 class="pj-pcard-title"><?= proj_e($p['name']) ?></h3>
                        <p class="pj-pcard-meta">Project type: <?= proj_e($p['project_type'] ?: 'Solo') ?></p>
                        <p class="pj-pcard-meta">Assigned to: <?= proj_e($p['section'] ?: '—') ?></p>
                        <p class="pj-pcard-meta">Deadline: <?= proj_e(proj_date_long($p['deadline'])) ?></p>

                        <div class="pj-pcard-prog">
                            <span>Students' Progress:</span>
                            <?= proj_bar($p['progress']) ?>
                        </div>

                        <div class="pj-pcard-foot">
                            <?php if ($isFaculty): ?>
                                <form class="pj-inline" method="POST" action="<?= $formAction ?>" onsubmit="return confirm('Delete this project and all of its tasks? This cannot be undone.');">
                                    <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                    <input type="hidden" name="project_action" value="delete_project">
                                    <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="pj-del" aria-label="Delete <?= proj_e($p['name']) ?>"><?= $icoTrash ?></button>
                                </form>
                            <?php endif; ?>
                            <a class="pj-view" href="<?= proj_e(ProjectsLogic::url(['project' => (int)$p['id']])) ?>" aria-label="View <?= proj_e($p['name']) ?>">View
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h17M14 6l6 6-6 6"/></svg>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

<?php else: ?>
<?php /* ===================== PROJECT DETAIL / KANBAN (student + instructor) ===================== */
    $teamCount = count($logic->team);
    $instrText = trim((string)($selected['instructions'] ?? ''));
?>

        <div class="pj-detail-top">
            <a href="<?= proj_e(ProjectsLogic::url()) ?>" class="pj-backbtn">← Back to projects</a>
            <?php if ($isStudent): ?>
            <div class="pj-search">
                <input type="search" id="pj-task-search" placeholder="Search tasks....." aria-label="Search tasks" autocomplete="off">
            </div>
            <?php endif; ?>
        </div>

        <div class="pj-banner">
            <div class="pj-banner-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="3" width="15" height="12" rx="2"/><path d="m9 11 3-3 2 2 3-3"/><path d="M3 7v11a3 3 0 0 0 3 3h12"/></svg>
            </div>
            <div class="pj-banner-text">
                <h1><?= proj_e($selected['name']) ?></h1>
                <div class="pj-team">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/><circle cx="17" cy="9" r="2.5"/><path d="M18 14.2a5 5 0 0 1 4 4.8v1"/></svg>
                    Team: <?= $teamCount > 0 ? $teamCount . ' member' . ($teamCount === 1 ? '' : 's') : 'no group yet' ?>
                </div>
                <div class="pj-banner-meta">
                    <?= proj_e($selected['code']) ?> · <?= proj_e($selected['section'] ?: '—') ?> · Due <?= proj_e(proj_date($selected['deadline'])) ?>
                </div>
            </div>
            <?php if (!empty($selected['instruction_file'])): ?>
            <div class="pj-banner-tools">
                <a class="pj-btn" target="_blank" rel="noopener" href="<?= BASE_PATH ?>/<?= proj_e($selected['instruction_file']) ?>">Attachment</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($instrText !== ''): ?>
        <details class="pj-instr">
            <summary>Instructions</summary>
            <p><?= nl2br(proj_e($instrText)) ?></p>
        </details>
        <?php endif; ?>

        <h2 class="pj-section-title">Task Progress</h2>
        <?= proj_bar($logic->taskPercent, true) ?>

        <div class="pj-board">
            <?php foreach ($columns as $key => $col): ?>
                <section class="pj-col <?= $col[1] ?>" aria-label="<?= proj_e($col[0]) ?>">
                    <div class="pj-col-head">
                        <i></i><?= proj_e($col[0]) ?>
                        <?php if ($isFaculty && $key === 'todo'): ?>
                            <button type="button" class="pj-add" id="pj-add-toggle" aria-label="Add task" aria-expanded="false">+</button>
                        <?php endif; ?>
                    </div>
                    <div class="pj-col-body">

                        <?php if ($isFaculty && $key === 'todo'): ?>
                            <form class="pj-addtask" id="pj-addtask" method="POST" action="<?= $formAction ?>">
                                <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                <input type="hidden" name="project_action" value="add_task">
                                <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">

                                <label for="pj-task-title">Task Title</label>
                                <input type="text" id="pj-task-title" name="task_title" required maxlength="150">

                                <label for="pj-task-deadline">Submission</label>
                                <input type="date" id="pj-task-deadline" name="task_deadline" required min="<?= date('Y-m-d') ?>">

                                <label for="pj-task-student">Assigned Student</label>
                                <?php if ($teamCount > 0): ?>
                                    <select id="pj-task-student" name="task_student" required>
                                        <option value="">Select a student…</option>
                                        <?php foreach ($logic->team as $m): ?>
                                            <option value="<?= (int)$m['studentID'] ?>"><?= proj_e(proj_person($m)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <select id="pj-task-student" name="task_student" disabled><option>No group yet</option></select>
                                <?php endif; ?>

                                <div class="pj-addtask-foot">
                                    <button type="button" class="pj-link" id="pj-addtask-cancel">Cancel</button>
                                    <button type="submit" class="pj-mini">Create Task</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if (empty($logic->tasks[$key])): ?>
                            <p class="pj-col-empty">No tasks</p>
                        <?php endif; ?>

                        <?php foreach ($logic->tasks[$key] as $t):
                            $att      = trim((string)($t['attachment'] ?? ''));
                            $assignee = trim((string)($t['assignee'] ?? ''));
                            $comment  = trim((string)($t['review_comment'] ?? ''));
                            $label    = 'Show details for ' . $t['title'];
                        ?>
                            <article class="pj-task" data-title="<?= proj_e(strtolower($t['title'])) ?>">
                                <div class="pj-task-card">
                                    <span class="pj-task-bar"></span>
                                    <div class="pj-task-body">
                                        <p class="pj-task-title"><?= proj_e($t['title']) ?></p>
                                        <p class="pj-task-sub"><?= proj_e(proj_date($t['deadline'])) ?></p>
                                        <?php if ($assignee !== ''): ?>
                                            <p class="pj-task-sub">Assigned to: <?= proj_e($assignee) ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($key !== 'todo' || $isStudent): ?>
                                    <div class="pj-task-side">
                                        <?php if ($key === 'todo'): ?>
                                            <form method="POST" action="<?= $formAction ?>">
                                                <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                                <input type="hidden" name="project_action" value="start_task">
                                                <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" class="pj-start">Start</button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="pj-chev js-expand" aria-expanded="false" aria-label="<?= proj_e($label) ?>"><?= $chevron ?></button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($key === 'progress' && $isStudent): ?>
                                    <form class="pj-task-more" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                        <input type="hidden" name="project_action" value="pass_task">
                                        <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                        <?php if ($comment !== ''): ?>
                                            <p class="pj-comment"><strong>Instructor comment:</strong> <?= nl2br(proj_e($comment)) ?></p>
                                        <?php endif; ?>
                                        <label for="att-<?= (int)$t['id'] ?>">Screenshot/File attachment:</label>
                                        <input type="file" id="att-<?= (int)$t['id'] ?>" name="attachment" required>
                                        <div class="pj-more-foot"><span></span><button type="submit" class="pj-pass">PASS</button></div>
                                    </form>

                                <?php elseif ($key === 'progress'): ?>
                                    <div class="pj-task-more">
                                        <?php if ($comment !== ''): ?>
                                            <p class="pj-comment"><strong>Your last comment:</strong> <?= nl2br(proj_e($comment)) ?></p>
                                        <?php endif; ?>
                                        <p class="pj-comment" style="margin:0;">Waiting for the student to pass this task for review.</p>
                                    </div>

                                <?php elseif ($key === 'review' && $isFaculty): ?>
                                    <form class="pj-task-more" method="POST" action="<?= $formAction ?>">
                                        <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
                                        <input type="hidden" name="project_id" value="<?= (int)$selected['id'] ?>">
                                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                        <label>File/Attachment</label>
                                        <?= proj_file($att) ?>
                                        <label for="cm-<?= (int)$t['id'] ?>">Comment</label>
                                        <textarea id="cm-<?= (int)$t['id'] ?>" name="comment" maxlength="1000"></textarea>
                                        <div class="pj-review-actions">
                                            <button type="submit" name="project_action" value="decline_task" class="pj-decline">Decline</button>
                                            <button type="submit" name="project_action" value="approve_task" class="pj-approve">Approve</button>
                                        </div>
                                    </form>

                                <?php elseif ($key === 'review'): ?>
                                    <div class="pj-task-more">
                                        <label>File/Attachment</label>
                                        <?= proj_file($att) ?>
                                        <div class="pj-more-foot"><span class="pj-pill wait">Waiting for approval</span></div>
                                    </div>

                                <?php elseif ($key === 'done'): ?>
                                    <div class="pj-task-more">
                                        <label>File/Attachment</label>
                                        <?= proj_file($att) ?>
                                        <?php if ($comment !== ''): ?>
                                            <p class="pj-comment"><strong>Comment:</strong> <?= nl2br(proj_e($comment)) ?></p>
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

        <?php if ($isFaculty): $sub = $logic->submission; ?>
        <div class="pj-card">
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
        <?php endif; ?>

<?php endif; ?>

      </main>
    </div>
  </div>

<?php if ($isFaculty && !$selected): ?>
<?php /* ===================== INSTRUCTOR PANELS (kept outside <main> so they position against the viewport) ===================== */ ?>

  <div class="pj-backdrop<?= $reopenAssign ? ' open' : '' ?>" id="pj-assign-modal" role="dialog" aria-modal="true" aria-labelledby="pj-assign-title">
    <form class="pj-panel" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
      <button type="button" class="pj-back-link" data-close>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4M10 6l-6 6 6 6"/></svg> Back
      </button>
      <h2 id="pj-assign-title" class="pj-sr">Assign Project</h2>

      <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
      <input type="hidden" name="project_action" value="create_project">

      <div class="pj-row">
        <label for="pj-code">Project ID:</label>
        <input type="text" id="pj-code" value="<?= proj_e($logic->nextCode) ?>" readonly>
      </div>

      <div class="pj-row">
        <label for="pj-name">Project Title:</label>
        <input type="text" id="pj-name" name="name" required maxlength="150" value="<?= proj_old('name', $reopenAssign) ?>">
      </div>

      <div class="pj-row">
        <label for="pj-type">Project Type:</label>
        <?php $postedType = $reopenAssign ? ($_POST['project_type'] ?? 'Solo') : 'Solo'; ?>
        <select id="pj-type" name="project_type">
          <option value="Solo"<?= $postedType === 'Solo' ? ' selected' : '' ?>>Solo</option>
          <option value="Duo"<?= $postedType === 'Duo' ? ' selected' : '' ?>>Duo</option>
        </select>
      </div>

      <div class="pj-row">
        <label for="pj-section">Assign to:</label>
        <div>
          <input type="text" id="pj-section" name="section" required maxlength="50" list="pj-sections" placeholder="e.g. BSCS - 2C" autocomplete="off" value="<?= proj_old('section', $reopenAssign) ?>">
          <datalist id="pj-sections">
            <?php foreach ($logic->sections as $s): ?><option value="<?= proj_e($s) ?>"><?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div class="pj-row">
        <label for="pj-deadline">Set Deadline:</label>
        <input type="date" id="pj-deadline" name="deadline" required min="<?= date('Y-m-d') ?>" value="<?= proj_old('deadline', $reopenAssign) ?>">
      </div>

      <div class="pj-row top">
        <label for="pj-instructions">Instructions:</label>
        <textarea id="pj-instructions" name="instructions" maxlength="2000"><?= proj_old('instructions', $reopenAssign) ?></textarea>
      </div>

      <div class="pj-row">
        <span class="pj-lbl">Attachment:</span>
        <div>
          <label class="pj-attach">
            <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 7.5v9M7.5 12h9" stroke="#150d1b" stroke-width="2.6" stroke-linecap="round"/></svg>
            Attach File
            <input type="file" name="instruction_file">
          </label><span class="pj-filename"></span>
        </div>
      </div>

      <div class="pj-panel-foot">
        <button type="submit" class="pj-submit">Assign Project</button>
      </div>
    </form>
  </div>

  <div class="pj-backdrop<?= $reopenGroup ? ' open' : '' ?>" id="pj-group-modal" role="dialog" aria-modal="true" aria-labelledby="pj-group-title">
    <form class="pj-panel" method="POST" action="<?= $formAction ?>">
      <button type="button" class="pj-back-link" data-close>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4M10 6l-6 6 6 6"/></svg> Back
      </button>
      <h2 id="pj-group-title" class="pj-sr">Create Group</h2>

      <input type="hidden" name="csrf" value="<?= proj_e($csrf) ?>">
      <input type="hidden" name="project_action" value="create_group">

      <?php $hasGroupProjects = !empty($logic->groupProjects); ?>

      <div class="pj-row">
        <label for="pj-g-section">Course/Year/Block:</label>
        <input type="text" id="pj-g-section" value="" readonly placeholder="Pick a project first">
      </div>

      <div class="pj-row">
        <label for="pj-g-project">Project ID:</label>
        <div>
          <select id="pj-g-project" name="project_id" required <?= $hasGroupProjects ? '' : 'disabled' ?>>
            <?php if ($hasGroupProjects): ?>
              <option value="">Select a project…</option>
              <?php foreach ($logic->groupProjects as $gp): ?>
                <option value="<?= (int)$gp['id'] ?>" data-section="<?= proj_e($gp['section'] ?? '') ?>" data-type="<?= proj_e($gp['project_type'] ?? '') ?>"<?= $reopenGroup && (string)($_POST['project_id'] ?? '') === (string)$gp['id'] ? ' selected' : '' ?>>
                  <?= proj_e($gp['code'] . ' — ' . $gp['name']) ?>
                </option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="">No projects are waiting for a group</option>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div class="pj-row">
        <label for="pj-g-choice">Choices:</label>
        <input type="text" id="pj-g-choice" value="" readonly placeholder="Pick a project first">
      </div>

      <div class="pj-row">
        <label for="pj-g-leader">Assign Leader:</label>
        <select id="pj-g-leader" name="leader" required>
          <option value="">Select a leader…</option>
          <?php foreach ($logic->studentOptions as $s): ?>
            <option value="<?= (int)$s['enrolled_subjectID'] ?>"<?= $reopenGroup && (string)($_POST['leader'] ?? '') === (string)$s['enrolled_subjectID'] ? ' selected' : '' ?>><?= proj_e($s['last_name'] . ', ' . $s['first_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="pj-row top" id="pj-g-members-row">
        <span class="pj-lbl" id="pj-dd-label">Assign Member:</span>
        <div>
          <div class="pj-dd" id="pj-dd">
            <button type="button" class="pj-dd-btn" id="pj-dd-btn" aria-haspopup="true" aria-expanded="false" aria-labelledby="pj-dd-label">
              <span id="pj-dd-text">Select members…</span>
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div class="pj-members" id="pj-members">
              <?php if (empty($logic->studentOptions)): ?>
                <span class="pj-hint">No students available.</span>
              <?php endif; ?>
              <?php
                $postedMembers = $reopenGroup ? array_map('strval', (array)($_POST['members'] ?? [])) : [];
                foreach ($logic->studentOptions as $s):
              ?>
                <label data-id="<?= (int)$s['enrolled_subjectID'] ?>">
                  <input type="checkbox" name="members[]" value="<?= (int)$s['enrolled_subjectID'] ?>"<?= in_array((string)$s['enrolled_subjectID'], $postedMembers, true) ? ' checked' : '' ?>>
                  <?= proj_e($s['last_name'] . ', ' . $s['first_name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <p class="pj-hint">The leader is added automatically. Duo projects need exactly one member.</p>
        </div>
      </div>

      <div class="pj-row top">
        <label for="pj-g-message">Message:</label>
        <textarea id="pj-g-message" name="message" maxlength="1000" style="min-height: 100px;"><?= proj_old('message', $reopenGroup) ?></textarea>
      </div>

      <div class="pj-panel-foot">
        <button type="submit" class="pj-submit"<?= $hasGroupProjects ? '' : ' disabled' ?>>Create Group</button>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php if ($toast): ?>
  <div class="pj-toast <?= $toast[0] === 'err' ? 'err' : 'ok' ?>" id="pj-toast" role="<?= $toast[0] === 'err' ? 'alert' : 'status' ?>"><?= implode('<br>', array_map('proj_e', $toast[1])) ?></div>
<?php endif; ?>

  <script>
  (function () {
      var main = document.querySelector('main.pj');
      function byId(id) { return document.getElementById(id); }

      /* ---- toast ---- */
      var toast = byId('pj-toast');
      if (toast) setTimeout(function () { toast.classList.add('hide'); }, toast.classList.contains('err') ? 6500 : 3500);

      /* ---- list search ---- */
      var box = byId('pj-search');
      if (box) box.addEventListener('input', function () {
          var q = box.value.trim().toLowerCase();
          document.querySelectorAll('.pj-pcard').forEach(function (c) {
              c.style.display = c.dataset.search.indexOf(q) === -1 ? 'none' : '';
          });
      });

      /* ---- panels: the blurred backdrop covers only the content panel, like the mockup ---- */
      var modals = document.querySelectorAll('.pj-backdrop');
      function place(m) {
          if (!main) return;
          var r = main.getBoundingClientRect();
          m.style.left = r.left + 'px';
          m.style.top = r.top + 'px';
          m.style.width = r.width + 'px';
          m.style.height = r.height + 'px';
          m.style.borderRadius = getComputedStyle(main).borderRadius;
      }
      function placeOpen() { modals.forEach(function (m) { if (m.classList.contains('open')) place(m); }); }
      function openModal(m) {
          if (!m) return;
          place(m);
          m.classList.add('open');
          var first = m.querySelector('input:not([readonly]):not([type=hidden]):not([type=file]), select:not([disabled]), textarea');
          if (first) first.focus();
      }
      function closeModal(m) { m.classList.remove('open'); }

      document.querySelectorAll('[data-open]').forEach(function (b) {
          b.addEventListener('click', function () { openModal(byId(b.dataset.open)); });
      });
      modals.forEach(function (m) {
          m.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
          m.addEventListener('mousedown', function (e) { if (e.target === m) closeModal(m); });
      });
      document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') modals.forEach(function (m) { closeModal(m); });
      });
      window.addEventListener('resize', placeOpen);
      if (window.ResizeObserver && main) new ResizeObserver(placeOpen).observe(main);
      placeOpen();

      /* ---- attach-file label ---- */
      document.querySelectorAll('.pj-attach input[type=file]').forEach(function (inp) {
          inp.addEventListener('change', function () {
              var out = inp.closest('.pj-row').querySelector('.pj-filename');
              if (out) out.textContent = inp.files.length ? inp.files[0].name : '';
          });
      });

      /* ---- Create Group panel ---- */
      var gProject    = byId('pj-g-project');
      var gSection    = byId('pj-g-section');
      var gChoice     = byId('pj-g-choice');
      var gMembersRow = byId('pj-g-members-row');
      function syncSection() {
          if (!gProject || !gSection) return;
          var o = gProject.options[gProject.selectedIndex];
          gSection.value = o && o.dataset.section ? o.dataset.section : '';
          var type = o && o.dataset.type ? o.dataset.type : '';
          if (gChoice) gChoice.value = type;
          if (gMembersRow) {
              var solo = type === 'Solo';
              gMembersRow.style.display = solo ? 'none' : '';
              if (solo) gMembersRow.querySelectorAll('input:checked').forEach(function (i) { i.checked = false; });
          }
      }
      if (gProject) { gProject.addEventListener('change', syncSection); syncSection(); }

      var dd = byId('pj-dd'), ddBtn = byId('pj-dd-btn'), ddText = byId('pj-dd-text'), members = byId('pj-members'), leader = byId('pj-g-leader');
      if (dd && ddBtn && members) {
          var updateLabel = function () {
              var names = [];
              members.querySelectorAll('input:checked').forEach(function (i) { names.push(i.parentNode.textContent.trim()); });
              ddText.textContent = names.length ? names.join(' • ') : 'Select members…';
          };
          var setDd = function (open) {
              dd.classList.toggle('open', open);
              ddBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
          };
          ddBtn.addEventListener('click', function () { setDd(!dd.classList.contains('open')); });
          members.addEventListener('change', updateLabel);
          document.addEventListener('mousedown', function (e) { if (!dd.contains(e.target)) setDd(false); });
          document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setDd(false); });

          // the chosen leader can't also be picked as a member
          var syncLeader = function () {
              members.querySelectorAll('label').forEach(function (l) {
                  var same = leader && leader.value !== '' && l.dataset.id === leader.value;
                  l.style.display = same ? 'none' : '';
                  if (same) l.querySelector('input').checked = false;
              });
              updateLabel();
          };
          if (leader) { leader.addEventListener('change', syncLeader); }
          syncLeader();
      }

      /* ---- detail page: expand / collapse task details ---- */
      document.querySelectorAll('.js-expand').forEach(function (btn) {
          btn.addEventListener('click', function () {
              var task = btn.closest('.pj-task');
              var open = task.classList.toggle('open');
              btn.setAttribute('aria-expanded', open ? 'true' : 'false');
          });
      });

      /* ---- detail page: add-task popup (instructor) ---- */
      var addToggle = byId('pj-add-toggle'), addForm = byId('pj-addtask'), addCancel = byId('pj-addtask-cancel');
      if (addToggle && addForm) {
          addToggle.addEventListener('click', function () {
              var open = addForm.classList.toggle('open');
              addToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
              if (open) byId('pj-task-title').focus();
          });
          if (addCancel) addCancel.addEventListener('click', function () {
              addForm.classList.remove('open');
              addToggle.setAttribute('aria-expanded', 'false');
          });
      }

      /* ---- detail page: task search (student) ---- */
      var taskBox = byId('pj-task-search');
      if (taskBox) taskBox.addEventListener('input', function () {
          var q = taskBox.value.trim().toLowerCase();
          document.querySelectorAll('.pj-task').forEach(function (t) {
              t.style.display = t.dataset.title.indexOf(q) === -1 ? 'none' : '';
          });
      });
  })();
  </script>

</body>
</html>