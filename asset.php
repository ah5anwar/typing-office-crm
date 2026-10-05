<?php

/**
 * AH5 Office - serves a settings image (company logo, signature)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * The print page needs the logo without a login, so links are signed and
 * short lived. Only keys that hold an image can ever be served.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

// A picture used by the public home page. These are deliberately public:
// they are the images on your own front door.
if (isset($_GET['photo'])) {
    // A customer or supplier photo. Shown in lists inside the panel, so it
    // needs no signed link, but only a file some row actually points at.
    $name = basename((string) $_GET['photo']);
    $known = DB::value(
        'SELECT photo FROM customers WHERE photo = ?
         UNION SELECT photo FROM suppliers WHERE photo = ? LIMIT 1',
        ['docs/' . $name, 'docs/' . $name]
    );
    $path = DOCS_PATH . '/' . $name;

    if ($known === null || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    header('Content-Type: ' . Uploads::mimeFor($path));
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_GET['site'])) {
    $name = basename((string) $_GET['site']);
    $path = DOCS_PATH . '/' . $name;

    $known = DB::value(
        'SELECT image FROM site_sections WHERE image = ?
         UNION SELECT image FROM site_items WHERE image = ? LIMIT 1',
        ['docs/' . $name, 'docs/' . $name]
    );

    if ($known === null || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $mime = Uploads::mimeFor($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}


// Your own mark, shown on the login screen and the browser tab, so it has
// to load before anyone signs in.
const PUBLIC_KEYS = ['app_logo', 'app_favicon'];

// These sit on printed invoices, which are reached by a signed link.
const SIGNED_KEYS = ['company_logo', 'company_signature'];

const ASSET_KEYS = [...PUBLIC_KEYS, ...SIGNED_KEYS];

$key   = (string) ($_GET['key'] ?? '');
$token = (string) ($_GET['t'] ?? '');

if (!in_array($key, ASSET_KEYS, true)) {
    http_response_code(404);
    exit('Not found');
}

if (in_array($key, SIGNED_KEYS, true)) {
    $payload = $token !== '' ? JWT::decode($token) : null;
    if ($payload === null || ($payload['scope'] ?? '') !== 'asset' || ($payload['key'] ?? '') !== $key) {
        http_response_code(403);
        exit('This link has expired.');
    }
}

$stored = (string) DB::setting($key, '');
if ($stored === '') {
    http_response_code(404);
    exit('No image set');
}

// an external URL is simply handed back
if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
    header('Location: ' . $stored);
    exit;
}

$full = Uploads::resolve($stored);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('The image is missing from the server.');
}

header('Content-Type: ' . Uploads::mimeFor($full));
header('Content-Length: ' . filesize($full));
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');
readfile($full);
