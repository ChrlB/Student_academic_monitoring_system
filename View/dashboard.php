<?php
  require_once __DIR__."/../PageLogic/dashboard_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
</head>
<body>
  this is dashboard, Hi <?= $_SESSION['user'] ?>
  <?php require_once __DIR__."/components/navigation.php"; ?>

  
</body>
</html>