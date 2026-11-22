<?php 
require_once __DIR__.'/../Services/auth.php';

$_SESSION["page"] = "DASHBOARD";



function getAllProjectStatus($acad_year = 'now',$userID = 'self' ){
  global $clearanceDAO;
  
  if($acad_year == 'now') $acad_year = $clearanceDAO->executeQuery('getCurrentAcadYear')[0]['acad_year_sem'] ?? 'ALL';
  $result = $clearanceDAO->executeQuery('getAllProjectStatus',[
    $acad_year,
    $_SESSION['userID'],$_SESSION['userID']
  ]);

  return $result;

}

function getClearanceSummary($acad_year_sem = 'ALL') {
    global $clearanceDAO;
    $id = $_SESSION['userID'];   // formatted ID like 202600001
    $rows = $clearanceDAO->executeQuery("getPieChartData",
        [$id, $id, $id, $acad_year_sem, $acad_year_sem]);
    return $rows[0] ?? ['cleared' => 0, 'rejected' => 0, 'not_yet_cleared' => 0];
}

function getRecentClearanceUpdates($acad_year_sem = 'ALL', $category = 'ALL') {
    global $clearanceDAO;
    $id = $_SESSION['userID'];
    return $clearanceDAO->executeQuery("getClearanceUpdates",
        [$id, $id, $id, $acad_year_sem, $acad_year_sem, $category, $category]);
}

function getStudentSchedule($acad_year_sem) {
    global $clearanceDAO;
    return $clearanceDAO->executeQuery("getStudentSchedule",
        [$_SESSION['userID'], $acad_year_sem, $acad_year_sem]);
}

function getOpenEnrollments() {
    global $clearanceDAO;
    return $clearanceDAO->executeQuery("getOpenEnrollments", [$_SESSION['real_userID']]);
}

function getFacultySchedule($term) {
    global $clearanceDAO;
    $rows = $clearanceDAO->executeQuery("getSubjects", [
        'ALL','ALL', 'ALL','ALL', 'ALL','ALL', 'ALL','ALL',
        $term, $term,
        $_SESSION['userID']          // formatted faculty code, F2026...
    ]);
    // getSubjects repeats a schedule once per syllabus row, so dedupe by schedID
    $seen = [];
    foreach ($rows as $r) { $seen[$r['schedID']] = $r; }
    return array_values($seen);
}

function countWaiting($rows) {
    return count(array_filter($rows, fn($r) =>
        in_array(strtolower($r['status']), ['not yet cleared', 'pending'], true)));
}

function getFacultyClearanceCounts($term) {
    global $clearanceDAO;
    $fid   = $_SESSION['real_userID'];
    $dept  = $_SESSION['department'] ?? 'ALL';   // check the key your login stores
    $prog  = $_SESSION['program'] ?? 'ALL';

    $faculty = $clearanceDAO->executeQuery("getStudentsForFacultyClearance",
        [$fid, $term, $term, 'ALL','ALL', 'ALL','ALL', $prog, $dept, $dept]);

    $subjects = $clearanceDAO->executeQuery("getStudentsForSubjectClearance", [
        'ALL','ALL', 'ALL','ALL', 'ALL','ALL', 'ALL','ALL',
        $term, $term, $_SESSION['userID']]);

    $office = [];
    if (!empty($_SESSION['officeID'])) {
        $office = $clearanceDAO->executeQuery("getStudentsForOfficeClearance",
            [$_SESSION['officeID'], $term, $term, 'ALL','ALL', 'ALL','ALL']);
    }

    return [
        'faculty'  => countWaiting($faculty),
        'subjects' => countWaiting($subjects),
        'office'   => countWaiting($office),
        'has_office' => !empty($_SESSION['officeID']),
    ];
}

function getFacultyProjectCounts($term) {
    global $clearanceDAO;
    $rows = $clearanceDAO->executeQuery("getProjectsStatus",
        [$_SESSION['real_userID'], $term, $term, 'ALL', 'ALL']);
    $c = ['submitted' => 0, 'waiting' => 0, 'overdue' => 0];
    foreach ($rows as $r) {
        $k = strtolower($r['submission_status']);
        if (isset($c[$k])) $c[$k]++;
    }
    return $c;
}

function getStudentEvents() {
    global $clearanceDAO;
    return $clearanceDAO->executeQuery("getStudentEvents", [$_SESSION['real_userID']]);
}

function getFacultyEvents() {
    global $clearanceDAO;
    return $clearanceDAO->executeQuery("getFacultyEvents", [$_SESSION['real_userID']]);
}