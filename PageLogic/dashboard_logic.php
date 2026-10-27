<?php 
require_once __DIR__.'/../Services/auth.php';

// if($_SESSION["role"] == "student"){
//   header('Location: /student/dashboard');
//   exit;
// };

// // global $studentDAO;
// global $userDAO;

// // $records = $userDAO->executeQuery("getUsers");
// $records = $userDAO->executeQuery("getUserByID",[$_SESSION["userID"]]);

// foreach($records as $student){
//   echo "<br>";
//   echo "ID: ".$student["userID"]."<br>";
//   echo "Lastname: ".$student["last_name"]."<br>";
//   echo "password: ".$student["password"]."<br>";
// }


