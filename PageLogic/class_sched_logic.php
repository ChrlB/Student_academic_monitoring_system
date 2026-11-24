<?php
require_once __DIR__ . '/../Services/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION["page"] = "SCHEDULE";
require_once __DIR__ . '/../PDAO/DAO.php';


class ScheduleLogic
{
    const WEEK  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const CODES = ['Monday' => 'M', 'Tuesday' => 'T', 'Wednesday' => 'W', 'Thursday' => 'TH',
                   'Friday' => 'F', 'Saturday' => 'S', 'Sunday' => 'SU'];
    const NAMES = [
        'MON' => 'Monday', 'MONDAY' => 'Monday',
        'TUE' => 'Tuesday', 'TUES' => 'Tuesday', 'TUESDAY' => 'Tuesday',
        'WED' => 'Wednesday', 'WEDNESDAY' => 'Wednesday',
        'THU' => 'Thursday', 'THUR' => 'Thursday', 'THURS' => 'Thursday', 'THURSDAY' => 'Thursday',
        'FRI' => 'Friday', 'FRIDAY' => 'Friday',
        'SAT' => 'Saturday', 'SATURDAY' => 'Saturday',
        'SUN' => 'Sunday', 'SUNDAY' => 'Sunday',
    ];

    const FILTER_BY_SEMESTER = true;
    const FORMATS = ['onsite', 'blended_odd', 'blended_even'];
    const STATUSES = ['draft' => 'Draft', 'assigned' => 'Assigned', 'deployed' => 'Deployed'];

    private $pdo;
    public $role;
    public $group;    // manager | faculty | student
    public $userID;
    private $assigned = null;
    private $dept = null;

    const DB_HOST = 'localhost';
    const DB_PORT = 3306;
    const DB_NAME = 'ams_db';
    const DB_USER = 'root';
    const DB_PASS = '';

    public function __construct()
    {
        global $db_connection, $pdo, $conn, $db;

        foreach ([$db_connection ?? null, $pdo ?? null, $conn ?? null, $db ?? null] as $c) {
            if ($c instanceof PDO) { $this->pdo = $c; break; }
        }

        if (!$this->pdo) {
            try {
                $this->pdo = new PDO(
                    'mysql:host=' . self::DB_HOST . ';port=' . self::DB_PORT . ';dbname=' . self::DB_NAME . ';charset=utf8mb4',
                    self::DB_USER,
                    self::DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
            } catch (PDOException $e) {
                error_log('Schedule DB connection failed: ' . $e->getMessage());
                die('Database connection error.');
            }
        }

        $role = self::sessionPick(['role', 'user_role', 'userRole', 'type', 'user_type', 'account_type']);
        $real = self::sessionPick(['real_userID', 'realUserID']);
        $this->role = strtolower(trim((string)($role ?? 'student')));
        if ($real !== null) {
            $this->userID = (int)$real;
        } else {
            $uid = self::sessionPick(['userID', 'user_id', 'userId', 'id', 'studentID', 'student_id', 'facultyID', 'faculty_id']);
            $this->userID = (int)substr(preg_replace('/\D/', '', (string)$uid), -5);
        }
        $this->group  = self::roleGroup($this->role);
    }

    // Roles

    private static function sessionPick(array $keys)
    {
        foreach ($keys as $k) {
            if (isset($_SESSION[$k]) && !is_array($_SESSION[$k]) && $_SESSION[$k] !== '') return $_SESSION[$k];
        }
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach ($keys as $k) {
                if (isset($_SESSION['user'][$k]) && $_SESSION['user'][$k] !== '') return $_SESSION['user'][$k];
            }
        }
        return null;
    }

    public static function roleGroup($role)
    {
        $r = strtolower(trim((string)$role));
        if (strpos($r, 'registrar') !== false) return 'none';
        if (preg_match('/coordinator|dean|admin/', $r)) return 'manager';
        if (preg_match('/prof|faculty|instructor|teacher/', $r))  return 'faculty';
        return 'student';
    }

    // Only the dean can create, assign, deploy, edit and delete schedules.
    public function canManage()
    {
        return $this->isDean();
    }

    public function isCoordinator()
    {
        return strpos($this->role, 'coordinator') !== false;
    }

    public function assignedProgram()
    {
        if ($this->assigned === null) {
            $r = $this->all("SELECT program FROM faculty WHERE facultyID = :id", [':id' => $this->userID]);
            $p = $r ? trim((string)$r[0]['program']) : '';
            $this->assigned = $p !== '' ? $p : trim((string)($_SESSION['program'] ?? ''));
        }
        return $this->assigned;
    }

