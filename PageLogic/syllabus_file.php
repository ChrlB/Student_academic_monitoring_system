<?php
/*
  GET /syllabus-file?id=CONTENT_ID[&download=1]
  Streams an uploaded PDF / video after checking the login + class access.
  Supports HTTP Range requests so videos can be seeked in the browser.
*/
require_once __DIR__ . '/../Services/auth.php';
require_once __DIR__ . '/syllabus_logic.php';

function syllabusFileNotFound(string $msg = 'File not found.'): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$sl    = new SyllabusLogic();
$id    = (int)($_GET['id'] ?? 0);
$imgId = (int)($_GET['image'] ?? 0);

if ($imgId > 0) {                       // picture inside a lesson page
    $row = $sl->imageForServing($imgId);
} else {                                // PDF / video material
    $row = $id > 0 ? $sl->contentForServing($id) : null;
}

if (!$row || empty($row['stored_name'])) syllabusFileNotFound();
if (!preg_match('/^[a-f0-9]{32}\.(pdf|mp4|webm|png|jpe?g|gif|webp)$/', $row['stored_name'])) syllabusFileNotFound();

$path = SyllabusLogic::storageDir() . '/' . $row['stored_name'];
if (!is_file($path)) syllabusFileNotFound('The file is missing on the server.');

// A student opening a PDF/video again after a failed quiz counts as "studying again".
if ($imgId <= 0 && isset($row['taskID'])) {
    $sl->noteMaterialOpened((int)$row['taskID']);
}

// Let other requests use the session while a long video streams.
session_write_close();

$size     = (int) filesize($path);
$mime     = $row['mime_type'] ?: 'application/octet-stream';
$download = isset($_GET['download']);
$name     = $row['file_name'] ?: $row['stored_name'];
$ascii    = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

header_remove('Pragma');
header_remove('Expires');
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
     . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));

$start  = 0;
$end    = $size - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)
    && !($m[1] === '' && $m[2] === '')) {
    if ($m[1] === '') {                       // suffix range: last N bytes
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

http_response_code($status);
header('Content-Length: ' . ($end - $start + 1));

while (ob_get_level() > 0) ob_end_clean();
set_time_limit(0);

$fp = fopen($path, 'rb');
if ($fp === false) exit;
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $chunk = fread($fp, min(131072, $left));
    if ($chunk === false) break;
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fp);
exit;