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
        $colors = [
            'pending' => '#f1c40f',
            'approved' => '#2ecc71',
            'enrolled' => '#2ecc71',
            'rejected' => '#ff6b6b'
        ];

        $c = $colors[$status] ?? '#bbb';

        return '<span style="background:' . $c . '; color:#111; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:bold;">'
            . enroll_e(ucfirst($status)) . '</span>';
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

    <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/enrollment.css">
</head>

<body data-page="ENROLLMENT">

    <?php require_once __DIR__ . "/components/popup_message.php"; ?>

    <div class="row main_box">
        <?php require_once __DIR__ . "/components/navigation.php"; ?>

        <div class="column" style="flex: 1;">
            <?php require_once __DIR__ . "/components/header.php"; ?>

            <main class="column scrollbar enrollment-page">

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

                    <div class="row" style="justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
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

                        <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: normal;">
                            Pending Approvals
                        </h1>

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
                        <div><h1>Dean Enrollment Management</h1><p>College enrollment oversight · <?= enroll_e($logic->deanName) ?></p></div>
                        <label>Academic period
                            <select id="dean-period-filter" name="dean_period" class="form-control" aria-label="Academic period">
                                <?php foreach ($logic->deanPeriods as $dp): ?>
                                <option value="<?= (int)$dp['enrollmentID'] ?>" <?= $deanPeriod && (int)$deanPeriod['enrollmentID']===(int)$dp['enrollmentID']?'selected':'' ?>>
                                    <?= enroll_e($dp['acad_year'].' · '.enroll_sem($dp['sem']).' · '.$dp['program']) ?>
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
                            <p>Credit requests across all academic periods, including requests without an assigned period. Other special request types require a separate approved workflow.</p>
                            <label>Request status <select class="form-control dean-local-filter" data-target="dean-request-table" data-field="status"><option value="">All statuses</option><option value="pending">Pending</option><option value="credited">Credited</option><option value="not_credited">Not credited</option></select></label>
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
                            <h2>Student enrollment monitoring</h2><p>Read-only. Coordinator approval remains unchanged.</p>
                            <div class="dean-filter-row">
                                <input class="form-control dean-local-filter" data-target="dean-student-table" data-field="search" placeholder="Search name, student ID or subject">
                                <select class="form-control dean-local-filter" data-target="dean-student-table" data-field="year"><option value="">All years</option><?php foreach (array_unique(array_column($logic->deanStudents,'year_level')) as $v): ?><option value="<?= (int)$v ?>">Year <?= (int)$v ?></option><?php endforeach; ?></select>
                                <select class="form-control dean-local-filter" data-target="dean-student-table" data-field="status"><option value="">All statuses</option><option value="pending">Pending</option><option value="enrolled">Enrolled</option><option value="rejected">Rejected</option><option value="finished">Finished</option></select>
                            </div>
                            <div class="table-responsive"><table id="dean-student-table" class="enroll-table">
                            <thead><tr><th>Student</th><th>Year / Block</th><th>Type</th><th>Subject</th><th>Status</th></tr></thead><tbody>
                            <?php foreach ($logic->deanStudents as $r): ?>
                            <tr data-program="<?= enroll_e($r['program']) ?>" data-year="<?= (int)$r['year_level'] ?>" data-status="<?= enroll_e($r['status']) ?>">
                                <td><?= enroll_e($r['last_name'].', '.$r['first_name']) ?> · #<?= (int)$r['studentID'] ?></td>
                                <td><?= (int)$r['year_level'] ?> · <?= enroll_e($r['block']) ?></td>
                                <td><?= enroll_e($r['student_type']) ?></td>
                                <td><?= enroll_e(trim($r['subjectID']).' · '.($r['subject_name'] ?? '')) ?></td>
                                <td><?= enroll_badge($r['status']) ?><?= $r['rejection_remarks'] ? '<small>'.enroll_e($r['rejection_remarks']).'</small>' : '' ?></td>
                            </tr><?php endforeach; ?>
                            <?php if (!$logic->deanStudents): ?><tr><td colspan="5">No student enrollment records.</td></tr><?php endif; ?>
                            </tbody></table></div>
                        </div>
                    <?php elseif ($deanTab === 'subjects'): ?>
                        <div class="enroll-card">
                            <h2>Subject and section monitoring</h2><p>Read-only scheduled offerings. Section capacity is not available in the current database.</p>
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
                            table.querySelectorAll('tbody tr[data-status]').forEach(row => {
                                const show = filters.every(f => {
                                    const value = f.value.trim().toLowerCase();
                                    if (!value) return true;
                                    const field = f.dataset.field;
                                    const actual = field === 'search' ? row.textContent : row.dataset[field];
                                    return String(actual || '').toLowerCase().includes(value);
                                });
                                row.hidden = !show;
                            });
                        };
                        control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', update);
                    });
                })();
                </script>
                <?php endif; ?>

                <?php if ($group === 'registrar'): ?>

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

                <?php endif; ?>

                <?php if ($group === 'instructor'): ?>
                    <div class="enroll-card" style="margin-top: 20px;">
                        <p style="color: rgba(255,255,255,0.8); margin: 0;">
                            You do not have permission to access enrollment management.
                        </p>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

</body>
</html>