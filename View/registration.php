<?php
require_once __DIR__ . '/../PageLogic/registration_logic.php';   // registrar check,

$filters = [
    'all'                 => 'All Accounts',
    'student'             => 'Students',
    'faculty'             => 'All Faculty',
    'dean'                => 'Dean',
    'program coordinator' => 'Program Coordinator',
    'prof'                => 'Professor',
];
$defaultDept = $regDepartments[0]['department'] ?? '';
$total = count($regAccounts);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | User Registration</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/account.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/account_dialogs.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/registration.css">
</head>

<body>
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>

    <div class="column" style="flex: 1; min-width: 0;">
      <?php require_once __DIR__ . "/components/header.php"; ?>

      <main class="account registration scrollbar" id="reg"
            data-add-url="<?= e(BASE_PATH . '/registration-add') ?>"
            data-restrict-url="<?= e(BASE_PATH . '/registration-restrict') ?>"
            data-update-url="<?= e(BASE_PATH . '/registration-update') ?>"
            data-delete-url="<?= e(BASE_PATH . '/registration-delete') ?>"
            data-csrf="<?= e(csrfToken()) ?>"
            data-default-dept="<?= e($defaultDept) ?>">

        <?php if ($regLoadError): ?><p class="reg-warning" role="alert"><?= e($regLoadError) ?></p><?php endif; ?>

        <div class="reg-tools">
          <form class="reg-search" role="search" id="reg-search-form">
            <label class="reg-searchbox">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.2-4.2"/></svg>
              <span class="sr-only">Search accounts</span>
              <input type="search" id="reg-search" placeholder="Search Name, ID, Dept, or Program/Block" autocomplete="off">
            </label>
            <button type="submit" class="reg-btn reg-btn-light">Search</button>
          </form>
          <div class="reg-actions">
            <button type="button" class="reg-btn reg-btn-light" data-action="open-restrict">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>Restrict
            </button>
            <button type="button" class="reg-btn reg-btn-light" data-action="open-add">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add
            </button>
          </div>
        </div>

        <div class="reg-filters" role="group" aria-label="Filter accounts by role">
          <?php foreach ($filters as $key => $label): ?>
            <button type="button" class="reg-chip<?= $key === 'all' ? ' is-active' : '' ?>" data-filter="<?= e($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>

        <section class="acc-card reg-directory" aria-labelledby="reg-dir-title">
          <div class="reg-dir-head">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8.5" cy="8.5" r="3"/><path d="M3 19c0-3 2.4-5 5.5-5s5.5 2 5.5 5"/><circle cx="17" cy="9" r="2.4"/><path d="M16 14.2c2.7-.3 5 1.3 5 4.3"/></svg>
            <h2 id="reg-dir-title">Registered Students &amp; Faculty Directory <span>(Click any row to manage or restrict)</span></h2>
          </div>
          <p class="reg-count" id="reg-count" role="status" aria-live="polite">Showing <?= $total ?> of <?= $total ?> accounts</p>

          <div class="reg-table-wrap" tabindex="0" role="region" aria-label="Accounts table">
            <table class="reg-table">
              <thead>
                <tr>
                  <th scope="col">Name</th>
                  <th scope="col">School ID</th>
                  <th scope="col">Department</th>
                  <th scope="col">Program / Block</th>
                  <th scope="col">Roles:</th>
                  <th scope="col">Account Status</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody id="reg-body">
                <?php foreach ($regAccounts as $a): ?>
                <tr tabindex="0" data-account="<?= e(json_encode($a, JSON_UNESCAPED_UNICODE)) ?>"
                    data-kind="<?= e($a['kind']) ?>" data-role="<?= e($a['role']) ?>"
                    data-search="<?= e(strtolower($a['name'] . ' ' . $a['id'] . ' ' . $a['dept'] . ' ' . $a['programBlock'] . ' ' . $a['roleLabel'])) ?>">
                  <th scope="row"><?= e($a['name']) ?></th>
                  <td class="mono"><?= e($a['id']) ?></td>
                  <td><?= e($a['dept'] ?: '—') ?></td>
                  <td><?= e($a['programBlock']) ?></td>
                  <td><span class="role-name"><?= e($a['roleLabel']) ?></span><?php if ($a['kind'] === 'faculty'): ?> <span class="tag">Faculty</span><?php endif; ?></td>
                  <td><span class="status status-<?= e($a['status']) ?>"><?= $a['status'] === 'restricted' ? 'Restricted' : 'Active' ?></span></td>
                  <td class="reg-row-actions">
                    <button type="button" class="row-btn row-btn-edit" data-row-action="edit"<?= $a['locked'] ? ' disabled title="This account cannot be edited here"' : '' ?> aria-label="Edit <?= e($a['name']) ?>">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="M13 7l4 4"/></svg>Edit
                    </button>
                    <button type="button" class="row-btn row-btn-delete" data-row-action="delete"<?= $a['locked'] ? ' disabled title="This account cannot be deleted here"' : '' ?> aria-label="Delete <?= e($a['name']) ?>">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10.5 11v5M13.5 11v5"/></svg>Delete
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="reg-empty" id="reg-empty" <?= $total ? 'hidden' : '' ?>>No accounts match.</p>
          </div>
        </section>
      </main>
    </div>
  </div>

  <!-- Register Student / Faculty -->
  <dialog id="add-dialog" class="dlg dlg-light reg-dlg" aria-labelledby="add-title">
    <form id="add-form" novalidate autocomplete="off">
      <div class="reg-dlg-head">
        <h2 id="add-title">Register Student / Faculty</h2>
        <button type="button" class="reg-x" data-close aria-label="Close">&times;</button>
      </div>
      <label class="rf">
        <span>Full Name</span>
        <input type="text" name="full_name" placeholder="First Name, Middle Initial, Last Name" maxlength="150" required aria-describedby="add-full_name-err">
        <em class="rf-err" id="add-full_name-err" data-err="full_name" hidden></em>
      </label>
      <label class="rf">
        <span>School / Employee ID <small>(optional)</small></span>
        <input type="text" name="school_id" placeholder="e.g. 202600045 (blank = automatic)" maxlength="10" inputmode="text" aria-describedby="add-school_id-err">
        <em class="rf-err" id="add-school_id-err" data-err="school_id" hidden></em>
      </label>
      <div class="rf-row">
        <label class="rf">
          <span>Department</span>
          <select name="department" required aria-describedby="add-department-err">
            <?php foreach ($regDepartments as $d): ?>
              <option value="<?= e($d['department']) ?>"><?= e($d['department']) ?></option>
            <?php endforeach; ?>
          </select>
          <em class="rf-err" id="add-department-err" data-err="department" hidden></em>
        </label>
        <label class="rf">
          <span id="add-pb-label">Program / Block</span>
          <input type="text" name="program_block" placeholder="e.g. BSCS 2C" maxlength="20" aria-describedby="add-program_block-err">
          <em class="rf-err" id="add-program_block-err" data-err="program_block" hidden></em>
        </label>
      </div>
      <label class="rf">
        <span>Roles</span>
        <select name="role" required aria-describedby="add-role-err">
          <option>Students</option>
          <option>Dean</option>
          <option>Program Coordinator</option>
          <option>Professor</option>
        </select>
        <em class="rf-err" id="add-role-err" data-err="role" hidden></em>
      </label>
      <p class="rf-hint" id="add-hint">Students: program and block, e.g. <b>BSCS 2C</b>.</p>
      <div class="dlg-actions">
        <button type="button" class="pill pill-grey" data-close>Cancel</button>
        <button type="submit" class="pill">Add</button>
      </div>
    </form>
  </dialog>

  <!-- Restrict / lift -->
  <dialog id="restrict-dialog" class="dlg dlg-light reg-dlg" aria-labelledby="restrict-title">
    <form id="restrict-form" novalidate autocomplete="off">
      <div class="reg-dlg-head">
        <h2 id="restrict-title">Restrict Student / Faculty</h2>
        <button type="button" class="reg-x" data-close aria-label="Close">&times;</button>
      </div>
      <label class="rf">
        <span>School / Employee ID</span>
        <input type="text" name="school_id" placeholder="XXXXXXXXX or F202600012" maxlength="10" required aria-describedby="restrict-school_id-err">
        <em class="rf-err" id="restrict-school_id-err" data-err="school_id" hidden></em>
      </label>
      <label class="rf">
        <span>Full Name</span>
        <input type="text" name="full_name" placeholder="Filled in from the ID" readonly tabindex="-1">
      </label>
      <div class="rf-row">
        <label class="rf"><span>Department</span><input type="text" name="department" placeholder="—" readonly tabindex="-1"></label>
        <label class="rf"><span>Program / Block</span><input type="text" name="program_block" placeholder="—" readonly tabindex="-1"></label>
      </div>
      <label class="rf">
        <span>Roles</span>
        <input type="text" name="role" placeholder="—" readonly tabindex="-1">
      </label>
      <label class="rf">
        <span id="restrict-reason-label">Reason</span>
        <textarea name="reason" rows="3" maxlength="250" placeholder="Enter restriction reason..." aria-describedby="restrict-reason-err"></textarea>
        <em class="rf-err" id="restrict-reason-err" data-err="reason" hidden></em>
      </label>
      <p class="rf-err rf-formerr" id="restrict-formerr" role="alert" hidden></p>
      <div class="dlg-actions">
        <button type="button" class="pill pill-grey" data-close>Cancel</button>
        <button type="submit" class="pill" id="restrict-submit">Restrict</button>
      </div>
    </form>
  </dialog>

  <!-- Edit account -->
  <dialog id="edit-dialog" class="dlg dlg-light reg-dlg" aria-labelledby="edit-title">
    <form id="edit-form" novalidate autocomplete="off">
      <div class="reg-dlg-head">
        <h2 id="edit-title">Edit Student / Faculty</h2>
        <button type="button" class="reg-x" data-close aria-label="Close">&times;</button>
      </div>
      <label class="rf">
        <span>School / Employee ID <small>(cannot be changed)</small></span>
        <input type="text" name="school_id" readonly tabindex="-1">
      </label>
      <label class="rf">
        <span>Full Name</span>
        <input type="text" name="full_name" placeholder="First Name, Middle Initial, Last Name" maxlength="150" required aria-describedby="edit-full_name-err">
        <em class="rf-err" id="edit-full_name-err" data-err="full_name" hidden></em>
      </label>
      <div class="rf-row">
        <label class="rf">
          <span>Department</span>
          <select name="department" required aria-describedby="edit-department-err">
            <?php foreach ($regDepartments as $d): ?>
              <option value="<?= e($d['department']) ?>"><?= e($d['department']) ?></option>
            <?php endforeach; ?>
          </select>
          <em class="rf-err" id="edit-department-err" data-err="department" hidden></em>
        </label>
        <label class="rf">
          <span>Program / Block</span>
          <input type="text" name="program_block" placeholder="e.g. BSCS 2C" maxlength="20" aria-describedby="edit-program_block-err">
          <em class="rf-err" id="edit-program_block-err" data-err="program_block" hidden></em>
        </label>
      </div>
      <label class="rf">
        <span>Roles</span>
        <select name="role" required aria-describedby="edit-role-err">
          <option>Students</option>
          <option>Dean</option>
          <option>Program Coordinator</option>
          <option>Professor</option>
        </select>
        <em class="rf-err" id="edit-role-err" data-err="role" hidden></em>
      </label>
      <p class="rf-hint" id="edit-hint"></p>
      <p class="rf-err rf-formerr" id="edit-formerr" role="alert" hidden></p>
      <div class="dlg-actions">
        <button type="button" class="pill pill-grey" data-close>Cancel</button>
        <button type="submit" class="pill" id="edit-submit">Save</button>
      </div>
    </form>
  </dialog>

  <!-- Delete account -->
  <dialog id="delete-dialog" class="dlg dlg-light reg-dlg" aria-labelledby="delete-title">
    <form id="delete-form" novalidate>
      <div class="reg-dlg-head">
        <h2 id="delete-title">Delete Account</h2>
        <button type="button" class="reg-x" data-close aria-label="Close">&times;</button>
      </div>
      <p class="reg-confirm" id="delete-text"></p>
      <dl class="reg-cred">
        <div><dt>Name</dt><dd id="delete-name"></dd></div>
        <div><dt>School / Employee ID</dt><dd id="delete-id" class="mono"></dd></div>
      </dl>
      <p class="rf-hint">This cannot be undone. An account that already has records (enrollment, attendance, clearance) cannot be deleted: restrict it instead.</p>
      <p class="rf-err rf-formerr" id="delete-formerr" role="alert" hidden></p>
      <div class="dlg-actions">
        <button type="button" class="pill pill-grey" data-close>Cancel</button>
        <button type="submit" class="pill pill-danger" id="delete-submit">Delete</button>
      </div>
    </form>
  </dialog>

  <!-- shown once after a successful registration -->
  <dialog id="done-dialog" class="dlg dlg-light reg-dlg" aria-labelledby="done-title">
    <div class="reg-done">
      <h2 id="done-title">Account registered</h2>
      <p id="done-text"></p>
      <dl class="reg-cred">
        <div><dt>School / Employee ID</dt><dd id="done-id" class="mono"></dd></div>
        <div><dt>Temporary password</dt><dd id="done-pw" class="mono"></dd></div>
      </dl>
      <p class="rf-hint">Write this password down now: it is not stored anywhere and cannot be shown again. The person should change it after their first login.</p>
      <div class="dlg-actions">
        <button type="button" class="pill pill-grey" id="done-copy">Copy</button>
        <button type="button" class="pill" data-close>Done</button>
      </div>
    </div>
  </dialog>

  <div class="reg-toast" id="reg-toast" role="status" aria-live="polite" hidden></div>

  <script src="<?= BASE_PATH ?>/View/script/registration.js"></script>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
</body>

</html>