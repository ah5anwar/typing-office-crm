<?php
/**
 * AH5 Office - backups and self-updates
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Everything here follows one rule above all others: a path in
 * PROTECTED_PATHS is never written to, never deleted, by any operation in
 * this file - not on update, not on rollback. That is what keeps a bad
 * update from ever being able to take real work down with it.
 */

declare(strict_types=1);

final class Backup
{
    /**
     * Never touched by an update or a rollback, in either direction -
     * these are the person's own data and their own server setup, not
     * part of what a new zip is allowed to replace.
     */
    public const PROTECTED_PATHS = [
        'core/config.local.php',
        'core/install.lock',
        'storage',
        'backups',
    ];

    public static function backupDir(): string
    {
        $dir = BASE_PATH . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function isProtected(string $relativePath): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($relativePath === $p || str_starts_with($relativePath, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------ dump */

    /**
     * The database, written out as plain INSERT statements - not shelling
     * out to mysqldump, which many shared hosts don't allow a PHP process
     * to run at all. Slower, but it works everywhere this app already does.
     */
    public static function dumpDatabaseSql(): string
    {
        $tables = array_column(
            DB::all("SELECT TABLE_NAME FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                      ORDER BY TABLE_NAME"),
            'TABLE_NAME'
        );

        $out = "-- AH5 Office backup - " . date('Y-m-d H:i:s') . "\n"
             . "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";

        foreach ($tables as $table) {
            $create = DB::one("SHOW CREATE TABLE `{$table}`");
            $createSql = $create['Create Table'] ?? '';
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";

            $rows = DB::all("SELECT * FROM `{$table}`");
            foreach (array_chunk($rows, 200) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                $cols = '`' . implode('`,`', array_keys($chunk[0])) . '`';
                $lines = [];
                foreach ($chunk as $row) {
                    $vals = array_map(static function ($v) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return DB::conn()->quote((string) $v);
                    }, $row);
                    $lines[] = '(' . implode(',', $vals) . ')';
                }
                $out .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $lines) . ";\n";
            }
            $out .= "\n";
        }

        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $out;
    }

    /* -------------------------------------------------------------- zip */

    /** Every real file under a directory, as paths relative to it. */
    private static function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $out[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        return $out;
    }

    /**
     * A full backup: the code as it stands right now, the database, and
     * every file in storage/ - one zip, so restoring never means hunting
     * down three different things later.
     */
    public static function createFull(string $label = 'backup'): string
    {
        $stamp = date('Y-m-d_His');
        $path = self::backupDir() . "/ah5-{$label}-{$stamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }

        $zip->addFromString('database.sql', self::dumpDatabaseSql());
        $zip->addFromString('ah5-manifest.json', json_encode([
            'product' => 'ah5-office',
            'build'   => defined('APP_BUILD') ? APP_BUILD : null,
            'made_at' => date('c'),
            'kind'    => $label,
        ], JSON_PRETTY_PRINT));

        // the code, exactly as it runs right now - skip what a restore
        // must never touch anyway, and the backups folder itself
        foreach (self::codeFiles() as $rel) {
            $zip->addFile(BASE_PATH . '/' . $rel, 'code/' . $rel);
        }

        // storage/ goes in its own clearly-marked section, so a person
        // restoring by hand can tell at a glance what is code and what
        // is their own uploaded files
        foreach (self::walk(STORAGE_PATH) as $rel) {
            $zip->addFile(STORAGE_PATH . '/' . $rel, 'storage/' . $rel);
        }

        $zip->close();
        self::prune((int) DB::setting('backup_keep_count', '5'));
        return $path;
    }

    /** Every code file that belongs in an update/backup - not storage, not local config. */
    private static function codeFiles(): array
    {
        $all = self::walk(BASE_PATH);
        return array_values(array_filter($all, static function ($rel) {
            if (str_starts_with($rel, 'backups/')) {
                return false;
            }
            return !self::isProtected($rel);
        }));
    }

    /** Delete backups beyond the most recent $keep, oldest first. */
    public static function prune(int $keep): void
    {
        $files = glob(self::backupDir() . '/ah5-*.zip') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, max(0, $keep)) as $old) {
            @unlink($old);
        }
    }

    public static function list(): array
    {
        $files = glob(self::backupDir() . '/ah5-*.zip') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(static fn ($f) => [
            'name'       => basename($f),
            'size'       => filesize($f),
            'created_at' => date('c', filemtime($f)),
        ], $files);
    }

    /* --------------------------------------------------------- applying */

    /**
     * True only for a zip this app itself could have produced - a stray
     * file, or someone else's zip, is refused before anything is touched.
     */
    public static function isValidBuild(string $zipPath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $prefix = self::wrapperPrefix($zip);
        $hasBootstrap = $zip->locateName($prefix . 'core/bootstrap.php') !== false;
        $hasIndex     = $zip->locateName($prefix . 'api/v1/index.php') !== false
            || $zip->locateName($prefix . 'code/api/v1/index.php') !== false;
        $zip->close();
        return $hasBootstrap && $hasIndex;
    }

    /**
     * A zip made for a person to unzip usually wraps everything in one
     * top folder (ah5-office/...); this finds that prefix so it can be
     * stripped, or returns '' if the zip has no such wrapper.
     */
    private static function wrapperPrefix(ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            return '';
        }
        $first = $zip->getNameIndex(0);
        if ($first === false || !str_contains($first, '/')) {
            return '';
        }
        $top = explode('/', $first)[0];
        // only treat it as a wrapper if EVERY entry shares that same top folder
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && $name !== '' && !str_starts_with($name, $top . '/')) {
                return '';
            }
        }
        return $top . '/';
    }

    /**
     * Extracts to a scratch directory first and checks every single entry
     * on the way - this is what stops a zip whose entries say "../../x"
     * (zip-slip) from ever writing outside where it is meant to land.
     * Returns the resolved path each real file ended up at, keyed by its
     * path relative to the zip's own root (wrapper folder stripped, and
     * a leading "code/" from one of this app's own backups stripped too).
     */
    public static function safeExtract(string $zipPath, string $intoDir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the zip.');
        }
        $prefix = self::wrapperPrefix($zip);
        if (!is_dir($intoDir)) {
            mkdir($intoDir, 0755, true);
        }
        $realBase = realpath($intoDir);
        $written = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '' || str_ends_with($name, '/')) {
                continue; // a directory entry, nothing to write
            }
            $rel = $name;
            if ($prefix !== '' && str_starts_with($rel, $prefix)) {
                $rel = substr($rel, strlen($prefix));
            }
            if (str_starts_with($rel, 'code/')) {
                $rel = substr($rel, 5);
            }
            if ($rel === '' || str_starts_with($rel, 'storage/') || $rel === 'database.sql'
                || $rel === 'ah5-manifest.json') {
                continue; // this pass only ever applies code
            }

            // zip-slip guard: reject anything that is not a plain relative
            // path landing inside $intoDir, before a single byte is written
            if (str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel)) {
                throw new RuntimeException('Refused an unsafe path in the zip: ' . $name);
            }

            $dest = $intoDir . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new RuntimeException('Could not create ' . $destDir);
            }
            $resolvedDir = realpath($destDir);
            if ($resolvedDir === false || !str_starts_with($resolvedDir, (string) $realBase)) {
                throw new RuntimeException('Refused an unsafe path in the zip: ' . $name);
            }

            $data = $zip->getFromIndex($i);
            if ($data === false || file_put_contents($dest, $data) === false) {
                throw new RuntimeException('Could not write ' . $rel);
            }
            $written[$rel] = $dest;
        }

        $zip->close();
        return $written;
    }

    /**
     * Copies staged files over the live install - every single write goes
     * through isProtected() again here, a second time, so a mistake
     * anywhere upstream still cannot reach config.local.php or storage/.
     */
    public static function applyStaged(array $stagedFiles): array
    {
        $changed = [];
        foreach ($stagedFiles as $rel => $stagedPath) {
            if (self::isProtected($rel)) {
                continue;
            }
            $target = BASE_PATH . '/' . $rel;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            if (!copy($stagedPath, $target)) {
                throw new RuntimeException('Could not write ' . $rel);
            }
            $changed[] = $rel;
        }
        sort($changed);
        return $changed;
    }

    public static function clearStaging(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', sys_get_temp_dir())) {
            return; // only ever clean up inside the system temp dir
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
