<?php
/**
 * AH5 Office - Invoices (editable prices, started from a job)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class InvoiceController
{
    public static function index(): void
    {
        Auth::allow('invoices.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['i.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['customer_id'])) {
            $where[]  = 'i.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        if (!empty($_GET['status'])) {
            $list = array_filter(explode(',', (string) $_GET['status']));
            if ($list) {
                $where[] = 'i.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                $params  = array_merge($params, $list);
            }
        }
        if (!empty($_GET['currency'])) {
            $where[]  = 'i.currency = ?';
            $params[] = strtoupper((string) $_GET['currency']);
        }
        if (isset($_GET['unpaid']) && $_GET['unpaid'] === '1') {
            $where[] = "i.due_amount > 0 AND i.status NOT IN ('draft','cancelled')";
        }
        if (($from = Helper::parseDate($_GET['from'] ?? null)) !== null) {
            $where[]  = 'i.invoice_date >= ?';
            $params[] = $from;
        }
        if (($to = Helper::parseDate($_GET['to'] ?? null)) !== null) {
            $where[]  = 'i.invoice_date <= ?';
            $params[] = $to;
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(i.invoice_no LIKE ? OR i.subject LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            "SELECT COUNT(*) FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE {$whereSql}",
            $params
        );
        $rows = DB::all(
            "SELECT i.*, c.name AS customer_name, c.company_name, c.whatsapp AS customer_whatsapp,
                    DATEDIFF(CURDATE(), i.due_date) AS days_overdue
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE {$whereSql}
             ORDER BY i.invoice_date DESC, i.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $summary = DB::all(
            "SELECT i.currency,
                    COALESCE(SUM(i.total),0) AS total,
                    COALESCE(SUM(i.paid_amount),0) AS paid,
                    COALESCE(SUM(i.due_amount),0) AS due
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE {$whereSql} GROUP BY i.currency",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, ['by_currency' => $summary]);
    }

    public static function show(string $id): void
    {
        Auth::allow('invoices.view');
        $inv = self::find((int) $id);

        $inv['items'] = DB::all(
            'SELECT ii.*, s.name AS service_name
             FROM invoice_items ii LEFT JOIN services s ON s.id = ii.service_id
             WHERE ii.invoice_id = ? ORDER BY ii.sort_order ASC, ii.id ASC',
            [(int) $id]
        );
        $inv['payments'] = DB::all(
            'SELECT p.id, p.receipt_no, p.payment_date, p.method, p.reference, pa.amount, p.currency
             FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
             WHERE pa.invoice_id = ? ORDER BY p.payment_date ASC',
            [(int) $id]
        );
        $inv['profit'] = Helper::money((float) $inv['total'] - (float) $inv['vat_amount'] - (float) $inv['total_cost']);

        Response::ok($inv);
    }

    public static function store(): void
    {
        Auth::allow('invoices.edit');
        $in    = self::validateInput()->validate();
        $body  = Validator::input();
        $items = $body['items'] ?? [];

        DB::begin();
        try {
            $customer = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL',
                [(int) $in['customer_id']]);
            if ($customer === null) {
                DB::rollback();
                Response::notFound('Customer not found');
            }

            $currency = Helper::baseCurrency();   // one office currency, always

            // an invoice needs something on it, unless it is built from a job or quote
            $lines = Validator::input()['items'] ?? null;
            if (empty($in['job_id']) && empty($in['quotation_id'])
                && (!is_array($lines) || count($lines) === 0)) {
                Response::validation(['items' => ['Add at least one line to the invoice']]);
            }
            $date     = $in['invoice_date'] ?? date('Y-m-d');
            $dueDays  = (int) DB::setting('invoice_due_days', '7');

            $invoiceId = DB::insert('invoices', Helper::dropNulls([
                'invoice_no'     => Helper::docNumber('invoice', $date),
                'customer_id'    => (int) $in['customer_id'],
                'job_id'         => $in['job_id'],
                'quotation_id'   => $in['quotation_id'],
                'invoice_date'   => $date,
                'due_date'       => $in['due_date'] ?? date('Y-m-d', strtotime($date . ' +' . $dueDays . ' day')),
                'currency'       => $currency,
                'fx_rate'        => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
                'discount_type'  => $in['discount_type'],
                'discount_value' => $in['discount_value'],
                'vat_percent'    => $in['vat_percent'],
                'status'         => $in['status'] ?? 'draft',
                'subject'        => $in['subject'],
                'terms'          => $in['terms'],
                'notes'          => $in['notes'],
                'remind_enabled' => $in['remind_enabled'],
                'created_by'     => Auth::userId(),
            ], ['currency','fx_rate','discount_type','discount_value','vat_percent','status','remind_enabled']));

            // Build items: explicit list, or pull uninvoiced lines from a job
            if (!$items && !empty($in['job_id'])) {
                $items = self::itemsFromJob((int) $in['job_id']);
            }
            self::syncItems($invoiceId, is_array($items) ? $items : [], $currency);
            Finance::recalcInvoice($invoiceId);

            if (!empty($in['job_id'])) {
                DB::update('jobs', ['is_invoiced' => 1], 'id = ?', [(int) $in['job_id']]);
            }

            // lines you ticked become work to do; the rest are only billed
            if (empty($in['job_id'])) {
                self::startWorkFromLines($invoiceId, (int) $in['customer_id'],
                    is_array($items) ? $items : [], $currency, $body);
            }

            // apply any advance the customer already paid
            if (!empty($body['apply_advance'])) {
                self::applyAdvance((int) $in['customer_id'], $currency);
                Finance::recalcInvoice($invoiceId);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('invoice', $invoiceId, 'create');
        Response::created(self::find($invoiceId), 'Invoice created');
    }

    public static function update(string $id): void
    {
        Auth::allow('invoices.edit');
        $inv = self::find((int) $id);

        if ($inv['status'] === 'cancelled') {
            Response::error('A cancelled invoice cannot be edited', 422);
        }

        $v     = self::validateInput(false);
        $clean = $v->validate();
        $data  = Helper::dropNulls($v->present([
            'invoice_date'   => $clean['invoice_date'] ?? null,
            'due_date'       => $clean['due_date'] ?? null,
            'currency'       => $clean['currency'] ?? null,
            'fx_rate'        => $clean['fx_rate'] ?? null,
            'discount_type'  => $clean['discount_type'] ?? null,
            'discount_value' => $clean['discount_value'] ?? null,
            'vat_percent'    => $clean['vat_percent'] ?? null,
            'status'         => $clean['status'] ?? null,
            'subject'        => $clean['subject'] ?? null,
            'terms'          => $clean['terms'] ?? null,
            'notes'          => $clean['notes'] ?? null,
            'remind_enabled' => $clean['remind_enabled'] ?? null,
        ]), ['invoice_date','currency','fx_rate','discount_type','discount_value','vat_percent','status','remind_enabled']);

        $items = Validator::input()['items'] ?? null;

        DB::begin();
        try {
            if ($data) {
                DB::update('invoices', $data, 'id = ?', [(int) $id]);
            }
            if (is_array($items)) {
                self::syncItems((int) $id, $items, strtoupper((string) ($data['currency'] ?? $inv['currency'])));
            }
            Finance::recalcInvoice((int) $id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('invoice', (int) $id, 'update');
        Response::ok(self::find((int) $id), 'Invoice updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('invoices.delete');
        $inv = self::find((int) $id);

        if ((float) $inv['paid_amount'] > 0) {
            Response::error('This invoice has payments against it. Cancel it instead of deleting.', 422);
        }
        DB::update('invoices', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Helper::logActivity('invoice', (int) $id, 'delete');
        Response::ok(null, 'Invoice deleted');
    }

    public static function cancel(string $id): void
    {
        Auth::allow('invoices.cancel');
        $inv = self::find((int) $id);
        if ((float) $inv['paid_amount'] > 0) {
            Response::error('Remove the payments first, then cancel this invoice', 422);
        }
        DB::update('invoices', ['status' => 'cancelled'], 'id = ?', [(int) $id]);
        Helper::logActivity('invoice', (int) $id, 'cancel');
        Response::ok(self::find((int) $id), 'Invoice cancelled');
    }

    /** Move draft -> sent (so it counts as receivable) and stamp the time. */
    public static function markSent(string $id): void
    {
        Auth::allow('invoices.edit');
        $inv = self::find((int) $id);
        if ($inv['status'] !== 'draft') {
            Response::error('Only a draft invoice can be marked as sent', 422);
        }
        DB::update('invoices', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Finance::recalcInvoice((int) $id);
        Helper::logActivity('invoice', (int) $id, 'sent');
        Response::ok(self::find((int) $id), 'Invoice marked as sent');
    }

    // ---------------- internals ----------------

    /** Uninvoiced job lines -> invoice item payload. */
    private static function itemsFromJob(int $jobId): array
    {
        $rows = DB::all(
            "SELECT ji.* FROM job_items ji
             WHERE ji.job_id = ? AND ji.is_invoiced = 0 AND ji.status <> 'cancelled'
             ORDER BY ji.sort_order ASC, ji.id ASC",
            [$jobId]
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'service_id'  => $r['service_id'],
                'job_item_id' => (int) $r['id'],
                'description' => $r['description'],
                'qty'         => (float) $r['qty'],
                'unit_price'  => (float) $r['unit_price'],
                'cost_price'  => (float) $r['cost_price'],
            ];
        }
        return $items;
    }


    /**
     * Turn the ticked invoice lines into a job, so they show up in the work
     * list. Billing and doing the work are separate decisions: an invoice can
     * be sent before anything starts, or for something already finished.
     */
    private static function startWorkFromLines(
        int $invoiceId, int $customerId, array $items, string $currency, array $body
    ): void {
        $wanted = [];
        foreach ($items as $i => $item) {
            if (is_array($item) && !empty($item['to_work'])) {
                $wanted[] = $i;
            }
        }
        if (!$wanted && empty($body['create_job'])) {
            return;
        }
        // "create_job" without per-line ticks means every line
        if (!$wanted) {
            $wanted = array_keys($items);
        }

        $lines = DB::all(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
            [$invoiceId]
        );
        $chosen = [];
        foreach ($wanted as $index) {
            if (isset($lines[$index])) {
                $chosen[] = $lines[$index];
            }
        }
        if (!$chosen) {
            return;
        }

        $invoice = DB::one('SELECT invoice_no, invoice_date, due_date FROM invoices WHERE id = ?', [$invoiceId]);
        $title   = trim((string) ($body['job_title'] ?? ''));
        if ($title === '') {
            $title = 'Work for ' . ($invoice['invoice_no'] ?? 'invoice');
        }

        $jobId = DB::insert('jobs', [
            'job_no'        => JobController::nextJobNo(),
            'customer_id'   => $customerId,
            'title'         => mb_substr($title, 0, 200),
            'currency'      => $currency,
            'received_date' => $invoice['invoice_date'] ?? date('Y-m-d'),
            'due_date'      => !empty($body['job_due_date'])
                ? Helper::parseDate($body['job_due_date'])
                : ($invoice['due_date'] ?? null),
            'status'        => 'pending',
            'is_invoiced'   => 1,
            'notes'         => 'Started from invoice ' . ($invoice['invoice_no'] ?? ''),
            'created_by'    => Auth::userId(),
        ]);

        $sort = 0;
        foreach ($chosen as $line) {
            $jobItemId = DB::insert('job_items', [
                'job_id'          => $jobId,
                'service_id'      => $line['service_id'],
                'description'     => $line['description'],
                'qty'             => (float) $line['qty'],
                'unit_price'      => (float) $line['unit_price'],
                'cost_price'      => (float) $line['cost_price'],
                'line_total'      => (float) $line['line_total'],
                'is_invoiced'     => 1,
                'invoice_item_id' => (int) $line['id'],
                'sort_order'      => $sort++,
            ]);
            DB::update('invoice_items', ['job_item_id' => $jobItemId], 'id = ?', [(int) $line['id']]);
        }

        DB::update('invoices', ['job_id' => $jobId], 'id = ?', [$invoiceId]);
        JobController::recalcJob($jobId);
        Helper::logActivity('invoice', $invoiceId, 'work_started', 'job #' . $jobId);
    }

    public static function syncItems(int $invoiceId, array $items, string $currency): void
    {
        $keepIds = [];
        $sort    = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc      = trim((string) ($item['description'] ?? ''));
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
            // price is always editable - the catalogue value is only a fallback
            [$sellCol, $costCol] = Helper::priceColumns($currency);
            $sell = array_key_exists('unit_price', $item) && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : ($service !== null ? (float) $service[$sellCol] : 0.0);
            $cost = array_key_exists('cost_price', $item) && $item['cost_price'] !== ''
                ? (float) $item['cost_price']
                : ($service !== null ? (float) $service[$costCol] : 0.0);

            // A negative quantity or price would flip what an invoice line
            // means - money owed *to* the customer, dressed up as a bill.
            // Refunds and credit notes are a deliberate, separate action,
            // not a side effect of a minus sign in a quantity box.
            if ($qty < 0 || $sell < 0) {
                DB::rollback();
                Response::error('"' . $desc . '" has a negative quantity or price - '
                    . 'that would make the invoice owe the customer money', 422);
            }

            $row = [
                'invoice_id'  => $invoiceId,
                'service_id'  => $serviceId,
                'job_item_id' => isset($item['job_item_id']) && $item['job_item_id'] !== '' ? (int) $item['job_item_id'] : null,
                'description' => mb_substr($desc, 0, 255),
                'qty'         => $qty,
                'unit'        => isset($item['unit']) ? mb_substr((string) $item['unit'], 0, 40) : ($service['unit'] ?? null),
                'unit_price'  => Helper::money($sell),
                'cost_price'  => Helper::money($cost),
                'line_total'  => Helper::money($sell * $qty),
                'period_start' => !empty($item['period_start']) ? Helper::parseDate($item['period_start']) : null,
                'period_end'   => !empty($item['period_end']) ? Helper::parseDate($item['period_end']) : null,
                'sort_order'  => $sort++,
            ];

            $existingId = isset($item['id']) ? (int) $item['id'] : 0;
            if ($existingId > 0 && DB::value('SELECT id FROM invoice_items WHERE id = ? AND invoice_id = ?', [$existingId, $invoiceId]) !== null) {
                unset($row['invoice_id']);
                DB::update('invoice_items', $row, 'id = ?', [$existingId]);
                $newId = $existingId;
            } else {
                $newId = DB::insert('invoice_items', $row);
            }
            $keepIds[] = $newId;

            if (!empty($row['job_item_id'])) {
                DB::update('job_items', ['is_invoiced' => 1, 'invoice_item_id' => $newId],
                    'id = ?', [(int) $row['job_item_id']]);
            }
        }

        if ($keepIds) {
            $ph      = implode(',', array_fill(0, count($keepIds), '?'));
            $dropped = DB::all("SELECT id, job_item_id FROM invoice_items WHERE invoice_id = ? AND id NOT IN ({$ph})",
                array_merge([$invoiceId], $keepIds));
            foreach ($dropped as $d) {
                if (!empty($d['job_item_id'])) {
                    DB::update('job_items', ['is_invoiced' => 0, 'invoice_item_id' => null],
                        'id = ?', [(int) $d['job_item_id']]);
                }
            }
            DB::run("DELETE FROM invoice_items WHERE invoice_id = ? AND id NOT IN ({$ph})",
                array_merge([$invoiceId], $keepIds));
        } else {
            DB::run('DELETE FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
        }
    }

    /** Push any unallocated advance onto this customer's open invoices. */
    private static function applyAdvance(int $customerId, string $currency): void
    {
        $advances = DB::all(
            'SELECT id FROM payments
             WHERE customer_id = ? AND currency = ?
               AND deleted_at IS NULL AND unallocated_amount > 0
             ORDER BY payment_date ASC, id ASC',
            [$customerId, $currency]
        );
        foreach ($advances as $a) {
            Finance::autoAllocate((int) $a['id']);
        }
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT i.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
                    c.whatsapp AS customer_whatsapp, c.email AS customer_email, c.address AS customer_address
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.id = ? AND i.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Invoice not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('customer_id', $req . 'int|exists:customers', 'Customer')
            ->check('job_id', 'nullable|int|exists:jobs')
            ->check('quotation_id', 'nullable|int|exists:quotations')
            ->check('invoice_date', 'nullable|date')
            ->check('due_date', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('discount_type', 'nullable|in:none,flat,percent')
            ->check('discount_value', 'nullable|number|min:0')
            ->check('vat_percent', 'nullable|number|min:0|max:100')
            ->check('status', 'nullable|in:draft,sent,partial,paid,overdue,cancelled')
            ->check('subject', 'nullable|string|max:200')
            ->check('terms', 'nullable|string|max:5000')
            ->check('notes', 'nullable|string|max:5000')
            ->check('remind_enabled', 'nullable|bool');
    }
}
