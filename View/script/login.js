(function () {
  'use strict';

  const form = document.getElementById('login-form');
  const idInput = document.getElementById('school-id');
  const password = document.getElementById('password');
  const toggleBtn = document.getElementById('toggle-password');
  const alertBox = document.getElementById('alert');
  const region = document.getElementById('toast-region');
  const idError = document.getElementById('school-id-error');
  const pwError = document.getElementById('password-error');

  /* ---------- 1. Show / hide password (the eye button) ---------- */
  toggleBtn.addEventListener('click', function () {
    const willShow = password.type === 'password';

    password.type = willShow ? 'text' : 'password';
    toggleBtn.classList.toggle('is-visible', willShow);
    toggleBtn.setAttribute('aria-pressed', String(willShow));
    toggleBtn.setAttribute(
      'aria-label',
      willShow ? 'Hide password' : 'Show password',
    );

    password.focus();
  });

  /* ---------- 2a. Banner (general errors) ---------- */
  function showAlert(type, text) {
    alertBox.className = 'alert alert--' + type; // 'error' or 'success'
    alertBox.textContent = text; 
    alertBox.hidden = false;
  }

  /* ---------- 2b. Toast (success / info) ---------- */
  const ICONS = {
    error:
      '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>',
    success:
      '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/></svg>',
    info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/></svg>',
  };
  const MAX_TOASTS = 3;

  function showToast(type, text, duration) {
    if (!region) {
      showAlert(type === 'error' ? 'error' : 'success', text);
      return;
    } 

    if (!ICONS[type]) type = 'info';
    duration = duration || (type === 'error' ? 6000 : 5000);

    const existing = Array.prototype.find.call(region.children, function (t) {
      return t.dataset.text === text && !t.classList.contains('is-leaving');
    });
    if (existing) return; 

    while (region.children.length >= MAX_TOASTS)
      region.firstElementChild.remove();

    const toast = document.createElement('div');
    toast.className = 'toast toast--' + type;
    toast.dataset.text = text;
    toast.style.setProperty('--duration', duration + 'ms');
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = ICONS[type];

    const msg = document.createElement('span');
    msg.className = 'toast-msg';
    msg.textContent = text;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';

    const bar = document.createElement('span');
    bar.className = 'toast-bar';
    bar.setAttribute('aria-hidden', 'true');

    toast.append(icon, msg, close, bar);
    region.appendChild(toast);

    // auto-dismiss; paused while the mouse is over the toast or it has keyboard focus
    let remaining = duration;
    let startedAt = Date.now();
    let timer = setTimeout(dismiss, remaining);

    function pause() {
      clearTimeout(timer);
      remaining -= Date.now() - startedAt;
      bar.style.animationPlayState = 'paused';
    }
    function resume() {
      startedAt = Date.now();
      timer = setTimeout(dismiss, Math.max(remaining, 800));
      bar.style.animationPlayState = 'running';
    }
    function dismiss() {
      clearTimeout(timer);
      if (toast.classList.contains('is-leaving')) return;
      toast.classList.add('is-leaving');
      toast.addEventListener(
        'animationend',
        function () {
          toast.remove();
        },
        { once: true },
      );
      setTimeout(function () {
        toast.remove();
      }, 400); // fallback if animations are off
    }

    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('mouseleave', resume);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', resume);
    close.addEventListener('click', dismiss);
  }

  /* ---------- 3. Messages from the server ---------- */
  const flashError = document.body.dataset.flashError;
  const flashMessage = document.body.dataset.flashMessage;

  if (flashError) showAlert('error', flashError);
  if (flashMessage) showToast('info', flashMessage);

  const status = new URLSearchParams(window.location.search).get('status');
  if (status === 'loggedout') {
    showToast('success', 'You have been logged out.');
    try {
      window.history.replaceState(null, '', window.location.pathname);
    } catch (e) {
      /* ignore */
    }
  }

  /* ---------- 4. Field errors ---------- */
  function setFieldError(input, errorEl, text) {
    input.closest('.field').classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
    errorEl.textContent = text; // textContent: text can't run as code
    errorEl.hidden = false;
  }

  function clearFieldError(input, errorEl) {
    input.closest('.field').classList.remove('is-invalid');
    input.setAttribute('aria-invalid', 'false');
    errorEl.textContent = '';
    errorEl.hidden = true;
  }

  // Editing a field clears only that field's error; the other field is left alone.
  idInput.addEventListener('input', function () {
    clearFieldError(idInput, idError);
  });
  password.addEventListener('input', function () {
    clearFieldError(password, pwError);
  });

  /* ---------- 5. Check the form before it is sent ---------- */
  const validId = /^[A-Za-z0-9-]{4,20}$/;

  form.addEventListener('submit', function (event) {
    const idValue = idInput.value.trim();
    let firstBad = null;

    clearFieldError(idInput, idError);
    clearFieldError(password, pwError);
    alertBox.hidden = true;

    if (idValue === '') {
      setFieldError(idInput, idError, 'Enter your School ID.');
      firstBad = idInput;
    } else if (!validId.test(idValue)) {
      setFieldError(
        idInput,
        idError,
        'Enter a valid School ID, e.g. 202600001.',
      );
      firstBad = idInput;
    }

    if (password.value === '') {
      setFieldError(password, pwError, 'Enter your password.');
      firstBad = firstBad || password;
    }

    if (firstBad) {
      event.preventDefault();
      firstBad.focus();
    }
    // otherwise the form is sent to POST /login
  });
})();
