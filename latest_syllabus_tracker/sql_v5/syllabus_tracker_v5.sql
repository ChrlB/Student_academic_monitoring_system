-- ===========================================================================
-- Syllabus Tracker v5:
--   1. Midterm & Final term organization on syllabus_modules
--   2. School Academic Calendar (per acad_year + sem) & calendar breaks
--      so topic weeks and dates follow the calendar as a starting basis
--   3. Three assessment types per topic: Quiz, Exam, and Project
--      (syllabus_projects + syllabus_project_submissions)
--
-- Safe to run more than once (idempotent).
-- Import in phpMyAdmin: ams_db > SQL or Import tab.
-- ===========================================================================

-- 1) Midterm & Final term tag on syllabus_modules
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'syllabus_modules'
              AND COLUMN_NAME = 'term');
SET @s := IF(@c = 0,
             "ALTER TABLE `syllabus_modules` ADD COLUMN `term` enum('midterm','final') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'midterm' AFTER `title`",
             'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Academic Calendar (shared by all classes in the same acad_year + sem)
CREATE TABLE IF NOT EXISTS `syllabus_academic_calendar` (
  `calendarID`        int UNSIGNED NOT NULL AUTO_INCREMENT,
  `acad_year`         varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sem`               tinyint UNSIGNED NOT NULL,
  `semester_start`    date NOT NULL,                           -- Day 1 of Week 1
  `midterm_weeks`     tinyint UNSIGNED NOT NULL DEFAULT 9,     -- Weeks 1..midterm_weeks = Midterm
  `total_weeks`       tinyint UNSIGNED NOT NULL DEFAULT 18,    -- Remaining weeks = Final
  `midterm_exam_week` tinyint UNSIGNED DEFAULT 9,
  `final_exam_week`   tinyint UNSIGNED DEFAULT 18,
  `updated_by`        int UNSIGNED DEFAULT NULL,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`calendarID`),
  UNIQUE KEY `uq_acad_sem` (`acad_year`, `sem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Holidays / semestral breaks attached to an academic calendar
CREATE TABLE IF NOT EXISTS `syllabus_calendar_breaks` (
  `breakID`    int UNSIGNED NOT NULL AUTO_INCREMENT,
  `calendarID` int UNSIGNED NOT NULL,
  `title`      varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date`   date NOT NULL,
  `skip_week`  tinyint(1) NOT NULL DEFAULT 0,                  -- 1 = pushes subsequent weeks forward
  PRIMARY KEY (`breakID`),
  KEY `calendarID` (`calendarID`),
  CONSTRAINT `syllabus_calendar_breaks_ibfk_1` FOREIGN KEY (`calendarID`)
    REFERENCES `syllabus_academic_calendar` (`calendarID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Project assessment per topic (alongside Quiz and Exam in syllabus_quizzes)
CREATE TABLE IF NOT EXISTS `syllabus_projects` (
  `projectID`        int UNSIGNED NOT NULL AUTO_INCREMENT,
  `taskID`           int UNSIGNED NOT NULL,
  `title`            varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `instructions`     text COLLATE utf8mb4_unicode_ci,
  `submission_type`  enum('file','link','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both',
  `max_score`        smallint UNSIGNED NOT NULL DEFAULT 100,
  `pass_score`       smallint UNSIGNED NOT NULL DEFAULT 70,
  `require_approval` tinyint(1) NOT NULL DEFAULT 1,            -- 1 = faculty approves/passes to complete; 0 = submitting completes
  `is_published`     tinyint(1) NOT NULL DEFAULT 0,
  `created_at`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`projectID`),
  UNIQUE KEY `uq_task_project` (`taskID`),
  CONSTRAINT `syllabus_projects_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_project_submissions` (
  `submissionID` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `projectID`    int UNSIGNED NOT NULL,
  `studentID`    int UNSIGNED NOT NULL,
  `link_url`     varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_name`  varchar(80)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type`    varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size`    bigint UNSIGNED DEFAULT NULL,
  `notes`        text COLLATE utf8mb4_unicode_ci,
  `status`       enum('submitted','approved','revision') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `score`        smallint UNSIGNED DEFAULT NULL,
  `feedback`     text COLLATE utf8mb4_unicode_ci,
  `submitted_at` datetime NOT NULL,
  `graded_at`    datetime DEFAULT NULL,
  `graded_by`    int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`submissionID`),
  UNIQUE KEY `uq_project_student` (`projectID`, `studentID`),
  KEY `studentID` (`studentID`),
  CONSTRAINT `syllabus_project_submissions_ibfk_1` FOREIGN KEY (`projectID`)
    REFERENCES `syllabus_projects` (`projectID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `syllabus_project_submissions_ibfk_2` FOREIGN KEY (`studentID`)
    REFERENCES `students` (`studentID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;