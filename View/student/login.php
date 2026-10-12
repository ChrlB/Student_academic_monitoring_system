<?php
  require_once __DIR__.'/../../PageLogic/login_logic.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
</head>

<body> 
  
  this is login page
  <form action="/login" method="post">
    <input type="text" name="username">
    <button type="submit">login</button>
  </form>

  <a href="/faculty/login">
    <input type="button" value="login as faculty">
  </a>

</body>

</html>