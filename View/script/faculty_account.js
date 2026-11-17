(function () {
  'use strict';

  var root = document.querySelector('[data-faculty]');
  if (!root) return;

  var saveUrl = root.dataset.saveUrl;
  var csrf = root.dataset.csrf;

  var toggleBtn = root.querySelector('[data-action="toggle-info"]');
  var cancelBtn = root.querySelector('[data-action="cancel-info"]');
  var toggleLabel = toggleBtn.querySelector('span');
  var fields = [].slice.call(root.querySelectorAll('[data-editable]'));
  var editing = false;
  var busy = false;
  var snapshot = {};

  /* ---------- toast ---------- */
  var toastEl = document.getElementById('fa-toast');
  var toastTimer = null;
  function toast(text, isError) {
    if (!toastEl) return;
    toastEl.textContent = text; // textContent: never HTML
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(
      function () {
        toastEl.hidden = true;
      },
      isError ? 5000 : 2800,
    );
  }

  /* ---------- locking / unlocking the boxes ---------- */
  function wrap(el) {
    return el.closest('.f');
  }
  function clearErrors() {
    root.querySelectorAll('.f.has-error').forEach(function (w) {
      w.classList.remove('has-error');
    });
    root.querySelectorAll('[aria-invalid]').forEach(function (el) {
      el.removeAttribute('aria-invalid');
    });
    root.querySelectorAll('.f-err').forEach(function (m) {
      m.hidden = true;
      m.textContent = '';
    });
  }
  function showError(key, text) {
    var el = root.querySelector('[data-field="' + key + '"]');
    var msg = root.querySelector('[data-err-for="' + key + '"]');
    if (el) {
      wrap(el).classList.add('has-error');
      el.setAttribute('aria-invalid', 'true');
    }
    if (msg) {
      msg.textContent = text;
      msg.hidden = false;
    }
  }
  function setLocked(el, locked) {
    var isDate = el.dataset.kind === 'date';
    if (el.tagName === 'SELECT') el.disabled = locked;
    else el.readOnly = locked;
    if (isDate) el.type = locked ? 'text' : 'date'; // text keeps the 1978-05-14 look in view mode
    wrap(el).classList.toggle('is-editing', !locked);
  }
  function setEditing(on) {
    editing = on;
    root.classList.toggle('is-editing-info', on);
    fields.forEach(function (el) {
      setLocked(el, !on);
    });
    toggleBtn.classList.toggle('is-editing', on);
    toggleLabel.textContent = on ? 'Save Changes' : 'Edit Information';
    cancelBtn.hidden = !on;
    if (on) {
      // open every section so nothing that can be edited is hidden
      root.querySelectorAll('details.acc-sec').forEach(function (d) {
        d.open = true;
      });
    } else {
      clearErrors();
    }
  }

  function takeSnapshot() {
    snapshot = {};
    fields.forEach(function (el) {
      snapshot[el.dataset.field] = el.value;
    });
  }
  function applyValues(values) {
    fields.forEach(function (el) {
      var k = el.dataset.field;
      if (values && Object.prototype.hasOwnProperty.call(values, k))
        el.value = values[k];
    });
  }

  /* ---------- talking to the server ---------- */
  function post(url, params) {
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
          /* not JSON: the session probably ended and we were sent to the login page */
        }
        if (!data)
          data = {
            ok: false,
            message: 'Your session may have expired. Reload the page and log in again.',
          };
        return data;
      });
    });
  }

  function saveInfo() {
    if (busy) return;
    clearErrors();

    var params = new URLSearchParams();
    params.set('csrf_token', csrf);
    fields.forEach(function (el) {
      params.set(el.dataset.field, el.value);
    });

    busy = true;
    toggleBtn.disabled = true;
    post(saveUrl, params)
      .then(function (data) {
        if (data.ok) {
          applyValues(data.values);
          setEditing(false);
          toast(data.message || 'Changes saved.');
          return;
        }
        var first = null;
        if (data.errors) {
          Object.keys(data.errors).forEach(function (k) {
            showError(k, data.errors[k]);
            if (!first) first = k;
          });
        }
        if (first) {
          var el = root.querySelector('[data-field="' + first + '"]');
          if (el) el.focus();
        }
        toast(data.message || 'Could not save your changes.', true);
      })
      .catch(function () {
        toast('Could not reach the server. Check your connection and try again.', true);
      })
      .then(function () {
        busy = false;
        toggleBtn.disabled = false;
      });
  }

  /* ---------- Edit Information / Save Changes / Cancel ---------- */
  toggleBtn.addEventListener('click', function () {
    if (busy) return;
    if (!editing) {
      takeSnapshot();
      setEditing(true);
      toast('Fields unlocked for editing.');
      var firstEditable = fields.filter(function (el) {
        return !el.closest('.is-locked');
      })[0];
      if (firstEditable) firstEditable.focus();
    } else {
      saveInfo();
    }
  });
  cancelBtn.addEventListener('click', function () {
    if (busy) return;
    applyValues(snapshot);
    setEditing(false);
  });
  // Enter inside a box saves, Escape cancels
  root.addEventListener('keydown', function (e) {
    if (!editing || busy) return;
    var t = e.target;
    if (e.key === 'Enter' && t.matches('input[data-editable]')) {
      e.preventDefault();
      saveInfo();
    } else if (e.key === 'Escape' && t.closest('#fa-info')) {
      applyValues(snapshot);
      setEditing(false);
    }
  });
  // leaving the page with unsaved edits
  window.addEventListener('beforeunload', function (e) {
    if (!editing) return;
    var changed = fields.some(function (el) {
      return snapshot[el.dataset.field] !== el.value;
    });
    if (changed) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
})();