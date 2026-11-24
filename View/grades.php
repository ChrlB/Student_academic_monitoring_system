<?php
require_once __DIR__ . "/../PageLogic/grades_logic.php";

$accountRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($accountRole, ['student', 'prof', 'program coordinator', 'dean', 'registrar'], true)) {
    $_SESSION['error'] = 'Grades are not available for this account.';
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}

function grades_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$gradesLogic = new GradesLogic();
$isStudentAccount = $accountRole === 'student';
$isRegistrarAccount = $accountRole === 'registrar';
$isCoordinatorAccount = in_array($accountRole, ['program coordinator', 'dean'], true);
$isProfessorAccount = $accountRole === 'prof';
$isFacultyAccount = in_array($accountRole, ['prof', 'program coordinator', 'dean'], true);
$semesters = $isStudentAccount
    ? $gradesLogic->studentSemesters()
    : ($isFacultyAccount ? $gradesLogic->semesters() : []);
$semesterKey = $gradesLogic->selectedSemester($_GET['sem'] ?? '', $semesters);
$semesterLabel = $gradesLogic->semesterLabelForKey($semesterKey, $semesters);

if ($isStudentAccount) {
    $studentGrades = $gradesLogic->studentGrades($semesterKey);
    $gwaSummary = $gradesLogic->studentGwaSummary();
    $semesterGwa = $gwaSummary['terms'][$semesterKey] ?? null;
    $overallGwa = $gwaSummary['overall'];
    $gradedCount = count(array_filter(
        $studentGrades,
        static function (array $grade): bool {
            return $grade['final_grade'] !== null;
        }
    ));
    $gradeCount = count($studentGrades);
    $progress = $gradeCount > 0 ? (int)round($gradedCount / $gradeCount * 100) : 0;
    $semesterDisplay = $semesterLabel !== ''
        ? str_replace(', AY ', ' ', $semesterLabel)
        : 'No grades available';
}

$requestedView = (string)($_GET['view'] ?? 'preview');
$view = in_array($requestedView, ['gradebook', 'verification'], true) ? $requestedView : 'preview';
if ($view === 'verification' && !$isCoordinatorAccount) {
    $view = 'preview';
}
$scheduleID = (int)($_GET['class'] ?? $_POST['class'] ?? 0);
$assignedClasses = $isFacultyAccount && $view === 'gradebook'
    ? $gradesLogic->assignedClasses()
    : [];
if ($isFacultyAccount && $view === 'gradebook' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach ($assignedClasses as $assignedClass) {
        if ($assignedClass['schedID'] === $scheduleID) {
            $semesterKey = $assignedClass['semester_key'];
            $semesterLabel = $assignedClass['semester_label'];
            break;
        }
    }
}

if ($isCoordinatorAccount && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coordinator_review') {
    try {
        if (!hash_equals($gradesLogic->csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form session expired. Reload the page and try again.');
        }
        $gradesLogic->coordinatorReview(
            (int)($_POST['submissionID'] ?? 0),
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['remark'] ?? '')
        );
        $_SESSION['grades_flash'] = [
            'type' => 'success',
            'message' => ($_POST['decision'] ?? '') === 'approve'
                ? 'Grade submission forwarded to the registrar.'
                : 'Grade submission rejected.',
        ];
    } catch (Throwable $e) {
        error_log('Coordinator grade review failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = [
            'type' => 'error',
            'message' => $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to review the grade submission. Please try again.',
        ];
    }
    header('Location: ' . BASE_PATH . '/grades');
    exit;
}

