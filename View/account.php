<?php
  require_once __DIR__."/../PageLogic/account_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
<link rel="stylesheet" href="/View/style/general.css">
</head>
<body>
  
  <?php require_once __DIR__."/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__."/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__."/components/header.php"; ?>
      <main class="column scrollbar" >
         <img src="/get-profile" width="150" height="150" alt="No profile picture yet">

        <?php if (!empty($uploadStatusMessage)): ?>
          <p><?= htmlspecialchars($uploadStatusMessage) ?></p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="/upload-profile">
          <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">Upload</button>
        </form>
      </main>
    </div>
  </div>
</body>
</html>