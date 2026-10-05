<?php
/**
 * AH5 Office - staff accounts and their permission ticks
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class UserController
{
    public static function index(): void
    {
        Auth::allow('users.manage');

        $rows = DB::all(
            'SELECT id, name, email, phone, role, is_active, last_login_at, created_at
             FROM users ORDER BY role ASC, name ASC'
        );
        foreach ($rows as &$r) {
            $r['permissions'] = Perm::forUser($r);
            $r['device_count'] = (int) DB::value(
                'SELECT COUNT(*) FROM api_tokens
                 WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()',
                [(int) $r['id']]
            );
        }
        unset($r);

        Response::ok($rows);
    }

    /** The tick boxes to draw, grouped as they should appear. */
    public static function catalogue(): void
    {
        Auth::allow('users.manage');

        $groups = [];
        foreach (Perm::CATALOGUE as $group => $items) {
            $groups[] = [
                'group' => $group,
                'items' => array_map(
                    static fn ($key, $label) => ['key' => $key, 'label' => $label],
                    array_keys($items),
                    array_values($items)
                ),
            ];
        }

        Response::ok(['groups' => $groups, 'staff_defaults' => Perm::STAFF_DEFAULTS]);
    }

    public static function show(string $id): void
    {
        Auth::allow('users.manage');
        $user = self::find((int) $id);
        $user['permissions'] = Perm::forUser($user);
        unset($user['password_hash']);
        Response::ok($user);
    }

    public static function store(): void
    {
        Auth::allow('users.manage');

        $in = Validator::make()
            ->check('name', 'required|string|max:120', 'Name')
            ->check('email', 'required|email|max:160', 'Email')
            ->check('password', 'required|string|min:8|max:255', 'Password')
            ->check('phone', 'nullable|string|max:30')
            ->check('role', 'nullable|in:admin,staff')
            ->validate();

        $email = mb_strtolower((string) $in['email']);
        if (DB::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            Response::error('Someone already uses that email address', 422);
        }

        $userId = DB::insert('users', [
            'name'          => $in['name'],
            'email'         => $email,
            'phone'         => $in['phone'],
            'password_hash' => password_hash((string) $in['password'], PASSWORD_DEFAULT),
            'role'          => $in['role'] ?? 'staff',
            'is_active'     => 1,
        ]);

        $body  = Validator::input();
        $perms = is_array($body['permissions'] ?? null) ? $body['permissions'] : Perm::STAFF_DEFAULTS;
        Perm::set($userId, $perms, Auth::userId());

        Helper::logActivity('user', $userId, 'create', (string) $in['name']);

        $fresh = self::find($userId);
        $fresh['permissions'] = Perm::forUser($fresh);
        unset($fresh['password_hash']);
        Response::created($fresh, 'Staff account created');
    }

    public static function update(string $id): void
    {
        Auth::allow('users.manage');
        $user = self::find((int) $id);

        $v = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('email', 'nullable|email|max:160')
            ->check('phone', 'nullable|string|max:30')
            ->check('role', 'nullable|in:admin,staff')
            ->check('is_active', 'nullable|bool');
        $data = Helper::dropNulls($v->present($v->validate()), ['name', 'email', 'role', 'is_active']);

        // never let the last owner account be demoted or switched off
        if ((isset($data['role']) && $data['role'] !== 'admin')
            || (isset($data['is_active']) && (int) $data['is_active'] === 0)) {
            $otherAdmins = (int) DB::value(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
                [(int) $id]
            );
            if ($user['role'] === 'admin' && $otherAdmins === 0) {
                Response::error('This is the only owner account. Make someone else an owner first.', 422);
            }
        }

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower((string) $data['email']);
            $clash = DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], (int) $id]);
            if ($clash !== null) {
                Response::error('Someone already uses that email address', 422);
            }
        }

        if ($data) {
            DB::update('users', $data, 'id = ?', [(int) $id]);
        }

        $body = Validator::input();
        if (is_array($body['permissions'] ?? null)) {
            Perm::set((int) $id, $body['permissions'], Auth::userId());
        }

        Helper::logActivity('user', (int) $id, 'update');

        $fresh = self::find((int) $id);
        $fresh['permissions'] = Perm::forUser($fresh);
        unset($fresh['password_hash']);
        Response::ok($fresh, 'Staff account updated');
    }

    /** Owner resets a staff password without knowing the old one. */
    public static function resetPassword(string $id): void
    {
        Auth::allow('users.manage');
        self::find((int) $id);

        $in = Validator::make()
            ->check('new_password', 'required|string|min:8|max:255', 'New password')
            ->validate();

        DB::update('users',
            ['password_hash' => password_hash((string) $in['new_password'], PASSWORD_DEFAULT)],
            'id = ?', [(int) $id]);

        $signedOut = Auth::revokeAll((int) $id);
        Helper::logActivity('user', (int) $id, 'password_reset');

        Response::ok(['sessions_ended' => $signedOut],
            'Password changed. They will need to sign in again on every device.');
    }

    /** Sign a staff member out everywhere, without deleting the account. */
    public static function signOutEverywhere(string $id): void
    {
        Auth::allow('users.manage');
        self::find((int) $id);

        $n = Auth::revokeAll((int) $id);
        Helper::logActivity('user', (int) $id, 'sessions_revoked');
        Response::ok(['sessions_ended' => $n], 'Signed out on every device');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('users.manage');
        $user = self::find((int) $id);

        if ((int) $id === Auth::userId()) {
            Response::error('You cannot remove your own account', 422);
        }
        if ($user['role'] === 'admin') {
            $others = (int) DB::value(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [(int) $id]
            );
            if ($others === 0) {
                Response::error('This is the only owner account and cannot be removed', 422);
            }
        }

        // keep the history: switch the account off rather than deleting rows
        DB::update('users', ['is_active' => 0], 'id = ?', [(int) $id]);
        Auth::revokeAll((int) $id);
        Perm::set((int) $id, [], Auth::userId());

        Helper::logActivity('user', (int) $id, 'deactivate', (string) $user['name']);
        Response::ok(null, 'Account switched off. Their past entries stay in the records.');
    }

    private static function find(int $id): array
    {
        $row = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($row === null) {
            Response::notFound('User not found');
        }
        return $row;
    }
}
