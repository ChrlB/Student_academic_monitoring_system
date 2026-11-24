<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../PDAO/DAO.php';   // gives us $db_connection (PDO)
$_SESSION["page"] = "PROJECTS";

/*
 * REQUIRED DB CHANGES
 * -------------------
 * The page tries to apply these itself the first time it loads (and remembers
 * that it succeeded). If your DB user is not allowed to ALTER, run them once by hand:
 *
 *   ALTER TABLE project_task ADD COLUMN attachment       VARCHAR(255) NULL;
 *   ALTER TABLE project_task ADD COLUMN assigned_student INT NULL;
 *   ALTER TABLE project_task ADD COLUMN review_comment   TEXT NULL;
 *   ALTER TABLE projects     ADD COLUMN facultyID        INT NULL;
 *   ALTER TABLE projects     ADD COLUMN project_type     VARCHAR(20) NOT NULL DEFAULT 'Group';
 *   ALTER TABLE projects     ADD COLUMN section          VARCHAR(50) NULL;
 *   ALTER TABLE project_info ADD COLUMN instructions     TEXT NULL;
 *   ALTER TABLE project_info ADD COLUMN group_message    TEXT NULL;
 *
 *   -- A project is now assigned to a block BEFORE its group exists, so the
 *   -- leader column must allow NULL. Keep the same type it has today, e.g.:
 *   ALTER TABLE projects MODIFY enrolled_subjectID INT NULL;
 *
 *   -- If `satatus` is an ENUM, also allow the 'review' value:
 *   ALTER TABLE project_task MODIFY satatus VARCHAR(20) NOT NULL DEFAULT 'todo';
 */
class ProjectsLogic
{
    const SCHEMA_MSG = 'The database needs a one-time update for the new Projects screens. Run the ALTER statements listed at the top of projects_logic.php, then reload.';

    /** Project types an instructor can assign. 'Group' is kept so older projects still work. */
    const TYPES_ASSIGNABLE = ['Solo', 'Duo'];
    const TYPES_WITH_TEAM  = ['Solo', 'Duo', 'Group'];

    public $group = 'student';
    public $error = '';

    public $projects = [];
    public $stats    = ['total' => 0, 'pending' => 0, 'submitted' => 0];
    public $overall  = 0;            // average progress across all listed projects (0-100)

    public $selected    = null;
    public $team        = [];
    public $tasks       = ['todo' => [], 'progress' => [], 'review' => [], 'done' => []];
    public $taskPercent = 0;         // done tasks / all tasks of the open project (0-100)
    public $submission  = null;
    public $isLead      = false;

    public $instructorName = '';
    public $studentOptions = [];
    public $groupProjects  = [];     // instructor's projects that still need a team
    public $sections       = [];     // blocks already used (suggestions for "Assign to")
    public $nextCode       = 'PRJ-001';

    private $db;
    private $studentID = 0;
    private $facultyID = 0;
    private $schemaOk  = false;
    private $hasTaskAttachment = null;

    private static $schema = [
        ['project_task', 'assigned_student', 'INT NULL'],
        ['project_task', 'review_comment',   'TEXT NULL'],
        ['projects',     'facultyID',        'INT NULL'],
        ['projects',     'project_type',     "VARCHAR(20) NOT NULL DEFAULT 'Group'"],
        ['projects',     'section',          'VARCHAR(50) NULL'],
        ['project_info', 'instructions',     'TEXT NULL'],
        ['project_info', 'group_message',    'TEXT NULL'],
    ];

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->db = self::connect();
        $this->resolveUser();
        $this->ensureSchema();
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

    /* ------------------------------------------------------------------
     *  Schema helpers
     * ------------------------------------------------------------------ */

    private function ensureSchema()
    {
        if (!empty($_SESSION['projects_schema_ok'])) {
            $this->schemaOk = true;
            return;
        }

        // If ALTER keeps failing, only retry it every 5 minutes (the cheap checks still run).
        $tried = (int)($_SESSION['projects_schema_tried'] ?? 0);
        $alter = (time() - $tried) > 300;

        try {
            $ok = true;
            foreach (self::$schema as $def) {
                if (!$this->ensureColumn($def[0], $def[1], $def[2], $alter)) $ok = false;
            }
            if (!$this->ensureLeadNullable($alter)) $ok = false;
            $this->schemaOk = $ok;
        } catch (Throwable $e) {
            $this->schemaOk = false;
        }

        if ($this->schemaOk)  $_SESSION['projects_schema_ok'] = true;
        elseif ($alter)       $_SESSION['projects_schema_tried'] = time();
    }

