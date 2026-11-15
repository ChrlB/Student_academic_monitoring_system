<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../PDAO/DAO.php';   // gives us $db_connection (PDO)
$_SESSION["page"] = "PROJECTS";

class ProjectsLogic
{
    public $group = 'student';
    public $error = '';

    public $projects  = [];   
    public $stats     = ['total' => 0, 'pending' => 0, 'submitted' => 0];

    public $selected   = null; 
    public $team       = [];
    public $tasks      = ['todo' => [], 'progress' => [], 'done' => []];
    public $submission = null;
    public $isLead     = false;

    public $instructorName = '';
    public $studentOptions = [];   
    public $nextCode       = 'PRJ-001';

    private $db;
    private $studentID = 0;   
    private $facultyID = 0;   

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->db = self::connect();
        $this->resolveUser();
    }

    private static function connect()
    {
        $pdo = $GLOBALS['db_connection'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection not found. Check PDAO/DAO.php.');
        }
        return $pdo;
    }

    private function resolveUser()
    {
        $userID = '';
        foreach (['userID', 'user_id', 'ID', 'id'] as $key) {
            if (!empty($_SESSION[$key]) && is_scalar($_SESSION[$key])) {
                $userID = (string)$_SESSION[$key];
                break;
            }
        }

        if ($userID !== '' && $userID[0] === 'F') {
            $this->group = 'faculty';

            $st = $this->db->prepare(
                "SELECT facultyID, last_name FROM faculty
                  WHERE concat('F', year(date_created), lpad(facultyID, 5, 0)) = ?"
            );
            $st->execute([$userID]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->facultyID      = (int)$row['facultyID'];
                $this->instructorName = 'Prof. ' . $row['last_name'];
            }
            return;
        }

        if ($userID !== '') {
            $st = $this->db->prepare(
                "SELECT studentID FROM students
                  WHERE concat(year(date_created), lpad(studentID, 5, 0)) = ?"
            );
            $st->execute([$userID]);
            $this->studentID = (int)$st->fetchColumn();
        }

        $this->group = 'student';
    }

    public static function url($q = [], $fragment = '')
    {
        $url = BASE_PATH . '/projects';
        if (!empty($q))       $url .= '?' . http_build_query($q);
        if ($fragment !== '') $url .= '#' . $fragment;
        return $url;
    }

    public function csrf()
    {
        if (empty($_SESSION['projects_csrf'])) {
            $_SESSION['projects_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['projects_csrf'];
    }

    private function checkCsrf()
    {
        $sent = $_POST['csrf'] ?? '';
        return is_string($sent) && hash_equals($_SESSION['projects_csrf'] ?? '', $sent);
    }

    private function flash($type, $msg)
    {
        $_SESSION['projects_flash'] = ['type' => $type, 'msgs' => [$msg]];
    }

    public function takeFlash()
    {
        $f = $_SESSION['projects_flash'] ?? null;
        unset($_SESSION['projects_flash']);
        return $f;
    }

    private function redirect($q = [])
    {
        header('Location: ' . self::url($q));
        exit;
    }

    public function handlePost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        if (!$this->checkCsrf()) {
            $this->error = 'Your session expired. Please try again.';
            return;
        }

        $action    = $_POST['project_action'] ?? '';
        $projectID = (int)($_POST['project_id'] ?? 0);

        if ($action === 'create_project') {
            $this->createProject();
            return;
        }

        if (!$this->isMember($projectID)) {
            $this->error = 'You are not part of that project.';
            return;
        }

        if ($action === 'submit_project') $this->submitProject($projectID);
        if ($action === 'update_task')    $this->updateTask($projectID);
        if ($action === 'add_task')       $this->addTask($projectID); // NEW
    }

    private static function profSql()
    {
        return "COALESCE((SELECT CONCAT('Prof. ', f.last_name)
                            FROM subject_schedules ss
                            JOIN faculty f ON f.facultyID = ss.facultyID
                           WHERE ss.enrollmentID = es.enrollmentID
                             AND ss.subjectID = es.subjectID
                           LIMIT 1), '—')";
    }

    private function isMember($projectID)
    {
        if ($this->studentID <= 0) return false;

        $st = $this->db->prepare(
            "SELECT 1
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
              WHERE p.projectID = ?
                AND (es.studentID = ?
                     OR EXISTS (SELECT 1 FROM project_members m
                                 WHERE m.projectID = p.projectID AND m.studentID = ?))"
        );
        $st->execute([$projectID, $this->studentID, $this->studentID]);
        return (bool)$st->fetchColumn();
    }

    private function isInstructorOf($projectID)
    {
        if ($this->facultyID <= 0) return false;
        $st = $this->db->prepare(
            "SELECT 1
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
               JOIN subject_schedules ss ON ss.enrollmentID = es.enrollmentID AND ss.subjectID = es.subjectID
              WHERE p.projectID = ? AND ss.facultyID = ?"
        );
        $st->execute([$projectID, $this->facultyID]);
        return (bool)$st->fetchColumn();
    }

    private function isLeadOf($projectID)
    {
        $st = $this->db->prepare(
            "SELECT 1
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
              WHERE p.projectID = ? AND es.studentID = ?"
        );
        $st->execute([$projectID, $this->studentID]);
        return (bool)$st->fetchColumn();
    }

    // NEW: Add task function
    private function addTask($projectID)
    {
        // Any team member can add a task
        $title    = trim($_POST['task_title'] ?? '');
        $deadline = trim($_POST['task_deadline'] ?? '');

        $date = DateTime::createFromFormat('Y-m-d', $deadline);
        if ($title === '') {
            $this->error = 'Task title cannot be empty.';
            return;
        }
        if (!$date || $date->format('Y-m-d') !== $deadline) {
            $this->error = 'Choose a valid deadline date for the task.';
            return;
        }

        $st = $this->db->prepare(
            "INSERT INTO project_task (projectID, task_title, deadline, satatus) 
             VALUES (?, ?, ?, 'todo')"
        );
        $st->execute([$projectID, $title, $deadline . ' 23:59:59']);

        $this->flash('success', 'Task added to the board.');
        $this->redirect(['project' => $projectID]);
    }

    private function submitProject($projectID)
    {
        if (!$this->isLeadOf($projectID)) {
            $this->error = 'Only the team lead can submit this project.';
            return;
        }

        $file  = trim($_POST['file_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $attachmentPath = null;

        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/projects/'; 
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = basename($_FILES['attachment']['name']);
            $fileName = preg_replace("/[^a-zA-Z0-9.\-_]/", "_", $fileName);
            $uniqueName = time() . '_' . $fileName; 
            $targetFile = $uploadDir . $uniqueName;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
                $attachmentPath = 'uploads/projects/' . $uniqueName;
            } else {
                $this->error = 'Failed to upload the attachment. Please try again.';
                return;
            }
        } else {
            $st = $this->db->prepare("SELECT attachment FROM project_submission WHERE projectID = ?");
            $st->execute([$projectID]);
            $oldAtt = $st->fetchColumn();
            if ($oldAtt) {
                $attachmentPath = $oldAtt;
            }
        }

        if ($file === '') {
            $this->error = 'Add a project file or repository URL before submitting.';
            return;
        }

        $st = $this->db->prepare(
            "INSERT INTO project_submission (projectID, submitted_by, file_url, attachment, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE file_url = VALUES(file_url),
                                     attachment = VALUES(attachment),
                                     notes = VALUES(notes)"
        );
        $st->execute([$projectID, $this->studentID, $file, $attachmentPath, $notes]);

        $this->flash('success', 'Project submitted to your professor.');
        $this->redirect(['project' => $projectID]);
    }

    private function updateTask($projectID)
    {
        $taskID = (int)($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!in_array($status, ['todo', 'progress', 'done'], true)) {
            $this->error = 'Invalid task status.';
            return;
        }

        $st = $this->db->prepare(
            "UPDATE project_task SET satatus = ? WHERE taskID = ? AND projectID = ?"
        );
        $st->execute([$status, $taskID, $projectID]);

        $this->flash('success', 'Task updated.');
        $this->redirect(['project' => $projectID]);
    }

    private static function taskColumn($value)
    {
        $v = strtolower(trim((string)$value));
        if (in_array($v, ['done', 'completed', 'complete'], true)) return 'done';
        if (in_array($v, ['progress', 'in progress', 'in_progress', 'ongoing'], true)) return 'progress';
        return 'todo';
    }

    private function createProject()
    {
        if ($this->group !== 'faculty' || $this->facultyID <= 0) {
            $this->error = 'Only instructors can assign projects.';
            return;
        }

        $name     = trim($_POST['name'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $instr    = trim($_POST['instructions'] ?? '');
        $leader   = (int)($_POST['leader'] ?? 0);
        $members  = array_map('intval', (array)($_POST['members'] ?? []));
        $members  = array_values(array_diff(array_unique(array_filter($members)), [$leader]));
        $picked   = $leader > 0 ? array_merge([$leader], $members) : [];

        $date = DateTime::createFromFormat('Y-m-d', $deadline);
        if ($name === '')                                   { $this->error = 'Enter a project name.'; return; }
        if (!$date || $date->format('Y-m-d') !== $deadline) { $this->error = 'Choose a valid deadline date.'; return; }
        if ($leader <= 0)                                   { $this->error = 'Choose a team leader.'; return; }

        $in = implode(',', array_fill(0, count($picked), '?'));
        $st = $this->db->prepare(
            "SELECT es.enrolled_subjectID, es.studentID
               FROM enrolled_subjects es
              WHERE es.enrolled_subjectID IN ($in)
                AND EXISTS (SELECT 1 FROM subject_schedules ss
                             WHERE ss.enrollmentID = es.enrollmentID
                               AND ss.subjectID = es.subjectID
                               AND ss.facultyID = ?)"
        );
        $st->execute(array_merge($picked, [$this->facultyID]));
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[(int)$r['enrolled_subjectID']] = (int)$r['studentID'];
        }
        if (count($found) !== count($picked)) {
            $this->error = 'One of the selected students is not in your class.';
            return;
        }

        $leadEnrolled = $leader;           
        $leadStudent  = $found[$leadEnrolled];

        try {
            $this->db->beginTransaction();

            $st = $this->db->prepare(
                "INSERT INTO projects (enrolled_subjectID, name, deadline) VALUES (?, ?, ?)"
            );
            $st->execute([$leadEnrolled, $name, $deadline . ' 23:59:59']);
            $projectID = (int)$this->db->lastInsertId();

            $st = $this->db->prepare("INSERT INTO project_info (projectID, instructions) VALUES (?, ?)");
            $st->execute([$projectID, $instr]);

            $st = $this->db->prepare("INSERT IGNORE INTO project_members (projectID, studentID) VALUES (?, ?)");
            foreach ($found as $studentID) {
                if ($studentID !== $leadStudent) $st->execute([$projectID, $studentID]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->error = 'Could not create the project. Please try again.';
            return;
        }

        $this->flash('success', 'Project created and assigned.');
        $this->redirect();
    }

    public function loadData()
    {
        if ($this->group === 'faculty') {
            $this->loadInstructor();
            
            $projectID = (int)($_GET['project'] ?? 0);
            if ($projectID > 0) {
                $this->loadDetail($projectID);
            }
            return;
        }

        if ($this->group !== 'student' || $this->studentID <= 0) return;

        $this->loadList();

        $projectID = (int)($_GET['project'] ?? 0);
        if ($projectID > 0) {
            $this->loadDetail($projectID);
        }
    }

    private function loadInstructor()
    {
        if ($this->facultyID <= 0) return;

        $st = $this->db->prepare(
            "SELECT p.projectID AS id,
                    CONCAT('PRJ-', LPAD(p.projectID, 3, '0')) AS code,
                    p.name, p.deadline,
                    sub.file_url, sub.attachment, sub.notes,
                    (sub.id IS NOT NULL) AS submitted
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
          LEFT JOIN project_submission sub ON sub.projectID = p.projectID
              WHERE EXISTS (SELECT 1 FROM subject_schedules ss
                             WHERE ss.enrollmentID = es.enrollmentID
                               AND ss.subjectID = es.subjectID
                               AND ss.facultyID = ?)
           ORDER BY p.projectID"
        );
        $st->execute([$this->facultyID]);
        $this->projects = $st->fetchAll(PDO::FETCH_ASSOC);

        $submitted = 0;
        foreach ($this->projects as &$p) {
            $p['assigned_by'] = $this->instructorName;
            if ((int)$p['submitted'] === 1) $submitted++;
        }
        unset($p);

        $total = count($this->projects);
        $this->stats = ['total' => $total, 'submitted' => $submitted, 'pending' => $total - $submitted];

        $st = $this->db->prepare(
            "SELECT es.enrolled_subjectID, es.subjectID, s.first_name, s.last_name
               FROM enrolled_subjects es
               JOIN students s ON s.studentID = es.studentID
              WHERE EXISTS (SELECT 1 FROM subject_schedules ss
                             WHERE ss.enrollmentID = es.enrollmentID
                               AND ss.subjectID = es.subjectID
                               AND ss.facultyID = ?)
           GROUP BY es.enrolled_subjectID, es.subjectID, s.first_name, s.last_name
           ORDER BY s.last_name, s.first_name"
        );
        $st->execute([$this->facultyID]);
        $this->studentOptions = $st->fetchAll(PDO::FETCH_ASSOC);

        $next = (int)$this->db->query("SELECT COALESCE(MAX(projectID), 0) + 1 FROM projects")->fetchColumn();
        $this->nextCode = 'PRJ-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    private function loadList()
    {
        $st = $this->db->prepare(
            "SELECT p.projectID AS id,
                    CONCAT('PRJ-', LPAD(p.projectID, 3, '0')) AS code,
                    p.name, p.deadline,
                    " . self::profSql() . " AS assigned_by,
                    sub.file_url, sub.attachment, sub.notes,
                    (sub.id IS NOT NULL) AS submitted
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
          LEFT JOIN project_submission sub ON sub.projectID = p.projectID
              WHERE es.studentID = ?
                 OR p.projectID IN (SELECT projectID FROM project_members WHERE studentID = ?)
           ORDER BY p.projectID"
        );
        $st->execute([$this->studentID, $this->studentID]);
        $this->projects = $st->fetchAll(PDO::FETCH_ASSOC);

        $total     = count($this->projects);
        $submitted = 0;
        foreach ($this->projects as $p) {
            if ((int)$p['submitted'] === 1) $submitted++;
        }

        $this->stats = [
            'total'     => $total,
            'submitted' => $submitted,
            'pending'   => $total - $submitted,
        ];
    }

    private function loadDetail($projectID)
    {
        if ($this->group === 'student' && !$this->isMember($projectID)) {
            $this->error = 'Project not found.';
            return;
        }
        if ($this->group === 'faculty' && !$this->isInstructorOf($projectID)) {
            $this->error = 'Project not found or you do not have permission to view it.';
            return;
        }

        $st = $this->db->prepare(
            "SELECT p.projectID AS id,
                    CONCAT('PRJ-', LPAD(p.projectID, 3, '0')) AS code,
                    p.name, p.deadline,
                    " . self::profSql() . " AS assigned_by,
                    COALESCE(i.instructions, '') AS instructions
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
          LEFT JOIN project_info i ON i.projectID = p.projectID
              WHERE p.projectID = ?"
        );
        $st->execute([$projectID]);
        $this->selected = $st->fetch(PDO::FETCH_ASSOC);

        $this->isLead = $this->isLeadOf($projectID);

        $st = $this->db->prepare(
            "SELECT s.studentID, 'lead' AS role, s.first_name, s.last_name
               FROM projects p
               JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
               JOIN students s ON s.studentID = es.studentID
              WHERE p.projectID = ?
              UNION ALL
             SELECT s.studentID, 'member', s.first_name, s.last_name
               FROM project_members m
               JOIN students s ON s.studentID = m.studentID
              WHERE m.projectID = ?"
        );
        $st->execute([$projectID, $projectID]);
        $this->team = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $this->db->prepare(
            "SELECT taskID AS id, task_title AS title, satatus AS status, deadline
               FROM project_task
              WHERE projectID = ?
           ORDER BY taskID"
        );
        $st->execute([$projectID]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $this->tasks[self::taskColumn($task['status'])][] = $task;
        }

        $st = $this->db->prepare("SELECT * FROM project_submission WHERE projectID = ?");
        $st->execute([$projectID]);
        $this->submission = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}