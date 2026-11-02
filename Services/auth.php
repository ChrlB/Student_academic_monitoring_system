<?php
  require_once __DIR__."/session.php";

  if(!isLoggedIn()){
    global $base;
    $_SESSION["error"] = "Account verification required";
    header('Location: '.BASE_PATH.'/login');
    exit;
  }

  function navigateToDashboard(){
    if($_SESSION['role'] == "student") require __DIR__ . '/../View/dashboard.php';
    else if($_SESSION['role'] == "faculty"){
      require __DIR__ . '/../View/faculty/faculty_dashboard.php';
    }
  }