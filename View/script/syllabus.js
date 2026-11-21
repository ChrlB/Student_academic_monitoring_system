(function () {
  'use strict';

  var wrap = document.querySelector('.syllabus-wrapper');
  if (!wrap) return;

  var endpoint = wrap.dataset.endpoint;
  var csrf     = wrap.dataset.csrf;
  var sched    = wrap.dataset.sched;
  var canEdit  = !!wrap.dataset.edit;

  /* ---------- toast ---------- */
  var toastTimer = null;
  function toast(msg, isError) {
    var el = document.getElementById('sylToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'sylToast';
      document.body.appendChild(el);
    }
    el.className = 'syl-toast' + (isError ? ' error' : '');
    el.textContent = msg;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.remove(); }, 6000);
  }

  /* ---------- class dropdown ---------- */
  var classSelect = document.getElementById('classSelect');
  if (classSelect) {
    classSelect.addEventListener('change', function () { classSelect.form.submit(); });
  }

  /* ---------- search + status filter ---------- */
  var searchInput  = wrap.querySelector('.search-bar');
  var filterItems  = wrap.querySelectorAll('.filter-item');
  var activeFilter = '';

  function applyFilters() {
    var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var filtering = q !== '' || activeFilter !== '';

    wrap.querySelectorAll('.syl-module').forEach(function (mod) {
      var topics = mod.querySelectorAll('.topic');
      var visible = 0;
      topics.forEach(function (t) {
        var okText   = q === '' || (t.dataset.text || '').indexOf(q) !== -1;
        var okStatus = activeFilter === '' || t.dataset.status === activeFilter;
        var show = okText && okStatus;
        t.classList.toggle('is-hidden', !show);
        if (show) visible++;
      });
      // keep empty modules visible when nothing is being filtered (faculty need them to add topics)
      mod.classList.toggle('is-hidden', filtering && topics.length > 0 && visible === 0);
    });
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);

  filterItems.forEach(function (item) {
    function toggle() {
      var f = item.dataset.filter || '';
      activeFilter = (activeFilter === f) ? '' : f;
      filterItems.forEach(function (i) { i.classList.toggle('active', activeFilter !== '' && i.dataset.filter === activeFilter); });
      applyFilters();
    }
    item.addEventListener('click', toggle);
    item.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
    });
  });

  /* ---------- a topic row opens its topic page (students + faculty) ---------- */
  wrap.addEventListener('click', function (e) {
    var row = e.target.closest('.week-item[data-href]');
    if (!row) return;
    if (e.target.closest('a, button, select, input, textarea, label')) return;   // links navigate on their own
    if (row.classList.contains('editing')) return;
    window.location.href = row.dataset.href;
  });

  if (!canEdit) return;   // read-only view: nothing more to wire

  /* ---------- server actions (faculty) ---------- */
  function send(action, data) {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k] == null ? '' : data[k]); });

    return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().then(function (json) { return { res: res, json: json }; },
          function () { throw new Error('Unexpected response. Are you still logged in?'); });
      })
      .then(function (r) {
        if (!r.res.ok || !r.json.ok) throw new Error(r.json.error || 'Request failed.');
        return r.json;
      });
  }

  function run(action, data) {
    return send(action, data).then(function () {
      window.location.reload();
    }).catch(function (err) {
      toast(err.message, true);
    });
  }

  var moduleForm = wrap.querySelector('.module-form');
  if (moduleForm) {
    moduleForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = moduleForm.elements.title.value.trim();
      if (!title) { toast('Enter a module title first.', true); return; }
      run('add_module', { schedID: sched, title: title });
    });
  }

  wrap.querySelectorAll('.task-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = form.elements.title.value.trim();
      if (!title) { toast('Enter a topic title first.', true); return; }
      run('add_task', {
        moduleID: form.dataset.module,
        title: title,
        week_no: form.elements.week_no.value,
        available_from: form.elements.available_from.value,
        due_date: form.elements.due_date.value
      });
    });
  });


  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;
    var row = btn.closest('.week-item');

    if (action === 'rename-module') {
      var current = btn.dataset.title || '';
      var next = window.prompt('Rename module:', current);
      if (next === null) return;
      next = next.trim();
      if (!next) { toast('Module title cannot be empty.', true); return; }
      if (next !== current) run('rename_module', { moduleID: btn.dataset.module, title: next });

    } else if (action === 'delete-module') {
      if (window.confirm('Delete this module, ALL of its topics, lesson pages and uploaded files? This cannot be undone.')) {
        run('delete_module', { moduleID: btn.dataset.module });
      }

    } else if (action === 'edit-task' && row) {
      row.classList.add('editing');

    } else if (action === 'cancel-edit' && row) {
      row.classList.remove('editing');

    } else if (action === 'save-task' && row) {
      var title = row.querySelector('[name="title"]').value.trim();
      if (!title) { toast('Topic title cannot be empty.', true); return; }
      run('edit_task', {
        taskID: btn.dataset.task,
        title: title,
        week_no: row.querySelector('[name="week_no"]').value,
        available_from: row.querySelector('[name="available_from"]').value,
        due_date: row.querySelector('[name="due_date"]').value
      });

    } else if (action === 'delete-task') {
      if (window.confirm('Delete this topic, its lesson page and all of its materials? This cannot be undone.')) {
        run('delete_task', { taskID: btn.dataset.task });
      }

    }
  });
})();