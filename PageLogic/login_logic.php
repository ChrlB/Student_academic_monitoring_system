<?php

require_once __DIR__ . '/../Services/session.php';
require_once __DIR__ . '/../Services/csrf.php';

const LOGIN_MAX_FAILS    = 5;    
const LOGIN_LOCK_MINUTES = 5;   

// The ID exactly as typed (trimmed, max 20 characters) so the form can show it again. Never used for a lookup.
function loginTypedId($raw): string {
    return mb_substr(trim((string)$raw), 0, 20);
}

function loginFail(string $message, string $typedId = ''): void {
    $_SESSION['error'] = $message;
    if ($typedId !== '') $_SESSION['login_old'] = $typedId;
    header('Location: ' . BASE_PATH . '/login');
    exit;
}

function loginFieldFail(array $errors, string $typedId): void {
    $_SESSION['login_errors'] = $errors;
    $_SESSION['login_old']    = $typedId;
    header('Location: ' . BASE_PATH . '/login');
    exit;
}

function loginRecentFails(): array {
    $since = time() - LOGIN_LOCK_MINUTES * 60;
    $_SESSION['login_fails'] = array_values(array_filter($_SESSION['login_fails'] ?? [], fn($t) => $t > $since));
    return $_SESSION['login_fails'];
}

function normalizeLoginId(string $raw): string {
    $raw = trim($raw);

    return preg_match('/^[A-Za-z0-9-]{4,20}$/', $raw)
        ? strtoupper($raw)
        : '';
}
function logout() {
    terminateSession();
    header('Location: ' . BASE_PATH . '/login?status=loggedout');
    exit;
}

function login($login_info) {
    global $userDAO;

    $typedId = loginTypedId($login_info['username'] ?? '');

    if (!csrfValid($login_info['csrf_token'] ?? null)) {
        loginFail('Your session expired. Please try again.', $typedId);
    }

    $userID   = normalizeLoginId((string)($login_info['username'] ?? ''));
    $password = trim((string)($login_info['password'] ?? ''));

    $errors = [];
    if ($typedId === '') {
        $errors['username'] = 'Enter your School ID.';
    } elseif ($userID === '') {
        $errors['username'] = 'Enter a valid School ID, e.g. 202600001.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }
    if ($errors) {
        loginFieldFail($errors, $typedId);
    }

    if (count(loginRecentFails()) >= LOGIN_MAX_FAILS) {
        loginFail('Too many failed attempts. Try again in ' . LOGIN_LOCK_MINUTES . ' minutes.', $typedId);
    }

    $records = [];

    try {
        $records = $userDAO->executeQuery('getUserByID', [$userID]);
    } catch (Throwable $e) {
        error_log('Login query failed: ' . $e->getMessage());
        loginFail('Something went wrong. Please try again later.', $typedId);
    }

    $user = (is_array($records) && !empty($records))
        ? $records[0]
        : null;

    if (!is_array($user) || !isset($user['password'])) {
        $_SESSION['login_fails'][] = time();
        loginFieldFail(['username' => 'School ID not found.'], $typedId);
    }

    if (!password_verify($password, (string)$user['password'])) {
        $_SESSION['login_fails'][] = time();
        loginFieldFail(['password' => 'Incorrect password.'], $typedId);
    }
    session_regenerate_id(true);

    $_SESSION['userID'] = $user['userID'] ?? $userID;
    $_SESSION['user'] = $user['last_name'] ?? '';
    $_SESSION['role'] = $user['role'] ?? '';

    $_SESSION['folderID'] = $user['folderID'] ?? null;
    $_SESSION['real_userID'] = $user['real_userID'] ?? null;

    $_SESSION['message'] = 'Account verification successful';

    unset($_SESSION['csrf_token'], $_SESSION['error'], $_SESSION['login_errors'], $_SESSION['login_old'], $_SESSION['login_fails']);

    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}