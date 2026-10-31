<div class="row">
  <header>
    <h2>
    <?php echo $_SESSION["page"];?>
</h2> 

    <?php if (isset($_SESSION["user"])): ?>
      <div class="column" style="align-items: center;">
        <img src="/get-profile" alt="No profile picture yet" class="profile_icon">
        <!-- <img src="/View/resources/View Quilt.png" alt="" class="profile_icon"> -->
        <b><?php echo  $_SESSION["user"] ; ?></b>
      </div>
    <?php endif ?>
  </header>
</div>