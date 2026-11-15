<?php
# Start session first before touching $_SESSION
if (session_status() === PHP_SESSION_NONE) session_start();

# --- Handles Serving Stored QR Image Blob ---
if (isset($_GET['att_qr']) && $_GET['att_qr'] === '1') {
    require_once __DIR__.'/../Services/auth.php';
    require_once __DIR__.'/../PDAO/DAO.php';
    global $db_connection;
    
    $attUserRaw = (string)($_SESSION['userID'] ?? '');
    if (preg_match('/^(\d{4})(\d{5})$/', $attUserRaw, $m)) {
        $studentDBID = (int)$m[2];
        $st = $db_connection->prepare("SELECT qr_image_data FROM students WHERE studentID = ?");
        $st->execute([$studentDBID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['qr_image_data'])) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($row['qr_image_data']));
            echo $row['qr_image_data'];
            exit;
        }
    }
    header("HTTP/1.0 404 Not Found");
    exit;
}

require_once __DIR__.'/../Services/auth.php';
require_once __DIR__.'/../PDAO/DAO.php';
global $db_connection;

$_SESSION["page"] = "ATTENDANCE | Faculty";

# Checks role
function att_role_group(string $role): string {
  $r = strtolower(trim($role));
  if (preg_match('/coordinator|dean|registrar|admin/', $r)) return 'manager';
  if (preg_match('/prof|faculty|instructor|teacher/', $r))  return 'faculty';
  return 'student';
}

$attRole   = strtolower(trim($_SESSION['role'] ?? 'student'));
$attUserRaw    = trim((string)($_SESSION['userID'] ?? ''));
$attGroup  = att_role_group($attRole);          
$attFacultyID  = ($attGroup !== 'student') ? (int)($_SESSION['real_userID'] ?? substr($attUserRaw, 5)) : 0;

# CONSISTENT FORMAT: YYYYXXXXX (No hyphen)
$attStudentKey = ($attGroup === 'student' && preg_match('/^(\d{4})(\d{5})$/', $attUserRaw, $attM)) ? $attUserRaw : null;  

$_SESSION["page"] = ($attGroup === 'student') ? "ATTENDANCE | Student" : "ATTENDANCE | Faculty";

$attPos = 'student';
if ($attGroup !== 'student') {
  if     (strpos($attRole, 'coordinator') !== false) $attPos = 'coordinator';
  elseif (strpos($attRole, 'dean')        !== false) $attPos = 'dean';
  elseif (strpos($attRole, 'registrar')   !== false) $attPos = 'registrar';
  elseif (strpos($attRole, 'admin')       !== false) $attPos = 'admin';
  else                                               $attPos = 'faculty';
}
$attLabels = ['coordinator' => 'Coordinator', 'dean' => 'Dean', 'registrar' => 'Registrar', 'admin' => 'Admin'];
$attLabel  = strtoupper(($attGroup === 'student') ? 'Student' : ($attLabels[$attPos] ?? (ucwords($attRole) ?: 'Faculty')));
$_SESSION["page"] = "ATTENDANCE | " . $attLabel;
$attCanEdit   = in_array($attPos, ['coordinator', 'admin', 'dean', 'faculty'], true);
$attMyProgram = null; $attMyDept = null;
if ($attGroup !== 'student' && $attFacultyID > 0) {
  $fq = $db_connection->prepare("SELECT program, department FROM faculty WHERE facultyID = ?");
  $fq->execute([$attFacultyID]);
  $fr = $fq->fetch(PDO::FETCH_ASSOC) ?: [];
  $attMyProgram = $fr['program']    ?? null;
  $attMyDept    = $fr['department'] ?? null;
}
$attCanScan   = in_array($attPos, ['coordinator', 'faculty', 'dean'], true);
$attIsDean    = ($attPos === 'dean');   // only the dean approves / rejects events
$attScoped = false;
$evpage    = ($attGroup !== 'student' && !empty($_GET['events']));
if ($evpage) $_SESSION["page"] = "EVENTS | " . $attLabel;

