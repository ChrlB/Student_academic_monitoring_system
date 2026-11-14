<?php
require_once __DIR__.'/../Services/auth.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
</head>
<body>
    <iframe src="<?= BASE_PATH ?>/download-document?id=<?= $_GET['id'] ?>&amp;docfor=<?= $_GET['docfor'] ?>&amp;categ=<?= $_GET['categ'] ?>"
            style="width:100%; height:100%; border:0; border-radius:30px;"></iframe>
</body>
</html>