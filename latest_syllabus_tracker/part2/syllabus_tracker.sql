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
