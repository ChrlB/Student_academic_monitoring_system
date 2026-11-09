<?php
/* View/components/account_dialogs.php
   The two popups of the account page. Needs $acct (getAccountInfo()) and $dlg (takeDialogState()).
   Include once, before </body>, together with View/script/account.js and View/style/account_dialogs.css. */

/** @var array $acct  set by View/account.php (the editor can't see that, so it flags it as undefined) */
/** @var array $dlg   set by View/account.php */

$eye = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12c2-3.8 5.4-6 9-6s7 2.2 9 6c-2 3.8-5.4 6-9 6s-7-2.2-9-6z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>';
$old = is_array($dlg['old'] ?? null) ? $dlg['old'] : [];
$val = fn(string $k, string $fallback = '') => array_key_exists($k, $old) ? (string)$old[$k] : $fallback;
$pwErr = ($dlg['open'] ?? '') === 'password' ? (string)$dlg['error'] : '';
$prErr = ($dlg['open'] ?? '') === 'profile'  ? (string)$dlg['error'] : '';
$p = $acct['personal']; $a = $acct['address'];
$extra = defined('PROFILE_EXTRA_COLUMNS') && PROFILE_EXTRA_COLUMNS;
$defaultPwMsg = 'All fields are required. Password must be at least eight (8) characters or more.';

// one editable (or locked) box. $locked: shown but not sent. $needsColumn: disabled until the migration is done.
$box = function (string $name, string $label, string $value, string $w, string $type = 'text', array $opts = [], bool $locked = false, bool $needsColumn = false) use ($extra) {
    $off = $locked || ($needsColumn && !$extra);
    ?>
    <label class="df <?= e($w) ?>">
      <span><?= e($label) ?></span>
      <?php if ($type === 'select'): ?>
        <select <?= $off ? 'disabled' : 'name="' . e($name) . '"' ?>>
          <option value=""></option>
          <?php foreach ($opts as $o): ?>
            <option value="<?= e($o) ?>" <?= strcasecmp($o, $value) === 0 ? 'selected' : '' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="<?= e($type) ?>" <?= $off ? 'disabled' : 'name="' . e($name) . '"' ?> value="<?= e($value) ?>"
               <?= $type === 'date' ? 'max="' . date('Y-m-d') . '" min="1900-01-01"' : '' ?> autocomplete="off">
      <?php endif; ?>
    </label>
    <?php
};
?>

<?php if (canChangePassword()): ?>
<!-- ============ Change Password ============ -->
<dialog id="password-dialog" class="dlg dlg-light" aria-labelledby="pw-title" <?= $pwErr !== '' ? 'data-open' : '' ?>>
  <form method="post" action="<?= e(accountUrl('/change-password')) ?>" novalidate autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <h2 id="pw-title">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/><circle cx="12" cy="15.2" r=".6"/></svg>
      Change Password
    </h2>
    <p class="dlg-msg" id="pw-msg" role="alert" data-default="<?= e($defaultPwMsg) ?>"><?= e($pwErr !== '' ? $pwErr : $defaultPwMsg) ?></p>

    <?php foreach ([['current_password', 'Current password', 'current-password'], ['new_password', 'New password', 'new-password'], ['confirm_password', 'Confirm password', 'new-password']] as [$n, $l, $ac]): ?>
      <label class="pw">
        <span><?= e($l) ?></span>
        <div class="pw-box">
          <input type="password" name="<?= e($n) ?>" placeholder="Password" autocomplete="<?= e($ac) ?>" maxlength="200">
          <button type="button" class="eye" data-eye aria-label="Show <?= e(strtolower($l)) ?>" aria-pressed="false"><?= $eye ?></button>
        </div>
      </label>
    <?php endforeach; ?>

    <div class="dlg-actions">
      <button type="button" class="pill" data-close>Cancel</button>
      <button type="submit" class="pill">Submit</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($acct['isStudent']): ?>
<!-- ============ Edit Profile (students) ============ -->
<dialog id="profile-dialog" class="dlg dlg-dark" aria-labelledby="pr-title" <?= $prErr !== '' ? 'data-open' : '' ?>>
  <form method="post" action="<?= e(accountUrl('/edit-profile')) ?>" novalidate autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <div class="dlg-scroll">
      <h2 id="pr-title">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>
        Edit Personal Information
      </h2>
      <p class="dlg-msg dlg-msg-dark" id="pr-msg" role="alert" <?= $prErr === '' ? 'hidden' : '' ?>><?= e($prErr) ?></p>
      <?php if (!$extra): ?>
        <p class="dlg-note">Greyed-out fields can’t be saved yet. First and last name are managed by the school.</p>
      <?php else: ?>
        <p class="dlg-note">First and last name are managed by the school.</p>
      <?php endif; ?>

      <div class="df-row">
        <?php $box('', 'First Name',  $p['first_name'],  'w3', 'text', [], true); ?>
        <?php $box('', 'Last Name',   $p['last_name'],   'w3', 'text', [], true); ?>
        <?php $box('middle_name', 'Middle Name', $val('middle_name', $p['middle_name']), 'w3'); ?>
      </div>
      <div class="df-row">
        <?php $box('email', 'Email Address', $val('email', $acct['contact']['email']), 'w6', 'text', [], false, true); ?>
      </div>
      <div class="df-row">
        <?php $box('date_of_birth', 'Date of Birth', $val('date_of_birth', $p['birth_date']), 'w3', 'date'); ?>
        <?php $box('place_of_birth', 'Place of Birth', $val('place_of_birth', $p['birth_place']), 'w3', 'text', [], false, true); ?>
        <?php $box('civil_status', 'Civil Status', $val('civil_status', $p['civil_status']), 'w3', 'select', ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], false, true); ?>
        <?php $box('sex', 'Sex', $val('sex', $p['sex']), 'w2', 'select', ['Male', 'Female']); ?>
        <?php $box('sexual_orientation', 'Sexual Orientation', $val('sexual_orientation', $p['orientation']), 'w3', 'text', [], false, true); ?>
      </div>
      <div class="df-row">
        <?php $box('citizenship', 'Citizenship', $val('citizenship', $p['citizenship']), 'w3', 'text', [], false, true); ?>
        <?php $box('religion', 'Religion', $val('religion', $p['religion']), 'w3', 'text', [], false, true); ?>
      </div>

      <h3 class="dlg-sub">Current Address</h3>
      <div class="df-row">
        <?php $box('region', 'Region', $val('region', $a['region']), 'w3', 'text', [], false, true); ?>
        <?php $box('province', 'Province', $val('province', $a['province']), 'w3', 'text', [], false, true); ?>
        <?php $box('municipality', 'Municipality', $val('municipality', $a['municipality']), 'w3', 'text', [], false, true); ?>
        <?php $box('street', 'House No/Lot No/Street', $val('street', $a['street']), 'w4'); ?>
        <?php $box('zip_code', 'Zip Code', $val('zip_code', $a['zip']), 'w2', 'text', [], false, true); ?>
      </div>
    </div>
    <div class="dlg-actions dlg-actions-dark">
      <button type="button" class="pill" data-close>Cancel</button>
      <button type="submit" class="pill">Save Info</button>
    </div>
  </form>
</dialog>
<?php endif; ?>