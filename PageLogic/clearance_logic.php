<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "CLEARANCE";
$userID = $_SESSION["userID"];
global $clearanceDAO;

$updates_record = [];
$data = [];
$labels = [];
$values = [];

$currentAY = $clearanceDAO->executeQuery('getCurrentAcadYear')[0]['acad_year_sem'] ?? 'ALL';

$search = "ALL";
$status = "ALL";
$department = 'ALL';
$block = 'ALL';
$year = 'ALL';
$program = 'ALL';
$acadyear = $currentAY ;

// var_dump($currentAY );

$Fblock  = 'ALL';
$Facadyear  = $currentAY ;
$FstudentID  = 'ALL';

// $currentAY = 

$Oblock= 'ALL';
$Oacadyear= $currentAY ;
$OstudentID= 'ALL';



$allowedViews = ($_SESSION["role"] == 'student')
    ? ['MAIN', 'SUBJECTS', 'FACULTY', 'OFFICE', 'UPDATES', 'SUBJECT-REQUIREMENTS','FACULTY-REQUIREMENTS','OFFICE-REQUIREMENTS']
    : ['MAIN', 'SUBJECT', 'FACULTY', 'OFFICE'];

$student_view = strtoupper($_GET['view'] ?? 'MAIN');
if (!in_array($student_view, $allowedViews, true)) {
    $student_view = 'MAIN';
}

// var_dump($updates_record);

if($_SESSION["role"] == "student"){
    $updates_record = $clearanceDAO->executeQuery("getClearanceUpdates",[$userID,$userID,$userID,$acadyear,$acadyear,'ALL','ALL']);
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

    $acadyear = trim($_GET['acadyearfilter'] ?? '');
    if ($acadyear === '' ) { $acadyear = $currentAY; }

    $Facadyear = trim($_GET['Facadyearfilter'] ??  '');
    if ($Facadyear === '' ) { $Facadyear = $currentAY; }

    $Fblock = trim($_GET['Fblockfilter'] ?? '');
    if ($Fblock === '') { $Fblock = 'ALL'; }  


    $Oacadyear = trim($_GET['Oacadyearfilter'] ??  '');
    if ($Oacadyear === '' ) { $Oacadyear = $currentAY; }

    $Oblock = trim($_GET['Oblockfilter'] ?? '');
    if ($Oblock === '') { $Oblock = 'ALL'; }  
    
    // echo $subjectID.$status;
// }elseif(in_array($student_view, ["SUBJECT-REQUIREMENTS", "FACULTY-REQUIREMENTS", "OFFICE-REQUIREMENTS"])){
//     $search = trim($_GET['search'] ?? '');
// }    

function statusClass(string $status): string{
    $class = '';
    if(strtoupper($status) === 'CLEARED')$class = 'status_cleared';
    elseif(in_array(strtoupper($status),[ 'PENDING','NOT YET CLEARED']))$class = 'status_pending';
    elseif(strtoupper($status) === 'REJECT')$class = 'status_rejected';
    return  $class;
}

function getSubjectClearanceStatus($studentID,$categ,$acad_year){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getClearanceUpdates",[
        $studentID,
        $studentID,
        $studentID,
        $acad_year,
        $acad_year,
        $categ,
        $categ
    ]);

    return $records;
}

function getProjectsStatus($facultyID,$acad_year,$subjectID = 'ALL'){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getProjectsStatus",[
        $facultyID,
        $acad_year,
        $acad_year,
        $subjectID ,$subjectID 
    ]);

    return $records;
}

function getClearanceUpdates($acad_year='ALL',$studentID='self',$categ = 'ALL'){
    global $clearanceDAO;
    if($studentID === 'self') $studentID=$_SESSION['userID'];
    $records = $clearanceDAO->executeQuery("getClearanceUpdates",[
        $studentID,
        $studentID,
        $studentID,
        $acad_year,
        $acad_year,
        $categ,
        $categ
    ]);

    return $records;
}

function getSubjectsClearance($acad_year = 'ALL', $subjectID = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getSubjectsClearance",[
        
        $subjectID, $subjectID,
        $status,$status,
        $_SESSION["userID"],
        $acad_year,$acad_year
        

       
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}


