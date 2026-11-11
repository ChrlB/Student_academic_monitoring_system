<?php
require_once __DIR__ . '/DriveStorageService.php';
require_once __DIR__ . '/DocumentUploadService.php';

function uploadDocument($request): void
{
    global $userDAO;
    global $clearanceDAO;

    //$pageAfterUpload = BASE_PATH.'/documents';
    // $pageAfterUpload = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pageAfterUpload = $request["page_uploader"];

    if (empty($_SESSION['userID'])) {
        http_response_code(403);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document'])) {
        header('Location: ' . $pageAfterUpload);
        exit;
    }

    

    $uploadedFile = $_FILES['document'];
    $validation = (new DocumentUploadService())->validate($uploadedFile);

    if (!$validation['success']) {
        $_SESSION['message'] = $validation['message'];
        header('Location: ' . $pageAfterUpload);
        exit;
    }

    // Use a fixed MIME type per extension, since finfo can report .docx as zip
    $mimeTypeByExtension = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $savedMimeType  = $mimeTypeByExtension[$validation['extension']];
    $storedFileName = bin2hex(random_bytes(16)) . '.' . $validation['extension'];
    $driveFileId    = null;

    try {
        $driveConfig = require __DIR__ . '/../config.local.php';



        $driveStorageService = new DriveStorageService(
            $driveConfig['oauthClientPath'],
            $driveConfig['refreshToken'],
            ($_SESSION['folderID'] == null)
                ? $driveConfig['driveFolderId'] 
                : $_SESSION['folderID']
        );

        if ($_SESSION['folderID'] == null) {
            $createdFolderId = $driveStorageService->createFolder($_SESSION['userID']);

            $query = ($_SESSION['role'] == 'student') ? 'saveStudentFolderID' : 'saveFacultyFolderID';
            $userDAO->executeUpdate($query, [$createdFolderId, $_SESSION['userID']]);

            $_SESSION['folderID'] = $createdFolderId;
            $driveStorageService = new DriveStorageService(
                $driveConfig['oauthClientPath'],
                $driveConfig['refreshToken'],
                $createdFolderId
            );
        }

        $driveFileId = $driveStorageService->uploadFile(
            $uploadedFile['tmp_name'],
            $storedFileName,
            $savedMimeType
        );



        $clearanceDAO->executeUpdate($request['docs_for'],
            [
                $request["ID"],
                $driveFileId,
                $validation['originalName'],
                $savedMimeType,
                (int) $uploadedFile['size'],
                $_SESSION['userID'],
            ]
        );

        $_SESSION['message'] = 'Document uploaded!';
    } catch (Throwable $error) {
        // If Drive got the file but the database did not, remove the orphan
        if ($driveFileId !== null) {
            try {
                $driveStorageService->deleteFile($driveFileId);
            } catch (Throwable $cleanupError) {
                error_log('Orphan Drive file ' . $driveFileId . ': ' . $cleanupError->getMessage());
            }
        }
        error_log('Document upload failed: ' . $error->getMessage());
        $_SESSION['error'] = 'Could not upload the document.';
    }

    header('Location: ' . $pageAfterUpload);
    exit;
}


