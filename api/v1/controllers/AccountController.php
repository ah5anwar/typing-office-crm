<?php
/**
 * AH5 Office - accounts and the cash book
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * You name your own accounts: the cash box, a bank account, a bKash wallet.
 * Every payment, expense and supplier payment lands in one of them, so the
 * system can always answer the question a ledger exists to answer:
 * how much money do I have, and where is it.
 */

declare(strict_types=1);

final class AccountController
{
    public static function index(): void
    {
        Auth::allow('reports.view');

        $showAll = ($_GET['all'] ?? '') === '1';
        $rows = DB::all(
            'SELECT * FROM accounts' . ($showAll ? '' : ' WHERE is_active = 1')
            . ' ORDER BY is_active DESC, sort_order ASC, name ASC'
        );

        $totals = [];
        foreach ($rows as &$a) {
            $a['balance'] = self::balance((int) $a['id']);
            $a['last_entry'] = DB::value(
                'SELECT MAX(entry_date) FROM account_entries WHERE account_id = ?', [(int) $a['id']]
            );
            $a['entry_count'] = (int) DB::value(
                'SELECT COUNT(*) FROM account_entries WHERE account_id = ?', [(int) $a['id']]
            );
            if ((int) $a['is_active'] === 1) {
                $cur = (string) $a['currency'];
                $totals[$cur] = ($totals[$cur] ?? 0) + $a['balance'];
            }
        }
        unset($a);

        $byType = [];
        foreach ($rows as $a) {
            if ((int) $a['is_active'] !== 1) {
                continue;
            }
            $key = (string) $a['type'];
            $byType[$key] = ($byType[$key] ?? 0) + $a['balance'];
        }

        Response::ok([
            'accounts' => $rows,
            'totals'   => $totals,
            'by_type'  => $byType,
        ]);
    }

    public static function show(string $id): void
    {
        Auth::allow('reports.view');
        $account = self::find((int) $id);
        $account['balance'] = self::balance((int) $id);
        Response::ok($account);
    }

    public static function store(): void
    {
        Auth::allow('settings.manage');

        $in = Validator::make()
            ->check('name', 'required|string|max:120', 'Account name')
            ->check('type', 'nullable|in:cash,bank,mobile,card,other')
            ->check('account_number', 'nullable|string|max:60')
            ->check('bank_name', 'nullable|string|max:120')
            ->check('branch', 'nullable|string|max:120')
            ->check('currency', 'nullable|string|max:3')
            ->check('opening_balance', 'nullable|number')
            ->check('opening_date', 'nullable|date')
            ->check('is_default', 'nullable|bool')
            ->check('note', 'nullable|string|max:255')
            ->validate();

        if (DB::value('SELECT id FROM accounts WHERE name = ?', [$in['name']]) !== null) {
            Response::error('An account with that name already exists', 422);
        }

        $opening = (float) ($in['opening_balance'] ?? 0);
        $id = DB::insert('accounts', Helper::dropNulls([
            'name'            => $in['name'],
            'type'            => $in['type'] ?? 'cash',
            'account_number'  => $in['account_number'],
            'bank_name'       => $in['bank_name'],
            'branch'          => $in['branch'],
            'currency'        => Helper::baseCurrency(),
            'opening_balance' => $opening,
            'opening_date'    => $in['opening_date'] ?? date('Y-m-d'),
            'is_default'      => !empty($in['is_default']) ? 1 : 0,
            'note'            => $in['note'],
        ], ['type', 'currency', 'opening_balance', 'is_default']));

        if (!empty($in['is_default'])) {
            DB::run('UPDATE accounts SET is_default = 0 WHERE id <> ?', [$id]);
        }

        Helper::logActivity('account', $id, 'create', (string) $in['name']);
        Response::created(self::find($id), 'Account added');
    }

