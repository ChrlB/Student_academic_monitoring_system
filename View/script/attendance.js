document.addEventListener('DOMContentLoaded', () => {
  const att    = document.getElementById('att');
  const header = document.querySelector('header');
  const modal  = document.getElementById('qrModal');

  /* ---------- 1. QR button, to the left of the profile ---------- */
  const profile = header && header.querySelector('.profile_icon')?.closest('.column');
  if (profile && !header.querySelector('.att-qr')) {
    const group = document.createElement('div');
    group.className = 'att-hright';

    const qr = document.createElement('a');
    qr.className = 'att-qr';
    qr.href = '#'; 
    qr.title = 'QR Code';
    qr.setAttribute('aria-label', 'QR Code');
    
    const qrIconSrc = (att && att.dataset.qrIcon) || 'View/resources/qr.png';
    qr.innerHTML = `<img src="${qrIconSrc}" alt="QR">`;

    qr.addEventListener('click', (e) => {
      e.preventDefault();
      if (modal) modal.style.display = 'flex';
      // Camera stays off until "Scan / Retake" is clicked
    });

    profile.parentNode.insertBefore(group, profile);
    group.append(qr, profile);
  }

  // Scanner Logic variables (Faculty only)
  let html5QrCode = null;
  let handling = false;   // true while one scan is being sent, so one QR is never counted twice

  const startScanner = () => {
    const readerDiv = document.getElementById('qr-reader');
    const staticIcon = document.getElementById('qrStaticIcon');
    const studentInput = document.getElementById('scannerStudentId');
    const retakeBtn = document.getElementById('qrRetake');

    if (!readerDiv) return;

    // Show scanner view, hide static icon box
    readerDiv.style.display = 'block';
    if (staticIcon) staticIcon.style.display = 'none';
    if (retakeBtn) retakeBtn.textContent = 'Retake'; // Change text to Retake once active

    if (!html5QrCode) {
      html5QrCode = new Html5Qrcode("qr-reader");
    }

    html5QrCode.start(
      { facingMode: "environment" },
      { fps: 10, qrbox: { width: 220, height: 220 } },
      (decodedText) => {
        if (handling) return;
        handling = true;
        if (studentInput) studentInput.value = decodedText;

        if (navigator.vibrate) {
          navigator.vibrate(200);
        }

        stopScanner();
        processScannedAttendance(decodedText, true);
      },
      (errorMessage) => {}
    ).catch((err) => {
      console.error("Unable to start scanning.", err);
      readerDiv.style.display = 'none';
      if (staticIcon) staticIcon.style.display = 'block';
      if (retakeBtn) retakeBtn.textContent = 'Scan';
      showScannerAlert('Camera unavailable. Type the ID instead.', 'error');
    });
  };

  /**
   * Sends scanned/typed student ID asynchronously to PHP backend handler
   * @param {string} studentID 
   * @param {boolean} autoRestart - Whether to restart the scanner automatically after success
   */
  const processScannedAttendance = (studentID, autoRestart = false) => {
    const eventSelect = document.getElementById('eventSelect');
    const currentEventID = eventSelect ? eventSelect.value : null;

    if (!currentEventID) {
      showScannerAlert("Please select an active event first.", "error");
      handling = false;
      if (autoRestart) {
        setTimeout(startScanner, 2000);
      }
      return;
    }

    if (!studentID) {
      showScannerAlert("Please enter or scan a Student ID.", "error");
      handling = false;
      if (autoRestart) {
        setTimeout(startScanner, 2000);
      }
      return;
    }

    // Post to the page you are on (the scan handler lives in attendance_logic.php, loaded by this page)
    fetch(window.location.pathname, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        'att_action': 'scan',
        'studentID': studentID,
        'eventID': eventSelect.value
      })
    })
    .then(response => response.text())
    .then(text => {
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        console.error('Server returned non-JSON response:', text);
        throw new Error('Server returned an invalid response (Check F12 Console).');
      }

      if (data.status === 'success' || data.status === 'info') {
        showScannerAlert(data.message, data.status === 'success' ? 'success' : 'info');
        
        // Clear input box for next entry
        const studentInput = document.getElementById('scannerStudentId');
        if (studentInput) studentInput.value = '';

        // Automatically reopen/restart scanner for the next scan after a short delay
        if (autoRestart) {
          setTimeout(() => {
            startScanner();
          }, 1500);
        }
      } else {
        showScannerAlert(data.message || 'Error recording attendance.', 'error');
        if (autoRestart) {
          setTimeout(startScanner, 2500);
        }
      }
    })
    .catch(error => {
      console.error('Network or server error during scan submission:', error);
      showScannerAlert('Server response error. Check Console (F12).', 'error');
      if (autoRestart) {
        setTimeout(startScanner, 2500);
      }
    })
    .finally(() => { handling = false; });
  };

  /**
   * Helper function to show alerts inside the scanner modal banner
   */
  const showScannerAlert = (message, type = 'success') => {
    const alertBox = document.getElementById('scannerAlert');
    if (alertBox) {
      alertBox.textContent = message;
      alertBox.style.display = 'block';
      alertBox.style.background = type === 'success' ? '#065f46' : (type === 'info' ? '#1e40af' : '#991b1b');
      alertBox.style.color = '#fff';

      clearTimeout(window.alertTimeout);
      window.alertTimeout = setTimeout(() => {
        alertBox.style.display = 'none';
      }, 4000);
    }
  };

  const stopScanner = () => {
    const readerDiv = document.getElementById('qr-reader');
    const staticIcon = document.getElementById('qrStaticIcon');
    const retakeBtn = document.getElementById('qrRetake');

    if (retakeBtn) retakeBtn.textContent = 'Scan'; // Revert back to Scan when stopped

    const showIcon = () => {
      if (readerDiv) readerDiv.style.display = 'none';
      if (staticIcon) staticIcon.style.display = 'block';
    };

    const running = html5QrCode && (html5QrCode.isScanning || (html5QrCode.getState && html5QrCode.getState() === 2));
    if (!running) { showIcon(); return Promise.resolve(); }

    return html5QrCode.stop().then(showIcon).catch(err => console.error("Failed to stop scanner.", err));
  };

  // Button Event Handlers for Modal
  const retakeBtn = document.getElementById('qrRetake');
  if (retakeBtn) {
    retakeBtn.addEventListener('click', () => {
      stopScanner().then(startScanner);
    });
  }

  const cancelBtn = document.getElementById('qrCancel');
  if (cancelBtn && modal) {
    cancelBtn.addEventListener('click', () => {
      stopScanner().finally(() => {
        modal.style.display = 'none';
        location.reload(); // Refresh background list so new records show up when closing modal
      });
    });
  }

  const recordBtn = document.getElementById('qrRecord');
  if (recordBtn) {
    recordBtn.addEventListener('click', (e) => {
      e.preventDefault();
      if (handling) return;      // ignore double clicks while a scan is being sent
      handling = true;
      stopScanner();

      const studentInput = document.getElementById('scannerStudentId');
      const studentID = studentInput ? studentInput.value.trim() : '';

      processScannedAttendance(studentID, false); // Manual record doesn't auto-restart scanner
    });
  }

  if (!att) return;

  /* ---------- 2. Back arrow moved into the header title ---------- */
  const backBtn = att.querySelector('.back-btn');
  const title = header && [...header.querySelectorAll('*')].find(el => !el.children.length && /^\s*ATTENDANCE/i.test(el.textContent));

  if (backBtn && title) {
    const link = document.createElement('a');
    link.className = 'att-back';
    link.href = backBtn.getAttribute('href');
    link.title = backBtn.title;
    link.setAttribute('aria-label', backBtn.title);
    link.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
    
    const node = [...title.childNodes].find(n => n.nodeType === 3 && /ATTENDANCE/i.test(n.nodeValue));
    if (node) {
      const word = node.splitText(node.nodeValue.search(/ATTENDANCE/i));
      word.splitText(10);
      word.parentNode.insertBefore(link, word);
      link.append(word);
      backBtn.remove();
    }
  }

  /* ---------- Events page: the title goes back, rows open the event dialog ---------- */
  const evBack = att.dataset.back;
  if (evBack && header) {
    const t = [...header.querySelectorAll('*')].find(el => !el.children.length && /^\s*EVENTS/i.test(el.textContent));
    const n = t && [...t.childNodes].find(x => x.nodeType === 3 && /EVENTS/i.test(x.nodeValue));
    if (n) {
      const word = n.splitText(n.nodeValue.search(/EVENTS/i));
      word.splitText(6);
      const link = document.createElement('a');
      link.className = 'att-back';
      link.href = evBack;
      link.title = 'Back to attendance list';
      word.parentNode.insertBefore(link, word);
      link.append(word);
    }
  }

  const evDlg = document.getElementById('ev-dlg');
  if (evDlg) {
    const f = id => document.getElementById(id);
    const form = f('ev-form'), fields = [...evDlg.querySelectorAll('input[type=text],input[type=date],input[type=time],select')];

    // mode: 'add' | 'edit' | 'view'
    const openDlg = (mode, d = {}) => {
      form.noValidate = false;
      f('ev-action').value = mode === 'edit' ? 'edit_event' : 'add_event';
      f('ev-id').value     = d.id || '';
      f('ev-title').textContent = mode === 'add' ? 'ADD EVENT' : (mode === 'edit' ? 'EDIT EVENT' : 'VIEW EVENT');
      f('ev-name').value   = d.name   || '';
      f('ev-date').value   = d.date   || '';
      f('ev-status').value = d.status || 'ACTIVE';
      f('ev-start').value  = d.start  || '08:00';
      f('ev-end').value    = d.end    || '18:00';
      f('ev-coord').value  = d.coord  || '';
      fields.forEach(x => { x.disabled = mode === 'view'; });
      f('ev-ok').hidden     = mode === 'view';
      f('ev-delete').hidden = mode !== 'edit';
      f('ev-delete').dataset.att = d.att || 0;
      f('ev-cancel').textContent = mode === 'view' ? 'Close' : 'Cancel';
      if (!evDlg.open) evDlg.showModal();
      if (mode !== 'view') f('ev-name').focus();
    };

    const add = f('ev-add');
    if (add) add.addEventListener('click', () => openDlg('add'));

    const openRow = row => openDlg(row.dataset.edit === '1' ? 'edit' : 'view', {
      id: row.dataset.id, name: row.dataset.name, date: row.dataset.date, status: row.dataset.status,
      start: row.dataset.start, end: row.dataset.end, coord: row.dataset.coord, att: row.dataset.att
    });
    att.querySelectorAll('.ev-row').forEach(row => {
      row.addEventListener('click', () => openRow(row));
      row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRow(row); } });
    });

    f('ev-cancel').addEventListener('click', () => evDlg.close());
    evDlg.addEventListener('click', e => { if (e.target === evDlg) evDlg.close(); });   // click outside

    f('ev-delete').addEventListener('click', () => {
      const n = parseInt(f('ev-delete').dataset.att, 10) || 0;
      const extra = n ? `\n\nThis also deletes its ${n} attendance record${n === 1 ? '' : 's'}.` : '';
      if (!confirm('Delete this event?' + extra)) return;
      f('ev-action').value = 'delete_event';
      form.noValidate = true;
      form.submit();
    });
  }

  /* ---------- 3. Delete button: first click shows checkboxes, second deletes ---------- */
  const del = att.querySelector('.icon-btn.del');
  if (del) {
    att.classList.add('sel-hidden');
    del.addEventListener('click', e => {
      if (att.classList.contains('sel-hidden')) {
        e.preventDefault();
        att.classList.remove('sel-hidden');
        return;
      }
      const checked = att.querySelectorAll('.rd:checked').length;
      if (!checked) {
        e.preventDefault();
        att.classList.add('sel-hidden');
        return;
      }
      if (!confirm(`Delete ${checked} selected record${checked === 1 ? '' : 's'}?`)) e.preventDefault();
    });
  }
});