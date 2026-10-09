<?php
  require_once __DIR__."/session.php";

  if(!isLoggedIn()){
    header('Location: /student/login');
    exit;
  }

  function navigateToDashboard(){
    if($_SESSION['role'] == "student") require __DIR__ . '/../View/student/dashboard.php';
    else if($_SESSION['role'] == "faculty"){
      require __DIR__ . '/../View/faculty/faculty_dashboard.php';
    }
  }