<?php
/**
 * AH5 Office - shared money logic (totals, allocation, status)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class Finance
{
    /** Recalculate one invoice from its items + allocations. Returns the fresh row. */
    public static function recalcInvoice(int $invoiceId): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if ($inv === null) {
            throw new RuntimeException('Invoice not found: ' . $invoiceId);
        }

        $sum = DB::one(
            'SELECT COALESCE(SUM(line_total),0) AS subtotal,
                    COALESCE(SUM(cost_price * qty),0) AS total_cost
             FROM invoice_items WHERE invoice_id = ?',
            [$invoiceId]
        );
        $subtotal  = Helper::money($sum['subtotal'] ?? 0);
        $totalCost = Helper::money($sum['total_cost'] ?? 0);

        $discount = self::discountAmount(
            $subtotal,
            (string) $inv['discount_type'],
            (float) $inv['discount_value']
        );
        $afterDiscount = Helper::money($subtotal - $discount);
        $vat   = Helper::money($afterDiscount * ((float) $inv['vat_percent'] / 100));
        $total = Helper::money($afterDiscount + $vat);

        $paid = Helper::money((float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = ?',
            [$invoiceId]
        ));
        $due = Helper::money($total - $paid);

        $status = self::invoiceStatus((string) $inv['status'], $total, $paid, $due, $inv['due_date'] ?? null);

        DB::update('invoices', [
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'vat_amount'      => $vat,
            'total'           => $total,
            'total_cost'      => $totalCost,
            'paid_amount'     => $paid,
            'due_amount'      => $due,
            'status'          => $status,
        ], 'id = ?', [$invoiceId]);

        return DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]) ?? [];
    }

    /**
     * A discount can bring the bill to zero but never below it - a "500%
     * off" typo should not turn an invoice into money owed to the customer.
     * Both kinds are capped the same way: at what the lines actually add
     * up to, whatever the entered value says.
     */
    public static function discountAmount(float $subtotal, string $type, float $value): float
    {
        $value = max(0.0, $value);   // a negative discount would raise the total, not lower it

        if ($type === 'percent') {
            $value = min($value, 100.0);
            return Helper::money(min($subtotal * ($value / 100), $subtotal));
        }
        if ($type === 'flat') {
            return Helper::money(min($value, $subtotal));
        }
        return 0.0;
    }

    private static function invoiceStatus(string $current, float $total, float $paid, float $due, ?string $dueDate): string
    {
        if (in_array($current, ['draft', 'cancelled'], true)) {
            return $current;   // never auto-change these
        }
        if ($due <= 0.004 && $total > 0) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }
        if ($dueDate !== null && $dueDate < date('Y-m-d')) {
            return 'overdue';
        }
        return 'sent';
    }

    /** Recalculate one supplier bill from its allocations. */
    public static function recalcSupplierBill(int $billId): array
    {
        $bill = DB::one('SELECT * FROM supplier_bills WHERE id = ?', [$billId]);
        if ($bill === null) {
            throw new RuntimeException('Supplier bill not found: ' . $billId);
        }

        $paid = Helper::money((float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM supplier_payment_allocations WHERE supplier_bill_id = ?',
            [$billId]
        ));
        $amount = Helper::money((float) $bill['amount']);
        $due    = Helper::money($amount - $paid);

        $status = (string) $bill['status'];
        if ($status !== 'cancelled') {
            $status = $due <= 0.004 && $amount > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        }

        DB::update('supplier_bills', [
            'paid_amount' => $paid,
            'due_amount'  => $due,
            'status'      => $status,
        ], 'id = ?', [$billId]);

        return DB::one('SELECT * FROM supplier_bills WHERE id = ?', [$billId]) ?? [];
    }

    /** Refresh a payment's allocated / unallocated (advance) amounts. */
    public static function recalcPayment(int $paymentId): void
    {
        $pay = DB::one('SELECT amount FROM payments WHERE id = ?', [$paymentId]);
        if ($pay === null) {
            return;
        }
        $allocated = Helper::money((float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE payment_id = ?',
            [$paymentId]
        ));
        $amount = Helper::money((float) $pay['amount']);
        DB::update('payments', [
            'allocated_amount'   => $allocated,
            'unallocated_amount' => Helper::money($amount - $allocated),
            'is_advance'         => $allocated <= 0.004 ? 1 : 0,
        ], 'id = ?', [$paymentId]);
    }

    public static function recalcSupplierPayment(int $paymentId): void
    {
        $pay = DB::one('SELECT amount FROM supplier_payments WHERE id = ?', [$paymentId]);
        if ($pay === null) {
            return;
        }
        $allocated = Helper::money((float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM supplier_payment_allocations WHERE supplier_payment_id = ?',
            [$paymentId]
        ));
        $amount = Helper::money((float) $pay['amount']);
        DB::update('supplier_payments', [
            'allocated_amount'   => $allocated,
            'unallocated_amount' => Helper::money($amount - $allocated),
        ], 'id = ?', [$paymentId]);
    }

    /**
     * Spread a payment across unpaid invoices, oldest first.
     * Only invoices in the same currency are touched.
     */
    public static function autoAllocate(int $paymentId): float
    {
        $pay = DB::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($pay === null) {
            return 0.0;
        }
        $remaining = Helper::money((float) $pay['unallocated_amount']);
        if ($remaining <= 0.004) {
            return 0.0;
        }

        $invoices = DB::all(
            "SELECT id, due_amount FROM invoices
             WHERE customer_id = ? AND currency = ?
               AND deleted_at IS NULL AND status NOT IN ('draft','cancelled') AND due_amount > 0
             ORDER BY invoice_date ASC, id ASC",
            [(int) $pay['customer_id'], (string) $pay['currency']]
        );

        $used = 0.0;
        foreach ($invoices as $inv) {
            if ($remaining <= 0.004) {
                break;
            }
            $apply = min($remaining, Helper::money((float) $inv['due_amount']));
            if ($apply <= 0.004) {
                continue;
            }
            DB::insert('payment_allocations', [
                'payment_id' => $paymentId,
                'invoice_id' => (int) $inv['id'],
                'amount'     => $apply,
            ]);
            self::recalcInvoice((int) $inv['id']);
            $remaining = Helper::money($remaining - $apply);
            $used     += $apply;
        }

        self::recalcPayment($paymentId);
        return Helper::money($used);
    }

    /** Same idea for supplier payments against supplier bills. */
    public static function autoAllocateSupplier(int $paymentId): float
    {
        $pay = DB::one('SELECT * FROM supplier_payments WHERE id = ?', [$paymentId]);
        if ($pay === null) {
            return 0.0;
        }
        $remaining = Helper::money((float) $pay['unallocated_amount']);
        if ($remaining <= 0.004) {
            return 0.0;
        }

        $bills = DB::all(
            "SELECT id, due_amount FROM supplier_bills
             WHERE supplier_id = ? AND currency = ?
               AND deleted_at IS NULL AND status <> 'cancelled' AND due_amount > 0
             ORDER BY bill_date ASC, id ASC",
            [(int) $pay['supplier_id'], (string) $pay['currency']]
        );

        $used = 0.0;
        foreach ($bills as $b) {
            if ($remaining <= 0.004) {
                break;
            }
            $apply = min($remaining, Helper::money((float) $b['due_amount']));
            if ($apply <= 0.004) {
                continue;
            }
            DB::insert('supplier_payment_allocations', [
                'supplier_payment_id' => $paymentId,
                'supplier_bill_id'    => (int) $b['id'],
                'amount'              => $apply,
            ]);
            self::recalcSupplierBill((int) $b['id']);
            $remaining = Helper::money($remaining - $apply);
            $used     += $apply;
        }

        self::recalcSupplierPayment($paymentId);
        return Helper::money($used);
    }
}
