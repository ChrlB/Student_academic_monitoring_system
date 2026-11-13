-- Syllabus Tracker tables for ams_db
-- Import once in phpMyAdmin (select ams_db > Import, or paste into the SQL tab).
-- A "class" is one row of subject_schedules (subject + program + block), so each
-- section of a subject gets its own modules and tasks.

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
