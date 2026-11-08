
<dialog id="logout-dialog" class="confirm-dialog" aria-labelledby="logout-title">
<form method="dialog">
    <h2 id="logout-title">Are you sure you want to logout?</h2>
    <div class="confirm-actions">
    <button type="submit" value="no" class="confirm-no" autofocus>No</button>
    <button type="button" class="confirm-yes" id="logout-yes">Yes</button>
    </div>
  </form>
</dialog>
<style>
.confirm-dialog {
    width: min(92vw, 400px); padding: 26px 26px 22px;
    border: 1px solid rgba(150, 110, 235, .7); border-radius: 18px;
    background: #2a1160; color: #fff;
    font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    box-shadow: 0 24px 60px rgba(10, 2, 30, .6);
}
.confirm-dialog::backdrop { background: rgba(15, 4, 40, .65); backdrop-filter: blur(3px); }
.confirm-dialog h2 { margin: 0 0 22px; font-size: 1.15rem; font-weight: 700; line-height: 1.35; text-align: center; }
.confirm-actions { display: flex; gap: 12px; }
.confirm-actions button {
    flex: 1; height: 44px; border-radius: 12px; cursor: pointer;
    font: inherit; font-size: 1rem; font-weight: 700; color: #fff;
}
.confirm-no  { background: transparent; border: 1px solid rgba(255, 255, 255, .55); }
.confirm-no:hover { background: rgba(255, 255, 255, .12); }
.confirm-yes { background: #6d00a8; border: 1px solid #6d00a8; }
.confirm-yes:hover { background: #8200c8; }
.confirm-actions button:focus-visible { outline: 3px solid #e3c9ff; outline-offset: 2px; }
</style>
<script>
(function () {
const dialog = document.getElementById('logout-dialog');
const yes    = document.getElementById('logout-yes');
  if (!dialog || typeof dialog.showModal !== 'function') return;   

let pendingForm = null;

document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.closest('dialog')) return;
    if (new URL(form.action, location.href).pathname !== '/logout') return;

    event.preventDefault();
    pendingForm = form;
    dialog.showModal();
});

yes.addEventListener('click', function () {
    dialog.close();
    if (pendingForm) pendingForm.submit();
});

dialog.addEventListener('click', function (event) {
    if (event.target === dialog) dialog.close();
});
dialog.addEventListener('close', function () { if (dialog.returnValue !== 'yes') pendingForm = null; });
})();
</script>