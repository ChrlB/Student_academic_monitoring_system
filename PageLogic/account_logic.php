<?php
require_once __DIR__ . '/../Services/auth.php';
$_SESSION["page"] = "ACCOUNT";

// Safe printing helper (htmlspecialchars). Guarded in case another file already defines it.
if (!function_exists('e')) {
    function e($text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function yearLevelLabel($n): string {
    $n = (int)$n;
    if ($n < 1) return '';
    $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n] ?? 'th';
    return $n . $suffix . ' Year';
}

function properName(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '') return '';
    return preg_replace_callback('/(^|[\s\-\'’])(\p{Ll})/u', function ($m) {
        return $m[1] . mb_strtoupper($m[2], 'UTF-8');
    }, $s);
}
// Email shown when the student has no saved email yet: <school ID>@<school domain>
function schoolEmailFor(string $userID): string {
    $domain = defined('SCHOOL_EMAIL_DOMAIN') ? SCHOOL_EMAIL_DOMAIN : '@amscollege.edu.ph';   // one place to change: account_actions.php
    return $userID !== '' ? $userID . $domain : '';
}

function buildFullName(array $r): string {
    $first  = properName((string)($r['first_name']  ?? ''));
    $middle = trim((string)($r['middle_name'] ?? ''));
    $last   = properName((string)($r['last_name']   ?? ''));
    $initial = $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) . '.' : '';
    return trim(preg_replace('/\s+/', ' ', "$first $initial $last"));
}

function getAccountInfo(): array {
    global $userDAO;

    $userID    = (string)($_SESSION['userID'] ?? '');
    $role      = (string)($_SESSION['role'] ?? '');
    $isStudent = $role === 'student';
    $query     = $isStudent ? 'getStudentInfo' : 'getFacultyInfo';     // same two queries as before

    $row = [];
    try {
        $records = $userDAO->executeQuery($query, [$userID]);
        if (is_array($records) && !empty($records) && is_array($records[0])) {
            $row = $records[0];
        }
    } catch (Throwable $err) {
        error_log('account info query failed: ' . $err->getMessage());
    }

    // first non-empty value among the given column names, or ''
    $s = function (string ...$keys) use ($row): string {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]);
        }
        return '';
    };

    $program = $s('program');
    $block   = $s('block');
    $year    = yearLevelLabel($row['year_level'] ?? 0);
    $programYear = trim(($program !== '' ? $program : '') . ($block !== '' ? '-' . $block : ''));
    if ($year !== '') $programYear = trim($programYear . ' • ' . $year, ' •');

    return [
        'isStudent'   => $isStudent,
        'role'        => $role,
        'schoolId'    => $userID,
        'name'        => trim(properName($s('first_name')) . ' ' . properName($s('last_name'))),
        'fullName'    => buildFullName($row),
        'phone'       => $s('number'),
        'programYear' => $programYear,
        'personal' => [
            'first_name'   => properName($s('first_name')),
            'last_name'    => properName($s('last_name')),
            'middle_name'  => properName($s('middle_name')),
            'birth_date'   => $s('date_of_birth'),
            'birth_place'  => $s('place_of_birth'),    
            'civil_status' => $s('civil_status'),       
            'sex'          => $s('gender', 'sex'),
            'orientation'  => $s('sexual_orientation'), 
            'citizenship'  => $s('citizenship'),        
            'religion'     => $s('religion'),           
        ],
        'contact' => [
            'phone' => $s('number'),
            'email' => $s('email') ?: schoolEmailFor($userID),   // stored email if there is one, else derived from the ID
        ],
        'address' => [
            'region'       => $s('region'),             
            'province'     => $s('province'),           
            'municipality' => $s('municipality'),       
            'street'       => $s('address'),
            'zip'          => $s('zip_code'),           
        ],
    ];
}