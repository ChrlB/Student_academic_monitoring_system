<?php
require_once __DIR__.'/../Services/auth.php';
require_once __DIR__.'/../PDAO/DAO.php';
global $db_connection;

$_SESSION["page"] = "ATTENDANCE | Faculty";

#Starts session
if (session_status() === PHP_SESSION_NONE) session_start();

#Checks role
function att_role_group(string $role): string {
  $r = strtolower(trim($role));
  if (preg_match('/coordinator|dean|registrar|admin/', $r)) return 'manager';
  if (preg_match('/prof|faculty|instructor|teacher/', $r))  return 'faculty';
  return 'student';
}

$attRole   = strtolower(trim($_SESSION['role'] ?? 'student'));
$attUserRaw    = (string)($_SESSION['userID'] ?? '');
// faculty userID = "F" + year + 5-digit facultyID (F202400012); students = year + 5-digit studentID (202600001)
$attGroup  = att_role_group($attRole);          
// real_userID is the numeric facultyID (DAO.php); fall back to cutting it out of "F202400012"
$attFacultyID  = ($attGroup !== 'student') ? (int)($_SESSION['real_userID'] ?? substr($attUserRaw, 5)) : 0;
$attStudentKey = ($attGroup === 'student' && preg_match('/^(\d{4})(\d{5})$/', $attUserRaw, $attM)) ? $attM[1] . '-' . $attM[2] : null;  // "2026-00001"

$_SESSION["page"] = ($attGroup === 'student') ? "ATTENDANCE | Student" : "ATTENDANCE | Faculty";

#Handles record deletion (Fixed to use attendanceID primary key)
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'delete') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $ids = array_map('intval', array_filter((array)($_POST['ids'] ?? []), 'is_numeric'));
  
  if ($ids) {
    global $db_connection;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $db_connection->prepare("DELETE FROM attendance WHERE attendanceID IN ($placeholders)");
    $stmt->execute($ids);
    
    $_SESSION['message'] = count($ids).' record'.(count($ids) === 1 ? '' : 's').' deleted.';
  } else {
    $_SESSION['error'] = 'Select at least one record to delete.';
  }
  header('Location: '.($_SERVER['REQUEST_URI'] ?? ''));
  exit;
}

# --- Handles QR Code Scanning & Attendance Logging ---
# The QR modal POSTs here (the attendance page URL, NOT attendance_logic.php) with att_action=scan.
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'scan') {
  header('Content-Type: application/json');

  $respond = function (string $status, string $message, array $extra = []): void {
    echo json_encode(['status' => $status, 'message' => $message] + $extra);
    exit;
  };

  if ($attFacultyID <= 0) $respond('error', 'Your faculty account could not be identified.');

  $rawStudentID = trim($_POST['studentID'] ?? '');
  $eventKey     = trim($_POST['eventID'] ?? '');       // an eventID or an event name

  // "2026-00001" or "202600001" -> year 2026, studentID 1
  if (!preg_match('/^(\d{4})\D?(\d{5})$/', $rawStudentID, $m)) {
    $respond('error', 'Enter a valid student ID (xxxx-xxxxx).');
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
      // attendance.userID is the faculty/manager who scanned
      $db_connection->prepare("INSERT INTO attendance (eventID, userID, studentID, timein) VALUES (?, ?, ?, NOW())")
                    ->execute([$eventID, $attFacultyID, $studentID]);
      $respond('success', "Time-in logged for $who.", ['type' => 'timein']);
    }
    if (empty($row['timeout'])) {
      // a second scan within a minute of the time-in is almost always an accidental double scan
      if ((int)$row['secs'] < 60) $respond('info', "$who just timed in. Scan again later to time out.");
      $db_connection->prepare("UPDATE attendance SET timeout = NOW() WHERE attendanceID = ?")->execute([(int)$row['attendanceID']]);
      $respond('success', "Time-out recorded for $who.", ['type' => 'timeout']);
    }
    $respond('info', "$who has already completed time-in and time-out for this event.");
  } catch (Exception $e) {
    error_log('Attendance scan failed: ' . $e->getMessage());
    $respond('error', 'Database error. Please try again.');
  }
}

