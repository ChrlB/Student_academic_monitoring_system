<?php
  require_once __DIR__."/../PageLogic/attendance_logic.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS</title>
<link rel="stylesheet" href="<?= BASE_PATH?>/View/style/general.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH?>/View/style/attendance.css">
</head>

<body>
  <?php require_once __DIR__."/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__."/components/navigation.php"; ?>
    <div class="column" style="flex: 1; min-width: 0;">
      <?php require_once __DIR__."/components/header.php"; ?>
      <main class="column scrollbar att-main">
<div class="att<?= $attGroup === 'student' ? ' student' : '' ?>" id="att">
<?php if ($attGroup === 'student'): /* =============== STUDENT VIEW (read-only) =============== */ ?>
  <section class="view">
    <div class="detail-head">
      <div class="detail-title"><?= e("{$me['id']}, {$me['name']}, {$me['dept']}, {$me['course']}") ?></div>
    </div>

    <div class="summary">
      <div class="sbox"><span class="lbl">No. Events</span><span class="val"><?= count($recs) ?></span></div>
      <div class="sbox"><span class="lbl">Presents / Absents</span><span class="val"><?= $ontime + $late ?> / <?= $absent ?></span></div>
      <div class="sbox"><span class="lbl">On-time / Late</span><span class="val"><?= $ontime ?> / <?= $late ?></span></div>
      <div class="sbox"><span class="lbl">Total Hours</span><span class="val"><?= $hrs ?><small>hrs</small></span></div>
    </div>

    <div class="toolbar detail-toolbar">
      <?php sa_dropdown('Events', 'All events', $ATT_EVENT_NAMES, $sa_event, 'event'); ?>
      <?php sa_dropdown('Year', 'All years', $sa_years, $sa_year, 'year'); ?>
    </div>

    <div class="rec-box">
      <div class="rec-scroll">
        <table class="rec">
          <thead><tr>
            <th class="sel"></th><th>Event</th><th>Date</th><th>Time in / Time out</th><th>Status</th><th>Instructor</th><th>Hours</th>
          </tr></thead>
          <tbody>
          <?php foreach ($recs as $r): $cls = ['ON-TIME' => 'st-ontime', 'LATE' => 'st-late', 'ABSENT' => 'st-absent'][$r['status']]; ?>
            <tr>
              <td class="sel"></td>
              <td><?= e($r['event']) ?></td>
              <td><?= e($r['date']) ?></td>
              <td><?= $r['tin'] === '--' ? '--' : e($r['tin'] . ' - ' . $r['tout']) ?></td>
              <td class="<?= $cls ?>"><?= e($r['status']) ?></td>
              <td><?= e($r['who']) ?></td>
              <td><?= $r['hours'] ?: '--' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!$recs): ?><p class="empty">No records for this filter.</p><?php endif; ?>
      </div>
    </div>
  </section>

<?php elseif (!$stu): /* =============== LIST VIEW =============== */ ?>
  <section class="view">
    <div class="toolbar">
      <?php att_dropdown('Department', 'All departments', array_keys($ATT_DEPT_COURSES), $dept, 'dept'); ?>
      <?php att_dropdown('Course', 'All courses', $dept ? ATT_DEPT_COURSES[$dept] : $allCourses, $course, 'course'); ?>
      <form class="search" method="get" action="">
        <?php foreach (['dept' => $dept, 'course' => $course, 'view' => $view === 'list' ? 'list' : null] as $k => $v): if ($v): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endif; endforeach; ?>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="SEARCH" autocomplete="off" aria-label="Search students">
        <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></button>
      </form>
      <div class="toggle" role="group" aria-label="View mode">
        <a href="<?= e(att_url(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'active' : '' ?>" title="List view" aria-label="List view">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg></a>
        <a href="<?= e(att_url(['view' => null])) ?>" class="<?= $view === 'grid' ? 'active' : '' ?>" title="Grid view" aria-label="Grid view">
          <svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg></a>
      </div>
      <span class="count"><?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>

    <div class="content-box"><div class="scroll">
      <?php if (!$rows): ?>
        <p class="empty">No students match your filters.</p>
      <?php elseif ($view === 'grid'): ?>
        <div class="grid">
          <?php $i = 0; foreach ($rows as $s): ?>
            <a class="card" href="<?= e(att_url(['student' => $s['id']])) ?>" title="<?= e($s['name']) ?> – view attendance" style="animation-delay: <?= min($i++, 20) * 12 ?>ms">
              <span class="avatar"><?= e($s['initials']) ?></span><b><?= e($s['id']) ?></b><span><?= e($s['dept']) ?></span><span><?= e($s['course']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>STUDENT ID</th><th>Department</th><th>Course</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $s): $href = e(att_url(['student' => $s['id']])); ?>
            <tr class="clickable" title="<?= e($s['name']) ?> – view attendance">
              <td><a href="<?= $href ?>"><?= e($s['id']) ?></a></td>
              <td><a href="<?= $href ?>"><?= e($s['dept']) ?></a></td>
              <td><a href="<?= $href ?>"><?= e($s['course']) ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div></div>
  </section>

