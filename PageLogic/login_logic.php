<?php
  

  function logout(){
    global $base;
    terminateSession();
    header('Location: '.BASE_PATH.'/login');
    exit;
  }

  function login($login_info){
    global $userDAO;

    $records = $userDAO->executeQuery("getUserByID",[$login_info["username"]]);

    $password =  trim($login_info['password']);
    
    if(password_verify($password, $records[0]['password'])){
      $_SESSION["userID"] = trim($login_info['username']);
      $_SESSION['user'] = $records[0]["last_name"];
      $_SESSION["role"] = $records[0]["role"];

      $_SESSION["message"] = "Account verification sucsessful";

      header('Location: '.BASE_PATH.'/dashboard');
      exit;
    }
    
    $_SESSION["error"] = "login failed";
    header('Location: '.BASE_PATH.'/');
    exit;

    

    //header('Location: /dashboard');
   
  }
?>