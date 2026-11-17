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
     STUDENT: study-again countdown, mark finished, mini quiz / mock exam
  ===================================================================== */
  function initStudent() {
    function reloadAfter(action, data) {
      return send(action, data).then(function () {
        window.location.reload();
      }).catch(function (err) { toast(err.message, true); });
    }

    // forced re-study banner: the retake unlocks after the study time
    var banner = document.getElementById('restudyBanner');
    if (banner) {
      var end = Date.now() + (parseInt(banner.dataset.seconds, 10) || 0) * 1000;
      var timerEl = document.getElementById('restudyTimer');
      var btn = document.getElementById('finishReviewBtn');
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      (function tick() {
        var left = Math.max(0, Math.round((end - Date.now()) / 1000));
        timerEl.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
        btn.disabled = left > 0;
        if (left > 0) setTimeout(tick, 500);
      })();
    }

    function showResult(box, r) {
      box.innerHTML = '';
      var res = el('div', 'quiz-result ' + (r.passed ? 'pass' : 'fail'));
      res.appendChild(el('h2', null, r.passed ? 'You passed!' : 'Not yet'));
      res.appendChild(el('div', 'score', r.score + '%'));
      res.appendChild(el('div', 'sub', r.correct + ' of ' + r.total + ' correct. Pass mark: ' + r.pass_percent + '%'));

      if (r.passed) {
        res.appendChild(el('p', null, r.completed
          ? 'This topic is now completed.'
          : (r.kind === 'quiz' ? 'Well done! The mock exam is now unlocked.' : 'Well done!')));
        if (r.review && r.review.length) {
          var ul = el('ul', 'quiz-review');
          r.review.forEach(function (it) {
            var li = el('li');
            li.appendChild(el('div', null, it.prompt));
            li.appendChild(el('div', 'good', 'Correct answer: ' + it.correct));
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
      var actions = el('div', 'quiz-actions');
      var close = el('button', 'quiz-btn', 'Close');
      close.type = 'button';
      close.addEventListener('click', function () { window.location.reload(); });
      actions.appendChild(close);
      res.appendChild(actions);
      box.appendChild(res);
    }

    function showQuiz(quiz) {
      var overlay = el('div', 'quiz-overlay');
      var box = el('div', 'quiz-box');
      overlay.appendChild(box);
      box.appendChild(el('h2', null, quiz.title));
      box.appendChild(el('div', 'sub', 'Pass mark: ' + quiz.pass_percent + '% \u00B7 ' + quiz.questions.length
        + ' question' + (quiz.questions.length === 1 ? '' : 's') + '. Answer every question, then submit.'));

      var form = el('form');
      quiz.questions.forEach(function (q, i) {
        var qWrap = el('div', 'quiz-q');
        qWrap.appendChild(el('div', 'qtext', (i + 1) + '. ' + q.prompt));
        q.choices.forEach(function (c) {
          var label = el('label');
          var input = document.createElement('input');
          input.type = 'radio';
          input.name = 'q' + q.id;
          input.value = c.id;
          label.appendChild(input);
          label.appendChild(el('span', null, c.label));
          qWrap.appendChild(label);
        });
        form.appendChild(qWrap);
      });

      var actions = el('div', 'quiz-actions');
      var cancel = el('button', 'quiz-btn secondary', 'Cancel');
      cancel.type = 'button';
      cancel.addEventListener('click', function () { overlay.remove(); });
      var submit = el('button', 'quiz-btn', 'Submit answers');
      submit.type = 'submit';
      actions.appendChild(cancel);
      actions.appendChild(submit);
      form.appendChild(actions);

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var answers = {};
        var missing = false;
        quiz.questions.forEach(function (q) {
          var checked = form.querySelector('input[name="q' + q.id + '"]:checked');
          if (!checked) missing = true; else answers[q.id] = parseInt(checked.value, 10);
        });
        if (missing) { toast('Answer every question first.', true); return; }
        submit.disabled = true;
        send('quiz_submit', { quizID: quiz.id, answers: JSON.stringify(answers) })
          .then(function (r) { showResult(box, r); })
          .catch(function (err) { toast(err.message, true); submit.disabled = false; });
      });

      box.appendChild(form);
      document.body.appendChild(overlay);
    }

    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action]');
      if (!btn) return;
      var action = btn.dataset.action;

      if (action === 'start-quiz') {
        btn.disabled = true;
        send('quiz_start', { quizID: btn.dataset.quiz }).then(function (r) {
          btn.disabled = false;
          showQuiz(r.quiz);
        }).catch(function (err) {
          btn.disabled = false;
          toast(err.message, true);
        });

      } else if (action === 'mark-finished') {
        if (window.confirm('Mark this topic as finished?')) reloadAfter('mark_finished', { taskID: btn.dataset.task });

      } else if (action === 'finish-review') {
        reloadAfter('finish_review', { taskID: btn.dataset.task });
      }
    });
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

    /* ----- checkpoint builder: settings ----- */
    wrap.querySelectorAll('.quiz-settings').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {
          taskID: form.dataset.task,
          kind: form.dataset.kind,
          title: form.elements.title.value.trim(),
          pass_percent: form.elements.pass_percent.value,
          cooldown_minutes: form.elements.cooldown_minutes.value,
          published: form.elements.published.checked ? '1' : '0'
        };
        if (form.elements.draw_count) data.draw_count = form.elements.draw_count.value;
        run('save_quiz', data);
      });
    });

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
      label.appendChild(radio);
      label.appendChild(input);
      return label;
    }

    function setChoiceRows(form, labels, correctIdx) {
      var holder = form.querySelector('.choice-rows');
      holder.innerHTML = '';
      var count = Math.max(4, labels.length);
      for (var i = 0; i < count; i++) {
        holder.appendChild(choiceRow(i, labels[i] || '', i === correctIdx));
      }
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
          correct: correct
        });
      });
    });

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

      } else if (action === 'delete-quiz') {
        if (window.confirm('Delete this checkpoint with all its questions and student attempts? This cannot be undone.')) {
          run('delete_quiz', { quizID: btn.dataset.quiz });
        }

      } else if (action === 'add-choice') {
        var addForm = btn.closest('.question-form');
        var rows = addForm.querySelectorAll('.choice-row');
        if (rows.length >= 6) { toast('A question can have up to 6 choices.', true); return; }
        addForm.querySelector('.choice-rows').appendChild(choiceRow(rows.length, '', false));

      } else if (action === 'edit-question') {
        var qItem = btn.closest('.q-item');
        var card  = btn.closest('.assess-card');
        var qForm = card.querySelector('.question-form');
        var q;
        try { q = JSON.parse(qItem.dataset.q); } catch (err) { return; }
        qForm.elements.questionID.value = q.id;
        qForm.elements.prompt.value = q.prompt;
        setChoiceRows(qForm, q.choices, q.correct);
        qForm.querySelector('.qf-title').textContent = 'Edit question';
        qForm.querySelector('[data-action="cancel-question"]').classList.remove('is-hidden');
        qForm.scrollIntoView({ block: 'center', behavior: 'smooth' });

      } else if (action === 'cancel-question') {
        window.location.reload();

      } else if (action === 'delete-question') {
        if (window.confirm('Delete this question?')) run('delete_question', { questionID: btn.dataset.question });
      }
    });
  }

  if (isStudent) initStudent();
  if (canEdit) initStaff();
})();
