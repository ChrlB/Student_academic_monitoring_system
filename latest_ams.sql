-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 30, 2026 at 02:45 AM
-- Server version: 8.0.34
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `student_academic_monitoring_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `ID` int UNSIGNED NOT NULL,
  `eventID` int UNSIGNED NOT NULL,
  `facultyID` int UNSIGNED NOT NULL,
  `studentID` int UNSIGNED NOT NULL,
  `timein` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timeout` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `clearance_updates`
-- (See below for the actual view)
--
CREATE TABLE `clearance_updates` (
`category` varchar(8)
,`date_cleared` timestamp
,`item_name` varchar(250)
,`name` varchar(402)
,`remarks` varchar(250)
,`status` varchar(50)
,`studentID` int unsigned
);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department`, `name`) VALUES
('CCS', 'College of Computer Studies');

-- --------------------------------------------------------

--
-- Table structure for table `enrolled_subjects`
--

CREATE TABLE `enrolled_subjects` (
  `enrolled_subjectID` int NOT NULL,
  `enrollmentID` int UNSIGNED NOT NULL,
  `studentID` int UNSIGNED NOT NULL,
  `subjectID` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `midterm_grade` decimal(5,2) DEFAULT NULL,
  `final_grade` decimal(5,2) DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_period`
--

