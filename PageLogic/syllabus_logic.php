<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../Services/csrf.php';
require_once __DIR__ . '/../PDAO/DAO.php';

date_default_timezone_set('Asia/Manila');
$_SESSION["page"] = "SYLLABUS";

/*
========================================================
  SYLLABUS TRACKER LOGIC
  Roles
    student                      -> read-only, sees published contents
    prof                         -> manages the classes they teach
    program coordinator / dean   -> manage every class
    anyone else (e.g. registrar) -> read-only
  Completion % = completed topics / all topics (same per module)
========================================================
*/
class SyllabusLogic
{
    private const STATUSES = ['not_started', 'in_progress', 'completed'];
    private const KINDS    = ['pdf', 'video', 'note'];

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

    public function __construct()
    {
        global $db_connection;
        $this->pdo       = $db_connection;
        $this->role      = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $this->userID    = (int)($_SESSION['real_userID'] ?? 0);
        $this->isProf    = ($this->role === 'prof');
        $this->isManager = in_array($this->role, ['program coordinator', 'dean'], true);
        $this->isFaculty = $this->isProf || $this->isManager;
    }

    /* ---------- small PDO helpers ---------- */
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

    /** May this user edit this class's syllabus? */
    public function canEdit(array $class): bool
    {
        return $this->canManageSched((int)$class['schedID']);
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
        if ($this->role === 'student') {
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
            "SELECT c.*, m.schedID
               FROM syllabus_contents c
               JOIN syllabus_tasks t ON t.taskID = c.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE c.contentID = :c",
            [':c' => $contentID]
        );
        if (!$row) return null;
        $sched = (int)$row['schedID'];
        if (!$this->canViewClass($sched)) return null;
        if ((int)$row['is_published'] !== 1 && !$this->canManageSched($sched)) return null;
        return $row;
    }

    /* ---------------------------------------------------------
       TRACKER DATA + COMPUTATIONS
    --------------------------------------------------------- */
    public function tracker(int $schedID, bool $includeHidden = false): array
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

