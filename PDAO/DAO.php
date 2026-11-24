<?php
require __DIR__.'/../vendor/autoload.php';

use Chrlb\PhpDao\PHPDAO;

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  // This line forces the connection collation to match your tables perfectly
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
  PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
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
      F.`program` AS `program`,
      F.`department` AS `department`,
      F.`password` AS `password`,
      F.`folderID` AS `folderID`,
      O.`officeID` AS `officeID`,
      F.`role` AS `role`,
      F.`status` AS `status`
  FROM
      `faculty` as F
      left join programs as P
        ON P.program = F.program
        left join office as O
        ON O.facultyID = F.facultyID
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
      S.`program` AS `program`,
      P.`department` AS `department`,
      S.`password` AS `password`,
      S.`folderID` AS `folderID`,
      null AS `officeID`,
      'student' AS `role`,
      S.`status` AS `status`
  FROM
      `students` as S
    inner join programs as P
    ON P.program = S.program
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
        COALESCE(NULLIF(email,''), concat(concat(year(date_created),lpad(studentID,5,0)),'@gordoncollege.edu.ph')) as email,
        block,
        year_level,
        program,
        date_created,
        address,
        number,
        date_of_birth,
        gender,
        status,
        place_of_birth,
        civil_status,
        sexual_orientation,
        citizenship,
        religion,
        region,
        province,
        municipality,
        zip_code
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

/* ---- faculty account page (dean / program coordinator / professor): faculty table columns only ---- */
$userDAO->prepareStatement("getFacultyAccount",
  "SELECT F.facultyID, F.first_name, F.middle_name, F.last_name, F.role, F.department, F.program,
          F.address, F.number, F.date_of_birth, F.gender, F.status, F.date_created,
          (F.image_data IS NOT NULL AND LENGTH(F.image_data) > 0) AS has_photo
   FROM faculty AS F
   WHERE F.facultyID = ? AND YEAR(F.date_created) = ?;"
);
$userDAO->prepareStatement("updateFacultyBasic",
  "UPDATE faculty SET middle_name = ?, date_of_birth = ?, gender = ?, address = ?, number = ? WHERE facultyID = ? AND YEAR(date_created) = ?;");
$userDAO->prepareStatement("updateFacultyNumber",
  "UPDATE faculty SET number = ? WHERE facultyID = ? AND YEAR(date_created) = ?;");
$userDAO->prepareStatement("updateFacultyPassword",
  "UPDATE faculty SET password = ? WHERE facultyID = ? AND YEAR(date_created) = ?;");

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





$userDAO->prepareStatement("studentRowExists",
    "SELECT studentID FROM students WHERE studentID = ? AND YEAR(date_created) = ?;");
$userDAO->prepareStatement("updateStudentProfile",
    "UPDATE students SET middle_name = ?, number = ?, date_of_birth = ?, gender = ?, address = ? WHERE studentID = ? AND YEAR(date_created) = ?;");
$userDAO->prepareStatement("updateStudentPassword",
    "UPDATE students SET password = ? WHERE studentID = ? AND YEAR(date_created) = ?;");



$userDAO->prepareStatement("updateStudentProfileFull",
        "UPDATE students SET middle_name = ?, email = ?, number = ?, date_of_birth = ?, gender = ?, address = ?, place_of_birth = ?, civil_status = ?, sexual_orientation = ?, citizenship = ?, religion = ?, region = ?, province = ?, municipality = ?, zip_code = ? WHERE studentID = ? AND YEAR(date_created) = ?;");





