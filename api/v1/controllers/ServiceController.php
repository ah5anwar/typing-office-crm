<?php
/**
 * AH5 Office - Service catalogue endpoints (dual pricing: cost + sell)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class ServiceController
{
    private const NOT_NULL = ['name','unit','cost_primary','sell_primary',
        'is_recurring','has_expiry','show_in_list','sort_order','is_active'];

    // ---------------- Categories ----------------

    public static function categories(): void
    {
        Auth::allow('services.view');
        $rows = DB::all(
            'SELECT c.*, (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) AS service_count
             FROM service_categories c
             ORDER BY c.sort_order ASC, c.name ASC'
        );
        Response::ok($rows);
    }

    public static function storeCategory(): void
    {
        Auth::allow('services.edit');
        $in = Validator::make()
            ->check('name', 'required|string|max:120', 'Category name')
            ->check('sort_order', 'nullable|int')
            ->check('is_active', 'nullable|bool')
            ->validate();

        if (DB::value('SELECT id FROM service_categories WHERE name = ?', [$in['name']]) !== null) {
            Response::error('This category already exists', 422);
        }

        $id = DB::insert('service_categories', [
            'name'       => $in['name'],
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'is_active'  => $in['is_active'] ?? 1,
        ]);
        Response::created(DB::one('SELECT * FROM service_categories WHERE id = ?', [$id]), 'Category added');
    }

    public static function updateCategory(string $id): void
    {
        Auth::allow('services.edit');
        $v = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('sort_order', 'nullable|int')
            ->check('is_active', 'nullable|bool');
        $data = $v->present($v->validate());

        if ($data) {
            DB::update('service_categories', $data, 'id = ?', [(int) $id]);
        }
        $row = DB::one('SELECT * FROM service_categories WHERE id = ?', [(int) $id]);
        if ($row === null) {
            Response::notFound('Category not found');
        }
        Response::ok($row, 'Category updated');
    }

    public static function destroyCategory(string $id): void
    {
        Auth::allow('services.edit');
        DB::run('DELETE FROM service_categories WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Category deleted');
    }

    // ---------------- Services ----------------

    public static function index(): void
    {
        Auth::allow('services.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['1=1'];
        $params = [];

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(s.name LIKE ? OR s.description LIKE ?)';
            array_push($params, $q, $q);
        }
        if (!empty($_GET['category_id'])) {
            $where[]  = 's.category_id = ?';
            $params[] = (int) $_GET['category_id'];
        }
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
            $where[]  = 's.is_active = ?';
            $params[] = (int) $_GET['is_active'];
        }
        if (isset($_GET['show_in_list']) && $_GET['show_in_list'] !== '') {
            $where[]  = 's.show_in_list = ?';
            $params[] = (int) $_GET['show_in_list'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM services s WHERE {$whereSql}", $params);

        $rows = DB::all(
            "SELECT s.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM service_suppliers ss WHERE ss.service_id = s.id) AS supplier_count
             FROM services s
             LEFT JOIN service_categories c ON c.id = s.category_id
             WHERE {$whereSql}
             ORDER BY s.sort_order ASC, s.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$r) {
            $r['profit_primary']   = Helper::money((float) $r['sell_primary'] - (float) $r['cost_primary']);
        }
        unset($r);

        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function show(string $id): void
    {
        Auth::allow('services.view');
        $service = self::find((int) $id);

        $service['suppliers'] = DB::all(
            'SELECT ss.id AS link_id, ss.supplier_cost, ss.currency, ss.is_preferred, ss.lead_days, ss.note,
                    sp.id AS supplier_id, sp.code, sp.name, sp.company_name, sp.phone, sp.whatsapp
             FROM service_suppliers ss
             JOIN suppliers sp ON sp.id = ss.supplier_id
             WHERE ss.service_id = ? AND sp.deleted_at IS NULL
             ORDER BY ss.is_preferred DESC, sp.name ASC',
            [(int) $id]
        );
        $service['profit_primary']   = Helper::money((float) $service['sell_primary'] - (float) $service['cost_primary']);

        Response::ok($service);
    }

    public static function store(): void
    {
        Auth::allow('services.edit');
        $in = self::validateInput()->validate();

        $id = DB::insert('services', Helper::dropNulls(self::columns($in)));
        Helper::logActivity('service', $id, 'create', (string) $in['name']);

        Response::created(self::find($id), 'Service added');
    }

    public static function update(string $id): void
    {
        Auth::allow('services.edit');
        self::find((int) $id);

        $v    = self::validateInput(false);
        $data = Helper::dropNulls($v->present(self::columns($v->validate())), self::NOT_NULL);

        if ($data) {
            DB::update('services', $data, 'id = ?', [(int) $id]);
            Helper::logActivity('service', (int) $id, 'update');
        }
        Response::ok(self::find((int) $id), 'Service updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('services.edit');
        self::find((int) $id);

        $used = DB::value('SELECT id FROM invoice_items WHERE service_id = ? LIMIT 1', [(int) $id]);
        if ($used !== null) {
            DB::update('services', ['is_active' => 0], 'id = ?', [(int) $id]);
            Response::ok(null, 'Service is used in invoices, so it was deactivated instead of deleted');
        }

        DB::run('DELETE FROM services WHERE id = ?', [(int) $id]);
        Helper::logActivity('service', (int) $id, 'delete');
        Response::ok(null, 'Service deleted');
    }

    // ---------------- Service <-> Supplier ----------------

    public static function attachSupplier(string $id): void
    {
        Auth::allow('services.edit');
        self::find((int) $id);

        $in = Validator::make()
            ->check('supplier_id', 'required|int|exists:suppliers', 'Supplier')
            ->check('supplier_cost', 'nullable|number|min:0')
            ->check('currency', 'nullable|currency')
            ->check('is_preferred', 'nullable|bool')
            ->check('lead_days', 'nullable|int|min:0')
            ->check('note', 'nullable|string|max:255')
            ->validate();

        $exists = DB::value(
            'SELECT id FROM service_suppliers WHERE service_id = ? AND supplier_id = ?',
            [(int) $id, (int) $in['supplier_id']]
        );

        $data = [
            'supplier_cost' => Helper::money($in['supplier_cost'] ?? 0),
            'currency'      => $in['currency'] ?? BASE_CURRENCY,
            'is_preferred'  => $in['is_preferred'] ?? 0,
            'lead_days'     => $in['lead_days'],
            'note'          => $in['note'],
        ];
        $data = Helper::dropNulls($data, ['supplier_cost','currency','is_preferred']);

        if ($exists !== null) {
            DB::update('service_suppliers', $data, 'id = ?', [(int) $exists]);
        } else {
            $data['service_id']  = (int) $id;
            $data['supplier_id'] = (int) $in['supplier_id'];
            DB::insert('service_suppliers', $data);
        }

        // only one preferred supplier per service
        if ((int) ($in['is_preferred'] ?? 0) === 1) {
            DB::run(
                'UPDATE service_suppliers SET is_preferred = 0 WHERE service_id = ? AND supplier_id <> ?',
                [(int) $id, (int) $in['supplier_id']]
            );
        }

        Response::ok(null, 'Supplier linked to service');
    }

    public static function detachSupplier(string $id, string $supplierId): void
    {
        Auth::allow('services.edit');
        $n = DB::run(
            'DELETE FROM service_suppliers WHERE service_id = ? AND supplier_id = ?',
            [(int) $id, (int) $supplierId]
        )->rowCount();

        if ($n === 0) {
            Response::notFound('This supplier is not linked to the service');
        }
        Response::ok(null, 'Supplier unlinked');
    }

    // ---------------- helpers ----------------

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT s.*, c.name AS category_name
             FROM services s LEFT JOIN service_categories c ON c.id = s.category_id
             WHERE s.id = ?',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Service not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('name', $req . 'string|max:180', 'Service name')
            ->check('category_id', 'nullable|int|exists:service_categories')
            ->check('description', 'nullable|string|max:5000')
            ->check('unit', 'nullable|string|max:40')
            ->check('cost_primary', 'nullable|number|min:0')
            ->check('sell_primary', 'nullable|number|min:0')
            ->check('is_recurring', 'nullable|bool')
            ->check('recurring_months', 'nullable|int|min:1|max:120')
            ->check('has_expiry', 'nullable|bool')
            ->check('show_in_list', 'nullable|bool')
            ->check('sort_order', 'nullable|int')
            ->check('is_active', 'nullable|bool');
    }

    private static function columns(array $in): array
    {
        $allowed = [
            'name','category_id','description','unit',
            'cost_primary','sell_primary',
            'is_recurring','recurring_months','has_expiry','show_in_list','sort_order','is_active',
        ];
        $out = array_intersect_key($in, array_flip($allowed));
        foreach (['cost_primary','sell_primary'] as $f) {
            if (array_key_exists($f, $out)) {
                $out[$f] = Helper::money($out[$f] ?? 0);
            }
        }
        return $out;
    }
}
