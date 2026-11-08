
<?php

require_once __DIR__ . '/../Services/session.php';
require_once __DIR__ . '/../Services/csrf.php';

// Redirect to login with an error message.
function loginFail(string $message): void {
    $_SESSION['error'] = $message;
    header('Location: ' . BASE_PATH . '/login');
    exit;
}

// Normalize and validate school ID.
function normalizeLoginId(string $raw): string {
    $raw = trim($raw);

    return preg_match('/^[A-Za-z0-9-]{4,20}$/', $raw)
        ? strtoupper($raw)
        : '';
}

// Logout user.
function logout() {
    terminateSession();
    header('Location: ' . BASE_PATH . '/login?status=loggedout');
    exit;
}

// Login user.
function login($login_info) {
    global $userDAO;

    // Validate CSRF token.
    if (!csrfValid($login_info['csrf_token'] ?? null)) {
        loginFail('Your session expired. Please try again.');
    }

    // Get and validate login credentials.
    $userID = normalizeLoginId(
        (string)($login_info['username'] ?? '')
    );

    $password = trim((string)($login_info['password'] ?? ''));

    if ($userID === '' || $password === '') {
        loginFail('Incorrect ID or password. Please try again.');
    }

    // Retrieve user from database.
    $records = [];

    try {
        $records = $userDAO->executeQuery('getUserByID', [$userID]);
    } catch (Throwable $e) {
        error_log('Login query failed: ' . $e->getMessage());
        loginFail('Something went wrong. Please try again later.');
    }

    // Validate user credentials.
    $user = (is_array($records) && !empty($records))
        ? $records[0]
        : null;

    if (
        !is_array($user) ||
        !isset($user['password']) ||
        !password_verify($password, (string)$user['password'])
    ) {
        loginFail('Incorrect ID or password. Please try again.');
    }

    // Regenerate session ID for security.
    session_regenerate_id(true);

    // Store user information in session.
    $_SESSION['userID'] = $user['userID'] ?? $userID;
    $_SESSION['user'] = $user['last_name'] ?? '';
    $_SESSION['role'] = $user['role'] ?? '';

    // Preserve existing session variables.
    $_SESSION['folderID'] = $user['folderID'] ?? null;
    $_SESSION['real_userID'] = $user['real_userID'] ?? null;

    $_SESSION['message'] = 'Account verification successful';

    unset($_SESSION['csrf_token'], $_SESSION['error']);

    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}
