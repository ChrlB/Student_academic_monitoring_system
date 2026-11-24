
<?php
require_once __DIR__ . '/../Services/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION["page"] = "ENROLLMENT";

class EnrollmentLogic
{
    private PDO $pdo;

    public $role;
    public $group;
    public $userID;
    public $numericID = null;

    public $studentProgram = '';
    public $studentYear = '';
    public $studentType = 'Regular';
    public $studentBlock = '';
    public $term = null;
    public $canEdit = false;
    public $picked = [];
    public $available = [];
    public $totalUnits = 0;

    public $facultyID = null;
    public $pendingStudents = [];
    public $selectedStudent = null;
    public $studentSubjects = [];
    public $isWindowOpen = true;

    public $bulkStudents = [];
    public $bulkAdvisers = [];
    public $bulkProfessors = [];
    public $bulkSubjectClasses = [];
    public $bulkSubjectAssignments = [];
    public $allPeriods = [];
    public $periodPrograms = [];
    public $periodDepartments = [];
    public $allSubjects = [];
    public $prerequisiteOptions = [];
    public $editSubject = null;

    public $error = '';
    public $creditRequests = [];
    public $historyRows = [];
    public $historyPeriods = [];
    public $notifications = [];
    public $unreadNotifications = 0;
    public $deanName = '';
    public $deanPeriods = [];
    public $deanSelectedPeriod = null;
    public $deanStats = [];
    public $deanPrograms = [];
    public $deanYears = [];
    public $deanStudents = [];
    public $deanOfferings = [];
    public $deanTrends = [];
    public $deanRequestStats = [];


    public const UNIT_LIMIT = 21;

    private const TIMEZONE = 'Asia/Manila';
    private const MODULE_PARAMS = ['tab', 'edit_subject', 'review_student', 'enroll_id'];

    public function __construct()
    {
        global $db_connection;

        if (isset($db_connection) && $db_connection instanceof PDO) {
            $this->pdo = $db_connection;
        } else {
            $this->pdo = new PDO(
                "mysql:host=localhost;port=3306;dbname=ams_db;charset=utf8mb4",
                "root",
                "",
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        }

        $this->role = strtolower(trim($_SESSION['role'] ?? ''));
        $this->userID = trim((string)($_SESSION['userID'] ?? ''));

        $this->group = self::roleGroup($this->role);

        if ($this->group === 'student') {
            $this->resolveStudentID();
            $this->loadStudentProfile();
        } elseif (in_array($this->group, ['coordinator', 'dean'], true)) {
            $this->resolveFacultyID();
        }
    }

    public static function roleGroup($role)
    {
        $r = strtolower(trim((string)$role));

        if (preg_match('/registrar|admin/', $r)) {
            return 'registrar';
        }
        if (preg_match('/dean/', $r)) {
            return 'dean';
        }

        if (preg_match('/coordinator|program chair|adviser|advisor/', $r)) {
            return 'coordinator';
        }

        if (preg_match('/instructor|teacher|professor|prof/', $r)) {
            return 'instructor';
        }

        return 'student';
    }

    public static function ordinal($number)
    {
        $n = (int)$number;

        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }

        switch ($n % 10) {
            case 1:
                return $n . 'st';
            case 2:
                return $n . 'nd';
            case 3:
                return $n . 'rd';
            default:
                return $n . 'th';
        }
    }

    public static function url(array $query = [], $fragment = '')
    {
        $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $keep = array_diff_key($_GET, array_flip(self::MODULE_PARAMS));

        $query = array_filter($query, function ($v) {
            return $v !== '' && $v !== null;
        });

        $all = array_merge($keep, $query);

        return $path
            . ($all ? '?' . http_build_query($all) : '')
            . ($fragment !== '' ? '#' . $fragment : '');
    }

