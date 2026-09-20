<?php 
require __DIR__ . '/../PDAO/PDAO.php';

$records = $studentDAO->executeQuery("getAllStudents");

var_dump($records);


foreach($records as $student){
  echo "<br>";
  echo $student["userID"]."<br>";
  echo $student["username"]."<br>";
  echo $student["password"]."<br>";
  echo $student["fullname"]."<br>"."<br>";
}
