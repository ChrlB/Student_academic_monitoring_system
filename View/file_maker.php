<?php
// header("Content-type: application/vnd.ms-word");
// header("Content-Disposition: attachment;Filename=document.doc");

// echo "<html>";
// echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=Windows-1252\">";
// echo "<body>";
// echo "<h1>My Document Title</h1>";
// echo "<p>This is generated directly from PHP.</p>";
// echo "</body>";
// echo "</html>";

?>
<?php
require_once __DIR__ . '/../PageLogic/clearance_logic.php';
global $clearanceDAO;

if (strtoupper($_SESSION['role'] ?? '') !== 'STUDENT') {
    http_response_code(403);
    exit('Students only.');
}

// the student comes from the session, never from the form
$studentCode = $_SESSION['userID'];
$term = trim($_POST['acad_year_sem'] ?? '');
if ($term === '' || $term === 'ALL') {
    http_response_code(400);
    exit('Pick a term first.');
}

$rows = $clearanceDAO->executeQuery('getClearanceFormSubjects', [$studentCode, $term]);
if (!$rows) {
    http_response_code(404);
    exit('No enrolled subjects found for that term.');
}

// one row per subject, even if a subject has two schedule rows
$subjects = [];
foreach ($rows as $r) { $subjects[$r['enrolled_subjectID']] = $r; }
$subjects = array_values($subjects);

$head = $subjects[0];
$sigs = $clearanceDAO->executeQuery('getClearanceFormSignatories',
    [$head['department'], $head['program']]);

$dean = $coor = null;
foreach ($sigs as $s) {
    $role = strtolower($s['role']);
    if (strpos($role, 'dean') !== false)        $dean = $s['name'];
    if (strpos($role, 'coordinator') !== false) $coor = $s['name'];
}

$isCleared = fn($r) => strtolower(trim($r['status'] ?? '')) === 'cleared';
$allCleared = count(array_filter($subjects, $isCleared)) === count($subjects);



$file = 'clearance_' . preg_replace('/[^A-Za-z0-9_-]/', '', $studentCode) . '.doc';

while (ob_get_level()) ob_end_clean();   // nothing may be sent before the headers
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
  .sig  { border: none; text-align: center; padding-top: 40px; width: 50%; }
  .line { border-top: 1px solid #000; padding-top: 4px; }
</style>
</head>
<body>
  <h1>CLEARANCE FORM</h1>
  <h3><?= e($head['acad_year_sem']) ?></h3>

  <p>
    <b>Name:</b> <?= e($head['student_name']) ?><br>
    <b>Student No.:</b> <?= e($studentCode) ?><br>
    <b>Program / Block:</b> <?= e($head['program']) ?> - <?= e($head['block']) ?>
  </p>

  <table>
    <tr>
      <th>Subject Code</th>
      <th>Subject</th>
      <th>Instructor</th>
      <th>Cleared</th>
    </tr>
    <?php foreach ($subjects as $r): ?>
    <tr>
      <td><?= e($r['subjectID']) ?></td>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['instructor'] ?? '') ?></td>
      <td class="c"><?= $isCleared($r) ? 'CLEARED' : '' ?></td>
    </tr>
    <?php endforeach ?>
  </table>

  <?php if (!$allCleared): ?>
    <p><i>Note: some subjects are not yet cleared.</i></p>
  <?php endif ?>

  <table style="margin-top:30px">
    <tr>
      <td class="sig">
        <div class="line"><b><?= e($coor ?? '') ?></b><br>Program Coordinator</div>
      </td>
      <td class="sig">
        <div class="line"><b><?= e($dean ?? '') ?></b><br>Dean</div>
      </td>
    </tr>
  </table>

  <p>Date generated: <?= date('F j, Y') ?></p>
</body>
</html>