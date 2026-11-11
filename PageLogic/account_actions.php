<?php

require_once __DIR__ . '/../Services/session.php';
require_once __DIR__ . '/../Services/csrf.php';

const PW_MIN_LENGTH = 8;
const PW_MAX_LENGTH = 72;      // bcrypt only reads the first 72 bytes
const PW_MAX_FAILS  = 5;       // wrong "current password" tries ...
const PW_LOCK_MINUTES = 15;    // ... per this many minutes

// Roles allowed to change their password from the account page.
// Students only for now. Faculty roles (e.g. 'prof') get added here later; the registrar is never added.
const PW_CHANGE_ROLES = ['student'];

function canChangePassword(): bool {
    return in_array((string)($_SESSION['role'] ?? ''), PW_CHANGE_ROLES, true);
}

// false = only date of birth, sex and street are saved (columns that exist today).
// Run sql/students_profile_columns.sql, add the extra columns to getStudentInfo, then set this to true.
const PROFILE_EXTRA_COLUMNS = true;

// the school email domain (same constant the login/reset files use)
if (!defined('SCHOOL_EMAIL_DOMAIN')) define('SCHOOL_EMAIL_DOMAIN', '@gordoncollege.edu.ph');

/* ---------- small helpers ---------- */

// '/account' -> BASE_PATH . '/account' (BASE_PATH is '' when the site runs at the root)
function accountUrl(string $path): string {
    return (defined('BASE_PATH') ? BASE_PATH : '') . $path;
}

function accountDone(string $text): void {
    $_SESSION['message'] = $text;
    header('Location: ' . accountUrl('/account'));
    exit;
}

function accountFail(string $dialog, string $error, array $old = []): void {
    $_SESSION['account_dialog'] = ['open' => $dialog, 'error' => $error, 'old' => $old];
    header('Location: ' . accountUrl('/account'));
    exit;
}

// read once by View/account.php; gone after that
function takeDialogState(): array {
    $s = $_SESSION['account_dialog'] ?? ['open' => '', 'error' => '', 'old' => []];
    unset($_SESSION['account_dialog']);
    return $s;
}

function accountDeny(string $text): void {
    $_SESSION['error'] = $text;
    header('Location: ' . accountUrl('/account'));
    exit;
}

// [table, numeric id, year] from the session userID, or null
function accountRowKey(): ?array {
    $id = (string)($_SESSION['userID'] ?? '');
    if (preg_match('/^(\d{4})(\d{5})$/', $id, $m))  return ['students', (int)$m[2], (int)$m[1]];
    if (preg_match('/^F(\d{4})(\d{5})$/', $id, $m)) return ['faculty',  (int)$m[2], (int)$m[1]];
    return null;
}

function accountUserOrLogin(): array {
    $key = accountRowKey();
    if (empty($_SESSION['userID']) || $key === null) {
        header('Location: ' . accountUrl('/login'));
        exit;
    }
    return $key;
}

function changePassword($post) {
    global $userDAO;
    [$table, $num, $year] = accountUserOrLogin();

    if (!canChangePassword()) {
        accountDeny('Changing the password is not available for this account.');
    }
    if (!csrfValid($post['csrf_token'] ?? null)) {
        accountFail('password', 'Your session expired. Please try again.');
    }

    $current = (string)($post['current_password'] ?? '');
    $new     = (string)($post['new_password'] ?? '');
    $confirm = (string)($post['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        accountFail('password', 'All fields are required. Password must be at least eight (8) characters or more.');
    }
    if (mb_strlen($new) < PW_MIN_LENGTH) {
        accountFail('password', 'Password must be at least eight (8) characters or more.');
    }
    if (strlen($new) > PW_MAX_LENGTH) {
        accountFail('password', 'Password is too long (72 characters at most).');
    }
    if ($new !== $confirm) {
        accountFail('password', 'New password and confirm password do not match.');
    }

    // throttle guessing of the current password (per session)
    $now  = time();
    $hits = array_values(array_filter($_SESSION['pw_fails'] ?? [], fn($t) => $t > $now - PW_LOCK_MINUTES * 60));
    $_SESSION['pw_fails'] = $hits;
    if (count($hits) >= PW_MAX_FAILS) {
        accountFail('password', 'Too many wrong attempts. Try again in ' . PW_LOCK_MINUTES . ' minutes.');
    }

    try {
        $records = $userDAO->executeQuery('getUserByID', [(string)$_SESSION['userID']]);
        $user = (is_array($records) && !empty($records)) ? $records[0] : null;
        if (!is_array($user) || empty($user['password'])) {
            accountFail('password', 'Something went wrong. Please try again.');
        }

        if (!password_verify($current, (string)$user['password'])) {
            $_SESSION['pw_fails'][] = $now;
            accountFail('password', 'Current password is incorrect.');
        }
        if (hash_equals($current, $new)) {
            accountFail('password', 'New password must be different from the current one.');
        }

        $hash   = password_hash($new, PASSWORD_DEFAULT);
        $update = $table === 'students' ? 'updateStudentPassword' : 'updateFacultyPassword';
        $userDAO->executeQuery($update, [$hash, $num, $year]);

        // make sure it really changed (an UPDATE that matches no row does not raise an error)
        $check = $userDAO->executeQuery('getUserByID', [(string)$_SESSION['userID']]);
        if (!is_array($check) || empty($check) || !password_verify($new, (string)($check[0]['password'] ?? ''))) {
            error_log('change password: update did not stick for ' . $_SESSION['userID']);
            accountFail('password', 'Could not save the new password. Please try again.');
        }
    } catch (Throwable $e) {
        error_log('change password failed: ' . $e->getMessage());
        accountFail('password', 'Something went wrong. Please try again.');
    }

    unset($_SESSION['pw_fails']);
    session_regenerate_id(true);
    accountDone('Password changed successfully.');
}

// Single source of truth: field => [label, type, max length or options, required, needs extra column]
function profileRules(): array {
    return [
        'middle_name'        => ['Middle Name',        'text',   50,  false, false],
        'email'              => ['Email Address',      'email',  100, false, true],
        'date_of_birth'      => ['Date of Birth',      'date',   null, true,  false],
        'sex'                => ['Sex',                'select', ['Male', 'Female'], true, false],
        'street'             => ['House No/Lot No/Street', 'text', 120, false, false],
        'place_of_birth'     => ['Place of Birth',     'text',   100, false, true],
        'civil_status'       => ['Civil Status',       'select', ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], false, true],
        'sexual_orientation' => ['Sexual Orientation', 'text',   40,  false, true],
        'citizenship'        => ['Citizenship',        'text',   40,  false, true],
        'religion'           => ['Religion',           'text',   60,  false, true],
        'region'             => ['Region',             'text',   60,  false, true],
        'province'           => ['Province',           'text',   60,  false, true],
        'municipality'       => ['Municipality',       'text',   60,  false, true],
        'zip_code'           => ['Zip Code',           'zip',    4,   false, true],
    ];
}

