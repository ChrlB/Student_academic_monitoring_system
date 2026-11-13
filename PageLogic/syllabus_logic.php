<?php
// Auth + session. (BASE_PATH is already defined by index.php; config.local.php is not needed here.)
require_once __DIR__ . '/../Services/auth.php';

$_SESSION["page"] = "SYLLABUS";

/*
========================================================
  SYLLABUS DATA (static for now - replace with DAO calls)
  Statuses: completed | progress | not-started | upcoming
========================================================
*/
$syllabusCourse = "BSCS - 3A | Object-Oriented Programming";

$modules = [
    [
        "title" => "Module 1: Introduction to OOP & Fundamentals",
        "weeks" => [
            ["week" => 1, "topic" => "History, Paradigm Shift, and Setup",         "status" => "completed",   "deadline" => "Sept 7"],
            ["week" => 2, "topic" => "Basic Syntax & Input/Output Operations",      "status" => "progress",    "deadline" => "Sept 14"],
            ["week" => 3, "topic" => "Functions and Methods",                       "status" => "not-started", "deadline" => "Sept 21"],
        ],
    ],
    [
        "title" => "Module 2: Classes, Objects, and Encapsulation",
        "weeks" => [
            ["week" => 4, "topic" => "Understanding Classes & Object Creation",     "status" => "progress",    "deadline" => "Sept 28"],
            ["week" => 5, "topic" => "Constructors and Destructors",                "status" => "not-started", "deadline" => "Oct 5"],
        ],
    ],
    [
        "title" => "Module 3: Inheritance & Polymorphism",
        "weeks" => [
            ["week" => 6, "topic" => "Inheritance Basics",                          "status" => "upcoming",    "deadline" => "Oct 12"],
        ],
    ],
];

$statusMap = [
    "completed"   => ["label" => "COMPLETED",   "class" => "status-completed"],
    "progress"    => ["label" => "IN PROGRESS", "class" => "status-progress"],
    "not-started" => ["label" => "NOT STARTED", "class" => "status-not-started"],
    "upcoming"    => ["label" => "UPCOMING",    "class" => "status-upcoming"],
];

// Derive every number from the data so the page can never contradict itself.
$counts = ["completed" => 0, "progress" => 0, "not-started" => 0, "upcoming" => 0];
$currentWeek = null;
foreach ($modules as $m) {
    foreach ($m["weeks"] as $w) {
        $counts[$w["status"]]++;
        if ($currentWeek === null && $w["status"] === "progress") {
            $currentWeek = $w;
        }
    }
}
$totalTopics = array_sum($counts);
$remaining   = $counts["not-started"] + $counts["upcoming"];
$completion  = $totalTopics > 0 ? (int) round($counts["completed"] / $totalTopics * 100) : 0;

// PLACEHOLDER: set the real midterm date. Days-left is computed from it.
$midtermDate  = "2026-11-30";
$midtermLabel = "Midterm Examination";
$midtermScope = "(Syllabus 1-4)";
$daysToMidterm = (int) (new DateTime("today"))->diff(new DateTime($midtermDate))->format("%r%a");