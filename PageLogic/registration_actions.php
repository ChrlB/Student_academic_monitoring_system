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

function regTitle(string $s): string {
    return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

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
    $name = trim(regTitle(trim((string)$r['first_name'])) . ' '
          . ($mid !== '' ? mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '') . ' '
          . regTitle(trim((string)$r['last_name'])));
    return preg_replace('/\s+/', ' ', $name);
}


function regAccount(string $kind, array $r, array $reasons = [], ?array $asg = null): array {
    $num  = (int)($kind === 'student' ? $r['studentID'] : $r['facultyID']);
    $id   = regUserId($kind === 'student' ? 'student' : 'faculty', $num, (string)$r['date_created']);
    $name = regDisplayName($r);
    if ($asg === null) $asg = regAssignmentIndex();

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
        // your own account and registrar accounts cannot be edited, deleted or restricted from this page
        'locked' => $role === 'registrar' || strtoupper($id) === strtoupper((string)($_SESSION['userID'] ?? '')),
        // students: the professors assigned to them (one entry per subject / advising role); faculty: how many students
        'assignments'   => $kind === 'student' ? ($asg['student'][$num] ?? []) : [],
        'assignedCount' => $kind === 'faculty' ? (int)($asg['faculty'][$num] ?? 0) : 0,
    ];
}

/* ------------------------------------------------------ assignments */

