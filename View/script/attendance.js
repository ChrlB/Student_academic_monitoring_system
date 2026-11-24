document.addEventListener('DOMContentLoaded', () => {
  const att    = document.getElementById('att');
  const header = document.querySelector('header');
  const modal  = document.getElementById('qrModal');

  /* Create and insert QR Code button next to user profile header icon */
  // data-can-qr is "0" when the role may not scan (registrar); students, faculty, coordinators and deans get "1"
  const profile = header && header.querySelector('.profile_icon')?.closest('.column');
  const canQr   = !att || att.dataset.canQr !== '0';

  if (canQr && profile && !header.querySelector('.att-qr')) {
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

  /* Initialize and manage QR Code scanner functionality */
  let html5QrCode = null;
  let handling = false;   // true while one scan is being sent, so one QR is never counted twice

  const startScanner = () => {
    const eventSelect = document.getElementById('eventSelect');
    const currentEventID = eventSelect ? eventSelect.value.trim() : '';

    // Block scanning entirely if there is no active event
    if (!currentEventID) {
      showScannerAlert("No active event available. Cannot start scanning.", "error");
      return;
    }

    const readerDiv = document.getElementById('qr-reader');
    const staticIcon = document.getElementById('qrStaticIcon');
    const studentInput = document.getElementById('scannerStudentId');
    const retakeBtn = document.getElementById('qrRetake');

    if (!readerDiv) return;

    // Show scanner view, hide static icon box
    readerDiv.style.display = 'block';
    if (staticIcon) staticIcon.style.display = 'none';
    if (retakeBtn) retakeBtn.textContent = 'Retake'; 

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
   * Send scanned or typed student ID asynchronously to PHP backend handler
   * @param {string} studentID
   * @param {boolean} autoRestart - Whether to restart the scanner automatically after success
   */
  const processScannedAttendance = (studentID, autoRestart = false) => {
    const eventSelect = document.getElementById('eventSelect');
    const currentEventID = eventSelect ? eventSelect.value.trim() : '';

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

        // Automatically reopen/restart scanner for the next scan after a short delay (Cooldown window)
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

  /* Helper function to display alerts inside the scanner modal banner */
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
      }, 5000);
    }
  };

  /* Stop active QR scanner camera instance */
  const stopScanner = () => {
    const readerDiv = document.getElementById('qr-reader');
    const staticIcon = document.getElementById('qrStaticIcon');
    const retakeBtn = document.getElementById('qrRetake');

    if (retakeBtn) retakeBtn.textContent = 'Scan';

    const showIcon = () => {
      if (readerDiv) readerDiv.style.display = 'none';
      if (staticIcon) staticIcon.style.display = 'block';
    };

    const running = html5QrCode && (html5QrCode.isScanning || (html5QrCode.getState && html5QrCode.getState() === 2));
    if (!running) { showIcon(); return Promise.resolve(); }

    return html5QrCode.stop().then(showIcon).catch(err => console.error("Failed to stop scanner.", err));
  };

  /* Button event listeners for scanner modal actions */
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
        location.reload(); 
      });
    });
  }

  const recordBtn = document.getElementById('qrRecord');
  if (recordBtn) {
    recordBtn.addEventListener('click', (e) => {
      e.preventDefault();
      if (handling) return;      
      handling = true;
      stopScanner();

      const studentInput = document.getElementById('scannerStudentId');
      const studentID = studentInput ? studentInput.value.trim() : '';

      processScannedAttendance(studentID, false); 
    });
  }

  /* Inject back arrow into header on events page to return to student list */
  const evTitle = header && [...header.querySelectorAll('*')].find(el => !el.children.length && /^\s*EVENTS/i.test(el.textContent));
  if (evTitle && !header.querySelector('.att-back') && !(att && att.dataset.noBack === '1')) {
    const evNode = [...evTitle.childNodes].find(x => x.nodeType === 3 && /EVENTS/i.test(x.nodeValue));
    if (evNode) {
      const back = new URL(window.location.href);
      back.searchParams.delete('events');

      const word = evNode.splitText(evNode.nodeValue.search(/EVENTS/i));
      word.splitText(6);

      const link = document.createElement('a');
      link.className = 'att-back';
      link.href = (att && att.dataset.back) || (back.pathname + back.search);
      link.title = 'Back to attendance list';
      link.setAttribute('aria-label', 'Back to attendance list');
      link.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
      word.parentNode.insertBefore(link, word);
      link.append(word);
    }
  }

  if (!att) return;

  /* Relocate back arrow into header title on attendance detail view */
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

  /* Events management dialog and combobox autocomplete behavior */
  const evDlg = document.getElementById('ev-dlg');
  if (evDlg) {
    const f = id => document.getElementById(id);
    const form = f('ev-form'), fields = [...evDlg.querySelectorAll('input[type=text],input[type=date],input[type=time],select')];
    let currentEventData = null;

    (() => {
      const inp = f('ev-coord'), list = f('ev-coord-list'), caret = f('ev-caret'), combo = f('ev-combo');
      if (!inp || !list) return;
      const items = [...list.children];
      let hi = -1;
      const setHi = i => {
        const vis = items.filter(li => !li.hidden);
        items.forEach(li => li.classList.remove('hi'));
        if (!vis.length) { hi = -1; return; }
        hi = (i + vis.length) % vis.length;
        vis[hi].classList.add('hi'); vis[hi].scrollIntoView({ block: 'nearest' });
      };
      const close = () => { list.hidden = true; inp.setAttribute('aria-expanded', 'false'); hi = -1; items.forEach(li => li.classList.remove('hi')); };
      const open = filter => {
        if (inp.disabled) return;
        const q = filter ? inp.value.trim().toLowerCase() : '';
        let n = 0;
        items.forEach(li => { const m = !q || li.dataset.v.toLowerCase().includes(q); li.hidden = !m; if (m) n++; });
        if (!n) { close(); return; }
        list.hidden = false; inp.setAttribute('aria-expanded', 'true');
      };
      const pick = li => { inp.value = li.dataset.v; close(); inp.focus(); };

      inp.addEventListener('focus', () => open(false));
      inp.addEventListener('click', () => { if (list.hidden) open(false); });
      inp.addEventListener('input', () => { open(true); setHi(0); });
      caret.addEventListener('mousedown', e => { e.preventDefault(); if (inp.disabled) return; if (list.hidden) { inp.focus(); open(false); } else close(); });
      list.addEventListener('mousedown', e => e.preventDefault());
      list.addEventListener('click', e => { const li = e.target.closest('li'); if (li) pick(li); });
      inp.addEventListener('blur', close);
      inp.addEventListener('keydown', e => {
        const vis = items.filter(li => !li.hidden);
        if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { open(false); setHi(0); } else setHi(hi + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (!list.hidden) setHi(hi - 1); }
        else if (e.key === 'Enter' && !list.hidden && hi >= 0) { e.preventDefault(); pick(vis[hi]); }
        else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); e.stopPropagation(); close(); }
      });
      evDlg.addEventListener('close', close);
    })();

    /* Open event dialog in appropriate mode (add, edit, view, review) */
    const isDean = evDlg.dataset.dean === '1';
    const editBtn = f('ev-edit');
    const openDlg = (mode, d = {}) => {
      currentEventData = d;
      const canEdit = d.canEdit === true || d.canEdit === '1';
      form.noValidate = false;
      f('ev-action').value = mode === 'edit' ? 'edit_event' : 'add_event';
      f('ev-id').value     = d.id || '';
      f('ev-title').textContent = mode === 'add' ? 'ADD EVENT' : (mode === 'edit' ? 'EDIT EVENT' : (mode === 'review' ? 'REVIEW EVENT' : 'VIEW EVENT'));
      f('ev-name').value   = d.name   || '';
      f('ev-date').value   = d.date   || '';
      f('ev-status').value = mode === 'add' ? (isDean ? 'ACTIVE' : 'PENDING') : (d.status || 'ACTIVE');
      f('ev-start').value  = d.start  || '08:00';
      f('ev-end').value    = d.end    || '18:00';
      
      /* Pull and show the assigned coordinator from table data (d.coord) */
      f('ev-coord').value = d.coord || (mode === 'add' && !isDean ? (evDlg.dataset.me || '') : '');
      
      f('ev-program').value = mode === 'add' ? (evDlg.dataset.myProgram || '') : (d.program || '');
      const locked = mode === 'view' || mode === 'review';
      fields.forEach(x => { x.disabled = locked; });
      /* Remark: a coordinator must explain an edit; everyone else just reads it */
      const rm = f('ev-remark'), rmWrap = f('ev-remark-wrap');
      if (rm && rmWrap) {
        const asking = mode === 'edit' && !isDean;
        rm.readOnly = !asking;
        rm.required = asking;
        rm.value    = asking ? '' : (d.remark || '');
        rmWrap.hidden = !(asking || d.remark);
      }
      if (!isDean) { f('ev-status').disabled = true; f('ev-coord').disabled = true; if (mode === 'edit') { f('ev-name').disabled = true; f('ev-program').disabled = true; } }
      f('ev-note').hidden    = isDean || locked;
      f('ev-ok').hidden      = locked;
      if (editBtn) editBtn.hidden = !(mode === 'view' && canEdit);
      f('ev-approve').hidden = mode !== 'review';
      f('ev-reject').hidden  = mode !== 'review';
      f('ev-delete').hidden  = mode !== 'edit';
      f('ev-delete').dataset.att = d.att || 0;
      f('ev-cancel').textContent = locked ? 'Close' : 'Cancel';
      if (!evDlg.open) evDlg.showModal();
      if (!locked) (f('ev-name').disabled ? f('ev-date') : f('ev-name')).focus();
    };

    if (editBtn) {
      editBtn.addEventListener('click', () => {
        if (currentEventData && (currentEventData.canEdit === true || currentEventData.canEdit === '1')) {
          openDlg('edit', currentEventData);
        }
      });
    }

    const add = f('ev-add');
    if (add) add.addEventListener('click', () => openDlg('add'));

    const openRow = row => openDlg(row.dataset.review === '1' ? 'review' : 'view', {
      id: row.dataset.id, name: row.dataset.name, date: row.dataset.date, status: row.dataset.status,
      start: row.dataset.start, end: row.dataset.end, coord: row.dataset.coord, program: row.dataset.program,
      att: row.dataset.att, remark: row.dataset.remark, canEdit: row.dataset.edit === '1'
    });
    document.querySelectorAll('.ev-row').forEach(row => {
      row.addEventListener('click', () => openRow(row));
      row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRow(row); } });
    });

    f('ev-cancel').addEventListener('click', () => evDlg.close());
    evDlg.addEventListener('click', e => { if (e.target === evDlg) evDlg.close(); });

    const decide = act => {
      f('ev-action').value = act;
      form.noValidate = true;
      fields.forEach(x => { x.disabled = true; }); 
      form.submit();
    };
    f('ev-approve').addEventListener('click', () => decide('approve_event'));
    f('ev-reject').addEventListener('click', () => { if (confirm('Reject this event? It will be deleted.')) decide('reject_event'); });

    f('ev-delete').addEventListener('click', () => {
      const n = parseInt(f('ev-delete').dataset.att, 10) || 0;
      const extra = n ? `\n\nThis also deletes its ${n} attendance record${n === 1 ? '' : 's'}.` : '';
      if (!confirm('Delete this event?' + extra)) return;
      f('ev-action').value = 'delete_event';
      form.noValidate = true;
      form.submit();
    });
  }

  /* Attendance record selection and bulk deletion toggle behavior */
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