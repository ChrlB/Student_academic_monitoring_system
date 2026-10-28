<?php
  

  function logout(){
    terminateSession();
    header('Location: /login');
    exit;
  }

  function login($login_info){
    global $userDAO;

    $records = $userDAO->executeQuery("getUserByID",[$login_info["username"]]);
    $hased_password = password_hash("123", PASSWORD_DEFAULT);

    $password =  trim($login_info['password']);
    
    if(password_verify($password, $hased_password)){
      $_SESSION["userID"] = trim($login_info['username']);
      $_SESSION['user'] = $records[0]["last_name"];
      $_SESSION["role"] = $records[0]["role"];

      $_SESSION["message"] = "login sucsessful";

      header('Location: /dashboard');
      exit;
    }
    
    $_SESSION["error"] = "login failed";
    header('Location: /');
    exit;

    

    //header('Location: /dashboard');
   
  }
?>