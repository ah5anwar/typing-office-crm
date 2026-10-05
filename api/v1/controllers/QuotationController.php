<?php
/**
 * AH5 Office - Quotations (+ convert to invoice)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class QuotationController
{
    public static function index(): void
    {
        Auth::allow('quotations.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['q.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['customer_id'])) {
            $where[]  = 'q.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        if (!empty($_GET['status'])) {
            $where[]  = 'q.status = ?';
            $params[] = (string) $_GET['status'];
        }
        if (!empty($_GET['q'])) {
            $s = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(q.quote_no LIKE ? OR q.subject LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $s, $s, $s, $s);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            "SELECT COUNT(*) FROM quotations q JOIN customers c ON c.id = q.customer_id WHERE {$whereSql}",
            $params
        );
        $rows = DB::all(
            "SELECT q.*, c.name AS customer_name, c.company_name,
                    DATEDIFF(q.valid_until, CURDATE()) AS days_valid
             FROM quotations q JOIN customers c ON c.id = q.customer_id
             WHERE {$whereSql}
             ORDER BY q.quote_date DESC, q.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function show(string $id): void
    {
        Auth::allow('quotations.view');
        $q = self::find((int) $id);
        $q['items'] = DB::all(
            'SELECT qi.*, s.name AS service_name
             FROM quotation_items qi LEFT JOIN services s ON s.id = qi.service_id
             WHERE qi.quotation_id = ? ORDER BY qi.sort_order ASC, qi.id ASC',
            [(int) $id]
        );
        Response::ok($q);
    }

    public static function store(): void
    {
        Auth::allow('quotations.edit');
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

            $currency = Helper::baseCurrency();
            $date     = $in['quote_date'] ?? date('Y-m-d');

            $quoteId = DB::insert('quotations', Helper::dropNulls([
                'quote_no'       => Helper::docNumber('quotation', $date),
                'customer_id'    => (int) $in['customer_id'],
                'quote_date'     => $date,
                'valid_until'    => $in['valid_until'] ?? date('Y-m-d', strtotime($date . ' +15 day')),
                'currency'       => $currency,
                'fx_rate'        => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
                'discount_type'  => $in['discount_type'],
                'discount_value' => $in['discount_value'],
                'vat_percent'    => $in['vat_percent'],
                'status'         => $in['status'] ?? 'draft',
                'subject'        => $in['subject'],
                'terms'          => $in['terms'],
                'notes'          => $in['notes'],
                'created_by'     => Auth::userId(),
            ], ['currency','fx_rate','discount_type','discount_value','vat_percent','status']));

            self::syncItems($quoteId, is_array($items) ? $items : [], $currency);
            self::recalc($quoteId);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('quotation', $quoteId, 'create');
        Response::created(self::find($quoteId), 'Quotation created');
    }

    public static function update(string $id): void
    {
        Auth::allow('quotations.edit');
        $quote = self::find((int) $id);
        if ($quote['status'] === 'converted') {
            Response::error('This quotation is already converted to an invoice', 422);
        }

        $v     = self::validateInput(false);
        $clean = $v->validate();
        $data  = Helper::dropNulls($v->present([
            'quote_date'     => $clean['quote_date'] ?? null,
            'valid_until'    => $clean['valid_until'] ?? null,
            'currency'       => $clean['currency'] ?? null,
            'fx_rate'        => $clean['fx_rate'] ?? null,
            'discount_type'  => $clean['discount_type'] ?? null,
            'discount_value' => $clean['discount_value'] ?? null,
            'vat_percent'    => $clean['vat_percent'] ?? null,
            'status'         => $clean['status'] ?? null,
            'subject'        => $clean['subject'] ?? null,
            'terms'          => $clean['terms'] ?? null,
            'notes'          => $clean['notes'] ?? null,
        ]), ['quote_date','currency','fx_rate','discount_type','discount_value','vat_percent','status']);

        $items = Validator::input()['items'] ?? null;

        DB::begin();
        try {
            if ($data) {
                DB::update('quotations', $data, 'id = ?', [(int) $id]);
            }
            if (is_array($items)) {
                self::syncItems((int) $id, $items, strtoupper((string) ($data['currency'] ?? $quote['currency'])));
            }
            self::recalc((int) $id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Response::ok(self::find((int) $id), 'Quotation updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('quotations.delete');
        $q = self::find((int) $id);
        if ($q['status'] === 'converted') {
            Response::error('A converted quotation cannot be deleted', 422);
        }
        DB::update('quotations', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Response::ok(null, 'Quotation deleted');
    }

    /** Turn an accepted quotation into an invoice, copying every line. */
    public static function convert(string $id): void
    {
        Auth::allow('invoices.edit');
        $quote = self::find((int) $id);

        if ($quote['status'] === 'converted' && !empty($quote['converted_invoice_id'])) {
            Response::error('Already converted to invoice #' . $quote['converted_invoice_id'], 422);
        }

        $items = DB::all('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC',
            [(int) $id]);
        if (!$items) {
            Response::error('This quotation has no items', 422);
        }

        DB::begin();
        try {
            $date    = date('Y-m-d');
            $dueDays = (int) DB::setting('invoice_due_days', '7');

            $invoiceId = DB::insert('invoices', [
                'invoice_no'     => Helper::docNumber('invoice', $date),
                'customer_id'    => (int) $quote['customer_id'],
                'quotation_id'   => (int) $id,
                'invoice_date'   => $date,
                'due_date'       => date('Y-m-d', strtotime($date . ' +' . $dueDays . ' day')),
                'currency'       => (string) $quote['currency'],
                'fx_rate'        => (float) $quote['fx_rate'],
                'discount_type'  => (string) $quote['discount_type'],
                'discount_value' => (float) $quote['discount_value'],
                'vat_percent'    => (float) $quote['vat_percent'],
                'status'         => 'draft',
                'subject'        => $quote['subject'],
                'terms'          => $quote['terms'],
                'created_by'     => Auth::userId(),
            ]);

            foreach ($items as $it) {
                DB::insert('invoice_items', [
                    'invoice_id'  => $invoiceId,
                    'service_id'  => $it['service_id'],
                    'description' => $it['description'],
                    'qty'         => (float) $it['qty'],
                    'unit'        => $it['unit'],
                    'unit_price'  => (float) $it['unit_price'],
                    'cost_price'  => (float) $it['cost_price'],
                    'line_total'  => (float) $it['line_total'],
                    'sort_order'  => (int) $it['sort_order'],
                ]);
            }

            Finance::recalcInvoice($invoiceId);
            DB::update('quotations', ['status' => 'converted', 'converted_invoice_id' => $invoiceId],
                'id = ?', [(int) $id]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        Helper::logActivity('quotation', (int) $id, 'convert', 'invoice #' . $invoiceId);
        Response::created(DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]), 'Quotation converted to invoice');
    }

    // ---------------- internals ----------------

    public static function recalc(int $quoteId): void
    {
        $q = DB::one('SELECT * FROM quotations WHERE id = ?', [$quoteId]);
        if ($q === null) {
            return;
        }
        $subtotal = Helper::money((float) DB::value(
            'SELECT COALESCE(SUM(line_total),0) FROM quotation_items WHERE quotation_id = ?', [$quoteId]
        ));
        $discount = Finance::discountAmount($subtotal, (string) $q['discount_type'], (float) $q['discount_value']);
        $after    = Helper::money($subtotal - $discount);
        $vat      = Helper::money($after * ((float) $q['vat_percent'] / 100));

        DB::update('quotations', [
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'vat_amount'      => $vat,
            'total'           => Helper::money($after + $vat),
        ], 'id = ?', [$quoteId]);
    }

    private static function syncItems(int $quoteId, array $items, string $currency): void
    {
        DB::run('DELETE FROM quotation_items WHERE quotation_id = ?', [$quoteId]);
        $sort = 0;

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
            [$sellCol, $costCol] = Helper::priceColumns($currency);
            $sell = array_key_exists('unit_price', $item) && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : ($service !== null ? (float) $service[$sellCol] : 0.0);
            $cost = array_key_exists('cost_price', $item) && $item['cost_price'] !== ''
                ? (float) $item['cost_price']
                : ($service !== null ? (float) $service[$costCol] : 0.0);

            // same rule as an invoice: a negative line would make the
            // quotation itself owe the customer money
            if ($qty < 0 || $sell < 0) {
                DB::rollback();
                Response::error('"' . $desc . '" has a negative quantity or price', 422);
            }

            DB::insert('quotation_items', [
                'quotation_id' => $quoteId,
                'service_id'   => $serviceId,
                'description'  => mb_substr($desc, 0, 255),
                'qty'          => $qty,
                'unit'         => isset($item['unit']) ? mb_substr((string) $item['unit'], 0, 40) : ($service['unit'] ?? null),
                'unit_price'   => Helper::money($sell),
                'cost_price'   => Helper::money($cost),
                'line_total'   => Helper::money($sell * $qty),
                'sort_order'   => $sort++,
            ]);
        }
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT q.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
                    c.whatsapp AS customer_whatsapp, c.address AS customer_address
             FROM quotations q JOIN customers c ON c.id = q.customer_id
             WHERE q.id = ? AND q.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Quotation not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('customer_id', $req . 'int|exists:customers', 'Customer')
            ->check('quote_date', 'nullable|date')
            ->check('valid_until', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('discount_type', 'nullable|in:none,flat,percent')
            ->check('discount_value', 'nullable|number|min:0')
            ->check('vat_percent', 'nullable|number|min:0|max:100')
            ->check('status', 'nullable|in:draft,sent,accepted,rejected,expired,converted')
            ->check('subject', 'nullable|string|max:200')
            ->check('terms', 'nullable|string|max:5000')
            ->check('notes', 'nullable|string|max:5000');
    }
}
