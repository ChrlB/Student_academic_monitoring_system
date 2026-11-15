<?php

require_once __DIR__ . '/account_actions.php';   // accountRowKey(), validateProfileValue()

function facultyLoadAccountHelpers(): void {
    require_once __DIR__ . '/account_logic.php';   // properName(), buildFullName(), schoolEmailFor()
}

/** Department code => name shown on the profile card. Unknown codes are shown as typed. */
const FACULTY_DEPARTMENTS = [
    'CCS' => 'College of Computer Studies',
];


function facultyFieldSpec(): array {
    $f = fn(string $label, string $type, $limit = 100, bool $locked = false, bool $required = false) =>
        ['label' => $label, 'type' => $type, 'limit' => $limit, 'locked' => $locked, 'required' => $required];

    return [
        // personal (faculty table)
        'first_name'    => $f('First Name',          'text',   200, true),
        'last_name'     => $f('Last Name',           'text',   200, true),
        'middle_name'   => $f('Middle Name',         'text',   50),
        'date_of_birth' => $f('Date of Birth',       'date',   null, false, true),
        'gender'        => $f('Sex',                 'select', ['Male', 'Female'], false, true),
        'address'       => $f('Residential Address', 'text',   250, false, true),
        // assignment (faculty table, managed by the registrar)
        'role'          => $f('Position',            'text',   200, true),
        'department'    => $f('College / Department', 'text', 100, true),
        'program'       => $f('Program',             'text',   50, true),
        'status'        => $f('Account Status',      'text',   50, true),
        'date_created'  => $f('Date Joined',         'text',   20, true),
        // contact
        'institutional_email' => $f('Institutional Email', 'text', 100, true),
        'number'        => $f('Contact Number',      'phone',  20, false, true),
    ];
}

/** Role-wide wording: titles, labels, the avatar badge. */
function facultyRoleConfig(string $role): array {
    $role = strtolower(trim($role));
    $roles = [
        'dean' => [
            'key' => 'dean', 'name' => 'Dean', 'idLabel' => 'Dean ID:',
            'officeLabel' => 'College / Office:', 'badge' => 'DEAN', 'banner' => 'bank',
        ],
        'program coordinator' => [
            'key' => 'program-coordinator', 'name' => 'Program Coordinator', 'idLabel' => 'Coordinator ID:',
            'officeLabel' => 'College / Office:', 'badge' => 'COORD', 'banner' => 'clipboard',
        ],
        'prof' => [
            'key' => 'prof', 'name' => 'Professor', 'idLabel' => 'Faculty ID:',
            'officeLabel' => 'College / Office:', 'badge' => 'PROF', 'banner' => 'cap',
        ],
        'registrar' => [
            'key' => 'registrar', 'name' => 'Registrar', 'idLabel' => 'Staff ID:',
            'officeLabel' => 'Office:', 'badge' => 'REG', 'banner' => 'bank',
        ],
    ];
    return $roles[$role] ?? [
        'key' => preg_replace('/[^a-z0-9]+/', '-', $role) ?: 'faculty',
        'name' => ucwords($role) ?: 'Faculty', 'idLabel' => 'Faculty ID:',
        'officeLabel' => 'Office:', 'badge' => strtoupper(substr($role, 0, 5)) ?: 'FAC', 'banner' => 'cap',
    ];
}

/** Section layout per role. Each cell is [fieldKey, widthClass] (widths are twelfths of the row). */
function facultySections(string $role): array {
    $personal = ['title' => 'Personal Information', 'icon' => 'person', 'rows' => [
        [['first_name', 'w4'], ['last_name', 'w4'], ['middle_name', 'w4']],
        [['date_of_birth', 'w6'], ['gender', 'w6']],
        [['address', 'w12']],
    ]];
    $contact = fn(string $title) => ['title' => $title, 'icon' => 'phone', 'rows' => [
        [['institutional_email', 'w6'], ['number', 'w6']],
    ]];

    switch (strtolower(trim($role))) {
        case 'dean':
            return [
                $personal,
                ['title' => 'Executive & College Administration', 'icon' => 'briefcase', 'rows' => [
                    [['role', 'w4'], ['department', 'w4'], ['status', 'w4']],
                    [['date_created', 'w4']],
                ]],
                $contact('Contact & Executive Directory'),
            ];
        case 'program coordinator':
            return [
                $personal,
                ['title' => 'Program Coordination', 'icon' => 'book', 'rows' => [
                    [['role', 'w4'], ['department', 'w4'], ['program', 'w4']],
                    [['status', 'w4'], ['date_created', 'w4']],
                ]],
                $contact('Contact & Office Directory'),
            ];
        case 'registrar':
            return [
                $personal,
                ['title' => 'Office Assignment', 'icon' => 'briefcase', 'rows' => [
                    [['role', 'w4'], ['department', 'w4'], ['status', 'w4']],
                    [['date_created', 'w4']],
                ]],
                $contact('Contact & Office Directory'),
            ];
        default: // prof
            return [
                $personal,
                ['title' => 'Teaching Assignment', 'icon' => 'book', 'rows' => [
                    [['role', 'w4'], ['department', 'w4'], ['program', 'w4']],
                    [['status', 'w4'], ['date_created', 'w4']],
                ]],
                $contact('Contact & Office Directory'),
            ];
    }
}

