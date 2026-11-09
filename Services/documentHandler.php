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
            $_SESSION['folderID'] = $driveStorageService->createFolder($_SESSION["userID"]);
            $driveStorageService = new DriveStorageService(
                $driveConfig['oauthClientPath'],
                $driveConfig['refreshToken'],
                $_SESSION['folderID']
            );
            $clearanceDAO->executeUpdate("saveStudentFolderID",[$_SESSION['folderID']]);
        }

        $driveFileId = $driveStorageService->uploadFile(
            $uploadedFile['tmp_name'],
            $storedFileName,
            $savedMimeType
        );



        $clearanceDAO->executeUpdate("addSubjectRequirement",
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