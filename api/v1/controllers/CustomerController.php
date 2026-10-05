<?php
/**
 * AH5 Office - Customer endpoints
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class CustomerController
{
    /** Columns that must never receive NULL (they have DB defaults). */
    private const NOT_NULL = ['name','type','default_currency','opening_balance','credit_limit','status'];

    public static function index(): void
    {
        Auth::allow('customers.view');
        [$page, $perPage, $offset] = Helper::pagination();
        [$sort, $dir] = Helper::sortColumn(
            ['id', 'name', 'company_name', 'created_at', 'city'], 'created_at'
        );

        $where  = ['c.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(c.name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.email LIKE ? OR c.code LIKE ?)';
            array_push($params, $q, $q, $q, $q, $q, $q);
        }
        if (!empty($_GET['status'])) {
            $where[]  = 'c.status = ?';
            $params[] = (string) $_GET['status'];
        }
        if (!empty($_GET['currency'])) {
            $where[]  = 'c.default_currency = ?';
            $params[] = strtoupper((string) $_GET['currency']);
        }
        if (isset($_GET['has_due']) && $_GET['has_due'] === '1') {
            $where[] = '(SELECT COALESCE(SUM(i.due_amount),0) FROM invoices i
                         WHERE i.customer_id = c.id AND i.deleted_at IS NULL
                           AND i.status NOT IN ("draft","cancelled")) > 0';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM customers c WHERE {$whereSql}", $params);

        $rows = DB::all(
            "SELECT c.*,
                    (SELECT COALESCE(SUM(i.due_amount),0) FROM invoices i
                      WHERE i.customer_id = c.id AND i.deleted_at IS NULL
                        AND i.status NOT IN ('draft','cancelled')) AS total_due,
                    (SELECT COUNT(*) FROM jobs j
                      WHERE j.customer_id = c.id AND j.deleted_at IS NULL
                        AND j.status IN ('pending','in_progress','on_hold')) AS pending_jobs,
                    (SELECT COUNT(*) FROM customer_documents d
                      WHERE d.customer_id = c.id AND d.deleted_at IS NULL
                        AND d.status = 'active' AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_docs,
                    (SELECT COALESCE(SUM(p.unallocated_amount),0) FROM payments p
                      WHERE p.customer_id = c.id AND p.deleted_at IS NULL) AS advance_held,
                    (SELECT COUNT(*) FROM job_items ji
                       JOIN jobs j2 ON j2.id = ji.job_id AND j2.deleted_at IS NULL
                      WHERE j2.customer_id = c.id
                        AND ji.status IN ('pending','in_progress')) AS pending_work,
                    (SELECT COUNT(*) FROM attachments a
                      WHERE a.entity_type = 'document'
                        AND a.entity_id IN (SELECT id FROM customer_documents d2
                                             WHERE d2.customer_id = c.id AND d2.deleted_at IS NULL)) AS file_count,
                    (SELECT MIN(d3.expiry_date) FROM customer_documents d3
                      WHERE d3.customer_id = c.id AND d3.deleted_at IS NULL
                        AND d3.status = 'active') AS next_expiry
             FROM customers c
             WHERE {$whereSql}
             ORDER BY c.{$sort} {$dir}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $row['photo_url'] = self::photoUrl($row['photo'] ?? null);
        }
        unset($row);

        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function show(string $id): void
    {
        Auth::allow('customers.view');
        $customer = self::find((int) $id);

        $customer['documents'] = DB::all(
            'SELECT d.*, dt.name AS doc_type_name,
                    DATEDIFF(d.expiry_date, CURDATE()) AS days_left
             FROM customer_documents d
             LEFT JOIN document_types dt ON dt.id = d.doc_type_id
             WHERE d.customer_id = ? AND d.deleted_at IS NULL
             ORDER BY d.expiry_date ASC',
            [(int) $id]
        );
        $customer['recent_jobs'] = DB::all(
            'SELECT id, job_no, title, status, received_date, due_date, est_total, currency
             FROM jobs WHERE customer_id = ? AND deleted_at IS NULL
             ORDER BY received_date DESC, id DESC LIMIT 10',
            [(int) $id]
        );
        $customer['recent_invoices'] = DB::all(
            'SELECT id, invoice_no, invoice_date, due_date, currency, total, paid_amount, due_amount, status
             FROM invoices WHERE customer_id = ? AND deleted_at IS NULL
             ORDER BY invoice_date DESC, id DESC LIMIT 10',
            [(int) $id]
        );

        Response::ok($customer);
    }

    public static function store(): void
    {
        Auth::allow('customers.edit');
        $in = self::validateInput()->validate();

        $in['created_by'] = Auth::userId();

        $id = Helper::insertWithFreshCode('customers', 'CUS-', function ($code) use ($in) {
            $in['code'] = $code;
            return Helper::dropNulls(self::columns($in));
        });
        Helper::logActivity('customer', $id, 'create', (string) $in['name']);

        Response::created(self::find($id), 'Customer added');
    }

    public static function update(string $id): void
    {
        Auth::allow('customers.edit');
        self::find((int) $id);

        $v    = self::validateInput(false);
        $data = Helper::dropNulls($v->present(self::columns($v->validate())), self::NOT_NULL);

        if ($data) {
            DB::update('customers', $data, 'id = ?', [(int) $id]);
            Helper::logActivity('customer', (int) $id, 'update');
        }
        Response::ok(self::find((int) $id), 'Customer updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('customers.delete');
        self::find((int) $id);

        $hasInvoice = DB::value('SELECT id FROM invoices WHERE customer_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id]);
        $hasJob     = DB::value('SELECT id FROM jobs WHERE customer_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id]);
        if ($hasInvoice !== null || $hasJob !== null) {
            // keep the history - soft delete only
            DB::update('customers', ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'inactive'], 'id = ?', [(int) $id]);
            Helper::logActivity('customer', (int) $id, 'soft_delete');
            Response::ok(null, 'Customer archived (invoice/job history kept)');
        }

        DB::update('customers', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Helper::logActivity('customer', (int) $id, 'delete');
        Response::ok(null, 'Customer deleted');
    }

    /** Full ledger summary for one customer, per currency. */
    public static function summary(string $id): void
    {
        Auth::allow('customers.view');
        $customer = self::find((int) $id);

        $invoices = DB::all(
            "SELECT currency,
                    COUNT(*)                    AS invoice_count,
                    COALESCE(SUM(total),0)      AS invoiced,
                    COALESCE(SUM(paid_amount),0) AS paid,
                    COALESCE(SUM(due_amount),0)  AS due,
                    COALESCE(SUM(total_cost),0)  AS cost
             FROM invoices
             WHERE customer_id = ? AND deleted_at IS NULL
               AND status NOT IN ('draft','cancelled')
             GROUP BY currency",
            [(int) $id]
        );

        $advance = DB::all(
            "SELECT currency, COALESCE(SUM(unallocated_amount),0) AS advance_balance
             FROM payments
             WHERE customer_id = ? AND deleted_at IS NULL
             GROUP BY currency",
            [(int) $id]
        );

        $jobs = DB::one(
            "SELECT
                SUM(status IN ('pending','in_progress','on_hold')) AS pending,
                SUM(status = 'completed')  AS completed,
                SUM(status = 'delivered')  AS delivered,
                COUNT(*)                   AS total
             FROM jobs WHERE customer_id = ? AND deleted_at IS NULL",
            [(int) $id]
        );

        $docs = DB::all(
            'SELECT id, title, expiry_date, DATEDIFF(expiry_date, CURDATE()) AS days_left, status
             FROM customer_documents
             WHERE customer_id = ? AND deleted_at IS NULL AND status = "active"
             ORDER BY expiry_date ASC LIMIT 20',
            [(int) $id]
        );

        $ledger = DB::all(
            "SELECT 'invoice' AS type, id AS ref_id, invoice_no AS ref_no, invoice_date AS entry_date,
                    currency, total AS debit, 0 AS credit, status
             FROM invoices
             WHERE customer_id = ? AND deleted_at IS NULL AND status NOT IN ('draft','cancelled')
             UNION ALL
             SELECT 'payment', id, receipt_no, payment_date, currency, 0, amount, method
             FROM payments
             WHERE customer_id = ? AND deleted_at IS NULL
             ORDER BY entry_date DESC, ref_id DESC
             LIMIT 100",
            [(int) $id, (int) $id]
        );

        Response::ok([
            'customer'      => [
                'id'           => (int) $customer['id'],
                'code'         => $customer['code'],
                'name'         => $customer['name'],
                'company_name' => $customer['company_name'],
                'currency'     => Helper::baseCurrency(),
            ],
            'by_currency'   => $invoices,
            'advance'       => $advance,
            'jobs'          => $jobs,
            'expiring_docs' => $docs,
            'ledger'        => $ledger,
        ]);
    }


    /**
     * Everything about one customer on a single call: what they owe, what
     * they paid, what work is open, which documents expire when, and every
     * file on record. This is the page you open before you ring them.
     */
    public static function overview(string $id): void
    {
        Auth::allow('customers.view');
        $customer = self::find((int) $id);
        $cid = (int) $id;

        $money = DB::all(
            "SELECT i.currency,
                    COUNT(*)                        AS invoices,
                    COALESCE(SUM(i.total),0)        AS invoiced,
                    COALESCE(SUM(i.paid_amount),0)  AS paid,
                    COALESCE(SUM(i.due_amount),0)   AS due,
                    COALESCE(SUM(i.total_cost),0)   AS cost,
                    MIN(CASE WHEN i.due_amount > 0 THEN i.due_date END) AS oldest_due
             FROM invoices i
             WHERE i.customer_id = ? AND i.deleted_at IS NULL
               AND i.status NOT IN ('draft','cancelled')
             GROUP BY i.currency",
            [$cid]
        );

        $advance = DB::all(
            'SELECT currency, COALESCE(SUM(unallocated_amount),0) AS amount
             FROM payments WHERE customer_id = ? AND deleted_at IS NULL AND unallocated_amount > 0
             GROUP BY currency',
            [$cid]
        );

        $work = DB::all(
            "SELECT ji.id, ji.description, ji.status, ji.line_total, j.job_no, j.title AS job_title,
                    j.due_date, j.currency,
                    DATEDIFF(j.due_date, CURDATE()) AS days_to_due,
                    (SELECT GROUP_CONCAT(sup.name SEPARATOR ', ')
                       FROM job_supplier_assign a JOIN suppliers sup ON sup.id = a.supplier_id
                      WHERE a.job_item_id = ji.id) AS given_to
             FROM job_items ji
             JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL
             WHERE j.customer_id = ? AND ji.status IN ('pending','in_progress')
             ORDER BY (j.due_date IS NULL), j.due_date ASC
             LIMIT 30",
            [$cid]
        );

        $documents = DB::all(
            "SELECT d.id, d.title, d.doc_number, d.expiry_date, d.status, d.renewal_amount, d.currency,
                    DATEDIFF(d.expiry_date, CURDATE()) AS days_left,
                    dt.name AS doc_type_name,
                    (SELECT COUNT(*) FROM attachments a
                      WHERE a.entity_type = 'document' AND a.entity_id = d.id) AS file_count
             FROM customer_documents d
             LEFT JOIN document_types dt ON dt.id = d.doc_type_id
             WHERE d.customer_id = ? AND d.deleted_at IS NULL
             ORDER BY d.expiry_date ASC",
            [$cid]
        );

        $files = DB::all(
            "SELECT a.id, a.file_name, a.mime_type, a.file_size, a.label, a.created_at,
                    a.entity_type, a.entity_id,
                    CASE a.entity_type
                      WHEN 'document' THEN (SELECT d.title FROM customer_documents d WHERE d.id = a.entity_id)
                      WHEN 'payment'  THEN (SELECT p.receipt_no FROM payments p WHERE p.id = a.entity_id)
                      WHEN 'invoice'  THEN (SELECT i.invoice_no FROM invoices i WHERE i.id = a.entity_id)
                      ELSE NULL END AS belongs_to
             FROM attachments a
             WHERE (a.entity_type = 'customer' AND a.entity_id = ?)
                OR (a.entity_type = 'document' AND a.entity_id IN
                     (SELECT id FROM customer_documents WHERE customer_id = ? AND deleted_at IS NULL))
                OR (a.entity_type = 'payment' AND a.entity_id IN
                     (SELECT id FROM payments WHERE customer_id = ? AND deleted_at IS NULL))
                OR (a.entity_type = 'invoice' AND a.entity_id IN
                     (SELECT id FROM invoices WHERE customer_id = ? AND deleted_at IS NULL))
             ORDER BY a.created_at DESC
             LIMIT 60",
            [$cid, $cid, $cid, $cid]
        );
        foreach ($files as &$f) {
            $f['view_url']     = PrintAuth::fileLink((int) $f['id'], true);
            $f['download_url'] = PrintAuth::fileLink((int) $f['id'], false);
            $f['can_view']     = AttachmentController::viewableInBrowser((string) ($f['mime_type'] ?? ''));
            $f['size_label']   = AttachmentController::sizeLabel((int) $f['file_size']);
        }
        unset($f);

        $ledger = DB::all(
            "SELECT 'invoice' AS type, i.id AS ref_id, i.invoice_no AS ref_no,
                    i.subject AS what_for, i.invoice_date AS entry_date, i.currency,
                    i.total AS debit, 0 AS credit, i.status, NULL AS method,
                    NULL AS account_label
             FROM invoices i
             WHERE i.customer_id = ? AND i.deleted_at IS NULL
               AND i.status NOT IN ('draft','cancelled')
             UNION ALL
             SELECT 'payment', p.id, p.receipt_no,
                    (SELECT GROUP_CONCAT(COALESCE(NULLIF(i2.subject,''), i2.invoice_no)
                                          ORDER BY i2.invoice_no SEPARATOR ', ')
                       FROM payment_allocations pa2
                       JOIN invoices i2 ON i2.id = pa2.invoice_id
                      WHERE pa2.payment_id = p.id),
                    p.payment_date, p.currency, 0, p.amount,
                    IF(p.unallocated_amount > 0, 'advance', 'received'),
                    p.method, acc.name
             FROM payments p
             LEFT JOIN accounts acc ON acc.id = p.account_id
             WHERE p.customer_id = ? AND p.deleted_at IS NULL
             ORDER BY entry_date DESC, ref_id DESC
             LIMIT 60",
            [$cid, $cid]
        );

        $messages = DB::all(
            "SELECT channel, body, status, created_at FROM message_log
             WHERE party_type = 'customer' AND party_id = ?
             ORDER BY created_at DESC LIMIT 10",
            [$cid]
        );

        Response::ok([
            'customer'  => $customer,
            'money'     => $money,
            'advance'   => $advance,
            'work'      => $work,
            'documents' => $documents,
            'files'     => $files,
            'ledger'    => $ledger,
            'messages'  => $messages,
            'counts'    => [
                'open_work'     => count($work),
                'documents'     => count($documents),
                'files'         => count($files),
                'expiring_soon' => count(array_filter($documents,
                    static fn ($d) => $d['days_left'] !== null && (int) $d['days_left'] <= 30)),
            ],
        ]);
    }

    // ---------------- helpers ----------------


    /**
     * Every payment this customer has made: when, how much, by what means,
     * and what it went towards. Written so that they can read it too — the
     * point is that both of you can see which work was paid for.
     */
    public static function payments(string $id): void
    {
        Auth::allow('payments.view');
        $customer = self::find((int) $id);

        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-d', strtotime('-1 year')));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));

        $rows = DB::all(
            'SELECT p.id, p.receipt_no, p.payment_date, p.amount, p.method, p.reference,
                    p.note, p.allocated_amount, p.unallocated_amount, acc.name AS account_label
             FROM payments p
             LEFT JOIN accounts acc ON acc.id = p.account_id
             WHERE p.customer_id = ? AND p.deleted_at IS NULL
               AND p.payment_date BETWEEN ? AND ?
             ORDER BY p.payment_date DESC, p.id DESC',
            [(int) $id, $from, $to]
        );

        // what each one was set against, so the reader can match money to work
        foreach ($rows as &$r) {
            $r['towards'] = DB::all(
                'SELECT pa.amount, i.id AS invoice_id, i.invoice_no, i.subject
                 FROM payment_allocations pa
                 JOIN invoices i ON i.id = pa.invoice_id
                 WHERE pa.payment_id = ?
                 ORDER BY i.invoice_date ASC',
                [(int) $r['id']]
            );
        }
        unset($r);

        $received = array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows));
        $onAccount = (float) DB::value(
            'SELECT COALESCE(SUM(unallocated_amount),0) FROM payments
             WHERE customer_id = ? AND deleted_at IS NULL',
            [(int) $id]
        );

        // the same money grouped by how it arrived
        $byMethod = DB::all(
            "SELECT COALESCE(NULLIF(p.method,''),'Not said') AS method,
                    COUNT(*) AS times, COALESCE(SUM(p.amount),0) AS total
             FROM payments p
             WHERE p.customer_id = ? AND p.deleted_at IS NULL
               AND p.payment_date BETWEEN ? AND ?
             GROUP BY p.method ORDER BY total DESC",
            [(int) $id, $from, $to]
        );

        $still = (float) DB::value(
            "SELECT COALESCE(SUM(due_amount),0) FROM invoices
             WHERE customer_id = ? AND deleted_at IS NULL
               AND status NOT IN ('draft','cancelled')",
            [(int) $id]
        );

        Response::ok([
            'customer' => [
                'id'           => (int) $customer['id'],
                'code'         => $customer['code'],
                'name'         => $customer['name'],
                'company_name' => $customer['company_name'],
                'phone'        => $customer['phone'],
            ],
            'from'          => $from,
            'to'            => $to,
            'currency'      => Helper::baseCurrency(),
            'received'      => Helper::money($received),
            'on_account'    => Helper::money($onAccount),
            'still_due'     => Helper::money($still),
            'by_method'     => $byMethod,
            'payments'      => $rows,
        ]);
    }

    /** Where the panel should load their picture from. */
    public static function photoUrl(?string $stored): ?string
    {
        return ($stored === null || $stored === '')
            ? null
            : 'asset.php?photo=' . rawurlencode(basename($stored));
    }

    private static function find(int $id): array
    {
        $row = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            Response::notFound('Customer not found');
        }
        $row['photo_url'] = self::photoUrl($row['photo'] ?? null);
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('name', $req . 'string|max:160', 'Name')
            ->check('company_name', 'nullable|string|max:200')
            ->check('type', 'nullable|in:individual,company')
            ->check('phone', 'nullable|phone|max:30')
            ->check('phone_alt', 'nullable|phone|max:30')
            ->check('whatsapp', 'nullable|phone|max:30')
            ->check('telegram_chat_id', 'nullable|string|max:40')
            ->check('messenger_psid', 'nullable|string|max:60')
            ->check('email', 'nullable|email|max:160')
            ->check('website', 'nullable|string|max:200')
            ->check('address', 'nullable|string|max:255')
            ->check('city', 'nullable|string|max:80')
            ->check('country', 'nullable|string|max:80')
            ->check('trade_license', 'nullable|string|max:80')
            ->check('tax_number', 'nullable|string|max:80')
            ->check('default_currency', 'nullable|currency')
            ->check('opening_balance', 'nullable|number')
            ->check('credit_limit', 'nullable|number')
            ->check('notes', 'nullable|string|max:5000')
            ->check('tags', 'nullable|string|max:255')
            ->check('status', 'nullable|in:active,inactive');
    }

    /** Keep only real table columns. */
    private static function columns(array $in): array
    {
        $allowed = [
            'code','name','company_name','type','phone','phone_alt','whatsapp',
            'telegram_chat_id','messenger_psid','email','website','address','city','country',
            'trade_license','tax_number','default_currency','opening_balance','credit_limit',
            'notes','tags','status','created_by',
        ];
        return array_intersect_key($in, array_flip($allowed));
    }
}
