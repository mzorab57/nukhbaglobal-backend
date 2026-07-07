<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Upload;
use RuntimeException;
use Throwable;

final class UploadController
{
    public function image(): never
    {
        try {
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Image file is required.');
            }

            $directory = trim((string) ($_POST['directory'] ?? 'media'));
            $result = Upload::storeImage($file, $directory);

            Response::jsonResponse(true, 'Image uploaded successfully.', $result, 201);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'required') || str_contains($normalized, 'invalid') || str_contains($normalized, 'allowed')) {
            return 422;
        }

        return 500;
    }
}
