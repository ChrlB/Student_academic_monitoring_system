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
      if (autoRestart) {
        setTimeout(startScanner, 2000);
      }
      return;
    }

    if (!studentID) {
      showScannerAlert("Please enter or scan a Student ID.", "error");
      if (autoRestart) {
        setTimeout(startScanner, 2000);
      }
      return;
    }

    fetch(window.location.pathname, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        'att_action': 'scan',
        'studentID': studentID,
        'eventID': currentEventID
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success' || data.status === 'info') {
        showScannerAlert(data.message, data.status === 'success' ? 'success' : 'info');
        
        // Clear input box for next entry
        const studentInput = document.getElementById('scannerStudentId');
        if (studentInput) studentInput.value = '';

        // Automatically reopen/restart scanner for the next scan after a short delay
        if (autoRestart) {
          setTimeout(() => {
            startScanner();
          }, 1500); // 1.5 second pause so you can read the success message
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
      showScannerAlert('Network connection error.', 'error');
      if (autoRestart) {
        setTimeout(startScanner, 2500);
      }
    });
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

    if (html5QrCode && html5QrCode.isScanning) {
      html5QrCode.stop().then(() => {
        if (readerDiv) readerDiv.style.display = 'none';
        if (staticIcon) staticIcon.style.display = 'block';
      }).catch(err => console.error("Failed to stop scanner.", err));
    } else {
      if (readerDiv) readerDiv.style.display = 'none';
      if (staticIcon) staticIcon.style.display = 'block';
    }
  };

  // Button Event Handlers for Modal
  const retakeBtn = document.getElementById('qrRetake');
  if (retakeBtn) {
    retakeBtn.addEventListener('click', () => {
      stopScanner();
      setTimeout(startScanner, 200);
    });
  }

  const cancelBtn = document.getElementById('qrCancel');
  if (cancelBtn && modal) {
    cancelBtn.addEventListener('click', () => {
      stopScanner();
      modal.style.display = 'none';
      location.reload(); // Refresh background list so new records show up when closing modal
    });
  }

  const recordBtn = document.getElementById('qrRecord');
  if (recordBtn) {
    recordBtn.addEventListener('click', (e) => {
      e.preventDefault();
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