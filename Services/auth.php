<?php
  require_once __DIR__."/session.php";

  if(!isLoggedIn()){
    global $base;
    $_SESSION["error"] = "Account verification required";
<<<<<<< HEAD
    header('Location: '.$base.'/login');
=======
    header('Location: '.BASE_PATH.'/login');
>>>>>>> 762bac65d9bdd87d93ea7b06931500bb3d6f4638
    exit;
  }

  function navigateToDashboard(){
    if($_SESSION['role'] == "student") require __DIR__ . '/../View/dashboard.php';
    else if($_SESSION['role'] == "faculty"){
      require __DIR__ . '/../View/faculty/faculty_dashboard.php';
    }
  }