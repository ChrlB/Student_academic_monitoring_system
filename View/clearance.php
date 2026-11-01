<?php
  require_once __DIR__."/../PageLogic/clearance_logic.php";

// 1. Simulate data coming from a MySQL database, API, or local array
$browser_data = [
    "Chrome"  => 65.5,
    "Safari"  => 18.2,
    "Edge"    => 5.2,
    "Firefox" => 3.1,
    "Opera"   => 2.3
];

// 2. Separate keys (labels) and values (data) into separate arrays
$labels = array_keys($browser_data);
$data   = array_values($browser_data);

// 3. Convert arrays into JSON strings so JavaScript can safely read them
$js_labels = json_encode($labels);
$js_data   = json_encode($data);
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
        <!-- <div style="width:600px; height:400px; border:2px solid #444; border-radius:8px; overflow:hidden;">
        <iframe src="serve.php?file=<= urlencode($file) ?>"
                style="width:100%; height:100%; border:0;"></iframe>
        </div> -->
        
        <form method="post" enctype="multipart/form-data" action="/upload-document">
            <input type="file" name="document"
                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  required>
            <button type="submit">Upload document</button>
        </form>
        <img src="/get-profile" width="150" height="150" alt="No profile picture yet">

        <?php if (!empty($uploadStatusMessage)): ?>
          <p><?= htmlspecialchars($uploadStatusMessage) ?></p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="/upload-profile">
          <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">change profile</button>
        </form>
      </main>
    </div>
  </div>
</body>
</html>