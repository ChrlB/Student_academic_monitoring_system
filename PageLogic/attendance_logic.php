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
$eventNamesStmt = $db_connection->query("SELECT DISTINCT name FROM events ORDER BY name ASC");
$ATT_EVENT_NAMES = $eventNamesStmt->fetchAll(PDO::FETCH_COLUMN);

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

#Fetches attendance records for a specific student
function att_records(array $stu): array {
  global $db_connection;
  
  $stmt = $db_connection->prepare("
    SELECT 
      a.ID as record_id,
      e.name as event_name,
      YEAR(e.datetime_start) as event_year,
      a.timein,
      a.timeout,
      e.datetime_start,
      CONCAT('Mr./Ms. ', f.last_name, ', ', f.first_name) as instructor_name
    FROM attendance a
    JOIN events e ON a.eventID = e.eventID
    JOIN faculty f ON a.facultyID = f.facultyID
    WHERE a.studentID = ?
    ORDER BY e.datetime_start DESC
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
$year   = in_array((int)($_GET['year'] ?? 0), [2025, 2026], true) ? (int)$_GET['year'] : null;

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