    public static function update(string $id): void
    {
        Auth::allow('settings.manage');
        self::find((int) $id);

        $v = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('type', 'nullable|in:cash,bank,mobile,card,other')
            ->check('account_number', 'nullable|string|max:60')
            ->check('bank_name', 'nullable|string|max:120')
            ->check('branch', 'nullable|string|max:120')
            ->check('opening_balance', 'nullable|number')
            ->check('opening_date', 'nullable|date')
            ->check('is_default', 'nullable|bool')
            ->check('is_active', 'nullable|bool')
            ->check('note', 'nullable|string|max:255');
        $data = Helper::dropNulls($v->present($v->validate()),
            ['name', 'type', 'opening_balance', 'is_default', 'is_active']);

        if ($data) {
            DB::update('accounts', $data, 'id = ?', [(int) $id]);
        }
        if (!empty($data['is_default'])) {
            DB::run('UPDATE accounts SET is_default = 0 WHERE id <> ?', [(int) $id]);
        }

        Response::ok(self::find((int) $id), 'Account updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('settings.manage');
        self::find((int) $id);

        $used = (int) DB::value('SELECT COUNT(*) FROM account_entries WHERE account_id = ?', [(int) $id]);
        if ($used > 0) {
            DB::update('accounts', ['is_active' => 0], 'id = ?', [(int) $id]);
            Response::ok(null, $used . ' entr(ies) already sit in this account, so it was closed instead of removed');
        }

        DB::run('DELETE FROM accounts WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Account removed');
    }

    /** The account's own statement, with a running balance. */
    public static function statement(string $id): void
    {
        Auth::allow('reports.view');
        $account = self::find((int) $id);

        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01', strtotime('-2 month')));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));

        // everything before the window, folded into one opening figure
        $before = (float) DB::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
             FROM account_entries WHERE account_id = ? AND entry_date < ?",
            [(int) $id, $from]
        );
        $running = (float) $account['opening_balance'] + $before;
        $openingAt = $running;

        $rows = DB::all(
            'SELECT * FROM account_entries
             WHERE account_id = ? AND entry_date BETWEEN ? AND ?
             ORDER BY entry_date ASC, id ASC',
            [(int) $id, $from, $to]
        );

        $inTotal = 0.0;
        $outTotal = 0.0;
        foreach ($rows as &$r) {
            $amount = (float) $r['amount'];
            if ($r['direction'] === 'in') {
                $running += $amount;
                $inTotal += $amount;
            } else {
                $running -= $amount;
                $outTotal += $amount;
            }
            $r['running_balance'] = Helper::money($running);
            $r['party'] = self::partyFor($r);
        }
        unset($r);

        Response::ok([
            'account'         => $account,
            'from'            => $from,
            'to'              => $to,
            'opening_balance' => Helper::money($openingAt),
            'money_in'        => Helper::money($inTotal),
            'money_out'       => Helper::money($outTotal),
            'closing_balance' => Helper::money($running),
            'entries'         => $rows,
        ]);
    }

    /** Put money in or take it out by hand: an owner deposit, a withdrawal. */
    public static function adjust(string $id): void
    {
        Auth::allow('expenses.entry');
        $account = self::find((int) $id);

        $in = Validator::make()
            ->check('direction', 'required|in:in,out', 'Direction')
            ->check('amount', 'required|number|min:0.01', 'Amount')
            ->check('entry_date', 'nullable|date')
            ->check('description', 'nullable|string|max:255')
            ->validate();

        $entryId = self::post(
            (int) $id,
            (string) $in['direction'],
            (float) $in['amount'],
            'adjustment',
            null,
            (string) ($in['description'] ?? 'Manual entry'),
            $in['entry_date'] ?? date('Y-m-d')
        );

        Helper::logActivity('account', (int) $id, 'adjust',
            $in['direction'] . ' ' . Helper::money((float) $in['amount']));

        Response::created([
            'entry_id' => $entryId,
            'balance'  => self::balance((int) $id),
        ], 'Entry recorded');
    }

    /** Move money between two of your own accounts. */
    public static function transfer(): void
    {
        Auth::allow('expenses.entry');

        $in = Validator::make()
            ->check('from_account_id', 'required|int', 'From account')
            ->check('to_account_id', 'required|int', 'To account')
            ->check('amount', 'required|number|min:0.01', 'Amount')
            ->check('entry_date', 'nullable|date')
            ->check('description', 'nullable|string|max:255')
            ->validate();

        $fromId = (int) $in['from_account_id'];
        $toId   = (int) $in['to_account_id'];

        if ($fromId === $toId) {
            Response::validation(['to_account_id' => ['Pick a different account to move the money into']]);
        }
        $from = self::find($fromId);
        $to   = self::find($toId);

        $amount = (float) $in['amount'];
        $date   = $in['entry_date'] ?? date('Y-m-d');
        $group  = 'TRF' . date('ymdHis');
        $note   = (string) ($in['description'] ?? '');

        DB::transaction(static function () use ($fromId, $toId, $amount, $date, $group, $note, $from, $to): void {
            self::post($fromId, 'out', $amount, 'transfer', null,
                $note !== '' ? $note : 'To ' . $to['name'], $date, $group);
            self::post($toId, 'in', $amount, 'transfer', null,
                $note !== '' ? $note : 'From ' . $from['name'], $date, $group);
        });

        Helper::logActivity('account', $fromId, 'transfer',
            Helper::money($amount) . ' to ' . $to['name']);

        Response::created([
            'from_balance' => self::balance($fromId),
            'to_balance'   => self::balance($toId),
        ], 'Moved ' . Helper::money($amount) . ' from ' . $from['name'] . ' to ' . $to['name']);
    }

