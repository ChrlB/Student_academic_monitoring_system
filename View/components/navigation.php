<?php 
require_once __DIR__.'/../../Services/auth.php';
$page = $_SESSION["page"] ?? "";
?>
<body data-page="<?= htmlspecialchars($page) ?>"> 


<div style="display: flex; flex-direction:row; ">
 
<div class="column navbar scrollbar">

  <img src="<?= BASE_PATH?>/View/resources/logo1.png" alt="" class="nav_logo">
  
  <div class="buttons_section">
    
    
    <a class="nav_buttons row DASHBOARD" href="<?= BASE_PATH?>/dashboard">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/View Quilt.png" alt="" class="nav_button_icons"> </div>
      <input type="button" value="DASHBOARD"></a>

    <?php if(in_array($_SESSION["role"], ["program coordinator","dean","prof","student"])): ?>
      <a class="nav_buttons row SCHEDULE" href="<?= BASE_PATH?>/class-sched">
        <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Calendar.png" alt="" class="nav_button_icons"> </div>
        <input type="button" value="SCHEDULE"></a>
    <?php endif ?>

    <a class="nav_buttons row GRADES" href="<?= BASE_PATH?>/grades">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Grades.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="GRADES"></a>

    <a class="nav_buttons row ATTENDANCE"  href="<?= BASE_PATH?>/attendance">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Attendance.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="ATTENDANCE"></a>
    <a class="nav_buttons row ENROLLMENT" href="<?= BASE_PATH?>/enrollment">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/enrollment.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="ENROLLMENT"></a>
    <a class="nav_buttons row PROJECTS" href="<?= BASE_PATH?>/projects">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Project.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="PROJECTS"></a>

    <a class="nav_buttons row SYLLABUS" href="<?= BASE_PATH?>/syllabus">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Syllabus.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="SYLLABUS"></a>
    <a class="nav_buttons row CLEARANCE" href="<?= BASE_PATH?>/clearance">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/clearance.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="CLEARANCE"></a>
    <a class="nav_buttons row ACCOUNT" href="<?= BASE_PATH?>/account">
      <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/User.png" alt="" class="nav_button_icons"> </div>
      <input type="button" value="ACCOUNT"></a>
    

    <?php if($_SESSION["role"] == "registrar"): ?>
      <a class="nav_buttons row REGISTRATION" href="<?= BASE_PATH?>/registration">
        <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/User.png" alt="" class="nav_button_icons"> </div>
        <input type="button" value="REGISTRATION"></a>
    <?php endif ?>

    <hr>
    <form method="post" action="<?= BASE_PATH?>/logout" >
      <button type="submit" name="" class="nav_buttons row logout_button"> 
        <div class="nav_icon_holder row"><img src="<?= BASE_PATH?>/View/resources/Logout.png" alt="" class="nav_button_icons"></div><div>LOG OUT</div> 
      </button>
    </form>
  </div>



  
</div>
</div>