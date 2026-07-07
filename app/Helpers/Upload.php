<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

final class Upload
{
    /**
     * @var array<string, string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const MAX_FILE_SIZE = 8_388_608; // 8 MB

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function storeImage(array $file, string $directory = 'media'): array
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::resolveUploadErrorMessage($errorCode));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded file is invalid.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Image size must not exceed 8 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;

        if ($extension === null) {
            throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
        }

        $safeDirectory = trim(preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($directory)) ?? 'media', '-');
        $safeDirectory = $safeDirectory !== '' ? $safeDirectory : 'media';
        $datePath = date('Y/m');
        $relativeDirectory = sprintf('uploads/%s/%s', $safeDirectory, $datePath);
        $absoluteDirectory = BASE_PATH . '/public/' . $relativeDirectory;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('Failed to prepare upload directory.');
        }

        $fileName = sprintf('%s.%s', bin2hex(random_bytes(16)), $extension);
        $absolutePath = $absoluteDirectory . '/' . $fileName;

        if (!move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Failed to move uploaded image.');
        }

        $relativePath = '/' . $relativeDirectory . '/' . $fileName;

        return [
            'path' => $relativePath,
            'url' => self::buildPublicUrl($relativePath),
            'mimeType' => $mimeType,
            'size' => $fileSize,
            'fileName' => $fileName,
        ];
    }

    private static function buildPublicUrl(string $relativePath): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/');

        if ($host !== '') {
            $base = sprintf('%s://%s', $scheme, $host);
            if ($basePath !== '' && $basePath !== '.') {
                $base .= $basePath === '/' ? '' : $basePath;
            }

            return rtrim($base, '/') . $relativePath;
        }

        $config = require BASE_PATH . '/config/config.php';
        $appUrl = rtrim((string) (($config['app']['url'] ?? '')), '/');
        if ($appUrl !== '') {
            return $appUrl . $relativePath;
        }

        return $relativePath;
    }

    private static function resolveUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image is too large.',
            UPLOAD_ERR_PARTIAL => 'Image upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'Image file is required.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded image to disk.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the image upload.',
            default => 'Image upload failed.',
        };
    }
}
