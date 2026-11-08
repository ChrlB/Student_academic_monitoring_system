<?php
require_once __DIR__ . "/../PageLogic/grades_logic.php";

function grades_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$gradesLogic = new GradesLogic();
$semesters = $gradesLogic->semesters();
$semesterKey = $gradesLogic->selectedSemester($_GET['sem'] ?? '', $semesters);
$semesterLabel = $gradesLogic->semesterLabelForKey($semesterKey, $semesters);
$view = ($_GET['view'] ?? 'preview') === 'gradebook' ? 'gradebook' : 'preview';
$scheduleID = (int)($_GET['class'] ?? $_POST['class'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    $semesterKey = $gradesLogic->selectedSemester($_POST['sem'] ?? '', $semesters);
    $semesterLabel = $gradesLogic->semesterLabelForKey($semesterKey, $semesters);
    $class = $gradesLogic->assignedClass($scheduleID, $semesterKey);

    try {
        if (!hash_equals($gradesLogic->csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form session expired. Reload the page and try again.');
        }
        if (!$class) {
            throw new InvalidArgumentException('That class is not assigned to your account.');
        }

        $page = $gradesLogic->saveGrades($class, is_array($_POST['grades'] ?? null) ? $_POST['grades'] : []);
        $_SESSION['message'] = 'Added Sucessfully';
        unset($_SESSION['grades_old']);
        $returnToPreview = true;
    } catch (InvalidArgumentException $e) {
        error_log('Grade save failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
        $_SESSION['grades_old'] = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
        $page = max(1, (int)($_POST['page'] ?? 1));
        $returnToPreview = false;
    } catch (PDOException $e) {
        error_log('Grade save failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = ['type' => 'error', 'message' => 'Unable to save grades. Please reload and try again.'];
        $_SESSION['grades_old'] = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
        $page = max(1, (int)($_POST['page'] ?? 1));
        $returnToPreview = false;
    } catch (RuntimeException $e) {
        error_log('Grade save failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
        $_SESSION['grades_old'] = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
        $page = max(1, (int)($_POST['page'] ?? 1));
        $returnToPreview = false;
    } catch (Throwable $e) {
        error_log('Grade save failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = ['type' => 'error', 'message' => 'Unable to save grades. Please reload and try again.'];
        $_SESSION['grades_old'] = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
        $page = max(1, (int)($_POST['page'] ?? 1));
        $returnToPreview = false;
    }

    $redirectParams = ['sem' => $semesterKey];
    if (!$returnToPreview) {
        $redirectParams += [
            'view' => 'gradebook',
            'class' => $scheduleID,
            'page' => $page,
        ];
    }
    $query = http_build_query($redirectParams);
    header('Location: ' . BASE_PATH . '/grades?' . $query);
    exit;
}

$classPreview = $gradesLogic->classPreview($semesterKey);
$class = null;
$roster = null;
$flash = $gradesLogic->takeFlash();
$oldGrades = $_SESSION['grades_old'] ?? [];
unset($_SESSION['grades_old']);

if ($view === 'gradebook') {
    $class = $gradesLogic->assignedClass($scheduleID, $semesterKey);
    if ($class) {
        $roster = $gradesLogic->classStudents($class, (int)($_GET['page'] ?? 1));
    }
}
$csrf = $gradesLogic->csrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS - Grades</title>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <style>
    .grades-add-shell {
      width: 100%;
      min-height: 100%;
      padding: 12px 10px 8px;
      box-sizing: border-box;
      background: linear-gradient(180deg, rgba(168, 138, 172, 0.8), rgba(112, 73, 117, 0.7));
      border-radius: 20px;
      border: 2px solid rgba(255, 255, 255, 0.75);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
    }

    .grades-add-shell,
    .grades-add-shell *,
    .grades-add-shell *::before,
    .grades-add-shell *::after {
      box-sizing: border-box;
    }

    .grades-add-panel {
      width: 100%;
      min-height: 100%;
      border-radius: 28px;
      border: 3px solid rgba(255, 255, 255, 0.7);
      background: linear-gradient(180deg, rgba(174, 140, 178, 0.95), rgba(125, 86, 128, 0.9));
      padding: 12px 18px 18px;
      box-sizing: border-box;
    }

    .grades-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      min-height: 72px;
      padding: 12px 10px 14px;
      border-bottom: 2px solid rgba(255, 255, 255, 0.52);
      margin-bottom: 18px;
    }

    .grades-title-wrap {
      display: flex;
      align-items: center;
      gap: 14px;
      color: #fff;
    }

    .grades-title-wrap .book-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      border: 2px solid rgba(255, 255, 255, 0.8);
      display: grid;
      place-items: center;
      font-size: 1.5rem;
      background: rgba(255,255,255,0.08);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.2);
    }

    .grades-title-wrap h1 {
      margin: 0;
      font-size: clamp(2.2rem, 2vw, 3rem);
      letter-spacing: 0.04em;
      font-weight: 900;
      color: #fff;
    }

    .grades-title-wrap small {
      display: block;
      margin-top: 4px;
      font-size: 0.95rem;
      color: rgba(255,255,255,0.9);
      font-weight: 600;
    }

    .grades-main {
      display: grid;
      grid-template-columns: minmax(200px, 240px) minmax(0, 1fr);
      gap: 14px;
      align-items: start;
      width: 100%;
      min-width: 0;
    }

    .grades-main--preview {
      grid-template-columns: minmax(0, 1fr);
      align-items: start;
    }

    .grades-main--preview .grades-sidebar,
    .grades-main--preview .grade-content {
      width: 100%;
    }

    .grades-sidebar {
      display: grid;
      grid-template-columns: minmax(100px, 120px) minmax(0, 1fr);
      align-items: start;
      column-gap: 14px;
      padding: 10px 0 0;
      min-width: 0;
    }

    .back-btn,
    .term-btn,
    .class-scope-btn,
    .add-grade-btn,
    .class-card {
      border: 1px solid rgba(255,255,255,0.7);
      border-radius: 12px;
      color: #fff;
      font-weight: 800;
      letter-spacing: 0.04em;
      font-family: inherit;
    }

    .back-btn {
      width: 120px;
      min-height: 40px;
      padding: 10px 14px;
      background: rgba(255,255,255,0.17);
      text-transform: uppercase;
      cursor: pointer;
    }

    .term-group {
      display: flex;
      flex-direction: column;
      gap: 10px;
      min-width: 0;
    }

    .term-label {
      display: inline-flex;
      align-items: center;
      justify-content: flex-start;
      width: 100%;
      min-height: 44px;
      padding: 0 12px;
      border-radius: 10px;
      background: rgba(44, 38, 45, 0.7);
      color: #fff;
      font-size: 0.9rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      border: 1px solid rgba(255,255,255,0.55);
    }

    .term-btn {
      min-height: 42px;
      background: rgba(255,255,255,0.06);
      padding: 10px 12px;
      text-align: left;
      cursor: pointer;
      text-transform: uppercase;
    }

    .term-btn.active {
      background: rgba(255,255,255,0.22);
      border-color: rgba(255,255,255,0.8);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25);
    }

    .class-scope-btn {
      background: rgba(60,52,65,0.75);
      min-height: 38px;
      padding: 10px 12px;
      margin-top: 4px;
      text-transform: uppercase;
      cursor: pointer;
    }

    .grades-main--preview .class-scope-btn {
      grid-column: 1;
      grid-row: 2;
      width: 120px;
      margin-top: 0;
    }

    .grade-content {
      display: flex;
      flex-direction: column;
      gap: 18px;
      min-width: 0;
      width: 100%;
    }

    .grade-head {
      display: flex;
      align-items: flex-end;
      flex-direction: column;
      gap: 28px;
      margin-top: 6px;
      width: 100%;
      min-width: 0;
    }

    .semester-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 8px 16px;
      border: 1px solid rgba(255,255,255,0.4);
      border-radius: 999px;
      background: rgba(28, 21, 30, 0.28);
      font-size: 0.82rem;
      font-weight: 700;
      color: #fff;
      white-space: nowrap;
    }

    .add-grade-btn {
      width: 210px;
      max-width: 100%;
      min-height: 42px;
      padding: 10px 12px;
      background: rgba(60,52,65,0.75);
      text-transform: uppercase;
      font-size: 0.9rem;
      letter-spacing: 0.04em;
      cursor: pointer;
    }

    .add-grade-btn:disabled {
      opacity: 1;
      color: #fff;
      cursor: not-allowed;
      filter: none;
    }

    .class-grid {
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 155px;
      grid-template-rows: minmax(112px, auto);
      gap: 12px;
      padding: 6px 0 8px;
      width: fit-content;
      max-width: 100%;
      min-width: 0;
      overflow-x: auto;
      overflow-y: hidden;
    }

    .class-card {
      background: rgba(60, 32, 68, 0.45);
      min-height: 112px;
      padding: 10px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12);
      min-width: 0;
    }

    .class-card h3 {
      margin: 0 0 7px;
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1.2;
      color: #fff;
    }

    .class-meta {
      margin: 0;
      padding: 0;
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 3px;
      color: #fff;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    .class-meta li {
      line-height: 1.25;
    }

    .class-meta .stat-label {
      opacity: 0.82;
      text-transform: uppercase;
    }

    .empty-state {
      width: 100%;
      max-width: 100%;
      min-height: 180px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.5);
      color: #fff;
      font-weight: 700;
      text-align: center;
      padding: 18px;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    .grades-action-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
    }

    .class-card-link {
      display: block;
      color: inherit;
      text-decoration: none;
      min-width: 0;
    }

    .class-card-link:hover .class-card,
    .class-card-link:focus-visible .class-card {
      background: rgba(91, 48, 101, 0.78);
      border-color: #fff;
      transform: translateY(-2px);
    }

    .class-card {
      display: block;
      width: 100%;
      text-align: left;
      cursor: pointer;
      font: inherit;
      transition: transform 0.15s ease, background 0.15s ease;
    }

    .term-picker {
      display: flex;
      align-items: center;
      gap: 6px;
      width: 100%;
    }

    .term-picker select {
      width: 100%;
      min-width: 0;
      min-height: 44px;
      padding: 0 18px;
      color: #fff;
      background: #ad83b4;
      border: 2px solid rgba(255,255,255,0.9);
      border-radius: 12px;
      font: inherit;
      font-size: 0.95rem;
      font-weight: 600;
      letter-spacing: 0;
    }

    .term-picker select option {
      color: #fff;
      background: #32113f;
    }

    .grade-flash {
      margin: 0 0 14px;
      padding: 10px 14px;
      border: 1px solid rgba(255,255,255,0.65);
      border-radius: 10px;
      color: #fff;
      font-weight: 700;
    }

    .grade-flash--success {
      background: rgba(34, 112, 69, 0.45);
    }

    .grade-flash--error {
      background: rgba(145, 35, 54, 0.5);
    }

    .gradebook-panel {
      width: 100%;
      min-width: 0;
      padding: 20px 18px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,0.7);
      background: rgba(74, 43, 79, 0.72);
    }

    .gradebook-panel .grade-table th,
    .gradebook-panel .grade-table td {
      padding-left: 16px;
      padding-right: 16px;
    }

    .gradebook-heading {
      margin: 0 0 18px 8px;
      color: #fff;
      font-size: 1.05rem;
      font-weight: 800;
    }

    .grade-table-wrap {
      overflow-x: auto;
      border-radius: 12px;
    }

    .grade-table {
      width: 100%;
      border-collapse: collapse;
      color: #fff;
      font-size: 0.88rem;
    }

    .grade-table th {
      padding: 14px 12px;
      background: rgba(42, 33, 45, 0.8);
      color: rgba(255,255,255,0.82);
      text-align: center;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .grade-table th:first-child,
    .grade-table td:first-child {
      text-align: left;
    }

    .grade-table td {
      padding: 8px 12px;
      border-bottom: 1px solid rgba(255,255,255,0.45);
      text-align: center;
    }

    .grade-table input[type="number"] {
      width: min(120px, 100%);
      min-height: 34px;
      padding: 5px 8px;
      color: #fff;
      background: rgba(37, 24, 42, 0.78);
      border: 1px solid rgba(255,255,255,0.55);
      border-radius: 8px;
      font: inherit;
      text-align: center;
    }

    .grade-table input[type="number"]:focus-visible,
    .term-picker select:focus-visible {
      outline: 2px solid #fff;
      outline-offset: 2px;
    }

    .gradebook-actions {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 16px;
      color: #fff;
      font-weight: 700;
    }

    .grade-pager {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .grade-pager a {
      display: inline-flex;
      min-width: 32px;
      min-height: 32px;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(255,255,255,0.7);
      border-radius: 50%;
      color: #fff;
      text-decoration: none;
    }

    .grade-pager a:hover {
      background: rgba(255,255,255,0.16);
    }

    .gradebook-submit {
      min-height: 44px;
      padding: 10px 18px;
      color: #fff;
      background: rgba(37, 24, 42, 0.5);
      border: 1px solid rgba(255,255,255,0.8);
      border-radius: 12px;
      font: inherit;
      font-weight: 800;
      text-transform: uppercase;
      cursor: pointer;
    }

    .gradebook-submit:hover {
      background: rgba(255,255,255,0.16);
    }

    .gradebook-empty {
      padding: 22px;
      color: rgba(255,255,255,0.85);
      text-align: center;
    }

    @media (max-width: 1100px) {
      .grades-main {
        grid-template-columns: minmax(0, 1fr);
      }

    }

    @media (max-width: 600px) {
      .grades-toolbar {
        flex-direction: column;
        align-items: flex-start;
      }

      .grades-sidebar {
        grid-template-columns: minmax(82px, 100px) minmax(0, 1fr);
        column-gap: 10px;
      }

      .back-btn {
        width: 100%;
      }

      .gradebook-actions {
        align-items: flex-start;
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <main class="column scrollbar">
        <div class="grades-add-shell">
          <div class="grades-add-panel">
            <div class="grades-toolbar">
              <div class="grades-title-wrap">
                <div class="book-icon" aria-hidden="true">▦</div>
                <div>
                  <h1>ADD GRADES</h1>
                  <small>Manage Student Grades</small>
                </div>
              </div>
            </div>

            <?php if ($flash): ?>
              <div class="grade-flash grade-flash--<?= grades_e($flash['type']) ?>" role="status">
                <?= grades_e($flash['message']) ?>
              </div>
            <?php endif; ?>

            <div class="grades-main<?= $view === 'preview' ? ' grades-main--preview' : '' ?>">
              <aside class="grades-sidebar">
                <a class="back-btn grades-action-link" href="<?= $view === 'gradebook'
                    ? BASE_PATH . '/grades?' . http_build_query(['sem' => $semesterKey])
                    : BASE_PATH . '/dashboard' ?>">Back</a>

                <form class="term-group" method="get" action="<?= BASE_PATH ?>/grades">
                  <?php if ($view === 'gradebook' && $class): ?>
                    <input type="hidden" name="view" value="gradebook">
                    <input type="hidden" name="class" value="<?= (int)$class['schedID'] ?>">
                  <?php endif; ?>
                  <div class="term-picker">
                    <select id="grade-semester" name="sem" aria-label="Select Term" onchange="this.form.submit()" <?= empty($semesters) ? 'disabled' : '' ?>>
                      <?php if (empty($semesters)): ?>
                        <option value="" selected>Select Term</option>
                      <?php endif; ?>
                      <?php foreach ($semesters as $semester): ?>
                        <option value="<?= grades_e($semester['key']) ?>" <?= $semester['key'] === $semesterKey ? 'selected' : '' ?>>
                          <?= grades_e($semester['label']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <noscript><button class="term-btn" type="submit">Show Term</button></noscript>
                </form>
                <?php if ($view === 'preview'): ?>
                  <a class="class-scope-btn grades-action-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query(['sem' => $semesterKey]) ?>">Class Preview</a>
                <?php endif; ?>
              </aside>

              <section class="grade-content">
                <div class="grade-head">
                  <div class="semester-pill"><?= grades_e($semesterLabel ?: 'No assigned semester') ?></div>
                  <?php if ($view === 'preview'): ?>
                    <?php
                      $gradebookParams = ['view' => 'gradebook', 'sem' => $semesterKey];
                      if (!empty($classPreview)) {
                          $gradebookParams['class'] = (int)$classPreview[0]['schedID'];
                      }
                    ?>
                    <a class="add-grade-btn grades-action-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query($gradebookParams) ?>">
                      Add New Grade
                    </a>
                  <?php endif; ?>
                </div>

                <?php if ($view === 'gradebook' && $class && $roster): ?>
                  <section class="gradebook-panel" aria-labelledby="gradebook-class-title">
                    <h2 class="gradebook-heading" id="gradebook-class-title">
                      <?= grades_e($class['subject_code']) ?> - <?= grades_e($class['program'] . $class['block']) ?>
                    </h2>
                    <form method="post" action="<?= BASE_PATH ?>/grades?<?= http_build_query([
                        'view' => 'gradebook',
                        'class' => $class['schedID'],
                        'sem' => $semesterKey,
                        'page' => $roster['page'],
                    ]) ?>">
                      <input type="hidden" name="action" value="save_grades">
                      <input type="hidden" name="csrf" value="<?= grades_e($csrf) ?>">
                      <input type="hidden" name="class" value="<?= (int)$class['schedID'] ?>">
                      <input type="hidden" name="sem" value="<?= grades_e($semesterKey) ?>">
                      <input type="hidden" name="page" value="<?= (int)$roster['page'] ?>">

                      <?php if (!$roster['rows']): ?>
                        <div class="gradebook-empty">No approved students are enrolled in this class block yet.</div>
                      <?php else: ?>
                        <div class="grade-table-wrap">
                          <table class="grade-table">
                            <thead>
                              <tr>
                                <th scope="col">Student ID</th>
                                <th scope="col">Name</th>
                                <th scope="col">Midterm Grade</th>
                                <th scope="col">Final Grade</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($roster['rows'] as $student):
                                  $enrolledID = (string)$student['enrolled_subjectID'];
                                  $old = $oldGrades[$enrolledID] ?? [];
                                  $midterm = array_key_exists('midterm', $old) ? $old['midterm'] : $student['midterm_grade'];
                                  $final = array_key_exists('final', $old) ? $old['final'] : $student['final_grade'];
                              ?>
                                <tr>
                                  <td><?= grades_e($student['display_student_id']) ?></td>
                                  <td><?= grades_e($student['name']) ?></td>
                                  <td>
                                    <input type="number" name="grades[<?= (int)$student['enrolled_subjectID'] ?>][midterm]"
                                      min="0" max="100" step="0.01" value="<?= grades_e($midterm) ?>"
                                      aria-label="Midterm grade for <?= grades_e($student['name']) ?>">
                                  </td>
                                  <td>
                                    <input type="number" name="grades[<?= (int)$student['enrolled_subjectID'] ?>][final]"
                                      min="0" max="100" step="0.01" value="<?= grades_e($final) ?>"
                                      aria-label="Final grade for <?= grades_e($student['name']) ?>">
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php endif; ?>

                      <div class="gradebook-actions">
                        <div class="grade-pager" aria-label="Student pages">
                          <span>Page <?= (int)$roster['page'] ?> / <?= (int)$roster['pages'] ?></span>
                          <?php if ($roster['page'] > 1): ?>
                            <a href="<?= BASE_PATH ?>/grades?<?= http_build_query([
                                'view' => 'gradebook',
                                'class' => $class['schedID'],
                                'sem' => $semesterKey,
                                'page' => $roster['page'] - 1,
                            ]) ?>" aria-label="Previous page">&lsaquo;</a>
                          <?php endif; ?>
                          <?php if ($roster['page'] < $roster['pages']): ?>
                            <a href="<?= BASE_PATH ?>/grades?<?= http_build_query([
                                'view' => 'gradebook',
                                'class' => $class['schedID'],
                                'sem' => $semesterKey,
                                'page' => $roster['page'] + 1,
                            ]) ?>" aria-label="Next page">&rsaquo;</a>
                          <?php endif; ?>
                        </div>
                        <button class="gradebook-submit" type="submit">Submit Grade</button>
                      </div>
                    </form>
                  </section>
                <?php elseif ($view === 'gradebook'): ?>
                  <section class="gradebook-panel" aria-labelledby="gradebook-class-title">
                    <h2 class="gradebook-heading" id="gradebook-class-title">Grade Book</h2>
                    <div class="gradebook-empty">
                      No assigned class schedule or student grades are available to display.
                    </div>
                  </section>
                <?php elseif (empty($classPreview)): ?>
                  <div class="empty-state">No assigned class schedule found for this account.</div>
                <?php else: ?>
                  <div class="class-grid">
                    <?php foreach ($classPreview as $class): ?>
                      <a class="class-card-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query([
                          'view' => 'gradebook',
                          'class' => (int)$class['schedID'],
                          'sem' => $semesterKey,
                      ]) ?>">
                        <article class="class-card">
                          <h3><?= grades_e($class['subject_code']) ?> - <?= grades_e($class['program'] . $class['block']) ?></h3>
                          <ul class="class-meta">
                            <li><span class="stat-label">Total Students:</span> <?= (int)$class['total_students'] ?></li>
                            <li><span class="stat-label">Grades Submitted:</span> <?= (int)$class['grades_submitted'] ?></li>
                            <li><span class="stat-label">Missing Grades:</span> <?= (int)$class['missing_grades'] ?></li>
                          </ul>
                        </article>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>
</body>
</html>