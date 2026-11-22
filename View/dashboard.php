<?php
require_once __DIR__ . "/../PageLogic/dashboard_logic.php";


?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH?>/View/style/clearance.css">
</head>

<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>
      <main class="column scrollbar" style="gap:20px" >

        <?php if(strtoupper($_SESSION['role']) == 'STUDENT'): ?>
          <?php $projects = getAllProjectStatus()?>
          <div class="row" style="gap:20px" >
            <div style="gap:20px" class="column">
              <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; text-decoration:none; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">
                    PROJECTS
                  </b>
                  <a href="<?= BASE_PATH ?>/projects " class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style=" font-size:25px">View </b>
                    
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                
                <div>
                  <hr>
                </div>
                <?php //var_dump($subject_clearance_status) ?>
                <div  class="column scrollbar" style="overflow: auto;">
                  <?php foreach($projects as $subject): ?>
                  <div class="row" style="justify-content: space-between;">
                    <b>
                      <?= $subject['project_name'] ?>
                    </b>
                    <b style="<?php 
                      if(strtolower($subject['submission_status']) == 'submitted') echo 'color : green';
                      elseif(strtolower($subject['submission_status']) == 'overdue') echo 'color : red';
                      else  echo 'color : yellow'?>"
                    >
                      <?= $subject['submission_status'] ?>
                    </b>
                    
                  </div>
                <?php endforeach ?>
                </div>
                
                
              </div>

              <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; text-decoration:none; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">
                    CLEARANCE
                  </b>
                  <a href="<?= BASE_PATH ?>/clearance " class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style=" font-size:25px">View </b>
                    
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <div><hr></div>
                <div class="column scrollbar" style="overflow: auto; gap:10px">
                  <?php if (!$updates): ?>
                  <b>No clearance updates yet.</b>
                  <?php else: foreach ($updates as $u):
                        $s = strtolower($u['status']); ?>
                    <div class="row" style="justify-content: space-between;">
                      <b><?= htmlspecialchars(strtoupper($u['category'])) ?> - <?= htmlspecialchars($u['item_name']) ?></b>
                      <b style="color: <?= $colors[$s] ?? '#e6c200' ?>"><?= htmlspecialchars($s) ?></b>
                    </div>
                  <?php endforeach; endif ?>
                </div>
              </div>
              <?php $events = getStudentEvents(); ?>

              <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; overflow-y:auto; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">EVENTS</b>
                  <a href="<?= BASE_PATH ?>/attendance" class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style="font-size:25px">View</b>
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <div><hr></div>

                <?php if (!$events): ?>
                  <b>No upcoming events.</b>
                <?php else: foreach ($events as $ev):
                    $start = strtotime($ev['datetime_Start']);
                    $end   = $ev['datetime_End'] ? strtotime($ev['datetime_End']) : null;
                    $ongoing = strtoupper($ev['status']) === 'ACTIVE' && $start <= time() && (!$end || $end >= time());
                ?>
                  <div class="row" style="justify-content:space-between; margin-bottom:10px;">
                    <div class="column">
                      <b><?= htmlspecialchars($ev['eventName']) ?></b>
                      <span>
                        <?= date('M j, g:i A', $start) ?><?= $end ? ' - ' . date('g:i A', $end) : '' ?>
                      </span>
                    </div>
                    <b style="color: <?= $ev['attended'] ? 'green' : ($ongoing ? '#e6c200' : 'white') ?>">
                      <?= $ev['attended'] ? 'attended' : ($ongoing ? 'ongoing' : 'upcoming') ?>
                    </b>
                  </div>
                <?php endforeach; endif ?>
              </div>   
              
            </div>
            <div style="gap:20px" class="column">
               <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; overflow-y:auto; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">TODAY'S CLASSES</b>
                  
                  <a href="<?= BASE_PATH ?>/class-sched " class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style=" font-size:25px">View </b>
                    
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <b><?= date('D, M j') ?></b>
                <div><hr></div>
                <?php //var_dump($todays); ?>

                <?php if (count($schedule) < 1): ?>
                  <b>No schedule found for this term.</b>
                <?php elseif (!$todays): ?>
                  <b>No classes today.</b>
                <?php else: foreach ($todays as $c): ?>
                  <div class="row" style="justify-content: space-between; margin-bottom:10px;">
                    <div class="column">
                      <b><?= htmlspecialchars($c['subjectID']) ?> - <?= htmlspecialchars($c['name']) ?></b>
                      <span><?= htmlspecialchars($c['prof_name'] ?? 'TBA') ?> · Room <?= htmlspecialchars($c['roomID']) ?></span>
                    </div>
                    <b><?= date('g:i A', strtotime($c['time_start'])) ?> - <?= date('g:i A', strtotime($c['time_end'])) ?></b>
                  </div>
                <?php endforeach; endif ?>
              </div> 
              
              <?php $open = getOpenEnrollments(); ?>

              <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; overflow-y:auto; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">ENROLLMENT</b>
                  <a href="<?= BASE_PATH ?>/enrollment" class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style="font-size:25px">View</b>
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <div><hr></div>

                <?php if (!$open): ?>
                  <b>No open enrollment right now.</b>
                <?php else: foreach ($open as $e): ?>
                  <div class="row" style="justify-content: space-between; align-items:center; margin-bottom:10px;">
                    <div class="column">
                      <b><?= htmlspecialchars($e['acad_year_sem']) ?></b>
                      <span>Until <?= date('M j, Y', strtotime($e['close_date'])) ?></span>
                    </div>
                    <b style="color: <?= $e['already_enrolled'] ? 'green' : '#e6c200' ?>">
                      <?= $e['already_enrolled'] ? 'enrolled' : 'not yet enrolled' ?>
                    </b>
                  </div>
                <?php endforeach; endif ?>
              </div>
            </div> 
                  
          </div>


        <?php else: ?>
          <div class="row" style="gap:20px">
            <div style="gap:20px" class="column">
              <?php if(!in_array($_SESSION['role'],['registrar'])): ?>
                <?php //var_dump($schedule); ?>
                <div class="column drop_shadow" style="padding:30px; border-radius:30px; width:500px; max-height:400px; overflow-y:auto; background: rgba(40,40,40,0.3);">
                  <div class="row" style="align-items:center; justify-content:space-between">
                    <b style="font-size:30px; font-weight:bold;">TODAY'S CLASSES</b>
                    <a href="<?= BASE_PATH ?>/class-sched " class="row" style="align-items:center; text-decoration:none; gap:10px">
                      <b style=" font-size:25px">View </b>
                      
                      <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                    </a>
                    
                  </div>
                  <b><?= date('D, M j') ?></b>
                  <div><hr></div>
                  <?php if (!$schedule): ?>
                    <b>No schedule found for this term.</b>
                  <?php elseif (!$todays): ?>
                    <b>No classes today.</b>
                  <?php else: foreach ($todays as $c): ?>
                    <div class="row" style="justify-content:space-between; margin-bottom:10px;">
                      <div class="column">
                        <b><?= htmlspecialchars($c['subjectID']) ?> - <?= htmlspecialchars($c['block']) ?></b>
                        <span><?= htmlspecialchars($c['name']) ?> · Room <?= htmlspecialchars($c['roomID']) ?></span>
                      </div>
                      <b><?= date('g:i A', strtotime($c['time_start'])) ?> - <?= date('g:i A', strtotime($c['time_end'])) ?></b>
                    </div>
                  <?php endforeach; endif ?>
                </div>

              <?php endif; $events = getFacultyEvents(); ?>

              <div class="column drop_shadow" style="padding: 30px; border-radius:30px; width:500px; max-height:400px; overflow-y:auto; background: rgba(40, 40, 40, 0.3);">
                <div class="row" style="align-items:center; justify-content:space-between">
                  <b style="font-size: 30px; font-weight: bold;">EVENTS</b>
                  <a href="<?= BASE_PATH ?>/attendance" class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style="font-size:25px">View</b>
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <div><hr></div>

                <?php if (!$events): ?>
                  <b>No upcoming events.</b>
                <?php else: foreach ($events as $ev):
                    $start = strtotime($ev['datetime_Start']);
                    $end   = $ev['datetime_End'] ? strtotime($ev['datetime_End']) : null;
                ?>
                  <div class="row" style="justify-content:space-between; margin-bottom:10px;">
                    <div class="column">
                      <b><?= htmlspecialchars($ev['eventName']) ?></b>
                      <span><?= date('M j, g:i A', $start) ?><?= $end ? ' - ' . date('g:i A', $end) : '' ?></span>
                      <?php if ($ev['coordinator_name']): ?>
                        <span>Coordinator: <?= htmlspecialchars($ev['coordinator_name']) ?></span>
                      <?php endif ?>
                    </div>
                    <b style="color:#e6c200"><?= (int)$ev['attendees'] ?> attended</b>
                  </div>
                <?php endforeach; endif ?>
              </div>
            </div>            
            <div class="column" style="gap:20px">
              <div class="column drop_shadow" style="padding:30px; border-radius:30px; width:500px; background: rgba(40,40,40,0.3);">
                <div class="row" style="align-items:center; justify-content:space-between">
                  <b style="font-size:30px; font-weight:bold;">TO CLEAR</b>
                  <a href="<?= BASE_PATH ?>/clearance " class="row" style="align-items:center; text-decoration:none; gap:10px">
                    <b style=" font-size:25px">View </b>
                    
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>
                </div>
                <div><hr></div>
                <div class="row" style="justify-content:space-between"><b>Subjects</b><b style="color:#e6c200"><?= $toClear['subjects'] ?></b></div>
                <div class="row" style="justify-content:space-between"><b>Faculty clearance</b><b style="color:#e6c200"><?= $toClear['faculty'] ?></b></div>
                <?php if ($toClear['has_office']): ?>
                  <div class="row" style="justify-content:space-between"><b>Office clearance</b><b style="color:#e6c200"><?= $toClear['office'] ?></b></div>
                <?php endif ?>
              </div>
              <?php if(!in_array($_SESSION['role'],['registrar'])): ?>      
                <div class="column drop_shadow" style="padding:30px; border-radius:30px; width:500px; background: rgba(40,40,40,0.3);">
                  <div class="row" style="align-items:center; justify-content:space-between">
                    <b style="font-size:30px; font-weight:bold;">PROJECTS</b>
                    <a href="<?= BASE_PATH ?>/projects " class="row" style="align-items:center; text-decoration:none; gap:10px">
                      <b style=" font-size:25px">View </b>
                      
                      <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                    </a>
                  </div>
                  <div><hr></div>
                  <div class="row" style="justify-content:space-between"><b>Submitted</b><b style="color:green"><?= $proj['submitted'] ?></b></div>
                  <div class="row" style="justify-content:space-between"><b>Waiting</b><b style="color:#e6c200"><?= $proj['waiting'] ?></b></div>
                  <div class="row" style="justify-content:space-between"><b>Overdue</b><b style="color:red"><?= $proj['overdue'] ?></b></div>
                </div>
              <?php endif ?>
            </div>
          </div>
        <?php endif ?>
      </main>
    </div>
  </div>


<?php require_once __DIR__ . "/components/logout.php"; ?>
</body>

</html>