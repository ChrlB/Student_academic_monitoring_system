<?php
  

  function logout(){
    global $base;
    terminateSession();
<<<<<<< HEAD
    header('Location: '.$base.'/login');
=======
    header('Location: '.BASE_PATH.'/login');
>>>>>>> 762bac65d9bdd87d93ea7b06931500bb3d6f4638
    exit;
  }

  function login($login_info){
    global $userDAO;
    global $base;

    $records = $userDAO->executeQuery("getUserByID",[$login_info["username"]]);

    $password =  trim($login_info['password']);
    
    if(password_verify($password, $records[0]['password'])){
      $_SESSION["userID"] = trim($login_info['username']);
      $_SESSION['user'] = $records[0]["last_name"];
      $_SESSION["role"] = $records[0]["role"];

      $_SESSION["message"] = "Account verification sucsessful";

<<<<<<< HEAD
      header('Location: '.$base.'/dashboard');
=======
      header('Location: '.BASE_PATH.'/dashboard');
>>>>>>> 762bac65d9bdd87d93ea7b06931500bb3d6f4638
      exit;
    }
    
    $_SESSION["error"] = "login failed";
<<<<<<< HEAD
    header('Location: '.$base.'/');
=======
    header('Location: '.BASE_PATH.'/');
>>>>>>> 762bac65d9bdd87d93ea7b06931500bb3d6f4638
    exit;

    

    //header('Location: /dashboard');
   
  }
?>