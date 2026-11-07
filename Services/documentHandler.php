<?php
require_once __DIR__ . '/DriveStorageService.php';
require_once __DIR__ . '/DocumentUploadService.php';

function uploadDocument($request): void
{
    global $userDAO;

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
        }

        $driveFileId = $driveStorageService->uploadFile(
            $uploadedFile['tmp_name'],
            $storedFileName,
            $savedMimeType
        );

        // $userDAO->executeUpdate('saveDocument', [
        //     $_SESSION['userID'],
        //     $_SESSION['role'],
        //     $driveFileId,
        //     $validation['originalName'],
        //     $savedMimeType,
        //     (int) $uploadedFile['size'],
        // ]);

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
    global $userDAO;

    if (empty($_SESSION['userID'])) {
        http_response_code(403);
        exit;
    }

    $requestedDocumentId = (int) ($_GET['id'] ?? 0);
    $loggedInUserId = $_SESSION['userID'];
    $loggedInRole   = $_SESSION['role'];
    session_write_close(); // release the session lock before streaming

    $documentRows = $userDAO->executeQuery('findDocument', [$requestedDocumentId]);
    if (empty($documentRows)) {
        http_response_code(404);
        exit;
    }
    $document = $documentRows[0];

    // YOUR RULE: who may open this document? For example only the owner,
    // plus certain roles. Adjust the column names to match your table.
    // $isOwner = ($document['owner_id'] === (string) $loggedInUserId);
    // if (!$isOwner) {
    //     http_response_code(403);
    //     exit;
    // }

    $driveConfig = require __DIR__ . '/../config.local.php';
    $driveStorageService = new DriveStorageService(
        $driveConfig['oauthClientPath'],
        $driveConfig['refreshToken'],
        $driveConfig['driveFolderId']
    );

    $downloadName = str_replace(['"', "\r", "\n"], '', $document['original_name']);
    header('Content-Type: ' . $document['mime_type']);
    header("Content-Disposition: attachment; filename=\"{$downloadName}\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . (int) $document['file_size']);
    header('Cache-Control: private, no-store');

    $driveStorageService->streamFile($document['drive_file_id']);
    exit;
}