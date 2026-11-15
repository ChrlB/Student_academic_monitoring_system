(function () {
  'use strict';

  var wrap = document.querySelector('.topic-page');
  if (!wrap) return;

  var endpoint = wrap.dataset.endpoint;
  var csrf     = wrap.dataset.csrf;
  var taskId   = wrap.dataset.task;
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

  /* ---------- lazy-load videos (everyone) ---------- */
  document.querySelectorAll('video[data-src]').forEach(function (v) {
    v.preload = 'metadata';
    v.src = v.dataset.src;
  });

  if (!canEdit) return;   // students: nothing else to wire

  /* ---------- request helper ---------- */
  function post(fd) {
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

  function send(action, data) {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k] == null ? '' : data[k]); });
    return post(fd);
  }

  /* ---------- the document editor ---------- */
  var quill = null;
  var dirty = false;
  var timer = null;
  var inflight = Promise.resolve();
  var statusEl = document.getElementById('docStatus');
  var wordsEl  = document.getElementById('docWords');

  function setStatus(text, isError) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('err', !!isError);
  }

  function updateWords() {
    if (!quill || !wordsEl) return;
    var t = quill.getText().trim();
    var n = t === '' ? 0 : t.split(/\s+/).length;
    wordsEl.textContent = n + (n === 1 ? ' word' : ' words');
  }

  function saveNow() {
    clearTimeout(timer);
    if (!quill || !dirty) return inflight;

    var html  = quill.getSemanticHTML();
    var delta = JSON.stringify(quill.getContents());
    dirty = false;
    setStatus('Saving...');

    inflight = inflight.catch(function () { /* previous failure already reported */ }).then(function () {
      return send('save_page', { taskID: taskId, html: html, delta: delta });
    }).then(function (r) {
      setStatus('All changes saved' + (r.saved_at ? ' at ' + r.saved_at : ''));
    }).catch(function (err) {
      dirty = true;
      setStatus('Not saved: ' + err.message, true);
      throw err;
    });
    return inflight;
  }

  function markDirty() {
    dirty = true;
    setStatus('Unsaved changes...');
    clearTimeout(timer);
    timer = setTimeout(function () { saveNow().catch(function () { /* shown in status bar */ }); }, 2000);
  }

  function pickImage() {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/png,image/jpeg,image/gif,image/webp';
    input.onchange = function () {
      var f = input.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { toast('Pictures can be up to 5 MB.', true); return; }

      var range = quill.getSelection(true);
      setStatus('Uploading picture...');
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action', 'upload_image');
      fd.append('taskID', taskId);
      fd.append('file', f);

      post(fd).then(function (json) {
        quill.insertEmbed(range.index, 'image', json.url, 'user');
        quill.setSelection(range.index + 1, 0, 'silent');
      }).catch(function (err) {
        toast(err.message, true);
        setStatus(dirty ? 'Unsaved changes...' : 'All changes saved');
      });
    };
    input.click();
  }

  function table() { return quill.getModule('table'); }

  function initEditor() {
    var host = document.getElementById('editor');
    if (!host) return;
    if (!window.Quill) {
      var tb = document.getElementById('toolbar');
      if (tb) tb.style.display = 'none';
      host.innerHTML = '<p style="padding:24px;color:#c0392b;line-height:1.5;">The editor files could not be loaded. '
        + 'Save quill.js and quill.snow.css into View/lib/quill/ (see the setup note), or check your internet connection, then refresh with Ctrl+Shift+R.</p>';
      setStatus('Editor not loaded', true);
      return;
    }

    quill = new window.Quill('#editor', {
      theme: 'snow',
      placeholder: 'Start writing the lesson for this topic...',
      modules: {
        toolbar: {
          container: '#toolbar',
          handlers: {
            image: pickImage,
            undo: function () { quill.history.undo(); },
            redo: function () { quill.history.redo(); },
            'table-insert': function () { table().insertTable(3, 3); },
            'row-add':  function () { table().insertRowBelow(); },
            'col-add':  function () { table().insertColumnRight(); },
            'row-del':  function () { table().deleteRow(); },
            'col-del':  function () { table().deleteColumn(); },
            'table-del': function () { table().deleteTable(); }
          }
        },
        table: true,
        history: { delay: 1000, maxStack: 200, userOnly: true }
      }
    });

    var initEl = document.getElementById('initialDelta');
    if (initEl) {
      try {
        var initial = JSON.parse(initEl.textContent);
        if (initial && initial.ops) quill.setContents(initial, 'silent');
      } catch (e) { /* start with an empty page */ }
    }

    updateWords();
    quill.on('text-change', function (delta, oldDelta, source) {
      updateWords();
      if (source === 'user') markDirty();
    });

    var saveBtn = document.getElementById('saveBtn');
    if (saveBtn) saveBtn.addEventListener('click', function () { saveNow().catch(function (e) { toast(e.message, true); }); });

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveNow().catch(function (err) { toast(err.message, true); });
      }
    });

    window.addEventListener('beforeunload', function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  initEditor();

  /* ---------- actions that reload the page: save the document first ---------- */
  function run(action, data) {
    return saveNow().then(function () {
      return send(action, data);
    }).then(function () {
      window.location.reload();
    }).catch(function (err) {
      toast(err.message, true);
    });
  }

  wrap.querySelectorAll('.status-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      run('set_status', { taskID: sel.dataset.task, status: sel.value });
    });
  });

  /* ---------- add material form: type switch + upload with progress ---------- */
  var ACCEPT = { pdf: '.pdf,application/pdf', video: '.mp4,.webm,video/mp4,video/webm' };

  function syncContentForm(form) {
    var kind = form.elements.kind.value;
    var isNote = kind === 'note';
    form.querySelector('.cf-file').classList.toggle('is-hidden', isNote);
    form.querySelector('.cf-note').classList.toggle('is-hidden', !isNote);
    if (!isNote) form.elements.file.setAttribute('accept', ACCEPT[kind]);
  }

  var contentForm = wrap.querySelector('.content-form');
  if (contentForm) {
    syncContentForm(contentForm);
    contentForm.elements.kind.addEventListener('change', function () { syncContentForm(contentForm); });

    contentForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var form  = contentForm;
      var kind  = form.elements.kind.value;
      var title = form.elements.title.value.trim();
      if (!title) { toast('Give the material a title first.', true); return; }

      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action', 'add_content');
      fd.append('taskID', taskId);
      fd.append('kind', kind);
      fd.append('title', title);
      fd.append('description', form.elements.description.value.trim());

      if (kind === 'note') {
        if (!form.elements.body.value.trim()) { toast('Write the note text first.', true); return; }
        fd.append('body', form.elements.body.value);
      } else {
        var file = form.elements.file.files[0];
        if (!file) { toast('Choose a file to upload.', true); return; }
        fd.append('file', file);
      }

      var btn   = form.querySelector('button[type="submit"]');
      var bar   = form.querySelector('.upload-progress');
      var fill  = bar.firstElementChild;
      var label = form.querySelector('.upload-label');

      function resetForm() { btn.disabled = false; bar.hidden = true; label.textContent = ''; }

      btn.disabled = true;
      bar.hidden = false;
      fill.style.width = '0%';
      label.textContent = 'Uploading... 0%';

      saveNow().then(function () {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint);
        xhr.withCredentials = true;

        xhr.upload.onprogress = function (ev) {
          if (!ev.lengthComputable) return;
          var pct = Math.round(ev.loaded / ev.total * 100);
          fill.style.width = pct + '%';
          label.textContent = pct < 100 ? 'Uploading... ' + pct + '%' : 'Processing...';
        };
        xhr.onload = function () {
          var json = null;
          try { json = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
          if (xhr.status >= 200 && xhr.status < 300 && json && json.ok) {
            window.location.reload();
          } else {
            toast((json && json.error) || 'Upload failed (HTTP ' + xhr.status + ').', true);
            resetForm();
          }
        };
        xhr.onerror = function () {
          toast('Network error while uploading. Please try again.', true);
          resetForm();
        };
        xhr.send(fd);
      }).catch(function (err) {
        toast('Fix the save problem first: ' + err.message, true);
        resetForm();
      });
    });
  }

  /* ---------- material buttons ---------- */
  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;
    var item = btn.closest('.content-item');

    if (action === 'edit-content' && item) {
      item.classList.add('editing');

    } else if (action === 'cancel-content-edit' && item) {
      item.classList.remove('editing');

    } else if (action === 'save-content' && item) {
      var cTitle = item.querySelector('[name="title"]').value.trim();
      if (!cTitle) { toast('Title cannot be empty.', true); return; }
      var bodyEl = item.querySelector('[name="body"]');
      run('edit_content', {
        contentID: btn.dataset.content,
        title: cTitle,
        description: item.querySelector('[name="description"]').value.trim(),
        body: bodyEl ? bodyEl.value : ''
      });

    } else if (action === 'toggle-publish') {
      run('toggle_publish', { contentID: btn.dataset.content, published: btn.dataset.publish });

    } else if (action === 'move-content') {
      run('move_content', { contentID: btn.dataset.content, dir: btn.dataset.dir });

    } else if (action === 'delete-content') {
      if (window.confirm('Delete this material? Uploaded files are removed permanently.')) {
        run('delete_content', { contentID: btn.dataset.content });
      }
    }
  });
})();