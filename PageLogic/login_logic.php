<?php
  require_once __DIR__."/../Services/session.php";

  

  function logout(){
    terminateSession();
    header('Location: /login');
    exit;
  }

  function login(){
    $_SESSION["user"] = "john";
    header('Location: /dashboard');
    exit;
  }
?>