$GLOBALS['attPos'] = $attPos; $GLOBALS['attCanEdit'] = $attCanEdit; $GLOBALS['attScoped'] = $attScoped;
$GLOBALS['attMyProgram'] = $attMyProgram; $GLOBALS['attMyDept'] = $attMyDept; $GLOBALS['evpage'] = $evpage;
$GLOBALS['attCanScan'] = $attCanScan; $GLOBALS['attIsDean'] = $attIsDean; $GLOBALS['attFacultyID'] = $attFacultyID;

const ATT_DEBUG = true;
const ATT_EVENT_STATUSES = ['ACTIVE', 'INACTIVE', 'FINISHED', 'RESCHEDULE', 'PENDING'];

# --- Handles Saving Student QR Code (Blob) ---
if ($attGroup === 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'save_qr') {
  $rawQr = $_POST['qr'] ?? '';
  
  if ($attStudentKey && preg_match('/^(\d{4})(\d{5})$/', $attStudentKey, $m) && strpos($rawQr, 'data:image/png;base64,') === 0) {
    $studentDBID = (int)$m[2];
    list(, $data) = explode(',', $rawQr);
    $binaryData = base64_decode($data);
    
    if ($binaryData !== false) {
      try {
        $stmt = $db_connection->prepare("UPDATE students SET qr_image_data = ? WHERE studentID = ?");
        $stmt->execute([$binaryData, $studentDBID]);
        echo json_encode(['status' => 'success']);
        exit;
      } catch (Exception $e) {
        error_log('Failed to save student QR blob: ' . $e->getMessage());
      }
    }
  }
  echo json_encode(['status' => 'error']);
  exit;
}

# Handles record deletion
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'delete') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!$attCanEdit) {
    $_SESSION['error'] = 'You are not allowed to delete attendance records.';
    header('Location: '.($_SERVER['REQUEST_URI'] ?? ''));
    exit;
  }
  $ids = array_map('intval', array_filter((array)($_POST['ids'] ?? []), 'is_numeric'));

  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql  = "DELETE FROM attendance WHERE attendanceID IN ($placeholders)";
    $args = $ids;
    if ($attScoped) {
      $sql  .= " AND studentID IN (SELECT studentID FROM students WHERE program = ?)";
      $args[] = $attMyProgram;
    }
    $stmt = $db_connection->prepare($sql);
    $stmt->execute($args);
    $n = $stmt->rowCount();

    if ($n) $_SESSION['message'] = $n.' record'.($n === 1 ? '' : 's').' deleted.';
    else    $_SESSION['error']   = 'Those records are not in your program.';
  } else {
    $_SESSION['error'] = 'Select at least one record to delete.';
  }
  header('Location: '.($_SERVER['REQUEST_URI'] ?? ''));
  exit;
}

# Handles Add / Edit / Delete of an event
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['att_action'] ?? '', ['add_event', 'edit_event', 'delete_event', 'approve_event', 'reject_event'], true)) {
  att_handle_event_action($db_connection, $_POST['att_action']);
  header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?events=1');
  exit;
}

