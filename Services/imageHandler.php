<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

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

// Send the person back to the account page with a message (shown once by popup_message).
function profileUploadDone(string $key, string $text): void {
    $_SESSION[$key] = $text;                       // $key is 'message' or 'error'
    header('Location: ' . BASE_PATH . '/account');
    exit;
}

function uploadProfile(){
  global $userDAO; 

  // Not logged in: nothing to save.
  if (empty($_SESSION['userID'])) {
      header('Location: ' . BASE_PATH . '/login');
      exit;
  }
  $loggedInUserId = $_SESSION['userID'];

  // A file bigger than the server's post_max_size makes PHP drop the whole request ($_POST and $_FILES stay empty).
  if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      profileUploadDone('error', 'That picture is too large. Please choose a smaller one.');
  }

  // Same CSRF check as the login and account forms: other sites cannot change someone's picture.
  if (!csrfValid($_POST['csrf_token'] ?? null)) {
      profileUploadDone('error', 'Your session expired. Please try again.');
  }

  if (!isset($_FILES['profile_picture'])) {
      profileUploadDone('error', 'Choose a picture first.');
  }

  $imageUploadService = new ImageUploadService();
  $processedImage = $imageUploadService->processUploadedImage($_FILES['profile_picture']);

  if (!$processedImage['success']) {
      profileUploadDone('error', $processedImage['message']);
  }

  $imageBinaryData = $processedImage['imageBinaryData'];
  $savedMimeType = $processedImage['mimeType'];

  $upload_for = (($_SESSION['role'] ?? '') === "student")
        ? "uploadStudentProfile"
        : "uploadFacultyProfile";

  try {
      $rows_affected = $userDAO->executeUpdate($upload_for, [ $imageBinaryData, $savedMimeType, $loggedInUserId]);
  } catch (Throwable $e) {
      error_log('Profile picture save failed: ' . $e->getMessage());
      profileUploadDone('error', 'Could not save the picture. Please try again.');
  }

  if ($rows_affected > 0) {
      profileUploadDone('message', 'Profile picture saved!');
  }
  profileUploadDone('error', 'The picture was not saved. Please try again.');
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
        $defaultPicturePath = __DIR__ . '/../View/resources/User.png';

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