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
      F.`facultyID` AS real_userID,
      F.`first_name` AS `first_name`,
      F.`middle_name` AS `middle_name`,
      F.`last_name` AS `last_name`,
      F.`password` AS `password`,
      F.`folderID` AS `folderID`,
      F.`role` AS `role`
  FROM
      `faculty` as F
  UNION
  SELECT
      CONCAT(
          YEAR(S.`date_created`),
          LPAD(S.`studentID`, 5, 0)
      ) AS `userID`,
      S.`studentID` AS real_userID,
      S.`first_name` AS `first_name`,
      S.`middle_name` AS `middle_name`,
      S.`last_name` AS `last_name`,
      S.`password` AS `password`,
      S.`folderID` AS `folderID`,
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
$prospectus = "SELECT
      `s`.`subjectID` AS `subjectID`,
      COALESCE(
          GROUP_CONCAT(
              DISTINCT `sp`.`prerequisite` SEPARATOR ', '
          ),
          ''
      ) AS `prerequisite`,
      `s`.`sem_available` AS `sem_available`,
      `s`.`program` AS `program`,
      `s`.`unit` AS `unit`,
      `s`.`year` AS `year`
  FROM
      `syllabus` AS `s`
  LEFT JOIN `ams_db`.`subject_prerequisite` AS `sp`
  ON
      `sp`.`subjectID` = `s`.`subjectID`
  GROUP BY
      `s`.`subjectID`,
      `s`.`sem_available`,
      `s`.`program`,
      `s`.`year`,
      `s`.`unit`
  ORDER BY
      `s`.`year`
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

$userDAO->prepareStatement("saveStudentFolderID","UPDATE 
    students
    SET folderID = ?
    where concat(year(students.date_created),lpad(students.studentID,5,0)) = ?;
");
$userDAO->prepareStatement("saveFacultyFolderID","UPDATE 
    faculty
    SET folderID = ?
    where concat('F',year(faculty.date_created),lpad(faculty.facultyID,5,0)) = ?;
");


// CLEARANCE 


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

$clearanceDAO->prepareStatement("getSubjectsClearance","SELECT 
        DISTINCT(S.subjectID) AS subjectID,
        S.name AS name,
        SC.date_cleared AS date_cleared,
        SC.remarks AS remarks,
        SC.status AS status,
        SS.roomID AS room,
        CONCAT(F.last_name,', ', F.first_name ) AS prof_name
    FROM subject_clearance AS SC 
        INNER JOIN enrolled_subjects AS  ES 
        ON ES.enrolled_subjectID = SC.enrolled_subjectID
        INNER JOIN students AS  ST 
        ON ST.studentID = ES.studentID
        INNER JOIN subjects AS  S
        ON S.subjectID = ES.subjectID
        LEFT JOIN subject_schedules AS  SS 
        ON SS.enrollmentID = ES.enrollmentID
        AND SS.subjectID = ES.subjectID
        LEFT JOIN faculty AS  F
        ON F.facultyID = SS.facultyID
    WHERE EXISTS (
            SELECT 1
            FROM subject_clearance_requirements AS SCR
            WHERE SCR.subjectID = S.subjectID
        )
        AND ('ALL' = ? OR S.subjectID = ?)
        AND concat(year(ST.date_created),lpad(ES.studentID,5,0)) = ?
        AND ('ALL' = ? OR SC.status = ?);
"
);

$clearanceDAO->prepareStatement("getSubjectRequirements","SELECT
        S.subjectID AS subjectID,
        S.name AS name,
        SC.date_cleared AS date_cleared,
        SC.remarks AS remarks,
        SC.status AS status,
        CONCAT(SS.time_start,' - ', SS.time_end) as class_time,
        SS.day AS day,
        SS.roomID AS room,
        CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
        SCR.ID,
        SCR.fileID,
        SCR.file_name,
        SCR.mime_type,
        IF(
            COALESCE(SCR.file_size, 0) < 1000000,
            CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1024, 1), ' KB'),
            CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1048576, 2), ' MB')
        ) AS file_size,
        SCR.uploaded_at
    FROM
        subject_clearance AS SC
    INNER JOIN enrolled_subjects AS ES
    ON
        ES.enrolled_subjectID = SC.enrolled_subjectID
    INNER JOIN students AS ST
    ON
        ST.studentID = ES.studentID
    INNER JOIN subjects AS S
    ON
        S.subjectID = ES.subjectID
    LEFT JOIN subject_schedules AS SS
    ON
        SS.enrollmentID = ES.enrollmentID AND SS.subjectID = ES.subjectID
    LEFT JOIN faculty AS F
    ON  F.facultyID = SS.facultyID
    left JOIN subject_clearance_requirements AS SCR
    ON 	SCR.subjectID = ES.subjectID
        
    WHERE
        S.subjectID = ? and concat(year(ST.date_created),lpad(ES.studentID,5,0)) = ?
        ANd not SCR.uploaded_by = ?;

