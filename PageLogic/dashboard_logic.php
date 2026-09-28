<?php 
require_once __DIR__.'/../Services/auth.php';

global $studentDAO;

$records = $studentDAO->executeQuery("getAllStudents");

// var_dump($records);


// foreach($records as $student){
//   echo "<br>";
//   echo $student["userID"]."<br>";
//   echo $student["username"]."<br>";
//   echo $student["password"]."<br>";
//   echo $student["fullname"]."<br>"."<br>";
// }


