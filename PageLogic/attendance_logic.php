<?php
# Start session if none is active yet
if (session_status() === PHP_SESSION_NONE) session_start();

# Configure debug reporting flag and attendance constants
if (!defined('ATT_DEBUG')) define('ATT_DEBUG', false);
if (ATT_DEBUG) { ini_set('display_errors', 1); error_reporting(E_ALL); }

const ATT_GRACE_MIN = 15;
const ATT_EVENT_STATUSES = ['ACTIVE', 'INACTIVE', 'FINISHED', 'RESCHEDULE', 'PENDING'];

# Escape output strings safely for HTML rendering
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

# Load authentication, database connection, and shared globals
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../PDAO/DAO.php';
global $db_connection;

# Send a JSON response and terminate execution immediately
function att_json(array $payload): void {
  header('Content-Type: application/json');
  echo json_encode($payload);
  exit;
}

# Serve a student's stored PNG QR code blob directly when requested
if (isset($_GET['att_qr']) && $_GET['att_qr'] === '1') {
  $u = (string)($_SESSION['userID'] ?? '');
  $isStudentSession = att_role_group((string)($_SESSION['role'] ?? 'student')) === 'student';
  if ($isStudentSession && preg_match('/^(\d{4})(\d{5})$/', $u, $m)) {
    $st = $db_connection->prepare("SELECT qr_image_data FROM students WHERE studentID = ? AND YEAR(date_created) = ?");
    $st->execute([(int)$m[2], (int)$m[1]]);
    $blob = $st->fetchColumn();
    if (!empty($blob)) {
      if (ob_get_level()) ob_end_clean();
      header('Content-Type: image/png');
      header('Content-Length: ' . strlen($blob));
      echo $blob;
      exit;
    }
  }
  header("HTTP/1.0 404 Not Found");
  exit;
}

# Classify a raw session role string into student, faculty, or manager group
function att_role_group(string $role): string {
  $r = strtolower(trim($role));
  if (preg_match('/coordinator|dean|registrar|admin/', $r)) return 'manager';
  if (preg_match('/prof|faculty|instructor|teacher/', $r))  return 'faculty';
  return 'student';
}

