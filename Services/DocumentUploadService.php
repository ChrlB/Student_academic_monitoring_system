<?php

class DocumentUploadService
{
    private const ALLOWED_TYPES = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', // some PHP setups report .docx files as zip
        ],
    ];

    public function __construct(private int $maxUploadSizeInBytes = 10 * 1024 * 1024) {}

    public function validate(array $uploadedFile): array
    {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->failure('Upload failed.');
        }
        if ($uploadedFile['size'] > $this->maxUploadSizeInBytes) {
            return $this->failure('File is too large.');
        }

        $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_TYPES[$fileExtension])) {
            return $this->failure('Only PDF, DOC or DOCX allowed.');
        }

        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedFile['tmp_name']);
        if (!in_array($detectedMimeType, self::ALLOWED_TYPES[$fileExtension], true)) {
            return $this->failure('File content does not match its extension.');
        }

        return [
            'success'      => true,
            'message'      => 'OK',
            'mimeType'     => $detectedMimeType,
            'extension'    => $fileExtension,
            'originalName' => basename($uploadedFile['name']),
        ];
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}