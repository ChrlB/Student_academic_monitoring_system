<?php

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class DriveStorageService
{
  private Drive $driveService;
  private string $targetFolderId;

  public function __construct(string $oauthClientPath, string $refreshToken, string $targetFolderId)
  {
    $googleClient = new Client();
    $googleClient->setAuthConfig($oauthClientPath);
    $googleClient->addScope(Drive::DRIVE);
    $googleClient->setAccessType('offline');

    $tokenResponse = $googleClient->fetchAccessTokenWithRefreshToken($refreshToken);
    if (isset($tokenResponse['error'])) {
      throw new RuntimeException('Google token error: ' . ($tokenResponse['error_description'] ?? $tokenResponse['error']));
    }

    $this->driveService = new Drive($googleClient);
    $this->targetFolderId = $targetFolderId;
  }

  /** Uploads a local file and returns the Drive file ID. */
  public function uploadFile(
    string $localFilePath, string $driveFileName, string $mimeType,
    ?string $parentFolderId = null
  ): string {
    $driveFile = new DriveFile([
      'name'    => $driveFileName,
      'parents' => [$parentFolderId ?? $this->targetFolderId],
    ]);
    $createdFile = $this->driveService->files->create($driveFile, [
      'data'              => file_get_contents($localFilePath),
      'mimeType'          => $mimeType,
      'uploadType'        => 'multipart',
      'fields'            => 'id',
      'supportsAllDrives' => true,
    ]);

    return $createdFile->id;
  }

  /** Streams a file from Drive straight to the browser. */
  public function streamFile(string $driveFileId): void
  {
    $response = $this->driveService->files->get($driveFileId, [
      'alt'               => 'media',
      'supportsAllDrives' => true,
    ]);

    /** @var \Psr\Http\Message\ResponseInterface $response */
    $fileStream = $response->getBody();
    while (!$fileStream->eof()) {
      echo $fileStream->read(8192);
    }
  }

  public function deleteFile(string $driveFileId): void
  {
    $this->driveService->files->delete($driveFileId, ['supportsAllDrives' => true]);
  }

  /** Creates a folder inside the main folder and returns its Drive ID. */
  public function createFolder(string $folderName): string
  {
    $folderMetadata = new DriveFile([
      'name'     => $folderName,
      'mimeType' => 'application/vnd.google-apps.folder',
      'parents'  => [$this->targetFolderId],
    ]);

    $createdFolder = $this->driveService->files->create($folderMetadata, [
      'fields'            => 'id',
      'supportsAllDrives' => true,
    ]);

    return $createdFolder->id;
  }
}
