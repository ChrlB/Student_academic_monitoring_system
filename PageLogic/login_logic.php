<?php

  function logout(){
    terminateSession();
    header('Location: /student/login');
    exit;
  }

  function login($login_info){
    
    $_SESSION["user"] = $login_info['username'] ?? "null";
    $_SESSION["role"] = "student";

    header('Location: /student/dashboard');
    exit;
  }
?>