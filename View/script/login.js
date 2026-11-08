
/*
   Success messages -> toast (top-right, disappears by itself)
   Error messages   -> banner inside the login card
   This file only improves the experience. PHP (login_logic.php) repeats every check,
   because anyone can bypass JavaScript. */
 
(function () {
  'use strict';
 
  const form      = document.getElementById('login-form');
  const idInput     = document.getElementById('school-id');
  const password  = document.getElementById('password');
  const toggleBtn = document.getElementById('toggle-password');
  const alertBox  = document.getElementById('alert');          // banner (errors)
  const region    = document.getElementById('toast-region');   // toast container (success)
 
  /* ---------- 1. Show / hide password (the eye button) ---------- */
  toggleBtn.addEventListener('click', function () {
    const willShow = password.type === 'password';
 
    password.type = willShow ? 'text' : 'password';
    toggleBtn.classList.toggle('is-visible', willShow);
    toggleBtn.setAttribute('aria-pressed', String(willShow));
    toggleBtn.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
 
    password.focus();
  });
 
  /* ---------- 2a. Banner (errors) ---------- */
  function showAlert(type, text) {
    alertBox.className = 'alert alert--' + type;   // 'error' or 'success'
    alertBox.textContent = text;                   // textContent, never innerHTML: text can't run as code
    alertBox.hidden = false;
  }
 
  /* ---------- 2b. Toast (success / info) ----------
     showToast('success', 'Saved!');   showToast('info', 'Heads up', 4000);   (3rd value = milliseconds) */
  const ICONS = {   // fixed, trusted SVG strings (user text is never put in here)
    error:   '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>',
    success: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/></svg>',
    info:    '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/></svg>'
  };
  const MAX_TOASTS = 3;
 
  function showToast(type, text, duration) {
    if (!region) { showAlert(type === 'error' ? 'error' : 'success', text); return; }   // no container: use the banner
 
    if (!ICONS[type]) type = 'info';
    duration = duration || (type === 'error' ? 6000 : 5000);
 
    const existing = Array.prototype.find.call(region.children, function (t) {
      return t.dataset.text === text && !t.classList.contains('is-leaving');
    });
    if (existing) return;                                          // same message is already showing
 
    while (region.children.length >= MAX_TOASTS) region.firstElementChild.remove();
 
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
      toast.addEventListener('animationend', function () { toast.remove(); }, { once: true });
      setTimeout(function () { toast.remove(); }, 400);            // fallback if animations are off
    }
 
    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('mouseleave', resume);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', resume);
    close.addEventListener('click', dismiss);
  }
 
  /* ---------- 3. Messages from the server ----------
     login.php copies the session messages into data-flash-* attributes on <body>.
     logout() redirects to /login?status=loggedout. */
  const flashError   = document.body.dataset.flashError;
  const flashMessage = document.body.dataset.flashMessage;
 
  if (flashError)   showAlert('error', flashError);
  if (flashMessage) showToast('info', flashMessage);
 
  const status = new URLSearchParams(window.location.search).get('status');
  if (status === 'loggedout') {
    showToast('success', 'You have been logged out.');
    // remove ?status=... so a page refresh doesn't show the toast again
    try { window.history.replaceState(null, '', window.location.pathname); } catch (e) { /* ignore */ }
  }
 
  /* ---------- 4. Check the form before it is sent ---------- */
  // School ID: letters, digits or hyphens, 4-20 characters (e.g. 202600001, F202600001)
  const validId = /^[A-Za-z0-9-]{4,20}$/;
 
  [idInput, password].forEach(function (input) {
    input.addEventListener('input', function () {
      input.closest('.field').classList.remove('is-invalid');   // clear the red border once the user edits the field
    });
  });
 
  form.addEventListener('submit', function (event) {
    const idValue = idInput.value.trim();
 
    idInput.closest('.field').classList.remove('is-invalid');
    password.closest('.field').classList.remove('is-invalid');
 
    if (idValue === '' || password.value === '') {
      event.preventDefault();
      if (idValue === '') idInput.closest('.field').classList.add('is-invalid');
      if (password.value === '') password.closest('.field').classList.add('is-invalid');
      showAlert('error', 'Please enter your school ID and password.');
      return;
    }
 
    if (!validId.test(idValue)) {
      event.preventDefault();
      idInput.closest('.field').classList.add('is-invalid');
      showAlert('error', 'Enter a valid school ID, e.g. 202600001.');
    }
    // otherwise the form is sent to POST /login
  });
})();