<?php
require_once __DIR__ . "/../PageLogic/enrollment_logic.php";

$logic = new EnrollmentLogic();
$logic->handlePost();
$logic->loadData();


if (!function_exists('enroll_e')) {
    function enroll_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('enroll_url')) {
    
    function enroll_url($q = [], $fragment = '') { return EnrollmentLogic::url($q, $fragment); }
}
if (!function_exists('enroll_sem')) {
    function enroll_sem($n) {
        $n = (int)$n;
        return $n === 3 ? 'Summer' : EnrollmentLogic::ordinal($n) . ' Semester';
    }
}
if (!function_exists('enroll_badge')) {
    function enroll_badge($status) {
        $colors = ['pending' => '#f1c40f', 'approved' => '#2ecc71', 'rejected' => '#ff6b6b'];
        $c = $colors[$status] ?? '#bbb';
        return '<span style="background:' . $c . '; color:#111; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:bold;">'
            . enroll_e(ucfirst($status)) . '</span>';
    }
}

$formAction = enroll_e(EnrollmentLogic::url());

$group = $logic->group;
$term  = $logic->term;
$error = $logic->error;
$flash = $logic->takeFlash();
$csrf  = $logic->csrf();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Enrollment</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">

  <style>
      
.enrollment-page {
    padding: 30px !important;
    color: #f7f1ff;
}

.enrollment-page h1 {
    color: #fff;
    font-size: clamp(25px, 3vw, 32px);
    font-weight: 700 !important;
    letter-spacing: -0.6px;
}

.enrollment-page h2 {
    color: #fff;
    font-size: 19px !important;
    font-weight: 650;
    margin-top: 0;
    margin-bottom: 18px;
}

.enroll-card {
    background: linear-gradient(
        145deg,
        rgba(67, 32, 83, 0.72),
        rgba(28, 12, 43, 0.78)
    ) !important;
    border: 1px solid rgba(220, 185, 255, 0.18) !important;
    border-radius: 16px !important;
    padding: 24px !important;
    box-shadow: 0 10px 30px rgba(10, 0, 20, 0.18);
    backdrop-filter: blur(14px);
    transition: border-color 0.2s ease, transform 0.2s ease;
    min-width: 0;
}

.enroll-card:hover {
    border-color: rgba(204, 153, 255, 0.35) !important;
}

.enroll-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(300px, 0.75fr);
    gap: 22px;
    align-items: start;
    margin-top: 22px;
}

.subject-grid {
    display: grid;
    grid-template-columns: minmax(280px, 350px) minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
    border-radius: 10px;
}

.enroll-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    min-width: 580px;
}

.enroll-table th {
    text-align: left;
    padding: 15px 13px;
    background: rgba(14, 5, 24, 0.42);
    border-bottom: 1px solid rgba(210, 174, 255, 0.22);
    color: #d8b8f2;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.8px;
    white-space: nowrap;
}

.enroll-table th:first-child {
    border-radius: 9px 0 0 0;
}

.enroll-table th:last-child {
    border-radius: 0 9px 0 0;
}

.enroll-table td {
    padding: 15px 13px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.07);
    color: rgba(255, 255, 255, 0.9);
    vertical-align: middle;
    line-height: 1.5;
}

.enroll-table tbody tr {
    transition: background 0.18s ease;
}

.enroll-table tbody tr:hover {
    background: rgba(190, 130, 255, 0.09);
}

.enroll-table tbody tr:last-child td {
    border-bottom: none;
}

.enroll-table tr.is-editing td {
    background: rgba(177, 119, 255, 0.15);
}