    private function resolveStudentID()
    {
        if ($this->userID === '') {
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT studentID
            FROM students
            WHERE CONCAT(
                YEAR(date_created),
                LPAD(studentID, 5, '0')
            ) = ?
            LIMIT 1
        ");

        $stmt->execute([$this->userID]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->numericID = (int)$id;
        }
    }

    private function resolveFacultyID()
    {
        if ($this->userID === '') {
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT facultyID
            FROM faculty
            WHERE CONCAT(
                'F',
                YEAR(date_created),
                LPAD(facultyID, 5, '0')
            ) = ?
            LIMIT 1
        ");

        $stmt->execute([$this->userID]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->facultyID = (int)$id;
            $this->numericID = (int)$id;
        }
    }

    private function loadStudentProfile()
    {
        if (!$this->numericID) {
            $this->error = 'Student account could not be identified.';
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT program, year_level, student_type, block
            FROM students
            WHERE studentID = ?
        ");

        $stmt->execute([$this->numericID]);
        $profile = $stmt->fetch();

        if (!$profile) {
            $this->error = 'Student profile not found.';
            return;
        }

        $this->studentProgram = $profile['program'];
        $this->studentType = $profile['student_type'] ?? 'Regular';
        $this->studentBlock = $profile['block'] ?? '';
        $this->studentYear = self::ordinal((int)$profile['year_level']) . ' Year';
    }

    private function studentYearNumber()
    {
        return (int)filter_var(
            $this->studentYear,
            FILTER_SANITIZE_NUMBER_INT
        );
    }

    public function loadData()
    {
        if ($this->group === 'student') {
            $this->loadStudentData();
            return;
        }

        if ($this->group === 'registrar') {
            $this->loadRegistrarData();
            return;
        }

        if ($this->group === 'coordinator') {
            $this->loadAdviserData();
            return;
        }
        if ($this->group === 'dean') {
            $this->loadDeanData();
            return;
        }

        $this->error = 'You do not have permission to access Enrollment management.';
    }

    private function loadStudentData()
    {
        if (!$this->numericID || !$this->studentProgram) {
            return;
        }

        // Keep a complete per-student curriculum record, including future year levels.
        // This is separate from enrolled_subjects, which tracks actual enrollment per term.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS student_curriculum (
            curriculum_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            studentID INT UNSIGNED NOT NULL,
            program VARCHAR(50) NOT NULL,
            subjectID VARCHAR(100) NOT NULL,
            curriculum_year TINYINT NOT NULL,
            semester TINYINT UNSIGNED NOT NULL,
            units TINYINT NOT NULL DEFAULT 0,
            status ENUM('not_yet_taken','finished') NOT NULL DEFAULT 'not_yet_taken',
            UNIQUE KEY uq_student_curriculum (studentID, subjectID),
            INDEX idx_curriculum_student (studentID, program)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $sync = $this->pdo->prepare("
            INSERT INTO student_curriculum (studentID, program, subjectID, curriculum_year, semester, units)
            SELECT ?, sy.program, TRIM(sy.subjectID), sy.year, sy.sem_available, sy.unit
            FROM prospectus sy
            WHERE sy.program = ?
            ON DUPLICATE KEY UPDATE
                program = VALUES(program),
                curriculum_year = VALUES(curriculum_year),
                semester = VALUES(semester),
                units = VALUES(units)
        ");
        $sync->execute([$this->numericID, $this->studentProgram]);

        // A credited subject is already completed in the student's curriculum.
        $credited = $this->pdo->prepare("
            UPDATE student_curriculum sc
            JOIN subject_credit_requests cr
              ON cr.studentID = sc.studentID AND TRIM(cr.subjectID) = TRIM(sc.subjectID)
            SET sc.status = 'finished'
            WHERE sc.studentID = ? AND cr.status = 'credited'
        ");
        $credited->execute([$this->numericID]);
        $this->pdo->prepare("UPDATE student_curriculum sc SET status='finished' WHERE sc.studentID=? AND EXISTS (SELECT 1 FROM enrolled_subjects es WHERE es.studentID=sc.studentID AND TRIM(es.subjectID)=TRIM(sc.subjectID) AND es.status='passed')")->execute([$this->numericID]);

        // Select the enrollment period for this student's program and year level.
        // Older periods with NULL year_level are kept as a fallback.
        $yearLevel = $this->studentYearNumber();

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM enrollment_period
            WHERE program = ?
              AND (year_level = ? OR year_level IS NULL)
            ORDER BY
                CASE WHEN year_level = ? THEN 0 ELSE 1 END,
                enrollmentID DESC
            LIMIT 1
        ");

        $stmt->execute([
            $this->studentProgram,
            $yearLevel,
            $yearLevel
        ]);
        $this->term = $stmt->fetch();

        if (!$this->term) {
            return;
        }

        $enrollmentID = (int)$this->term['enrollmentID'];

        // Regular students are automatically given every subject in their
        // program's current year level and semester. These remain pending so
        // the Coordinator can still review the enrollment normally.
        if (strtolower(trim((string)$this->studentType)) === 'regular') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS regular_enrollment_initialization (
                enrollmentID INT NOT NULL,
                studentID INT UNSIGNED NOT NULL,
                initialized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (enrollmentID, studentID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $claim = $this->pdo->prepare("INSERT IGNORE INTO regular_enrollment_initialization (enrollmentID, studentID) VALUES (?, ?)");
            $claim->execute([$enrollmentID, $this->numericID]);
            if ($claim->rowCount() === 1) {
            $autoAdd = $this->pdo->prepare("
                INSERT INTO enrolled_subjects
                    (enrollmentID, studentID, subjectID, selected_schedID, status)
                SELECT ?, ?, TRIM(sy.subjectID), NULL, 'pending'
                FROM prospectus sy
                WHERE sy.program = ?
                  AND sy.year = ?
                  AND sy.sem_available = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM student_curriculum sc
                      WHERE sc.studentID = ?
                        AND TRIM(sc.subjectID) = TRIM(sy.subjectID)
                        AND sc.status = 'finished'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM enrolled_subjects es
                      WHERE es.enrollmentID = ?
                        AND es.studentID = ?
                        AND TRIM(es.subjectID) = TRIM(sy.subjectID)
                  )
            ");
            $autoAdd->execute([
                $enrollmentID,
                $this->numericID,
                $this->studentProgram,
                $yearLevel,
                (int)$this->term['sem'],
                $this->numericID,
                $enrollmentID,
                $this->numericID
            ]);
            }
        }

        $stmt = $this->pdo->prepare("
            SELECT window_open
            FROM student_enrollment_control
            WHERE enrollmentID = ?
              AND studentID = ?
            LIMIT 1
        ");

        $stmt->execute([$enrollmentID, $this->numericID]);
        $individualWindow = $stmt->fetchColumn();

        if ($individualWindow === false) {
            $individualWindow = 1;
        }

        $now = (new DateTime(
            'now',
            new DateTimeZone(self::TIMEZONE)
        ))->format('Y-m-d H:i:s');

        $dateOpen =
            $now >= $this->term['open_date'] &&
            (
                empty($this->term['close_date']) ||
                $now <= $this->term['close_date']
            );

        $periodOpen = strtolower($this->term['status']) === 'open';
        $globalWindow = (int)$this->term['student_window_open'] === 1;

        $this->canEdit =
            $dateOpen &&
            $periodOpen &&
            $globalWindow &&
            (int)$individualWindow === 1;

        $stmt = $this->pdo->prepare("
            SELECT
                es.subjectID,
                es.status,
                es.rejection_remarks,
                s.name AS subjectName,
                COALESCE(MAX(sy.unit), 0) AS unit
            FROM enrolled_subjects es
            JOIN subjects s
                ON s.subjectID = es.subjectID
            LEFT JOIN syllabus sy
                ON sy.subjectID = es.subjectID
                AND sy.program = ?
            WHERE es.enrollmentID = ?
              AND es.studentID = ?
              AND es.status <> 'dropped'
            GROUP BY
                es.enrolled_subjectID,
                es.subjectID,
                es.status,
                es.rejection_remarks,
                s.name
            ORDER BY es.subjectID
        ");

        $stmt->execute([
            $this->studentProgram,
            $enrollmentID,
            $this->numericID
        ]);

        $this->picked = $stmt->fetchAll();

        $this->totalUnits = 0;

        foreach ($this->picked as $subject) {
            if (!in_array($subject['status'], ['rejected', 'dropped'], true)) {
                $this->totalUnits += (int)$subject['unit'];
            }
        }

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT subjectID
            FROM student_curriculum
            WHERE studentID = ?
              AND status = 'finished'
        ");

        $stmt->execute([$this->numericID]);
        $approved = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $this->pdo->prepare("
            SELECT
                TRIM(sy.subjectID) AS subjectID,
                s.name AS subjectName,
                sy.unit,
                sy.year,
                COALESCE(sc.status, 'not_yet_taken') AS curriculum_status,
                CASE WHEN sc.status = 'finished' THEN 1 ELSE 0 END AS finished,
                COALESCE(sy.prerequisite, '') AS prerequisite
            FROM prospectus sy
            JOIN subjects s
                ON TRIM(s.subjectID) = TRIM(sy.subjectID)
            LEFT JOIN student_curriculum sc
                ON sc.studentID = ? AND TRIM(sc.subjectID) = TRIM(sy.subjectID)
            WHERE sy.program = ?
              AND sy.sem_available = ?
              AND sy.year = ?
            ORDER BY TRIM(sy.subjectID)
        ");

        $stmt->execute([
            $this->numericID,
            $this->studentProgram,
            $this->term['sem'],
            $this->studentYearNumber()
        ]);

        $this->available = $stmt->fetchAll();

        $scheduleStmt = $this->pdo->prepare("
            SELECT schedID, day, time_start, time_end, block
            FROM subject_schedules
            WHERE enrollmentID = ? AND subjectID = ?
            ORDER BY block, day, time_start
        ");
        foreach ($this->available as &$row) {
            $scheduleStmt->execute([$enrollmentID, $row['subjectID']]);
            $row['schedules'] = $scheduleStmt->fetchAll();
            $prereqs = array_filter(
                array_map('trim', explode(',', $row['prerequisite']))
            );

            $row['prerequisite'] = implode(', ', $prereqs);
            $row['missing'] = array_values(array_diff($prereqs, $approved));
            $row['blocked'] = !empty($row['missing']);
        }

        unset($row);
        $this->loadStudentExtras();
    }

    private function loadStudentExtras()
    {
        if (!$this->numericID) return;
        $creditOptions = $this->pdo->prepare("
            SELECT s.subjectID, s.name
            FROM subjects s
            WHERE NOT EXISTS (
                SELECT 1 FROM student_curriculum sc
                WHERE sc.studentID = ? AND TRIM(sc.subjectID) = TRIM(s.subjectID)
                  AND sc.status = 'finished'
            )
            AND NOT EXISTS (
                SELECT 1 FROM subject_credit_requests cr
                WHERE cr.studentID = ? AND TRIM(cr.subjectID) = TRIM(s.subjectID)
                  AND cr.status IN ('pending', 'credited')
            )
            ORDER BY s.subjectID
        ");
        $creditOptions->execute([$this->numericID, $this->numericID]);
        $this->prerequisiteOptions = $creditOptions->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare("SELECT notification_id, enrollmentID, notification_type, message, is_read, created_at FROM enrollment_notifications WHERE studentID = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$this->numericID]);
        $this->notifications = $stmt->fetchAll();
        $this->unreadNotifications = (int)$this->pdo->query("SELECT COUNT(*) FROM enrollment_notifications WHERE studentID = " . (int)$this->numericID . " AND is_read = 0")->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT enrollmentID, acad_year, sem FROM enrollment_period WHERE program = ? ORDER BY enrollmentID DESC");
        $stmt->execute([$this->studentProgram]);
        $this->historyPeriods = $stmt->fetchAll();

        // Load all history once. The academic-period dropdown filters rows in the
        // browser, avoiding a GET submission and full-page refresh on selection.
        $sql = "SELECT ep.enrollmentID, ep.acad_year, ep.sem, es.subjectID, s.name AS subjectName,
                       es.status, es.rejection_remarks, es.selected_schedID,
                       COALESCE(sy.unit,0) AS unit, ss.block
                FROM enrolled_subjects es
                JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
                JOIN subjects s ON s.subjectID = es.subjectID
                LEFT JOIN syllabus sy ON sy.subjectID = es.subjectID AND sy.program = ?
                LEFT JOIN subject_schedules ss ON ss.schedID = es.selected_schedID
                WHERE es.studentID = ?
                  AND es.status <> 'dropped'
                ORDER BY ep.enrollmentID DESC, es.subjectID";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->studentProgram, $this->numericID]);
        $this->historyRows = $stmt->fetchAll();

        if (isset($_GET['mark_notification_read'])) {
            $readID = (int)$_GET['mark_notification_read'];
            $stmt = $this->pdo->prepare("UPDATE enrollment_notifications SET is_read = 1 WHERE notification_id = ? AND studentID = ?");
            $stmt->execute([$readID, $this->numericID]);
        }
    }

    private function loadDeanData()
    {
        if (!$this->facultyID) {
            $this->error = 'Dean account could not be identified.';
            return;
        }
        $stmt = $this->pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM faculty WHERE facultyID = ? AND LOWER(role) LIKE '%dean%' LIMIT 1");
        $stmt->execute([$this->facultyID]);
        $this->deanName = (string)$stmt->fetchColumn();
        if ($this->deanName === '') {
            $this->error = 'Only an account with the Dean role can review enrollment data.';
            return;
        }

        $this->deanPeriods = $this->pdo->query("SELECT enrollmentID, program, acad_year, sem, year_level, status FROM enrollment_period ORDER BY acad_year DESC, sem DESC, enrollmentID DESC")->fetchAll(PDO::FETCH_ASSOC);
        $selected = filter_input(INPUT_GET, 'dean_period', FILTER_VALIDATE_INT);
        $valid = array_column($this->deanPeriods, 'enrollmentID');
        $periodID = $selected && in_array($selected, array_map('intval', $valid), true)
            ? (int)$selected
            : (int)($this->deanPeriods[0]['enrollmentID'] ?? 0);
        foreach ($this->deanPeriods as $period) {
            if ((int)$period['enrollmentID'] === $periodID) {
                $this->deanSelectedPeriod = $period;
                break;
            }
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT CASE WHEN es.status IN ('approved','enrolled','passed','failed') THEN es.studentID END) AS enrolled,
                   COUNT(DISTINCT CASE WHEN es.status = 'pending' THEN es.studentID END) AS pending_students,
                   COUNT(DISTINCT CASE WHEN es.status = 'rejected' THEN es.studentID END) AS rejected_students,
                   SUM(CASE WHEN es.status = 'pending' THEN 1 ELSE 0 END) AS pending_subjects
            FROM enrolled_subjects es WHERE es.enrollmentID = ?
        ");
        $stmt->execute([$periodID]);
        $this->deanStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->pdo->prepare("
            SELECT s.program, COUNT(DISTINCT es.studentID) AS students,
                   COUNT(DISTINCT CASE WHEN es.status IN ('approved','enrolled','passed','failed') THEN es.studentID END) AS approved
            FROM enrolled_subjects es JOIN students s ON s.studentID = es.studentID
            WHERE es.enrollmentID = ? AND es.status <> 'dropped'
            GROUP BY s.program ORDER BY s.program
        ");
        $stmt->execute([$periodID]);
        $this->deanPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("
            SELECT s.year_level, s.student_type, COUNT(DISTINCT es.studentID) AS students
            FROM enrolled_subjects es JOIN students s ON s.studentID = es.studentID
            WHERE es.enrollmentID = ? AND es.status <> 'dropped'
            GROUP BY s.year_level, s.student_type ORDER BY s.year_level, s.student_type
        ");
        $stmt->execute([$periodID]);
        $this->deanYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Show active enrollment selections and completed/credited curriculum subjects.
        // A completed subject is shown once, even if an enrollment record also exists.
        $stmt = $this->pdo->prepare("
            SELECT records.studentID, records.first_name, records.last_name,
                   records.program, records.year_level, records.block,
                   records.student_type, records.subjectID, CASE WHEN records.status = 'approved' THEN 'enrolled' ELSE records.status END AS status,
                   records.rejection_remarks, records.subject_name
            FROM (
                SELECT s.studentID, s.first_name, s.last_name, s.program,
                       s.year_level, s.block, s.student_type, es.subjectID,
                       es.status, es.rejection_remarks, sub.name AS subject_name
                FROM enrolled_subjects es
                JOIN students s ON s.studentID = es.studentID
                LEFT JOIN subjects sub ON TRIM(sub.subjectID) = TRIM(es.subjectID)
                WHERE es.enrollmentID = ? AND es.status <> 'dropped'
                  AND NOT EXISTS (
                      SELECT 1 FROM student_curriculum sc
                      WHERE sc.studentID = es.studentID
                        AND TRIM(sc.subjectID) = TRIM(es.subjectID)
                        AND sc.status = 'finished'
                        AND EXISTS (SELECT 1 FROM subject_credit_requests cr WHERE cr.studentID = sc.studentID AND TRIM(cr.subjectID) = TRIM(sc.subjectID) AND cr.status = 'credited')
                  )
                UNION ALL
                SELECT s.studentID, s.first_name, s.last_name, s.program,
                       s.year_level, s.block, s.student_type, sc.subjectID,
                       'credited' AS status, NULL AS rejection_remarks,
                       sub.name AS subject_name
                FROM student_curriculum sc
                JOIN students s ON s.studentID = sc.studentID
                LEFT JOIN subjects sub ON TRIM(sub.subjectID) = TRIM(sc.subjectID)
                WHERE sc.status = 'finished'
                  AND EXISTS (SELECT 1 FROM subject_credit_requests cr WHERE cr.studentID = sc.studentID AND TRIM(cr.subjectID) = TRIM(sc.subjectID) AND cr.status = 'credited')
                  AND EXISTS (
                      SELECT 1 FROM enrolled_subjects es2
                      WHERE es2.studentID = sc.studentID
                        AND es2.enrollmentID = ?
                        AND es2.status <> 'dropped'
                  )
            ) records
            ORDER BY records.last_name, records.first_name, records.subjectID
        ");
        $stmt->execute([$periodID, $periodID]);
        $this->deanStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("
            SELECT ss.schedID, ss.subjectID, sub.name AS subject_name,
                   ss.day, ss.time_start, ss.time_end, ss.block, ss.roomID,
                   CONCAT(f.first_name, ' ', f.last_name) AS faculty_name,
                   COUNT(DISTINCT CASE WHEN es.status IN ('pending','approved','enrolled') THEN es.studentID END) AS demand
            FROM subject_schedules ss
            LEFT JOIN subjects sub ON TRIM(sub.subjectID) = TRIM(ss.subjectID)
            LEFT JOIN faculty f ON f.facultyID = ss.facultyID
            LEFT JOIN enrolled_subjects es ON es.selected_schedID = ss.schedID AND es.enrollmentID = ss.enrollmentID
            WHERE ss.enrollmentID = ?
            GROUP BY ss.schedID, ss.subjectID, sub.name, ss.day, ss.time_start, ss.time_end, ss.block, ss.roomID, f.first_name, f.last_name
            ORDER BY ss.subjectID, ss.block
        ");
        $stmt->execute([$periodID]);
        $this->deanOfferings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("
            SELECT cr.*, st.first_name, st.last_name, st.program, st.year_level,
                   sub.name AS subject_name
            FROM subject_credit_requests cr
            JOIN students st ON st.studentID = cr.studentID
            LEFT JOIN subjects sub ON TRIM(sub.subjectID) = TRIM(cr.subjectID)
            ORDER BY FIELD(cr.status,'pending','credited','not_credited'), cr.created_at DESC
        ");
        $stmt->execute();
        $this->creditRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->deanRequestStats = ['pending' => 0, 'credited' => 0, 'not_credited' => 0];
        foreach ($this->creditRequests as $request) {
            $status = $request['status'];
            if (isset($this->deanRequestStats[$status])) $this->deanRequestStats[$status]++;
        }

        $this->deanTrends = $this->pdo->query("
            SELECT ep.acad_year, ep.sem,
                   COUNT(DISTINCT CASE WHEN es.status IN ('approved','enrolled','passed','failed') THEN es.studentID END) AS enrolled
            FROM enrollment_period ep
            LEFT JOIN enrolled_subjects es ON es.enrollmentID = ep.enrollmentID
            GROUP BY ep.acad_year, ep.sem
            ORDER BY ep.acad_year DESC, ep.sem DESC LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isAssignedAdviser($studentID)
    {
        if (!$this->facultyID) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM professor_student
            WHERE facultyID = ?
              AND studentID = ?
              AND assignment_type = 'adviser'
            LIMIT 1
        ");

        $stmt->execute([
            $this->facultyID,
            (int)$studentID
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private function loadAdviserData()
    {
        if (!$this->facultyID) {
            $this->error = 'Program Coordinator account could not be identified.';
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                st.studentID,
                st.first_name,
                st.last_name,
                st.program,
                st.year_level,
                COALESCE(ep.enrollmentID, 0) AS enrollmentID,
                ep.acad_year,
                ep.sem,
                COUNT(
                    CASE
                        WHEN es.status = 'pending'
                        THEN es.enrolled_subjectID
                    END
                ) AS subject_count
            FROM professor_student aa
            JOIN students st
                ON st.studentID = aa.studentID
            LEFT JOIN enrollment_period ep
                ON ep.enrollmentID = (
                    SELECT ep2.enrollmentID
                    FROM enrollment_period ep2
                    WHERE ep2.program = st.program
                      AND (ep2.year_level = st.year_level OR ep2.year_level IS NULL)
                    ORDER BY
                        CASE WHEN ep2.year_level = st.year_level THEN 0 ELSE 1 END,
                        ep2.enrollmentID DESC
                    LIMIT 1
                )
            LEFT JOIN enrolled_subjects es
                ON es.studentID = st.studentID
                AND es.enrollmentID = ep.enrollmentID
            WHERE aa.facultyID = ? AND aa.assignment_type = 'adviser'
            GROUP BY
                st.studentID,
                st.first_name,
                st.last_name,
                st.program,
                st.year_level,
                ep.enrollmentID,
                ep.acad_year,
                ep.sem
            ORDER BY st.last_name, st.first_name
        ");

        $stmt->execute([$this->facultyID]);
        $this->pendingStudents = $stmt->fetchAll();

        if (
            !isset($_GET['review_student']) ||
            !isset($_GET['enroll_id'])
        ) {
            return;
        }

        $reviewID = (int)$_GET['review_student'];
        $enrollID = (int)$_GET['enroll_id'];

        if (!$this->isAssignedAdviser($reviewID)) {
            $this->error = 'You are not assigned to this student.';
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT studentID, first_name, last_name, program, year_level
            FROM students
            WHERE studentID = ?
            LIMIT 1
        ");

        $stmt->execute([$reviewID]);
        $this->selectedStudent = $stmt->fetch();

        if (!$this->selectedStudent) {
            return;
        }

        if ($enrollID < 1) {
            $this->error = 'There is no enrollment period available for this student yet.';
            $this->selectedStudent = null;
            return;
        }

        $periodStmt = $this->pdo->prepare("
            SELECT enrollmentID
            FROM enrollment_period
            WHERE enrollmentID = ?
              AND program = ?
              AND (year_level = ? OR year_level IS NULL)
            LIMIT 1
        ");

        $periodStmt->execute([
            $enrollID,
            $this->selectedStudent['program'],
            (int)$this->selectedStudent['year_level']
        ]);

        if (!$periodStmt->fetchColumn()) {
            $this->error = 'The selected enrollment period does not belong to this student.';
            $this->selectedStudent = null;
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT window_open
            FROM student_enrollment_control
            WHERE enrollmentID = ?
              AND studentID = ?
            LIMIT 1
        ");

        $stmt->execute([$enrollID, $reviewID]);
        $window = $stmt->fetchColumn();

        $this->isWindowOpen =
            ($window === false) ? true : ((int)$window === 1);

        $stmt = $this->pdo->prepare("
            SELECT
                es.subjectID,
                es.status,
                es.rejection_remarks,
                s.name AS subjectName,
                COALESCE(MAX(sy.unit), 0) AS unit
            FROM enrolled_subjects es
            JOIN subjects s
                ON s.subjectID = es.subjectID
            LEFT JOIN syllabus sy
                ON sy.subjectID = es.subjectID
                AND sy.program = ?
            WHERE es.enrollmentID = ?
              AND es.studentID = ?
            GROUP BY
                es.enrolled_subjectID,
                es.subjectID,
                es.status,
                es.rejection_remarks,
                s.name
            ORDER BY es.subjectID
        ");

        $stmt->execute([
            $this->selectedStudent['program'],
            $enrollID,
            $reviewID
        ]);

        $this->studentSubjects = $stmt->fetchAll();
    }

    private function loadRegistrarData()
    {
        // Load all departments, including those without programs.
        $this->periodDepartments = $this->pdo->query("
            SELECT department, name FROM departments ORDER BY department
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Program options used by the enrollment-period form.
        $this->periodPrograms = $this->pdo->query("
            SELECT program, name, department
            FROM programs
            ORDER BY department, program
        ")->fetchAll();

        $this->available = $this->pdo->query("
            SELECT program, name
            FROM programs
            ORDER BY program
        ")->fetchAll();

        $this->bulkStudents = $this->pdo->query("
            SELECT st.studentID, CONCAT(YEAR(st.date_created), LPAD(st.studentID,5,'0')) AS display_id,
                   CONCAT_WS(' ',st.first_name,st.last_name) AS student_name,
                   st.program, p.department, st.year_level, st.block, st.student_type,
                   a.facultyID AS adviser_id, CONCAT_WS(' ',f.first_name,f.last_name) AS adviser_name
            FROM students st JOIN programs p ON p.program = st.program
            LEFT JOIN professor_student a ON a.studentID = st.studentID AND a.assignment_type = 'adviser'
            LEFT JOIN faculty f ON f.facultyID = a.facultyID
            WHERE st.status = 'active' ORDER BY st.program, st.year_level, st.block, st.last_name, st.first_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        $this->bulkAdvisers = $this->pdo->query("
            SELECT facultyID, CONCAT_WS(' ',first_name,last_name) AS name, department, program, role
            FROM faculty WHERE status = 'active'
              AND LOWER(TRIM(role)) IN ('program coordinator','program chair','coordinator','adviser','advisor')
            ORDER BY last_name, first_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->bulkProfessors = $this->pdo->query("SELECT facultyID, CONCAT_WS(' ',first_name,last_name) AS name, department, program, role FROM faculty WHERE status='active' AND LOWER(TRIM(role)) IN ('prof','professor','faculty','instructor','teacher','program coordinator','program chair','coordinator') ORDER BY last_name,first_name")->fetchAll(PDO::FETCH_ASSOC);
        $this->bulkSubjectClasses = $this->pdo->query("SELECT DISTINCT es.studentID, es.subjectID, ss.facultyID, ep.program, ep.acad_year, ep.sem, ep.enrollmentID FROM enrolled_subjects es JOIN students st ON st.studentID=es.studentID JOIN enrollment_period ep ON ep.enrollmentID=es.enrollmentID JOIN subject_schedules ss ON ss.enrollmentID=es.enrollmentID AND TRIM(ss.subjectID)=TRIM(es.subjectID) AND (ss.schedID=es.selected_schedID OR (es.selected_schedID IS NULL AND ss.block=st.block)) WHERE es.status IN ('approved','enrolled')")->fetchAll(PDO::FETCH_ASSOC);
        $this->bulkSubjectAssignments = $this->pdo->query("SELECT ps.studentID, ps.assignment AS subjectID, ps.facultyID, CONCAT_WS(' ',f.first_name,f.last_name) AS name FROM professor_student ps JOIN faculty f ON f.facultyID=ps.facultyID WHERE ps.assignment_type='subject'")->fetchAll(PDO::FETCH_ASSOC);

        $this->allPeriods = $this->pdo->query("
            SELECT
                ep.*,
                p.name AS program_name,
                p.department AS department
            FROM enrollment_period ep
            LEFT JOIN programs p
                ON p.program = ep.program
            ORDER BY ep.enrollmentID DESC
        ")->fetchAll();

        $this->prerequisiteOptions = $this->pdo->query("
            SELECT subjectID, name
            FROM subjects
            ORDER BY subjectID
        ")->fetchAll();

        $this->allSubjects = $this->pdo->query("
            SELECT
                sy.id,
                s.subjectID,
                s.name,
                sy.unit,
                sy.year,
                sy.program,
                sy.sem_available,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT sp.prerequisite
                        ORDER BY sp.prerequisite
                        SEPARATOR ', '
                    ),
                    ''
                ) AS prerequisite_codes,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT CONCAT(ps.subjectID, ' - ', ps.name)
                        ORDER BY ps.subjectID
                        SEPARATOR '||'
                    ),
                    ''
                ) AS prerequisite_labels
            FROM syllabus sy
            JOIN subjects s
                ON s.subjectID = sy.subjectID
            LEFT JOIN subject_prerequisite sp
                ON sp.subjectID = sy.subjectID
            LEFT JOIN subjects ps
                ON ps.subjectID = sp.prerequisite
            GROUP BY
                sy.id,
                s.subjectID,
                s.name,
                sy.unit,
                sy.year,
                sy.program,
                sy.sem_available
            ORDER BY
                sy.program,
                sy.year,
                sy.sem_available,
                s.subjectID
        ")->fetchAll();
    }

    public function getPickedIds()
    {
        return array_column($this->picked, 'subjectID');
    }

    public function csrf()
    {
        if (empty($_SESSION['enroll_csrf'])) {
            $_SESSION['enroll_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['enroll_csrf'];
    }

    public function takeFlash()
    {
        $flash = $_SESSION['enroll_flash'] ?? null;
        unset($_SESSION['enroll_flash']);

        return $flash;
    }

    private function flash($type, $message)
    {
        $_SESSION['enroll_flash'] = [
            'type' => $type,
            'msgs' => [$message]
        ];
    }

    private function fail(Throwable $e)
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            $message = ((string)$e->getCode() === '23000')
                ? 'This record is linked to other data, or it already exists, so the change was not saved.'
                : 'A database error occurred. Please try again.';
        } else {
            $message = $e->getMessage();
        }

        $this->flash('error', $message);
    }

    private function go(array $query = [], $fragment = '')
    {
        $url = self::url($query, $fragment);

        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }

        echo '<script>location.replace(' . json_encode($url) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url='
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }

    public function handlePost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = $_POST['enroll_action'] ?? '';

        if (
            !hash_equals(
                $this->csrf(),
                (string)($_POST['csrf'] ?? '')
            )
        ) {
            $this->flash('error', 'Security token mismatch. Please try again.');
            $this->go(array_intersect_key($_GET, array_flip(self::MODULE_PARAMS)));
        }

        if ($this->group === 'instructor') {
            http_response_code(403);
            $this->error = 'You do not have permission to perform Enrollment actions.';
            return;
        }

        if ($this->group === 'registrar') {
            if ($action === 'bulk_assign_adviser') {
                if (($_POST['assignment_type'] ?? 'adviser') === 'subject') $this->bulkAssignProfessor($_POST);
                else $this->bulkAssignAdviser($_POST);
                $this->go(['tab' => 'advisers'], 'advisers-section');
            }
            if ($action === 'create_period') {
                $this->createPeriod($_POST);
                $this->go(['tab' => 'periods'], 'periods-section');
            }

            if ($action === 'update_period') {
                $this->updatePeriod($_POST);
                $this->go(['tab' => 'periods'], 'periods-section');
            }

            if ($action === 'toggle_period') {
                $this->togglePeriod($_POST['enrollment_id'] ?? 0);
                $this->go(['tab' => 'periods'], 'periods-section');
            }

            if ($action === 'add_subject') {
                $this->createSubject($_POST);
                $this->go(['tab' => 'subjects'], 'subjects-section');
            }

            if ($action === 'update_subject') {
                $this->updateSubject($_POST);
                $this->go(['tab' => 'subjects'], 'subjects-section');
            }

            if ($action === 'delete_subject') {
                $this->deleteSubject($_POST['syllabus_id'] ?? 0);
                $this->go(['tab' => 'subjects'], 'subjects-section');
            }
        }

        if ($this->group === 'student') {
            if ($action === 'request_credit') {
                $this->requestCredit($_POST);
                $this->go();
            }
            if ($action === 'add') {
                $this->loadStudentData();
                $this->addSubject($_POST['subjectID'] ?? '', $_POST['schedID'] ?? null);
                $this->go();
            }

            if ($action === 'remove') {
                $this->loadStudentData();
                $this->removeSubject($_POST['subjectID'] ?? '');
                $this->go();
            }
        }

        if ($this->group === 'dean') {
            if ($action === 'review_credit') {
                $this->reviewCredit($_POST);
                $this->go();
            }
        }

        if ($this->group === 'coordinator') {
            $studentID = (int)($_POST['student_id'] ?? 0);
            $enrollID = (int)($_POST['enroll_id'] ?? 0);

            if ($action === 'approve') {
                $this->approveEnrollment($studentID, $enrollID);
                $this->go();
            }

            if ($action === 'reject') {
                $this->rejectEnrollment(
                    $studentID,
                    $enrollID,
                    trim($_POST['remarks'] ?? '')
                );
                $this->go();
            }

            if ($action === 'toggle_student') {
                $this->toggleStudentWindow($studentID, $enrollID);

                $this->go([
                    'review_student' => $studentID,
                    'enroll_id' => $enrollID
                ]);
            }
        }

        $this->go(array_intersect_key($_GET, array_flip(self::MODULE_PARAMS)));
    }

    private function bulkAssignAdviser(array $data): void
    {
        try {
            $facultyID = (int)($data['faculty_id'] ?? 0);
            $department = trim((string)($data['department'] ?? ''));
            $program = trim((string)($data['program'] ?? ''));
            $year = trim((string)($data['year_level'] ?? ''));
            $block = trim((string)($data['block'] ?? ''));
            $replace = ($data['replace_existing'] ?? '') === '1';
            $raw = json_decode((string)($data['student_ids'] ?? ''), true);
            if (!$facultyID || $department === '' || $program === '' || !is_array($raw) || !$raw) {
                throw new InvalidArgumentException('Choose a department, program, adviser and at least one student.');
            }
            $ids = [];
            foreach ($raw as $id) {
                if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('Invalid student selection.');
                $ids[$id] = $id;
            }
            sort($ids, SORT_NUMERIC);
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare("SELECT facultyID FROM faculty WHERE facultyID = ? AND status = 'active'
                AND LOWER(TRIM(role)) IN ('program coordinator','program chair','coordinator','adviser','advisor')
                AND department = ? AND (program = ? OR program IS NULL OR TRIM(program) = '') FOR UPDATE");
            $check->execute([$facultyID, $department, $program]);
            if (!$check->fetchColumn()) throw new InvalidArgumentException('The selected adviser is not eligible for this program.');
            $student = $this->pdo->prepare("SELECT st.studentID, st.year_level, st.block FROM students st
                JOIN programs p ON p.program = st.program WHERE st.studentID = ? AND st.status = 'active'
                AND st.program = ? AND p.department = ? FOR UPDATE");
            $existing = $this->pdo->prepare("SELECT facultyID FROM professor_student WHERE studentID = ? AND assignment_type = 'adviser' FOR UPDATE");
            $insert = $this->pdo->prepare("INSERT INTO professor_student (facultyID, studentID, assignment_type, assignment) VALUES (?, ?, 'adviser', 'Academic Adviser')");
            $update = $this->pdo->prepare("UPDATE professor_student SET facultyID = ?, assignment = 'Academic Adviser', assigned_at = CURRENT_TIMESTAMP WHERE studentID = ? AND assignment_type = 'adviser'");
            $assigned = $reassigned = $skipped = 0;
            foreach ($ids as $id) {
                $student->execute([$id, $program, $department]);
                $row = $student->fetch(PDO::FETCH_ASSOC);
                if (!$row || ($year !== '' && (string)$row['year_level'] !== $year) || ($block !== '' && preg_replace('/^\d+\s*/', '', (string)$row['block']) !== $block)) {
                    throw new RuntimeException('A selected student no longer matches the filters. Refresh and try again.');
                }
                $existing->execute([$id]);
                $old = $existing->fetchColumn();
                if ($old !== false) {
                    if (!$replace || (int)$old === $facultyID) { $skipped++; continue; }
                    $update->execute([$facultyID, $id]);
                    $reassigned++;
                } else {
                    if ($replace) { $skipped++; continue; }
                    $insert->execute([$facultyID, $id]);
                    $assigned++;
                }
            }
            $this->pdo->commit();
            $this->flash('ok', "Adviser assignment saved: $assigned assigned, $reassigned reassigned, $skipped skipped.");
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function bulkAssignProfessor(array $data): void
    {
        try {
            $faculty = (int)($data['faculty_id'] ?? 0);
            $period = (int)($data['subject_period'] ?? 0);
            $subject = trim((string)($data['subject_id'] ?? ''));
            $program = trim((string)($data['program'] ?? ''));
            $dept = trim((string)($data['department'] ?? ''));
            $year = trim((string)($data['year_level'] ?? ''));
            $block = trim((string)($data['block'] ?? ''));
            $replace = ($data['replace_existing'] ?? '') === '1';
            $raw = json_decode((string)($data['student_ids'] ?? ''), true);
            if (!$faculty || !$period || !$subject || !$program || !$dept || !is_array($raw) || !$raw) throw new InvalidArgumentException('Select a class, professor and students.');
            foreach ($raw as $id) if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('Invalid student selection.');
            $ids = array_unique($raw); sort($ids, SORT_NUMERIC);
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare("SELECT facultyID FROM faculty WHERE facultyID=? AND status='active' AND department=? AND (program=? OR program IS NULL OR TRIM(program)='') AND LOWER(TRIM(role)) IN ('prof','professor','faculty','instructor','teacher','program coordinator','program chair','coordinator') FOR UPDATE");
            $check->execute([$faculty,$dept,$program]);
            if (!$check->fetchColumn()) throw new RuntimeException('Professor is not eligible for this program.');
            $student = $this->pdo->prepare("SELECT st.year_level,st.block FROM students st JOIN programs p ON p.program=st.program WHERE st.studentID=? AND st.status='active' AND st.program=? AND p.department=? FOR UPDATE");
            $class = $this->pdo->prepare("SELECT es.enrolled_subjectID FROM enrolled_subjects es JOIN students st ON st.studentID=es.studentID JOIN enrollment_period ep ON ep.enrollmentID=es.enrollmentID JOIN subject_schedules ss ON ss.enrollmentID=es.enrollmentID AND TRIM(ss.subjectID)=TRIM(es.subjectID) AND (ss.schedID=es.selected_schedID OR (es.selected_schedID IS NULL AND ss.block=st.block)) WHERE es.studentID=? AND es.enrollmentID=? AND TRIM(es.subjectID)=? AND ss.facultyID=? AND ep.program=? AND es.status IN ('approved','enrolled') LIMIT 1 FOR UPDATE");
            $existing = $this->pdo->prepare("SELECT id,facultyID FROM professor_student WHERE studentID=? AND assignment_type='subject' AND TRIM(assignment)=? FOR UPDATE");
            $insert = $this->pdo->prepare("INSERT INTO professor_student(facultyID,studentID,assignment_type,assignment) VALUES (?,?,'subject',?)");
            $delete = $this->pdo->prepare("DELETE FROM professor_student WHERE studentID=? AND assignment_type='subject' AND TRIM(assignment)=?");
            $changed=0; $skipped=0;
            foreach ($ids as $id) {
                $student->execute([$id,$program,$dept]); $row=$student->fetch(PDO::FETCH_ASSOC);
                if (!$row || ($year!=='' && (string)$row['year_level']!==$year) || ($block!=='' && preg_replace('/^\d+\s*/','',(string)$row['block'])!==$block)) throw new RuntimeException('Student no longer matches filters.');
                $class->execute([$id,$period,$subject,$faculty,$program]);
                if (!$class->fetchColumn()) throw new RuntimeException('A selected student is not enrolled in this professor’s class. Update Class Schedule first.');
                $existing->execute([$id,$subject]); $old=$existing->fetchAll(PDO::FETCH_ASSOC);
                if (($replace && !$old) || (!$replace && $old) || (count($old)===1 && (int)$old[0]['facultyID']===$faculty)) { $skipped++; continue; }
                if ($replace) $delete->execute([$id,$subject]);
                $insert->execute([$faculty,$id,$subject]); $changed++;
            }
            $this->pdo->commit(); $this->flash('ok', "Subject professor assignment saved: $changed changed, $skipped skipped.");
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function validatePeriodData($data)
    {
        $program = trim($data['program'] ?? '');
        $department = trim($data['department'] ?? '');
        $yearLevel = (int)($data['year_level'] ?? 0);
        $acadYear = trim($data['acad_year'] ?? '');
        $sem = (int)($data['sem'] ?? 0);
        $open = str_replace('T', ' ', trim($data['open_date'] ?? ''));
        $close = str_replace('T', ' ', trim($data['close_date'] ?? ''));

        if ($program === '' || $department === '' || $acadYear === '' || $open === '' || $close === '') {
            throw new Exception('Complete all enrollment period fields.');
        }
        if ($yearLevel < 1 || $yearLevel > 4) {
            throw new Exception('Invalid year level.');
        }
        if (!in_array($sem, [1, 2, 3], true)) {
            throw new Exception('Invalid semester.');
        }
        if (strtotime($open) === false || strtotime($close) === false) {
            throw new Exception('Invalid enrollment dates.');
        }
        if (strtotime($close) <= strtotime($open)) {
            throw new Exception('Close date must be after the open date.');
        }
        if (strlen($open) === 16) $open .= ':00';
        if (strlen($close) === 16) $close .= ':00';

        $stmt = $this->pdo->prepare("SELECT 1 FROM programs WHERE program = ? AND department = ? LIMIT 1");
        $stmt->execute([$program, $department]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('The selected program does not belong to that department.');
        }

        return [
            'department' => $department, 'program' => $program,
            'year_level' => $yearLevel, 'acad_year' => $acadYear,
            'sem' => $sem, 'open_date' => $open, 'close_date' => $close
        ];
    }

    private function createPeriod($data)
    {
        try {
            $d = $this->validatePeriodData($data);
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM enrollment_period
                WHERE program = ? AND year_level = ? AND acad_year = ? AND sem = ?
                LIMIT 1
            ");
            $stmt->execute([$d['program'], $d['year_level'], $d['acad_year'], $d['sem']]);
            if ($stmt->fetchColumn()) {
                throw new Exception('An enrollment period for this program, year level, academic year and semester already exists.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO enrollment_period
                    (program, year_level, acad_year, sem, open_date, close_date, status, student_window_open)
                VALUES (?, ?, ?, ?, ?, ?, 'open', 1)
            ");
            $stmt->execute([$d['program'], $d['year_level'], $d['acad_year'], $d['sem'], $d['open_date'], $d['close_date']]);
            $this->flash('ok', 'Enrollment period created successfully.');
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function updatePeriod($data)
    {
        try {
            $id = (int)($data['enrollment_id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid enrollment period.');
            $exists = $this->pdo->prepare('SELECT 1 FROM enrollment_period WHERE enrollmentID = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new Exception('Enrollment period not found.');

            $d = $this->validatePeriodData($data);
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM enrollment_period
                WHERE program = ? AND year_level = ? AND acad_year = ? AND sem = ? AND enrollmentID <> ?
                LIMIT 1
            ");
            $stmt->execute([$d['program'], $d['year_level'], $d['acad_year'], $d['sem'], $id]);
            if ($stmt->fetchColumn()) throw new Exception('Another enrollment period already exists with these details.');

            $stmt = $this->pdo->prepare("
                UPDATE enrollment_period SET program = ?, year_level = ?, acad_year = ?, sem = ?, open_date = ?, close_date = ?
                WHERE enrollmentID = ?
            ");
            $stmt->execute([$d['program'], $d['year_level'], $d['acad_year'], $d['sem'], $d['open_date'], $d['close_date'], $id]);
            $this->flash('ok', 'Enrollment period updated successfully.');
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function togglePeriod($id)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT status
                FROM enrollment_period
                WHERE enrollmentID = ?
            ");

            $stmt->execute([(int)$id]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                throw new Exception('Enrollment period not found.');
            }

            $newStatus = strtolower($current) === 'open'
                ? 'closed'
                : 'open';

            $stmt = $this->pdo->prepare("
                UPDATE enrollment_period
                SET status = ?
                WHERE enrollmentID = ?
            ");

            $stmt->execute([$newStatus, (int)$id]);

            $this->flash('ok', "Period is now {$newStatus}.");
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function toggleStudentWindow($studentID, $enrollID)
    {
        if (!$this->isAssignedAdviser($studentID)) {
            $this->flash('error', 'You are not assigned to this student.');
            return;
        }

        if ((int)$enrollID < 1) {
            $this->flash('error', 'A valid enrollment period is required.');
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT window_open
                FROM student_enrollment_control
                WHERE enrollmentID = ?
                  AND studentID = ?
                LIMIT 1
            ");

            $stmt->execute([(int)$enrollID, (int)$studentID]);
            $current = $stmt->fetchColumn();

            $current = ($current === false) ? 1 : (int)$current;
            $newState = $current === 1 ? 0 : 1;

            $stmt = $this->pdo->prepare("
                INSERT INTO student_enrollment_control
                    (enrollmentID, studentID, window_open, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    window_open = VALUES(window_open),
                    updated_by = VALUES(updated_by)
            ");

            $stmt->execute([
                (int)$enrollID,
                (int)$studentID,
                $newState,
                $this->facultyID
            ]);

            $this->flash(
                'ok',
                $newState
                    ? 'Student enrollment window opened.'
                    : 'Student enrollment window closed.'
            );
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function validateSubjectData($data)
    {
        $out = [
            'subjectID' => trim($data['subjectID'] ?? ''),
            'name' => trim($data['subjectName'] ?? ''),
            'program' => trim($data['program'] ?? ''),
            'sem' => (int)($data['sem'] ?? 0),
            'year' => (int)($data['year'] ?? 0),
            'unit' => (int)($data['unit'] ?? 0)
        ];

        if ($out['subjectID'] === '' || $out['name'] === '') {
            throw new Exception('Subject code and name are required.');
        }

        if ($out['program'] === '') {
            throw new Exception('Please select a program.');
        }

        if (!in_array($out['sem'], [1, 2], true)) {
            throw new Exception('Invalid semester.');
        }

        if ($out['year'] < 1 || $out['year'] > 4) {
            throw new Exception('Invalid year level.');
        }

        if ($out['unit'] < 1 || $out['unit'] > 5) {
            throw new Exception('Units must be between 1 and 5.');
        }

        $stmt = $this->pdo->prepare("
            SELECT 1 FROM programs WHERE program = ?
        ");

        $stmt->execute([$out['program']]);

        if (!$stmt->fetchColumn()) {
            throw new Exception('Selected program does not exist.');
        }

        return $out;
    }

    private function savePrerequisites($subjectID, $data)
    {
        $selected = $data['prerequisites'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        $selected = array_values(array_unique(
            array_filter(array_map('trim', $selected))
        ));

        $selected = array_values(array_diff($selected, [$subjectID]));

        if ($selected) {
            $placeholders = implode(
                ',',
                array_fill(0, count($selected), '?')
            );

            $check = $this->pdo->prepare("
                SELECT subjectID
                FROM subjects
                WHERE subjectID IN ($placeholders)
            ");

            $check->execute($selected);
            $valid = $check->fetchAll(PDO::FETCH_COLUMN);

            if (count($valid) !== count($selected)) {
                throw new Exception('One or more selected prerequisite subjects are invalid.');
            }
        }

        $this->pdo->prepare("
            DELETE FROM subject_prerequisite
            WHERE subjectID = ?
        ")->execute([$subjectID]);

        if (!$selected) {
            return;
        }

        $insert = $this->pdo->prepare("
            INSERT INTO subject_prerequisite
                (subjectID, prerequisite)
            VALUES (?, ?)
        ");

        foreach ($selected as $prerequisite) {
            $insert->execute([$subjectID, $prerequisite]);
        }
    }

    private function createSubject($data)
    {
        try {
            $d = $this->validateSubjectData($data);

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM subjects
                WHERE subjectID = ?
            ");

            $stmt->execute([$d['subjectID']]);

            if (!$stmt->fetchColumn()) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO subjects
                        (subjectID, name, department)
                    VALUES (?, ?, 'CCS')
                ");

                $stmt->execute([
                    $d['subjectID'],
                    $d['name']
                ]);
            }

            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM syllabus
                WHERE subjectID = ?
                  AND program = ?
                LIMIT 1
            ");

            $stmt->execute([
                $d['subjectID'],
                $d['program']
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception('This subject is already in that program\'s curriculum.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO syllabus
                    (subjectID, program, sem_available, year, unit)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $d['subjectID'],
                $d['program'],
                $d['sem'],
                $d['year'],
                $d['unit']
            ]);

            $this->savePrerequisites($d['subjectID'], $data);

            $this->pdo->commit();

            $this->flash('ok', 'Subject added successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function updateSubject($data)
    {
        try {
            $syllabusID = (int)($data['syllabus_id'] ?? 0);

            if (!$syllabusID) {
                throw new Exception('Invalid subject information.');
            }

            $d = $this->validateSubjectData($data);

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT subjectID
                FROM syllabus
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$syllabusID]);
            $realSubjectID = $stmt->fetchColumn();

            if ($realSubjectID === false) {
                throw new Exception('Subject entry not found.');
            }

            $stmt = $this->pdo->prepare("
                UPDATE subjects
                SET name = ?
                WHERE subjectID = ?
            ");

            $stmt->execute([
                $d['name'],
                $realSubjectID
            ]);

            $stmt = $this->pdo->prepare("
                UPDATE syllabus
                SET program = ?,
                    sem_available = ?,
                    year = ?,
                    unit = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $d['program'],
                $d['sem'],
                $d['year'],
                $d['unit'],
                $syllabusID
            ]);

            $this->savePrerequisites($realSubjectID, $data);

            $this->pdo->commit();

            $this->flash('ok', 'Subject updated successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function deleteSubject($syllabusID)
    {
        try {
            $syllabusID = (int)$syllabusID;

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT subjectID
                FROM syllabus
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$syllabusID]);
            $subjectID = $stmt->fetchColumn();

            if ($subjectID === false) {
                throw new Exception('Subject entry not found.');
            }

            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM enrolled_subjects
                WHERE subjectID = ?
            ");

            $stmt->execute([$subjectID]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception(
                    'This subject is already used in enrollment records and cannot be deleted.'
                );
            }

            $this->pdo->prepare("
                DELETE FROM syllabus
                WHERE id = ?
            ")->execute([$syllabusID]);

            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM syllabus
                WHERE subjectID = ?
            ");

            $stmt->execute([$subjectID]);

            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->prepare("
                    DELETE FROM subject_prerequisite
                    WHERE subjectID = ?
                       OR prerequisite = ?
                ")->execute([$subjectID, $subjectID]);

                $this->pdo->prepare("
                    DELETE FROM subjects
                    WHERE subjectID = ?
                ")->execute([$subjectID]);
            }

            $this->pdo->commit();

            $this->flash('ok', 'Subject deleted successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function addSubject($subjectID, $schedID = null)
    {
        if (!$this->canEdit || !$this->numericID || !$this->term) {
            $this->flash('error', 'Enrollment window is closed.');
            return;
        }

        $subjectID = trim($subjectID);
        $enrollmentID = (int)$this->term['enrollmentID'];

        try {
            $stmt = $this->pdo->prepare("
                SELECT unit
                FROM prospectus
                WHERE TRIM(subjectID) = TRIM(?)
                  AND program = ?
                  AND sem_available = ?
                  AND year = ?
                LIMIT 1
            ");

            $stmt->execute([
                $subjectID,
                $this->studentProgram,
                $this->term['sem'],
                $this->studentYearNumber()
            ]);

            $unit = $stmt->fetchColumn();

            $finishedCheck = $this->pdo->prepare("SELECT 1 FROM student_curriculum WHERE studentID = ? AND TRIM(subjectID) = TRIM(?) AND status = 'finished' LIMIT 1");
            $finishedCheck->execute([$this->numericID, $subjectID]);
            if ($finishedCheck->fetchColumn()) {
                throw new Exception('This subject is already credited/finished and does not need to be added again.');
            }

            if ($unit === false) {
                throw new Exception('This subject is not available for your program and year.');
            }

            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND subjectID = ?
                  AND status NOT IN ('rejected', 'dropped')
                LIMIT 1
            ");

            $stmt->execute([
                $enrollmentID,
                $this->numericID,
                $subjectID
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception('This subject is already in your selection.');
            }

            if ($this->totalUnits + (int)$unit > self::UNIT_LIMIT) {
                throw new Exception(
                    'Adding this subject would exceed the ' .
                    self::UNIT_LIMIT .
                    '-unit limit.'
                );
            }

            $stmt = $this->pdo->prepare("
                SELECT prerequisite
                FROM subject_prerequisite
                WHERE subjectID = ?
            ");

            $stmt->execute([$subjectID]);
            $prerequisites = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $check = $this->pdo->prepare("
                SELECT 1
                FROM student_curriculum
                WHERE studentID = ?
                  AND TRIM(subjectID) = TRIM(?)
                  AND status = 'finished'
                LIMIT 1
            ");

            foreach ($prerequisites as $prerequisite) {
                $check->execute([
                    $this->numericID,
                    $prerequisite
                ]);

                if (!$check->fetchColumn()) {
                    throw new Exception(
                        'Missing prerequisite: ' . trim($prerequisite)
                    );
                }
            }

            $selectedSchedID = null;
            if (strtolower($this->studentType) === 'irregular') {
                $selectedSchedID = (int)$schedID;
                if ($selectedSchedID < 1) {
                    throw new Exception('Please select a schedule for this subject.');
                }
                $scheduleCheck = $this->pdo->prepare("
                    SELECT schedID, day, time_start, time_end
                    FROM subject_schedules
                    WHERE schedID = ? AND enrollmentID = ? AND subjectID = ?
                    LIMIT 1
                ");
                $scheduleCheck->execute([$selectedSchedID, $enrollmentID, $subjectID]);
                $chosen = $scheduleCheck->fetch();
                if (!$chosen) {
                    throw new Exception('The selected schedule is invalid. Please choose again.');
                }
                $existing = $this->pdo->prepare("
                    SELECT ss.day, ss.time_start, ss.time_end
                    FROM enrolled_subjects es
                    JOIN subject_schedules ss ON ss.schedID = es.selected_schedID
                    WHERE es.enrollmentID = ? AND es.studentID = ?
                      AND es.status NOT IN ('rejected', 'dropped') AND es.selected_schedID IS NOT NULL
                ");
                $existing->execute([$enrollmentID, $this->numericID]);
                $days = function ($value) {
                    $value = strtoupper(trim((string)$value));
                    if ($value === 'MTH') return ['M', 'TH'];
                    return preg_split('/[ ,\/]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
                };
                $newDays = $days($chosen['day']);
                foreach ($existing->fetchAll() as $old) {
                    if (array_intersect($newDays, $days($old['day'])) &&
                        $chosen['time_start'] < $old['time_end'] &&
                        $chosen['time_end'] > $old['time_start']) {
                        throw new Exception('Schedule conflict: this subject overlaps with another selected subject.');
                    }
                }
            }

            $this->pdo->beginTransaction();

            $this->pdo->prepare("
                DELETE FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND subjectID = ?
                  AND status IN ('rejected', 'dropped')
            ")->execute([
                $enrollmentID,
                $this->numericID,
                $subjectID
            ]);

            $this->pdo->prepare("
                INSERT INTO enrolled_subjects
                    (enrollmentID, studentID, subjectID, selected_schedID, status)
                VALUES (?, ?, ?, ?, 'pending')
            ")->execute([
                $enrollmentID,
                $this->numericID,
                $subjectID,
                $selectedSchedID
            ]);

            $this->pdo->commit();

            $this->flash('ok', 'Subject added successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function removeSubject($subjectID)
    {
        if (!$this->numericID || !$this->term) {
            $this->flash('error', 'No active student enrollment was found.');
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND TRIM(subjectID) = TRIM(?)
                  AND status = 'pending'
            ");

            $stmt->execute([
                (int)$this->term['enrollmentID'],
                $this->numericID,
                trim($subjectID)
            ]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('Only pending subjects can be removed. Approved or completed subjects cannot be removed.');
            }

            $this->flash('ok', 'Subject removed from selection. You can add it again while enrollment is open.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function approveEnrollment($studentID, $enrollID)
    {
        if (!$this->isAssignedAdviser($studentID)) {
            $this->flash('error', 'You are not assigned to this student.');
            return;
        }

        if ((int)$enrollID < 1) {
            $this->flash('error', 'A valid enrollment period is required.');
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE enrolled_subjects
                SET status = 'enrolled',
                    rejection_remarks = NULL,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $this->facultyID,
                $enrollID,
                $studentID
            ]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('No pending subjects were found to approve.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO student_enrollment_control
                    (enrollmentID, studentID, window_open, updated_by)
                VALUES (?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                    window_open = 0,
                    updated_by = VALUES(updated_by)
            ");

            $stmt->execute([
                $enrollID,
                $studentID,
                $this->facultyID
            ]);

            $notice = $this->pdo->prepare("
                INSERT INTO enrollment_notifications
                    (studentID, enrollmentID, notification_type, message)
                VALUES (?, ?, 'approved', ?)
            ");
            $notice->execute([$studentID, $enrollID, 'Your enrollment has been approved by your Program Coordinator.']);

            $this->pdo->commit();

            $this->flash(
                'ok',
                'Enrollment approved. Student enrollment window is now closed.'
            );
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function rejectEnrollment($studentID, $enrollID, $remarks)
    {
        if (!$this->isAssignedAdviser($studentID)) {
            $this->flash('error', 'You are not assigned to this student.');
            return;
        }

        if ((int)$enrollID < 1) {
            $this->flash('error', 'A valid enrollment period is required.');
            return;
        }

        if ($remarks === '') {
            $this->flash('error', 'Please provide a reason for rejection.');
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("
                UPDATE enrolled_subjects
                SET status = 'rejected',
                    rejection_remarks = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $remarks,
                $this->facultyID,
                $enrollID,
                $studentID
            ]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('No pending subjects were found to reject.');
            }

            $notice = $this->pdo->prepare("
                INSERT INTO enrollment_notifications
                    (studentID, enrollmentID, notification_type, message)
                VALUES (?, ?, 'rejected', ?)
            ");
            $notice->execute([$studentID, $enrollID, 'Your enrollment was rejected. Remarks: ' . $remarks]);
            $this->pdo->commit();

            $this->flash(
                'ok',
                'Enrollment rejected. The rejection remarks were saved.'
            );
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function requestCredit(array $data)
    {
        if (strtolower(trim((string)$this->studentType)) === 'regular') {
            $this->flash('error', 'Subject credit requests are available only to irregular students.');
            return;
        }
        if (!$this->numericID) { $this->flash('error', 'Student account could not be identified.'); return; }
        $subjectID = trim($data['subjectID'] ?? '');
        $remarks = trim($data['supporting_remarks'] ?? '');
        if ($subjectID === '') { $this->flash('error', 'Select a subject to request for credit.'); return; }
        try {
            $check = $this->pdo->prepare("SELECT 1 FROM subjects WHERE subjectID = ?");
            $check->execute([$subjectID]);
            if (!$check->fetchColumn()) throw new Exception('Selected subject does not exist.');
            $done = $this->pdo->prepare("SELECT 1 FROM student_curriculum WHERE studentID = ? AND TRIM(subjectID) = ? AND status = 'finished' LIMIT 1");
            $done->execute([$this->numericID, $subjectID]);
            if ($done->fetchColumn()) throw new Exception('This subject is already finished or credited.');
            $stmt = $this->pdo->prepare("SELECT 1 FROM subject_credit_requests WHERE studentID = ? AND TRIM(subjectID) = ? AND status IN ('pending','credited') LIMIT 1");
            $stmt->execute([$this->numericID, $subjectID]);
            if ($stmt->fetchColumn()) throw new Exception('This subject is already pending review or has been credited.');
            $period = $this->term ?: null;
            $stmt = $this->pdo->prepare("INSERT INTO subject_credit_requests (studentID, subjectID, academic_year, semester, supporting_remarks, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$this->numericID, $subjectID, $period['acad_year'] ?? null, $period['sem'] ?? null, $remarks]);
            $this->flash('ok', 'Subject credit request submitted for Dean review.');
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function reviewCredit(array $data)
    {
        if (!$this->facultyID) { $this->flash('error', 'Dean permission is required.'); return; }
        $roleCheck = $this->pdo->prepare("SELECT role FROM faculty WHERE facultyID = ? LIMIT 1");
        $roleCheck->execute([$this->facultyID]);
        if (!preg_match('/dean/i', (string)$roleCheck->fetchColumn())) { $this->flash('error', 'Only a Dean can review subject credit requests.'); return; }
        $id = (int)($data['request_id'] ?? 0);
        $status = $data['credit_decision'] ?? '';
        $remarks = trim($data['dean_remarks'] ?? '');
        if ($id < 1 || !in_array($status, ['credited','not_credited'], true)) { $this->flash('error', 'Invalid credit review decision.'); return; }
        try {
            $stmt = $this->pdo->prepare("UPDATE subject_credit_requests SET status = ?, dean_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ? AND status = 'pending'");
            $stmt->execute([$status, $remarks, $this->facultyID, $id]);
            if ($stmt->rowCount() < 1) throw new Exception('This request has already been reviewed or no longer exists.');
            if ($status === 'credited') {
                $getStudent = $this->pdo->prepare("SELECT studentID, TRIM(subjectID) AS subjectID FROM subject_credit_requests WHERE request_id = ?");
                $getStudent->execute([$id]);
                $credit = $getStudent->fetch();
                if ($credit) {
                    $finish = $this->pdo->prepare("UPDATE student_curriculum SET status = 'finished' WHERE studentID = ? AND TRIM(subjectID) = ?");
                    $finish->execute([$credit['studentID'], $credit['subjectID']]);
                    // Reflect the credit in the student's enrollment record/history too.
                    $finishEnrollment = $this->pdo->prepare("UPDATE enrolled_subjects SET status = 'finished' WHERE studentID = ? AND TRIM(subjectID) = ? AND status NOT IN ('passed','failed')");
                    $finishEnrollment->execute([$credit['studentID'], $credit['subjectID']]);
                }
            }
            $this->flash('ok', 'Credit request review saved.');
        } catch (Throwable $e) { $this->fail($e); }
    }
}
?>