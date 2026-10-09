<?php

  function logout(){
    terminateSession();
    header('Location: /student/login');
    exit;
  }

  function login($login_info){
    
    $_SESSION["user"] = trim($login_info['username']);
    $_SESSION["role"] = "student";

    //header('Location: /dashboard');
    header('Location: /student/dashboard');
    exit;
  }
?>