.btn-enroll {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 34px;
    padding: 7px 14px;
    border-radius: 8px;
    border: 1px solid rgba(216, 184, 242, 0.3);
    background: rgba(160, 105, 207, 0.16);
    color: #fff;
    font-size: 12px;
    font-weight: 650;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.btn-enroll:hover {
    background: rgba(177, 119, 255, 0.28);
    border-color: #c99aff;
    transform: translateY(-1px);
}

.btn-enroll:focus-visible {
    outline: 2px solid #d9b5ff;
    outline-offset: 3px;
}

.btn-approve {
    background: #277b58;
    border-color: #3da879;
    color: #fff;
}

.btn-approve:hover {
    background: #32976b;
    border-color: #63d4a0;
}

.btn-danger {
    background: rgba(190, 65, 82, 0.12);
    border-color: rgba(255, 112, 130, 0.5);
    color: #ff9eaa;
}

.btn-danger:hover {
    background: #a83e52;
    border-color: #ff8797;
    color: #fff;
}

.btn-edit {
    background: rgba(218, 164, 65, 0.12);
    border-color: rgba(255, 204, 112, 0.45);
    color: #ffd27e;
}

.btn-edit:hover {
    background: #9a702e;
    color: #fff;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 11px;
    border: 1px solid rgba(73, 207, 148, 0.45);
    border-radius: 20px;
    background: rgba(46, 180, 120, 0.12);
    color: #7ce5b5;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.status-badge.closed {
    border-color: rgba(255, 112, 130, 0.45);
    background: rgba(190, 65, 82, 0.12);
    color: #ff9eaa;
}

.locked-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px !important;
    border: 1px solid rgba(255, 112, 130, 0.35) !important;
    border-radius: 20px !important;
    background: rgba(190, 65, 82, 0.1) !important;
    color: #ffabb5 !important;
    font-size: 11px !important;
    font-weight: 650;
}

.enroll-status {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.form-group {
    margin-bottom: 16px;
    min-width: 0;
}

.form-group label {
    display: block;
    color: #dfc8f2;
    margin-bottom: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.7px;
}

.form-control {
    display: block;
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    box-sizing: border-box;
    border: 1px solid rgba(220, 185, 255, 0.24);
    border-radius: 9px;
    background: rgba(14, 5, 24, 0.42);
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-control:focus {
    border-color: #bd85ed;
    box-shadow: 0 0 0 3px rgba(189, 133, 237, 0.13);
}

.form-control option {
    background: #291638;
    color: #fff;
}

.form-control::placeholder {
    color: rgba(255, 255, 255, 0.4);
}

.enroll-table .is-editing {
    background: rgba(177, 119, 255, 0.12);
}

#subject-form {
    scroll-margin-top: 20px;
}

#sf-cancel {
    margin-top: 8px;
}

