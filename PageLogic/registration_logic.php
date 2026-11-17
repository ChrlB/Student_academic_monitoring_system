<?php
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/../Services/csrf.php';
require_once __DIR__ . '/registration_actions.php';

if (strtolower((string)($_SESSION['role'] ?? '')) !== 'registrar') {
    $_SESSION['error'] = 'Only the registrar can open the registration page.';
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}
$_SESSION['page'] = 'REGISTRATION';

if (!function_exists('e')) {
    function e($text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$regAccounts = [];
$regLoadError = '';
try {
    $regAccounts = regDirectory();
} catch (Throwable $err) {
    error_log('registration directory failed: ' . $err->getMessage());
    $regLoadError = 'The account list could not be loaded.';
}

$regDepartments = [];
try {
    global $db_connection;
    $regDepartments = $db_connection->query('SELECT department, name FROM departments ORDER BY department')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $err) {
    error_log('registration departments failed: ' . $err->getMessage());
}

$regAsgReady = regAssignmentsReady();   