");
$clearanceDAO->prepareStatement("getUploadedSubjectRequirements","SELECT
        S.subjectID AS subjectID,
        S.name AS name,
        SC.date_cleared AS date_cleared,
        SC.remarks AS remarks,
        SC.status AS status,
        CONCAT(SS.time_start,' - ', SS.time_end) as class_time,
        SS.day AS day,
        SS.roomID AS room,
        CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
        SCR.ID,
        SCR.fileID,
        SCR.file_name,
        SCR.mime_type,
        IF(
            COALESCE(SCR.file_size, 0) < 1000000,
            CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1024, 1), ' KB'),
            CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1048576, 2), ' MB')
        ) AS file_size,
        SCR.uploaded_at
    FROM
        subject_clearance AS SC
    INNER JOIN enrolled_subjects AS ES
    ON
        ES.enrolled_subjectID = SC.enrolled_subjectID
    INNER JOIN students AS ST
    ON
        ST.studentID = ES.studentID
    INNER JOIN subjects AS S
    ON
        S.subjectID = ES.subjectID
    LEFT JOIN subject_schedules AS SS
    ON
        SS.enrollmentID = ES.enrollmentID AND SS.subjectID = ES.subjectID
    LEFT JOIN faculty AS F
    ON  F.facultyID = SS.facultyID
    left JOIN subject_clearance_requirements AS SCR
    ON 	SCR.subjectID = ES.subjectID
        
    WHERE
        S.subjectID = ? and concat(year(ST.date_created),lpad(ES.studentID,5,0)) = ?
        ANd SCR.uploaded_by = ?;

");

$clearanceDAO->prepareStatement("addSubjectRequirement","INSERT INTO 
    `subject_clearance_requirements` ( `subjectID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`) 
    VALUES (?, ?,?,?, ?,?)"
);


$clearanceDAO->prepareStatement("getFacultyClearance","SELECT DISTINCT
        F.role,
        FC.facultyID AS facultyID,
        FC.date_cleared AS date_cleared,
        FC.remarks AS remarks,
        FC.status AS status,
        CONCAT(F.last_name, ', ', F.first_name) AS prof_name
    FROM faculty_clearance AS FC
        INNER JOIN students AS ST
            ON ST.studentID = FC.studentID
        LEFT JOIN faculty AS F
            ON F.facultyID = FC.facultyID
    WHERE EXISTS (
            SELECT 1
            FROM faculty_clearance_requirements AS FCR
            WHERE FCR.faculty_clearanceID = FC.faculty_clearanceID
        )
        AND ('ALL' = ? OR F.last_name = ?)
        AND CONCAT(YEAR(ST.date_created), LPAD(FC.studentID, 5, 0)) = ?
        AND ('ALL' = ? OR FC.status = ?);
"
);
$clearanceDAO->prepareStatement("addFacultyRequirement","INSERT INTO 
    `faculty_clearance_requirements` ( `faculty_clearanceID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`) 
    VALUES (?, ?,?,?, ?,?)"
);
$clearanceDAO->prepareStatement("getFacultyRequirements","SELECT
        FC.faculty_clearanceID ,
        FC.date_cleared AS date_cleared,
        FC.remarks AS remarks,
        FC.status AS status,
        CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
        F.role,
        FCR.ID,
        FCR.fileID,
        FCR.file_name,
        FCR.mime_type,
        IF(
            COALESCE(FCR.file_size, 0) < 1000000,
            CONCAT(ROUND(COALESCE(FCR.file_size, 0) / 1024, 1), ' KB'),
            CONCAT(ROUND(COALESCE(FCR.file_size, 0) / 1048576, 2), ' MB')
        ) AS file_size,
        FCR.uploaded_at
    FROM
        faculty_clearance AS FC
    INNER JOIN students AS ST
    ON ST.studentID = FC.studentID
    inner JOIN faculty AS F
    ON  F.facultyID = FC.facultyID
    inner JOIN faculty_clearance_requirements AS FCR
    ON 	FCR.faculty_clearanceID = FC.faculty_clearanceID

    WHERE
        FC.facultyID = ? and concat(year(ST.date_created),lpad(ST.studentID,5,0)) = ?
        ANd not FCR.uploaded_by = ?;

"); 



$clearanceDAO->prepareStatement("addOfficeRequirement","INSERT INTO 
    `office_clearance_requirements` ( `office_clearanceID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`) 
    VALUES (?, ?,?,?, ?,?)"
);





$clearanceDAO->prepareStatement("findClearanceDocument","SELECT
        CF.ID,
        CF.fileID,
        CF.file_name,
        CF.mime_type,
        CF.file_size,
        CF.studentID
    FROM
        (
            SELECT
                CONCAT('S', SCR.ID) AS ID,
                SCR.fileID,
                SCR.file_name,
                SCR.mime_type,
                SCR.file_size,
                ES.studentID
            FROM
                subject_clearance_requirements AS SCR
            INNER JOIN enrolled_subjects AS ES
            ON
                ES.subjectID = SCR.subjectID
            UNION
            SELECT
                CONCAT('F', FCR.ID) AS ID,
                FCR.fileID,
                FCR.file_name,
                FCR.mime_type,
                FCR.file_size,
                FC.studentID
            FROM
                faculty_clearance_requirements AS FCR
            INNER JOIN faculty_clearance AS FC
            ON
                FC.faculty_clearanceID = FCR.faculty_clearanceID
            UNION
            SELECT
                CONCAT('O', OCR.ID) AS ID,
                OCR.fileID,
                OCR.file_name,
                OCR.mime_type,
                OCR.file_size,
                OC.studentID
            FROM
                office_clearance_requirements AS OCR
            INNER JOIN office_clearance AS OC
            ON
                OC.office_clearanceID = OCR.office_clearanceID
        ) AS CF
    INNER JOIN students AS ST
    ON
        CF.studentID = ST.studentID
    WHERE
        CF.ID = ? AND CONCAT( YEAR(ST.date_created), LPAD(CF.studentID, 5, 0)) = ?;
    "
);