    private function columnRow($table, $column)
    {
        $q = $this->db->query("SHOW COLUMNS FROM `$table` LIKE " . $this->db->quote($column));
        return $q ? $q->fetch(PDO::FETCH_ASSOC) : false;
    }

    private function ensureColumn($table, $column, $ddl, $alter)
    {
        if ($this->columnRow($table, $column)) return true;
        if (!$alter) return false;

        try {
            $this->db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
        } catch (Throwable $e) {
            // fall through: re-check below
        }
        return (bool)$this->columnRow($table, $column);
    }

    /** projects.enrolled_subjectID used to be the team lead; a project now exists before its group does. */
    private function ensureLeadNullable($alter)
    {
        $row = $this->columnRow('projects', 'enrolled_subjectID');
        if (!$row) return false;
        if (strtoupper((string)$row['Null']) === 'YES') return true;
        if (!$alter) return false;

        try {
            // $row['Type'] comes straight from the database (e.g. "int(11)"), so the type is preserved.
            $this->db->exec('ALTER TABLE projects MODIFY enrolled_subjectID ' . $row['Type'] . ' NULL');
        } catch (Throwable $e) {
            // fall through: re-check below
        }
        $row = $this->columnRow('projects', 'enrolled_subjectID');
        return $row && strtoupper((string)$row['Null']) === 'YES';
    }

    /**
     * project_task.attachment may not exist yet. Try to add it once; if the DB user
     * is not allowed to ALTER, the page still works (without task attachments).
     */
    private function taskHasAttachment()
    {
        if ($this->hasTaskAttachment !== null) return $this->hasTaskAttachment;

        try {
            $this->hasTaskAttachment = (bool)$this->columnRow('project_task', 'attachment');
            if (!$this->hasTaskAttachment) {
                $this->db->exec("ALTER TABLE project_task ADD COLUMN attachment VARCHAR(255) NULL");
                $this->hasTaskAttachment = (bool)$this->columnRow('project_task', 'attachment');
            }
        } catch (Throwable $e) {
            $this->hasTaskAttachment = false;
        }
        return $this->hasTaskAttachment;
    }

    /* ------------------------------------------------------------------
     *  Request plumbing
     * ------------------------------------------------------------------ */

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

        if ($action === 'create_project') { $this->createProject(); return; }
        if ($action === 'create_group')   { $this->createGroup();   return; }
        if ($action === 'delete_project') { $this->deleteProject($projectID); return; }

        if ($this->group === 'student' && !$this->isMember($projectID)) {
            $this->error = 'You are not part of that project.';
            return;
        }
        if ($this->group === 'faculty' && !$this->isInstructorOf($projectID)) {
            $this->error = 'You do not have permission for that project.';
            return;
        }

