<!-- QR Modal Overlay -->
<div id="qrModal" class="qr-modal-overlay" style="display: none;">
  <div class="qr-modal-content">
    <div class="qr-modal-header">
    </div>

    <div class="qr-scanner-box">
      <?php if ($attGroup === 'student'): ?>
        <!-- =============== STUDENT VIEW: Dynamically Generate QR =============== -->
        <div class="qr-large-icon" id="student-qrcode" style="display: flex; justify-content: center; align-items: center; background: #fff; padding: 10px; border-radius: 8px; width: fit-content; margin: 0 auto 15px auto;"></div>
        
        <h3>Your Student QR Code</h3>
        <p class="qr-subtext"><?= e($me['id'] ?? '') ?> - <?= e($me['name'] ?? '') ?></p>
        
        <!-- Include qrcode.js library -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
          // Generate the QR code dynamically when the modal loads using the student ID (e.g., "2026-00001")
          document.addEventListener("DOMContentLoaded", function() {
            const studentIdText = "<?= e($me['id'] ?? '') ?>";
            const qrContainer = document.getElementById("student-qrcode");
            
            if (studentIdText && qrContainer && qrContainer.innerHTML.trim() === "") {
              new QRCode(qrContainer, {
                text: studentIdText,
                width: 140,
                height: 140,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
              });
            }
          });
        </script>

        <div class="qr-modal-actions" style="margin-top: 20px;">
          <button type="button" class="qr-btn" id="qrCancel">Close</button>
        </div>

      <?php elseif (!empty($attCanScan)): ?>      
        <!-- =============== FACULTY VIEW: Camera Scanner & Database Form =============== -->
        <form id="qrAttendanceForm" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <input type="hidden" name="att_action" value="scan">

        <?php if (empty($currentActiveEvent)): ?>
            <!-- BLOCKED STATE: Show message when no active event exists -->
            <div style="width: 100%; padding: 40px 20px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;">
                <div style="color: rgba(255, 255, 255, 0.85); font-size: 16px; font-weight: 600; letter-spacing: 0.3px;">
                    There's no event to record.
                </div>
            </div>
            <div class="qr-modal-actions" style="width: 100%; max-width: 280px; margin-top: 10px;">
                <button type="button" class="qr-btn" id="qrCancel">Close</button>
            </div>
        <?php else: ?>
            <input type="hidden" id="eventSelect" name="eventID" value="<?= e($currentActiveEvent) ?>">

            <!-- Active Event Display -->
            <div style="width: 100%; max-width: 280px; margin-bottom: 15px; text-align: left;">
                <span style="font-size: 12px; color: rgba(255,255,255,0.7); display: block; margin-bottom: 4px;">Current Event:</span>
                <div style="width: 100%; padding: 8px 10px; background: #231f2c; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #3b82f6; font-weight: 600; font-size: 13px; text-align: center;">
                    <?= e($currentActiveEvent) ?>
                </div>
            </div>

            <!-- Initially hidden completely until Scan is clicked -->
            <div id="qr-reader" style="width: 100%; max-width: 300px; display: none; margin-bottom: 15px;"></div>

            <!-- Static icon shown by default -->
            <div class="qr-large-icon" id="qrStaticIcon">
                <img src="<?= BASE_PATH ?>/View/resources/qr.png" alt="QR Scanner">
            </div>

            <h3>Enter Student ID</h3>
            <input type="text" id="scannerStudentId" name="studentID" placeholder="eg. xxxx-xxxxx" autocomplete="off" required>
            
            <div id="scannerAlert" style="display: none; width: 100%; max-width: 280px; margin-bottom: 12px; padding: 8px; font-size: 12px; border-radius: 6px; text-align: center;"></div>

            <div class="qr-modal-actions">
                <!-- Button starts as "Scan" -->
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