// CLEARANCE 
{
    // $cleranceUpdate = "SELECT
    //         'faculty' AS category,
    //         f.role AS item_name,
    //         CONCAT(f.last_name, ', ', f.first_name) AS name,
    //         fc.studentID AS studentID,
    //         DATE(fc.date_cleared) AS date_cleared,
    //         fc.remarks AS remarks,
    //         fc.status AS status
    //     FROM faculty_clearance AS fc
    //     JOIN faculty AS f ON f.facultyID = fc.facultyID
    //     JOIN students AS S ON S.studentID = fc.studentID
    //     WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?

    //     UNION ALL

    //     SELECT
    //         'office',
    //         o.name,
    //         CONCAT(f.last_name, ', ', f.first_name),
    //         oc.studentID,
    //         DATE(oc.date_cleared),
    //         oc.remarks,
    //         oc.status
    //     FROM office_clearance AS oc
    //     JOIN office AS o ON o.officeID = oc.officeID
    //     JOIN faculty AS f ON f.facultyID = o.facultyID
    //     JOIN students AS S ON S.studentID = oc.studentID
    //     WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?

    //     UNION ALL

    //     SELECT
    //         'subjects',
    //         su.name,
    //         es.subjectID,
    //         es.studentID,
    //         DATE(sc.date_cleared),
    //         sc.remarks,
    //         sc.status
    //     FROM subject_clearance AS sc
    //     JOIN enrolled_subjects AS es ON es.enrolled_subjectID = sc.enrolled_subjectID
    //     JOIN subjects AS su ON su.subjectID = es.subjectID
    //     JOIN students AS S ON S.studentID = es.studentID
    //     WHERE concat(year(S.date_created),lpad(S.studentID,5,0)) = ?
    // ";


    // $clearanceDAO->prepareStatement("getClearanceUpdates",
    // " SELECT category, item_name, name, studentID, date_cleared, remarks, status
    //     FROM ($cleranceUpdate) AS updates
        
    //     ORDER BY date_cleared DESC LIMIT 50;
    // "
    // );

    $cleranceUpdate = "SELECT
            'faculty' AS category,
            f.role AS item_name,
            CONCAT(f.last_name, ', ', f.first_name) AS name,
            fc.studentID AS studentID,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            DATE(fc.date_cleared) AS date_cleared,
            fc.remarks AS remarks,
            fc.status AS status
        FROM faculty_clearance AS fc
            JOIN faculty AS f ON f.facultyID = fc.facultyID
            JOIN students AS S ON S.studentID = fc.studentID
            JOIN enrollment_period AS EP ON EP.enrollmentID = fc.enrollmentID
        WHERE CONCAT(YEAR(S.date_created), LPAD(S.studentID, 5, '0')) = ?

        UNION ALL

        SELECT
            'office',
            o.name,
            CONCAT(f.last_name, ', ', f.first_name),
            oc.studentID,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem'),
            DATE(oc.date_cleared),
            oc.remarks,
            oc.status
        FROM office_clearance AS oc
            JOIN office AS o ON o.officeID = oc.officeID
            JOIN faculty AS f ON f.facultyID = o.facultyID
            JOIN students AS S ON S.studentID = oc.studentID
            JOIN enrollment_period AS EP ON EP.enrollmentID = oc.enrollmentID
        WHERE CONCAT(YEAR(S.date_created), LPAD(S.studentID, 5, '0')) = ?

        UNION ALL

        SELECT
            'subjects',
            su.name,
            es.subjectID,
            es.studentID,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem'),
            DATE(sc.date_cleared),
            sc.remarks,
            sc.status
        FROM subject_clearance AS sc
            JOIN enrolled_subjects AS es ON es.enrolled_subjectID = sc.enrolled_subjectID
            JOIN subjects AS su ON su.subjectID = es.subjectID
            JOIN students AS S ON S.studentID = es.studentID
            JOIN enrollment_period AS EP ON EP.enrollmentID = es.enrollmentID
        WHERE CONCAT(YEAR(S.date_created), LPAD(S.studentID, 5, '0')) = ?
    ";

    $clearanceDAO->prepareStatement("getClearanceUpdates",
        "SELECT category, item_name, name, studentID, acad_year_sem, date_cleared, remarks, status
        FROM ($cleranceUpdate) AS updates
        WHERE ('ALL' = ? OR acad_year_sem = ?)
        AND ('ALL' = ? OR category = ?)
        ORDER BY acad_year_sem DESC, date_cleared DESC
        LIMIT 50;"
    );
        
    $clearanceDAO->prepareStatement("getPieChartData",
    " SELECT
        COALESCE(SUM(UPPER(status) = 'CLEARED'), 0)  AS cleared,
        COALESCE(SUM(UPPER(status) = 'REJECT'), 0) AS rejected,
        COALESCE(SUM(UPPER(status) in ( 'PENDING','NOT YET CLEARED')), 0) AS not_yet_cleared
        FROM ($cleranceUpdate) AS updates
        WHERE ('ALL' = ? OR acad_year_sem = ?);
    "
    );

    // $clearanceDAO->prepareStatement("getSubjectsClearanc","SELECT 
    //         DISTINCT(S.subjectID) AS subjectID,
    //         S.name AS name,
    //         SC.date_cleared AS date_cleared,
    //         SC.remarks AS remarks,
    //         SC.status AS status,
    //         SS.roomID AS room,
    //         CONCAT(F.last_name,', ', F.first_name ) AS prof_name
    //     FROM subject_clearance AS SC 
    //         INNER JOIN enrolled_subjects AS  ES 
    //         ON ES.enrolled_subjectID = SC.enrolled_subjectID
    //         INNER JOIN students AS  ST 
    //         ON ST.studentID = ES.studentID
    //         INNER JOIN subjects AS  S
    //         ON S.subjectID = ES.subjectID
    //         inner JOIN subject_schedules AS  SS 
    //         ON SS.enrollmentID = ES.enrollmentID
    //         AND SS.subjectID = ES.subjectID
    //         LEFT JOIN faculty AS  F
    //         ON F.facultyID = SS.facultyID
    //     WHERE EXISTS (
    //             SELECT 1
    //             FROM subject_clearance_requirements AS SCR
    //             WHERE SCR.subjectID = S.subjectID
    //         )
    //         AND ('ALL' = ? OR S.subjectID = ?)
    //         AND concat(year(ST.date_created),lpad(ES.studentID,5,0)) = ?
    //         AND ('ALL' = ? OR SC.status = ?);
    // "
    // );
    $clearanceDAO->prepareStatement("getSubjectsClearance","SELECT DISTINCT
            S.subjectID AS subjectID,
            S.name AS name,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            SC.date_cleared AS date_cleared,
            SC.remarks AS remarks,
            SC.status AS status,
            SS.roomID AS room,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name
        FROM subject_clearance AS SC
            INNER JOIN enrolled_subjects AS ES
                ON ES.enrolled_subjectID = SC.enrolled_subjectID
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            inner JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            LEFT JOIN faculty AS F
                ON F.facultyID = SS.facultyID
        WHERE EXISTS (
                SELECT 1
                FROM subject_clearance_requirements AS SCR
                WHERE SCR.subjectID = S.subjectID
                    AND SCR.enrollmentID = ES.enrollmentID
            )
            AND ('ALL' = ? OR S.subjectID = ?)
            AND ('ALL' = ? OR SC.status = ?)
            AND CONCAT(YEAR(ST.date_created), LPAD(ES.studentID, 5, '0')) = ?
            AND ( CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?);
            
    
    ");

    // $clearanceDAO->prepareStatement("getSubjectRequirements","SELECT
    //         SC.subject_clearanceID,
    //         S.subjectID AS subjectID,
    //         S.name AS name,
    //         SC.date_cleared AS date_cleared,
    //         SC.remarks AS remarks,
    //         SC.status AS status,
    //         CONCAT(SS.time_start,' - ', SS.time_end) as class_time,
    //         SS.day AS day,
    //         SS.roomID AS room,
    //         CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
    //         SCR.ID,
    //         SCR.fileID,
    //         SCR.file_name,
    //         SCR.mime_type,
    //         IF(
    //             COALESCE(SCR.file_size, 0) < 1000000,
    //             CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1024, 1), ' KB'),
    //             CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1048576, 2), ' MB')
    //         ) AS file_size,
    //         SCR.uploaded_at
    //     FROM
    //         subject_clearance AS SC
    //     INNER JOIN enrolled_subjects AS ES
    //     ON
    //         ES.enrolled_subjectID = SC.enrolled_subjectID
    //     INNER JOIN students AS ST
    //     ON
    //         ST.studentID = ES.studentID
    //     INNER JOIN subjects AS S
    //     ON
    //         S.subjectID = ES.subjectID
    //     LEFT JOIN subject_schedules AS SS
    //     ON
    //         SS.enrollmentID = ES.enrollmentID AND SS.subjectID = ES.subjectID
    //     LEFT JOIN faculty AS F
    //     ON  F.facultyID = SS.facultyID
    //     left JOIN subject_clearance_requirements AS SCR
    //     ON 	SCR.subjectID = ES.subjectID
            
    //     WHERE
    //         S.subjectID = ? and concat(year(ST.date_created),lpad(ES.studentID,5,0)) = ?
    //         ANd not SCR.uploaded_by = ?;

    // ");

    $clearanceDAO->prepareStatement("getSubjectRequirements","SELECT
            EP.enrollmentID,
            SC.subject_clearanceID,
            S.subjectID AS subjectID,
            S.name AS name,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            SC.date_cleared AS date_cleared,
            SC.remarks AS remarks,
            SC.status AS status,
            CONCAT(SS.time_start, ' - ', SS.time_end) AS class_time,
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
        FROM enrolled_subjects AS ES
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            INNER JOIN subject_clearance_requirements AS SCR
                ON SCR.subjectID = ES.subjectID
                AND SCR.enrollmentID = ES.enrollmentID
            LEFT JOIN subject_clearance AS SC
                ON SC.enrolled_subjectID = ES.enrolled_subjectID
            LEFT JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            LEFT JOIN faculty AS F
                ON F.facultyID = SS.facultyID
        WHERE S.subjectID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ES.studentID, 5, '0')) = ?
            AND (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? )
            AND SCR.uploaded_by <> ?
        ORDER BY EP.acad_year DESC, EP.sem DESC, SCR.uploaded_at DESC;
    ");

    $clearanceDAO->prepareStatement("getUploadedSubjectRequirements","SELECT
            SC.subject_clearanceID,
            S.subjectID AS subjectID,
            S.name AS name,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            SC.date_cleared AS date_cleared,
            SC.remarks AS remarks,
            SC.status AS status,
            CONCAT(SS.time_start, ' - ', SS.time_end) AS class_time,
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
        FROM enrolled_subjects AS ES
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            INNER JOIN subject_clearance_requirements AS SCR
                ON SCR.subjectID = ES.subjectID
                AND SCR.enrollmentID = ES.enrollmentID
            LEFT JOIN subject_clearance AS SC
                ON SC.enrolled_subjectID = ES.enrolled_subjectID
            LEFT JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            LEFT JOIN faculty AS F
                ON F.facultyID = SS.facultyID
        WHERE S.subjectID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ES.studentID, 5, '0')) = ?
            AND (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? )
            AND SCR.uploaded_by = ?
        ORDER BY EP.acad_year DESC, EP.sem DESC, SCR.uploaded_at DESC;

    ");

    $clearanceDAO->prepareStatement("addSubjectRequirement","INSERT INTO 
        `subject_clearance_requirements` ( `subjectID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`,`enrollmentID`) 
        VALUES (?, ?,?,?, ?,?,?)"
    );



    $clearanceDAO->prepareStatement("getFacultyClearance","SELECT DISTINCT
            F.role,
            FC.facultyID AS facultyID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            FC.date_cleared AS date_cleared,
            FC.remarks AS remarks,
            FC.status AS status,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name
        FROM faculty_clearance AS FC
            INNER JOIN students AS ST
                ON ST.studentID = FC.studentID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = FC.enrollmentID
            LEFT JOIN faculty AS F
                ON F.facultyID = FC.facultyID
        WHERE EXISTS (
                SELECT 1
                FROM faculty_clearance_requirements AS FCR
                WHERE FCR.faculty_clearanceID = FC.faculty_clearanceID
            )
            AND ('ALL' = ? OR F.last_name = ?)
            AND CONCAT(YEAR(ST.date_created), LPAD(FC.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
            AND ('ALL' = ? OR FC.status = ?);
    ");

    $clearanceDAO->prepareStatement("addFacultyRequirement","INSERT INTO 
        `faculty_clearance_requirements` ( `faculty_clearanceID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`) 
        VALUES (?, ?,?,?, ?,?)"
    );

    $clearanceDAO->prepareStatement("getFacultyRequirements","SELECT
            FC.faculty_clearanceID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
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
        FROM faculty_clearance AS FC
            INNER JOIN students AS ST
                ON ST.studentID = FC.studentID
            INNER JOIN faculty AS F
                ON F.facultyID = FC.facultyID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = FC.enrollmentID
            LEFT JOIN faculty_clearance_requirements AS FCR
                ON FCR.faculty_clearanceID = FC.faculty_clearanceID
                AND FCR.uploaded_by <> ?
        WHERE FC.facultyID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
        ORDER BY EP.acad_year DESC, EP.sem DESC, FCR.uploaded_at DESC;
    ");

    $clearanceDAO->prepareStatement("getUploadedFacultyRequirements","SELECT
            FC.faculty_clearanceID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
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
        FROM faculty_clearance AS FC
            INNER JOIN students AS ST
                ON ST.studentID = FC.studentID
            INNER JOIN faculty AS F
                ON F.facultyID = FC.facultyID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = FC.enrollmentID
            LEFT JOIN faculty_clearance_requirements AS FCR
                ON FCR.faculty_clearanceID = FC.faculty_clearanceID
                AND FCR.uploaded_by = ?
        WHERE FC.facultyID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
        ORDER BY EP.acad_year DESC, EP.sem DESC, FCR.uploaded_at DESC;

    ");

    $clearanceDAO->prepareStatement("addOfficeRequirement","INSERT INTO 
        `office_clearance_requirements` ( `office_clearanceID`, `fileID`, `file_name`,mime_type, `file_size`,  `uploaded_by`) 
        VALUES (?, ?,?,?, ?,?)"
    );


    // $clearanceDAO->prepareStatement("getOfficeClearance","SELECT DISTINCT
        //         F.role,
        //         OC.officeID ,
        //         O.name,
        //         OC.date_cleared AS date_cleared,
        //         OC.remarks AS remarks,
        //         OC.status AS status,
        //         CONCAT(F.last_name, ', ', F.first_name) AS prof_name
        //     FROM office_clearance AS OC
        //         INNER JOIN students AS ST
        //             ON ST.studentID = OC.studentID
        //         inner JOIN office AS O
        //             ON O.officeID = OC.officeID
        //         inner JOIN faculty AS F
        //             ON F.facultyID = O.facultyID
        //     WHERE EXISTS (
        //             SELECT 1
        //             FROM office_clearance_requirements AS OCR
        //             WHERE OCR.office_clearanceID = OC.office_clearanceID
        //         )
        //         AND ('ALL' = ? OR upper(O.name) = upper(?))
        //         AND CONCAT(YEAR(ST.date_created), LPAD(OC.studentID, 5, 0)) = ?
        //         AND ('ALL' = ? OR OC.status = ?);

        // ");

    $clearanceDAO->prepareStatement("getOfficeClearance","SELECT DISTINCT
            F.role,
            OC.officeID,
            O.name,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            OC.date_cleared AS date_cleared,
            OC.remarks AS remarks,
            OC.status AS status,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name
        FROM office_clearance AS OC
            INNER JOIN students AS ST
                ON ST.studentID = OC.studentID
            INNER JOIN office AS O
                ON O.officeID = OC.officeID
            INNER JOIN faculty AS F
                ON F.facultyID = O.facultyID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = OC.enrollmentID
        WHERE EXISTS (
                SELECT 1
                FROM office_clearance_requirements AS OCR
                WHERE OCR.office_clearanceID = OC.office_clearanceID
            )
            AND ('ALL' = ? OR UPPER(O.name) = UPPER(?))
            AND CONCAT(YEAR(ST.date_created), LPAD(OC.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
            AND ('ALL' = ? OR OC.status = ?);
    ");

    // $clearanceDAO->prepareStatement("getOfficeRequirements","SELECT
    //         upper(O.name) as name,
    //         OC.office_clearanceID ,
    //         OC.date_cleared AS date_cleared,
    //         OC.remarks AS remarks,
    //         OC.status AS status,
    //         CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
    //         F.role,
    //         OCR.ID,
    //         OCR.fileID,
    //         OCR.file_name,
    //         OCR.mime_type,
    //         IF(
    //             COALESCE(OCR.file_size, 0) < 1000000,
    //             CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1024, 1), ' KB'),
    //             CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1048576, 2), ' MB')
    //         ) AS file_size,
    //         OCR.uploaded_at
    //     FROM
    //         office_clearance AS OC
    //     INNER JOIN students AS ST
    //     ON ST.studentID = OC.studentID
    //     inner JOIN office AS O
    //     ON O.officeID = OC.officeID
    //     inner JOIN faculty AS F
    //     ON  F.facultyID = O.facultyID
    //     inner JOIN office_clearance_requirements AS OCR
    //     ON 	OCR.office_clearanceID = OC.office_clearanceID

    //     WHERE
    //         O.officeID = ? and concat(year(ST.date_created),lpad(ST.studentID,5,0)) = ?
    //         ANd not OCR.uploaded_by = ?;

    // "); 

    $clearanceDAO->prepareStatement("getOfficeRequirements","SELECT
            UPPER(O.name) AS name,
            OC.office_clearanceID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            OC.date_cleared AS date_cleared,
            OC.remarks AS remarks,
            OC.status AS status,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
            F.role,
            OCR.ID,
            OCR.fileID,
            OCR.file_name,
            OCR.mime_type,
            IF(
                COALESCE(OCR.file_size, 0) < 1000000,
                CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1024, 1), ' KB'),
                CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1048576, 2), ' MB')
            ) AS file_size,
            OCR.uploaded_at
        FROM office_clearance AS OC
            INNER JOIN students AS ST
                ON ST.studentID = OC.studentID
            INNER JOIN office AS O
                ON O.officeID = OC.officeID
            INNER JOIN faculty AS F
                ON F.facultyID = O.facultyID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = OC.enrollmentID
            LEFT JOIN office_clearance_requirements AS OCR
                ON OCR.office_clearanceID = OC.office_clearanceID
                AND OCR.uploaded_by <> ?
        WHERE O.officeID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
        ORDER BY EP.acad_year DESC, EP.sem DESC, OCR.uploaded_at DESC;
    ");

    // $clearanceDAO->prepareStatement("getUploadedOfficeRequireMents","SELECT
    //         upper(O.name) as name,
    //         OC.office_clearanceID ,
    //         OC.date_cleared AS date_cleared,
    //         OC.remarks AS remarks,
    //         OC.status AS status,
    //         CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
    //         F.role,
    //         OCR.ID,
    //         OCR.fileID,
    //         OCR.file_name,
    //         OCR.mime_type,
    //         IF(
    //             COALESCE(OCR.file_size, 0) < 1000000,
    //             CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1024, 1), ' KB'),
    //             CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1048576, 2), ' MB')
    //         ) AS file_size,
    //         OCR.uploaded_at
    //     FROM
    //         office_clearance AS OC
    //     INNER JOIN students AS ST
    //     ON ST.studentID = OC.studentID
    //     inner JOIN office AS O
    //     ON O.officeID = OC.officeID
    //     inner JOIN faculty AS F
    //     ON  F.facultyID = O.facultyID
    //     inner JOIN office_clearance_requirements AS OCR
    //     ON 	OCR.office_clearanceID = OC.office_clearanceID

    //     WHERE
    //         O.officeID = ? and concat(year(ST.date_created),lpad(ST.studentID,5,0)) = ?
    //         ANd  OCR.uploaded_by = ?;

    // ");

    $clearanceDAO->prepareStatement("getUploadedOfficeRequireMents","SELECT
            UPPER(O.name) AS name,
            OC.office_clearanceID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            OC.date_cleared AS date_cleared,
            OC.remarks AS remarks,
            OC.status AS status,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
            F.role,
            OCR.ID,
            OCR.fileID,
            OCR.file_name,
            OCR.mime_type,
            IF(
                COALESCE(OCR.file_size, 0) < 1000000,
                CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1024, 1), ' KB'),
                CONCAT(ROUND(COALESCE(OCR.file_size, 0) / 1048576, 2), ' MB')
            ) AS file_size,
            OCR.uploaded_at
        FROM office_clearance AS OC
            INNER JOIN students AS ST
                ON ST.studentID = OC.studentID
            INNER JOIN office AS O
                ON O.officeID = OC.officeID
            INNER JOIN faculty AS F
                ON F.facultyID = O.facultyID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = OC.enrollmentID
            LEFT JOIN office_clearance_requirements AS OCR
                ON OCR.office_clearanceID = OC.office_clearanceID
                AND OCR.uploaded_by = ?
        WHERE O.officeID = ?
            AND CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
            AND ('ALL' = ? OR CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
        ORDER BY EP.acad_year DESC, EP.sem DESC, OCR.uploaded_at DESC;
    ");


    $clearanceDAO->prepareStatement("getStudentsForOfficeClearance","SELECT
            OC.office_clearanceID AS office_clearanceID,
            CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) AS studentID,
            ST.studentID AS real_studentID,
            CONCAT(ST.last_name, ', ', ST.first_name,
                IFNULL(CONCAT(' ', ST.middle_name), '')) AS student_name,
            ST.program,
            ST.block,
            EP.enrollmentID,
            OC.remarks,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            IFNULL(CAST(OC.date_cleared AS CHAR), 'not yet cleared') AS date_cleared,
            IFNULL(OC.`status`, 'not yet cleared') AS status
        FROM students AS ST
            INNER JOIN enrolled_subjects AS ES
                ON ES.studentID = ST.studentID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            LEFT JOIN office_clearance AS OC
                ON OC.studentID = ST.studentID
                AND OC.officeID = ?
                AND OC.enrollmentID = EP.enrollmentID
        WHERE (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?)
            AND (ST.block = ? OR 'ALL' = ?)
            AND (CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ? OR 'ALL' = ?)
        GROUP BY ST.studentID, ST.date_created, ST.last_name, ST.first_name,
            ST.middle_name, ST.program, ST.block, EP.acad_year, EP.sem, EP.enrollmentID,
            OC.office_clearanceID, OC.remarks, OC.date_cleared, OC.`status`
        ORDER BY EP.acad_year DESC, EP.sem DESC, student_name, ST.studentID,
            OC.date_cleared;
    ");



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

    $clearanceDAO->prepareStatement("findFacultyClearanceDocument","SELECT
            CF.ID,
            CF.fileID,
            CF.file_name,
            CF.mime_type,
            CF.file_size,
            CF.uploaded_by
        FROM
            (
                SELECT
                    CONCAT('S', SCR.ID) AS ID,
                    SCR.fileID,
                    SCR.file_name,
                    SCR.mime_type,
                    SCR.file_size,
                    SCR.uploaded_by
                FROM
                    subject_clearance_requirements AS SCR
                UNION
                SELECT
                    CONCAT('F', FCR.ID) AS ID,
                    FCR.fileID,
                    FCR.file_name,
                    FCR.mime_type,
                    FCR.file_size,
                    FCR.uploaded_by
                FROM
                    faculty_clearance_requirements AS FCR
                UNION
                SELECT
                    CONCAT('O', OCR.ID) AS ID,
                    OCR.fileID,
                    OCR.file_name,
                    OCR.mime_type,
                    OCR.file_size,
                    OCR.uploaded_by
                FROM
                    office_clearance_requirements AS OCR
            ) AS CF
        WHERE
            CF.ID = ? AND CF.uploaded_by = ?;
        "
    );

    // 'S' => 'deleteStudentClearanceDocument',
    //     'F' => 'deleteFacultyClearanceDocument',
    //     'O' => 'deleteOfficeClearanceDocument',

    $clearanceDAO->prepareStatement("deleteStudentClearanceDocument","
        DELETE FROM subject_clearance_requirements 
        WHERE ID = ? and uploaded_by = ?; 
    ");

    $clearanceDAO->prepareStatement("deleteFacultyClearanceDocument","
        DELETE FROM faculty_clearance_requirements 
        WHERE ID = ? and uploaded_by = ?;
    ");

    $clearanceDAO->prepareStatement("deleteOfficeClearanceDocument","
        DELETE FROM office_clearance_requirements 
        WHERE ID = ? and uploaded_by = ?;
    ");


    //faculty

    $clearanceDAO->prepareStatement("getOffices","SELECT 
            O.`officeID`,
            O.`name`,
            O.`facultyID`,
            O.`roomID`
        FROM `office` AS O
            inner join faculty AS F
            ON F.facultyID = O.facultyID
        WHERE 
            CONCAT( 'F',YEAR(F.date_created), LPAD(O.facultyID, 5, 0)) = ?;
    ");

    $clearanceDAO->prepareStatement("getSubjects","SELECT 
            SS.`schedID`,
            SS.`facultyID`,
            SS.`enrollmentID`,
            SS.`subjectID` as subjectID,
            SS.`day`,
            SS.`roomID`,
            SS.`time_start`,
            SS.`time_end`,
            SS.`block`,
            F.date_created,
            S.department,
            S.name,
            SY.year,
            SY.program,
            EP.acad_year,
            EP.sem,
            concat(EP.acad_year,' ',EP.sem,'sem') as acad_year_sem
        FROM `subject_schedules` AS SS
            inner join faculty AS F
            ON F.facultyID = SS.facultyID
            inner join subjects AS S
            ON S.subjectID = SS.`subjectID`
            inner join syllabus AS SY
            ON SY.subjectID = SS.`subjectID`
            inner join enrollment_period AS EP
            ON EP.enrollmentID = SS.`enrollmentID`
        WHERE 
            (S.department = ? OR 'ALL' = ?)
            AND (SS.`subjectID` = ? OR 'ALL' = ?)
            AND ( SS.`block` = ? OR 'ALL' = ?)
            AND ( SY.year = ? OR 'ALL' = ?)
            AND ( concat(EP.acad_year,' ',EP.sem,'sem') = ? OR 'ALL' = ?)
            AND CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) = ?;
           
    ");

    // $clearanceDAO->prepareStatement("getStudentsForSubjectClearance","SELECT 
    //         (ES.`enrolled_subjectID`) as enrolled_subjectID,
    //         ES.`enrollmentID`,
    //         CONCAT( YEAR(ST.date_created), LPAD( ES.`studentID`, 5, '0')) as studentID,
    //         CONCAT(ST.last_name, ', ', ST.first_name,' ',ST.middle_name) as student_name,
    //         ES.`subjectID`,
    //         ES.`midterm_grade`,
    //         ES.`final_grade`,
    //         IFNULL(SC.`status`, 'Not Yet Cleared') AS status,
    //         SS.`block`,
    //         S.department,
    //         S.name,
    //         SY.year,
    //         SY.program,
    //         EP.acad_year,
    //         EP.sem,
    //         SC.subject_clearanceID,
    //         IFNULL(SC.date_cleared, 'Not Yet Cleared') AS date_cleared,
    //         concat(EP.acad_year,' ',EP.sem,'sem') as acad_year_sem
    //     FROM `enrolled_subjects` AS ES
    //         INNER JOIN students AS ST
    //             ON ST.studentID = ES.`studentID`
    //         INNER JOIN subjects AS S
    //             ON S.subjectID = ES.`subjectID`
    //         INNER JOIN syllabus AS SY
    //             ON SY.subjectID = ES.`subjectID`
    //             AND SY.program = ST.program
    //         INNER JOIN enrollment_period AS EP
    //             ON EP.enrollmentID = ES.`enrollmentID`
    //         INNER JOIN `subject_schedules` AS SS
    //             ON SS.enrollmentID = ES.`enrollmentID`
    //             AND SS.subjectID = ES.`subjectID`
    //         INNER JOIN faculty AS F
    //             ON F.facultyID = SS.facultyID
    //         LEFT JOIN subject_clearance AS SC
    //             ON SC.enrolled_subjectID = ES.`enrolled_subjectID`
    //     WHERE 
    //         (S.department = ? OR 'ALL' = ?)
    //         AND (ES.`subjectID` = ? OR 'ALL' = ?)
    //         AND ( SS.`block` = ? OR 'ALL' = ?)
    //         AND ( SY.year = ? OR 'ALL' = ?)
    //         AND ( concat(EP.acad_year,' ',EP.sem,'sem') = ? OR 'ALL' = ?)
    //         AND CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) = ?;


    // ");

    $clearanceDAO->prepareStatement("getStudentsForSubjectClearance","SELECT 
            ES.`enrolled_subjectID` AS enrolled_subjectID,
            ES.`enrollmentID`,
            CONCAT(YEAR(ST.date_created), LPAD(ES.`studentID`, 5, '0')) AS studentID,
            CONCAT_WS(' ', CONCAT(ST.last_name, ','), ST.first_name, ST.middle_name) AS student_name,
            ES.`subjectID`,
            ES.`midterm_grade`,
            ES.`final_grade`,
            IFNULL(SC.`status`, 'Not Yet Cleared') AS status,
            COALESCE(SS.`block`, ST.`block`) AS block,
            S.department,
            S.name,
            SY.year,
            SY.program,
            EP.acad_year,
            EP.sem,
            SC.subject_clearanceID,
            IFNULL(SC.date_cleared, 'Not Yet Cleared') AS date_cleared,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            SS.facultyID,
            CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) AS faculty_code
        FROM `enrolled_subjects` AS ES
            INNER JOIN students AS ST
                ON ST.studentID = ES.`studentID`
            INNER JOIN subjects AS S
                ON S.subjectID = ES.`subjectID`
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.`enrollmentID`
            LEFT JOIN syllabus AS SY
                ON SY.subjectID = ES.`subjectID`
                AND SY.program = ST.program
            inner JOIN `subject_schedules` AS SS
                ON SS.enrollmentID = ES.`enrollmentID`
                AND SS.block = ST.`block`
                AND SS.subjectID = ES.`subjectID`
            LEFT JOIN faculty AS F
                ON F.facultyID = SS.facultyID
            inner JOIN subject_clearance AS SC
                ON SC.enrolled_subjectID = ES.`enrolled_subjectID`
            WHERE 
                    (S.department = ? OR 'ALL' = ?)
                    AND (ES.`subjectID` = ? OR 'ALL' = ?)
                    AND ( SS.`block` = ? OR 'ALL' = ?)
                    AND ( SY.year = ? OR 'ALL' = ?)
                    AND ( concat(EP.acad_year,' ',EP.sem,'sem') = ? OR 'ALL' = ?)
                    AND CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) = ?;
    
    ");


    $clearanceDAO->prepareStatement("editStudentsForSubjectClearance","SELECT 
            (ES.`enrolled_subjectID`) as enrolled_subjectID,
            ES.`enrollmentID`,
            CONCAT( YEAR(ST.date_created), LPAD( ES.`studentID`, 5, '0')) as studentID,
            CONCAT(ST.last_name, ', ', ST.first_name,' ',ST.middle_name) as student_name,
            ES.`subjectID`,
            ES.`midterm_grade`,
            ES.`final_grade`,
            IFNULL(SC.`status`, 'Not Yet Cleared') AS status,
            SS.`block`,
            S.department,
            S.name,
            SY.year,
            SY.program,
            EP.acad_year,
            EP.sem,
            SC.remarks,
            SC.subject_clearanceID,
            IFNULL(SC.date_cleared, 'Not Yet Cleared') AS date_cleared,
            concat(EP.acad_year,' ',EP.sem,'sem') as acad_year_sem
        FROM `enrolled_subjects` AS ES
            INNER JOIN students AS ST
                ON ST.studentID = ES.`studentID`
            INNER JOIN subjects AS S
                ON S.subjectID = ES.`subjectID`
            INNER JOIN syllabus AS SY
                ON SY.subjectID = ES.`subjectID`
                AND SY.program = ST.program
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.`enrollmentID`
            INNER JOIN `subject_schedules` AS SS
                ON SS.enrollmentID = ES.`enrollmentID`
                AND SS.subjectID = ES.`subjectID`
            INNER JOIN faculty AS F
                ON F.facultyID = SS.facultyID
            LEFT JOIN subject_clearance AS SC
                ON SC.enrolled_subjectID = ES.`enrolled_subjectID`
        WHERE 
            (S.department = ? OR 'ALL' = ?)
            AND (ES.`subjectID` = ? OR 'ALL' = ?)
            AND ( SS.`block` = ? OR 'ALL' = ?)
            AND ( SY.year = ? OR 'ALL' = ?)
            AND ( concat(EP.acad_year,' ',EP.sem,'sem') = ? OR 'ALL' = ?)
            AND CONCAT( YEAR(ST.date_created), LPAD( ES.`studentID`, 5, '0')) = ?
            AND CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) = ?;


    ");

    // $clearanceDAO->prepareStatement("getStudentsForFacultyClearance","SELECT
    //         CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) AS studentID,
    //         CONCAT(ST.last_name, ', ', ST.first_name,
    //             IFNULL(CONCAT(' ', ST.middle_name), '')) AS student_name,
    //         ST.program,
    //         ST.block,
    //         CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
    //         IFNULL(CAST(FC.date_cleared AS CHAR), 'not yet cleared') AS date_cleared,
    //         IFNULL(FC.`status`, 'not yet cleared') AS status
    //     FROM students AS ST
    //         INNER JOIN enrolled_subjects AS ES
    //             ON ES.studentID = ST.studentID
    //         INNER JOIN enrollment_period AS EP
    //             ON EP.enrollmentID = ES.enrollmentID
    //         LEFT JOIN faculty_clearance AS FC
    //                     ON FC.studentID = ST.`studentID`
    //                     and FC.facultyID = ?
    //     WHERE (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?)
    //             AND (ST.block = ? OR 'ALL' = ?)
    //             AND (CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ? OR 'ALL' = ?)
    //             AND (ST.program = ?)
    //     GROUP BY ST.studentID, ST.date_created, ST.last_name, ST.first_name,
    //             ST.middle_name, ST.program, ST.block, EP.acad_year, EP.sem,
    //             FC.date_cleared, FC.`status`
    //     ORDER BY EP.acad_year DESC, EP.sem DESC, student_name, ST.studentID,
    //             FC.date_cleared;
    // ");

    $clearanceDAO->prepareStatement("getStudentsForFacultyClearance","SELECT
            FC.faculty_clearanceID as faculty_clearanceID,
            CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) AS studentID,
            ST.studentID AS real_studentID,
            CONCAT(ST.last_name, ', ', ST.first_name,
                IFNULL(CONCAT(' ', ST.middle_name), '')) AS student_name,
            ST.program,
            ST.block,
            EP.enrollmentID,
            FC.remarks,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            IFNULL(CAST(FC.date_cleared AS CHAR), 'not yet cleared') AS date_cleared,
            IFNULL(FC.`status`, 'not yet cleared') AS status
        FROM students AS ST
            INNER JOIN enrolled_subjects AS ES
                ON ES.studentID = ST.studentID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            LEFT JOIN faculty_clearance AS FC
                ON FC.studentID = ST.studentID
                AND FC.facultyID = ?
        WHERE (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?)
            AND (ST.block = ? OR 'ALL' = ?)
            AND (CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ? OR 'ALL' = ?)
            AND (
                ST.program = ?
                OR ST.program IN (
                    SELECT P.program
                    FROM programs AS P
                    WHERE (P.department = ? OR 'ALL' = ?)
                )
            )
        GROUP BY ST.studentID, ST.date_created, ST.last_name, ST.first_name,
            ST.middle_name, ST.program, ST.block, EP.acad_year, EP.sem,
            FC.date_cleared, FC.`status`,FC.faculty_clearanceID,EP.enrollmentID,ST.studentID
        ORDER BY EP.acad_year DESC, EP.sem DESC, student_name, ST.studentID,
            FC.date_cleared;
        
    ");

    $clearanceDAO->prepareStatement("getCurrentAcadYear","SELECT 
        CONCAT(acad_year, ' ', sem, 'sem') AS acad_year_sem
        FROM enrollment_period
        ORDER BY acad_year DESC
    ");

    // $clearanceDAO->prepareStatement("getFacultyUploadedRequirements","SELECT
    //         S.subjectID AS subjectID,
    //         S.name AS name,
    //         SC.date_cleared AS date_cleared,
    //         SC.remarks AS remarks,
    //         SC.status AS status,
    //         CONCAT(SS.time_start,' - ', SS.time_end) as class_time,
    //         SS.day AS day,
    //         SS.roomID AS room,
    //         CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
    //         SCR.ID,
    //         SCR.fileID,
    //         SCR.file_name,
    //         SCR.mime_type,
    //         IF(
    //             COALESCE(SCR.file_size, 0) < 1000000,
    //             CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1024, 1), ' KB'),
    //             CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1048576, 2), ' MB')
    //         ) AS file_size,
    //         SCR.uploaded_at
    //     FROM
    //         subject_clearance AS SC
    //     INNER JOIN enrolled_subjects AS ES
    //     ON
    //         ES.enrolled_subjectID = SC.enrolled_subjectID
    //     INNER JOIN students AS ST
    //     ON
    //         ST.studentID = ES.studentID
    //     INNER JOIN subjects AS S
    //     ON
    //         S.subjectID = ES.subjectID
    //     LEFT JOIN subject_schedules AS SS
    //     ON
    //         SS.enrollmentID = ES.enrollmentID AND SS.subjectID = ES.subjectID
    //     LEFT JOIN faculty AS F
    //     ON  F.facultyID = SS.facultyID
    //     left JOIN subject_clearance_requirements AS SCR
    //     ON 	SCR.subjectID = ES.subjectID
            
    //     WHERE
    //         S.subjectID = ? 
    //         ANd SCR.uploaded_by = ?;

    // ");

    $clearanceDAO->prepareStatement("getFacultyUploadedRequirements","SELECT
            S.subjectID AS subjectID,
            S.name AS name,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            SCR.ID,
            SCR.fileID,
            SCR.file_name,
            SCR.mime_type,
            IF(
                COALESCE(SCR.file_size, 0) < 1000000,
                CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1024, 1), ' KB'),
                CONCAT(ROUND(COALESCE(SCR.file_size, 0) / 1048576, 2), ' MB')
            ) AS file_size,
            SCR.uploaded_by,
            SCR.uploaded_at
        FROM subject_clearance_requirements AS SCR
            INNER JOIN subjects AS S
                ON S.subjectID = SCR.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = SCR.enrollmentID
        WHERE SCR.subjectID = ?
            AND (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? )
            AND (SCR.uploaded_by = ? )
        ORDER BY SCR.uploaded_at DESC;
    
    ");

    $clearanceDAO->prepareStatement("getAllClearance","SELECT
            `faculty_clearanceID` AS id,
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
            `office_clearanceID`,
            'office' AS category,
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
            `subject_clearanceID`,
            'subjects' AS category,
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
    ");

    $clearanceDAO->prepareStatement("updateSubjectClearance","UPDATE `subject_clearance` 
        SET 
            `remarks` = ?,
            `status` = ?
        WHERE `subject_clearanceID` = ?;

    ");

    $clearanceDAO->prepareStatement("updateOfficeClearance","UPDATE `office_clearance` 
        SET 
            `remarks` = ?,
            `status` = ?
        WHERE `office_clearanceID` = ?;

    ");

    $clearanceDAO->prepareStatement("updateFacultyClearance","UPDATE `faculty_clearance` 
        SET 
            `remarks` = ?,
            `status` = ?
        WHERE `faculty_clearanceID` = ?;
    
    ");

    $clearanceDAO->prepareStatement("addSubjectClearance"," INSERT 
        INTO `subject_clearance` (
            `enrolled_subjectID`,
            `remarks`,
            `status`
        ) VALUES (?,?,? );
    ");

    $clearanceDAO->prepareStatement("addOfficeClearance","INSERT INTO `office_clearance` (
            `officeID`, 
            `studentID`, 
            `remarks`, 
            `enrollmentID`
        ) VALUES (?, ?, ?, ?);

    ");

    $clearanceDAO->prepareStatement("addFacultyClearance","INSERT INTO `faculty_clearance` (
            `facultyID`, 
            `studentID`, 
            `remarks`, 
            `enrollmentID`
        ) VALUES (?, ?, ?, ?);
    ");

    

    $clearanceDAO->prepareStatement("getFacultyClearanceID","SELECT 
            `faculty_clearanceID` 
        FROM `faculty_clearance` 
        WHERE `enrollmentID` = ?
        and `studentID` = ? 
        and `facultyID` = ?;"
    );

    $clearanceDAO->prepareStatement("getOfficeClearanceID","SELECT 
            `office_clearanceID` 
        FROM `office_clearance` 
        WHERE `enrollmentID` = ?
        and `studentID` = ? 
        and `officeID` = ?;"
    );

    $clearanceDAO->prepareStatement("getProjectsStatus","SELECT
            P.projectID,
            P.name AS project_name,
            P.deadline,
            P.status AS project_status,
            S.subjectID AS subjectID,
            SS.block AS block,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            COUNT(PS.id) AS submissions,
            MAX(PS.submitted_at) AS last_submitted_at,
            CASE
                WHEN COUNT(PS.id) > 0 THEN 'submitted'
                WHEN P.deadline < NOW() THEN 'overdue'
                ELSE 'waiting'
            END AS submission_status
        FROM projects AS P
            INNER JOIN enrolled_subjects AS ES
                ON ES.enrolled_subjectID = P.enrolled_subjectID
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            INNER JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            LEFT JOIN project_submission AS PS
                ON PS.projectID = P.projectID
        WHERE SS.facultyID = ?
            AND (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?)
            AND (ES.subjectID = ? OR 'ALL' = ?)
        GROUP BY P.projectID, P.name, P.deadline, P.status, S.subjectID,
            SS.block, EP.acad_year, EP.sem
        ORDER BY P.deadline DESC;
    ");

    $clearanceDAO->prepareStatement("getAllProjectStatus","SELECT
            P.projectID,
            CONCAT(ES.subjectID,' - ',UPPER(P.name)) AS project_name,
            ES.subjectID,
            P.deadline,
            P.status AS project_status,
            S.subjectID AS subjectID,
            SS.block AS block,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            COUNT(PS.id) AS submissions,
            MAX(PS.submitted_at) AS last_submitted_at,
            CASE
                WHEN COUNT(PS.id) > 0 THEN 'submitted'
                WHEN P.deadline < NOW() THEN 'overdue'
                ELSE 'waiting'
            END AS submission_status
        FROM projects AS P
            INNER JOIN enrolled_subjects AS ES
                ON ES.enrolled_subjectID = P.enrolled_subjectID
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            INNER JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            INNER JOIN faculty AS F
                ON F.facultyID = SS.facultyID
            LEFT JOIN project_submission AS PS
                ON PS.projectID = P.projectID
        WHERE  (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?)
            AND (CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ? OR CONCAT('F',YEAR(F.date_created), LPAD(F.facultyID, 5, '0')) = ?)
        GROUP BY P.projectID, P.name, P.deadline, P.status, S.subjectID,
            SS.block, EP.acad_year, EP.sem,ES.subjectID
        ORDER BY P.deadline DESC;
    ");

    $clearanceDAO->prepareStatement("getStudentSchedule", "SELECT
            S.subjectID AS subjectID,
            S.name AS name,
            SS.day AS day,
            SS.time_start AS time_start,
            SS.time_end AS time_end,
            SS.roomID AS roomID,
            CONCAT(F.last_name, ', ', F.first_name) AS prof_name,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem
        FROM enrolled_subjects AS ES
            INNER JOIN students AS ST
                ON ST.studentID = ES.studentID
            INNER JOIN subjects AS S
                ON S.subjectID = ES.subjectID
            INNER JOIN enrollment_period AS EP
                ON EP.enrollmentID = ES.enrollmentID
            INNER JOIN subject_schedules AS SS
                ON SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
            LEFT JOIN faculty AS F
                ON F.facultyID = SS.facultyID
        WHERE CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
            AND (CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ? OR 'ALL' = ?)
        ORDER BY EP.acad_year DESC, EP.sem DESC, SS.time_start;"
    );


    $clearanceDAO->prepareStatement("getOpenEnrollments", "SELECT
            EP.enrollmentID,
            EP.acad_year,
            EP.sem,
            CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
            EP.open_date,
            EP.close_date,
            EXISTS (
                SELECT 1
                FROM enrolled_subjects AS ES
                WHERE ES.enrollmentID = EP.enrollmentID
                    AND ES.studentID = ST.studentID
            ) AS already_enrolled
        FROM enrollment_period AS EP
            INNER JOIN students AS ST
                ON ST.program = EP.program
        WHERE ST.studentID = ?
            AND EP.status = 'open'
            AND EP.student_window_open = 1
            AND NOW() BETWEEN EP.open_date AND EP.close_date
        ORDER BY EP.acad_year DESC, EP.sem DESC;"
    );

    $clearanceDAO->prepareStatement("getStudentEvents", "SELECT
            E.eventID,
            E.eventName,
            E.datetime_Start,
            E.datetime_End,
            E.status,
            CONCAT(F.last_name, ', ', F.first_name) AS coordinator_name,
            EXISTS (
                SELECT 1
                FROM attendance AS A
                WHERE A.eventID = E.eventID
                    AND A.studentID = ST.studentID
            ) AS attended
        FROM events AS E
            INNER JOIN students AS ST
                ON ST.studentID = ?
            LEFT JOIN programs AS P
                ON P.program = ST.program
            LEFT JOIN faculty AS F
                ON F.facultyID = E.eventCoordinator
        WHERE E.status <> 'FINISHED'
            AND (
                E.program = ST.program
                OR E.department = P.department
                OR (E.program IS NULL AND E.department IS NULL)
            )
        ORDER BY E.datetime_Start;
    ");
    $clearanceDAO->prepareStatement("getFacultyEvents", "SELECT
            E.eventID,
            E.eventName,
            E.datetime_Start,
            E.datetime_End,
            E.status,
            CONCAT(F.last_name, ', ', F.first_name) AS coordinator_name,
            COUNT(DISTINCT A.studentID) AS attendees
        FROM events AS E
            INNER JOIN faculty AS FA
                ON FA.facultyID = ?
            LEFT JOIN faculty AS F
                ON F.facultyID = E.eventCoordinator
            LEFT JOIN attendance AS A
                ON A.eventID = E.eventID
        WHERE E.status <> 'FINISHED'
            AND (
                E.eventCoordinator = FA.facultyID
                OR E.program = FA.program
                OR E.department = FA.department
                OR (E.program IS NULL AND E.department IS NULL)
            )
        GROUP BY E.eventID, E.eventName, E.datetime_Start, E.datetime_End,
            E.status, F.last_name, F.first_name
        ORDER BY E.datetime_Start;
    ");
}

// ---- CLEARANCE FORM: one row per approved enrolled subject ----
$clearanceDAO->prepareStatement("getClearanceFormSubjects", "SELECT
        ES.enrolled_subjectID,
        ES.subjectID,
        S.name,
        CONCAT_WS(' ', CONCAT(ST.last_name, ','), ST.first_name, ST.middle_name) AS student_name,
        ST.program,
        ST.block,
        PR.department,
        CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
        CONCAT(F.last_name, ', ', F.first_name) AS instructor,
        SC.status,
        SC.date_cleared
    FROM enrolled_subjects AS ES
        INNER JOIN students AS ST
            ON ST.studentID = ES.studentID
        INNER JOIN subjects AS S
            ON S.subjectID = ES.subjectID
        INNER JOIN enrollment_period AS EP
            ON EP.enrollmentID = ES.enrollmentID
        LEFT JOIN programs AS PR
            ON PR.program = ST.program
        LEFT JOIN subject_clearance AS SC
            ON SC.enrolled_subjectID = ES.enrolled_subjectID
        LEFT JOIN subject_schedules AS SS
            ON SS.enrollmentID = ES.enrollmentID
            AND SS.subjectID = ES.subjectID
            AND SS.block = ST.block
        LEFT JOIN faculty AS F
            ON F.facultyID = SS.facultyID
    WHERE CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
        AND CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?
    ORDER BY ES.subjectID;
");

// ---- CLEARANCE FORM: dean and program coordinator ----
$clearanceDAO->prepareStatement("getClearanceFormSignatories", "SELECT
        F.role,
        CONCAT(F.first_name, ' ', F.last_name) AS name
    FROM faculty AS F
    WHERE (LOWER(F.role) LIKE '%dean%' AND F.department = ?)
        OR (LOWER(F.role) LIKE '%coordinator%' AND F.program = ?);
");

// ---- CLEARANCE FORM: every office, with this student's status for the term ----
$clearanceDAO->prepareStatement("getClearanceFormOffices", "SELECT
        O.officeID,
        UPPER(O.name) AS name,
        CONCAT(F.last_name, ', ', F.first_name) AS office_head,
        OC.status,
        OC.date_cleared
    FROM office AS O
        INNER JOIN students AS ST
            ON CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) = ?
        INNER JOIN enrollment_period AS EP
            ON CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?
            AND EP.program = ST.program
        LEFT JOIN faculty AS F
            ON F.facultyID = O.facultyID
        LEFT JOIN office_clearance AS OC
            ON OC.officeID = O.officeID
            AND OC.studentID = ST.studentID
            AND OC.enrollmentID = EP.enrollmentID
    ORDER BY O.name;
");

// ---- CLASS LIST FORM: approved students of one subject + block + term ----
$clearanceDAO->prepareStatement("getClassListForm", "SELECT
        ES.enrolled_subjectID,
        CONCAT(YEAR(ST.date_created), LPAD(ST.studentID, 5, '0')) AS studentID,
        CONCAT_WS(' ', CONCAT(ST.last_name, ','), ST.first_name, ST.middle_name) AS student_name,
        ST.program,
        ST.block,
        S.name AS subject_name,
        CONCAT(EP.acad_year, ' ', EP.sem, 'sem') AS acad_year_sem,
        SC.status,
        (SELECT COUNT(*)
            FROM projects AS P
            WHERE P.enrolled_subjectID = ES.enrolled_subjectID) AS total_projects,
        (SELECT COUNT(*)
            FROM projects AS P
            WHERE P.enrolled_subjectID = ES.enrolled_subjectID
                AND EXISTS (
                    SELECT 1
                    FROM project_submission AS PS
                    WHERE PS.projectID = P.projectID
                )) AS submitted_projects
    FROM enrolled_subjects AS ES
        INNER JOIN students AS ST
            ON ST.studentID = ES.studentID
        INNER JOIN subjects AS S
            ON S.subjectID = ES.subjectID
        INNER JOIN enrollment_period AS EP
            ON EP.enrollmentID = ES.enrollmentID
        LEFT JOIN subject_clearance AS SC
            ON SC.enrolled_subjectID = ES.enrolled_subjectID
    WHERE ES.subjectID = ?
        AND (ST.block = ? OR 'ALL' = ?)
        AND CONCAT(EP.acad_year, ' ', EP.sem, 'sem') = ?
        AND EXISTS (
            SELECT 1
            FROM subject_schedules AS SS
                INNER JOIN faculty AS F
                    ON F.facultyID = SS.facultyID
            WHERE SS.enrollmentID = ES.enrollmentID
                AND SS.subjectID = ES.subjectID
                AND SS.block = ST.block
                AND CONCAT('F', YEAR(F.date_created), LPAD(SS.facultyID, 5, '0')) = ?
        )
    ORDER BY ST.block, ST.last_name, ST.first_name;
");