    // Dean sees everything. Coordinator sees deployed schedules of the assigned program.
    public function canAccess(array $row)
    {
        if ($this->isDean()) {
            return $this->assignedDepartment() !== '' && $row['department'] === $this->assignedDepartment();
        }
        if ($this->isCoordinator()) {
            return $this->assignedProgram() !== ''
                && $row['program'] === $this->assignedProgram()
                && $row['status'] === 'deployed';
        }
        return false;
    }
    public function isDean()
   {
    return strpos($this->role, 'dean') !== false;
   }

    // Department handled by the dean (faculty record, else the login session).
    public function assignedDepartment()
    {
        if ($this->dept === null) {
            $r = $this->all("SELECT department FROM faculty WHERE facultyID = :id", [':id' => $this->userID]);
            $d = $r ? trim((string)$r[0]['department']) : '';
            $this->dept = $d !== '' ? $d : trim((string)($_SESSION['department'] ?? ''));
        }
        return $this->dept;
    }

    // A dean only gets the programs of the handled department.
    public function programs()
    {
        if ($this->isDean()) {
            return $this->all(
                "SELECT program, name, department FROM programs WHERE department = :d ORDER BY program",
                [':d' => $this->assignedDepartment()]
            );
        }
        return $this->all("SELECT program, name, department FROM programs ORDER BY program");
    }

    // Day codes / formatting helpers

    // Day codes or names to a list of weekdays.
    public static function parseDays($raw)
    {
        $found = [];
        foreach (preg_split('/[^A-Za-z]+/', strtoupper((string)$raw), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (isset(self::NAMES[$tok])) {
                $found[self::NAMES[$tok]] = true;
                continue;
            }
            for ($i = 0, $n = strlen($tok); $i < $n; $i++) {
                $two = substr($tok, $i, 2);
                if ($two === 'TH')      { $found['Thursday'] = true; $i++; }
                elseif ($two === 'SU')  { $found['Sunday'] = true;   $i++; }
                else {
                    switch ($tok[$i]) {
                        case 'M': $found['Monday'] = true;    break;
                        case 'T': $found['Tuesday'] = true;   break;
                        case 'W': $found['Wednesday'] = true; break;
                        case 'F': $found['Friday'] = true;    break;
                        case 'S': $found['Saturday'] = true;  break;
                    }
                }
            }
        }
        return array_values(array_filter(self::WEEK, function ($d) use ($found) {
            return isset($found[$d]);
        }));
    }

    public static function daysToCode(array $days)
    {
        $code = '';
        foreach (self::WEEK as $d) {
            if (in_array($d, $days, true)) $code .= self::CODES[$d];
        }
        return $code;
    }

    public static function fmtTime($t)
    {
        return date('g:i A', strtotime($t));
    }

    public static function fmtRange($a, $b)
    {
        $x = strtotime($a);
        $y = strtotime($b);
        return date('A', $x) === date('A', $y)
            ? date('g:i', $x) . '–' . date('g:i A', $y)
            : date('g:i A', $x) . ' – ' . date('g:i A', $y);
    }

    public static function classType($subjectID, $name)
    {
        if (stripos((string)$name, '(GEC)') !== false || preg_match('/^GEC?\d/i', trim((string)$subjectID))) return 'GEC';
        if (stripos((string)$name, '(LAB)') !== false) return 'LAB';
        if (stripos((string)$name, '(LEC)') !== false) return 'LEC';
        return preg_match('/L$/i', trim((string)$subjectID)) ? 'LAB' : 'LEC';
    }

    // onsite = every week. blended_odd / blended_even = alternating online and onsite, onsite on odd / even weeks.
    public static function formatLabel($f)
    {
        if ($f === 'blended_odd')  return 'Alternating online/onsite (onsite on odd weeks)';
        if ($f === 'blended_even') return 'Alternating online/onsite (onsite on even weeks)';
        return 'Full onsite';
    }

    // Two classes need the room in the same week unless both are alternating on opposite weeks.
    public static function weeksOverlap($a, $b)
    {
        if ($a === 'onsite' || $b === 'onsite') return true;
        return $a === $b;
    }

    public static function typeLabel($type)
    {
        if ($type === 'LAB') return 'LABORATORY (LAB)';
        if ($type === 'GEC') return 'GENERAL EDUCATION (GEC)';
        return 'LECTURE (LEC)';
    }

    public static function semesterLabel($acad, $sem)
    {
        $ord = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];
        return ($ord[(int)$sem] ?? ((int)$sem . 'th Semester')) . ', AY ' . str_replace('-', '–', $acad);
    }

    public static function properName($s)
    {
        return ucwords(strtolower(trim((string)$s)));
    }

    // Lookups for dropdowns

