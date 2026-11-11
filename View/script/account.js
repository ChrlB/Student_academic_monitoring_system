/* View/script/account.js : opens/closes the two popups on the account page. */
(function () {
  'use strict';
  var pw = document.getElementById('password-dialog');
  var pr = document.getElementById('profile-dialog');

  function open(d)  { if (d && typeof d.showModal === 'function' && !d.open) d.showModal(); }
  function close(d) {
    if (!d) return;
    d.close();
    var f = d.querySelector('form'); if (f) f.reset();
    resetPassword();
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-action]');
    if (t && t.dataset.action === 'change-password') open(pw);
    if (t && t.dataset.action === 'edit-profile')    open(pr);
    var c = e.target.closest('[data-close]');
    if (c) close(c.closest('dialog'));
    // click on the dark backdrop closes too
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('dlg')) close(e.target);
  });

  // eye toggles
  document.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = b.parentElement.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', String(show));
      b.classList.toggle('on', show);
    });
  });
  function resetPassword() {
    if (!pw) return;
    pw.querySelectorAll('.pw-box input').forEach(function (i) { i.type = 'password'; });
    pw.querySelectorAll('[data-eye]').forEach(function (b) { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); });
    var m = document.getElementById('pw-msg');
    if (m) { m.textContent = m.dataset['default']; m.classList.remove('err'); }
  }

  // client checks (the server checks again)
  if (pw) {
    var msg = document.getElementById('pw-msg');
    pw.querySelector('form').addEventListener('submit', function (e) {
      var inputs = pw.querySelectorAll('.pw-box input');
      var cur = inputs[0].value, nw = inputs[1].value, cf = inputs[2].value, err = '';
      if (!cur || !nw || !cf) err = msg.dataset['default'];
      else if (nw.length < 8) err = 'Password must be at least eight (8) characters or more.';
      else if (nw !== cf) err = 'New password and confirm password do not match.';
      if (err) { e.preventDefault(); msg.textContent = err; msg.classList.add('err'); return; }
      lock(e.target);
    });
  }
  if (pr) {
    pr.querySelector('form').addEventListener('submit', function (e) { lock(e.target); });
  }
  function lock(form) {   // stop double submits
    var b = form.querySelector('button[type=submit]');
    if (b) setTimeout(function () { b.disabled = true; }, 0);
  }

  // the server sent us back with an error: reopen that popup
  document.querySelectorAll('dialog[data-open]').forEach(open);
})();