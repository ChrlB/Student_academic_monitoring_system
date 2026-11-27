<?php
require_once __DIR__ . '/../Services/account_log.php';
require_once __DIR__ . '/account_actions.php';
require_once __DIR__ . '/../Services/google_link.php';

function googleLinkJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function linkGoogleAccount($post) {
    global $db_connection;
    $key = accountRowKey();
    if (empty($_SESSION['userID']) || $key === null || $key[0] !== 'students') {
        googleLinkJson(['ok' => false, 'message' => 'Please log in with a student account.'], 401);
    }
    if (!csrfValid($post['csrf_token'] ?? null)) {
        googleLinkJson(['ok' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
    }
    [, $num, $year] = $key;

    [$email, $err] = googleVerifyIdToken((string)($post['credential'] ?? ''));
    if ($err) googleLinkJson(['ok' => false, 'message' => $err], 422);

    try {
        foreach (['students' => 'studentID', 'faculty' => 'facultyID'] as $t => $idCol) {
            try {
                $st = $db_connection->prepare("SELECT $idCol FROM $t WHERE LOWER(email) = ?" . ($t === 'students' ? " AND $idCol <> ?" : ''));
                $st->execute($t === 'students' ? [$email, $num] : [$email]);
                if ($st->fetchColumn() !== false) {
                    googleLinkJson(['ok' => false, 'message' => 'That Google email is already linked to another account.'], 422);
                }
            } catch (PDOException $e) { /* table has no email column yet: nothing to clash with */ }
        }
        $st = $db_connection->prepare('UPDATE students SET email = ? WHERE studentID = ? AND YEAR(date_created) = ?');
        $st->execute([$email, $num, $year]);
    } catch (Throwable $e) {
        error_log('google link failed: ' . $e->getMessage());
        googleLinkJson(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
    accountLog($db_connection ?? null, (string)$_SESSION['userID'], '', 'updated', 'Linked Google account: ' . $email);
    googleLinkJson(['ok' => true, 'email' => $email, 'message' => 'Google account linked.']);
}