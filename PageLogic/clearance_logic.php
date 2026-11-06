<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "CLEARANCE";
$userID = $_SESSION["userID"];
global $clearanceDAO;

$updates_record = [];
$data = [];
$labels = [];
$values = [];

$allowedViews = ['MAIN', 'SUBJECTS', 'FACULTY', 'OFFICE'];
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

var_dump( isset($_GET["view"]));

function statusClass(string $status): string{
    return strtoupper($status) === 'CLEARED' ? 'status_cleared' : 'status_rejected';
}