    // ---------------- used by the money controllers ----------------

    /**
     * Record one movement. Called whenever a payment, expense or supplier
     * payment is saved, so the cash book never drifts from the ledgers.
     */
    public static function post(
        int $accountId,
        string $direction,
        float $amount,
        string $source,
        ?int $refId,
        string $description,
        ?string $date = null,
        ?string $transferGroup = null
    ): int {
        return DB::insert('account_entries', [
            'account_id'     => $accountId,
            'entry_date'     => $date ?? date('Y-m-d'),
            'direction'      => $direction,
            'amount'         => round($amount, 2),
            'currency'       => Helper::baseCurrency(),
            'source'         => $source,
            'ref_id'         => $refId,
            'transfer_group' => $transferGroup,
            'description'    => mb_substr($description, 0, 255),
            'created_by'     => Auth::userId(),
        ]);
    }

    /**
     * Rewrite the cash-book entry behind a record that was just edited.
     * Amount, date and account can all change, so the old line is replaced
     * rather than patched - the books can never drift from the ledger.
     */
    public static function repost(
        string $source,
        int $refId,
        ?int $accountId,
        string $direction,
        float $amount,
        string $description,
        ?string $date = null
    ): void {
        self::unpost($source, $refId);
        if ($accountId === null || $amount <= 0) {
            return;
        }
        self::post($accountId, $direction, $amount, $source, $refId, $description, $date);
    }

    /** Remove the cash-book entry behind a deleted payment or expense. */
    public static function unpost(string $source, int $refId): void
    {
        DB::run('DELETE FROM account_entries WHERE source = ? AND ref_id = ?', [$source, $refId]);
    }

    /** The account a money form should use when none was chosen. */
    public static function defaultId(): ?int
    {
        $id = DB::value('SELECT id FROM accounts WHERE is_default = 1 AND is_active = 1 LIMIT 1');
        if ($id === null) {
            $id = DB::value('SELECT id FROM accounts WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1');
        }
        return $id === null ? null : (int) $id;
    }

    /** Reject an account id that does not exist or is closed. */
    public static function requireUsable($accountId): ?int
    {
        if ($accountId === null || $accountId === '') {
            return self::defaultId();
        }
        $row = DB::one('SELECT id, is_active FROM accounts WHERE id = ?', [(int) $accountId]);
        if ($row === null) {
            Response::validation(['account_id' => ['That account does not exist']]);
        }
        if ((int) $row['is_active'] !== 1) {
            Response::validation(['account_id' => ['That account is closed']]);
        }
        return (int) $row['id'];
    }

    public static function balance(int $accountId): float
    {
        $opening = (float) DB::value('SELECT opening_balance FROM accounts WHERE id = ?', [$accountId]);
        $moved = (float) DB::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
             FROM account_entries WHERE account_id = ?",
            [$accountId]
        );
        return Helper::money($opening + $moved);
    }

    // ---------------- internals ----------------

    private static function find(int $id): array
    {
        $row = DB::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if ($row === null) {
            Response::notFound('Account not found');
        }
        return $row;
    }

    /** Who the entry was with, so the statement reads like a bank statement. */
    private static function partyFor(array $entry): ?string
    {
        $refId = $entry['ref_id'] === null ? null : (int) $entry['ref_id'];
        if ($refId === null) {
            return null;
        }
        return match ((string) $entry['source']) {
            'payment' => DB::value(
                'SELECT c.name FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$refId]),
            'supplier_payment' => DB::value(
                'SELECT s.name FROM supplier_payments sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.id = ?', [$refId]),
            'expense', 'income' => DB::value('SELECT title FROM expenses WHERE id = ?', [$refId]),
            default => null,
        };
    }
}