# --- Handles QR Code Scanning & Attendance Logging ---
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'scan') {
  header('Content-Type: application/json');

  $respond = function (string $status, string $message, array $extra = []): void {
    echo json_encode(['status' => $status, 'message' => $message] + $extra);
    exit;
  };

  if (!$attCanScan) $respond('error', 'You are not allowed to record attendance.');

  $rawStudentID = trim($_POST['studentID'] ?? '');
  $eventKey     = trim($_POST['eventID'] ?? '');

  if (!preg_match('/^(\d{4})(\d{5})$/', $rawStudentID, $m)) {
    $respond('error', 'Enter a valid student ID (YYYYXXXXX).');
  }
  if ($eventKey === '') $respond('error', 'Select an event first.');

  try {
    $st = $db_connection->prepare("SELECT studentID, first_name, last_name FROM students WHERE studentID = ? AND YEAR(date_created) = ?");
    $st->execute([(int)$m[2], (int)$m[1]]);
    $stuRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$stuRow) $respond('error', 'Student not found.');
    $studentID = (int)$stuRow['studentID'];
    $who = ucwords(strtolower($stuRow['first_name'] . ' ' . $stuRow['last_name']));

    $ev = $db_connection->prepare("SELECT eventID, status FROM events WHERE eventID = ? OR eventName = ? LIMIT 1");
    $ev->execute([is_numeric($eventKey) ? (int)$eventKey : 0, $eventKey]);
    $event = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$event) $respond('error', 'Invalid event specified.');
    if ($event['status'] !== 'ACTIVE') $respond('error', 'This event is not active, so attendance cannot be recorded.');
    $eventID = (int)$event['eventID'];

    $chk = $db_connection->prepare("SELECT attendanceID, timeout, TIMESTAMPDIFF(SECOND, timein, NOW()) AS secs FROM attendance WHERE studentID = ? AND eventID = ? ORDER BY attendanceID DESC LIMIT 1");
    $chk->execute([$studentID, $eventID]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $db_connection->prepare("INSERT INTO attendance (eventID, facultyID, studentID, timein) VALUES (?, ?, ?, NOW())")
                    ->execute([$eventID, $attFacultyID, $studentID]);
      $respond('success', "Time-in logged for $who.", ['type' => 'timein']);
    }
    if (empty($row['timeout'])) {
      if ((int)$row['secs'] < 60) $respond('info', "$who just timed in. Scan again later to time out.");
      $db_connection->prepare("UPDATE attendance SET timeout = NOW() WHERE attendanceID = ?")->execute([(int)$row['attendanceID']]);
      $respond('success', "Time-out recorded for $who.", ['type' => 'timeout']);
    }
    $respond('info', "$who has already completed time-in and time-out for this event.");
  } catch (Exception $e) {
    error_log('Attendance scan failed: ' . $e->getMessage());
    $respond('error', ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.');
  }
}

const ATT_GRACE_MIN = 15;

if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$progStmt = $db_connection->query("SELECT program, department FROM programs");
$allProgs = $progStmt->fetchAll();
$ATT_DEPT_COURSES = [];
foreach ($allProgs as $p) {
  $ATT_DEPT_COURSES[$p['department']][] = $p['program'];
}

if ($attScoped) {
  foreach ($ATT_DEPT_COURSES as $d => $cs) {
    if (in_array($attMyProgram, $cs, true)) { $ATT_DEPT_COURSES = [$d => [$attMyProgram]]; break; }
  }
}

$eventNamesStmt = $db_connection->query("SELECT DISTINCT eventName FROM events WHERE status <> 'PENDING' ORDER BY eventName ASC");
$ATT_EVENT_NAMES = $eventNamesStmt->fetchAll(PDO::FETCH_COLUMN);

