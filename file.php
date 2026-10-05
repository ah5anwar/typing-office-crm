<?php
/**
 * AH5 Office - serves one attached file
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * ?view=1 opens it in the browser (PDF, images). Without it the browser
 * saves the file. Links are signed and short lived, so nothing under
 * storage/ is ever reachable on its own.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
PrintAuth::checkFile($id);

$row = DB::one('SELECT * FROM attachments WHERE id = ?', [$id]);
if ($row === null) {
    http_response_code(404);
    exit('File not found.');
}

$full = Uploads::resolve((string) $row['file_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('The file is missing from the server.');
}

$mime   = (string) ($row['mime_type'] ?: Uploads::mimeFor($full));
$inline = ($_GET['view'] ?? '') === '1'
    && ($mime === 'application/pdf' || str_starts_with($mime, 'image/'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . rawurlencode((string) $row['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; object-src \'self\'');
header('Cache-Control: private, max-age=0, no-store');

readfile($full);
