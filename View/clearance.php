<?php
  require_once __DIR__."/../PageLogic/clearance_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
<link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  
  <?php require_once __DIR__."/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__."/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__."/components/header.php"; ?>
      <main class="column scrollbar" >
        <!-- <div style="width:600px; height:400px; border:2px solid #444; border-radius:8px; overflow:hidden;">
        <iframe src="serve.php?file=<= urlencode($file) ?>"
                style="width:100%; height:100%; border:0;"></iframe>
        </div> -->
        
        <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH?>/upload-document">
            <input type="file" name="document"
                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  required>
            <button type="submit">Upload document</button>
        </form>
        <img src="<?= BASE_PATH?>/get-profile" width="150" height="150" alt="No profile picture yet">

        <?php if (!empty($uploadStatusMessage)): ?>
          <p><?= htmlspecialchars($uploadStatusMessage) ?></p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH?>/upload-profile">
          <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">change profile</button>
        </form>

        <div style="width:150px; height:400px; border:3px solid #fffdfd; border-radius:30px; padding:20px; color:aliceblue; background-color: rgba(40,40,40,0.3);">
          <canvas id="pie"></canvas>
        </div>
      </main>
    </div>
  </div>
  <?= require_once __DIR__."/script/clearance.php"; ?>
</body>
</html>