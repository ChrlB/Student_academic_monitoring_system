<?php
require_once __DIR__ . "/../PageLogic/registration_logic.php";
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/account.css">
</head>

<body>
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>
      <main class="column scrollbar">
        <form action="<?= BASE_PATH ?>/registration" method="post">
          <select  id="" name="acount_category" onchange="this.form.submit()">
            <option value="STUDENT" <?php echo ($selected == "STUDENT") ? 'selected' : '';?>>STUDENT</option>
            <option value="FACULTY" <?php echo ($selected == "FACULTY")? 'selected' : ''; ?>>FACULTY</option>
          </select>
        </form>
        

        </option>

        <?php if(isset($selected) && $selected == "STUDENT"):  ?>
          STUDENT
          <form action="" class="registration"><input type="text">
            
            <input type="text"><input type="text">
            <input type="password" name="" id="">
            <button type="submit">REGISTER</button>
          </form>

        <?php else:?>
          FACULTY
          <form action="" class="registration"><input type="text">
            <input type="text"><input type="text">
            <input type="password" name="" id="">
            <button type="submit">REGISTER</button>
          </form>
        <?php endif?>
        
      </main>
    </div>
  </div>
</body>

</html>