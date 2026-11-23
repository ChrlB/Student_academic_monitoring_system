<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "GRADES";

class GradesLogic
{
    private $pdo;

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
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            } catch (PDOException $e) {
                error_log('Grades DB connection failed: ' . $e->getMessage());
                throw new RuntimeException('Unable to connect to the grades database.', 0, $e);
            }
        }

        $this->ensureVerificationTable();
    }

    private function ensureVerificationTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS grade_submissions (
                submissionID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                schedID INT UNSIGNED NOT NULL,
                enrollmentID INT UNSIGNED NOT NULL,
                subjectID VARCHAR(100) NOT NULL,
                program VARCHAR(50) NOT NULL,
                block VARCHAR(50) NOT NULL,
                acad_year VARCHAR(20) NOT NULL,
                sem TINYINT NOT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                grade_data LONGTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending_coordinator',
                coordinatorID INT UNSIGNED DEFAULT NULL,
                coordinator_remark TEXT DEFAULT NULL,
                coordinator_verified_at DATETIME DEFAULT NULL,
                registrarID INT UNSIGNED DEFAULT NULL,
                registrar_remark TEXT DEFAULT NULL,
                registrar_verified_at DATETIME DEFAULT NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (submissionID),
                KEY grade_submission_status (status),
                KEY grade_submission_class (schedID, enrollmentID, subjectID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function all($sql, array $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Grades query failed: ' . $e->getMessage());
            throw new RuntimeException('Unable to load gradebook data.', 0, $e);
        }
    }

    public function currentUserID()
    {
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        if (preg_match('/student/', $role) || !preg_match('/prof|faculty|instructor|teacher|coordinator|dean|registrar/', $role)) {
            return 0;
        }

        $raw = trim((string)($_SESSION['userID'] ?? ''));
        if ($raw === '') {
            return 0;
        }

        $rows = $this->all(
            "SELECT facultyID FROM faculty
             WHERE CONCAT('F', YEAR(date_created), LPAD(facultyID, 5, '0')) = :uid
             LIMIT 1",
            [':uid' => $raw]
        );
        if (isset($rows[0]['facultyID'])) {
            return (int)$rows[0]['facultyID'];
        }

        return ctype_digit($raw) ? (int)$raw : 0;
    }

    private function currentStudentID(): int
    {
        if (($_SESSION['role'] ?? '') !== 'student') {
            return 0;
        }

        $studentID = $_SESSION['real_userID'] ?? null;
        return is_scalar($studentID) && ctype_digit((string)$studentID)
            ? (int)$studentID
            : 0;
    }

    public function studentSemesters(): array
    {
        $studentID = $this->currentStudentID();
        if ($studentID <= 0) {
            return [];
        }

        $rows = $this->all(
            "SELECT DISTINCT ep.acad_year, ep.sem
             FROM enrolled_subjects es
             JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
             WHERE es.studentID = :studentID
               AND es.status = 'approved'
             ORDER BY ep.acad_year DESC, ep.sem ASC",
            [':studentID' => $studentID]
        );

        foreach ($rows as &$row) {
            $row['key'] = $row['acad_year'] . '|' . (int)$row['sem'];
            $row['label'] = self::semesterLabel($row['acad_year'], $row['sem']);
        }
        unset($row);

        return $rows;
    }

    public function studentGrades(string $semesterKey): array
    {
        $studentID = $this->currentStudentID();
        $semester = $this->splitSemester($semesterKey);
        if ($studentID <= 0 || !$semester) {
            return [];
        }

        list($acadYear, $sem) = $semester;
        return $this->all(
            "SELECT TRIM(es.subjectID) AS subject_code,
                    s.name AS subject_name,
                    es.midterm_grade,
                    es.final_grade
             FROM enrolled_subjects es
             JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
             JOIN subjects s ON TRIM(s.subjectID) = TRIM(es.subjectID)
             WHERE es.studentID = :studentID
               AND es.status = 'approved'
               AND ep.acad_year = :acadYear
               AND ep.sem = :sem
             ORDER BY es.subjectID",
            [
                ':studentID' => $studentID,
                ':acadYear' => $acadYear,
                ':sem' => $sem,
            ]
        );
    }

    public static function percentageToGwa(float $percentage): ?float
    {
        if ($percentage < 0 || $percentage > 100) {
            return null;
        }

        if ($percentage < 75) {
            return 5.00;
        }
        if ($percentage < 76) {
            return 3.00;
        }
        if ($percentage < 79) {
            return 2.75;
        }
        if ($percentage < 82) {
            return 2.50;
        }
        if ($percentage < 85) {
            return 2.25;
        }
        if ($percentage < 88) {
            return 2.00;
        }
        if ($percentage < 91) {
            return 1.75;
        }
        if ($percentage < 94) {
            return 1.50;
        }
        if ($percentage < 97) {
            return 1.25;
        }

        return 1.00;
    }

    public static function percentageRemark(float $percentage): string
    {
        if ($percentage === 4.0) {
            return 'Remedial';
        }

        return $percentage >= 75 ? 'Passed' : 'Failed';
    }

    public function studentGwaSummary(): array
    {
        $studentID = $this->currentStudentID();
        if ($studentID <= 0) {
            return ['overall' => null, 'terms' => []];
        }

        $rows = $this->all(
            "SELECT ep.acad_year, ep.sem, es.final_grade
             FROM enrolled_subjects es
             JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
             WHERE es.studentID = :studentID
               AND es.status = 'approved'
               AND es.final_grade IS NOT NULL
             ORDER BY ep.acad_year DESC, ep.sem DESC",
            [':studentID' => $studentID]
        );

        $totals = [];
        $overallTotal = 0.0;
        $overallCount = 0;
        foreach ($rows as $row) {
            $gradePoint = self::percentageToGwa((float)$row['final_grade']);
            if ($gradePoint === null) {
                continue;
            }

            $key = $row['acad_year'] . '|' . (int)$row['sem'];
            if (!isset($totals[$key])) {
                $totals[$key] = ['total' => 0.0, 'count' => 0];
            }
            $totals[$key]['total'] += $gradePoint;
            $totals[$key]['count']++;
            $overallTotal += $gradePoint;
            $overallCount++;
        }

        $terms = [];
        foreach ($totals as $key => $total) {
            $terms[$key] = round($total['total'] / $total['count'], 2);
        }

        return [
            'overall' => $overallCount > 0
                ? round($overallTotal / $overallCount, 2)
                : null,
            'terms' => $terms,
        ];
    }

    public function csrfToken()
    {
        if (empty($_SESSION['grades_csrf'])) {
            $_SESSION['grades_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['grades_csrf'];
    }

    public function takeFlash()
    {
        $flash = $_SESSION['grades_flash'] ?? null;
        unset($_SESSION['grades_flash']);
        return $flash;
    }

    public function semesters()
    {
        $facultyID = $this->currentUserID();
        if ($facultyID <= 0) {
            return [];
        }

        $rows = $this->all(
            "SELECT DISTINCT ep.acad_year, ep.sem
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             WHERE ss.facultyID = :facultyID
             ORDER BY ep.acad_year DESC, ep.sem DESC",
            [':facultyID' => $facultyID]
        );

        if (!$rows) {
            $rows = $this->all(
                "SELECT DISTINCT acad_year, sem
                 FROM enrollment_period
                 ORDER BY acad_year DESC, sem DESC"
            );
        }

        foreach ($rows as &$row) {
            $row['key'] = $row['acad_year'] . '|' . (int)$row['sem'];
            $row['label'] = self::semesterLabel($row['acad_year'], $row['sem']);
        }
        unset($row);
        return $rows;
    }

    public function assignedClasses(): array
    {
        $facultyID = $this->currentUserID();
        if ($facultyID <= 0) {
            return [];
        }

        $rows = $this->all(
            "SELECT ss.schedID, TRIM(ss.subjectID) AS subject_code,
                    TRIM(s.name) AS subject_name, ss.block, ep.program,
                    ep.acad_year, ep.sem
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             JOIN subjects s ON TRIM(s.subjectID) = TRIM(ss.subjectID)
             WHERE ss.facultyID = :facultyID
             ORDER BY ep.acad_year DESC, ep.sem DESC, ss.subjectID, ep.program, ss.block",
            [':facultyID' => $facultyID]
        );

        foreach ($rows as &$row) {
            $row['schedID'] = (int)$row['schedID'];
            $row['sem'] = (int)$row['sem'];
            $row['semester_key'] = $row['acad_year'] . '|' . $row['sem'];
            $row['semester_label'] = self::semesterLabel($row['acad_year'], $row['sem']);
        }
        unset($row);

        return $rows;
    }

    public static function semesterLabel($acadYear, $sem)
    {
        $labels = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];
        $semester = (int)$sem;
        return ($labels[$semester] ?? ($semester . 'th Semester'))
            . ', AY ' . str_replace('-', '–', (string)$acadYear);
    }

    public function selectedSemester($requested, array $semesters)
    {
        foreach ($semesters as $semester) {
            if ($semester['key'] === $requested) {
                return $requested;
            }
        }

        foreach ($semesters as $semester) {
            if ((int)($semester['sem'] ?? 0) === 1) {
                return $semester['key'];
            }
        }

        return $semesters ? $semesters[0]['key'] : '';
    }

    public function semesterLabelForKey($key, array $semesters)
    {
        foreach ($semesters as $semester) {
            if ($semester['key'] === $key) {
                return $semester['label'];
            }
        }
        return '';
    }

    private function splitSemester($key)
    {
        $parts = explode('|', (string)$key, 2);
        if (count($parts) !== 2 || !preg_match('/^\d{4}-\d{4}$/', $parts[0]) || !in_array((int)$parts[1], [1, 2, 3], true)) {
            return null;
        }
        return [$parts[0], (int)$parts[1]];
    }

    public function classPreview($semesterKey)
    {
        $facultyID = $this->currentUserID();
        $semester = $this->splitSemester($semesterKey);
        if ($facultyID <= 0 || !$semester) {
            return [];
        }

        list($acadYear, $sem) = $semester;
        $rows = $this->all(
            "SELECT
                ss.schedID,
                TRIM(ss.subjectID) AS subject_code,
                TRIM(s.name) AS subject_name,
                ss.block,
                ep.program,
                ep.acad_year,
                ep.sem,
                COUNT(DISTINCT es.studentID) AS total_students,
                COUNT(DISTINCT CASE
                    WHEN es.midterm_grade IS NOT NULL AND es.final_grade IS NOT NULL
                    THEN es.studentID END) AS grades_submitted,
                COUNT(DISTINCT CASE
                    WHEN es.enrolled_subjectID IS NOT NULL
                     AND (es.midterm_grade IS NULL OR es.final_grade IS NULL)
                    THEN es.studentID END) AS missing_grades
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             JOIN subjects s ON TRIM(s.subjectID) = TRIM(ss.subjectID)
             LEFT JOIN (
                SELECT es.enrolled_subjectID, es.studentID, es.enrollmentID,
                       TRIM(es.subjectID) AS subject_code,
                       es.midterm_grade, es.final_grade,
                       st.program AS student_program, st.block AS student_block
                FROM enrolled_subjects es
                JOIN students st ON st.studentID = es.studentID
                WHERE es.status = 'approved'
             ) es
                ON es.enrollmentID = ss.enrollmentID
               AND es.subject_code = TRIM(ss.subjectID)
               AND es.student_program = ep.program
               AND es.student_block = ss.block
             WHERE ss.facultyID = :facultyID
               AND ep.acad_year = :acadYear
               AND ep.sem = :sem
             GROUP BY ss.schedID, ss.subjectID, s.name, ss.block, ep.program, ep.acad_year, ep.sem
             ORDER BY ss.subjectID, ss.block",
            [':facultyID' => $facultyID, ':acadYear' => $acadYear, ':sem' => $sem]
        );

        foreach ($rows as &$row) {
            $row['schedID'] = (int)$row['schedID'];
            $row['total_students'] = (int)$row['total_students'];
            $row['grades_submitted'] = (int)$row['grades_submitted'];
            $row['missing_grades'] = (int)$row['missing_grades'];
        }
        unset($row);
        return $rows;
    }

    public function assignedClass($scheduleID, $semesterKey)
    {
        $facultyID = $this->currentUserID();
        $semester = $this->splitSemester($semesterKey);
        if ($facultyID <= 0 || !$semester) {
            return null;
        }

        list($acadYear, $sem) = $semester;
        $rows = $this->all(
            "SELECT ss.schedID, ss.enrollmentID, TRIM(ss.subjectID) AS subject_code,
                    TRIM(s.name) AS subject_name, ss.block, ep.program,
                    ep.acad_year, ep.sem
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             JOIN subjects s ON TRIM(s.subjectID) = TRIM(ss.subjectID)
             WHERE ss.schedID = :scheduleID
               AND ss.facultyID = :facultyID
               AND ep.acad_year = :acadYear
               AND ep.sem = :sem
             LIMIT 1",
            [
                ':scheduleID' => (int)$scheduleID,
                ':facultyID' => $facultyID,
                ':acadYear' => $acadYear,
                ':sem' => $sem,
            ]
        );

        if (!$rows) {
            return null;
        }

        $rows[0]['schedID'] = (int)$rows[0]['schedID'];
        $rows[0]['enrollmentID'] = (int)$rows[0]['enrollmentID'];
        return $rows[0];
    }

    public function classStudents(array $class, $page, $perPage = 10)
    {
        $where = "ss.schedID = :scheduleID
                  AND ss.facultyID = :facultyID
                  AND es.enrollmentID = :enrollmentID
                  AND TRIM(es.subjectID) = :subjectID
                  AND es.status = 'approved'
                  AND ep.program = :program
                  AND st.program = ep.program
                  AND st.block = ss.block";
        $params = [
            ':scheduleID' => (int)$class['schedID'],
            ':facultyID' => $this->currentUserID(),
            ':enrollmentID' => $class['enrollmentID'],
            ':subjectID' => $class['subject_code'],
            ':program' => $class['program'],
        ];
        $from = "FROM enrolled_subjects es
                 JOIN students st ON st.studentID = es.studentID
                 JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
                 JOIN subject_schedules ss
                   ON ss.enrollmentID = es.enrollmentID
                  AND TRIM(ss.subjectID) = TRIM(es.subjectID)
                 WHERE $where";

        $countRows = $this->all("SELECT COUNT(*) AS total $from", $params);
        $total = (int)($countRows[0]['total'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)$page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->all(
            "SELECT es.enrolled_subjectID, es.midterm_grade, es.final_grade,
                    st.studentID,
                    CONCAT(YEAR(st.date_created), LPAD(st.studentID, 5, '0')) AS display_student_id,
                    st.first_name, st.middle_name, st.last_name
             $from
             ORDER BY st.last_name, st.first_name, st.studentID
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            $params
        );

        foreach ($rows as &$row) {
            $middle = trim((string)$row['middle_name']);
            $row['name'] = trim($row['first_name'] . ' ' . ($middle !== '' ? $middle . ' ' : '') . $row['last_name']);
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    private function normalizedGrade($value, $field, $studentID)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || !is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
            throw new InvalidArgumentException("$field grade for student $studentID must be a number from 0 to 100, or blank.");
        }
        return number_format((float)$value, 2, '.', '');
    }

    public function submitGradesForVerification(array $class, array $gradeInputs): int
    {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $roster = $this->classStudents($class, $page);
        $allowedRows = [];
        foreach ($roster['rows'] as $student) {
            $allowedRows[(string)$student['enrolled_subjectID']] = $student;
        }

        foreach ($gradeInputs as $enrolledSubjectID => $grades) {
            if (!isset($allowedRows[(string)$enrolledSubjectID]) || !is_array($grades)) {
                throw new InvalidArgumentException('The submitted grade list does not match this class roster.');
            }
        }

        $gradeData = [];
        foreach ($gradeInputs as $enrolledSubjectID => $grades) {
            $student = $allowedRows[(string)$enrolledSubjectID];
            $gradeData[] = [
                'enrolled_subjectID' => (int)$enrolledSubjectID,
                'studentID' => (int)$student['studentID'],
                'display_student_id' => (string)$student['display_student_id'],
                'student_name' => (string)$student['name'],
                'midterm' => $this->normalizedGrade($grades['midterm'] ?? '', 'Midterm', $student['display_student_id']),
                'final' => $this->normalizedGrade($grades['final'] ?? '', 'Final', $student['display_student_id']),
            ];
        }

        $facultyID = $this->currentUserID();
        if ($facultyID <= 0) {
            throw new RuntimeException('Unable to identify the professor account.');
        }

        $existing = $this->all(
            "SELECT submissionID
             FROM grade_submissions
             WHERE schedID = :schedID
               AND enrollmentID = :enrollmentID
               AND TRIM(subjectID) = :subjectID
               AND status IN ('pending_coordinator', 'pending_registrar')
             LIMIT 1",
            [
                ':schedID' => (int)$class['schedID'],
                ':enrollmentID' => (int)$class['enrollmentID'],
                ':subjectID' => $class['subject_code'],
            ]
        );
        if ($existing) {
            throw new InvalidArgumentException('This class already has grades awaiting verification.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO grade_submissions
                    (schedID, enrollmentID, subjectID, program, block, acad_year, sem,
                     submitted_by, grade_data, status, submitted_at)
                 VALUES
                    (:schedID, :enrollmentID, :subjectID, :program, :block, :acadYear, :sem,
                     :facultyID, :gradeData, 'pending_coordinator', CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                ':schedID' => $class['schedID'],
                ':enrollmentID' => $class['enrollmentID'],
                ':subjectID' => $class['subject_code'],
                ':program' => $class['program'],
                ':block' => $class['block'],
                ':acadYear' => $class['acad_year'],
                ':sem' => (int)$class['sem'],
                ':facultyID' => $facultyID,
                ':gradeData' => json_encode($gradeData, JSON_UNESCAPED_UNICODE),
            ]);
            $submissionID = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $roster['page'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function enrichGradeStudentIds(array $grades): array
    {
        $studentIds = [];
        foreach ($grades as $grade) {
            if (isset($grade['studentID']) && empty($grade['display_student_id'])) {
                $studentIds[] = (int)$grade['studentID'];
            }
        }
        $studentIds = array_values(array_unique(array_filter($studentIds)));
        if (!$studentIds) {
            return $grades;
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT studentID,
                    CONCAT(YEAR(date_created), LPAD(studentID, 5, '0')) AS display_student_id
             FROM students
             WHERE studentID IN ($placeholders)"
        );
        $stmt->execute($studentIds);
        $idsByStudent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
            $idsByStudent[(int)$student['studentID']] = (string)$student['display_student_id'];
        }

        foreach ($grades as &$grade) {
            if (empty($grade['display_student_id'])) {
                $studentID = (int)($grade['studentID'] ?? 0);
                $grade['display_student_id'] = $idsByStudent[$studentID] ?? (string)$studentID;
            }
        }
        unset($grade);
        return $grades;
    }

    public function coordinatorSubmissions(): array
    {
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $params = [];
        $where = "gs.status = 'pending_coordinator'";

        if ($role === 'program coordinator') {
            $program = trim((string)($_SESSION['program'] ?? ''));
            if ($program === '') {
                return [];
            }
            $where .= " AND gs.program = :program";
            $params[':program'] = $program;
        }

        $rows = $this->all(
            "SELECT gs.*, TRIM(s.name) AS subject_name,
                    CONCAT(f.first_name, ' ', f.last_name) AS submitted_by_name
             FROM grade_submissions gs
             LEFT JOIN subjects s ON TRIM(s.subjectID) = TRIM(gs.subjectID)
             LEFT JOIN faculty f ON f.facultyID = gs.submitted_by
             WHERE $where
             ORDER BY gs.submitted_at DESC",
            $params
        );

        foreach ($rows as &$row) {
            $row['submissionID'] = (int)$row['submissionID'];
            $row['grades'] = json_decode((string)$row['grade_data'], true);
            if (!is_array($row['grades'])) {
                $row['grades'] = [];
            }
            $row['grades'] = $this->enrichGradeStudentIds($row['grades']);
        }
        unset($row);
        return $rows;
    }

    public function registrarSubmissions(): array
    {
        $rows = $this->all(
            "SELECT gs.*, TRIM(s.name) AS subject_name,
                    CONCAT(f.first_name, ' ', f.last_name) AS submitted_by_name
             FROM grade_submissions gs
             LEFT JOIN subjects s ON TRIM(s.subjectID) = TRIM(gs.subjectID)
             LEFT JOIN faculty f ON f.facultyID = gs.submitted_by
             WHERE gs.status = 'pending_registrar'
             ORDER BY gs.coordinator_verified_at DESC, gs.submitted_at DESC"
        );

        foreach ($rows as &$row) {
            $row['submissionID'] = (int)$row['submissionID'];
            $row['grades'] = json_decode((string)$row['grade_data'], true);
            if (!is_array($row['grades'])) {
                $row['grades'] = [];
            }
            $row['grades'] = $this->enrichGradeStudentIds($row['grades']);
        }
        unset($row);
        return $rows;
    }

    public function registrarStudentList(array $filters = []): array
    {
        $program = trim((string)($filters['program'] ?? ''));
        $facultyID = (int)($filters['faculty'] ?? 0);
        $academicYear = trim((string)($filters['acad_year'] ?? ''));
        $semester = (int)($filters['sem'] ?? 0);
        $showStudents = !empty($filters['show_students']);
        $eligibility = "
            FROM subject_schedules ss
            JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
            JOIN enrolled_subjects es
              ON es.enrollmentID = ss.enrollmentID
             AND TRIM(es.subjectID) = TRIM(ss.subjectID)
             AND es.status = 'approved'
            JOIN students st
              ON st.studentID = es.studentID
             AND st.program = ep.program
             AND st.block = ss.block
             AND st.status = 'active'";

        $programOptions = $this->all(
            "SELECT DISTINCT ep.program
             FROM subject_schedules ss
             JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
             JOIN enrolled_subjects es
               ON es.enrollmentID = ss.enrollmentID
              AND TRIM(es.subjectID) = TRIM(ss.subjectID)
              AND es.status = 'approved'
             JOIN students st
               ON st.studentID = es.studentID
              AND st.program = ep.program
              AND st.block = ss.block
              AND st.status = 'active'
             ORDER BY ep.program"
        );

        $facultyOptions = $program !== ''
            ? $this->all(
                "SELECT DISTINCT f.facultyID,
                        CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
                 $eligibility
                 JOIN faculty f ON f.facultyID = ss.facultyID
                 WHERE ep.program = :program
                 ORDER BY faculty_name",
                [':program' => $program]
            )
            : [];

        $yearParams = [':program' => $program, ':facultyID' => $facultyID];
        $academicYearOptions = $program !== '' && $facultyID > 0
            ? $this->all(
                "SELECT DISTINCT ep.acad_year
                 $eligibility
                 WHERE ep.program = :program
                   AND ss.facultyID = :facultyID
                 ORDER BY ep.acad_year DESC",
                $yearParams
            )
            : [];

        $validAcademicYear = in_array($academicYear, array_column($academicYearOptions, 'acad_year'), true);
        $semesterOptions = $program !== '' && $facultyID > 0 && $validAcademicYear
            ? $this->all(
                "SELECT DISTINCT ep.sem
                 $eligibility
                 WHERE ep.program = :program
                   AND ss.facultyID = :facultyID
                   AND ep.acad_year = :acadYear
                 ORDER BY ep.sem",
                [
                    ':program' => $program,
                    ':facultyID' => $facultyID,
                    ':acadYear' => $academicYear,
                ]
            )
            : [];
        $semesterOptions = array_map(static fn(array $row): int => (int)$row['sem'], $semesterOptions);
        $validSemester = in_array($semester, $semesterOptions, true);

        $validFaculty = false;
        foreach ($facultyOptions as $faculty) {
            if ((int)$faculty['facultyID'] === $facultyID) {
                $validFaculty = true;
                break;
            }
        }
        $hasCompleteFilters = $program !== '' && $validFaculty && $validAcademicYear && $validSemester;
        $scheduleID = (int)($filters['schedule'] ?? 0);
        $scheduleOptions = [];
        $selectedSchedule = null;
        $rows = [];
        if ($hasCompleteFilters) {
            $scheduleOptions = $this->all(
                "SELECT ss.schedID, TRIM(ss.subjectID) AS subject_code,
                        TRIM(s.name) AS subject_name, ss.block,
                        ep.program, ep.acad_year, ep.sem,
                        ss.day, ss.time_start, ss.time_end, ss.roomID,
                        COUNT(DISTINCT st.studentID) AS student_count
                 FROM subject_schedules ss
                 JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
                 JOIN subjects s ON TRIM(s.subjectID) = TRIM(ss.subjectID)
                 LEFT JOIN enrolled_subjects es
                   ON es.enrollmentID = ss.enrollmentID
                  AND TRIM(es.subjectID) = TRIM(ss.subjectID)
                  AND es.status = 'approved'
                 LEFT JOIN students st
                   ON st.studentID = es.studentID
                  AND st.program = ep.program
                  AND st.block = ss.block
                  AND st.status = 'active'
                 WHERE ep.program = :program
                   AND ss.facultyID = :facultyID
                   AND ep.acad_year = :acadYear
                   AND ep.sem = :semester
                 GROUP BY ss.schedID, ss.subjectID, s.name, ss.block,
                          ep.program, ep.acad_year, ep.sem, ss.day,
                          ss.time_start, ss.time_end, ss.roomID
                 ORDER BY ep.sem, ep.program, ss.block, subject_code, ss.day, ss.time_start",
                [
                    ':program' => $program,
                    ':facultyID' => $facultyID,
                    ':acadYear' => $academicYear,
                    ':semester' => $semester,
                ]
            );
            foreach ($scheduleOptions as &$schedule) {
                $schedule['schedID'] = (int)$schedule['schedID'];
                $schedule['student_count'] = (int)$schedule['student_count'];
            }
            unset($schedule);

            $validSchedule = false;
            foreach ($scheduleOptions as $schedule) {
                if ($schedule['schedID'] === $scheduleID) {
                    $validSchedule = true;
                    $selectedSchedule = $schedule;
                    break;
                }
            }

            if ($showStudents && $validSchedule) {
                $filterParams = [
                    ':program' => $program,
                    ':facultyID' => $facultyID,
                    ':acadYear' => $academicYear,
                    ':semester' => $semester,
                    ':scheduleID' => $scheduleID,
                ];
                $matchingConditions = "
                    es.status = 'approved'
                    AND st.status = 'active'
                    AND st.program = :program
                    AND ep.program = st.program
                    AND ep.acad_year = :acadYear
                    AND ep.sem = :semester
                    AND EXISTS (
                        SELECT 1
                        FROM subject_schedules ss
                        WHERE ss.enrollmentID = es.enrollmentID
                          AND TRIM(ss.subjectID) = TRIM(es.subjectID)
                          AND ss.block = st.block
                          AND ss.facultyID = :facultyID
                          AND ss.schedID = :scheduleID
                    )";
                $rows = $this->all(
                    "SELECT DISTINCT st.studentID,
                            CONCAT(YEAR(st.date_created), LPAD(st.studentID, 5, '0')) AS display_student_id,
                            st.first_name, st.middle_name, st.last_name,
                            st.program, st.year_level, st.block, ep.acad_year
                     FROM enrolled_subjects es
                     JOIN students st ON st.studentID = es.studentID
                     JOIN enrollment_period ep ON ep.enrollmentID = es.enrollmentID
                     WHERE $matchingConditions
                     ORDER BY st.program, st.year_level, st.block,
                              st.last_name, st.first_name, st.studentID",
                    $filterParams
                );

                foreach ($rows as &$row) {
                    $middle = trim((string)$row['middle_name']);
                    $row['name'] = trim(
                        $row['first_name'] . ' ' .
                        ($middle !== '' ? $middle . ' ' : '') .
                        $row['last_name']
                    );
                    $row['studentID'] = (int)$row['studentID'];
                    $row['year_level'] = (int)$row['year_level'];
                }
                unset($row);
            }
        }

        $selectedFacultyName = '';
        foreach ($facultyOptions as $faculty) {
            if ((int)$faculty['facultyID'] === $facultyID) {
                $selectedFacultyName = (string)$faculty['faculty_name'];
                break;
            }
        }

        return [
            'rows' => $rows,
            'programs' => array_column($programOptions, 'program'),
            'faculty' => $facultyOptions,
            'academic_years' => array_column($academicYearOptions, 'acad_year'),
            'semesters' => $semesterOptions,
            'selected_faculty_name' => $selectedFacultyName,
            'filters_complete' => $hasCompleteFilters,
            'schedules' => $scheduleOptions,
            'selected_schedule' => $selectedSchedule,
        ];
    }

    public function coordinatorReview(int $submissionID, string $decision, string $remark): void
    {
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        if (!in_array($role, ['program coordinator', 'dean'], true)) {
            throw new RuntimeException('This account cannot verify grades.');
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Invalid grade verification decision.');
        }

        $submission = $this->submissionForReview($submissionID, 'pending_coordinator');
        if ($role === 'program coordinator') {
            $program = trim((string)($_SESSION['program'] ?? ''));
            if ($program === '' || $submission['program'] !== $program) {
                throw new RuntimeException('This grade submission belongs to another program.');
            }
        }

        $facultyID = $this->currentUserID();
        $status = $decision === 'approve' ? 'pending_registrar' : 'rejected';
        $stmt = $this->pdo->prepare(
            "UPDATE grade_submissions
             SET status = :status,
                 coordinatorID = :facultyID,
                 coordinator_remark = :remark,
                 coordinator_verified_at = CURRENT_TIMESTAMP
             WHERE submissionID = :submissionID
               AND status = 'pending_coordinator'"
        );
        $stmt->execute([
            ':status' => $status,
            ':facultyID' => $facultyID,
            ':remark' => trim($remark) !== '' ? trim($remark) : null,
            ':submissionID' => $submissionID,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('This submission was already reviewed.');
        }
    }

    private function submissionForReview(int $submissionID, string $status): array
    {
        $rows = $this->all(
            "SELECT * FROM grade_submissions
             WHERE submissionID = :submissionID AND status = :status
             LIMIT 1",
            [':submissionID' => $submissionID, ':status' => $status]
        );
        if (!$rows) {
            throw new RuntimeException('The grade submission is no longer awaiting review.');
        }
        return $rows[0];
    }

    public function registrarFinalReview(int $submissionID, string $decision, string $remark): void
    {
        if (strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'registrar') {
            throw new RuntimeException('Only the registrar can complete final grade verification.');
        }

        $submission = $this->submissionForReview($submissionID, 'pending_registrar');
        $registrarID = $this->currentUserID();

        if ($decision === 'reject') {
            $stmt = $this->pdo->prepare(
                "UPDATE grade_submissions
                 SET status = 'rejected',
                     registrarID = :registrarID,
                     registrar_remark = :remark,
                     registrar_verified_at = CURRENT_TIMESTAMP
                 WHERE submissionID = :submissionID
                   AND status = 'pending_registrar'"
            );
            $stmt->execute([
                ':registrarID' => $registrarID,
                ':remark' => trim($remark) !== '' ? trim($remark) : null,
                ':submissionID' => $submissionID,
            ]);
            return;
        }
        if ($decision !== 'approve') {
            throw new InvalidArgumentException('Invalid final verification decision.');
        }

        $gradeData = json_decode((string)$submission['grade_data'], true);
        if (!is_array($gradeData)) {
            throw new RuntimeException('The saved grade submission is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                "UPDATE enrolled_subjects
                 SET midterm_grade = :midterm,
                     final_grade = :final
                 WHERE enrolled_subjectID = :enrolledSubjectID
                   AND enrollmentID = :enrollmentID
                   AND TRIM(subjectID) = :subjectID
                   AND status = 'approved'"
            );

            foreach ($gradeData as $grade) {
                $match = $this->pdo->prepare(
                    "SELECT enrolled_subjectID
                     FROM enrolled_subjects
                     WHERE enrolled_subjectID = :enrolledSubjectID
                       AND enrollmentID = :enrollmentID
                       AND TRIM(subjectID) = :subjectID
                       AND status = 'approved'
                     LIMIT 1"
                );
                $match->execute([
                    ':enrolledSubjectID' => (int)$grade['enrolled_subjectID'],
                    ':enrollmentID' => (int)$submission['enrollmentID'],
                    ':subjectID' => $submission['subjectID'],
                ]);
                if (!$match->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('A student enrollment changed before final verification.');
                }

                $update->execute([
                    ':midterm' => $grade['midterm'] !== null ? (float)$grade['midterm'] : null,
                    ':final' => $grade['final'] !== null ? (float)$grade['final'] : null,
                    ':enrolledSubjectID' => (int)$grade['enrolled_subjectID'],
                    ':enrollmentID' => (int)$submission['enrollmentID'],
                    ':subjectID' => $submission['subjectID'],
                ]);
            }

            $approved = $this->pdo->prepare(
                "UPDATE grade_submissions
                 SET status = 'approved',
                     registrarID = :registrarID,
                     registrar_remark = :remark,
                     registrar_verified_at = CURRENT_TIMESTAMP
                 WHERE submissionID = :submissionID
                   AND status = 'pending_registrar'"
            );
            $approved->execute([
                ':registrarID' => $registrarID,
                ':remark' => trim($remark) !== '' ? trim($remark) : null,
                ':submissionID' => $submissionID,
            ]);
            if ($approved->rowCount() !== 1) {
                throw new RuntimeException('This submission was already processed.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function saveGrades(array $class, array $gradeInputs)
    {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $roster = $this->classStudents($class, $page);
        $allowedRows = [];
        foreach ($roster['rows'] as $student) {
            $allowedRows[(string)$student['enrolled_subjectID']] = $student;
        }

        foreach ($gradeInputs as $enrolledSubjectID => $grades) {
            if (!isset($allowedRows[(string)$enrolledSubjectID]) || !is_array($grades)) {
                throw new InvalidArgumentException('The submitted grade list does not match this class roster.');
            }
        }

        $facultyID = $this->currentUserID();
        $sql = "UPDATE enrolled_subjects
                SET midterm_grade = :midterm, final_grade = :final
                WHERE enrolled_subjectID = :enrolledSubjectID
                  AND enrollmentID = :enrollmentID
                  AND TRIM(subjectID) = :subjectID
                  AND status = 'approved'
                  AND EXISTS (
                      SELECT 1 FROM subject_schedules ss
                      WHERE ss.schedID = :scheduleID
                        AND ss.facultyID = :facultyID
                        AND ss.enrollmentID = enrolled_subjects.enrollmentID
                        AND TRIM(ss.subjectID) = TRIM(enrolled_subjects.subjectID)
                  )";

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare($sql);
            foreach ($gradeInputs as $enrolledSubjectID => $grades) {
                $student = $allowedRows[(string)$enrolledSubjectID];
                $stmt->execute([
                    ':midterm' => $this->normalizedGrade($grades['midterm'] ?? '', 'Midterm', $student['display_student_id']),
                    ':final' => $this->normalizedGrade($grades['final'] ?? '', 'Final', $student['display_student_id']),
                    ':enrolledSubjectID' => (int)$enrolledSubjectID,
                    ':enrollmentID' => $class['enrollmentID'],
                    ':subjectID' => $class['subject_code'],
                    ':scheduleID' => $class['schedID'],
                    ':facultyID' => $facultyID,
                ]);
                if ($stmt->rowCount() > 0) {
                    continue;
                }

                $check = $this->pdo->prepare(
                    "SELECT es.enrolled_subjectID
                     FROM enrolled_subjects es
                     JOIN subject_schedules ss
                       ON ss.schedID = :scheduleID
                      AND ss.facultyID = :facultyID
                      AND ss.enrollmentID = es.enrollmentID
                      AND TRIM(ss.subjectID) = TRIM(es.subjectID)
                     WHERE es.enrolled_subjectID = :id
                       AND es.enrollmentID = :enrollmentID
                       AND TRIM(es.subjectID) = :subjectID
                       AND es.status = 'approved'"
                );
                $check->execute([
                    ':scheduleID' => $class['schedID'],
                    ':facultyID' => $facultyID,
                    ':id' => (int)$enrolledSubjectID,
                    ':enrollmentID' => $class['enrollmentID'],
                    ':subjectID' => $class['subject_code'],
                ]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('A student enrollment changed while saving grades. Reload the page and try again.');
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $roster['page'];
    }
}
