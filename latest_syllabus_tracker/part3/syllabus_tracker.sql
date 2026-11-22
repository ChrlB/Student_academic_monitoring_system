-- Syllabus Tracker tables for ams_db
-- Safe to run more than once (IF NOT EXISTS). Import in phpMyAdmin: ams_db > Import.
--
-- A "class" is one row of subject_schedules (subject + program + block), so each
-- section of a subject gets its own modules, topics (tasks) and learning contents.
--
--   syllabus_modules   one per module of a class
--   syllabus_tasks     topics inside a module (these drive the completion %)
--   syllabus_contents  learning contents attached to a topic: PDF, video or text note

CREATE TABLE IF NOT EXISTS `syllabus_modules` (
  `moduleID`   int UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedID`    int UNSIGNED NOT NULL,
  `title`      varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`moduleID`),
  KEY `schedID` (`schedID`),
  CONSTRAINT `syllabus_modules_ibfk_1` FOREIGN KEY (`schedID`)
    REFERENCES `subject_schedules` (`schedID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_tasks` (
  `taskID`       int UNSIGNED NOT NULL AUTO_INCREMENT,
  `moduleID`     int UNSIGNED NOT NULL,
  `title`        varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `week_no`      tinyint UNSIGNED DEFAULT NULL,
  `due_date`     date DEFAULT NULL,
  `status`       enum('not_started','in_progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `completed_at` datetime DEFAULT NULL,
  `sort_order`   int NOT NULL DEFAULT 0,
  PRIMARY KEY (`taskID`),
  KEY `moduleID` (`moduleID`),
  CONSTRAINT `syllabus_tasks_ibfk_1` FOREIGN KEY (`moduleID`)
    REFERENCES `syllabus_modules` (`moduleID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_contents` (
  `contentID`    int UNSIGNED NOT NULL AUTO_INCREMENT,
  `taskID`       int UNSIGNED NOT NULL,
  `kind`         enum('pdf','video','note') COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`        varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`  varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body`         text COLLATE utf8mb4_unicode_ci,                       -- text of a note
  `file_name`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- original file name
  `stored_name`  varchar(80)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- random name on disk (storage/syllabus/)
  `mime_type`    varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size`    bigint UNSIGNED DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,                         -- 0 = hidden from students
  `sort_order`   int NOT NULL DEFAULT 0,
  `uploaded_by`  int UNSIGNED DEFAULT NULL,
  `created_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`contentID`),
  KEY `taskID` (`taskID`),
  CONSTRAINT `syllabus_contents_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Topic "lesson page" (rich text written in the editor) + images pasted into it
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `syllabus_pages` (
  `taskID`     int UNSIGNED NOT NULL,
  `html`       mediumtext COLLATE utf8mb4_unicode_ci,      -- sanitized HTML shown to students
  `delta`      mediumtext COLLATE utf8mb4_unicode_ci,      -- editor document (lossless, used to re-open the editor)
  `updated_by` int UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`taskID`),
  CONSTRAINT `syllabus_pages_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_images` (
  `imageID`     int UNSIGNED NOT NULL AUTO_INCREMENT,
  `taskID`      int UNSIGNED NOT NULL,
  `stored_name` varchar(80)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type`   varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size`   bigint UNSIGNED DEFAULT NULL,
  `uploaded_by` int UNSIGNED DEFAULT NULL,
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`imageID`),
  KEY `taskID` (`taskID`),
  CONSTRAINT `syllabus_images_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- v3: per-student progress, opening dates, quizzes & mock exams
-- (safe to re-run)
-- ===========================================================================

