<?php
date_default_timezone_set('Asia/Manila'); 


/** "Last, M., First" for a school ID (students: YYYY+5 digits, faculty: F+YYYY+5 digits), or '' if not found. */
function accountLogName(PDO $pdo, string $id): string {
    $id = strtoupper(trim($id));
    if (!preg_match('/^(F?)(\d{4})(\d{5})$/', $id, $m)) return '';
    $table = $m[1] === 'F' ? 'faculty' : 'students';
    $col   = $m[1] === 'F' ? 'facultyID' : 'studentID';
    try {
        $st = $pdo->prepare("SELECT first_name, middle_name, last_name FROM $table WHERE $col = ?");
        $st->execute([(int)$m[3]]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ''; }
    if (!$r) return '';
    $t = fn($s) => mb_convert_case(trim((string)$s), MB_CASE_TITLE, 'UTF-8');
    $mid = trim((string)$r['middle_name']);
    return $t($r['last_name']) . ($mid !== '' ? ', ' . mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '') . ', ' . $t($r['first_name']);
}

function accountLog(?PDO $pdo, string $accountId, string $accountName, string $action, string $details = '',
                    ?string $actorId = null, ?string $actorName = null): void {
    if (!$pdo) return;
    try {
        if ($actorId === null) $actorId = (string)($_SESSION['userID'] ?? '');
        if ($actorName === null) $actorName = $actorId !== '' ? accountLogName($pdo, $actorId) : '';
        if ($accountName === '') $accountName = accountLogName($pdo, $accountId);
        $pdo->prepare('INSERT INTO account_logs (account_id, account_name, action, details, actor_id, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                mb_substr($accountId, 0, 20), mb_substr($accountName, 0, 150), mb_substr($action, 0, 30), mb_substr($details, 0, 500),
                mb_substr($actorId, 0, 20), mb_substr($actorName, 0, 100), (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
            ]);
    } catch (Throwable $e) {
        error_log('account_logs write skipped (run logs_migration.sql?): ' . $e->getMessage());
    }
}


/** Whole row of a student/faculty (by numeric id + year of date_created) or [] - used to see what a save changed. */
function accountLogSnapshot(?PDO $pdo, string $table, int $num, int $year): array {
    if (!$pdo) return [];
    try {
        $idCol = $table === 'faculty' ? 'facultyID' : 'studentID';
        $st = $pdo->prepare("SELECT * FROM $table WHERE $idCol = ? AND YEAR(date_created) = ?");
        $st->execute([$num, $year]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : [];
    } catch (Throwable $e) { return []; }
}

/** "Contact Number: 0917 → 0918; Religion: (empty) → Catholic" for the columns that differ, or '' if nothing changed. */
function accountLogDiff(array $before, array $after): string {
    if (!$before || !$after) return '';
    $skip  = ['password', 'date_created', 'status', 'archived_at', 'archived_prev_status', 'studentID', 'facultyID'];
    $label = ['middle_name' => 'Middle Name', 'number' => 'Contact Number', 'email' => 'Email', 'date_of_birth' => 'Date of Birth',
              'sex' => 'Sex', 'gender' => 'Sex', 'street' => 'Street', 'address' => 'Address', 'place_of_birth' => 'Place of Birth',
              'civil_status' => 'Civil Status', 'sexual_orientation' => 'Sexual Orientation', 'citizenship' => 'Citizenship',
              'religion' => 'Religion', 'region' => 'Region', 'province' => 'Province', 'municipality' => 'Municipality',
              'zip_code' => 'Zip Code'];
    $show = fn($v) => ($v === null || trim((string)$v) === '') ? '(empty)' : mb_substr(trim((string)$v), 0, 60);
    $out = [];
    foreach ($after as $col => $new) {
        if (in_array($col, $skip, true) || !array_key_exists($col, $before)) continue;
        if (trim((string)$before[$col]) === trim((string)$new)) continue;
        $out[] = ($label[$col] ?? ucwords(str_replace('_', ' ', $col))) . ': ' . $show($before[$col]) . ' → ' . $show($new);
    }
    return implode('; ', $out);
}

/**
 * Edit limit: how many times may this account save changed information within the window?
 * Counts the "Edited own information" log rows (saves with no changes are logged differently, so they do not count).
 * Returns '' when still allowed, otherwise a message saying when the next edit opens up.
 * If the log table is missing the limit cannot be checked and the edit is allowed.
 */
const SELF_EDIT_LIMIT = 3;           // edits allowed ...
const SELF_EDIT_WINDOW_HOURS = 24;   // ... per this many hours (rolling)

function accountEditLimitMessage(?PDO $pdo, string $accountId): string {
    if (!$pdo) return '';
    try {
        $since = (new DateTime('now', new DateTimeZone('Asia/Manila')))
            ->modify('-' . SELF_EDIT_WINDOW_HOURS . ' hours')->format('Y-m-d H:i:s');
        $st = $pdo->prepare("SELECT created_at FROM account_logs WHERE account_id = ? AND actor_id = ? AND action = 'updated'
                             AND details LIKE 'Edited own information%' AND created_at >= ? ORDER BY created_at ASC");
        $st->execute([$accountId, $accountId, $since]);
        $times = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return ''; }
    if (count($times) < SELF_EDIT_LIMIT) return '';
    // the edit that frees a slot is the (count - LIMIT + 1)-th oldest one
    $frees = strtotime($times[count($times) - SELF_EDIT_LIMIT]) + SELF_EDIT_WINDOW_HOURS * 3600;
    return 'You can only edit your information ' . SELF_EDIT_LIMIT . ' times every ' . SELF_EDIT_WINDOW_HOURS
         . ' hours. You can edit again after ' . date('M j, Y g:i A', $frees) . '.';
}