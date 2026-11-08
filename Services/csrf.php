<?php
require_once __DIR__ . '/session.php';   // makes sure the session is started

// Returns this visitor's token, creating it the first time it is needed.
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// True only if the submitted token matches the one stored in the session.
// The session token must exist: two empty strings would otherwise count as a "match".
function csrfValid($submitted): bool {
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    return $stored !== '' && is_string($submitted) && hash_equals($stored, $submitted);
}