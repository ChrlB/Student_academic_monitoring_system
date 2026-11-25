<?php
require_once __DIR__ . "/../PageLogic/enrollment_logic.php";

$logic = new EnrollmentLogic();
if (isset($_GET['enroll_ajax']) && $_GET['enroll_ajax'] === 'notifications') {
    header('Content-Type: application/json; charset=utf-8');
    if ($logic->group !== 'student' || !$logic->numericID) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
    global $db_connection;
    $pdoAjax = ($db_connection ?? null) instanceof PDO ? $db_connection : new PDO('mysql:host=localhost;port=3306;dbname=ams_db;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $uid = trim((string)($_SESSION['userID'] ?? ''));
    $q = $pdoAjax->prepare("SELECT studentID FROM students WHERE CONCAT(YEAR(date_created), LPAD(studentID,5,'0')) = ? LIMIT 1");
    $q->execute([$uid]); $sid = (int)$q->fetchColumn();
    $q = $pdoAjax->prepare("SELECT COUNT(*) FROM enrollment_notifications WHERE studentID = ? AND is_read = 0");
    $q->execute([$sid]);
    echo json_encode(['unread' => (int)$q->fetchColumn()]);
    exit;
}
$logic->handlePost();
$logic->loadData();

if (!function_exists('enroll_e')) {
    function enroll_e($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('enroll_url')) {
    function enroll_url($q = [], $fragment = '') {
        return EnrollmentLogic::url($q, $fragment);
    }
}

if (!function_exists('enroll_sem')) {
    function enroll_sem($n) {
        $n = (int)$n;
        return $n === 3 ? 'Summer' : EnrollmentLogic::ordinal($n) . ' Semester';
    }
}

if (!function_exists('enroll_badge')) {
    function enroll_badge($status) {
        if ($status === 'approved') $status = 'enrolled';
        $status = strtolower(trim((string)$status));
        $variant = in_array($status, ['pending','enrolled','credited','passed','failed','rejected','finished','awaiting_grade_verification'], true) ? $status : 'neutral';
        $label = $status === 'awaiting_grade_verification' ? 'Awaiting Grade Verification' : ucfirst($status);
        return '<span class="enrollment-result result-' . $variant . '">' . enroll_e($label) . '</span>';
    }
}

$formAction = enroll_e(EnrollmentLogic::url());

$group = $logic->group;
$term = $logic->term;
$error = $logic->error;
$flash = $logic->takeFlash();
$csrf = $logic->csrf();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMS - Enrollment</title>
<link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/enrollment.css">
</head>

<body data-page="ENROLLMENT">

    <?php require_once __DIR__ . "/components/popup_message.php"; ?>

    <div class="row main_box">
        <?php require_once __DIR__ . "/components/navigation.php"; ?>

        <div class="column enrollment-shell" style="flex: 1; min-width:0; min-height:0;">
            <?php require_once __DIR__ . "/components/header.php"; ?>

            <main class="column scrollbar enrollment-page" data-enrollment-role="<?= enroll_e($group) ?>">

                <?php if ($flash): ?>
                    <div class="popup <?= $flash['type'] === 'error' ? 'error' : '' ?>">
                        <?php foreach ($flash['msgs'] as $m): ?>
                            <p><?= enroll_e($m) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="popup error">
                        <p><?= enroll_e($error) ?></p>
                    </div>
                <?php endif; ?>


                <?php if ($group === 'student'): ?>
                    <div id="enrollment-toast-area" role="status" aria-live="polite" style="position:fixed;right:22px;top:80px;z-index:9999;display:grid;gap:10px;max-width:min(370px,calc(100vw - 44px));pointer-events:none"></div>
                    <script>
                    (function(){
                      const area=document.getElementById('enrollment-toast-area');
                      const termId=<?= json_encode($term ? (int)$term['enrollmentID'] : 0) ?>;
                      const isOpen=<?= json_encode((bool)($term && strtolower((string)$term['status'])==='open')) ?>;
                      const items=<?= json_encode(array_values(array_map(static function($n){return ['id'=>(int)$n['notification_id'],'type'=>(string)$n['notification_type'],'message'=>(string)$n['message']];}, array_filter($logic->notifications,static function($n){return !(int)$n['is_read'];}))),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                      function toast(message){const el=document.createElement('div');el.textContent=message;el.style.cssText='pointer-events:auto;padding:15px 19px;border-radius:12px;background:#442454;color:white;border:1px solid #b88bd0;box-shadow:0 8px 25px #0005;font-size:14px';area.appendChild(el);setTimeout(()=>el.remove(),6500);}
                      try{const key='ams-enrollment-open-'+termId;if(isOpen&&termId&&!sessionStorage.getItem(key)){toast('Enrollment period is now open.');sessionStorage.setItem(key,'1');}}catch(e){}
                      for(const n of items){if(/approv|reject|credit/i.test(n.type+' '+n.message)){const key='ams-enroll-notif-'+n.id;try{if(sessionStorage.getItem(key))continue;sessionStorage.setItem(key,'1');}catch(e){}toast(n.message);}}
                    })();
                    </script>

                    <div class="row enrollment-hero" style="justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <div>
                            <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal;">
                                Enroll in Subjects
                            </h1>

                            <?php if ($term): ?>
                                <p style="color: rgba(255,255,255,0.8); margin-top: 5px; font-size: 14px;">
                                    <?= enroll_e($term['acad_year']) ?>
                                    — <?= enroll_e(enroll_sem($term['sem'])) ?>
                                    &middot; Program: <?= enroll_e($logic->studentProgram) ?>
                                    &middot; Year: <?= enroll_e($logic->studentYear) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$term): ?>

                        <div class="enroll-card" style="margin-top: 20px;">
                            <p style="color: rgba(255,255,255,0.8); margin: 0;">
                                There is no enrollment period for your program yet.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="enroll-grid">

                            <div class="enroll-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <div>
                                        <h2 style="margin: 0;">Available Subjects</h2>

                                        <div style="margin-top: 5px; font-size: 14px; color: white;">
                                            Enrollment Status:
                                            <span class="status-badge <?= $logic->canEdit ? '' : 'closed' ?>">
                                                <?= $logic->canEdit ? 'Open' : 'Closed' ?>
                                            </span>
                                        </div>
                                    </div>

                                    <span style="border: 1px solid #a96add; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                                        Limit: <?= EnrollmentLogic::UNIT_LIMIT ?> units
                                    </span>
                                </div>

                                <div class="table-responsive selection-table-wrap">
                                    <table class="enroll-table selection-table">
                                        <thead>
                                            <tr>
                                                <th>CODE</th>
                                                <th>SUBJECT</th>
                                                <th>UNITS</th>
                                                <th>YEAR</th>
                                                <th>PRE-REQ</th>
                                                <th>ACTION</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php if (empty($logic->available)): ?>
                                                <tr>
                                                    <td colspan="6" style="text-align:center; color: rgba(255,255,255,0.6);">
                                                        No subjects available for your program, year and semester.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>

                                            <?php $pickedIds = array_map('trim', $logic->getPickedIds()); ?>

                                            <?php foreach ($logic->available as $s):
                                                $already = in_array(trim($s['subjectID']), $pickedIds, true);
                                            ?>
                                                <tr>
                                                    <td><?= enroll_e($s['subjectID']) ?></td>
                                                    <td><?= enroll_e($s['subjectName']) ?></td>
                                                    <td><?= (int)$s['unit'] ?></td>
                                                    <td><?= enroll_e(EnrollmentLogic::ordinal($s['year'])) ?> Year</td>
                                                    <td><?= $s['prerequisite'] !== '' ? enroll_e($s['prerequisite']) : '—' ?></td>

                                                    <td>
                                                        <?php $finished = !empty($s['finished']); if ($finished): ?>
                                                            <span style="color:#2ecc71;font-weight:bold;font-size:12px;">Finished / Credited</span>
                                                        <?php elseif ($already): ?>
                                                            <span style="color: #2ecc71; font-weight: bold; font-size:12px;">Added</span>
                                                        <?php elseif (!$logic->canEdit): ?>

                                                            <span class="locked-badge">
                                                                locked
                                                            </span>

                                                        <?php elseif ($s['blocked']): ?>

                                                            <span
                                                                class="locked-badge"
                                                                title="Missing: <?= enroll_e(implode(', ', $s['missing'])) ?>">
                                                                blocked
                                                            </span>

                                                        <?php else: ?>

                                                            <form method="POST" action="<?= $formAction ?>" style="display:inline;">
                                                                <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                                <input type="hidden" name="enroll_action" value="add">
                                                                <input type="hidden" name="subjectID" value="<?= enroll_e($s['subjectID']) ?>">
                                                                <?php if (strtolower($logic->studentType) === 'irregular'): ?>
                                                                    <select name="schedID" required class="form-control" style="max-width:180px; display:inline-block; margin-right:5px;">
                                                                        <option value="">Choose schedule</option>
                                                                        <?php foreach (($s['schedules'] ?? []) as $sc): ?>
                                                                            <option value="<?= (int)$sc['schedID'] ?>"><?= enroll_e($sc['block'] . ' | ' . $sc['day'] . ' | ' . $sc['time_start'] . '-' . $sc['time_end']) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php endif; ?>

                                                                <button class="btn-enroll" style="padding: 2px 10px; font-size: 11px; border-radius: 12px;">
                                                                    + add
                                                                </button>
                                                            </form>

                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>


                            <div class="enroll-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h2 style="margin: 0;">Your Selection</h2>

                                    <span style="border: 1px solid #a96add; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                                        <?= (int)$logic->totalUnits ?> / <?= EnrollmentLogic::UNIT_LIMIT ?> units
                                    </span>
                                </div>

                                <div class="table-responsive selection-picked-wrap">
                                    <table class="enroll-table selection-picked-table">
                                        <thead>
                                            <tr>
                                                <th>CODE</th>
                                                <th>UNITS</th>
                                                <th>ACTION</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php if (empty($logic->picked)): ?>
                                                <tr>
                                                    <td colspan="3" style="text-align:center; padding: 20px; color: rgba(255,255,255,0.6);">
                                                        No subjects selected.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>

                                            <?php foreach ($logic->picked as $p): ?>
                                                <tr>
                                                    <td><?= enroll_e($p['subjectID']) ?></td>
                                                    <td><?= (int)$p['unit'] ?></td>

                                                    <td>
                                                        <?php if (trim((string)$p['status']) === 'pending'): ?>
                                                            <form method="POST" action="<?= $formAction ?>" style="display:inline;">
                                                                <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                                <input type="hidden" name="enroll_action" value="remove">
                                                                <input type="hidden" name="subjectID" value="<?= enroll_e($p['subjectID']) ?>">

                                                                <button type="submit" title="Remove pending selection" aria-label="Remove pending selection" class="btn-enroll btn-danger" style="padding: 5px 11px; font-size: 13px; border-radius: 8px; cursor:pointer;">
                                                                    &times;
                                                                </button>
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


                        <div class="enroll-card" style="margin-top: 20px;">
                            <h2 style="margin-bottom:15px;">My Enrollment Status</h2>

                            <div class="table-responsive">
                                <table class="enroll-table">
                                    <thead>
                                        <tr>
                                            <th>CODE</th>
                                            <th>SUBJECT</th>
                                            <th>STATUS</th>
                                            <th>REMARKS</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if (empty($logic->picked)): ?>
                                            <tr>
                                                <td colspan="4" style="text-align:center; color: rgba(255,255,255,0.6);">
                                                    No subjects pending or enrolled.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($logic->picked as $p): ?>
                                            <tr>
                                                <td><?= enroll_e($p['subjectID']) ?></td>
                                                <td><?= enroll_e($p['subjectName']) ?></td>
                                                <td><?= enroll_badge($p['status']) ?></td>
                                                <td>
                                                    <?= $p['status'] === 'rejected' ? enroll_e($p['rejection_remarks']) : '' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>


                    <?php if ($term): ?>
                    <?php if (strtolower(trim($logic->studentType)) !== 'regular'): ?>
                    <div class="enroll-card" style="margin-top:20px;">
                        <h2>Request Subject Credit</h2>
                        <form method="POST" action="<?= $formAction ?>">
                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                            <input type="hidden" name="enroll_action" value="request_credit">
                            <label>Subject</label>
                            <select name="subjectID" class="form-control" required>
                                <option value="">Select previously completed subject</option>
                                <?php foreach ($logic->prerequisiteOptions as $subject): ?>
                                    <option value="<?= enroll_e($subject['subjectID']) ?>"><?= enroll_e($subject['subjectID'].' - '.$subject['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Supporting details</label>
                            <textarea name="supporting_remarks" class="form-control" rows="3" placeholder="Add details about your previous subject or school."></textarea>
                            <button class="btn-enroll" type="submit">Submit Credit Request</button>
                        </form>
                    </div>
                    <?php endif; ?>
                    <div class="enroll-card" style="margin-top:20px;">
                        <h2>Enrollment History</h2>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <select id="history-period-filter" class="form-control" aria-label="Filter enrollment history by academic period">
                                <option value="all">All academic periods</option>
                                <?php foreach ($logic->historyPeriods as $hp): ?>
                                    <option value="<?= (int)$hp['enrollmentID'] ?>"><?= enroll_e($hp['acad_year'].' - '.enroll_sem($hp['sem'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="table-responsive"><table class="enroll-table" id="enrollment-history-table">
                            <thead><tr><th>AY / SEM</th><th>CODE</th><th>SUBJECT</th><th>UNITS</th><th>BLOCK</th><th>STATUS</th><th>REMARKS</th></tr></thead>
                            <tbody>
                            <?php foreach ($logic->historyRows as $h): ?>
                                <tr data-enrollment-id="<?= (int)$h['enrollmentID'] ?>"><td><?= enroll_e($h['acad_year'].' / '.enroll_sem($h['sem'])) ?></td><td><?= enroll_e($h['subjectID']) ?></td><td><?= enroll_e($h['subjectName']) ?></td><td><?= (int)$h['unit'] ?></td><td><?= enroll_e($h['block'] ?? '—') ?></td><td><?= enroll_badge($h['status']) ?></td><td><?= enroll_e($h['rejection_remarks'] ?? '') ?></td></tr>
                            <?php endforeach; ?>
                            <tr id="history-empty-row" <?= $logic->historyRows ? 'hidden' : '' ?>><td colspan="7">No enrollment history found for this academic period.</td></tr>
                            </tbody>
                        </table></div>
                        <script>
                        (function(){
                            const filter=document.getElementById('history-period-filter');
                            const table=document.getElementById('enrollment-history-table');
                            if(!filter||!table)return;
                            const rows=Array.from(table.querySelectorAll('tbody tr[data-enrollment-id]'));
                            const empty=document.getElementById('history-empty-row');
                            function applyHistoryFilter(){
                                const selected=filter.value;
                                let visible=0;
                                rows.forEach(row=>{const show=selected==='all'||row.dataset.enrollmentId===selected;row.hidden=!show;if(show)visible++;});
                                if(empty)empty.hidden=visible!==0;
                            }
                            filter.addEventListener('change',applyHistoryFilter);
                            applyHistoryFilter();
                        })();
                        </script>
                    </div>

                    <?php endif; ?>

                    <?php endif; ?>

                <?php endif; ?>


                <?php if ($group === 'coordinator'): ?>

                    <?php if ($logic->selectedStudent):

                        $rv = $logic->selectedStudent;
                        $rvID = (int)$rv['studentID'];
                        $rvEnroll = (int)($_GET['enroll_id'] ?? 0);
                        $hasPending = false;

                        foreach ($logic->studentSubjects as $ss) {
                            if ($ss['status'] === 'pending') {
                                $hasPending = true;
                                break;
                            }
                        }
                    ?>

                        <div class="review-header">
                            <a href="<?= enroll_e(enroll_url([])) ?>" class="btn-enroll">
                                ← Back
                            </a>

                            <div>
                                <h1 style="margin: 0; color: #fff; font-weight: normal; font-size: 28px;">
                                    Review — <?= enroll_e($rv['first_name'] . ' ' . $rv['last_name']) ?>
                                </h1>

                                <p style="color: rgba(255,255,255,0.7); margin-top: 5px; font-size: 13px;">
                                    Student ID: <?= $rvID ?>
                                </p>
                            </div>
                        </div>


                        <div class="enroll-card" style="margin-bottom: 20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <h2 style="margin:0;">Enrollment window</h2>

                                <span class="status-badge <?= $logic->isWindowOpen ? '' : 'closed' ?>">
                                    <?= $logic->isWindowOpen ? 'Open' : 'Closed' ?>
                                </span>
                            </div>

                            <form method="POST" action="<?= $formAction ?>" style="margin-top: 15px;">
                                <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                <input type="hidden" name="enroll_action" value="toggle_student">
                                <input type="hidden" name="student_id" value="<?= $rvID ?>">
                                <input type="hidden" name="enroll_id" value="<?= $rvEnroll ?>">

                                <button class="btn-enroll" style="font-size: 12px; padding: 6px 12px;">
                                    <?= $logic->isWindowOpen ? 'Close Window' : 'Open Window' ?>
                                </button>
                            </form>
                        </div>


                        <div class="enroll-card">
                            <h2>Subjects Picked (<?= count($logic->studentSubjects) ?>)</h2>

                            <div class="table-responsive">
                                <table class="enroll-table">
                                    <thead>
                                        <tr>
                                            <th>CODE</th>
                                            <th>SUBJECT</th>
                                            <th>UNITS</th>
                                            <th>STATUS</th>
                                            <th>REMARKS</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($logic->studentSubjects as $s): ?>
                                            <tr>
                                                <td><?= enroll_e($s['subjectID']) ?></td>
                                                <td><?= enroll_e($s['subjectName']) ?></td>
                                                <td><?= (int)$s['unit'] ?></td>
                                                <td><?= enroll_badge($s['status']) ?></td>
                                                <td>
                                                    <?= $s['status'] === 'rejected' ? enroll_e($s['rejection_remarks']) : '' ?>
                                                </td>
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

                                        <button class="btn-enroll btn-approve">
                                            Approve
                                        </button>
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

                                        <button class="btn-enroll btn-danger">
                                            Reject
                                        </button>
                                    </form>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php else: ?>

                        <div class="enrollment-hero"><div><h1>Pending Approvals</h1><p>Review student subject selections and manage enrollment decisions.</p></div></div>

                        <div class="enroll-card" style="margin-top: 20px;">
                            <h2>My Advisees</h2>

                            <p style="color: rgba(255,255,255,0.7); font-size: 13px; margin-top: -15px; margin-bottom: 20px;">
                                All students assigned to you as their program coordinator.
                            </p>

                            <div class="table-responsive">
                                <table class="enroll-table">
                                    <thead>
                                        <tr>
                                            <th>STUDENT</th>
                                            <th>PROGRAM</th>
                                            <th>YEAR</th>
                                            <th>PERIOD</th>
                                            <th>SUBJECTS</th>
                                            <th>ACTION</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if (empty($logic->pendingStudents)): ?>
                                            <tr>
                                                <td colspan="6" style="text-align:center;">
                                                    No students are currently assigned to you.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($logic->pendingStudents as $p): ?>
                                            <tr>
                                                <td><?= enroll_e($p['last_name'] . ', ' . $p['first_name']) ?></td>
                                                <td><?= enroll_e($p['program']) ?></td>
                                                <td><?= enroll_e(EnrollmentLogic::ordinal($p['year_level'])) ?> Year</td>

                                                <td>
                                                    <?= !empty($p['acad_year'])
                                                        ? enroll_e($p['acad_year']) . ' — ' . enroll_e(enroll_sem($p['sem']))
                                                        : 'No period yet' ?>
                                                </td>

                                                <td><?= (int)$p['subject_count'] ?> Pending</td>

                                                <td>
                                                    <?php if ((int)$p['enrollmentID'] > 0): ?>
                                                        <a
                                                            href="<?= enroll_e(enroll_url([
                                                                'review_student' => $p['studentID'],
                                                                'enroll_id' => $p['enrollmentID']
                                                            ])) ?>"
                                                            class="btn-enroll"
                                                            style="padding: 2px 12px; font-size: 11px; border-radius: 12px;">
                                                            View
                                                        </a>
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



                <?php if ($group === 'dean'): ?>
                <?php
                    $deanTab = (string)($_GET['dean_tab'] ?? 'overview');
                    if (!in_array($deanTab, ['overview','requests','students','subjects','reports'], true)) $deanTab = 'overview';
                    $deanPeriod = $logic->deanSelectedPeriod;
                    $deanBase = enroll_url(['dean_tab' => null]);
                    $deanRequestPending = (int)($logic->deanRequestStats['pending'] ?? 0);
                ?>
                <section class="dean-stage1">
                    <div class="dean-stage1-heading">
                        <div><h1>Dean Enrollment Management</h1><p>College enrollment oversight. </p></div>
                        <label>Academic period
                            <select id="dean-period-filter" name="dean_period" class="form-control" aria-label="Academic period">
                                <?php foreach ($logic->deanPeriods as $dp): ?>
                                <option value="<?= (int)$dp['enrollmentID'] ?>" <?= $deanPeriod && (int)$deanPeriod['enrollmentID']===(int)$dp['enrollmentID']?'selected':'' ?>>
                                    <?= enroll_e($dp['acad_year'].' · '.enroll_sem($dp['sem']).' · '.$dp['program'].' · '.(!empty($dp['year_level']) ? 'Year '.(int)$dp['year_level'] : 'All years')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <nav class="dean-tabs" aria-label="Dean enrollment sections">
                        <?php foreach (['overview'=>'Overview','requests'=>'Special Requests','students'=>'Students','subjects'=>'Subjects & Sections','reports'=>'Reports'] as $tabKey=>$tabTitle): ?>
                            <a href="<?= enroll_e(enroll_url(['dean_tab'=>$tabKey, 'dean_period'=>$deanPeriod['enrollmentID'] ?? null])) ?>" class="<?= $deanTab===$tabKey?'active':'' ?>"><?= enroll_e($tabTitle) ?><?= $tabKey==='requests' && $deanRequestPending ? ' ('.$deanRequestPending.')' : '' ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <?php if (!$deanPeriod): ?>
                        <div class="enroll-card"><p>No enrollment periods have been created. Statistics will be available when the Registrar creates a period.</p></div>
                    <?php endif; ?>

                    <?php if ($deanTab === 'overview'): ?>
                        <div class="dean-stat-grid">
                            <?php foreach ([
                                ['Enrolled students', $logic->deanStats['enrolled'] ?? 0],
                                ['Students with pending subjects', $logic->deanStats['pending_students'] ?? 0],
                                ['Students with rejected subjects', $logic->deanStats['rejected_students'] ?? 0],
                                ['Pending subject selections', $logic->deanStats['pending_subjects'] ?? 0],
                                ['Pending credit requests', $logic->deanRequestStats['pending'] ?? 0],
                                ['All credit requests', count($logic->creditRequests)]
                            ] as $metric): ?>
                            <div class="enroll-card dean-metric"><span><?= enroll_e($metric[0]) ?></span><strong><?= (int)$metric[1] ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="dean-two-col">
                            <div class="enroll-card">
                                <h2>Enrollment by program</h2>
                                <div class="table-responsive"><table class="enroll-table"><thead><tr><th>Program</th><th>Students with selections</th><th>Enrolled</th></tr></thead><tbody>
                                <?php foreach ($logic->deanPrograms as $r): ?><tr><td><?= enroll_e($r['program']) ?></td><td><?= (int)$r['students'] ?></td><td><?= (int)$r['approved'] ?></td></tr><?php endforeach; ?>
                                <?php if (!$logic->deanPrograms): ?><tr><td colspan="3">No enrollment records.</td></tr><?php endif; ?>
                                </tbody></table></div>
                            </div>
                            <div class="enroll-card">
                                <h2>Year level and student type</h2>
                                <div class="table-responsive"><table class="enroll-table"><thead><tr><th>Year</th><th>Type</th><th>Students</th></tr></thead><tbody>
                                <?php foreach ($logic->deanYears as $r): ?><tr><td><?= (int)$r['year_level'] ?></td><td><?= enroll_e($r['student_type']) ?></td><td><?= (int)$r['students'] ?></td></tr><?php endforeach; ?>
                                <?php if (!$logic->deanYears): ?><tr><td colspan="3">No enrollment records.</td></tr><?php endif; ?>
                                </tbody></table></div>
                            </div>
                        </div>
                    <?php elseif ($deanTab === 'requests'): ?>
                        <div class="enroll-card">
                            <h2>Subject crediting requests</h2>
                            <label>Request status <h2><h2/> <select class="form-control dean-local-filter" data-target="dean-request-table" data-field="status"><option value="">All statuses</option><option value="pending">Pending</option><option value="credited">Credited</option><option value="not_credited">Not credited</option></select></label>
                            <div class="table-responsive"><table id="dean-request-table" class="enroll-table">
                                <thead><tr><th>Student</th><th>Program / Year</th><th>Subject</th><th>Submitted</th><th>Reason</th><th>Status / Decision</th></tr></thead><tbody>
                                <?php foreach ($logic->creditRequests as $cr): ?>
                                <tr data-status="<?= enroll_e($cr['status']) ?>">
                                    <td><?= enroll_e($cr['last_name'].', '.$cr['first_name']) ?><small> #<?= (int)$cr['studentID'] ?></small></td>
                                    <td><?= enroll_e($cr['program']) ?> · Year <?= (int)$cr['year_level'] ?></td>
                                    <td><?= enroll_e(trim($cr['subjectID']).' · '.($cr['subject_name'] ?? '')) ?></td>
                                    <td><?= enroll_e($cr['created_at']) ?></td>
                                    <td><?= enroll_e($cr['supporting_remarks'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($cr['status']==='pending'): ?>
                                        <form method="POST" action="<?= $formAction ?>" onsubmit="return confirm('Confirm this crediting decision?')">
                                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                            <input type="hidden" name="enroll_action" value="review_credit">
                                            <input type="hidden" name="request_id" value="<?= (int)$cr['request_id'] ?>">
                                            <select name="credit_decision" class="form-control" required><option value="">Choose decision</option><option value="credited">Credited</option><option value="not_credited">Not credited</option></select>
                                            <textarea name="dean_remarks" class="form-control" rows="2" placeholder="Remarks"></textarea>
                                            <button class="btn-enroll" type="submit">Save decision</button>
                                        </form>
                                        <?php else: ?>
                                            <?= enroll_badge($cr['status']) ?><p><?= enroll_e($cr['dean_remarks'] ?? '') ?></p>
                                            <small>Reviewer #<?= (int)($cr['reviewed_by'] ?? 0) ?> · <?= enroll_e($cr['reviewed_at'] ?? '') ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$logic->creditRequests): ?><tr><td colspan="6">No credit requests for this period.</td></tr><?php endif; ?>
                                </tbody></table></div>
                        </div>
                    <?php elseif ($deanTab === 'students'): ?>
                        <div class="enroll-card">
                            <h2>Student enrollment monitoring</h2>
                            <div class="dean-filter-row">
                                <input class="form-control dean-local-filter" data-target="dean-student-table" data-field="search" placeholder="Search name, student ID or subject">
                                <select class="form-control dean-local-filter" data-target="dean-student-table" data-field="type"><option value="">All student types</option><option value="regular">Regular</option><option value="irregular">Irregular</option></select>
                                <select class="form-control dean-local-filter" data-target="dean-student-table" data-field="status"><option value="">All statuses</option><option value="pending">Pending</option><option value="enrolled">Enrolled</option><option value="awaiting_grade_verification">Awaiting Grade Verification</option><option value="rejected">Rejected</option><option value="credited">Credited</option><option value="passed">Passed</option><option value="failed">Failed</option></select>
                            </div>
                            <div class="table-responsive"><table id="dean-student-table" class="enroll-table">
                            <thead><tr><th>Student</th><th>Year / Block</th><th>Type</th><th>Subject</th><th>Status</th></tr></thead><tbody>
                            <?php foreach ($logic->deanStudents as $r): ?>
                            <tr data-program="<?= enroll_e($r['program']) ?>" data-type="<?= enroll_e($r['student_type']) ?>" data-status="<?= enroll_e($r['display_status']) ?>">
                                <td><?= enroll_e($r['last_name'].', '.$r['first_name']) ?> · #<?= (int)$r['studentID'] ?></td>
                                <td><?= (int)$r['year_level'] ?> · <?= enroll_e(preg_replace('/^'.(int)$r['year_level'].'(?=[A-Za-z])/', '', trim((string)$r['block']))) ?></td>
                                <td><?= enroll_e($r['student_type']) ?></td>
                                <td><?= enroll_e(trim($r['subjectID']).' · '.($r['subject_name'] ?? '')) ?></td>
                                <td><?= enroll_badge($r['display_status']) ?><?= $r['rejection_remarks'] ? '<small>'.enroll_e($r['rejection_remarks']).'</small>' : '' ?></td>
                            </tr><?php endforeach; ?>
                            <tr id="dean-student-empty" <?= $logic->deanStudents ? 'hidden' : '' ?>><td colspan="5">No student enrollment records match the selected filters.</td></tr>
                            </tbody></table></div>
                        </div>
                    <?php elseif ($deanTab === 'subjects'): ?>
                        <div class="enroll-card">
                            <h2>Subject and section monitoring</h2>
                            <div class="table-responsive"><table class="enroll-table"><thead><tr><th>Subject</th><th>Block</th><th>Day / Time</th><th>Room</th><th>Faculty</th><th>Selected students</th></tr></thead><tbody>
                            <?php foreach ($logic->deanOfferings as $r): ?><tr>
                                <td><?= enroll_e(trim($r['subjectID']).' · '.($r['subject_name'] ?? '')) ?></td>
                                <td><?= enroll_e($r['block']) ?></td>
                                <td><?= enroll_e($r['day'].' · '.$r['time_start'].'–'.$r['time_end']) ?></td>
                                <td><?= enroll_e($r['roomID']) ?></td><td><?= enroll_e($r['faculty_name'] ?? 'Unassigned') ?></td><td><?= (int)$r['demand'] ?></td>
                            </tr><?php endforeach; ?>
                            <?php if (!$logic->deanOfferings): ?><tr><td colspan="6">No scheduled offerings.</td></tr><?php endif; ?>
                            </tbody></table></div>
                        </div>
                    <?php elseif ($deanTab === 'reports'): ?>
                        <div class="dean-two-col">
                            <div class="enroll-card"><h2>Enrollment trends</h2>
                                <div class="table-responsive"><table class="enroll-table"><thead><tr><th>Academic year</th><th>Semester</th><th>Enrolled students</th></tr></thead><tbody>
                                <?php foreach ($logic->deanTrends as $r): ?><tr><td><?= enroll_e($r['acad_year']) ?></td><td><?= enroll_e(enroll_sem($r['sem'])) ?></td><td><?= (int)$r['enrolled'] ?></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                            </div>
                            <div class="enroll-card"><h2>Subject crediting decisions</h2>
                                <div class="table-responsive"><table class="enroll-table"><thead><tr><th>Status</th><th>Requests</th></tr></thead><tbody>
                                <?php foreach ($logic->deanRequestStats as $status=>$n): ?><tr><td><?= enroll_e(ucwords(str_replace('_',' ',$status))) ?></td><td><?= (int)$n ?></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                                <p>Figures reflect recorded selections and decisions, not final official Registrar enrollment totals.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <style>
                    .dean-stage1{max-width:100%;min-width:0;color:#fff;padding-bottom:28px}
                    .dean-stage1-heading{display:flex;justify-content:space-between;gap:16px;align-items:end;flex-wrap:wrap;margin-bottom:18px}
                    .dean-stage1 h1{font-size:clamp(22px,2.4vw,30px);font-weight:650;margin:0 0 5px}
                    .dean-stage1 h2{font-size:19px;margin:0 0 12px}
                    .dean-stage1 p{opacity:.85}
                    .dean-stage1-heading label{min-width:250px;max-width:100%;display:grid;gap:6px;font-size:13px}
                    .dean-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:18px}
                    .dean-tabs a{padding:10px 14px;border:1px solid #ffffff33;border-radius:12px;color:#eee;text-decoration:none;background:#ffffff0b}
                    .dean-tabs a.active{background:#7853bd;color:#fff;border-color:#b6a2ec}
                    .dean-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:18px}
                    .dean-metric{display:flex;flex-direction:column;gap:12px}
                    .dean-metric span{font-size:13px;opacity:.85}
                    .dean-metric strong{font-size:32px}
                    .dean-two-col{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:16px}
                    .dean-stage1 .enroll-card{min-width:0;overflow:hidden}
                    .dean-stage1 .table-responsive{max-width:100%;overflow-x:auto}
                    .dean-stage1 .enroll-table{width:100%;min-width:0}
                    .dean-stage1 .enroll-table td,.dean-stage1 .enroll-table th{overflow-wrap:anywhere;white-space:normal}
                    .dean-filter-row{display:flex;flex-wrap:wrap;gap:10px;margin:15px 0}
                    .dean-filter-row>*{flex:1 1 165px;min-width:0}
                    .dean-stage1 small{display:block;opacity:.7}
                    @media(max-width:650px){.dean-stage1 .enroll-table{font-size:12px}.dean-stage1 .enroll-table td,.dean-stage1 .enroll-table th{padding:7px 5px}}
                </style>
                <script>
                (() => {
                    const periodSelect = document.getElementById('dean-period-filter');
                    if (!periodSelect) return;
                    periodSelect.addEventListener('change', () => {
                        const url = new URL(window.location.href);
                        url.searchParams.set('dean_period', periodSelect.value);
                        url.searchParams.set('dean_tab', <?= json_encode($deanTab) ?>);
                        window.location.href = url.pathname + url.search;
                    });
                })();
                </script>
                <script>
                (() => {
                    const controls = [...document.querySelectorAll('.dean-local-filter')];
                    controls.forEach(control => {
                        const update = () => {
                            const table = document.getElementById(control.dataset.target);
                            if (!table) return;
                            const filters = controls.filter(c => c.dataset.target === control.dataset.target);
                            let visible = 0;
                            table.querySelectorAll('tbody tr[data-status]').forEach(row => {
                                const show = filters.every(f => {
                                    const value = f.value.trim().toLowerCase();
                                    if (!value) return true;
                                    const field = f.dataset.field;
                                    const actual = field === 'search' ? row.textContent : row.dataset[field];
                                    const normalized = String(actual || '').trim().toLowerCase();
                                    return field === 'search' ? normalized.includes(value) : normalized === value;
                                });
                                row.hidden = !show;
                                if (show) visible++;
                            });
                            const empty = table.querySelector('#dean-student-empty');
                            if (empty) empty.hidden = visible !== 0;
                        };
                        control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', update);
                    });
                })();
                </script>
                <?php endif; ?>

                <?php if ($group === 'registrar'): ?>
                    <div class="enrollment-hero">
                        <div><h1>Enrollment Management</h1><p>Manage academic periods, subject catalog and student adviser assignments.</p></div>
                    </div>
                    <nav class="registrar-tabs" aria-label="Registrar enrollment sections">
                        <a href="#periods-section" data-reg-tab="periods">Enrollment Periods</a>
                        <a href="#subjects-section" data-reg-tab="subjects">Subjects</a>
                        <a href="#advisers-section" data-reg-tab="advisers">Faculty Assignment</a>
                    </nav>
                    <div class="registrar-panel" data-reg-panel="periods">


                    <h1 id="periods-section" style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal; margin-bottom: 20px;">
                        Enrollment Periods
                    </h1>

                    <div class="enroll-card" style="margin-bottom: 20px;">
                        <h2 id="period-form-title">Create New Period</h2>
                        <form method="POST" action="<?= $formAction ?>" id="period-form">
                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                            <input type="hidden" name="enroll_action" id="period-action" value="create_period">
                            <input type="hidden" name="enrollment_id" id="period-id" value="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>DEPARTMENT</label>
                                    <select name="department" id="period-department" class="form-control" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($logic->periodDepartments as $department): ?>
                                            <option value="<?= enroll_e($department['department']) ?>"><?= enroll_e($department['department'].' - '.$department['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>PROGRAM</label>
                                    <select name="program" id="period-program" class="form-control" required>
                                        <option value="">Select Program</option>
                                        <?php foreach ($logic->periodPrograms as $prog): ?>
                                            <option value="<?= enroll_e($prog['program']) ?>" data-department="<?= enroll_e($prog['department'] ?? '') ?>"><?= enroll_e($prog['program']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>YEAR LEVEL</label>
                                    <select name="year_level" id="period-year-level" class="form-control" required>
                                        <option value="">Select Year Level</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>ACADEMIC YEAR</label>
                                    <input type="text" name="acad_year" id="period-acad-year" class="form-control" placeholder="e.g. 2026-2027" pattern="[0-9]{4}-[0-9]{4}" title="Use the format YYYY-YYYY" required>
                                </div>
                                <div class="form-group">
                                    <label>SEMESTER</label>
                                    <select name="sem" id="period-sem" class="form-control" required><option value="1">1st Semester</option><option value="2">2nd Semester</option><option value="3">Summer</option></select>
                                </div>
                                <div class="form-group">
                                    <label>OPEN DATE</label>
                                    <input type="datetime-local" name="open_date" id="period-open-date" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>CLOSE DATE</label>
                                    <input type="datetime-local" name="close_date" id="period-close-date" class="form-control" required>
                                </div>
                                <div class="form-group" style="display:flex; align-items:flex-end; gap:10px;">
                                    <button type="submit" id="period-submit" class="btn-enroll btn-approve" style="width:100%;">Create Period</button>
                                    <button type="button" id="period-cancel" class="btn-enroll" style="width:100%; display:none;">Cancel</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="enroll-card" style="margin-bottom: 30px;">
                        <h2>Existing Periods</h2>
                        <div class="table-responsive">
                            <table class="enroll-table">
                                <thead><tr><th>DEPARTMENT</th><th>PROGRAM</th><th>YEAR LEVEL</th><th>ACADEMIC YEAR</th><th>SEMESTER</th><th>OPEN DATE</th><th>CLOSE DATE</th><th>STATUS / ACTION</th></tr></thead>
                                <tbody>
                                    <?php if (empty($logic->allPeriods)): ?><tr><td colspan="8" style="text-align:center; color: rgba(255,255,255,0.6);">No enrollment periods yet.</td></tr><?php endif; ?>
                                    <?php foreach ($logic->allPeriods as $p): $isOpen = strtolower($p['status']) === 'open'; ?>
                                        <tr>
                                            <td><?= enroll_e($p['department'] ?? '—') ?></td><td><?= enroll_e($p['program']) ?></td>
                                            <td><?= !empty($p['year_level']) ? enroll_e(EnrollmentLogic::ordinal($p['year_level'])) . ' Year' : 'Not set' ?></td>
                                            <td><?= enroll_e($p['acad_year']) ?></td><td><?= enroll_e(enroll_sem($p['sem'])) ?></td>
                                            <td><?= enroll_e(date('M d, Y h:i A', strtotime($p['open_date']))) ?></td>
                                            <td><?= !empty($p['close_date']) ? enroll_e(date('M d, Y h:i A', strtotime($p['close_date']))) : '—' ?></td>
                                            <td>
                                                <span class="status-badge <?= $isOpen ? '' : 'closed' ?>"><?= enroll_e(ucfirst($p['status'])) ?></span>
                                                <div style="display:flex; gap:5px; margin-top:8px; flex-wrap:wrap;">
                                                    <button type="button" class="btn-enroll btn-edit js-edit-period" style="padding:4px 10px; font-size:12px;" data-id="<?= (int)$p['enrollmentID'] ?>" data-department="<?= enroll_e($p['department'] ?? '') ?>" data-program="<?= enroll_e($p['program']) ?>" data-year-level="<?= (int)($p['year_level'] ?? 0) ?>" data-acad-year="<?= enroll_e($p['acad_year']) ?>" data-sem="<?= (int)$p['sem'] ?>" data-open-date="<?= enroll_e(date('Y-m-d\TH:i', strtotime($p['open_date']))) ?>" data-close-date="<?= !empty($p['close_date']) ? enroll_e(date('Y-m-d\TH:i', strtotime($p['close_date']))) : '' ?>">Edit</button>
                                                    <form method="POST" action="<?= $formAction ?>" style="display:inline; margin:0;">
                                                        <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>"><input type="hidden" name="enroll_action" value="toggle_period"><input type="hidden" name="enrollment_id" value="<?= (int)$p['enrollmentID'] ?>">
                                                        <button type="submit" class="btn-enroll <?= $isOpen ? 'btn-danger' : 'btn-approve' ?>" style="padding:4px 10px; font-size:12px;"><?= $isOpen ? 'Close' : 'Open' ?></button>
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
                    <div class="registrar-panel" data-reg-panel="advisers">
                    <section class="enroll-card" id="advisers-section" style="margin-bottom:24px;">
                        <div class="ba-heading"><div><h2>Faculty Assignment</h2><p>Assign advisers or subject professors to students and blocks.</p></div></div>
                        <form method="POST" action="<?= $formAction ?>" id="bulk-adviser-form">
                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                            <input type="hidden" name="enroll_action" value="bulk_assign_adviser">
                            <input type="hidden" name="student_ids" id="bulk-student-ids">
                            <div class="form-group"><label for="bulk-kind">Assignment type</label><select class="form-control" id="bulk-kind" name="assignment_type"><option value="adviser">Academic adviser</option><option value="subject">Subject professor</option></select></div>
                            <div id="bulk-class-wrap" hidden><label for="bulk-class">Enrolled subject / academic period</label><select class="form-control" id="bulk-class"></select><input type="hidden" name="subject_id" id="bulk-subject"><input type="hidden" name="subject_period" id="bulk-period"></div>
                            <div class="ba-filters">
                                <div class="form-group"><label for="bulk-dept">Department</label><select class="form-control" name="department" id="bulk-dept" required><option value="">Select department</option><?php foreach ($logic->periodDepartments as $d): ?><option value="<?= enroll_e($d['department']) ?>"><?= enroll_e($d['department'].' - '.$d['name']) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="bulk-program">Program</label><select class="form-control" name="program" id="bulk-program" required><option value="">Select program</option><?php foreach ($logic->periodPrograms as $p): ?><option value="<?= enroll_e($p['program']) ?>" data-dept="<?= enroll_e($p['department']) ?>"><?= enroll_e($p['program']) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="bulk-year">Year level</label><select class="form-control" id="bulk-year" name="year_level"><option value="">All years</option></select></div>
                                <div class="form-group"><label for="bulk-block">Block</label><select class="form-control" id="bulk-block" name="block"><option value="">All blocks</option></select></div>
                                <div class="form-group"><label for="bulk-faculty">Academic adviser</label><select class="form-control" id="bulk-faculty" name="faculty_id" required><option value="">Select adviser</option><?php foreach ($logic->bulkAdvisers as $a): ?><option value="<?= (int)$a['facultyID'] ?>" data-dept="<?= enroll_e($a['department']) ?>" data-program="<?= enroll_e($a['program'] ?? '') ?>"><?= enroll_e($a['name'].' ('.$a['role'].')'.(empty($a['program'])?' - department-wide': ' - '.$a['program'])) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="bulk-type">Student type</label><select class="form-control" id="bulk-type"><option value="">All types</option><option>Regular</option><option>Irregular</option></select></div>
                            </div>
                            <div class="ba-mode"><label for="bulk-mode">Assignment action</label><select class="form-control" id="bulk-mode"><option value="assign">Assign unassigned students</option><option value="reassign">Reassign existing advisers</option></select><input type="hidden" id="bulk-replace" name="replace_existing" value="0"><small>For an entire block, choose a year and block, then use Select entire block. For individual students, select their checkboxes.</small></div>
                            <div class="ba-list-head"><div><h3>Student roster</h3><p id="bulk-stats" aria-live="polite">Select a department and program to preview students.</p></div><button type="button" class="btn-enroll" id="bulk-select-block" disabled>Select entire block</button></div>
                            <input class="form-control" id="bulk-search" placeholder="Search student name or ID" aria-label="Search students">
                            <div class="ba-table">
                                <table class="enroll-table" style="width:100%;"><thead><tr><th class="ba-check"><input type="checkbox" id="bulk-all" aria-label="Select all eligible visible students"></th><th>Student</th><th>Year / Block</th><th>Type</th><th id="bulk-current-heading">Current adviser</th></tr></thead><tbody id="bulk-rows">
                                <?php foreach ($logic->bulkStudents as $st): $block = preg_replace('/^\d+\s*/', '', (string)$st['block']); ?>
                                    <tr hidden data-dept="<?= enroll_e($st['department']) ?>" data-program="<?= enroll_e($st['program']) ?>" data-year="<?= enroll_e($st['year_level']) ?>" data-block="<?= enroll_e($block) ?>" data-type="<?= enroll_e($st['student_type']) ?>" data-adviser="<?= enroll_e($st['adviser_id'] ?? '') ?>">
                                        <td class="ba-check"><input type="checkbox" class="bulk-pick" value="<?= (int)$st['studentID'] ?>" aria-label="Select <?= enroll_e($st['student_name']) ?>"></td>
                                        <td><strong><?= enroll_e($st['student_name']) ?></strong><small class="ba-id"><?= enroll_e($st['display_id']) ?></small></td><td><?= enroll_e($st['year_level'].' / '.$block) ?></td><td><span class="ba-type"><?= enroll_e($st['student_type']) ?></span></td><td><span class="ba-current"><?= $st['adviser_id'] ? enroll_e($st['adviser_name']) : 'Unassigned' ?></span><small class="ba-row-note"></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            </div>
                            <div id="ba-empty" class="ba-empty" hidden>No students match these filters.</div><div class="ba-footer"><div><strong id="bulk-selected" aria-live="polite">0 selected</strong><small id="ba-action-hint">Select an adviser to continue.</small></div><button class="btn-enroll btn-approve" id="bulk-submit" type="submit" disabled>Assign selected students</button></div>
                        </form>
                    </section>
                    <style>
#advisers-section{padding:clamp(16px,2.4vw,30px);border:1px solid rgba(255,255,255,.16);border-radius:20px;background:linear-gradient(145deg,rgba(42,25,64,.82),rgba(65,35,85,.88));color:#fff;box-shadow:0 16px 40px #20102d30;scroll-margin-top:20px}
#advisers-section *{box-sizing:border-box} #advisers-section h2{margin:5px 0 8px;font-size:clamp(22px,2vw,28px);font-weight:700} #advisers-section p{margin:0;color:#e1d3ee;font-size:13px;line-height:1.5} .ba-heading,.ba-list-head,.ba-footer{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.ba-kicker{font-size:10px;letter-spacing:.12em;color:#cbb3ec;font-weight:700}.ba-tag,.ba-type{display:inline-block;padding:5px 10px;border-radius:7px;background:#b99be322;color:#f0e4ff;font-size:12px}.ba-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:24px 0 16px}.ba-filters .form-group{margin:0;min-width:0}.ba-filters label{display:block;margin-bottom:7px;font-size:12px;font-weight:600;color:#e5d6f3} #advisers-section .form-control{width:100%;min-height:43px;border:1px solid #ffffff25;border-radius:9px;background:#21142f;color:#fff;padding:10px 12px} #advisers-section select option{background:#21142f;color:#fff} #advisers-section :focus-visible{outline:2px solid #d1a8ff;outline-offset:3px}.ba-replace{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #ffffff20;border-radius:12px;background:#ffffff08;cursor:pointer}.ba-replace small,.ba-footer small,.ba-id,.ba-row-note{display:block;color:#d8c7e7;font-size:12px;margin-top:4px}.ba-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:20px 0}.ba-metrics>div{background:#ffffff09;border:1px solid #ffffff19;border-radius:13px;padding:16px}.ba-metrics small{display:block;color:#dfcfee;font-size:12px}.ba-metrics strong{display:block;font-size:27px;margin-top:7px}.ba-list-head{margin:20px 0 12px}.ba-list-head h3{margin:0 0 5px;font-size:17px}.ba-table{max-height:420px;overflow:auto;margin-top:12px;border:1px solid #ffffff20;border-radius:12px}.ba-table table{border-collapse:collapse;min-width:570px}.ba-table th{position:sticky;top:0;background:#2d1c40!important;z-index:1;text-align:left}.ba-table td,.ba-table th{padding:13px 12px!important;border-bottom:1px solid #ffffff12}.ba-table .ba-check{width:48px;text-align:center}.ba-table tr[data-selected="true"] td{background:#a983e524}.ba-table tr:hover td{background:#ffffff09}.ba-table tr[data-selected="true"]:hover td{background:#a983e530} #bulk-rows tr[hidden],#ba-empty[hidden]{display:none!important} #bulk-adviser-form input[type=checkbox]{appearance:auto!important;-webkit-appearance:checkbox!important;position:static!important;opacity:1!important;pointer-events:auto!important;width:19px;height:19px;min-width:19px;accent-color:#b88af5;cursor:pointer;margin:0;vertical-align:middle} #bulk-adviser-form input[type=checkbox]:disabled{opacity:.38!important;cursor:not-allowed}.ba-footer{margin-top:16px;padding:16px 0 0;border-top:1px solid #ffffff20}.ba-footer #bulk-submit{background:#b48bf1;color:#241333;border:0;border-radius:10px;padding:12px 20px;font-weight:700} #bulk-submit:disabled{opacity:.45;cursor:not-allowed}.ba-empty{padding:24px;text-align:center;color:#e0d0ef} @media(max-width:900px){.ba-filters{grid-template-columns:repeat(2,minmax(0,1fr))}} @media(max-width:550px){.ba-filters{grid-template-columns:1fr}.ba-metrics{gap:6px}.ba-metrics>div{padding:10px}.ba-metrics strong{font-size:21px}.ba-footer #bulk-submit{width:100%}}
                    </style>
                    <script>
                    (()=>{
                        const get=id=>document.getElementById(id), form=get('bulk-adviser-form');
                        const dept=get('bulk-dept'), program=get('bulk-program'), year=get('bulk-year'), block=get('bulk-block'), faculty=get('bulk-faculty'), type=get('bulk-type'), search=get('bulk-search'), replace=get('bulk-replace'), all=get('bulk-all'), submit=get('bulk-submit'), mode=get('bulk-mode'), kind=get('bulk-kind'), cls=get('bulk-class'), blockButton=get('bulk-select-block');
                        const rows=[...get('bulk-rows').querySelectorAll('tr')], programs=[...program.options].slice(1), advisers=[...faculty.options].slice(1);
                        const professors=<?= json_encode($logic->bulkProfessors, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>, classes=<?= json_encode($logic->bulkSubjectClasses, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>, assignments=<?= json_encode($logic->bulkSubjectAssignments, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>;
                        const pick=r=>r.querySelector('.bulk-pick'), isSubject=()=>kind.value==='subject';
                        rows.forEach(r=>r.dataset.adviserName=r.querySelector('.ba-current').textContent);
                        function options(select,list,placeholder){const old=select.value;select.replaceChildren(new Option(placeholder,''));list.forEach(o=>select.append(o.cloneNode(true)));select.value=old;if(select.selectedIndex<0)select.value='';}
                        function values(select,list,placeholder){options(select,[...new Set(list.filter(Boolean))].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true})).map(v=>new Option(v,v)),placeholder);}
                        function scope(){return rows.filter(r=>dept.value&&program.value&&r.dataset.dept===dept.value&&r.dataset.program===program.value);}
                        const classKey=c=>JSON.stringify([String(c.enrollmentID),c.subjectID]);
                        function selectedClasses(){return classes.filter(c=>c.program===program.value&&classKey(c)===cls.value);}
                        function current(r){return isSubject()?assignments.filter(a=>String(a.studentID)===pick(r).value&&a.subjectID.trim()===get('bulk-subject').value.trim()):r.dataset.adviser?[{facultyID:r.dataset.adviser,name:r.dataset.adviserName}]:[];}
                        function counts(){const eligible=rows.filter(r=>!r.hidden&&!pick(r).disabled), chosen=eligible.filter(r=>pick(r).checked);rows.forEach(r=>r.dataset.selected=String(pick(r).checked&&!r.hidden));all.checked=eligible.length>0&&chosen.length===eligible.length;all.indeterminate=chosen.length>0&&chosen.length<eligible.length;all.disabled=!eligible.length;get('bulk-selected').textContent=chosen.length+' selected';submit.disabled=!faculty.value||!chosen.length;blockButton.disabled=!year.value||!block.value||!eligible.length;submit.textContent=(mode.value==='reassign'?'Reassign ':'Assign ')+chosen.length+' selected student(s)';get('ba-action-hint').textContent=!faculty.value?'Choose an eligible faculty member.':!chosen.length?'Select eligible students.':'Ready to confirm assignment.';return chosen;}
                        function render(){const inScope=new Set(scope()), active=selectedClasses();let visible=0;rows.forEach(r=>{const d=r.dataset, old=current(r), matching=active.filter(c=>String(c.studentID)===pick(r).value), show=inScope.has(r)&&(!year.value||d.year===year.value)&&(!block.value||d.block===block.value)&&(!type.value||d.type.toLowerCase()===type.value.toLowerCase())&&(!isSubject()||matching.length>0)&&r.textContent.toLowerCase().includes(search.value.trim().toLowerCase());r.hidden=!show;r.querySelector('.ba-current').textContent=old.map(a=>a.name).join(', ')||'Unassigned';const same=old.length===1&&String(old[0].facultyID)===faculty.value, blocked=mode.value==='reassign'?!old.length:!!old.length, wrong=isSubject()&&faculty.value&&!matching.some(c=>String(c.facultyID)===faculty.value);const c=pick(r);c.disabled=!show||blocked||same||wrong;if(c.disabled)c.checked=false;r.querySelector('.ba-row-note').textContent=wrong?'Not in selected professor’s class':same?'Already assigned':blocked?'Not eligible for selected action':old.length?'Eligible for reassignment':'Available for assignment';if(show)visible++;});get('ba-empty').hidden=!(dept.value&&program.value&&!visible);get('bulk-stats').textContent=isSubject()&&!cls.value?'Select an enrolled subject and academic period.':'Select eligible students and faculty.';counts();}
                        function refreshFaculty(){const active=selectedClasses();const list=isSubject()?professors.filter(a=>a.department===dept.value&&(!a.program||a.program===program.value)&&active.some(c=>String(c.facultyID)===String(a.facultyID))).map(a=>new Option(a.name+' ('+a.role+')',a.facultyID)):advisers.filter(o=>dept.value&&program.value&&o.dataset.dept===dept.value&&(!o.dataset.program||o.dataset.program===program.value));options(faculty,list,isSubject()?'Select professor':'Select adviser');render();}
                        function refreshClass(){const selected=cls.value?JSON.parse(cls.value):['',''];get('bulk-period').value=selected[0];get('bulk-subject').value=selected[1];rows.forEach(r=>pick(r).checked=false);refreshFaculty();}
                        function refreshBlocks(){values(block,scope().filter(r=>!year.value||r.dataset.year===year.value).map(r=>r.dataset.block),'All blocks');}
                        function refreshProgram(){values(year,scope().map(r=>r.dataset.year),'All years');refreshBlocks();const unique=new Map();classes.filter(c=>c.program===program.value).forEach(c=>unique.set(classKey(c),new Option(c.subjectID+' — '+c.acad_year+' · '+({'1':'1st Semester','2':'2nd Semester','3':'Summer'}[String(c.sem)] || 'Semester '+c.sem),classKey(c))));options(cls,[...unique.values()],'Select subject and period');refreshClass();}
                        dept.addEventListener('change',()=>{options(program,programs.filter(o=>o.dataset.dept===dept.value),'Select program');refreshProgram();});program.addEventListener('change',refreshProgram);year.addEventListener('change',()=>{refreshBlocks();render();});[block,type].forEach(e=>e.addEventListener('change',render));cls.addEventListener('change',refreshClass);kind.addEventListener('change',()=>{get('bulk-class-wrap').hidden=!isSubject();get('bulk-current-heading').textContent=isSubject()?'Current subject professor':'Current adviser';form.querySelector('label[for=bulk-faculty]').textContent=isSubject()?'Subject professor':'Academic adviser';mode.options[0].textContent='Assign unassigned students';mode.options[1].textContent=isSubject()?'Reassign existing subject professors':'Reassign existing advisers';faculty.value='';refreshProgram();});mode.addEventListener('change',()=>{replace.value=mode.value==='reassign'?'1':'0';rows.forEach(r=>pick(r).checked=false);render();});blockButton.addEventListener('click',()=>{search.value='';type.value='';render();rows.forEach(r=>{if(!r.hidden&&!pick(r).disabled)pick(r).checked=true;});counts();});faculty.addEventListener('change',render);search.addEventListener('input',render);rows.forEach(r=>pick(r).addEventListener('change',counts));all.addEventListener('change',()=>{rows.forEach(r=>{if(!r.hidden&&!pick(r).disabled)pick(r).checked=all.checked;});counts();});
                        form.addEventListener('submit',e=>{const selected=counts();if(!selected.length||!faculty.value||!confirm('Confirm '+mode.value+' for '+selected.length+' student(s)?')){e.preventDefault();return;}get('bulk-student-ids').value=JSON.stringify(selected.map(r=>Number(pick(r).value)));submit.disabled=true;});
                        options(program,[],'Select program');refreshProgram();
                    })();
                    </script>

                    </div>
                    <div class="registrar-panel" data-reg-panel="subjects">
                    <h1 id="subjects-section" style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal; margin-bottom: 20px;">
                        Master Subject List
                    </h1>

                    <p style="color: rgba(255,255,255,0.8); margin-top: -15px; margin-bottom: 20px; font-size: 14px;">
                        Manage the subjects for the school catalog.
                    </p>

                    <div class="subject-grid">

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
                                            <option value="<?= enroll_e($prog['program']) ?>">
                                                <?= enroll_e($prog['program']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>PREREQUISITES (OPTIONAL)</label>
                                    <div class="prereq-picker" id="prereq-picker">
                                        <input type="search" id="prereq-search" class="form-control" placeholder="Search subject code or name..." autocomplete="off">
                                        <div class="prereq-options" id="prereq-options">
                                            <?php foreach ($logic->prerequisiteOptions as $option): ?>
                                                <label class="prereq-option" data-search="<?= enroll_e(strtolower($option['subjectID'] . ' ' . $option['name'])) ?>">
                                                    <input type="checkbox" name="prerequisites[]" value="<?= enroll_e($option['subjectID']) ?>">
                                                    <span><?= enroll_e($option['subjectID'] . ' - ' . $option['name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="prereq-selected" id="prereq-selected">No prerequisites selected</div>
                                    </div>
                                    <small style="color:rgba(255,255,255,.65);">Search and check one or more subjects. Leave empty if none.</small>
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

                                <button type="submit" id="sf-submit" class="btn-enroll btn-approve" style="width: 100%;">
                                    Add Subject
                                </button>

                                <button type="button" id="sf-cancel" class="btn-enroll" style="width: 100%; margin-top: 10px; display: none;">
                                    Cancel Edit
                                </button>
                            </form>
                        </div>


                        <div class="enroll-card">
                            <h2>Existing Subjects</h2>

                            <div class="table-responsive">
                                <table class="enroll-table">
                                    <thead>
                                        <tr>
                                            <th>CODE</th>
                                            <th>SUBJECT</th>
                                            <th>PROGRAM</th>
                                            <th>YEAR</th>
                                            <th>SEM</th>
                                            <th>UNITS</th>
                                            <th>PREREQUISITES</th>
                                            <th>ACTION</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if (empty($logic->allSubjects)): ?>
                                            <tr>
                                                <td colspan="8" style="text-align:center; color: rgba(255,255,255,0.6);">
                                                    No subjects yet.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($logic->allSubjects as $s): ?>
                                            <tr data-row="<?= (int)$s['id'] ?>">
                                                <td><?= enroll_e($s['subjectID']) ?></td>
                                                <td><?= enroll_e($s['name']) ?></td>
                                                <td><?= enroll_e($s['program']) ?></td>
                                                <td><?= (int)$s['year'] ?></td>
                                                <td><?= (int)$s['sem_available'] ?></td>
                                                <td><?= (int)$s['unit'] ?></td>
                                                <td class="subject-prereq-cell"><?= $s['prerequisite_labels'] !== '' ? nl2br(enroll_e(str_replace('||', "\n", $s['prerequisite_labels']))) : '<span style="opacity:.6;">None</span>' ?></td>

                                                <td>
                                                    <div style="display: flex; gap: 5px;">

                                                        <button
                                                            type="button"
                                                            class="btn-enroll btn-edit js-edit-subject"
                                                            style="padding: 4px 10px; font-size:12px;"
                                                            data-id="<?= (int)$s['id'] ?>"
                                                            data-code="<?= enroll_e(trim($s['subjectID'])) ?>"
                                                            data-name="<?= enroll_e($s['name']) ?>"
                                                            data-program="<?= enroll_e($s['program']) ?>"
                                                            data-unit="<?= (int)$s['unit'] ?>"
                                                            data-year="<?= (int)$s['year'] ?>"
                                                            data-sem="<?= (int)$s['sem_available'] ?>"
                                                            data-prereqs="<?= enroll_e(json_encode(array_filter(explode(', ', $s['prerequisite_codes'])))) ?>">
                                                            Edit
                                                        </button>

                                                        <form method="POST" action="<?= $formAction ?>" onsubmit="return confirm('Delete this subject?');" style="margin:0;">
                                                            <input type="hidden" name="csrf" value="<?= enroll_e($csrf) ?>">
                                                            <input type="hidden" name="enroll_action" value="delete_subject">
                                                            <input type="hidden" name="syllabus_id" value="<?= (int)$s['id'] ?>">

                                                            <button class="btn-enroll btn-danger" style="padding: 4px 10px; font-size:12px;">
                                                                Delete
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

                    </div>


                    <style>
                    .prereq-picker{position:relative;background:rgba(34,17,49,.55);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:10px}
                    .prereq-options{display:none;max-height:190px;overflow:auto;margin-top:8px;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:6px}.prereq-picker.open .prereq-options{display:block}
                    .prereq-option{display:flex;gap:9px;align-items:center;padding:7px 8px;border-radius:6px;color:#fff;cursor:pointer;font-size:13px}
                    .prereq-option:hover{background:rgba(255,255,255,.1)}
                    .prereq-option input{accent-color:#b77be0}
                    .prereq-selected{font-size:12px;color:rgba(255,255,255,.7);margin-top:8px}
                    
                    .selection-table-wrap.table-responsive,.selection-picked-wrap.table-responsive{display:block;width:100%;max-width:100%;min-width:0;overflow-x:hidden!important;overflow-y:visible!important;}
                    .selection-table,.selection-picked-table{width:100%!important;max-width:100%;min-width:0!important;table-layout:fixed;border-collapse:collapse;}
                    .enroll-grid,.enroll-grid>.enroll-card{min-width:0;max-width:100%;}
                    .selection-table th,.selection-table td,.selection-picked-table th,.selection-picked-table td{white-space:normal!important;overflow-wrap:anywhere;word-break:normal;padding:9px 7px;}
                    .selection-table th:nth-child(1),.selection-table td:nth-child(1){width:16%;}
                    .selection-table th:nth-child(2),.selection-table td:nth-child(2){width:29%;}
                    .selection-table th:nth-child(3),.selection-table td:nth-child(3){width:10%;}
                    .selection-table th:nth-child(4),.selection-table td:nth-child(4){width:12%;}
                    .selection-table th:nth-child(5),.selection-table td:nth-child(5){width:13%;}
                    .selection-table th:nth-child(6),.selection-table td:nth-child(6){width:20%;}
                    .selection-picked-table th:nth-child(1),.selection-picked-table td:nth-child(1){width:48%;}
                    .selection-picked-table th:nth-child(2),.selection-picked-table td:nth-child(2){width:17%;}
                    .selection-picked-table th:nth-child(3),.selection-picked-table td:nth-child(3){width:35%;}
                    .selection-picked-table form{display:inline-flex;max-width:100%;min-width:0;gap:5px;flex-wrap:wrap;}
                    .selection-table select,.selection-table button,.selection-picked-table button{max-width:100%;min-width:0;white-space:normal;}
                    .selection-table td:last-child{overflow-wrap:anywhere;}
                    .selection-picked-table button{max-width:100%;white-space:normal;}
                    @media(max-width:600px){.selection-table th,.selection-table td,.selection-picked-table th,.selection-picked-table td{font-size:11px;padding:7px 4px;}}

                    /* Keep both student tables within their cards at 100% browser zoom. */
                    .enrollment-page,.enroll-grid,.enroll-grid>.enroll-card{min-width:0!important;max-width:100%;box-sizing:border-box;}
                    .enroll-grid{display:grid!important;grid-template-columns:minmax(0,1.65fr) minmax(0,1fr)!important;gap:16px!important;align-items:start;}
                    .selection-table-wrap,.selection-picked-wrap{width:100%!important;max-width:100%!important;overflow-x:hidden!important;box-sizing:border-box;}
                    .selection-table,.selection-picked-table{table-layout:fixed!important;width:100%!important;min-width:0!important;}
                    .selection-picked-table th,.selection-picked-table td{min-width:0!important;white-space:normal!important;overflow-wrap:anywhere!important;}
                    .selection-picked-table th:nth-child(1),.selection-picked-table td:nth-child(1){width:43%!important;}
                    .selection-picked-table th:nth-child(2),.selection-picked-table td:nth-child(2){width:20%!important;}
                    .selection-picked-table th:nth-child(3),.selection-picked-table td:nth-child(3){width:37%!important;text-align:center;}
                    .selection-picked-wrap::-webkit-scrollbar,.selection-table-wrap::-webkit-scrollbar{display:none;}
                    @media(max-width:1100px){.enroll-grid{grid-template-columns:minmax(0,1fr)!important;}}
</style>

                    <script>
                    (function () {
                        var form = document.getElementById('period-form');
                        if (!form) return;
                        var el = { title: document.getElementById('period-form-title'), action: document.getElementById('period-action'), id: document.getElementById('period-id'), department: document.getElementById('period-department'), program: document.getElementById('period-program'), year: document.getElementById('period-year-level'), acad: document.getElementById('period-acad-year'), sem: document.getElementById('period-sem'), open: document.getElementById('period-open-date'), close: document.getElementById('period-close-date'), submit: document.getElementById('period-submit'), cancel: document.getElementById('period-cancel') };
                        var programs = Array.from(el.program.options).map(function (o) { return { value:o.value, text:o.textContent, department:o.dataset.department || '' }; });
                        function filterPrograms(selected) {
                            var dept = el.department.value; el.program.innerHTML = '';
                            var placeholder = document.createElement('option'); placeholder.value=''; placeholder.textContent='Select Program'; el.program.appendChild(placeholder);
                            programs.forEach(function (p) { if (p.value && p.department === dept) { var o=document.createElement('option'); o.value=p.value; o.textContent=p.text; o.dataset.department=p.department; el.program.appendChild(o); } });
                            if (selected) el.program.value=selected;
                        }
                        function reset() { form.reset(); el.action.value='create_period'; el.id.value=''; el.title.textContent='Create New Period'; el.submit.textContent='Create Period'; el.cancel.style.display='none'; filterPrograms(''); }
                        el.department.addEventListener('change', function(){ filterPrograms(''); });
                        document.querySelectorAll('.js-edit-period').forEach(function(btn){ btn.addEventListener('click', function(){ var d=btn.dataset; el.action.value='update_period'; el.id.value=d.id; el.department.value=d.department; filterPrograms(d.program); el.year.value=d.yearLevel; el.acad.value=d.acadYear; el.sem.value=d.sem; el.open.value=d.openDate; el.close.value=d.closeDate; el.title.textContent='Edit Enrollment Period'; el.submit.textContent='Update Period'; el.cancel.style.display='block'; form.scrollIntoView({behavior:'smooth',block:'start'}); }); });
                        el.cancel.addEventListener('click', reset);
                        filterPrograms('');
                        form.addEventListener('submit', function(e){ if (el.close.value && el.open.value && new Date(el.close.value) <= new Date(el.open.value)) { e.preventDefault(); alert('Close date must be after the open date.'); } });
                    })();
                    </script>

                    <script>
                    (function () {
                        var form = document.getElementById('subject-form');
                        if (!form) return;

                        var el = {
                            title: document.getElementById('subject-form-title'),
                            action: document.getElementById('sf-action'),
                            syllabus: document.getElementById('sf-syllabus'),
                            code: document.getElementById('sf-code'),
                            name: document.getElementById('sf-name'),
                            program: document.getElementById('sf-program'),
                            unit: document.getElementById('sf-unit'),
                            year: document.getElementById('sf-year'),
                            sem: document.getElementById('sf-sem'),
                            submit: document.getElementById('sf-submit'),
                            cancel: document.getElementById('sf-cancel')
                        };

                        var prereqChecks = Array.from(document.querySelectorAll('#prereq-options input[type="checkbox"]'));
                        var prereqSearch = document.getElementById('prereq-search');
                        var prereqSelected = document.getElementById('prereq-selected');

                        function updatePrereqSummary() {
                            var chosen = prereqChecks.filter(function (c) {
                                return c.checked;
                            }).map(function (c) {
                                return c.parentElement.querySelector('span').textContent;
                            });

                            prereqSelected.textContent = chosen.length
                                ? chosen.join(', ')
                                : 'No prerequisites selected';
                        }

                        prereqChecks.forEach(function (c) {
                            c.addEventListener('change', updatePrereqSummary);
                        });

                        prereqSearch.addEventListener('focus', function () {
                            document.getElementById('prereq-picker').classList.add('open');
                        });

                        prereqSearch.addEventListener('click', function () {
                            document.getElementById('prereq-picker').classList.add('open');
                        });

                        document.addEventListener('click', function (event) {
                            if (!document.getElementById('prereq-picker').contains(event.target)) {
                                document.getElementById('prereq-picker').classList.remove('open');
                            }
                        });

                        prereqSearch.addEventListener('input', function () {
                            var q = prereqSearch.value.toLowerCase().trim();

                            document.querySelectorAll('.prereq-option').forEach(function (item) {
                                item.style.display = item.dataset.search.indexOf(q) !== -1
                                    ? 'flex'
                                    : 'none';
                            });
                        });

                        function setPrerequisites(values) {
                            prereqChecks.forEach(function (c) {
                                c.checked = values.indexOf(c.value) !== -1;
                            });

                            updatePrereqSummary();
                        }

                        function clearHighlight() {
                            document.querySelectorAll('tr.is-editing').forEach(function (r) {
                                r.classList.remove('is-editing');
                            });
                        }

                        function resetForm() {
                            form.reset();
                            setPrerequisites([]);
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

                                var selectedPrereqs = [];

                                try {
                                    selectedPrereqs = JSON.parse(d.prereqs || '[]');
                                } catch (e) {}

                                setPrerequisites(selectedPrereqs);
                                el.submit.textContent = 'Update Subject';
                                el.cancel.style.display = 'block';

                                clearHighlight();

                                var row = btn.closest('tr');

                                if (row) {
                                    row.classList.add('is-editing');
                                }

                                var r = form.getBoundingClientRect();

                                if (r.top < 0 || r.bottom > window.innerHeight) {
                                    form.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'nearest'
                                    });
                                }

                                el.name.focus({ preventScroll: true });
                            });
                        });

                        el.cancel.addEventListener('click', resetForm);

                    })();
                    </script>

                    </div>
                    <script>
                    (() => {
                        const nav = document.querySelector('.registrar-tabs');
                        if (!nav) return;
                        const tabs = [...nav.querySelectorAll('[data-reg-tab]')];
                        const panels = [...document.querySelectorAll('[data-reg-panel]')];
                        const key = 'ams-registrar-enrollment-tab';
                        function valid(name) { return tabs.some(t => t.dataset.regTab === name); }
                        function activate(name, scroll) {
                            if (!valid(name)) name = 'periods';
                            panels.forEach(p => p.hidden = p.dataset.regPanel !== name);
                            tabs.forEach(t => { const active = t.dataset.regTab === name; t.classList.toggle('active', active); if(active) t.setAttribute('aria-current','page'); else t.removeAttribute('aria-current'); });
                            try { sessionStorage.setItem(key,name); } catch(e) {}
                            if (scroll) document.querySelector('.enrollment-page').scrollTo({top:0,behavior:'auto'});
                        }
                        function fromHash(){ return tabs.find(t=>t.getAttribute('href')===location.hash)?.dataset.regTab; }
                        let initial = fromHash();
                        if(!initial) { try { initial=sessionStorage.getItem(key); } catch(e) {} }
                        activate(initial,false);
                        tabs.forEach(t => t.addEventListener('click',e=>{e.preventDefault();history.replaceState(null,'',t.getAttribute('href'));activate(t.dataset.regTab,true);}));
                        window.addEventListener('hashchange',()=>{const name=fromHash();if(name)activate(name,true);});
                    })();
                    </script>
                <?php endif; ?>

                <?php if ($group === 'instructor'): ?>
                    <div class="enroll-card" style="margin-top: 20px;">
                        <p style="color: rgba(255,255,255,0.8); margin: 0;">
                            You do not have permission to access enrollment management.
                        </p>
                    </div>
                <?php endif; ?>


<style id="enrollment-modern-layout">
/* Scoped enrollment workspace: no changes to shared navigation or other modules. */
body[data-page="ENROLLMENT"] .enrollment-page{--en-bg:rgba(80,38,108,.35);--en-panel:rgba(82,39,110,.40);--en-line:#ffffff38;--en-muted:#f3e9fa;--en-text:#fff;--en-accent:#e6ceff;--en-primary:#7050b4;color:var(--en-text);background:radial-gradient(ellipse at top right,#dba6ff38 0,transparent 45%),var(--en-bg);border:1px solid #ffffff26;border-radius:20px;padding:clamp(16px,2.5vw,32px);overflow:auto;min-width:0;max-width:100%;box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.5;scrollbar-color:#745e91 transparent;gap:0;}
.enrollment-page *, .enrollment-page *::before,.enrollment-page *::after{box-sizing:border-box;}
.enrollment-page [hidden]{display:none!important;}
.enrollment-page .enrollment-role-label{margin-bottom:14px;}
.enrollment-page .enrollment-eyebrow{display:block;color:var(--en-accent);font-size:10px;font-weight:750;letter-spacing:.13em;line-height:1.5;}
.enrollment-page .enrollment-hero,.enrollment-page .dean-stage1-heading,.enrollment-page .review-header{padding:22px 24px;border:1px solid var(--en-line);border-radius:16px;background:linear-gradient(120deg,#ffffff20,#b36bdb25);margin:0 0 20px!important;align-items:center;gap:16px;flex-wrap:wrap;}
.enrollment-page .enrollment-hero h1,.enrollment-page .dean-stage1 h1,.enrollment-page .review-header h1,.enrollment-page h1{font-size:clamp(23px,2.2vw,32px)!important;font-weight:700!important;line-height:1.2;letter-spacing:-.035em;color:var(--en-text)!important;margin:0 0 8px!important;}
.enrollment-page .enrollment-hero p,.enrollment-page .dean-stage1-heading p{color:var(--en-muted);margin:6px 0 0;font-size:14px;opacity:1;}
.enrollment-page .enroll-card,.enrollment-page #advisers-section{background:var(--en-panel)!important;border:1px solid var(--en-line)!important;border-radius:16px!important;padding:clamp(16px,2vw,24px)!important;box-shadow:0 4px 16px #10091b20!important;min-width:0;max-width:100%;color:var(--en-text);}
.enrollment-page .enroll-card h2,.enrollment-page #advisers-section h2{font-size:19px!important;font-weight:650!important;letter-spacing:-.02em;line-height:1.3;margin:0 0 14px;color:var(--en-text);}
.enrollment-page .enroll-card p{line-height:1.55;}
.enrollment-page .registrar-tabs,.enrollment-page .dean-tabs{display:flex;flex-wrap:wrap;gap:5px;padding:5px;background:rgba(82,40,112,.30);border:1px solid var(--en-line);border-radius:12px;margin:0 0 22px;align-self:stretch;}
.enrollment-page .registrar-tabs a,.enrollment-page .dean-tabs a{flex:0 1 auto;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--en-muted);font-size:13px;font-weight:600;text-decoration:none;min-height:42px;}
.enrollment-page .registrar-tabs a:hover,.enrollment-page .dean-tabs a:hover{background:#ffffff0a;color:#fff;}
.enrollment-page .registrar-tabs a.active,.enrollment-page .dean-tabs a.active{background:rgba(106,62,160,.78);border-color:#e6ceff36;color:#fff;}
.enrollment-page .registrar-panel{width:100%;min-width:0;}
.enrollment-page .registrar-panel>h1{font-size:23px!important;margin:0 0 16px!important;}
.enrollment-page .registrar-panel>p{margin:0 0 18px!important;}
.enrollment-page .form-grid,.enrollment-page .ba-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:16px;align-items:start;}
.enrollment-page .form-group{min-width:0;margin:0;}
.enrollment-page .form-group label,.enrollment-page form>label{display:block;color:var(--en-muted);font-size:12px;font-weight:650;margin:0 0 7px;letter-spacing:.015em;}
.enrollment-page .form-control,.enrollment-page #advisers-section .form-control{background:rgba(80,38,108,.35)!important;border:1px solid #ffffff24!important;color:var(--en-text)!important;border-radius:9px!important;min-height:42px;max-width:100%;padding:10px 12px!important;font-family:inherit;font-size:13px;line-height:1.4;box-shadow:none!important;color-scheme:dark;}
.enrollment-page .form-group .form-control{width:100%;}
.enrollment-page textarea.form-control{resize:vertical;min-height:80px;}
.enrollment-page .form-control::placeholder{color:#e6d8f1;opacity:1;}
.enrollment-page select option{background:#58336e;color:#fff;}
.enrollment-page :is(a,button,input,select,textarea):focus-visible{outline:2px solid var(--en-accent)!important;outline-offset:3px;}
.enrollment-page .btn-enroll{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:34px;padding:7px 13px!important;border:1px solid #ad92eb55!important;border-radius:8px!important;background:#453362!important;color:#fff!important;font-family:inherit;font-weight:650;font-size:12px!important;line-height:1.3;text-decoration:none;cursor:pointer;white-space:normal;}
.enrollment-page .btn-enroll:hover:not(:disabled){filter:brightness(1.15);}
.enrollment-page .btn-enroll.btn-approve{background:#7050b4!important;border-color:#a78bfa66!important;color:#fff!important;}
.enrollment-page .btn-enroll.btn-danger{background:#522839!important;border-color:#dc78984d!important;color:#ffd5e0!important;}
.enrollment-page .btn-enroll:disabled{opacity:.42;cursor:not-allowed;}
.enrollment-page .table-responsive,.enrollment-page .ba-table{width:100%;max-width:100%;overflow-x:auto;border:1px solid var(--en-line);border-radius:11px;margin-top:12px;}
.enrollment-page .enroll-table{width:100%;border-collapse:separate!important;border-spacing:0;text-align:left;font-size:13px;color:var(--en-text);background:transparent;}
.enrollment-page .enroll-table th{background:rgba(74,34,102,.65)!important;color:#f1e4ff!important;font-size:11px;font-weight:700;text-transform:none;letter-spacing:.025em;text-align:left;}
.enrollment-page .enroll-table th,.enrollment-page .enroll-table td{padding:12px 13px;border-bottom:1px solid #ffffff12;vertical-align:middle;}
.enrollment-page .enroll-table td{background:transparent;}
.enrollment-page .enroll-table tbody tr:last-child td{border-bottom:0;}
.enrollment-page .enroll-table tbody tr:hover td{background:#ffffff08;}
.enrollment-page .enroll-table tbody tr.is-editing td{background:#aa83ed20;}
.enrollment-page .enrollment-result,.enrollment-page .status-badge,.enrollment-page .locked-badge{display:inline-flex;align-items:center;justify-content:center;border:1px solid #ffffff20;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:650;line-height:1.2;white-space:nowrap;background:#ffffff10;color:#e3d9ef;}
.enrollment-page .dean-grade-note{font-size:12px;color:var(--en-muted,#e3d9ef);margin:0 0 12px;}
.enrollment-page .result-pending,.enrollment-page .result-awaiting_grade_verification{background:#f5c46b18;color:#f8d599;border-color:#f5c46b33;}
.enrollment-page .result-enrolled,.enrollment-page .result-passed,.enrollment-page .status-badge:not(.closed){background:#51d4ae19;color:#a2efd7;border-color:#51d4ae33;}
.enrollment-page .result-credited{background:#88b5ff1c;color:#b9d4ff;border-color:#88b5ff40;}
.enrollment-page .result-failed,.enrollment-page .result-rejected,.enrollment-page .status-badge.closed{background:#f18da51a;color:#ffc3d0;border-color:#f18da533;}
.enrollment-page .dean-stat-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,155px),1fr));gap:12px;}
.enrollment-page .dean-metric{gap:8px;}
.enrollment-page .dean-metric span{color:var(--en-muted);opacity:1;}
.enrollment-page .dean-metric strong{font-size:29px;color:#fff;font-variant-numeric:tabular-nums;}
.enrollment-page .dean-filter-row{gap:12px;}
.enrollment-page .dean-two-col,.enrollment-page .subject-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;align-items:start;}
.enrollment-page .subject-grid>*{min-width:0;}
.enrollment-page .enroll-grid{display:grid!important;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)!important;gap:16px!important;align-items:start;}
.enrollment-page .selection-table-wrap,.enrollment-page .selection-picked-wrap{overflow-x:auto!important;scrollbar-width:thin;}
.enrollment-page .selection-table{min-width:520px!important;table-layout:auto!important;}
.enrollment-page .selection-picked-table{min-width:280px!important;table-layout:auto!important;}
.enrollment-page .selection-table td,.enrollment-page .selection-table th,.enrollment-page .selection-picked-table td,.enrollment-page .selection-picked-table th{padding:9px 8px;overflow-wrap:normal;}
.enrollment-page .ba-metrics>div{background:rgba(74,34,102,.65);border:1px solid var(--en-line);border-radius:10px;padding:15px;}
.enrollment-page .ba-replace{display:flex!important;align-items:center;gap:12px;background:rgba(74,34,102,.65);color:var(--en-text);}
.enrollment-page .ba-table tr[data-selected="true"] td{background:#ad8ff52a;}
.enrollment-page .ba-table tr[data-selected="true"]:hover td{background:#ad8ff538;}
.enrollment-page .ba-footer{padding-top:16px;border-top:1px solid var(--en-line);}
.enrollment-page input[type=checkbox]{accent-color:#e6ceff;}
.enrollment-page .prereq-picker{background:rgba(74,34,102,.65);border-color:var(--en-line);}
.enrollment-page .popup{border-radius:10px;margin-bottom:16px;}
@media(max-width:1250px){.enrollment-page .enroll-grid{grid-template-columns:minmax(0,1fr)!important;}}
@media(max-width:700px){body[data-page="ENROLLMENT"] .enrollment-page{padding:12px;border-radius:12px;}.enrollment-page .enrollment-hero,.enrollment-page .dean-stage1-heading,.enrollment-page .review-header{padding:16px;}.enrollment-page .registrar-tabs a,.enrollment-page .dean-tabs a{flex:1 1 130px;}.enrollment-page .ba-metrics{grid-template-columns:1fr;}.enrollment-page .enroll-card{padding:14px!important;}.enrollment-page .enroll-table th,.enrollment-page .enroll-table td{padding:10px;}.enrollment-page .dean-stage1 .enroll-table{min-width:460px;}}
@media(prefers-reduced-motion:reduce){.enrollment-page *{scroll-behavior:auto!important;transition:none!important;}}

body[data-page="ENROLLMENT"] .enrollment-page{backdrop-filter:blur(18px) saturate(125%);-webkit-backdrop-filter:blur(18px) saturate(125%);box-shadow:inset 0 1px 0 #ffffff45,0 12px 36px #3f195126;}
.enrollment-page .enroll-card,.enrollment-page #advisers-section{backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:inset 0 1px 0 #ffffff24,0 6px 18px #3f195119!important;}
.enrollment-page .enroll-table th{background:rgba(79,39,108,.88)!important;}
.enrollment-page .form-control,.enrollment-page #advisers-section .form-control{background:rgba(76,36,103,.64)!important;}
.enrollment-page #advisers-section select option{background:#58336e!important;}
.enrollment-page .ba-mode{display:grid;gap:8px;max-width:100%;padding:14px;border:1px solid #ffffff36;border-radius:12px;background:#ffffff0e;}
.enrollment-page .ba-mode label{font-weight:650;}.enrollment-page .ba-mode small{color:var(--en-muted);font-size:12px;}
.enrollment-page .ba-table th{background:#603a7b!important;}
</style>

            </main>
        </div>
    </div>

</body>
</html>