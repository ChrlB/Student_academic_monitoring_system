(function () {
  'use strict';
  var pw = document.getElementById('password-dialog');
  var pr = document.getElementById('profile-dialog');
  var photoInput = document.getElementById('photo-input');
  var photoForm  = document.getElementById('photo-form');

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
    if (t && t.dataset.action === 'upload-photo' && photoInput && !t.classList.contains('is-busy')) photoInput.click();
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

  /* ---------- profile picture ---------- */
  var PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  var PHOTO_MAX_SIDE = 600, PHOTO_QUALITY = 0.85, PHOTO_MAX_RAW = 25 * 1024 * 1024;

  function photoNote(text) {                 // small message at the top right (textContent: safe)
    var old = document.querySelector('.photo-note'); if (old) old.remove();
    var n = document.createElement('div');
    n.className = 'photo-note'; n.setAttribute('role', 'alert'); n.textContent = text;
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 6000);
  }

  function shrinkPhoto(file) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var scale = Math.min(1, PHOTO_MAX_SIDE / Math.max(bmp.width, bmp.height));
      var w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
      var c = document.createElement('canvas'); c.width = w; c.height = h;
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);          // transparent PNGs get a white background
      ctx.drawImage(bmp, 0, 0, w, h);
      return new Promise(function (resolve) { c.toBlob(resolve, 'image/jpeg', PHOTO_QUALITY); });
    });
  }

  function sendPhoto(blob) {
    var btn = document.querySelector('[data-action="upload-photo"]');
    if (btn) btn.classList.add('is-busy');
    if (blob) {
      try {
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'profile.jpg', { type: 'image/jpeg' }));
        photoInput.files = dt.files;
      } catch (e) { /* old browser: the original picture is sent instead */ }
    }
    photoForm.submit();
  }

  if (photoInput && photoForm) {
    photoInput.addEventListener('change', function () {
      var file = photoInput.files && photoInput.files[0];
      if (!file) return;
      if (PHOTO_TYPES.indexOf(file.type) === -1) {
        photoNote('Please choose a JPEG, PNG or WebP picture.'); photoInput.value = ''; return;
      }
      if (file.size > PHOTO_MAX_RAW) {
        photoNote('That picture is too large. Please choose a smaller one.'); photoInput.value = ''; return;
      }
      shrinkPhoto(file).then(sendPhoto, function () { sendPhoto(null); });
    });
  }

  // the server sent us back with an error: reopen that popup
  document.querySelectorAll('dialog[data-open]').forEach(open);
})();