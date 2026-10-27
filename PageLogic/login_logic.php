<?php

  function logout(){
    terminateSession();
    header('Location: /student/login');
    exit;
  }

  function login($login_info){
    $hased_password = password_hash("123", PASSWORD_DEFAULT);

    $password =  trim($login_info['password']);

    if(password_verify($password, $hased_password)){
      $_SESSION["user"] = trim($login_info['username']);
      $_SESSION["role"] = "student";

      $_SESSION["message"] = "login sucsessful";

      header('Location: /student/dashboard');
      exit;
    }
    
    $_SESSION["error"] = "login failed";
    header('Location: /');
    exit;

    

    //header('Location: /dashboard');
   
  }
?>