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
        <!-- =============== FACULTY VIEW: Camera Scanner & Database Form =============== -->
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