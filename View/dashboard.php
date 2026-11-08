<?php
require_once __DIR__ . "/../PageLogic/dashboard_logic.php";
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
  <link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
</head>

<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>
      <main class="column scrollbar">
        <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
            <input type="file" name="document"
                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  required>
            <button type="submit">Upload document</button>
            <input type="hidden" name="page_uploader" value="<?php echo parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
        </form>
      </main>
    </div>
  </div>



</body>

</html>