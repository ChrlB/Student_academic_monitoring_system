<?php
require_once __DIR__.'/PDAO.php';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // This line forces the connection collation to match your tables perfectly
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

$db_connection = new PDO("mysql:host=localhost;port=3306;dbname=ams_db","root","",$options);


$userDAO = new \Chrlb\PhpDao\PHPDAO($db_connection);

$userDAO->prepareStatement("getUsers", 
  "SELECT * FROM users;"
);   

$userDAO->prepareStatement("getUserByID", 
  "SELECT * FROM users WHERE userID = ?;"
);   


// $studentDAO = new \Chrlb\PhpDao\PHPDAO($db_connection);
// $gradesDAO = new \Chrlb\PhpDao\PHPDAO($db_connection);

// $studentDAO->prepareStatement("getAllStudents",
//   "SELECT * FROM tbl_users;",
//   "get all users records"
// );