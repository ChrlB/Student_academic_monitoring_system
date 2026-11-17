<?php

require_once __DIR__ . '/../../PageLogic/faculty_account_logic.php';
require_once __DIR__ . '/../../Services/csrf.php';

$fa   = getFacultyAccount();
$cfg  = $fa['cfg'];
$name = $cfg['name'];

$icons = [
    'bank'      => '<path d="M3 9l9-5 9 5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 20h18M4 10h16"/>',
    'cap'       => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11.5V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.5M22 9v6"/>',
    'clipboard' => '<rect x="5" y="4.5" width="14" height="16" rx="2"/><path d="M9 4.5h6v2.5H9zM8.5 12h7M8.5 16h5"/>',
    'person'    => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/>',
    'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="2"/><path d="M9 7.5V6a1.5 1.5 0 011.5-1.5h3A1.5 1.5 0 0115 6v1.5M3.5 13h17"/>',
    'phone'     => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a1 1 0 01-1 1A15 15 0 014 5a1 1 0 011-1z"/>',
    'book'      => '<path d="M6 4h11a1 1 0 011 1v14a1 1 0 01-1 1H7a2 2 0 01-2-2V5a1 1 0 011-1z"/><path d="M9 4v9l2.5-1.8L14 13V4"/>',
];
$svg = fn(string $name) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';

// One labelled box. data-field is what faculty_account.js sends back to the server.
$box = function (array $c) {
    $id  = 'ff-' . $c['key'];
    $cls = 'f ' . $c['w'] . ($c['locked'] ? ' is-locked' : '');
    $editAttr = $c['locked'] ? '' : 'data-editable';
    $errId    = 'ff-' . $c['key'] . '-err';
    $inType   = $c['type'] === 'phone' ? 'tel' : 'text';   // dates stay text until editing (faculty_account.js)
    $auto     = ['date_of_birth' => 'bday', 'number' => 'tel', 'address' => 'street-address'][$c['key']] ?? 'off';
    $dateAttr = $c['type'] === 'date' ? 'data-kind="date" min="1900-01-01" max="' . date('Y-m-d') . '"' : '';
    ?>
    <!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | Faculty Account</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/account.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/account_dialogs.css">
  
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/faculty_account.css">
  
</head>
<body>
    <label class="<?= e($cls) ?>" for="<?= e($id) ?>">
      <span><?= e($c['label']) ?></span>
      <?php if ($c['type'] === 'select'): ?>
        <select id="<?= e($id) ?>" data-field="<?= e($c['key']) ?>" aria-describedby="<?= e($errId) ?>" <?= $editAttr ?> disabled>
          <option value=""></option>
          <?php foreach ($c['opts'] as $o): ?>
            <option value="<?= e($o) ?>" <?= strcasecmp($o, $c['value']) === 0 ? 'selected' : '' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <?php /* dates stay type=text (so they read 1978-05-14); faculty_account.js makes them real date pickers while editing */ ?>
        <input id="<?= e($id) ?>" type="<?= $inType ?>" data-field="<?= e($c['key']) ?>" aria-describedby="<?= e($errId) ?>" <?= $editAttr ?> value="<?= e($c['value']) ?>" autocomplete="<?= $auto ?>" readonly <?= $dateAttr ?>>
      <?php endif; ?>
      <em class="f-err" id="<?= e($errId) ?>" data-err-for="<?= e($c['key']) ?>" hidden></em>
    </label>
    <?php
};
?>
<article class="faculty-account" data-faculty aria-labelledby="fa-page-title"
     data-save-url="<?= e(accountUrl('/faculty-info')) ?>"
     data-csrf="<?= e(csrfToken()) ?>">

  <?php /* a <div>, not <header>: general.css styles every <header> as the site top bar */ ?>
  <div class="acc-banner">
    <?= $svg($cfg['banner']) ?>
    <span class="bar" aria-hidden="true"></span>
    <h1 id="fa-page-title"><?= e($name) ?> Account</h1>
  </div>

  <div class="acc-top">

    <section class="acc-card acc-profile" aria-labelledby="fa-profile-title">
      <div class="avatar">
        <span class="avatar-badge" aria-hidden="true"><?= e($cfg['badge']) ?></span>
        <?php if ($fa['hasPhoto']): ?>
          <img src="<?= BASE_PATH ?>/get-profile" alt="Profile picture" class="avatar-img" onerror="this.remove()">
        <?php endif; ?>
        <button type="button" class="avatar-btn" data-action="upload-photo" aria-label="Change profile picture">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/></svg>
        </button>
      </div>

      <div class="profile-body">
        <div class="profile-head">
          <h2 id="fa-profile-title"><?= e($name) ?> Profile</h2>
        </div>
        <dl class="profile-info">
          <div><dt>Full Name:</dt><dd data-bind="fullName"><?= e($fa['fullName'] ?: '—') ?></dd></div>
          <div class="sep"><dt><?= e($cfg['idLabel']) ?></dt><dd><?= e($fa['schoolId'] ?: '—') ?></dd></div>
          <div><dt>Department | Program:</dt><dd><?= e($fa['deptProgram'] ?: '—') ?></dd></div>
          <div class="sep"><dt><?= e($cfg['officeLabel']) ?></dt><dd><?= e($fa['office'] ?: '—') ?></dd></div>
        </dl>
      </div>
    </section>

    <section class="acc-card acc-security" aria-labelledby="fa-security-title">
      <div class="sec-head">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7.5 3v5.5c0 4.6-3.2 8-7.5 9.5-4.3-1.5-7.5-4.9-7.5-9.5V6z"/></svg>
        <div><h2 id="fa-security-title">Account Security</h2><p>Manage your account</p></div>
      </div>
      <?php if (canChangePassword()): ?>
      <button type="button" class="sec-row" data-action="change-password">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
        <span>Change Password</span><i class="chev" aria-hidden="true"></i>
      </button>
      <?php endif; ?>
      <form method="post" action="<?= e(accountUrl('/logout')) ?>">
        <button type="submit" class="sec-row">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H6a1 1 0 00-1 1v14a1 1 0 001 1h4M15 8l4 4-4 4M19 12H9"/></svg>
          <span>Logout</span><i class="chev" aria-hidden="true"></i>
        </button>
      </form>
    </section>
  </div>

  <section class="acc-card acc-info" id="fa-info" aria-labelledby="fa-info-title">
    <div class="info-head">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></svg>
      <h2 id="fa-info-title"><?= e($name) ?> Information</h2>
      <div class="info-actions">
        <button type="button" class="edit-btn fa-cancel" data-action="cancel-info" hidden>Cancel</button>
        <button type="button" class="edit-btn fa-toggle" data-action="toggle-info" aria-controls="fa-info">
          <svg class="ic-edit" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="M13 7l4 4"/></svg>
          <svg class="ic-save" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
          <span>Edit Information</span>
        </button>
      </div>
    </div>

    <?php foreach ($fa['sections'] as $i => $sec): ?>
    <details class="acc-sec" <?= $i === 0 ? 'open' : '' ?>>
      <summary>
        <?= $svg($sec['icon']) ?>
        <span id="fa-sec-<?= (int)$i ?>"><?= e($sec['title']) ?></span><i class="chev" aria-hidden="true"></i>
      </summary>
      <div class="sec-body" role="group" aria-labelledby="fa-sec-<?= (int)$i ?>">
        <?php foreach ($sec['rows'] as $cells): ?>
        <div class="row-f">
          <?php foreach ($cells as $c) $box($c); ?>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>
  </section>
</article>

<div class="fa-toast" id="fa-toast" role="status" aria-live="polite" hidden></div>

  </body>
  </html>  