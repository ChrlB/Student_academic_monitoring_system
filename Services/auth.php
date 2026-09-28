<?php

  if(!isLoggedIn()){
    header('Location: /login');
    exit;
  }