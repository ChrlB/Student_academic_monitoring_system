<?php
  require_once __DIR__."/../PageLogic/account_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
<link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
<link rel="stylesheet" href="<?= BASE_PATH?>/View/style/account.css">
</head>
<body>
  
  <?php require_once __DIR__."/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__."/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__."/components/header.php"; ?>
      <main class="column scrollbar" >
        <img src="<?= BASE_PATH?>/get-profile"  alt="No profile picture yet" class="profile_pic">
        <?php getUserInfo(); ?>
      </main>
    </div>
  </div>
</body>
</html>