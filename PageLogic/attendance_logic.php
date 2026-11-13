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
$attGroup  = att_role_group($attRole);          
$attFacultyID  = ($attGroup !== 'student') ? (int)($_SESSION['real_userID'] ?? substr($attUserRaw, 5)) : 0;
$attStudentKey = ($attGroup === 'student' && preg_match('/^(\d{4})(\d{5})$/', $attUserRaw, $attM)) ? $attM[1] . '-' . $attM[2] : null;  // "2026-00001"

$_SESSION["page"] = ($attGroup === 'student') ? "ATTENDANCE | Student" : "ATTENDANCE | Faculty";

#Position inside the faculty:
#  program coordinator -> access to everything
#  dean    -> view everything and scan, but cannot add, edit or delete
#  registrar -> view only
#  any other faculty   -> view only and scan
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
$attCanEdit   = in_array($attPos, ['coordinator', 'admin', 'dean', 'faculty'], true);   // add / edit / delete
$attMyProgram = null; $attMyDept = null;
if ($attGroup !== 'student' && $attFacultyID > 0) {
  $fq = $db_connection->prepare("SELECT program, department FROM faculty WHERE facultyID = ?");
  $fq->execute([$attFacultyID]);
  $fr = $fq->fetch(PDO::FETCH_ASSOC) ?: [];
  $attMyProgram = $fr['program']    ?? null;
  $attMyDept    = $fr['department'] ?? null;
}
$attCanScan   = in_array($attPos, ['coordinator', 'faculty', 'dean'], true);
$attScoped = false;
$evpage    = ($attGroup !== 'student' && !empty($_GET['events']));
if ($evpage) $_SESSION["page"] = "EVENTS | " . $attLabel;

#The functions below read these through $GLOBALS
$GLOBALS['attPos'] = $attPos; $GLOBALS['attCanEdit'] = $attCanEdit; $GLOBALS['attScoped'] = $attScoped;
$GLOBALS['attMyProgram'] = $attMyProgram; $GLOBALS['attMyDept'] = $attMyDept; $GLOBALS['evpage'] = $evpage;
$GLOBALS['attCanScan'] = $attCanScan;

#While testing: true shows the real database error in the scan popup. Set to false before going live.
const ATT_DEBUG = true;

#Status choices for an event (the events.status column must have the same values)
const ATT_EVENT_STATUSES = ['ACTIVE', 'INACTIVE', 'FINISHED', 'RESCHEDULE', 'PENDING'];

#Handles record deletion (program coordinator only, and only students of their own program)
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

