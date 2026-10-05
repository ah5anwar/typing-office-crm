<?php
/**
 * AH5 Office - Authentication (JWT access + rotating refresh token)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;
    private static ?array $perms = null;

    /** Verify the Bearer token; halts with 401 when missing/invalid. */
    /**
     * A print page has already proved itself with a signed link, so it stands
     * in for the user who asked for it. Read-only by nature: the page only
     * ever renders figures.
     */
    public static function asPrintReader(int $userId): void
    {
        $row = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$userId]);
        self::$user = $row ?? ['id' => $userId, 'role' => 'admin', 'name' => 'print'];
        self::$user['role'] = 'admin';
    }

    public static function require(): array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $token = self::bearerToken();
        if ($token === null) {
            Response::unauthorized('Authorization token missing');
        }

        $payload = JWT::decode($token);
        if ($payload === null || empty($payload['sub'])) {
            Response::unauthorized('Invalid or expired token');
        }

        $user = DB::one(
            'SELECT id, name, email, phone, role, avatar, is_active, token_version
             FROM users WHERE id = ?',
            [(int) $payload['sub']]
        );
        if ($user === null || (int) $user['is_active'] !== 1) {
            Response::unauthorized('Account not active');
        }

        // a password change or a forced sign-out bumps the version, so every
        // access token issued before that stops working immediately
        if ((int) ($payload['tv'] ?? -1) !== (int) $user['token_version']) {
            Response::unauthorized('This session was ended. Please sign in again.');
        }

        self::$user = $user;
        return $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function userId(): ?int
    {
        return self::$user !== null ? (int) self::$user['id'] : null;
    }


    /** Permissions of the signed-in user (cached for this request). */
    public static function permissions(): array
    {
        if (self::$perms === null) {
            self::$perms = self::$user === null ? [] : Perm::forUser(self::$user);
        }
        return self::$perms;
    }

    public static function can(string $permission): bool
    {
        if (self::$user === null) {
            return false;
        }
        if ((self::$user['role'] ?? '') === 'admin') {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    /**
     * Verify the token AND the permission. Halts with 403 when the user
     * is signed in but not allowed to do this.
     */
    public static function allow(string $permission): array
    {
        $user = self::require();
        if (!self::can($permission)) {
            Response::forbidden('You do not have permission to ' . lcfirst(Perm::label($permission)));
        }
        return $user;
    }

    /** True when this user may see cost prices and profit. */
    public static function seesCosts(): bool
    {
        return self::can('costs.view');
    }


    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') {
            Response::forbidden('Admin access required');
        }
        return $u;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
            $header  = $headers['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(\S+)/i', (string) $header, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Issue an access token + a fresh refresh token row. */
    public static function issueTokens(array $user, string $deviceName = '', string $platform = 'web'): array
    {
        $version = $user['token_version']
            ?? DB::value('SELECT token_version FROM users WHERE id = ?', [(int) $user['id']]);

        $access = JWT::encode([
            'sub'  => (int) $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'tv'   => (int) $version,
        ], ACCESS_TOKEN_TTL);

        $refresh = bin2hex(random_bytes(32));
        DB::insert('api_tokens', [
            'token_hash'  => hash('sha256', $refresh),
            'user_id'     => (int) $user['id'],
            'device_name' => $deviceName !== '' ? mb_substr($deviceName, 0, 120) : null,
            'platform'    => in_array($platform, ['web', 'android', 'ios', 'other'], true) ? $platform : 'other',
            'ip'          => Helper::clientIp(),
            'expires_at'  => date('Y-m-d H:i:s', time() + REFRESH_TOKEN_TTL),
        ]);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => ACCESS_TOKEN_TTL,
        ];
    }

    /** Rotate: validate the old refresh token, revoke it, issue a new pair. */
    public static function refresh(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $row  = DB::one(
            'SELECT * FROM api_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()',
            [$hash]
        );
        if ($row === null) {
            return null;
        }

        $user = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $row['user_id']]);
        if ($user === null) {
            return null;
        }

        DB::update('api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);

        return self::issueTokens($user, (string) ($row['device_name'] ?? ''), (string) $row['platform']);
    }

    public static function revoke(string $refreshToken): bool
    {
        return DB::update(
            'api_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'token_hash = ? AND revoked_at IS NULL',
            [hash('sha256', $refreshToken)]
        ) > 0;
    }

    /**
     * End every session for this user: revoke the refresh tokens AND move the
     * cut-off forward so access tokens already issued stop working at once.
     */
    public static function revokeAll(int $userId): int
    {
        $n = DB::update(
            'api_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
        DB::run('UPDATE users SET token_version = token_version + 1 WHERE id = ?', [$userId]);
        return $n;
    }

    /** Simple brute-force guard backed by activity_log. */
    public static function tooManyAttempts(string $email): bool
    {
        $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
        $count = (int) DB::value(
            "SELECT COUNT(*) FROM activity_log
             WHERE entity = 'auth' AND action = 'login_failed' AND note = ? AND created_at > ?",
            [mb_substr($email, 0, 255), $since]
        );
        return $count >= LOGIN_MAX_ATTEMPTS;
    }
}
