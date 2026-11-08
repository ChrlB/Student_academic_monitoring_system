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
            return;
        }

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
        if (preg_match('/student/', $role) || !preg_match('/prof|faculty|instructor|teacher|coordinator|dean/', $role)) {
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

        foreach ($rows as &$row) {
            $row['key'] = $row['acad_year'] . '|' . (int)$row['sem'];
            $row['label'] = self::semesterLabel($row['acad_year'], $row['sem']);
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
        $where = "es.enrollmentID = :enrollmentID
                  AND TRIM(es.subjectID) = :subjectID
                  AND es.status = 'approved'
                  AND st.program = :program
                  AND st.block = :block";
        $params = [
            ':enrollmentID' => $class['enrollmentID'],
            ':subjectID' => $class['subject_code'],
            ':program' => $class['program'],
            ':block' => $class['block'],
        ];
        $from = "FROM enrolled_subjects es
                 JOIN students st ON st.studentID = es.studentID
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