function getfacultyClearance($acad_year = 'ALL', $faculty_lastname = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getFacultyClearance",[
        $faculty_lastname, $faculty_lastname,
        $_SESSION["userID"], 
        $acad_year,$acad_year,
        $status,$status
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}


function getSubjectRequireMents($subjectID,$acadyear, $studentID = 'self'){
    global $clearanceDAO;
    
    if($studentID == 'self') $studentID = $_SESSION['userID'];

    $records = $clearanceDAO->executeQuery("getSubjectRequirements",[ 
        $subjectID ,
        $studentID,
        $acadyear,
        $_SESSION['userID']
    ]);

    return $records;
}

function getUploadedSubjectRequireMents($acadyear, $subjectID){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getUploadedSubjectRequirements",[ 
        $subjectID ,
        $_SESSION['userID'],
        $acadyear,
        $_SESSION['userID']
    ]);

    return $records;
}

function getFacultyRequireMents($acadyear,$facultyID,  $studentID = 'self'){
    global $clearanceDAO;
    if($studentID == 'self') $studentID = $_SESSION['userID'];
    $records = $clearanceDAO->executeQuery("getFacultyRequirements",[
        $_SESSION['userID'],
        $facultyID, 
        $studentID, 
        $acadyear,$acadyear
    ]);

    return $records;
}

function getUploadedFacultyRequireMents($acadyear, $facultyID,$studentID = 'self'){
    global $clearanceDAO;
    if($studentID == 'self') $studentID = $_SESSION['userID'];
    $records = $clearanceDAO->executeQuery("getUploadedFacultyRequirements",[
        $_SESSION['userID'],
        $facultyID, 
        $studentID, 
        $acadyear,$acadyear
    ]);

    return $records;
}

function getOfficeClearance($acadyear = 'ALL',$faculty_lastname = 'ALL',$status = 'ALL'){
    global $clearanceDAO;
    
    

    $records = $clearanceDAO->executeQuery("getOfficeClearance",[
        $faculty_lastname, $faculty_lastname,
        $_SESSION["userID"], 
        $acadyear,$acadyear,
        $status,$status
    ]);

    return $records;
    // var_dump($subjectID == 'ALL');
    // var_dump($_SESSION["userID"]);
    // var_dump($records);
}

function getOfficeRequireMents($acadyear,$officeID,$studentID = 'self'){
    global $clearanceDAO;
    if($studentID === 'self') $studentID = $_SESSION['userID'];
    $records = $clearanceDAO->executeQuery("getOfficeRequirements",[
        $_SESSION['userID'], 
        $officeID, 
        $studentID,
        $acadyear,$acadyear
    ]);

    return $records;
}

function getUploadedOfficeRequireMents($acadyear,$officeID,$studentID = 'self'){
    global $clearanceDAO;
    if($studentID === 'self') $studentID = $_SESSION['userID'];
    $records = $clearanceDAO->executeQuery("getUploadedOfficeRequireMents",[
        $_SESSION['userID'], 
        $officeID, 
        $studentID,
        $acadyear,$acadyear
    ]);

    return $records;
}

function getOffices(){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getOffices",[ $_SESSION['userID']]);

    return $records;
}

function getSubjects($department = 'ALL', $subjectID = 'ALL',$block = 'ALL',$year = 'ALL',$acad_year = 'ALL'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("getSubjects",[
        $department,$department,
        $subjectID,$subjectID,
        $block,$block, 
        $year,$year,
        $acad_year,$acad_year,
        $_SESSION['userID']]);

    return $records;
}

function getStudentsForSubjectClearance($department = 'ALL', $subjectID = 'ALL',$block = 'ALL',$year = 'ALL',$acad_year = 'ALL'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("getStudentsForSubjectClearance",[
        $department,$department,
        $subjectID,$subjectID,
        $block,$block, 
        $year,$year,
        $acad_year,$acad_year,
        $_SESSION['userID']]);

    return $records;
}
function editStudentsForSubjectClearance($department = 'ALL', $subjectID = 'ALL',$block = 'ALL',$year = 'ALL',$acad_year = 'ALL',$studentID = '0'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("editStudentsForSubjectClearance",[
        $department,$department,
        $subjectID,$subjectID,
        $block,$block, 
        $year,$year,
        $acad_year,$acad_year,
        $studentID,
        $_SESSION['userID']]);

    return $records;
}

