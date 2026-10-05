<?php

  if(!isLoggedIn()){
    header('Location: /student/login');
    exit;
  }