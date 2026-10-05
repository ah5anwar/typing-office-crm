<?php
/**
 * AH5 Office - file uploads for customer documents
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Files live in storage/docs, outside the document root where possible,
 * and are served only through download.php with a short-lived token.
 */

declare(strict_types=1);

final class Uploads
{
    /** Accept one uploaded file and return [relativePath, originalName]. */
    public static function receive(string $field, string $prefix): array
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            Response::error('No file was sent', 422);
        }
        $f = $_FILES[$field];

        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error(self::errorText((int) $f['error']), 422);
        }
        if ((int) $f['size'] > UPLOAD_MAX_BYTES) {
            Response::error('File is larger than ' . round(UPLOAD_MAX_BYTES / 1048576) . ' MB', 422);
        }
        if (!is_uploaded_file((string) $f['tmp_name'])) {
            Response::error('Upload failed', 422);
        }

        $original = Helper::safeFileName((string) $f['name']);
        $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
            Response::error('That file type is not allowed. Use: ' . implode(', ', UPLOAD_ALLOWED_EXT), 422);
        }

        // reject anything whose real content is not what the extension claims
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $info = @getimagesize((string) $f['tmp_name']);
            if ($info === false) {
                Response::error('That image file is not readable', 422);
            }
        }

        if (!is_dir(DOCS_PATH)) {
            @mkdir(DOCS_PATH, 0755, true);
        }

        $stored = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = DOCS_PATH . '/' . $stored;

        if (!move_uploaded_file((string) $f['tmp_name'], $target)) {
            Response::error('Could not save the file on the server', 500);
        }
        @chmod($target, 0644);

        return ['docs/' . $stored, $original];
    }

    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $full = self::resolve($relativePath);
        if ($full !== null && is_file($full)) {
            @unlink($full);
        }
    }

    /** Absolute path, or null when the path escapes storage. */
    public static function resolve(string $relativePath): ?string
    {
        $clean = str_replace('\\', '/', $relativePath);
        if (str_contains($clean, '..')) {
            return null;
        }
        $full = STORAGE_PATH . '/' . ltrim($clean, '/');
        $real = realpath($full);
        $root = realpath(STORAGE_PATH);
        if ($real === false || $root === false || !str_starts_with($real, $root)) {
            return null;
        }
        return $real;
    }

    public static function mimeFor(string $path): string
    {
        $map = [
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $map[$ext] ?? 'application/octet-stream';
    }

    private static function errorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large for the server limit',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was chosen',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server cannot write the file',
            default              => 'Upload failed',
        };
    }
}