-- Professor-set "opens on" date for each topic (the existing due_date is the deadline).
-- Before this date a topic is UPCOMING and locked for students.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_tasks' AND COLUMN_NAME = 'available_from');
SET @sql := IF(@col = 0,
               'ALTER TABLE `syllabus_tasks` ADD COLUMN `available_from` date DEFAULT NULL AFTER `week_no`',
               'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Each STUDENT's own status per topic.
--   no row            -> Not Started
--   status in_progress -> the student opened the topic
--   status completed   -> the student finished it (passed the checkpoint, or marked it finished)
CREATE TABLE IF NOT EXISTS `syllabus_progress` (
  `studentID`        int UNSIGNED NOT NULL,
  `taskID`           int UNSIGNED NOT NULL,
  `status`           enum('in_progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `first_opened_at`  datetime NOT NULL,
  `last_opened_at`   datetime NOT NULL,
  `completed_at`     datetime DEFAULT NULL,
  `needs_restudy`    tinyint(1) NOT NULL DEFAULT 0,        -- 1 = failed a checkpoint, must study again
  `last_fail_at`     datetime DEFAULT NULL,
  `last_fail_quizID` int UNSIGNED DEFAULT NULL,
  `restudy_hits`     int UNSIGNED NOT NULL DEFAULT 0,      -- materials opened since the last fail
  PRIMARY KEY (`studentID`,`taskID`),
  KEY `taskID` (`taskID`),
  CONSTRAINT `syllabus_progress_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `syllabus_progress_ibfk_2` FOREIGN KEY (`studentID`)
    REFERENCES `students` (`studentID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checkpoints: one mini quiz and one mock exam per topic.
CREATE TABLE IF NOT EXISTS `syllabus_quizzes` (
  `quizID`           int UNSIGNED NOT NULL AUTO_INCREMENT,
  `taskID`           int UNSIGNED NOT NULL,
  `kind`             enum('quiz','exam') COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`            varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pass_percent`     tinyint UNSIGNED NOT NULL DEFAULT 70,
  `cooldown_minutes` smallint UNSIGNED NOT NULL DEFAULT 5,     -- minimum study time after a failed attempt
  `draw_count`       smallint UNSIGNED DEFAULT NULL,           -- exam: random questions per attempt (NULL = all)
  `is_published`     tinyint(1) NOT NULL DEFAULT 0,
  `created_at`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`quizID`),
  UNIQUE KEY `task_kind` (`taskID`,`kind`),
  CONSTRAINT `syllabus_quizzes_ibfk_1` FOREIGN KEY (`taskID`)
    REFERENCES `syllabus_tasks` (`taskID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_questions` (
  `questionID` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `quizID`     int UNSIGNED NOT NULL,
  `prompt`     text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`questionID`),
  KEY `quizID` (`quizID`),
  CONSTRAINT `syllabus_questions_ibfk_1` FOREIGN KEY (`quizID`)
    REFERENCES `syllabus_quizzes` (`quizID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_choices` (
  `choiceID`   int UNSIGNED NOT NULL AUTO_INCREMENT,
  `questionID` int UNSIGNED NOT NULL,
  `label`      varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`choiceID`),
  KEY `questionID` (`questionID`),
  CONSTRAINT `syllabus_choices_ibfk_1` FOREIGN KEY (`questionID`)
    REFERENCES `syllabus_questions` (`questionID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_attempts` (
  `attemptID`     int UNSIGNED NOT NULL AUTO_INCREMENT,
  `quizID`        int UNSIGNED NOT NULL,
  `studentID`     int UNSIGNED NOT NULL,
  `score_pct`     tinyint UNSIGNED NOT NULL,
  `correct_count` smallint UNSIGNED NOT NULL,
  `total_count`   smallint UNSIGNED NOT NULL,
  `passed`        tinyint(1) NOT NULL DEFAULT 0,
  `answers`       text COLLATE utf8mb4_unicode_ci,
  `submitted_at`  datetime NOT NULL,
  PRIMARY KEY (`attemptID`),
  KEY `quiz_student` (`quizID`,`studentID`),
  CONSTRAINT `syllabus_attempts_ibfk_1` FOREIGN KEY (`quizID`)
    REFERENCES `syllabus_quizzes` (`quizID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `syllabus_attempts_ibfk_2` FOREIGN KEY (`studentID`)
    REFERENCES `students` (`studentID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- v4: quiz / exam MODES + student practice (spaced repetition, mistake review)
-- (safe to re-run)
-- ===========================================================================

-- how each checkpoint is run:
--   standard | instant | timed | confidence | adaptive | survival
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_quizzes' AND COLUMN_NAME = 'mode');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_quizzes` ADD COLUMN `mode` varchar(20) NOT NULL DEFAULT ''standard'' AFTER `kind`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_quizzes' AND COLUMN_NAME = 'time_limit_minutes');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_quizzes` ADD COLUMN `time_limit_minutes` smallint UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_quizzes' AND COLUMN_NAME = 'lives');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_quizzes` ADD COLUMN `lives` tinyint UNSIGNED NOT NULL DEFAULT 3', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_quizzes' AND COLUMN_NAME = 'allow_practice');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_quizzes` ADD COLUMN `allow_practice` tinyint(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_questions' AND COLUMN_NAME = 'explanation');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_questions` ADD COLUMN `explanation` text DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_questions' AND COLUMN_NAME = 'difficulty');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_questions` ADD COLUMN `difficulty` tinyint UNSIGNED NOT NULL DEFAULT 2', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_attempts' AND COLUMN_NAME = 'mode');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_attempts` ADD COLUMN `mode` varchar(20) NOT NULL DEFAULT ''standard''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syllabus_attempts' AND COLUMN_NAME = 'details');
SET @s := IF(@c = 0, 'ALTER TABLE `syllabus_attempts` ADD COLUMN `details` text DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Each student's memory of each question (drives Mistake review + Spaced review).
CREATE TABLE IF NOT EXISTS `syllabus_qstate` (
  `studentID`     int UNSIGNED NOT NULL,
  `questionID`    int UNSIGNED NOT NULL,
  `streak`        smallint UNSIGNED NOT NULL DEFAULT 0,   -- correct answers in a row
  `wrong_count`   smallint UNSIGNED NOT NULL DEFAULT 0,
  `correct_count` smallint UNSIGNED NOT NULL DEFAULT 0,
  `box`           tinyint UNSIGNED NOT NULL DEFAULT 0,    -- spaced-repetition level 0-5
  `due_at`        datetime NOT NULL,                      -- when it should come back
  `mistake_open`  tinyint(1) NOT NULL DEFAULT 0,          -- 1 = in the mistake bank (cleared after 2 correct in a row)
  `last_seen`     datetime NOT NULL,
  PRIMARY KEY (`studentID`,`questionID`),
  KEY `questionID` (`questionID`),
  CONSTRAINT `syllabus_qstate_ibfk_1` FOREIGN KEY (`questionID`)
    REFERENCES `syllabus_questions` (`questionID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `syllabus_qstate_ibfk_2` FOREIGN KEY (`studentID`)
    REFERENCES `students` (`studentID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
