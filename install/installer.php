<?php
/**
 * AH5 Office - installer engine
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Everything the wizard needs that is not screen output. Kept apart so the
 * wizard file stays readable, and so nothing here depends on core/config.php
 * (which does not exist yet on a fresh upload).
 */

declare(strict_types=1);

// This file only holds the engine. Opening it directly does nothing useful,
// so send the visitor to the wizard instead of serving a blank page.
if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    header('Location: index.php');
    exit;
}

final class Installer
{
    public const MIN_PHP = '8.0.0';

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function lockFile(): string
    {
        return self::root() . '/core/install.lock';
    }

    public static function configFile(): string
    {
        return self::root() . '/core/config.local.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    // ------------------------------------------------------------ step 1

    /** Each row: [label, ok, detail, fatal] */
    public static function requirements(): array
    {
        $rows = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $rows[] = ['PHP ' . self::MIN_PHP . ' or newer', $phpOk, 'You have ' . PHP_VERSION, true];

        foreach (['pdo_mysql' => true, 'mbstring' => false, 'curl' => false, 'json' => true, 'openssl' => false] as $ext => $fatal) {
            $has = extension_loaded($ext);
            $note = $has ? 'available' : ($fatal ? 'required' : 'recommended, the system still runs without it');
            $rows[] = ['PHP extension: ' . $ext, $has, $note, $fatal];
        }

        if (!extension_loaded('curl')) {
            $rows[] = ['allow_url_fopen (fallback for cURL)', (bool) ini_get('allow_url_fopen'),
                'needed to send WhatsApp and Telegram messages without cURL', false];
        }

        foreach (['core' => true, 'storage' => true, 'storage/docs' => false, 'storage/invoices' => false] as $dir => $fatal) {
            $path = self::root() . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $writable = is_dir($path) && is_writable($path);
            $rows[] = [$dir . '/ is writable', $writable,
                $writable ? 'ok' : 'set this folder to 755 in cPanel File Manager', $fatal];
        }

        $schema = self::root() . '/database.sql';
        $rows[] = ['database.sql is present', is_file($schema),
            is_file($schema) ? round(filesize($schema) / 1024) . ' KB' : 'upload it next to install/', true];

        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $rows[] = ['File upload limit', $upload >= 2 * 1024 * 1024,
            ini_get('upload_max_filesize') . ' - documents up to this size can be attached', false];

        return $rows;
    }

    public static function requirementsPass(array $rows): bool
    {
        foreach ($rows as [$label, $ok, $detail, $fatal]) {
            if ($fatal && !$ok) {
                return false;
            }
        }
        return true;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num  = (int) $value;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    // ------------------------------------------------------------ step 2

    /** Returns [PDO|null, errorMessage|null] */
    public static function connect(array $db): array
    {
        $dsn = 'mysql:host=' . $db['host'] . (empty($db['port']) ? '' : ';port=' . (int) $db['port'])
             . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return [$pdo, null];
        } catch (PDOException $e) {
            return [null, self::friendlyDbError($e)];
        }
    }

    private static function friendlyDbError(PDOException $e): string
    {
        $msg  = $e->getMessage();
        $code = (string) $e->getCode();

        if (str_contains($msg, 'Unknown database')) {
            return 'That database does not exist. Create it in cPanel > MySQL Databases first.';
        }
        if ($code === '1045' || str_contains($msg, 'Access denied')) {
            return 'The username or password is wrong, or the user is not added to that database with ALL PRIVILEGES.';
        }
        if (str_contains($msg, 'Connection refused') || str_contains($msg, "Can't connect")) {
            return 'Cannot reach the database server. On cPanel the host is almost always "localhost".';
        }
        return 'Database connection failed: ' . $msg;
    }

    /**
     * Split a .sql file into runnable statements.
     * Handles line comments, block comments and quoted semicolons.
     */
    public static function splitSql(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $inSingle   = false;
        $inDouble   = false;
        $inBacktick = false;
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch   = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // comments, only when not inside a quoted string
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if (($ch === '-' && $next === '-') || $ch === '#') {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    $buffer .= "\n";
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i   = $end === false ? $len : $end + 1;
                    continue;
                }
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                // an escaped quote stays inside the string
                $escaped = false;
                $back = $i - 1;
                while ($back >= 0 && $sql[$back] === '\\') { $escaped = !$escaped; $back--; }
                if (!$escaped) { $inSingle = !$inSingle; }
            } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
                $escaped = false;
                $back = $i - 1;
                while ($back >= 0 && $sql[$back] === '\\') { $escaped = !$escaped; $back--; }
                if (!$escaped) { $inDouble = !$inDouble; }
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }

    /**
     * Run database.sql. Returns [okCount, errors[]].
     * The schema uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE, so running
     * it again on an existing database is safe and simply changes nothing.
     */
    public static function importSchema(PDO $pdo): array
    {
        $file = self::root() . '/database.sql';
        if (!is_file($file)) {
            return [0, ['database.sql was not found next to the install folder']];
        }
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            return [0, ['database.sql could not be read or is empty']];
        }

        $statements = self::splitSql($sql);
        $done = 0;
        $errors = [];

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $done++;
            } catch (PDOException $e) {
                // a table that already exists is not a problem worth stopping for
                if (str_contains($e->getMessage(), 'already exists')) {
                    $done++;
                    continue;
                }
                $head = mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 70);
                $errors[] = $head . ' ... -> ' . $e->getMessage();
                if (count($errors) >= 5) {
                    break;
                }
            }
        }

        return [$done, $errors];
    }

    public static function tableCount(PDO $pdo, string $dbName): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $stmt->execute([$dbName]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------ step 3

    public static function configContents(array $db, string $secret, string $timezone, string $currency): string
    {
        $q = static fn (string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";

        return "<?php\n"
            . "/**\n"
            . " * AH5 Office - local configuration, written by the installer.\n"
            . " * This file overrides core/config.php. Keep it off version control.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "define('DB_HOST', " . $q($db['host']) . ");\n"
            . "define('DB_NAME', " . $q($db['name']) . ");\n"
            . "define('DB_USER', " . $q($db['user']) . ");\n"
            . "define('DB_PASS', " . $q($db['pass']) . ");\n\n"
            . "define('JWT_SECRET', " . $q($secret) . ");\n\n"
            . "define('APP_TIMEZONE', " . $q($timezone) . ");\n"
            . "define('BASE_CURRENCY', " . $q($currency) . ");\n"
            . "define('APP_DEBUG', false);\n\n"
            . "// Add every address the admin panel is opened from.\n"
            . "define('ALLOWED_ORIGINS', [\n"
            . "    " . $q(self::guessOrigin()) . ",\n"
            . "]);\n";
    }

    public static function writeConfig(string $contents): bool
    {
        $ok = @file_put_contents(self::configFile(), $contents);
        if ($ok !== false) {
            @chmod(self::configFile(), 0640);
        }
        return $ok !== false;
    }

    public static function guessOrigin(): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Path to the project root as seen from the browser, e.g. "" or "/office". */
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base   = preg_replace('#/install/.*$#', '', $script) ?? '';
        return rtrim($base, '/');
    }

    public static function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------ step 4

    public static function createAdmin(PDO $pdo, string $name, string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            return [false, 'An account with that email already exists in this database.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active)
             VALUES (?, ?, ?, "admin", 1)'
        );
        $stmt->execute([trim($name), $email, password_hash($password, PASSWORD_DEFAULT)]);
        return [true, null];
    }

    public static function adminCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    // ------------------------------------------------------------ step 5

    public static function saveSettings(PDO $pdo, array $values): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($values as $key => $value) {
            $stmt->execute([$key, (string) $value]);
        }
    }

    public static function finish(): bool
    {
        // The schema has done its job; leaving it sitting in the web root
        // means its only protection is .htaccess, which some shared hosts
        // restrict (AllowOverride). Removing it here means nothing
        // depends on that for a file that is no longer needed.
        @unlink(self::root() . '/database.sql');

        $note = "Installed on " . date('Y-m-d H:i:s') . "\n"
              . "Delete the install folder from the server.\n";
        return @file_put_contents(self::lockFile(), $note) !== false;
    }

    public static function removeSelf(): bool
    {
        $dir = __DIR__;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return @rmdir($dir);
    }

    public static function timezones(): array
    {
        return ['Asia/Dhaka', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Riyadh',
                'Asia/Kuala_Lumpur', 'Europe/London', 'America/New_York', 'UTC'];
    }
}
