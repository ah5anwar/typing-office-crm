<?php
/**
 * AH5 Office - Supplier endpoints (ledger, skills, who-does-what)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class SupplierController
{
    private const NOT_NULL = ['name','default_currency','opening_balance','status'];

    public static function index(): void
    {
        Auth::allow('suppliers.view');
        [$page, $perPage, $offset] = Helper::pagination();
        [$sort, $dir] = Helper::sortColumn(['id', 'name', 'company_name', 'created_at'], 'name');
        if ($sort === 'name') {
            $dir = 'ASC';
        }

        $where  = ['s.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(s.name LIKE ? OR s.company_name LIKE ? OR s.phone LIKE ? OR s.code LIKE ?
                         OR EXISTS (SELECT 1 FROM service_suppliers ss
                                     JOIN services sv ON sv.id = ss.service_id
                                    WHERE ss.supplier_id = s.id AND sv.name LIKE ?))';
            array_push($params, $q, $q, $q, $q, $q);
        }
        if (!empty($_GET['status'])) {
            $where[]  = 's.status = ?';
            $params[] = (string) $_GET['status'];
        }
        if (!empty($_GET['service_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM service_suppliers ss WHERE ss.supplier_id = s.id AND ss.service_id = ?)';
            $params[] = (int) $_GET['service_id'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM suppliers s WHERE {$whereSql}", $params);

        $rows = DB::all(
            "SELECT s.*,
                    (SELECT COALESCE(SUM(b.due_amount),0) FROM supplier_bills b
                      WHERE b.supplier_id = s.id AND b.deleted_at IS NULL AND b.status <> 'cancelled') AS payable,
                    (SELECT COUNT(*) FROM job_supplier_assign a
                      WHERE a.supplier_id = s.id AND a.status IN ('pending','in_progress')) AS pending_works,
                    (SELECT GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ')
                       FROM service_suppliers ss JOIN services sv ON sv.id = ss.service_id
                      WHERE ss.supplier_id = s.id) AS services,
                    (SELECT COALESCE(SUM(sp.unallocated_amount),0) FROM supplier_payments sp
                      WHERE sp.supplier_id = s.id AND sp.deleted_at IS NULL) AS advance_paid,
                    (SELECT COALESCE(SUM(a.agreed_cost),0) FROM job_supplier_assign a
                      WHERE a.supplier_id = s.id
                        AND a.status IN ('pending','in_progress')) AS pending_work_value
             FROM suppliers s
             WHERE {$whereSql}
             ORDER BY s.{$sort} {$dir}
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
        Auth::allow('suppliers.view');
        $supplier = self::find((int) $id);

        $supplier['skills'] = DB::all(
            'SELECT ss.service_id AS id, sv.name AS skill, ss.supplier_cost AS note
             FROM service_suppliers ss JOIN services sv ON sv.id = ss.service_id
             WHERE ss.supplier_id = ? ORDER BY sv.name ASC',
            [(int) $id]
        );
        $supplier['services'] = DB::all(
            'SELECT ss.id AS link_id, ss.supplier_cost, ss.currency, ss.is_preferred, ss.lead_days,
                    s.id AS service_id, s.name, s.unit
             FROM service_suppliers ss
             JOIN services s ON s.id = ss.service_id
             WHERE ss.supplier_id = ?
             ORDER BY s.name ASC',
            [(int) $id]
        );
        $supplier['pending_works'] = DB::all(
            'SELECT a.id, a.work_detail, a.agreed_cost, a.currency, a.assigned_date, a.due_date, a.status,
                    j.job_no, j.title AS job_title, c.name AS customer_name
             FROM job_supplier_assign a
             JOIN jobs j ON j.id = a.job_id
             JOIN customers c ON c.id = j.customer_id
             WHERE a.supplier_id = ? AND a.status IN ("pending","in_progress")
             ORDER BY a.due_date IS NULL, a.due_date ASC, a.id DESC',
            [(int) $id]
        );

        Response::ok($supplier);
    }

    public static function store(): void
    {
        Auth::allow('suppliers.edit');
        $in = self::validateInput()->validate();

        $id = Helper::insertWithFreshCode('suppliers', 'SUP-', function ($code) use ($in) {
            $in['code'] = $code;
            return Helper::dropNulls(self::columns($in));
        });

        self::syncServices($id, Validator::input()['service_ids'] ?? []);

        Helper::logActivity('supplier', $id, 'create', (string) $in['name']);
        Response::created(self::find($id), 'Supplier added');
    }

    public static function update(string $id): void
    {
        Auth::allow('suppliers.edit');
        self::find((int) $id);

        $v    = self::validateInput(false);
        $data = Helper::dropNulls($v->present(self::columns($v->validate())), self::NOT_NULL);

        if ($data) {
            DB::update('suppliers', $data, 'id = ?', [(int) $id]);
            Helper::logActivity('supplier', (int) $id, 'update');
        }
        if (array_key_exists('service_ids', Validator::input())) {
            self::syncServices((int) $id, Validator::input()['service_ids']);
        }

        Response::ok(self::find((int) $id), 'Supplier updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('suppliers.delete');
        self::find((int) $id);

        $hasBill = DB::value('SELECT id FROM supplier_bills WHERE supplier_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id]);
        if ($hasBill !== null) {
            DB::update('suppliers', ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'inactive'], 'id = ?', [(int) $id]);
            Response::ok(null, 'Supplier archived (bill history kept)');
        }

        DB::update('suppliers', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Helper::logActivity('supplier', (int) $id, 'delete');
        Response::ok(null, 'Supplier deleted');
    }

    /** Supplier ledger: what I owe, what I paid, work status. */
    public static function summary(string $id): void
    {
        Auth::allow('suppliers.view');
        $supplier = self::find((int) $id);

        $bills = DB::all(
            "SELECT currency,
                    COUNT(*)                     AS bill_count,
                    COALESCE(SUM(amount),0)      AS billed,
                    COALESCE(SUM(paid_amount),0) AS paid,
                    COALESCE(SUM(due_amount),0)  AS payable
             FROM supplier_bills
             WHERE supplier_id = ? AND deleted_at IS NULL AND status <> 'cancelled'
             GROUP BY currency",
            [(int) $id]
        );

        $advance = DB::all(
            "SELECT currency, COALESCE(SUM(unallocated_amount),0) AS advance_paid
             FROM supplier_payments
             WHERE supplier_id = ? AND deleted_at IS NULL
             GROUP BY currency",
            [(int) $id]
        );

        $works = DB::one(
            "SELECT
                SUM(status = 'pending')     AS pending,
                SUM(status = 'in_progress') AS in_progress,
                SUM(status = 'completed')   AS completed,
                COUNT(*)                    AS total
             FROM job_supplier_assign WHERE supplier_id = ?",
            [(int) $id]
        );

        $ledger = DB::all(
            "SELECT 'bill' AS type, b.id AS ref_id, b.bill_no AS ref_no,
                    b.bill_date AS entry_date, b.currency, b.amount AS credit, 0 AS debit,
                    b.status, b.description AS what_for, NULL AS method, NULL AS account_label
             FROM supplier_bills b
             WHERE b.supplier_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled'
             UNION ALL
             SELECT 'payment', sp.id, sp.voucher_no, sp.payment_date, sp.currency, 0, sp.amount,
                    IF(sp.unallocated_amount > 0, 'advance', 'paid'),
                    sp.reference, sp.method, acc.name
             FROM supplier_payments sp
             LEFT JOIN accounts acc ON acc.id = sp.account_id
             WHERE sp.supplier_id = ? AND sp.deleted_at IS NULL
             ORDER BY entry_date DESC, ref_id DESC
             LIMIT 100",
            [(int) $id, (int) $id]
        );

        Response::ok([
            'supplier'    => [
                'id'   => (int) $supplier['id'],
                'code' => $supplier['code'],
                'name' => $supplier['name'],
            ],
            'by_currency' => $bills,
            'services_list' => DB::value(
                "SELECT GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ')
                 FROM service_suppliers ss JOIN services sv ON sv.id = ss.service_id
                 WHERE ss.supplier_id = ?",
                [(int) $id]
            ),
            'advance'     => $advance,
            'works'       => $works,
            'ledger'      => $ledger,
        ]);
    }


    /**
     * Which of your own services this supplier provides.
     * Their "skills" are simply your service list, ticked.
     */

    /**
     * Hand a supplier a piece of work. Usually it belongs to a job you are
     * already doing for a customer; sometimes it is just something you need
     * done, so a small job is opened to hold it. Either way the cost lands
     * in the right place and it shows on the work list.
     */
    public static function giveWork(): void
    {
        Auth::allow('jobs.edit');

        $in = Validator::make()
            ->check('supplier_id', 'required|int|exists:suppliers', 'Supplier')
            ->check('job_id', 'nullable|int')
            ->check('customer_id', 'nullable|int')
            ->check('work_detail', 'required|string|max:255', 'What they must do')
            ->check('agreed_cost', 'nullable|number|min:0')
            ->check('due_date', 'nullable|date')
            ->check('due_in_days', 'nullable|int|min:0')
            ->check('note', 'nullable|string|max:5000')
            ->validate();

        $supplier = DB::one('SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL',
            [(int) $in['supplier_id']]);
        if ($supplier === null) {
            Response::notFound('Supplier not found');
        }

        // "in five days" is easier to say than a date
        $due = $in['due_date'] ?? null;
        if ($due === null && isset($in['due_in_days'])) {
            $due = date('Y-m-d', strtotime('+' . (int) $in['due_in_days'] . ' days'));
        }

        $jobId = $in['job_id'] ?? null;
        $openedJob = false;

        if ($jobId !== null) {
            $job = DB::one('SELECT * FROM jobs WHERE id = ? AND deleted_at IS NULL', [(int) $jobId]);
            if ($job === null) {
                Response::error('That job does not exist', 422);
            }
        } else {
            // nothing to hang it on, so open one
            if (empty($in['customer_id'])) {
                Response::validation(['customer_id' => [
                    'Say which customer this is for, or pick an existing job'
                ]]);
            }
            $jobId = DB::insert('jobs', Helper::dropNulls([
                'job_no'        => JobController::nextJobNo(),
                'customer_id'   => (int) $in['customer_id'],
                'title'         => mb_substr((string) $in['work_detail'], 0, 200),
                'currency'      => Helper::baseCurrency(),
                'received_date' => date('Y-m-d'),
                'due_date'      => $due,
                'status'        => 'in_progress',
                'description'   => 'Opened when work was given to ' . $supplier['name'],
            ], ['job_no', 'customer_id', 'title', 'currency', 'received_date', 'status']));
            $openedJob = true;
        }

        $assignId = DB::insert('job_supplier_assign', Helper::dropNulls([
            'job_id'        => (int) $jobId,
            'supplier_id'   => (int) $in['supplier_id'],
            'work_detail'   => $in['work_detail'],
            'agreed_cost'   => $in['agreed_cost'] ?? 0,
            'currency'      => Helper::baseCurrency(),
            'assigned_date' => date('Y-m-d'),
            'due_date'      => $due,
            'status'        => 'pending',
            'note'          => $in['note'] ?? null,
        ], ['job_id', 'supplier_id', 'work_detail', 'agreed_cost', 'currency', 'assigned_date', 'status']));

        Helper::logActivity('supplier', (int) $in['supplier_id'], 'give_work',
            (string) $in['work_detail']);

        Response::created([
            'assign_id'  => $assignId,
            'job_id'     => (int) $jobId,
            'opened_job' => $openedJob,
            'due_date'   => $due,
        ], $openedJob
            ? 'Given to ' . $supplier['name'] . ' — a job was opened to hold it'
            : 'Given to ' . $supplier['name']);
    }

    private static function syncServices(int $supplierId, $serviceIds): void
    {
        if (!is_array($serviceIds)) {
            return;
        }
        $wanted = array_values(array_unique(array_map('intval', $serviceIds)));

        DB::run('DELETE FROM service_suppliers WHERE supplier_id = ?', [$supplierId]);
        foreach ($wanted as $serviceId) {
            if ($serviceId <= 0 || DB::value('SELECT id FROM services WHERE id = ?', [$serviceId]) === null) {
                continue;
            }
            DB::run(
                'INSERT IGNORE INTO service_suppliers (service_id, supplier_id) VALUES (?, ?)',
                [$serviceId, $supplierId]
            );
        }
    }


    /**
     * One-click view: who does what.
     * group=supplier -> supplier with their skills+services
     * group=skill    -> each skill/service with the suppliers who do it
     */
    /**
     * Who does what, two ways round: every supplier with the services they
     * cover, and every service with the suppliers who can do it.
     */
    public static function whoDoesWhat(): void
    {
        Auth::allow('suppliers.view');

        $byPerson = DB::all(
            "SELECT s.id, s.name, s.company_name, s.phone, s.whatsapp,
                    GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
                    COUNT(sv.id) AS service_count,
                    (SELECT COUNT(*) FROM job_supplier_assign a
                      WHERE a.supplier_id = s.id AND a.status IN ('pending','in_progress')) AS open_work
             FROM suppliers s
             LEFT JOIN service_suppliers ss ON ss.supplier_id = s.id
             LEFT JOIN services sv ON sv.id = ss.service_id
             WHERE s.deleted_at IS NULL
             GROUP BY s.id
             ORDER BY service_count DESC, s.name ASC"
        );

        $byService = DB::all(
            "SELECT sv.id, sv.name, c.name AS category_name,
                    GROUP_CONCAT(s.name ORDER BY ss.is_preferred DESC, s.name SEPARATOR ', ') AS suppliers,
                    COUNT(s.id) AS supplier_count,
                    MIN(ss.supplier_cost) AS cheapest
             FROM services sv
             LEFT JOIN service_categories c ON c.id = sv.category_id
             LEFT JOIN service_suppliers ss ON ss.service_id = sv.id
             LEFT JOIN suppliers s ON s.id = ss.supplier_id AND s.deleted_at IS NULL
             WHERE sv.is_active = 1
             GROUP BY sv.id
             ORDER BY supplier_count DESC, sv.name ASC"
        );

        Response::ok([
            'by_person'  => $byPerson,
            'by_service' => $byService,
        ]);
    }

    // ---------------- helpers ----------------

    /** Where the panel should load their picture from. */
    public static function photoUrl(?string $stored): ?string
    {
        return ($stored === null || $stored === '')
            ? null
            : 'asset.php?photo=' . rawurlencode(basename($stored));
    }

    private static function find(int $id): array
    {
        $row = DB::one('SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            Response::notFound('Supplier not found');
        }
        $row['photo_url'] = self::photoUrl($row['photo'] ?? null);

        // the services they provide, so the form can tick them back
        $row['services'] = DB::all(
            'SELECT ss.service_id, sv.name, ss.supplier_cost, ss.is_preferred
             FROM service_suppliers ss JOIN services sv ON sv.id = ss.service_id
             WHERE ss.supplier_id = ? ORDER BY sv.name ASC',
            [$id]
        );
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('name', $req . 'string|max:160', 'Name')
            ->check('company_name', 'nullable|string|max:200')
            ->check('phone', 'nullable|phone|max:30')
            ->check('whatsapp', 'nullable|phone|max:30')
            ->check('telegram_chat_id', 'nullable|string|max:40')
            ->check('email', 'nullable|email|max:160')
            ->check('address', 'nullable|string|max:255')
            ->check('country', 'nullable|string|max:80')
            ->check('default_currency', 'nullable|currency')
            ->check('opening_balance', 'nullable|number')
            ->check('payment_terms', 'nullable|string|max:120')
            ->check('notes', 'nullable|string|max:5000')
            ->check('status', 'nullable|in:active,inactive');
    }

    private static function columns(array $in): array
    {
        $allowed = [
            'code','name','company_name','phone','whatsapp','telegram_chat_id','email',
            'address','country','default_currency','opening_balance','payment_terms',
            'notes','status',
        ];
        return array_intersect_key($in, array_flip($allowed));
    }
}
