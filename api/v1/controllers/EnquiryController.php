<?php
/**
 * AH5 Office - messages from the website
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Someone filled in the form on the home page. This is where those land,
 * and where one of them becomes a customer.
 */

declare(strict_types=1);

final class EnquiryController
{
    public static function index(): void
    {
        Auth::allow('customers.view');

        $where = [];
        $params = [];

        if (!empty($_GET['status'])) {
            $status = (string) $_GET['status'];
            if (in_array($status, ['new', 'read', 'replied', 'closed', 'spam'], true)) {
                $where[]  = 'e.status = ?';
                $params[] = $status;
            }
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(e.name LIKE ? OR e.phone LIKE ? OR e.email LIKE ? OR e.message LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }

        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        [$page, $perPage, $offset] = Helper::pagination();

        $total = (int) DB::value("SELECT COUNT(*) FROM enquiries e{$clause}", $params);
        $rows  = DB::all(
            "SELECT e.*, c.name AS customer_name
             FROM enquiries e LEFT JOIN customers c ON c.id = e.customer_id
             {$clause}
             ORDER BY e.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::ok($rows, null, [
            'meta' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
                'unread' => (int) DB::value("SELECT COUNT(*) FROM enquiries WHERE status = 'new'"),
            ],
        ]);
    }

    public static function show(string $id): void
    {
        Auth::allow('customers.view');
        $row = self::find((int) $id);

        // opening it is reading it
        if ($row['status'] === 'new') {
            DB::update('enquiries', ['status' => 'read'], 'id = ?', [(int) $id]);
            $row['status'] = 'read';
        }

        Response::ok($row);
    }

    public static function update(string $id): void
    {
        Auth::allow('customers.edit');
        self::find((int) $id);

        $in = Validator::make()
            ->check('status', 'required|in:new,read,replied,closed,spam', 'Status')
            ->validate();

        DB::update('enquiries', ['status' => $in['status']], 'id = ?', [(int) $id]);
        Response::ok(self::find((int) $id), 'Saved');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('customers.delete');
        self::find((int) $id);
        DB::run('DELETE FROM enquiries WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Message removed');
    }

    /** Turn a message into a customer, keeping the two linked. */
    public static function convert(string $id): void
    {
        Auth::allow('customers.edit');
        $row = self::find((int) $id);

        if ($row['customer_id'] !== null) {
            Response::error('This one is already a customer', 422);
        }

        $existing = null;
        if (!empty($row['phone'])) {
            $existing = DB::value(
                'SELECT id FROM customers WHERE phone = ? AND deleted_at IS NULL',
                [Helper::normalizePhone((string) $row['phone'])]
            );
        }
        if ($existing === null && !empty($row['email'])) {
            $existing = DB::value(
                'SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL', [$row['email']]
            );
        }

        if ($existing !== null) {
            DB::update('enquiries', ['customer_id' => (int) $existing, 'status' => 'replied'],
                'id = ?', [(int) $id]);
            Response::ok(['customer_id' => (int) $existing, 'was_existing' => true],
                'They are already on your list - the message is linked to them');
        }

        $customerId = DB::insert('customers', Helper::dropNulls([
            'code'    => Helper::partyCode('customers', 'CUS-'),
            'name'    => $row['name'],
            'phone'   => !empty($row['phone']) ? Helper::normalizePhone((string) $row['phone']) : null,
            'email'   => $row['email'],
            'notes'   => "From the website on " . date('d/m/Y', strtotime((string) $row['created_at']))
                       . ":\n" . (string) $row['message'],
        ]));

        DB::update('enquiries', ['customer_id' => $customerId, 'status' => 'replied'],
            'id = ?', [(int) $id]);
        Helper::logActivity('customer', $customerId, 'create', 'from a website message');

        Response::created(['customer_id' => $customerId, 'was_existing' => false],
            $row['name'] . ' is now a customer');
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT e.*, c.name AS customer_name
             FROM enquiries e LEFT JOIN customers c ON c.id = e.customer_id
             WHERE e.id = ?',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Message not found');
        }
        return $row;
    }
}