if ($isRegistrarAccount && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registrar_allow_edit') {
    try {
        if (!hash_equals($gradesLogic->csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form session expired. Reload the page and try again.');
        }
        $gradesLogic->registrarAllowGradeEdit((int)($_POST['submissionID'] ?? 0));
        $_SESSION['grades_flash'] = [
            'type' => 'success',
            'message' => 'The professor can now edit and resubmit these grades for verification.',
        ];
    } catch (Throwable $e) {
        error_log('Registrar grade edit permission failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = [
            'type' => 'error',
            'message' => $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to allow grade edits. Please try again.',
        ];
    }
    header('Location: ' . BASE_PATH . '/grades');
    exit;
}

if ($isRegistrarAccount && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registrar_review') {
    try {
        if (!hash_equals($gradesLogic->csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your form session expired. Reload the page and try again.');
        }
        $gradesLogic->registrarFinalReview(
            (int)($_POST['submissionID'] ?? 0),
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['remark'] ?? '')
        );
        $_SESSION['grades_flash'] = [
            'type' => 'success',
            'message' => ($_POST['decision'] ?? '') === 'approve'
                ? 'Grades verified and added to the student records.'
                : 'Grade submission rejected.',
        ];
    } catch (Throwable $e) {
        error_log('Registrar grade review failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = [
            'type' => 'error',
            'message' => $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to complete final grade verification. Please try again.',
        ];
    }
    header('Location: ' . BASE_PATH . '/grades');
    exit;
}

if ($isProfessorAccount && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
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

        $page = $gradesLogic->submitGradesForVerification($class, is_array($_POST['grades'] ?? null) ? $_POST['grades'] : []);
        $_SESSION['grades_flash'] = [
            'type' => 'success',
            'message' => 'Grades submitted for program coordinator verification.',
        ];
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

$classPreview = $isFacultyAccount ? $gradesLogic->classPreview($semesterKey) : [];
$class = null;
$roster = null;
$gradeSubmissionStates = [];
$coordinatorSubmissions = $isCoordinatorAccount && $view === 'preview'
    ? $gradesLogic->coordinatorSubmissions()
    : [];
$coordinatorVerification = null;
if ($isCoordinatorAccount && $view === 'verification') {
    try {
        $coordinatorVerification = $gradesLogic->coordinatorSubmission((int)($_GET['submission'] ?? 0));
    } catch (Throwable $e) {
        error_log('Coordinator grade verification load failed: ' . $e->getMessage());
        $_SESSION['grades_flash'] = [
            'type' => 'error',
            'message' => $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to load this grade submission. Please try again.',
        ];
        header('Location: ' . BASE_PATH . '/grades');
        exit;
    }
}
$registrarSubmissions = $isRegistrarAccount ? $gradesLogic->registrarSubmissions() : [];
$registrarApprovedSubmissions = $isRegistrarAccount ? $gradesLogic->registrarApprovedSubmissions() : [];
$requestedRegistrarListAction = $_GET['list_action'] ?? $_GET['filter_action'] ?? '';
$registrarListAction = in_array($requestedRegistrarListAction, ['filter', 'generate', 'preview'], true)
    ? (string)$requestedRegistrarListAction
    : '';
$registrarStudentList = $isRegistrarAccount ? $gradesLogic->registrarStudentList([
    'department' => $_GET['department'] ?? '',
    'program' => $_GET['program'] ?? '',
    'acad_year' => $_GET['acad_year'] ?? '',
    'faculty' => $_GET['faculty'] ?? '',
    'sem' => $_GET['sem'] ?? '',
    'schedule' => $_GET['schedule'] ?? '',
    'show_students' => $registrarListAction === 'preview',
]) : null;
$flash = ($isFacultyAccount || $isRegistrarAccount) ? $gradesLogic->takeFlash() : null;
$oldGrades = $_SESSION['grades_old'] ?? [];
unset($_SESSION['grades_old']);

if ($isFacultyAccount && $view === 'gradebook') {
    $class = $gradesLogic->assignedClass($scheduleID, $semesterKey);
    if ($class) {
        $roster = $gradesLogic->classStudents($class, (int)($_GET['page'] ?? 1));
        $gradeSubmissionStates = $gradesLogic->gradeSubmissionStates($class);
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
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/student_grades.css">
  <style>
    body,
    body * {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    }

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

    .grades-main--preview .grades-sidebar > .term-group {
      width: fit-content;
      max-width: 100%;
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
      min-height: 40px;
      padding: 10px 20px;
      margin-top: 20px;
      text-transform: uppercase;
      cursor: pointer;
    }

    .preview-toolbar {
      grid-column: 1 / -1;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      min-width: 0;
    }

    .preview-toolbar-actions {
      display: flex;
      align-items: flex-end;
      flex-direction: column;
      justify-content: flex-end;
      gap: 10px;
      min-width: 0;
      transform: translateY(-40px);
    }

    .preview-toolbar .class-scope-btn {
      width: auto;
      min-width: 150px;
      flex-shrink: 0;
      white-space: nowrap;
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
      margin-top: 15px;
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

    .class-preview-panel {
      width: 100%;
      min-width: 0;
      padding: 7px 14px;
      border: 1px solid rgba(255,255,255,0.7);
      border-radius: 14px;
      background: rgba(74, 43, 79, 0.72);
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

    .grades-main--preview .term-picker {
      width: fit-content;
      max-width: 100%;
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

    .grades-main--preview .term-picker select {
      width: auto;
      max-width: 100%;
      padding-left: 12px;
      padding-right: 28px;
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

    .grade-table input[type="number"]:disabled {
      opacity: 0.75;
      cursor: not-allowed;
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

    .verification-panel {
      min-height: 220px;
    }

    .coordinator-verification-preview {
      border: 1px solid rgba(255,255,255,0.65);
      border-radius: 12px;
      background: rgba(74, 43, 79, 0.62);
      color: #fff;
    }

    .coordinator-verification-preview > summary {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 14px;
      font-weight: 800;
      cursor: pointer;
      list-style: none;
    }

    .coordinator-verification-preview > summary::-webkit-details-marker {
      display: none;
    }

    .coordinator-verification-preview > summary span {
      min-width: 26px;
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(255,255,255,0.18);
      text-align: center;
    }

    .coordinator-verification-list {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 8px;
      max-height: 240px;
      overflow-y: auto;
      padding: 0 10px 10px;
    }

    .coordinator-verification-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 10px;
      border: 1px solid rgba(255,255,255,0.35);
      border-radius: 9px;
      background: rgba(255,255,255,0.07);
    }

    .coordinator-verification-item strong,
    .coordinator-verification-item small {
      display: block;
    }

    .coordinator-verification-item small {
      margin-top: 4px;
      color: rgba(255,255,255,0.82);
    }

    .coordinator-verification-item .class-scope-btn {
      min-height: 36px;
      padding: 7px 12px;
      flex: 0 0 auto;
    }

    .coordinator-verification-empty {
      padding: 0 14px 12px;
      color: rgba(255,255,255,0.84);
      font-size: 0.9rem;
    }

    .verification-card {
      margin-top: 14px;
      padding: 16px;
      border: 1px solid rgba(255,255,255,0.42);
      border-radius: 12px;
      background: rgba(255,255,255,0.07);
    }

    .verification-card-header {
      margin-bottom: 12px;
      color: #fff;
    }

    .verification-card-header small,
    .verification-card-header div {
      display: block;
      margin-top: 4px;
    }

    .verification-table-wrap {
      overflow-x: auto;
    }

    .verification-table {
      width: 100%;
    }

    .verification-remark {
      display: block;
      margin-top: 12px;
      color: #fff;
      font-weight: 700;
    }

    .verification-remark textarea {
      width: 100%;
      margin-top: 6px;
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 8px;
      padding: 8px;
      resize: vertical;
    }

    .verification-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 12px;
    }

    .registrar-list-filters {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
      margin: 12px 0 16px;
      color: #fff;
    }

    .registrar-list-filters label {
      display: grid;
      min-width: 0;
      gap: 5px;
      font-size: 0.88rem;
      font-weight: 700;
    }

    .registrar-list-filters select {
      width: 100%;
      min-width: 0;
      min-height: 38px;
      box-sizing: border-box;
      border: 1px solid rgba(255,255,255,0.55);
      border-radius: 8px;
      padding: 7px 9px;
      background: rgba(255,255,255,0.9);
      color: #35233a;
    }

    .registrar-list-filters .gradebook-submit {
      align-self: end;
    }

    .registrar-list-filters .gradebook-submit:disabled,
    .registrar-list-preview .gradebook-submit:disabled {
      opacity: 0.55;
      cursor: not-allowed;
    }

    .registrar-student-table {
      width: 100%;
      margin-bottom: 4px;
    }

    .registrar-schedule-back {
      display: flex;
      width: fit-content;
      margin: 0 0 12px;
    }

    .faculty-grades .registrar-list-preview .class-grid {
      display: grid;
      grid-auto-flow: row;
      grid-auto-columns: initial;
      grid-template-columns: minmax(0, 1fr);
      grid-template-rows: none;
      width: 100%;
      max-width: 360px;
      overflow: visible;
    }

    .faculty-grades .registrar-list-preview .class-card {
      cursor: default;
    }

    .faculty-grades .class-preview-panel.registrar-list-preview {
      margin-top: 16px;
    }

    .faculty-grades .registrar-list-preview form {
      display: flex;
      justify-content: flex-start;
      margin-top: 12px;
    }

    @media (max-width: 900px) {
      .registrar-list-filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 560px) {
      .registrar-list-filters {
        grid-template-columns: 1fr;
      }
    }

    .verification-reject {
      background: rgba(145, 35, 54, 0.72);
    }

    .faculty-grades .grades-toolbar {
      min-height: 98px;
      margin-bottom: 20px;
      padding: 16px 22px;
      border: 1px solid rgba(255, 255, 255, 0.7);
      border-radius: 17px;
      background: rgba(42, 34, 46, 0.82);
      box-shadow: 0 8px 20px rgba(34, 20, 37, 0.18);
    }

    .faculty-grades .grades-title-wrap .book-icon {
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.12);
      box-shadow: 0 5px 14px rgba(24, 14, 27, 0.2);
    }

    .faculty-grades__profile {
      display: flex;
      flex: 0 0 auto;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      color: #fff;
      font-size: 0.8rem;
    }

    .faculty-grades .profile_icon {
      width: 40px;
      height: 40px;
      border: 1.5px solid #D9D9D9;
      border-radius: 50%;
      object-fit: cover;
    }

    .faculty-grades .grades-sidebar {
      align-content: start;
    }

    .faculty-grades .grades-main:not(.grades-main--preview) {
      grid-template-columns: minmax(0, 1fr);
    }

    .faculty-grades .grades-main:not(.grades-main--preview) .grades-sidebar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      width: 100%;
      padding-top: 0;
    }

    .faculty-grades .grades-main:not(.grades-main--preview) .grade-content {
      grid-column: 1;
    }

    .faculty-grades .back-btn,
    .faculty-grades .term-btn,
    .faculty-grades .class-scope-btn,
    .faculty-grades .add-grade-btn {
      border-color: rgba(255, 255, 255, 0.72);
      border-radius: 10px;
      background-color: rgba(42, 34, 46, 0.56);
      box-shadow: 0 4px 10px rgba(34, 20, 37, 0.14);
      transition: background-color 140ms ease, transform 140ms ease;
    }

    .faculty-grades .back-btn:hover,
    .faculty-grades .term-btn:hover,
    .faculty-grades .class-scope-btn:hover,
    .faculty-grades .add-grade-btn:hover,
    .faculty-grades .gradebook-submit:hover {
      background-color: rgba(255, 255, 255, 0.2);
      transform: translateY(-1px);
    }

    .faculty-grades .term-picker select {
      border-radius: 10px;
      background-color: rgba(42, 34, 46, 0.78);
      box-shadow: 0 4px 10px rgba(34, 20, 37, 0.12);
    }

    .faculty-grades .semester-pill {
      border-color: rgba(255, 255, 255, 0.58);
      background: rgba(42, 34, 46, 0.58);
      box-shadow: 0 4px 12px rgba(34, 20, 37, 0.14);
    }

    .faculty-grades .class-grid {
      grid-auto-flow: column;
      grid-auto-columns: max-content;
      grid-template-columns: none;
      grid-template-rows: minmax(112px, auto);
      width: fit-content;
      max-width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
    }

    .faculty-grades .class-card h3 {
      white-space: nowrap;
    }

    .faculty-grades .class-card {
      background: rgba(54, 37, 59, 0.68);
      box-shadow: 0 7px 16px rgba(34, 20, 37, 0.2);
      transition: background-color 140ms ease, transform 140ms ease;
    }

    .faculty-grades .class-card-link:hover .class-card,
    .faculty-grades .class-card-link:focus-visible .class-card {
      background: rgba(101, 57, 110, 0.82);
    }

    .faculty-grades .gradebook-panel {
      border: 1px solid rgba(255, 255, 255, 0.68);
      border-radius: 16px;
      background: rgba(73, 38, 80, 0.72);
      box-shadow: 0 8px 20px rgba(34, 20, 37, 0.18);
    }

    .faculty-grades .grade-table th,
    .faculty-grades .grade-table td {
      border-color: rgba(255, 255, 255, 0.62);
    }

    .faculty-grades .grade-table th,  .faculty-grades .grade-table td{
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

      .preview-toolbar {
        align-items: flex-start;
        flex-direction: column;
      }

      .preview-toolbar-actions {
        align-items: flex-start;
        justify-content: flex-start;
      }

      .grades-main--preview .grades-sidebar > .term-group {
        width: 100%;
      }

      .grades-main--preview .term-picker {
        width: 100%;
      }

      .grades-main--preview .term-picker select {
        width: 100%;
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
    <div class="column" style="flex: 1; min-width: 0;">
      <?php if ($isStudentAccount): ?>
        <?php require_once __DIR__ . "/components/header.php"; ?>
      <?php endif; ?>
      <main class="column scrollbar<?= $isStudentAccount ? ' student-grades-main' : '' ?>">
      <?php if ($_SESSION['role'] === 'student'): ?>
        <section class="student-grades" aria-labelledby="student-grades-title">
          <header class="student-grades__heading">
            <img class="student-grades__icon" src="<?= BASE_PATH ?>/View/resources/Grades.png" alt="">
            <div>
              <h1 id="student-grades-title">GRADES</h1>
              <p>Track your academic performance</p>
            </div>
          </header>

          <div class="student-grades__toolbar">
            <form method="get" action="<?= BASE_PATH ?>/grades">
              <label class="sr-only" for="student-grade-term">Select term</label>
              <select class="student-grades__term" id="student-grade-term" name="sem"
                      aria-label="Select term" onchange="this.form.submit()" <?= empty($semesters) ? 'disabled' : '' ?>>
                <?php if (empty($semesters)): ?>
                  <option value="">No terms available</option>
                <?php endif; ?>
                <?php foreach ($semesters as $semester): ?>
                  <option value="<?= grades_e($semester['key']) ?>" <?= $semester['key'] === $semesterKey ? 'selected' : '' ?>>
                    Select Term — <?= grades_e($semester['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
            <span class="student-grades__period"><?= grades_e($semesterDisplay) ?></span>
          </div>

          <div class="student-grades__layout">
            <aside class="student-grades__summary" aria-label="Grade summary">
              <section class="student-grades__card">
                <h2>GWA Tracker</h2>
                <div class="student-grades__metric" aria-label="Semester GWA">
                  <?= $semesterGwa !== null ? number_format($semesterGwa, 2) : '—' ?>
                </div>
                <p class="student-grades__metric-note">
                  Average of posted final grades converted to the GWA scale.
                </p>
                <div class="student-grades__progress" role="progressbar"
                     aria-label="Final grades posted" aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?= $progress ?>">
                  <span style="width: <?= $progress ?>%"></span>
                </div>
                <p class="student-grades__metric-note">
                  <?= $gradedCount ?> of <?= $gradeCount ?> final grades posted (<?= $progress ?>%)
                </p>
              </section>

              <section class="student-grades__card">
                <h2>Overall GWA</h2>
                <div class="student-grades__metric" aria-label="Overall GWA">
                  <?= $overallGwa !== null ? number_format($overallGwa, 2) : '—' ?>
                </div>
                <p class="student-grades__metric-note">
                  Average of all posted final grades converted to the GWA scale.
                </p>
                <?php if ($semesters): ?>
                  <ul class="student-grades__history">
                    <?php foreach ($semesters as $semester): ?>
                      <li>
                        <span><?= grades_e(str_replace(', AY ', ' ', $semester['label'])) ?></span>
                        <strong>
                          <?= isset($gwaSummary['terms'][$semester['key']])
                              ? number_format($gwaSummary['terms'][$semester['key']], 2)
                              : '—' ?>
                        </strong>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="student-grades__history-note">No semester grades are available yet.</p>
                <?php endif; ?>
              </section>
            </aside>

            <section class="student-grades__table-panel" aria-labelledby="subject-grades-title">
              <h2 id="subject-grades-title">Subject Grades</h2>
              <?php if ($studentGrades): ?>
                <div class="student-grades__table-wrap">
                  <table class="student-grades__table">
                    <thead>
                      <tr>
                        <th scope="col">Subject Code</th>
                        <th scope="col">Subject Title</th>
                        <th scope="col">Midterm Grade</th>
                        <th scope="col">Final Grade</th>
                        <th scope="col">Semester Grade</th>
                        <th scope="col">Remarks</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($studentGrades as $grade):
                          $midtermGrade = $grade['midterm_grade'] !== null
                              ? number_format((float)$grade['midterm_grade'], 2)
                              : '—';
                          $finalGrade = $grade['final_grade'] !== null
                              ? number_format((float)$grade['final_grade'], 2)
                              : '—';
                          $semesterGrade = $grade['midterm_grade'] !== null && $grade['final_grade'] !== null
                              ? number_format(((float)$grade['midterm_grade'] + (float)$grade['final_grade']) / 2, 2)
                              : '—';
                          $remarks = $grade['final_grade'] !== null
                              ? GradesLogic::percentageRemark((float)$grade['final_grade'])
                              : ($grade['midterm_grade'] !== null ? 'Final grade pending' : 'Grades pending');
                      ?>
                        <tr>
                          <td><?= grades_e($grade['subject_code']) ?></td>
                          <td><?= grades_e($grade['subject_name']) ?></td>
                          <td><?= grades_e($midtermGrade) ?></td>
                          <td><?= grades_e($finalGrade) ?></td>
                          <td><?= grades_e($semesterGrade) ?></td>
                          <td><?= grades_e($remarks) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="student-grades__empty">No approved subject grades are available for this term.</div>
              <?php endif; ?>
            </section>
          </div>
        </section>
      <?php elseif (in_array($accountRole, ['prof', 'program coordinator', 'dean', 'registrar'], true)): ?>
      <div class="grades-add-shell faculty-grades">
          <div class="grades-add-panel">
            <div class="grades-toolbar faculty-grades__heading-panel">
              <div class="grades-title-wrap">
                <div class="book-icon" aria-hidden="true">▦</div>
                <div>
                  <h1>ADD GRADES</h1>
                  <small>Manage Student Grades</small>
                </div>
              </div>
              <?php if (isset($_SESSION['user'])): ?>
                <div class="faculty-grades__profile">
                  <img src="<?= BASE_PATH ?>/get-profile" alt="" class="profile_icon">
                  <b><?= grades_e($_SESSION['user']) ?></b>
                </div>
              <?php endif; ?>
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
                    : ($view === 'verification'
                        ? BASE_PATH . '/grades?' . http_build_query(['sem' => $semesterKey])
                        : BASE_PATH . '/dashboard') ?>">Back</a>

                <?php if ($view === 'gradebook' && $class): ?>
                  <div class="semester-pill"><?= grades_e($semesterLabel ?: 'No assigned semester') ?></div>
                <?php endif; ?>

                <?php if ($isFacultyAccount && $view === 'preview'): ?>
                  <form class="term-group" method="get" action="<?= BASE_PATH ?>/grades">
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
                <?php endif; ?>
                <?php if ($isFacultyAccount && $accountRole !== 'dean' && $view === 'preview'): ?>
                  <?php
                    $gradebookParams = ['view' => 'gradebook', 'sem' => $semesterKey];
                    if (!empty($classPreview)) {
                        $gradebookParams['class'] = (int)$classPreview[0]['schedID'];
                    }
                  ?>
                  <div class="preview-toolbar">
                    <a class="class-scope-btn grades-action-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query(['sem' => $semesterKey]) ?>">Class Preview</a>
                    <div class="preview-toolbar-actions">
                      <div class="semester-pill"><?= grades_e($semesterLabel ?: 'No assigned semester') ?></div>
                      <a class="add-grade-btn grades-action-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query($gradebookParams) ?>">
                        Add New Grade
                      </a>
                    </div>
                  </div>
                <?php endif; ?>
              </aside>

              <section class="grade-content">
                <?php if ($isCoordinatorAccount && $view === 'verification' && $coordinatorVerification): ?>
                  <section class="gradebook-panel verification-panel" aria-labelledby="coordinator-verification-title">
                    <div class="verification-card-header">
                      <h2 class="gradebook-heading" id="coordinator-verification-title">Review Grade Submission</h2>
                      <strong>
                        <?= grades_e($coordinatorVerification['subjectID']) ?>
                        <?= $coordinatorVerification['subject_name'] ? ' - ' . grades_e($coordinatorVerification['subject_name']) : '' ?>
                      </strong>
                      <div>
                        <?= grades_e($coordinatorVerification['program'] . $coordinatorVerification['block']) ?>
                        | <?= grades_e(GradesLogic::semesterLabel($coordinatorVerification['acad_year'], $coordinatorVerification['sem'])) ?>
                      </div>
                      <small>
                        Submitted by <?= grades_e($coordinatorVerification['submitted_by_name'] ?: 'Professor') ?>
                        on <?= grades_e($coordinatorVerification['submitted_at']) ?>
                      </small>
                    </div>
                    <form method="post" action="<?= BASE_PATH ?>/grades">
                      <input type="hidden" name="action" value="coordinator_review">
                      <input type="hidden" name="csrf" value="<?= grades_e($csrf) ?>">
                      <input type="hidden" name="submissionID" value="<?= (int)$coordinatorVerification['submissionID'] ?>">
                      <div class="verification-table-wrap">
                        <table class="grade-table verification-table">
                          <thead><tr><th>Student ID</th><th>Student</th><th>Midterm</th><th>Final</th></tr></thead>
                          <tbody>
                            <?php foreach ($coordinatorVerification['grades'] as $grade): ?>
                              <tr>
                                <td><?= grades_e($grade['display_student_id'] ?? ($grade['studentID'] ?? '')) ?></td>
                                <td><?= grades_e($grade['student_name'] ?? ('Student ' . $grade['studentID'])) ?></td>
                                <td><?= $grade['midterm'] === null ? '—' : grades_e(number_format((float)$grade['midterm'], 2)) ?></td>
                                <td><?= $grade['final'] === null ? '—' : grades_e(number_format((float)$grade['final'], 2)) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <label class="verification-remark">Remarks
                        <textarea name="remark" rows="3" placeholder="Optional remarks"></textarea>
                      </label>
                      <div class="verification-actions">
                        <button class="gradebook-submit verification-reject" type="submit" name="decision" value="reject">Reject</button>
                        <button class="gradebook-submit" type="submit" name="decision" value="approve">Verify & Forward</button>
                      </div>
                    </form>
                  </section>
                <?php elseif ($isRegistrarAccount): ?>
                  <section class="gradebook-panel verification-panel" aria-labelledby="registrar-student-list-title">
                    <h2 class="gradebook-heading" id="registrar-student-list-title">Enrolled Student List</h2>
                    <?php
                      $selectedDepartment = trim((string)($_GET['department'] ?? ''));
                      $selectedProgram = trim((string)($_GET['program'] ?? ''));
                      $selectedFaculty = (int)($_GET['faculty'] ?? 0);
                      $selectedAcademicYear = (string)($_GET['acad_year'] ?? '');
                      $selectedSemester = (int)($_GET['sem'] ?? 0);
                      $registrarFiltersReady = $registrarStudentList['filters_complete'];
                    ?>
                    <form class="registrar-list-filters" method="get" action="<?= BASE_PATH ?>/grades">
                      <input type="hidden" name="filter_action" value="filter">
                      <label>Department
                        <select name="department" required
                          onchange="this.form.elements.program.value=''; this.form.elements.faculty.value=''; this.form.elements.acad_year.value=''; this.form.elements.sem.value=''; this.form.elements.filter_action.value='filter'; this.form.submit()">
                          <option value="">Choose Department</option>
                          <?php foreach ($registrarStudentList['departments'] as $department): ?>
                            <option value="<?= grades_e($department['department']) ?>" <?= $selectedDepartment === $department['department'] ? 'selected' : '' ?>>
                              <?= grades_e($department['department'] . ' — ' . $department['name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Program
                        <select name="program" required <?= $selectedDepartment === '' ? 'disabled' : '' ?>
                          onchange="this.form.elements.faculty.value=''; this.form.elements.acad_year.value=''; this.form.elements.sem.value=''; this.form.elements.filter_action.value='filter'; this.form.submit()">
                          <option value=""><?= $selectedDepartment === '' ? 'Choose Department First' : 'Choose Program' ?></option>
                          <?php foreach ($registrarStudentList['programs'] as $program): ?>
                            <option value="<?= grades_e($program) ?>" <?= $selectedProgram === $program ? 'selected' : '' ?>>
                              <?= grades_e($program) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Assigned Professor
                        <select name="faculty" required <?= $selectedProgram === '' ? 'disabled' : '' ?>
                          onchange="this.form.elements.acad_year.value=''; this.form.elements.sem.value=''; this.form.elements.filter_action.value='filter'; this.form.submit()">
                          <option value=""><?= $selectedProgram === '' ? 'Choose Program First' : 'Choose Professor' ?></option>
                          <?php foreach ($registrarStudentList['faculty'] as $faculty): ?>
                            <option value="<?= (int)$faculty['facultyID'] ?>" <?= $selectedFaculty === (int)$faculty['facultyID'] ? 'selected' : '' ?>>
                              <?= grades_e($faculty['faculty_name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Academic Year
                        <select name="acad_year" required <?= $selectedFaculty <= 0 ? 'disabled' : '' ?>
                          onchange="this.form.elements.sem.value=''; this.form.elements.filter_action.value='filter'; this.form.submit()">
                          <option value=""><?= $selectedFaculty <= 0 ? 'Choose Professor First' : 'Choose Academic Year' ?></option>
                          <?php foreach ($registrarStudentList['academic_years'] as $academicYear): ?>
                            <option value="<?= grades_e($academicYear) ?>" <?= $selectedAcademicYear === $academicYear ? 'selected' : '' ?>>
                              <?= grades_e(str_replace('-', '–', $academicYear)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Semester
                        <select name="sem" required <?= $selectedAcademicYear === '' ? 'disabled' : '' ?>
                          onchange="this.form.elements.filter_action.value='filter'; this.form.submit()">
                          <option value=""><?= $selectedAcademicYear === '' ? 'Choose Academic Year First' : 'Choose Semester' ?></option>
                          <?php foreach ($registrarStudentList['semesters'] as $semester): ?>
                            <option value="<?= (int)$semester ?>" <?= $selectedSemester === (int)$semester ? 'selected' : '' ?>>
                              <?= grades_e(GradesLogic::semesterLabel($selectedAcademicYear, $semester)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <button class="gradebook-submit" type="submit" name="list_action" value="generate"
                        <?= !$registrarFiltersReady ? 'disabled' : '' ?>>Generate List</button>
                    </form>

                    <?php if ($registrarListAction === 'generate' && $registrarFiltersReady): ?>
                      <div class="class-preview-panel registrar-list-preview">
                        <div class="class-grid">
                          <?php if (!$registrarStudentList['schedules']): ?>
                            <div class="gradebook-empty">No schedules were found for this professor and academic year.</div>
                          <?php else: ?>
                            <?php foreach ($registrarStudentList['schedules'] as $schedule): ?>
                              <article class="class-card">
                                <h3><?= grades_e($schedule['subject_code']) ?> - <?= grades_e($schedule['subject_name']) ?></h3>
                                <ul class="class-meta">
                                  <li><span class="stat-label">Program / Block:</span> <?= grades_e($schedule['program'] . $schedule['block']) ?></li>
                                  <li><span class="stat-label">Term:</span> <?= grades_e(GradesLogic::semesterLabel($schedule['acad_year'], $schedule['sem'])) ?></li>
                                  <li><span class="stat-label">Schedule:</span> <?= grades_e($schedule['day']) ?>, <?= grades_e(date('g:i A', strtotime($schedule['time_start']))) ?>–<?= grades_e(date('g:i A', strtotime($schedule['time_end']))) ?></li>
                                  <li><span class="stat-label">Room:</span> <?= grades_e($schedule['roomID']) ?></li>
                                  <li><span class="stat-label">Enrolled Students:</span> <?= (int)$schedule['student_count'] ?></li>
                                </ul>
                                <form method="get" action="<?= BASE_PATH ?>/grades">
                                  <input type="hidden" name="department" value="<?= grades_e($selectedDepartment) ?>">
                                  <input type="hidden" name="program" value="<?= grades_e($selectedProgram) ?>">
                                  <input type="hidden" name="faculty" value="<?= $selectedFaculty ?>">
                                  <input type="hidden" name="acad_year" value="<?= grades_e($selectedAcademicYear) ?>">
                                  <input type="hidden" name="sem" value="<?= $selectedSemester ?>">
                                  <input type="hidden" name="schedule" value="<?= (int)$schedule['schedID'] ?>">
                                  <button class="gradebook-submit" type="submit" name="list_action" value="preview"
                                    <?= (int)$schedule['student_count'] === 0 ? 'disabled' : '' ?>>Preview Students</button>
                                </form>
                              </article>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php elseif ($registrarListAction === 'preview' && $registrarFiltersReady): ?>
                      <?php if ($registrarStudentList['selected_schedule']): ?>
                        <section class="gradebook-panel verification-panel" aria-labelledby="selected-schedule-title">
                          <h2 class="gradebook-heading" id="selected-schedule-title">
                            <?= grades_e($registrarStudentList['selected_schedule']['subject_code']) ?> -
                            <?= grades_e($registrarStudentList['selected_schedule']['program'] . $registrarStudentList['selected_schedule']['block']) ?>
                            | <?= grades_e(GradesLogic::semesterLabel($registrarStudentList['selected_schedule']['acad_year'], $registrarStudentList['selected_schedule']['sem'])) ?>
                          </h2>
                          <a class="class-scope-btn grades-action-link registrar-schedule-back" href="<?= BASE_PATH ?>/grades?<?= http_build_query([
                              'department' => $selectedDepartment,
                              'program' => $selectedProgram,
                              'faculty' => $selectedFaculty,
                              'acad_year' => $selectedAcademicYear,
                              'sem' => $selectedSemester,
                              'list_action' => 'generate',
                          ]) ?>">Back to Schedules</a>
                          <div class="verification-table-wrap">
                            <table class="grade-table verification-table registrar-student-table">
                              <thead>
                                <tr>
                                  <th>Student ID</th>
                                  <th>Student Name</th>
                                  <th>Program</th>
                                  <th>Year</th>
                                  <th>Block</th>
                                  <th>Academic Year</th>
                                  <th>Semester</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php if (!$registrarStudentList['rows']): ?>
                                  <tr><td colspan="7" class="gradebook-empty">No approved enrolled students are assigned to this schedule.</td></tr>
                                <?php else: ?>
                                  <?php foreach ($registrarStudentList['rows'] as $student): ?>
                                    <tr>
                                      <td><?= grades_e($student['display_student_id']) ?></td>
                                      <td><?= grades_e($student['name']) ?></td>
                                      <td><?= grades_e($student['program']) ?></td>
                                      <td><?= (int)$student['year_level'] ?></td>
                                      <td><?= grades_e($student['block']) ?></td>
                                      <td><?= grades_e(str_replace('-', '–', $student['acad_year'])) ?></td>
                                      <td><?= grades_e(GradesLogic::semesterLabel($registrarStudentList['selected_schedule']['acad_year'], $registrarStudentList['selected_schedule']['sem'])) ?></td>
                                    </tr>
                                  <?php endforeach; ?>
                                <?php endif; ?>
                              </tbody>
                            </table>
                          </div>
                        </section>
                      <?php endif; ?>
                    <?php endif; ?>
                  </section>
                  <section class="gradebook-panel verification-panel" aria-labelledby="registrar-verification-title">
                    <h2 class="gradebook-heading" id="registrar-verification-title">Registrar Final Grade Verification</h2>
                    <?php if (!$registrarSubmissions): ?>
                      <div class="gradebook-empty">No coordinator-verified grades are awaiting final verification.</div>
                    <?php else: ?>
                      <?php foreach ($registrarSubmissions as $submission): ?>
                        <article class="verification-card">
                          <div class="verification-card-header">
                            <div>
                              <strong><?= grades_e($submission['subjectID']) ?><?= $submission['subject_name'] ? ' - ' . grades_e($submission['subject_name']) : '' ?></strong>
                              <div><?= grades_e($submission['program'] . $submission['block']) ?> | <?= grades_e(GradesLogic::semesterLabel($submission['acad_year'], $submission['sem'])) ?></div>
                              <small>Coordinator approved on <?= grades_e($submission['coordinator_verified_at']) ?></small>
                            </div>
                          </div>
                          <form method="post" action="<?= BASE_PATH ?>/grades">
                            <input type="hidden" name="action" value="registrar_review">
                            <input type="hidden" name="csrf" value="<?= grades_e($csrf) ?>">
                            <input type="hidden" name="submissionID" value="<?= (int)$submission['submissionID'] ?>">
                            <div class="verification-table-wrap">
                              <table class="grade-table verification-table">
                                <thead><tr><th>Student ID</th><th>Student</th><th>Midterm</th><th>Final</th></tr></thead>
                                <tbody>
                                  <?php foreach ($submission['grades'] as $grade): ?>
                                    <tr>
                                      <td><?= grades_e($grade['display_student_id'] ?? ($grade['studentID'] ?? '')) ?></td>
                                      <td><?= grades_e($grade['student_name'] ?? ('Student ' . $grade['studentID'])) ?></td>
                                      <td><?= $grade['midterm'] === null ? '—' : grades_e(number_format((float)$grade['midterm'], 2)) ?></td>
                                      <td><?= $grade['final'] === null ? '—' : grades_e(number_format((float)$grade['final'], 2)) ?></td>
                                    </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                            <label class="verification-remark">Remarks
                              <textarea name="remark" rows="2" placeholder="Optional remarks"></textarea>
                            </label>
                            <div class="verification-actions">
                              <button class="gradebook-submit verification-reject" type="submit" name="decision" value="reject">Reject</button>
                              <button class="gradebook-submit" type="submit" name="decision" value="approve">Verify & Add Grades</button>
                            </div>
                          </form>
                        </article>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </section>
                  <section class="gradebook-panel verification-panel" aria-labelledby="registrar-edit-grant-title">
                    <h2 class="gradebook-heading" id="registrar-edit-grant-title">Allow Grade Edits</h2>
                    <p class="gradebook-empty">
                      Allowing edits unlocks this submission in the professor's gradebook. Resubmitted grades will go through verification again.
                    </p>
                    <?php if (!$registrarApprovedSubmissions): ?>
                      <div class="gradebook-empty">There are no approved grade submissions available to reopen.</div>
                    <?php else: ?>
                      <?php foreach ($registrarApprovedSubmissions as $submission): ?>
                        <article class="verification-card">
                          <div class="verification-card-header">
                            <strong>
                              <?= grades_e($submission['subjectID']) ?>
                              <?= $submission['subject_name'] ? ' - ' . grades_e($submission['subject_name']) : '' ?>
                            </strong>
                            <div>
                              <?= grades_e($submission['program'] . $submission['block']) ?>
                              | <?= grades_e(GradesLogic::semesterLabel($submission['acad_year'], $submission['sem'])) ?>
                            </div>
                            <small>
                              Professor: <?= grades_e($submission['submitted_by_name'] ?: 'Professor') ?>
                              | <?= count($submission['grades']) ?> students
                              | Approved <?= grades_e($submission['registrar_verified_at']) ?>
                            </small>
                          </div>
                          <form class="verification-actions" method="post" action="<?= BASE_PATH ?>/grades">
                            <input type="hidden" name="action" value="registrar_allow_edit">
                            <input type="hidden" name="csrf" value="<?= grades_e($csrf) ?>">
                            <input type="hidden" name="submissionID" value="<?= (int)$submission['submissionID'] ?>">
                            <button class="gradebook-submit" type="submit">Allow Professor to Edit</button>
                          </form>
                        </article>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </section>
                <?php elseif ($view === 'gradebook' && $class && $roster): ?>
                  <section class="gradebook-panel" aria-labelledby="gradebook-class-title">
                    <h2 class="gradebook-heading" id="gradebook-class-title">
                      <?= grades_e($class['subject_code']) ?> - <?= grades_e($class['program'] . $class['block']) ?>
                    </h2>
                    <?php
                      $hasEditableGrades = false;
                      $hasLockedGrades = false;
                      $hasEditAllowedGrades = false;
                      foreach ($roster['rows'] as $student) {
                          $state = $gradeSubmissionStates[(string)$student['enrolled_subjectID']] ?? null;
                          if (!empty($state['locked'])) {
                              $hasLockedGrades = true;
                          } else {
                              $hasEditableGrades = true;
                          }
                          if (!empty($state['edit_allowed'])) {
                              $hasEditAllowedGrades = true;
                          }
                      }
                    ?>
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

                      <?php if ($hasLockedGrades): ?>
                        <p class="gradebook-empty">Submitted grades are locked while awaiting verification and after approval.</p>
                      <?php endif; ?>
                      <?php if ($hasEditAllowedGrades): ?>
                        <p class="gradebook-empty">The registrar allowed edits to these submitted grades. Resubmit them for verification when finished.</p>
                      <?php endif; ?>
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
                                <th scope="col">Semester Grade</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($roster['rows'] as $student):
                                  $enrolledID = (string)$student['enrolled_subjectID'];
                                  $state = $gradeSubmissionStates[$enrolledID] ?? null;
                                  $gradeLocked = !empty($state['locked']);
                                  $old = $gradeLocked ? [] : ($oldGrades[$enrolledID] ?? []);
                                  $midterm = $gradeLocked
                                      ? $state['midterm']
                                      : (array_key_exists('midterm', $old) ? $old['midterm'] : $student['midterm_grade']);
                                  $final = $gradeLocked
                                      ? $state['final']
                                      : (array_key_exists('final', $old) ? $old['final'] : $student['final_grade']);
                              ?>
                                <tr>
                                  <td><?= grades_e($student['display_student_id']) ?></td>
                                  <td><?= grades_e($student['name']) ?></td>
                                  <td>
                                    <input type="number" name="grades[<?= (int)$student['enrolled_subjectID'] ?>][midterm]"
                                      min="0" max="100" step="0.01" value="<?= grades_e($midterm) ?>"
                                      aria-label="Midterm grade for <?= grades_e($student['name']) ?>"
                                      <?= $gradeLocked ? 'disabled' : '' ?>>
                                  </td>
                                  <td>
                                    <input type="number" name="grades[<?= (int)$student['enrolled_subjectID'] ?>][final]"
                                      min="0" max="100" step="0.01" value="<?= grades_e($final) ?>"
                                      aria-label="Final grade for <?= grades_e($student['name']) ?>"
                                      <?= $gradeLocked ? 'disabled' : '' ?>>
                                  </td>
                                  <td data-semester-grade aria-label="Semester grade for <?= grades_e($student['name']) ?>">
                                    <?php if ($midterm !== '' && $midterm !== null && $final !== '' && $final !== null
                                        && is_numeric($midterm) && is_numeric($final)): ?>
                                      <?= number_format(((float)$midterm + (float)$final) / 2, 2) ?>
                                    <?php else: ?>
                                      —
                                    <?php endif; ?>
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
                        <button class="gradebook-submit" type="submit" <?= !$hasEditableGrades ? 'disabled' : '' ?>>Submit Grade</button>
                      </div>
                    </form>
                  </section>
                <?php elseif ($view === 'gradebook'): ?>
                  <section class="gradebook-panel" aria-labelledby="gradebook-class-title">
                    <h2 class="gradebook-heading" id="gradebook-class-title">Grade Book</h2>
                    <div class="gradebook-empty">
                      <?= $assignedClasses
                          ? 'Choose a class from the preview to view its gradebook.'
                          : 'No assigned class schedule or student grades are available to display.' ?>
                    </div>
                  </section>
                <?php else: ?>
                  <?php if ($isCoordinatorAccount): ?>
                    <details class="coordinator-verification-preview">
                      <summary>
                        Pending grade verification
                        <span><?= count($coordinatorSubmissions) ?></span>
                      </summary>
                      <?php if (!$coordinatorSubmissions): ?>
                        <div class="coordinator-verification-empty">No grade submissions are awaiting coordinator verification.</div>
                      <?php else: ?>
                        <div class="coordinator-verification-list">
                          <?php foreach ($coordinatorSubmissions as $submission): ?>
                            <article class="coordinator-verification-item">
                              <div>
                                <strong>
                                  <?= grades_e($submission['subjectID']) ?>
                                  <?= $submission['subject_name'] ? ' - ' . grades_e($submission['subject_name']) : '' ?>
                                </strong>
                                <small>
                                  <?= grades_e($submission['program'] . $submission['block']) ?>
                                  | <?= grades_e(GradesLogic::semesterLabel($submission['acad_year'], $submission['sem'])) ?>
                                  | <?= count($submission['grades']) ?> students
                                </small>
                              </div>
                              <a class="class-scope-btn grades-action-link" href="<?= BASE_PATH ?>/grades?<?= http_build_query([
                                  'view' => 'verification',
                                  'submission' => (int)$submission['submissionID'],
                                  'sem' => $semesterKey,
                              ]) ?>">Review</a>
                            </article>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </details>
                  <?php endif; ?>

                  <?php if (empty($classPreview)): ?>
                    <div class="empty-state">No assigned class schedule found for this account.</div>
                  <?php else: ?>
                    <div class="class-preview-panel">
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
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </section>
            </div>
          </div>
        </div>
        <?php else: ?>
          <p>Account role could not be verified for this grades page.</p>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
  <?php if ($isFacultyAccount && $view === 'gradebook' && $class && $roster && $roster['rows']): ?>
    <script>
      document.querySelectorAll('.grade-table tbody tr').forEach(function (row) {
        var midterm = row.querySelector('input[name$="[midterm]"]');
        var final = row.querySelector('input[name$="[final]"]');
        var semester = row.querySelector('[data-semester-grade]');

        function updateSemesterGrade() {
          if (midterm.value === '' || final.value === '') {
            semester.textContent = '—';
            return;
          }

          var midtermGrade = Number(midterm.value);
          var finalGrade = Number(final.value);
          if (!Number.isFinite(midtermGrade) || !Number.isFinite(finalGrade)) {
            semester.textContent = '—';
            return;
          }

          semester.textContent = ((midtermGrade + finalGrade) / 2).toFixed(2);
        }

        midterm.addEventListener('input', updateSemesterGrade);
        final.addEventListener('input', updateSemesterGrade);
      });
    </script>
  <?php endif; ?>
</body>
</html>