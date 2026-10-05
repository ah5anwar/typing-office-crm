<?php
/**
 * AH5 Office - per-user permissions
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Every user gets their own set of ticks. The owner account (role = admin)
 * always has everything and cannot be locked out of the system.
 *
 * COST_VISIBILITY is the important one: without `costs.view` a user never
 * sees what anything costs you, only what the customer is charged.
 */

declare(strict_types=1);

final class Perm
{
    /** Grouped for the settings screen. Label is what the tick box says. */
    public const CATALOGUE = [
        'Customers' => [
            'customers.view'   => 'See customers and their ledger',
            'customers.edit'   => 'Add and edit customers',
            'customers.delete' => 'Remove customers',
        ],
        'Services' => [
            'services.view' => 'See the service list',
            'services.edit' => 'Add and edit services',
        ],
        'Suppliers' => [
            'suppliers.view'   => 'See suppliers and their work',
            'suppliers.edit'   => 'Add and edit suppliers',
            'suppliers.delete' => 'Remove suppliers',
            'supplier_bills.view'  => 'See supplier bills and payments',
            'supplier_bills.entry' => 'Record supplier bills and payments',
            'supplier_bills.delete'=> 'Remove supplier bills and payments',
        ],
        'Work' => [
            'jobs.view'   => 'See jobs',
            'jobs.edit'   => 'Add and edit jobs, assign suppliers',
            'jobs.delete' => 'Remove jobs',
        ],
        'Billing' => [
            'quotations.view'   => 'See quotations',
            'quotations.edit'   => 'Write and edit quotations',
            'quotations.delete' => 'Remove quotations',
            'invoices.view'     => 'See invoices',
            'invoices.edit'     => 'Create and edit invoices',
            'invoices.cancel'   => 'Cancel invoices',
            'invoices.delete'   => 'Remove invoices',
        ],
        'Money' => [
            'payments.view'   => 'See the due list and payments received',
            'payments.entry'  => 'Record payments from customers',
            'payments.delete' => 'Remove a recorded payment',
            'expenses.view'   => 'See office expenses',
            'expenses.entry'  => 'Record office expenses',
            'expenses.delete' => 'Remove an expense entry',
        ],
        'Documents' => [
            'documents.view'   => 'See customer documents and expiry dates',
            'documents.edit'   => 'Add, edit and renew documents',
            'documents.delete' => 'Remove documents',
            'documents.remind' => 'Send expiry reminders',
        ],
        'Messages' => [
            'messages.send' => 'Send messages and reminders',
            'messages.view' => 'See the message log and queue',
        ],
        'Reports and private data' => [
            'reports.view' => 'See income and expense reports',
            'costs.view'   => 'See your cost prices and profit',
            'activity.view'=> 'See who changed what',
        ],
        'Administration' => [
            'settings.manage' => 'Change settings and API keys',
            'users.manage'    => 'Add staff and set their permissions',
        ],
    ];

    /** A sensible starting set when you add a new staff member. */
    public const STAFF_DEFAULTS = [
        'customers.view', 'customers.edit',
        'services.view',
        'jobs.view', 'jobs.edit',
        'invoices.view', 'invoices.edit',
        'quotations.view',
        'payments.view', 'payments.entry',
        'documents.view', 'documents.edit', 'documents.remind',
        'messages.send',
    ];

    /** Flat list of every valid key. */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $group) {
            foreach ($group as $key => $label) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    public static function label(string $key): string
    {
        foreach (self::CATALOGUE as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }
        return $key;
    }

    /** Permissions currently granted to a user. Admin gets everything. */
    public static function forUser(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return self::all();
        }
        $rows = DB::all('SELECT perm_key FROM user_permissions WHERE user_id = ?', [(int) $user['id']]);
        return array_values(array_filter(array_column($rows, 'perm_key'), [self::class, 'isValid']));
    }

    /** Replace a user's ticks in one go. */
    public static function set(int $userId, array $keys, ?int $grantedBy = null): array
    {
        $keys = array_values(array_unique(array_filter($keys, [self::class, 'isValid'])));

        DB::begin();
        try {
            DB::run('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);
            foreach ($keys as $k) {
                DB::insert('user_permissions', [
                    'user_id'    => $userId,
                    'perm_key'   => $k,
                    'granted_by' => $grantedBy,
                ]);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }
        return $keys;
    }
}
