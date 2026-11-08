<?php


require_once __DIR__ . '/../Services/auth.php';
$_SESSION["page"] = "ACCOUNT";

if (!function_exists('e')) {
    function e($text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// 1 -> "1st Year", 2 -> "2nd Year" ...
function yearLevelLabel($n): string {
    $n = (int)$n;
    if ($n < 1) return '';
    $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n] ?? 'th';
    return $n . $suffix . ' Year';
}
function nameCase(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function buildFullName(array $r): string {
    $first  = trim((string)($r['first_name']  ?? ''));
    $middle = trim((string)($r['middle_name'] ?? ''));
    $last   = trim((string)($r['last_name']   ?? ''));
    $initial = $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) . '.' : '';
    return trim(preg_replace('/\s+/', ' ', "$first $initial $last"));
}
/* Returns everything the page needs:
   [
     'isStudent'   => bool,
     'role'        => 'student',
     'schoolId'    => '202600001',
     'name'        => 'Fred Cruz',                 // short name for the profile card
     'fullName'    => 'Fred M. Cruz',
     'phone'       => '0909-090-90009',
     'programYear' => 'BSCS-1A • 1st Year',        // students only
     'personal'    => [ first_name, last_name, middle_name, birth_date, birth_place, civil_status, sex, orientation, citizenship, religion ],
     'contact'     => [ phone, email ],
     'address'     => [ region, province, municipality, street, zip ],
   ]
   Keys whose column does not exist in the database yet come back as ''. */
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
    foreach (['first_name', 'middle_name', 'last_name'] as $k) {
        if (isset($row[$k])) $row[$k] = nameCase((string)$row[$k]);
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
        'name'        => trim($s('first_name') . ' ' . $s('last_name')),
        'fullName'    => buildFullName($row),
        'phone'       => $s('number'),
        'programYear' => $programYear,

        'personal' => [
            'first_name'   => $s('first_name'),
            'last_name'    => $s('last_name'),
            'middle_name'  => $s('middle_name'),
            'birth_date'   => $s('date_of_birth'),
            'birth_place'  => $s('place_of_birth'),     // no column yet
            'civil_status' => $s('civil_status'),       // no column yet
            'sex'          => $s('gender', 'sex'),
            'orientation'  => $s('sexual_orientation'), // no column yet
            'citizenship'  => $s('citizenship'),        // no column yet
            'religion'     => $s('religion'),           // no column yet
        ],
        'contact' => [
            'phone' => $s('number'),
            'email' => $userID !== '' ? $userID . '@gordoncollege.edu.ph' : '',   // derived, not stored
        ],
        'address' => [
            'region'       => $s('region'),             // no column yet
            'province'     => $s('province'),           // no column yet
            'municipality' => $s('municipality'),       // no column yet
            'street'       => $s('address'),
            'zip'          => $s('zip_code'),           // no column yet
        ],
    ];
}