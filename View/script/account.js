(function () {
  'use strict';
  var pw = document.getElementById('password-dialog');
  var pr = document.getElementById('profile-dialog');
  var photoInput = document.getElementById('photo-input');
  var photoForm = document.getElementById('photo-form');

  function open(d) {
    if (d && typeof d.showModal === 'function' && !d.open) d.showModal();
  }
  function close(d) {
    if (!d) return;
    d.close();
    var f = d.querySelector('form');
    if (f) f.reset();
    resetPassword();
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-action]');
    if (t && t.dataset.action === 'change-password') open(pw);
    if (t && t.dataset.action === 'edit-profile') open(pr);
    if (
      t &&
      t.dataset.action === 'upload-photo' &&
      photoInput &&
      !t.classList.contains('is-busy')
    )
      photoInput.click();
    var c = e.target.closest('[data-close]');
    if (c) close(c.closest('dialog'));
    // click on the dark backdrop closes too
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('dlg'))
      close(e.target);
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
    pw.querySelectorAll('.pw-box input').forEach(function (i) {
      i.type = 'password';
    });
    pw.querySelectorAll('[data-eye]').forEach(function (b) {
      b.classList.remove('on');
      b.setAttribute('aria-pressed', 'false');
    });
    var m = document.getElementById('pw-msg');
    if (m) {
      m.textContent = m.dataset['default'];
      m.classList.remove('err');
    }
  }

  // client checks (the server checks again)
  if (pw) {
    var msg = document.getElementById('pw-msg');
    pw.querySelector('form').addEventListener('submit', function (e) {
      var inputs = pw.querySelectorAll('.pw-box input');
      var cur = inputs[0].value,
        nw = inputs[1].value,
        cf = inputs[2].value,
        err = '';
      if (!cur || !nw || !cf) err = msg.dataset['default'];
      else if (nw.length < 8)
        err = 'Password must be at least eight (8) characters or more.';
      else if (nw !== cf)
        err = 'New password and confirm password do not match.';
      if (err) {
        e.preventDefault();
        msg.textContent = err;
        msg.classList.add('err');
        return;
      }
      lock(e.target);
    });
  }
  // Same rules as validateEmailAddress() in PageLogic/account_actions.php (the server checks again).
  var EMAIL_TYPOS = {
    'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gamil.com': 'gmail.com', 'gmaill.com': 'gmail.com',
    'gmal.com': 'gmail.com', 'gmil.com': 'gmail.com', 'gnail.com': 'gmail.com', 'ggmail.com': 'gmail.com',
    'gmail.co': 'gmail.com', 'gmail.con': 'gmail.com', 'gmail.cm': 'gmail.com', 'gmail.om': 'gmail.com',
    'gmail.comm': 'gmail.com', 'gmail.vom': 'gmail.com',
    'yahoo.con': 'yahoo.com', 'yaho.com': 'yahoo.com', 'yahooo.com': 'yahoo.com', 'yahoo.cm': 'yahoo.com',
    'hotmial.com': 'hotmail.com', 'hotmail.con': 'hotmail.com', 'hotmai.com': 'hotmail.com',
    'outlok.com': 'outlook.com', 'outlook.con': 'outlook.com', 'outllok.com': 'outlook.com',
    'icloud.con': 'icloud.com', 'iclod.com': 'icloud.com'
  };
  function emailProblem(raw) {
    var v = raw.trim().toLowerCase();
    if (v === '') return '';
    if (/\s/.test(v)) return 'Email Address cannot contain spaces.';
    if (v.length > 100) return 'Email Address is too long (100 characters at most).';
    var at = v.split('@');
    if (
      at.length !== 2 ||
      !/^[a-z0-9!#$%&'*+\/=?^_`{|}~.-]+@[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(v) ||
      /^\.|\.\.|\.@/.test(v) ||
      /(^|\.)-|-(\.|$)/.test(at[1])
    )
      return 'Enter a valid email address, e.g. name@gmail.com.';
    var local = at[0],
      domain = at[1];
    if (local.length > 64) return 'Email Address is not valid: the part before @ is too long.';
    var tld = domain.slice(domain.lastIndexOf('.') + 1);
    if (!/^[a-z]{2,24}$/.test(tld) && !/^xn--[a-z0-9-]{2,59}$/.test(tld))
      return 'Email Address does not end in a valid domain such as .com or .ph.';
    var rep = domain.match(/\.([a-z]{2,24})\.\1$/);
    if (rep)
      return (
        'Email Address ends with “.' + rep[1] + '.' + rep[1] + '”. Did you mean ' +
        local + '@' + domain.slice(0, -(rep[1].length + 1)) + '?'
      );
    if (domain.indexOf('gmail.com.') === 0)
      return 'Email Address looks mistyped. Did you mean ' + local + '@gmail.com?';
    if (Object.prototype.hasOwnProperty.call(EMAIL_TYPOS, domain))
      return 'Email Address looks mistyped. Did you mean ' + local + '@' + EMAIL_TYPOS[domain] + '?';
    return '';
  }

  if (pr) {
    pr.querySelector('form').addEventListener('submit', function (e) {
      var num = pr.querySelector('input[name="number"]');
      var pm = document.getElementById('pr-msg');
      var mail = pr.querySelector('input[name="email"]');
      if (mail && !mail.disabled) {
        var mErr = emailProblem(mail.value);
        if (mErr) {
          e.preventDefault();
          if (pm) {
            pm.textContent = mErr;
            pm.hidden = false;
          }
          mail.focus();
          return;
        }
      }
      if (num && !num.disabled && num.value.trim() !== '') {
        var v = num.value.trim(),
          d = v.replace(/\D/g, ''),
          err = '';
        if (!/^\+?[0-9][0-9 ()\-]*$/.test(v))
          err =
            'Contact Number can only contain digits (spaces and dashes are fine).';
        else if (/^639\d{9}$/.test(d) && v.charAt(0) === '+')
          d = '0' + d.slice(2);
        if (!err && !/^09\d{9}$/.test(d))
          err =
            'Contact Number must be 11 digits and start with 09, e.g. 0909 123 4567.';
        if (err) {
          e.preventDefault();
          if (pm) {
            pm.textContent = err;
            pm.hidden = false;
          }
          num.focus();
          return;
        }
      }
      lock(e.target);
    });
  }
  function lock(form) {
    // stop double submits
    var b = form.querySelector('button[type=submit]');
    if (b)
      setTimeout(function () {
        b.disabled = true;
      }, 0);
  }

  /* ---------- profile picture ---------- */
  var PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  var PHOTO_MAX_SIDE = 600,
    PHOTO_QUALITY = 0.85,
    PHOTO_MAX_RAW = 25 * 1024 * 1024;

  function photoNote(text) {
    // small message at the top right (textContent: safe)
    var old = document.querySelector('.photo-note');
    if (old) old.remove();
    var n = document.createElement('div');
    n.className = 'photo-note';
    n.setAttribute('role', 'alert');
    n.textContent = text;
    document.body.appendChild(n);
    setTimeout(function () {
      n.remove();
    }, 6000);
  }

  function shrinkPhoto(file) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(
      function (bmp) {
        var scale = Math.min(
          1,
          PHOTO_MAX_SIDE / Math.max(bmp.width, bmp.height),
        );
        var w = Math.round(bmp.width * scale),
          h = Math.round(bmp.height * scale);
        var c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h); // transparent PNGs get a white background
        ctx.drawImage(bmp, 0, 0, w, h);
        return new Promise(function (resolve) {
          c.toBlob(resolve, 'image/jpeg', PHOTO_QUALITY);
        });
      },
    );
  }

  function sendPhoto(blob) {
    var btn = document.querySelector('[data-action="upload-photo"]');
    if (btn) btn.classList.add('is-busy');
    if (blob) {
      try {
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'profile.jpg', { type: 'image/jpeg' }));
        photoInput.files = dt.files;
      } catch (e) {
        /* old browser: the original picture is sent instead */
      }
    }
    photoForm.submit();
  }

  if (photoInput && photoForm) {
    photoInput.addEventListener('change', function () {
      var file = photoInput.files && photoInput.files[0];
      if (!file) return;
      if (PHOTO_TYPES.indexOf(file.type) === -1) {
        photoNote('Please choose a JPEG, PNG or WebP picture.');
        photoInput.value = '';
        return;
      }
      if (file.size > PHOTO_MAX_RAW) {
        photoNote('That picture is too large. Please choose a smaller one.');
        photoInput.value = '';
        return;
      }
      shrinkPhoto(file).then(sendPhoto, function () {
        sendPhoto(null);
      });
    });
  }

  // the server sent us back with an error: reopen that popup
  document.querySelectorAll('dialog[data-open]').forEach(open);
})();