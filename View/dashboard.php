<?php
  require_once __DIR__."/../Services/session.php";
  require_once __DIR__."/../PageLogic/dashboard_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SACAD</title>
</head>
<body>
  this is dashboard

  <a href="/dashboard">
    <input type="button" value="Go to dashboard">
  </a>

  <a href="/class-sched">
    <input type="button" value="Go to class-scheds">
  </a>

  <a href="/account">
    <input type="button" value="Go to account">
  </a>
</body>
</html>