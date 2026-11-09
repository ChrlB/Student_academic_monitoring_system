<?php
require_once __DIR__ . '/../PageLogic/login_logic.php';
require_once __DIR__ . '/../Services/csrf.php';

if (isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}
$flashError   = $_SESSION['error']   ?? '';
$flashMessage = $_SESSION['message'] ?? '';
unset($_SESSION['error'], $_SESSION['message']);

function e($text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | Login</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/login.css">
</head>

<body data-flash-error="<?= e($flashError) ?>" data-flash-message="<?= e($flashMessage) ?>">

  <main class="page">

    <!-- ============ LEFT: intro ============ -->
    <section class="hero">
      <h1 class="hero-title">Academic<br>Monitoring System</h1>
      <p class="hero-lead">Track your academic progress, manage your records, and stay on top of your goal.</p>

      <ul class="features">
        <li>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3v17h17"/><path d="M8 16v-4M12 16V8M16 16v-6M20 16v-3"/></svg>
          View Grades
        </li>
        <li>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M8 3v4M16 3v4M3.5 10h17M9 15l2 2 4-4"/></svg>
          Track Attendance
        </li>
        <li>
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
          Access Your Academic Records
        </li>
        <li>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 13l2 2 4-4"/></svg>
          Manage Projects
        </li>
      </ul>
    </section>

    <!-- ============ RIGHT: login card ============ -->
    <section class="card" aria-labelledby="login-title">

      <div class="brand brand--card">
        <img src="<?= BASE_PATH ?>/View/resources/logo1.png" alt="logo" class="brand-logo">
      </div>

      <h2 id="login-title" class="sr-only">Log in to your account</h2>

      <div id="alert" class="alert" role="alert" hidden></div>

      <form id="login-form" action="<?= BASE_PATH ?>/login" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <label for="school-id">School ID</label>
        <div class="field">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2"/><path d="M5.8 16c.6-1.5 1.8-2.2 3.2-2.2s2.6.7 3.2 2.2M15 10h3.5M15 13.5h3.5"/></svg>
          <input type="text" id="school-id" name="username"
                placeholder="Enter your school ID" maxlength="20"
                autocomplete="username" autocapitalize="characters" spellcheck="false" required>
        </div>

        <label for="password">Password</label>
        <div class="field">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10.5" rx="2.5"/><path d="M8 10.5V8a4 4 0 018 0v2.5"/></svg>
          <input type="password" id="password" name="password"
                placeholder="Enter your password"
                autocomplete="current-password" required>
          <button type="button" id="toggle-password" class="toggle"
                  aria-label="Show password" aria-pressed="false">
            <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path d="M4 20L20 4"/></svg>
            <svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <button type="submit" class="btn">Login</button>
      </form>

      <div class="divider"><span>Don't have an account?</span></div>
      <p class="link--center">Contact your school administrator</p>

    </section>
  </main>

  <footer class="site-footer">
    AMS Version 1.1 | <a href="#">Terms of Service</a> | <a href="#">Privacy Policy</a> | &copy; <?= date('Y') ?> BSCS2C
  </footer>
  <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>

  <script src="/View/script/login.js"></script>
</body>
</html>