<?php else: /* =============== DETAIL VIEW =============== */ ?>
  <section class="view">
    <div class="detail-head">
      <a class="back-btn" href="<?= e(att_url(['student' => null, 'event' => null, 'year' => null])) ?>" title="Back to attendance list">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>Back
      </a>
      <div class="detail-title"><?= e("{$stu['id']}, {$stu['name']}, {$stu['dept']}, {$stu['course']}") ?></div>
    </div>

    <div class="summary">
      <div class="sbox"><span class="lbl">No. Events</span><span class="val"><?= count($recs) ?></span></div>
      <div class="sbox"><span class="lbl">Presents / Absents</span><span class="val"><?= $ontime + $late ?> / <?= $absent ?></span></div>
      <div class="sbox"><span class="lbl">On-time / Late</span><span class="val"><?= $ontime ?> / <?= $late ?></span></div>
      <div class="sbox"><span class="lbl">Total Hours</span><span class="val"><?= $hrs ?><small>hrs</small></span></div>
    </div>

    <div class="toolbar detail-toolbar">
      <?php att_dropdown('Events', 'All events', $ATT_EVENT_NAMES, $event, 'event'); ?>
      <?php att_dropdown('Year', 'All years', $years, $year, 'year'); ?>
    </div>

    <form class="rec-box" method="post" action="<?= e(att_url()) ?>">
      <input type="hidden" name="att_action" value="delete">
      <div class="rec-scroll">
        <table class="rec">
          <thead><tr>
            <th class="sel"></th><th>Event</th><th>Date</th><th>Time in / Time out</th><th>Status</th><th>Instructor</th><th>Hours</th>
          </tr></thead>
          <tbody>
          <?php foreach ($recs as $r): $cls = ['ON-TIME' => 'st-ontime', 'LATE' => 'st-late', 'ABSENT' => 'st-absent'][$r['status']]; ?>
            <tr>
              <td class="sel"><input type="checkbox" class="rd" name="ids[]" value="<?= e($r['id']) ?>" aria-label="Select <?= e($r['event']) ?>"></td>
              <td><?= e($r['event']) ?></td>
              <td><?= e($r['date']) ?></td>
              <td><?= $r['tin'] === '--' ? '--' : e($r['tin'] . ' - ' . $r['tout']) ?></td>
              <td class="<?= $cls ?>"><?= e($r['status']) ?></td>
              <td><?= e($r['who']) ?></td>
              <td><?= $r['hours'] ?: '--' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!$recs): ?><p class="empty">No records for this filter.</p><?php endif; ?>
      </div>
      <div class="rec-actions">
        <button type="submit" class="icon-btn del" title="Delete selected" aria-label="Delete selected">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 7h14l-1.2 13a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8zM9 3h6l1 2H8z"/></svg>
        </button>
      </div>
    </form>
  </section>
<?php endif; ?>
</div>
      </main>
    </div>
  </div>
</body>
</html>
