<?php
require_once __DIR__ . '/../PageLogic/clearance_logic.php';
global $clearanceDAO, $userDAO;

if (strtoupper($_SESSION['role'] ?? '') === 'STUDENT') {
    http_response_code(403);
    exit('Faculty only.');
}

$subjectID = trim($_POST['subjectID'] ?? '');
$block     = trim($_POST['block'] ?? '');
$term      = trim($_POST['acad_year_sem'] ?? '');
if ($subjectID === '' || $block === '' || $term === '' || $term === 'ALL') {
    http_response_code(400);
    exit('Pick a subject, block and term first.');
}
var_dump($subjectID.$block.$term);
// the professor must really handle this subject, block and term
$handled = $clearanceDAO->executeQuery('getSubjects', [
    'ALL', 'ALL',
    $subjectID, $subjectID,
    $block, $block,
    'ALL', 'ALL',
    $term, $term,
    $_SESSION['userID']
]);

if (!$handled) {
    http_response_code(403);
    exit('You do not handle that class.');
}

$students = $clearanceDAO->executeQuery('getClassListForm',
    [$subjectID, $block, $block, $term, $_SESSION['userID']]);
if (!$students) {
    http_response_code(404);
    exit('No enrolled students found.');
}

$head = $students[0];

// professor name for the signature line
$prof = $userDAO->executeQuery('getFacultyInfo', [$_SESSION['userID']])[0] ?? null;
$profName = $prof ? trim($prof['first_name'] . ' ' . $prof['last_name']) : '';

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$isCleared  = fn($r) => strtolower(trim($r['status'] ?? '')) === 'cleared';
// no pending or unsubmitted project = every project has a submission (zero projects counts as clear)
$projClear  = fn($r) => (int)$r['submitted_projects'] >= (int)$r['total_projects'];

$file = 'classlist_' . preg_replace('/[^A-Za-z0-9_-]/', '', $subjectID . '_' . $block) . '.doc';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<style>
  body  { font-family: Arial, sans-serif; font-size: 11pt; }
  h1    { text-align: center; font-size: 18pt; margin-bottom: 0; }
  h3    { text-align: center; margin-top: 0; font-weight: normal; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #000; padding: 6px; }
  th    { background: #e6e6e6; }
  .c    { text-align: center; }
  .sig  { border: none; text-align: center; padding-top: 40px; }
  .line { border-top: 1px solid #000; padding-top: 4px; margin: 0 60px; }
</style>
</head>
<body>
  <h1>CLASS CLEARANCE LIST</h1>
  <h3><?= e($head['acad_year_sem']) ?></h3>

  <p>
    <b>Subject:</b> <?= e($subjectID) ?> - <?= e($head['subject_name']) ?><br>
    <b>Instructor:</b> <?= e($profName) ?>
  </p>

  <table>
    <tr>
      <th>Block</th>
      <th>Student No.</th>
      <th>Name</th>
      <th>Program</th>
      <th>Project</th>
      <th>Cleared</th>
      
    </tr>
    <?php foreach ($students as $i => $r): ?>
    <tr>
      <td class="c"><?= e($r['block']) ?></td>
      <td><?= e($r['studentID']) ?></td>
      <td><?= e($r['student_name']) ?></td>
      <td><?= e($r['program']) ?></td>
      <td class="c"><?= $projClear($r) ? 'CLEARED' : 'NOT CLEARED' ?></td>
      <td class="c"><?= $isCleared($r) ? 'CLEARED' : '' ?></td>
      
    </tr>
    <?php endforeach ?>
  </table>

  <p><i>Blank = not yet cleared, rejected, or has a project that is pending or not submitted.</i></p>

  <table>
    <tr>
      <td class="sig">
        <div class="line"><b><?= e($profName) ?></b><br>Instructor</div>
      </td>
    </tr>
  </table>

  <p>Date generated: <?= date('F j, Y') ?></p>
</body>
</html>