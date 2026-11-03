<?php
require __DIR__.'/../vendor/autoload.php';

use Chrlb\PhpDao\PHPDAO;

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  // This line forces the connection collation to match your tables perfectly
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

$db_connection = new PDO("mysql:host=localhost;port=3306;dbname=ams_db", "root", "John_Chrl@2006;", $options);


$userDAO = new PHPDAO($db_connection);
$studentDAO = new PHPDAO($db_connection);

$userDAO->prepareStatement("getUsers",  
  "SELECT
      CONCAT(
          'F',
          YEAR(`ams_db`.`faculty`.`date_created`),
          CONVERT(
              LPAD(`ams_db`.`faculty`.`facultyID`, 5, 0) USING utf8mb4
          )
      ) AS `userID`,
      `ams_db`.`faculty`.`first_name` AS `first_name`,
      `ams_db`.`faculty`.`middle_name` AS `middle_name`,
      `ams_db`.`faculty`.`last_name` AS `last_name`,
      `ams_db`.`faculty`.`password` AS `password`,
      `ams_db`.`faculty`.`role` AS `role`
  FROM
      `ams_db`.`faculty`
  UNION
  SELECT
      CONCAT(
          YEAR(`ams_db`.`students`.`date_created`),
          LPAD(`ams_db`.`students`.`studentID`, 5, 0)
      ) AS `userID`,
      `ams_db`.`students`.`first_name` AS `first_name`,
      `ams_db`.`students`.`middle_name` AS `middle_name`,
      `ams_db`.`students`.`last_name` AS `last_name`,
      `ams_db`.`students`.`password` AS `password`,
      'student' AS `role`
  FROM
      `ams_db`.`students`"
);

$userDAO->prepareStatement("getUserByID",  
  "SELECT * 
   FROM (SELECT
      CONCAT(
          'F',
          YEAR(`ams_db`.`faculty`.`date_created`),
          CONVERT(
              LPAD(`ams_db`.`faculty`.`facultyID`, 5, 0) USING utf8mb4
          )
      ) AS `userID`,
      `ams_db`.`faculty`.`first_name` AS `first_name`,
      `ams_db`.`faculty`.`middle_name` AS `middle_name`,
      `ams_db`.`faculty`.`last_name` AS `last_name`,
      `ams_db`.`faculty`.`password` AS `password`,
      `ams_db`.`faculty`.`role` AS `role`
  FROM
      `ams_db`.`faculty`
  UNION
  SELECT
      CONCAT(
          YEAR(`ams_db`.`students`.`date_created`),
          LPAD(`ams_db`.`students`.`studentID`, 5, 0)
      ) AS `userID`,
      `ams_db`.`students`.`first_name` AS `first_name`,
      `ams_db`.`students`.`middle_name` AS `middle_name`,
      `ams_db`.`students`.`last_name` AS `last_name`,
      `ams_db`.`students`.`password` AS `password`,
      'student' AS `role`
  FROM
      `ams_db`.`students`) AS users
  WHERE users.userID = ?;"
  );

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
  WHERE concat(year(date_created),lpad(studentID,5,0)) = ?"
);
$userDAO->prepareStatement( "getFacultyProfile",
  "SELECT image_data, mime_type
                          FROM faculty
  WHERE concat('F',year(date_created),lpad(facultyID,5,0)) = ?"
);
$userDAO->prepareStatement(  "getStudentInfo",
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
$userDAO->prepareStatement(  "getFacultyInfo",
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



