<?php
/**
 * AH5 Office - Bootstrap (loads everything, no Composer)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/messenger.php';
require_once __DIR__ . '/print_auth.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/invoice_design.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/router.php';

// Autoload controllers from api/v1/controllers
spl_autoload_register(static function (string $class): void {
    $file = BASE_PATH . '/api/v1/controllers/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Make sure storage folders exist
foreach ([STORAGE_PATH, DOCS_PATH, INVOICE_PATH, TMP_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
