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
    Completed    the student passed the quiz,
                 or - when the topic has no quiz - marked it as finished
    A failed quiz asks the student to study again: the retake unlocks after a short wait.
    passed AND (when the topic has PDF/video materials) at least one material was opened again.
========================================================
*/
class SyllabusLogic
{
    private const KINDS             = ['pdf', 'video', 'note'];
    private const QUIZ_KINDS        = ['quiz', 'exam'];
    private const TERMS             = ['midterm', 'final'];
    private const PROJECT_SUB_TYPES = ['file', 'link', 'both'];

    // Allowed uploads. Real limits are also capped by php.ini (upload_max_filesize / post_max_size).
    private const FILE_RULES = [
        'pdf'     => ['ext' => ['pdf'], 'mime' => ['application/pdf'], 'max' => 52428800],   // 50 MB
        'image'   => ['ext' => ['png', 'jpg', 'jpeg', 'gif', 'webp'], 'mime' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], 'max' => 5242880],  // 5 MB (pictures inside lesson pages)
        'project' => [
            'ext'  => ['pdf', 'zip', 'docx', 'doc', 'pptx', 'xlsx', 'txt', 'png', 'jpg', 'jpeg', 'webp'],
            'mime' => [
                'application/pdf', 'application/zip', 'application/x-zip-compressed', 'application/x-zip',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/octet-stream', 'text/plain',
                'image/png', 'image/jpeg', 'image/webp',
            ],
            'max'  => 52428800,   // 50 MB
        ],
    ];
    private const MIME_BY_EXT = [
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'zip' => 'application/zip', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];
    private const STORED_RE = '/^[a-f0-9]{32}\.(pdf|mp4|webm|png|jpe?g|gif|webp|zip|docx?|pptx|xlsx|txt)$/';

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

    /** Project submission row for the file endpoint, or null when this user must not get it. */
    public function projectSubmissionForServing(int $submissionID): ?array
    {
        $row = $this->one(
            "SELECT ps.submissionID, ps.studentID, ps.stored_name, ps.file_name, ps.mime_type, m.schedID
               FROM syllabus_project_submissions ps
               JOIN syllabus_projects sp ON sp.projectID = ps.projectID
               JOIN syllabus_tasks t ON t.taskID = sp.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE ps.submissionID = :s",
            [':s' => $submissionID]
        );
        if (!$row || empty($row['stored_name'])) return null;
        $isOwner = ($this->isStudent && (int)$row['studentID'] === $this->userID);
        if (!$isOwner && !$this->canManageSched((int)$row['schedID'])) return null;
        return $row;
    }

    /* ---------------------------------------------------------
       ACADEMIC CALENDAR (per acad_year + sem)
       Provides the term/week basis for creating topics and auto-filling dates.
    --------------------------------------------------------- */
    public function academicCalendar(string $acadYear, int $sem): ?array
    {
        $cal = $this->one(
            "SELECT calendarID, acad_year, sem, semester_start, midterm_weeks, total_weeks,
                    midterm_exam_week, final_exam_week, updated_at
               FROM syllabus_academic_calendar
              WHERE acad_year = :ay AND sem = :s",
            [':ay' => $acadYear, ':s' => $sem]
        );
        if (!$cal) return null;

        $breaks = $this->all(
            "SELECT breakID, title, start_date, end_date, skip_week
               FROM syllabus_calendar_breaks
              WHERE calendarID = :c
              ORDER BY start_date, breakID",
            [':c' => (int)$cal['calendarID']]
        );
        $cal['breaks'] = $breaks;
        $cal['weeks']  = $this->computeCalendarWeeks($cal, $breaks);

        $today = $this->today();
        $cal['current_week'] = null;
        foreach ($cal['weeks'] as $w) {
            if ($today >= $w['start_date'] && $today <= $w['end_date']) {
                $cal['current_week'] = $w;
                break;
            }
        }
        $lastWeek = end($cal['weeks']);
        $cal['semester_end'] = $lastWeek ? $lastWeek['end_date'] : $cal['semester_start'];
        return $cal;
    }

    public function calendarForSched(int $schedID): ?array
    {
        $ep = $this->one(
            "SELECT ep.acad_year, ep.sem
               FROM subject_schedules ss
               JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID
              WHERE ss.schedID = :s",
            [':s' => $schedID]
        );
        if (!$ep) return null;
        return $this->academicCalendar((string)$ep['acad_year'], (int)$ep['sem']);
    }

    /** Computes Week 1..N date ranges, midterm/final tags, exam weeks and overlapping breaks. */
    private function computeCalendarWeeks(array $cal, array $breaks): array
    {
        $total   = max(1, min(26, (int)$cal['total_weeks']));
        $midW    = max(1, min($total, (int)$cal['midterm_weeks']));
        $midExam = $cal['midterm_exam_week'] ? (int)$cal['midterm_exam_week'] : null;
        $finExam = $cal['final_exam_week'] ? (int)$cal['final_exam_week'] : null;

        $cursor = new DateTime($cal['semester_start']);
        $weeks  = [];

        for ($w = 1; $w <= $total; $w++) {
            // If a skip-week break overlaps the current week window, push the week past that break.
            $guard = 0;
            while ($guard < 20) {
                $guard++;
                $ws = $cursor->format('Y-m-d');
                $we = (clone $cursor)->modify('+6 days')->format('Y-m-d');
                $pushed = false;
                foreach ($breaks as $b) {
                    if ((int)$b['skip_week'] === 1 && $b['start_date'] <= $we && $b['end_date'] >= $ws) {
                        $after = new DateTime($b['end_date']);
                        $after->modify('+1 day');
                        if ($after > $cursor) {
                            $cursor = $after;
                            $pushed = true;
                            break;
                        }
                    }
                }
                if (!$pushed) break;
            }

            $startStr = $cursor->format('Y-m-d');
            $endStr   = (clone $cursor)->modify('+6 days')->format('Y-m-d');
            $term     = ($w <= $midW) ? 'midterm' : 'final';

            $notes = [];
            foreach ($breaks as $b) {
                if ((int)$b['skip_week'] === 0 && $b['start_date'] <= $endStr && $b['end_date'] >= $startStr) {
                    $notes[] = $b['title'];
                }
            }

            $examLabel = '';
            if ($midExam !== null && $w === $midExam)     $examLabel = 'Midterm Exam Week';
            elseif ($finExam !== null && $w === $finExam) $examLabel = 'Final Exam Week';

            $weeks[$w] = [
                'week_no'      => $w,
                'term'         => $term,
                'start_date'   => $startStr,
                'end_date'     => $endStr,
                'is_exam_week' => ($examLabel !== ''),
                'exam_label'   => $examLabel,
                'breaks'       => $notes,
            ];
            $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /** Syncs available_from and due_date for all topics with a week_no in a class to the calendar. */
    private function syncClassDatesToCalendar(int $schedID, array $cal): int
    {
        $weeks = $cal['weeks'] ?? [];
        if (!$weeks) return 0;
        $tasks = $this->all(
            "SELECT t.taskID, t.week_no
               FROM syllabus_tasks t
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s AND t.week_no IS NOT NULL",
            [':s' => $schedID]
        );
        $updated = 0;
        foreach ($tasks as $t) {
            $w = (int)$t['week_no'];
            if (!isset($weeks[$w])) continue;
            $this->run(
                "UPDATE syllabus_tasks SET available_from = :a, due_date = :d WHERE taskID = :id",
                [':a' => $weeks[$w]['start_date'], ':d' => $weeks[$w]['end_date'], ':id' => (int)$t['taskID']]
            );
            $updated++;
        }
        return $updated;
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

        $calendar = $this->calendarForSched($schedID);

        $modules = $this->all(
            "SELECT moduleID, title, term FROM syllabus_modules
              WHERE schedID = :s ORDER BY FIELD(term, 'midterm', 'final'), sort_order, moduleID",
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

        // assessments per topic: quiz, exam, project (students only see published ones)
        $quizByTask = [];
        foreach ($this->all(
            "SELECT z.taskID, z.kind, z.is_published,
                    (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
               FROM syllabus_quizzes z
               JOIN syllabus_tasks t ON t.taskID = z.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s AND z.kind IN ('quiz','exam')",
            [':s' => $schedID]
        ) as $z) {
            if (!$staff && ((int)$z['is_published'] !== 1 || (int)$z['qn'] < 1)) continue;
            $quizByTask[$z['taskID']][$z['kind']] = $z;
        }

        $projByTask = [];
        foreach ($this->all(
            "SELECT sp.taskID, sp.projectID, sp.title, sp.is_published
               FROM syllabus_projects sp
               JOIN syllabus_tasks t ON t.taskID = sp.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s" . ($staff ? '' : ' AND sp.is_published = 1'),
            [':s' => $schedID]
        ) as $sp) {
            $projByTask[(int)$sp['taskID']] = $sp;
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
            $t['project']    = $hide ? null : ($projByTask[$tid] ?? null);
            $t['cal_week']   = ($calendar && $t['week_no'] !== null && isset($calendar['weeks'][(int)$t['week_no']]))
                               ? $calendar['weeks'][(int)$t['week_no']] : null;
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
            'open_topics' => 0, 'help' => [], 'hard' => [],
            'terms' => [
                'midterm' => ['modules' => 0, 'total' => 0, 'done' => 0, 'class_done' => 0, 'pct' => 0],
                'final'   => ['modules' => 0, 'total' => 0, 'done' => 0, 'class_done' => 0, 'pct' => 0],
            ],
        ];
        $firstProgress = null;
        $firstOpen     = null;
        $classDone     = 0;

        foreach ($modules as &$m) {
            $mTerm          = in_array($m['term'] ?? '', self::TERMS, true) ? $m['term'] : 'midterm';
            $m['term']      = $mTerm;
            $m['tasks']     = $byModule[$m['moduleID']] ?? [];
            $m['total']     = count($m['tasks']);
            $m['done']      = 0;
            $m['class_done'] = 0;
            $sum['terms'][$mTerm]['modules']++;

            foreach ($m['tasks'] as $t) {
                $t['module_title'] = $m['title'];
                $sum['total']++;
                if (!$t['locked']) $sum['open_topics']++;
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
            $sum['terms'][$mTerm]['total']      += $m['total'];
            $sum['terms'][$mTerm]['done']       += $m['done'];
            $sum['terms'][$mTerm]['class_done'] += $m['class_done'];
        }
        unset($m);

        foreach (self::TERMS as $tk) {
            $tt = &$sum['terms'][$tk];
            if ($mode === 'student') {
                $tt['pct'] = $tt['total'] > 0 ? (int)round($tt['done'] / $tt['total'] * 100) : 0;
            } elseif ($staff) {
                $tt['pct'] = ($nStudents > 0 && $tt['total'] > 0) ? (int)round($tt['class_done'] / ($nStudents * $tt['total']) * 100) : 0;
            }
        }
        unset($tt);

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
            $sum['help'] = $this->needsHelp($schedID, $roster, $stu, (int)$sum['open_topics']);
            $sum['hard'] = $this->hardQuestions($schedID);
        }

        $sum['current'] = $firstProgress ?? $firstOpen;

        if ($sum['next'] !== null) {
            $sum['next']['days_left'] = (int)(new DateTime($today))->diff(new DateTime($sum['next']['due_date']))->days;
        }
        if ($sum['next_open'] !== null) {
            $sum['next_open']['days_until'] = (int)(new DateTime($today))->diff(new DateTime($sum['next_open']['available_from']))->days;
        }

        return ['modules' => $modules, 'summary' => $sum, 'calendar' => $calendar];
    }

    /* ---------------------------------------------------------
       TOPIC PAGE (one topic: lesson document + materials + checkpoints)
    --------------------------------------------------------- */
    public function topicPage(int $taskID): ?array
    {
        $t = $this->one(
            "SELECT t.taskID, t.title, t.week_no, t.available_from, t.due_date,
                    m.moduleID, m.title AS module_title, m.term, m.schedID
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
            "SELECT contentID, taskID, kind, title, description, body, file_name, file_size, is_published,
                    (CASE WHEN stored_name IS NULL OR stored_name = '' THEN 0 ELSE 1 END) AS has_file
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
       QUIZZES
    --------------------------------------------------------- */
    private function questionsOf(int $quizID, bool $withAnswers): array
    {
        $qs = $this->all(
            "SELECT questionID, prompt, explanation, difficulty FROM syllabus_questions WHERE quizID = :q ORDER BY sort_order, questionID",
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
     * ['quiz' => row|null]
     * staff:   every checkpoint, with its questions and the correct answers
     * student: published ones that have questions, plus this student's attempt summary
     * other:   published ones that have questions
     */
    public function assessmentsFor(int $taskID, string $mode): array
    {
        $out  = ['quiz' => null, 'exam' => null, 'project' => null];
        $rows = $this->all(
            "SELECT quizID, kind, mode, title, pass_percent, cooldown_minutes, draw_count, time_limit_minutes, lives, allow_practice, is_published
               FROM syllabus_quizzes WHERE taskID = :t AND kind IN ('quiz','exam')",
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

        // Project assessment (optional third kind)
        $proj = $this->one(
            "SELECT projectID, taskID, title, instructions, submission_type, max_score, pass_score, require_approval, is_published
               FROM syllabus_projects WHERE taskID = :t",
            [':t' => $taskID]
        );
        if ($proj) {
            if ($mode === 'staff') {
                $proj['sub_count'] = (int)$this->value(
                    "SELECT COUNT(*) FROM syllabus_project_submissions WHERE projectID = :p",
                    [':p' => (int)$proj['projectID']]
                );
                $proj['approved_count'] = (int)$this->value(
                    "SELECT COUNT(*) FROM syllabus_project_submissions WHERE projectID = :p AND status = 'approved'",
                    [':p' => (int)$proj['projectID']]
                );
                $out['project'] = $proj;
            } elseif ((int)$proj['is_published'] === 1) {
                $proj['submission'] = null;
                $proj['passed']     = false;
                if ($mode === 'student') {
                    $sub = $this->one(
                        "SELECT submissionID, link_url, file_name, file_size, notes, status, score, feedback, submitted_at, graded_at
                           FROM syllabus_project_submissions
                          WHERE projectID = :p AND studentID = :s",
                        [':p' => (int)$proj['projectID'], ':s' => $this->userID]
                    );
                    $proj['submission'] = $sub;
                    if ($sub) {
                        $proj['passed'] = ((int)$proj['require_approval'] === 0) || ($sub['status'] === 'approved');
                    }
                }
                $out['project'] = $proj;
            }
        }
        return $out;
    }

    /** Prompts the student missed on their last failed attempt of a checkpoint. */
    private function lastMissed(int $quizID): array
    {
        $a = $this->one(
            "SELECT answers FROM syllabus_attempts WHERE quizID = :q AND studentID = :s AND passed = 0 ORDER BY attemptID DESC LIMIT 1",
            [':q' => $quizID, ':s' => $this->userID]
        );
        if (!$a) return [];
        $answers = json_decode((string)$a['answers'], true);
        if (!is_array($answers)) return [];
        $out = [];
        foreach ($answers as $qid => $x) {
            if (!empty($x['ok'])) continue;
            $prompt = $this->value("SELECT prompt FROM syllabus_questions WHERE questionID = :q", [':q' => (int)$qid]);
            if ($prompt !== false) $out[] = $prompt;
        }
        return array_slice($out, 0, 10);
    }

    /** What the student may do next on this topic. */
    public function studentFlow(array $assess, ?array $progress): array
    {
        $quiz    = $assess['quiz'] ?? null;
        $exam    = $assess['exam'] ?? null;
        $project = $assess['project'] ?? null;

        $completed = ($progress && $progress['status'] === 'completed');
        $flagged   = ($progress && (int)$progress['needs_restudy'] === 1 && !$completed);

        $secsLeft    = 0;
        $failedTitle = '';
        $failedQuiz  = 0;
        $failedKind  = '';
        $missed      = [];
        if ($flagged) {
            $lastFailId = (int)($progress['last_fail_quizID'] ?? 0);
            $failedObj  = null;
            if ($exam && (int)$exam['quizID'] === $lastFailId)     $failedObj = $exam;
            elseif ($quiz && (int)$quiz['quizID'] === $lastFailId) $failedObj = $quiz;
            elseif ($quiz)                                         $failedObj = $quiz;
            elseif ($exam)                                         $failedObj = $exam;

            if ($failedObj) {
                $failedQuiz  = (int)$failedObj['quizID'];
                $failedTitle = $failedObj['title'];
                $failedKind  = $failedObj['kind'];
                $cool        = (int)$failedObj['cooldown_minutes'];
                $failAt      = $progress['last_fail_at'] ? strtotime($progress['last_fail_at']) : time();
                $secsLeft    = max(0, $failAt + $cool * 60 - time());
                $missed      = $this->lastMissed($failedQuiz);
            }
        }
        $waiting = ($flagged && $secsLeft > 0);

        $state = ['quiz' => null, 'exam' => null, 'project' => null];
        if ($quiz) {
            if ($quiz['passed'])                              $state['quiz'] = 'passed';
            elseif ($completed)                               $state['quiz'] = 'closed';
            elseif ($waiting && $failedKind === 'quiz')       $state['quiz'] = 'restudy';
            else                                              $state['quiz'] = 'ready';
        }
        if ($exam) {
            if ($exam['passed'])                              $state['exam'] = 'passed';
            elseif ($completed)                               $state['exam'] = 'closed';
            elseif ($waiting && $failedKind === 'exam')       $state['exam'] = 'restudy';
            else                                              $state['exam'] = 'ready';
        }
        if ($project) {
            $sub = $project['submission'] ?? null;
            if ($project['passed'])                           $state['project'] = 'passed';
            elseif ($completed)                               $state['project'] = 'closed';
            elseif ($sub && $sub['status'] === 'revision')    $state['project'] = 'revision';
            elseif ($sub && $sub['status'] === 'submitted')   $state['project'] = 'submitted';
            else                                              $state['project'] = 'ready';
        }

        // the one thing the student should do now
        $next = null;
        if (!$completed) {
            if (!$waiting && $state['quiz'] === 'ready')                                   $next = 'quiz';
            elseif (!$waiting && $state['exam'] === 'ready')                               $next = 'exam';
            elseif (in_array($state['project'], ['ready', 'revision'], true))              $next = 'project';
        }

        return [
            'completed'    => $completed,
            'flagged'      => $flagged,
            'waiting'      => $waiting,
            'seconds_left' => $secsLeft,
            'failed_title' => $failedTitle,
            'failed_quiz'  => $failedQuiz,
            'missed'       => $missed,
            'next'         => $next,
            'state'        => $state,
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
              WHERE z.taskID = :t AND z.kind IN ('quiz','exam')
              GROUP BY a.studentID, z.kind",
            [':t' => $taskID]
        ) as $a) {
            $att[(int)$a['studentID']][$a['kind']] = $a;
        }

        $projSubs = [];
        foreach ($this->all(
            "SELECT ps.submissionID, ps.studentID, ps.link_url, ps.file_name, ps.stored_name, ps.notes,
                    ps.status, ps.score, ps.feedback, ps.submitted_at, ps.graded_at
               FROM syllabus_project_submissions ps
               JOIN syllabus_projects sp ON sp.projectID = ps.projectID
              WHERE sp.taskID = :t",
            [':t' => $taskID]
        ) as $ps) {
            $projSubs[(int)$ps['studentID']] = $ps;
        }

        $rows = [];
        foreach ($roster as $r) {
            $sid = (int)$r['studentID'];
            $p   = $prog[$sid] ?? null;
            $state = !$p ? 'not-started'
                   : ($p['status'] === 'completed' ? 'completed'
                   : ((int)$p['needs_restudy'] === 1 ? 'restudy' : 'progress'));
            $rows[] = [
                'studentID' => $sid,
                'name'      => $r['name'],
                'state'     => $state,
                'last'      => $p['last_opened_at'] ?? null,
                'quiz'      => $att[$sid]['quiz'] ?? null,
                'exam'      => $att[$sid]['exam'] ?? null,
                'project'   => $projSubs[$sid] ?? null,
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
       RECORDED LESSONS ARE EMBEDDED FROM A LINK (nothing is stored on the school server)
       Recognised: YouTube, Vimeo, Google Drive. Any other https link (Zoom, Teams, ...)
       is shown as an "Open recording" button. Only these hosts are ever put in an iframe.
    --------------------------------------------------------- */
    public static function parseVideoLink(string $url): array
    {
        $url = trim($url);
        $out = ['ok' => false, 'provider' => 'link', 'embed' => null, 'url' => $url];
        if ($url === '' || strlen($url) > 500) return $out;

        $p = parse_url($url);
        if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) return $out;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return $out;

        $out['ok'] = true;
        $host = strtolower((string)preg_replace('/^www\./i', '', $p['host']));
        $path = (string)($p['path'] ?? '');
        $q = [];
        parse_str((string)($p['query'] ?? ''), $q);

        $start = 0;                                           // "&t=90" / "&t=90s" / "?start=90"
        $t = (string)($q['t'] ?? ($q['start'] ?? ''));
        if (preg_match('/^(\d{1,6})s?$/', $t, $tm)) $start = (int)$tm[1];

        // YouTube
        $yt = '';
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch' && isset($q['v'])) $yt = (string)$q['v'];
            elseif (preg_match('#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,20})#', $path, $m)) $yt = $m[1];
        } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{6,20})#', $path, $m)) {
            $yt = $m[1];
        }
        if ($yt !== '' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $yt)) {
            $out['provider'] = 'youtube';
            $out['embed']    = 'https://www.youtube-nocookie.com/embed/' . $yt . ($start > 0 ? '?start=' . $start : '');
            return $out;
        }

        // Vimeo (public, or unlisted with a hash)
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)
            && preg_match('#^/(?:video/)?(\d{5,12})(?:/([a-f0-9]{6,20}))?$#i', $path, $m)) {
            $h = $m[2] ?? ($q['h'] ?? '');
            $out['provider'] = 'vimeo';
            $out['embed']    = 'https://player.vimeo.com/video/' . $m[1] . (preg_match('/^[a-f0-9]{6,20}$/i', (string)$h) ? '?h=' . $h : '');
            return $out;
        }

        // Google Drive file (must be shared as "Anyone with the link")
        if ($host === 'drive.google.com' && preg_match('#^/file/d/([A-Za-z0-9_-]{10,80})#', $path, $m)) {
            $out['provider'] = 'drive';
            $out['embed']    = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
            return $out;
        }

        return $out;      // valid https link, but not embeddable: shown as a button
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
            if (in_array($action, ['mark_finished', 'run_start', 'run_answer', 'run_submit', 'submit_project'], true)) {
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
        $out = ['quiz' => null, 'exam' => null, 'project' => null];
        foreach ($this->all(
            "SELECT z.*, (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
               FROM syllabus_quizzes z WHERE z.taskID = :t AND z.kind IN ('quiz','exam') AND z.is_published = 1",
            [':t' => $taskID]
        ) as $z) {
            if ((int)$z['qn'] >= 1) $out[$z['kind']] = $z;
        }
        $proj = $this->one(
            "SELECT * FROM syllabus_projects WHERE taskID = :t AND is_published = 1",
            [':t' => $taskID]
        );
        if ($proj) $out['project'] = $proj;
        return $out;
    }

    /** Marks the topic completed for a student when all published assessments (quiz, exam, project) are satisfied. */
    private function checkTopicCompletion(int $taskID, int $studentID): bool
    {
        $pa = $this->publishedAssessments($taskID);
        if (!$pa['quiz'] && !$pa['exam'] && !$pa['project']) return false;

        foreach (['quiz', 'exam'] as $k) {
            if ($pa[$k] && !$this->value(
                "SELECT 1 FROM syllabus_attempts WHERE quizID = :q AND studentID = :s AND passed = 1 LIMIT 1",
                [':q' => $pa[$k]['quizID'], ':s' => $studentID]
            )) {
                return false;
            }
        }
        if ($pa['project']) {
            $sub = $this->one(
                "SELECT status FROM syllabus_project_submissions WHERE projectID = :p AND studentID = :s",
                [':p' => $pa['project']['projectID'], ':s' => $studentID]
            );
            if (!$sub) return false;
            if ((int)$pa['project']['require_approval'] === 1 && $sub['status'] !== 'approved') return false;
        }

        $now = $this->nowStr();
        $this->run(
            "INSERT INTO syllabus_progress (studentID, taskID, status, first_opened_at, last_opened_at, completed_at, needs_restudy)
             VALUES (:s, :t, 'completed', :n1, :n2, :n3, 0)
             ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, :n4), needs_restudy = 0",
            [':s' => $studentID, ':t' => $taskID, ':n1' => $now, ':n2' => $now, ':n3' => $now, ':n4' => $now]
        );
        return true;
    }

    private function handleStudentAction(string $action): void
    {
        switch ($action) {

            case 'mark_finished':
                $taskID = $this->intParam('taskID');
                $this->studentTask($taskID);
                $a = $this->publishedAssessments($taskID);
                if ($a['quiz'] && !$this->value(
                    "SELECT 1 FROM syllabus_attempts WHERE quizID = :q AND studentID = :s AND passed = 1 LIMIT 1",
                    [':q' => $a['quiz']['quizID'], ':s' => $this->userID]
                )) {
                    $this->fail('Pass the quiz to complete this topic.', 409);
                }
                if ($a['exam'] && !$this->value(
                    "SELECT 1 FROM syllabus_attempts WHERE quizID = :q AND studentID = :s AND passed = 1 LIMIT 1",
                    [':q' => $a['exam']['quizID'], ':s' => $this->userID]
                )) {
                    $this->fail('Pass the exam to complete this topic.', 409);
                }
                if ($a['project']) {
                    $sub = $this->one(
                        "SELECT status FROM syllabus_project_submissions WHERE projectID = :p AND studentID = :s",
                        [':p' => $a['project']['projectID'], ':s' => $this->userID]
                    );
                    if (!$sub) $this->fail('Submit the project to complete this topic.', 409);
                    if ((int)$a['project']['require_approval'] === 1 && $sub['status'] !== 'approved') {
                        $this->fail('Wait for your instructor to approve your project submission.', 409);
                    }
                }
                $this->recordOpen($taskID);   // makes sure a progress row exists
                $this->run(
                    "UPDATE syllabus_progress SET status = 'completed', completed_at = :n, needs_restudy = 0
                      WHERE studentID = :s AND taskID = :t",
                    [':n' => $this->nowStr(), ':s' => $this->userID, ':t' => $taskID]
                );
                break;

            case 'submit_project':
                $projectID = $this->intParam('projectID');
                $proj = $this->one(
                    "SELECT * FROM syllabus_projects WHERE projectID = :p AND is_published = 1",
                    [':p' => $projectID]
                );
                if (!$proj) $this->fail('This project is not available.', 404);
                $taskID = (int)$proj['taskID'];
                $this->studentTask($taskID);
                $this->recordOpen($taskID);

                $existing = $this->one(
                    "SELECT submissionID, stored_name, file_name, mime_type, file_size, status
                       FROM syllabus_project_submissions
                      WHERE projectID = :p AND studentID = :s",
                    [':p' => $projectID, ':s' => $this->userID]
                );
                if ($existing && $existing['status'] === 'approved') {
                    $this->fail('Your project has already been approved.', 409);
                }

                $subType = (string)$proj['submission_type'];
                $link    = trim((string)($_POST['link_url'] ?? ''));
                if ($link !== '') {
                    if (strlen($link) > 500 || !preg_match('#^https?://#i', $link) || !filter_var($link, FILTER_VALIDATE_URL)) {
                        $this->fail('Enter a valid http:// or https:// link.');
                    }
                }
                $notes = $this->optionalText('notes', 4000, true);

                $hasNewFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $file = null;
                if ($hasNewFile) {
                    if ($subType === 'link') $this->fail('This project accepts a link only.');
                    $file = $this->storeUpload('project');
                }
                if ($subType === 'link' && $link === '') {
                    $this->fail('Paste a link to your project.');
                }
                if ($subType === 'file' && !$file && !($existing && !empty($existing['stored_name']))) {
                    $this->fail('Upload your project file.');
                }
                if ($subType === 'both' && $link === '' && !$file && !($existing && !empty($existing['stored_name']))) {
                    $this->fail('Attach a file or paste a link to your project.');
                }

                $stored = $file ? $file['stored'] : ($existing['stored_name'] ?? null);
                $fname  = $file ? $file['name']   : ($existing['file_name'] ?? null);
                $mime   = $file ? $file['mime']   : ($existing['mime_type'] ?? null);
                $fsize  = $file ? $file['size']   : ($existing['file_size'] ?? null);
                $oldStored = ($file && $existing && !empty($existing['stored_name'])) ? $existing['stored_name'] : null;

                $now = $this->nowStr();
                try {
                    if ($existing) {
                        $this->run(
                            "UPDATE syllabus_project_submissions
                                SET link_url = :l, file_name = :fn, stored_name = :sn, mime_type = :mt,
                                    file_size = :fs, notes = :nt, status = 'submitted', submitted_at = :now
                              WHERE submissionID = :id",
                            [
                                ':l' => ($link !== '' ? $link : null), ':fn' => $fname, ':sn' => $stored,
                                ':mt' => $mime, ':fs' => $fsize, ':nt' => $notes, ':now' => $now,
                                ':id' => (int)$existing['submissionID'],
                            ]
                        );
                    } else {
                        $this->run(
                            "INSERT INTO syllabus_project_submissions
                                (projectID, studentID, link_url, file_name, stored_name, mime_type, file_size, notes, status, submitted_at)
                             VALUES (:p, :s, :l, :fn, :sn, :mt, :fs, :nt, 'submitted', :now)",
                            [
                                ':p' => $projectID, ':s' => $this->userID, ':l' => ($link !== '' ? $link : null),
                                ':fn' => $fname, ':sn' => $stored, ':mt' => $mime, ':fs' => $fsize,
                                ':nt' => $notes, ':now' => $now,
                            ]
                        );
                    }
                } catch (PDOException $ex) {
                    if ($file) $this->removeFiles([$file['stored']]);
                    throw $ex;
                }
                if ($oldStored) $this->removeFiles([$oldStored]);
                $this->checkTopicCompletion($taskID, $this->userID);
                break;

            case 'run_start':
                if ((string)($_POST['type'] ?? '') === 'practice') $this->startPractice(); else $this->startCheckpoint();
                break;

            case 'run_answer':
                $this->runAnswer();
                break;

            case 'run_submit':
                $this->runSubmit();
                break;

        }
    }

    /* ---------------------------------------------------------
       QUIZ RUNNER  (graded checkpoints + ungraded practice)

       Graded modes  (the quiz's `mode`)
         standard    all questions on one page, submit at the end
         instant     one question at a time, right answer + explanation shown straight away
         timed       countdown, answers locked until submit, no feedback
         confidence  student rates how sure they are; sure+right earns more, sure+wrong costs points
         adaptive    next question gets harder after a correct answer, easier after a miss
         survival    limited lives; a wrong answer costs one
       Practice modes (never affect completion)
         study | flash | mistakes | spaced
       Answers are always checked on the server; the browser never receives the answer key
       before the student has answered.
    --------------------------------------------------------- */
    private const MODES       = ['standard', 'instant', 'timed', 'confidence', 'adaptive', 'survival'];
    private const BATCH_MODES = ['standard', 'timed', 'confidence'];
    private const SAVE_MODES  = ['standard', 'instant', 'timed'];   // the modes professors can pick today (the others keep working for old quizzes)
    private const SRS_SECONDS = [600, 86400, 259200, 604800, 1209600, 2592000];   // box 0..5: 10 min, 1d, 3d, 7d, 14d, 30d

    private function loadQuestion(int $qid): ?array
    {
        $q = $this->one(
            "SELECT questionID, quizID, prompt, explanation, difficulty FROM syllabus_questions WHERE questionID = :q",
            [':q' => $qid]
        );
        if (!$q) return null;
        $q['choices'] = $this->all(
            "SELECT choiceID, label, is_correct FROM syllabus_choices WHERE questionID = :q ORDER BY sort_order, choiceID",
            [':q' => $qid]
        );
        return $q;
    }

    private function correctChoice(array $q): ?array
    {
        foreach ($q['choices'] as $c) {
            if ((int)$c['is_correct'] === 1) return $c;
        }
        return null;
    }

    /** A question as the student's browser may see it (no answer key). */
    private function publicQuestion(array $q): array
    {
        $ch = $q['choices'];
        shuffle($ch);
        return [
            'id'      => (int)$q['questionID'],
            'prompt'  => $q['prompt'],
            'choices' => array_map(fn($c) => ['id' => (int)$c['choiceID'], 'label' => $c['label']], $ch),
        ];
    }

    /** Remember how the student did on a question (feeds Mistake review + Spaced review). */
    private function recordAnswer(int $questionID, bool $ok): void
    {
        $row = $this->one(
            "SELECT streak, wrong_count, correct_count, box, mistake_open FROM syllabus_qstate WHERE studentID = :s AND questionID = :q",
            [':s' => $this->userID, ':q' => $questionID]
        );
        $streak = $row ? (int)$row['streak'] : 0;
        $box    = $row ? (int)$row['box'] : 0;
        $wrong  = $row ? (int)$row['wrong_count'] : 0;
        $right  = $row ? (int)$row['correct_count'] : 0;
        $open   = $row ? (int)$row['mistake_open'] : 0;

        if ($ok) {
            $streak++;
            $right++;
            $box = min(5, $box + 1);
            if ($open === 1 && $streak >= 2) $open = 0;     // cleared: two correct in a row
        } else {
            $streak = 0;
            $wrong++;
            $box  = 0;
            $open = 1;
        }

        $now = date('Y-m-d H:i:s');
        $due = date('Y-m-d H:i:s', time() + self::SRS_SECONDS[$box]);
        $this->run(
            "INSERT INTO syllabus_qstate (studentID, questionID, streak, wrong_count, correct_count, box, due_at, mistake_open, last_seen)
             VALUES (:s, :q, :st, :w, :c, :b, :d, :m, :n)
             ON DUPLICATE KEY UPDATE streak = :st2, wrong_count = :w2, correct_count = :c2, box = :b2, due_at = :d2, mistake_open = :m2, last_seen = :n2",
            [
                ':s' => $this->userID, ':q' => $questionID, ':st' => $streak, ':w' => $wrong, ':c' => $right, ':b' => $box, ':d' => $due, ':m' => $open, ':n' => $now,
                ':st2' => $streak, ':w2' => $wrong, ':c2' => $right, ':b2' => $box, ':d2' => $due, ':m2' => $open, ':n2' => $now,
            ]
        );
    }

    /** Question ids a student may practise on this topic (published checkpoints that allow practice). */
    private function practiceQuestionIds(int $taskID): array
    {
        $rows = $this->all(
            "SELECT q.questionID
               FROM syllabus_questions q
               JOIN syllabus_quizzes z ON z.quizID = q.quizID
              WHERE z.taskID = :t AND z.kind IN ('quiz','exam') AND z.is_published = 1 AND z.allow_practice = 1
              ORDER BY q.questionID",
            [':t' => $taskID]
        );
        return array_map('intval', array_column($rows, 'questionID'));
    }

    /** Numbers shown on the student's Practice buttons. */
    public function practiceInfo(int $taskID): array
    {
        $info = ['pool' => 0, 'review' => 0];
        $ids  = $this->practiceQuestionIds($taskID);
        if (!$ids || !$this->isStudent) return $info;
        $in = implode(',', $ids);   // integers only
        $info['pool']   = count($ids);
        $info['review'] = (int)$this->value(
            "SELECT COUNT(*) FROM syllabus_qstate
              WHERE studentID = :s AND questionID IN ({$in}) AND (mistake_open = 1 OR due_at <= :n)",
            [':s' => $this->userID, ':n' => date('Y-m-d H:i:s')]
        );
        return $info;
    }

    private function storeRun(array $run): string
    {
        $token = bin2hex(random_bytes(8));
        $runs  = $_SESSION['syl_run'] ?? [];
        while (count($runs) >= 6) array_shift($runs);        // keep the session small
        $runs[$token] = $run;
        $_SESSION['syl_run'] = $runs;
        return $token;
    }

    private function getRun(string $token): array
    {
        $run = $_SESSION['syl_run'][$token] ?? null;
        if (!$run || (int)$run['sid'] !== $this->userID) $this->fail('This session expired. Please start again.', 409);
        return $run;
    }

    private function baseRun(string $type, string $mode, int $taskID, int $quizID, int $n): array
    {
        return [
            'sid' => $this->userID, 'type' => $type, 'mode' => $mode, 'taskID' => $taskID, 'quizID' => $quizID,
            'started' => time(), 'deadline' => null, 'N' => $n, 'lives' => 0,
            'order' => [], 'diff' => [], 'answers' => [], 'level' => 2, 'path' => [],
            'streak' => 0, 'best' => 0, 'count' => 0, 'current' => 0,
        ];
    }

    private function nextQuestionId(array $run): int
    {
        if ($run['mode'] === 'adaptive') {
            $asked = array_map('intval', array_keys($run['answers']));
            $want  = (int)$run['level'];
            foreach ([0, 1, -1, 2, -2] as $d) {            // closest available difficulty
                $lvl = $want + $d;
                if ($lvl < 1 || $lvl > 3) continue;
                $cands = [];
                foreach ($run['diff'] as $qid => $df) {
                    if ((int)$df === $lvl && !in_array((int)$qid, $asked, true)) $cands[] = (int)$qid;
                }
                if ($cands) return $cands[array_rand($cands)];
            }
            return 0;
        }
        return (int)($run['order'][$run['count']] ?? 0);
    }

    private function startCheckpoint(): void
    {
        $quiz = $this->one(
            "SELECT z.*, (SELECT COUNT(*) FROM syllabus_questions q WHERE q.quizID = z.quizID) AS qn
               FROM syllabus_quizzes z WHERE z.quizID = :q AND z.kind IN ('quiz','exam')",
            [':q' => $this->intParam('quizID')]
        );
        if (!$quiz || (int)$quiz['is_published'] !== 1 || (int)$quiz['qn'] < 1) $this->fail('This checkpoint is not available.', 404);
        $taskID = (int)$quiz['taskID'];
        $this->studentTask($taskID);
        $this->recordOpen($taskID);

        $p = $this->progressFor($taskID);
        if ($p && $p['status'] === 'completed') $this->fail('You have already completed this topic.', 409);
        if ($p && (int)$p['needs_restudy'] === 1) {
            $cool = (int)$this->value("SELECT cooldown_minutes FROM syllabus_quizzes WHERE quizID = :q", [':q' => (int)($p['last_fail_quizID'] ?? 0)]);
            $left = strtotime($p['last_fail_at'] ?: $this->nowStr()) + $cool * 60 - time();
            if ($left > 0) $this->fail('Review the lesson first. You can retake in ' . (int)ceil($left / 60) . ' minute(s).', 409);
            // the wait is over: the retake is open again
            $this->run("UPDATE syllabus_progress SET needs_restudy = 0 WHERE studentID = :s AND taskID = :t", [':s' => $this->userID, ':t' => $taskID]);
        }

        $mode = in_array($quiz['mode'], self::MODES, true) ? $quiz['mode'] : 'standard';
        $rows = $this->all("SELECT questionID, difficulty FROM syllabus_questions WHERE quizID = :q ORDER BY questionID", [':q' => $quiz['quizID']]);
        shuffle($rows);
        $count = count($rows);
        $limit = $quiz['draw_count'] ? (int)$quiz['draw_count'] : ($mode === 'adaptive' ? 10 : $count);
        $limit = max(1, min($limit, $count));

        $run = $this->baseRun('checkpoint', $mode, $taskID, (int)$quiz['quizID'], $limit);
        $out = [
            'ok' => true, 'mode' => $mode, 'title' => $quiz['title'], 'kind' => $quiz['kind'],
            'pass_percent' => (int)$quiz['pass_percent'], 'total' => $limit,
        ];

        if ($mode === 'adaptive') {
            foreach ($rows as $r) $run['diff'][(int)$r['questionID']] = (int)$r['difficulty'];
        } else {
            $run['order'] = array_map(fn($r) => (int)$r['questionID'], array_slice($rows, 0, $limit));
        }
        if ($mode === 'survival') {
            $run['lives'] = max(1, (int)$quiz['lives']);
            $out['lives'] = $run['lives'];
        }
        if ($mode === 'timed') {
            $minutes = $quiz['time_limit_minutes'] ? (int)$quiz['time_limit_minutes'] : 20;
            $run['deadline']   = time() + $minutes * 60;
            $out['time_limit'] = $minutes * 60;
        }

        if (in_array($mode, self::BATCH_MODES, true)) {
            $questions = [];
            foreach ($run['order'] as $qid) {
                $q = $this->loadQuestion((int)$qid);
                if ($q) $questions[] = $this->publicQuestion($q);
            }
            $out['batch']     = true;
            $out['questions'] = $questions;
            $out['run']       = $this->storeRun($run);
        } else {
            $run['current'] = $this->nextQuestionId($run);
            $first = $run['current'] ? $this->loadQuestion($run['current']) : null;
            if (!$first) $this->fail('This checkpoint is not available.', 404);
            $out['batch'] = false;
            $out['first'] = $this->publicQuestion($first);
            $out['run']   = $this->storeRun($run);
        }
        $this->reply($out);
    }

    private function startPractice(): void
    {
        $taskID = $this->intParam('taskID');
        $this->studentTask($taskID);
        $this->recordOpen($taskID);

        $mode = (string)($_POST['mode'] ?? 'study');
        if (!in_array($mode, ['study', 'flash', 'review'], true)) $this->fail('Unknown practice mode.');

        $ids = $this->practiceQuestionIds($taskID);
        if (!$ids) $this->fail('There are no practice questions for this topic yet.', 404);
        $in = implode(',', $ids);   // integers only

        if ($mode === 'review') {   // questions missed before + questions that are due to come back
            $ids = array_map('intval', array_column($this->all(
                "SELECT questionID FROM syllabus_qstate
                  WHERE studentID = :s AND questionID IN ({$in}) AND (mistake_open = 1 OR due_at <= :n)",
                [':s' => $this->userID, ':n' => date('Y-m-d H:i:s')]
            ), 'questionID'));
            if (!$ids) $this->fail('Nothing to review right now. Nice work!', 409);
        }

        shuffle($ids);
        $ids = array_slice($ids, 0, $mode === 'review' ? 10 : 20);

        $run = $this->baseRun('practice', $mode, $taskID, 0, count($ids));
        $run['order']   = $ids;
        $run['current'] = $ids[0];
        $first = $this->loadQuestion($run['current']);
        if (!$first) $this->fail('Question not found.', 404);

        $this->reply([
            'ok' => true, 'batch' => false, 'mode' => $mode, 'total' => count($ids),
            'first' => $this->publicQuestion($first), 'run' => $this->storeRun($run),
        ]);
    }

    /** One answer in a step-by-step run (instant / adaptive / survival / all practice modes). */
    private function runAnswer(): void
    {
        $token = (string)($_POST['run'] ?? '');
        $run   = $this->getRun($token);
        if (in_array($run['mode'], self::BATCH_MODES, true)) $this->fail('Submit the whole attempt at once.', 409);

        $qid = $this->intParam('questionID');
        if ($qid !== (int)$run['current']) $this->fail('Unexpected question. Please start again.', 409);
        $q = $this->loadQuestion($qid);
        if (!$q) $this->fail('Question not found.', 404);

        $right  = $this->correctChoice($q);
        $picked = $this->intParam('choiceID');                       // 0 = "I don't know"
        $ok     = ($right && (int)$right['choiceID'] === $picked);
        $this->recordAnswer($qid, $ok);

        $run['answers'][$qid] = ['ok' => $ok];
        $run['count']++;
        if ($ok) { $run['streak']++; $run['best'] = max($run['best'], $run['streak']); } else { $run['streak'] = 0; }
        if ($run['mode'] === 'survival' && !$ok) $run['lives']--;
        if ($run['mode'] === 'adaptive') {
            $run['path'][] = (int)$run['level'];
            $run['level']  = $ok ? min(3, $run['level'] + 1) : max(1, $run['level'] - 1);
        }

        $fb = ['ok' => true, 'correct' => $ok, 'lives' => (int)$run['lives']];
        if ($run['type'] === 'practice' || $run['mode'] === 'instant') {      // full feedback only where the mode promises it
            $fb['correct_label'] = $right['label'] ?? '';
            $fb['explanation']   = $q['explanation'] ?? '';
        }
        if ($run['mode'] === 'adaptive') $fb['level'] = (int)$run['level'];

        $finished = ($run['count'] >= $run['N']) || ($run['mode'] === 'survival' && $run['lives'] <= 0);
        if (!$finished) {
            $next = $this->nextQuestionId($run);
            if ($next === 0) {
                $finished = true;
            } else {
                $nq = $this->loadQuestion($next);
                if (!$nq) $finished = true;
                else {
                    $run['current'] = $next;
                    $fb['next']  = $this->publicQuestion($nq);
                    $fb['index'] = $run['count'] + 1;
                }
            }
        }

        if ($finished) {
            unset($_SESSION['syl_run'][$token]);
            if ($run['type'] === 'checkpoint') {
                $fb['result'] = $this->finishCheckpoint($run);
            } else {
                $good = 0;
                foreach ($run['answers'] as $a) { if (!empty($a['ok'])) $good++; }
                $fb['summary'] = ['correct' => $good, 'total' => $run['count'], 'mode' => $run['mode']];
            }
        } else {
            $_SESSION['syl_run'][$token] = $run;
        }
        $this->reply($fb);
    }

    /** Whole-attempt submit for standard / timed / confidence. */
    private function runSubmit(): void
    {
        $token = (string)($_POST['run'] ?? '');
        $run   = $this->getRun($token);
        if ($run['type'] !== 'checkpoint' || !in_array($run['mode'], self::BATCH_MODES, true)) $this->fail('Unexpected submit.', 409);

        $answers = json_decode((string)($_POST['answers'] ?? ''), true);
        $confs   = json_decode((string)($_POST['conf'] ?? ''), true);
        if (!is_array($answers)) $answers = [];
        if (!is_array($confs))   $confs = [];

        $timedOut = ($run['deadline'] !== null && time() > $run['deadline'] + 20);   // 20 s grace for slow networks
        $levels   = ['low', 'med', 'high'];

        if ($run['mode'] === 'confidence' && !$timedOut) {
            foreach ($run['order'] as $qid) {
                $picked = (int)($answers[(string)$qid] ?? 0);
                if ($picked > 0 && !in_array((string)($confs[(string)$qid] ?? ''), $levels, true)) {
                    $this->fail('Tell us how sure you are for every question.', 400);
                }
            }
        }

        $run['answers'] = [];
        foreach ($run['order'] as $qid) {
            $q = $this->loadQuestion((int)$qid);
            if (!$q) continue;
            $right  = $this->correctChoice($q);
            $picked = $timedOut ? 0 : (int)($answers[(string)$qid] ?? 0);
            $ok     = ($right && (int)$right['choiceID'] === $picked);
            $conf   = (string)($confs[(string)$qid] ?? 'med');
            if (!in_array($conf, $levels, true)) $conf = 'med';
            $this->recordAnswer((int)$qid, $ok);
            $run['answers'][(int)$qid] = ['ok' => $ok, 'conf' => $conf];
        }

        unset($_SESSION['syl_run'][$token]);
        $res = $this->finishCheckpoint($run);
        if ($timedOut) $res['notes'][] = 'Time ran out, so this attempt was marked as not passed.';
        $this->reply($res);
    }

    /** Scores a finished graded attempt, saves it, and updates the student's progress. */
    private function finishCheckpoint(array $run): array
    {
        $quizID  = (int)$run['quizID'];
        $taskID  = (int)$run['taskID'];
        $quiz    = $this->one("SELECT * FROM syllabus_quizzes WHERE quizID = :q", [':q' => $quizID]);
        $mode    = $run['mode'];
        $planned = max(1, (int)$run['N']);
        $answers = $run['answers'];

        $correct = 0;
        foreach ($answers as $a) { if (!empty($a['ok'])) $correct++; }
        $score   = (int)round($correct / $planned * 100);
        $notes   = [];
        $details = ['mode' => $mode];

        if ($mode === 'confidence') {
            $pts = 0; $hiWrong = 0; $loRight = 0;
            foreach ($answers as $a) {
                $c = $a['conf'] ?? 'med';
                if (!empty($a['ok'])) {
                    $pts += ['low' => 1, 'med' => 2, 'high' => 3][$c] ?? 2;
                    if ($c === 'low') $loRight++;
                } else {
                    $pts += ['low' => 0, 'med' => -2, 'high' => -6][$c] ?? -2;
                    if ($c === 'high') $hiWrong++;
                }
            }
            $score = (int)round(max(0, $pts) / (3 * $planned) * 100);
            $details += ['points' => $pts, 'high_wrong' => $hiWrong, 'low_right' => $loRight];
            $notes[] = $correct . ' of ' . $planned . ' answers were correct. Confidence score: ' . $score . '%.';
            if ($hiWrong > 0) $notes[] = 'You were very sure but wrong on ' . $hiWrong . ' question' . ($hiWrong === 1 ? '' : 's') . '. Those cost the most points.';
            if ($loRight > 0) $notes[] = 'You got ' . $loRight . ' right while unsure. Trust what you know.';
        } elseif ($mode === 'adaptive') {
            $pts = 0;
            foreach ($answers as $qid => $a) { if (!empty($a['ok'])) $pts += (int)($run['diff'][$qid] ?? 2); }
            $score = (int)round($pts / (3 * $planned) * 100);
            $names = [1 => 'Easy', 2 => 'Medium', 3 => 'Hard'];
            $details += ['final_level' => (int)$run['level'], 'path' => $run['path']];
            $notes[] = 'You finished at the ' . $names[(int)$run['level']] . ' level. Harder questions are worth more points.';
        } elseif ($mode === 'survival') {
            $details += ['lives_left' => (int)$run['lives'], 'best_streak' => (int)$run['best']];
            if ($run['lives'] <= 0) $notes[] = 'You ran out of lives after ' . count($answers) . ' answer' . (count($answers) === 1 ? '' : 's') . '.';
            else                    $notes[] = 'You made it to the end with ' . (int)$run['lives'] . ' life' . ($run['lives'] === 1 ? '' : 's') . ' left.';
            $notes[] = 'Longest streak: ' . (int)$run['best'] . ' in a row.';
        } elseif ($mode === 'timed') {
            $details['seconds'] = time() - (int)$run['started'];
        }

        $score  = max(0, min(100, $score));
        $passed = ($score >= (int)$quiz['pass_percent']);
        if ($mode === 'survival' && $run['lives'] <= 0) $passed = false;
        $now = $this->nowStr();

        $this->run(
            "INSERT INTO syllabus_attempts (quizID, studentID, score_pct, correct_count, total_count, passed, answers, submitted_at, mode, details)
             VALUES (:q, :s, :p, :c, :t, :ps, :a, :n, :m, :d)",
            [
                ':q' => $quizID, ':s' => $this->userID, ':p' => $score, ':c' => $correct, ':t' => $planned,
                ':ps' => $passed ? 1 : 0, ':a' => json_encode($answers), ':n' => $now, ':m' => $mode, ':d' => json_encode($details),
            ]
        );

        $completed = false;
        if ($passed) {
            $completed = $this->checkTopicCompletion($taskID, $this->userID);
        } else {
            $this->run(
                "UPDATE syllabus_progress SET needs_restudy = 1, last_fail_at = :n, last_fail_quizID = :q, restudy_hits = 0
                  WHERE studentID = :s AND taskID = :t AND status <> 'completed'",
                [':n' => $now, ':q' => $quizID, ':s' => $this->userID, ':t' => $taskID]
            );
        }

        $missed = [];
        $review = [];
        foreach ($answers as $qid => $a) {
            $q = $this->loadQuestion((int)$qid);
            if (!$q) continue;
            $right = $this->correctChoice($q);
            if (empty($a['ok'])) $missed[] = $q['prompt'];
            $review[] = ['prompt' => $q['prompt'], 'correct' => $right['label'] ?? '', 'explanation' => $q['explanation'] ?? '', 'ok' => !empty($a['ok'])];
        }

        return [
            'ok' => true, 'passed' => $passed, 'score' => $score, 'correct' => $correct, 'total' => $planned,
            'pass_percent' => (int)$quiz['pass_percent'], 'completed' => $completed, 'kind' => $quiz['kind'],
            'mode' => $mode, 'cooldown' => (int)$quiz['cooldown_minutes'], 'notes' => $notes,
            'missed' => $passed ? [] : $missed,
            'review' => $passed ? $review : [],
        ];
    }

    /* ---------------------------------------------------------
       COPY A SYLLABUS FROM ANOTHER CLASS
       Dates are never copied. Quizzes arrive as drafts.
    --------------------------------------------------------- */
    /** number of topics per class, for the "copy from another class" list */
    public function topicCounts(array $schedIDs): array
    {
        $ids = array_values(array_filter(array_map('intval', $schedIDs)));
        if (!$ids) return [];
        $in  = implode(',', $ids);   // integers only
        $out = [];
        foreach ($this->all(
            "SELECT m.schedID, COUNT(t.taskID) AS n
               FROM syllabus_modules m
               LEFT JOIN syllabus_tasks t ON t.moduleID = m.moduleID
              WHERE m.schedID IN ({$in})
              GROUP BY m.schedID"
        ) as $r) {
            $out[(int)$r['schedID']] = (int)$r['n'];
        }
        return $out;
    }

    /** Duplicates an uploaded file under a new random name. Returns the new stored name, or null. */
    private function copyStored(string $stored, array &$created): ?string
    {
        if (!preg_match(self::STORED_RE, $stored)) return null;
        $dir = self::ensureStorage();
        $src = $dir . '/' . $stored;
        if (!is_file($src)) return null;
        $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        $new = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@copy($src, $dir . '/' . $new)) return null;
        $created[] = $new;
        return $new;
    }

    private function copyClass(int $source, int $target, bool $pages, bool $files, bool $quizzes): void
    {
        @set_time_limit(0);                     // big videos can take a while to duplicate
        $created = [];                          // files we made, removed again if anything fails
        $this->pdo->beginTransaction();
        try {
            $base = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) FROM syllabus_modules WHERE schedID = :s", [':s' => $target]);
            $mods = $this->all("SELECT moduleID, title, term FROM syllabus_modules WHERE schedID = :s ORDER BY FIELD(term, 'midterm', 'final'), sort_order, moduleID", [':s' => $source]);
            $targetCal = $this->calendarForSched($target);

            foreach ($mods as $i => $m) {
                $mTerm = in_array($m['term'] ?? '', self::TERMS, true) ? $m['term'] : 'midterm';
                $this->run("INSERT INTO syllabus_modules (schedID, title, term, sort_order) VALUES (:s, :t, :tm, :o)", [':s' => $target, ':t' => $m['title'], ':tm' => $mTerm, ':o' => $base + $i + 1]);
                $newMod = (int)$this->pdo->lastInsertId();

                $tasks = $this->all("SELECT taskID, title, week_no, sort_order FROM syllabus_tasks WHERE moduleID = :m ORDER BY sort_order, taskID", [':m' => $m['moduleID']]);
                foreach ($tasks as $t) {
                    $w = $t['week_no'] !== null ? (int)$t['week_no'] : null;
                    $open = ($targetCal && $w !== null && isset($targetCal['weeks'][$w])) ? $targetCal['weeks'][$w]['start_date'] : null;
                    $due  = ($targetCal && $w !== null && isset($targetCal['weeks'][$w])) ? $targetCal['weeks'][$w]['end_date'] : null;
                    $this->run(
                        "INSERT INTO syllabus_tasks (moduleID, title, week_no, available_from, due_date, sort_order) VALUES (:m, :t, :w, :a, :d, :o)",
                        [':m' => $newMod, ':t' => $t['title'], ':w' => $w, ':a' => $open, ':d' => $due, ':o' => $t['sort_order']]
                    );
                    $newTask = (int)$this->pdo->lastInsertId();
                    $oldTask = (int)$t['taskID'];

                    if ($pages)   $this->copyPage($oldTask, $newTask, $created);
                    if ($files)   $this->copyContents($oldTask, $newTask, $created);
                    if ($quizzes) {
                        $this->copyQuizzes($oldTask, $newTask);
                        $this->copyProject($oldTask, $newTask);
                    }
                }
            }
            $this->pdo->commit();
        } catch (PDOException $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->removeFiles($created);
            throw $ex;
        }
    }

    private function copyPage(int $oldTask, int $newTask, array &$created): void
    {
        $page = $this->one("SELECT html, delta FROM syllabus_pages WHERE taskID = :t", [':t' => $oldTask]);
        if (!$page || (trim((string)$page['html']) === '' && trim((string)$page['delta']) === '')) return;

        // pictures inside the lesson: copy them and point the copied lesson at the new copies
        $map = [];
        foreach ($this->all(
            "SELECT imageID, stored_name, file_name, mime_type, file_size FROM syllabus_images WHERE taskID = :t",
            [':t' => $oldTask]
        ) as $img) {
            $stored = $this->copyStored((string)$img['stored_name'], $created);
            if ($stored === null) continue;
            $this->run(
                "INSERT INTO syllabus_images (taskID, stored_name, file_name, mime_type, file_size, uploaded_by) VALUES (:t, :s, :n, :m, :z, :u)",
                [':t' => $newTask, ':s' => $stored, ':n' => $img['file_name'], ':m' => $img['mime_type'], ':z' => $img['file_size'], ':u' => $this->userID]
            );
            $map[(int)$img['imageID']] = (int)$this->pdo->lastInsertId();
        }
        $rewrite = function ($text) use ($map) {
            return preg_replace_callback('/syllabus-file\?image=(\d+)/', function ($m) use ($map) {
                return 'syllabus-file?image=' . ($map[(int)$m[1]] ?? $m[1]);
            }, (string)$text);
        };
        $this->run(
            "INSERT INTO syllabus_pages (taskID, html, delta, updated_by) VALUES (:t, :h, :d, :u)",
            [':t' => $newTask, ':h' => $rewrite($page['html']), ':d' => $rewrite($page['delta']), ':u' => $this->userID]
        );
    }

    private function copyContents(int $oldTask, int $newTask, array &$created): void
    {
        foreach ($this->all(
            "SELECT kind, title, description, body, file_name, stored_name, mime_type, file_size, is_published, sort_order
               FROM syllabus_contents WHERE taskID = :t ORDER BY sort_order, contentID",
            [':t' => $oldTask]
        ) as $c) {
            $stored = null;
            if (!empty($c['stored_name'])) {
                $stored = $this->copyStored((string)$c['stored_name'], $created);
                if ($stored === null) continue;      // the original file is missing: skip it
            }
            $this->run(
                "INSERT INTO syllabus_contents (taskID, kind, title, description, body, file_name, stored_name, mime_type, file_size, is_published, sort_order, uploaded_by)
                 VALUES (:t, :k, :ti, :d, :b, :fn, :sn, :mt, :fs, :p, :o, :u)",
                [
                    ':t' => $newTask, ':k' => $c['kind'], ':ti' => $c['title'], ':d' => $c['description'], ':b' => $c['body'],
                    ':fn' => $c['file_name'], ':sn' => $stored, ':mt' => $c['mime_type'], ':fs' => $c['file_size'],
                    ':p' => $c['is_published'], ':o' => $c['sort_order'], ':u' => $this->userID,
                ]
            );
        }
    }

    private function copyQuizzes(int $oldTask, int $newTask): void
    {
        foreach ($this->all(
            "SELECT quizID, kind, mode, title, pass_percent, cooldown_minutes, draw_count, time_limit_minutes, lives, allow_practice
               FROM syllabus_quizzes WHERE taskID = :t AND kind IN ('quiz','exam')",
            [':t' => $oldTask]
        ) as $z) {
            $this->run(
                "INSERT INTO syllabus_quizzes (taskID, kind, mode, title, pass_percent, cooldown_minutes, draw_count, time_limit_minutes, lives, allow_practice, is_published)
                 VALUES (:t, :k, :m, :ti, :p, :c, :d, :tl, :lv, :ap, 0)",
                [
                    ':t' => $newTask, ':k' => $z['kind'], ':m' => $z['mode'], ':ti' => $z['title'], ':p' => $z['pass_percent'],
                    ':c' => $z['cooldown_minutes'], ':d' => $z['draw_count'], ':tl' => $z['time_limit_minutes'],
                    ':lv' => $z['lives'], ':ap' => $z['allow_practice'],
                ]
            );
            $newQuiz = (int)$this->pdo->lastInsertId();

            foreach ($this->all(
                "SELECT questionID, prompt, explanation, difficulty, sort_order FROM syllabus_questions WHERE quizID = :q ORDER BY sort_order, questionID",
                [':q' => $z['quizID']]
            ) as $q) {
                $this->run(
                    "INSERT INTO syllabus_questions (quizID, prompt, explanation, difficulty, sort_order) VALUES (:z, :p, :e, :d, :o)",
                    [':z' => $newQuiz, ':p' => $q['prompt'], ':e' => $q['explanation'], ':d' => $q['difficulty'], ':o' => $q['sort_order']]
                );
                $newQ = (int)$this->pdo->lastInsertId();
                foreach ($this->all(
                    "SELECT label, is_correct, sort_order FROM syllabus_choices WHERE questionID = :q ORDER BY sort_order, choiceID",
                    [':q' => $q['questionID']]
                ) as $c) {
                    $this->run(
                        "INSERT INTO syllabus_choices (questionID, label, is_correct, sort_order) VALUES (:q, :l, :c, :o)",
                        [':q' => $newQ, ':l' => $c['label'], ':c' => $c['is_correct'], ':o' => $c['sort_order']]
                    );
                }
            }
        }
    }

    private function copyProject(int $oldTask, int $newTask): void
    {
        $p = $this->one(
            "SELECT title, instructions, submission_type, max_score, pass_score, require_approval
               FROM syllabus_projects WHERE taskID = :t",
            [':t' => $oldTask]
        );
        if (!$p) return;
        $this->run(
            "INSERT INTO syllabus_projects (taskID, title, instructions, submission_type, max_score, pass_score, require_approval, is_published)
             VALUES (:t, :ti, :in, :st, :ms, :ps, :ra, 0)",
            [
                ':t' => $newTask, ':ti' => $p['title'], ':in' => $p['instructions'],
                ':st' => $p['submission_type'], ':ms' => $p['max_score'],
                ':ps' => $p['pass_score'], ':ra' => $p['require_approval'],
            ]
        );
    }

    /* ---------------------------------------------------------
       FACULTY INSIGHTS: who needs help, which questions are hardest
    --------------------------------------------------------- */
    private function needsHelp(int $schedID, array $roster, array $stu, int $openTopics): array
    {
        $reasons = [];
        foreach ($this->all(
            "SELECT a.studentID, z.title, COUNT(*) AS tries, SUM(a.passed) AS passes
               FROM syllabus_attempts a
               JOIN syllabus_quizzes z ON z.quizID = a.quizID
               JOIN syllabus_tasks t ON t.taskID = z.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s AND z.kind IN ('quiz','exam')
              GROUP BY a.studentID, a.quizID, z.title",
            [':s' => $schedID]
        ) as $r) {
            if ((int)$r['passes'] === 0 && (int)$r['tries'] >= 2) {
                $reasons[(int)$r['studentID']][] = 'Failed "' . $r['title'] . '" ' . (int)$r['tries'] . ' times';
            }
        }

        $weekAgo = time() - 7 * 86400;
        $out = [];
        foreach ($roster as $r) {
            $sid  = (int)$r['studentID'];
            $list = $reasons[$sid] ?? [];
            if ($openTopics > 0) {
                $last = $stu[$sid]['last'] ?? null;
                if ($last === null)                         $list[] = "Hasn't opened any topic yet";
                elseif (strtotime($last) < $weekAgo)        $list[] = 'No activity in 7+ days';
            }
            if ($list) $out[] = ['name' => $r['name'], 'reasons' => $list];
        }
        usort($out, fn($a, $b) => count($b['reasons']) <=> count($a['reasons']));
        return array_slice($out, 0, 8);
    }

    private function hardQuestions(int $schedID): array
    {
        $rows = $this->all(
            "SELECT q.prompt, SUM(s.wrong_count) AS wrong, SUM(s.wrong_count + s.correct_count) AS total
               FROM syllabus_qstate s
               JOIN syllabus_questions q ON q.questionID = s.questionID
               JOIN syllabus_quizzes z ON z.quizID = q.quizID
               JOIN syllabus_tasks t ON t.taskID = z.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s AND z.kind IN ('quiz','exam')
              GROUP BY q.questionID, q.prompt
             HAVING SUM(s.wrong_count + s.correct_count) >= 3 AND SUM(s.wrong_count) > 0
              ORDER BY SUM(s.wrong_count) / SUM(s.wrong_count + s.correct_count) DESC, SUM(s.wrong_count) DESC
              LIMIT 3",
            [':s' => $schedID]
        );
        foreach ($rows as &$r) {
            $r['rate'] = (int)round((int)$r['wrong'] / max(1, (int)$r['total']) * 100);
        }
        unset($r);
        return $rows;
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
                $term  = (string)($_POST['term'] ?? 'midterm');
                if (!in_array($term, self::TERMS, true)) $term = 'midterm';
                $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_modules WHERE schedID = :s", [':s' => $sched]);
                $this->run("INSERT INTO syllabus_modules (schedID, title, term, sort_order) VALUES (:s, :t, :tm, :o)", [':s' => $sched, ':t' => $title, ':tm' => $term, ':o' => $next]);
                break;

            case 'rename_module':
                $id = $this->intParam('moduleID');
                $this->requireCanManageModule($id);
                $term = (string)($_POST['term'] ?? '');
                if (in_array($term, self::TERMS, true)) {
                    $this->run("UPDATE syllabus_modules SET title = :t, term = :tm WHERE moduleID = :m", [':t' => $this->titleParam('title', 200), ':tm' => $term, ':m' => $id]);
                } else {
                    $this->run("UPDATE syllabus_modules SET title = :t WHERE moduleID = :m", [':t' => $this->titleParam('title', 200), ':m' => $id]);
                }
                break;

            case 'set_module_term':
                $id = $this->intParam('moduleID');
                $this->requireCanManageModule($id);
                $term = (string)($_POST['term'] ?? '');
                if (!in_array($term, self::TERMS, true)) $this->fail('Choose Midterm or Final.');
                $this->run("UPDATE syllabus_modules SET term = :tm WHERE moduleID = :m", [':tm' => $term, ':m' => $id]);
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
                $files = array_merge($files, array_column($this->all(
                    "SELECT ps.stored_name FROM syllabus_project_submissions ps
                       JOIN syllabus_projects sp ON sp.projectID = ps.projectID
                       JOIN syllabus_tasks t ON t.taskID = sp.taskID
                      WHERE t.moduleID = :m AND ps.stored_name IS NOT NULL",
                    [':m' => $id]
                ), 'stored_name'));
                $this->pdo->beginTransaction();
                $this->run("DELETE i FROM syllabus_images i JOIN syllabus_tasks t ON t.taskID = i.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE p FROM syllabus_pages p JOIN syllabus_tasks t ON t.taskID = p.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE c FROM syllabus_contents c JOIN syllabus_tasks t ON t.taskID = c.taskID WHERE t.moduleID = :m", [':m' => $id]);
                $this->run("DELETE FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);   // progress, quizzes, projects, attempts cascade
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
                if ($week !== null && ($open === null || $due === null)) {
                    $schedID = (int)$this->value("SELECT schedID FROM syllabus_modules WHERE moduleID = :m", [':m' => $id]);
                    $cal = $this->calendarForSched($schedID);
                    if ($cal && isset($cal['weeks'][$week])) {
                        if ($open === null) $open = $cal['weeks'][$week]['start_date'];
                        if ($due === null)  $due  = $cal['weeks'][$week]['end_date'];
                    }
                }
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
                if ($week !== null && ($open === null || $due === null)) {
                    $schedID = (int)$this->value(
                        "SELECT m.schedID FROM syllabus_tasks t JOIN syllabus_modules m ON m.moduleID = t.moduleID WHERE t.taskID = :t",
                        [':t' => $id]
                    );
                    $cal = $this->calendarForSched($schedID);
                    if ($cal && isset($cal['weeks'][$week])) {
                        if ($open === null) $open = $cal['weeks'][$week]['start_date'];
                        if ($due === null)  $due  = $cal['weeks'][$week]['end_date'];
                    }
                }
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
                $files = array_merge($files, array_column($this->all(
                    "SELECT ps.stored_name FROM syllabus_project_submissions ps
                       JOIN syllabus_projects sp ON sp.projectID = ps.projectID
                      WHERE sp.taskID = :t AND ps.stored_name IS NOT NULL",
                    [':t' => $id]
                ), 'stored_name'));
                $this->pdo->beginTransaction();
                $this->run("DELETE FROM syllabus_images WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_pages WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_contents WHERE taskID = :t", [':t' => $id]);
                $this->run("DELETE FROM syllabus_tasks WHERE taskID = :t", [':t' => $id]);   // progress, quizzes, projects, attempts cascade
                $this->pdo->commit();
                $this->removeFiles($files);
                break;

            /* ----- academic calendar ----- */
            case 'save_calendar':
                $sched = $this->intParam('schedID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $ep = $this->one(
                    "SELECT ep.acad_year, ep.sem FROM subject_schedules ss JOIN enrollment_period ep ON ep.enrollmentID = ss.enrollmentID WHERE ss.schedID = :s",
                    [':s' => $sched]
                );
                if (!$ep) $this->fail('Class semester not found.', 404);
                $start = $this->dateParam('semester_start');
                if ($start === null) $this->fail('Choose the semester start date.');
                $totalW  = $this->intRangeParam('total_weeks', 4, 26, 18);
                $midW    = $this->intRangeParam('midterm_weeks', 1, $totalW, (int)floor($totalW / 2));
                $midExam = trim((string)($_POST['midterm_exam_week'] ?? '')) !== ''
                           ? $this->intRangeParam('midterm_exam_week', 1, $midW, $midW) : null;
                $finExam = trim((string)($_POST['final_exam_week'] ?? '')) !== ''
                           ? $this->intRangeParam('final_exam_week', $midW + 1 <= $totalW ? $midW + 1 : 1, $totalW, $totalW) : null;

                $this->run(
                    "INSERT INTO syllabus_academic_calendar
                        (acad_year, sem, semester_start, midterm_weeks, total_weeks, midterm_exam_week, final_exam_week, updated_by)
                     VALUES (:ay, :sm, :st, :mw, :tw, :me, :fe, :u)
                     ON DUPLICATE KEY UPDATE
                        semester_start = :st2, midterm_weeks = :mw2, total_weeks = :tw2,
                        midterm_exam_week = :me2, final_exam_week = :fe2, updated_by = :u2",
                    [
                        ':ay' => $ep['acad_year'], ':sm' => (int)$ep['sem'], ':st' => $start, ':mw' => $midW,
                        ':tw' => $totalW, ':me' => $midExam, ':fe' => $finExam, ':u' => $this->userID,
                        ':st2' => $start, ':mw2' => $midW, ':tw2' => $totalW,
                        ':me2' => $midExam, ':fe2' => $finExam, ':u2' => $this->userID,
                    ]
                );
                if (($_POST['sync_dates'] ?? '') === '1') {
                    $cal = $this->academicCalendar((string)$ep['acad_year'], (int)$ep['sem']);
                    if ($cal) $this->syncClassDatesToCalendar($sched, $cal);
                }
                break;

            case 'add_calendar_break':
                $sched = $this->intParam('schedID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $cal = $this->calendarForSched($sched);
                if (!$cal) $this->fail('Save the academic calendar first.');
                $title = $this->titleParam('title', 150);
                $start = $this->dateParam('start_date');
                $end   = $this->dateParam('end_date') ?? $start;
                if ($start === null) $this->fail('Choose a start date for the holiday or break.');
                if ($end < $start) $this->fail('The end date must be on or after the start date.');
                $skip = (($_POST['skip_week'] ?? '') === '1') ? 1 : 0;
                $this->run(
                    "INSERT INTO syllabus_calendar_breaks (calendarID, title, start_date, end_date, skip_week)
                     VALUES (:c, :t, :s, :e, :sk)",
                    [':c' => (int)$cal['calendarID'], ':t' => $title, ':s' => $start, ':e' => $end, ':sk' => $skip]
                );
                break;

            case 'delete_calendar_break':
                $sched   = $this->intParam('schedID');
                $breakID = $this->intParam('breakID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $cal = $this->calendarForSched($sched);
                if (!$cal) $this->fail('Academic calendar not found.', 404);
                $this->run(
                    "DELETE FROM syllabus_calendar_breaks WHERE breakID = :b AND calendarID = :c",
                    [':b' => $breakID, ':c' => (int)$cal['calendarID']]
                );
                break;

            case 'sync_calendar_dates':
                $sched = $this->intParam('schedID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $cal = $this->calendarForSched($sched);
                if (!$cal) $this->fail('Configure the academic calendar for this semester first.');
                $n = $this->syncClassDatesToCalendar($sched, $cal);
                $this->reply(['ok' => true, 'updated' => $n]);
                break;

            case 'scaffold_calendar':
                $sched = $this->intParam('schedID');
                if (!$this->canManageSched($sched)) $this->fail('You cannot edit this class.', 403);
                $cal = $this->calendarForSched($sched);
                if (!$cal) $this->fail('Configure the academic calendar for this semester first.');
                $withWeeks = (($_POST['include_weeks'] ?? '1') === '1');

                $this->pdo->beginTransaction();
                $base = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) FROM syllabus_modules WHERE schedID = :s", [':s' => $sched]);
                $midW = (int)$cal['midterm_weeks'];
                $totW = (int)$cal['total_weeks'];

                $this->run(
                    "INSERT INTO syllabus_modules (schedID, title, term, sort_order) VALUES (:s, :t, 'midterm', :o)",
                    [':s' => $sched, ':t' => 'Midterm Period (Weeks 1–' . $midW . ')', ':o' => $base + 1]
                );
                $midMod = (int)$this->pdo->lastInsertId();

                $finMod = 0;
                if ($totW > $midW) {
                    $this->run(
                        "INSERT INTO syllabus_modules (schedID, title, term, sort_order) VALUES (:s, :t, 'final', :o)",
                        [':s' => $sched, ':t' => 'Final Period (Weeks ' . ($midW + 1) . '–' . $totW . ')', ':o' => $base + 2]
                    );
                    $finMod = (int)$this->pdo->lastInsertId();
                }

                if ($withWeeks) {
                    foreach ($cal['weeks'] as $w) {
                        $modID = ($w['term'] === 'midterm' || $finMod === 0) ? $midMod : $finMod;
                        $topicTitle = $w['exam_label'] !== ''
                            ? $w['exam_label']
                            : 'Week ' . $w['week_no'] . ' Topic';
                        $this->run(
                            "INSERT INTO syllabus_tasks (moduleID, title, week_no, available_from, due_date, sort_order)
                             VALUES (:m, :t, :w, :a, :d, :o)",
                            [':m' => $modID, ':t' => $topicTitle, ':w' => $w['week_no'], ':a' => $w['start_date'], ':d' => $w['end_date'], ':o' => $w['week_no']]
                        );
                    }
                }
                $this->pdo->commit();
                break;

            /* ----- learning contents ----- */
            case 'add_content':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, self::KINDS, true)) $this->fail('Choose a content type.');
                if ($kind === 'note') $this->fail('Write notes in the lesson editor instead.');
                $title = $this->titleParam('title', 200);
                $desc  = $this->optionalText('description', 500);
                $body  = null;
                $file  = null;
                if ($kind === 'video') {                 // a recorded lesson is embedded from a link and never stored here
                    $link = self::parseVideoLink((string)($_POST['url'] ?? ''));
                    if (!$link['ok']) $this->fail('Paste a valid https:// link to the recording (YouTube, Vimeo, Google Drive, Zoom...).');
                    $body = $link['url'];
                } else {
                    $file = $this->storeUpload($kind);   // PDF
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
                } elseif ($row['kind'] === 'video' && empty($row['stored_name'])) {   // an embedded recording: its link can change
                    $link = self::parseVideoLink((string)($_POST['url'] ?? ''));
                    if (!$link['ok']) $this->fail('Paste a valid https:// link to the recording.');
                    $this->run("UPDATE syllabus_contents SET title = :t, description = :d, body = :b WHERE contentID = :c",
                        [':t' => $title, ':d' => $desc, ':b' => $link['url'], ':c' => $row['contentID']]);
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

            /* ----- quiz ----- */
            case 'save_quiz':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, self::QUIZ_KINDS, true)) $this->fail('Choose a valid checkpoint type.');
                $mode    = (string)($_POST['mode'] ?? 'standard');
                $oldMode = (string)$this->value("SELECT mode FROM syllabus_quizzes WHERE taskID = :t AND kind = :k", [':t' => $taskID, ':k' => $kind]);
                if (!in_array($mode, self::SAVE_MODES, true) && $mode !== $oldMode) $this->fail('Choose how this checkpoint is run.');
                if (!in_array($mode, self::MODES, true)) $this->fail('Choose how this checkpoint is run.');
                $title = $this->optionalText('title', 200) ?? ($kind === 'exam' ? 'Exam' : 'Quiz');
                $pass  = $this->intRangeParam('pass_percent', 1, 100, 70);
                $cool  = $this->intRangeParam('cooldown_minutes', 0, 1440, 2);
                $draw  = null;
                if (trim((string)($_POST['draw_count'] ?? '')) !== '') {
                    $draw = $this->intRangeParam('draw_count', 1, 500, 1);
                }
                $limit = ($mode === 'timed') ? $this->intRangeParam('time_limit_minutes', 1, 300, 20) : null;
                $lives = ($mode === 'survival') ? $this->intRangeParam('lives', 1, 10, 3) : 3;
                $prac  = (($_POST['allow_practice'] ?? '') === '1') ? 1 : 0;
                $pub   = (($_POST['published'] ?? '') === '1') ? 1 : 0;
                $this->run(
                    "INSERT INTO syllabus_quizzes (taskID, kind, mode, title, pass_percent, cooldown_minutes, draw_count, time_limit_minutes, lives, allow_practice, is_published)
                     VALUES (:t, :k, :m, :ti, :p, :c, :d, :tl, :lv, :ap, :u)
                     ON DUPLICATE KEY UPDATE mode = :m2, title = :ti2, pass_percent = :p2, cooldown_minutes = :c2, draw_count = :d2,
                                             time_limit_minutes = :tl2, lives = :lv2, allow_practice = :ap2, is_published = :u2",
                    [
                        ':t' => $taskID, ':k' => $kind, ':m' => $mode, ':ti' => $title, ':p' => $pass, ':c' => $cool, ':d' => $draw,
                        ':tl' => $limit, ':lv' => $lives, ':ap' => $prac, ':u' => $pub,
                        ':m2' => $mode, ':ti2' => $title, ':p2' => $pass, ':c2' => $cool, ':d2' => $draw,
                        ':tl2' => $limit, ':lv2' => $lives, ':ap2' => $prac, ':u2' => $pub,
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

            /* ----- project assessment ----- */
            case 'save_project':
                $taskID = $this->intParam('taskID');
                $this->requireCanManageTask($taskID);
                $title = $this->optionalText('title', 200) ?? 'Project';
                $instr = $this->optionalText('instructions', 10000, true);
                $subType = (string)($_POST['submission_type'] ?? 'both');
                if (!in_array($subType, self::PROJECT_SUB_TYPES, true)) $subType = 'both';
                $maxScore  = $this->intRangeParam('max_score', 1, 1000, 100);
                $passScore = $this->intRangeParam('pass_score', 1, $maxScore, min(70, $maxScore));
                $reqApp    = (($_POST['require_approval'] ?? '1') === '1') ? 1 : 0;
                $pub       = (($_POST['published'] ?? '') === '1') ? 1 : 0;

                $this->run(
                    "INSERT INTO syllabus_projects
                        (taskID, title, instructions, submission_type, max_score, pass_score, require_approval, is_published)
                     VALUES (:t, :ti, :in, :st, :ms, :ps, :ra, :pub)
                     ON DUPLICATE KEY UPDATE
                        title = :ti2, instructions = :in2, submission_type = :st2,
                        max_score = :ms2, pass_score = :ps2, require_approval = :ra2, is_published = :pub2",
                    [
                        ':t' => $taskID, ':ti' => $title, ':in' => $instr, ':st' => $subType,
                        ':ms' => $maxScore, ':ps' => $passScore, ':ra' => $reqApp, ':pub' => $pub,
                        ':ti2' => $title, ':in2' => $instr, ':st2' => $subType,
                        ':ms2' => $maxScore, ':ps2' => $passScore, ':ra2' => $reqApp, ':pub2' => $pub,
                    ]
                );
                break;

            case 'delete_project':
                $projectID = $this->intParam('projectID');
                $proj = $this->one("SELECT projectID, taskID FROM syllabus_projects WHERE projectID = :p", [':p' => $projectID]);
                if (!$proj) $this->fail('Project not found.', 404);
                $this->requireCanManageTask((int)$proj['taskID']);
                $files = array_column($this->all(
                    "SELECT stored_name FROM syllabus_project_submissions WHERE projectID = :p AND stored_name IS NOT NULL",
                    [':p' => $projectID]
                ), 'stored_name');
                $this->run("DELETE FROM syllabus_projects WHERE projectID = :p", [':p' => $projectID]);
                $this->removeFiles($files);
                break;

            case 'grade_project':
                $subID = $this->intParam('submissionID');
                $sub = $this->one(
                    "SELECT ps.submissionID, ps.studentID, sp.taskID, sp.max_score, m.schedID
                       FROM syllabus_project_submissions ps
                       JOIN syllabus_projects sp ON sp.projectID = ps.projectID
                       JOIN syllabus_tasks t ON t.taskID = sp.taskID
                       JOIN syllabus_modules m ON m.moduleID = t.moduleID
                      WHERE ps.submissionID = :s",
                    [':s' => $subID]
                );
                if (!$sub || !$this->canManageSched((int)$sub['schedID'])) $this->fail('You cannot grade this submission.', 403);
                $status = (string)($_POST['status'] ?? 'approved');
                if (!in_array($status, ['submitted', 'approved', 'revision'], true)) $this->fail('Invalid status.');
                $scoreRaw = trim((string)($_POST['score'] ?? ''));
                $score = null;
                if ($scoreRaw !== '') {
                    $score = $this->intRangeParam('score', 0, (int)$sub['max_score'], 0);
                }
                $feedback = $this->optionalText('feedback', 4000, true);
                $this->run(
                    "UPDATE syllabus_project_submissions
                        SET status = :st, score = :sc, feedback = :fb, graded_at = :ga, graded_by = :gb
                      WHERE submissionID = :id",
                    [
                        ':st' => $status, ':sc' => $score, ':fb' => $feedback,
                        ':ga' => $this->nowStr(), ':gb' => $this->userID, ':id' => $subID,
                    ]
                );
                if ($status === 'approved') {
                    $this->checkTopicCompletion((int)$sub['taskID'], (int)$sub['studentID']);
                } elseif ($status === 'revision') {
                    $this->run(
                        "UPDATE syllabus_progress SET status = 'in_progress', completed_at = NULL
                          WHERE studentID = :s AND taskID = :t",
                        [':s' => (int)$sub['studentID'], ':t' => (int)$sub['taskID']]
                    );
                }
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
                $explain = $this->optionalText('explanation', 1500, true);
                $diff    = $this->intRangeParam('difficulty', 1, 3, 2);

                if ($qid > 0 && !$this->value("SELECT 1 FROM syllabus_questions WHERE questionID = :q AND quizID = :z", [':q' => $qid, ':z' => $quiz['quizID']])) {
                    $this->fail('Question not found.', 404);
                }
                $this->pdo->beginTransaction();
                if ($qid > 0) {
                    $this->run("UPDATE syllabus_questions SET prompt = :p, explanation = :e, difficulty = :d WHERE questionID = :q", [':p' => $prompt, ':e' => $explain, ':d' => $diff, ':q' => $qid]);
                    $this->run("DELETE FROM syllabus_choices WHERE questionID = :q", [':q' => $qid]);
                } else {
                    $next = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_questions WHERE quizID = :z", [':z' => $quiz['quizID']]);
                    $this->run("INSERT INTO syllabus_questions (quizID, prompt, explanation, difficulty, sort_order) VALUES (:z, :p, :e, :d, :o)", [':z' => $quiz['quizID'], ':p' => $prompt, ':e' => $explain, ':d' => $diff, ':o' => $next]);
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

            /* ----- copy a syllabus from another class ----- */
            case 'copy_class':
                $target = $this->intParam('targetSched');
                $source = $this->intParam('sourceSched');
                if ($target === $source) $this->fail('Choose a different class to copy from.');
                if (!$this->canManageSched($target)) $this->fail('You cannot edit this class.', 403);
                if (!$this->canManageSched($source)) $this->fail('You can only copy from a class you manage.', 403);
                if (!(int)$this->value("SELECT COUNT(*) FROM syllabus_modules WHERE schedID = :s", [':s' => $source])) $this->fail('That class has no syllabus to copy yet.');
                $this->copyClass(
                    $source, $target,
                    ($_POST['include_pages'] ?? '') === '1',
                    ($_POST['include_files'] ?? '') === '1',
                    ($_POST['include_quizzes'] ?? '') === '1'
                );
                break;

            /* ----- bulk question import + duplicate ----- */
            case 'import_questions':
                $quiz  = $this->requireCanManageQuiz($this->intParam('quizID'));
                $items = json_decode((string)($_POST['questions'] ?? ''), true);
                if (!is_array($items) || !$items) $this->fail('There are no questions to import.');
                if (count($items) > 200) $this->fail('Import up to 200 questions at a time.');

                $clean = [];
                foreach (array_values($items) as $n => $it) {
                    $num = $n + 1;
                    if (!is_array($it)) $this->fail("Question {$num} is not valid.");
                    $prompt = trim((string)($it['prompt'] ?? ''));
                    if ($prompt === '' || mb_strlen($prompt) > 2000) $this->fail("Question {$num}: write the question (max 2000 characters).");
                    $choices = array_values(array_map(fn($c) => trim((string)$c), (array)($it['choices'] ?? [])));
                    if (count($choices) < 2 || count($choices) > 6) $this->fail("Question {$num}: give between 2 and 6 answer choices.");
                    foreach ($choices as $c) {
                        if ($c === '' || mb_strlen($c) > 500) $this->fail("Question {$num}: every choice needs text (max 500 characters).");
                    }
                    $correct = (int)($it['correct'] ?? -1);
                    if ($correct < 0 || $correct >= count($choices)) $this->fail("Question {$num}: mark which choice is correct.");
                    $expl = trim((string)($it['explanation'] ?? ''));
                    if (mb_strlen($expl) > 1500) $this->fail("Question {$num}: the explanation is too long (max 1500 characters).");
                    $diff = (int)($it['difficulty'] ?? 2);
                    if ($diff < 1 || $diff > 3) $diff = 2;
                    $clean[] = ['prompt' => $prompt, 'choices' => $choices, 'correct' => $correct, 'explanation' => $expl === '' ? null : $expl, 'difficulty' => $diff];
                }

                $this->pdo->beginTransaction();
                $next = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) FROM syllabus_questions WHERE quizID = :z", [':z' => $quiz['quizID']]);
                foreach ($clean as $it) {
                    $next++;
                    $this->run(
                        "INSERT INTO syllabus_questions (quizID, prompt, explanation, difficulty, sort_order) VALUES (:z, :p, :e, :d, :o)",
                        [':z' => $quiz['quizID'], ':p' => $it['prompt'], ':e' => $it['explanation'], ':d' => $it['difficulty'], ':o' => $next]
                    );
                    $qid = (int)$this->pdo->lastInsertId();
                    foreach ($it['choices'] as $i => $label) {
                        $this->run(
                            "INSERT INTO syllabus_choices (questionID, label, is_correct, sort_order) VALUES (:q, :l, :c, :o)",
                            [':q' => $qid, ':l' => $label, ':c' => ($i === $it['correct']) ? 1 : 0, ':o' => $i + 1]
                        );
                    }
                }
                $this->pdo->commit();
                break;

            case 'duplicate_question':
                $src = $this->one("SELECT questionID, quizID, prompt, explanation, difficulty FROM syllabus_questions WHERE questionID = :q", [':q' => $this->intParam('questionID')]);
                if (!$src) $this->fail('Question not found.', 404);
                $this->requireCanManageQuiz((int)$src['quizID']);
                $this->pdo->beginTransaction();
                $next = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_questions WHERE quizID = :z", [':z' => $src['quizID']]);
                $this->run(
                    "INSERT INTO syllabus_questions (quizID, prompt, explanation, difficulty, sort_order) VALUES (:z, :p, :e, :d, :o)",
                    [':z' => $src['quizID'], ':p' => $src['prompt'], ':e' => $src['explanation'], ':d' => $src['difficulty'], ':o' => $next]
                );
                $newQ = (int)$this->pdo->lastInsertId();
                foreach ($this->all("SELECT label, is_correct, sort_order FROM syllabus_choices WHERE questionID = :q ORDER BY sort_order, choiceID", [':q' => $src['questionID']]) as $c) {
                    $this->run(
                        "INSERT INTO syllabus_choices (questionID, label, is_correct, sort_order) VALUES (:q, :l, :c, :o)",
                        [':q' => $newQ, ':l' => $c['label'], ':c' => $c['is_correct'], ':o' => $c['sort_order']]
                    );
                }
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