function getStudentsForFacultyClearance($block = 'ALL',$acad_year = 'ALL', $studentID = 'ALL'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("getStudentsForFacultyClearance",[
        $_SESSION['real_userID'],
        $acad_year,$acad_year,
        $block,$block, 
        $studentID,$studentID,
        $_SESSION['program'],
        $_SESSION['department'],$_SESSION['department']]);

    return $records;
}

function getFacultyUploadedRequirements($subjectID,$acadyear = ''){
    global $clearanceDAO;
    $records = $clearanceDAO->executeQuery("getFacultyUploadedRequirements",[ 
        $subjectID ,
        $acadyear,
        $_SESSION['userID']
        ]
    );

    return $records;
}

function updateClearance($request){
    global $clearanceDAO;
    $records = -1;
    if(!isset($request['clearance_categ']) || !isset($request['clearanceID'])){
        $_SESSION["error"] = "CANT SAVE RECORD";
        return;
    } 

    
    $remarks = $request['remarks'] ?? '';
    $status = $request['clearance_status_filter'] ?? '';

    if($request['clearance_categ'] === "SUBJECT"){
        if($request['clearanceID'] === ''){
           $records = $clearanceDAO->executeQuery("addSubjectClearance",[$request['enrolled_subID'],$remarks,$status]);
        }else{
           $records = $clearanceDAO->executeQuery("updateSubjectClearance",[$remarks,$status,$request['clearanceID']]);
        }
        

    }elseif($request['clearance_categ'] === "OFFICE"){
        if($request['clearanceID'] == ''){
            $records = $clearanceDAO->executeQuery("addOfficeClearance",[
                $_SESSION["real_userID"],
                $request['studentID'],
                $remarks,
                $request['enrollmentID']
            ]);
        }else{
           $records = $clearanceDAO->executeQuery("updateOfficeClearance",[
                $remarks,
                $status,
                $request['clearanceID']
           ]);

        }

    }elseif($request['clearance_categ'] === "FACULTY"){
        if($request['clearanceID'] == ''){var_dump($request['clearanceID'] == '');
            $records = $clearanceDAO->executeQuery("addFacultyClearance",[
                $_SESSION["real_userID"],
                $request['studentID'],
                $remarks,
                $request['enrollmentID']
            ]);

            
        }else{
           $records = $clearanceDAO->executeQuery("updateFacultyClearance",[
                $remarks,
                $status,
                $request['clearanceID']
            ]);

        }

    }

    ($records > 0)? $_SESSION["message"] = "RECORD SAVED"
    : $_SESSION["error"] = "CANT SAVE RECORD".($request['clearanceID'] === '');

    if($request['clearanceID'] == '') return;

    header('Location: ' .$request['page_uploader']);
    exit;
}

function getFacultyClearanceID($request){
    global $clearanceDAO;
    // WHERE `enrollmentID` = ?
    //     and `studentID` = ? 
    //     and `facultyID` = ?;"

    $records = $clearanceDAO->executeQuery("getFacultyClearanceID",[
        $request['enrollmentID'],
        $request['studentID'],
        $_SESSION['real_userID']
    ]);

    return $records[0]['faculty_clearanceID'];
}

function getOfficeClearanceID($request){
    global $clearanceDAO;
    // WHERE `enrollmentID` = ?
    //     and `studentID` = ? 
    //     and `facultyID` = ?;"

    $records = $clearanceDAO->executeQuery("getOfficeClearanceID",[
        $request['enrollmentID'],
        $request['studentID'],
        $_SESSION['real_userID']
    ]);

    return $records[0]['faculty_clearanceID'];
}



function getStudentsForOfficeClearance($block = 'ALL',$acad_year = 'ALL', $studentID = 'ALL'){
    global $clearanceDAO;

    $records = $clearanceDAO->executeQuery("getStudentsForOfficeClearance",[
        $_SESSION['officeID'],
        $acad_year,$acad_year,
        $block,$block, 
        $studentID,$studentID]);

    return $records;
}