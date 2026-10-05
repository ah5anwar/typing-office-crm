<?php
/**
 * AH5 Office - Jobs (customer work entry, items, supplier assignment)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class JobController
{
    public static function index(): void
    {
        Auth::allow('jobs.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['j.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['customer_id'])) {
            $where[]  = 'j.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        if (!empty($_GET['status'])) {
            $list = array_filter(explode(',', (string) $_GET['status']));
            if ($list) {
                $where[] = 'j.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                $params  = array_merge($params, $list);
            }
        }
        if (isset($_GET['pending']) && $_GET['pending'] === '1') {
            $where[] = "j.status IN ('pending','in_progress','on_hold')";
        }
        if (isset($_GET['not_invoiced']) && $_GET['not_invoiced'] === '1') {
            $where[] = 'j.is_invoiced = 0';
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(j.title LIKE ? OR j.job_no LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }
        if (($from = Helper::parseDate($_GET['from'] ?? null)) !== null) {
            $where[]  = 'j.received_date >= ?';
            $params[] = $from;
        }
        if (($to = Helper::parseDate($_GET['to'] ?? null)) !== null) {
            $where[]  = 'j.received_date <= ?';
            $params[] = $to;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            "SELECT COUNT(*) FROM jobs j JOIN customers c ON c.id = j.customer_id WHERE {$whereSql}",
            $params
        );

        $rows = DB::all(
            "SELECT j.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
                    (SELECT COUNT(*) FROM job_items ji WHERE ji.job_id = j.id) AS item_count,
                    (SELECT COUNT(*) FROM job_items ji WHERE ji.job_id = j.id AND ji.status = 'completed') AS completed_items,
                    DATEDIFF(j.due_date, CURDATE()) AS days_to_due
             FROM jobs j
             JOIN customers c ON c.id = j.customer_id
             WHERE {$whereSql}
             ORDER BY j.received_date DESC, j.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function show(string $id): void
    {
        Auth::allow('jobs.view');
        $job = self::find((int) $id);

        $job['items'] = DB::all(
            'SELECT ji.*, s.name AS service_name, s.unit
             FROM job_items ji LEFT JOIN services s ON s.id = ji.service_id
             WHERE ji.job_id = ? ORDER BY ji.sort_order ASC, ji.id ASC',
            [(int) $id]
        );
        $job['suppliers'] = DB::all(
            'SELECT a.*, sp.name AS supplier_name, sp.phone AS supplier_phone, sp.whatsapp AS supplier_whatsapp
             FROM job_supplier_assign a JOIN suppliers sp ON sp.id = a.supplier_id
             WHERE a.job_id = ? ORDER BY a.id ASC',
            [(int) $id]
        );
        $job['invoices'] = DB::all(
            'SELECT id, invoice_no, invoice_date, total, paid_amount, due_amount, status
             FROM invoices WHERE job_id = ? AND deleted_at IS NULL ORDER BY id DESC',
            [(int) $id]
        );

        Response::ok($job);
    }

    public static function store(): void
    {
        Auth::allow('jobs.edit');
        $in    = self::validateInput()->validate();
        $items = Validator::input()['items'] ?? [];

        DB::begin();
        try {
            $customer = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL',
                [(int) $in['customer_id']]);
            if ($customer === null) {
                DB::rollback();
                Response::notFound('Customer not found');
            }

            $data = Helper::dropNulls([
                'job_no'        => self::nextJobNo(),
                'customer_id'   => (int) $in['customer_id'],
                'title'         => $in['title'],
                'description'   => $in['description'],
                'currency'      => Helper::baseCurrency(),
                'received_date' => $in['received_date'] ?? date('Y-m-d'),
                'due_date'      => $in['due_date'],
                'priority'      => $in['priority'],
                'status'        => $in['status'],
                'notes'         => $in['notes'],
                'remind_enabled' => $in['remind_enabled'],
                'created_by'    => Auth::userId(),
            ], ['currency','received_date','priority','status','remind_enabled']);

            $jobId = DB::insert('jobs', $data);
            self::syncItems($jobId, is_array($items) ? $items : [], (string) ($data['currency'] ?? BASE_CURRENCY));
            self::recalcJob($jobId);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('job', $jobId, 'create', (string) $in['title']);
        Response::created(self::find($jobId), 'Job added');
    }

    public static function update(string $id): void
    {
        Auth::allow('jobs.edit');
        $job = self::find((int) $id);

        $v     = self::validateInput(false);
        $clean = $v->validate();
        $data  = Helper::dropNulls($v->present([
            'customer_id'    => $clean['customer_id'] ?? null,
            'title'          => $clean['title'] ?? null,
            'description'    => $clean['description'] ?? null,
            'currency'       => $clean['currency'] ?? null,
            'received_date'  => $clean['received_date'] ?? null,
            'due_date'       => $clean['due_date'] ?? null,
            'delivered_date' => $clean['delivered_date'] ?? null,
            'priority'       => $clean['priority'] ?? null,
            'status'         => $clean['status'] ?? null,
            'notes'          => $clean['notes'] ?? null,
            'remind_enabled' => $clean['remind_enabled'] ?? null,
        ]), ['customer_id','title','currency','received_date','priority','status','remind_enabled']);

        $items = Validator::input()['items'] ?? null;

        DB::begin();
        try {
            if ($data) {
                DB::update('jobs', $data, 'id = ?', [(int) $id]);
            }
            if (is_array($items)) {
                self::syncItems((int) $id, $items, (string) ($data['currency'] ?? $job['currency']));
            }
            self::recalcJob((int) $id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('job', (int) $id, 'update');
        Response::ok(self::find((int) $id), 'Job updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('jobs.delete');
        self::find((int) $id);

        if (DB::value('SELECT id FROM invoices WHERE job_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id]) !== null) {
            Response::error('This job already has an invoice, so it cannot be deleted. Cancel it instead.', 422);
        }
        DB::update('jobs', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Helper::logActivity('job', (int) $id, 'delete');
        Response::ok(null, 'Job deleted');
    }

    /** Mark one job line complete/incomplete (manual). */
    public static function setItemStatus(string $id, string $itemId): void
    {
        Auth::allow('jobs.edit');
        self::find((int) $id);

        $in = Validator::make()
            ->check('status', 'required|in:pending,in_progress,completed,cancelled', 'Status')
            ->validate();

        $n = DB::update('job_items', [
            'status'       => $in['status'],
            'completed_at' => $in['status'] === 'completed' ? date('Y-m-d H:i:s') : null,
        ], 'id = ? AND job_id = ?', [(int) $itemId, (int) $id]);

        if ($n === 0) {
            Response::notFound('Job item not found');
        }
        self::recalcJob((int) $id);
        Response::ok(self::find((int) $id), 'Job item updated');
    }

    /** Assign work to a supplier. */
    public static function assignSupplier(string $id): void
    {
        Auth::allow('jobs.edit');
        $job = self::find((int) $id);

        $in = Validator::make()
            ->check('supplier_id', 'required|int|exists:suppliers', 'Supplier')
            ->check('job_item_id', 'nullable|int')
            ->check('work_detail', 'nullable|string|max:255')
            ->check('agreed_cost', 'nullable|number|min:0')
            ->check('currency', 'nullable|currency')
            ->check('assigned_date', 'nullable|date')
            ->check('due_date', 'nullable|date')
            ->check('note', 'nullable|string|max:5000')
            ->validate();

        if (!empty($in['job_item_id'])) {
            $ok = DB::value('SELECT id FROM job_items WHERE id = ? AND job_id = ?',
                [(int) $in['job_item_id'], (int) $id]);
            if ($ok === null) {
                Response::error('That job item does not belong to this job', 422);
            }
        }

        $assignId = DB::insert('job_supplier_assign', Helper::dropNulls([
            'job_id'        => (int) $id,
            'job_item_id'   => $in['job_item_id'],
            'supplier_id'   => (int) $in['supplier_id'],
            'work_detail'   => $in['work_detail'],
            'agreed_cost'   => Helper::money($in['agreed_cost'] ?? 0),
            'currency'      => $in['currency'] ?? (string) $job['currency'],
            'assigned_date' => $in['assigned_date'] ?? date('Y-m-d'),
            'due_date'      => $in['due_date'],
            'note'          => $in['note'],
        ], ['agreed_cost','currency','assigned_date']));

        Helper::logActivity('job', (int) $id, 'assign_supplier', 'supplier #' . $in['supplier_id']);
        Response::created(['id' => $assignId], 'Work assigned to supplier');
    }

    /** Manually mark supplier work complete. */
    public static function setAssignStatus(string $id, string $assignId): void
    {
        Auth::allow('jobs.edit');
        self::find((int) $id);

        $in = Validator::make()
            ->check('status', 'required|in:pending,in_progress,completed,cancelled', 'Status')
            ->validate();

        $n = DB::update('job_supplier_assign', [
            'status'       => $in['status'],
            'completed_at' => $in['status'] === 'completed' ? date('Y-m-d H:i:s') : null,
        ], 'id = ? AND job_id = ?', [(int) $assignId, (int) $id]);

        if ($n === 0) {
            Response::notFound('Assignment not found');
        }
        Response::ok(null, 'Supplier work status updated');
    }

    public static function removeAssign(string $id, string $assignId): void
    {
        Auth::allow('jobs.edit');
        $row = DB::one('SELECT * FROM job_supplier_assign WHERE id = ? AND job_id = ?',
            [(int) $assignId, (int) $id]);
        if ($row === null) {
            Response::notFound('Assignment not found');
        }
        if ((int) $row['is_billed'] === 1) {
            Response::error('This work is already billed by the supplier, it cannot be removed', 422);
        }
        DB::run('DELETE FROM job_supplier_assign WHERE id = ?', [(int) $assignId]);
        Response::ok(null, 'Assignment removed');
    }

    // ---------------- internals ----------------

    public static function nextJobNo(): string
    {
        $last = (int) DB::value(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(job_no, 5) AS UNSIGNED)),0) FROM jobs WHERE job_no LIKE 'JOB-%'"
        );
        return 'JOB-' . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Replace all job items with the given list. */
    public static function syncItems(int $jobId, array $items, string $currency): void
    {
        $keepIds = [];
        $sort    = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            $serviceId = isset($item['service_id']) && $item['service_id'] !== '' ? (int) $item['service_id'] : null;

            $service = null;
            if ($serviceId !== null) {
                $service = DB::one('SELECT * FROM services WHERE id = ?', [$serviceId]);
                if ($service === null) {
                    $serviceId = null;
                } elseif ($desc === '') {
                    $desc = (string) $service['name'];
                }
            }
            if ($desc === '') {
                continue;
            }

            $qty  = (float) ($item['qty'] ?? 1);
            [$sellCol, $costCol] = Helper::priceColumns($currency);
            $sell = array_key_exists('unit_price', $item) && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : ($service !== null ? (float) $service[$sellCol] : 0.0);
            $cost = array_key_exists('cost_price', $item) && $item['cost_price'] !== ''
                ? (float) $item['cost_price']
                : ($service !== null ? (float) $service[$costCol] : 0.0);

            $row = [
                'job_id'     => $jobId,
                'service_id' => $serviceId,
                'description' => mb_substr($desc, 0, 255),
                'qty'        => $qty,
                'unit_price' => Helper::money($sell),
                'cost_price' => Helper::money($cost),
                'line_total' => Helper::money($sell * $qty),
                'sort_order' => $sort++,
            ];
            if (isset($item['status']) && in_array($item['status'], ['pending','in_progress','completed','cancelled'], true)) {
                $row['status'] = $item['status'];
            }

            $existingId = isset($item['id']) ? (int) $item['id'] : 0;
            if ($existingId > 0 && DB::value('SELECT id FROM job_items WHERE id = ? AND job_id = ?', [$existingId, $jobId]) !== null) {
                unset($row['job_id']);
                DB::update('job_items', $row, 'id = ?', [$existingId]);
                $keepIds[] = $existingId;
            } else {
                $keepIds[] = DB::insert('job_items', $row);
            }
        }

        // remove lines the client dropped, unless already invoiced
        if ($keepIds) {
            $ph = implode(',', array_fill(0, count($keepIds), '?'));
            DB::run("DELETE FROM job_items WHERE job_id = ? AND is_invoiced = 0 AND id NOT IN ({$ph})",
                array_merge([$jobId], $keepIds));
        } else {
            DB::run('DELETE FROM job_items WHERE job_id = ? AND is_invoiced = 0', [$jobId]);
        }
    }

    /** Refresh estimated totals + auto status from the item list. */
    public static function recalcJob(int $jobId): void
    {
        $sum = DB::one(
            'SELECT COALESCE(SUM(line_total),0) AS total,
                    COALESCE(SUM(cost_price * qty),0) AS cost,
                    COUNT(*) AS n,
                    SUM(status = "completed") AS done,
                    SUM(status = "cancelled") AS cancelled
             FROM job_items WHERE job_id = ?',
            [$jobId]
        );

        $data = [
            'est_total' => Helper::money($sum['total'] ?? 0),
            'est_cost'  => Helper::money($sum['cost'] ?? 0),
        ];

        $job = DB::one('SELECT status FROM jobs WHERE id = ?', [$jobId]);
        $n   = (int) ($sum['n'] ?? 0);
        $done = (int) ($sum['done'] ?? 0) + (int) ($sum['cancelled'] ?? 0);

        // auto-complete only from an in-progress state, never override manual states
        if ($job !== null && $n > 0 && $done === $n
            && in_array($job['status'], ['pending', 'in_progress'], true)) {
            $data['status'] = 'completed';
        }

        DB::update('jobs', $data, 'id = ?', [$jobId]);
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT j.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
                    c.whatsapp AS customer_whatsapp
             FROM jobs j JOIN customers c ON c.id = j.customer_id
             WHERE j.id = ? AND j.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Job not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('customer_id', $req . 'int|exists:customers', 'Customer')
            ->check('title', $req . 'string|max:200', 'Job title')
            ->check('description', 'nullable|string|max:5000')
            ->check('currency', 'nullable|currency')
            ->check('received_date', 'nullable|date')
            ->check('due_date', 'nullable|date')
            ->check('delivered_date', 'nullable|date')
            ->check('priority', 'nullable|in:low,normal,high,urgent')
            ->check('status', 'nullable|in:pending,in_progress,on_hold,completed,delivered,cancelled')
            ->check('notes', 'nullable|string|max:5000')
            ->check('remind_enabled', 'nullable|bool');
    }
}