        $contents = $this->all(
            "SELECT c.contentID, c.taskID, c.kind, c.title, c.description, c.body,
                    c.file_name, c.file_size, c.is_published
               FROM syllabus_contents c
               JOIN syllabus_tasks t ON t.taskID = c.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE m.schedID = :s" . ($includeHidden ? '' : ' AND c.is_published = 1') . "
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

        $byModule = [];
        foreach ($tasks as $t) {
            $view = 'not-started';
            if ($t['status'] === 'completed')        $view = 'completed';
            elseif ($t['status'] === 'in_progress')  $view = 'progress';
            elseif ($t['due_date'] && $t['due_date'] >= $today && $t['due_date'] <= $soon) $view = 'upcoming';

            $t['view']       = $view;
            $t['week_label'] = $t['week_no'] !== null ? 'Week ' . (int)$t['week_no'] : 'Task';
            $t['overdue']    = ($t['status'] !== 'completed' && $t['due_date'] && $t['due_date'] < $today);
            $t['contents']   = $contentsByTask[$t['taskID']] ?? [];
            $t['has_page']   = isset($pageTasks[$t['taskID']]);
            $byModule[$t['moduleID']][] = $t;
        }

        $sum = [
            'total' => 0, 'done' => 0, 'progress' => 0, 'remaining' => 0, 'pct' => 0,
            'overdue' => 0, 'materials' => count($contents), 'current' => null, 'next' => null,
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

    public function canManageClass(int $schedID): bool
    {
        return $this->canManageSched($schedID);
    }

    /* ---------------------------------------------------------
       TOPIC PAGE (one topic: lesson document + materials)
    --------------------------------------------------------- */
    public function topicPage(int $taskID): ?array
    {
        $t = $this->one(
            "SELECT t.taskID, t.title, t.week_no, t.due_date, t.status,
                    m.moduleID, m.title AS module_title, m.schedID
               FROM syllabus_tasks t
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE t.taskID = :t",
            [':t' => $taskID]
        );
        if (!$t || !$this->canViewClass((int)$t['schedID'])) return null;

        $today = date('Y-m-d');
        $soon  = date('Y-m-d', strtotime('+7 days'));
        $view  = 'not-started';
        if ($t['status'] === 'completed')       $view = 'completed';
        elseif ($t['status'] === 'in_progress') $view = 'progress';
        elseif ($t['due_date'] && $t['due_date'] >= $today && $t['due_date'] <= $soon) $view = 'upcoming';
        $t['view']       = $view;
        $t['week_label'] = $t['week_no'] !== null ? 'Week ' . (int)$t['week_no'] : 'Topic';
        $t['overdue']    = ($t['status'] !== 'completed' && $t['due_date'] && $t['due_date'] < $today);

        $page = $this->one("SELECT html, delta, updated_at FROM syllabus_pages WHERE taskID = :t", [':t' => $taskID]);
        $t['html']       = $page['html'] ?? '';
        $t['delta']      = $page['delta'] ?? null;
        $t['updated_at'] = $page['updated_at'] ?? null;

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

    /** Image row for the file endpoint, or null when this user must not get it. */
    public function imageForServing(int $imageID): ?array
    {
        $row = $this->one(
            "SELECT i.imageID, i.stored_name, i.file_name, i.mime_type, m.schedID
               FROM syllabus_images i
               JOIN syllabus_tasks t ON t.taskID = i.taskID
               JOIN syllabus_modules m ON m.moduleID = t.moduleID
              WHERE i.imageID = :i",
            [':i' => $imageID]
        );
        if (!$row || !$this->canViewClass((int)$row['schedID'])) return null;
        return $row;
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

    /* ---------------------------------------------------------
       POST ACTIONS (JSON)
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

    private function dateParam(): ?string
    {
        $raw = trim((string)($_POST['due_date'] ?? ''));
        if ($raw === '') return null;
        $d = DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) $this->fail('Invalid due date.');
        return $raw;
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
        if ($sched === false || !$this->canManageSched((int)$sched)) $this->fail('You cannot edit this task.', 403);
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

    public function handlePost(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->isFaculty) $this->fail('Only faculty can edit the syllabus.', 403);

        // A file bigger than post_max_size makes PHP drop $_POST and $_FILES entirely.
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->fail('The upload is larger than the server allows (post_max_size = ' . ini_get('post_max_size') . '). Raise it in php.ini and restart Apache.', 413);
        }
        if (!csrfValid($_POST['csrf_token'] ?? null)) $this->fail('Session expired. Please refresh the page.', 419);

        $action = (string)($_POST['action'] ?? '');

        try {
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
                    $this->run("DELETE FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);
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
                    $due   = $this->dateParam();
                    $next  = (int)$this->value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_tasks WHERE moduleID = :m", [':m' => $id]);
                    $this->run(
                        "INSERT INTO syllabus_tasks (moduleID, title, week_no, due_date, sort_order) VALUES (:m, :t, :w, :d, :o)",
                        [':m' => $id, ':t' => $title, ':w' => $week, ':d' => $due, ':o' => $next]
                    );
                    break;

                case 'edit_task':
                    $id = $this->intParam('taskID');
                    $this->requireCanManageTask($id);
                    $this->run(
                        "UPDATE syllabus_tasks SET title = :t, week_no = :w, due_date = :d WHERE taskID = :id",
                        [':t' => $this->titleParam('title', 250), ':w' => $this->weekParam(), ':d' => $this->dateParam(), ':id' => $id]
                    );
                    break;

                case 'set_status':
                    $id     = $this->intParam('taskID');
                    $status = (string)($_POST['status'] ?? '');
                    if (!in_array($status, self::STATUSES, true)) $this->fail('Invalid status.');
                    $this->requireCanManageTask($id);
                    $this->run(
                        "UPDATE syllabus_tasks SET status = :s, completed_at = " . ($status === 'completed' ? 'NOW()' : 'NULL') . " WHERE taskID = :id",
                        [':s' => $status, ':id' => $id]
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
                    $this->run("DELETE FROM syllabus_tasks WHERE taskID = :t", [':t' => $id]);
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

                default:
                    $this->fail('Unknown action.');
            }
        } catch (PDOException $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('Syllabus action failed: ' . $ex->getMessage());
            $this->fail('Database error. Did you import the latest syllabus_tracker.sql?', 500);
        }

        $this->reply(['ok' => true]);
    }
}

// POST /syllabus is routed straight to this file; handle the action and stop.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    (new SyllabusLogic())->handlePost();
}
