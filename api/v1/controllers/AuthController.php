<?php
/**
 * AH5 Office - Auth endpoints
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class AuthController
{
    public static function login(): void
    {
        $in = Validator::make()
            ->check('email', 'required|string|max:160', 'Email')
            ->check('password', 'required|string|max:255', 'Password')
            ->check('device_name', 'nullable|string|max:120')
            ->check('platform', 'nullable|in:web,android,ios,other')
            ->validate();

        $email = mb_strtolower((string) $in['email']);

        if (Auth::tooManyAttempts($email)) {
            Response::error('Too many failed attempts. Try again in ' . LOGIN_LOCK_MINUTES . ' minutes.', 429);
        }

        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user === null || !password_verify((string) $in['password'], (string) $user['password_hash'])) {
            DB::insert('activity_log', [
                'entity' => 'auth', 'action' => 'login_failed',
                'note'   => $email, 'ip' => Helper::clientIp(),
            ]);
            Response::unauthorized('Email or password is incorrect');
        }

        if ((int) $user['is_active'] !== 1) {
            Response::forbidden('This account is disabled');
        }

        // Upgrade legacy hashes if the algorithm changed
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash((string) $in['password'], PASSWORD_DEFAULT)],
                'id = ?', [(int) $user['id']]);
        }

        $tokens = Auth::issueTokens(
            $user,
            (string) ($in['device_name'] ?? ''),
            (string) ($in['platform'] ?? 'web')
        );

        DB::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $user['id']]);
        DB::insert('activity_log', [
            'user_id' => (int) $user['id'], 'entity' => 'auth',
            'action'  => 'login', 'ip' => Helper::clientIp(),
        ]);

        Response::ok([
            'user'   => array_merge(self::publicUser($user), [
                'permissions' => Perm::forUser($user),
            ]),
            'tokens' => $tokens,
        ], 'Login successful');
    }

    public static function refresh(): void
    {
        $in = Validator::make()->check('refresh_token', 'required|string', 'Refresh token')->validate();

        $tokens = Auth::refresh((string) $in['refresh_token']);
        if ($tokens === null) {
            Response::unauthorized('Refresh token is invalid or expired');
        }
        Response::ok(['tokens' => $tokens], 'Token refreshed');
    }

    public static function logout(): void
    {
        $in = Validator::make()->check('refresh_token', 'nullable|string')->validate();

        if (!empty($in['refresh_token'])) {
            Auth::revoke((string) $in['refresh_token']);
        } else {
            $user = Auth::require();
            Auth::revokeAll((int) $user['id']);
        }
        Response::ok(null, 'Logged out');
    }

    public static function me(): void
    {
        $user = Auth::require();
        Response::ok(array_merge(self::publicUser($user), [
            'permissions' => Auth::permissions(),
        ]));
    }

    public static function updateProfile(): void
    {
        $user = Auth::require();
        $v    = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('phone', 'nullable|string|max:30')
            ->check('avatar', 'nullable|string|max:255');
        $data = $v->present($v->validate());

        if ($data) {
            DB::update('users', $data, 'id = ?', [(int) $user['id']]);
        }
        $fresh = DB::one('SELECT * FROM users WHERE id = ?', [(int) $user['id']]);
        Response::ok(self::publicUser($fresh ?? $user), 'Profile updated');
    }

    public static function changePassword(): void
    {
        $user = Auth::require();
        $in   = Validator::make()
            ->check('current_password', 'required|string', 'Current password')
            ->check('new_password', 'required|string|min:8|max:255', 'New password')
            ->validate();

        $row = DB::one('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        if ($row === null || !password_verify((string) $in['current_password'], (string) $row['password_hash'])) {
            Response::error('Current password is incorrect', 422);
        }

        DB::update('users',
            ['password_hash' => password_hash((string) $in['new_password'], PASSWORD_DEFAULT)],
            'id = ?', [(int) $user['id']]
        );
        Auth::revokeAll((int) $user['id']);
        Helper::logActivity('auth', (int) $user['id'], 'password_changed');

        Response::ok(null, 'Password changed. Please log in again on all devices.');
    }

    public static function devices(): void
    {
        $user = Auth::require();
        $rows = DB::all(
            'SELECT id, device_name, platform, ip, last_used_at, created_at, expires_at
             FROM api_tokens
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC',
            [(int) $user['id']]
        );
        Response::ok($rows);
    }

    public static function revokeDevice(string $id): void
    {
        $user = Auth::require();
        $n = DB::update('api_tokens', ['revoked_at' => date('Y-m-d H:i:s')],
            'id = ? AND user_id = ? AND revoked_at IS NULL', [(int) $id, (int) $user['id']]);

        if ($n === 0) {
            Response::notFound('Device session not found');
        }
        Response::ok(null, 'Device logged out');
    }

    private static function publicUser(array $u): array
    {
        return [
            'id'            => (int) $u['id'],
            'name'          => $u['name'],
            'email'         => $u['email'],
            'phone'         => $u['phone'] ?? null,
            'role'          => $u['role'],
            'avatar'        => $u['avatar'] ?? null,
            'last_login_at' => $u['last_login_at'] ?? null,
        ];
    }
}
