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
$attUserID = (int)($_SESSION['userID'] ?? 0);   
$attGroup  = att_role_group($attRole);          

$_SESSION["page"] = ($attGroup === 'student') ? "ATTENDANCE | Student" : "ATTENDANCE | Faculty";

#Handles record deletion
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'delete') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $ids = array_filter((array)($_POST['ids'] ?? []), 'is_numeric'); 
  
  if ($ids) {
    global $db_connection;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $db_connection->prepare("DELETE FROM attendance WHERE ID IN ($placeholders)");
    $stmt->execute($ids);
    
    $_SESSION['message'] = count($ids).' record'.(count($ids) === 1 ? '' : 's').' deleted.';
  } else {
    $_SESSION['error'] = 'Select at least one record to delete.';
  }
  header('Location: '.($_SERVER['REQUEST_URI'] ?? ''));
  exit;
}

# --- Handles QR Code Scanning & Attendance Logging ---
if ($attGroup !== 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['att_action'] ?? '') === 'scan') {
  header('Content-Type: application/json');
  $rawStudentID = trim($_POST['studentID'] ?? '');
  $eventID      = trim($_POST['eventID'] ?? '');
  $facultyID    = $attUserID; // Automatically pulls the logged-in faculty/manager ID

  // Extract numeric studentID if formatted with year prefix (e.g., "2024-00012")
  $studentID = $rawStudentID;
  if (strpos($rawStudentID, '-') !== false) {
    $parts = explode('-', $rawStudentID);
    $studentID = end($parts);
  }
  $studentID = (int)$studentID;

  if ($studentID > 0 && !empty($eventID)) {
    try {
      // Check existing attendance record for this student and event name/ID
      $checkStmt = $db_connection->prepare("
        SELECT a.ID, a.timein, a.timeout 
        FROM attendance a
        JOIN events e ON a.eventID = e.eventID
        WHERE a.studentID = ? AND (e.eventID = ? OR e.eventName = ?)
      ");
      $checkStmt->execute([$studentID, $eventID, $eventID]);
      $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        // If already timed in but no timeout recorded yet, record timeout
        if (empty($row['timeout'])) {
          $updateStmt = $db_connection->prepare("UPDATE attendance SET timeout = NOW() WHERE ID = ?");
          $updateStmt->execute([$row['ID']]);
          echo json_encode([
            'status' => 'success', 
            'type' => 'timeout', 
            'message' => 'Timeout recorded successfully for ID: ' . $rawStudentID
          ]);
        } else {
          echo json_encode([
            'status' => 'info', 
            'message' => 'Student has already completed time-in and time-out for this event.'
          ]);
        }
      } else {
        // Resolve eventID if $eventID passed is the event name string
        $resolvedEventID = $eventID;
        if (!is_numeric($eventID)) {
          $evLookup = $db_connection->prepare("SELECT eventID FROM events WHERE eventName = ? LIMIT 1");
          $evLookup->execute([$eventID]);
          $resolvedEventID = $evLookup->fetchColumn();
        }

        if ($resolvedEventID) {
          // Insert new time-in record
          $insertStmt = $db_connection->prepare("INSERT INTO attendance (eventID, facultyID, studentID, timein) VALUES (?, ?, ?, NOW())");
          $insertStmt->execute([$resolvedEventID, $facultyID, $studentID]);
          echo json_encode([
            'status' => 'success', 
            'type' => 'timein', 
            'message' => 'Time-in logged successfully for ID: ' . $rawStudentID
          ]);
        } else {
          echo json_encode(['status' => 'error', 'message' => 'Invalid event specified.']);
        }
      }
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid student ID or missing event ID.']);
  }
  exit;
}

#Escapes output text
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

#Loads departments and programs from database[cite: 6]
$progStmt = $db_connection->query("SELECT program, department FROM programs");
$allProgs = $progStmt->fetchAll();
$ATT_DEPT_COURSES = [];
foreach ($allProgs as $p) {
  $ATT_DEPT_COURSES[$p['department']][] = $p['program'];
}

#Loads event names from database (using eventName column)[cite: 13]
$eventNamesStmt = $db_connection->query("SELECT DISTINCT eventName FROM events ORDER BY eventName ASC");
$ATT_EVENT_NAMES = $eventNamesStmt->fetchAll(PDO::FETCH_COLUMN);

#Finds the active event based on status = 'ACTIVE', or falls back to latest event[cite: 13]
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

#Fetches student list and profiles[cite: 6]
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

#Fetches attendance records for a specific student[cite: 6]
function att_records(array $stu): array {
  global $db_connection;
  
  $stmt = $db_connection->prepare("
    SELECT 
      a.ID as record_id,
      e.eventName as event_name,
      YEAR(e.datetime_Start) as event_year,
      a.timein,
      a.timeout,
      e.datetime_Start,
      CONCAT('Mr./Ms. ', f.last_name, ', ', f.first_name) as instructor_name
    FROM attendance a
    JOIN events e ON a.eventID = e.eventID
    JOIN faculty f ON a.facultyID = f.facultyID
    WHERE a.studentID = ?
    ORDER BY e.datetime_Start DESC
  ");
  
  $rawStudentID = explode('-', $stu['id'])[1] ?? $stu['id'];
  $stmt->execute([$rawStudentID]);
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
    
    $status = 'ON-TIME'; 
    
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

#Sets up request state and filters[cite: 6]
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
$year   = in_array((int)($_GET['year'] ?? 0), [2025, 2026], true) ? (int)$_GET['year'] : null;

#Builds query URL parameters[cite: 6]
function att_url(array $over = []): string {
  global $q, $dept, $course, $view, $stu, $event, $year;
  $p = ['dept' => $dept, 'course' => $course, 'q' => $q ?: null, 'view' => $view === 'list' ? 'list' : null,
        'student' => $stu['id'] ?? null, 'event' => $event, 'year' => $year];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

#Renders filter dropdown component[cite: 6]
function att_dropdown(string $label, string $allText, array $items, $current, string $param, array $extra = []): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => null] + $extra)) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(att_url([$param => $it] + $extra)) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

#Builds student view URL parameters[cite: 6]
function sa_url(array $over = []): string {
  global $sa_event, $sa_year;
  $p = ['event' => $sa_event, 'year' => $sa_year];
  foreach ($over as $k => $v) $p[$k] = $v;
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

#Renders student view dropdown component[cite: 6]
function sa_dropdown(string $label, string $allText, array $items, $current, string $param): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => null])) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e(sa_url([$param => $it])) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

#Processes view branch logic and data[cite: 6]
if ($attGroup === 'student') {
  $me       = $students[array_key_first($students)] ?? null;
  $sa_all   = $me ? att_records($me) : [];
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