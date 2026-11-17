<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../Services/csrf.php';
require_once __DIR__ . '/../PDAO/DAO.php';

date_default_timezone_set('Asia/Manila');
$_SESSION["page"] = "SYLLABUS";

/*
========================================================
  SYLLABUS TRACKER LOGIC

  Who sees what
    student                      -> own progress (Not Started / In Progress / Completed / Upcoming)
    prof                         -> manages the classes they teach, sees class-wide progress
    program coordinator / dean   -> manage every class
    anyone else (e.g. registrar) -> read-only

  Topic lifecycle for a STUDENT
    Upcoming     the professor set an "opens on" date that is still in the future (locked)
    Not Started  the student has not opened the topic
    In Progress  the student opened it
    Completed    the student passed the checkpoint (mock exam if there is one, else mini quiz),
                 or - when the topic has no quiz - marked it as finished
    A failed quiz/exam forces the student to study again: retake is locked until the cooldown
    passed AND (when the topic has PDF/video materials) at least one material was opened again.
========================================================
*/
class SyllabusLogic
{
    private const KINDS      = ['pdf', 'video', 'note'];
    private const QUIZ_KINDS = ['quiz', 'exam'];

    // Allowed uploads. Real limits are also capped by php.ini (upload_max_filesize / post_max_size).
    private const FILE_RULES = [
        'pdf'   => ['ext' => ['pdf'],         'mime' => ['application/pdf'],                         'max' => 52428800],   // 50 MB
        'video' => ['ext' => ['mp4', 'webm'], 'mime' => ['video/mp4', 'video/x-m4v', 'video/webm'],  'max' => 524288000],  // 500 MB
        'image' => ['ext' => ['png', 'jpg', 'jpeg', 'gif', 'webp'], 'mime' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], 'max' => 5242880],  // 5 MB (pictures inside lesson pages)
    ];
    private const MIME_BY_EXT = [
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];
    private const STORED_RE = '/^[a-f0-9]{32}\.(pdf|mp4|webm|png|jpe?g|gif|webp)$/';

    private PDO $pdo;
    public string $role;
    public int $userID;
    public bool $isProf;
    public bool $isManager;
    public bool $isFaculty;
    public bool $isStudent;

    public function __construct()
    {
        global $db_connection;
        $this->pdo       = $db_connection;
        $this->role      = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $this->userID    = (int)($_SESSION['real_userID'] ?? 0);
        $this->isProf    = ($this->role === 'prof');
        $this->isManager = in_array($this->role, ['program coordinator', 'dean'], true);
        $this->isFaculty = $this->isProf || $this->isManager;
        $this->isStudent = ($this->role === 'student');
    }

    /* ---------- small helpers ---------- */
    private function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
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

    public function today(): string
    {
        return date('Y-m-d');
    }

    private function nowStr(): string
    {
        return date('Y-m-d H:i:s');
    }

    /* ---------- storage ---------- */
    public static function storageDir(): string
    {
        return rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/') . '/storage/syllabus';
    }

    private static function ensureStorage(): string
    {
        $dir = self::storageDir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($ht)) {
            @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    private function removeFiles(array $storedNames): void
    {
        foreach ($storedNames as $n) {
            if (is_string($n) && preg_match(self::STORED_RE, $n)) {
                @unlink(self::storageDir() . '/' . $n);
            }
        }
    }

    /* ---------------------------------------------------------
       CLASS LIST (feeds the "course - year/section" dropdown)
       prof     -> classes they teach
       student  -> classes of their own program + block they are enrolled in
       others   -> every class
    --------------------------------------------------------- */
    public function classes(): array
    {
        $join   = '';
        $where  = '';
        $params = [];

        if ($this->isProf) {
            $where = ' WHERE ss.facultyID = :fid';
            $params[':fid'] = $this->userID;
        } elseif ($this->isStudent) {
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

    /** Students enrolled (approved) in a class. */
    public function roster(int $schedID): array
    {
        $rows = $this->all(
            "SELECT st.studentID, st.first_name, st.middle_name, st.last_name
               FROM subject_schedules ss
               JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
               JOIN students st ON st.program = ep.program AND st.block = ss.block
              WHERE ss.schedID = :s
                AND EXISTS (SELECT 1 FROM enrolled_subjects es
                             WHERE es.studentID = st.studentID
                               AND es.enrollmentID = ss.enrollmentID
                               AND es.subjectID = ss.subjectID
                               AND es.status = 'approved')
              ORDER BY st.last_name, st.first_name",
            [':s' => $schedID]
        );
        foreach ($rows as &$r) {
            $r['name'] = mb_convert_case(trim($r['last_name'] . ', ' . $r['first_name']), MB_CASE_TITLE, 'UTF-8');
        }
        unset($r);
        return $rows;
    }

    /* ---------- permissions ---------- */
    /** May this user edit this class's syllabus? */
    public function canEdit(array $class): bool
    {
        return $this->canManageSched((int)$class['schedID']);
    }

    public function canManageClass(int $schedID): bool
    {
        return $this->canManageSched($schedID);
    }

    private function profOwns(int $schedID): bool
    {
        return (bool)$this->value(
            "SELECT 1 FROM subject_schedules WHERE schedID = :s AND facultyID = :f",
            [':s' => $schedID, ':f' => $this->userID]
        );
    }

    private function canManageSched(int $schedID): bool
    {
        if ($this->isProf) return $this->profOwns($schedID);
        if ($this->isManager) {
            return (bool)$this->value("SELECT 1 FROM subject_schedules WHERE schedID = :s", [':s' => $schedID]);
        }
        return false;
    }

    private function canViewClass(int $schedID): bool
    {
        if ($this->isProf) return $this->profOwns($schedID);
        if ($this->isStudent) {
            foreach ($this->classes() as $c) {
                if ((int)$c['schedID'] === $schedID) return true;
            }
            return false;
        }
        return true;   // coordinator, dean and others can look at any class
    }

    /** Content row for the file endpoint, or null when this user must not get it. */
    public function contentForServing(int $contentID): ?array
    {
        $row = $this->one(
            "SELECT c.*, t.available_from, m.schedID
               FROM syllabus_contents c
               JOIN syllabus_tasks t ON t.taskID = c.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE c.contentID = :c",
            [':c' => $contentID]
        );
        if (!$row) return null;
        $sched = (int)$row['schedID'];
        if (!$this->canViewClass($sched)) return null;
        $manager = $this->canManageSched($sched);
        if ((int)$row['is_published'] !== 1 && !$manager) return null;
        if ($row['available_from'] && $row['available_from'] > $this->today() && !$manager) return null;   // topic still locked
        return $row;
    }

    /** Image row for the file endpoint, or null when this user must not get it. */
    public function imageForServing(int $imageID): ?array
    {
        $row = $this->one(
            "SELECT i.imageID, i.stored_name, i.file_name, i.mime_type, t.available_from, m.schedID
               FROM syllabus_images i
               JOIN syllabus_tasks t ON t.taskID = i.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE i.imageID = :i",
            [':i' => $imageID]
        );
        if (!$row || !$this->canViewClass((int)$row['schedID'])) return null;
        if ($row['available_from'] && $row['available_from'] > $this->today() && !$this->canManageSched((int)$row['schedID'])) return null;
        return $row;
    }

    /** A student opened a PDF/video: counts toward the "study again" requirement after a failed quiz. */
    public function noteMaterialOpened(int $taskID): void
    {
        if (!$this->isStudent) return;
        $this->run(
            "UPDATE syllabus_progress SET restudy_hits = restudy_hits + 1
              WHERE studentID = :s AND taskID = :t AND needs_restudy = 1",
            [':s' => $this->userID, ':t' => $taskID]
        );
    }

    /* ---------------------------------------------------------
       TRACKER DATA + COMPUTATIONS
       $mode: 'staff'    faculty who can edit this class (class-wide progress)
              'student'  a student (their own progress)
              'preview'  faculty previewing the student view (no progress)
              'observer' anyone else (no progress)
    --------------------------------------------------------- */
    public function tracker(int $schedID, string $mode = 'staff'): array
    {
        $staff = ($mode === 'staff');
        $today = $this->today();

        $modules = $this->all(
            "SELECT moduleID, title FROM syllabus_modules
              WHERE schedID = :s ORDER BY sort_order, moduleID",
            [':s' => $schedID]
        );

        $tasks = $this->all(
            "SELECT t.taskID, t.moduleID, t.title, t.week_no, t.available_from, t.due_date
               FROM syllabus_tasks t
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s
              ORDER BY (t.week_no IS NULL), t.week_no, t.sort_order, t.taskID",
            [':s' => $schedID]
        );

        $contents = $this->all(
            "SELECT c.contentID, c.taskID, c.kind, c.title, c.is_published
               FROM syllabus_contents c
               JOIN syllabus_tasks t ON t.taskID = c.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s" . ($staff ? '' : ' AND c.is_published = 1') . "
              ORDER BY c.sort_order, c.contentID",
            [':s' => $schedID]
        );
        $contentsByTask = [];
        foreach ($contents as $c) {
            $contentsByTask[$c['taskID']][] = $c;
        }

        $pageTasks = [];
        foreach ($this->all(
            "SELECT p.taskID
               FROM syllabus_pages p
               JOIN syllabus_tasks t ON t.taskID = p.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s AND p.html IS NOT NULL AND p.html <> ''",
            [':s' => $schedID]
        ) as $pr) {
            $pageTasks[$pr['taskID']] = true;
        }

        // checkpoints per topic (students only see published ones that have questions)
        $quizByTask = [];
        foreach ($this->all(
            "SELECT z.taskID, z.kind, z.is_published,
                    (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
               FROM syllabus_quizzes z
               JOIN syllabus_tasks t ON t.taskID = z.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s",
            [':s' => $schedID]
        ) as $z) {
            if (!$staff && ((int)$z['is_published'] !== 1 || (int)$z['qn'] < 1)) continue;
            $quizByTask[$z['taskID']][$z['kind']] = $z;
        }

        // progress: own rows (student) or class-wide stats (staff)
        $mine = [];
        $roster = [];
        $taskStats = [];
        $stu = [];
        if ($mode === 'student') {
            foreach ($this->all(
                "SELECT p.taskID, p.status, p.needs_restudy
                   FROM syllabus_progress p
                   JOIN syllabus_tasks t ON t.taskID = p.taskID
                   JOIN syllabus_modules m ON m.moduleID = t.moduleID
                  WHERE m.schedID = :s AND p.studentID = :st",
                [':s' => $schedID, ':st' => $this->userID]
            ) as $p) {
                $mine[$p['taskID']] = $p;
            }
        } elseif ($staff) {
            $roster = $this->roster($schedID);
            foreach ($roster as $r) {
                $stu[(int)$r['studentID']] = ['done' => 0, 'opened' => 0, 'last' => null, 'restudy' => 0];
            }
            foreach ($this->all(
                "SELECT p.studentID, p.taskID, p.status, p.last_opened_at, p.needs_restudy
                   FROM syllabus_progress p
                   JOIN syllabus_tasks t ON t.taskID = p.taskID
                   JOIN syllabus_modules m ON m.moduleID = t.moduleID
                  WHERE m.schedID = :s",
                [':s' => $schedID]
            ) as $p) {
                $sid = (int)$p['studentID'];
                if (!isset($stu[$sid])) continue;          // not enrolled in this class
                $tid = (int)$p['taskID'];
                if (!isset($taskStats[$tid])) $taskStats[$tid] = ['opened' => 0, 'completed' => 0];
                $taskStats[$tid]['opened']++;
                $stu[$sid]['opened']++;
                if ($p['status'] === 'completed') {
                    $taskStats[$tid]['completed']++;
                    $stu[$sid]['done']++;
                } elseif ((int)$p['needs_restudy'] === 1) {
                    $stu[$sid]['restudy']++;
                }
                if ($stu[$sid]['last'] === null || $p['last_opened_at'] > $stu[$sid]['last']) {
                    $stu[$sid]['last'] = $p['last_opened_at'];
                }
            }
        }
        $nStudents = count($roster);

        $byModule = [];
        foreach ($tasks as $t) {
            $tid    = (int)$t['taskID'];
            $locked = ($t['available_from'] && $t['available_from'] > $today);
            $hide   = ($locked && !$staff);          // students never see the contents of a locked topic

            $t['locked']     = $locked;
            $t['week_label'] = $t['week_no'] !== null ? 'Week ' . (int)$t['week_no'] : 'Topic';
            $t['contents']   = $hide ? [] : ($contentsByTask[$tid] ?? []);
            $t['has_page']   = !$hide && isset($pageTasks[$tid]);
            $t['quizzes']    = $hide ? [] : ($quizByTask[$tid] ?? []);
            $t['done']       = false;
            $t['restudy']    = false;
            $t['opened_n']   = 0;
            $t['done_n']     = 0;

            if ($mode === 'student') {
                $p = $mine[$tid] ?? null;
                $t['done']    = ($p && $p['status'] === 'completed');
                $t['restudy'] = ($p && (int)$p['needs_restudy'] === 1 && !$t['done']);
                $view = $locked ? 'upcoming' : (!$p ? 'not-started' : ($t['done'] ? 'completed' : 'progress'));
            } elseif ($staff) {
                $t['opened_n'] = $taskStats[$tid]['opened'] ?? 0;
                $t['done_n']   = $taskStats[$tid]['completed'] ?? 0;
                $view = $locked ? 'upcoming' : (($t['due_date'] && $t['due_date'] < $today) ? 'closed' : 'open');
            } else {
                $view = $locked ? 'upcoming' : 'not-started';
            }
            $t['view']    = $view;
            $t['overdue'] = ($mode === 'student' && !$locked && !$t['done'] && $t['due_date'] && $t['due_date'] < $today);

            $byModule[$t['moduleID']][] = $t;
        }

        $sum = [
            'mode' => $mode, 'total' => 0, 'done' => 0, 'progress' => 0, 'upcoming' => 0, 'remaining' => 0,
            'pct' => 0, 'overdue' => 0, 'materials' => count($contents), 'students' => $nStudents,
            'current' => null, 'next' => null, 'next_open' => null, 'people' => [],
        ];
        $firstProgress = null;
        $firstOpen     = null;
        $classDone     = 0;

        foreach ($modules as &$m) {
            $m['tasks']     = $byModule[$m['moduleID']] ?? [];
            $m['total']     = count($m['tasks']);
            $m['done']      = 0;
            $m['class_done'] = 0;

            foreach ($m['tasks'] as $t) {
                $t['module_title'] = $m['title'];
                $sum['total']++;
                $m['class_done'] += $t['done_n'];
                $classDone       += $t['done_n'];

                if ($t['locked']) {
                    $sum['upcoming']++;
                    if ($sum['next_open'] === null || $t['available_from'] < $sum['next_open']['available_from']) {
                        $sum['next_open'] = $t;
                    }
                }

                if ($mode === 'student') {
                    if ($t['done']) {
                        $m['done']++;
                        $sum['done']++;
                        continue;
                    }
                    if ($t['view'] === 'progress') {
                        $sum['progress']++;
                        if ($firstProgress === null) $firstProgress = $t;
                    } elseif ($t['view'] === 'not-started' && $firstOpen === null) {
                        $firstOpen = $t;
                    }
                    if ($t['overdue']) $sum['overdue']++;
                }

                // nearest deadline among topics that are already open
                if (!$t['locked'] && $t['due_date'] && $t['due_date'] >= $today
                    && ($sum['next'] === null || $t['due_date'] < $sum['next']['due_date'])) {
                    $sum['next'] = $t;
                }
            }

            if ($mode === 'student') {
                $m['pct'] = $m['total'] > 0 ? (int)round($m['done'] / $m['total'] * 100) : 0;
            } elseif ($staff) {
                $m['pct'] = ($nStudents > 0 && $m['total'] > 0) ? (int)round($m['class_done'] / ($nStudents * $m['total']) * 100) : 0;
            } else {
                $m['pct'] = 0;
            }
        }
        unset($m);

        if ($mode === 'student') {
            $sum['pct']       = $sum['total'] > 0 ? (int)round($sum['done'] / $sum['total'] * 100) : 0;
            $sum['remaining'] = $sum['total'] - $sum['done'] - $sum['progress'];
        } elseif ($staff) {
            $sum['pct'] = ($nStudents > 0 && $sum['total'] > 0) ? (int)round($classDone / ($nStudents * $sum['total']) * 100) : 0;
            foreach ($roster as $r) {
                $s = $stu[(int)$r['studentID']];
                $sum['people'][] = [
                    'name'    => $r['name'],
                    'done'    => $s['done'],
                    'opened'  => $s['opened'],
                    'pct'     => $sum['total'] > 0 ? (int)round($s['done'] / $sum['total'] * 100) : 0,
                    'last'    => $s['last'],
                    'restudy' => $s['restudy'],
                ];
            }
        }

        $sum['current'] = $firstProgress ?? $firstOpen;

        if ($sum['next'] !== null) {
            $sum['next']['days_left'] = (int)(new DateTime($today))->diff(new DateTime($sum['next']['due_date']))->days;
        }
        if ($sum['next_open'] !== null) {
            $sum['next_open']['days_until'] = (int)(new DateTime($today))->diff(new DateTime($sum['next_open']['available_from']))->days;
        }

        return ['modules' => $modules, 'summary' => $sum];
    }

    /* ---------------------------------------------------------
       TOPIC PAGE (one topic: lesson document + materials + checkpoints)
    --------------------------------------------------------- */
    public function topicPage(int $taskID): ?array
    {
        $t = $this->one(
            "SELECT t.taskID, t.title, t.week_no, t.available_from, t.due_date,
                    m.moduleID, m.title AS module_title, m.schedID
               FROM syllabus_tasks t
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE t.taskID = :t",
            [':t' => $taskID]
        );
        if (!$t || !$this->canViewClass((int)$t['schedID'])) return null;

        $t['week_label'] = $t['week_no'] !== null ? 'Week ' . (int)$t['week_no'] : 'Topic';
        $t['locked']     = ($t['available_from'] && $t['available_from'] > $this->today());
        $t['html']       = '';
        $t['delta']      = null;
        $t['updated_at'] = null;

        if (!$t['locked'] || $this->canManageSched((int)$t['schedID'])) {
            $page = $this->one("SELECT html, delta, updated_at FROM syllabus_pages WHERE taskID = :t", [':t' => $taskID]);
            $t['html']       = $page['html'] ?? '';
            $t['delta']      = $page['delta'] ?? null;
            $t['updated_at'] = $page['updated_at'] ?? null;
        }

        $t['class_label'] = '';
        foreach ($this->classes() as $c) {
            if ((int)$c['schedID'] === (int)$t['schedID']) { $t['class_label'] = $c['label']; break; }
        }
        return $t;
    }

    public function contentsForTask(int $taskID, bool $includeHidden): array
    {
        return $this->all(
            "SELECT contentID, taskID, kind, title, description, body, file_name, file_size, is_published
               FROM syllabus_contents
              WHERE taskID = :t" . ($includeHidden ? '' : ' AND is_published = 1') . "
              ORDER BY sort_order, contentID",
            [':t' => $taskID]
        );
    }

    /** The logged-in student's progress row for a topic. */
    public function progressFor(int $taskID): ?array
    {
        if (!$this->isStudent) return null;
        return $this->one(
            "SELECT * FROM syllabus_progress WHERE studentID = :s AND taskID = :t",
            [':s' => $this->userID, ':t' => $taskID]
        );
    }

    /** "The student clicked on it" -> In Progress. */
    public function recordOpen(int $taskID): void
    {
        if (!$this->isStudent) return;
        $now = $this->nowStr();
        $this->run(
            "INSERT INTO syllabus_progress (studentID, taskID, status, first_opened_at, last_opened_at)
             VALUES (:s, :t, 'in_progress', :n1, :n2)
             ON DUPLICATE KEY UPDATE last_opened_at = :n3",
            [':s' => $this->userID, ':t' => $taskID, ':n1' => $now, ':n2' => $now, ':n3' => $now]
        );
    }

    /* ---------------------------------------------------------
       CHECKPOINTS (mini quiz + mock exam)
    --------------------------------------------------------- */
    private function questionsOf(int $quizID, bool $withAnswers): array
    {
        $qs = $this->all(
            "SELECT questionID, prompt FROM syllabus_questions WHERE quizID = :q ORDER BY sort_order, questionID",
            [':q' => $quizID]
        );
        foreach ($qs as &$q) {
            $q['choices'] = $this->all(
                "SELECT choiceID, label" . ($withAnswers ? ', is_correct' : '') . "
                   FROM syllabus_choices WHERE questionID = :q ORDER BY sort_order, choiceID",
                [':q' => $q['questionID']]
            );
        }
        unset($q);
        return $qs;
    }

    /**
     * ['quiz' => row|null, 'exam' => row|null]
     * staff:   every checkpoint, with its questions and the correct answers
     * student: published ones that have questions, plus this student's attempt summary
     * other:   published ones that have questions
     */
    public function assessmentsFor(int $taskID, string $mode): array
    {
        $out  = ['quiz' => null, 'exam' => null];
        $rows = $this->all(
            "SELECT quizID, kind, title, pass_percent, cooldown_minutes, draw_count, is_published
               FROM syllabus_quizzes WHERE taskID = :t",
            [':t' => $taskID]
        );
        foreach ($rows as $q) {
            $qn = (int)$this->value("SELECT COUNT(*) FROM syllabus_questions WHERE quizID = :q", [':q' => $q['quizID']]);
            $q['question_count'] = $qn;
            $q['passed']   = false;
            $q['attempts'] = 0;
            $q['best']     = null;

            if ($mode === 'staff') {
                $q['questions'] = $this->questionsOf((int)$q['quizID'], true);
            } else {
                if ((int)$q['is_published'] !== 1 || $qn < 1) continue;
                if ($mode === 'student') {
                    $a = $this->one(
                        "SELECT COUNT(*) AS n, MAX(score_pct) AS best, MAX(passed) AS passed
                           FROM syllabus_attempts WHERE quizID = :q AND studentID = :s",
                        [':q' => $q['quizID'], ':s' => $this->userID]
                    );
                    $q['attempts'] = (int)($a['n'] ?? 0);
                    $q['best']     = $a['best'];
                    $q['passed']   = ((int)($a['passed'] ?? 0) === 1);
                }
            }
            $out[$q['kind']] = $q;
        }
        return $out;
    }

    /** What the student may do next on this topic. */
    public function studentFlow(array $assess, ?array $progress, bool $hasFiles): array
    {
        $quiz = $assess['quiz'];
        $exam = $assess['exam'];
        $required  = $exam ? 'exam' : ($quiz ? 'quiz' : null);
        $completed = ($progress && $progress['status'] === 'completed');
        $restudy   = ($progress && (int)$progress['needs_restudy'] === 1 && !$completed);

        $secsLeft  = 0;
        $needFiles = false;
        $failedTitle = '';
        if ($restudy) {
            $failQuiz = (int)($progress['last_fail_quizID'] ?? 0);
            $cool = 0;
            foreach ([$quiz, $exam] as $q) {
                if ($q && (int)$q['quizID'] === $failQuiz) {
                    $cool = (int)$q['cooldown_minutes'];
                    $failedTitle = $q['title'];
                }
            }
            $failAt    = $progress['last_fail_at'] ? strtotime($progress['last_fail_at']) : time();
            $secsLeft  = max(0, $failAt + $cool * 60 - time());
            $needFiles = ($hasFiles && (int)($progress['restudy_hits'] ?? 0) < 1);
        }

        $state = ['quiz' => null, 'exam' => null];
        foreach (self::QUIZ_KINDS as $k) {
            $q = $assess[$k];
            if (!$q) continue;
            if ($q['passed'])                                   $s = 'passed';
            elseif ($completed)                                 $s = 'closed';
            elseif ($restudy)                                   $s = 'restudy';
            elseif ($k === 'exam' && $quiz && !$quiz['passed']) $s = 'needs_quiz';
            else                                                $s = 'ready';
            $state[$k] = $s;
        }

        return [
            'required'        => $required,
            'completed'       => $completed,
            'restudy'         => $restudy,
            'seconds_left'    => $secsLeft,
            'need_files'      => $needFiles,
            'failed_title'    => $failedTitle,
            'can_finish_review' => ($restudy && $secsLeft === 0 && !$needFiles),
            'state'           => $state,
        ];
    }

    /** Faculty: how every student of the class is doing on ONE topic. */
    public function topicProgress(int $taskID, int $schedID): array
    {
        $roster = $this->roster($schedID);

        $prog = [];
        foreach ($this->all(
            "SELECT studentID, status, last_opened_at, needs_restudy FROM syllabus_progress WHERE taskID = :t",
            [':t' => $taskID]
        ) as $p) {
            $prog[(int)$p['studentID']] = $p;
        }

        $att = [];
        foreach ($this->all(
            "SELECT a.studentID, z.kind, MAX(a.score_pct) AS best, COUNT(*) AS n, MAX(a.passed) AS passed
               FROM syllabus_attempts a
               JOIN syllabus_quizzes z ON z.quizID = a.quizID
              WHERE z.taskID = :t
              GROUP BY a.studentID, z.kind",
            [':t' => $taskID]
        ) as $a) {
            $att[(int)$a['studentID']][$a['kind']] = $a;
        }

        $rows = [];
        foreach ($roster as $r) {
            $sid = (int)$r['studentID'];
            $p   = $prog[$sid] ?? null;
            $state = !$p ? 'not-started'
                   : ($p['status'] === 'completed' ? 'completed'
                   : ((int)$p['needs_restudy'] === 1 ? 'restudy' : 'progress'));
            $rows[] = [
                'name'  => $r['name'],
                'state' => $state,
                'last'  => $p['last_opened_at'] ?? null,
                'quiz'  => $att[$sid]['quiz'] ?? null,
                'exam'  => $att[$sid]['exam'] ?? null,
            ];
        }
        return $rows;
    }


    /* ---------------------------------------------------------
       HTML SANITIZER (allow-list). Lesson pages are written by faculty
       and shown to students, so nothing executable may get through.
    --------------------------------------------------------- */
    private const HTML_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'span', 'div', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'colgroup', 'col', 'hr',
    ];
    private const HTML_DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'video', 'audio',
        'source', 'canvas', 'noscript', 'template', 'title', 'head',
    ];

    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="syl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root) return '';

        $this->cleanNode($root);

        $out = '';
        foreach ($root->childNodes as $n) {
            $out .= $dom->saveHTML($n);
        }
        return trim($out);
    }

    public function isBlankHtml(string $html): bool
    {
        $t = str_replace('&nbsp;', ' ', $html);
        $t = html_entity_decode(strip_tags($t, '<img><table><hr>'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($t) === '';
    }

    private function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) continue;   // text nodes are safe

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::HTML_DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::HTML_TAGS, true)) {
                // unknown tag: keep its cleaned content, drop the tag itself
                $this->cleanNode($child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            $this->cleanAttributes($child, $tag);
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            $this->cleanNode($child);
        }
    }

    private function cleanAttributes(DOMElement $el, string $tag): void
    {
        $keep = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $val  = trim($attr->value);

            if ($name === 'class') {
                $cls = array_filter(preg_split('/\s+/', $val) ?: [], fn($c) => (bool)preg_match('/^ql-[a-z0-9-]+$/', $c));
                if ($cls) $keep['class'] = implode(' ', $cls);
            } elseif ($name === 'style') {
                $st = $this->cleanStyle($val);
                if ($st !== '') $keep['style'] = $st;
            } elseif ($name === 'data-list' && in_array($val, ['bullet', 'ordered'], true)) {
                $keep[$name] = $val;
            } elseif ($name === 'data-row' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $val)) {
                $keep[$name] = $val;
            } elseif (($tag === 'td' || $tag === 'th') && ($name === 'colspan' || $name === 'rowspan') && ctype_digit($val) && (int)$val >= 1 && (int)$val <= 50) {
                $keep[$name] = $val;
            } elseif ($tag === 'a' && $name === 'href' && preg_match('#^(https?://|mailto:)#i', $val)) {
                $keep['href'] = $val;
            } elseif ($tag === 'img' && $name === 'src' && preg_match('/^syllabus-file\?image=\d+$/', $val)) {
                $keep['src'] = $val;
            } elseif ($tag === 'img' && $name === 'alt') {
                $keep['alt'] = mb_substr($val, 0, 200);
            }
        }

        while ($el->attributes->length > 0) {
            $el->removeAttributeNode($el->attributes->item(0));
        }
        foreach ($keep as $k => $v) {
            $el->setAttribute($k, $v);
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function cleanStyle(string $css): string
    {
        $out = [];
        foreach (explode(';', $css) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) continue;
            $prop = strtolower(trim($parts[0]));
            $val  = trim($parts[1]);
            if (!in_array($prop, ['color', 'background-color'], true)) continue;
            if (preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[0-9.]+\s*)?\)|[a-z]{3,20})$/i', $val)) {
                $out[] = $prop . ': ' . $val;
            }
        }
        return implode('; ', $out);
    }

    /** Validates an uploaded PDF/video and moves it into storage/. Returns file info. */
    private function storeUpload(string $kind): array
    {
        $rule = self::FILE_RULES[$kind];
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $this->fail('Choose a file to upload.');

        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $this->fail('The file is larger than the server allows (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Raise it in php.ini and restart Apache.', 413);
        }
        if ($f['error'] !== UPLOAD_ERR_OK) $this->fail('Upload failed (error code ' . (int)$f['error'] . '). Please try again.', 500);

        if ($f['size'] > $rule['max']) {
            $this->fail('That file is too large (max ' . (int)round($rule['max'] / 1048576) . ' MB for ' . $kind . ' files).', 413);
        }

        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $rule['ext'], true)) {
            $this->fail('Allowed ' . $kind . ' files: .' . implode(', .', $rule['ext']) . '.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, $rule['mime'], true)) {
            $labels = ['pdf' => 'PDF', 'video' => 'MP4/WebM video', 'image' => 'image (PNG, JPG, GIF or WebP)'];
            $this->fail('That file does not look like a valid ' . ($labels[$kind] ?? 'file') . '.');
        }
        if ($kind === 'image' && @getimagesize($f['tmp_name']) === false) {
            $this->fail('That image could not be read.');
        }

        $dir    = self::ensureStorage();
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!is_dir($dir) || !move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            $this->fail('Could not save the file on the server. Check that the storage/syllabus folder is writable.', 500);
        }

        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', basename((string)$f['name'])) ?? 'file.' . $ext;
        return [
            'stored' => $stored,
            'name'   => mb_substr($name, 0, 255),
            'mime'   => self::MIME_BY_EXT[$ext],
            'size'   => (int)$f['size'],
        ];
    }

    /* ---------------------------------------------------------
       POST plumbing
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

    private function intRangeParam(string $key, int $min, int $max, int $default): int
    {
        $raw = trim((string)($_POST[$key] ?? ''));
        if ($raw === '') return $default;
        if (!ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) $this->fail("Enter a number from {$min} to {$max}.");
        return (int)$raw;
    }

    private function titleParam(string $key, int $max): string
    {
        $v = trim((string)($_POST[$key] ?? ''));
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        if ($v === '') $this->fail('Title is required.');
        if (mb_strlen($v) > $max) $this->fail("Title is too long (max {$max} characters).");
        return $v;
    }

    private function optionalText(string $key, int $max, bool $multiline = false): ?string
    {
        $v = str_replace("\r\n", "\n", trim((string)($_POST[$key] ?? '')));
        if ($v === '') return null;
        if (!$multiline) $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        if (mb_strlen($v) > $max) $this->fail("Text is too long (max {$max} characters).");
        return $v;
    }

    private function weekParam(): ?int
    {
        $raw = trim((string)($_POST['week_no'] ?? ''));
        if ($raw === '') return null;
        if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 52) $this->fail('Week must be a number from 1 to 52.');
        return (int)$raw;
    }

    private function dateParam(string $key): ?string
    {
        $raw = trim((string)($_POST[$key] ?? ''));
        if ($raw === '') return null;
        $d = DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) $this->fail('Invalid date.');
        return $raw;
    }

    private function checkDates(?string $open, ?string $due): void
    {
        if ($open !== null && $due !== null && $open > $due) {
            $this->fail('The opening date must be on or before the deadline.');
        }
    }

    private function requireCanManageModule(int $moduleID): void
    {
        $sched = $this->value("SELECT schedID FROM syllabus_modules WHERE moduleID = :m", [':m' => $moduleID]);
        if ($sched === false || !$this->canManageSched((int)$sched)) $this->fail('You cannot edit this module.', 403);
    }

    private function requireCanManageTask(int $taskID): void
    {
        $sched = $this->value(
            "SELECT m.schedID FROM syllabus_tasks t JOIN syllabus_modules m ON m.moduleID = t.moduleID WHERE t.taskID = :t",
            [':t' => $taskID]
        );
        if ($sched === false || !$this->canManageSched((int)$sched)) $this->fail('You cannot edit this topic.', 403);
    }

    private function requireCanManageContent(int $contentID): array
    {
        $row = $this->one(
            "SELECT c.contentID, c.taskID, c.kind, c.stored_name, m.schedID
               FROM syllabus_contents c
               JOIN syllabus_tasks t ON t.taskID = c.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE c.contentID = :c",
            [':c' => $contentID]
        );
        if (!$row || !$this->canManageSched((int)$row['schedID'])) $this->fail('You cannot edit this content.', 403);
        return $row;
    }

    private function requireCanManageQuiz(int $quizID): array
    {
        $row = $this->one(
            "SELECT z.quizID, z.taskID, z.kind, m.schedID
               FROM syllabus_quizzes z
               JOIN syllabus_tasks t ON t.taskID = z.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE z.quizID = :q",
            [':q' => $quizID]
        );
        if (!$row || !$this->canManageSched((int)$row['schedID'])) $this->fail('You cannot edit this checkpoint.', 403);
        return $row;
    }

    /* ---------------------------------------------------------
       POST ACTIONS (JSON)
    --------------------------------------------------------- */
    public function handlePost(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->isFaculty && !$this->isStudent) $this->fail('You are not allowed to do this.', 403);

        // A file bigger than post_max_size makes PHP drop $_POST and $_FILES entirely.
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->fail('The upload is larger than the server allows (post_max_size = ' . ini_get('post_max_size') . '). Raise it in php.ini and restart Apache.', 413);
        }
        if (!csrfValid($_POST['csrf_token'] ?? null)) $this->fail('Session expired. Please refresh the page.', 419);

        $action = (string)($_POST['action'] ?? '');

        try {
            if (in_array($action, ['mark_finished', 'quiz_start', 'quiz_submit', 'finish_review'], true)) {
                if (!$this->isStudent) $this->fail('Only students can do this.', 403);
                $this->handleStudentAction($action);
            } else {
                if (!$this->isFaculty) $this->fail('Only faculty can edit the syllabus.', 403);
                $this->handleFacultyAction($action);
            }
        } catch (PDOException $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('Syllabus action failed: ' . $ex->getMessage());
            $this->fail('Database error. Did you import the latest syllabus_tracker.sql?', 500);
        }

        $this->reply(['ok' => true]);
    }

    /* ---------------- student actions ---------------- */
    private function studentTask(int $taskID): array
    {
        $t = $this->one(
            "SELECT t.taskID, t.available_from, m.schedID
               FROM syllabus_tasks t JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE t.taskID = :t",
            [':t' => $taskID]
        );
        if (!$t || !$this->canViewClass((int)$t['schedID'])) $this->fail('Topic not found.', 404);
        if ($t['available_from'] && $t['available_from'] > $this->today()) $this->fail('This topic is not open yet.', 403);
        return $t;
    }

    private function publishedAssessments(int $taskID): array
    {
        $out = ['quiz' => null, 'exam' => null];
        foreach ($this->all(
            "SELECT z.*, (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
               FROM syllabus_quizzes z WHERE z.taskID = :t AND z.is_published = 1",
            [':t' => $taskID]
        ) as $z) {
            if ((int)$z['qn'] >= 1) $out[$z['kind']] = $z;
        }
        return $out;
    }

    private function handleStudentAction(string $action): void
    {
        switch ($action) {

            case 'mark_finished':
                $taskID = $this->intParam('taskID');
                $this->studentTask($taskID);
                $a = $this->publishedAssessments($taskID);
                if ($a['quiz'] || $a['exam']) $this->fail('Pass the checkpoint to complete this topic.', 409);
                $this->recordOpen($taskID);   // makes sure a progress row exists
                $this->run(
                    "UPDATE syllabus_progress SET status = 'completed', completed_at = :n, needs_restudy = 0
                      WHERE studentID = :s AND taskID = :t",
                    [':n' => $this->nowStr(), ':s' => $this->userID, ':t' => $taskID]
                );
                break;

            case 'quiz_start':
                $quiz = $this->one(
                    "SELECT z.*, (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
                       FROM syllabus_quizzes z WHERE z.quizID = :q",
                    [':q' => $this->intParam('quizID')]
                );
                if (!$quiz || (int)$quiz['is_published'] !== 1 || (int)$quiz['qn'] < 1) $this->fail('This checkpoint is not available.', 404);
                $taskID = (int)$quiz['taskID'];
                $this->studentTask($taskID);
                $this->recordOpen($taskID);

                $p = $this->progressFor($taskID);
                if ($p && $p['status'] === 'completed') $this->fail('You have already completed this topic.', 409);
                if ($p && (int)$p['needs_restudy'] === 1) $this->fail('Study the topic again first. Your retake is locked until you finish the review.', 409);

                if ($quiz['kind'] === 'exam') {
                    $pa = $this->publishedAssessments($taskID);
                    if ($pa['quiz'] && !$this->value(
                        "SELECT 1 FROM syllabus_attempts WHERE quizID = :q AND studentID = :s AND passed = 1 LIMIT 1",
                        [':q' => $pa['quiz']['quizID'], ':s' => $this->userID]
                    )) {
                        $this->fail('Pass the mini quiz before the mock exam.', 409);
                    }
                }

                $questions = $this->questionsOf((int)$quiz['quizID'], false);
                shuffle($questions);
                if ($quiz['kind'] === 'exam' && $quiz['draw_count'] !== null && (int)$quiz['draw_count'] > 0) {
                    $questions = array_slice($questions, 0, (int)$quiz['draw_count']);
                }

                $ids = [];
                $payload = [];
                foreach ($questions as $q) {
                    $ids[] = (int)$q['questionID'];
                    $ch = $q['choices'];
                    shuffle($ch);
                    $payload[] = [
                        'id'      => (int)$q['questionID'],
                        'prompt'  => $q['prompt'],
                        'choices' => array_map(fn($c) => ['id' => (int)$c['choiceID'], 'label' => $c['label']], $ch),
                    ];
                }
                $_SESSION['syl_quiz'][(int)$quiz['quizID']] = ['q' => $ids, 'at' => time()];

                $this->reply(['ok' => true, 'quiz' => [
                    'id' => (int)$quiz['quizID'], 'title' => $quiz['title'], 'kind' => $quiz['kind'],
                    'pass_percent' => (int)$quiz['pass_percent'], 'questions' => $payload,
                ]]);
                break;

            case 'quiz_submit':
                $quizID = $this->intParam('quizID');
                $served = $_SESSION['syl_quiz'][$quizID] ?? null;
                if (!$served || empty($served['q'])) $this->fail('Start the quiz first.', 409);
                $quiz = $this->one("SELECT * FROM syllabus_quizzes WHERE quizID = :q", [':q' => $quizID]);
                if (!$quiz) $this->fail('This checkpoint is not available.', 404);
                $taskID = (int)$quiz['taskID'];
                $this->studentTask($taskID);

                $answers = json_decode((string)($_POST['answers'] ?? ''), true);
                if (!is_array($answers)) $answers = [];

                $correct = 0;
                $total   = 0;
                $missed  = [];
                $review  = [];
                foreach ($served['q'] as $qid) {
                    $qrow = $this->one("SELECT prompt FROM syllabus_questions WHERE questionID = :q AND quizID = :z", [':q' => $qid, ':z' => $quizID]);
                    if (!$qrow) continue;
                    $right  = $this->one("SELECT choiceID, label FROM syllabus_choices WHERE questionID = :q AND is_correct = 1 ORDER BY choiceID LIMIT 1", [':q' => $qid]);
                    $picked = (int)($answers[(string)$qid] ?? 0);
                    $ok     = ($right && (int)$right['choiceID'] === $picked);
                    $total++;
                    if ($ok) $correct++; else $missed[] = $qrow['prompt'];
                    $review[] = ['prompt' => $qrow['prompt'], 'correct' => $right['label'] ?? '', 'ok' => $ok];
                }
                unset($_SESSION['syl_quiz'][$quizID]);

                $pct    = $total > 0 ? (int)round($correct / $total * 100) : 0;
                $passed = ($pct >= (int)$quiz['pass_percent']);
                $now    = $this->nowStr();

                $this->run(
                    "INSERT INTO syllabus_attempts (quizID, studentID, score_pct, correct_count, total_count, passed, answers, submitted_at)
                     VALUES (:q, :s, :p, :c, :t, :ps, :a, :n)",
                    [':q' => $quizID, ':s' => $this->userID, ':p' => $pct, ':c' => $correct, ':t' => $total,
                     ':ps' => $passed ? 1 : 0, ':a' => json_encode($answers), ':n' => $now]
                );

                $completed = false;
                if ($passed) {
                    $pa = $this->publishedAssessments($taskID);
                    $required = $pa['exam'] ? 'exam' : ($pa['quiz'] ? 'quiz' : null);
                    if ($required === $quiz['kind']) {
                        $this->run(
                            "UPDATE syllabus_progress SET status = 'completed', completed_at = :n, needs_restudy = 0
                              WHERE studentID = :s AND taskID = :t",
                            [':n' => $now, ':s' => $this->userID, ':t' => $taskID]
                        );
                        $completed = true;
                    }
                } else {
                    $this->run(
                        "UPDATE syllabus_progress SET needs_restudy = 1, last_fail_at = :n, last_fail_quizID = :q, restudy_hits = 0
                          WHERE studentID = :s AND taskID = :t AND status <> 'completed'",
                        [':n' => $now, ':q' => $quizID, ':s' => $this->userID, ':t' => $taskID]
                    );
                }

                $this->reply([
                    'ok' => true, 'passed' => $passed, 'score' => $pct, 'correct' => $correct, 'total' => $total,
                    'pass_percent' => (int)$quiz['pass_percent'], 'completed' => $completed, 'kind' => $quiz['kind'],
                    'cooldown' => (int)$quiz['cooldown_minutes'],
                    'missed' => $passed ? [] : $missed,
                    'review' => $passed ? $review : [],
                ]);
                break;

            case 'finish_review':
                $taskID = $this->intParam('taskID');
                $this->studentTask($taskID);
                $p = $this->progressFor($taskID);
                if (!$p || (int)$p['needs_restudy'] !== 1 || $p['status'] === 'completed') $this->fail('There is nothing to review right now.', 409);

                $cool = (int)$this->value("SELECT cooldown_minutes FROM syllabus_quizzes WHERE quizID = :q", [':q' => (int)($p['last_fail_quizID'] ?? 0)]);
                $left = strtotime($p['last_fail_at'] ?: $this->nowStr()) + $cool * 60 - time();
                if ($left > 0) $this->fail('Keep studying. You can retake in ' . (int)ceil($left / 60) . ' minute(s).', 409);

                $hasFiles = (int)$this->value(
                    "SELECT COUNT(*) FROM syllabus_contents WHERE taskID = :t AND is_published = 1 AND kind IN ('pdf','video')",
                    [':t' => $taskID]
                ) > 0;
                if ($hasFiles && (int)$p['restudy_hits'] < 1) $this->fail('Open at least one learning material (PDF or video) again before retaking.', 409);

                $this->run("UPDATE syllabus_progress SET needs_restudy = 0 WHERE studentID = :s AND taskID = :t", [':s' => $this->userID, ':t' => $taskID]);
                break;
        }
    }

    /* ---------------- faculty actions ---------------- */
    private function handleFacultyAction(string $action): void
    {
        switch ($action) {

            /* ----- modules ----- */
            case 'add_module':
                $sched = $this->intParam('schedID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $title = $this->titleParam('title', 200);
                $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_modules WHERE schedID = :s", [':s' => $sched]);
                $this->run("INSERT INTO syllabus_modules (schedID, title, sort_order) VALUES (:s, :t, :o)", [':s' => $sched, ':t' => $title, ':o' => $next]);
                break;

            case 'rename_module':
                $id = $this->intParam('moduleID');
                $this->requireCanManageModule($id);
                $this->run("UPDATE syllabus_modules SET title = :t WHERE moduleID = :m", [':t' => $this->titleParam('title', 200), ':m' => $id]);
                break;

            case 'delete_module':
                $id = $this->intParam('moduleID');
                $this->requireCanManageModule($id);
                $files = array_column($this->all(
                    "SELECT c.stored_name FROM syllabus_contents c
                       JOIN syllabus_tasks t ON t.taskID = c.taskID
                      WHERE t.moduleID = :m AND c.stored_name IS NOT NULL",
                    [':m' => $id]
                ), 'stored_name');
                $files = array_merge($files, array_column($this->all(
                    "SELECT i.stored_name FROM syllabus_images i
                       JOIN syllabus_tasks t ON t.taskID = i.taskID
                      WHERE t.moduleID = :m",
                    [':m' => $id]
                ), 'stored_name'));
                $this->pdo->beginTransaction();
                $this->run("DELETE i FROM syllabus_images i JOIN syllabus_tasks t ON t.taskID = i.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE p FROM syllabus_pages p JOIN syllabus_tasks t ON t.taskID = p.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE c FROM syllabus_contents c JOIN syllabus_tasks t ON t.taskID = c.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);   // progress, quizzes, questions, attempts cascade
                $this->run("DELETE FROM syllabus_modules WHERE moduleID = :m", [':m' => $id]);
                $this->pdo->commit();
                $this->removeFiles($files);
                break;

            /* ----- topics (tasks) ----- */
            case 'add_task':
                $id = $this->intParam('moduleID');
                $this->requireCanManageModule($id);
                $title = $this->titleParam('title', 250);
                $week  = $this->weekParam();
                $open  = $this->dateParam('available_from');
                $due   = $this->dateParam('due_date');
                $this->checkDates($open, $due);
                $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);
                $this->run(
                    "INSERT INTO syllabus_tasks (moduleID, title, week_no, available_from, due_date, sort_order) VALUES (:m, :t, :w, :a, :d, :o)",
                    [':m' => $id, ':t' => $title, ':w' => $week, ':a' => $open, ':d' => $due, ':o' => $next]
                );
                break;

            case 'edit_task':
                $id = $this->intParam('taskID');
                $this->requireCanManageTask($id);
                $title = $this->titleParam('title', 250);
                $week  = $this->weekParam();
                $open  = $this->dateParam('available_from');
                $due   = $this->dateParam('due_date');
                $this->checkDates($open, $due);
                $this->run(
                    "UPDATE syllabus_tasks SET title = :t, week_no = :w, available_from = :a, due_date = :d WHERE taskID = :id",
                    [':t' => $title, ':w' => $week, ':a' => $open, ':d' => $due, ':id' => $id]
                );
                break;

            case 'delete_task':
                $id = $this->intParam('taskID');
                $this->requireCanManageTask($id);
                $files = array_column($this->all(
                    "SELECT stored_name FROM syllabus_contents WHERE taskID = :t AND stored_name IS NOT NULL",
                    [':t' => $id]
                ), 'stored_name');
                $files = array_merge($files, array_column($this->all(
                    "SELECT stored_name FROM syllabus_images WHERE taskID = :t",
                    [':t' => $id]
                ), 'stored_name'));
                $this->pdo->beginTransaction();
                $this->run("DELETE FROM syllabus_images WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_pages WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_contents WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_tasks WHERE taskID = :t", [':t' => $id]);   // progress, quizzes, questions, attempts cascade
                $this->pdo->commit();
                $this->removeFiles($files);
                break;

            /* ----- learning contents ----- */
            case 'add_content':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, self::KINDS, true)) $this->fail('Choose a content type.');
                $title = $this->titleParam('title', 200);
                $desc  = $this->optionalText('description', 500);
                $body  = null;
                $file  = null;
                if ($kind === 'note') {
                    $body = $this->optionalText('body', 20000, true);
                    if ($body === null) $this->fail('Write the note text first.');
                } else {
                    $file = $this->storeUpload($kind);
                }
                try {
                    $next = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_contents WHERE taskID = :t", [':t' => $taskID]);
                    $this->run(
                        "INSERT INTO syllabus_contents
                            (taskID, kind, title, description, body, file_name, stored_name, mime_type, file_size, sort_order, uploaded_by)
                         VALUES (:t, :k, :ti, :d, :b, :fn, :sn, :mt, :fs, :o, :u)",
                        [
                            ':t' => $taskID, ':k' => $kind, ':ti' => $title, ':d' => $desc, ':b' => $body,
                            ':fn' => $file['name'] ?? null, ':sn' => $file['stored'] ?? null,
                            ':mt' => $file['mime'] ?? null, ':fs' => $file['size'] ?? null,
                            ':o' => $next, ':u' => $this->userID,
                        ]
                    );
                } catch (PDOException $ex) {
                    if ($file) $this->removeFiles([$file['stored']]);   // don't leave an orphan file behind
                    throw $ex;
                }
                break;

            case 'edit_content':
                $row   = $this->requireCanManageContent($this->intParam('contentID'));
                $title = $this->titleParam('title', 200);
                $desc  = $this->optionalText('description', 500);
                if ($row['kind'] === 'note') {
                    $body = $this->optionalText('body', 20000, true);
                    if ($body === null) $this->fail('The note text cannot be empty.');
                    $this->run("UPDATE syllabus_contents SET title = :t, description = :d, body = :b WHERE contentID = :c",
                        [':t' => $title, ':d' => $desc, ':b' => $body, ':c' => $row['contentID']]);
                } else {
                    $this->run("UPDATE syllabus_contents SET title = :t, description = :d WHERE contentID = :c",
                        [':t' => $title, ':d' => $desc, ':c' => $row['contentID']]);
                }
                break;

            case 'toggle_publish':
                $row = $this->requireCanManageContent($this->intParam('contentID'));
                $pub = (($_POST['published'] ?? '') === '1') ? 1 : 0;
                $this->run("UPDATE syllabus_contents SET is_published = :p WHERE contentID = :c", [':p' => $pub, ':c' => $row['contentID']]);
                break;

            case 'move_content':
                $row = $this->requireCanManageContent($this->intParam('contentID'));
                $dir = (string)($_POST['dir'] ?? '');
                if ($dir !== 'up' && $dir !== 'down') $this->fail('Invalid direction.');
                $cur = (int)$this->value("SELECT sort_order FROM syllabus_contents WHERE contentID = :c", [':c' => $row['contentID']]);
                $nb  = $dir === 'up'
                    ? $this->one("SELECT contentID, sort_order FROM syllabus_contents WHERE taskID = :t AND sort_order < :o ORDER BY sort_order DESC, contentID DESC LIMIT 1", [':t' => $row['taskID'], ':o' => $cur])
                    : $this->one("SELECT contentID, sort_order FROM syllabus_contents WHERE taskID = :t AND sort_order > :o ORDER BY sort_order ASC, contentID ASC LIMIT 1",  [':t' => $row['taskID'], ':o' => $cur]);
                if ($nb) {
                    $this->pdo->beginTransaction();
                    $this->run("UPDATE syllabus_contents SET sort_order = :o WHERE contentID = :c", [':o' => $nb['sort_order'], ':c' => $row['contentID']]);
                    $this->run("UPDATE syllabus_contents SET sort_order = :o WHERE contentID = :c", [':o' => $cur, ':c' => $nb['contentID']]);
                    $this->pdo->commit();
                }
                break;

            case 'delete_content':
                $row = $this->requireCanManageContent($this->intParam('contentID'));
                $this->run("DELETE FROM syllabus_contents WHERE contentID = :c", [':c' => $row['contentID']]);
                if (!empty($row['stored_name'])) $this->removeFiles([$row['stored_name']]);
                break;

            /* ----- lesson page (rich text) ----- */
            case 'save_page':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $html  = (string)($_POST['html'] ?? '');
                $delta = (string)($_POST['delta'] ?? '');
                if (strlen($html) > 1000000 || strlen($delta) > 1500000) $this->fail('This page is too large to save.', 413);
                $arr = json_decode($delta, true);
                if (!is_array($arr) || !isset($arr['ops']) || !is_array($arr['ops'])) $this->fail('Invalid editor data.');
                $clean = $this->sanitizeHtml($html);
                if ($this->isBlankHtml($clean)) $clean = '';
                $this->run(
                    "INSERT INTO syllabus_pages (taskID, html, delta, updated_by) VALUES (:t, :h, :d, :u)
                     ON DUPLICATE KEY UPDATE html = :h2, delta = :d2, updated_by = :u2",
                    [
                        ':t' => $taskID, ':h' => $clean, ':d' => json_encode($arr, JSON_UNESCAPED_UNICODE), ':u' => $this->userID,
                        ':h2' => $clean, ':d2' => json_encode($arr, JSON_UNESCAPED_UNICODE), ':u2' => $this->userID,
                    ]
                );
                $this->reply(['ok' => true, 'saved_at' => date('g:i A')]);
                break;

            case 'upload_image':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $file = $this->storeUpload('image');
                try {
                    $this->run(
                        "INSERT INTO syllabus_images (taskID, stored_name, file_name, mime_type, file_size, uploaded_by) VALUES (:t, :s, :n, :m, :z, :u)",
                        [':t' => $taskID, ':s' => $file['stored'], ':n' => $file['name'], ':m' => $file['mime'], ':z' => $file['size'], ':u' => $this->userID]
                    );
                } catch (PDOException $ex) {
                    $this->removeFiles([$file['stored']]);
                    throw $ex;
                }
                // relative URL so it works on any base path (localhost subfolder or live root)
                $this->reply(['ok' => true, 'url' => 'syllabus-file?image=' . (int)$this->pdo->lastInsertId()]);
                break;

            /* ----- checkpoints: mini quiz + mock exam ----- */
            case 'save_quiz':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, self::QUIZ_KINDS, true)) $this->fail('Choose quiz or exam.');
                $title = $this->optionalText('title', 200) ?? ($kind === 'exam' ? 'Mock exam' : 'Mini quiz');
                $pass  = $this->intRangeParam('pass_percent', 1, 100, 70);
                $cool  = $this->intRangeParam('cooldown_minutes', 0, 1440, 5);
                $draw  = null;
                if ($kind === 'exam' && trim((string)($_POST['draw_count'] ?? '')) !== '') {
                    $draw = $this->intRangeParam('draw_count', 1, 500, 1);
                }
                $pub = (($_POST['published'] ?? '') === '1') ? 1 : 0;
                $this->run(
                    "INSERT INTO syllabus_quizzes (taskID, kind, title, pass_percent, cooldown_minutes, draw_count, is_published)
                     VALUES (:t, :k, :ti, :p, :c, :d, :u)
                     ON DUPLICATE KEY UPDATE title = :ti2, pass_percent = :p2, cooldown_minutes = :c2, draw_count = :d2, is_published = :u2",
                    [
                        ':t' => $taskID, ':k' => $kind, ':ti' => $title, ':p' => $pass, ':c' => $cool, ':d' => $draw, ':u' => $pub,
                        ':ti2' => $title, ':p2' => $pass, ':c2' => $cool, ':d2' => $draw, ':u2' => $pub,
                    ]
                );
                break;

            case 'delete_quiz':
                $quiz = $this->requireCanManageQuiz($this->intParam('quizID'));
                $this->pdo->beginTransaction();
                $this->run("UPDATE syllabus_progress SET needs_restudy = 0 WHERE last_fail_quizID = :q", [':q' => $quiz['quizID']]);
                $this->run("DELETE FROM syllabus_quizzes WHERE quizID = :q", [':q' => $quiz['quizID']]);   // questions, choices, attempts cascade
                $this->pdo->commit();
                break;

            case 'save_question':
                $quiz   = $this->requireCanManageQuiz($this->intParam('quizID'));
                $qid    = $this->intParam('questionID');
                $prompt = $this->optionalText('prompt', 2000, true);
                if ($prompt === null) $this->fail('Write the question first.');
                $choices = json_decode((string)($_POST['choices'] ?? ''), true);
                if (!is_array($choices)) $this->fail('Invalid answer choices.');
                $choices = array_values(array_map(fn($c) => trim((string)$c), $choices));
                if (count($choices) < 2 || count($choices) > 6) $this->fail('Give between 2 and 6 answer choices.');
                foreach ($choices as $c) {
                    if ($c === '' || mb_strlen($c) > 500) $this->fail('Fill in every answer choice (max 500 characters each).');
                }
                $correct = (int)($_POST['correct'] ?? -1);
                if ($correct < 0 || $correct >= count($choices)) $this->fail('Mark which choice is the correct one.');

                if ($qid > 0 && !$this->value("SELECT 1 FROM syllabus_questions WHERE questionID = :q AND quizID = :z", [':q' => $qid, ':z' => $quiz['quizID']])) {
                    $this->fail('Question not found.', 404);
                }
                $this->pdo->beginTransaction();
                if ($qid > 0) {
                    $this->run("UPDATE syllabus_questions SET prompt = :p WHERE questionID = :q", [':p' => $prompt, ':q' => $qid]);
                    $this->run("DELETE FROM syllabus_choices WHERE questionID = :q", [':q' => $qid]);
                } else {
                    $next = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_questions WHERE quizID = :z", [':z' => $quiz['quizID']]);
                    $this->run("INSERT INTO syllabus_questions (quizID, prompt, sort_order) VALUES (:z, :p, :o)", [':z' => $quiz['quizID'], ':p' => $prompt, ':o' => $next]);
                    $qid = (int)$this->pdo->lastInsertId();
                }
                foreach ($choices as $i => $label) {
                    $this->run(
                        "INSERT INTO syllabus_choices (questionID, label, is_correct, sort_order) VALUES (:q, :l, :c, :o)",
                        [':q' => $qid, ':l' => $label, ':c' => ($i === $correct) ? 1 : 0, ':o' => $i + 1]
                    );
                }
                $this->pdo->commit();
                break;

            case 'delete_question':
                $qid    = $this->intParam('questionID');
                $quizID = (int)$this->value("SELECT quizID FROM syllabus_questions WHERE questionID = :q", [':q' => $qid]);
                $this->requireCanManageQuiz($quizID);
                $this->pdo->beginTransaction();
                $this->run("DELETE FROM syllabus_choices WHERE questionID = :q", [':q' => $qid]);
                $this->run("DELETE FROM syllabus_questions WHERE questionID = :q", [':q' => $qid]);
                $this->pdo->commit();
                break;

            default:
                $this->fail('Unknown action.');
        }
    }
}

// POST /syllabus is routed straight to this file; handle the action and stop.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    (new SyllabusLogic())->handlePost();
}