    private function all($sql, array $params = [])
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Schedule query failed: ' . $e->getMessage());
            return [];
        }
    }

    public function semesters()
    {
        $rows = $this->all("SELECT DISTINCT acad_year, sem FROM enrollment_period ORDER BY acad_year DESC, sem DESC");
        foreach ($rows as &$r) {
            $r['key']   = $r['acad_year'] . '|' . $r['sem'];
            $r['label'] = self::semesterLabel($r['acad_year'], $r['sem']);
        }
        return $rows;
    }

    // Valid semester key, else the default.
    public function selectedSemester($requested)
    {
        $list = $this->semesters();
        foreach ($list as $s) {
            if ($s['key'] === $requested) return $requested;
        }
        if (!$list) return '';

        $sql = "SELECT DISTINCT ep.acad_year, ep.sem
                FROM subject_schedules ss
                JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID";
        $params = [];
        $where  = [];
        if ($this->group === 'faculty') {
            $where[] = "ss.facultyID = :uid";
            $params[':uid'] = $this->userID;
        } elseif ($this->group === 'student') {
            $sql .= self::STUDENT_JOIN;
            $where[] = "st.studentID = :uid";
            $params[':uid'] = $this->userID;
        }
        if (!$this->isDean()) $where[] = "ss.status = 'deployed'";
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY ep.acad_year DESC, ep.sem DESC LIMIT 1";
        $with = $this->all($sql, $params);
        return $with ? $with[0]['acad_year'] . '|' . $with[0]['sem'] : $list[0]['key'];
    }

    public static function splitSemester($key)
    {
        $p = explode('|', (string)$key, 2);
        return [$p[0] ?? '', (int)($p[1] ?? 0)];
    }

    // Program + block pairs.
    public function blocks()
    {
        $rows = $this->all(
            "SELECT program, block FROM students
             UNION
             SELECT ep.program, ss.block FROM subject_schedules ss
               JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             ORDER BY program, block"
        );
        if ($this->isDean()) {
            $allowed = array_column($this->programs(), 'program');
            $rows = array_values(array_filter($rows, function ($r) use ($allowed) {
                return in_array($r['program'], $allowed, true);
            }));
        }
        if ($this->isCoordinator()) {
            $mine = $this->assignedProgram();
            $rows = array_values(array_filter($rows, function ($r) use ($mine) {
                return $r['program'] === $mine;
            }));
        }
        foreach ($rows as &$r) {
            $r['value'] = $r['program'] . '|' . $r['block'];
            $r['label'] = $r['program'] . ' ' . $r['block'];
        }
        return $rows;
    }

    public function subjects()
    {
        $rows = $this->all(
            "SELECT s.subjectID, s.name,
                    EXISTS (SELECT 1 FROM subject_prerequisite p WHERE p.prerequisite = s.subjectID) AS is_prereq,
                    (SELECT GROUP_CONCAT(TRIM(p2.prerequisite) SEPARATOR ', ')
                       FROM subject_prerequisite p2 WHERE p2.subjectID = s.subjectID) AS requires
             FROM subjects s ORDER BY s.subjectID"
        );
        $offered = [];
        foreach ($this->all("SELECT TRIM(subjectID) AS sid, program, sem_available FROM syllabus") as $o) {
            $offered[$o['sid']][] = self::FILTER_BY_SEMESTER ? $o['program'] . ':' . (int)$o['sem_available'] : $o['program'];
        }
        foreach ($rows as &$r) {
            $r['type']     = self::classType($r['subjectID'], $r['name']);
            $r['kind']     = $r['is_prereq'] ? 'prereq' : 'normal';
            $r['programs'] = $offered[trim($r['subjectID'])] ?? [];
        }
        return $rows;
    }

    public function instructors()
    {
        return $this->all(
            "SELECT facultyID, first_name, last_name FROM faculty
             WHERE status = 'active' AND LOWER(role) <> 'registrar'
             ORDER BY last_name, first_name"
        );
    }

    public function rooms()
    {
        return $this->all("SELECT roomID FROM rooms ORDER BY roomID");
    }

    private function studentInfo()
    {
        $r = $this->all("SELECT program, block FROM students WHERE studentID = :id", [':id' => $this->userID]);
        return $r[0] ?? null;
    }

    // Schedule reads

    // Students see every class of their own program + block.
    const STUDENT_JOIN = " JOIN students st ON st.program = ep.program AND st.block = ss.block";

    const BASE_SELECT =
        "SELECT ss.schedID, ss.subjectID, s.name AS subject_name, ss.facultyID, ss.status, ss.class_format,
                CONCAT(f.first_name, ' ', f.last_name) AS faculty_name,
                ss.day AS day_code, ss.roomID, ss.time_start, ss.time_end, ss.block,
                ss.enrollmentID, ep.program, ep.acad_year, ep.sem, pr.department
         FROM subject_schedules ss
         LEFT JOIN subjects s ON s.subjectID = ss.subjectID
         JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
         JOIN programs pr ON pr.program = ep.program
         LEFT JOIN faculty f ON f.facultyID = ss.facultyID";

    private function decorate(array $r)
    {
        $r['subject_code'] = trim((string)$r['subjectID']);
        $r['subject_name'] = (string)$r['subject_name'];
        $r['days']         = self::parseDays($r['day_code']);
        $r['type']         = self::classType($r['subjectID'], $r['subject_name']);
        $r['block_label']  = $r['block'] !== null && $r['block'] !== '' ? $r['program'] . ' ' . $r['block'] : $r['program'] . ' (no block yet)';
        $r['range']        = self::fmtRange($r['time_start'], $r['time_end']);
        $r['format_label'] = self::formatLabel($r['class_format']);
        $r['faculty_name'] = $r['facultyID'] ? (self::properName($r['faculty_name'] ?? '') ?: 'TBA') : 'No instructor yet';
        return $r;
    }

    // Schedules for one semester, filtered by role.
    public function schedules($semKey, $search = '', array $filters = [])
    {
        if ($this->group === 'none') return [];
        list($acad, $sem) = self::splitSemester($semKey);
        $sql    = self::BASE_SELECT . " WHERE ep.acad_year = :ay AND ep.sem = :sem";
        $params = [':ay' => $acad, ':sem' => $sem];

        if ($this->group === 'faculty') {
            $sql .= " AND ss.facultyID = :uid";
            $params[':uid'] = $this->userID;
        } elseif ($this->group === 'student') {
            $sql = preg_replace('/^SELECT /', 'SELECT DISTINCT ', self::BASE_SELECT)
                 . self::STUDENT_JOIN
                 . " WHERE ep.acad_year = :ay AND ep.sem = :sem AND st.studentID = :uid";
            $params[':uid'] = $this->userID;
        }

        if (!$this->isDean()) {
            $sql .= " AND ss.status = 'deployed'";
        }

        if ($this->isDean()) {
            $d = $this->assignedDepartment();
            $sql .= " AND pr.department = :ddept";
            $params[':ddept'] = $d !== '' ? $d : '__none__';
        }

        if ($this->isCoordinator()) {
            $mine = $this->assignedProgram();
            $sql .= " AND ep.program = :cprog";
            $params[':cprog'] = $mine !== '' ? $mine : '__none__';
        }

        // The dean filters with dropdowns (program, block, status, instructor), not with text search.
        if ($this->isDean() && $filters) {
            $sql .= $this->deanFilterSql($filters, $params, true);
        }

        if ($search !== '' && !$this->isDean()) {
            if ($this->group === 'manager') {
                // Coordinator: block of the assigned program (e.g. "BSCS 1A"), subject, room or instructor.
                $sql .= " AND (CONCAT(ep.program, ss.block) LIKE :qb OR ss.subjectID LIKE :q OR s.name LIKE :q
                               OR ss.roomID LIKE :q OR CONCAT(f.first_name, ' ', f.last_name) LIKE :q)";
                $params[':q']  = '%' . str_replace(['%', '_'], '', $search) . '%';
                $params[':qb'] = '%' . str_replace([' ', '%', '_'], '', $search) . '%';
            } else {
                $sql .= " AND (ss.subjectID LIKE :q OR s.name LIKE :q OR ss.roomID LIKE :q OR ss.block LIKE :q
                               OR CONCAT(f.first_name, ' ', f.last_name) LIKE :q)";
                $params[':q'] = '%' . str_replace(['%', '_'], '', $search) . '%';
            }
        }
        $sql .= " ORDER BY ss.time_start, ss.schedID";
        return array_map([$this, 'decorate'], $this->all($sql, $params));
    }

    // Dean filters

    // Validated filters of the dean: program, block ("PROGRAM|BLOCK" or "none"), status, instructor (id or "none").
    // Sent filters are remembered in the session, so the list keeps them after deploying, editing or deleting.
    public function deanFilters(array $get, $semKey)
    {
        $empty = ['program' => '', 'block' => '', 'status' => '', 'instructor' => ''];
        if (!$this->isDean()) return $empty;

        if (isset($get['reset'])) {
            unset($_SESSION['sched_dean_filters']);
            return $empty;
        }
        if (isset($get['f_program']) || isset($get['f_block']) || isset($get['f_status']) || isset($get['f_instructor'])) {
            $in = [
                'program'    => $get['f_program'] ?? '',
                'block'      => $get['f_block'] ?? '',
                'status'     => $get['f_status'] ?? '',
                'instructor' => $get['f_instructor'] ?? '',
            ];
        } else {
            $in = $_SESSION['sched_dean_filters'] ?? $empty;
        }

        $f       = $empty;
        $allowed = array_column($this->programs(), 'program');

        $p = trim((string)($in['program'] ?? ''));
        $f['program'] = in_array($p, $allowed, true) ? $p : '';

        $b = (string)($in['block'] ?? '');
        if ($b === 'none') {
            $f['block'] = 'none';
        } elseif ($b !== '') {
            $parts = explode('|', $b, 2);
            if (count($parts) === 2 && $parts[1] !== '' && in_array($parts[0], $allowed, true)) {
                if ($f['program'] === '') $f['program'] = $parts[0];
                if ($parts[0] === $f['program']) $f['block'] = $b;   // block of another program is dropped
            }
        }

        $s = (string)($in['status'] ?? '');
        $f['status'] = isset(self::STATUSES[$s]) ? $s : '';

        $i = (string)($in['instructor'] ?? '');
        if ($i === 'none') {
            $f['instructor'] = 'none';
        } elseif (ctype_digit($i) && (int)$i > 0) {
            $ids = array_map('intval', array_column($this->scheduleInstructors($semKey), 'facultyID'));
            if (in_array((int)$i, $ids, true)) $f['instructor'] = (string)(int)$i;
        }

        $_SESSION['sched_dean_filters'] = $f;
        return $f;
    }

    private function deanFilterSql(array $f, array &$params, $withStatus)
    {
        $sql = '';
        if (($f['program'] ?? '') !== '') {
            $sql .= " AND ep.program = :fprog";
            $params[':fprog'] = $f['program'];
        }
        if (($f['block'] ?? '') === 'none') {
            $sql .= " AND (ss.block IS NULL OR ss.block = '')";
        } elseif (($f['block'] ?? '') !== '') {
            $sql .= " AND ss.block = :fblk";
            $params[':fblk'] = explode('|', $f['block'], 2)[1];
        }
        if (($f['instructor'] ?? '') === 'none') {
            $sql .= " AND ss.facultyID IS NULL";
        } elseif (($f['instructor'] ?? '') !== '') {
            $sql .= " AND ss.facultyID = :finst";
            $params[':finst'] = (int)$f['instructor'];
        }
        if ($withStatus && ($f['status'] ?? '') !== '') {
            $sql .= " AND ss.status = :fstat";
            $params[':fstat'] = $f['status'];
        }
        return $sql;
    }

    // Blocks for the filter dropdown (real blocks only, of the dean's programs).
    public function filterBlocks()
    {
        return array_values(array_filter($this->blocks(), function ($b) {
            return $b['block'] !== null && trim((string)$b['block']) !== '';
        }));
    }

    // Instructors that have a class in the dean's department for the semester.
    public function scheduleInstructors($semKey)
    {
        list($acad, $sem) = self::splitSemester($semKey);
        $d = $this->assignedDepartment();
        return $this->all(
            "SELECT DISTINCT f.facultyID, f.first_name, f.last_name
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             JOIN programs pr ON pr.program = ep.program
             JOIN faculty f ON f.facultyID = ss.facultyID
             WHERE ep.acad_year = :ay AND ep.sem = :sem AND pr.department = :d
             ORDER BY f.last_name, f.first_name",
            [':ay' => $acad, ':sem' => $sem, ':d' => $d !== '' ? $d : '__none__']
        );
    }

    // Draft / assigned / deployed totals for the current filters (the status filter itself is ignored).
    public function statusCounts($semKey, array $f)
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        if (!$this->isDean()) return $counts;
        list($acad, $sem) = self::splitSemester($semKey);
        $d      = $this->assignedDepartment();
        $params = [':ay' => $acad, ':sem' => $sem, ':ddept' => $d !== '' ? $d : '__none__'];
        $sql = "SELECT ss.status, COUNT(*) AS n
                FROM subject_schedules ss
                JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
                JOIN programs pr ON pr.program = ep.program
                WHERE ep.acad_year = :ay AND ep.sem = :sem AND pr.department = :ddept"
             . $this->deanFilterSql($f, $params, false)
             . " GROUP BY ss.status";
        foreach ($this->all($sql, $params) as $r) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['n'];
        }
        return $counts;
    }

    public function find($schedID)
    {
        $rows = $this->all(self::BASE_SELECT . " WHERE ss.schedID = :id", [':id' => (int)$schedID]);
        return $rows ? $this->decorate($rows[0]) : null;
    }

    // Group rows by weekday.
    public function week(array $rows)
    {
        $w = array_fill_keys(self::WEEK, []);
        foreach ($rows as $r) {
            foreach ($r['days'] as $d) $w[$d][] = $r;
        }
        return $w;
    }

    // Paginated student roster.
    public function classStudents(array $row, $q, $page, $per = 6)
    {
        if (empty($row['block'])) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'offset' => 0];
        }
        $where  = "st.program = :prog AND st.block = :blk";
        $params = [':prog' => $row['program'], ':blk' => $row['block']];
        if ($q !== '') {
            $where .= " AND (CAST(st.studentID AS CHAR) LIKE :q
                         OR CONCAT(st.last_name, ', ', st.first_name, ' ', st.middle_name) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $from = "FROM students st WHERE $where";

        $total = (int)($this->all("SELECT COUNT(DISTINCT st.studentID) AS n $from", $params)[0]['n'] ?? 0);
        $pages = max(1, (int)ceil($total / $per));
        $page  = min(max(1, (int)$page), $pages);
        $off   = ($page - 1) * $per;

        $rows = $this->all(
            "SELECT DISTINCT st.studentID, st.last_name, st.first_name, st.middle_name $from
             ORDER BY st.last_name, st.first_name LIMIT " . (int)$per . " OFFSET " . (int)$off,
            $params
        );
        foreach ($rows as &$r) {
            $mi = trim($r['middle_name']) !== '' ? ' ' . strtoupper(substr(trim($r['middle_name']), 0, 1)) . '.' : '';
            $r['name'] = self::properName($r['last_name']) . ', ' . self::properName($r['first_name']) . $mi;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'offset' => $off];
    }

    // Writes

    private function normTime($t)
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$t)
            ? substr((string)$t . ':00', 0, 8) : null;
    }

    private function conflicts($acad, $sem, $excludeID, $faculty, $room, $program, $block, array $days, $start, $end, $format = 'onsite')
    {
        $out  = [];
        $rows = $this->all(
            "SELECT ss.schedID, ss.subjectID, ss.day, ss.roomID, ss.facultyID, ss.block,
                    ss.time_start, ss.time_end, ss.class_format, ep.program
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             WHERE ep.acad_year = :ay AND ep.sem = :sem AND ss.schedID <> :id
               AND ss.time_start < :te AND ss.time_end > :ts",
            [':ay' => $acad, ':sem' => $sem, ':id' => (int)$excludeID, ':te' => $end, ':ts' => $start]
        );
        foreach ($rows as $r) {
            $shared = array_values(array_intersect($days, self::parseDays($r['day'])));
            if (!$shared) continue;
            $when = implode(', ', array_map(function ($d) { return substr($d, 0, 3); }, $shared))
                  . ' ' . self::fmtRange($r['time_start'], $r['time_end']);
            $sub  = trim((string)$r['subjectID']) !== '' ? trim($r['subjectID']) : 'another time slot';
            if ((string)$r['roomID'] === (string)$room && self::weeksOverlap($format, $r['class_format'])) {
                $weeks = $r['class_format'] === 'blended_even' ? 'even' : 'odd';
                if ($r['class_format'] === 'onsite') $hint = '';
                elseif ($format === 'onsite')        $hint = " - it is onsite only on $weeks weeks, but a full onsite class needs the room every week";
                else                                 $hint = " - it is onsite on $weeks weeks too, so choose the other weeks to share the room";
                $out[] = "Room $room is already booked for $sub ($when)$hint.";
            }
            if ($faculty > 0 && (int)$r['facultyID'] === (int)$faculty) {
                $out[] = "This instructor already teaches $sub ($when).";
            }
            if ($block !== '' && $r['program'] === $program && $r['block'] === $block) {
                $out[] = "$program $block already has $sub ($when).";
            }
        }
        return array_values(array_unique($out));
    }

    // Create (id null) or update. Subject, instructor and block are optional until Step 2.
    public function save($id, array $in)
    {
        $errors  = [];
        $current = null;
        if ($id) {
            $current = $this->find($id);
            if (!$current) return ['ok' => false, 'errors' => ['Schedule not found.']];
            if (!$this->canAccess($current)) return ['ok' => false, 'errors' => ['You can only manage schedules of your department.']];
        }

        $program = trim((string)($in['program'] ?? ''));
        $parts   = explode('|', (string)($in['block'] ?? ''), 2);
        $block   = $parts[1] ?? '';
        if ($block !== '') {
            if ($program === '')            $program = $parts[0];
            elseif ($parts[0] !== $program) $errors[] = 'The block does not belong to the selected program.';
        }
        $subjectID = (string)($in['subject'] ?? '');
        $faculty   = (int)($in['instructor'] ?? 0);
        $format    = ($in['delivery'] ?? 'onsite') === 'blended'
                   ? 'blended_' . (($in['onsite_weeks'] ?? 'odd') === 'even' ? 'even' : 'odd')
                   : 'onsite';
        $room      = trim((string)($in['room'] ?? ''));
        $days      = array_values(array_intersect(self::WEEK, (array)($in['days'] ?? [])));
        $start     = $this->normTime($in['start_time'] ?? '');
        $end       = $this->normTime($in['end_time'] ?? '');
        list($acad, $sem) = self::splitSemester($in['semester'] ?? '');

        if ($program === '')           $errors[] = 'Select a program.';
        if ($room === '')              $errors[] = 'Select a room.';
        if (!$days)                    $errors[] = 'Select at least one day.';
        if (!$start || !$end)          $errors[] = 'Select a start and end time.';
        elseif ($end <= $start)        $errors[] = 'End time must be later than start time.';
        if ($acad === '' || $sem <= 0) $errors[] = 'Select a semester.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $pd = $this->all("SELECT department FROM programs WHERE program = :p", [':p' => $program]);
        if (!$pd || $pd[0]['department'] !== $this->assignedDepartment()) {
            return ['ok' => false, 'errors' => ['You can only manage programs of the ' . $this->assignedDepartment() . ' department.']];
        }

        $ep = $this->all(
            "SELECT enrollmentID FROM enrollment_period WHERE program = :p AND acad_year = :ay AND sem = :s LIMIT 1",
            [':p' => $program, ':ay' => $acad, ':s' => $sem]
        );
        if (!$ep) return ['ok' => false, 'errors' => ["No enrollment period exists for $program in that semester."]];

        if (!$this->all("SELECT 1 FROM rooms WHERE roomID = :v", [':v' => $room])) $errors[] = 'Unknown room.';
        if ($faculty > 0 && !$this->all("SELECT 1 FROM faculty WHERE facultyID = :v", [':v' => $faculty])) {
            $errors[] = 'Unknown instructor.';
        }
        if ($subjectID !== '') {
            if (!$this->all("SELECT 1 FROM subjects WHERE subjectID = :v", [':v' => $subjectID])) {
                $errors[] = 'Unknown subject.';
            } else {
                $listed = $this->all("SELECT program, sem_available FROM syllabus WHERE TRIM(subjectID) = TRIM(:s)", [':s' => $subjectID]);
                if ($listed) {
                    $ok = false;
                    foreach ($listed as $l) {
                        if ($l['program'] === $program && (!self::FILTER_BY_SEMESTER || (int)$l['sem_available'] === $sem)) $ok = true;
                    }
                    if (!$ok) {
                        $errors[] = "That subject is not offered in $program" . (self::FILTER_BY_SEMESTER ? " for semester $sem" : '') . '.';
                    }
                }
            }
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $assigned = $subjectID !== '' && $faculty > 0 && $block !== '';
        $status   = $current['status'] ?? 'draft';
        if ($status === 'deployed') {
            if (!$assigned) {
                return ['ok' => false, 'errors' => ['A deployed schedule must keep its subject, instructor and block. Undeploy it first to clear them.']];
            }
        } else {
            $status = $assigned ? 'assigned' : 'draft';
        }

        $errors = $this->conflicts($acad, $sem, (int)$id, $faculty, $room, $program, $block, $days, $start, $end, $format);
        if ($errors) return ['ok' => false, 'errors' => $errors, 'conflict' => true];

        $params = [
            ':sub' => $subjectID !== '' ? $subjectID : null,
            ':fac' => $faculty > 0 ? $faculty : null,
            ':eid' => (int)$ep[0]['enrollmentID'],
            ':day' => self::daysToCode($days), ':room' => $room,
            ':ts' => $start, ':te' => $end,
            ':blk' => $block !== '' ? $block : null,
            ':status' => $status,
            ':fmt' => $format,
        ];
        try {
            if ($id) {
                $params[':id'] = (int)$id;
                $st = $this->pdo->prepare(
                    "UPDATE subject_schedules SET subjectID = :sub, facultyID = :fac, enrollmentID = :eid,
                            day = :day, roomID = :room, time_start = :ts, time_end = :te, block = :blk, status = :status, class_format = :fmt
                     WHERE schedID = :id"
                );
            } else {
                $st = $this->pdo->prepare(
                    "INSERT INTO subject_schedules (subjectID, facultyID, enrollmentID, day, roomID, time_start, time_end, block, status, class_format)
                     VALUES (:sub, :fac, :eid, :day, :room, :ts, :te, :blk, :status, :fmt)"
                );
            }
            $st->execute($params);
        } catch (PDOException $e) {
            error_log('Schedule save failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['Could not save the schedule. Please try again.']];
        }
        return ['ok' => true, 'errors' => [], 'id' => $id ? (int)$id : (int)$this->pdo->lastInsertId()];
    }

    // Step 3: deploy (assigned -> deployed) or undeploy (deployed -> assigned). Returns an error message or ''.
    public function setStatus($id, $to)
    {
        $row = $this->find($id);
        if (!$row) return 'Schedule not found.';
        if (!$this->canAccess($row)) return 'You can only manage schedules of your department.';

        if ($to === 'deployed') {
            if ($row['status'] === 'deployed') return 'This schedule is already deployed.';
            if (!$row['subjectID'] || !$row['facultyID'] || !$row['block']) {
                return 'Assign a subject, instructor and block before deploying.';
            }
            $new = 'deployed';
        } else {
            if ($row['status'] !== 'deployed') return 'This schedule is not deployed.';
            $new = 'assigned';
        }
        try {
            $st = $this->pdo->prepare("UPDATE subject_schedules SET status = :s WHERE schedID = :id");
            $st->execute([':s' => $new, ':id' => (int)$id]);
        } catch (PDOException $e) {
            error_log('Schedule status failed: ' . $e->getMessage());
            return 'Could not change the status.';
        }
        return '';
    }

    // Syllabus modules linked to a schedule (they are deleted together with it).
    public function syllabusCount($schedID)
    {
        $r = $this->all("SELECT COUNT(*) AS n FROM syllabus_modules WHERE schedID = :id", [':id' => (int)$schedID]);
        return (int)($r[0]['n'] ?? 0);
    }

    // Request handling (POST -> redirect -> GET), flash + CSRF

    public function csrf()
    {
        if (empty($_SESSION['sched_csrf'])) {
            $_SESSION['sched_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['sched_csrf'];
    }

    public function takeFlash()
    {
        $f = $_SESSION['sched_flash'] ?? null;
        unset($_SESSION['sched_flash']);
        return $f;
    }

    public function takeOld()
    {
        $o = $_SESSION['sched_old'] ?? null;
        unset($_SESSION['sched_old']);
        return $o;
    }

    private function go($self, array $q)
    {
        header('Location: ' . $self . ($q ? '?' . http_build_query($q) : ''));
        exit;
    }

    public function delete($id)
    {
        try {
            $st = $this->pdo->prepare("DELETE FROM subject_schedules WHERE schedID = :id");
            $st->execute([':id' => (int)$id]);
            return $st->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('Schedule delete failed: ' . $e->getMessage());
            return false;
        }
    }

    // Run before any output.
    public function handlePost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

        $self   = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $semKey = (string)($_POST['semester'] ?? '');
        $action = $_POST['sched_action'] ?? '';
        $id     = (int)($_POST['schedID'] ?? 0);

        if (!$this->canManage()
            || !hash_equals($this->csrf(), (string)($_POST['csrf'] ?? ''))) {
            $_SESSION['sched_flash'] = ['type' => 'error', 'msgs' => ['You are not allowed to do that.']];
            $this->go($self, []);
        }

        if ($action === 'create') {
            $res = $this->save(null, $_POST);
            if ($res['ok']) {
                $_SESSION['sched_flash'] = ['type' => 'ok', 'msgs' => ['Time slot added as a draft. Open it to assign the subject, instructor and block.']];
                $this->go($self, ['sem' => $semKey]);
            }
            $_SESSION['sched_old']   = $_POST;
            $_SESSION['sched_flash'] = ['type' => !empty($res['conflict']) ? 'conflict' : 'error', 'msgs' => $res['errors']];
            $this->go($self, ['sem' => $semKey, 'add' => 1]);
        }

        if ($action === 'update' && $id > 0) {
            $res = $this->save($id, $_POST);
            if ($res['ok']) {
                $_SESSION['sched_flash'] = ['type' => 'ok', 'msgs' => ['Schedule updated.']];
                $this->go($self, ['view' => 'class', 'id' => $id]);
            }
            $_SESSION['sched_old']   = $_POST;
            $_SESSION['sched_flash'] = ['type' => !empty($res['conflict']) ? 'conflict' : 'error', 'msgs' => $res['errors']];
            $this->go($self, ['view' => 'edit', 'id' => $id]);
        }

        if (($action === 'deploy' || $action === 'undeploy') && $id > 0) {
            $err = $this->setStatus($id, $action === 'deploy' ? 'deployed' : 'assigned');
            $_SESSION['sched_flash'] = $err === ''
                ? ['type' => 'ok', 'msgs' => [$action === 'deploy' ? 'Schedule deployed. Students, professors and coordinators can now see it.' : 'Schedule undeployed.']]
                : ['type' => 'error', 'msgs' => [$err]];
            $this->go($self, ($_POST['back'] ?? '') === 'class' ? ['view' => 'class', 'id' => $id] : ['sem' => $semKey]);
        }

        if ($action === 'delete' && $id > 0) {
            $row = $this->find($id);
            $ok  = $row && $this->canAccess($row) && $this->delete($id);
            $_SESSION['sched_flash'] = $ok
                ? ['type' => 'ok', 'msgs' => ['Schedule deleted.']]
                : ['type' => 'error', 'msgs' => ['Could not delete the schedule.']];
            $this->go($self, $row ? ['sem' => $row['acad_year'] . '|' . $row['sem']] : []);
        }

        $this->go($self, []);
    }
}