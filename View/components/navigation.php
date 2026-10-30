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
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="DASHBOARD"></a>
    <a class="nav_buttons row SCHEDULE" href="/class-sched">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="SCHEDULE"></a>
    <a class="nav_buttons row GRADES" href="/grades">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="GRADES"></a>

    <a class="nav_buttons row ATTENDANCE"  href="/attendance">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="ATTENDANCE"></a>
    <a class="nav_buttons row ENROLLMENT" href="/enrollment">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="ENROLLMENT"></a>
    <a class="nav_buttons row PROJECTS" href="/projects">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="PROJECTS"></a>

    <a class="nav_buttons row SYLLABUS" href="/syllabus">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="SYLLABUS"></a>
    <a class="nav_buttons row CLEARANCE" href="/clearance">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="CLEARANCE"></a>
    <a class="nav_buttons row ACCOUNT" href="/account">
      <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> <input type="button" value="ACCOUNT"></a>

    <form method="post" action="/logout" style="padding: 0px;">
      <button type="submit" name="" class="nav_buttons row logout_button"> 
        <img src="/View/resources/View Quilt.png" alt="" class="nav_button_icons"> LOG OUT
      </button>
    </form>
  </div>



  
</div>
</div>