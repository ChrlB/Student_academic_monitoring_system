<?php
require_once __DIR__ . "/../PageLogic/clearance_logic.php";


 

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/clearance.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>
      <main class="column scrollbar" style="gap:20px">
        <!-- <div style="width:600px; height:400px; border:2px solid #444; border-radius:8px; overflow:hidden;">
        <iframe src="serve.php?file=<= urlencode($file) ?>"
                style="width:100%; height:100%; border:0;"></iframe>
        </div> -->

        <!-- <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
            <input type="file" name="document"
                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  required>
            <button type="submit">Upload document</button>
        </form>
        <img src="<?= BASE_PATH ?>/get-profile" width="150" height="150" alt="No profile picture yet"> -->
        <div class="row" style=" gap:20px">
          <div class="clearance_categ_box"></div>
          <div class="clearance_categ_box"></div>
          <div class="clearance_categ_box"></div>
        </div>

        <div class="row" style="gap:20px">
          <div class="column" style="gap:20px">
            <div class="row" style="justify-content:space-between; align-items:center; color:white; ">
              <b style=" font-size:30px">UPDATES</b>
              <div class="row">
                <b style=" font-size:25px">View All Updates</b>
                <div></div>
              </div>

            </div>
            <div class="updates_frame">
              <table class="updates_table">
                <thead>
                  <tr>
                    <th class="col_name">CATEGORY</th>
                    <th class="col_about">ABOUT</th>
                    <th class="col_date">DATE</th>
                    <th class="col_status">STATUS</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($updates_record as $row): ?>
                    <tr>
                      <td title="<?= htmlspecialchars($row['category']) ?>">
                        <?= htmlspecialchars($row['category']) ?>
                      </td>
                      <td title="<?= htmlspecialchars($row['item_name']) ?>">
                        <?= htmlspecialchars($row['item_name']) ?>
                      </td>
                      <td><?= htmlspecialchars($row['date_cleared']) ?></td>
                      <td>
                        <span class="status_badge <?= statusClass($row['status']) ?>">
                          <?= htmlspecialchars(strtoupper($row['status'])) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="pie_chart">
            <canvas id="pie"></canvas>
          </div>
        </div>


        <!-- <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-profile">
          <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">change profile</button>
        </form>  -->


      </main>
    </div>
  </div>
  <?= require_once __DIR__ . "/script/clearance.php"; ?>
</body>

</html>