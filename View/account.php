<?php
require_once __DIR__ . "/../PageLogic/account_logic.php";
$acct = getAccountInfo();

// One labelled, read-only box. $w = how wide it is (CSS class).
function field(string $label, string $value, string $w = ''): void { ?>
  <label class="f <?= e($w) ?>">
    <span><?= e($label) ?></span>
    <input type="text" value="<?= e($value) ?>" readonly>
  </label>
<?php } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | School Account</title>
  <link rel="icon" type="image/x-icon" href="/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="/View/style/general.css">
  <link rel="stylesheet" href="/View/style/account.css">
</head>
<body>

  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1; min-width: 0;">
      <main class="account scrollbar" data-role="<?= e(str_replace(' ', '-', $acct['role'])) ?>">

        <!-- ===== banner ===== -->
        <div class="acc-banner">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11.5V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.5M22 9v6"/></svg>
          <span class="bar" aria-hidden="true"></span>
          <h1><?= $acct['isStudent'] ? 'Profile/Enlistment' : 'Profile' ?></h1>
        </div>

        <div class="acc-top">

          <!-- ===== profile card ===== -->
          <section class="acc-card acc-profile">
            <div class="avatar">
              <svg class="avatar-fallback" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
              <!-- if /get-profile has no picture it fails to load; onerror removes it so the fallback icon shows -->
              <img src="/get-profile" alt="Profile picture" class="avatar-img" onerror="this.remove()">
              <button type="button" class="avatar-btn" data-action="upload-photo" aria-label="Change profile picture">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/></svg>
              </button>
            </div>

            <div class="profile-body">
              <div class="profile-head">
                <h2><?= $acct['isStudent'] ? 'Student Profile' : e(ucwords($acct['role'])) . ' Profile' ?></h2>
                <button type="button" class="edit-btn" data-action="edit-profile">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="M13 7l4 4"/></svg>Edit
                </button>
              </div>
              <dl class="profile-info">
                <div><dt>Full Name:</dt><dd><?= e($acct['fullName'] ?: '—') ?></dd></div>
                <div class="sep"><dt><?= $acct['isStudent'] ? 'Student ID:' : 'School ID:' ?></dt><dd><?= e($acct['schoolId'] ?: '—') ?></dd></div>
                <div><dt>Contact Num:</dt><dd><?= e($acct['phone'] ?: '—') ?></dd></div>
                <?php if ($acct['isStudent']): ?>
                  <div class="sep"><dt>Program/Year:</dt><dd><?= e($acct['programYear'] ?: '—') ?></dd></div>
                <?php else: ?>
                  <div class="sep"><dt>Role:</dt><dd><?= e(ucwords($acct['role'])) ?></dd></div>
                <?php endif; ?>
              </dl>
            </div>
          </section>

          <!-- ===== account security card ===== -->
          <section class="acc-card acc-security">
            <div class="sec-head">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7.5 3v5.5c0 4.6-3.2 8-7.5 9.5-4.3-1.5-7.5-4.9-7.5-9.5V6z"/></svg>
              <div><h2>Account Security</h2><p>Manage your account</p></div>
            </div>
            <button type="button" class="sec-row" data-action="change-password">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
              <span>Change Password</span><i class="chev" aria-hidden="true"></i>
            </button>
            <form method="post" action="/logout">
              <button type="submit" class="sec-row">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H6a1 1 0 00-1 1v14a1 1 0 001 1h4M15 8l4 4-4 4M19 12H9"/></svg>
                <span>Logout</span><i class="chev" aria-hidden="true"></i>
              </button>
            </form>
          </section>
        </div>

        <?php if ($acct['isStudent']): ?>
        <!-- ===== student information (accordions: no JavaScript, <details> opens and closes itself) ===== -->
        <section class="acc-card acc-info">
          <div class="info-head">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></svg>
            <h2>Student Information</h2>
          </div>

          <details class="acc-sec" open>
            <summary>
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>
              <span>Personal Information</span><i class="chev" aria-hidden="true"></i>
            </summary>
            <div class="sec-body">
              <div class="row-f">
                <?php field('First Name',  $acct['personal']['first_name'],  'w3'); ?>
                <?php field('Last Name',   $acct['personal']['last_name'],   'w3'); ?>
                <?php field('Middle Name', $acct['personal']['middle_name'], 'w4'); ?>
              </div>
              <div class="row-f">
                <?php field('Date of Birth',       $acct['personal']['birth_date'],   'w3'); ?>
                <?php field('Place of Birth',      $acct['personal']['birth_place'],  'w3'); ?>
                <?php field('Civil Status',        $acct['personal']['civil_status'], 'w2'); ?>
                <?php field('Sex',                 $acct['personal']['sex'],          'w1'); ?>
                <?php field('Sexual Orientation',  $acct['personal']['orientation'],  'w3'); ?>
                <?php field('Citizenship',         $acct['personal']['citizenship'],  'w2'); ?>
                <?php field('Religion',            $acct['personal']['religion'],     'w2'); ?>
              </div>
            </div>
          </details>

          <details class="acc-sec">
            <summary>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a1 1 0 01-1 1A15 15 0 014 5a1 1 0 011-1z"/></svg>
              <span>Contact Information</span><i class="chev" aria-hidden="true"></i>
            </summary>
            <div class="sec-body">
              <div class="row-f">
                <?php field('Phone Number',  $acct['contact']['phone'], 'w3'); ?>
                <?php field('Email Address', $acct['contact']['email'], 'w4'); ?>
              </div>
              <h3 class="sub">Current Address</h3>
              <div class="row-f">
                <?php field('Region',                   $acct['address']['region'],       'w3'); ?>
                <?php field('Province',                 $acct['address']['province'],     'w3'); ?>
                <?php field('Municipality',             $acct['address']['municipality'], 'w3'); ?>
                <?php field('House No/Lot No/Street',   $acct['address']['street'],       'w4'); ?>
                <?php field('Zip Code',                 $acct['address']['zip'],          'w2'); ?>
              </div>
            </div>
          </details>
        </section>
        <?php endif; ?>

      </main>
    </div>
  </div>

  <?php require_once __DIR__ . "/components/logout.php"; ?>
</body>
</html>