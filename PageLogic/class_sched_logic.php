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

    private $pdo;
    public $role;
    public $group;    // manager | faculty | student
    public $userID;

    public function __construct()
    {
        global $db_connection;

        if (isset($db_connection) && $db_connection instanceof PDO) {
            $this->pdo = $db_connection;
        } else {
            try {
                $this->pdo = new PDO(
                    "mysql:host=localhost;port=3306;dbname=ams_db;charset=utf8mb4",
                    "root",
                    "",
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            } catch (PDOException $e) {
                error_log('Schedule DB connection failed: ' . $e->getMessage());
                die('Database connection error.');
            }
        }

        $this->role   = strtolower(trim($_SESSION['role'] ?? 'student'));
        $this->userID = (int)($_SESSION['userID'] ?? 0);
        $this->group  = self::roleGroup($this->role);
    }

// Pang identify ng roles
    public static function roleGroup($role)
    {
        $r = strtolower(trim((string)$role));
        if (preg_match('/coordinator|dean|registrar|admin/', $r)) return 'manager';
        if (preg_match('/prof|faculty|instructor|teacher/', $r))  return 'faculty';
        return 'student';
    }

// Only the program coordinator and the dean may create or edit schedules.
    public function canManage()
    {
        return (bool)preg_match('/coordinator|dean/', $this->role);
    }

//Pang format
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
        if (stripos((string)$name, '(LAB)') !== false) return 'LAB';
        if (stripos((string)$name, '(LEC)') !== false) return 'LEC';
        return preg_match('/L$/i', trim((string)$subjectID)) ? 'LAB' : 'LEC';
    }

    public static function typeLabel($type)
    {
        return $type === 'LAB' ? 'LABORATORY (LAB)' : 'LECTURE (LEC)';
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

//Lookups for dropdowns

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

// Return a valid "acad|sem" key
    public function selectedSemester($requested)
    {
        $list = $this->semesters();
        foreach ($list as $s) {
            if ($s['key'] === $requested) return $requested;
        }
        return $list ? $list[0]['key'] : '';
    }

    public static function splitSemester($key)
    {
        $p = explode('|', (string)$key, 2);
        return [$p[0] ?? '', (int)($p[1] ?? 0)];
    }

// Program + block pairs
    public function blocks()
    {
        $rows = $this->all(
            "SELECT program, block FROM students
             UNION
             SELECT ep.program, ss.block FROM subject_schedules ss
               JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             ORDER BY program, block"
        );
        foreach ($rows as &$r) {
            $r['value'] = $r['program'] . '|' . $r['block'];
            $r['label'] = $r['program'] . ' ' . $r['block'];
        }
        return $rows;
    }

    public function subjects()
    {
        $rows = $this->all("SELECT subjectID, name FROM subjects ORDER BY subjectID");
        foreach ($rows as &$r) {
            $r['type'] = self::classType($r['subjectID'], $r['name']);
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

//Schedule reads (sql command tu)

    const BASE_SELECT =
        "SELECT ss.schedID, ss.subjectID, s.name AS subject_name, ss.facultyID,
                CONCAT(f.first_name, ' ', f.last_name) AS faculty_name,
                ss.day AS day_code, ss.roomID, ss.time_start, ss.time_end, ss.block,
                ss.enrollmentID, ep.program, ep.acad_year, ep.sem
         FROM subject_schedules ss
         JOIN subjects s ON s.subjectID = ss.subjectID
         JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
         LEFT JOIN faculty f ON f.facultyID = ss.facultyID";

    private function decorate(array $r)
    {
        $r['subject_code'] = trim($r['subjectID']);
        $r['days']         = self::parseDays($r['day_code']);
        $r['type']         = self::classType($r['subjectID'], $r['subject_name']);
        $r['block_label']  = $r['program'] . ' ' . $r['block'];
        $r['range']        = self::fmtRange($r['time_start'], $r['time_end']);
        $r['faculty_name'] = self::properName($r['faculty_name'] ?? '') ?: 'TBA';
        return $r;
    }

// Role-filtered schedules for one semester ("acad|sem"). Manager view can filter by block text
    public function schedules($semKey, $search = '')
    {
        list($acad, $sem) = self::splitSemester($semKey);
        $sql    = self::BASE_SELECT . " WHERE ep.acad_year = :ay AND ep.sem = :sem";
        $params = [':ay' => $acad, ':sem' => $sem];

        if ($this->group === 'faculty') {
            $sql .= " AND ss.facultyID = :uid";
            $params[':uid'] = $this->userID;
        } elseif ($this->group === 'student') {
            $st = $this->studentInfo();
            if (!$st) return [];
            $sql .= " AND ep.program = :prog AND ss.block = :blk";
            $params[':prog'] = $st['program'];
            $params[':blk']  = $st['block'];
        } elseif ($search !== '') {
            $sql .= " AND CONCAT(ep.program, ss.block) LIKE :q";
            $params[':q'] = '%' . str_replace([' ', '%', '_'], '', $search) . '%';
        }

        $sql .= " ORDER BY ss.time_start, ss.schedID";
        return array_map([$this, 'decorate'], $this->all($sql, $params));
    }

    public function find($schedID)
    {
        $rows = $this->all(self::BASE_SELECT . " WHERE ss.schedID = :id", [':id' => (int)$schedID]);
        return $rows ? $this->decorate($rows[0]) : null;
    }

// Groups rows into Monday..Sunday buckets (a row with several days appears in each). 
    public function week(array $rows)
    {
        $w = array_fill_keys(self::WEEK, []);
        foreach ($rows as $r) {
            foreach ($r['days'] as $d) $w[$d][] = $r;
        }
        return $w;
    }

// Paginated, searchable roster for a class.
    public function classStudents(array $row, $q, $page, $per = 6)
    {
        $where  = "es.subjectID = :sub AND es.enrollmentID = :eid AND es.status = 'approved'
                   AND st.program = :prog AND st.block = :blk";
        $params = [':sub' => $row['subjectID'], ':eid' => $row['enrollmentID'],
                   ':prog' => $row['program'], ':blk' => $row['block']];
        if ($q !== '') {
            $where .= " AND (CAST(st.studentID AS CHAR) LIKE :q
                         OR CONCAT(st.last_name, ', ', st.first_name, ' ', st.middle_name) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $from = "FROM enrolled_subjects es JOIN students st ON st.studentID = es.studentID WHERE $where";

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

//Writes 

    private function normTime($t)
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$t)
            ? substr((string)$t . ':00', 0, 8) : null;
    }

    private function conflicts($acad, $sem, $excludeID, $faculty, $room, $program, $block, array $days, $start, $end)
    {
        $out  = [];
        $rows = $this->all(
            "SELECT ss.schedID, ss.subjectID, ss.day, ss.roomID, ss.facultyID, ss.block,
                    ss.time_start, ss.time_end, ep.program
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
            $sub  = trim($r['subjectID']);
            if ((string)$r['roomID'] === (string)$room) {
                $out[] = "Room $room is already booked for $sub ($when).";
            }
            if ((int)$r['facultyID'] === (int)$faculty) {
                $out[] = "This instructor already teaches $sub ($when).";
            }
            if ($r['program'] === $program && $r['block'] === $block) {
                $out[] = "$program $block already has $sub ($when).";
            }
        }
        return array_values(array_unique($out));
    }

    // Create ($id null) or update a schedule. Returns ['ok'=>bool, 'errors'=>[...]].
    public function save($id, array $in)
    {
        $errors = [];

        $blockParts = explode('|', (string)($in['block'] ?? ''), 2);
        $program    = $blockParts[0] ?? '';
        $block      = $blockParts[1] ?? '';
        $subjectID  = (string)($in['subject'] ?? '');
        $faculty    = (int)($in['instructor'] ?? 0);
        $room       = trim((string)($in['room'] ?? ''));
        $days       = array_values(array_intersect(self::WEEK, (array)($in['days'] ?? [])));
        $start      = $this->normTime($in['start_time'] ?? '');
        $end        = $this->normTime($in['end_time'] ?? '');
        list($acad, $sem) = self::splitSemester($in['semester'] ?? '');

        if ($program === '' || $block === '') $errors[] = 'Select a block / section.';
        if ($subjectID === '')                $errors[] = 'Select a subject.';
        if ($faculty <= 0)                    $errors[] = 'Select an instructor.';
        if ($room === '')                     $errors[] = 'Select a room.';
        if (!$days)                           $errors[] = 'Select at least one day.';
        if (!$start || !$end)                 $errors[] = 'Select a start and end time.';
        elseif ($end <= $start)               $errors[] = 'End time must be later than start time.';
        if ($acad === '' || $sem <= 0)        $errors[] = 'Select a semester.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $ep = $this->all(
            "SELECT enrollmentID FROM enrollment_period WHERE program = :p AND acad_year = :ay AND sem = :s LIMIT 1",
            [':p' => $program, ':ay' => $acad, ':s' => $sem]
        );
        if (!$ep) return ['ok' => false, 'errors' => ["No enrollment period exists for $program in that semester."]];

        if (!$this->all("SELECT 1 FROM subjects WHERE subjectID = :v", [':v' => $subjectID]))  $errors[] = 'Unknown subject.';
        if (!$this->all("SELECT 1 FROM faculty WHERE facultyID = :v", [':v' => $faculty]))     $errors[] = 'Unknown instructor.';
        if (!$this->all("SELECT 1 FROM rooms WHERE roomID = :v", [':v' => $room]))             $errors[] = 'Unknown room.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $errors = $this->conflicts($acad, $sem, (int)$id, $faculty, $room, $program, $block, $days, $start, $end);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $params = [
            ':sub' => $subjectID, ':fac' => $faculty, ':eid' => (int)$ep[0]['enrollmentID'],
            ':day' => self::daysToCode($days), ':room' => $room,
            ':ts' => $start, ':te' => $end, ':blk' => $block,
        ];
        try {
            if ($id) {
                $params[':id'] = (int)$id;
                $st = $this->pdo->prepare(
                    "UPDATE subject_schedules SET subjectID = :sub, facultyID = :fac, enrollmentID = :eid,
                            day = :day, roomID = :room, time_start = :ts, time_end = :te, block = :blk
                     WHERE schedID = :id"
                );
            } else {
                $st = $this->pdo->prepare(
                    "INSERT INTO subject_schedules (subjectID, facultyID, enrollmentID, day, roomID, time_start, time_end, block)
                     VALUES (:sub, :fac, :eid, :day, :room, :ts, :te, :blk)"
                );
            }
            $st->execute($params);
        } catch (PDOException $e) {
            error_log('Schedule save failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['Could not save the schedule. Please try again.']];
        }
        return ['ok' => true, 'errors' => []];
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

// Call before any output.
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
                $_SESSION['sched_flash'] = ['type' => 'ok', 'msgs' => ['Schedule added.']];
                $this->go($self, ['sem' => $semKey]);
            }
            $_SESSION['sched_old']   = $_POST;
            $_SESSION['sched_flash'] = ['type' => 'error', 'msgs' => $res['errors']];
            $this->go($self, ['sem' => $semKey, 'add' => 1]);
        }

        if ($action === 'update' && $id > 0) {
            $res = $this->save($id, $_POST);
            if ($res['ok']) {
                $_SESSION['sched_flash'] = ['type' => 'ok', 'msgs' => ['Class updated.']];
                $this->go($self, ['view' => 'class', 'id' => $id]);
            }
            $_SESSION['sched_old']   = $_POST;
            $_SESSION['sched_flash'] = ['type' => 'error', 'msgs' => $res['errors']];
            $this->go($self, ['view' => 'edit', 'id' => $id]);
        }

        $this->go($self, []);
    }
}