        switch ($action) {
            case 'submit_project': $this->submitProject($projectID); break;
            case 'add_task':       $this->addTask($projectID);       break;
            case 'start_task':     $this->startTask($projectID);     break;
            case 'pass_task':      $this->passTask($projectID);      break;
            case 'approve_task':   $this->approveTask($projectID);   break;
            case 'decline_task':   $this->declineTask($projectID);   break;
        }
    }

    /* ------------------------------------------------------------------
     *  SQL fragments
     * ------------------------------------------------------------------ */

    private function profSql()
    {
        $viaSchedule = "(SELECT CONCAT('Prof. ', f.last_name)
                           FROM subject_schedules ss
                           JOIN faculty f ON f.facultyID = ss.facultyID
                          WHERE ss.enrollmentID = es.enrollmentID
                            AND ss.subjectID = es.subjectID
                          LIMIT 1)";
        $direct = $this->schemaOk
            ? "(SELECT CONCAT('Prof. ', f2.last_name) FROM faculty f2 WHERE f2.facultyID = p.facultyID)"
            : 'NULL';

        return "COALESCE($direct, $viaSchedule, '—')";
    }

    private function projectExtras()
    {
        return $this->schemaOk
            ? 'p.project_type, p.section'
            : "'Group' AS project_type, NULL AS section";
    }

    private static function taskCounts()
    {
        $done = "LOWER(TRIM(t.satatus)) IN ('done', 'completed', 'complete')";
        return "(SELECT COUNT(*) FROM project_task t WHERE t.projectID = p.projectID) AS task_total,
                (SELECT COUNT(*) FROM project_task t WHERE t.projectID = p.projectID AND NOT ($done)) AS task_open,
                (SELECT COUNT(*) FROM project_task t WHERE t.projectID = p.projectID AND $done) AS task_done";
    }

    /* ------------------------------------------------------------------
     *  Permissions
     * ------------------------------------------------------------------ */

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

        $own    = $this->schemaOk ? 'p.facultyID = ? OR ' : '';
        $params = $this->schemaOk
            ? [$projectID, $this->facultyID, $this->facultyID]
            : [$projectID, $this->facultyID];

        $st = $this->db->prepare(
            "SELECT 1
               FROM projects p
          LEFT JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
              WHERE p.projectID = ?
                AND ($own EXISTS (SELECT 1 FROM subject_schedules ss
                                   WHERE ss.enrollmentID = es.enrollmentID
                                     AND ss.subjectID = es.subjectID
                                     AND ss.facultyID = ?))"
        );
        $st->execute($params);
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

    /* ------------------------------------------------------------------
     *  Projects: assign (to a block) -> create group (leader + members)
     * ------------------------------------------------------------------ */

    private function createProject()
    {
        if ($this->group !== 'faculty' || $this->facultyID <= 0) {
            $this->error = 'Only instructors can assign projects.';
            return;
        }
        if (!$this->schemaOk) {
            $this->error = self::SCHEMA_MSG;
            return;
        }

        $name         = trim($_POST['name'] ?? '');
        $section      = trim($_POST['section'] ?? '');
        $deadline     = trim($_POST['deadline'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $type         = trim($_POST['project_type'] ?? '');

        $date = DateTime::createFromFormat('Y-m-d', $deadline);
        if ($name === '' || strlen($name) > 150)            { $this->error = 'Enter a project title (150 characters max).'; return; }
        if (!in_array($type, self::TYPES_ASSIGNABLE, true)) { $this->error = 'Choose a project type (Solo or Duo).'; return; }
        if ($section === '' || strlen($section) > 50)       { $this->error = 'Enter who the project is assigned to (e.g. BSCS - 2C).'; return; }
        if (!$date || $date->format('Y-m-d') !== $deadline) { $this->error = 'Choose a valid deadline date.'; return; }

        $instructionPath = $this->saveUpload('instruction_file', 'instructions');
        if ($instructionPath === false) {
            $this->error = 'That attachment type or size is not allowed (max 10 MB).';
            return;
        }

        try {
            $this->db->beginTransaction();

            $st = $this->db->prepare(
                "INSERT INTO projects (enrolled_subjectID, facultyID, name, deadline, project_type, section)
                 VALUES (NULL, ?, ?, ?, ?, ?)"
            );
            $st->execute([$this->facultyID, $name, $deadline . ' 23:59:59', $type, $section]);
            $projectID = (int)$this->db->lastInsertId();

            $st = $this->db->prepare(
                "INSERT INTO project_info (projectID, instruction_file, instructions) VALUES (?, ?, ?)"
            );
            $st->execute([$projectID, $instructionPath, $instructions !== '' ? $instructions : null]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->error = 'Could not create the project. Please try again.';
            return;
        }

        $this->flash('success', 'Successfully added');
        $this->redirect();
    }

    private function createGroup()
    {
        if ($this->group !== 'faculty' || $this->facultyID <= 0) {
            $this->error = 'Only instructors can create groups.';
            return;
        }
        if (!$this->schemaOk) {
            $this->error = self::SCHEMA_MSG;
            return;
        }

        $projectID = (int)($_POST['project_id'] ?? 0);
        $leader    = (int)($_POST['leader'] ?? 0);
        $members   = array_map('intval', (array)($_POST['members'] ?? []));
        $members   = array_values(array_diff(array_unique(array_filter($members)), [$leader]));
        $message   = trim($_POST['message'] ?? '');

        if ($projectID <= 0)                    { $this->error = 'Choose a project for this group.'; return; }
        if (!$this->isInstructorOf($projectID)) { $this->error = 'You do not have permission for that project.'; return; }
        if ($leader <= 0)                       { $this->error = 'Choose a team leader.'; return; }

        $st = $this->db->prepare("SELECT project_type, enrolled_subjectID FROM projects WHERE projectID = ?");
        $st->execute([$projectID]);
        $project = $st->fetch(PDO::FETCH_ASSOC);
        if (!$project) { $this->error = 'Project not found.'; return; }

        $type = $project['project_type'];
        if (!in_array($type, self::TYPES_WITH_TEAM, true))  { $this->error = 'This project type cannot have a team.'; return; }
        if ($project['enrolled_subjectID'] !== null)       { $this->error = 'This project already has a group.'; return; }

        if ($type === 'Solo' && count($members) !== 0) { $this->error = 'A Solo project has only one student. Remove the extra members.'; return; }
        if ($type === 'Duo'  && count($members) !== 1) { $this->error = 'A Duo project needs exactly one member besides the leader.'; return; }

        $picked = array_merge([$leader], $members);
        $in     = implode(',', array_fill(0, count($picked), '?'));

        $st = $this->db->prepare(
            "SELECT es.enrolled_subjectID, es.studentID
               FROM enrolled_subjects es
              WHERE es.enrolled_subjectID IN ($in)"
        );
        $st->execute($picked);
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[(int)$r['enrolled_subjectID']] = (int)$r['studentID'];
        }

        if (count($found) !== count($picked)) {
            $this->error = 'One of the selected students is invalid.';
            return;
        }

        $leadEnrolled = $leader;
        $leadStudent  = $found[$leadEnrolled];

        try {
            $this->db->beginTransaction();

            $st = $this->db->prepare("UPDATE projects SET enrolled_subjectID = ? WHERE projectID = ?");
            $st->execute([$leadEnrolled, $projectID]);

            $st = $this->db->prepare("INSERT IGNORE INTO project_members (projectID, studentID) VALUES (?, ?)");
            foreach ($found as $studentID) {
                if ($studentID !== $leadStudent) $st->execute([$projectID, $studentID]);
            }

            $note = $message !== '' ? $message : null;
            $st = $this->db->prepare("SELECT 1 FROM project_info WHERE projectID = ?");
            $st->execute([$projectID]);
            if ($st->fetchColumn()) {
                $st = $this->db->prepare("UPDATE project_info SET group_message = ? WHERE projectID = ?");
                $st->execute([$note, $projectID]);
            } else {
                $st = $this->db->prepare("INSERT INTO project_info (projectID, group_message) VALUES (?, ?)");
                $st->execute([$projectID, $note]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->error = 'Could not create the group. Please try again.';
            return;
        }

        $this->flash('success', 'Successfully added');
        $this->redirect();
    }

    private function deleteProject($projectID)
    {
        if ($this->group !== 'faculty') {
            $this->error = 'Only instructors can delete projects.';
            return;
        }

        if (!$this->isInstructorOf($projectID)) {
            $this->error = 'You do not have permission to delete this project.';
            return;
        }

        try {
            $this->db->beginTransaction();

            $tables = ['project_task', 'project_submission', 'project_members', 'project_info'];
            foreach ($tables as $table) {
                $st = $this->db->prepare("DELETE FROM $table WHERE projectID = ?");
                $st->execute([$projectID]);
            }

            $st = $this->db->prepare("DELETE FROM projects WHERE projectID = ?");
            $st->execute([$projectID]);

            $this->db->commit();
            $this->flash('success', 'Project deleted successfully.');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->error = 'Failed to delete the project.';
        }
    }

    /* ------------------------------------------------------------------
     *  Tasks:  To Do --start--> In Progress --pass (file)--> Review
     *          Review --approve (instructor)--> Done
     *          Review --decline (instructor)--> In Progress
     * ------------------------------------------------------------------ */

    private function addTask($projectID)
    {
        if ($this->group !== 'faculty') {
            $this->error = 'Only the instructor can add tasks.';
            return;
        }

        $title    = trim($_POST['task_title'] ?? '');
        $deadline = trim($_POST['task_deadline'] ?? '');
        $assignee = (int)($_POST['task_student'] ?? 0);

        $date = DateTime::createFromFormat('Y-m-d', $deadline);
        if ($title === '') {
            $this->error = 'Task title cannot be empty.';
            return;
        }
        if (!$date || $date->format('Y-m-d') !== $deadline) {
            $this->error = 'Choose a valid deadline date for the task.';
            return;
        }

        $teamIds = array_map(function ($r) { return (int)$r['studentID']; }, $this->fetchTeam($projectID));
        if ($teamIds && !in_array($assignee, $teamIds, true)) {
            $this->error = 'Choose a student from the team for this task.';
            return;
        }
        if (!$teamIds) $assignee = 0;

        if ($this->schemaOk) {
            $st = $this->db->prepare(
                "INSERT INTO project_task (projectID, task_title, deadline, satatus, assigned_student)
                 VALUES (?, ?, ?, 'todo', ?)"
            );
            $st->execute([$projectID, $title, $deadline . ' 23:59:59', $assignee > 0 ? $assignee : null]);
        } else {
            $st = $this->db->prepare(
                "INSERT INTO project_task (projectID, task_title, deadline, satatus)
                 VALUES (?, ?, ?, 'todo')"
            );
            $st->execute([$projectID, $title, $deadline . ' 23:59:59']);
        }

        $this->flash('success', 'Successfully added');
        $this->redirect(['project' => $projectID]);
    }

    /** Returns the task's current board column, or null if the task isn't in this project. */
    private function taskState($projectID, $taskID)
    {
        $st = $this->db->prepare("SELECT satatus FROM project_task WHERE taskID = ? AND projectID = ?");
        $st->execute([$taskID, $projectID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::taskColumn($row['satatus']) : null;
    }

    private function startTask($projectID)
    {
        $taskID = (int)($_POST['task_id'] ?? 0);

        if ($this->group !== 'student') {
            $this->error = 'Only team members can start tasks.';
            return;
        }
        if ($this->taskState($projectID, $taskID) !== 'todo') {
            $this->error = 'That task can no longer be started.';
            return;
        }

        $st = $this->db->prepare("UPDATE project_task SET satatus = 'progress' WHERE taskID = ? AND projectID = ?");
        $st->execute([$taskID, $projectID]);

        $this->flash('success', 'Task moved to In Progress.');
        $this->redirect(['project' => $projectID]);
    }

    private function passTask($projectID)
    {
        $taskID = (int)($_POST['task_id'] ?? 0);

        if ($this->group !== 'student') {
            $this->error = 'Only team members can pass tasks for review.';
            return;
        }
        if ($this->taskState($projectID, $taskID) !== 'progress') {
            $this->error = 'Only tasks that are in progress can be passed for review.';
            return;
        }

        if (!$this->taskHasAttachment()) {
            $this->error = 'Task attachments are not set up yet. Run: ALTER TABLE project_task ADD COLUMN attachment VARCHAR(255) NULL;';
            return;
        }

        $path = $this->saveUpload('attachment', 'tasks');
        if ($path === null) {
            $this->error = 'Attach a screenshot or file before passing the task.';
            return;
        }
        if ($path === false) {
            $this->error = 'That file type or size is not allowed (max 10 MB).';
            return;
        }

        $st = $this->db->prepare(
            "UPDATE project_task SET satatus = 'review', attachment = ? WHERE taskID = ? AND projectID = ?"
        );
        $st->execute([$path, $taskID, $projectID]);

        $this->flash('success', 'Task passed to review.');
        $this->redirect(['project' => $projectID]);
    }

    private function approveTask($projectID)
    {
        $this->reviewTask($projectID, 'done', 'approve', 'Task approved and marked as done.');
    }

    private function declineTask($projectID)
    {
        $this->reviewTask($projectID, 'progress', 'decline', 'Task sent back to In Progress for revision.');
    }

    /** Shared by approve/decline: both can carry the instructor's comment. */
    private function reviewTask($projectID, $status, $verb, $message)
    {
        $taskID  = (int)($_POST['task_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($this->group !== 'faculty' || !$this->isInstructorOf($projectID)) {
            $this->error = "Only the instructor can $verb tasks.";
            return;
        }
        if ($this->taskState($projectID, $taskID) !== 'review') {
            $this->error = "Only tasks in review can be {$verb}d.";
            return;
        }

        if ($this->schemaOk) {
            $st = $this->db->prepare(
                "UPDATE project_task SET satatus = ?, review_comment = ? WHERE taskID = ? AND projectID = ?"
            );
            $st->execute([$status, $comment !== '' ? $comment : null, $taskID, $projectID]);
        } else {
            $st = $this->db->prepare("UPDATE project_task SET satatus = ? WHERE taskID = ? AND projectID = ?");
            $st->execute([$status, $taskID, $projectID]);
        }

        $this->flash('success', $message);
        $this->redirect(['project' => $projectID]);
    }

    /**
     * Saves an uploaded file under uploads/<subdir>/.
     * Returns the relative path, null when nothing was uploaded, false when rejected.
     */
    private function saveUpload($field, $subdir)
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK) return false;

        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true) || $f['size'] > 10 * 1024 * 1024) return false;

        $dir = __DIR__ . '/../uploads/' . $subdir . '/';
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $base   = preg_replace('/[^a-zA-Z0-9\-_]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $unique = bin2hex(random_bytes(6)) . '_' . substr($base, 0, 60) . '.' . $ext;

        if (!move_uploaded_file($f['tmp_name'], $dir . $unique)) return false;
        return 'uploads/' . $subdir . '/' . $unique;
    }

    private function submitProject($projectID)
    {
        if (!$this->isLeadOf($projectID)) {
            $this->error = 'Only the team lead can submit this project.';
            return;
        }

        $file  = trim($_POST['file_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $attachmentPath = $this->saveUpload('attachment', 'projects');
        if ($attachmentPath === false) {
            $this->error = 'That file type or size is not allowed (max 10 MB).';
            return;
        }
        if ($attachmentPath === null) {
            $st = $this->db->prepare("SELECT attachment FROM project_submission WHERE projectID = ?");
            $st->execute([$projectID]);
            $attachmentPath = $st->fetchColumn() ?: null;
        }

        if (empty($attachmentPath)) {
            $this->error = 'Please attach a file before submitting.';
            return;
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
        $this->redirect();
    }

    private static function taskColumn($value)
    {
        $v = strtolower(trim((string)$value));
        if (in_array($v, ['done', 'completed', 'complete'], true)) return 'done';
        if (in_array($v, ['review', 'in review', 'in_review'], true)) return 'review';
        if (in_array($v, ['progress', 'in progress', 'in_progress', 'ongoing'], true)) return 'progress';
        return 'todo';
    }

    /* ------------------------------------------------------------------
     *  Loading
     * ------------------------------------------------------------------ */

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

    /** Adds a 0-100 `progress` to every project and the overall average. */
    private function decorateProgress()
    {
        $sum = 0;
        foreach ($this->projects as &$p) {
            $total = (int)$p['task_total'];
            $done  = (int)$p['task_done'];
            $pct   = ((int)$p['submitted'] === 1) ? 100 : ($total > 0 ? (int)round($done / $total * 100) : 0);
            $p['progress'] = $pct;
            $sum += $pct;
        }
        unset($p);

        $n = count($this->projects);
        $this->overall = $n > 0 ? (int)round($sum / $n) : 0;
    }

    private function loadInstructor()
    {
        if ($this->facultyID <= 0) return;

        $own    = $this->schemaOk ? 'p.facultyID = ? OR ' : '';
        $params = $this->schemaOk ? [$this->facultyID, $this->facultyID] : [$this->facultyID];

        $st = $this->db->prepare(
            "SELECT p.projectID AS id,
                    CONCAT('PRJ-', LPAD(p.projectID, 3, '0')) AS code,
                    p.name, p.deadline,
                    " . $this->projectExtras() . ",
                    (p.enrolled_subjectID IS NULL) AS no_group,
                    sub.file_url, sub.attachment, sub.notes,
                    (sub.id IS NOT NULL) AS submitted,
                    " . self::taskCounts() . "
               FROM projects p
          LEFT JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
          LEFT JOIN project_submission sub ON sub.projectID = p.projectID
              WHERE ($own EXISTS (SELECT 1 FROM subject_schedules ss
                                   WHERE ss.enrollmentID = es.enrollmentID
                                     AND ss.subjectID = es.subjectID
                                     AND ss.facultyID = ?))
           ORDER BY p.projectID"
        );
        $st->execute($params);
        $this->projects = $st->fetchAll(PDO::FETCH_ASSOC);

        $submitted = 0;
        foreach ($this->projects as &$p) {
            $p['assigned_by'] = $this->instructorName;
            if ((int)$p['submitted'] === 1) $submitted++;
        }
        unset($p);

        $total = count($this->projects);
        $this->stats = ['total' => $total, 'submitted' => $submitted, 'pending' => $total - $submitted];
        $this->decorateProgress();

        $this->groupProjects = array_values(array_filter($this->projects, function ($p) {
            return (int)$p['no_group'] === 1
                && in_array($p['project_type'] ?? '', self::TYPES_WITH_TEAM, true);
        }));
        $this->sections = array_values(array_unique(array_filter(array_column($this->projects, 'section'))));

        $st = $this->db->prepare(
            "SELECT MIN(es.enrolled_subjectID) AS enrolled_subjectID,
                    GROUP_CONCAT(DISTINCT es.subjectID SEPARATOR ', ') AS subjectID,
                    s.first_name, s.last_name
               FROM enrolled_subjects es
               JOIN students s ON s.studentID = es.studentID
           GROUP BY s.studentID, s.first_name, s.last_name
           ORDER BY s.last_name, s.first_name"
        );
        $st->execute();
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
                    " . $this->projectExtras() . ",
                    " . $this->profSql() . " AS assigned_by,
                    sub.file_url, sub.attachment, sub.notes,
                    (sub.id IS NOT NULL) AS submitted,
                    " . self::taskCounts() . "
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
        $this->decorateProgress();
    }

    private function fetchTeam($projectID)
    {
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
        return $st->fetchAll(PDO::FETCH_ASSOC);
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

        $instructions = $this->schemaOk ? 'i.instructions' : 'NULL AS instructions';

        $st = $this->db->prepare(
            "SELECT p.projectID AS id,
                    CONCAT('PRJ-', LPAD(p.projectID, 3, '0')) AS code,
                    p.name, p.deadline,
                    " . $this->projectExtras() . ",
                    " . $this->profSql() . " AS assigned_by,
                    i.instruction_file, $instructions
               FROM projects p
          LEFT JOIN enrolled_subjects es ON es.enrolled_subjectID = p.enrolled_subjectID
          LEFT JOIN project_info i ON i.projectID = p.projectID
              WHERE p.projectID = ?"
        );
        $st->execute([$projectID]);
        $this->selected = $st->fetch(PDO::FETCH_ASSOC);

        $this->isLead = $this->group === 'student' && $this->isLeadOf($projectID);
        $this->team   = $this->fetchTeam($projectID);

        $cols = "t.taskID AS id, t.task_title AS title, t.satatus AS status, t.deadline, "
              . ($this->taskHasAttachment() ? 't.attachment' : 'NULL AS attachment') . ', '
              . ($this->schemaOk
                    ? "t.review_comment, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS assignee"
                    : 'NULL AS review_comment, NULL AS assignee');
        $join = $this->schemaOk ? 'LEFT JOIN students s ON s.studentID = t.assigned_student' : '';

        $st = $this->db->prepare(
            "SELECT $cols
               FROM project_task t
               $join
              WHERE t.projectID = ?
           ORDER BY t.taskID"
        );
        $st->execute([$projectID]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $this->tasks[self::taskColumn($task['status'])][] = $task;
        }

        $total = 0;
        foreach ($this->tasks as $col) $total += count($col);
        $this->taskPercent = $total > 0 ? (int)round(count($this->tasks['done']) / $total * 100) : 0;

        $st = $this->db->prepare("SELECT * FROM project_submission WHERE projectID = ?");
        $st->execute([$projectID]);
        $this->submission = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}