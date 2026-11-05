<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "CLEARANCE";
$userID = $_SESSION["userID"];
global $clearanceDAO;
$updates_record = $clearanceDAO->executeQuery("getClearanceUpdates",[$userID,$userID,$userID]);

// var_dump($updates_record);

// Maps a status to its CSS class
function statusClass(string $status): string{
    return strtoupper($status) === 'CLEARED' ? 'status_cleared' : 'status_rejected';
}


$data = $clearanceDAO->executeQuery("getPieChartData",[$userID,$userID,$userID]);
// var_dump($data);
$labels = json_encode(['Cleared', 'Rejected', 'Not yet cleared']);
$values = json_encode([
    (int) ($data[0]['cleared'] ?? 0),
    (int) ($data[0]['rejected'] ?? 0),
    (int) ($data[0]['not_yet_cleared'] ?? 0),
]);