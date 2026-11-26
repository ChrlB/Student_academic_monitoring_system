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

    // Scheduling rules
    const OPEN_TIME    = '07:00:00';   // earliest start
    const CLOSE_TIME   = '21:00:00';   // latest end
    const LUNCH_START  = '12:00:00';   // no class during lunch
    const LUNCH_END    = '13:00:00';
    const REST_MINUTES = 30;           // minimum gap between two classes of the same instructor
    // Hours per week each subject of a block must be scheduled for (100% = the number below).
    const WEEKLY_HOURS = ['LEC' => 4, 'GEC' => 4, 'LAB' => 4];

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

    // Coordinators share the exact same personal teaching schedule view as faculty/professors.
    public static function roleGroup($role)
    {
        $r = strtolower(trim((string)$role));
        if (strpos($r, 'registrar') !== false) return 'none';
        if (preg_match('/dean|admin/', $r)) return 'manager';
        if (preg_match('/coordinator|prof|faculty|instructor|teacher/', $r)) return 'faculty';
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

    // A coordinator who types a search looks at the whole assigned program, not only their own classes.
    public function isMonitoring($search)
    {
        return $this->isCoordinator() && trim((string)$search) !== '';
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

    // Dean sees everything in their department.
    public function canAccess(array $row)
    {
        if ($this->isDean()) {
            return $this->assignedDepartment() !== '' && $row['department'] === $this->assignedDepartment();
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

    public static function formatLabel($f)
    {
        if ($f === 'blended_odd')  return 'Alternating online/onsite (onsite on odd weeks)';
        if ($f === 'blended_even') return 'Alternating online/onsite (onsite on even weeks)';
        return 'Full onsite';
    }

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

    // Time helpers

    public static function minutes($t)
    {
        $p = explode(':', (string)$t);
        return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
    }

    public static function fromMinutes($m)
    {
        $m = max(0, min(23 * 60 + 59, (int)$m));
        return sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    }

    public static function fmtHours($h)
    {
        return rtrim(rtrim(number_format((float)$h, 2, '.', ''), '0'), '.');
    }

    public static function requiredHours($type)
    {
        return self::WEEKLY_HOURS[$type] ?? 4;
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
        return $this->all("SELECT roomID, status FROM rooms ORDER BY roomID");
    }

    // Schedule reads

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
            if ($this->isMonitoring($search)) {
                // Coordinator searching: the deployed schedule of the assigned program.
                $mine = $this->assignedProgram();
                $sql .= " AND ep.program = :cprog";
                $params[':cprog'] = $mine !== '' ? $mine : '__none__';
            } else {
                $sql .= " AND ss.facultyID = :uid";
                $params[':uid'] = $this->userID;
            }
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

        if ($this->isDean() && $filters) {
            $sql .= $this->deanFilterSql($filters, $params, true);
        }

        if ($search !== '' && !$this->isDean()) {
            if ($this->isCoordinator()) {
                // Coordinator: block ("BSCS 1A" or "1A"), instructor (either name order) or subject.
                $sql .= " AND (CONCAT(ep.program, ss.block) LIKE :qb OR ss.block LIKE :q
                               OR CONCAT(f.first_name, ' ', f.last_name) LIKE :q
                               OR CONCAT(f.last_name, ' ', f.first_name) LIKE :q
                               OR CONCAT(f.last_name, ', ', f.first_name) LIKE :q
                               OR ss.subjectID LIKE :q OR s.name LIKE :q)";
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
                if ($parts[0] === $f['program']) $f['block'] = $b;
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

    public function filterBlocks()
    {
        return array_values(array_filter($this->blocks(), function ($b) {
            return $b['block'] !== null && trim((string)$b['block']) !== '';
        }));
    }

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

    // Weekly hours per subject (100% = WEEKLY_HOURS)

    // Hours per weekday of one subject of a block. The weekly hours can be spread over different days
    // and slots (for example 2 hrs Monday + 2 hrs Wednesday, or 1 hr on four days); they are added up.
    public function hoursByDay($acad, $sem, $program, $block, $subjectID, $excludeID = 0)
    {
        $rows = $this->all(
            "SELECT ss.day, ss.time_start, ss.time_end
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             WHERE ep.acad_year = :ay AND ep.sem = :sem AND ep.program = :p AND ss.block = :b
               AND TRIM(ss.subjectID) = TRIM(:s) AND ss.schedID <> :id",
            [':ay' => $acad, ':sem' => (int)$sem, ':p' => $program, ':b' => $block, ':s' => $subjectID, ':id' => (int)$excludeID]
        );
        $by = [];
        foreach ($rows as $r) {
            $h = (self::minutes($r['time_end']) - self::minutes($r['time_start'])) / 60;
            foreach (self::parseDays($r['day']) as $d) $by[$d] = ($by[$d] ?? 0) + $h;
        }
        return $by;
    }

    public function weeklyHours($acad, $sem, $program, $block, $subjectID, $excludeID = 0)
    {
        return (float)array_sum($this->hoursByDay($acad, $sem, $program, $block, $subjectID, $excludeID));
    }

    // "Mon 2h · Wed 2h"
    public static function fmtDayHours(array $by)
    {
        $parts = [];
        foreach (self::WEEK as $d) {
            if (!empty($by[$d])) $parts[] = substr($d, 0, 3) . ' ' . self::fmtHours($by[$d]) . 'h';
        }
        return implode(' · ', $parts);
    }

    // Hours report of one schedule row (null while it has no subject or block).
    public function hoursFor(array $row)
    {
        if (trim((string)$row['subjectID']) === '' || (string)$row['block'] === '') return null;
        $req = self::requiredHours($row['type']);
        $by  = $this->hoursByDay($row['acad_year'], $row['sem'], $row['program'], $row['block'], $row['subjectID'], 0);
        $got = (float)array_sum($by);
        return [
            'type' => $row['type'], 'required' => $req, 'scheduled' => $got, 'by_day' => $by,
            'pct'  => (int)round($got / $req * 100), 'left' => max(0, $req - $got),
        ];
    }

    // Every scheduled subject of one block with its hours per week.
    public function blockHours($semKey, $program, $block)
    {
        list($acad, $sem) = self::splitSemester($semKey);
        $rows = $this->all(
            "SELECT TRIM(ss.subjectID) AS sid, s.name, ss.day, ss.time_start, ss.time_end
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             LEFT JOIN subjects s ON s.subjectID = ss.subjectID
             WHERE ep.acad_year = :ay AND ep.sem = :sem AND ep.program = :p AND ss.block = :b
               AND ss.subjectID IS NOT NULL AND TRIM(ss.subjectID) <> ''",
            [':ay' => $acad, ':sem' => $sem, ':p' => $program, ':b' => $block]
        );
        $out = [];
        foreach ($rows as $r) {
            $k = $r['sid'];
            if (!isset($out[$k])) {
                $type = self::classType($k, (string)$r['name']);
                $out[$k] = ['subject' => $k, 'name' => (string)$r['name'], 'type' => $type,
                            'required' => self::requiredHours($type), 'scheduled' => 0.0, 'by_day' => []];
            }
            $len = (self::minutes($r['time_end']) - self::minutes($r['time_start'])) / 60;
            foreach (self::parseDays($r['day']) as $d) {
                $out[$k]['by_day'][$d] = ($out[$k]['by_day'][$d] ?? 0) + $len;
                $out[$k]['scheduled'] += $len;
            }
        }
        ksort($out);
        foreach ($out as &$o) {
            $o['pct']  = (int)round($o['scheduled'] / $o['required'] * 100);
            $o['left'] = max(0, $o['required'] - $o['scheduled']);
        }
        return array_values($out);
    }

    // Availability

    // Rooms or instructors with their booked slots on one weekday of a semester.
    // Rooms and instructors are shared by all departments, so every department's classes count.
    public function availability($semKey, $day, $kind)
    {
        list($acad, $sem) = self::splitSemester($semKey);
        $rows = $this->all(
            "SELECT ss.subjectID, ss.day, ss.roomID, ss.facultyID, ss.block, ss.time_start, ss.time_end,
                    ss.class_format, ep.program, CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             LEFT JOIN faculty f ON f.facultyID = ss.facultyID
             WHERE ep.acad_year = :ay AND ep.sem = :sem",
            [':ay' => $acad, ':sem' => $sem]
        );

        $ents = [];
        if ($kind === 'instructors') {
            foreach ($this->instructors() as $i) {
                $ents[(string)$i['facultyID']] = [
                    'label' => self::properName($i['last_name'] . ', ' . $i['first_name']),
                    'note' => '', 'blocked' => false, 'busy' => [],
                ];
            }
        } else {
            foreach ($this->all("SELECT roomID, status FROM rooms ORDER BY roomID") as $r) {
                $ok = strtolower(trim((string)$r['status'])) === 'available';
                $ents[(string)$r['roomID']] = [
                    'label' => 'Room ' . $r['roomID'],
                    'note' => $ok ? '' : ucfirst(trim((string)$r['status'])),
                    'blocked' => !$ok, 'busy' => [],
                ];
            }
        }

        foreach ($rows as $r) {
            if (!in_array($day, self::parseDays($r['day']), true)) continue;
            $key = $kind === 'instructors' ? (string)$r['facultyID'] : (string)$r['roomID'];
            if ($key === '' || !isset($ents[$key])) continue;
            $blk = ($r['block'] !== null && $r['block'] !== '') ? $r['program'] . ' ' . $r['block'] : $r['program'];
            $sub = trim((string)$r['subjectID']);
            $ents[$key]['busy'][] = [
                'start' => $r['time_start'], 'end' => $r['time_end'],
                'label' => ($sub !== '' ? $sub : 'Time slot') . ' • ' . $blk,
                'who'   => $kind === 'instructors'
                            ? 'Room ' . $r['roomID']
                            : ($r['facultyID'] ? self::properName($r['faculty_name'] ?? '') : 'No instructor yet'),
                'alt'   => $r['class_format'] !== 'onsite',
                'range' => self::fmtRange($r['time_start'], $r['time_end']),
            ];
        }
        foreach ($ents as &$e) {
            usort($e['busy'], function ($a, $b) { return strcmp($a['start'], $b['start']); });
        }
        return $ents;
    }

    // Ids that are free for the whole range (instructors also keep the rest gap).
    public function freeFor(array $ents, $from, $to, $kind)
    {
        $pad = $kind === 'instructors' ? self::REST_MINUTES : 0;
        $a = self::minutes($from) - $pad;
        $b = self::minutes($to) + $pad;
        $out = [];
        foreach ($ents as $id => $e) {
            if ($e['blocked']) continue;
            $free = true;
            foreach ($e['busy'] as $x) {
                if (self::minutes($x['start']) < $b && self::minutes($x['end']) > $a) { $free = false; break; }
            }
            if ($free) $out[$id] = $e['label'];
        }
        return $out;
    }

    public function find($schedID)
    {
        $rows = $this->all(self::BASE_SELECT . " WHERE ss.schedID = :id", [':id' => (int)$schedID]);
        return $rows ? $this->decorate($rows[0]) : null;
    }

    public function week(array $rows)
    {
        $w = array_fill_keys(self::WEEK, []);
        foreach ($rows as $r) {
            foreach ($r['days'] as $d) $w[$d][] = $r;
        }
        return $w;
    }

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

        // An instructor needs a rest gap between two classes (touching or closer than REST_MINUTES).
        if ($faculty > 0) {
            $gs = self::fromMinutes(self::minutes($start) - self::REST_MINUTES);
            $ge = self::fromMinutes(self::minutes($end) + self::REST_MINUTES);
            $near = $this->all(
                "SELECT ss.subjectID, ss.day, ss.time_start, ss.time_end
                 FROM subject_schedules ss
                 JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
                 WHERE ep.acad_year = :ay AND ep.sem = :sem AND ss.schedID <> :id AND ss.facultyID = :f
                   AND ss.time_start < :ge AND ss.time_end > :gs",
                [':ay' => $acad, ':sem' => $sem, ':id' => (int)$excludeID, ':f' => (int)$faculty, ':ge' => $ge, ':gs' => $gs]
            );
            foreach ($near as $r) {
                if ($r['time_start'] < $end && $r['time_end'] > $start) continue;   // real overlap, reported above
                $shared = array_values(array_intersect($days, self::parseDays($r['day'])));
                if (!$shared) continue;
                $when = implode(', ', array_map(function ($d) { return substr($d, 0, 3); }, $shared))
                      . ' ' . self::fmtRange($r['time_start'], $r['time_end']);
                $sub  = trim((string)$r['subjectID']) !== '' ? trim($r['subjectID']) : 'another time slot';
                $out[] = "An instructor needs at least " . self::REST_MINUTES . " minutes between classes. This instructor teaches $sub ($when), which is too close to this time slot.";
            }
        }

        return array_values(array_unique($out));
    }

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
        else {
            if ($start < self::OPEN_TIME || $end > self::CLOSE_TIME) {
                $errors[] = 'Classes can only be scheduled between 7:00 AM and 9:00 PM.';
            }
            if ($start < self::LUNCH_END && $end > self::LUNCH_START) {
                $errors[] = 'Classes cannot be scheduled during the lunch break (12:00 PM – 1:00 PM).';
            }
        }
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

        $rm = $this->all("SELECT roomID, status FROM rooms WHERE roomID = :v", [':v' => $room]);
        if (!$rm) {
            $errors[] = 'Unknown room.';
        } elseif (strtolower(trim((string)$rm[0]['status'])) !== 'available'
                  && (!$current || (string)$current['roomID'] !== $room)) {
            $errors[] = "Room $room is currently marked as " . trim((string)$rm[0]['status']) . ' and cannot be booked.';
        }
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

        // Each subject of a block has a fixed number of hours per week (4 for lecture and laboratory).
        if ($subjectID !== '' && $block !== '') {
            $sn   = $this->all("SELECT name FROM subjects WHERE subjectID = :v", [':v' => $subjectID]);
            $type = self::classType($subjectID, $sn[0]['name'] ?? '');
            $need = self::requiredHours($type);
            $by   = $this->hoursByDay($acad, $sem, $program, $block, $subjectID, (int)$id);
            $have = (float)array_sum($by);
            $add  = (self::minutes($end) - self::minutes($start)) / 60 * count($days);
            if ($have + $add > $need + 0.001) {
                return ['ok' => false, 'errors' => [sprintf(
                    '%s for %s %s needs exactly %s hours per week (%s), spread over any days. %s hour(s) are already scheduled%s, so only %s more hour(s) fit, but this slot adds %s.',
                    trim($subjectID), $program, $block, self::fmtHours($need), $type,
                    self::fmtHours($have), $by ? ' (' . self::fmtDayHours($by) . ')' : '',
                    self::fmtHours(max(0, $need - $have)), self::fmtHours($add)
                )]];
            }
        }

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
            $h = $this->hoursFor($row);
            if ($h && abs($h['scheduled'] - $h['required']) > 0.001) {
                return sprintf(
                    'Cannot deploy yet: %s for %s has %s of %s required hours per week (%d%%)%s. %s',
                    $row['subject_code'], $row['block_label'], self::fmtHours($h['scheduled']), self::fmtHours($h['required']), $h['pct'],
                    $h['by_day'] ? ' – ' . self::fmtDayHours($h['by_day']) : '',
                    $h['scheduled'] < $h['required']
                        ? 'Add the remaining ' . self::fmtHours($h['left']) . ' hour(s) on any day first.'
                        : 'Remove the extra hours first.'
                );
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

    public function syllabusCount($schedID)
    {
        $r = $this->all("SELECT COUNT(*) AS n FROM syllabus_modules WHERE schedID = :id", [':id' => (int)$schedID]);
        return (int)($r[0]['n'] ?? 0);
    }

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