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

    // Student
    public $studentProgram = '';
    public $studentYear = '';
    public $term = null;
    public $canEdit = false;
    public $picked = [];
    public $available = [];
    public $totalUnits = 0;

    // Adviser
    public $facultyID = null;
    public $pendingStudents = [];
    public $selectedStudent = null;
    public $studentSubjects = [];
    public $isWindowOpen = true;

    // Registrar
    public $allPeriods = [];
    public $allSubjects = [];
    public $prerequisiteOptions = [];
    public $editSubject = null;

    public $error = '';

    public const UNIT_LIMIT = 21;

    // Timezone used when comparing "now" with the period open/close dates.
    private const TIMEZONE = 'Asia/Manila';

    // Query-string keys that belong to this module only. Everything else in
    // the URL (for example the router's own "page=..." parameter) is kept
    // when redirecting, so the router can still find the page.
    private const MODULE_PARAMS = ['tab', 'edit_subject', 'review_student', 'enroll_id'];

    public function __construct()
    {
        global $db_connection;

        if (isset($db_connection) && $db_connection instanceof PDO) {
            $this->pdo = $db_connection;
        } else {
            $this->pdo = new PDO(
                "mysql:host=localhost;port=3306;dbname=ams_db2;charset=utf8mb4",
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
        } elseif ($this->group === 'faculty') {
            $this->resolveFacultyID();
        }
    }

    public static function roleGroup($role)
    {
        $r = strtolower(trim((string)$role));

        if (preg_match('/coordinator|dean|registrar|admin/', $r)) {
            return 'manager';
        }

        if (preg_match('/prof|faculty|instructor|teacher|adviser|advisor/', $r)) {
            return 'faculty';
        }

        return 'student';
    }

    public static function ordinal($number)
    {
        $n = (int)$number;
        if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
        switch ($n % 10) {
            case 1:  return $n . 'st';
            case 2:  return $n . 'nd';
            case 3:  return $n . 'rd';
            default: return $n . 'th';
        }
    }

    /**
     * Builds a URL for the current page. It keeps the current path AND any
     * query parameters used by the router, drops this module's own
     * parameters, then adds the ones passed in.
     */
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
            SELECT program, year_level
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
        $this->studentYear = self::ordinal((int)$profile['year_level']) . ' Year';
    }

    private function studentYearNumber()
    {
        return (int)filter_var($this->studentYear, FILTER_SANITIZE_NUMBER_INT);
    }

    public function loadData()
    {
        if ($this->group === 'student') {
            $this->loadStudentData();
        } elseif ($this->group === 'manager') {
            $this->loadRegistrarData();
        } else {
            $this->loadAdviserData();
        }
    }

    private function loadStudentData()
    {
        if (!$this->numericID || !$this->studentProgram) {
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM enrollment_period
            WHERE program = ?
            ORDER BY enrollmentID DESC
            LIMIT 1
        ");

        $stmt->execute([$this->studentProgram]);
        $this->term = $stmt->fetch();

        if (!$this->term) {
            return;
        }

        $enrollmentID = (int)$this->term['enrollmentID'];

        // Individual student window defaults to open if no control row exists.
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

        $now = (new DateTime('now', new DateTimeZone(self::TIMEZONE)))
            ->format('Y-m-d H:i:s');

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

        // Selected subjects
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
            $this->studentProgram,
            $enrollmentID,
            $this->numericID
        ]);

        $this->picked = $stmt->fetchAll();

        // Rejected subjects no longer count toward the unit limit.
        $this->totalUnits = 0;

        foreach ($this->picked as $subject) {
            if ($subject['status'] !== 'rejected') {
                $this->totalUnits += (int)$subject['unit'];
            }
        }

        // Subjects the student has already completed (approved).
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT subjectID
            FROM enrolled_subjects
            WHERE studentID = ?
              AND status = 'approved'
        ");

        $stmt->execute([$this->numericID]);

        $approved = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Available subjects, with prerequisites grouped to avoid duplicates.
        $stmt = $this->pdo->prepare("
            SELECT
                sy.subjectID,
                s.name AS subjectName,
                sy.unit,
                sy.year,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT sp.prerequisite
                        ORDER BY sp.prerequisite
                        SEPARATOR ', '
                    ),
                    ''
                ) AS prerequisite
            FROM syllabus sy
            JOIN subjects s
                ON s.subjectID = sy.subjectID
            LEFT JOIN subject_prerequisite sp
                ON sp.subjectID = sy.subjectID
            WHERE sy.program = ?
              AND sy.sem_available = ?
              AND sy.year = ?
            GROUP BY
                sy.id,
                sy.subjectID,
                s.name,
                sy.unit,
                sy.year
            ORDER BY sy.subjectID
        ");

        $stmt->execute([
            $this->studentProgram,
            $this->term['sem'],
            $this->studentYearNumber()
        ]);

        $this->available = $stmt->fetchAll();

        // Mark subjects whose prerequisites are not yet approved.
        foreach ($this->available as &$row) {
            $prereqs = array_filter(array_map('trim', explode(',', $row['prerequisite'])));

            $row['prerequisite'] = implode(', ', $prereqs);
            $row['missing'] = array_values(array_diff($prereqs, $approved));
            $row['blocked'] = !empty($row['missing']);
        }
        unset($row);
    }

    private function isAssignedAdviser($studentID)
    {
        if (!$this->facultyID) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM adviser_student
            WHERE facultyID = ?
              AND studentID = ?
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
        $this->error = 'Faculty account could not be identified.';
        return;
    }

    // Show ALL students assigned to this Adviser.
    // Pending subjects are counted separately, so students with no pending
    // requests are still visible in the My Advisees list.
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
                CASE WHEN es.status = 'pending'
                THEN es.enrolled_subjectID END
            ) AS subject_count
        FROM adviser_student aa
        JOIN students st
            ON st.studentID = aa.studentID
        LEFT JOIN enrollment_period ep
            ON ep.enrollmentID = (
                SELECT MAX(ep2.enrollmentID)
                FROM enrollment_period ep2
                WHERE ep2.program = st.program
            )
        LEFT JOIN enrolled_subjects es
            ON es.studentID = st.studentID
            AND es.enrollmentID = ep.enrollmentID
        WHERE aa.facultyID = ?
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

    // Load a selected advisee for review.
    if (
        isset($_GET['review_student']) &&
        isset($_GET['enroll_id'])
    ) {
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

        // A student can be assigned to an Adviser even when no enrollment
        // period exists yet. Do not try to review or toggle enrollment 0.
        if ($enrollID < 1) {
            $this->error = 'There is no enrollment period available for this student yet.';
            $this->selectedStudent = null;
            return;
        }

        $periodStmt = $this->pdo->prepare("
            SELECT enrollmentID
            FROM enrollment_period
            WHERE enrollmentID = ? AND program = ?
            LIMIT 1
        ");
        $periodStmt->execute([$enrollID, $this->selectedStudent['program']]);

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
}

    private function loadRegistrarData()
    {
        $this->available = $this->pdo->query("
            SELECT program, name
            FROM programs
            ORDER BY program
        ")->fetchAll();

        $this->allPeriods = $this->pdo->query("
            SELECT
                ep.*,
                p.name AS program_name
            FROM enrollment_period ep
            LEFT JOIN programs p
                ON p.program = ep.program
            ORDER BY ep.enrollmentID DESC
        ")->fetchAll();

        // Syllabus is the writable table; prospectus is a VIEW.
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
                COALESCE(GROUP_CONCAT(DISTINCT sp.prerequisite ORDER BY sp.prerequisite SEPARATOR ', '), '') AS prerequisite_codes,
                COALESCE(GROUP_CONCAT(DISTINCT CONCAT(ps.subjectID, ' - ', ps.name) ORDER BY ps.subjectID SEPARATOR '||'), '') AS prerequisite_labels
            FROM syllabus sy
            JOIN subjects s ON s.subjectID = sy.subjectID
            LEFT JOIN subject_prerequisite sp ON sp.subjectID = sy.subjectID
            LEFT JOIN subjects ps ON ps.subjectID = sp.prerequisite
            GROUP BY sy.id, s.subjectID, s.name, sy.unit, sy.year, sy.program, sy.sem_available
            ORDER BY sy.program, sy.year, sy.sem_available, s.subjectID
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

    /**
     * Shows a safe message instead of raw database errors.
     */
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

        // Fallback if the router already sent output.
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

        // Manager actions
        if ($this->group === 'manager') {
            if ($action === 'create_period') {
                $this->createPeriod($_POST);
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

        // Student actions
        if ($this->group === 'student') {
            if ($action === 'add') {
                $this->loadStudentData();
                $this->addSubject($_POST['subjectID'] ?? '');
                $this->go();
            }

            if ($action === 'remove') {
                $this->loadStudentData();
                $this->removeSubject($_POST['subjectID'] ?? '');
                $this->go();
            }
        }

        // Adviser actions
        if ($this->group === 'faculty') {
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

        // Unknown action: go back instead of leaving a POST page open.
        $this->go(array_intersect_key($_GET, array_flip(self::MODULE_PARAMS)));
    }

    private function createPeriod($data)
    {
        try {
            $program = trim($data['program'] ?? '');
            $acadYear = trim($data['acad_year'] ?? '');
            $sem = (int)($data['sem'] ?? 0);
            $open = str_replace('T', ' ', $data['open_date'] ?? '');
            $close = str_replace('T', ' ', $data['close_date'] ?? '');

            if ($program === '' || $acadYear === '' || $open === '' || $close === '') {
                throw new Exception('Complete all enrollment period fields.');
            }

            if (!in_array($sem, [1, 2, 3], true)) {
                throw new Exception('Invalid semester.');
            }

            if (strtotime($close) <= strtotime($open)) {
                throw new Exception('Close date must be after the open date.');
            }

            // datetime-local sends "Y-m-d H:i"; MySQL needs seconds.
            if (strlen($open) === 16)  $open .= ':00';
            if (strlen($close) === 16) $close .= ':00';

            $stmt = $this->pdo->prepare("SELECT 1 FROM programs WHERE program = ?");
            $stmt->execute([$program]);

            if (!$stmt->fetchColumn()) {
                throw new Exception('Selected program does not exist.');
            }

            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM enrollment_period
                WHERE program = ? AND acad_year = ? AND sem = ?
                LIMIT 1
            ");
            $stmt->execute([$program, $acadYear, $sem]);

            if ($stmt->fetchColumn()) {
                throw new Exception('An enrollment period for this program, year and semester already exists.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO enrollment_period
                    (program, acad_year, sem, open_date, close_date,
                     status, student_window_open)
                VALUES (?, ?, ?, ?, ?, 'open', 1)
            ");

            $stmt->execute([$program, $acadYear, $sem, $open, $close]);

            $this->flash('ok', 'Enrollment period created successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function togglePeriod($id)
    {
        try {
            // Read the real status from the database; never trust the form.
            $stmt = $this->pdo->prepare("
                SELECT status FROM enrollment_period WHERE enrollmentID = ?
            ");
            $stmt->execute([(int)$id]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                throw new Exception('Enrollment period not found.');
            }

            $newStatus = strtolower($current) === 'open' ? 'closed' : 'open';

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
                WHERE enrollmentID = ? AND studentID = ?
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
            'name'      => trim($data['subjectName'] ?? ''),
            'program'   => trim($data['program'] ?? ''),
            'sem'       => (int)($data['sem'] ?? 0),
            'year'      => (int)($data['year'] ?? 0),
            'unit'      => (int)($data['unit'] ?? 0),
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

        $stmt = $this->pdo->prepare("SELECT 1 FROM programs WHERE program = ?");
        $stmt->execute([$out['program']]);

        if (!$stmt->fetchColumn()) {
            throw new Exception('Selected program does not exist.');
        }

        return $out;
    }

    private function savePrerequisites($subjectID, $data)
    {
        $selected = $data['prerequisites'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_unique(array_filter(array_map('trim', $selected))));
        $selected = array_values(array_diff($selected, [$subjectID]));

        if ($selected) {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $check = $this->pdo->prepare("SELECT subjectID FROM subjects WHERE subjectID IN ($placeholders)");
            $check->execute($selected);
            $valid = $check->fetchAll(PDO::FETCH_COLUMN);
            if (count($valid) !== count($selected)) {
                throw new Exception('One or more selected prerequisite subjects are invalid.');
            }
        }

        $this->pdo->prepare("DELETE FROM subject_prerequisite WHERE subjectID = ?")->execute([$subjectID]);
        if (!$selected) return;

        $insert = $this->pdo->prepare("INSERT INTO subject_prerequisite (subjectID, prerequisite) VALUES (?, ?)");
        foreach ($selected as $prerequisite) {
            $insert->execute([$subjectID, $prerequisite]);
        }
    }

    private function createSubject($data)
    {
        try {
            $d = $this->validateSubjectData($data);

            $this->pdo->beginTransaction();

            // The subject may already exist (used by another program).
            $stmt = $this->pdo->prepare("SELECT 1 FROM subjects WHERE subjectID = ?");
            $stmt->execute([$d['subjectID']]);

            if (!$stmt->fetchColumn()) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO subjects (subjectID, name, department)
                    VALUES (?, ?, 'CCS')
                ");
                $stmt->execute([$d['subjectID'], $d['name']]);
            }

            $stmt = $this->pdo->prepare("
                SELECT 1 FROM syllabus
                WHERE subjectID = ? AND program = ?
                LIMIT 1
            ");
            $stmt->execute([$d['subjectID'], $d['program']]);

            if ($stmt->fetchColumn()) {
                throw new Exception('This subject is already in that program\'s curriculum.');
            }

            // Do not INSERT into prospectus; it is a VIEW.
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
                SELECT subjectID FROM syllabus WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$syllabusID]);
            $realSubjectID = $stmt->fetchColumn();

            if ($realSubjectID === false) {
                throw new Exception('Subject entry not found.');
            }

            // The subject code cannot be changed; use the stored one.
            $stmt = $this->pdo->prepare("
                UPDATE subjects SET name = ? WHERE subjectID = ?
            ");
            $stmt->execute([$d['name'], $realSubjectID]);

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
                SELECT subjectID FROM syllabus WHERE id = ? LIMIT 1
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

            $this->pdo->prepare("DELETE FROM syllabus WHERE id = ?")
                ->execute([$syllabusID]);

            // Remove the master subject only if no program still uses it.
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM syllabus WHERE subjectID = ?
            ");
            $stmt->execute([$subjectID]);

            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->prepare("
                    DELETE FROM subject_prerequisite
                    WHERE subjectID = ? OR prerequisite = ?
                ")->execute([$subjectID, $subjectID]);

                $this->pdo->prepare("DELETE FROM subjects WHERE subjectID = ?")
                    ->execute([$subjectID]);
            }

            $this->pdo->commit();

            $this->flash('ok', 'Subject deleted successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function addSubject($subjectID)
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
                FROM syllabus
                WHERE subjectID = ?
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

            if ($unit === false) {
                throw new Exception('This subject is not available for your program and year.');
            }

            // Duplicate check. Rejected rows may be selected again.
            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND subjectID = ?
                  AND status <> 'rejected'
                LIMIT 1
            ");

            $stmt->execute([$enrollmentID, $this->numericID, $subjectID]);

            if ($stmt->fetchColumn()) {
                throw new Exception('This subject is already in your selection.');
            }

            if ($this->totalUnits + (int)$unit > self::UNIT_LIMIT) {
                throw new Exception('Adding this subject would exceed the ' . self::UNIT_LIMIT . '-unit limit.');
            }

            // Prerequisites must have an approved enrollment record.
            $stmt = $this->pdo->prepare("
                SELECT prerequisite
                FROM subject_prerequisite
                WHERE subjectID = ?
            ");

            $stmt->execute([$subjectID]);
            $prerequisites = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $check = $this->pdo->prepare("
                SELECT 1
                FROM enrolled_subjects
                WHERE studentID = ?
                  AND subjectID = ?
                  AND status = 'approved'
                LIMIT 1
            ");

            foreach ($prerequisites as $prerequisite) {
                $check->execute([$this->numericID, $prerequisite]);

                if (!$check->fetchColumn()) {
                    throw new Exception('Missing prerequisite: ' . trim($prerequisite));
                }
            }

            $this->pdo->beginTransaction();

            // Clear an older rejected row for the same subject, then re-add.
            $this->pdo->prepare("
                DELETE FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND subjectID = ?
                  AND status = 'rejected'
            ")->execute([$enrollmentID, $this->numericID, $subjectID]);

            $this->pdo->prepare("
                INSERT INTO enrolled_subjects
                    (enrollmentID, studentID, subjectID, status)
                VALUES (?, ?, ?, 'pending')
            ")->execute([$enrollmentID, $this->numericID, $subjectID]);

            $this->pdo->commit();

            $this->flash('ok', 'Subject added successfully.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function removeSubject($subjectID)
    {
        if (!$this->canEdit || !$this->numericID || !$this->term) {
            $this->flash('error', 'Enrollment window is closed.');
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM enrolled_subjects
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND subjectID = ?
                  AND status IN ('pending', 'rejected')
            ");

            $stmt->execute([
                (int)$this->term['enrollmentID'],
                $this->numericID,
                trim($subjectID)
            ]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('That subject could not be removed.');
            }

            $this->flash('ok', 'Subject removed.');
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

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE enrolled_subjects
                SET status = 'approved',
                    rejection_remarks = NULL,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE enrollmentID = ?
                  AND studentID = ?
                  AND status = 'pending'
            ");

            $stmt->execute([$this->facultyID, $enrollID, $studentID]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('No pending subjects were found to approve.');
            }

            // Lock this student's enrollment window after approval.
            $stmt = $this->pdo->prepare("
                INSERT INTO student_enrollment_control
                    (enrollmentID, studentID, window_open, updated_by)
                VALUES (?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                    window_open = 0,
                    updated_by = VALUES(updated_by)
            ");

            $stmt->execute([$enrollID, $studentID, $this->facultyID]);

            $this->pdo->commit();

            $this->flash('ok', 'Enrollment approved. Student enrollment window is now closed.');
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

        if ($remarks === '') {
            $this->flash('error', 'Please provide a reason for rejection.');
            return;
        }

        try {
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

            $stmt->execute([$remarks, $this->facultyID, $enrollID, $studentID]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('No pending subjects were found to reject.');
            }

            $this->flash('ok', 'Enrollment rejected. The rejection remarks were saved.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }
}
?>