CREATE TABLE `enrollment_period` (
  `enrollmentID` int UNSIGNED NOT NULL,
  `program` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acad_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sem` tinyint NOT NULL,
  `open_date` timestamp NOT NULL,
  `close_date` timestamp NOT NULL,
  `status` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `date_created` timestamp NOT NULL DEFAULT (now())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `eventID` int UNSIGNED NOT NULL,
  `name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `datetime_start` timestamp NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty`
--

CREATE TABLE `faculty` (
  `facultyID` int UNSIGNED NOT NULL,
  `first_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_created` date NOT NULL DEFAULT (now()),
  `address` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `faculty`
--

INSERT INTO `faculty` (`facultyID`, `first_name`, `middle_name`, `last_name`, `password`, `role`, `department`, `date_created`, `address`, `number`, `date_of_birth`, `gender`, `status`) VALUES
(1, 'mike', 'santos', 'grega', '$2y$10$lUbCRz5DEY3CM9FI17WS.u0uJ.Uqvli9VsqrPTkE2UlA94rP3f9uO', 'faculty', 'CCS', '2026-10-27', 'olongapo city', '090909090', '2026-10-29', 'male', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_clearance`
--

CREATE TABLE `faculty_clearance` (
  `faculty_clearanceID` int UNSIGNED NOT NULL,
  `facultyID` int UNSIGNED NOT NULL,
  `studentID` int UNSIGNED NOT NULL,
  `date_cleared` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remarks` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cleared'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `office`
--

CREATE TABLE `office` (
  `officeID` int UNSIGNED NOT NULL,
  `name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `facultyID` int UNSIGNED NOT NULL,
  `roomID` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `office_clearance`
--

CREATE TABLE `office_clearance` (
  `office_cleranceID` int UNSIGNED NOT NULL,
  `officeID` int UNSIGNED NOT NULL,
  `studentID` int UNSIGNED NOT NULL,
  `date_cleared` timestamp NOT NULL,
  `remarks` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cleared'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program`, `name`, `department`) VALUES
('BSCS', 'Bachelor of Science in Computer Science', 'CCS'),
('BSEMC', 'Bachelor of Science in Entertainment and Multimedia Computing', 'CCS'),
('BSIT', 'Bachelor of Science in Information Technology', 'CCS');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `projectID` int UNSIGNED NOT NULL,
  `enrolled_subjectID` int NOT NULL,
  `name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deadline` timestamp NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_task`
--

CREATE TABLE `project_task` (
  `taskID` int UNSIGNED NOT NULL,
  `projectID` int UNSIGNED NOT NULL,
  `task_title` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deadline` timestamp NOT NULL,
  `satatus` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `roomID` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `studentID` int UNSIGNED NOT NULL,
  `first_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_created` date NOT NULL DEFAULT (now()),
  `year_level` tinyint NOT NULL,
  `block` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`studentID`, `first_name`, `middle_name`, `last_name`, `password`, `program`, `date_created`, `year_level`, `block`, `address`, `number`, `date_of_birth`, `gender`, `status`) VALUES
(1, 'fred', 'martinez', 'cruz', '$2y$10$lUbCRz5DEY3CM9FI17WS.u0uJ.Uqvli9VsqrPTkE2UlA94rP3f9uO', 'BSCS', '2026-10-27', 1, '1A', 'olongapo city', '090909090', '2026-10-29', 'male', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `subjectID` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subject_clearance`
--

CREATE TABLE `subject_clearance` (
  `subject_clearanceID` int UNSIGNED NOT NULL,
  `enrolled_subjectID` int NOT NULL,
  `date_cleared` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remarks` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cleared'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subject_schedules`
--

CREATE TABLE `subject_schedules` (
  `schedID` int UNSIGNED NOT NULL,
  `facultyID` int UNSIGNED NOT NULL,
  `enrollmentID` int UNSIGNED NOT NULL,
  `subjectID` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `day` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `roomID` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  `block` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `syllabus`
--

CREATE TABLE `syllabus` (
  `id` int NOT NULL,
  `subjectID` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prerequisite` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `program` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sem_available` tinyint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_assignment`
--

CREATE TABLE `task_assignment` (
  `ID` int UNSIGNED NOT NULL,
  `taskID` int UNSIGNED NOT NULL,
  `memberID` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team_members`
--

CREATE TABLE `team_members` (
  `memberID` int UNSIGNED NOT NULL,
  `projectID` int UNSIGNED NOT NULL,
  `studentID` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `users`
-- (See below for the actual view)
--
CREATE TABLE `users` (
`first_name` varchar(200)
,`last_name` varchar(200)
,`middle_name` varchar(200)
,`password` varchar(250)
,`role` varchar(200)
,`userID` varchar(11)
);

-- --------------------------------------------------------

--
-- Structure for view `clearance_updates`
--
DROP TABLE IF EXISTS `clearance_updates`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `clearance_updates`  AS SELECT 'faculty' AS `category`, `f`.`role` AS `item_name`, concat(`f`.`last_name`,', ',`f`.`first_name`) AS `name`, `fc`.`studentID` AS `studentID`, `fc`.`date_cleared` AS `date_cleared`, `fc`.`remarks` AS `remarks`, `fc`.`status` AS `status` FROM (`faculty_clearance` `fc` join `faculty` `f` on((`f`.`facultyID` = `fc`.`facultyID`)))union select 'office' AS `category`,`o`.`name` AS `item_name`,concat(`f`.`last_name`,', ',`f`.`first_name`) AS `name`,`oc`.`studentID` AS `studentID`,`oc`.`date_cleared` AS `date_cleared`,`oc`.`remarks` AS `remarks`,`oc`.`status` AS `status` from ((`office_clearance` `oc` join `office` `o` on((`o`.`officeID` = `oc`.`officeID`))) join `faculty` `f` on((`f`.`facultyID` = `o`.`facultyID`))) union select 'subjects' AS `category`,`es`.`subjectID` AS `item_name`,`s`.`name` AS `name`,`es`.`studentID` AS `studentID`,`sc`.`date_cleared` AS `date_cleared`,`sc`.`remarks` AS `remarks`,`sc`.`status` AS `status` from ((`subject_clearance` `sc` join `enrolled_subjects` `es` on((`es`.`enrolled_subjectID` = `sc`.`enrolled_subjectID`))) join `subjects` `s` on((`s`.`subjectID` = `es`.`subjectID`))) order by `date_cleared` desc  ;

-- --------------------------------------------------------

--
-- Structure for view `users`
--
DROP TABLE IF EXISTS `users`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `users`  AS SELECT concat('F',year(`faculty`.`date_created`),convert(lpad(`faculty`.`facultyID`,5,0) using utf8mb4)) AS `userID`, `faculty`.`first_name` AS `first_name`, `faculty`.`middle_name` AS `middle_name`, `faculty`.`last_name` AS `last_name`, `faculty`.`password` AS `password`, `faculty`.`role` AS `role` FROM `faculty`union select concat(year(`students`.`date_created`),lpad(`students`.`studentID`,5,0)) AS `userID`,`students`.`first_name` AS `first_name`,`students`.`middle_name` AS `middle_name`,`students`.`last_name` AS `last_name`,`students`.`password` AS `password`,'student' AS `role` from `students`  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `eventID` (`eventID`),
  ADD KEY `facultyID` (`facultyID`),
  ADD KEY `studentID` (`studentID`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `enrolled_subjects`
--
ALTER TABLE `enrolled_subjects`
  ADD PRIMARY KEY (`enrolled_subjectID`),
  ADD KEY `enrollmentID` (`enrollmentID`),
  ADD KEY `studentID` (`studentID`),
  ADD KEY `subjectID` (`subjectID`);

--
-- Indexes for table `enrollment_period`
--
ALTER TABLE `enrollment_period`
  ADD PRIMARY KEY (`enrollmentID`),
  ADD KEY `program` (`program`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`eventID`),
  ADD KEY `program` (`program`);

--
-- Indexes for table `faculty`
--
ALTER TABLE `faculty`
  ADD PRIMARY KEY (`facultyID`),
  ADD KEY `department` (`department`);

--
-- Indexes for table `faculty_clearance`
--
ALTER TABLE `faculty_clearance`
  ADD PRIMARY KEY (`faculty_clearanceID`),
  ADD KEY `facultyID` (`facultyID`),
  ADD KEY `studentID` (`studentID`);

--
-- Indexes for table `office`
--
ALTER TABLE `office`
  ADD PRIMARY KEY (`officeID`),
  ADD KEY `facultyID` (`facultyID`),
  ADD KEY `roomID` (`roomID`);

--
-- Indexes for table `office_clearance`
--
ALTER TABLE `office_clearance`
  ADD PRIMARY KEY (`office_cleranceID`),
  ADD KEY `officeID` (`officeID`),
  ADD KEY `studentID` (`studentID`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `department` (`department`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`projectID`),
  ADD KEY `enrolled_subjectID` (`enrolled_subjectID`);

--
-- Indexes for table `project_task`
--
ALTER TABLE `project_task`
  ADD PRIMARY KEY (`taskID`),
  ADD KEY `projectID` (`projectID`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`roomID`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`studentID`),
  ADD KEY `program` (`program`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`subjectID`),
  ADD KEY `department` (`department`);

--
-- Indexes for table `subject_clearance`
--
ALTER TABLE `subject_clearance`
  ADD PRIMARY KEY (`subject_clearanceID`),
  ADD KEY `enrolled_subjectID` (`enrolled_subjectID`);

--
-- Indexes for table `subject_schedules`
--
ALTER TABLE `subject_schedules`
  ADD PRIMARY KEY (`schedID`),
  ADD KEY `facultyID` (`facultyID`),
  ADD KEY `enrollmentID` (`enrollmentID`),
  ADD KEY `subjectID` (`subjectID`),
  ADD KEY `roomID` (`roomID`);

--
-- Indexes for table `syllabus`
--
ALTER TABLE `syllabus`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subjectID` (`subjectID`),
  ADD KEY `prerequisite` (`prerequisite`),
  ADD KEY `program` (`program`);

--
-- Indexes for table `task_assignment`
--
ALTER TABLE `task_assignment`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `taskID` (`taskID`),
  ADD KEY `memberID` (`memberID`);

--
-- Indexes for table `team_members`
--
ALTER TABLE `team_members`
  ADD PRIMARY KEY (`memberID`),
  ADD KEY `projectID` (`projectID`),
  ADD KEY `studentID` (`studentID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `ID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrolled_subjects`
--
ALTER TABLE `enrolled_subjects`
  MODIFY `enrolled_subjectID` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollment_period`
--
ALTER TABLE `enrollment_period`
  MODIFY `enrollmentID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `eventID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty`
--
ALTER TABLE `faculty`
  MODIFY `facultyID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `faculty_clearance`
--
ALTER TABLE `faculty_clearance`
  MODIFY `faculty_clearanceID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `office`
--
ALTER TABLE `office`
  MODIFY `officeID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `office_clearance`
--
ALTER TABLE `office_clearance`
  MODIFY `office_cleranceID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `projectID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_task`
--
ALTER TABLE `project_task`
  MODIFY `taskID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `studentID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subject_clearance`
--
ALTER TABLE `subject_clearance`
  MODIFY `subject_clearanceID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subject_schedules`
--
ALTER TABLE `subject_schedules`
  MODIFY `schedID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `syllabus`
--
ALTER TABLE `syllabus`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_assignment`
--
ALTER TABLE `task_assignment`
  MODIFY `ID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team_members`
--
ALTER TABLE `team_members`
  MODIFY `memberID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`eventID`) REFERENCES `events` (`eventID`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`facultyID`) REFERENCES `faculty` (`facultyID`),
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);

--
-- Constraints for table `enrolled_subjects`
--
ALTER TABLE `enrolled_subjects`
  ADD CONSTRAINT `enrolled_subjects_ibfk_1` FOREIGN KEY (`enrollmentID`) REFERENCES `enrollment_period` (`enrollmentID`),
  ADD CONSTRAINT `enrolled_subjects_ibfk_2` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`),
  ADD CONSTRAINT `enrolled_subjects_ibfk_3` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`);

--
-- Constraints for table `enrollment_period`
--
ALTER TABLE `enrollment_period`
  ADD CONSTRAINT `enrollment_period_ibfk_1` FOREIGN KEY (`program`) REFERENCES `programs` (`program`);

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`program`) REFERENCES `programs` (`program`);

--
-- Constraints for table `faculty`
--
ALTER TABLE `faculty`
  ADD CONSTRAINT `faculty_ibfk_1` FOREIGN KEY (`department`) REFERENCES `departments` (`department`);

--
-- Constraints for table `faculty_clearance`
--
ALTER TABLE `faculty_clearance`
  ADD CONSTRAINT `faculty_clearance_ibfk_1` FOREIGN KEY (`facultyID`) REFERENCES `faculty` (`facultyID`),
  ADD CONSTRAINT `faculty_clearance_ibfk_2` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);

--
-- Constraints for table `office`
--
ALTER TABLE `office`
  ADD CONSTRAINT `office_ibfk_1` FOREIGN KEY (`facultyID`) REFERENCES `faculty` (`facultyID`),
  ADD CONSTRAINT `office_ibfk_2` FOREIGN KEY (`roomID`) REFERENCES `rooms` (`roomID`);

--
-- Constraints for table `office_clearance`
--
ALTER TABLE `office_clearance`
  ADD CONSTRAINT `office_clearance_ibfk_1` FOREIGN KEY (`officeID`) REFERENCES `office` (`officeID`),
  ADD CONSTRAINT `office_clearance_ibfk_2` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`department`) REFERENCES `departments` (`department`);

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`enrolled_subjectID`) REFERENCES `enrolled_subjects` (`enrolled_subjectID`);

--
-- Constraints for table `project_task`
--
ALTER TABLE `project_task`
  ADD CONSTRAINT `project_task_ibfk_1` FOREIGN KEY (`projectID`) REFERENCES `projects` (`projectID`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`program`) REFERENCES `programs` (`program`);

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`department`) REFERENCES `departments` (`department`);

