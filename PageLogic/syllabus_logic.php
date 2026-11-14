<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../Services/csrf.php';
require_once __DIR__ . '/../PDAO/DAO.php';

date_default_timezone_set('Asia/Manila');
$_SESSION["page"] = "SYLLABUS";

/*
========================================================
  SYLLABUS TRACKER LOGIC
  - Students / coordinators / dean: read-only view
  - Professors: manage modules + tasks of THEIR OWN classes
  - Overall completion = completed tasks / all tasks
    (same formula per module for the small module bars)
========================================================
*/
class SyllabusLogic
{
    private const STATUSES = ['not_started', 'in_progress', 'completed'];

    private PDO $pdo;
    public string $role;
    public int $userID;
    public bool $isProf;

    public function __construct()
    {
        global $db_connection;
        $this->pdo    = $db_connection;
        $this->role   = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $this->userID = (int)($_SESSION['real_userID'] ?? 0);
        $this->isProf = ($this->role === 'prof');
    }

    private function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private function value(string $sql, array $params = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    private function run(string $sql, array $params = []): void
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    /* ---------------------------------------------------------
       CLASS LIST (feeds the "course - year/section" dropdown)
       prof     -> classes they teach
       student  -> classes of their own program + block they are enrolled in
       others   -> every class (read-only)
    --------------------------------------------------------- */
    public function classes(): array
    {
        $join   = '';
        $where  = '';
        $params = [];

        if ($this->isProf) {
            $where = ' WHERE ss.facultyID = :fid';
            $params[':fid'] = $this->userID;
        } elseif ($this->role === 'student') {
            $join  = ' JOIN students st ON st.studentID = :sid AND st.program = ep.program AND st.block = ss.block';
            $where = " WHERE EXISTS (SELECT 1 FROM enrolled_subjects es
                                      WHERE es.studentID = :sid2
                                        AND es.enrollmentID = ss.enrollmentID
                                        AND es.subjectID = ss.subjectID
                                        AND es.status = 'approved')";
            $params[':sid']  = $this->userID;
            $params[':sid2'] = $this->userID;
        }

        $rows = $this->all(
            "SELECT ss.schedID, ss.facultyID, ss.subjectID, TRIM(s.name) AS subject_name,
                    ss.block, ep.program, ep.acad_year, ep.sem
               FROM subject_schedules ss
               JOIN subjects s ON s.subjectID = ss.subjectID
               JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID{$join}{$where}
              ORDER BY ep.acad_year DESC, ep.sem DESC, ep.program, ss.block, ss.subjectID",
            $params
        );

        foreach ($rows as &$r) {
            $r['label'] = $r['program'] . ' - ' . $r['block'] . ' | ' . trim($r['subjectID']) . ' - ' . $r['subject_name'];
        }
        unset($r);

        return $rows;
    }

    public function selectedClass(array $classes): ?array
    {
        $want = (int)($_GET['class'] ?? 0);
        foreach ($classes as $c) {
            if ((int)$c['schedID'] === $want) return $c;
        }
        return $classes[0] ?? null;
    }

    public function canEdit(array $class): bool
    {
        return $this->isProf && (int)$class['facultyID'] === $this->userID;
    }

