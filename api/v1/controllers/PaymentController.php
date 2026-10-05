<?php
/**
 * AH5 Office - Customer payments (advance + allocation + auto message)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class PaymentController
{
    public static function index(): void
    {
        Auth::allow('payments.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['p.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['customer_id'])) {
            $where[]  = 'p.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        if (isset($_GET['advance_only']) && $_GET['advance_only'] === '1') {
            $where[] = 'p.unallocated_amount > 0';
        }
        if (($from = Helper::parseDate($_GET['from'] ?? null)) !== null) {
            $where[]  = 'p.payment_date >= ?';
            $params[] = $from;
        }
        if (($to = Helper::parseDate($_GET['to'] ?? null)) !== null) {
            $where[]  = 'p.payment_date <= ?';
            $params[] = $to;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM payments p WHERE {$whereSql}", $params);
        $rows  = DB::all(
            "SELECT p.*, acc.name AS account_label, c.name AS customer_name, c.company_name,
                    (SELECT GROUP_CONCAT(COALESCE(NULLIF(i2.subject, ''), i2.invoice_no)
                                          ORDER BY i2.invoice_date SEPARATOR ', ')
                       FROM payment_allocations pa2
                       JOIN invoices i2 ON i2.id = pa2.invoice_id
                      WHERE pa2.payment_id = p.id) AS towards_label
             FROM payments p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN accounts acc ON acc.id = p.account_id
             WHERE {$whereSql}
             ORDER BY p.payment_date DESC, p.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $summary = DB::all(
            "SELECT p.currency, COALESCE(SUM(p.amount),0) AS received,
                    COALESCE(SUM(p.unallocated_amount),0) AS advance_held
             FROM payments p WHERE {$whereSql} GROUP BY p.currency",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, ['by_currency' => $summary]);
    }

    public static function show(string $id): void
    {
        Auth::allow('payments.view');
        $pay = self::find((int) $id);
        $pay['allocations'] = DB::all(
            'SELECT pa.id, pa.amount, i.id AS invoice_id, i.invoice_no, i.subject,
                    i.invoice_date, i.total, i.due_amount
             FROM payment_allocations pa JOIN invoices i ON i.id = pa.invoice_id
             WHERE pa.payment_id = ? ORDER BY pa.id ASC',
            [(int) $id]
        );
        Response::ok($pay);
    }

    /**
     * Record a payment.
     *  - allocations: [{invoice_id, amount}]  -> manual
     *  - auto_allocate: true                  -> oldest invoice first
     *  - neither                              -> stays as advance
     */

    /**
     * What this customer still owes, invoice by invoice, so a payment can be
     * put against the right work rather than guessed at.
     */
    public static function openInvoices(string $customerId): void
    {
        Auth::allow('payments.view');

        $rows = DB::all(
            "SELECT i.id, i.invoice_no, i.subject, i.invoice_date, i.due_date,
                    i.total, i.paid_amount, i.due_amount, i.status,
                    DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
                    (SELECT j.title FROM jobs j WHERE j.id = i.job_id) AS job_title
             FROM invoices i
             WHERE i.customer_id = ? AND i.deleted_at IS NULL
               AND i.due_amount > 0 AND i.status NOT IN ('draft','cancelled')
             ORDER BY i.due_date ASC, i.invoice_date ASC",
            [(int) $customerId]
        );

        Response::ok([
            'invoices'  => $rows,
            'total_due' => Helper::money(array_sum(
                array_map(static fn ($r) => (float) $r['due_amount'], $rows)
            )),
            'advance_held' => Helper::money((float) DB::value(
                'SELECT COALESCE(SUM(unallocated_amount),0) FROM payments
                 WHERE customer_id = ? AND deleted_at IS NULL',
                [(int) $customerId]
            )),
        ]);
    }

    public static function store(): void
    {
        Auth::allow('payments.entry');
        $in   = self::validateInput()->validate();
        $body = Validator::input();

        DB::begin();
        try {
            $customer = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL',
                [(int) $in['customer_id']]);
            if ($customer === null) {
                DB::rollback();
                Response::notFound('Customer not found');
            }

            $currency = Helper::baseCurrency();
            $date     = $in['payment_date'] ?? date('Y-m-d');
            $amount   = Helper::money((float) $in['amount']);

            $accountId = AccountController::requireUsable($in['account_id'] ?? null);

            $paymentId = DB::insert('payments', Helper::dropNulls([
                'receipt_no'         => Helper::docNumber('payment', $date),
                'customer_id'        => (int) $in['customer_id'],
                'payment_date'       => $date,
                'currency'           => $currency,
                'fx_rate'            => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
                'amount'             => $amount,
                'unallocated_amount' => $amount,
                'is_advance'         => 1,
                'method'             => $in['method'],
                'account_id'         => $accountId,
                'reference'          => $in['reference'],
                'account_name'       => $in['account_name'],
                'note'               => $in['note'],
                'created_by'         => Auth::userId(),
            ], ['currency','fx_rate','method','account_id']));

            $allocations = $body['allocations'] ?? null;
            if (is_array($allocations) && $allocations) {
                self::allocate($paymentId, $allocations, $currency, (int) $in['customer_id']);
            } elseif (!empty($body['auto_allocate'])) {
                Finance::autoAllocate($paymentId);
            } else {
                Finance::recalcPayment($paymentId);
            }

            // the cash book must move with the ledger, inside the same transaction
            if ($accountId !== null) {
                AccountController::post($accountId, 'in', $amount, 'payment', $paymentId,
                    'Received from customer #' . (int) $in['customer_id'], $date);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('payment', $paymentId, 'create');

        $payment = self::find($paymentId);

        // optional instant notification to the customer
        $notify = $body['notify'] ?? null;
        if (!empty($notify)) {
            $sent = Messenger::sendPaymentReceipt($paymentId, is_string($notify) ? $notify : 'whatsapp');
            $payment['notification'] = $sent;
        }

        Response::created($payment, 'Payment recorded');
    }

    public static function update(string $id): void
    {
        Auth::allow('payments.entry');
        self::find((int) $id);

        $v    = Validator::make()
            ->check('payment_date', 'nullable|date')
            ->check('method', 'nullable|string|max:60')
            ->check('account_id', 'nullable|int')
            ->check('reference', 'nullable|string|max:120')
            ->check('account_name', 'nullable|string|max:120')
            ->check('note', 'nullable|string|max:255');
        $data = Helper::dropNulls($v->present($v->validate()), ['payment_date','method','account_id']);

        if ($data) {
            DB::update('payments', $data, 'id = ?', [(int) $id]);

            // amount, date or account may have moved - rewrite the cash book
            $fresh = DB::one('SELECT * FROM payments WHERE id = ?', [(int) $id]);
            if ($fresh !== null) {
                $who = DB::value('SELECT name FROM customers WHERE id = ?', [(int) $fresh['customer_id']]);
                AccountController::repost(
                    'payment', (int) $id,
                    $fresh['account_id'] === null ? null : (int) $fresh['account_id'],
                    'in', (float) $fresh['amount'],
                    'Received from ' . ($who ?? 'customer'),
                    (string) $fresh['payment_date']
                );
            }
        }
        Response::ok(self::find((int) $id), 'Payment updated');
    }

    /** Delete a payment and roll back every invoice it touched. */
    public static function destroy(string $id): void
    {
        Auth::allow('payments.delete');
        self::find((int) $id);

        DB::begin();
        try {
            $invoiceIds = array_column(
                DB::all('SELECT invoice_id FROM payment_allocations WHERE payment_id = ?', [(int) $id]),
                'invoice_id'
            );
            DB::run('DELETE FROM payment_allocations WHERE payment_id = ?', [(int) $id]);
            AccountController::unpost('payment', (int) $id);
        DB::update('payments', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);

            foreach ($invoiceIds as $invId) {
                Finance::recalcInvoice((int) $invId);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('payment', (int) $id, 'delete');
        Response::ok(null, 'Payment deleted and invoices adjusted');
    }

    /** Apply an existing advance to invoices later. */
    public static function applyAdvance(string $id): void
    {
        Auth::allow('payments.entry');
        $pay  = self::find((int) $id);
        $body = Validator::input();

        DB::begin();
        try {
            if (!empty($body['allocations']) && is_array($body['allocations'])) {
                self::allocate((int) $id, $body['allocations'], (string) $pay['currency'], (int) $pay['customer_id']);
            } else {
                Finance::autoAllocate((int) $id);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Response::ok(self::find((int) $id), 'Advance applied');
    }

    /** Every customer who still owes money. */
    public static function dueList(): void
    {
        Auth::allow('payments.view');
        $params  = [];

        $rows = DB::all(
            "SELECT c.id AS customer_id, c.code, c.name, c.company_name, c.phone, c.whatsapp,
                    i.currency,
                    COUNT(i.id)                     AS invoice_count,
                    COALESCE(SUM(i.total),0)        AS invoiced,
                    COALESCE(SUM(i.paid_amount),0)  AS paid,
                    COALESCE(SUM(i.due_amount),0)   AS due,
                    MIN(i.due_date)                 AS oldest_due_date,
                    DATEDIFF(CURDATE(), MIN(i.due_date)) AS days_overdue
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
               AND i.due_amount > 0
             GROUP BY c.id, i.currency
             ORDER BY due DESC",
            $params
        );

        $totals = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $totals[$cur] = Helper::money(($totals[$cur] ?? 0) + (float) $r['due']);
        }

        Response::ok(['rows' => $rows, 'totals_by_currency' => $totals]);
    }

    /** Money received list (জমা তালিকা). */
    public static function receivedList(): void
    {
        Auth::allow('payments.view');
        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));
        $params  = [$from, $to];

        $rows = DB::all(
            "SELECT p.id, p.receipt_no, p.payment_date, p.currency, p.amount, p.method, p.reference,
                    c.id AS customer_id, c.name AS customer_name, c.company_name
             FROM payments p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN accounts acc ON acc.id = p.account_id
             WHERE p.deleted_at IS NULL AND p.payment_date BETWEEN ? AND ?
             ORDER BY p.payment_date DESC, p.id DESC",
            $params
        );
        $totals = DB::all(
            "SELECT p.currency, COALESCE(SUM(p.amount),0) AS total
             FROM payments p
             WHERE p.deleted_at IS NULL AND p.payment_date BETWEEN ? AND ?
             GROUP BY p.currency",
            $params
        );

        Response::ok(['from' => $from, 'to' => $to, 'rows' => $rows, 'totals_by_currency' => $totals]);
    }

    // ---------------- internals ----------------

    private static function allocate(int $paymentId, array $allocations, string $currency, int $customerId): void
    {
        foreach ($allocations as $a) {
            if (!is_array($a) || empty($a['invoice_id'])) {
                continue;
            }
            // FOR UPDATE: locks the row for the rest of this transaction, so a
            // second payment landing on the same invoice at the same moment
            // waits and then re-reads the real due_amount, rather than both
            // requests checking a stale figure and jointly over-allocating.
            $invoice = DB::one(
                'SELECT * FROM invoices WHERE id = ? AND customer_id = ? AND deleted_at IS NULL FOR UPDATE',
                [(int) $a['invoice_id'], $customerId]
            );
            if ($invoice === null) {
                DB::rollback();
                Response::error('Invoice #' . $a['invoice_id'] . ' does not belong to this customer', 422);
            }
            if ((string) $invoice['currency'] !== $currency) {
                DB::rollback();
                Response::error('Invoice ' . $invoice['invoice_no'] . ' is in ' . $invoice['currency']
                    . ', the payment is in ' . $currency, 422);
            }
            if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
                DB::rollback();
                Response::error(
                    'Invoice ' . $invoice['invoice_no'] . ' is still a '
                    . $invoice['status'] . ' - send it to the customer before taking payment against it',
                    422
                );
            }

            $amount = Helper::money((float) ($a['amount'] ?? 0));
            if ($amount <= 0) {
                continue;
            }

            // Never put more against an invoice than it is still owed. Without
            // this a slip of the keyboard leaves the invoice showing a negative
            // balance, and every report that sums due amounts is then wrong.
            $stillOwed = Helper::money((float) $invoice['due_amount']);
            if ($amount > $stillOwed) {
                DB::rollback();
                Response::error(
                    'Invoice ' . $invoice['invoice_no'] . ' only has '
                    . Helper::formatMoney($stillOwed) . ' ' . $currency . ' left to pay, '
                    . 'but ' . Helper::formatMoney($amount) . ' was set against it',
                    422
                );
            }

            DB::insert('payment_allocations', [
                'payment_id' => $paymentId,
                'invoice_id' => (int) $invoice['id'],
                'amount'     => $amount,
            ]);
            Finance::recalcInvoice((int) $invoice['id']);
        }
        Finance::recalcPayment($paymentId);

        $pay = DB::one('SELECT amount, allocated_amount FROM payments WHERE id = ?', [$paymentId]);
        if ($pay !== null && (float) $pay['allocated_amount'] > (float) $pay['amount'] + 0.004) {
            DB::rollback();
            Response::error('Allocated amount is more than the payment amount', 422);
        }
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT p.*, acc.name AS account_label, c.name AS customer_name, c.company_name, c.whatsapp AS customer_whatsapp
             FROM payments p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN accounts acc ON acc.id = p.account_id
             WHERE p.id = ? AND p.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Payment not found');
        }
        return $row;
    }

    private static function validateInput(): Validator
    {
        return Validator::make()
            ->check('customer_id', 'required|int|exists:customers', 'Customer')
            ->check('amount', 'required|number|min:0.01', 'Amount')
            ->check('payment_date', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('method', 'nullable|string|max:60')
            ->check('account_id', 'nullable|int')
            ->check('reference', 'nullable|string|max:120')
            ->check('account_name', 'nullable|string|max:120')
            ->check('note', 'nullable|string|max:255');
    }
}
