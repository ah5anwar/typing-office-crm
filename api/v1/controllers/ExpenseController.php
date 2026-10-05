<?php
/**
 * AH5 Office - Office expenses & other income
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class ExpenseController
{
    public static function categories(): void
    {
        Auth::allow('expenses.view');
        $kind = in_array((string) ($_GET['kind'] ?? ''), ['expense', 'income'], true) ? (string) $_GET['kind'] : null;
        $all = isset($_GET['all']) && $_GET['all'] === '1';
        $where = $all ? '1=1' : 'c.is_active = 1';
        $params = [];
        if ($kind !== null) {
            $where .= ' AND c.kind = ?';
            $params[] = $kind;
        }
        $rows = DB::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM expenses e
                      WHERE e.category_id = c.id AND e.deleted_at IS NULL) AS entry_count,
                    (SELECT COALESCE(SUM(e.amount),0) FROM expenses e
                      WHERE e.category_id = c.id AND e.deleted_at IS NULL) AS total_amount
             FROM expense_categories c
             WHERE {$where}
             ORDER BY c.kind ASC, c.name ASC",
            $params
        );
        Response::ok($rows);
    }

    public static function storeCategory(): void
    {
        Auth::allow('expenses.entry');
        $in = Validator::make()
            ->check('name', 'required|string|max:120', 'Category name')
            ->check('kind', 'nullable|in:expense,income')
            ->validate();

        $kind = (string) ($in['kind'] ?? 'expense');
        if (DB::value('SELECT id FROM expense_categories WHERE name = ? AND kind = ?', [$in['name'], $kind]) !== null) {
            Response::error('This category already exists', 422);
        }
        $id = DB::insert('expense_categories', ['name' => $in['name'], 'kind' => $kind]);
        Response::created(DB::one('SELECT * FROM expense_categories WHERE id = ?', [$id]), 'Category added');
    }

    public static function updateCategory(string $id): void
    {
        Auth::allow('expenses.entry');

        $v = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('kind', 'nullable|in:expense,income')
            ->check('is_active', 'nullable|bool');
        $data = Helper::dropNulls($v->present($v->validate()), ['name', 'kind', 'is_active']);

        if ($data) {
            DB::update('expense_categories', $data, 'id = ?', [(int) $id]);
        }
        $row = DB::one('SELECT * FROM expense_categories WHERE id = ?', [(int) $id]);
        if ($row === null) {
            Response::notFound('Category not found');
        }
        Response::ok($row, 'Category updated');
    }

    public static function destroyCategory(string $id): void
    {
        Auth::allow('expenses.delete');

        $used = (int) DB::value('SELECT COUNT(*) FROM expenses WHERE category_id = ? AND deleted_at IS NULL',
            [(int) $id]);
        if ($used > 0) {
            DB::update('expense_categories', ['is_active' => 0], 'id = ?', [(int) $id]);
            Response::ok(null, $used . ' entr(ies) use this category, so it was switched off instead of removed');
        }

        DB::run('DELETE FROM expense_categories WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Category removed');
    }

    public static function index(): void
    {
        Auth::allow('expenses.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['e.deleted_at IS NULL'];
        $params = [];
        if (!empty($_GET['kind'])) {
            $where[]  = 'e.kind = ?';
            $params[] = (string) $_GET['kind'];
        }
        if (!empty($_GET['category_id'])) {
            $where[]  = 'e.category_id = ?';
            $params[] = (int) $_GET['category_id'];
        }
        if (($from = Helper::parseDate($_GET['from'] ?? null)) !== null) {
            $where[]  = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if (($to = Helper::parseDate($_GET['to'] ?? null)) !== null) {
            $where[]  = 'e.entry_date <= ?';
            $params[] = $to;
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(e.title LIKE ? OR e.paid_to LIKE ? OR e.reference LIKE ?)';
            array_push($params, $q, $q, $q);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM expenses e WHERE {$whereSql}", $params);
        $rows  = DB::all(
            "SELECT e.*, acc.name AS account_label, cat.name AS category_name, s.name AS supplier_name,
                    (SELECT COUNT(*) FROM attachments a
                      WHERE a.entity_type = 'expense' AND a.entity_id = e.id) AS file_count
             FROM expenses e
             LEFT JOIN accounts acc ON acc.id = e.account_id
             LEFT JOIN expense_categories cat ON cat.id = e.category_id
             LEFT JOIN suppliers s ON s.id = e.supplier_id
             WHERE {$whereSql}
             ORDER BY e.entry_date DESC, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $summary = DB::all(
            "SELECT e.currency, e.kind, COALESCE(SUM(e.amount),0) AS total
             FROM expenses e WHERE {$whereSql} GROUP BY e.currency, e.kind",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, ['by_currency' => $summary]);
    }

    public static function store(): void
    {
        Auth::allow('expenses.entry');
        $in = self::validateInput()->validate();

        $currency = strtoupper((string) ($in['currency'] ?? BASE_CURRENCY));
        $date     = $in['entry_date'] ?? date('Y-m-d');

        $accountId = AccountController::requireUsable($in['account_id'] ?? null);

        $id = DB::insert('expenses', Helper::dropNulls([
            'category_id' => $in['category_id'],
            'kind'        => $in['kind'],
            'entry_date'  => $date,
            'title'       => $in['title'],
            'currency'    => $currency,
            'fx_rate'     => $in['fx_rate'] ?? Helper::fxRate($currency, $date),
            'amount'      => Helper::money((float) $in['amount']),
            'method'      => $in['method'],
            'account_id'  => $accountId,
            'paid_to'     => $in['paid_to'],
            'supplier_id' => $in['supplier_id'],
            'reference'   => $in['reference'],
            'note'        => $in['note'],
            'created_by'  => Auth::userId(),
        ], ['kind','entry_date','currency','fx_rate','method','account_id']));

        if ($accountId !== null) {
            $kind = (string) ($in['kind'] ?? 'expense');
            AccountController::post(
                $accountId,
                $kind === 'income' ? 'in' : 'out',
                (float) $in['amount'],
                $kind === 'income' ? 'income' : 'expense',
                $id,
                (string) $in['title'],
                $in['entry_date'] ?? date('Y-m-d')
            );
        }

        Helper::logActivity('expense', $id, 'create', (string) $in['title']);
        Response::created(self::find($id), 'Entry saved');
    }

    public static function update(string $id): void
    {
        Auth::allow('expenses.entry');
        self::find((int) $id);

        $v     = self::validateInput(false);
        $clean = $v->validate();
        if (array_key_exists('amount', $clean) && $clean['amount'] !== null) {
            $clean['amount'] = Helper::money((float) $clean['amount']);
        }
        $data = Helper::dropNulls($v->present([
            'category_id' => $clean['category_id'] ?? null,
            'kind'        => $clean['kind'] ?? null,
            'entry_date'  => $clean['entry_date'] ?? null,
            'title'       => $clean['title'] ?? null,
            'currency'    => $clean['currency'] ?? null,
            'fx_rate'     => $clean['fx_rate'] ?? null,
            'amount'      => $clean['amount'] ?? null,
            'method'      => $clean['method'] ?? null,
            'account_id'  => $clean['account_id'] ?? null,
            'paid_to'     => $clean['paid_to'] ?? null,
            'supplier_id' => $clean['supplier_id'] ?? null,
            'reference'   => $clean['reference'] ?? null,
            'note'        => $clean['note'] ?? null,
        ]), ['kind','entry_date','title','currency','fx_rate','amount','method','account_id']);

        if ($data) {
            DB::update('expenses', $data, 'id = ?', [(int) $id]);

            // the amount, date or account may have changed - rewrite the cash book
            $fresh = DB::one('SELECT * FROM expenses WHERE id = ?', [(int) $id]);
            if ($fresh !== null) {
                $kind = (string) $fresh['kind'];
                AccountController::unpost($kind === 'income' ? 'expense' : 'income', (int) $id);
                AccountController::repost(
                    $kind === 'income' ? 'income' : 'expense', (int) $id,
                    $fresh['account_id'] === null ? null : (int) $fresh['account_id'],
                    $kind === 'income' ? 'in' : 'out',
                    (float) $fresh['amount'],
                    (string) $fresh['title'],
                    (string) $fresh['entry_date']
                );
            }
        }
        Response::ok(self::find((int) $id), 'Entry updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('expenses.delete');
        self::find((int) $id);
        AccountController::unpost('expense', (int) $id);
        AccountController::unpost('income', (int) $id);
        DB::update('expenses', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Response::ok(null, 'Entry deleted');
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT e.*, acc.name AS account_label, cat.name AS category_name FROM expenses e
             LEFT JOIN accounts acc ON acc.id = e.account_id
             LEFT JOIN expense_categories cat ON cat.id = e.category_id
             WHERE e.id = ? AND e.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Entry not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('title', $req . 'string|max:200', 'Title')
            ->check('amount', $req . 'number|min:0.01', 'Amount')
            ->check('kind', 'nullable|in:expense,income')
            ->check('category_id', 'nullable|int|exists:expense_categories')
            ->check('entry_date', 'nullable|date')
            ->check('currency', 'nullable|currency')
            ->check('fx_rate', 'nullable|number|min:0')
            ->check('method', 'nullable|string|max:60')
            ->check('account_id', 'nullable|int')
            ->check('paid_to', 'nullable|string|max:160')
            ->check('supplier_id', 'nullable|int|exists:suppliers')
            ->check('reference', 'nullable|string|max:120')
            ->check('note', 'nullable|string|max:5000');
    }
}
