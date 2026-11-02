<?php 
require_once __DIR__.'/../../Services/auth.php';
$page = $_SESSION["page"] ?? "";
?>
<body data-page="<?= htmlspecialchars($page) ?>"> 


<div style="display: flex; flex-direction:row; ">
 
<div class="column navbar scrollbar">

  <img src="/View/resources/logo1.png" alt="" class="nav_logo">
  
  <div class="buttons_section">
    
    
    <a class="nav_buttons row DASHBOARD" href="/dashboard">
      <div class="nav_icon_holder row"><img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> </div>
      <input type="button" value="DASHBOARD"></a>
    <a class="nav_buttons row SCHEDULE" href="/class-sched">
      <div class="nav_icon_holder row"><img src="/View/resources/Calendar.png" alt="" class="nav_button_icons"> </div>
      <input type="button" value="SCHEDULE"></a>
    <a class="nav_buttons row GRADES" href="/grades">
      <div class="nav_icon_holder row"><img src="/View/resources/Grades.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="GRADES"></a>

    <a class="nav_buttons row ATTENDANCE"  href="/attendance">
      <div class="nav_icon_holder row"><img src="/View/resources/Attendance.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="ATTENDANCE"></a>
    <a class="nav_buttons row ENROLLMENT" href="/enrollment">
      <div class="nav_icon_holder row"><img src="/View/resources/enrollment.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="ENROLLMENT"></a>
    <a class="nav_buttons row PROJECTS" href="/projects">
      <div class="nav_icon_holder row"><img src="/View/resources/Project.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="PROJECTS"></a>

    <a class="nav_buttons row SYLLABUS" href="/syllabus">
      <div class="nav_icon_holder row"><img src="/View/resources/Syllabus.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="SYLLABUS"></a>
    <a class="nav_buttons row CLEARANCE" href="/clearance">
      <div class="nav_icon_holder row"><img src="/View/resources/clearance.png" alt="" class="nav_button_icons"></div>
      <input type="button" value="CLEARANCE"></a>
    <a class="nav_buttons row ACCOUNT" href="/account">
      <div class="nav_icon_holder row"><img src="/View/resources/User.png" alt="" class="nav_button_icons"> </div>
      <input type="button" value="ACCOUNT"></a>
    <hr>
    <form method="post" action="/logout" >
      <button type="submit" name="" class="nav_buttons row logout_button"> 
        <div class="nav_icon_holder row"><img src="/View/resources/Logout.png" alt="" class="nav_button_icons"></div><div>LOG OUT</div> 
      </button>
    </form>
  </div>



  
</div>
</div>