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


$allowedViews = ['MAIN', 'SUBJECTS', 'FACULTY', 'OFFICE','SUBJECT-REQUIREMENTS','FACULTY-REQUIREMENTS'];
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
if( in_array($student_view, ["SUBJECTS","FACULTY"])){
    $search = trim($_GET['search'] ?? '');
    if ($search === '') { $search = 'ALL'; }  
    $status =  ($_GET['statusfilter'] ?? 'ALL');
    // echo $subjectID.$status;
}elseif(in_array($student_view, ["SUBJECT-REQUIREMENTS", "FACULTY-REQUIREMENTS"])){
    $search = trim($_GET['search'] ?? '');
}

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
    $records = $clearanceDAO->executeQuery("getSubjectRequirements",[ $subjectID ]);

    return $records;
}
function getFacultyRequireMents($subjectID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getFacultyRequirements",[ $subjectID ]);

    return $records;
}
// function getSubjectRequireMents($subjectID){
//     global $clearanceDAO;
//     $records = $clearanceDAO->executeQuery("getSubjectRequirements",[ $subjectID ]);

//     return $records;
// }