$openEventsStmt = $db_connection->query("
  SELECT eventID, eventName, DATE_FORMAT(datetime_Start, '%m/%d/%Y') as d 
  FROM events 
  WHERE status = 'ACTIVE' 
  ORDER BY datetime_Start DESC
");
$ATT_OPEN_EVENTS = $openEventsStmt->fetchAll(PDO::FETCH_ASSOC);
$currentActiveEvent = !empty($ATT_OPEN_EVENTS) ? $ATT_OPEN_EVENTS[0]['eventName'] : null;

function att_students(): array {
  global $db_connection;
  $stmt = $db_connection->query("
    SELECT s.studentID, s.first_name, s.last_name, s.program, s.date_created, p.department 
    FROM students s
    LEFT JOIN programs p ON s.program = p.program
    ORDER BY s.last_name ASC
  ");
  $rows = $stmt->fetchAll();
  
  global $attScoped, $attMyProgram;
  $out = [];
  foreach ($rows as $s) {
    if ($attScoped && $s['program'] !== $attMyProgram) continue;
    $year = date('Y', strtotime($s['date_created']));
    
    # CONSISTENT FORMAT: YYYYXXXXX (No hyphen)
    $id = $year . str_pad($s['studentID'], 5, '0', STR_PAD_LEFT);
    
    $f = $s['first_name'];
    $l = $s['last_name'];
    
    $out[$id] = [
      'id' => $id, 
      'db_id' => $s['studentID'],
      'dept' => $s['department'] ?? 'CCS', 
      'course' => $s['program'], 
      'name' => "$f $l", 
      'initials' => strtoupper(($f[0] ?? '') . ($l[0] ?? ''))
    ];
  }
  return $out;
}

function att_records(array $stu): array {
  global $db_connection;
  $stmt = $db_connection->prepare("
    SELECT 
      a.attendanceID as record_id,
      e.eventName as event_name,
      YEAR(e.datetime_Start) as event_year,
      a.timein,
      a.timeout,
      e.datetime_Start,
      e.datetime_End,
      IFNULL(CONCAT('Mr./Ms. ', f.last_name, ', ', f.first_name), 'TBA') as instructor_name
    FROM attendance a
    JOIN events e ON a.eventID = e.eventID
    LEFT JOIN faculty f ON a.facultyID = f.facultyID
    WHERE a.studentID = ? 
    ORDER BY e.datetime_Start DESC
  ");
  $stmt->execute([$stu['db_id']]);
  $rows = $stmt->fetchAll();
  
  $out = [];
  foreach ($rows as $r) {
    $timeInObj = new DateTime($r['timein']);
    $timeOutObj = $r['timeout'] ? new DateTime($r['timeout']) : null;
    $tinStr = $timeInObj->format('h:i A');
    $toutStr = $timeOutObj ? $timeOutObj->format('h:i A') : '--';
    
    $hours = 0;
    if ($timeOutObj) {
      $diff = $timeInObj->diff($timeOutObj);
      $hours = round(($diff->h * 60 + $diff->i) / 60, 1);
    }
    
    $graceEnd = (new DateTime($r['datetime_Start']))->modify('+' . ATT_GRACE_MIN . ' minutes');
    $status   = ($timeInObj > $graceEnd) ? 'LATE' : 'ON-TIME';
    
    $out[] = [
      'id' => $r['record_id'], 
      'event' => $r['event_name'] . ' ' . $r['event_year'],
      'eventName' => $r['event_name'], 
      'year' => (int)$r['event_year'], 
      'date' => $timeInObj->format('m - d - Y'),
      'status' => $status, 
      'tin' => $tinStr, 
      'tout' => $toutStr, 
      'hours' => $hours,
      'who' => $r['instructor_name'],
    ];
  }
  return $out;
}

$students = $students ?? att_students();
$q      = trim($_GET['q'] ?? '');
$dept   = isset($ATT_DEPT_COURSES[$_GET['dept'] ?? '']) ? $_GET['dept'] : null;
$allCourses = $ATT_DEPT_COURSES ? array_merge(...array_values($ATT_DEPT_COURSES)) : [];
$course = in_array($_GET['course'] ?? '', $allCourses, true) ? $_GET['course'] : null;
if ($course && $dept && !in_array($course, $ATT_DEPT_COURSES[$dept], true)) $course = null;
if ($course && !$dept) foreach ($ATT_DEPT_COURSES as $d => $cs) if (in_array($course, $cs, true)) $dept = $d;
$view   = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$sid    = $_GET['student'] ?? null;
$stu    = $sid && isset($students[$sid]) ? $students[$sid] : null;
$event  = in_array($_GET['event'] ?? '', $ATT_EVENT_NAMES, true) ? $_GET['event'] : null;
$yr     = (int)($_GET['year'] ?? 0);
$year   = ($yr >= 2000 && $yr <= 2100) ? $yr : null;

function att_url(array $over = []): string {
  global $q, $dept, $course, $view, $stu, $event, $year, $evpage;
  $p = ['dept' => $dept, 'course' => $course, 'q' => $q ?: null, 'view' => $view === 'list' ? 'list' : null,
        'student' => $stu['id'] ?? null, 'event' => $event, 'year' => $year, 'events' => $evpage ? 1 : null];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

function att_dropdown(string $label, string $allText, array $items, $current, string $param, array $extra = []): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => null] + $extra)) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => $it] + $extra)) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

function sa_url(array $over = []): string {
  global $sa_event, $sa_year;
  $p = ['event' => $sa_event, 'year' => $sa_year];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

function sa_dropdown(string $label, string $allText, array $items, $current, string $param): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => null])) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => $it])) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

