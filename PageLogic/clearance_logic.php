<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "CLEARANCE";
$userID = $_SESSION["userID"];
global $clearanceDAO;

$updates_record = [];
$data = [];
$labels = [];
$values = [];

$search = "ALL";
$status = "ALL";
$department = 'ALL';
$block = 'ALL';
$year = 'ALL';
$program = 'ALL';


$allowedViews = ($_SESSION["role"] == 'student')
    ? ['MAIN', 'SUBJECTS', 'FACULTY', 'OFFICE', 'UPDATES', 'SUBJECT-REQUIREMENTS','FACULTY-REQUIREMENTS','OFFICE-REQUIREMENTS']
    : ['MAIN', 'SUBJECT', 'FACULTY', 'OFFICE'];

$student_view = strtoupper($_GET['view'] ?? 'MAIN');
if (!in_array($student_view, $allowedViews, true)) {
    $student_view = 'MAIN';
}

// var_dump($updates_record);

if($_SESSION["role"] == "student"){
    $updates_record = $clearanceDAO->executeQuery("getClearanceUpdates",[$userID,$userID,$userID]);
    $data = $clearanceDAO->executeQuery("getPieChartData",[$userID,$userID,$userID]);
    $labels = json_encode(['Cleared', 'Rejected', 'Not yet cleared']);
    $values = json_encode([
        (int) ($data[0]['cleared'] ?? 0),
        (int) ($data[0]['rejected'] ?? 0),
        (int) ($data[0]['not_yet_cleared'] ?? 0),
    ]);
}

// var_dump( isset($_GET["view"]));
// if( in_array($student_view, ["SUBJECTS","FACULTY","OFFICE"])){
    $search = trim($_GET['search'] ?? '');
    if ($search === '') { $search = 'ALL'; }  

    $status =  ($_GET['statusfilter'] ?? 'ALL');

    $department = trim($_GET['filter'] ?? '');
    if ($department === '') { $department = 'ALL'; }  

    $block = trim($_GET['blockfilter'] ?? '');
    if ($block === '') { $block = 'ALL'; }  

    $year = trim($_GET['yearfilter'] ?? '');
    if ($year === '') { $year = 'ALL'; }  

    $program = trim($_GET['programfilter'] ?? '');
    if ($program === '') { $program = 'ALL'; }  
    
    // echo $subjectID.$status;
// }elseif(in_array($student_view, ["SUBJECT-REQUIREMENTS", "FACULTY-REQUIREMENTS", "OFFICE-REQUIREMENTS"])){
//     $search = trim($_GET['search'] ?? '');
// }

function statusClass(string $status): string{
    return strtoupper($status) === 'CLEARED' ? 'status_cleared' : 'status_rejected';
}

function getSubjectsClearance($subjectID = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getSubjectsClearance",[
        $subjectID, $subjectID,
        $_SESSION["userID"], 
        $status,$status
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}
function getfacultyClearance($faculty_lastname = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getFacultyClearance",[
        $faculty_lastname, $faculty_lastname,
        $_SESSION["userID"], 
        $status,$status
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}


function getSubjectRequireMents($subjectID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getSubjectRequirements",[ $subjectID ,$_SESSION['userID'],$_SESSION['userID']]);

    return $records;
}

function getUploadedSubjectRequireMents($subjectID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getUploadedSubjectRequirements",[ $subjectID ,$_SESSION['userID'],$_SESSION['userID']]);

    return $records;
}

function getFacultyRequireMents($facultyID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getFacultyRequirements",[$facultyID, $_SESSION['userID'], $_SESSION['userID']]);

    return $records;
}

function getUploadedFacultyRequireMents($facultyID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getUploadedFacultyRequirements",[ $facultyID ,$_SESSION['userID'],$_SESSION['userID']]);

    return $records;
}

function getOfficeClearance($faculty_lastname = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getOfficeClearance",[
        $faculty_lastname, $faculty_lastname,
        $_SESSION["userID"], 
        $status,$status
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}

function getOfficeRequireMents($officeID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getOfficeRequirements",[$officeID, $_SESSION['userID'], $_SESSION['userID']]);

    return $records;
}

function getUploadedOfficeRequireMents($officeID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getUploadedOfficeRequireMents",[ $officeID ,$_SESSION['userID'],$_SESSION['userID']]);

    return $records;
}

function getOffices(){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getOffices",[ $_SESSION['userID']]);

    return $records;
}

function getSubjects($department = 'ALL', $subjectID = 'ALL',$block = 'ALL',$year = 'ALL'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("getSubjects",[
        $department,$department,
        $subjectID,$subjectID,
        $block,$block, 
        $year,$year,
        $_SESSION['userID']]);

    return $records;
}