function regAssignmentsReady(): bool {
    try {
        regPdo()->query('SELECT assignment_type, assignment FROM professor_student LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function regAssignmentIndex(): array {
    $pdo = regPdo();
    $cols = regAssignmentsReady() ? 'aa.assignment_type, aa.assignment' : "'professor' AS assignment_type, 'Academic professor' AS assignment";
    $out = ['student' => [], 'faculty' => []];
    try {
        $rows = $pdo->query(
            "SELECT aa.id, aa.studentID, aa.facultyID, $cols,
                    f.first_name, f.middle_name, f.last_name, f.role, f.date_created
               FROM professor_student aa JOIN faculty f ON f.facultyID = aa.facultyID
              ORDER BY aa.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration assignments failed: ' . $e->getMessage());
        return $out;
    }
    $seen = [];
    foreach ($rows as $r) {
        $sNum = (int)$r['studentID']; $fNum = (int)$r['facultyID'];
        $out['student'][$sNum][] = [
            'id'        => (int)$r['id'],
            'facultyId' => regUserId('faculty', $fNum, (string)$r['date_created']),
            'name'      => regDisplayName($r),
            'roleLabel' => regRoleLabel((string)$r['role']),
            'type'      => $r['assignment_type'] === 'subject' ? 'subject' : 'professor',
            'label'     => (string)$r['assignment'],
        ];
        if (!isset($seen[$fNum][$sNum])) {
            $seen[$fNum][$sNum] = true;
            $out['faculty'][$fNum] = ($out['faculty'][$fNum] ?? 0) + 1;
        }
    }
    return $out;
}

/** A fresh directory entry for one account (used to refresh a row after a change). */
function regLoadAccount(PDO $pdo, string $kind, int $num): ?array {
    if ($kind === 'student') {
        $st = $pdo->prepare("SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department
                            FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?");
    } else {
        $st = $pdo->prepare("SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status
                            FROM faculty WHERE facultyID = ?");
    }
    $st->execute([$num]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? regAccount($kind, $r, regReasons(), regAssignmentIndex()) : null;
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
    $asg = regAssignmentIndex();
    $out = [];
    $students = $pdo->query(
        "SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created,
                s.status, p.department
        FROM students s LEFT JOIN programs p ON p.program = s.program
        ORDER BY s.date_created, s.studentID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($students as $r) $out[] = regAccount('student', $r, $reasons, $asg);

    $faculty = $pdo->query(
        "SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status
        FROM faculty ORDER BY date_created, facultyID"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($faculty as $r) $out[] = regAccount('faculty', $r, $reasons, $asg);
    return $out;
}

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

function regCheckAssignment(PDO $pdo, string $kind, string $roleValue, array $post, array &$errors): array {
    $dept = strtoupper(trim((string)($post['department'] ?? '')));
    $st = $pdo->prepare('SELECT 1 FROM departments WHERE department = ?');
    $st->execute([$dept]);
    if ($dept === '' || !$st->fetchColumn()) $errors['department'] = 'Choose a valid department.';

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
    return [$dept, $program, $yearLevel, $block];
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

function regFindAccount(PDO $pdo, $rawId): array {
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
    if ($errors) {
        regJson(['ok' => false, 'errors' => $errors, 'message' => reset($errors)], 422);
    }

    [$first, $mid, $last] = $parts;
    try {
        if ($kind === 'student') {
            $pdo->prepare('UPDATE students SET first_name=?, middle_name=?, last_name=?, program=?, year_level=?, block=? WHERE studentID=?')
                ->execute([$first, $mid, $last, $program, $yearLevel, $block, $num]);
            $st = $pdo->prepare("SELECT s.studentID, s.first_name, s.middle_name, s.last_name, s.program, s.block, s.date_created, s.status, p.department
                                   FROM students s LEFT JOIN programs p ON p.program = s.program WHERE s.studentID = ?");
        } else {
            $pdo->prepare('UPDATE faculty SET first_name=?, middle_name=?, last_name=?, role=?, department=?, program=? WHERE facultyID=?')
                ->execute([$first, $mid, $last, $roleValue, $dept, $program, $num]);
            $st = $pdo->prepare("SELECT facultyID, first_name, middle_name, last_name, role, department, program, date_created, status
                                   FROM faculty WHERE facultyID = ?");
        }
        $st->execute([$num]);
        $fresh = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('registration update failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not save the changes. Please try again.'], 500);
    }

    $acct = regAccount($kind, $fresh, regReasons());
    regJson(['ok' => true, 'account' => $acct, 'message' => $acct['name'] . ' (' . $acct['id'] . ') was updated.']);
}

function registrationDelete($post) {
    regGuard($post);
    $pdo = regPdo();
    [$id, $kind, $num, $table, $col] = regFindAccount($pdo, $post['school_id'] ?? '');

    if ($kind === 'faculty') {   // do not silently drop a professor's student assignments
        $n = 0;
        try {
            $st = $pdo->prepare('SELECT COUNT(DISTINCT studentID) FROM professor_student WHERE facultyID = ?');
            $st->execute([$num]);
            $n = (int)$st->fetchColumn();
        } catch (Throwable $e) { /* no professor_student table yet: nothing is assigned */ }
        if ($n > 0) {
            regJson(['ok' => false, 'message' => "$id is assigned to $n student" . ($n === 1 ? '' : 's') . '. Remove those assignments first, then delete the account.'], 409);
        }
    }

    try {
        $pdo->prepare("DELETE FROM $table WHERE $col = ?")->execute([$num]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {   // foreign key: enrollments, attendance, clearance... still point to this account
            regJson(['ok' => false, 'message' => "$id has records (enrollment, attendance, clearance or schedules) and cannot be deleted. Restrict the account instead."], 409);
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

    regJson(['ok' => true, 'id' => $id, 'message' => "$id was deleted."]);
}

function regNeedMigration(): void {
    if (!regAssignmentsReady()) {
        regJson(['ok' => false, 'message' => 'Assignments need a one-time database update, then try again.'], 409);
    }
}

/** Both rows an assignment change touches, for the page to redraw. */
function regAssignmentReply(PDO $pdo, int $studentNum, int $facultyNum, string $message): void {
    $accounts = array_values(array_filter([
        regLoadAccount($pdo, 'student', $studentNum),
        regLoadAccount($pdo, 'faculty', $facultyNum),
    ]));
    regJson(['ok' => true, 'message' => $message, 'accounts' => $accounts]);
}

function registrationAssign($post) {
    regGuard($post);
    regNeedMigration();
    $pdo = regPdo();

    [$sid, $sKind, $sNum] = regFindAccount($pdo, $post['school_id'] ?? '');
    if ($sKind !== 'student') regJson(['ok' => false, 'message' => 'Choose a student.'], 422);

    $errors = [];
    $fRaw = strtoupper(trim((string)($post['faculty_id'] ?? '')));
    $fNum = 0; $fRow = null;
    if ($fRaw === '' || !preg_match('/^F\d{9}$/', $fRaw)) {
        $errors['faculty_id'] = 'Choose a professor.';
    } else {
        [, , $fNum, , , $fRow] = regFindAccount($pdo, $fRaw);   // answers 404 / 422 itself for unknown, registrar
    }

    $type = (string)($post['type'] ?? '');
    if (!in_array($type, ['subject', 'professor'], true)) $errors['type'] = 'Choose the assignment type.';

    $label = trim(preg_replace('/\s+/u', ' ', (string)($post['assignment'] ?? '')));
    if ($type === 'professor' && $label === '') $label = 'Academic professor';
    if ($type === 'subject' && $label === '') $errors['assignment'] = 'Enter the course or subject, e.g. CS 202.';
    if (mb_strlen($label) > 100) $errors['assignment'] = 'Keep this under 100 characters.';

    if (!$errors) {
        // the same professor may teach several subjects of one student, but not the same one twice;
        // and only one professor link per professor and student (enrollment treats any professor link as the professor)
        $st = $pdo->prepare('SELECT 1 FROM professor_student WHERE facultyID = ? AND studentID = ? AND assignment_type = ?'
                          . ($type === 'subject' ? ' AND LOWER(assignment) = LOWER(?)' : ''));
        $st->execute($type === 'subject' ? [$fNum, $sNum, $type, $label] : [$fNum, $sNum, $type]);
        if ($st->fetchColumn()) {
            $errors[$type === 'subject' ? 'assignment' : 'faculty_id'] = $type === 'subject'
                ? 'This professor is already assigned to that subject for this student.'
                : 'This professor is already this student\'s academic professor.';
        }
    }
    if ($errors) regJson(['ok' => false, 'errors' => $errors, 'message' => reset($errors)], 422);

    try {
        $pdo->prepare('INSERT INTO professor_student (facultyID, studentID, assignment_type, assignment) VALUES (?, ?, ?, ?)')
            ->execute([$fNum, $sNum, $type, $label]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            regJson(['ok' => false, 'errors' => ['assignment' => 'That assignment already exists.'], 'message' => 'That assignment already exists.'], 422);
        }
        error_log('registration assign failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not save the assignment. Please try again.'], 500);
    } catch (Throwable $e) {
        error_log('registration assign failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not save the assignment. Please try again.'], 500);
    }

    regAssignmentReply($pdo, $sNum, $fNum, regDisplayName($fRow) . ' was assigned (' . $label . ').');
}

function registrationUnassign($post) {
    regGuard($post);
    $pdo = regPdo();
    [$sid, $sKind, $sNum] = regFindAccount($pdo, $post['school_id'] ?? '');
    if ($sKind !== 'student') regJson(['ok' => false, 'message' => 'Choose a student.'], 422);

    $aid = (int)($post['assignment_id'] ?? 0);
    $st = $pdo->prepare('SELECT facultyID FROM professor_student WHERE id = ? AND studentID = ?');
    $st->execute([$aid, $sNum]);
    $fNum = $st->fetchColumn();
    if ($fNum === false) {
        regJson(['ok' => false, 'message' => 'That assignment no longer exists. Reload the page.'], 404);
    }
    try {
        $pdo->prepare('DELETE FROM professor_student WHERE id = ? AND studentID = ?')->execute([$aid, $sNum]);
    } catch (Throwable $e) {
        error_log('registration unassign failed: ' . $e->getMessage());
        regJson(['ok' => false, 'message' => 'Could not remove the assignment. Please try again.'], 500);
    }
    regAssignmentReply($pdo, $sNum, (int)$fNum, 'Assignment removed.');
}