function att_norm_name(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/\b(mr|ms|mrs|miss|dr|prof|engr|atty)\b\.?/', ' ', $s);
  $s = preg_replace('/[^a-z0-9ñ ]+/u', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

function att_faculty_names(): array {
  global $db_connection;
  $out = [];
  foreach ($db_connection->query("SELECT facultyID, first_name, middle_name, last_name FROM faculty WHERE LOWER(role) NOT LIKE '%registrar%' ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $out[(int)$f['facultyID']] = ucwords(strtolower(preg_replace('/\s+/', ' ', trim($f['first_name'] . ' ' . $f['middle_name'] . ' ' . $f['last_name']))));
  }
  return $out;
}

function att_find_faculty(string $typed): ?int {
  global $db_connection;
  $want = att_norm_name($typed);
  if ($want === '') return null;
  foreach ($db_connection->query("SELECT facultyID, first_name, middle_name, last_name FROM faculty")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $fn = att_norm_name($f['first_name']); $mn = att_norm_name($f['middle_name']); $ln = att_norm_name($f['last_name']);
    $variants = [trim("$fn $mn $ln"), trim("$fn $ln"), trim("$ln $fn"), trim("$ln $fn $mn")];
    if (in_array($want, $variants, true)) return (int)$f['facultyID'];
  }
  return null;
}

function att_events(): array {
  global $db_connection, $attCanEdit, $attScoped, $attMyProgram, $attMyDept, $attIsDean, $attFacultyID;
  $sql = "SELECT e.eventID, e.eventName, e.program, e.department, e.datetime_Start, e.datetime_End, e.eventCoordinator, e.status,
                 TRIM(CONCAT(f.first_name, ' ', f.middle_name, ' ', f.last_name)) AS coord_name,
                 (SELECT COUNT(*) FROM attendance a WHERE a.eventID = e.eventID) AS att_count
          FROM events e LEFT JOIN faculty f ON f.facultyID = e.eventCoordinator";
  $args = []; $where = [];
  if ($attScoped) {
    $where[] = "(e.program = ? OR (e.program IS NULL AND (e.department IS NULL OR e.department = ?)))";
    array_push($args, $attMyProgram, $attMyDept);
  }
  if (!$attIsDean) {  
    $where[] = "(e.status <> 'PENDING' OR e.eventCoordinator = ?)";
    $args[] = $attFacultyID;
  }
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= " ORDER BY e.datetime_Start DESC, e.eventID DESC";
  $st = $db_connection->prepare($sql);
  $st->execute($args);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $s = new DateTime($r['datetime_Start']);
    $en = $r['datetime_End'] ? new DateTime($r['datetime_End']) : null;
    $out[] = [
      'id'      => (int)$r['eventID'],
      'name'    => $r['eventName'],
      'scope'   => $r['program'] ?: ($r['department'] ?: 'ALL'),
      'ymd'     => $s->format('Y-m-d'),
      'date'    => $s->format('m/d/Y'),
      'start'   => $s->format('g:iA'),
      'end'     => $en ? $en->format('g:iA') : '--',
      'start24' => $s->format('H:i'),
      'end24'   => $en ? $en->format('H:i') : '',
      'status'  => strtoupper($r['status']),
      'coord'   => ($r['eventCoordinator'] && trim((string)$r['coord_name']) !== '') ? ucwords(strtolower(preg_replace('/\s+/', ' ', $r['coord_name']))) : '',
      'att'     => (int)$r['att_count'],
    
      'canEdit' => $attCanEdit && (!$attScoped || $r['program'] === $attMyProgram) && ($attIsDean || (int)$r['eventCoordinator'] === $attFacultyID),
      'review'  => $attIsDean && strtoupper($r['status']) === 'PENDING',  
    ];
  }
  return $out;
}

function att_handle_event_action(PDO $db, string $act): void {
  global $attCanEdit, $attScoped, $attMyProgram, $attMyDept, $attIsDean, $attFacultyID;
  if (in_array($act, ['approve_event', 'reject_event'], true)) {
    if (!$attIsDean) { $_SESSION['error'] = 'Only the dean can approve or reject events.'; return; }
    try {
      $id = (int)($_POST['eventID'] ?? 0);
      $q = $db->prepare("SELECT status FROM events WHERE eventID = ?");
      $q->execute([$id]);
      $cur = $q->fetchColumn();
      if ($cur === false) { $_SESSION['error'] = 'That event no longer exists.'; return; }
      if (strtoupper((string)$cur) !== 'PENDING') { $_SESSION['error'] = 'This event is not waiting for approval.'; return; }
      if ($act === 'approve_event') {
        $db->prepare("UPDATE events SET status = 'ACTIVE' WHERE eventID = ? AND status = 'PENDING'")->execute([$id]);
        $_SESSION['message'] = 'Event approved.';
      } else {
       
        $db->prepare("DELETE FROM events WHERE eventID = ? AND status = 'PENDING'")->execute([$id]);
        $_SESSION['message'] = 'Event rejected and deleted.';
      }
    } catch (Exception $e) {
      error_log('Attendance approval failed: ' . $e->getMessage());
      $_SESSION['error'] = ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.';
    }
    return;
  }
  if (!$attCanEdit) {
    $_SESSION['error'] = 'You are not allowed to add, edit or delete events.';
    return;
  }
  try {
    $id = (int)($_POST['eventID'] ?? 0);
    $existing = null;
    if ($act !== 'add_event') {
      $q = $db->prepare("SELECT eventID, eventName, program, status, eventCoordinator FROM events WHERE eventID = ?");
      $q->execute([$id]);
      $existing = $q->fetch(PDO::FETCH_ASSOC);
      if (!$existing) { $_SESSION['error'] = 'That event no longer exists.'; return; }
      if ($attScoped && $existing['program'] !== $attMyProgram) { $_SESSION['error'] = 'You can only change events of your own program.'; return; }
      if (!$attIsDean && strtoupper((string)$existing['status']) === 'PENDING' && (int)$existing['eventCoordinator'] !== $attFacultyID) {
        $_SESSION['error'] = 'That event no longer exists.'; return;
      }
      if (!$attIsDean && (int)$existing['eventCoordinator'] !== $attFacultyID) {
        $_SESSION['error'] = 'Only the person who posted this event can edit or delete it.'; return;
      }
    }

    if ($act === 'delete_event') {
      $db->prepare("DELETE FROM events WHERE eventID = ?")->execute([$id]);
      $_SESSION['message'] = 'Event deleted.';
      return;
    }

    $name   = trim((string)($_POST['eventName'] ?? ''));
    if (!$attIsDean && $act === 'edit_event') $name = (string)$existing['eventName'];  
    $date   = (string)($_POST['date'] ?? '');
    $start  = (string)($_POST['start'] ?? '');
    $end    = (string)($_POST['end'] ?? '');
    $status = strtoupper(trim((string)($_POST['status'] ?? '')));
   
    if (!$attIsDean) $status = 'PENDING';
    $coord  = trim((string)($_POST['coordinator'] ?? ''));

    $d = DateTime::createFromFormat('!Y-m-d', $date);
    $timeOk = fn($t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    if ($name === '' || mb_strlen($name) > 250)            { $_SESSION['error'] = 'Enter the event name.'; return; }
    if (!$d || $d->format('Y-m-d') !== $date)              { $_SESSION['error'] = 'Enter a valid date.'; return; }
    if (!in_array($status, ATT_EVENT_STATUSES, true))      { $_SESSION['error'] = 'Choose a status.'; return; }
    if (!$timeOk($start) || !$timeOk($end))                { $_SESSION['error'] = 'Enter the start and end time.'; return; }
    if ($end <= $start)                                    { $_SESSION['error'] = 'The end time must be after the start time.'; return; }

    $coordID = null;
    if (!$attIsDean) {
      $coordID = $attFacultyID ?: null; 
    } elseif ($coord !== '') {
      $coordID = att_find_faculty($coord);
      if ($coordID === null || !isset(att_faculty_names()[$coordID])) { $_SESSION['error'] = 'Event coordinator not found. Pick a name from the list.'; return; }
    }
    $dtStart = "$date $start:00";
    $dtEnd   = "$date $end:00";

    if ($act === 'add_event') {
      $db->prepare("INSERT INTO events (eventName, program, department, datetime_Start, datetime_End, eventCoordinator, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
         ->execute([$name, $attScoped ? $attMyProgram : null, $attMyDept, $dtStart, $dtEnd, $coordID, $status]);
      $_SESSION['message'] = $attIsDean ? 'Event added.' : 'Event sent to the dean for approval.';
    } else {
      $db->prepare("UPDATE events SET eventName = ?, datetime_Start = ?, datetime_End = ?, eventCoordinator = ?, status = ? WHERE eventID = ?")
         ->execute([$name, $dtStart, $dtEnd, $coordID, $status, $id]);
      $_SESSION['message'] = $attIsDean ? 'Event updated.' : 'Event updated and sent to the dean for approval.';
    }
  } catch (Exception $e) {
    error_log('Attendance events failed: ' . $e->getMessage());
    $_SESSION['error'] = ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.';
  }
}

if ($evpage) {
  $events = att_events();
  $ATT_FACULTY_NAMES = $attCanEdit ? array_values(att_faculty_names()) : [];
  $mq = $db_connection->prepare("SELECT TRIM(CONCAT(first_name, ' ', middle_name, ' ', last_name)) FROM faculty WHERE facultyID = ?");
  $mq->execute([$attFacultyID]);
  $ATT_ME_NAME = ucwords(strtolower(preg_replace('/\s+/', ' ', (string)$mq->fetchColumn())));
} elseif ($attGroup === 'student') {
  $me = $attStudentKey ? ($students[$attStudentKey] ?? null) : null;
  if (!$me) {
    $_SESSION['error'] = 'Student record not found.';
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
  }
  
  $qrCheckStmt = $db_connection->prepare("SELECT (qr_image_data IS NOT NULL AND LENGTH(qr_image_data) > 0) FROM students WHERE studentID = ?");
  $qrCheckStmt->execute([$me['db_id']]);
  $attHasQr = (bool)$qrCheckStmt->fetchColumn();
  $GLOBALS['attHasQr'] = $attHasQr;

  $sa_all   = att_records($me);
  $sa_years = array_values(array_unique(array_column($sa_all, 'year'))); rsort($sa_years);
  $sa_event = in_array($_GET['event'] ?? '', $ATT_EVENT_NAMES, true) ? $_GET['event'] : null;
  $sa_year  = in_array((int)($_GET['year'] ?? 0), $sa_years, true) ? (int)$_GET['year'] : null;
  $recs   = array_values(array_filter($sa_all, fn($r) => (!$sa_event || $r['eventName'] === $sa_event) && (!$sa_year || $r['year'] === $sa_year)));
  $cnt    = fn($st) => count(array_filter($recs, fn($r) => $r['status'] === $st));
  $ontime = $cnt('ON-TIME'); $late = $cnt('LATE'); $absent = $cnt('ABSENT');
  $hrs    = round(array_sum(array_column($recs, 'hours')), 1);
} elseif (!$stu) {
  $rows = array_filter($students, function ($s) use ($dept, $course, $q) {
    if ($dept && $s['dept'] !== $dept) return false;
    if ($course && $s['course'] !== $course) return false;
    if ($q === '') return true;
    foreach ([$s['id'], $s['name'], $s['dept'], $s['course']] as $f) if (stripos($f, $q) !== false) return true;
    return false;
  });
} else {
  $all  = att_records($stu);
  $recs = array_values(array_filter($all, fn($r) => (!$event || $r['eventName'] === $event) && (!$year || $r['year'] === $year)));
  $cnt  = fn($st) => count(array_filter($recs, fn($r) => $r['status'] === $st));
  $ontime = $cnt('ON-TIME'); $late = $cnt('LATE'); $absent = $cnt('ABSENT');
  $hrs = round(array_sum(array_column($all, 'hours')), 1);
  $years = array_values(array_unique(array_column($all, 'year'))); rsort($years);
}

$GLOBALS['q'] = $q; $GLOBALS['dept'] = $dept; $GLOBALS['course'] = $course; $GLOBALS['view'] = $view;
$GLOBALS['stu'] = $stu; $GLOBALS['event'] = $event; $GLOBALS['year'] = $year;
$GLOBALS['currentActiveEvent'] = $currentActiveEvent ?? null;
$GLOBALS['ATT_OPEN_EVENTS'] = $ATT_OPEN_EVENTS ?? [];
$GLOBALS['evpage'] = $evpage;