/** Keys of the fields this role may edit with "Edit Information" (everything shown except locked ones). */
function facultyEditableKeys(string $role): array {
    $spec = facultyFieldSpec();
    $keys = [];
    foreach (facultySections($role) as $sec) {
        foreach ($sec['rows'] as $row) {
            foreach ($row as [$k]) {
                if (isset($spec[$k]) && !$spec[$k]['locked']) $keys[] = $k;
            }
        }
    }
    return $keys;
}

/** The logged-in faculty member's row from the faculty table ([] if not found). */
function loadFacultyRow(int $num, int $year): array {
    global $userDAO;
    try {
        $r = $userDAO->executeQuery('getFacultyAccount', [$num, $year]);
        if (is_array($r) && !empty($r) && is_array($r[0])) return $r[0];
    } catch (Throwable $err) {
        error_log('faculty account query failed: ' . $err->getMessage());
    }
    return [];
}

function facultyDisplayValue(string $key, array $row, string $userID): string {
    $v = trim((string)($row[$key] ?? ''));
    switch ($key) {
        case 'gender':
        case 'status':              return $v === '' ? '' : ucfirst(strtolower($v));
        case 'first_name':
        case 'last_name':
        case 'middle_name':         return properName($v);
        case 'role':                return facultyRoleConfig($v)['name'];
        case 'department':          return FACULTY_DEPARTMENTS[strtoupper($v)] ?? $v;
        case 'date_created':        return substr($v, 0, 10);
        case 'institutional_email': return schoolEmailFor($userID);
    }
    return $v;
}

/** Everything View/faculty/faculty_account.php needs. */
function getFacultyAccount(): array {
    facultyLoadAccountHelpers();
    $role   = (string)($_SESSION['role'] ?? '');
    $userID = (string)($_SESSION['userID'] ?? '');
    $cfg    = facultyRoleConfig($role);

    $key = accountRowKey();
    $row = ($key && $key[0] === 'faculty') ? loadFacultyRow($key[1], $key[2]) : [];

    $spec = facultyFieldSpec();
    $sections = [];
    foreach (facultySections($role) as $sec) {
        $rows = [];
        foreach ($sec['rows'] as $cells) {
            $out = [];
            foreach ($cells as [$k, $w]) {
                if (!isset($spec[$k])) continue;
                $s = $spec[$k];
                // a program-less account (e.g. dean) shows a dash instead of an empty box
                $out[] = [
                    'key' => $k, 'w' => $w, 'label' => $s['label'], 'type' => $s['type'],
                    'opts' => is_array($s['limit']) ? $s['limit'] : [],
                    'locked' => $s['locked'],
                    'value' => facultyDisplayValue($k, $row, $userID),
                ];
            }
            if ($out) $rows[] = $out;
        }
        $sections[] = ['title' => $sec['title'], 'icon' => $sec['icon'], 'rows' => $rows];
    }

    $deptName = facultyDisplayValue('department', $row, $userID);
    $program  = trim((string)($row['program'] ?? ''));
    // profile card: "CCS | BSCS" (department code | program); a dean has no program, so just "CCS"
    $deptProgram = implode(' | ', array_filter([strtoupper(trim((string)($row['department'] ?? ''))), $program]));
    $office   = $deptName;   // full college name

    return [
        'role'     => $role,
        'cfg'      => $cfg,
        'schoolId' => $userID,
        'fullName' => buildFullName($row),
        'phone'    => trim((string)($row['number'] ?? '')),
        'office'   => $office,
        'deptProgram' => $deptProgram,
        'hasPhoto' => !empty($row['has_photo']),
        'sections' => $sections,
    ];
}