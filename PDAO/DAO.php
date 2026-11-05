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
$clearanceDAO = new PHPDAO($db_connection);

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

$cleranceUpdate = "SELECT
        'faculty' AS category,
        f.role AS item_name,
        CONCAT(f.last_name, ', ', f.first_name) AS name,
        fc.studentID AS studentID,
        DATE(fc.date_cleared) AS date_cleared,
        fc.remarks AS remarks,
        fc.status AS status
    FROM faculty_clearance AS fc
    JOIN faculty AS f ON f.facultyID = fc.facultyID
    JOIN students AS S ON S.studentID = fc.studentID
    WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?

    UNION ALL

    SELECT
        'office',
        o.name,
        CONCAT(f.last_name, ', ', f.first_name),
        oc.studentID,
        DATE(oc.date_cleared),
        oc.remarks,
        oc.status
    FROM office_clearance AS oc
    JOIN office AS o ON o.officeID = oc.officeID
    JOIN faculty AS f ON f.facultyID = o.facultyID
    JOIN students AS S ON S.studentID = oc.studentID
    WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?

    UNION ALL

    SELECT
        'subjects',
        su.name,
        es.subjectID,
        es.studentID,
        DATE(sc.date_cleared),
        sc.remarks,
        sc.status
    FROM subject_clearance AS sc
    JOIN enrolled_subjects AS es ON es.enrolled_subjectID = sc.enrolled_subjectID
    JOIN subjects AS su ON su.subjectID = es.subjectID
    JOIN students AS S ON S.studentID = es.studentID
    WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?
";


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

$clearanceDAO->prepareStatement("getClearanceUpdates",
 " SELECT category, item_name, name, studentID, date_cleared, remarks, status
  FROM ($cleranceUpdate) AS updates
  WHERE upper(status) in ('CLEARED','REJECTED')
  ORDER BY date_cleared DESC LIMIT 50
  "
);

$clearanceDAO->prepareStatement("getPieChartData",
 " SELECT
    COALESCE(SUM(UPPER(status) = 'CLEARED'), 0)  AS cleared,
    COALESCE(SUM(UPPER(status) = 'REJECTED'), 0) AS rejected,
    COALESCE(SUM(UPPER(status) in ( 'PENDING','NOT YET CLEARED')), 0) AS not_yet_cleared
    FROM ($cleranceUpdate) AS updates
  "
);

