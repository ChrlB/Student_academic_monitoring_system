<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "ACCOUNT";



function getUserInfo(){
  global $userDAO;

  $user_info_for = ($_SESSION['role'] == "student")
                ? "getStudentInfo"
                : "getFacultyInfo";

  
  $records = $userDAO->executeQuery($user_info_for,[$_SESSION["userID"]]);
  // var_dump($records);
  $user_info = $records[0];

  foreach($user_info as $title=>$info) echo $title.": ".$info."<br>";

}