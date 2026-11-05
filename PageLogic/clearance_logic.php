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