function downloadDocument(): void
{
    if (empty($_SESSION['userID'])) {
        http_response_code(403);
        exit;
    }

    $requestedDocumentId = (int) ($_GET['id'] ?? 0);
    $wantsDownload       = ($_GET['download'] ?? '') === '1';
    $loggedInUserId      = $_SESSION['userID'];
    $loggedInRole        = $_SESSION['role'];
    $docFor = $_GET['docfor'] ?? '';
    $categ  = $_GET['categ'] ?? '';

    
    session_write_close();

    $documentRows = [];
    $document = [];

    global $clearanceDAO;
    if($docFor === "clearance"){
        if (!in_array($categ, ['S', 'F', 'O'], true) || $requestedDocumentId < 1) {
            http_response_code(400);
            exit;
        }
        $documentRows = $clearanceDAO->executeQuery('findClearanceDocument', [$categ.$requestedDocumentId, $_SESSION['userID']]);
        if (empty($documentRows)) {
            http_response_code(404);
            exit;
        }
        $document = $documentRows[0];
    } else {
        http_response_code(400);
        exit;
    }

    // permission check goes here (see point 2)

    // only PDFs can display inline; Word files always download
    $disposition = ($wantsDownload || $document['mime_type'] !== 'application/pdf')
        ? 'attachment' : 'inline';

    $driveConfig = require __DIR__ . '/../config.local.php';
    $driveStorageService = new DriveStorageService(
        $driveConfig['oauthClientPath'],
        $driveConfig['refreshToken'],
        $driveConfig['driveFolderId']
    );

    $downloadName = str_replace(['"', "\r", "\n", '/', '\\'], '', $document['file_name']);

    while (ob_get_level() > 0) { ob_end_clean(); }
    set_time_limit(0);

    header('Content-Type: ' . $document['mime_type']);
    header("Content-Disposition: {$disposition}; filename=\"{$downloadName}\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . (int) $document['file_size']);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, no-store');

    try {
        $driveStorageService->streamFile($document['fileID']);
    } catch (Throwable $e) {
        error_log('Drive stream failed: ' . $e->getMessage());
    }
    exit;
}

// function downloadDocument(): void
// {
//     global $userDAO;

//     if (empty($_SESSION['userID'])) {
//         http_response_code(403);
//         exit;
//     }

//     $requestedDocumentId = (int) ($_GET['id'] ?? 0);
//     $loggedInUserId = $_SESSION['userID'];
//     $loggedInRole   = $_SESSION['role'];
//     session_write_close(); // release the session lock before streaming

//     $documentRows = $userDAO->executeQuery('findDocument', [$requestedDocumentId]);
//     if (empty($documentRows)) {
//         http_response_code(404);
//         exit;
//     }
//     $document = $documentRows[0];

//     // YOUR RULE: who may open this document? For example only the owner,
//     // plus certain roles. Adjust the column names to match your table.
//     // $isOwner = ($document['owner_id'] === (string) $loggedInUserId);
//     // if (!$isOwner) {
//     //     http_response_code(403);
//     //     exit;
//     // }

//     $driveConfig = require __DIR__ . '/../config.local.php';
//     $driveStorageService = new DriveStorageService(
//         $driveConfig['oauthClientPath'],
//         $driveConfig['refreshToken'],
//         $driveConfig['driveFolderId']
//     );

//     $downloadName = str_replace(['"', "\r", "\n"], '', $document['original_name']);
//     header('Content-Type: ' . $document['mime_type']);
//     header("Content-Disposition: attachment; filename=\"{$downloadName}\"; filename*=UTF-8''" . rawurlencode($downloadName));
//     header('Content-Length: ' . (int) $document['file_size']);
//     header('Cache-Control: private, no-store');

//     $driveStorageService->streamFile($document['drive_file_id']);
//     exit;
// }

/**
 * Only allow redirects to paths on this site.
//  */
// function safeRedirectPath(?string $path, string $fallback): string
// {
//     if (
//         !is_string($path) || $path === '' ||
//         $path[0] !== '/' ||                 // must be a local path
//         substr($path, 0, 2) === '//' ||     // blocks //evil.com
//         strpos($path, '\\') !== false ||    // blocks /\evil.com
//         preg_match('/[\r\n]/', $path)       // blocks header injection
//     ) {
//         return $fallback;
//     }
//     return $path;
// }

// /**
//  * Turn $_FILES['document'] (single or multiple) into a list of
//  * single-file arrays, skipping empty slots.
//  */
// function normalizeUploadedFiles(array $input): array
// {
//     $files = [];

//     if (!is_array($input['name'])) {
//         $input = array_map(fn($v) => [$v], $input);  // wrap single upload
//     }

//     foreach ($input['name'] as $i => $name) {
//         if ($input['error'][$i] === UPLOAD_ERR_NO_FILE) {
//             continue;
//         }
//         $files[] = [
//             'name'     => $name,
//             'type'     => $input['type'][$i],
//             'tmp_name' => $input['tmp_name'][$i],
//             'error'    => $input['error'][$i],
//             'size'     => $input['size'][$i],
//         ];
//     }
//     return $files;
// }

// /**
//  * Validate -> upload to Drive -> save to DB for ONE file.
//  * Returns [success(bool), message(string)].
//  */
// function uploadOneDocument(
//     array $file,
//     $requirementID,
//     DriveStorageService $drive,
//     $clearanceDAO
// ): array {
//     static $mimeTypeByExtension = [
//         'pdf'  => 'application/pdf',
//         'doc'  => 'application/msword',
//         'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
//     ];

//     $label = basename((string) $file['name']);

//     $validation = (new DocumentUploadService())->validate($file);
//     if (!$validation['success']) {
//         return [false, $label . ': ' . $validation['message']];
//     }

//     $savedMimeType  = $mimeTypeByExtension[$validation['extension']];
//     $storedFileName = bin2hex(random_bytes(16)) . '.' . $validation['extension'];
//     $driveFileId    = null;

//     try {
//         $driveFileId = $drive->uploadFile($file['tmp_name'], $storedFileName, $savedMimeType);

//         $clearanceDAO->executeUpdate("addSubjectRequirement", [
//             $requirementID,
//             $driveFileId,
//             $validation['originalName'],
//             $savedMimeType,
//             (int) $file['size'],
//             $_SESSION['userID'],
//         ]);

//         return [true, $label];
//     } catch (Throwable $error) {
//         // Drive got the file but the database did not: remove the orphan
//         if ($driveFileId !== null) {
//             try {
//                 $drive->deleteFile($driveFileId);
//             } catch (Throwable $cleanupError) {
//                 error_log('Orphan Drive file ' . $driveFileId . ': ' . $cleanupError->getMessage());
//             }
//         }
//         error_log('Document upload failed (' . $label . '): ' . $error->getMessage());
//         return [false, $label . ': could not be uploaded.'];
//     }
// }

// function uploadDocument($request): void
// {
//     global $clearanceDAO;

//     $pageAfterUpload = safeRedirectPath(
//         $request['page_uploader'] ?? null,
//         BASE_PATH . '/clearance'
//     );

//     if (empty($_SESSION['userID'])) {
//         http_response_code(403);
//         exit;
//     }

//     if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//         header('Location: ' . $pageAfterUpload);
//         exit;
//     }

//     // $_FILES is empty when the request exceeds post_max_size
//     if (!isset($_FILES['document'])) {
//         $_SESSION['error'] = 'No files received. They may be too large.';
//         header('Location: ' . $pageAfterUpload);
//         exit;
//     }

//     $files = normalizeUploadedFiles($_FILES['document']);

//     if (!$files) {
//         $_SESSION['error'] = 'Please attach at least one file.';
//         header('Location: ' . $pageAfterUpload);
//         exit;
//     }

//     $maxFiles = 10;
//     if (count($files) > $maxFiles) {
//         $_SESSION['error'] = 'You can upload up to ' . $maxFiles . ' files at a time.';
//         header('Location: ' . $pageAfterUpload);
//         exit;
//     }

//     // TODO: confirm here that the logged-in student is allowed to upload
//     // to $request['ID'] (use your existing DAO check for this).

//     $succeeded = [];
//     $failed    = [];

//     try {
//         $driveConfig = require __DIR__ . '/../config.local.php';
//         $folderID    = $_SESSION['folderID'] ?? null;

//         // Create the student's folder ONCE, before the loop
//         if ($folderID === null) {
//             $rootDrive = new DriveStorageService(
//                 $driveConfig['oauthClientPath'],
//                 $driveConfig['refreshToken'],
//                 $driveConfig['driveFolderId']
//             );
//             $folderID = $rootDrive->createFolder($_SESSION['userID']);
//             $clearanceDAO->executeUpdate("saveStudentFolderID", [$folderID]);
//             // Only cache in the session after the DB save succeeded
//             $_SESSION['folderID'] = $folderID;
//         }

//         $drive = new DriveStorageService(
//             $driveConfig['oauthClientPath'],
//             $driveConfig['refreshToken'],
//             $folderID
//         );

//         foreach ($files as $file) {
//             [$ok, $message] = uploadOneDocument($file, $request['ID'], $drive, $clearanceDAO);
//             if ($ok) {
//                 $succeeded[] = $message;
//             } else {
//                 $failed[] = $message;
//             }
//         }
//     } catch (Throwable $error) {
//         error_log('Document upload setup failed: ' . $error->getMessage());
//         $failed[] = 'Could not connect to file storage. Please try again.';
//     }

//     if ($succeeded) {
//         $_SESSION['message'] = count($succeeded) . ' file(s) uploaded: ' . implode(', ', $succeeded);
//     }
//     if ($failed) {
//         $_SESSION['error'] = implode(' | ', $failed);
//     }

//     header('Location: ' . $pageAfterUpload);
//     exit;
// }