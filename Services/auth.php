<?php
  require_once __DIR__."/session.php";

  if(!isLoggedIn()){
    header('Location: /student/login');
    exit;
  }