.enrollment-page .popup {
    width: fit-content;
    max-width: min(420px, 100%);
    min-height: unset;
    padding: 10px 16px !important;
    margin: 0 0 14px auto;
    border: 1px solid rgba(120, 255, 180, 0.3);
    border-radius: 10px;
    background: rgba(25, 170, 90, 0.16) !important;
    color: #a8ffd0 !important;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.12);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

@media (max-width: 1100px) {
    .enroll-grid,
    .subject-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .enrollment-page {
        padding: 16px !important;
    }

    .enroll-card {
        padding: 17px !important;
        border-radius: 13px !important;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 5px;
    }

    .enrollment-page h1 {
        font-size: 25px;
    }

    .enrollment-page h2 {
        font-size: 17px !important;
    }

    .enroll-grid {
        gap: 15px;
    }
}
  </style>
</head>
<body data-page="ENROLLMENT">

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>

  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="column scrollbar enrollment-page" style="padding: 30px;">

        <?php if ($flash): ?>
            <div class="popup <?= $flash['type'] === 'error' ? 'error' : '' ?>" style="position: relative; top: 0; right: 0; margin-bottom: 15px; display: inline-block; animation: none;">
                <?php foreach ($flash['msgs'] as $m): ?><p><?= enroll_e($m) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="popup error" style="position: relative; top: 0; right: 0; margin-bottom: 15px; display: inline-block; animation: none;">
                <p><?= enroll_e($error) ?></p>
            </div>
        <?php endif; ?>

        <?php /* ===================== STUDENT VIEW ===================== */ ?>
        <?php if ($group === 'student'): ?>
            <div class="row" style="justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                <div>
                    <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal;">Enroll in Subjects</h1>
                    <?php if ($term): ?>
                    <p style="color: rgba(255,255,255,0.8); margin-top: 5px; font-size: 14px;">
                        <?= enroll_e($term['acad_year']) ?> — <?= enroll_e(enroll_sem($term['sem'])) ?> &middot; Program: <?= enroll_e($logic->studentProgram) ?> &middot; Year: <?= enroll_e($logic->studentYear) ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$term): ?>
                <div class="enroll-card" style="margin-top: 20px;">
                    <p style="color: rgba(255,255,255,0.8); margin: 0;">There is no enrollment period for your program yet.</p>
                </div>
            <?php else: ?>
            <div class="enroll-grid">
                <!-- AVAILABLE SUBJECTS -->
                <div class="enroll-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div>
                            <h2 style="margin: 0;">Available Subjects</h2>
                            <div style="margin-top: 5px; font-size: 14px; color: white;">
                                Enrollment Status: <span class="status-badge <?= $logic->canEdit ? '' : 'closed' ?>"><?= $logic->canEdit ? 'Open' : 'Closed' ?></span>
                            </div>
                        </div>
                        <span style="border: 1px solid #a96add; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Limit: <?= EnrollmentLogic::UNIT_LIMIT ?> units</span>
                    </div>

                    <div class="table-responsive">
                        <table class="enroll-table">
                            <thead><tr><th>CODE</th><th>SUBJECT</th><th>UNITS</th><th>YEAR</th><th>PRE-REQ</th><th>ACTION</th></tr></thead>
                            <tbody>
                                <?php if (empty($logic->available)): ?>
                                    <tr><td colspan="6" style="text-align:center; color: rgba(255,255,255,0.6);">No subjects available for your program, year and semester.</td></tr>
                                <?php endif; ?>
                                <?php $pickedIds = array_map('trim', $logic->getPickedIds()); ?>
                                <?php foreach ($logic->available as $s):
                                    $already = in_array(trim($s['subjectID']), $pickedIds, true); ?>
                                    <tr>
                                        <td><?= enroll_e($s['subjectID']) ?></td>
                                        <td><?= enroll_e($s['subjectName']) ?></td>
                                        <td><?= (int)$s['unit'] ?></td>
                                        <td><?= enroll_e(EnrollmentLogic::ordinal($s['year'])) ?> Year</td>
                                        <td><?= $s['prerequisite'] !== '' ? enroll_e($s['prerequisite']) : '—' ?></td>
                                        <td>
                                            <?php if ($already): ?>
                                                <span style="color: #2ecc71; font-weight: bold; font-size:12px;">Added</span>
                                            <?php elseif (!$logic->canEdit): ?>
                                                <span class="locked-badge" style="padding: 2px 8px; font-size:11px;">locked</span>
                                            <?php elseif ($s['blocked']): ?>
                                                <span class="locked-badge" style="padding: 2px 8px; font-size:11px; border-color:#ff6b6b; color:#ff6b6b;" title="Missing: <?= enroll_e(implode(', ', $s['missing'])) ?>">blocked</span>
                                            <?php else: ?>
                                                <form method="POST" action="<?= $formAction ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                    <input type="hidden" name="enroll_action" value="add">
                                                    <input type="hidden" name="subjectID" value="<?= enroll_e($s['subjectID']) ?>">
                                                    <button class="btn-enroll" style="padding: 2px 10px; font-size: 11px; border-radius: 12px;">+ add</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- YOUR SELECTION -->
                <div class="enroll-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">Your Selection</h2>
                        <span style="border: 1px solid #a96add; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;"><?= (int)$logic->totalUnits ?> / <?= EnrollmentLogic::UNIT_LIMIT ?> units</span>
                    </div>

                    <div class="table-responsive">
                        <table class="enroll-table">
                            <thead><tr><th>CODE</th><th>UNITS</th><th>ACTION</th></tr></thead>
                            <tbody>
                                <?php if (empty($logic->picked)): ?>
                                    <tr><td colspan="3" style="text-align:center; padding: 20px; color: rgba(255,255,255,0.6);">No subjects selected.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($logic->picked as $p): ?>
                                    <tr>
                                        <td><?= enroll_e($p['subjectID']) ?></td>
                                        <td><?= (int)$p['unit'] ?></td>
                                        <td>
                                            <?php if ($logic->canEdit && in_array($p['status'], ['pending', 'rejected'], true)): ?>
                                                <form method="POST" action="<?= $formAction ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                    <input type="hidden" name="enroll_action" value="remove">
                                                    <input type="hidden" name="subjectID" value="<?= enroll_e($p['subjectID']) ?>">
                                                    <button class="btn-enroll btn-danger" style="padding: 2px 8px; font-size: 11px; border-radius: 50%;">&times;</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ENROLLMENT STATUS -->
            <div class="enroll-card" style="margin-top: 20px;">
                <h2 style="margin-bottom:15px;">My Enrollment Status</h2>
                <div class="table-responsive">
                <table class="enroll-table">
                    <thead><tr><th>CODE</th><th>SUBJECT</th><th>STATUS</th><th>REMARKS</th></tr></thead>
                    <tbody>
                        <?php if (empty($logic->picked)): ?>
                            <tr><td colspan="4" style="text-align:center; color: rgba(255,255,255,0.6);">No subjects pending or enrolled.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logic->picked as $p): ?>
                            <tr>
                                <td><?= enroll_e($p['subjectID']) ?></td>
                                <td><?= enroll_e($p['subjectName']) ?></td>
                                <td><?= enroll_badge($p['status']) ?></td>
                                <td><?= $p['status'] === 'rejected' ? enroll_e($p['rejection_remarks']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>


        <?php /* ===================== ADVISER VIEW ===================== */ ?>
        <?php if ($group === 'faculty'): ?>
            <?php if ($logic->selectedStudent):
                $rv = $logic->selectedStudent;
                $rvID = (int)$rv['studentID'];
                $rvEnroll = (int)($_GET['enroll_id'] ?? 0);
                $hasPending = false;
                foreach ($logic->studentSubjects as $ss) { if ($ss['status'] === 'pending') { $hasPending = true; break; } }
            ?>
                <a href="<?= enroll_e(enroll_url([])) ?>" class="btn-enroll" style="margin-bottom: 20px; font-size:12px; padding: 4px 10px;">← Back</a>
                <div style="margin-bottom: 20px;">
                    <h1 style="margin: 0; color: #fff; font-weight: normal; font-size: 28px;">Review — <?= enroll_e($rv['first_name'] . ' ' . $rv['last_name']) ?></h1>
                    <p style="color: rgba(255,255,255,0.7); margin-top: 5px; font-size: 13px;">Student ID: <?= $rvID ?></p>
                </div>

                <div class="enroll-card" style="margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="margin:0;">Enrollment window</h2>
                        <span class="status-badge <?= $logic->isWindowOpen ? '' : 'closed' ?>"><?= $logic->isWindowOpen ? 'Open' : 'Closed' ?></span>
                    </div>
                    <form method="POST" action="<?= $formAction ?>" style="margin-top: 15px;">
                        <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                        <input type="hidden" name="enroll_action" value="toggle_student">
                        <input type="hidden" name="student_id" value="<?= $rvID ?>">
                        <input type="hidden" name="enroll_id" value="<?= $rvEnroll ?>">
                        <button class="btn-enroll" style="font-size: 12px; padding: 6px 12px;">🔒 <?= $logic->isWindowOpen ? 'Close Window' : 'Open Window' ?></button>
                    </form>
                </div>

                <div class="enroll-card">
                    <h2>Subjects Picked (<?= count($logic->studentSubjects) ?>)</h2>
                    <div class="table-responsive">
                    <table class="enroll-table">
                        <thead><tr><th>CODE</th><th>SUBJECT</th><th>UNITS</th><th>STATUS</th><th>REMARKS</th></tr></thead>
                        <tbody>
                            <?php foreach ($logic->studentSubjects as $s): ?>
                                <tr>
                                    <td><?= enroll_e($s['subjectID']) ?></td>
                                    <td><?= enroll_e($s['subjectName']) ?></td>
                                    <td><?= (int)$s['unit'] ?></td>
                                    <td><?= enroll_badge($s['status']) ?></td>
                                    <td><?= $s['status'] === 'rejected' ? enroll_e($s['rejection_remarks']) : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($hasPending): ?>
                    <div style="margin-top: 20px; display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
                        <form method="POST" action="<?= $formAction ?>">
                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                            <input type="hidden" name="enroll_action" value="approve">
                            <input type="hidden" name="student_id" value="<?= $rvID ?>">
                            <input type="hidden" name="enroll_id" value="<?= $rvEnroll ?>">
                            <button class="btn-enroll btn-approve">✅ Approve</button>
                        </form>

                        <form method="POST" action="<?= $formAction ?>" style="flex: 1; min-width: 260px;" onsubmit="return confirm('Reject this enrollment request?');">
                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                            <input type="hidden" name="enroll_action" value="reject">
                            <input type="hidden" name="student_id" value="<?= $rvID ?>">
                            <input type="hidden" name="enroll_id" value="<?= $rvEnroll ?>">
                            <div class="form-group">
                                <label>REJECTION REMARKS</label>
                                <textarea name="remarks" class="form-control" rows="3" placeholder="Reason for rejection" required></textarea>
                            </div>
                            <button class="btn-enroll btn-danger">❌ Reject</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal;">My Advisees</h1>

                <div class="enroll-card" style="margin-top: 20px;">
                    <h2>My Advisees</h2>
                    <p style="color: rgba(255,255,255,0.7); font-size: 13px; margin-top: -15px; margin-bottom: 20px;">All students assigned to you as their Adviser</p>
                    <div class="table-responsive">
                        <table class="enroll-table">
                            <thead><tr><th>STUDENT</th><th>PROGRAM</th><th>YEAR</th><th>PERIOD</th><th>SUBJECTS</th><th>ACTION</th></tr></thead>
                            <tbody>
                                <?php if (empty($logic->pendingStudents)): ?><tr><td colspan="6" style="text-align:center;">No students are currently assigned to you.</td></tr><?php endif; ?>
                                <?php foreach ($logic->pendingStudents as $p): ?>
                                    <tr>
                                        <td><?= enroll_e($p['last_name'] . ', ' . $p['first_name']) ?></td>
                                        <td><?= enroll_e($p['program']) ?></td>
                                        <td><?= enroll_e(EnrollmentLogic::ordinal($p['year_level'])) ?> Year</td>
                                        <td><?= !empty($p['acad_year']) ? enroll_e($p['acad_year']) . ' — ' . enroll_e(enroll_sem($p['sem'])) : 'No period yet' ?></td>
                                        <td><?= (int)$p['subject_count'] ?> Pending</td>
                                        <td>
                                            <?php if ((int)$p['enrollmentID'] > 0): ?>
                                                <a href="<?= enroll_e(enroll_url(['review_student' => $p['studentID'], 'enroll_id' => $p['enrollmentID']])) ?>" class="btn-enroll" style="padding: 2px 12px; font-size: 11px; border-radius: 12px;">View</a>
                                            <?php else: ?>
                                                <span class="locked-badge">No period</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>


        <?php /* ===================== REGISTRAR VIEW ===================== */ ?>
        <?php if ($group === 'manager'): ?>
            <h1 id="periods-section" style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal; margin-bottom: 20px;">Enrollment Periods</h1>

            <div class="enroll-card" style="margin-bottom: 20px;">
                <h2>Create New Period</h2>
                <form method="POST" action="<?= $formAction ?>">
                    <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                    <input type="hidden" name="enroll_action" value="create_period">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>PROGRAM</label>
                            <select name="program" class="form-control" required>
                                <?php foreach ($logic->available as $prog): ?>
                                    <option value="<?= enroll_e($prog['program']) ?>"><?= enroll_e($prog['program']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ACADEMIC YEAR</label>
                            <input type="text" name="acad_year" class="form-control" placeholder="e.g. 2026-2027" required>
                        </div>
                        <div class="form-group">
                            <label>SEMESTER</label>
                            <select name="sem" class="form-control" required>
                                <option value="1">1st Semester</option>
                                <option value="2">2nd Semester</option>
                                <option value="3">Summer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>OPEN DATE</label>
                            <input type="datetime-local" name="open_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>CLOSE DATE</label>
                            <input type="datetime-local" name="close_date" class="form-control" required>
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn-enroll btn-approve" style="width:100%;">Create Period</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="enroll-card" style="margin-bottom: 30px;">
                <h2>Existing Periods</h2>
                <div class="table-responsive">
                    <table class="enroll-table">
                        <thead><tr><th>PROGRAM</th><th>YEAR</th><th>SEMESTER</th><th>OPEN DATE</th><th>CLOSE DATE</th><th>STATUS</th></tr></thead>
                        <tbody>
                            <?php if (empty($logic->allPeriods)): ?>
                                <tr><td colspan="6" style="text-align:center; color: rgba(255,255,255,0.6);">No enrollment periods yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($logic->allPeriods as $p): $isOpen = strtolower($p['status']) === 'open'; ?>
                                <tr>
                                    <td><?= enroll_e($p['program']) ?></td>
                                    <td><?= enroll_e($p['acad_year']) ?></td>
                                    <td><?= enroll_e(enroll_sem($p['sem'])) ?></td>
                                    <td><?= enroll_e(date('M d, Y h:i A', strtotime($p['open_date']))) ?></td>
                                    <td><?= $p['close_date'] ? enroll_e(date('M d, Y h:i A', strtotime($p['close_date']))) : '—' ?></td>
                                    <td>
                                        <span class="status-badge <?= $isOpen ? '' : 'closed' ?>"><?= enroll_e(ucfirst($p['status'])) ?></span>
                                        <form method="POST" action="<?= $formAction ?>" style="display:inline; margin-left:8px;">
                                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                            <input type="hidden" name="enroll_action" value="toggle_period">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$p['enrollmentID'] ?>">
                                            <button type="submit" class="btn-enroll <?= $isOpen ? 'btn-danger' : 'btn-approve' ?>" style="padding: 4px 10px; font-size:12px;">
                                                <?= $isOpen ? 'Close' : 'Open' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <h1 id="subjects-section" style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal; margin-bottom: 20px;">Master Subject List</h1>
            <p style="color: rgba(255,255,255,0.8); margin-top: -15px; margin-bottom: 20px; font-size: 14px;">Manage the subjects for the school catalog.</p>

            <div class="subject-grid">
                <!-- ADD / EDIT FORM (edit is filled in by JavaScript; no page reload) -->
                <div class="enroll-card" id="subject-form-card">
                    <h2 id="subject-form-title">Add New Subject</h2>

                    <form method="POST" action="<?= $formAction ?>" id="subject-form">
                        <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                        <input type="hidden" name="enroll_action" id="sf-action" value="add_subject">
                        <input type="hidden" name="syllabus_id" id="sf-syllabus" value="">

                        <div class="form-group">
                            <label>SUBJECT CODE</label>
                            <input type="text" name="subjectID" id="sf-code" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>SUBJECT NAME</label>
                            <input type="text" name="subjectName" id="sf-name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>ASSIGNED PROGRAM</label>
                            <select name="program" id="sf-program" class="form-control" required>
                                <?php foreach ($logic->available as $prog): ?>
                                    <option value="<?= enroll_e($prog['program']) ?>"><?= enroll_e($prog['program']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>UNITS</label>
                            <input type="number" name="unit" id="sf-unit" class="form-control" min="1" max="5" value="3" required>
                        </div>
                        <div class="form-group">
                            <label>YEAR LEVEL</label>
                            <select name="year" id="sf-year" class="form-control" required>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>SEMESTER</label>
                            <select name="sem" id="sf-sem" class="form-control" required>
                                <option value="1">1st Semester</option>
                                <option value="2">2nd Semester</option>
                            </select>
                        </div>
                        <button type="submit" id="sf-submit" class="btn-enroll btn-approve" style="width: 100%;">Add Subject</button>
                        <button type="button" id="sf-cancel" class="btn-enroll" style="width: 100%; margin-top: 10px; display: none;">Cancel Edit</button>
                    </form>
                </div>

                <!-- TABLE -->
                <div class="enroll-card">
                    <h2>Existing Subjects</h2>
                    <div class="table-responsive">
                        <table class="enroll-table">
                            <thead><tr><th>CODE</th><th>SUBJECT</th><th>PROGRAM</th><th>YEAR</th><th>SEM</th><th>UNITS</th><th>ACTION</th></tr></thead>
                            <tbody>
                                <?php if (empty($logic->allSubjects)): ?>
                                    <tr><td colspan="7" style="text-align:center; color: rgba(255,255,255,0.6);">No subjects yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($logic->allSubjects as $s): ?>
                                    <tr data-row="<?= (int)$s['id'] ?>">
                                        <td><?= enroll_e($s['subjectID']) ?></td>
                                        <td><?= enroll_e($s['name']) ?></td>
                                        <td><?= enroll_e($s['program']) ?></td>
                                        <td><?= (int)$s['year'] ?></td>
                                        <td><?= (int)$s['sem_available'] ?></td>
                                        <td><?= (int)$s['unit'] ?></td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <button type="button" class="btn-enroll btn-edit js-edit-subject" style="padding: 4px 10px; font-size:12px;"
                                                    data-id="<?= (int)$s['id'] ?>"
                                                    data-code="<?= enroll_e(trim($s['subjectID'])) ?>"
                                                    data-name="<?= enroll_e($s['name']) ?>"
                                                    data-program="<?= enroll_e($s['program']) ?>"
                                                    data-unit="<?= (int)$s['unit'] ?>"
                                                    data-year="<?= (int)$s['year'] ?>"
                                                    data-sem="<?= (int)$s['sem_available'] ?>">Edit</button>
                                                <form method="POST" action="<?= $formAction ?>" onsubmit="return confirm('Delete this subject?');" style="margin:0;">
                                                    <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                    <input type="hidden" name="enroll_action" value="delete_subject">
                                                    <input type="hidden" name="syllabus_id" value="<?= (int)$s['id'] ?>">
                                                    <button class="btn-enroll btn-danger" style="padding: 4px 10px; font-size:12px;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
            (function () {
                var form = document.getElementById('subject-form');
                if (!form) return;

                var el = {
                    title:   document.getElementById('subject-form-title'),
                    action:  document.getElementById('sf-action'),
                    syllabus:document.getElementById('sf-syllabus'),
                    code:    document.getElementById('sf-code'),
                    name:    document.getElementById('sf-name'),
                    program: document.getElementById('sf-program'),
                    unit:    document.getElementById('sf-unit'),
                    year:    document.getElementById('sf-year'),
                    sem:     document.getElementById('sf-sem'),
                    submit:  document.getElementById('sf-submit'),
                    cancel:  document.getElementById('sf-cancel')
                };

                function clearHighlight() {
                    document.querySelectorAll('tr.is-editing').forEach(function (r) { r.classList.remove('is-editing'); });
                }

                function resetForm() {
                    form.reset();
                    el.title.textContent = 'Add New Subject';
                    el.action.value = 'add_subject';
                    el.syllabus.value = '';
                    el.code.readOnly = false;
                    el.unit.value = 3;
                    el.submit.textContent = 'Add Subject';
                    el.cancel.style.display = 'none';
                    clearHighlight();
                }

                document.querySelectorAll('.js-edit-subject').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var d = btn.dataset;
                        el.title.textContent = 'Edit Subject';
                        el.action.value = 'update_subject';
                        el.syllabus.value = d.id;
                        el.code.value = d.code;
                        el.code.readOnly = true;
                        el.name.value = d.name;
                        el.program.value = d.program;
                        el.unit.value = d.unit;
                        el.year.value = d.year;
                        el.sem.value = d.sem;
                        el.submit.textContent = 'Update Subject';
                        el.cancel.style.display = 'block';

                        clearHighlight();
                        var row = btn.closest('tr');
                        if (row) row.classList.add('is-editing');

                        // Only scroll if the form is not already visible.
                        var r = form.getBoundingClientRect();
                        if (r.top < 0 || r.bottom > window.innerHeight) {
                            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                        el.name.focus({ preventScroll: true });
                    });
                });

                el.cancel.addEventListener('click', resetForm);
            })();
            </script>
        <?php endif; ?>

      </main>
    </div>
  </div>

</body>
</html>