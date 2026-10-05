<?php
/**
 * AH5 Office - Supplier bills & payments (আমার কাছে কে কত পাবে)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class SupplierBillController
{
    // ---------------- Bills ----------------

    public static function index(): void
    {
        Auth::allow('supplier_bills.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['b.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['supplier_id'])) {
            $where[]  = 'b.supplier_id = ?';
            $params[] = (int) $_GET['supplier_id'];
        }
        if (!empty($_GET['status'])) {
            $where[]  = 'b.status = ?';
            $params[] = (string) $_GET['status'];
        }
        if (isset($_GET['unpaid']) && $_GET['unpaid'] === '1') {
            $where[] = 'b.due_amount > 0';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM supplier_bills b WHERE {$whereSql}", $params);
        $rows  = DB::all(
            "SELECT b.*, s.name AS supplier_name, s.company_name, s.whatsapp AS supplier_whatsapp,
                    j.job_no, j.title AS job_title
             FROM supplier_bills b
             JOIN suppliers s ON s.id = b.supplier_id
             LEFT JOIN jobs j ON j.id = b.job_id
             WHERE {$whereSql}
             ORDER BY b.bill_date DESC, b.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $summary = DB::all(
            "SELECT b.currency, COALESCE(SUM(b.amount),0) AS billed,
                    COALESCE(SUM(b.paid_amount),0) AS paid, COALESCE(SUM(b.due_amount),0) AS payable
             FROM supplier_bills b WHERE {$whereSql} GROUP BY b.currency",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, ['by_currency' => $summary]);
    }

    public static function show(string $id): void
    {
        Auth::allow('supplier_bills.view');
        $bill = self::findBill((int) $id);
        $bill['payments'] = DB::all(
            'SELECT sp.id, sp.voucher_no, sp.payment_date, sp.method, sp.reference, spa.amount
             FROM supplier_payment_allocations spa
             JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
             WHERE spa.supplier_bill_id = ? ORDER BY sp.payment_date ASC',
            [(int) $id]
        );
        Response::ok($bill);
    }

    public static function store(): void
    {
        Auth::allow('supplier_bills.entry');
        $in = Validator::make()
            ->check('supplier_id', 'required|int|exists:suppliers', 'Supplier')
            ->check('amount', 'required|number|min:0.01', 'Amount')
            ->check('job_id', 'nullable|int|exists:jobs')
            ->check('bill_date', 'nullable|date')
            ->check('due_date', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('description', 'nullable|string|max:255')
            ->check('note', 'nullable|string|max:5000')
            ->validate();

        $supplier = DB::one('SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL', [(int) $in['supplier_id']]);
        if ($supplier === null) {
            Response::notFound('Supplier not found');
        }

        $currency = Helper::baseCurrency();
        $date     = $in['bill_date'] ?? date('Y-m-d');
        $amount   = Helper::money((float) $in['amount']);

        DB::begin();
        try {
            $billId = DB::insert('supplier_bills', Helper::dropNulls([
                'bill_no'     => Helper::docNumber('supplier_bill', $date),
                'supplier_id' => (int) $in['supplier_id'],
                'job_id'      => $in['job_id'],
                'bill_date'   => $date,
                'due_date'    => $in['due_date'],
                'currency'    => $currency,
                'fx_rate'     => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
                'amount'      => $amount,
                'due_amount'  => $amount,
                'description' => $in['description'],
                'note'        => $in['note'],
            ], ['currency','fx_rate']));

            // link the assigned works this bill covers
            $assignIds = Validator::input()['assign_ids'] ?? [];
            if (is_array($assignIds)) {
                foreach ($assignIds as $aid) {
                    DB::update('job_supplier_assign',
                        ['is_billed' => 1, 'supplier_bill_id' => $billId],
                        'id = ? AND supplier_id = ?', [(int) $aid, (int) $in['supplier_id']]);
                }
            }

            Finance::recalcSupplierBill($billId);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('supplier_bill', $billId, 'create');
        Response::created(self::findBill($billId), 'Supplier bill added');
    }

    public static function destroyBill(string $id): void
    {
        Auth::allow('supplier_bills.delete');
        $bill = self::findBill((int) $id);
        if ((float) $bill['paid_amount'] > 0) {
            Response::error('This bill already has payments against it', 422);
        }
        DB::update('supplier_bills', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        DB::update('job_supplier_assign', ['is_billed' => 0, 'supplier_bill_id' => null],
            'supplier_bill_id = ?', [(int) $id]);
        Response::ok(null, 'Supplier bill deleted');
    }

    // ---------------- Payments to suppliers ----------------

    public static function payments(): void
    {
        Auth::allow('supplier_bills.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['p.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['supplier_id'])) {
            $where[]  = 'p.supplier_id = ?';
            $params[] = (int) $_GET['supplier_id'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM supplier_payments p WHERE {$whereSql}", $params);
        $rows  = DB::all(
            "SELECT p.*, s.name AS supplier_name FROM supplier_payments p
             JOIN suppliers s ON s.id = p.supplier_id
             WHERE {$whereSql} ORDER BY p.payment_date DESC, p.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function pay(): void
    {
        Auth::allow('supplier_bills.entry');
        $in   = Validator::make()
            ->check('supplier_id', 'required|int|exists:suppliers', 'Supplier')
            ->check('amount', 'required|number|min:0.01', 'Amount')
            ->check('payment_date', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('method', 'nullable|string|max:60')
            ->check('account_id', 'nullable|int')
            ->check('reference', 'nullable|string|max:120')
            ->check('note', 'nullable|string|max:255')
            ->validate();
        $body = Validator::input();

        $supplier = DB::one('SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL', [(int) $in['supplier_id']]);
        if ($supplier === null) {
            Response::notFound('Supplier not found');
        }

        $currency = Helper::baseCurrency();
        $date     = $in['payment_date'] ?? date('Y-m-d');
        $amount   = Helper::money((float) $in['amount']);

        DB::begin();
        try {
            $accountId = AccountController::requireUsable($in['account_id'] ?? null);

            $payId = DB::insert('supplier_payments', Helper::dropNulls([
                'voucher_no'         => Helper::docNumber('supplier_payment', $date),
                'supplier_id'        => (int) $in['supplier_id'],
                'payment_date'       => $date,
                'currency'           => $currency,
                'fx_rate'            => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
                'amount'             => $amount,
                'unallocated_amount' => $amount,
                'method'             => $in['method'],
                'account_id'         => $accountId,
                'reference'          => $in['reference'],
                'note'               => $in['note'],
                'created_by'         => Auth::userId(),
            ], ['currency','fx_rate','method','account_id']));

            if (!empty($body['allocations']) && is_array($body['allocations'])) {
                foreach ($body['allocations'] as $a) {
                    if (!is_array($a) || empty($a['supplier_bill_id'])) {
                        continue;
                    }
                    // same guard as the customer side: lock the row so a
                    // second concurrent payment re-reads the real due_amount
                    // instead of both racing against a stale figure
                    $bill = DB::one(
                        'SELECT * FROM supplier_bills WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL FOR UPDATE',
                        [(int) $a['supplier_bill_id'], (int) $in['supplier_id']]
                    );
                    if ($bill === null) {
                        DB::rollback();
                        Response::error('Bill #' . $a['supplier_bill_id'] . ' does not belong to this supplier', 422);
                    }
                    if ($bill['status'] === 'cancelled') {
                        DB::rollback();
                        Response::error('Bill ' . $bill['bill_no'] . ' was cancelled - it cannot take a payment', 422);
                    }
                    $amt = Helper::money((float) ($a['amount'] ?? 0));
                    if ($amt <= 0) {
                        continue;
                    }

                    // the same guard as on the customer side: paying more
                    // against a bill than it owes leaves a negative balance
                    $stillOwed = Helper::money((float) $bill['due_amount']);
                    if ($amt > $stillOwed) {
                        DB::rollback();
                        Response::error(
                            'Bill ' . $bill['bill_no'] . ' only has '
                            . Helper::formatMoney($stillOwed) . ' left to pay, but '
                            . Helper::formatMoney($amt) . ' was set against it',
                            422
                        );
                    }

                    DB::insert('supplier_payment_allocations', [
                        'supplier_payment_id' => $payId,
                        'supplier_bill_id'    => (int) $bill['id'],
                        'amount'              => $amt,
                    ]);
                    Finance::recalcSupplierBill((int) $bill['id']);
                }
                Finance::recalcSupplierPayment($payId);
            } else {
                Finance::autoAllocateSupplier($payId);
            }

            if ($accountId !== null) {
                AccountController::post($accountId, 'out', $amount, 'supplier_payment', $payId,
                    'Paid to supplier #' . (int) $in['supplier_id'], $date);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('supplier_payment', $payId, 'create');
        $payment = DB::one('SELECT * FROM supplier_payments WHERE id = ?', [$payId]);

        // notify the supplier
        if (!empty($body['notify'])) {
            $channel = is_string($body['notify']) ? $body['notify'] : 'whatsapp';
            $tpl     = Messenger::template('supplier_payment', $channel);
            $text    = Helper::renderTemplate(
                $tpl['body'] ?? 'Payment {{amount}} {{currency}} sent. Ref: {{reference}}',
                [
                    'supplier_name' => $supplier['name'],
                    'amount'        => number_format($amount, 2),
                    'currency'      => $currency,
                    'reference'     => (string) ($in['reference'] ?? $payment['voucher_no']),
                ]
            );
            $to = Messenger::recipientFor($supplier, $channel);
            if ($to !== null) {
                $res = Messenger::send($channel, $to, $text, [
                    'party_type'        => 'supplier',
                    'party_id'          => (int) $supplier['id'],
                    'template_name'     => $tpl['wa_template_name'] ?? null,
                    'template_language' => $tpl['wa_language'] ?? 'en',
                    'template_params'   => [$supplier['name'], number_format($amount, 2), $currency],
                ]);
                if ($res['success']) {
                    DB::update('supplier_payments', ['notify_sent' => 1], 'id = ?', [$payId]);
                }
                $payment['notification'] = $res;
            } else {
                $payment['notification'] = ['success' => false, 'error' => 'Supplier has no ' . $channel . ' address'];
            }
        }

        Response::created($payment, 'Supplier payment recorded');
    }

    public static function destroyPayment(string $id): void
    {
        Auth::allow('supplier_bills.delete');
        $pay = DB::one('SELECT * FROM supplier_payments WHERE id = ? AND deleted_at IS NULL', [(int) $id]);
        if ($pay === null) {
            Response::notFound('Supplier payment not found');
        }

        DB::begin();
        try {
            $billIds = array_column(
                DB::all('SELECT supplier_bill_id FROM supplier_payment_allocations WHERE supplier_payment_id = ?', [(int) $id]),
                'supplier_bill_id'
            );
            DB::run('DELETE FROM supplier_payment_allocations WHERE supplier_payment_id = ?', [(int) $id]);
            AccountController::unpost('supplier_payment', (int) $id);
        DB::update('supplier_payments', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
            foreach ($billIds as $b) {
                Finance::recalcSupplierBill((int) $b);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Response::ok(null, 'Supplier payment deleted');
    }

    /** কে কত টাকা পাবে - payable list. */
    public static function payableList(): void
    {
        Auth::allow('supplier_bills.view');
        $params  = [];

        $rows = DB::all(
            "SELECT s.id AS supplier_id, s.code, s.name, s.company_name, s.phone, s.whatsapp,
                    b.currency,
                    COUNT(b.id) AS bill_count,
                    COALESCE(SUM(b.amount),0) AS billed,
                    COALESCE(SUM(b.paid_amount),0) AS paid,
                    COALESCE(SUM(b.due_amount),0) AS payable,
                    MIN(b.due_date) AS oldest_due_date
             FROM supplier_bills b JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.due_amount > 0
             GROUP BY s.id, b.currency
             ORDER BY payable DESC",
            $params
        );

        $totals = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $totals[$cur] = Helper::money(($totals[$cur] ?? 0) + (float) $r['payable']);
        }

        Response::ok(['rows' => $rows, 'totals_by_currency' => $totals]);
    }

    private static function findBill(int $id): array
    {
        $row = DB::one(
            'SELECT b.*, s.name AS supplier_name, s.company_name, s.whatsapp AS supplier_whatsapp
             FROM supplier_bills b JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.id = ? AND b.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Supplier bill not found');
        }
        return $row;
    }
}
