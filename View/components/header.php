<div class="row ">
  <header class="drop_shadow">
    <h2>
    <?php if($_SESSION["page"] == "SCHEDULE"):?>
      <?php if($_SESSION["role"] == "student"): echo "Hi Student! Here’s your schedule"?>
      <?php elseif($_SESSION["role"] == "prof"): echo "Hi Ma’am/Sir! Here’s your schedule"?>
      <?php elseif($_SESSION["role"] == "dean"): echo "Hi Ma’am/ Sir! Monitor or add schedule here"?>
      <?php elseif($_SESSION["role"] == "program coordinator"): echo "Hi Ma’am/Sir! Here’s your schedule"?>
      <?php else :echo $_SESSION["page"] ?>
      <?php endif ?>
    <?php else :echo $_SESSION["page"] ?>
    <?php endif ?>
    
  </h2> 

    <?php if (isset($_SESSION["user"])): ?>
      <a class="column" style="align-items: center; text-decoration:none;" href="<?= BASE_PATH ?>/account">
        <img src="<?= BASE_PATH ?>/get-profile" alt="No profile picture yet" class="profile_icon">
        <!-- <img src="/View/resources/View Quilt.png" alt="" class="profile_icon"> -->
        <b class="header_username"><?php echo  $_SESSION["user"] ; ?></b>
      </a>
    <?php endif ?>
  </header>
</div>
<!-- If Student = ""
If Prof = ""
If Dean = "" -->