--
-- Constraints for table `subject_clearance`
--
ALTER TABLE `subject_clearance`
  ADD CONSTRAINT `subject_clearance_ibfk_1` FOREIGN KEY (`enrolled_subjectID`) REFERENCES `enrolled_subjects` (`enrolled_subjectID`);

--
-- Constraints for table `subject_schedules`
--
ALTER TABLE `subject_schedules`
  ADD CONSTRAINT `subject_schedules_ibfk_1` FOREIGN KEY (`facultyID`) REFERENCES `faculty` (`facultyID`),
  ADD CONSTRAINT `subject_schedules_ibfk_2` FOREIGN KEY (`enrollmentID`) REFERENCES `enrollment_period` (`enrollmentID`),
  ADD CONSTRAINT `subject_schedules_ibfk_3` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`),
  ADD CONSTRAINT `subject_schedules_ibfk_4` FOREIGN KEY (`roomID`) REFERENCES `rooms` (`roomID`);

--
-- Constraints for table `syllabus`
--
ALTER TABLE `syllabus`
  ADD CONSTRAINT `syllabus_ibfk_1` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`),
  ADD CONSTRAINT `syllabus_ibfk_2` FOREIGN KEY (`prerequisite`) REFERENCES `subjects` (`subjectID`),
  ADD CONSTRAINT `syllabus_ibfk_3` FOREIGN KEY (`program`) REFERENCES `programs` (`program`);

--
-- Constraints for table `task_assignment`
--
ALTER TABLE `task_assignment`
  ADD CONSTRAINT `task_assignment_ibfk_1` FOREIGN KEY (`taskID`) REFERENCES `project_task` (`taskID`),
  ADD CONSTRAINT `task_assignment_ibfk_2` FOREIGN KEY (`memberID`) REFERENCES `team_members` (`memberID`);

--
-- Constraints for table `team_members`
--
ALTER TABLE `team_members`
  ADD CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`projectID`) REFERENCES `projects` (`projectID`),
  ADD CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
