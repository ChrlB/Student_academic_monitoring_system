<?php
require_once __DIR__.'/PDAO.php';

//$db_connection = new PDO("mysql:host=localhost;port=3306;dbname=business_inventory_db","root","John_Chrl@2006;");
$db_connection = new PDO("mysql:host=localhost;port=3306;dbname=business_inventory_db","root","");
  

$studentDAO = new \Chrlb\PhpDao\PHPDAO($db_connection);
$gradesDAO = new \Chrlb\PhpDao\PHPDAO($db_connection);

$studentDAO->prepareStatement("getAllStudents",
  "SELECT * FROM tbl_users;",
  "get all users records"
);