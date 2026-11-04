<?php
require __DIR__.'/../vendor/autoload.php';

use Chrlb\PhpDao\PHPDAO;

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  // This line forces the connection collation to match your tables perfectly
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

$db_connection = new PDO("mysql:host=localhost;port=3306;dbname=ams_db", "root", "", $options);


$userDAO = new PHPDAO($db_connection);
$studentDAO = new PHPDAO($db_connection);

$users = "SELECT
      CONCAT(
          'F',
          YEAR(F.`date_created`),
          LPAD(F.`facultyID`, 5, 0)
      ) AS `userID`,
      F.`first_name` AS `first_name`,
      F.`middle_name` AS `middle_name`,
      F.`last_name` AS `last_name`,
      F.`password` AS `password`,
      F.`role` AS `role`
  FROM
      `faculty` as F
  UNION
  SELECT
      CONCAT(
          YEAR(S.`date_created`),
          LPAD(S.`studentID`, 5, 0)
      ) AS `userID`,
      S.`first_name` AS `first_name`,
      S.`middle_name` AS `middle_name`,
      S.`last_name` AS `last_name`,
      S.`password` AS `password`,
      'student' AS `role`
  FROM
      `students` as S";

$userDAO->prepareStatement("getUsers",  $users);

$userDAO->prepareStatement("getUserByID",  "SELECT *  FROM ($users) AS users WHERE users.userID = ?;" );

$userDAO->prepareStatement( "uploadStudentProfile",
  "UPDATE students
    SET image_data       = ?,
        mime_type        = ?
  WHERE concat(year(date_created),lpad(studentID,5,0)) = ?"
);
$userDAO->prepareStatement("uploadFacultyProfile",
  "UPDATE faculty
    SET image_data       = ?,
        mime_type        = ?
  WHERE concat('F',year(date_created),lpad(facultyID,5,0)) = ?"
);
$userDAO->prepareStatement( "getStudentProfile",
  "SELECT image_data, mime_type
                          FROM students
  WHERE concat(year(date_created),lpad(studentID,5,0)) = ?;"
);
$userDAO->prepareStatement( "getFacultyProfile",
  "SELECT image_data, mime_type
                          FROM faculty
  WHERE concat('F',year(date_created),lpad(facultyID,5,0)) = ?"
);
$userDAO->prepareStatement( "getStudentInfo",
  "SELECT 
      concat(year(date_created),lpad(studentID,5,0)) as ID ,
      first_name,
      middle_name,
      last_name,
      concat(concat(year(date_created),lpad(studentID,5,0)),'@gordoncollege.edu.ph') as email,
      block,
      year_level,
      program,
      date_created,
      address,
      number,
      date_of_birth,
      gender,
      status
      FROM students
      WHERE  concat(year(date_created),lpad(studentID,5,0)) = ?"
);
$userDAO->prepareStatement( "getFacultyInfo",
  "SELECT 
      concat('F',year(date_created),lpad(facultyID,5,0)) as ID ,
      first_name,
      middle_name,
      last_name,
      concat( concat('F',year(date_created),lpad(facultyID,5,0)),'@gordoncollege.edu.ph') as email,
      role,
      department,
      date_created,
      address,
      number,
      date_of_birth,
      gender,
      status
  FROM faculty
  WHERE  concat('F',year(date_created),lpad(facultyID,5,0)) = ?"
);