# Sync ACTIVE event statuses based on current time (past end -> FINISHED)
function att_sync_elapsed_events(PDO $db): void {
  try {
    $db->query("
      UPDATE events
      SET status = CASE
        WHEN datetime_End IS NOT NULL AND datetime_End < NOW() THEN 'FINISHED'
        ELSE status
      END,
      datetime_Start = datetime_Start  -- keep: column has ON UPDATE current_timestamp()
      WHERE status = 'ACTIVE'
        AND datetime_End IS NOT NULL AND datetime_End < NOW()
    ");
  } catch (Throwable $e) {
    error_log('Event sync failed: ' . $e->getMessage());
  }
}

# Run initial event status sync and load dynamically registered departments from DB
att_sync_elapsed_events($db_connection);

$validDepartments = array_values(array_unique(array_map(
  fn($d) => strtoupper(trim((string)$d)),
  $db_connection->query("
    SELECT DISTINCT department FROM programs WHERE department IS NOT NULL AND department <> ''
    UNION
    SELECT DISTINCT department FROM faculty WHERE department IS NOT NULL AND department <> ''
  ")->fetchAll(PDO::FETCH_COLUMN)
)));

# Resolve current user session identity, role position, and department/program scope
$attRole       = strtolower(trim($_SESSION['role'] ?? 'student'));
$attUserRaw    = trim((string)($_SESSION['userID'] ?? ''));
$attGroup      = att_role_group($attRole);
$attFacultyID  = ($attGroup !== 'student') ? (int)($_SESSION['real_userID'] ?? substr($attUserRaw, 5)) : 0;
$attStudentKey = ($attGroup === 'student' && preg_match('/^\d{9}$/', $attUserRaw)) ? $attUserRaw : null;

$attPos = 'student';
if ($attGroup !== 'student') {
  foreach (['coordinator', 'dean', 'registrar', 'admin'] as $pos) {
    if (strpos($attRole, $pos) !== false) { $attPos = $pos; break; }
  }
  if ($attPos === 'student') $attPos = 'faculty';
}

$attLabels = ['coordinator' => 'Coordinator', 'dean' => 'Dean', 'registrar' => 'Registrar', 'admin' => 'Admin'];
$attLabel  = strtoupper(($attGroup === 'student') ? 'Student' : ($attLabels[$attPos] ?? (ucwords($attRole) ?: 'Faculty')));
$_SESSION["page"] = "ATTENDANCE | " . $attLabel;

# Fetch faculty department and program, then evaluate dynamic department & role permissions
$attMyProgram = null;
$attMyDept    = null;
if ($attGroup !== 'student' && $attFacultyID > 0) {
  $fq = $db_connection->prepare("SELECT program, department FROM faculty WHERE facultyID = ? LIMIT 1");
  $fq->execute([$attFacultyID]);
  $fr = $fq->fetch(PDO::FETCH_ASSOC) ?: [];
  $attMyProgram = isset($fr['program']) && trim((string)$fr['program']) !== '' ? strtoupper(trim((string)$fr['program'])) : null;
  $attMyDept    = strtoupper(trim((string)($fr['department'] ?? '')));
}

$isAuthorizedDepartment = ($attGroup === 'student') || ($attMyDept !== '' && in_array($attMyDept, $validDepartments, true));
$attCanEdit = ($attGroup !== 'student') && $isAuthorizedDepartment && in_array($attPos, ['coordinator', 'admin', 'dean', 'faculty'], true);
$attCanScan = ($attGroup !== 'student') && $isAuthorizedDepartment && in_array($attPos, ['coordinator', 'faculty', 'dean'], true);
$attIsDean  = ($attPos === 'dean');
$attScoped  = ($attPos === 'coordinator');
# Dean and registrar only manage their own department, and faculty is department-scoped (admin stays unrestricted)
$attDeptScoped = (in_array($attPos, ['dean', 'registrar'], true) && (string)$attMyDept !== '') || $attPos === 'faculty';
$evpage     = ($attGroup !== 'student' && (!empty($_GET['events']) || !empty($_POST['events'])));
if ($evpage) $_SESSION["page"] = "EVENTS | " . $attLabel;

# Sync scope & permission flags to $GLOBALS immediately so helper functions inside closures see them
$GLOBALS['validDepartments']       = $validDepartments;
$GLOBALS['attPos']                 = $attPos;
$GLOBALS['attCanEdit']             = $attCanEdit;
$GLOBALS['attScoped']              = $attScoped;
$GLOBALS['attMyProgram']           = $attMyProgram;
$GLOBALS['attMyDept']              = $attMyDept;
$GLOBALS['evpage']                 = $evpage;
$GLOBALS['attCanScan']             = $attCanScan;
$GLOBALS['attIsDean']              = $attIsDean;
$GLOBALS['attDeptScoped']          = $attDeptScoped;
$GLOBALS['attFacultyID']           = $attFacultyID;
$GLOBALS['isAuthorizedDepartment'] = $isAuthorizedDepartment;

# Restrict non-list-permitted staff GET requests to the events view
$attCanList = ($attGroup === 'student') || in_array($attPos, ['dean', 'coordinator', 'admin'], true);
if (!$attCanList && !$evpage && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['att_qr'])) {
  header('Location: ' . BASE_PATH . '/attendance?events=1');
  exit;
}

# Dispatch POST actions for QR saving, bulk record deletion, event management, and scanning
$act = $_POST['att_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act !== '') {
  # Save student-generated PNG QR image data into database
  if ($attGroup === 'student' && $act === 'save_qr') {
    $rawQr = (string)($_POST['qr'] ?? '');
    if ($attStudentKey && preg_match('/^(\d{4})(\d{5})$/', $attStudentKey, $m) && strpos($rawQr, 'data:image/png;base64,') === 0) {
      $parts = explode(',', $rawQr, 2);
      $bin = isset($parts[1]) ? base64_decode($parts[1], true) : false;
      if ($bin !== false && strlen($bin) < 200000 && strncmp($bin, "\x89PNG", 4) === 0) {
        try {
          $st = $db_connection->prepare("UPDATE students SET qr_image_data = ? WHERE studentID = ? AND YEAR(date_created) = ?");
          $st->execute([$bin, (int)$m[2], (int)$m[1]]);
          att_json(['status' => 'success']);
        } catch (Throwable $e) {
          error_log('QR save failed: ' . $e->getMessage());
        }
      }
    }
    att_json(['status' => 'error']);
  }

  # Delete selected attendance records within authorized department/program scope
  if ($attGroup !== 'student' && $act === 'delete' && $attCanList) {
    if (!$attCanEdit || !$isAuthorizedDepartment) {
      $_SESSION['error'] = 'Access restricted: Unauthorized department for record deletion.';
    } else {
      $ids = array_values(array_unique(array_map('intval', array_filter((array)($_POST['ids'] ?? []), 'is_numeric'))));
      if (!$ids) {
        $_SESSION['error'] = 'Select at least one record to delete.';
      } else {
        $sql  = "DELETE FROM attendance WHERE attendanceID IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $args = $ids;
        if ($attScoped) {
          $sql .= " AND studentID IN (SELECT studentID FROM students WHERE UPPER(TRIM(program)) = ?)";
          $args[] = (string)$attMyProgram;
        } elseif ($attDeptScoped) {
          $sql .= " AND studentID IN (SELECT s.studentID FROM students s JOIN programs p ON UPPER(TRIM(p.program)) = UPPER(TRIM(s.program)) WHERE UPPER(TRIM(p.department)) = ?)";
          $args[] = (string)$attMyDept;
        }
        $st = $db_connection->prepare($sql);
        $st->execute($args);
        $n = $st->rowCount();
        $_SESSION[$n ? 'message' : 'error'] = $n ? "$n record" . ($n === 1 ? '' : 's') . " deleted." : 'Those records are not in your program.';
      }
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
  }

  # Delegate event creation, editing, deletion, approval, and rejection
  if ($attGroup !== 'student' && in_array($act, ['add_event', 'edit_event', 'delete_event', 'approve_event', 'reject_event'], true)) {
    att_handle_event_action($db_connection, $act);
    header('Location: ' . BASE_PATH . '/attendance?events=1');
    exit;
  }

  # Process QR/ID scan for time-in / time-out with department, event, and window checks
  if ($attGroup !== 'student' && $act === 'scan') {
    att_sync_elapsed_events($db_connection);
    if (!$attCanScan || !$isAuthorizedDepartment) {
      att_json(['status' => 'error', 'message' => 'Access restricted: Your department is not authorized to record attendance.']);
    }
    $rawStudentID = trim((string)($_POST['studentID'] ?? ''));
    $eventKey     = trim((string)($_POST['eventID'] ?? ''));
    if (!preg_match('/^(\d{4})(\d{5})$/', $rawStudentID, $m)) {
      att_json(['status' => 'error', 'message' => 'Enter a valid student ID (YYYYXXXXX).']);
    }
    if ($eventKey === '') {
      att_json(['status' => 'error', 'message' => 'Select an event first.']);
    }

    try {
      $st = $db_connection->prepare("
        SELECT s.studentID, s.first_name, s.last_name, s.program,
               (SELECT p.department FROM programs p WHERE UPPER(TRIM(p.program)) = UPPER(TRIM(s.program)) LIMIT 1) AS department
        FROM students s WHERE s.studentID = ? AND YEAR(s.date_created) = ? LIMIT 1
      ");
      $st->execute([(int)$m[2], (int)$m[1]]);
      $stuRow = $st->fetch(PDO::FETCH_ASSOC);
      if (!$stuRow) att_json(['status' => 'error', 'message' => 'Student not found.']);

      $studentID = (int)$stuRow['studentID'];
      $who = att_title($stuRow['first_name'] . ' ' . $stuRow['last_name']);
      $studentProgram = strtoupper(trim((string)($stuRow['program'] ?? '')));
      $studentDept = strtoupper(trim((string)($stuRow['department'] ?? '')));
      if ($attDeptScoped && $studentDept !== (string)$attMyDept) {
        att_json(['status' => 'error', 'message' => "$who is not in your department."]);
      }

      $ev = $db_connection->prepare("
        SELECT eventID, status, program, department,
               (NOW() BETWEEN datetime_Start - INTERVAL 30 MINUTE AND COALESCE(datetime_End, datetime_Start + INTERVAL 12 HOUR)) AS in_window
        FROM events WHERE eventID = ? OR eventName = ?
        ORDER BY (eventID = ?) DESC,
                 (status = 'ACTIVE' AND NOW() BETWEEN datetime_Start - INTERVAL 30 MINUTE AND COALESCE(datetime_End, datetime_Start + INTERVAL 12 HOUR)) DESC,
                 datetime_Start DESC
        LIMIT 1
      ");
      $evId = is_numeric($eventKey) ? (int)$eventKey : 0;
      $ev->execute([$evId, $eventKey, $evId]);
      $event = $ev->fetch(PDO::FETCH_ASSOC);
      if (!$event) att_json(['status' => 'error', 'message' => 'Invalid event specified.']);
      if ($event['status'] !== 'ACTIVE') att_json(['status' => 'error', 'message' => 'This event is not active, so attendance cannot be recorded.']);
      if (empty($event['in_window'])) att_json(['status' => 'error', 'message' => 'This event is not currently open for scanning.']);

      $eventProg = strtoupper(trim((string)($event['program'] ?? '')));
      $eventDept = strtoupper(trim((string)($event['department'] ?? '')));
      if ($eventProg !== '' && $eventProg !== $studentProgram) {
        att_json(['status' => 'error', 'message' => "$who belongs to program '$studentProgram', but this event is restricted to '$eventProg'."]);
      }
      if ($eventProg === '' && $eventDept !== '' && $eventDept !== $studentDept) {
        att_json(['status' => 'error', 'message' => "$who belongs to department '$studentDept', but this event is restricted to department '$eventDept'."]);
      }

      $chk = $db_connection->prepare("
        SELECT attendanceID, timeout, TIMESTAMPDIFF(SECOND, timein, NOW()) AS secs
        FROM attendance WHERE studentID = ? AND eventID = ? ORDER BY attendanceID DESC LIMIT 1
      ");
      $chk->execute([$studentID, (int)$event['eventID']]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        $db_connection->prepare("INSERT INTO attendance (eventID, facultyID, studentID, timein) VALUES (?, ?, ?, NOW())")
                      ->execute([(int)$event['eventID'], $attFacultyID, $studentID]);
        att_json(['status' => 'success', 'message' => "Time-in logged for $who.", 'type' => 'timein']);
      }
      if (empty($row['timeout'])) {
        if ((int)$row['secs'] < 60) {
          att_json(['status' => 'info', 'message' => "$who just timed in. Scan again later to time out."]);
        }
        $db_connection->prepare("UPDATE attendance SET timeout = NOW() WHERE attendanceID = ?")
                      ->execute([(int)$row['attendanceID']]);
        att_json(['status' => 'success', 'message' => "Time-out recorded for $who.", 'type' => 'timeout']);
      }
      att_json(['status' => 'info', 'message' => "$who has already completed time-in and time-out for this event."]);
    } catch (Throwable $e) {
      error_log('Scan failed: ' . $e->getMessage());
      att_json(['status' => 'error', 'message' => ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.']);
    }
  }
}

# Build department-to-program lookup map and apply coordinator program scoping
$ATT_DEPT_COURSES = [];
foreach ($db_connection->query("SELECT program, department FROM programs WHERE program IS NOT NULL AND program <> ''")->fetchAll(PDO::FETCH_ASSOC) as $p) {
  $d = strtoupper(trim((string)($p['department'] ?? '')));
  $c = strtoupper(trim((string)($p['program'])));
  if ($d !== '' && !in_array($c, $ATT_DEPT_COURSES[$d] ?? [], true)) $ATT_DEPT_COURSES[$d][] = $c;
}
if ($attScoped) {
  if ($attMyProgram) {
    $matchedDept = null;
    foreach ($ATT_DEPT_COURSES as $d => $cs) {
      if (in_array($attMyProgram, $cs, true)) { $matchedDept = $d; break; }
    }
    $ATT_DEPT_COURSES = [($matchedDept ?: ($attMyDept ?: 'PROGRAM')) => [$attMyProgram]];
  } else {
    $ATT_DEPT_COURSES = [];
  }
} elseif ($attDeptScoped) {
  $ATT_DEPT_COURSES = isset($ATT_DEPT_COURSES[$attMyDept]) ? [$attMyDept => $ATT_DEPT_COURSES[$attMyDept]] : [];
}

# Fetch non-pending event names and currently scannable ACTIVE events within window
$ATT_EVENT_NAMES = $db_connection->query("SELECT DISTINCT eventName FROM events WHERE status <> 'PENDING' ORDER BY eventName ASC")->fetchAll(PDO::FETCH_COLUMN);

$openSql = "
  SELECT eventID, eventName, DATE_FORMAT(datetime_Start, '%m/%d/%Y') AS d
  FROM events
  WHERE status = 'ACTIVE'
    AND NOW() BETWEEN datetime_Start - INTERVAL 30 MINUTE AND COALESCE(datetime_End, datetime_Start + INTERVAL 12 HOUR)
";
$openArgs = [];
if ($attScoped) {
  $openSql .= " AND (UPPER(TRIM(program)) = ? OR ((program IS NULL OR TRIM(program) = '') AND (department IS NULL OR TRIM(department) = '' OR UPPER(TRIM(department)) = ?)))";
  $openArgs = [(string)$attMyProgram, (string)$attMyDept];
} elseif ($attDeptScoped) {
  $openSql .= " AND (department IS NULL OR TRIM(department) = '' OR UPPER(TRIM(department)) = ?)";
  $openArgs = [(string)$attMyDept];
}
$openStmt = $db_connection->prepare($openSql . " ORDER BY datetime_Start DESC");
$openStmt->execute($openArgs);
$ATT_OPEN_EVENTS = $openStmt->fetchAll(PDO::FETCH_ASSOC);
$currentActiveEvent = $ATT_OPEN_EVENTS[0]['eventName'] ?? null;

# Fetch formatted student directory keyed by YYYYXXXXX ID and scoped to coordinator's program
function att_students(): array {
  global $db_connection;
  $scoped = (bool)($GLOBALS['attScoped'] ?? false);
  $myProg = strtoupper(trim((string)($GLOBALS['attMyProgram'] ?? '')));
  $deptScoped = (bool)($GLOBALS['attDeptScoped'] ?? false);
  $myDept = strtoupper(trim((string)($GLOBALS['attMyDept'] ?? '')));

  $rows = $db_connection->query("
    SELECT s.studentID, s.first_name, s.last_name, s.program, s.date_created,
           (SELECT p.department FROM programs p WHERE UPPER(TRIM(p.program)) = UPPER(TRIM(s.program)) LIMIT 1) AS department
    FROM students s ORDER BY s.last_name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $s) {
    $prog = strtoupper(trim((string)($s['program'] ?? '')));
    if ($scoped && ($myProg === '' || $prog !== $myProg)) continue;
    if ($deptScoped && strtoupper(trim((string)($s['department'] ?? ''))) !== $myDept) continue;

    $id = date('Y', strtotime((string)$s['date_created'])) . str_pad((string)$s['studentID'], 5, '0', STR_PAD_LEFT);
    $f = (string)$s['first_name'];
    $l = (string)$s['last_name'];
    $out[$id] = [
      'id' => $id, 'db_id' => (int)$s['studentID'],
      'dept' => strtoupper(trim((string)($s['department'] ?? ''))),
      'course' => $prog, 'name' => trim("$f $l"),
      'initials' => strtoupper(($f[0] ?? '') . ($l[0] ?? '')),
    ];
  }
  return $out;
}

# Fetch attendance logs for a student and compute status, formatted times, and hours
function att_records(array $stu): array {
  global $db_connection;
  $st = $db_connection->prepare("
    SELECT a.attendanceID AS record_id, e.eventName AS event_name, YEAR(e.datetime_Start) AS event_year,
           a.timein, a.timeout, e.datetime_Start,
           IFNULL(CONCAT('Mr./Ms. ', f.last_name, ', ', f.first_name), 'TBA') AS instructor_name
    FROM attendance a JOIN events e ON a.eventID = e.eventID LEFT JOIN faculty f ON a.facultyID = f.facultyID
    WHERE a.studentID = ? ORDER BY e.datetime_Start DESC
  ");
  $st->execute([$stu['db_id']]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tin = new DateTime($r['timein']);
    $tout = $r['timeout'] ? new DateTime($r['timeout']) : null;
    $hrs = 0;
    if ($tout) {
      $d = $tin->diff($tout);
      $hrs = round((($d->days * 24 + $d->h) * 60 + $d->i) / 60, 1);
    }
    $grace = (new DateTime($r['datetime_Start']))->modify('+' . ATT_GRACE_MIN . ' minutes');
    $out[] = [
      'id' => (int)$r['record_id'], 'event' => $r['event_name'] . ' ' . $r['event_year'],
      'eventName' => $r['event_name'], 'year' => (int)$r['event_year'],
      'date' => $tin->format('m - d - Y'), 'status' => ($tin > $grace) ? 'LATE' : 'ON-TIME',
      'tin' => $tin->format('h:i A'), 'tout' => $tout ? $tout->format('h:i A') : '--',
      'hours' => $hrs, 'who' => $r['instructor_name'],
    ];
  }
  return $out;
}

# Return only events and years assigned to the student (department first, then program)
function att_student_scope_filters(PDO $db, array $stu): array {
  $prog = strtoupper(trim((string)($stu['course'] ?? '')));
  $dept = strtoupper(trim((string)($stu['dept'] ?? '')));

  $st = $db->query("
    SELECT DISTINCT eventName, program, department, YEAR(datetime_Start) AS ev_year
    FROM events
    WHERE status <> 'PENDING'
    ORDER BY eventName ASC, ev_year DESC
  ");

  $events = [];
  $years  = [];

  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $evDept = strtoupper(trim((string)($r['department'] ?? '')));
    $evProg = strtoupper(trim((string)($r['program'] ?? '')));

    # 1. Department check first
    if ($evDept !== '') {
      if ($dept === '' || $evDept !== $dept) continue;
    }

    # 2. Program check second (if program is specified on the event, it must match student's program)
    if ($evProg !== '') {
      if ($prog === '' || $evProg !== $prog) continue;
    }

    $evName = trim((string)($r['eventName'] ?? ''));
    if ($evName !== '' && !in_array($evName, $events, true)) {
      $events[] = $evName;
    }

    $yr = (int)($r['ev_year'] ?? 0);
    if ($yr >= 2000 && $yr <= 2100 && !in_array($yr, $years, true)) {
      $years[] = $yr;
    }
  }

  sort($events, SORT_NATURAL | SORT_FLAG_CASE);
  rsort($years);

  return ['events' => $events, 'years' => $years];
}

# Summarize on-time, late, absent counts and total hours from filtered records
function att_summarize_records(array $records): array {
  $sum = ['ontime' => 0, 'late' => 0, 'absent' => 0, 'hours' => 0.0];
  foreach ($records as $r) {
    $k = strtolower((string)($r['status'] ?? ''));
    if ($k === 'on-time') $sum['ontime']++;
    elseif ($k === 'late') $sum['late']++;
    elseif ($k === 'absent') $sum['absent']++;
    $sum['hours'] += (float)($r['hours'] ?? 0);
  }
  $sum['hours'] = round($sum['hours'], 1);
  return $sum;
}

# Title-case a name without breaking multibyte letters such as Ñ
function att_title(string $s): string {
  return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

# Normalize person names by stripping titles and punctuation
function att_norm_name(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = preg_replace('/\b(mr|ms|mrs|miss|dr|prof|engr|atty)\b\.?/', ' ', $s);
  $s = preg_replace('/[^a-z0-9ñ ]+/u', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

# Department that limits which faculty can be picked as coordinator (null = unrestricted, i.e. admin)
function att_coord_dept(): ?string {
  $d = strtoupper(trim((string)($GLOBALS['attMyDept'] ?? '')));
  return (($GLOBALS['attPos'] ?? '') !== 'admin' && $d !== '') ? $d : null;
}

# Fetch non-registrar faculty display names keyed by facultyID (same department unless admin)
function att_faculty_names(): array {
  global $db_connection;
  $out = [];
  $dept = att_coord_dept();
  $sql  = "SELECT facultyID, first_name, middle_name, last_name FROM faculty WHERE LOWER(COALESCE(role, '')) NOT LIKE '%registrar%'"
        . ($dept !== null ? " AND UPPER(TRIM(department)) = ?" : "")
        . " ORDER BY last_name, first_name";
  $fq = $db_connection->prepare($sql);
  $fq->execute($dept !== null ? [$dept] : []);
  foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $out[(int)$f['facultyID']] = att_title(preg_replace('/\s+/', ' ', trim($f['first_name'] . ' ' . $f['middle_name'] . ' ' . $f['last_name'])));
  }
  return $out;
}

# Resolve a typed coordinator name to a facultyID using variant and fallback matching
function att_find_faculty(string $typed): ?int {
  global $db_connection;
  $want = att_norm_name($typed);
  if ($want === '') return null;
  $dept = att_coord_dept();
  $all  = $db_connection->prepare("SELECT facultyID, first_name, middle_name, last_name FROM faculty" . ($dept !== null ? " WHERE UPPER(TRIM(department)) = ?" : ""));
  $all->execute($dept !== null ? [$dept] : []);
  foreach ($all->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $fn = att_norm_name((string)$f['first_name']);
    $mn = att_norm_name((string)$f['middle_name']);
    $ln = att_norm_name((string)$f['last_name']);
    if (in_array($want, [trim("$fn $mn $ln"), trim("$fn $ln"), trim("$ln $fn"), trim("$ln $fn $mn")], true)) {
      return (int)$f['facultyID'];
    }
  }
  $like = '%' . addcslashes($typed, '%_\\') . '%';
  $st = $db_connection->prepare("SELECT facultyID FROM faculty WHERE (CONCAT(first_name, ' ', last_name) LIKE ? OR last_name LIKE ?)" . ($dept !== null ? " AND UPPER(TRIM(department)) = ?" : "") . " LIMIT 1");
  $st->execute($dept !== null ? [$like, $like, $dept] : [$like, $like]);
  $match = $st->fetchColumn();
  return $match ? (int)$match : null;
}

# Fetch available programs scoped to the faculty member's department or coordinator's program
function att_program_options(): array {
  global $db_connection;
  $scoped = (bool)($GLOBALS['attScoped'] ?? false);
  $myProg = strtoupper(trim((string)($GLOBALS['attMyProgram'] ?? '')));
  $myDept = strtoupper(trim((string)($GLOBALS['attMyDept'] ?? '')));

  if ($scoped) {
    return $myProg !== '' ? [$myProg] : [];
  }
  $sql = "SELECT DISTINCT UPPER(TRIM(program)) FROM programs WHERE program IS NOT NULL AND TRIM(program) <> ''";
  $args = [];
  if ($myDept !== '') { $sql .= " AND UPPER(TRIM(department)) = ?"; $args[] = $myDept; }
  $st = $db_connection->prepare($sql . " ORDER BY 1");
  $st->execute($args);
  return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
}

# Sync elapsed events and return formatted events visible to the current role/scope
function att_events(): array {
  global $db_connection;
  $canEdit = (bool)($GLOBALS['attCanEdit'] ?? false);
  $scoped  = (bool)($GLOBALS['attScoped'] ?? false);
  $myProg  = strtoupper(trim((string)($GLOBALS['attMyProgram'] ?? '')));
  $myDept  = strtoupper(trim((string)($GLOBALS['attMyDept'] ?? '')));
  $isDean  = (bool)($GLOBALS['attIsDean'] ?? false);
  $facID   = (int)($GLOBALS['attFacultyID'] ?? 0);
  $pos     = (string)($GLOBALS['attPos'] ?? 'student');
  $deptScoped = (bool)($GLOBALS['attDeptScoped'] ?? false);

  att_sync_elapsed_events($db_connection);

  $sql = "
    SELECT e.eventID, e.eventName, e.program, e.department, e.datetime_Start, e.datetime_End,
           e.eventCoordinator, e.status, e.edit_remark,
           (DATE(e.datetime_Start) > CURDATE()) AS is_future,
           TRIM(CONCAT_WS(' ', f.first_name, f.middle_name, f.last_name)) AS coord_name,
           (SELECT COUNT(*) FROM attendance a WHERE a.eventID = e.eventID) AS att_count
    FROM events e LEFT JOIN faculty f ON f.facultyID = e.eventCoordinator
  ";
  $args = []; $where = [];
  if ($scoped) {
    $where[] = "(UPPER(TRIM(e.program)) = ? OR ((e.program IS NULL OR TRIM(e.program) = '') AND (e.department IS NULL OR TRIM(e.department) = '' OR UPPER(TRIM(e.department)) = ?)))";
    array_push($args, $myProg, $myDept);
  }
  if ($deptScoped) {
    $where[] = "(e.department IS NULL OR TRIM(e.department) = '' OR UPPER(TRIM(e.department)) = ?)";
    $args[] = $myDept;
  }
  if (!$isDean) {
    $where[] = "(e.status <> 'PENDING' OR e.eventCoordinator = ?)";
    $args[] = $facID;
  }
  if ($pos === 'registrar') {
    $where[] = "(e.status = 'ACTIVE' AND e.datetime_Start <= NOW() AND (e.datetime_End IS NULL OR e.datetime_End >= NOW()))";
  }
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $st = $db_connection->prepare($sql . " ORDER BY e.datetime_Start DESC, e.eventID DESC");
  $st->execute($args);

  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $s = new DateTime($r['datetime_Start']);
    $en = $r['datetime_End'] ? new DateTime($r['datetime_End']) : null;
    $status = strtoupper((string)$r['status']);
    $prog = strtoupper(trim((string)($r['program'] ?? '')));
    $dept = strtoupper(trim((string)($r['department'] ?? '')));
    $coordMatch = (!$scoped || ($myProg !== '' && $prog === $myProg) || ($prog === '' && ($dept === '' || $dept === $myDept)));
    $out[] = [
      'id' => (int)$r['eventID'], 'name' => $r['eventName'],
      'scope' => $prog ?: ($dept ?: 'ALL'), 'program' => $prog,
      'ymd' => $s->format('Y-m-d'), 'date' => $s->format('m/d/Y'),
      'start' => $s->format('g:iA'), 'end' => $en ? $en->format('g:iA') : '--',
      'start24' => $s->format('H:i'), 'end24' => $en ? $en->format('H:i') : '',
      'status' => $status,
      'shown'  => ($status === 'ACTIVE' && !empty($r['is_future'])) ? 'INACTIVE' : $status,
      'coord' => ($r['eventCoordinator'] && trim((string)$r['coord_name']) !== '') ? att_title(preg_replace('/\s+/', ' ', $r['coord_name'])) : '',
      'att' => (int)$r['att_count'],
      'remark' => (string)($r['edit_remark'] ?? ''),
      'canEdit' => $canEdit && $coordMatch && ($isDean || ((int)$r['eventCoordinator'] === $facID && $status !== 'FINISHED')),
      'review' => $isDean && $status === 'PENDING',
    ];
  }
  return $out;
}

# Validate and execute add, edit, delete, approve, or reject actions on events
function att_handle_event_action(PDO $db, string $act): void {
  $canEdit = (bool)($GLOBALS['attCanEdit'] ?? false);
  $scoped  = (bool)($GLOBALS['attScoped'] ?? false);
  $myProg  = strtoupper(trim((string)($GLOBALS['attMyProgram'] ?? '')));
  $myDept  = strtoupper(trim((string)($GLOBALS['attMyDept'] ?? '')));
  $isDean  = (bool)($GLOBALS['attIsDean'] ?? false);
  $facID   = (int)($GLOBALS['attFacultyID'] ?? 0);
  $authDept = (bool)($GLOBALS['isAuthorizedDepartment'] ?? false);
  $deptScoped = (bool)($GLOBALS['attDeptScoped'] ?? false);

  if (!$authDept) { $_SESSION['error'] = 'Access restricted: Unauthorized department for event management.'; return; }

  if (in_array($act, ['approve_event', 'reject_event'], true)) {
    if (!$isDean) { $_SESSION['error'] = 'Only the dean can approve or reject events.'; return; }
    try {
      $id = (int)($_POST['eventID'] ?? 0);
      $q = $db->prepare("SELECT status, department FROM events WHERE eventID = ? LIMIT 1");
      $q->execute([$id]);
      $evRow = $q->fetch(PDO::FETCH_ASSOC);
      $cur = $evRow ? $evRow['status'] : false;
      if ($cur === false) { $_SESSION['error'] = 'That event no longer exists.'; return; }
      $evDeptRow = strtoupper(trim((string)($evRow['department'] ?? '')));
      if ($deptScoped && $evDeptRow !== '' && $evDeptRow !== $myDept) { $_SESSION['error'] = 'That event belongs to another department.'; return; }
      if (strtoupper((string)$cur) !== 'PENDING') { $_SESSION['error'] = 'This event is not waiting for approval.'; return; }
      if ($act === 'approve_event') {
        $db->prepare("UPDATE events SET status = 'ACTIVE', datetime_Start = datetime_Start, edit_remark = NULL WHERE eventID = ? AND status = 'PENDING'")->execute([$id]);
        $_SESSION['message'] = 'Event approved.';
      } else {
        $db->prepare("DELETE FROM events WHERE eventID = ? AND status = 'PENDING'")->execute([$id]);
        $_SESSION['message'] = 'Event rejected and deleted.';
      }
    } catch (Throwable $e) {
      error_log('Approval failed: ' . $e->getMessage());
      $_SESSION['error'] = ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.';
    }
    return;
  }

  if (!$canEdit) { $_SESSION['error'] = 'You are not allowed to add, edit or delete events.'; return; }

  try {
    $id = (int)($_POST['eventID'] ?? 0);
    $existing = null;
    if ($act !== 'add_event') {
      $q = $db->prepare("SELECT eventID, eventName, program, department, status, eventCoordinator, datetime_Start, datetime_End, edit_remark FROM events WHERE eventID = ? LIMIT 1");
      $q->execute([$id]);
      $existing = $q->fetch(PDO::FETCH_ASSOC);
      if (!$existing) { $_SESSION['error'] = 'That event no longer exists.'; return; }
      $exProg = strtoupper(trim((string)($existing['program'] ?? '')));
      $exDept  = strtoupper(trim((string)($existing['department'] ?? '')));
      $owns = $isDean || ((int)$existing['eventCoordinator'] === $facID && strtoupper((string)$existing['status']) !== 'FINISHED');
      $inScope = !$scoped || ($myProg !== '' && $exProg === $myProg) || ($exProg === '' && ($exDept === '' || $exDept === $myDept));
      if ($deptScoped && $exDept !== '' && $exDept !== $myDept) $inScope = false;
      if (!$owns || !$inScope) { $_SESSION['error'] = 'You are not allowed to modify this event.'; return; }
    }
    if ($act === 'delete_event') {
      $db->prepare("DELETE FROM events WHERE eventID = ?")->execute([$id]);
      $_SESSION['message'] = 'Event deleted.';
      return;
    }

    $name = trim((string)($_POST['eventName'] ?? ''));
    $date = (string)($_POST['date'] ?? '');
    $start = (string)($_POST['start'] ?? '');
    $end = (string)($_POST['end'] ?? '');
    $status = strtoupper(trim((string)($_POST['status'] ?? 'ACTIVE')));
    $coord = trim((string)($_POST['coordinator'] ?? ''));
    $remark = mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 500);
    $remarkVal = null;
    $prog = strtoupper(trim((string)($_POST['program'] ?? '')));
    # The dialog locks name/program for non-deans when editing; disabled fields are never submitted,
    # so keep the stored values (otherwise every edit fails with "Enter the event name.")
    if (!$isDean && $act === 'edit_event' && $existing) {
      $name = (string)$existing['eventName'];
      $prog = strtoupper(trim((string)($existing['program'] ?? '')));
    }

    $d = DateTime::createFromFormat('!Y-m-d', $date);
    $timeOk = fn($t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    if ($name === '' || mb_strlen($name) > 250) { $_SESSION['error'] = 'Enter the event name.'; return; }
    if (!$d || $d->format('Y-m-d') !== $date)   { $_SESSION['error'] = 'Enter a valid date.'; return; }
    if (!$timeOk($start) || !$timeOk($end))     { $_SESSION['error'] = 'Enter the start and end time.'; return; }
    if ($end <= $start)                         { $_SESSION['error'] = 'The end time must be after the start time.'; return; }
    $exProgEdit = $existing ? strtoupper(trim((string)($existing['program'] ?? ''))) : '';
    $exDeptEdit = $existing ? strtoupper(trim((string)($existing['department'] ?? ''))) : '';
    $allowedCoordProg = ($act === 'edit_event' && $existing && $exProgEdit === '' && ($exDeptEdit === '' || $exDeptEdit === $myDept) && $prog === '');
    if ($scoped && !$allowedCoordProg && ($myProg === '' || $prog !== $myProg)) {
      $_SESSION['error'] = 'You can only manage events for your own program.';
      return;
    }

    if ($prog !== '') {
      $exProgNow = $existing ? strtoupper(trim((string)($existing['program'] ?? ''))) : null;
      if ($prog !== $exProgNow && !in_array($prog, att_program_options(), true)) {
        $_SESSION['error'] = 'Choose a valid program.'; return;
      }
    }

    $dtStart = "$date $start:00";
    $dtEnd = "$date $end:00";

    if ($isDean) {
      if (!in_array($status, ATT_EVENT_STATUSES, true)) { $_SESSION['error'] = 'Choose a status.'; return; }
      $remarkVal = ($status === 'PENDING') ? ($existing['edit_remark'] ?? null) : null;
    } elseif ($act === 'add_event') {
      $status = 'PENDING';
    } else {
      $changed = ($existing['datetime_Start'] !== $dtStart)
              || ((string)$existing['datetime_End'] !== $dtEnd)
              || (strtoupper(trim((string)$existing['program'])) !== $prog);
      $status = $changed ? 'PENDING' : strtoupper((string)$existing['status']);
      if ($changed && $remark === '') { $_SESSION['error'] = 'Enter a remark explaining why this event is being changed.'; return; }
      $remarkVal = $changed ? $remark : ($existing['edit_remark'] ?? null);
    }

    $coordID = $facID ?: 1;
    if ($coord !== '') {
      $foundID = att_find_faculty($coord);
      if (!$foundID) { $_SESSION['error'] = 'Event coordinator not found. Pick a name from the list.'; return; }
      $coordID = $foundID;
    }

    $deptVal = $myDept;
    if ($prog !== '') {
      $ds = $db->prepare("SELECT department FROM programs WHERE UPPER(TRIM(program)) = ? LIMIT 1");
      $ds->execute([$prog]);
      if ($mDept = $ds->fetchColumn()) $deptVal = strtoupper(trim((string)$mDept));
    }

    if ($act === 'add_event') {
      $db->prepare("INSERT INTO events (eventName, program, department, datetime_Start, datetime_End, eventCoordinator, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
         ->execute([$name, $prog !== '' ? $prog : null, $deptVal, $dtStart, $dtEnd, $coordID, $status]);
      $_SESSION['message'] = 'Event added successfully.';
    } else {
      $db->prepare("UPDATE events SET eventName = ?, program = ?, department = ?, datetime_Start = ?, datetime_End = ?, eventCoordinator = ?, status = ?, edit_remark = ? WHERE eventID = ?")
         ->execute([$name, $prog !== '' ? $prog : null, $deptVal, $dtStart, $dtEnd, $coordID, $status, $remarkVal, $id]);
      $_SESSION['message'] = 'Event updated successfully.';
    }
  } catch (Throwable $e) {
    error_log('Event action failed: ' . $e->getMessage());
    $_SESSION['error'] = ATT_DEBUG ? 'Database error: ' . $e->getMessage() : 'Database error. Please try again.';
  }
}

# Parse and sanitize GET filters for department, program, search query, view, student, event, and year
$students   = att_students();
$getStr     = fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
$q          = trim($getStr('q'));
$deptRaw    = $getStr('dept');
$dept       = ($deptRaw !== '' && isset($ATT_DEPT_COURSES[$deptRaw])) ? $deptRaw : null;
$allCourses = $ATT_DEPT_COURSES ? array_merge(...array_values($ATT_DEPT_COURSES)) : [];
$courseRaw  = strtoupper(trim($getStr('course')));
$course     = in_array($courseRaw, $allCourses, true) ? $courseRaw : null;
if ($course && $dept && !in_array($course, $ATT_DEPT_COURSES[$dept], true)) $course = null;
if ($course && !$dept) {
  foreach ($ATT_DEPT_COURSES as $d => $cs) if (in_array($course, $cs, true)) { $dept = $d; break; }
}
$view  = ($getStr('view') === 'list') ? 'list' : 'grid';
$sid   = $getStr('student');
$stu   = ($sid !== '' && isset($students[$sid])) ? $students[$sid] : null;
# Event filter lists only events that apply to this student's department/program (not every event in the system)
if ($stu) $ATT_EVENT_NAMES = att_student_scope_filters($db_connection, $stu)['events'];
$event = in_array($getStr('event'), $ATT_EVENT_NAMES, true) ? $getStr('event') : null;
$yr    = (int)($_GET['year'] ?? 0);
$year  = ($yr >= 2000 && $yr <= 2100) ? $yr : null;

# Render a filter dropdown element using a provided link generator callback
function att_render_dd(string $label, string $allText, array $items, $current, callable $hrefFn): void {
  echo '<details class="dd"><summary class="dd-btn' . ($current !== null ? ' has-value' : '') . '"><span>' . e($current ?? $label) . '</span>'
     . '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg></summary><ul class="dd-menu">';
  echo '<li' . ($current === null ? ' class="selected"' : '') . '><a href="' . e($hrefFn(null)) . '">' . e($allText) . '</a></li>';
  foreach ($items as $it) {
    echo '<li' . ((string)$it === (string)$current ? ' class="selected"' : '') . '><a href="' . e($hrefFn($it)) . '">' . e($it) . '</a></li>';
  }
  echo '</ul></details>';
}

# Build query string for faculty/events view while preserving active filters
function att_url(array $over = []): string {
  $p = array_merge([
    'dept'    => $GLOBALS['dept'] ?? null,
    'course'  => $GLOBALS['course'] ?? null,
    'q'       => ($GLOBALS['q'] ?? '') ?: null,
    'view'    => (($GLOBALS['view'] ?? 'grid') === 'list') ? 'list' : null,
    'student' => $GLOBALS['stu']['id'] ?? null,
    'event'   => $GLOBALS['event'] ?? null,
    'year'    => $GLOBALS['year'] ?? null,
    'events'  => !empty($GLOBALS['evpage']) ? 1 : null,
  ], $over);
  return '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
}

# Render a faculty/events filter dropdown
function att_dropdown(string $label, string $allText, array $items, $current, string $param, array $extra = []): void {
  att_render_dd($label, $allText, $items, $current, fn($v) => att_url([$param => $v] + $extra));
}

# Build query string for student self-attendance view filters
function sa_url(array $over = []): string {
  return '?' . http_build_query(array_filter(array_merge([
    'event' => $GLOBALS['sa_event'] ?? null,
    'year'  => $GLOBALS['sa_year'] ?? null,
  ], $over), fn($v) => $v !== null && $v !== ''));
}

# Render a student self-attendance filter dropdown
function sa_dropdown(string $label, string $allText, array $items, $current, string $param): void {
  att_render_dd($label, $allText, $items, $current, fn($v) => sa_url([$param => $v]));
}

# Prepare view-specific datasets for events, student self-view, student directory, or student detail
$sa_event = null;
$sa_year  = null;

if ($evpage) {
  $events = att_events();
  $ATT_FACULTY_NAMES = $attCanEdit ? array_values(att_faculty_names()) : [];
  $ATT_PROGRAMS = att_program_options();
  $ATT_ALL_LABEL = 'ALL ' . ($attMyDept ?: 'PROGRAMS');
  $mq = $db_connection->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM faculty WHERE facultyID = ? LIMIT 1");
  $mq->execute([$attFacultyID]);
  $ATT_ME_NAME = att_title(preg_replace('/\s+/', ' ', (string)$mq->fetchColumn()));
} elseif ($attGroup === 'student') {
  $me = $attStudentKey ? ($students[$attStudentKey] ?? null) : null;
  if (!$me) {
    $_SESSION['error'] = 'Student record not found.';
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
  }
  $qc = $db_connection->prepare("SELECT (qr_image_data IS NOT NULL AND OCTET_LENGTH(qr_image_data) > 0) FROM students WHERE studentID = ? LIMIT 1");
  $qc->execute([$me['db_id']]);
  $GLOBALS['attHasQr'] = (bool)$qc->fetchColumn();

  $sa_all          = att_records($me);
  $sa_filter       = att_student_scope_filters($db_connection, $me);
  $sa_events       = $sa_filter['events'];
  $sa_years        = $sa_filter['years'];
  $ATT_EVENT_NAMES = $sa_events;
  $sa_event        = in_array($getStr('event'), $sa_events, true) ? $getStr('event') : null;
  $sa_year         = in_array((int)($_GET['year'] ?? 0), $sa_years, true) ? (int)$_GET['year'] : null;
  $recs            = array_values(array_filter($sa_all, fn($r) => (!$sa_event || $r['eventName'] === $sa_event) && (!$sa_year || $r['year'] === $sa_year)));
  $summary         = att_summarize_records($recs);
} elseif (!$stu) {
  $rows = array_filter($students, function ($s) use ($dept, $course, $q) {
    if ($dept && $s['dept'] !== $dept) return false;
    if ($course && $s['course'] !== $course) return false;
    if ($q === '') return true;
    foreach ([$s['id'], $s['name'], $s['dept'], $s['course']] as $f) if (stripos((string)$f, $q) !== false) return true;
    return false;
  });
} else {
  $all     = att_records($stu);
  $recs    = array_values(array_filter($all, fn($r) => (!$event || $r['eventName'] === $event) && (!$year || $r['year'] === $year)));
  $years   = array_values(array_unique(array_column($all, 'year'))); rsort($years);
  $summary = att_summarize_records($recs);
}

# Unpack summary metrics and expose state variables to $GLOBALS for router closure helpers
if (isset($summary)) {
  $ontime = $summary['ontime'];
  $late   = $summary['late'];
  $absent = $summary['absent'];
  $hrs    = $summary['hours'];
}

foreach ([
  'validDepartments', 'attPos', 'attCanEdit', 'attScoped', 'attMyProgram', 'attMyDept',
  'evpage', 'attCanScan', 'attIsDean', 'attFacultyID', 'q', 'dept', 'course', 'view',
  'stu', 'event', 'year', 'sa_event', 'sa_year', 'sa_events', 'sa_years',
  'currentActiveEvent', 'ATT_OPEN_EVENTS',
] as $gKey) {
  if (isset($$gKey)) $GLOBALS[$gKey] = $$gKey;
}