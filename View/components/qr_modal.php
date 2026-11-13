<!-- QR Modal Overlay -->
<div id="qrModal" class="qr-modal-overlay" style="display: none;">
  <div class="qr-modal-content">
    <div class="qr-modal-header">
    </div>

    <div class="qr-scanner-box">
      <?php if ($attGroup === 'student'): ?>
        <!-- =============== STUDENT VIEW: Display their QR from DB =============== -->
        <div class="qr-large-icon">
          <img src="<?= e($me['qr_image'] ?? BASE_PATH . '/View/resources/qr.png') ?>" alt="My QR Code">
        </div>
        <h3>Your Student QR Code</h3>
        <p class="qr-subtext"><?= e($me['id'] ?? '') ?> - <?= e($me['name'] ?? '') ?></p>
        <div class="qr-modal-actions" style="margin-top: 20px;">
          <button type="button" class="qr-btn" id="qrCancel">Close</button>
        </div>

      <?php elseif (!empty($attCanScan)): ?>  
        <!-- =============== FACULTY VIEW: Camera Scanner & Database Form =============== -->
        <form id="qrAttendanceForm" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <input type="hidden" name="att_action" value="scan">

        <!-- Event Selector Dropdown for Scanning/Recording -->
        <div style="width: 100%; max-width: 280px; margin-bottom: 15px; text-align: left;">
            <label for="eventSelect" style="font-size: 12px; color: rgba(255,255,255,0.7); display: block; margin-bottom: 4px;">Select Event:</label>
            <select id="eventSelect" name="eventID" style="width: 100%; padding: 8px 10px; background: #231f2c; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; outline: none; font-size: 13px;">
            
            <option value="">-- Choose Event --</option>
            <?php foreach ($ATT_EVENT_NAMES as $eventName): ?>
                <option value="<?= e($eventName) ?>" <?= ($eventName === ($currentActiveEvent ?? '')) ? 'selected' : '' ?>>
                <?= e($eventName) ?>
                </option>
            <?php endforeach; ?>
            </select>
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
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>