#Handles Add / Edit / Delete of an event (program coordinator only)
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['att_action'] ?? '', ['add_event', 'edit_event', 'delete_event'], true)) {
  att_handle_event_action($db_connection, $_POST['att_action']);
  header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?events=1');
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

  if (!$attCanScan) $respond('error', 'You are not allowed to record attendance.');

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
      // attendance.facultyID is the faculty/manager who scanned
      $db_connection->prepare("INSERT INTO attendance (eventID, facultyID, studentID, timein) VALUES (?, ?, ?, NOW())")
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
    $respond('error', ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.');
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

#A coordinator only works with their own program
if ($attScoped) {
  foreach ($ATT_DEPT_COURSES as $d => $cs) {
    if (in_array($attMyProgram, $cs, true)) { $ATT_DEPT_COURSES = [$d => [$attMyProgram]]; break; }
  }
}

#Loads event names from database
$eventNamesStmt = $db_connection->query("SELECT DISTINCT eventName FROM events ORDER BY eventName ASC");
$ATT_EVENT_NAMES = $eventNamesStmt->fetchAll(PDO::FETCH_COLUMN);

#Finds the active event based strictly on status = 'ACTIVE'
$activeEventStmt = $db_connection->query("
  SELECT eventName FROM events 
  WHERE status = 'ACTIVE' 
  ORDER BY datetime_Start DESC LIMIT 1
");
$currentActiveEvent = $activeEventStmt->fetchColumn();

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
  
  global $attScoped, $attMyProgram;
  $out = [];
  foreach ($rows as $s) {
    if ($attScoped && $s['program'] !== $attMyProgram) continue;
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
  global $q, $dept, $course, $view, $stu, $event, $year, $evpage;
  $p = ['dept' => $dept, 'course' => $course, 'q' => $q ?: null, 'view' => $view === 'list' ? 'list' : null,
        'student' => $stu['id'] ?? null, 'event' => $event, 'year' => $year, 'events' => $evpage ? 1 : null];
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

#---------------- Events (list page + add / edit / delete) ----------------

#"Mr. Johniel Zar Mendoza" / "Mendoza, Johniel" -> "johniel zar mendoza" (for matching a typed name to a faculty)
function att_norm_name(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/\b(mr|ms|mrs|miss|dr|prof|engr|atty)\b\.?/', ' ', $s);
  $s = preg_replace('/[^a-z0-9ñ ]+/u', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

#All faculty as [facultyID => "First Middle Last"]
function att_faculty_names(): array {
  global $db_connection;
  $out = [];
  foreach ($db_connection->query("SELECT facultyID, first_name, middle_name, last_name FROM faculty ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $out[(int)$f['facultyID']] = ucwords(strtolower(preg_replace('/\s+/', ' ', trim($f['first_name'] . ' ' . $f['middle_name'] . ' ' . $f['last_name']))));
  }
  return $out;
}

#Typed coordinator name -> facultyID (null when it does not match anybody)
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

#Events the logged-in faculty may see (a coordinator: own program + their department-wide events)
function att_events(): array {
  global $db_connection, $attCanEdit, $attScoped, $attMyProgram, $attMyDept;
  $sql = "SELECT e.eventID, e.eventName, e.program, e.department, e.datetime_Start, e.datetime_End, e.eventCoordinator, e.status,
                 TRIM(CONCAT(f.first_name, ' ', f.middle_name, ' ', f.last_name)) AS coord_name,
                 (SELECT COUNT(*) FROM attendance a WHERE a.eventID = e.eventID) AS att_count
          FROM events e LEFT JOIN faculty f ON f.facultyID = e.eventCoordinator";
  $args = [];
  if ($attScoped) {
    $sql .= " WHERE e.program = ? OR (e.program IS NULL AND (e.department IS NULL OR e.department = ?))";
    $args = [$attMyProgram, $attMyDept];
  }
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
      // only a coordinator can edit / delete, and only events of their own program
      'canEdit' => $attCanEdit && (!$attScoped || $r['program'] === $attMyProgram),
    ];
  }
  return $out;
}

#Add / edit / delete an event. Always ends with a message in the session (shown by popup_message.php).
function att_handle_event_action(PDO $db, string $act): void {
  global $attCanEdit, $attScoped, $attMyProgram, $attMyDept;
  if (!$attCanEdit) {
    $_SESSION['error'] = 'You are not allowed to add, edit or delete events.';
    return;
  }
  try {
    $id = (int)($_POST['eventID'] ?? 0);
    $existing = null;
    if ($act !== 'add_event') {
      $q = $db->prepare("SELECT eventID, program FROM events WHERE eventID = ?");
      $q->execute([$id]);
      $existing = $q->fetch(PDO::FETCH_ASSOC);
      if (!$existing) { $_SESSION['error'] = 'That event no longer exists.'; return; }
      if ($attScoped && $existing['program'] !== $attMyProgram) { $_SESSION['error'] = 'You can only change events of your own program.'; return; }
    }

    if ($act === 'delete_event') {
      // attendance rows of the event are removed with it (ON DELETE CASCADE)
      $db->prepare("DELETE FROM events WHERE eventID = ?")->execute([$id]);
      $_SESSION['message'] = 'Event deleted.';
      return;
    }

    $name   = trim((string)($_POST['eventName'] ?? ''));
    $date   = (string)($_POST['date'] ?? '');
    $start  = (string)($_POST['start'] ?? '');
    $end    = (string)($_POST['end'] ?? '');
    $status = strtoupper(trim((string)($_POST['status'] ?? '')));
    $coord  = trim((string)($_POST['coordinator'] ?? ''));

    $d = DateTime::createFromFormat('!Y-m-d', $date);
    $timeOk = fn($t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    if ($name === '' || mb_strlen($name) > 250)            { $_SESSION['error'] = 'Enter the event name.'; return; }
    if (!$d || $d->format('Y-m-d') !== $date)              { $_SESSION['error'] = 'Enter a valid date.'; return; }
    if (!in_array($status, ATT_EVENT_STATUSES, true))      { $_SESSION['error'] = 'Choose a status.'; return; }
    if (!$timeOk($start) || !$timeOk($end))                { $_SESSION['error'] = 'Enter the start and end time.'; return; }
    if ($end <= $start)                                    { $_SESSION['error'] = 'The end time must be after the start time.'; return; }

    $coordID = null;
    if ($coord !== '') {
      $coordID = att_find_faculty($coord);
      if ($coordID === null) { $_SESSION['error'] = 'Event coordinator not found. Pick a name from the list.'; return; }
    }
    $dtStart = "$date $start:00";
    $dtEnd   = "$date $end:00";

    if ($act === 'add_event') {
      // a coordinator's events belong to their program; (admin: department-wide)
      $db->prepare("INSERT INTO events (eventName, program, department, datetime_Start, datetime_End, eventCoordinator, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
         ->execute([$name, $attScoped ? $attMyProgram : null, $attMyDept, $dtStart, $dtEnd, $coordID, $status]);
      $_SESSION['message'] = 'Event added.';
    } else {
      // datetime_Start is always written here, so nothing can reset it by accident
      $db->prepare("UPDATE events SET eventName = ?, datetime_Start = ?, datetime_End = ?, eventCoordinator = ?, status = ? WHERE eventID = ?")
         ->execute([$name, $dtStart, $dtEnd, $coordID, $status, $id]);
      $_SESSION['message'] = 'Event updated.';
    }
  } catch (Exception $e) {
    error_log('Attendance events failed: ' . $e->getMessage());
    $_SESSION['error'] = ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.';
  }
}

#Processes view branch logic and data
if ($evpage) {
  $events = att_events();
  $ATT_FACULTY_NAMES = $attCanEdit ? array_values(att_faculty_names()) : [];
} elseif ($attGroup === 'student') {
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
$GLOBALS['evpage'] = $evpage;