    /* ---------------------------------------------------------
       TRACKER DATA + COMPUTATIONS
    --------------------------------------------------------- */
    public function tracker(int $schedID): array
    {
        $today = date('Y-m-d');
        $soon  = date('Y-m-d', strtotime('+7 days'));

        $modules = $this->all(
            "SELECT moduleID, title FROM syllabus_modules
              WHERE schedID = :s ORDER BY sort_order, moduleID",
            [':s' => $schedID]
        );

        $tasks = $this->all(
            "SELECT t.taskID, t.moduleID, t.title, t.week_no, t.due_date, t.status
               FROM syllabus_tasks t
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s
              ORDER BY (t.week_no IS NULL), t.week_no, t.sort_order, t.taskID",
            [':s' => $schedID]
        );

        $byModule = [];
        foreach ($tasks as $t) {
            $view = 'not-started';
            if ($t['status'] === 'completed')        $view = 'completed';
            elseif ($t['status'] === 'in_progress')  $view = 'progress';
            elseif ($t['due_date'] && $t['due_date'] >= $today && $t['due_date'] <= $soon) $view = 'upcoming';

            $t['view']       = $view;
            $t['week_label'] = $t['week_no'] !== null ? 'Week ' . (int)$t['week_no'] : 'Task';
            $t['overdue']    = ($t['status'] !== 'completed' && $t['due_date'] && $t['due_date'] < $today);
            $byModule[$t['moduleID']][] = $t;
        }

        $sum = [
            'total' => 0, 'done' => 0, 'progress' => 0, 'remaining' => 0, 'pct' => 0,
            'overdue' => 0, 'current' => null, 'next' => null,
        ];
        $firstProgress = null;
        $firstOpen     = null;

        foreach ($modules as &$m) {
            $m['tasks'] = $byModule[$m['moduleID']] ?? [];
            $m['total'] = count($m['tasks']);
            $m['done']  = 0;

            foreach ($m['tasks'] as $t) {
                $t['module_title'] = $m['title'];
                $sum['total']++;

                if ($t['status'] === 'completed') {
                    $m['done']++;
                    $sum['done']++;
                    continue;
                }
                if ($t['status'] === 'in_progress') {
                    $sum['progress']++;
                    if ($firstProgress === null) $firstProgress = $t;
                }
                if ($firstOpen === null) $firstOpen = $t;

                if ($t['overdue']) $sum['overdue']++;
                if ($t['due_date'] && $t['due_date'] >= $today
                    && ($sum['next'] === null || $t['due_date'] < $sum['next']['due_date'])) {
                    $sum['next'] = $t;
                }
            }
            $m['pct'] = $m['total'] > 0 ? (int)round($m['done'] / $m['total'] * 100) : 0;
        }
        unset($m);

        $sum['remaining'] = $sum['total'] - $sum['done'] - $sum['progress'];
        $sum['pct']       = $sum['total'] > 0 ? (int)round($sum['done'] / $sum['total'] * 100) : 0;
        $sum['current']   = $firstProgress ?? $firstOpen;

        if ($sum['next'] !== null) {
            $sum['next']['days_left'] = (int)(new DateTime($today))->diff(new DateTime($sum['next']['due_date']))->days;
        }

        return ['modules' => $modules, 'summary' => $sum];
    }

    /* ---------------------------------------------------------
       POST ACTIONS (JSON). Only the professor who owns the class.
    --------------------------------------------------------- */
    private function reply(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }

    private function fail(string $msg, int $code = 400): void
    {
        $this->reply(['ok' => false, 'error' => $msg], $code);
    }

    private function intParam(string $key): int
    {
        return (int)($_POST[$key] ?? 0);
    }

    private function titleParam(string $key, int $max): string
    {
        $v = trim((string)($_POST[$key] ?? ''));
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        if ($v === '') $this->fail('Title is required.');
        if (mb_strlen($v) > $max) $this->fail("Title is too long (max {$max} characters).");
        return $v;
    }

