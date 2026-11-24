(function () {
  'use strict';

  var wrap = document.querySelector('.topic-page');
  if (!wrap) return;

  var endpoint  = wrap.dataset.endpoint;
  var csrf      = wrap.dataset.csrf;
  var taskId    = wrap.dataset.task;
  var canEdit   = !!wrap.dataset.edit;
  var isStudent = !!wrap.dataset.student;

  /* ---------- helpers ---------- */
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

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

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

  // videos: load the metadata so the player shows a poster frame + duration
  document.querySelectorAll('video[data-src]').forEach(function (v) {
    v.preload = 'metadata';
    v.src = v.dataset.src;
  });

  /* =====================================================================
     STUDENT: study-again countdown, mark finished, checkpoints + practice
  ===================================================================== */
  function initStudent() {
    function reloadAfter(action, data) {
      return send(action, data).then(function () {
        window.location.reload();
      }).catch(function (err) { toast(err.message, true); });
    }

    // after a failed checkpoint: a short wait, then the Retake button unlocks by itself
    var banner = document.getElementById('restudyBanner');
    if (banner && (parseInt(banner.dataset.seconds, 10) || 0) > 0) {
      var end = Date.now() + parseInt(banner.dataset.seconds, 10) * 1000;
      var timerEl = document.getElementById('restudyTimer');
      var waitEl = document.getElementById('restudyWait');
      var retakeBtn = document.getElementById('retakeBtn');
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      (function tick() {
        var left = Math.max(0, Math.round((end - Date.now()) / 1000));
        timerEl.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
        if (left > 0) {
          setTimeout(tick, 500);
        } else {
          if (waitEl) waitEl.style.display = 'none';
          if (retakeBtn) retakeBtn.disabled = false;
        }
      })();
    }

    var MODE_NAMES = {
      standard: 'Quiz', instant: 'Instant feedback', timed: 'Timed quiz', confidence: 'Confidence marking',
      adaptive: 'Adaptive', survival: 'Survival', study: 'Study mode', flash: 'Flashcards',
      review: 'Review'
    };
    var openTimers = [];

    function openOverlay() {
      var overlay = el('div', 'quiz-overlay');
      var box = el('div', 'quiz-box');
      overlay.appendChild(box);
      document.body.appendChild(overlay);
      box.closeOverlay = function () {
        openTimers.forEach(function (t) { clearInterval(t); });
        openTimers = [];
        overlay.remove();
      };
      return box;
    }

    function hearts(n) {
      var s = '';
      for (var i = 0; i < n; i++) s += '\u2764';
      return s || '\u2014';
    }

    function addActions(box, buttons) {
      var actions = el('div', 'quiz-actions');
      buttons.forEach(function (b) { actions.appendChild(b); });
      box.appendChild(actions);
      return actions;
    }

    function button(text, cls, handler) {
      var b = el('button', 'quiz-btn' + (cls ? ' ' + cls : ''), text);
      b.type = 'button';
      if (handler) b.addEventListener('click', handler);
      return b;
    }

    function radioChoice(name, value, label) {
      var l = el('label');
      var input = document.createElement('input');
      input.type = 'radio';
      input.name = name;
      input.value = value;
      l.appendChild(input);
      l.appendChild(el('span', null, label));
      return l;
    }

    /* ---------- result screens ---------- */
    function showResult(box, r) {
      box.innerHTML = '';
      var res = el('div', 'quiz-result ' + (r.passed ? 'pass' : 'fail'));
      res.appendChild(el('h2', null, r.passed ? 'You passed!' : 'Not yet'));
      res.appendChild(el('div', 'score', r.score + '%'));
      res.appendChild(el('div', 'sub', r.correct + ' of ' + r.total + ' correct. Pass mark: ' + r.pass_percent + '%'));

      if (r.notes && r.notes.length) {
        var nl = el('ul', 'quiz-notes');
        r.notes.forEach(function (n) { nl.appendChild(el('li', null, n)); });
        res.appendChild(nl);
      }

      if (r.passed) {
        res.appendChild(el('p', null, r.completed ? 'This topic is now completed.' : 'Well done!'));
        if (r.review && r.review.length) {
          var ul = el('ul', 'quiz-review');
          r.review.forEach(function (it) {
            var li = el('li');
            li.appendChild(el('div', null, it.prompt));
            li.appendChild(el('div', 'good', 'Correct answer: ' + it.correct));
            if (it.explanation) li.appendChild(el('div', null, it.explanation));
            ul.appendChild(li);
          });
          res.appendChild(ul);
        }
      } else {
        res.appendChild(el('p', null, 'Study this topic again before you retake. You can retake after ' + r.cooldown
          + ' minute(s) of study and after you have reviewed the materials.'));
        if (r.missed && r.missed.length) {
          res.appendChild(el('div', 'sub', 'Questions to review:'));
          var ul2 = el('ul', 'quiz-review');
          r.missed.forEach(function (p) { ul2.appendChild(el('li', null, p)); });
          res.appendChild(ul2);
        }
      }
      addActions(res, [button('Close', '', function () { window.location.reload(); })]);
      box.appendChild(res);
    }

    function showSummary(box, s) {
      box.innerHTML = '';
      var res = el('div', 'quiz-result ' + (s.correct === s.total ? 'pass' : 'fail'));
      res.appendChild(el('h2', null, 'Practice complete'));
      res.appendChild(el('div', 'score', s.correct + ' / ' + s.total));
      res.appendChild(el('div', 'sub', s.correct === s.total
        ? 'Perfect round!'
        : 'Questions you missed go to Mistake review, and come back in Spaced review.'));
      addActions(res, [button('Close', '', function () { window.location.reload(); })]);
      box.appendChild(res);
    }

    /* ---------- whole-attempt modes: standard, timed, confidence ---------- */
    function runBatch(r) {
      var box = openOverlay();
      box.appendChild(el('h2', null, r.title));
      var sub = 'Pass mark: ' + r.pass_percent + '% \u00B7 ' + r.questions.length + ' question' + (r.questions.length === 1 ? '' : 's') + '.';
      if (r.mode === 'timed') sub += ' Answers are locked until you submit.';
      if (r.mode === 'confidence') sub += ' For every answer, say how sure you are. Sure and right earns more points; sure and wrong loses points.';
      box.appendChild(el('div', 'sub', sub));

      var timerBox = null;
      var submitting = false;
      if (r.mode === 'timed' && r.time_limit) {
        timerBox = el('div', 'run-timer', '--:--');
        box.appendChild(timerBox);
      }

      var form = el('form');
      r.questions.forEach(function (q, i) {
        var qWrap = el('div', 'quiz-q');
        qWrap.appendChild(el('div', 'qtext', (i + 1) + '. ' + q.prompt));
        q.choices.forEach(function (c) { qWrap.appendChild(radioChoice('q' + q.id, c.id, c.label)); });
        if (r.mode === 'confidence') {
          var row = el('div', 'conf-row');
          row.appendChild(el('span', null, 'How sure are you?'));
          row.appendChild(radioChoice('c' + q.id, 'low', 'Not sure'));
          row.appendChild(radioChoice('c' + q.id, 'med', 'Fairly sure'));
          row.appendChild(radioChoice('c' + q.id, 'high', 'Very sure'));
          qWrap.appendChild(row);
        }
        form.appendChild(qWrap);
      });

      var submitBtn = el('button', 'quiz-btn', 'Submit answers');
      submitBtn.type = 'submit';
      addActions(form, [button('Leave', 'secondary', function () { box.closeOverlay(); }), submitBtn]);

      function submitAttempt(force) {
        if (submitting) return;
        var answers = {};
        var conf = {};
        var missing = false;
        r.questions.forEach(function (q) {
          var checked = form.querySelector('input[name="q' + q.id + '"]:checked');
          if (!checked) { missing = true; return; }
          answers[q.id] = parseInt(checked.value, 10);
          if (r.mode === 'confidence') {
            var c = form.querySelector('input[name="c' + q.id + '"]:checked');
            if (c) conf[q.id] = c.value; else missing = true;
          }
        });
        if (!force && missing) {
          toast(r.mode === 'confidence' ? 'Answer every question and rate how sure you are.' : 'Answer every question first.', true);
          return;
        }
        submitting = true;
        submitBtn.disabled = true;
        send('run_submit', { run: r.run, answers: JSON.stringify(answers), conf: JSON.stringify(conf) })
          .then(function (res) {
            openTimers.forEach(function (t) { clearInterval(t); });
            openTimers = [];
            showResult(box, res);
          })
          .catch(function (err) { toast(err.message, true); submitting = false; submitBtn.disabled = false; });
      }

      form.addEventListener('submit', function (e) { e.preventDefault(); submitAttempt(false); });
      box.appendChild(form);

      if (timerBox) {
        var endAt = Date.now() + r.time_limit * 1000;
        var id = setInterval(function () {
          var left = Math.max(0, Math.round((endAt - Date.now()) / 1000));
          var mm = Math.floor(left / 60), ss = left % 60;
          timerBox.textContent = 'Time left: ' + (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
          timerBox.classList.toggle('low', left <= 60);
          if (left <= 0) {
            clearInterval(id);
            toast('Time is up. Submitting your answers...', true);
            submitAttempt(true);
          }
        }, 500);
        openTimers.push(id);
      }
    }

    /* ---------- one question at a time: instant, adaptive, survival, practice ---------- */
    function runSteps(r) {
      var box = openOverlay();
      var state = { lives: r.lives || 0, total: r.total };
      var isFlash = (r.mode === 'flash');
      var title = r.title || MODE_NAMES[r.mode] || 'Quiz';

      function header(index) {
        box.innerHTML = '';
        box.appendChild(el('h2', null, title));
        var bar = el('div', 'run-head');
        bar.appendChild(el('span', null, 'Question ' + index + ' of ' + state.total));
        if (r.mode === 'survival') bar.appendChild(el('span', 'hearts', hearts(state.lives)));
        box.appendChild(bar);
      }

      function showQuestion(q, index) {
        header(index);
        var qWrap = el('div', 'quiz-q');
        qWrap.appendChild(el('div', 'qtext', q.prompt));
        var holder = el('div');
        qWrap.appendChild(holder);
        box.appendChild(qWrap);

        var checkBtn = button('Check answer', '', null);
        checkBtn.disabled = true;

        function renderChoices() {
          holder.innerHTML = '';
          q.choices.forEach(function (c) {
            var label = radioChoice('step', c.id, c.label);
            label.querySelector('input').addEventListener('change', function () { checkBtn.disabled = false; });
            holder.appendChild(label);
          });
        }

        function answer(choiceId) {
          checkBtn.disabled = true;
          send('run_answer', { run: r.run, questionID: q.id, choiceID: choiceId })
            .then(function (fb) { showFeedback(q, index, fb); })
            .catch(function (err) { toast(err.message, true); checkBtn.disabled = false; });
        }

        if (isFlash) {
          holder.appendChild(el('div', 'sub', 'Try to answer from memory first.'));
          var flash = el('div', 'flash-actions');
          flash.appendChild(button('Reveal the choices', '', function () { flash.remove(); renderChoices(); }));
          flash.appendChild(button("I don't know. Show the answer", 'secondary', function () { answer(0); }));
          holder.appendChild(flash);
        } else {
          renderChoices();
        }

        checkBtn.addEventListener('click', function () {
          var picked = holder.querySelector('input[name="step"]:checked');
          if (!picked) { toast('Pick an answer first.', true); return; }
          answer(parseInt(picked.value, 10));
        });
        addActions(box, [button('Leave', 'secondary', function () { box.closeOverlay(); }), checkBtn]);
      }

      function showFeedback(q, index, fb) {
        state.lives = fb.lives;
        header(index);
        var qWrap = el('div', 'quiz-q');
        qWrap.appendChild(el('div', 'qtext', q.prompt));
        var f = el('div', 'quiz-feedback ' + (fb.correct ? 'good' : 'bad'));
        f.appendChild(el('strong', null, fb.correct ? 'Correct!' : 'Not quite.'));
        if (fb.correct_label !== undefined) {
          if (!fb.correct) f.appendChild(el('div', null, 'Correct answer: ' + fb.correct_label));
          if (fb.explanation) f.appendChild(el('div', 'why', fb.explanation));
        }
        if (r.mode === 'survival' && !fb.correct) {
          f.appendChild(el('div', null, state.lives > 0 ? 'You lost a life. Lives left: ' + hearts(state.lives) : 'You are out of lives.'));
        }
        if (r.mode === 'adaptive' && fb.next) {
          var names = { 1: 'Easy', 2: 'Medium', 3: 'Hard' };
          f.appendChild(el('div', null, 'Next question level: ' + (names[fb.level] || 'Medium')));
        }
        qWrap.appendChild(f);
        box.appendChild(qWrap);

        var last = !!(fb.result || fb.summary);
        addActions(box, [button(last ? 'See results' : 'Next question', '', function () {
          if (fb.result) showResult(box, fb.result);
          else if (fb.summary) showSummary(box, fb.summary);
          else showQuestion(fb.next, fb.index);
        })]);
      }

      showQuestion(r.first, 1);
    }

    function beginRun(data, btn) {
      if (btn) btn.disabled = true;
      send('run_start', data).then(function (r) {
        if (btn) btn.disabled = false;
        if (r.batch) runBatch(r); else runSteps(r);
      }).catch(function (err) {
        if (btn) btn.disabled = false;
        toast(err.message, true);
      });
    }

    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action]');
      if (!btn) return;
      var action = btn.dataset.action;

      if (action === 'start-quiz') {
        beginRun({ type: 'checkpoint', quizID: btn.dataset.quiz }, btn);

      } else if (action === 'start-practice') {
        var hide = document.getElementById('hideChoices');
        var pm = (btn.dataset.mode === 'study' && hide && hide.checked) ? 'flash' : btn.dataset.mode;
        beginRun({ type: 'practice', taskID: btn.dataset.task, mode: pm }, btn);

      } else if (action === 'mark-finished') {
        if (window.confirm('Mark this topic as finished?')) reloadAfter('mark_finished', { taskID: btn.dataset.task });
      }
    });

    // student: project submission (file and/or link)
    var projForm = wrap.querySelector('.project-submit-form');
    if (projForm) {
      projForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var subType = projForm.dataset.subType || 'both';
        var linkEl  = projForm.elements.link_url;
        var fileEl  = projForm.elements.file;
        var notesEl = projForm.elements.notes;
        var linkVal = linkEl ? linkEl.value.trim() : '';
        var fileObj = (fileEl && fileEl.files) ? fileEl.files[0] : null;

        if (subType === 'link' && !linkVal) { toast('Paste a link to your project first.', true); return; }
        if (subType === 'file' && !fileObj && !wrap.querySelector('.proj-sub-status')) { toast('Choose a file to upload.', true); return; }
        if (subType === 'both' && !linkVal && !fileObj && !wrap.querySelector('.proj-sub-status')) { toast('Attach a file or paste a link to your project.', true); return; }

        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'submit_project');
        fd.append('projectID', projForm.dataset.project);
        fd.append('link_url', linkVal);
        fd.append('notes', notesEl ? notesEl.value.trim() : '');
        if (fileObj) fd.append('file', fileObj);

        var btn   = projForm.querySelector('button[type="submit"]');
        var bar   = projForm.querySelector('.upload-progress');
        var fill  = bar ? bar.firstElementChild : null;
        var label = projForm.querySelector('.upload-label');

        function reset() {
          if (btn) btn.disabled = false;
          if (bar) bar.hidden = true;
          if (label) label.textContent = '';
        }

        if (btn) btn.disabled = true;
        if (bar) { bar.hidden = false; if (fill) fill.style.width = '0%'; }
        if (label) label.textContent = fileObj ? 'Uploading... 0%' : 'Submitting...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint);
        xhr.withCredentials = true;
        xhr.upload.onprogress = function (ev) {
          if (!ev.lengthComputable || !fill) return;
          var pct = Math.round(ev.loaded / ev.total * 100);
          fill.style.width = pct + '%';
          if (label) label.textContent = pct < 100 ? 'Uploading... ' + pct + '%' : 'Saving...';
        };
        xhr.onload = function () {
          var json = null;
          try { json = JSON.parse(xhr.responseText); } catch (err) {}
          if (xhr.status >= 200 && xhr.status < 300 && json && json.ok) {
            window.location.reload();
          } else {
            toast((json && json.error) || 'Submission failed (HTTP ' + xhr.status + ').', true);
            reset();
          }
        };
        xhr.onerror = function () { toast('Network error while submitting. Please try again.', true); reset(); };
        xhr.send(fd);
      });
    }
  }

  /* =====================================================================
     FACULTY: lesson editor, materials, checkpoint builder
  ===================================================================== */
  function initStaff() {
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
          + 'Save quill.js and quill.snow.css into View/lib/quill/, or check your internet connection, then refresh with Ctrl+Shift+R.</p>';
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

    // actions that reload the page: save the document first so nothing is lost
    function run(action, data) {
      return saveNow().then(function () {
        return send(action, data);
      }).then(function () {
        window.location.reload();
      }).catch(function (err) {
        toast(err.message, true);
      });
    }

    /* ----- add material: type switch + upload with progress ----- */
    var ACCEPT = { pdf: '.pdf,application/pdf' };

    function syncContentForm(form) {
      var isVideo = form.elements.kind.value === 'video';       // a recorded lesson is a link, a PDF is an upload
      form.querySelector('.cf-file').classList.toggle('is-hidden', isVideo);
      form.querySelector('.cf-url').classList.toggle('is-hidden', !isVideo);
      form.elements.file.setAttribute('accept', ACCEPT.pdf);
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

        if (kind === 'video') {
          var link = form.elements.url.value.trim();
          if (!link) { toast('Paste the link to the recording first.', true); return; }
          fd.append('url', link);
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

    /* ----- checkpoint builder: settings + mode picker ----- */
    var MODE_HELP = {
      standard: 'Students answer every question on one page, then submit. Results appear at the end.',
      instant: 'One question at a time. Students see the right answer and your explanation straight after each question. Best for learning.',
      timed: 'A countdown runs, answers are locked until they submit, and nothing is shown until the end.',
      confidence: 'Older mode: students rate how sure they are. Sure and right earns more; sure and wrong loses points.',
      adaptive: 'Older mode: questions get harder after a correct answer and easier after a miss.',
      survival: 'Older mode: students have limited lives and each wrong answer costs one.'
    };

    function syncMode(form) {
      var mode = form.elements.mode.value;
      form.querySelector('.mode-desc').textContent = MODE_HELP[mode] || '';
      form.querySelectorAll('.mode-only').forEach(function (n) {
        n.classList.toggle('is-hidden', !n.classList.contains('mode-' + mode));
      });
    }

    wrap.querySelectorAll('.quiz-settings').forEach(function (form) {
      syncMode(form);
      form.elements.mode.addEventListener('change', function () { syncMode(form); });

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {
          taskID: form.dataset.task,
          kind: form.dataset.kind,
          title: form.elements.title.value.trim(),
          mode: form.elements.mode.value,
          pass_percent: form.elements.pass_percent.value,
          cooldown_minutes: form.elements.cooldown_minutes.value,
          draw_count: form.elements.draw_count.value,
          time_limit_minutes: form.elements.time_limit_minutes.value,
          allow_practice: form.elements.allow_practice.checked ? '1' : '0',
          published: form.elements.published.checked ? '1' : '0'
        };
        run('save_quiz', data);
      });
    });

    /* ----- project builder settings ----- */
    var projSettings = wrap.querySelector('.project-settings');
    if (projSettings) {
      projSettings.addEventListener('submit', function (e) {
        e.preventDefault();
        run('save_project', {
          taskID: projSettings.dataset.task,
          title: projSettings.elements.title.value.trim(),
          instructions: projSettings.elements.instructions.value.trim(),
          submission_type: projSettings.elements.submission_type.value,
          max_score: projSettings.elements.max_score.value,
          pass_score: projSettings.elements.pass_score.value,
          require_approval: projSettings.elements.require_approval.checked ? '1' : '0',
          published: projSettings.elements.published.checked ? '1' : '0'
        });
      });
    }

    function openGradeModal(sub) {
      var overlay = el('div', 'quiz-overlay');
      var box = el('div', 'quiz-box');
      overlay.appendChild(box);
      document.body.appendChild(overlay);

      box.appendChild(el('h2', null, 'Project Submission — ' + sub.student));
      box.appendChild(el('div', 'sub', 'Submitted ' + sub.date));

      if (sub.link) {
        var linkP = el('p');
        var a = el('a', null, 'Open submitted link: ' + sub.link);
        a.href = sub.link;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.style.color = '#6d28d9';
        linkP.appendChild(a);
        box.appendChild(linkP);
      }
      if (sub.file && sub.fileUrl) {
        var fileP = el('p');
        var fa = el('a', null, 'Download submitted file: ' + sub.file);
        fa.href = sub.fileUrl;
        fa.style.color = '#6d28d9';
        fileP.appendChild(fa);
        box.appendChild(fileP);
      }
      if (sub.notes) {
        var nBox = el('div', 'quiz-feedback');
        nBox.style.background = '#f3f0fa';
        nBox.style.color = '#1f1f1f';
        nBox.appendChild(el('strong', null, 'Student notes:'));
        var nTxt = el('div', null, sub.notes);
        nTxt.style.whiteSpace = 'pre-wrap';
        nBox.appendChild(nTxt);
        box.appendChild(nBox);
      }

      var form = el('form');
      var stLabel = el('label', null, 'Decision ');
      stLabel.style.display = 'block';
      stLabel.style.marginBottom = '10px';
      var stSel = document.createElement('select');
      stSel.name = 'status';
      stSel.style.cssText = 'display:block;width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;';
      [['approved', 'Approved (Pass)'], ['revision', 'Request revision'], ['submitted', 'Submitted (Pending)']].forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if (sub.status === opt[0]) o.selected = true;
        stSel.appendChild(o);
      });
      stLabel.appendChild(stSel);
      form.appendChild(stLabel);

      var scLabel = el('label', null, 'Score (0 to ' + sub.max + ', optional)');
      scLabel.style.display = 'block';
      scLabel.style.marginBottom = '10px';
      var scIn = document.createElement('input');
      scIn.type = 'number';
      scIn.name = 'score';
      scIn.min = '0';
      scIn.max = String(sub.max);
      scIn.value = sub.score != null ? String(sub.score) : '';
      scIn.style.cssText = 'display:block;width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;';
      scLabel.appendChild(scIn);
      form.appendChild(scLabel);

      var fbLabel = el('label', null, 'Feedback for student (optional)');
      fbLabel.style.display = 'block';
      fbLabel.style.marginBottom = '12px';
      var fbIn = document.createElement('textarea');
      fbIn.name = 'feedback';
      fbIn.rows = 3;
      fbIn.value = sub.feedback || '';
      fbIn.style.cssText = 'display:block;width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;font-family:inherit;';
      fbLabel.appendChild(fbIn);
      form.appendChild(fbLabel);

      var actions = el('div', 'quiz-actions');
      var cancelBtn = el('button', 'quiz-btn secondary', 'Cancel');
      cancelBtn.type = 'button';
      cancelBtn.addEventListener('click', function () { overlay.remove(); });
      var saveBtn = el('button', 'quiz-btn', 'Save grade');
      saveBtn.type = 'submit';
      actions.appendChild(cancelBtn);
      actions.appendChild(saveBtn);
      form.appendChild(actions);

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        saveBtn.disabled = true;
        run('grade_project', {
          submissionID: sub.id,
          status: stSel.value,
          score: scIn.value,
          feedback: fbIn.value.trim()
        }).then(function () { overlay.remove(); });
      });

      box.appendChild(form);
    }

    /* ----- checkpoint builder: questions ----- */
    var LETTERS = 'ABCDEF';

    function choiceRow(index, text, checked) {
      var label = el('label', 'choice-row');
      var radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'correct';
      radio.value = index;
      radio.checked = !!checked;
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'choice';
      input.className = 'syl-input grow';
      input.maxLength = 500;
      input.placeholder = 'Answer choice ' + LETTERS.charAt(index);
      input.value = text || '';
      var remove = el('button', 'icon-btn choice-remove', '\u2715');
      remove.type = 'button';
      remove.title = 'Remove this choice';
      remove.setAttribute('aria-label', 'Remove this choice');
      remove.dataset.action = 'remove-choice';
      label.appendChild(radio);
      label.appendChild(input);
      label.appendChild(remove);
      return label;
    }

    function setChoiceRows(form, labels, correctIdx) {
      var holder = form.querySelector('.choice-rows');
      holder.innerHTML = '';
      var count = Math.max(2, labels.length);
      for (var i = 0; i < count; i++) {
        holder.appendChild(choiceRow(i, labels[i] || '', i === correctIdx));
      }
    }

    function reindexChoices(form) {
      form.querySelectorAll('.choice-row').forEach(function (row, i) {
        row.querySelector('input[type="radio"]').value = i;
        row.querySelector('input[type="text"]').placeholder = 'Answer choice ' + LETTERS.charAt(i);
      });
    }

    wrap.querySelectorAll('.question-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var prompt = form.elements.prompt.value.trim();
        if (!prompt) { toast('Write the question first.', true); return; }

        var choices = [];
        var correct = -1;
        var blankCorrect = false;
        form.querySelectorAll('.choice-row').forEach(function (row) {
          var text = row.querySelector('input[type="text"]').value.trim();
          var isCorrect = row.querySelector('input[type="radio"]').checked;
          if (text === '') { if (isCorrect) blankCorrect = true; return; }
          if (isCorrect) correct = choices.length;
          choices.push(text);
        });
        if (blankCorrect || correct < 0) { toast('Select a filled-in choice as the correct answer.', true); return; }
        if (choices.length < 2) { toast('Give at least two answer choices.', true); return; }

        run('save_question', {
          quizID: form.dataset.quiz,
          questionID: form.elements.questionID.value,
          prompt: prompt,
          choices: JSON.stringify(choices),
          correct: correct,
          explanation: form.elements.explanation.value.trim(),
          difficulty: form.elements.difficulty.value
        });
      });
    });

    /* ----- bulk question import (paste or CSV) ----- */
    function stripNumbering(line) {
      return line.replace(/^\s*(?:Q\s*)?\d+\s*[\.\):\-]\s*/i, '').replace(/^\s*Q\s*[:.]\s*/i, '');
    }

    // blocks separated by a blank line: question, choices (A) ...), * marks the correct one, optional "Explanation:"
    function parseBlocks(text) {
      var items = [];
      var errors = [];
      var blocks = text.replace(/\r\n/g, '\n').split(/\n\s*\n/);
      var n = 0;
      blocks.forEach(function (raw) {
        var lines = raw.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l !== ''; });
        if (!lines.length) return;
        n++;
        var promptLines = [];
        var choices = [];
        var stars = [];
        var explanation = [];
        var inExplain = false;
        var answerLetter = '';

        lines.forEach(function (line) {
          var ex = /^(?:explanation|why|reason)\s*:\s*(.*)$/i.exec(line);
          var an = /^(?:answer|correct)\s*:\s*([A-Fa-f])\b/i.exec(line);
          var ch = /^([A-Fa-f])[\)\.]\s+(.*)$/.exec(line);
          if (ex) { inExplain = true; explanation.push(ex[1]); }
          else if (inExplain) { explanation.push(line); }
          else if (an) { answerLetter = an[1].toUpperCase(); }
          else if (ch) {
            var text = ch[2];
            var star = false;
            if (/\s*\*\s*$/.test(text)) { star = true; text = text.replace(/\s*\*\s*$/, ''); }
            else if (/\(correct\)\s*$/i.test(text)) { star = true; text = text.replace(/\s*\(correct\)\s*$/i, ''); }
            choices.push(text.trim());
            if (star) stars.push(choices.length - 1);
          }
          else if (!choices.length) { promptLines.push(line); }
          else { choices[choices.length - 1] += ' ' + line; }
        });

        var prompt = stripNumbering(promptLines.join(' ')).trim();
        var correct = stars.length === 1 ? stars[0] : -1;
        if (answerLetter && !stars.length) correct = answerLetter.charCodeAt(0) - 65;

        if (!prompt) { errors.push('Question ' + n + ': the question text is missing.'); return; }
        if (choices.length < 2 || choices.length > 6) { errors.push('Question ' + n + ': it needs between 2 and 6 choices (A) B) C) ...).'); return; }
        if (stars.length > 1) { errors.push('Question ' + n + ': only one choice can have a *.'); return; }
        if (correct < 0 || correct >= choices.length) { errors.push('Question ' + n + ': mark the correct choice with a * (or add a line like "Answer: B").'); return; }
        items.push({ prompt: prompt, choices: choices, correct: correct, explanation: explanation.join(' ').trim() });
      });
      return { items: items, errors: errors };
    }

    function parseCsvRows(text) {
      var rows = [], row = [], field = '', inQ = false;
      for (var i = 0; i < text.length; i++) {
        var c = text.charAt(i);
        if (inQ) {
          if (c === '"') { if (text.charAt(i + 1) === '"') { field += '"'; i++; } else { inQ = false; } }
          else field += c;
        } else if (c === '"') { inQ = true; }
        else if (c === ',') { row.push(field); field = ''; }
        else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
        else if (c !== '\r') { field += c; }
      }
      if (field !== '' || row.length) { row.push(field); rows.push(row); }
      return rows;
    }

    // columns: question, choice A..F, correct (A-F or 1-6), explanation
    function parseCsvQuestions(text) {
      var rows = parseCsvRows(text.replace(/^\uFEFF/, '')).filter(function (r) { return r.join('').trim() !== ''; });
      var items = [];
      var errors = [];
      rows.forEach(function (r, idx) {
        if (idx === 0 && /^question$/i.test((r[0] || '').trim())) return;     // header row
        var n = items.length + errors.length + 1;
        var prompt = (r[0] || '').trim();
        var choices = [];
        var position = {};                                                    // original column -> index after skipping blanks
        for (var j = 1; j <= 6; j++) {
          var cell = (r[j] || '').trim();
          if (cell !== '') { position[j - 1] = choices.length; choices.push(cell); }
        }
        var raw = (r[7] || '').trim().toUpperCase();
        var col = /^[A-F]$/.test(raw) ? raw.charCodeAt(0) - 65 : (/^[1-6]$/.test(raw) ? parseInt(raw, 10) - 1 : -1);
        if (!prompt) { errors.push('Row ' + n + ': the question text is missing.'); return; }
        if (choices.length < 2) { errors.push('Row ' + n + ': it needs at least 2 choices.'); return; }
        if (col < 0 || position[col] === undefined) { errors.push('Row ' + n + ': the "correct" column must be A-F and point to a filled-in choice.'); return; }
        items.push({ prompt: prompt, choices: choices, correct: position[col], explanation: (r[8] || '').trim() });
      });
      return { items: items, errors: errors };
    }

    function runImport(box) {
      var paste = box.querySelector('[name="paste"]').value.trim();
      var file  = box.querySelector('[name="csv"]').files[0];

      function finish(res) {
        if (res.errors.length) {
          toast(res.errors[0] + (res.errors.length > 1 ? ' (and ' + (res.errors.length - 1) + ' more)' : ''), true);
          return;
        }
        if (!res.items.length) { toast('No questions were found.', true); return; }
        if (!window.confirm('Import ' + res.items.length + ' question' + (res.items.length === 1 ? '' : 's') + '?')) return;
        run('import_questions', { quizID: box.dataset.quiz, questions: JSON.stringify(res.items) });
      }

      if (file) {
        var reader = new FileReader();
        reader.onload = function () { finish(parseCsvQuestions(String(reader.result))); };
        reader.onerror = function () { toast('Could not read that file.', true); };
        reader.readAsText(file);
      } else if (paste) {
        finish(parseBlocks(paste));
      } else {
        toast('Paste your questions or choose a CSV file first.', true);
      }
    }

    function downloadCsvTemplate() {
      var text = 'question,choice A,choice B,choice C,choice D,choice E,choice F,correct,explanation\r\n'
        + 'What is 2 + 2?,3,4,5,6,,,B,Basic addition.\r\n';
      var blob = new Blob([text], { type: 'text/csv' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'questions_template.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
    }

    /* ----- material + builder buttons (event delegation) ----- */
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
        var urlEl = item.querySelector('[name="url"]');
        run('edit_content', {
          contentID: btn.dataset.content,
          title: cTitle,
          description: item.querySelector('[name="description"]').value.trim(),
          body: bodyEl ? bodyEl.value : '',
          url: urlEl ? urlEl.value.trim() : ''
        });

      } else if (action === 'toggle-publish') {
        run('toggle_publish', { contentID: btn.dataset.content, published: btn.dataset.publish });

      } else if (action === 'move-content') {
        run('move_content', { contentID: btn.dataset.content, dir: btn.dataset.dir });

      } else if (action === 'delete-content') {
        if (window.confirm('Delete this material? Uploaded files are removed permanently.')) {
          run('delete_content', { contentID: btn.dataset.content });
        }

      } else if (action === 'delete-quiz') {
        if (window.confirm('Delete this checkpoint with all its questions and student attempts? This cannot be undone.')) {
          run('delete_quiz', { quizID: btn.dataset.quiz });
        }

      } else if (action === 'add-choice') {
        var addForm = btn.closest('.question-form');
        var rows = addForm.querySelectorAll('.choice-row');
        if (rows.length >= 6) { toast('A question can have up to 6 choices.', true); return; }
        addForm.querySelector('.choice-rows').appendChild(choiceRow(rows.length, '', false));

      } else if (action === 'remove-choice') {
        var rmForm = btn.closest('.question-form');
        if (rmForm.querySelectorAll('.choice-row').length <= 2) { toast('A question needs at least 2 choices.', true); return; }
        var rmRow = btn.closest('.choice-row');
        var wasCorrect = rmRow.querySelector('input[type="radio"]').checked;
        rmRow.remove();
        if (wasCorrect) rmForm.querySelector('.choice-row input[type="radio"]').checked = true;
        reindexChoices(rmForm);

      } else if (action === 'true-false') {
        setChoiceRows(btn.closest('.question-form'), ['True', 'False'], 0);

      } else if (action === 'edit-question') {
        var qItem = btn.closest('.q-item');
        var card  = btn.closest('.assess-card');
        var qForm = card.querySelector('.question-form');
        var q;
        try { q = JSON.parse(qItem.dataset.q); } catch (err) { return; }
        qForm.elements.questionID.value = q.id;
        qForm.elements.prompt.value = q.prompt;
        qForm.elements.explanation.value = q.explanation || '';
        qForm.elements.difficulty.value = String(q.difficulty || 2);
        if ((q.explanation || '') !== '' || (q.difficulty || 2) !== 2) qForm.querySelector('.more-opts').open = true;
        setChoiceRows(qForm, q.choices, q.correct);
        qForm.querySelector('.qf-title').textContent = 'Edit question';
        qForm.querySelector('[data-action="cancel-question"]').classList.remove('is-hidden');
        qForm.scrollIntoView({ block: 'center', behavior: 'smooth' });

      } else if (action === 'cancel-question') {
        window.location.reload();

      } else if (action === 'delete-question') {
        if (window.confirm('Delete this question?')) run('delete_question', { questionID: btn.dataset.question });

      } else if (action === 'duplicate-question') {
        run('duplicate_question', { questionID: btn.dataset.question });

      } else if (action === 'import-questions') {
        runImport(btn.closest('.import-box'));

      } else if (action === 'csv-template') {
        downloadCsvTemplate();

      } else if (action === 'reveal-assess') {
        var target = document.getElementById(btn.dataset.target);
        if (target) {
          target.classList.remove('is-hidden');
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          btn.classList.add('is-hidden');
        }

      } else if (action === 'delete-project') {
        if (window.confirm('Delete this project and all student submissions? This cannot be undone.')) {
          run('delete_project', { projectID: btn.dataset.project });
        }

      } else if (action === 'grade-project') {
        var subObj = null;
        try { subObj = JSON.parse(btn.dataset.sub); } catch (err) { return; }
        if (subObj) openGradeModal(subObj);
      }
    });
  }

  if (isStudent) initStudent();
  if (canEdit) initStaff();
})();