#Minutes after the event start before a time-in counts as late
const ATT_GRACE_MIN = 15;

#Escapes output text
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

#Loads departments and programs from database
$progStmt = $db_connection->query("SELECT program, department FROM programs");
$allProgs = $progStmt->fetchAll();
$ATT_DEPT_COURSES = [];
foreach ($allProgs as $p) {
  $ATT_DEPT_COURSES[$p['department']][] = $p['program'];
}

#Loads event names from database
$eventNamesStmt = $db_connection->query("SELECT DISTINCT eventName FROM events ORDER BY eventName ASC");
$ATT_EVENT_NAMES = $eventNamesStmt->fetchAll(PDO::FETCH_COLUMN);

#Finds the active event based on status = 'ACTIVE', or falls back to latest event
$activeEventStmt = $db_connection->query("
  SELECT eventName FROM events 
  WHERE status = 'ACTIVE' 
  ORDER BY datetime_Start DESC LIMIT 1
");
$currentActiveEvent = $activeEventStmt->fetchColumn();

if (!$currentActiveEvent && !empty($ATT_EVENT_NAMES)) {
  $fallbackStmt = $db_connection->query("SELECT eventName FROM events ORDER BY datetime_Start DESC LIMIT 1");
  $currentActiveEvent = $fallbackStmt->fetchColumn();
}

#Fetches student list and profiles
function att_students(): array {
  global $db_connection;
  
  $stmt = $db_connection->query("
    SELECT s.studentID, s.first_name, s.last_name, s.program, s.date_created, p.department 
    FROM students s
    LEFT JOIN programs p ON s.program = p.program
    ORDER BY s.last_name ASC
  ");
  $rows = $stmt->fetchAll();
  
  $out = [];
  foreach ($rows as $s) {
    $year = date('Y', strtotime($s['date_created']));
    $id = $year . '-' . str_pad($s['studentID'], 5, '0', STR_PAD_LEFT);
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

#Fetches attendance records for a specific student (Fixed to use facultyID join)
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
    
    // LATE when the student timed in more than ATT_GRACE_MIN minutes after the event started
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

#Sets up request state and filters
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

#Builds query URL parameters
function att_url(array $over = []): string {
  global $q, $dept, $course, $view, $stu, $event, $year;
  $p = ['dept' => $dept, 'course' => $course, 'q' => $q ?: null, 'view' => $view === 'list' ? 'list' : null,
        'student' => $stu['id'] ?? null, 'event' => $event, 'year' => $year];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

#Renders filter dropdown component
function att_dropdown(string $label, string $allText, array $items, $current, string $param, array $extra = []): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => null] + $extra)) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => $it] + $extra)) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

#Builds student view URL parameters
function sa_url(array $over = []): string {
  global $sa_event, $sa_year;
  $p = ['event' => $sa_event, 'year' => $sa_year];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

#Renders student view dropdown component
function sa_dropdown(string $label, string $allText, array $items, $current, string $param): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => null])) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => $it])) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

#Processes view branch logic and data
if ($attGroup === 'student') {
  // the logged-in student (key like "2026-00001", built at the top of this file)
  $me = $attStudentKey ? ($students[$attStudentKey] ?? null) : null;
  if (!$me) {
    $_SESSION['error'] = 'Student record not found.';
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
  }
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
  $hrs = round(array_sum(array_column($recs, 'hours')), 1);
  $years = array_values(array_unique(array_column($all, 'year'))); rsort($years);
}

$GLOBALS['q'] = $q; $GLOBALS['dept'] = $dept; $GLOBALS['course'] = $course; $GLOBALS['view'] = $view;
$GLOBALS['stu'] = $stu; $GLOBALS['event'] = $event; $GLOBALS['year'] = $year;
$GLOBALS['currentActiveEvent'] = $currentActiveEvent ?? null;