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
  function matchesFilter(tr) {
    if (filter === 'all') return true;
    if (filter === 'faculty') return tr.dataset.kind === 'faculty';
    return tr.dataset.role === filter;
  }
  function applyFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var shown = 0,
      all = rows();
    all.forEach(function (tr) {
      var ok =
        matchesFilter(tr) && (q === '' || tr.dataset.search.indexOf(q) !== -1);
      tr.hidden = !ok;
      if (ok) shown++;
    });
    countEl.textContent =
      'Showing ' + shown + ' of ' + all.length + ' accounts';
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
    tr.dataset.search = (
      a.name +
      ' ' +
      a.id +
      ' ' +
      a.dept +
      ' ' +
      a.programBlock +
      ' ' +
      a.roleLabel +
      ' ' +
      (a.assignments || [])
        .map(function (x) {
          return x.name + ' ' + x.label;
        })
        .join(' ')
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
    tr.appendChild(assignedCell(a));
    var st = el('td');
    st.appendChild(
      el(
        'span',
        'status status-' + a.status,
        a.status === 'restricted' ? 'Restricted' : 'Active',
      ),
    );
    tr.appendChild(st);
    var act = el('td', 'reg-row-actions');
    act.appendChild(rowButton('edit', 'Edit', a));
    act.appendChild(rowButton('delete', 'Delete', a));
    tr.appendChild(act);
  }
  var NS = 'http://www.w3.org/2000/svg';
  function icon(paths, cls) {
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    if (cls) svg.setAttribute('class', cls);
    paths.forEach(function (d) {
      var p = document.createElementNS(NS, 'path');
      p.setAttribute('d', d);
      svg.appendChild(p);
    });
    return svg;
  }
  var PERSON_CHECK = [
    'M9.5 4.4a3.6 3.6 0 100 7.2 3.6 3.6 0 000-7.2z',
    'M3 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6',
    'M16.5 11.5l2 2 3.5-4',
  ];

  /* the "Assigned Professors" cell: a student shows one chip per professor (+ Add Prof), a faculty shows a count */
  function assignedCell(a) {
    var td = el('td', 'reg-asg');
    if (a.kind === 'faculty') {
      if (a.role === 'registrar') {
        td.appendChild(el('span', 'asg-none', '—'));
        return td;
      }
      var n = a.assignedCount || 0;
      var pill = el('span', 'asg-pill');
      pill.appendChild(
        icon([
          'M8.5 6a3 3 0 100 6 3 3 0 000-6z',
          'M3 19c0-3 2.4-5 5.5-5s5.5 2 5.5 5',
          'M17 6.6a2.4 2.4 0 100 4.8 2.4 2.4 0 000-4.8z',
          'M16 14.2c2.7-.3 5 1.3 5 4.3',
        ]),
      );
      pill.appendChild(
        document.createTextNode(n + ' assigned student' + (n === 1 ? '' : 's')),
      );
      td.appendChild(pill);
      return td;
    }
    var list = a.assignments || [];
    list.forEach(function (x) {
      var chip = el(
        'div',
        'asg-chip' + (x.type === 'adviser' ? ' is-adviser' : ''),
      );
      chip.appendChild(icon(PERSON_CHECK, 'asg-chip-ic'));
      var txt = el('span', 'asg-chip-txt');
      txt.appendChild(el('b', '', x.name));
      txt.appendChild(el('small', '', x.label));
      chip.appendChild(txt);
      var rm = el('button', 'asg-x', '\u00d7');
      rm.type = 'button';
      rm.dataset.asgRemove = x.id;
      rm.setAttribute(
        'aria-label',
        'Remove ' + x.name + ' (' + x.label + ') from ' + a.name,
      );
      chip.appendChild(rm);
      td.appendChild(chip);
    });
    var add = el('button', 'asg-add');
    add.type = 'button';
    add.dataset.asgAdd = a.id;
    add.setAttribute(
      'aria-label',
      (list.length ? 'Add a professor to ' : 'Assign a professor to ') + a.name,
    );
    add.appendChild(icon(list.length ? ['M12 5v14M5 12h14'] : PERSON_CHECK));
    add.appendChild(
      document.createTextNode(list.length ? 'Add Prof' : 'Assign Prof'),
    );
    td.appendChild(add);
    return td;
  }
  var ROW_ICONS = {
    edit: ['M4 20h4l10-10-4-4L4 16z', 'M13 7l4 4'],
    delete: ['M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10.5 11v5M13.5 11v5'],
  };
  function rowButton(kind, label, a) {
    var b = el('button', 'row-btn row-btn-' + kind);
    b.type = 'button';
    b.dataset.rowAction = kind;
    b.setAttribute('aria-label', label + ' ' + a.name);
    if (a.locked) {
      b.disabled = true;
      b.title =
        'This account cannot be ' +
        (kind === 'edit' ? 'edited' : 'deleted') +
        ' here';
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
  }
  roleSel.addEventListener('change', syncRole);

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
    var params = new URLSearchParams();
    ['full_name', 'school_id', 'department', 'program_block', 'role'].forEach(
      function (k) {
        params.set(k, addForm.elements[k].value);
      },
    );
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
    var asgAdd = e.target.closest('[data-asg-add]');
    if (asgAdd) {
      openAssign(asgAdd.dataset.asgAdd);
      return;
    }
    var asgRm = e.target.closest('[data-asg-remove]');
    if (asgRm) {
      unassign(accountOf(tr).id, asgRm.dataset.asgRemove, asgRm);
      return;
    }
    var btn = e.target.closest('[data-row-action]');
    if (btn) {
      // Edit / Delete buttons: do not also open the Restrict dialog
      e.stopPropagation();
      if (btn.disabled) return;
      if (btn.dataset.rowAction === 'edit') openEdit(accountOf(tr));
      else openDelete(accountOf(tr));
      return;
    }
    openRestrict(accountOf(tr));
  });
  body.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.tagName === 'TR') {
      // (buttons inside the row keep their own keys)
      e.preventDefault();
      openRestrict(accountOf(e.target));
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
  }
  editRole.addEventListener('change', syncEditRole);

  function openEdit(a) {
    if (!a) return;
    editKind = a.kind;
    editForm.reset();
    clearErrors(editForm);
    editFormErr.hidden = true;
    editForm.elements.school_id.value = a.id;
    editForm.elements.full_name.value = a.name;
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
    openDialog(editDlg);
    editForm.elements.full_name.focus();
  }

  var editBusy = false;
  editForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (editBusy) return;
    clearErrors(editForm);
    editFormErr.hidden = true;
    var params = new URLSearchParams();
    ['school_id', 'full_name', 'department', 'program_block', 'role'].forEach(
      function (k) {
        params.set(k, editForm.elements[k].value);
      },
    );
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

  /* ---------- Delete ---------- */
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
      'Delete this ' +
      (a.kind === 'faculty' ? a.roleLabel.toLowerCase() : 'student') +
      ' account?';
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

  /* ---------- Assign professors ---------- */
  var asgDlg = document.getElementById('assign-dialog');
  var asgForm = document.getElementById('assign-form');
  var asgStudent = asgForm.elements.school_id;
  var asgFaculty = asgForm.elements.faculty_id;
  var asgType = asgForm.elements.type;
  var asgLabel = asgForm.elements.assignment;
  var asgList = document.getElementById('assign-list');
  var asgInfo = document.getElementById('assign-info');
  var asgCount = document.getElementById('assign-count');
  var asgHint = document.getElementById('assign-hint');
  var asgFormErr = document.getElementById('assign-formerr');
  var asgSubmit = document.getElementById('assign-submit');

  function option(value, text) {
    var o = el('option', '', text);
    o.value = value;
    return o;
  }

  /* redraw the table rows the server sent back (the student and the professor) */
  function applyAccounts(accounts) {
    (accounts || []).forEach(function (a) {
      var tr = findRow(a.id);
      if (tr) fillRow(tr, a);
    });
    applyFilter();
  }

  function selectedStudent() {
    var tr = findRow(asgStudent.value);
    return tr ? accountOf(tr) : null;
  }

  /* the info strip + the "Currently Assigned Professors" list for the chosen student */
  function renderAssigned() {
    var a = selectedStudent();
    asgInfo.textContent = '';
    asgList.textContent = '';
    if (!a) {
      asgCount.textContent = '';
      return;
    }
    var pb = el('span');
    pb.appendChild(document.createTextNode('Program / Block: '));
    pb.appendChild(el('b', '', a.programBlock));
    var dp = el('span');
    dp.appendChild(document.createTextNode('Dept: '));
    dp.appendChild(el('b', '', a.dept || '—'));
    asgInfo.appendChild(pb);
    asgInfo.appendChild(dp);

    var list = a.assignments || [];
    asgCount.textContent = list.length + ' assigned';
    if (!list.length) {
      asgList.appendChild(el('li', 'asg-empty', 'No professors assigned yet.'));
      return;
    }
    list.forEach(function (x) {
      var li = el('li', 'asg-item');
      var txt = el('div', 'asg-item-txt');
      txt.appendChild(el('b', '', x.name + ' (' + x.roleLabel + ')'));
      txt.appendChild(
        el(
          'small',
          '',
          x.label + (x.type === 'adviser' ? ' • reviews enrollment' : ''),
        ),
      );
      li.appendChild(txt);
      var rm = el('button', 'asg-remove', 'Remove');
      rm.type = 'button';
      rm.dataset.asgRemove = x.id;
      rm.setAttribute('aria-label', 'Remove ' + x.name + ' (' + x.label + ')');
      li.appendChild(rm);
      asgList.appendChild(li);
    });
  }

  function syncAsgType() {
    if (asgType.value === 'adviser') {
      asgLabel.placeholder = 'Academic Adviser (optional)';
      asgHint.textContent =
        'An academic adviser can review this student’s enrollment requests. Use this for one adviser per student.';
    } else {
      asgLabel.placeholder = 'e.g. CS 202 — Object-Oriented Programming';
      asgHint.textContent =
        'Add one assignment per subject. The same professor can be added again for another subject.';
    }
  }
  asgType.addEventListener('change', syncAsgType);
  asgStudent.addEventListener('change', function () {
    clearErrors(asgForm);
    asgFormErr.hidden = true;
    renderAssigned();
  });

  function openAssign(studentId) {
    var studentRows = rows()
      .map(accountOf)
      .filter(function (a) {
        return a && a.kind === 'student';
      });
    if (!studentRows.length) {
      toast('Register a student first.', true);
      return;
    }
    var profs = rows()
      .map(accountOf)
      .filter(function (a) {
        return a && a.kind === 'faculty' && a.role !== 'registrar';
      });
    if (!profs.length) {
      toast('Register a professor first.', true);
      return;
    }

    asgStudent.textContent = '';
    studentRows.forEach(function (a) {
      asgStudent.appendChild(option(a.id, a.name + ' (' + a.id + ')'));
    });
    asgFaculty.textContent = '';
    asgFaculty.appendChild(option('', 'Select a professor…'));
    profs.forEach(function (a) {
      asgFaculty.appendChild(
        option(
          a.id,
          a.name + ' — ' + a.roleLabel + (a.dept ? ' (' + a.dept + ')' : ''),
        ),
      );
    });

    asgStudent.value = studentId || studentRows[0].id;
    if (asgStudent.value !== (studentId || studentRows[0].id))
      asgStudent.value = studentRows[0].id;
    asgType.value = 'subject';
    asgLabel.value = '';
    clearErrors(asgForm);
    asgFormErr.hidden = true;
    syncAsgType();
    renderAssigned();
    openDialog(asgDlg);
    asgFaculty.focus();
  }
  document
    .querySelector('[data-action="open-assign"]')
    .addEventListener('click', function () {
      openAssign(null);
    });

  var asgBusy = false;
  asgForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (asgBusy) return;
    clearErrors(asgForm);
    asgFormErr.hidden = true;
    var params = new URLSearchParams();
    params.set('school_id', asgStudent.value);
    params.set('faculty_id', asgFaculty.value);
    params.set('type', asgType.value);
    params.set('assignment', asgLabel.value);
    asgBusy = true;
    asgSubmit.disabled = true;
    post(cfg.assignUrl, params)
      .then(function (data) {
        if (!data.ok) {
          if (data.errors) showErrors(asgForm, data);
          else {
            asgFormErr.textContent = data.message || 'Something went wrong.';
            asgFormErr.hidden = false;
          }
          return;
        }
        applyAccounts(data.accounts);
        renderAssigned();
        asgFaculty.value = '';
        asgLabel.value = ''; // ready for the next subject
        toast(data.message);
        asgFaculty.focus();
      })
      .catch(function () {
        asgFormErr.textContent =
          'Could not reach the server. Check your connection and try again.';
        asgFormErr.hidden = false;
      })
      .then(function () {
        asgBusy = false;
        asgSubmit.disabled = false;
      });
  });

  /* remove one assignment (from the dialog list or the x on a chip) */
  function unassign(studentId, assignmentId, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    var params = new URLSearchParams();
    params.set('school_id', studentId);
    params.set('assignment_id', assignmentId);
    post(cfg.unassignUrl, params)
      .then(function (data) {
        if (!data.ok) {
          toast(data.message || 'Could not remove the assignment.', true);
          btn.disabled = false;
          return;
        }
        applyAccounts(data.accounts);
        if (asgDlg.open) renderAssigned();
        toast(data.message);
      })
      .catch(function () {
        toast(
          'Could not reach the server. Check your connection and try again.',
          true,
        );
        btn.disabled = false;
      });
  }
  asgList.addEventListener('click', function (e) {
    var rm = e.target.closest('[data-asg-remove]');
    if (rm) unassign(asgStudent.value, rm.dataset.asgRemove, rm);
  });

  // first paint: every row is drawn by fillRow so the page and later updates look identical
  rows().forEach(function (tr) {
    var a = accountOf(tr);
    if (a) fillRow(tr, a);
  });
  applyFilter();
})();
