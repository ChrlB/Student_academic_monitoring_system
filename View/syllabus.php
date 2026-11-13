<?php

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMS - Syllabus Tracker</title>

    <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
    
    <style>
        /* --- Syllabus Tracker Specific Styles --- */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

        .syllabus-wrapper {
            font-family: 'Inter', sans-serif;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            gap: 20px;
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
        }

        .syl-header {
            background-color: #2c1a38;
            padding: 20px;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .syl-header h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: 1px;
        }

        .course-info {
            background-color: #1a0f2e;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 10px;
            display: inline-block;
        }

        .completion-bar-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .completion-text {
            font-size: 14px;
        }

        .progress-bg {
            width: 200px;
            height: 8px;
            background-color: #1a0f2e;
            border-radius: 10px;
            border: 1px solid #4a2b5e;
            overflow: hidden;
        }

        .progress-fill {
            width: 68%;
            height: 100%;
            background-color: #ffffff;
            border-radius: 10px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .left-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .search-filter {
            background-color: #2c1a38;
            padding: 15px;
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .search-bar {
            width: 100%;
            padding: 10px;
            background-color: #000000;
            border: 1px solid #4a2b5e;
            border-radius: 8px;
            color: #fff;
            margin-bottom: 15px;
            box-sizing: border-box;
        }

        .filters {
            display: flex;
            gap: 15px;
        }

        .filter-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #b0a3c2;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .dot-green { background-color: #00ff00; }
        .dot-yellow { background-color: #ffcc00; }
        .dot-gray { background-color: #888888; }
        .dot-purple { background-color: #a855f7; }

        .syl-module {
            background-color: #2c1a38;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .syl-module h2 {
            margin: 0 0 15px 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .week-item {
            display: grid;
            grid-template-columns: 80px 1fr 120px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #3a1c4a;
        }

        .week-item:last-child {
            border-bottom: none;
        }

        .week-label {
            font-weight: 600;
            font-size: 14px;
        }

        .topic-name {
            font-size: 14px;
            color: #d1c4e9;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 600;
            text-align: center;
            text-transform: uppercase;
        }

        .status-completed {
            border: 1px solid #00ff00;
            color: #00ff00;
        }

        .status-progress {
            background-color: #ffcc00;
            color: #000;
        }

        .status-not-started {
            background-color: #e0e0e0;
            color: #000;
        }

        .right-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .syl-widget {
            background-color: #2c1a38;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .syl-widget h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
        }

        .stats-text {
            font-size: 14px;
            margin: 10px 0;
            color: #d1c4e9;
        }

        .syl-btn {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            background-color: #3a1c4a;
            border: 1px solid #5a3b7a;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-size: 14px;
            transition: background 0.2s;
        }

        .syl-btn:hover {
            background-color: #4a2b5e;
        }

        .milestone-subtext {
            font-size: 12px;
            color: #b0a3c2;
            margin-top: 5px;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body data-page="SYLLABUS">

    <?php require_once __DIR__ . "/components/popup_message.php"; ?>

    <div class="row main_box">
        <?php require_once __DIR__ . "/components/navigation.php"; ?>

        <div class="column" style="flex: 1;">
            <?php require_once __DIR__ . "/components/header.php"; ?>

            <main class="column scrollbar syllabus-page">
                
                <div class="syllabus-wrapper">
                    
                    <div class="syl-header">
                        <div>
                            <h1>SYLLABUS TRACKER</h1>
                            <div class="course-info">BSCS - 3A | Object-Oriented Programming</div>
                        </div>
                        <div class="completion-bar-container">
                            <span class="completion-text">Overall Completion 68%</span>
                            <div class="progress-bg">
                                <div class="progress-fill"></div>
                            </div>
                        </div>
                    </div>

                    <div class="content-grid">
                        
                        <div class="left-column">
                            <div class="search-filter">
                                <input type="text" class="search-bar" placeholder="🔍 Search topics...">
                                <div class="filters">
                                    <div class="filter-item"><div class="dot dot-green"></div> Completed</div>
                                    <div class="filter-item"><div class="dot dot-yellow"></div> In Progress</div>
                                    <div class="filter-item"><div class="dot dot-gray"></div> Not Started</div>
                                    <div class="filter-item"><div class="dot dot-purple"></div> Upcoming</div>
                                </div>
                            </div>

                            <div class="syl-module">
                                <h2>📦 Module 1: Introduction to OOP & Fundamentals</h2>
                                <div class="week-item">
                                    <div class="week-label">Week 1</div>
                                    <div class="topic-name">History, Paradigm Shift, and Setup</div>
                                    <div class="status-badge status-completed">COMPLETED</div>
                                </div>
                                <div class="week-item">
                                    <div class="week-label">Week 2</div>
                                    <div class="topic-name">Basic Syntax & Input/Output Operations</div>
                                    <div class="status-badge status-progress">IN PROGRESS</div>
                                </div>
                                <div class="week-item">
                                    <div class="week-label">Week 3</div>
                                    <div class="topic-name">Functions and Methods</div>
                                    <div class="status-badge status-not-started">NOT STARTED</div>
                                </div>
                            </div>

                            <div class="syl-module">
                                <h2>📦 Module 2: Classes, Objects, and Encapsulation</h2>
                                <div class="week-item">
                                    <div class="week-label">Week 4</div>
                                    <div class="topic-name">Understanding Classes & Object Creation</div>
                                    <div class="status-badge status-progress">IN PROGRESS</div>
                                </div>
                                <div class="week-item">
                                    <div class="week-label">Week 5</div>
                                    <div class="topic-name">Constructors and Destructors</div>
                                    <div class="status-badge status-not-started">NOT STARTED</div>
                                </div>
                            </div>
                        </div>

                        <div class="right-column">
                            <div class="syl-widget">
                                <h3>Quick Stats</h3>
                                <div class="stats-text"><strong>24/35</strong> Topics Completed</div>
                                <div class="stats-text"><strong>3</strong> In Progress</div>
                                <div class="stats-text"><strong>8</strong> Remaining</div>
                            </div>

                            <div class="syl-widget">
                                <h3>Current Week</h3>
                                <div class="stats-text">Week 3: Encapsulation</div>
                                <div class="milestone-subtext" style="margin-bottom: 15px;">Recommended deadline:<br>Sept 14</div>
                                <button class="syl-btn">Mark as Done</button>
                                <button class="syl-btn">Upload Material</button>
                            </div>

                            <div class="syl-widget">
                                <h3>Upcoming Milestone</h3>
                                <div class="stats-text" style="margin-bottom: 0;">Midterm Examination</div>
                                <div class="milestone-subtext">(Syllabus 1-4)<br>in 12 days</div>
                            </div>
                        </div>

                    </div>
                </div>

            </main>
        </div>
    </div>

    <?php require_once __DIR__ . "/components/logout.php"; ?>
    <script src="<?= BASE_PATH ?>/View/script/syllabus.js"></script>
</body>
</html>