(function () {
  'use strict';

  var root = document.getElementById('reg');
  if (!root) return;

  var cfg = root.dataset;
  var body = document.getElementById('reg-body');
  var countEl = document.getElementById('reg-count');
  var emptyEl = document.getElementById('reg-empty');
  var searchInput = document.getElementById('reg-search');
  var chips = [].slice.call(root.querySelectorAll('.reg-chip'));
  var filter = 'all';

  /* ---------- toast ---------- */
  var toastEl = document.getElementById('reg-toast');
  var toastTimer = null;
  function toast(text, isError) {
    toastEl.textContent = text;
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, isError ? 5500 : 3200);
  }

  /* ---------- table: filter + search ---------- */
  function rows() { return [].slice.call(body.querySelectorAll('tr')); }
  function matchesFilter(tr) {
    if (filter === 'all') return true;
    if (filter === 'faculty') return tr.dataset.kind === 'faculty';
    return tr.dataset.role === filter;
  }
  function applyFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var shown = 0, all = rows();
    all.forEach(function (tr) {
      var ok = matchesFilter(tr) && (q === '' || tr.dataset.search.indexOf(q) !== -1);
      tr.hidden = !ok;
      if (ok) shown++;
    });
    countEl.textContent = 'Showing ' + shown + ' of ' + all.length + ' accounts';
    emptyEl.hidden = shown !== 0;
  }
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      filter = chip.dataset.filter;
      chips.forEach(function (c) {
        var on = c === chip;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      applyFilter();
    });
  });
  searchInput.addEventListener('input', applyFilter);
  document.getElementById('reg-search-form').addEventListener('submit', function (e) {
    e.preventDefault();
    applyFilter();
  });

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text; // textContent: never HTML
    return n;
  }
  function fillRow(tr, a) {
    tr.dataset.account = JSON.stringify(a);
    tr.dataset.kind = a.kind;
    tr.dataset.role = a.role;
    tr.dataset.search = (a.name + ' ' + a.id + ' ' + a.dept + ' ' + a.programBlock + ' ' + a.roleLabel).toLowerCase();
    tr.textContent = '';
    var th = el('th', '', a.name); th.scope = 'row';
    tr.appendChild(th);
    tr.appendChild(el('td', 'mono', a.id));
    tr.appendChild(el('td', '', a.dept || '—'));
    tr.appendChild(el('td', '', a.programBlock));
    var role = el('td');
    role.appendChild(el('span', 'role-name', a.roleLabel));
    if (a.kind === 'faculty') { role.appendChild(document.createTextNode(' ')); role.appendChild(el('span', 'tag', 'Faculty')); }
    tr.appendChild(role);
    var st = el('td');
    st.appendChild(el('span', 'status status-' + a.status, a.status === 'restricted' ? 'Restricted' : 'Active'));
    tr.appendChild(st);
  }
  function accountOf(tr) {
    try { return JSON.parse(tr.dataset.account); } catch (e) { return null; }
  }
  function findRow(id) {
    return rows().filter(function (tr) { var a = accountOf(tr); return a && a.id === id; })[0] || null;
  }

  /* ---------- talking to the server ---------- */
  function post(url, params) {
    params.set('csrf_token', cfg.csrf);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: params.toString()
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* not JSON */ }
        if (!data) {
          var toLogin = res.redirected && /\/login\b/.test(res.url);
          if (!toLogin && window.console) console.error('registration: HTTP ' + res.status + ', non-JSON response:\n' + text.slice(0, 600));
          data = { ok: false, message: toLogin ? 'Your session expired. Reload the page and log in again.'
            : 'The server sent an unexpected reply (HTTP ' + res.status + '). Check the PHP error log or the Network tab.' };
        }
        return data;
      });
    });
  }

  /* ---------- dialog helpers ---------- */
  function openDialog(d) {
    if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
  }
  function closeDialog(d) {
    if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
  }
  [].slice.call(document.querySelectorAll('dialog.reg-dlg')).forEach(function (d) {
    d.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) closeDialog(d);
      else if (e.target === d) closeDialog(d); // click on the backdrop
    });
  });
  function clearErrors(form) {
    [].slice.call(form.querySelectorAll('.rf-err')).forEach(function (m) { m.hidden = true; m.textContent = ''; });
    [].slice.call(form.querySelectorAll('[aria-invalid]')).forEach(function (i) { i.removeAttribute('aria-invalid'); });
  }
  function showErrors(form, data) {
    var first = null;
    var errs = data.errors || {};
    Object.keys(errs).forEach(function (k) {
      var input = form.querySelector('[name="' + k + '"]');
      var msg = form.querySelector('[data-err="' + k + '"]');
      if (input) input.setAttribute('aria-invalid', 'true');
      if (msg) { msg.textContent = errs[k]; msg.hidden = false; }
      if (!first && input) first = input;
    });
    if (first) first.focus();
    else toast(data.message || 'Something went wrong.', true);
  }

  /* ---------- Add ---------- */
  var addDlg = document.getElementById('add-dialog');
  var addForm = document.getElementById('add-form');
  var roleSel = addForm.elements.role;
  var pbInput = addForm.elements.program_block;
  var hint = document.getElementById('add-hint');
  function syncRole() {
    var r = roleSel.value;
    if (r === 'Students') {
      pbInput.disabled = false; pbInput.placeholder = 'e.g. BSCS 2C';
      hint.innerHTML = 'Students: program and block, e.g. <b>BSCS 2C</b>.';
    } else if (r === 'Dean') {
      pbInput.disabled = true; pbInput.value = ''; pbInput.placeholder = 'All Programs';
      hint.textContent = 'A dean covers every program, so no program is needed.';
    } else {
      pbInput.disabled = false; pbInput.placeholder = 'e.g. BSCS';
      hint.innerHTML = 'Enter the program this ' + (r === 'Professor' ? 'professor teaches in' : 'coordinator handles') + ', e.g. <b>BSCS</b>.';
    }
  }
  roleSel.addEventListener('change', syncRole);

  document.querySelector('[data-action="open-add"]').addEventListener('click', function () {
    addForm.reset();
    addForm.elements.department.value = cfg.defaultDept;
    clearErrors(addForm);
    syncRole();
    openDialog(addDlg);
    addForm.elements.full_name.focus();
  });

  var addBusy = false;
  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (addBusy) return;
    clearErrors(addForm);
    var params = new URLSearchParams();
    ['full_name', 'school_id', 'department', 'program_block', 'role'].forEach(function (k) {
      params.set(k, addForm.elements[k].value);
    });
    addBusy = true;
    var btn = addForm.querySelector('button[type="submit"]');
    btn.disabled = true;
    post(cfg.addUrl, params).then(function (data) {
      if (!data.ok) { showErrors(addForm, data); return; }
      var tr = document.createElement('tr');
      tr.tabIndex = 0;
      fillRow(tr, data.account);
      body.appendChild(tr);
      applyFilter();
      closeDialog(addDlg);
      document.getElementById('done-text').textContent = data.account.name + ' (' + data.account.roleLabel + ') was registered.';
      document.getElementById('done-id').textContent = data.account.id;
      document.getElementById('done-pw').textContent = data.tempPassword;
      openDialog(document.getElementById('done-dialog'));
      tr.scrollIntoView({ block: 'nearest' });
    }).catch(function () {
      toast('Could not reach the server. Check your connection and try again.', true);
    }).then(function () { addBusy = false; btn.disabled = false; });
  });

  document.getElementById('done-copy').addEventListener('click', function () {
    var text = 'ID: ' + document.getElementById('done-id').textContent + '\nTemporary password: ' + document.getElementById('done-pw').textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('Copied.'); }, function () { toast('Copy is blocked here. Select the text and copy it.', true); });
    } else toast('Copy is blocked here. Select the text and copy it.', true);
  });
  document.getElementById('done-dialog').addEventListener('close', function () {
    // do not leave the password in the page
    document.getElementById('done-pw').textContent = '';
  });

  /* ---------- Restrict / lift ---------- */
  var resDlg = document.getElementById('restrict-dialog');
  var resForm = document.getElementById('restrict-form');
  var resTitle = document.getElementById('restrict-title');
  var resSubmit = document.getElementById('restrict-submit');
  var resReason = resForm.elements.reason;
  var resLabel = document.getElementById('restrict-reason-label');
  var resFormErr = document.getElementById('restrict-formerr');
  var mode = 'restrict';

  function showAccountInForm(a) {
    resForm.elements.full_name.value = a ? a.name : '';
    resForm.elements.department.value = a ? (a.dept || '—') : '';
    resForm.elements.program_block.value = a ? a.programBlock : '';
    resForm.elements.role.value = a ? a.roleLabel : '';
    if (a && a.status === 'restricted') {
      mode = 'lift';
      resTitle.textContent = 'Lift Restriction';
      resSubmit.textContent = 'Lift restriction';
      resLabel.textContent = 'Current restriction reason';
      resReason.value = a.reason || '(no reason saved)';
      resReason.readOnly = true;
    } else {
      mode = 'restrict';
      resTitle.textContent = 'Restrict Student / Faculty';
      resSubmit.textContent = 'Restrict';
      resLabel.textContent = 'Reason';
      if (resReason.readOnly) resReason.value = '';
      resReason.readOnly = false;
    }
  }
  function openRestrict(a) {
    resForm.reset();
    clearErrors(resForm);
    resFormErr.hidden = true;
    resReason.readOnly = false;
    resForm.elements.school_id.value = a ? a.id : '';
    showAccountInForm(a || null);
    openDialog(resDlg);
    (a ? resReason : resForm.elements.school_id).focus();
  }
  document.querySelector('[data-action="open-restrict"]').addEventListener('click', function () { openRestrict(null); });

  // typing an ID fills in the rest (read from the table that is already on the page)
  resForm.elements.school_id.addEventListener('input', function () {
    var id = this.value.trim().toUpperCase();
    var tr = id.length >= 9 ? findRow(id) : null;
    showAccountInForm(tr ? accountOf(tr) : null);
  });

  var resBusy = false;
  resForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (resBusy) return;
    clearErrors(resForm);
    resFormErr.hidden = true;
    var params = new URLSearchParams();
    params.set('school_id', resForm.elements.school_id.value.trim());
    params.set('mode', mode);
    params.set('reason', mode === 'restrict' ? resReason.value : '');
    resBusy = true;
    resSubmit.disabled = true;
    post(cfg.restrictUrl, params).then(function (data) {
      if (!data.ok) {
        if (data.errors) showErrors(resForm, data);
        else { resFormErr.textContent = data.message || 'Something went wrong.'; resFormErr.hidden = false; }
        return;
      }
      var tr = findRow(data.id);
      if (tr) {
        var a = accountOf(tr);
        a.status = data.status;
        a.reason = data.reason;
        fillRow(tr, a);
      }
      applyFilter();
      closeDialog(resDlg);
      toast(data.message);
    }).catch(function () {
      resFormErr.textContent = 'Could not reach the server. Check your connection and try again.';
      resFormErr.hidden = false;
    }).then(function () { resBusy = false; resSubmit.disabled = false; });
  });

  /* ---------- click / keyboard on a row ---------- */
  body.addEventListener('click', function (e) {
    var tr = e.target.closest('tr');
    if (tr) openRestrict(accountOf(tr));
  });
  body.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.tagName === 'TR') {
      e.preventDefault();
      openRestrict(accountOf(e.target));
    }
  });

  applyFilter();
})();