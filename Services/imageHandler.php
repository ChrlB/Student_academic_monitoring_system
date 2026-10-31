<?php

class ImageUploadService
{
    private int $maxUploadSizeInBytes;
    private int $maxImageDimension;
    private int $jpegQuality;
    private array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        int $maxUploadSizeInBytes = 5 * 1024 * 1024,
        int $maxImageDimension = 600,
        int $jpegQuality = 85
    ) {
        $this->maxUploadSizeInBytes = $maxUploadSizeInBytes;
        $this->maxImageDimension = $maxImageDimension;
        $this->jpegQuality = $jpegQuality;
    }

   
    public function processUploadedImage(array $uploadedFile): array
    {
        if (!extension_loaded('gd')) {
            return $this->failure('GD extension is not enabled.');
        }

        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->failure('Upload failed.');
        }

        if ($uploadedFile['size'] > $this->maxUploadSizeInBytes) {
            $maxSizeInMegabytes = $this->maxUploadSizeInBytes / (1024 * 1024);
            return $this->failure("File is too large (max {$maxSizeInMegabytes} MB).");
        }

        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedFile['tmp_name']);
        if (!in_array($detectedMimeType, $this->allowedMimeTypes, true)) {
            return $this->failure('Only JPEG, PNG or WebP allowed.');
        }

        $originalImage = @imagecreatefromstring(file_get_contents($uploadedFile['tmp_name']));
        if ($originalImage === false) {
            return $this->failure('The file is not a valid image.');
        }

        $resizedImage = $this->resizeImage($originalImage);

        ob_start();
        imagejpeg($resizedImage, null, $this->jpegQuality);
        $imageBinaryData = ob_get_clean();

        return [
            'success'         => true,
            'message'         => 'Image processed.',
            'imageBinaryData' => $imageBinaryData,
            'mimeType'        => 'image/jpeg',
        ];
    }

    private function resizeImage(GdImage $originalImage): GdImage
    {
        $originalWidth = imagesx($originalImage);
        $originalHeight = imagesy($originalImage);

        $scaleFactor = min(1, $this->maxImageDimension / max($originalWidth, $originalHeight));
        $resizedWidth = (int) round($originalWidth * $scaleFactor);
        $resizedHeight = (int) round($originalHeight * $scaleFactor);

        $resizedImage = imagecreatetruecolor($resizedWidth, $resizedHeight);
        imagefill($resizedImage, 0, 0, imagecolorallocate($resizedImage, 255, 255, 255));
        imagecopyresampled(
            $resizedImage, $originalImage,
            0, 0, 0, 0,
            $resizedWidth, $resizedHeight,
            $originalWidth, $originalHeight
        );

        return $resizedImage;
    }

    private function failure(string $message): array
    {
        return [
            'success'         => false,
            'message'         => $message,
            'imageBinaryData' => null,
            'mimeType'        => null,
        ];
    }
}
require_once __DIR__."/session.php";

function uploadProfile(){
  global $userDAO; 

  $loggedInUserId = $_SESSION['userID'] ; 

  if (isset($_FILES['profile_picture'])) {
      $imageUploadService = new ImageUploadService();
      $processedImage = $imageUploadService->processUploadedImage($_FILES['profile_picture']);

      if (!$processedImage['success']) {
          $_SESSION["error"] = $processedImage['message'];
      } else {
          $imageBinaryData = $processedImage['imageBinaryData'];
          $savedMimeType = $processedImage['mimeType'];
          // $saveStatement->bind_param('iss', $loggedInUserId, $imageBinaryData, $savedMimeType);

          $upload_for = ($_SESSION['role'] == "student")
                ? "uploadStudentProfile"
                : "uploadFacultyProfile";

          $rows_affected = $userDAO->executeUpdate($upload_for, [ $imageBinaryData, $savedMimeType, $loggedInUserId]);

          if ($rows_affected > 0) $_SESSION["message"] = 'Profile Picture Saved!';
            
      }

      
      header('Location: /dashboard');
      exit;
  }
}

function getProfilePic(){
    global $userDAO;

    $queryTitle = ($_SESSION['role'] === 'student')
        ? 'getStudentProfile'
        : 'getFacultyProfile';

    $loggedInUserId = $_SESSION['userID'];
    session_write_close(); 

    $profilePictureRows = $userDAO->executeQuery($queryTitle, [$loggedInUserId]);

    $imageBinaryData = $profilePictureRows[0]['image_data'] ?? null;
    $storedMimeType  = $profilePictureRows[0]['mime_type'] ?? null;

    if (is_resource($imageBinaryData)) {
        $imageBinaryData = stream_get_contents($imageBinaryData);
    }

    header('Cache-Control: private, no-cache');

    if ($imageBinaryData === null || $imageBinaryData === '' || $storedMimeType === null) {
        $defaultPicturePath = __DIR__ . '/../View/resources/View Quilt.png';

        if (!is_file($defaultPicturePath)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($defaultPicturePath));
        readfile($defaultPicturePath);
        exit;
    }

    $imageEtag = '"' . md5($imageBinaryData) . '"';
    header('ETag: ' . $imageEtag);

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $imageEtag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $storedMimeType);
    header('Content-Length: ' . strlen($imageBinaryData));
    echo $imageBinaryData;
    exit;
}