<?php
# Include attendance page logic
require_once __DIR__ . "/../PageLogic/attendance_logic.php";

# Temporary flag: reserve Department filter for Super Admin while DB setup is pending
$attIsSuperAdmin = (($attPos ?? $GLOBALS['attPos'] ?? '') === 'admin');
$eventPrograms   = $ATT_PROGRAMS ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Page meta tags, favicon, and stylesheet links -->
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Attendance</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/attendance.css">
  <script src="https://unpkg.com/html5-qrcode@2.3.8" type="text/javascript"></script>
</head>

<body>
  <!-- Global flash/popup message component -->
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>

  <!-- Main application shell with sidebar navigation, header, and dynamic attendance content -->
  <div class="row main_box">
    <!-- Sidebar navigation component -->
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1; min-width: 0;">
      <!-- Top header bar component -->
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <!-- Main attendance workspace container -->
      <main class="column scrollbar att-main">
        <div class="att<?= $attGroup === 'student' ? ' student' : '' ?>"
             id="att"
             data-qr="<?= e(BASE_PATH) ?>/attendance-qr"
             data-qr-icon="<?= BASE_PATH ?>/View/resources/qr.png"
             data-can-qr="<?= ($attGroup === 'student' || $attCanScan) ? '1' : '0' ?>"
             <?= !$attCanList ? ' data-back="' . e(BASE_PATH) . '/dashboard"' : '' ?>
             <?= in_array($attPos, ['faculty'], true) ? ' data-no-back="1"' : '' ?>>

          <?php if ($evpage): /* =============== EVENTS PAGE =============== */ ?>
          <!-- Events management toolbar and event rows -->
          <section class="view">
            <!-- Toolbar with Add Events button for permitted roles -->
            <div class="toolbar">
              <?php if ($attCanEdit): ?>
                <button type="button" class="ev-btn" id="ev-add"><span class="ev-dot"></span>ADD EVENTS</button>
              <?php endif; ?>
            </div>

            <!-- Scrollable list of visible events -->
            <div class="content-box"><div class="scroll">
              <?php if (!$events): ?>
                <p class="empty">No events yet.</p>
              <?php else: foreach ($events as $ev): ?>
                <div class="ev-row" role="button" tabindex="0"
                     title="Click to view"
                     data-id="<?= (int)$ev['id'] ?>"
                     data-name="<?= e($ev['name']) ?>"
                     data-date="<?= e($ev['ymd']) ?>"
                     data-start="<?= e($ev['start24']) ?>"
                     data-end="<?= e($ev['end24']) ?>"
                     data-status="<?= e($ev['status']) ?>"
                     data-program="<?= e($ev['program']) ?>"
                     data-coord="<?= e($ev['coord']) ?>"
                     data-remark="<?= e($ev['remark'] ?? '') ?>"
                     data-att="<?= (int)$ev['att'] ?>"
                     data-edit="<?= $ev['canEdit'] ? '1' : '0' ?>"
                     data-review="<?= !empty($ev['review']) ? '1' : '0' ?>">
                  <span class="ev-name"><?= e($ev['name']) ?></span>
                  <span><?= e($ev['scope']) ?></span>
                  <span><?= e($ev['date']) ?></span>
                  <span><?= e($ev['start']) ?></span>
                  <span><?= e($ev['end']) ?></span>
                  <span class="ev-st st-<?= e(strtolower($ev['shown'])) ?>"><?= e(ucfirst(strtolower($ev['shown']))) ?></span>
                </div>
              <?php endforeach; endif; ?>
            </div></div>
          </section>

          <!-- Add / Edit / View / Review event modal dialog -->
          <dialog class="ev-dlg" id="ev-dlg"
                  data-dean="<?= !empty($attIsDean) ? '1' : '0' ?>"
                  data-my-dept="<?= e($attMyDept ?? '') ?>"
                  data-me="<?= e($ATT_ME_NAME ?? '') ?>"
                  data-my-program="<?= e(in_array($attMyProgram, $eventPrograms, true) ? $attMyProgram : '') ?>">
            <form method="post" action="<?= e(att_url()) ?>" id="ev-form" autocomplete="off">
              <input type="hidden" name="att_action" id="ev-action" value="add_event">
              <input type="hidden" name="eventID" id="ev-id" value="">
              <input type="hidden" name="events" value="1">
              <div class="ev-head">
                <h2 id="ev-title">ADD EVENT</h2>
                <button type="button" class="ev-b ok ev-top-edit" id="ev-edit" hidden>Edit</button>
              </div>

              <!-- Event name input -->
              <label class="ev-field"><span>EVENT NAME</span>
                <input type="text" name="eventName" id="ev-name" placeholder="eg.CCS WEEK" maxlength="250" required></label>

              <!-- Event date and status fields -->
              <div class="ev-two">
                <label class="ev-field"><span>DATE</span>
                  <input type="date" name="date" id="ev-date" required></label>
                <label class="ev-field"><span>STATUS</span>
                  <select name="status" id="ev-status" required>
                    <?php foreach (ATT_EVENT_STATUSES as $st): ?><option value="<?= e($st) ?>"><?= e($st) ?></option><?php endforeach; ?>
                  </select></label>
              </div>

              <!-- Event start time, end time, and target program/department -->
              <div class="ev-three">
                <label class="ev-field"><span>START</span>
                  <input type="time" name="start" id="ev-start" value="08:00" required></label>
                <label class="ev-field"><span>END</span>
                  <input type="time" name="end" id="ev-end" value="18:00" required></label>
                <label class="ev-field"><span>PROGRAM / COLLEGE</span>
                  <select name="program" id="ev-program">
                    <option value=""><?= e($ATT_ALL_LABEL ?? 'ALL PROGRAMS') ?></option>
                    <?php foreach ($eventPrograms as $pg): ?><option value="<?= e($pg) ?>"><?= e($pg) ?></option><?php endforeach; ?>
                  </select></label>
              </div>

              <!-- Searchable/selectable event coordinator combobox -->
              <div class="ev-field"><span>EVENT COORDINATOR</span>
                <div class="ev-combo" id="ev-combo">
                  <input type="text" name="coordinator" id="ev-coord" placeholder="ex. Mr. Johniel Zar Mendoza" autocomplete="off"
                         role="combobox" aria-expanded="false" aria-controls="ev-coord-list" aria-autocomplete="list" aria-label="Event coordinator">
                  <button type="button" class="ev-caret" id="ev-caret" tabindex="-1" aria-label="Show coordinators"></button>
                  <ul class="ev-list" id="ev-coord-list" role="listbox" hidden>
                    <?php foreach (($ATT_FACULTY_NAMES ?? []) as $fn): ?><li role="option" data-v="<?= e($fn) ?>"><?= e($fn) ?></li><?php endforeach; ?>
                  </ul>
                </div></div>

              <!-- Remark: required when a coordinator/faculty changes an event; shown read-only to the dean when reviewing -->
              <label class="ev-field" id="ev-remark-wrap" hidden><span>REMARK (WHY IS THIS EVENT BEING CHANGED?)</span>
                <textarea name="remark" id="ev-remark" rows="3" maxlength="500" placeholder="eg. Venue is not available, moved to the next day"></textarea></label>

              <!-- Dean approval notice for non-dean submissions -->
              <p class="ev-note" id="ev-note" hidden>This event will be sent to the dean for approval.</p>

              <!-- Modal action buttons -->
              <div class="ev-btns">
                <button type="button" class="ev-b del" id="ev-delete" hidden>Delete</button>
                <button type="button" class="ev-b cancel" id="ev-cancel">Cancel</button>
                <button type="button" class="ev-b rej" id="ev-reject" hidden>Reject</button>
                <button type="button" class="ev-b ok" id="ev-approve" hidden>Approve</button>
                <button type="submit" class="ev-b ok" id="ev-ok">Confirm</button>
              </div>
            </form>
          </dialog>

          <?php elseif ($attGroup === 'student'): /* =============== STUDENT VIEW (read-only) =============== */ ?>
          <!-- Student personal attendance summary, scoped filters, and logs -->
          <section class="view">
            <!-- Student identity header -->
            <div class="detail-head">
              <div class="detail-title"><?= e("{$me['id']}, {$me['name']}, {$me['dept']}, {$me['course']}") ?></div>
            </div>

            <!-- Attendance summary cards -->
            <div class="summary">
              <div class="sbox"><span class="lbl">No. Events</span><span class="val"><?= count($recs) ?></span></div>
              <div class="sbox"><span class="lbl">Presents / Absents</span><span class="val"><?= $ontime + $late ?> / <?= $absent ?></span></div>
              <div class="sbox"><span class="lbl">On-time / Late</span><span class="val"><?= $ontime ?> / <?= $late ?></span></div>
              <div class="sbox"><span class="lbl">Total Hours</span><span class="val"><?= $hrs ?><small>hrs</small></span></div>
            </div>

            <!-- Student-scoped event and year filter dropdowns -->
            <div class="toolbar detail-toolbar">
              <?php sa_dropdown('Events', 'All events', $sa_events ?? [], $sa_event, 'event'); ?>
              <?php sa_dropdown('Year', 'All years', $sa_years ?? [], $sa_year, 'year'); ?>
            </div>

            <!-- Read-only student attendance records table -->
            <div class="rec-box">
              <div class="rec-scroll">
                <table class="rec">
                  <thead><tr>
                    <th class="sel"></th><th>Event</th><th>Date</th><th>Time in / Time out</th><th>Status</th><th>Instructor</th><th>Hours</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($recs as $r): $cls = ['ON-TIME' => 'st-ontime', 'LATE' => 'st-late', 'ABSENT' => 'st-absent'][$r['status']] ?? ''; ?>
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
          <!-- Faculty student directory with program/course filters, search, and view toggle -->
          <section class="view">
            <!-- Directory filter, search, layout toggle, and events navigation toolbar -->
            <div class="toolbar">
              <?php if ($attIsSuperAdmin): /* Temporary: Department filter reserved for Super Admin while DB setup is pending */ ?>
                <?php att_dropdown('Department', 'All departments', array_keys($ATT_DEPT_COURSES), $dept, 'dept'); ?>
              <?php endif; ?>
              <?php if (!$attScoped): /* Coordinator is scoped to one program; Dean/Admin can filter by program/course */ ?>
                <?php att_dropdown('Course', 'All courses', $dept ? $ATT_DEPT_COURSES[$dept] : $allCourses, $course, 'course'); ?>
              <?php endif; ?>

              <!-- Student search form preserving active filters -->
              <form class="search" method="get" action="">
                <?php foreach (['dept' => $dept, 'course' => $course, 'view' => $view === 'list' ? 'list' : null] as $k => $v): if ($v): ?>
                  <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endif; endforeach; ?>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="SEARCH" autocomplete="off" aria-label="Search students">
                <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></button>
              </form>

              <!-- Grid vs. list view switcher -->
              <div class="toggle" role="group" aria-label="View mode">
                <a href="<?= e(att_url(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'active' : '' ?>" title="List view" aria-label="List view">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg></a>
                <a href="<?= e(att_url(['view' => null])) ?>" class="<?= $view === 'grid' ? 'active' : '' ?>" title="Grid view" aria-label="Grid view">
                  <svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg></a>
              </div>

              <!-- Link to events management view and matching student counter -->
              <a class="ev-btn ev-link" href="<?= e(att_url(['events' => 1])) ?>"><span class="ev-dot"></span>EVENTS</a>
              <span class="count"><?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?></span>
            </div>

            <!-- Student cards (grid) or rows (list) container -->
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
          <!-- Faculty detailed student attendance view with filters and bulk deletion -->
          <section class="view">
            <!-- Back navigation and selected student header -->
            <div class="detail-head">
              <a class="back-btn" href="<?= e(att_url(['student' => null, 'event' => null, 'year' => null])) ?>" title="Back to attendance list">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>Back
              </a>
              <div class="detail-title"><?= e("{$stu['id']}, {$stu['name']}, {$stu['dept']}, {$stu['course']}") ?></div>
            </div>

            <!-- Selected student attendance summary cards -->
            <div class="summary">
              <div class="sbox"><span class="lbl">No. Events</span><span class="val"><?= count($recs) ?></span></div>
              <div class="sbox"><span class="lbl">Presents / Absents</span><span class="val"><?= $ontime + $late ?> / <?= $absent ?></span></div>
              <div class="sbox"><span class="lbl">On-time / Late</span><span class="val"><?= $ontime ?> / <?= $late ?></span></div>
              <div class="sbox"><span class="lbl">Total Hours</span><span class="val"><?= $hrs ?><small>hrs</small></span></div>
            </div>

            <!-- Event and year filter dropdowns -->
            <div class="toolbar detail-toolbar">
              <?php att_dropdown('Events', 'All events', $ATT_EVENT_NAMES, $event, 'event'); ?>
              <?php att_dropdown('Year', 'All years', $years, $year, 'year'); ?>
            </div>

            <!-- Attendance records table with optional bulk delete controls -->
            <form class="rec-box" method="post" action="">
              <input type="hidden" name="att_action" value="delete">
              <div class="rec-scroll">
                <table class="rec">
                  <thead><tr>
                    <th class="sel"></th><th>Event</th><th>Date</th><th>Time in / Time out</th><th>Status</th><th>Instructor</th><th>Hours</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($recs as $r): $cls = ['ON-TIME' => 'st-ontime', 'LATE' => 'st-late', 'ABSENT' => 'st-absent'][$r['status']] ?? ''; ?>
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

              <!-- Bulk delete submit button for authorized editors -->
              <?php if ($attCanEdit): ?>
              <div class="rec-actions">
                <button type="submit" class="icon-btn del" title="Delete selected" aria-label="Delete selected">
                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 7h14l-1.2 13a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8zM9 3h6l1 2H8z"/></svg>
                </button>
              </div>
              <?php endif; ?>
            </form>
          </section>
          <?php endif; ?>
        </div>
      </main>
    </div>
  </div>

  <!-- QR Modal Overlay -->
  <div id="qrModal" class="qr-modal-overlay" style="display: none;">
    <div class="qr-modal-content">
      <div class="qr-modal-header">
      </div>

      <div class="qr-scanner-box">
        <?php if ($attGroup === 'student'): ?>
          <!-- =============== STUDENT VIEW: the QR stored in the database =============== -->
          <style>
            #student-qrcode img, #student-qrcode canvas { width: 160px; height: 160px; }
          </style>
          <div class="qr-large-icon" id="student-qrcode"
               data-id="<?= e($me['id'] ?? '') ?>" data-csrf="<?= e(csrfToken()) ?>"
               style="display: flex; justify-content: center; align-items: center; background: #fff; padding: 10px; border-radius: 8px; width: fit-content; margin: 0 auto 15px auto;">
            <?php if (!empty($attHasQr)): ?>
              <img src="<?= e(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?att_qr=1" alt="My QR Code">
            <?php endif; ?>
          </div>

          <h3>Your Student QR Code</h3>
          <p class="qr-subtext"><?= e($me['id'] ?? '') ?></p>

          <?php if (empty($attHasQr)): ?>
          <!-- First time: draw the QR here, then keep a copy in students.qr_image_data (next time it comes from the database) -->
          <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
          <script>
            document.addEventListener("DOMContentLoaded", function () {
              var box = document.getElementById("student-qrcode");
              if (!box || !box.dataset.id || typeof QRCode === "undefined") return;

              new QRCode(box, { text: box.dataset.id, width: 300, height: 300,
                                colorDark: "#000000", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H });

              var canvas = box.querySelector("canvas");
              if (!canvas) return;
              fetch(window.location.pathname, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ att_action: "save_qr", csrf_token: box.dataset.csrf, qr: canvas.toDataURL("image/png") })
              }).catch(function () {});
            });
          </script>
          <?php endif; ?>

          <div class="qr-modal-actions" style="margin-top: 20px;">
            <button type="button" class="qr-btn" id="qrCancel">Close</button>
          </div>

        <?php elseif (!empty($attCanScan)): ?>
          <!-- =============== SCANNER VIEW (faculty, coordinator, dean): Camera Scanner & Database Form =============== -->
          <form id="qrAttendanceForm" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
          <input type="hidden" name="att_action" value="scan">

          <?php if (empty($ATT_OPEN_EVENTS)): ?>
              <!-- BLOCKED STATE: no ACTIVE event is inside its scan window -->
              <div style="width: 100%; padding: 40px 20px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;">
                  <div style="color: rgba(255, 255, 255, 0.85); font-size: 16px; font-weight: 600; letter-spacing: 0.3px;">
                      There's no event to record.
                  </div>
              </div>
              <div class="qr-modal-actions" style="width: 100%; max-width: 280px; margin-top: 10px;">
                  <button type="button" class="qr-btn" id="qrCancel">Close</button>
              </div>
          <?php else: ?>
              <!-- The event's ID is what is sent. One open event: shown as text. Several: the scanner picks. -->
              <?php if (count($ATT_OPEN_EVENTS) === 1): $oe = $ATT_OPEN_EVENTS[0]; ?>
                  <input type="hidden" id="eventSelect" name="eventID" value="<?= (int)$oe['eventID'] ?>">
                  <div style="width: 100%; max-width: 280px; margin-bottom: 15px; text-align: left;">
                      <span style="font-size: 12px; color: rgba(255,255,255,0.7); display: block; margin-bottom: 4px;">Current Event:</span>
                      <div style="width: 100%; padding: 8px 10px; background: #231f2c; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #3b82f6; font-weight: 600; font-size: 13px; text-align: center;">
                          <?= e($oe['eventName']) ?>
                      </div>
                  </div>
              <?php else: ?>
                  <div style="width: 100%; max-width: 280px; margin-bottom: 15px; text-align: left;">
                      <label for="eventSelect" style="font-size: 12px; color: rgba(255,255,255,0.7); display: block; margin-bottom: 4px;">Select Event:</label>
                      <select id="eventSelect" name="eventID" style="width: 100%; padding: 8px 10px; background: #231f2c; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; outline: none; font-size: 13px;">
                          <?php foreach ($ATT_OPEN_EVENTS as $oe): ?>
                              <option value="<?= (int)$oe['eventID'] ?>"><?= e($oe['eventName']) ?> (<?= e($oe['d']) ?>)</option>
                          <?php endforeach; ?>
                      </select>
                  </div>
              <?php endif; ?>

              <!-- Initially hidden completely until Scan is clicked -->
              <div id="qr-reader" style="width: 100%; max-width: 300px; display: none; margin-bottom: 15px;"></div>

              <!-- Static icon shown by default -->
              <div class="qr-large-icon" id="qrStaticIcon">
                  <img src="<?= BASE_PATH ?>/View/resources/qr.png" alt="QR Scanner">
              </div>

              <h3>Enter Student ID</h3>
              <input type="text" id="scannerStudentId" name="studentID" placeholder="eg. YYYYXXXXX" autocomplete="off" required>

              <div id="scannerAlert" style="display: none; width: 100%; max-width: 280px; margin-bottom: 12px; padding: 8px; font-size: 12px; border-radius: 6px; text-align: center;"></div>

              <div class="qr-modal-actions">
                  <!-- Scan keeps the camera running (many students in a row); the button then reads "Stop" -->
                  <button type="button" class="qr-btn" id="qrRetake">Scan</button>
                  <button type="button" class="qr-btn" id="qrRecord" style="background: #3b82f6; border-color: #3b82f6;">Record</button>
                  <button type="button" class="qr-btn" id="qrCancel">Cancel</button>
              </div>
          <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Attendance client-side interactivity and QR scanner script -->
  <script src="<?= BASE_PATH ?>/View/script/attendance.js" defer></script>
</body>
</html>