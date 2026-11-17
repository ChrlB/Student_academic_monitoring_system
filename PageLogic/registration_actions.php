<?php

require_once __DIR__ . '/../Services/csrf.php';

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

/** One row of the directory, in the shape registration.js draws. */
function regAccount(string $kind, array $r, array $reasons = []): array {
    $num  = (int)($kind === 'student' ? $r['studentID'] : $r['facultyID']);
    $id   = regUserId($kind === 'student' ? 'student' : 'faculty', $num, (string)$r['date_created']);
    $mid  = trim((string)$r['middle_name']);
    $name = trim(regTitle(trim((string)$r['first_name'])) . ' '
          . ($mid !== '' ? mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '') . ' '
          . regTitle(trim((string)$r['last_name'])));
    $name = preg_replace('/\s+/', ' ', $name);

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
        'name' => $name, 'dept' => $dept, 'programBlock' => $pb,
        'status' => $status, 'reason' => $status === 'restricted' ? ($reasons[$id] ?? '') : '',
    ];
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
                s.status, p.department
           FROM students s LEFT JOIN programs p ON p.program = s.program
          ORDER BY s.date_created, s.studentID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($students as $r) $out[] = regAccount('student', $r, $reasons);

    $faculty = $pdo->query(
        "SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status
           FROM faculty ORDER BY date_created, facultyID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($faculty as $r) $out[] = regAccount('faculty', $r, $reasons);
    return $out;
}

/** [first, middle, last] or an error string. Accepts "First, M, Last" or "First M Last". */
function regParseName(string $raw): array {
    $raw = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($raw === '') return [null, 'Enter the full name.'];
    if (mb_strlen($raw) > 150) return [null, 'The name is too long.'];

    if (strpos($raw, ',') !== false) {
        $parts = array_map('trim', explode(',', $raw));
        if (count($parts) === 2)      $parts = [$parts[0], '', $parts[1]];
        elseif (count($parts) !== 3)  return [null, 'Use the format: First Name, Middle Initial, Last Name.'];
    } else {
        $t = explode(' ', $raw);
        if (count($t) < 2) return [null, 'Enter at least a first and a last name.'];
        $last = array_pop($t);
        $mid  = count($t) >= 2 ? array_pop($t) : '';
        $parts = [implode(' ', $t), $mid, $last];
    }
    [$first, $mid, $last] = $parts;
    $mid = rtrim($mid, '.');
    if ($first === '' || $last === '') return [null, 'First and last name are required.'];
    foreach ([$first, $mid, $last] as $p) {
        if ($p !== '' && !preg_match("/^\p{L}[\p{L} .'\-]*$/u", $p)) {
            return [null, 'Names can only contain letters, spaces, hyphens and apostrophes.'];
        }
        if (mb_strlen($p) > 60) return [null, 'A part of the name is too long.'];
    }
    return [[regTitle($first), $mid === '' ? '' : mb_strtoupper($mid, 'UTF-8'), regTitle($last)], null];
}

function regTempPassword(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 10; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}

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

    // department must exist
    $dept = strtoupper(trim((string)($post['department'] ?? '')));
    $st = $pdo->prepare('SELECT 1 FROM departments WHERE department = ?');
    $st->execute([$dept]);
    if ($dept === '' || !$st->fetchColumn()) $errors['department'] = 'Choose a valid department.';

    // program / block
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
    if ($program !== null && !isset($errors['department'])) {
        $st = $pdo->prepare('SELECT 1 FROM programs WHERE program = ? AND department = ?');
        $st->execute([$program, $dept]);
        if (!$st->fetchColumn()) $errors['program_block'] = "$program is not a program of $dept.";
    }

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
        if ($num !== null) { array_unshift($cols, $idCol); array_unshift($vals, $num); }
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare($sql)->execute($vals);
        $newNum = $num ?? (int)$pdo->lastInsertId();

        $st = $pdo->prepare($kind === 'student'
            ? "SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department
                 FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?"
            : "SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status
                 FROM faculty WHERE facultyID = ?");
        $st->execute([$newNum]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration add failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not register this account. Please try again.'], 500);
    }

    $acct = regAccount($kind, $row);
    regJson([
        'ok' => true, 'account' => $acct, 'tempPassword' => $tempPw,
        'message' => $acct['name'] . ' was registered as ' . $acct['id'] . '.',
    ]);
}

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
    $st = $pdo->prepare("SELECT date_created, status" . ($kind === 'faculty' ? ', role' : '') . " FROM $table WHERE $col = ?");
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

    regJson([
        'ok' => true, 'id' => $id, 'status' => $lift ? 'active' : 'restricted', 'reason' => $lift ? '' : $reason,
        'message' => $lift ? "Restriction lifted for $id." : "$id is now restricted.",
    ]);
}