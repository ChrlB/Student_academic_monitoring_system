-- OPTIONAL clean-up after the mock exam was removed from the syllabus.
-- The app already ignores old mock exams, so you only need this to tidy the database.
-- Run it once in phpMyAdmin (ams_db > SQL). Safe to run again.

-- 1) students who failed a mock exam are no longer asked to study again
UPDATE `syllabus_progress`
   SET `needs_restudy` = 0
 WHERE `last_fail_quizID` IN (SELECT `quizID` FROM `syllabus_quizzes` WHERE `kind` = 'exam');

-- 2) students who had already passed the quiz of a topic (that also had an exam) are now completed
UPDATE `syllabus_progress` p
  JOIN `syllabus_quizzes` z ON z.`taskID` = p.`taskID` AND z.`kind` = 'quiz' AND z.`is_published` = 1
   SET p.`status` = 'completed', p.`completed_at` = NOW(), p.`needs_restudy` = 0
 WHERE p.`status` <> 'completed'
   AND EXISTS (SELECT 1 FROM `syllabus_attempts` a
                WHERE a.`quizID` = z.`quizID` AND a.`studentID` = p.`studentID` AND a.`passed` = 1);

-- 3) delete the mock exams (their questions, choices and attempts are removed with them)
DELETE FROM `syllabus_quizzes` WHERE `kind` = 'exam';