// returns [cleanValue, errorText|null]
function validateProfileValue(string $field, array $rule, string $raw): array {
    [$label, $type, $limit, $required] = $rule;
    $v = trim(preg_replace('/\s+/', ' ', $raw));

    if ($v === '') return $required ? ['', "$label is required."] : ['', null];

    if ($type === 'date') {
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        $ok = $d && $d->format('Y-m-d') === $v && $v >= '1900-01-01' && $v <= date('Y-m-d');
        return $ok ? [$v, null] : ['', "$label is not a valid date."];
    }
    if ($type === 'select') {
        foreach ($limit as $opt) if (strcasecmp($opt, $v) === 0) return [$opt, null];
        return ['', "$label has an invalid choice."];
    }
    if ($type === 'email') {
        if (mb_strlen($v) > $limit) return ['', "$label is too long ($limit characters at most)."];
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return ['', 'Enter a valid email address.'];
        $v = strtolower($v);
        $d = strtolower(SCHOOL_EMAIL_DOMAIN);
        if (substr($v, -strlen($d)) !== $d) return ['', 'Use your school email (ending in ' . SCHOOL_EMAIL_DOMAIN . ').'];
        return [$v, null];
    }
    if ($type === 'zip') {
        return preg_match('/^\d{4}$/', $v) ? [$v, null] : ['', 'Zip Code must be 4 digits.'];
    }
    if (mb_strlen($v) > $limit) return ['', "$label is too long ($limit characters at most)."];
    return [$v, null];
}

function updateProfile($post) {
    global $userDAO;
    [$table, $num, $year] = accountUserOrLogin();

    if ($table !== 'students') {
        accountDeny('Only students can edit this information.');
    }
    if (!csrfValid($post['csrf_token'] ?? null)) {
        accountFail('profile', 'Your session expired. Please try again.');
    }

    $clean = [];
    $old   = [];
    foreach (profileRules() as $field => $rule) {
        if ($rule[4] && !PROFILE_EXTRA_COLUMNS) continue;           // no column yet: ignore
        $raw = $post[$field] ?? '';
        $raw = is_string($raw) ? $raw : '';
        $old[$field] = $raw;
        [$value, $err] = validateProfileValue($field, $rule, $raw);
        if ($err) accountFail('profile', $err, $old);
        $clean[$field] = $value;
    }

    try {
        $exists = $userDAO->executeQuery('studentRowExists', [$num, $year]);
        if (!is_array($exists) || empty($exists)) {
            accountFail('profile', 'Could not find your record.', $old);
        }

        if (PROFILE_EXTRA_COLUMNS) {
            $userDAO->executeQuery('updateStudentProfileFull', [
                $clean['middle_name'], $clean['email'], $clean['date_of_birth'], $clean['sex'], $clean['street'],
                $clean['place_of_birth'], $clean['civil_status'], $clean['sexual_orientation'],
                $clean['citizenship'], $clean['religion'], $clean['region'], $clean['province'],
                $clean['municipality'], $clean['zip_code'], $num, $year,
            ]);
        } else {
            $userDAO->executeQuery('updateStudentProfile', [
                $clean['middle_name'], $clean['date_of_birth'], $clean['sex'], $clean['street'], $num, $year,
            ]);
        }
    } catch (Throwable $e) {
        error_log('update profile failed: ' . $e->getMessage());
        accountFail('profile', 'Something went wrong. Please try again.', $old);
    }

    accountDone('Profile updated successfully.');
}