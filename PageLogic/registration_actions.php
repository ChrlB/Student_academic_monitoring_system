<?php
/**
 * Registrar tools: the account directory, Add (register) and Restrict / Lift restriction.
 * Loaded by Services/route.php on EVERY request, so it must not include Services/auth.php
 * (that file redirects to /login). Each endpoint checks the session itself.
 *
 *   POST /registration-add       -> registrationAdd()
 *   POST /registration-restrict  -> registrationRestrict()
 *
 * Uses only the `students` and `faculty` tables (+ `programs` / `departments` for validation).
 * The optional reason log lives in `account_restrictions` (see registration_migration.sql).
 */
require_once __DIR__ . '/../Services/csrf.php';
require_once __DIR__ . '/../Services/account_logs.php';   // accountLog(): activity log

/** Roles the registrar may create: label shown in the page => [kind, value stored in faculty.role]. */
const REG_ROLES = [
    'Students'            => ['student', 'student'],
    'Dean'                => ['faculty', 'dean'],
    'Program Coordinator' => ['faculty', 'program coordinator'],
    'Professor'           => ['faculty', 'prof'],
];

function regJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Logged in as a registrar with a valid CSRF token, or answers with a JSON error. */
function regGuard(array $post): void {
    if (empty($_SESSION['userID'])) regJson(['ok' => false, 'message' => 'Please log in again.'], 401);
    if (strtolower((string)($_SESSION['role'] ?? '')) !== 'registrar') {
        regJson(['ok' => false, 'message' => 'Only the registrar can do this.'], 403);
    }
    if (!csrfValid($post['csrf_token'] ?? null)) {
        regJson(['ok' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
    }
}

function regPdo(): PDO {
    global $db_connection;
    return $db_connection;
}


/** Has archive_migration.sql been run? (students and faculty both need archived_at) */
function regHasArchive(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            regPdo()->query('SELECT archived_at, archived_prev_status FROM students LIMIT 0');
            regPdo()->query('SELECT archived_at, archived_prev_status FROM faculty LIMIT 0');
            $ok = true;
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}
/** SELECT fragment for archived_at (NULL when the migration has not been run, so nothing shows as archived). */
function regArchiveSql(string $prefix = ''): string {
    return regHasArchive() ? ', ' . $prefix . 'archived_at' : ', NULL AS archived_at';
}
function regNeedArchiveMigration(): void {
    if (!regHasArchive()) {
        regJson(['ok' => false, 'message' => 'Archiving needs a one-time database update. Run archive_migration.sql in phpMyAdmin, then try again.'], 409);
    }
}

/** Does this table have an email column yet? (faculty needs faculty_email_migration.sql) */
function regHasEmail(string $table): bool {
    static $seen = [];
    if (!isset($seen[$table])) {
        try { regPdo()->query("SELECT email FROM $table LIMIT 0"); $seen[$table] = true; }
        catch (Throwable $e) { $seen[$table] = false; }
    }
    return $seen[$table];
}
/** SELECT fragment for the email column (empty text when the column does not exist). */
function regEmailSql(string $table, string $prefix = ''): string {
    return regHasEmail($table) ? ', ' . $prefix . 'email' : ", '' AS email";
}

/** [cleanEmail, error|null]; blank is allowed. Must be unique across students and faculty. */
function regCheckEmail(PDO $pdo, string $raw, string $kind, ?int $selfNum = null): array {
    $v = strtolower(trim($raw));
    if ($v === '') return ['', null];
    if (mb_strlen($v) > 100) return ['', 'The email is too long (100 characters at most).'];
    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return ['', 'Enter a valid email address, e.g. name@gmail.com.'];
    foreach (['students' => 'studentID', 'faculty' => 'facultyID'] as $t => $idCol) {
        if (!regHasEmail($t)) continue;
        $own = (($t === 'students') === ($kind === 'student')) ? $selfNum : null;
        $st = $pdo->prepare("SELECT $idCol FROM $t WHERE LOWER(email) = ?" . ($own !== null ? " AND $idCol <> ?" : ''));
        $st->execute($own !== null ? [$v, $own] : [$v]);
        if ($st->fetchColumn() !== false) return ['', 'That email is already used by another account.'];
    }
    return [$v, null];
}

/* ---------------------------------------------------------------- helpers */

function regTitle(string $s): string {
    return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/** "ID" shown to people: students YYYY + 5 digits, faculty F + YYYY + 5 digits (same as the `users` view). */
function regUserId(string $kind, int $num, string $dateCreated): string {
    $year = substr($dateCreated, 0, 4);
    return ($kind === 'faculty' ? 'F' : '') . $year . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

function regRoleLabel(string $role): string {
    $role = strtolower(trim($role));
    foreach (REG_ROLES as $label => [, $value]) if ($value === $role) return $label;
    return $role === 'registrar' ? 'Registrar' : regTitle($role);
}

function regDisplayName(array $r): string {
    $mid  = trim((string)$r['middle_name']);
    // "Last Name, Middle Initial, First Name"  e.g. "Fazbear, D., Freddie" (no middle name: "Fazbear, Freddie")
    $parts = [regTitle(trim((string)$r['last_name']))];
    if ($mid !== '') $parts[] = mb_strtoupper(mb_substr($mid, 0, 1)) . '.';
    $parts[] = regTitle(trim((string)$r['first_name']));
    return preg_replace('/\s+/', ' ', implode(', ', $parts));
}

/**
 * One row of the directory, in the shape registration.js draws.
 */
function regAccount(string $kind, array $r, array $reasons = []): array {
    $num  = (int)($kind === 'student' ? $r['studentID'] : $r['facultyID']);
    $id   = regUserId($kind === 'student' ? 'student' : 'faculty', $num, (string)$r['date_created']);
    $name = regDisplayName($r);

    if ($kind === 'student') {
        $role = 'student';
        $pb   = trim($r['program'] . ' ' . $r['block']);
        $dept = (string)($r['department'] ?? '');
    } else {
        $role = strtolower((string)$r['role']);
        $prog = trim((string)($r['program'] ?? ''));
        $pb   = $prog !== '' ? $prog : ($role === 'dean' ? 'All Programs' : '—');
        $dept = (string)$r['department'];
    }
    $status = strtolower(trim((string)$r['status'])) === 'restricted' ? 'restricted' : 'active';
    return [
        'id' => $id, 'kind' => $kind, 'role' => $role, 'roleLabel' => regRoleLabel($role),
        'name' => $name, 'dept' => $dept, 'programBlock' => $pb, 'email' => trim((string)($r['email'] ?? '')),
        'status' => $status, 'reason' => $status === 'restricted' ? ($reasons[$id] ?? '') : '',
        'archived' => !empty($r['archived_at']), 'archivedAt' => !empty($r['archived_at']) ? substr((string)$r['archived_at'], 0, 10) : '',
        // your own account and registrar accounts cannot be edited, deleted or restricted from this page
        'locked' => $role === 'registrar' || strtoupper($id) === strtoupper((string)($_SESSION['userID'] ?? '')),
    ];
}

/* ------------------------------------------------------ accounts */

/** A fresh directory entry for one account (used to refresh a row after a change). */
function regLoadAccount(PDO $pdo, string $kind, int $num): ?array {
    if ($kind === 'student') {
        $st = $pdo->prepare("SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department" . regEmailSql('students', 's.') . regArchiveSql('s.') . "
                               FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?");
    } else {
        $st = $pdo->prepare("SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status" . regEmailSql('faculty') . regArchiveSql() . "
                               FROM faculty WHERE facultyID = ?");
    }
    $st->execute([$num]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? regAccount($kind, $r, regReasons()) : null;
}

/** Latest restriction reason per account ID. Silently empty if the log table was not created yet. */
function regReasons(): array {
    try {
        $rows = regPdo()->query(
            "SELECT account_id, reason FROM account_restrictions WHERE lifted_at IS NULL ORDER BY restriction_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[$r['account_id']] = $r['reason'];
    return $out;
}

/** Everyone, students first then faculty. */
function regDirectory(): array {
    $pdo = regPdo();
    $reasons = regReasons();
    $out = [];
    $students = $pdo->query(
        "SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created,
                s.status, p.department" . regEmailSql('students', 's.') . regArchiveSql('s.') . "
           FROM students s LEFT JOIN programs p ON p.program = s.program
          ORDER BY s.date_created, s.studentID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($students as $r) $out[] = regAccount('student', $r, $reasons);

    $faculty = $pdo->query(
        "SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status" . regEmailSql('faculty') . regArchiveSql() . "
           FROM faculty ORDER BY date_created, facultyID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($faculty as $r) $out[] = regAccount('faculty', $r, $reasons);
    return $out;
}

/** A first or last name: whole words only (letters, hyphen, apostrophe, an abbreviation dot like "Ma."), no lone initials. */
function regNameWordsOk(string $name): bool {
    foreach (explode(' ', $name) as $w) {
        $core = rtrim($w, '.');
        if (mb_strlen($core, 'UTF-8') < 2 || !preg_match("/^\p{L}[\p{L}'\-]*$/u", $core)) return false;
    }
    return true;
}

/** [[first, middle, last], null] or [null, error]. Input must be "Last Name, Middle Initial, First Name" (middle optional). */
function regParseName(string $raw): array {
    $fmt = 'Use the format: Last Name, Middle Initial, First Name (e.g. Fazbear, D., Freddie).';
    $raw = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($raw === '') return [null, 'Enter the full name.'];
    if (mb_strlen($raw) > 150) return [null, 'The name is too long.'];
    if (strpos($raw, ',') === false) return [null, $fmt];

    $parts = array_map('trim', explode(',', $raw));
    if (count($parts) === 2)      $parts = [$parts[0], '', $parts[1]];       // Last, First
    elseif (count($parts) !== 3)  return [null, $fmt];                        // Last, MI, First
    [$last, $mid, $first] = $parts;

    if ($last === '' || $first === '') return [null, 'The last name and the first name are both required.'];
    if (!regNameWordsOk($last) || !regNameWordsOk($first)) {
        return [null, 'The last and first names must be whole names without initials or symbols. The middle initial goes in the middle: Last Name, M., First Name.'];
    }
    $mid = rtrim($mid, '.');
    if ($mid !== '' && !preg_match('/^\p{L}$/u', $mid)) {
        return [null, 'The middle part must be a single letter (the middle initial), e.g. D'];
    }
    foreach ([$first, $last] as $p) if (mb_strlen($p) > 60) return [null, 'A part of the name is too long.'];
    return [[regTitle($first), $mid === '' ? '' : mb_strtoupper($mid, 'UTF-8'), regTitle($last)], null];
}

function regTempPassword(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 10; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}

/**
 * Department + Program/Block checks shared by Add and Edit.
 * Returns [dept, program|null, yearLevel, block]; problems are written to $errors.
 */
function regCheckAssignment(PDO $pdo, string $kind, string $roleValue, array $post, array &$errors): array {
    $dept = strtoupper(trim((string)($post['department'] ?? '')));

    $pbRaw = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($post['program_block'] ?? ''))));
    $program = null; $yearLevel = 0; $block = '';
    if ($kind === 'student') {
        if (!preg_match('/^([A-Z]+) ?([1-6])([A-Z])$/', $pbRaw, $m)) {
            $errors['program_block'] = 'Use the format Program + Block, e.g. BSCS 2C.';
        } else {
            $program = $m[1]; $yearLevel = (int)$m[2]; $block = $m[2] . $m[3];
        }
    } elseif ($roleValue !== 'dean') {   // a dean covers every program, so none is stored
        if ($pbRaw === '' || !preg_match('/^[A-Z]+$/', $pbRaw)) {
            $errors['program_block'] = 'Enter the program, e.g. BSCS.';
        } else {
            $program = $pbRaw;
        }
    }

    if ($program !== null) {
        // the department follows the program: whatever the form sent is ignored
        $st = $pdo->prepare('SELECT department FROM programs WHERE program = ?');
        $st->execute([$program]);
        $found = $st->fetchColumn();
        if ($found === false) {
            $errors['program_block'] = "$program is not a known program. Add it to the programs table first.";
            $dept = '';
        } else {
            $dept = strtoupper((string)$found);
        }
    } elseif ($roleValue === 'dean') {   // a dean has no program, so the department is chosen by hand
        $st = $pdo->prepare('SELECT 1 FROM departments WHERE department = ?');
        $st->execute([$dept]);
        if ($dept === '' || !$st->fetchColumn()) $errors['department'] = 'Choose a valid department.';
    }
    return [$dept, $program, $yearLevel, $block];
}

/* -------------------------------------------------------------------- add */

function registrationAdd($post) {
    regGuard($post);
    $pdo = regPdo();
    $errors = [];

    [$parts, $nameErr] = regParseName((string)($post['full_name'] ?? ''));
    if ($nameErr) $errors['full_name'] = $nameErr;

    $roleLabel = (string)($post['role'] ?? '');
    if (!isset(REG_ROLES[$roleLabel])) {
        $errors['role'] = 'Choose a role.';
        $kind = 'student'; $roleValue = 'student';
    } else {
        [$kind, $roleValue] = REG_ROLES[$roleLabel];
    }

    [$dept, $program, $yearLevel, $block] = regCheckAssignment($pdo, $kind, $roleValue, $post, $errors);

    // optional typed ID, otherwise the database numbers it
    $idRaw = strtoupper(trim((string)($post['school_id'] ?? '')));
    $num = null; $created = date('Y-m-d H:i:s');
    if ($idRaw !== '') {
        $pat = $kind === 'faculty' ? '/^F?(\d{4})(\d{5})$/' : '/^(\d{4})(\d{5})$/';
        if (!preg_match($pat, $idRaw, $m)) {
            $errors['school_id'] = $kind === 'faculty'
                ? 'Use the format F202600012, or leave it blank to assign one automatically.'
                : 'Use the format 202600045, or leave it blank to assign one automatically.';
        } else {
            $year = (int)$m[1]; $num = (int)$m[2];
            if ($year < 2000 || $year > (int)date('Y') || $num < 1) {
                $errors['school_id'] = 'That ID is not valid.';
            } else {
                $table = $kind === 'faculty' ? 'faculty' : 'students';
                $col   = $kind === 'faculty' ? 'facultyID' : 'studentID';
                $st = $pdo->prepare("SELECT 1 FROM $table WHERE $col = ?");
                $st->execute([$num]);
                if ($st->fetchColumn()) $errors['school_id'] = 'That ID number is already taken.';
                if ($year !== (int)date('Y')) $created = $year . '-01-01 00:00:00';
            }
        }
    }

    [$email, $emailErr] = regCheckEmail($pdo, (string)($post['email'] ?? ''), $kind);
    if ($emailErr) $errors['email'] = $emailErr;

    if ($errors) {
        regJson(['ok' => false, 'errors' => $errors, 'message' => reset($errors)], 422);
    }

    [$first, $mid, $last] = $parts;
    $tempPw = regTempPassword();
    $hash   = password_hash($tempPw, PASSWORD_DEFAULT);

    try {
        // the account page asks the owner to fill in what the registrar does not know
        if ($kind === 'student') {
            $cols = ['first_name', 'middle_name', 'last_name', 'password', 'program', 'date_created', 'year_level', 'block',
                     'address', 'number', 'date_of_birth', 'gender', 'status'];
            $vals = [$first, $mid, $last, $hash, $program, $created, $yearLevel, $block, '', '', '1900-01-01', '', 'active'];
            $idCol = 'studentID'; $table = 'students';
        } else {
            $cols = ['first_name', 'middle_name', 'last_name', 'password', 'role', 'department', 'program', 'date_created',
                     'address', 'number', 'date_of_birth', 'gender', 'status'];
            $vals = [$first, $mid, $last, $hash, $roleValue, $dept, $program, $created, '', '', '1900-01-01', '', 'active'];
            $idCol = 'facultyID'; $table = 'faculty';
        }
        if ($email !== '' && regHasEmail($table)) { $cols[] = 'email'; $vals[] = $email; }
        if ($num !== null) { array_unshift($cols, $idCol); array_unshift($vals, $num); }
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare($sql)->execute($vals);
        $newNum = $num ?? (int)$pdo->lastInsertId();

        $st = $pdo->prepare($kind === 'student'
            ? "SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department" . regEmailSql('students', 's.') . regArchiveSql('s.') . "
                 FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?"
            : "SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status" . regEmailSql('faculty') . regArchiveSql() . "
                 FROM faculty WHERE facultyID = ?");
        $st->execute([$newNum]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration add failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not register this account. Please try again.'], 500);
    }

    $acct = regAccount($kind, $row);
    accountLog($pdo, $acct['id'], $acct['name'], 'created',
        'Registered as ' . $acct['roleLabel'] . ($acct['dept'] ? ' · ' . $acct['dept'] : '') . ($acct['programBlock'] && $acct['programBlock'] !== '—' ? ' · ' . $acct['programBlock'] : ''));
    regJson([
        'ok' => true, 'account' => $acct, 'tempPassword' => $tempPw,
        'message' => $acct['name'] . ' was registered as ' . $acct['id'] . '.',
    ]);
}

/* --------------------------------------------------------------- restrict */

function registrationRestrict($post) {
    regGuard($post);
    $pdo = regPdo();

    $id = strtoupper(trim((string)($post['school_id'] ?? '')));
    if (!preg_match('/^(F?)(\d{4})(\d{5})$/', $id, $m)) {
        regJson(['ok' => false, 'errors' => ['school_id' => 'Enter a valid School / Employee ID.'],
                 'message' => 'Enter a valid School / Employee ID.'], 422);
    }
    $kind  = $m[1] === 'F' ? 'faculty' : 'student';
    $year  = (int)$m[2]; $num = (int)$m[3];
    $lift  = ($post['mode'] ?? 'restrict') === 'lift';

    $table = $kind === 'faculty' ? 'faculty' : 'students';
    $col   = $kind === 'faculty' ? 'facultyID' : 'studentID';
    $st = $pdo->prepare("SELECT date_created, status" . ($kind === 'faculty' ? ', role' : '') . regArchiveSql() . " FROM $table WHERE $col = ?");
    $st->execute([$num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)substr((string)$row['date_created'], 0, 4) !== $year) {
        regJson(['ok' => false, 'errors' => ['school_id' => 'No account has this ID.'], 'message' => 'No account has this ID.'], 404);
    }
    if ($id === strtoupper((string)$_SESSION['userID'])) {
        regJson(['ok' => false, 'message' => 'You cannot restrict your own account.'], 422);
    }
    if ($kind === 'faculty' && strtolower((string)$row['role']) === 'registrar') {
        regJson(['ok' => false, 'message' => 'Registrar accounts cannot be restricted here.'], 422);
    }
    if (!empty($row['archived_at'])) {
        regJson(['ok' => false, 'message' => "$id is archived. Restore it from the Archived tab first."], 409);
    }
    $isRestricted = strtolower(trim((string)$row['status'])) === 'restricted';

    $reason = trim(preg_replace('/\s+/u', ' ', (string)($post['reason'] ?? '')));
    if ($lift) {
        if (!$isRestricted) regJson(['ok' => false, 'message' => 'This account is not restricted.'], 422);
    } else {
        if ($isRestricted) regJson(['ok' => false, 'message' => 'This account is already restricted.'], 422);
        if (mb_strlen($reason) < 3)   regJson(['ok' => false, 'errors' => ['reason' => 'Enter the restriction reason.'], 'message' => 'Enter the restriction reason.'], 422);
        if (mb_strlen($reason) > 250) regJson(['ok' => false, 'errors' => ['reason' => 'Keep the reason under 250 characters.'], 'message' => 'Keep the reason under 250 characters.'], 422);
    }

    try {
        $pdo->prepare("UPDATE $table SET status = ? WHERE $col = ?")->execute([$lift ? 'active' : 'restricted', $num]);
        try {   // reason log is best effort: the restriction itself already worked
            if ($lift) {
                $pdo->prepare("UPDATE account_restrictions SET lifted_at = CURRENT_TIMESTAMP, lifted_by = ? WHERE account_id = ? AND lifted_at IS NULL")
                    ->execute([(string)$_SESSION['userID'], $id]);
            } else {
                $pdo->prepare("INSERT INTO account_restrictions (account_id, reason, restricted_by) VALUES (?, ?, ?)")
                    ->execute([$id, $reason, (string)$_SESSION['userID']]);
            }
        } catch (Throwable $e) {
            error_log('account_restrictions log failed (run registration_migration.sql?): ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('registration restrict failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }

    accountLog($pdo, $id, '', $lift ? 'lifted' : 'restricted', $lift ? 'Restriction lifted' : 'Reason: ' . $reason);
    regJson([
        'ok' => true, 'id' => $id, 'status' => $lift ? 'active' : 'restricted', 'reason' => $lift ? '' : $reason,
        'message' => $lift ? "Restriction lifted for $id." : "$id is now restricted.",
    ]);
}


/* ------------------------------------------------------- edit / delete */

/** Parse and look up the account an Edit / Delete is about. Answers with JSON when it cannot. */
function regFindAccount(PDO $pdo, $rawId, bool $allowArchived = false): array {
    $id = strtoupper(trim((string)$rawId));
    if (!preg_match('/^(F?)(\d{4})(\d{5})$/', $id, $m)) {
        regJson(['ok' => false, 'message' => 'That account ID is not valid.'], 422);
    }
    $kind  = $m[1] === 'F' ? 'faculty' : 'student';
    $year  = (int)$m[2]; $num = (int)$m[3];
    $table = $kind === 'faculty' ? 'faculty' : 'students';
    $col   = $kind === 'faculty' ? 'facultyID' : 'studentID';
    $st = $pdo->prepare("SELECT * FROM $table WHERE $col = ?");
    $st->execute([$num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)substr((string)$row['date_created'], 0, 4) !== $year) {
        regJson(['ok' => false, 'message' => 'No account has this ID. It may have been deleted already.'], 404);
    }
    if ($id === strtoupper((string)$_SESSION['userID'])) {
        regJson(['ok' => false, 'message' => 'You cannot change your own account here. Use the Account page.'], 422);
    }
    if (!$allowArchived && !empty($row['archived_at'])) {
        regJson(['ok' => false, 'message' => "$id is archived. Restore it from the Archived tab first."], 409);
    }
    if ($kind === 'faculty' && strtolower((string)$row['role']) === 'registrar') {
        regJson(['ok' => false, 'message' => 'Registrar accounts cannot be changed here.'], 422);
    }
    return [$id, $kind, $num, $table, $col, $row];
}

function registrationUpdate($post) {
    regGuard($post);
    $pdo = regPdo();
    [$id, $kind, $num, $table, $col, $row] = regFindAccount($pdo, $post['school_id'] ?? '');
    $errors = [];

    [$parts, $nameErr] = regParseName((string)($post['full_name'] ?? ''));
    if ($nameErr) $errors['full_name'] = $nameErr;

    if ($kind === 'student') {
        $roleValue = 'student';   // a student stays a student
    } else {
        $label = (string)($post['role'] ?? '');
        if (!isset(REG_ROLES[$label]) || REG_ROLES[$label][0] !== 'faculty') {
            $errors['role'] = 'Choose Dean, Program Coordinator or Professor.';
            $roleValue = strtolower((string)$row['role']);
        } else {
            $roleValue = REG_ROLES[$label][1];
        }
    }

    [$dept, $program, $yearLevel, $block] = regCheckAssignment($pdo, $kind, $roleValue, $post, $errors);
    $saveEmail = array_key_exists('email', $post);   // only touch the email when the form sent it
    $email = '';
    if ($saveEmail) {
        [$email, $emailErr] = regCheckEmail($pdo, (string)$post['email'], $kind, $num);
        if ($emailErr) $errors['email'] = $emailErr;
        if (!regHasEmail($table)) $saveEmail = false;
    }
    if ($errors) {
        regJson(['ok' => false, 'errors' => $errors, 'message' => reset($errors)], 422);
    }

    [$first, $mid, $last] = $parts;
    try {
        if ($kind === 'student') {
            $pdo->prepare('UPDATE students SET first_name=?, middle_name=?, last_name=?, program=?, year_level=?, block=?' . ($saveEmail ? ', email=?' : '') . ' WHERE studentID=?')
                ->execute(array_merge([$first, $mid, $last, $program, $yearLevel, $block], $saveEmail ? [$email] : [], [$num]));
            $st = $pdo->prepare("SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department" . regEmailSql('students', 's.') . regArchiveSql('s.') . "
                                   FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?");
        } else {
            $pdo->prepare('UPDATE faculty SET first_name=?, middle_name=?, last_name=?, role=?, department=?, program=?' . ($saveEmail ? ', email=?' : '') . ' WHERE facultyID=?')
                ->execute(array_merge([$first, $mid, $last, $roleValue, $dept, $program], $saveEmail ? [$email] : [], [$num]));
            $st = $pdo->prepare("SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status" . regEmailSql('faculty') . regArchiveSql() . "
                                   FROM faculty WHERE facultyID = ?");
        }
        $st->execute([$num]);
        $fresh = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration update failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not save the changes. Please try again.'], 500);
    }

    $acct = regAccount($kind, $fresh, regReasons());
    // what changed, for the activity log
    $changes = [];
    $oldName = regDisplayName($row);
    if ($oldName !== $acct['name']) $changes[] = "Name: $oldName → " . $acct['name'];
    $oldEmail = trim((string)($row['email'] ?? ''));
    if ($saveEmail && $oldEmail !== $acct['email']) $changes[] = 'Email: ' . ($oldEmail ?: '(none)') . ' → ' . ($acct['email'] ?: '(none)');
    if ($kind === 'student') {
        $oldPb = trim($row['program'] . ' ' . $row['block']);
        if ($oldPb !== $acct['programBlock']) $changes[] = "Program/Block: $oldPb → " . $acct['programBlock'];
    } else {
        if (strtolower((string)$row['role']) !== $acct['role']) $changes[] = 'Role: ' . regRoleLabel((string)$row['role']) . ' → ' . $acct['roleLabel'];
        if ((string)$row['department'] !== $acct['dept']) $changes[] = 'Department: ' . $row['department'] . ' → ' . $acct['dept'];
        $oldProg = trim((string)($row['program'] ?? ''));
        $newProg = $acct['programBlock'] === '—' || $acct['programBlock'] === 'All Programs' ? '' : $acct['programBlock'];
        if ($oldProg !== $newProg) $changes[] = 'Program: ' . ($oldProg ?: '(none)') . ' → ' . ($newProg ?: '(none)');
    }
    accountLog($pdo, $acct['id'], $acct['name'], 'updated', $changes ? implode('; ', $changes) : 'Saved with no changes');
    regJson(['ok' => true, 'account' => $acct, 'message' => $acct['name'] . ' (' . $acct['id'] . ') was updated.']);
}

function registrationDelete($post) {
    regGuard($post);
    $pdo = regPdo();
    regNeedArchiveMigration();
    [$id, $kind, $num, $table, $col, $row] = regFindAccount($pdo, $post['school_id'] ?? '', true);
    if (empty($row['archived_at'])) {   // permanent deletion only happens from the Archived tab
        regJson(['ok' => false, 'message' => "$id is not archived. Archive the account first, then delete it from the Archived tab."], 409);
    }

    if ($kind === 'faculty') {   // a professor who still has students linked (adviser / subject) is kept
        $n = 0;
        try {
            $st = $pdo->prepare('SELECT COUNT(DISTINCT studentID) FROM professor_student WHERE facultyID = ?');
            $st->execute([$num]);
            $n = (int)$st->fetchColumn();
        } catch (Throwable $e) { /* no professor_student table yet: nothing is assigned */ }
        if ($n > 0) {
            regJson(['ok' => false, 'message' => "$id is assigned to $n student" . ($n === 1 ? '' : 's') . ' (adviser or subject links), so the account cannot be deleted. Keep it archived instead.'], 409);
        }
    }

    if ($kind === 'student') {   // a student's professor links go with the account (best effort)
        try { $pdo->prepare('DELETE FROM professor_student WHERE studentID = ?')->execute([$num]); } catch (Throwable $e) { /* no such table */ }
    }
    try {
        $pdo->prepare("DELETE FROM $table WHERE $col = ?")->execute([$num]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {   // foreign key: enrollments, attendance, clearance... still point to this account
            regJson(['ok' => false, 'message' => "$id has records (enrollment, attendance, clearance or schedules) and cannot be deleted. Keep it archived instead."], 409);
        }
        error_log('registration delete failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not delete this account. Please try again.'], 500);
    } catch (Throwable $e) {
        error_log('registration delete failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not delete this account. Please try again.'], 500);
    }

    try {   // the restriction log for a deleted account is no longer needed (best effort)
        $pdo->prepare('DELETE FROM account_restrictions WHERE account_id = ?')->execute([$id]);
    } catch (Throwable $e) { /* table may not exist */ }

    accountLog($pdo, $id, regDisplayName($row), 'deleted', 'Permanently deleted from the Archived tab');
    regJson(['ok' => true, 'id' => $id, 'message' => "$id was deleted."]);
}


/* ------------------------------------------------------ archive / restore */

/** POST mode=archive|restore. Archiving hides the account from the active list and blocks its login (it is kept
 *  restricted while archived); nothing is deleted. Restoring puts the old status back. */
function registrationArchive($post) {
    regGuard($post);
    regNeedArchiveMigration();
    $pdo = regPdo();
    [$id, $kind, $num, $table, $col, $row] = regFindAccount($pdo, $post['school_id'] ?? '', true);
    $restore = ($post['mode'] ?? 'archive') === 'restore';
    $archived = !empty($row['archived_at']);

    if ($restore && !$archived)  regJson(['ok' => false, 'message' => "$id is not archived."], 409);
    if (!$restore && $archived)  regJson(['ok' => false, 'message' => "$id is already archived."], 409);

    try {
        if ($restore) {
            $prev = trim((string)($row['archived_prev_status'] ?? ''));
            $pdo->prepare("UPDATE $table SET archived_at = NULL, archived_prev_status = NULL, status = ? WHERE $col = ?")
                ->execute([$prev !== '' ? $prev : 'active', $num]);
        } else {
            $pdo->prepare("UPDATE $table SET archived_at = ?, archived_prev_status = ?, status = 'restricted' WHERE $col = ?")
                ->execute([date('Y-m-d H:i:s'), (string)$row['status'], $num]);
        }
    } catch (Throwable $e) {
        error_log('registration archive failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
    $acct = regLoadAccount($pdo, $kind, $num);
    accountLog($pdo, $id, $acct['name'] ?? regDisplayName($row), $restore ? 'restored' : 'archived',
               $restore ? 'Moved back to the active accounts' : 'Moved to the Archived tab; login blocked');
    regJson(['ok' => true, 'account' => $acct, 'message' => $restore ? "$id was restored." : "$id was archived."]);
}


/* -------------------------------------------------------------------- logs */

const REG_LOG_ACTIONS = [
    'created' => 'Registered', 'updated' => 'Edited', 'restricted' => 'Restricted', 'lifted' => 'Restriction lifted',
    'archived' => 'Archived', 'restored' => 'Restored', 'deleted' => 'Deleted', 'assigned' => 'Professor assigned',
    'unassigned' => 'Professor removed', 'login' => 'Logged in', 'login_blocked' => 'Login blocked', 'login_failed' => 'Failed login',
];

/** POST q (search), action, before (log_id cursor). Newest first, 40 per page. */
function registrationLogs($post) {
    regGuard($post);
    $pdo = regPdo();
    $where = []; $args = [];

    $q = trim((string)($post['q'] ?? ''));
    if ($q !== '') {
        // '!' is the LIKE escape character (works the same in MySQL and MariaDB, unlike backslash)
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 60)) . '%';
        $where[] = "(account_id LIKE ? ESCAPE '!' OR account_name LIKE ? ESCAPE '!' OR details LIKE ? ESCAPE '!' OR actor_name LIKE ? ESCAPE '!')";
        array_push($args, $like, $like, $like, $like);
    }
    $action = (string)($post['action'] ?? '');
    if ($action !== '') {
        if (!isset(REG_LOG_ACTIONS[$action])) regJson(['ok' => false, 'message' => 'Unknown activity type.'], 422);
        $where[] = 'action = ?'; $args[] = $action;
    }
    $before = (int)($post['before'] ?? 0);
    if ($before > 0) { $where[] = 'log_id < ?'; $args[] = $before; }

    $limit = 40;
    try {
        $st = $pdo->prepare('SELECT log_id, account_id, account_name, action, details, actor_id, actor_name, created_at FROM account_logs'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY log_id DESC LIMIT ' . ($limit + 1));
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration logs failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'The activity log needs a one-time database update. Run logs_migration.sql in phpMyAdmin, then try again.'], 409);
    }
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $out = array_map(fn($r) => [
        'id' => (int)$r['log_id'], 'when' => substr((string)$r['created_at'], 0, 16),
        'accountId' => $r['account_id'], 'accountName' => $r['account_name'],
        'action' => $r['action'], 'label' => REG_LOG_ACTIONS[$r['action']] ?? $r['action'], 'details' => $r['details'],
        'actorId' => $r['actor_id'], 'actorName' => $r['actor_name'],
    ], $rows);
    regJson(['ok' => true, 'logs' => $out, 'more' => $more]);
}