    private function weekParam(): ?int
    {
        $raw = trim((string)($_POST['week_no'] ?? ''));
        if ($raw === '') return null;
        if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 52) $this->fail('Week must be a number from 1 to 52.');
        return (int)$raw;
    }

    private function dateParam(): ?string
    {
        $raw = trim((string)($_POST['due_date'] ?? ''));
        if ($raw === '') return null;
        $d = DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) $this->fail('Invalid due date.');
        return $raw;
    }

    private function ownsSched(int $schedID): bool
    {
        return (bool)$this->value(
            "SELECT 1 FROM subject_schedules WHERE schedID = :s AND facultyID = :f",
            [':s' => $schedID, ':f' => $this->userID]
        );
    }

    private function requireOwnsModule(int $moduleID): void
    {
        $sched = $this->value("SELECT schedID FROM syllabus_modules WHERE moduleID = :m", [':m' => $moduleID]);
        if ($sched === false || !$this->ownsSched((int)$sched)) $this->fail('You cannot edit this module.', 403);
    }

    private function requireOwnsTask(int $taskID): void
    {
        $sched = $this->value(
            "SELECT m.schedID FROM syllabus_tasks t JOIN syllabus_modules m ON m.moduleID = t.moduleID WHERE t.taskID = :t",
            [':t' => $taskID]
        );
        if ($sched === false || !$this->ownsSched((int)$sched)) $this->fail('You cannot edit this task.', 403);
    }

    public function handlePost(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->isProf)                                  $this->fail('Only the class instructor can edit the syllabus.', 403);
        if (!csrfValid($_POST['csrf_token'] ?? null))        $this->fail('Session expired. Please refresh the page.', 419);

        $action = (string)($_POST['action'] ?? '');

        try {
            switch ($action) {
                case 'add_module':
                    $sched = $this->intParam('schedID');
                    if (!$this->ownsSched($sched)) $this->fail('You cannot edit this class.', 403);
                    $title = $this->titleParam('title', 200);
                    $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_modules WHERE schedID = :s", [':s' => $sched]);
                    $this->run("INSERT INTO syllabus_modules (schedID, title, sort_order) VALUES (:s, :t, :o)", [':s' => $sched, ':t' => $title, ':o' => $next]);
                    break;

                case 'rename_module':
                    $id = $this->intParam('moduleID');
                    $this->requireOwnsModule($id);
                    $this->run("UPDATE syllabus_modules SET title = :t WHERE moduleID = :m", [':t' => $this->titleParam('title', 200), ':m' => $id]);
                    break;

                case 'delete_module':
                    $id = $this->intParam('moduleID');
                    $this->requireOwnsModule($id);
                    $this->pdo->beginTransaction();
                    $this->run("DELETE FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);
                    $this->run("DELETE FROM syllabus_modules WHERE moduleID = :m", [':m' => $id]);
                    $this->pdo->commit();
                    break;

                case 'add_task':
                    $id = $this->intParam('moduleID');
                    $this->requireOwnsModule($id);
                    $title = $this->titleParam('title', 250);
                    $week  = $this->weekParam();
                    $due   = $this->dateParam();
                    $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);
                    $this->run(
                        "INSERT INTO syllabus_tasks (moduleID, title, week_no, due_date, sort_order) VALUES (:m, :t, :w, :d, :o)",
                        [':m' => $id, ':t' => $title, ':w' => $week, ':d' => $due, ':o' => $next]
                    );
                    break;

                case 'edit_task':
                    $id = $this->intParam('taskID');
                    $this->requireOwnsTask($id);
                    $this->run(
                        "UPDATE syllabus_tasks SET title = :t, week_no = :w, due_date = :d WHERE taskID = :id",
                        [':t' => $this->titleParam('title', 250), ':w' => $this->weekParam(), ':d' => $this->dateParam(), ':id' => $id]
                    );
                    break;

                case 'set_status':
                    $id     = $this->intParam('taskID');
                    $status = (string)($_POST['status'] ?? '');
                    if (!in_array($status, self::STATUSES, true)) $this->fail('Invalid status.');
                    $this->requireOwnsTask($id);
                    $this->run(
                        "UPDATE syllabus_tasks SET status = :s, completed_at = " . ($status === 'completed' ? 'NOW()' : 'NULL') . " WHERE taskID = :id",
                        [':s' => $status, ':id' => $id]
                    );
                    break;

                case 'delete_task':
                    $id = $this->intParam('taskID');
                    $this->requireOwnsTask($id);
                    $this->run("DELETE FROM syllabus_tasks WHERE taskID = :id", [':id' => $id]);
                    break;

                default:
                    $this->fail('Unknown action.');
            }
        } catch (PDOException $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('Syllabus action failed: ' . $ex->getMessage());
            $this->fail('Database error. Did you import syllabus_tracker.sql?', 500);
        }

        $this->reply(['ok' => true]);
    }
}

// POST /syllabus is routed straight to this file; handle the action and stop.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    (new SyllabusLogic())->handlePost();
}