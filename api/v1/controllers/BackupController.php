<?php
declare(strict_types=1);

/**
 * Backups, updating from a zip, and rolling a bad update back. Every
 * destructive action here (update, rollback, delete) asks for the
 * person's password again, the same way the installer once did - this
 * is the one part of the app that can rewrite its own code, so it is
 * held to a higher bar than an ordinary settings change.
 */
final class BackupController
{
    private static function confirmPassword(): void
    {
        $password = (string) (Validator::input()['password'] ?? $_POST['password'] ?? '');
        $user = DB::one('SELECT password_hash FROM users WHERE id = ?', [Auth::userId()]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            Response::error('That password is not right.', 422);
        }
    }

    public static function list(): void
    {
        Auth::allow('settings.manage');
        Response::ok(Backup::list());
    }

    public static function create(): void
    {
        Auth::allow('settings.manage');
        try {
            $path = Backup::createFull('manual');
        } catch (\Throwable $e) {
            Response::error('Could not make a backup: ' . $e->getMessage(), 500);
        }
        Helper::logActivity('settings', null, 'backup_created', basename($path));
        Response::created(['name' => basename($path)], 'Backup made');
    }

    /** Only ever a filename this app itself listed - never a path a person typed. */
    private static function resolveBackupPath(string $name): string
    {
        $name = basename($name);
        $path = Backup::backupDir() . '/' . $name;
        if (!str_starts_with($name, 'ah5-') || !str_ends_with($name, '.zip') || !is_file($path)) {
            Response::error('Backup not found', 404);
        }
        return $path;
    }

    public static function download(string $name): void
    {
        Auth::allow('settings.manage');
        $path = self::resolveBackupPath($name);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function delete(string $name): void
    {
        Auth::allow('settings.manage');
        $path = self::resolveBackupPath($name);
        @unlink($path);
        Response::ok(null, 'Backup removed');
    }

    /* --------------------------------------------------------- updating */

    public static function update(): void
    {
        Auth::allow('settings.manage');
        self::confirmPassword();

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Choose the update zip file.', 422);
        }
        $tmp = (string) $_FILES['file']['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            Response::error('Upload failed.', 422);
        }
        if ((int) $_FILES['file']['size'] > 120 * 1024 * 1024) {
            Response::error('That file is larger than an AH5 Office update should be.', 422);
        }
        if (!class_exists('ZipArchive')) {
            Response::error('The PHP zip extension is not available on this server.', 500);
        }
        if (!Backup::isValidBuild($tmp)) {
            Response::error('That does not look like an AH5 Office update file.', 422);
        }

        $staging = sys_get_temp_dir() . '/ah5-update-' . bin2hex(random_bytes(6));

        try {
            // a safety net before a single file of the live install changes
            $backupPath = Backup::createFull('pre-update');

            $staged = Backup::safeExtract($tmp, $staging);
            if (!$staged) {
                throw new RuntimeException('The zip had nothing recognisable to apply.');
            }
            $changed = Backup::applyStaged($staged);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $schema = SettingController::runSchemaUpgrade();

            Helper::logActivity('settings', null, 'system_updated',
                count($changed) . ' file(s), ' . $schema['added'] . ' schema change(s)');

            Response::ok([
                'backup'         => basename($backupPath),
                'files_changed'  => count($changed),
                'schema'         => $schema['steps'],
            ], 'Updated — ' . count($changed) . ' file(s) changed');
        } catch (\Throwable $e) {
            Response::error('Update stopped before anything broke: ' . $e->getMessage(), 500);
        } finally {
            Backup::clearStaging($staging);
        }
    }

    public static function rollback(): void
    {
        Auth::allow('settings.manage');
        self::confirmPassword();

        $name = (string) (Validator::input()['name'] ?? '');
        $path = self::resolveBackupPath($name);
        $staging = sys_get_temp_dir() . '/ah5-rollback-' . bin2hex(random_bytes(6));

        try {
            // rolling back is itself a code change, so it gets the same
            // safety net - if the rollback turns out to be the mistake,
            // there is still something to come back from
            Backup::createFull('pre-rollback');

            $staged = Backup::safeExtract($path, $staging);
            if (!$staged) {
                throw new RuntimeException('That backup had no code in it to restore.');
            }
            $changed = Backup::applyStaged($staged);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            Helper::logActivity('settings', null, 'system_rolled_back',
                'from ' . basename($path) . ', ' . count($changed) . ' file(s)');

            Response::ok(['files_changed' => count($changed)],
                'Rolled back — ' . count($changed) . ' file(s) restored');
        } catch (\Throwable $e) {
            Response::error('Rollback stopped before anything broke: ' . $e->getMessage(), 500);
        } finally {
            Backup::clearStaging($staging);
        }
    }

    public static function clearOpcache(): void
    {
        Auth::allow('settings.manage');
        $did = function_exists('opcache_reset') && @opcache_reset();
        Response::ok(['cleared' => $did], $did
            ? 'Cleared — the server is now running the files exactly as they are on disk'
            : 'Nothing to clear (opcache is not active on this server), but nothing was wrong either');
    }
}
