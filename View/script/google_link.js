(function () {
  var box = document.getElementById('google-link');
  if (!box) return;
  var msg = document.getElementById('google-link-msg');
  function say(text, bad) { msg.textContent = text; msg.style.color = bad ? '#ffb4b4' : '#b8f5c0'; msg.hidden = false; }

  window.addEventListener('load', function () {
    if (!window.google || !google.accounts || !google.accounts.id) {
      say('Could not load Google sign-in. Check your internet connection.', true);
      return;
    }
    google.accounts.id.initialize({
      client_id: box.dataset.clientId,
      callback: function (resp) {
        var body = new URLSearchParams();
        body.set('credential', resp.credential);
        body.set('csrf_token', box.dataset.csrf);
        say('Linking…', false);
        fetch(box.dataset.url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) { say(d.message || 'Could not link the account.', true); return; }
            say('Linked ' + d.email + '. Reloading…', false);
            setTimeout(function () { location.reload(); }, 800);
          })
          .catch(function () { say('Could not reach the server. Please try again.', true); });
      }
    });
    google.accounts.id.renderButton(document.getElementById('google-btn'), { theme: 'filled_black', size: 'large', text: 'continue_with', shape: 'pill' });
  });
})();