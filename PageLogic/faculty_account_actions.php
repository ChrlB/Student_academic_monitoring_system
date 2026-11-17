<?php

require_once __DIR__ . '/faculty_account_logic.php';
require_once __DIR__ . '/../Services/csrf.php';

function facultyJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Logged in, a faculty account, valid CSRF token. Returns [facultyID, year, role] or answers with an error. */
function facultyGuard(array $post): array {
    $key = accountRowKey();
    if (empty($_SESSION['userID']) || $key === null || $key[0] !== 'faculty') {
        facultyJson(['ok' => false, 'message' => 'Please log in again.'], 401);
    }
    if (!csrfValid($post['csrf_token'] ?? null)) {
        facultyJson(['ok' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
    }
    return [$key[1], $key[2], (string)($_SESSION['role'] ?? '')];
}

/** Thin wrapper over validateProfileValue(). Returns [cleanValue, error|null]. */
function validateFacultyValue(array $s, string $raw): array {
    $v = trim(preg_replace('/\s+/', ' ', $raw));
    return validateProfileValue('', [$s['label'], $s['type'], $s['limit'], $s['required']], $raw);
}

function saveFacultyInfo($post) {
    global $userDAO;
    [$num, $year, $role] = facultyGuard($post);
    facultyLoadAccountHelpers();   // only after the login check in facultyGuard()

    $row = loadFacultyRow($num, $year);
    if (!$row) facultyJson(['ok' => false, 'message' => 'Could not find your record.'], 404);

    $spec   = facultyFieldSpec();
    $clean  = [];
    $errors = [];
    foreach (facultyEditableKeys($role) as $k) {
        $raw = $post[$k] ?? '';
        [$value, $err] = validateFacultyValue($spec[$k], is_string($raw) ? $raw : '');
        if ($err) $errors[$k] = $err; else $clean[$k] = $value;
    }
    if ($errors) {
        facultyJson(['ok' => false, 'errors' => $errors, 'message' => reset($errors)], 422);
    }

    try {
        $userDAO->executeQuery('updateFacultyBasic', [
            $clean['middle_name']   ?? (string)$row['middle_name'],
            $clean['date_of_birth'] ?? (string)$row['date_of_birth'],
            strtolower($clean['gender'] ?? (string)$row['gender']),
            $clean['address']       ?? (string)$row['address'],
            $clean['number']        ?? (string)$row['number'],
            $num, $year,
        ]);
    } catch (Throwable $e) {
        error_log('faculty info save failed: ' . $e->getMessage());
        facultyJson(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }

    // send the stored (normalised) values back so the boxes show exactly what was saved
    $fresh = loadFacultyRow($num, $year);
    $values = [];
    foreach (array_keys($clean) as $k) {
        $values[$k] = facultyDisplayValue($k, $fresh, (string)$_SESSION['userID']);
    }
    facultyJson(['ok' => true, 'message' => 'Changes saved.', 'values' => $values]);
}

function saveFacultyContact($post) {
    global $userDAO;
    [$num, $year] = facultyGuard($post);

    $raw = $post['number'] ?? '';
    [$number, $err] = validateProfileValue('number', ['Contact Number', 'phone', 20, true], is_string($raw) ? $raw : '');
    if ($err) facultyJson(['ok' => false, 'message' => $err, 'errors' => ['number' => $err]], 422);

    try {
        $userDAO->executeQuery('updateFacultyNumber', [$number, $num, $year]);
    } catch (Throwable $e) {
        error_log('faculty contact save failed: ' . $e->getMessage());
        facultyJson(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
    facultyJson(['ok' => true, 'message' => 'Profile saved.', 'values' => ['number' => $number]]);
}