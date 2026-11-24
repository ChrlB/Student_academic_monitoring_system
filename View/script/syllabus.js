(function () {
  'use strict';

  var wrap = document.querySelector('.syllabus-wrapper');
  if (!wrap) return;

  var endpoint = wrap.dataset.endpoint;
  var csrf     = wrap.dataset.csrf;
  var sched    = wrap.dataset.sched;
  var canEdit  = !!wrap.dataset.edit;

  /* ---------- academic calendar weeks lookup ---------- */
  var calWeeks = {};
  var calEl = document.getElementById('calendarWeeksData');
  if (calEl) {
    try { calWeeks = JSON.parse(calEl.textContent) || {}; } catch (e) { calWeeks = {}; }
  }

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

  /* ---------- search + term filter + status filter ---------- */
  var searchInput  = wrap.querySelector('.search-bar');
  var filterItems  = wrap.querySelectorAll('.filter-item');
  var termTabs     = wrap.querySelectorAll('.term-tab');
  var activeFilter = '';
  var activeTerm   = '';

  function applyFilters() {
    var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var filtering = q !== '' || activeFilter !== '';

    wrap.querySelectorAll('.term-section').forEach(function (sec) {
      var secTerm = sec.dataset.termSection || '';
      if (activeTerm !== '' && secTerm !== activeTerm) {
        sec.classList.add('is-hidden');
        return;
      }
      sec.classList.remove('is-hidden');

      var visibleMods = 0;
      sec.querySelectorAll('.syl-module').forEach(function (mod) {
        var topics = mod.querySelectorAll('.topic');
        var visible = 0;
        topics.forEach(function (t) {
          var okText   = q === '' || (t.dataset.text || '').indexOf(q) !== -1;
          var okStatus = activeFilter === '' || t.dataset.status === activeFilter;
          var show = okText && okStatus;
          t.classList.toggle('is-hidden', !show);
          if (show) visible++;
        });
        var hideMod = filtering && topics.length > 0 && visible === 0;
        mod.classList.toggle('is-hidden', hideMod);
        if (!hideMod) visibleMods++;
      });

      if (filtering && visibleMods === 0) sec.classList.add('is-hidden');
    });
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);

  termTabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activeTerm = tab.dataset.termFilter || '';
      termTabs.forEach(function (t) { t.classList.toggle('active', (t.dataset.termFilter || '') === activeTerm); });
      applyFilters();
    });
  });

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

  /* ---------- auto-fill dates when a calendar week is picked ---------- */
  wrap.addEventListener('change', function (e) {
    var sel = e.target.closest('.cal-week-select');
    if (!sel) return;
    var w = calWeeks[sel.value];
    if (!w) return;
    var container = sel.closest('.task-form, .edit-fields');
    if (!container) return;
    var openInput = container.querySelector('[name="available_from"]');
    var dueInput  = container.querySelector('[name="due_date"]');
    if (openInput && w.start_date) openInput.value = w.start_date;
    if (dueInput && w.end_date)    dueInput.value  = w.end_date;
  });

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

  /* ---------- academic calendar forms ---------- */
  var calForm = document.getElementById('calendarForm');
  if (calForm) {
    calForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var start = calForm.elements.semester_start.value;
      if (!start) { toast('Choose the semester start date.', true); return; }
      run('save_calendar', {
        schedID: sched,
        semester_start: start,
        midterm_weeks: calForm.elements.midterm_weeks.value,
        total_weeks: calForm.elements.total_weeks.value,
        midterm_exam_week: calForm.elements.midterm_exam_week.value,
        final_exam_week: calForm.elements.final_exam_week.value,
        sync_dates: calForm.elements.sync_dates.checked ? '1' : '0'
      });
    });
  }

  var breakForm = document.getElementById('breakForm');
  if (breakForm) {
    breakForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = breakForm.elements.title.value.trim();
      var start = breakForm.elements.start_date.value;
      if (!title) { toast('Enter a holiday or break name.', true); return; }
      if (!start) { toast('Choose a start date.', true); return; }
      run('add_calendar_break', {
        schedID: sched,
        title: title,
        start_date: start,
        end_date: breakForm.elements.end_date.value || start,
        skip_week: breakForm.elements.skip_week.checked ? '1' : '0'
      });
    });
  }

  var moduleForm = wrap.querySelector('.module-form');
  if (moduleForm) {
    moduleForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = moduleForm.elements.title.value.trim();
      if (!title) { toast('Enter a module title first.', true); return; }
      run('add_module', {
        schedID: sched,
        title: title,
        term: moduleForm.elements.term ? moduleForm.elements.term.value : 'midterm'
      });
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

    if (action === 'open-calendar') {
      e.preventDefault();
      var cp = document.getElementById('calendarPanel');
      if (cp) { cp.classList.remove('is-hidden'); cp.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

    } else if (action === 'close-calendar') {
      var cp2 = document.getElementById('calendarPanel');
      if (cp2) cp2.classList.add('is-hidden');

    } else if (action === 'sync-calendar') {
      if (!window.confirm('Update opening dates and deadlines of all topics with a week number to match this academic calendar?')) return;
      send('sync_calendar_dates', { schedID: sched }).then(function (r) {
        toast('Updated ' + (r.updated || 0) + ' topic(s) from the academic calendar.', false);
        setTimeout(function () { window.location.reload(); }, 600);
      }).catch(function (err) { toast(err.message, true); });

    } else if (action === 'scaffold-calendar') {
      var withWeeks = document.getElementById('scaffoldWeeks');
      var inc = withWeeks && withWeeks.checked;
      var msg = inc
        ? 'Generate Midterm and Final modules with starter topics for every week in this academic calendar? Existing modules are kept.'
        : 'Generate Midterm and Final modules from this academic calendar? Existing modules are kept.';
      if (!window.confirm(msg)) return;
      run('scaffold_calendar', { schedID: sched, include_weeks: inc ? '1' : '0' });

    } else if (action === 'delete-break') {
      if (!window.confirm('Remove this holiday or break from the academic calendar?')) return;
      run('delete_calendar_break', { schedID: sched, breakID: btn.dataset.break });

    } else if (action === 'open-copy') {
      e.preventDefault();
      var panel = document.getElementById('copyPanel');
      if (panel) { panel.classList.remove('is-hidden'); panel.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

    } else if (action === 'close-copy') {
      var panel2 = document.getElementById('copyPanel');
      if (panel2) panel2.classList.add('is-hidden');

    } else if (action === 'run-copy') {
      var source = document.getElementById('copySource');
      if (!source || !source.value) { toast('Choose a class to copy from.', true); return; }
      if (!window.confirm('Copy that syllabus into this class? Existing modules here are kept, and the copy is added after them.')) return;
      btn.disabled = true;
      toast('Copying... this can take a minute if there are large files.', false);
      run('copy_class', {
        targetSched: sched,
        sourceSched: source.value,
        include_pages: document.getElementById('copyPages').checked ? '1' : '0',
        include_files: document.getElementById('copyFiles').checked ? '1' : '0',
        include_quizzes: document.getElementById('copyQuizzes').checked ? '1' : '0'
      }).then(function () { btn.disabled = false; });

    } else if (action === 'move-module-term') {
      run('set_module_term', { moduleID: btn.dataset.module, term: btn.dataset.term });

    } else if (action === 'rename-module') {
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
