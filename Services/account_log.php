<?php
/**
 * Account activity log (table account_logs, created by logs_migration.sql).
 * One row per event: who did what to which account and when. Every call is best effort: if the table does not
 * exist yet, or the insert fails, the real action (login, registration change...) carries on unaffected.
 *
 * action codes: created, updated, restricted, lifted, archived, restored, deleted, assigned, unassigned,
 *               login, login_blocked, login_failed
 */
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
                mb_substr($actorId, 0, 20), mb_substr($actorName, 0, 100), date('Y-m-d H:i:s'),
            ]);
    } catch (Throwable $e) {
        error_log('account_logs write skipped (run logs_migration.sql?): ' . $e->getMessage());
    }
}