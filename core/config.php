<?php
/**
 * AH5 Office - Configuration
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * To keep credentials out of this file, create core/config.local.php
 * and define the same constants there. It is loaded first and wins.
 */

declare(strict_types=1);

// Bumped whenever the panel's files change, so the browser and the
// Settings page can both tell you which build is actually running.
define('APP_BUILD', '2026.09.16');

define('BASE_PATH', dirname(__DIR__));

// Local override is loaded FIRST so its defines take priority.
if (is_file(BASE_PATH . '/core/config.local.php')) {
    require BASE_PATH . '/core/config.local.php';
}

// ---------- Database ----------
defined('DB_HOST')    or define('DB_HOST', 'localhost');
defined('DB_NAME')    or define('DB_NAME', 'ah5office');
defined('DB_USER')    or define('DB_USER', 'ah5user');
defined('DB_PASS')    or define('DB_PASS', 'CHANGE_THIS_PASSWORD');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// ---------- Security ----------
// Long random string. Changing it invalidates all access tokens.
defined('JWT_SECRET')        or define('JWT_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_64_CHARS_MIN');
defined('ACCESS_TOKEN_TTL')  or define('ACCESS_TOKEN_TTL', 3600);              // 1 hour
defined('REFRESH_TOKEN_TTL') or define('REFRESH_TOKEN_TTL', 60 * 60 * 24 * 90); // 90 days
defined('LOGIN_MAX_ATTEMPTS') or define('LOGIN_MAX_ATTEMPTS', 6);
defined('LOGIN_LOCK_MINUTES') or define('LOGIN_LOCK_MINUTES', 15);

// ---------- Paths ----------
defined('STORAGE_PATH') or define('STORAGE_PATH', BASE_PATH . '/storage');
define('DOCS_PATH', STORAGE_PATH . '/docs');
define('INVOICE_PATH', STORAGE_PATH . '/invoices');
define('TMP_PATH', STORAGE_PATH . '/tmp');

// ---------- App ----------
define('APP_NAME', 'AH5 Office');
define('APP_VERSION', '1.0.0');
defined('BASE_CURRENCY') or define('BASE_CURRENCY', 'BDT');
defined('APP_TIMEZONE')  or define('APP_TIMEZONE', 'Asia/Dhaka');
defined('APP_DEBUG')     or define('APP_DEBUG', false);

define('SUPPORTED_CURRENCIES', ['BDT', 'AED', 'USD']);
define('UPLOAD_MAX_BYTES', 10 * 1024 * 1024); // 10 MB
define('UPLOAD_ALLOWED_EXT', ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','zip']);

// ---------- CORS ----------
defined('ALLOWED_ORIGINS') or define('ALLOWED_ORIGINS', [
    'https://office.creativesit.com',
    'http://localhost',
    'http://localhost:5173',
]);

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
// UPDATE-TEST-MARKER-999
// UPDATE-TEST-MARKER-CURRENT
