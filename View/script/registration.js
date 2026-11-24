/* View/script/registration.js : registrar page.
   - search box + role chips filter the table (everything is already on the page)
   - Add posts to /registration-add, Restrict / Lift posts to /registration-restrict
   - Edit posts to /registration-update; Archive / Restore post to /registration-archive;
     permanent Delete (Archived tab only) posts to /registration-delete
   - Logs opens the account activity log (/registration-logs: newest first, searchable, 40 at a time)
   - the table updates in place, no reload */
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
    toastTimer = setTimeout(
      function () {
        toastEl.hidden = true;
      },
      isError ? 5500 : 3200,
    );
  }

  /* ---------- table: filter + search ---------- */
  function rows() {
    return [].slice.call(body.querySelectorAll('tr'));
  }
  function isArchivedRow(tr) {
    return tr.dataset.archived === '1';
  }
  // the Archived chip shows only archived accounts; every other chip shows only active (non-archived) ones
  function inScope(tr) {
    return (filter === 'archived') === isArchivedRow(tr);
  }
  function matchesFilter(tr) {
    if (filter === 'all' || filter === 'archived') return true;
    if (filter === 'faculty') return tr.dataset.kind === 'faculty';
    return tr.dataset.role === filter;
  }
  function applyFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var shown = 0,
      total = 0,
      archivedView = filter === 'archived';
    rows().forEach(function (tr) {
      var scoped = inScope(tr);
      if (scoped) total++;
      var ok =
        scoped &&
        matchesFilter(tr) &&
        (q === '' || tr.dataset.search.indexOf(q) !== -1);
      tr.hidden = !ok;
      if (ok) shown++;
    });
    countEl.textContent =
      'Showing ' +
      shown +
      ' of ' +
      total +
      (archivedView ? ' archived accounts' : ' accounts');
    emptyEl.textContent = archivedView
      ? 'No archived accounts.'
      : 'No accounts match.';
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
  document
    .getElementById('reg-search-form')
    .addEventListener('submit', function (e) {
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
    tr.dataset.archived = a.archived ? '1' : '0';
    tr.classList.toggle('is-archived', !!a.archived);
    tr.dataset.search = (
      a.name +
      ' ' +
      a.id +
      ' ' +
      a.dept +
      ' ' +
      a.programBlock +
      ' ' +
      a.roleLabel
    ).toLowerCase();
    tr.textContent = '';
    var th = el('th', '', a.name);
    th.scope = 'row';
    tr.appendChild(th);
    tr.appendChild(el('td', 'mono', a.id));
    tr.appendChild(el('td', '', a.dept || '—'));
    tr.appendChild(el('td', '', a.programBlock));
    var role = el('td');
    role.appendChild(el('span', 'role-name', a.roleLabel));
    if (a.kind === 'faculty') {
      role.appendChild(document.createTextNode(' '));
      role.appendChild(el('span', 'tag', 'Faculty'));
    }
    tr.appendChild(role);
    var st = el('td');
    if (a.archived) {
      var arch = el('span', 'status status-archived', 'Archived');
      if (a.archivedAt) arch.title = 'Archived on ' + a.archivedAt;
      st.appendChild(arch);
    } else {
      st.appendChild(
        el(
          'span',
          'status status-' + a.status,
          a.status === 'restricted' ? 'Restricted' : 'Active',
        ),
      );
    }
    tr.appendChild(st);
    var act = el('td', 'reg-row-actions');
    if (a.archived) {
      // archived accounts: look, bring back, or delete for good
      act.appendChild(rowButton('view', 'View', a));
      act.appendChild(rowButton('restore', 'Restore', a));
      act.appendChild(rowButton('delete', 'Delete', a));
    } else {
      act.appendChild(rowButton('edit', 'Edit', a));
      act.appendChild(rowButton('archive', 'Archive', a));
    }
    tr.appendChild(act);
  }
  var ROW_ICONS = {
    edit: ['M4 20h4l10-10-4-4L4 16z', 'M13 7l4 4'],
    archive: ['M4 7h16v3H4zM6 10v9h12v-9M10 14h4'],
    restore: ['M4 12a8 8 0 108-8M4 4v5h5'],
    view: [
      'M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z',
      'M12 9.2a2.8 2.8 0 100 5.6 2.8 2.8 0 000-5.6z',
    ],
    delete: ['M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10.5 11v5M13.5 11v5'],
  };
  var LOCK_WORD = {
    edit: 'edited',
    archive: 'archived',
    restore: 'restored',
    delete: 'deleted',
    view: 'viewed',
  };
  function rowButton(kind, label, a) {
    var b = el('button', 'row-btn row-btn-' + kind);
    b.type = 'button';
    b.dataset.rowAction = kind;
    b.setAttribute('aria-label', label + ' ' + a.name);
    if (a.locked) {
      b.disabled = true;
      b.title = 'This account cannot be ' + LOCK_WORD[kind] + ' here';
    }
    var NS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    ROW_ICONS[kind].forEach(function (d) {
      var p = document.createElementNS(NS, 'path');
      p.setAttribute('d', d);
      svg.appendChild(p);
    });
    b.appendChild(svg);
    b.appendChild(document.createTextNode(label));
    return b;
  }
  function accountOf(tr) {
    try {
      return JSON.parse(tr.dataset.account);
    } catch (e) {
      return null;
    }
  }
  function findRow(id) {
    return (
      rows().filter(function (tr) {
        var a = accountOf(tr);
        return a && a.id === id;
      })[0] || null
    );
  }

  /* ---------- talking to the server ---------- */
  function post(url, params) {
    params.set('csrf_token', cfg.csrf);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'fetch',
      },
      body: params.toString(),
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try {
          data = JSON.parse(text);
        } catch (e) {
          /* not JSON */
        }
        if (!data) {
          var toLogin = res.redirected && /\/login\b/.test(res.url);
          if (!toLogin && window.console)
            console.error(
              'registration: HTTP ' +
                res.status +
                ', non-JSON response:\n' +
                text.slice(0, 600),
            );
          data = {
            ok: false,
            message: toLogin
              ? 'Your session expired. Reload the page and log in again.'
              : 'The server sent an unexpected reply (HTTP ' +
                res.status +
                '). Check the PHP error log or the Network tab.',
          };
        }
        return data;
      });
    });
  }

  /* ---------- dialog helpers ---------- */
  function openDialog(d) {
    if (typeof d.showModal === 'function') d.showModal();
    else d.setAttribute('open', '');
  }
  function closeDialog(d) {
    if (typeof d.close === 'function') d.close();
    else d.removeAttribute('open');
  }
  [].slice
    .call(document.querySelectorAll('dialog.reg-dlg'))
    .forEach(function (d) {
      d.addEventListener('click', function (e) {
        if (e.target.closest('[data-close]')) closeDialog(d);
        else if (e.target === d) closeDialog(d); // click on the backdrop
      });
    });
  function clearErrors(form) {
    [].slice.call(form.querySelectorAll('.rf-err')).forEach(function (m) {
      m.hidden = true;
      m.textContent = '';
    });
    [].slice
      .call(form.querySelectorAll('[aria-invalid]'))
      .forEach(function (i) {
        i.removeAttribute('aria-invalid');
      });
  }
  function showErrors(form, data) {
    var first = null;
    var errs = data.errors || {};
    Object.keys(errs).forEach(function (k) {
      var input = form.querySelector('[name="' + k + '"]');
      var msg = form.querySelector('[data-err="' + k + '"]');
      if (input) input.setAttribute('aria-invalid', 'true');
      if (msg) {
        msg.textContent = errs[k];
        msg.hidden = false;
      }
      if (!first && input) first = input;
    });
    if (first) first.focus();
    else toast(data.message || 'Something went wrong.', true);
  }

  /* ---------- department follows the program ---------- */
  /* cfg.programs is {"BSCS": "CCS", ...}. Students, coordinators and professors get their department from the
     program they type; only a dean (no program) picks a department by hand. The server does the same lookup. */
  var programDepts = {};
  try {
    programDepts = JSON.parse(cfg.programs || '{}');
  } catch (e) {
    programDepts = {};
  }
  function programCode(text) {
    var m = String(text || '')
      .toUpperCase()
      .trim()
      .match(/^([A-Z]+) ?(?:[1-6][A-Z])?$/);
    return m ? m[1] : '';
  }
  function autoDept(form, pb, role) {
    var sel = form.elements.department;
    if (role === 'Dean') {
      sel.disabled = false;
      return;
    }
    sel.disabled = true;
    var d = programDepts[programCode(pb.value)];
    sel.value = d || '';
  }

  /* ---------- name format: "Last Name, Middle Initial, First Name" ---------- */
  /* Same rules as regParseName() on the server; the preview shows how the box will be read before saving. */
  function nameWordsOk(name) {
    return name.split(' ').every(function (w) {
      var core = w.replace(/\.$/, '');
      return (
        core.length >= 2 &&
        /^[A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F'\-]*$/.test(core)
      );
    });
  }
  function parseName(raw) {
    var t = String(raw || '')
      .replace(/\s+/g, ' ')
      .trim();
    if (!t) return { empty: true };
    var fmt =
      'Use the format: Last Name, Middle Initial, First Name (e.g. Fazbear, D., Freddie).';
    if (t.indexOf(',') < 0) return { error: fmt };
    var p = t.split(',').map(function (x) {
      return x.trim();
    });
    if (p.length === 2) p = [p[0], '', p[1]];
    else if (p.length !== 3) return { error: fmt };
    var last = p[0],
      mid = p[1].replace(/\.$/, ''),
      first = p[2];
    if (!last || !first)
      return { error: 'The last name and the first name are both required.' };
    if (!nameWordsOk(last) || !nameWordsOk(first))
      return {
        error:
          'The last and first names must be whole names without initials or symbols. The middle initial goes in the middle: Last Name, M., First Name.',
      };
    if (mid && !/^[A-Za-z\u00C0-\u024F]$/.test(mid))
      return {
        error:
          'The middle part must be a single letter (the middle initial), e.g. D',
      };
    return { first: first, mid: mid.toUpperCase(), last: last };
  }
  function bindNamePreview(input, out) {
    function show() {
      var r = parseName(input.value);
      out.classList.remove('is-bad');
      if (r.empty) {
        out.textContent = '';
        return;
      }
      if (r.error) {
        out.textContent = r.error;
        out.classList.add('is-bad');
        return;
      }
      out.textContent =
        'Last name: ' +
        r.last +
        '  \u00B7  First name: ' +
        r.first +
        (r.mid ? '  \u00B7  Middle initial: ' + r.mid + '.' : '');
    }
    input.addEventListener('input', show);
    input.form.addEventListener('reset', function () {
      setTimeout(show, 0);
    });
    show();
    return show;
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
      pbInput.disabled = false;
      pbInput.placeholder = 'e.g. BSCS 2C';
      hint.innerHTML = 'Students: program and block, e.g. <b>BSCS 2C</b>.';
    } else if (r === 'Dean') {
      pbInput.disabled = true;
      pbInput.value = '';
      pbInput.placeholder = 'All Programs';
      hint.textContent =
        'A dean covers every program, so no program is needed.';
    } else {
      pbInput.disabled = false;
      pbInput.placeholder = 'e.g. BSCS';
      hint.innerHTML =
        'Enter the program this ' +
        (r === 'Professor' ? 'professor teaches in' : 'coordinator handles') +
        ', e.g. <b>BSCS</b>.';
    }
    autoDept(addForm, pbInput, r);
  }
  roleSel.addEventListener('change', syncRole);
  var showAddName = bindNamePreview(
    addForm.elements.full_name,
    document.getElementById('add-name-preview'),
  );
  pbInput.addEventListener('input', function () {
    autoDept(addForm, pbInput, roleSel.value);
  });

  document
    .querySelector('[data-action="open-add"]')
    .addEventListener('click', function () {
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
    // a swapped first/last name is still a valid name, so ask the registrar to confirm how it was read
    var nm = parseName(addForm.elements.full_name.value);
    if (
      nm.first &&
      !window.confirm(
        'Register this name?\n\nLast name: ' +
          nm.last +
          '\nFirst name: ' +
          nm.first +
          (nm.mid ? '\nMiddle initial: ' + nm.mid + '.' : '') +
          '\n\nPress OK only if the first and last name are not swapped.',
      )
    )
      return;
    var params = new URLSearchParams();
    [
      'full_name',
      'email',
      'school_id',
      'department',
      'program_block',
      'role',
    ].forEach(function (k) {
      params.set(k, addForm.elements[k].value);
    });
    addBusy = true;
    var btn = addForm.querySelector('button[type="submit"]');
    btn.disabled = true;
    post(cfg.addUrl, params)
      .then(function (data) {
        if (!data.ok) {
          showErrors(addForm, data);
          return;
        }
        var tr = document.createElement('tr');
        tr.tabIndex = 0;
        fillRow(tr, data.account);
        body.appendChild(tr);
        applyFilter();
        closeDialog(addDlg);
        document.getElementById('done-text').textContent =
          data.account.name +
          ' (' +
          data.account.roleLabel +
          ') was registered.';
        document.getElementById('done-id').textContent = data.account.id;
        document.getElementById('done-pw').textContent = data.tempPassword;
        // with an email on file, one click opens the registrar's own mail app with the login details filled in
        var mailBtn = document.getElementById('done-mail'),
          mailNote = document.getElementById('done-mailnote');
        if (data.account.email) {
          var loginUrl = window.location.href.replace(
            /\/registration.*$/,
            '/login',
          );
          var mailBody =
            'Hello,\n\nYour account has been created.\n\nSchool ID: ' +
            data.account.id +
            '\nTemporary password: ' +
            data.tempPassword +
            '\nLogin: ' +
            loginUrl +
            '\n\nPlease log in and change your password right away. Do not share it with anyone.';
          mailBtn.href =
            'mailto:' +
            encodeURIComponent(data.account.email) +
            '?subject=' +
            encodeURIComponent('Your account details') +
            '&body=' +
            encodeURIComponent(mailBody);
          mailBtn.hidden = false;
          mailNote.textContent =
            'An email address is on file: use "Email to student" to send it.';
        } else {
          mailBtn.hidden = true;
          mailNote.textContent =
            'No email on file: give it to the person in person.';
        }
        openDialog(document.getElementById('done-dialog'));
        tr.scrollIntoView({ block: 'nearest' });
      })
      .catch(function () {
        toast(
          'Could not reach the server. Check your connection and try again.',
          true,
        );
      })
      .then(function () {
        addBusy = false;
        btn.disabled = false;
      });
  });

  document.getElementById('done-copy').addEventListener('click', function () {
    var text =
      'ID: ' +
      document.getElementById('done-id').textContent +
      '\nTemporary password: ' +
      document.getElementById('done-pw').textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () {
          toast('Copied.');
        },
        function () {
          toast('Copy is blocked here. Select the text and copy it.', true);
        },
      );
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
    resForm.elements.department.value = a ? a.dept || '—' : '';
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
  document
    .querySelector('[data-action="open-restrict"]')
    .addEventListener('click', function () {
      openRestrict(null);
    });

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
    post(cfg.restrictUrl, params)
      .then(function (data) {
        if (!data.ok) {
          if (data.errors) showErrors(resForm, data);
          else {
            resFormErr.textContent = data.message || 'Something went wrong.';
            resFormErr.hidden = false;
          }
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
      })
      .catch(function () {
        resFormErr.textContent =
          'Could not reach the server. Check your connection and try again.';
        resFormErr.hidden = false;
      })
      .then(function () {
        resBusy = false;
        resSubmit.disabled = false;
      });
  });

  /* ---------- click / keyboard on a row ---------- */
  body.addEventListener('click', function (e) {
    var tr = e.target.closest('tr');
    if (!tr) return;
    var btn = e.target.closest('[data-row-action]');
    if (btn) {
      // row buttons: do not also open the Restrict dialog
      e.stopPropagation();
      if (btn.disabled) return;
      var act = btn.dataset.rowAction,
        acct = accountOf(tr);
      if (act === 'edit') openEdit(acct);
      else if (act === 'view') openEdit(acct, true);
      else if (act === 'archive') openArchive(acct);
      else if (act === 'restore') restoreAccount(acct, btn);
      else openDelete(acct);
      return;
    }
    rowOpen(accountOf(tr));
  });
  // clicking an active row opens Restrict as before; an archived row opens its details (read only)
  function rowOpen(a) {
    if (!a) return;
    if (a.archived) openEdit(a, true);
    else openRestrict(a);
  }
  body.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.tagName === 'TR') {
      // (buttons inside the row keep their own keys)
      e.preventDefault();
      rowOpen(accountOf(e.target));
    }
  });

  /* ---------- Edit ---------- */
  var editDlg = document.getElementById('edit-dialog');
  var editForm = document.getElementById('edit-form');
  var editHint = document.getElementById('edit-hint');
  var editFormErr = document.getElementById('edit-formerr');
  var editSubmit = document.getElementById('edit-submit');
  var editRole = editForm.elements.role;
  var editPb = editForm.elements.program_block;
  var editKind = 'student';

  function syncEditRole() {
    var r = editRole.value;
    if (editKind === 'student') {
      editPb.disabled = false;
      editPb.placeholder = 'e.g. BSCS 2C';
      editHint.innerHTML =
        'Program and block, e.g. <b>BSCS 2C</b>. A student stays a student.';
    } else if (r === 'Dean') {
      editPb.disabled = true;
      editPb.value = '';
      editPb.placeholder = 'All Programs';
      editHint.textContent =
        'A dean covers every program, so no program is needed.';
    } else {
      editPb.disabled = false;
      editPb.placeholder = 'e.g. BSCS';
      editHint.innerHTML = 'Enter the program, e.g. <b>BSCS</b>.';
    }
    autoDept(editForm, editPb, editKind === 'student' ? 'Students' : r);
  }
  editRole.addEventListener('change', syncEditRole);
  var showEditName = bindNamePreview(
    editForm.elements.full_name,
    document.getElementById('edit-name-preview'),
  );
  editPb.addEventListener('input', function () {
    autoDept(
      editForm,
      editPb,
      editKind === 'student' ? 'Students' : editRole.value,
    );
  });

  var editTitle = document.getElementById('edit-title');
  var editCancel = editDlg.querySelector('.dlg-actions .pill-grey');
  // view-only (archived accounts): every box is locked, no Save button
  function setEditViewOnly(on) {
    [].slice.call(editForm.elements).forEach(function (f) {
      if (f.tagName === 'BUTTON' || f.name === 'school_id') return;
      if (f.tagName === 'SELECT') f.disabled = on;
      else f.readOnly = on; // (openEdit re-applies its own disabled states afterwards)
    });
    editSubmit.hidden = on;
    editTitle.textContent = on
      ? 'Archived Account (view only)'
      : 'Edit Student / Faculty';
    editCancel.textContent = on ? 'Close' : 'Cancel';
  }
  function openEdit(a, viewOnly) {
    if (!a) return;
    setEditViewOnly(false);
    editKind = a.kind;
    editForm.reset();
    clearErrors(editForm);
    editFormErr.hidden = true;
    editForm.elements.school_id.value = a.id;
    editForm.elements.full_name.value = a.name;
    showEditName();
    editForm.elements.email.value = a.email || '';
    editForm.elements.department.value = a.dept || cfg.defaultDept;
    editPb.value =
      a.programBlock &&
      a.programBlock !== '—' &&
      a.programBlock !== 'All Programs'
        ? a.programBlock
        : '';
    // students keep their role; faculty can be moved between Dean / Program Coordinator / Professor
    [].slice.call(editRole.options).forEach(function (o) {
      o.hidden = o.disabled =
        a.kind === 'student' ? o.value !== 'Students' : o.value === 'Students';
    });
    editRole.value = a.roleLabel;
    editRole.disabled = a.kind === 'student';
    syncEditRole();
    if (viewOnly) setEditViewOnly(true);
    openDialog(editDlg);
    if (!viewOnly) editForm.elements.full_name.focus();
  }

  var editBusy = false;
  editForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (editBusy) return;
    clearErrors(editForm);
    editFormErr.hidden = true;
    var params = new URLSearchParams();
    [
      'school_id',
      'full_name',
      'email',
      'department',
      'program_block',
      'role',
    ].forEach(function (k) {
      params.set(k, editForm.elements[k].value);
    });
    editBusy = true;
    editSubmit.disabled = true;
    post(cfg.updateUrl, params)
      .then(function (data) {
        if (!data.ok) {
          if (data.errors) showErrors(editForm, data);
          else {
            editFormErr.textContent = data.message || 'Something went wrong.';
            editFormErr.hidden = false;
          }
          return;
        }
        var tr = findRow(data.account.id);
        if (tr) fillRow(tr, data.account);
        applyFilter();
        closeDialog(editDlg);
        toast(data.message);
      })
      .catch(function () {
        editFormErr.textContent =
          'Could not reach the server. Check your connection and try again.';
        editFormErr.hidden = false;
      })
      .then(function () {
        editBusy = false;
        editSubmit.disabled = false;
      });
  });

  /* ---------- Activity logs ---------- */
  var logsDlg = document.getElementById('logs-dialog');
  var logsBody = document.getElementById('logs-body');
  var logsQ = document.getElementById('logs-q');
  var logsAction = document.getElementById('logs-action');
  var logsEmpty = document.getElementById('logs-empty');
  var logsCount = document.getElementById('logs-count');
  var logsMore = document.getElementById('logs-more');
  var logsLast = 0,
    logsShown = 0,
    logsSeq = 0,
    logsTimer = null;

  function logRow(l) {
    var tr = el('tr');
    tr.appendChild(el('td', 'lg-when', l.when));
    var acct = el('td', 'lg-acct');
    acct.appendChild(el('b', '', l.accountName || '(unknown name)'));
    var idBtn = el('button', '', l.accountId); // click an ID to see only that account's activity
    idBtn.type = 'button';
    idBtn.dataset.logAccount = l.accountId;
    idBtn.title = 'Show only this account';
    acct.appendChild(idBtn);
    tr.appendChild(acct);
    var act = el('td');
    act.appendChild(el('span', 'lg-badge lg-' + l.action, l.label));
    tr.appendChild(act);
    tr.appendChild(el('td', 'lg-det', l.details));
    var by = el('td', 'lg-by');
    if (l.actorId && l.actorId === l.accountId) {
      by.appendChild(el('span', '', 'The account itself'));
    } else if (l.actorId) {
      by.appendChild(el('span', '', l.actorName || l.actorId));
      by.appendChild(el('small', '', l.actorId));
    } else {
      by.appendChild(el('span', '', '\u2014'));
    }
    tr.appendChild(by);
    return tr;
  }
  function loadLogs(reset) {
    var seq = ++logsSeq;
    if (reset) {
      logsLast = 0;
      logsShown = 0;
      logsBody.textContent = '';
    }
    var params = new URLSearchParams();
    params.set('q', logsQ.value.trim());
    params.set('action', logsAction.value);
    if (logsLast) params.set('before', String(logsLast));
    logsMore.disabled = true;
    return post(cfg.logsUrl, params)
      .then(function (data) {
        if (seq !== logsSeq) return; // a newer search already replaced this one
        if (!data.ok) {
          logsEmpty.textContent = data.message || 'Could not load the log.';
          logsEmpty.hidden = false;
          logsMore.hidden = true;
          logsCount.textContent = '';
          return;
        }
        data.logs.forEach(function (l) {
          logsBody.appendChild(logRow(l));
          logsLast = l.id;
        });
        logsShown += data.logs.length;
        logsMore.hidden = !data.more;
        logsEmpty.hidden = logsShown !== 0;
        logsEmpty.textContent =
          logsQ.value.trim() || logsAction.value
            ? 'No activity matches.'
            : 'No activity has been recorded yet.';
        logsCount.textContent = logsShown
          ? 'Showing ' +
            logsShown +
            (data.more ? '+' : '') +
            ' entr' +
            (logsShown === 1 ? 'y' : 'ies')
          : '';
      })
      .catch(function () {
        if (seq !== logsSeq) return;
        logsEmpty.textContent =
          'Could not reach the server. Check your connection and try again.';
        logsEmpty.hidden = false;
      })
      .then(function () {
        logsMore.disabled = false;
      });
  }
  document
    .querySelector('[data-action="open-logs"]')
    .addEventListener('click', function () {
      logsQ.value = '';
      logsAction.value = '';
      logsEmpty.hidden = true;
      logsMore.hidden = true;
      logsCount.textContent = 'Loading\u2026';
      openDialog(logsDlg);
      loadLogs(true);
      logsQ.focus();
    });
  logsQ.addEventListener('input', function () {
    clearTimeout(logsTimer);
    logsTimer = setTimeout(function () {
      loadLogs(true);
    }, 300);
  });
  logsAction.addEventListener('change', function () {
    loadLogs(true);
  });
  logsMore.addEventListener('click', function () {
    loadLogs(false);
  });
  logsBody.addEventListener('click', function (e) {
    var b = e.target.closest('[data-log-account]');
    if (!b) return;
    logsQ.value = b.dataset.logAccount;
    loadLogs(true);
  });

  /* ---------- Archive / Restore ---------- */
  var arcDlg = document.getElementById('archive-dialog');
  var arcForm = document.getElementById('archive-form');
  var arcErr = document.getElementById('archive-formerr');
  var arcSubmit = document.getElementById('archive-submit');
  var arcId = '';

  function openArchive(a) {
    if (!a) return;
    arcId = a.id;
    arcErr.hidden = true;
    document.getElementById('archive-text').textContent =
      'Archive this ' +
      (a.kind === 'faculty' ? a.roleLabel.toLowerCase() : 'student') +
      ' account?';
    document.getElementById('archive-name').textContent = a.name;
    document.getElementById('archive-id').textContent = a.id;
    openDialog(arcDlg);
    arcDlg.querySelector('.pill-grey').focus();
  }
  function archiveCall(id, mode) {
    var params = new URLSearchParams();
    params.set('school_id', id);
    params.set('mode', mode);
    return post(cfg.archiveUrl, params);
  }
  function applyArchiveReply(data) {
    var tr = findRow(data.account.id);
    if (tr) fillRow(tr, data.account);
    applyFilter();
  }
  var arcBusy = false;
  arcForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (arcBusy) return;
    arcErr.hidden = true;
    arcBusy = true;
    arcSubmit.disabled = true;
    archiveCall(arcId, 'archive')
      .then(function (data) {
        if (!data.ok) {
          arcErr.textContent = data.message || 'Something went wrong.';
          arcErr.hidden = false;
          return;
        }
        applyArchiveReply(data);
        closeDialog(arcDlg);
        toast(data.message);
      })
      .catch(function () {
        arcErr.textContent =
          'Could not reach the server. Check your connection and try again.';
        arcErr.hidden = false;
      })
      .then(function () {
        arcBusy = false;
        arcSubmit.disabled = false;
      });
  });
  function restoreAccount(a, btn) {
    if (!a || btn.disabled) return;
    btn.disabled = true;
    archiveCall(a.id, 'restore')
      .then(function (data) {
        if (!data.ok) {
          toast(data.message || 'Something went wrong.', true);
          return;
        }
        applyArchiveReply(data);
        toast(data.message);
      })
      .catch(function () {
        toast(
          'Could not reach the server. Check your connection and try again.',
          true,
        );
      })
      .then(function () {
        btn.disabled = false;
      });
  }

  /* ---------- Delete (permanent, Archived tab only) ---------- */
  var delDlg = document.getElementById('delete-dialog');
  var delForm = document.getElementById('delete-form');
  var delErr = document.getElementById('delete-formerr');
  var delSubmit = document.getElementById('delete-submit');
  var delId = '';

  function openDelete(a) {
    if (!a) return;
    delId = a.id;
    delErr.hidden = true;
    document.getElementById('delete-text').textContent =
      'Permanently delete this archived ' +
      (a.kind === 'faculty' ? a.roleLabel.toLowerCase() : 'student') +
      ' account? This cannot be undone.';
    document.getElementById('delete-name').textContent = a.name;
    document.getElementById('delete-id').textContent = a.id;
    openDialog(delDlg);
    delDlg.querySelector('.pill-grey').focus(); // the safe choice is focused first
  }

  var delBusy = false;
  delForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (delBusy) return;
    delErr.hidden = true;
    var params = new URLSearchParams();
    params.set('school_id', delId);
    delBusy = true;
    delSubmit.disabled = true;
    post(cfg.deleteUrl, params)
      .then(function (data) {
        if (!data.ok) {
          delErr.textContent = data.message || 'Something went wrong.';
          delErr.hidden = false;
          return;
        }
        var tr = findRow(data.id);
        if (tr) tr.remove();
        applyFilter();
        closeDialog(delDlg);
        toast(data.message);
      })
      .catch(function () {
        delErr.textContent =
          'Could not reach the server. Check your connection and try again.';
        delErr.hidden = false;
      })
      .then(function () {
        delBusy = false;
        delSubmit.disabled = false;
      });
  });

  // first paint: every row is drawn by fillRow so the page and later updates look identical
  rows().forEach(function (tr) {
    var a = accountOf(tr);
    if (a) fillRow(tr, a);
  });
  applyFilter();
})();
