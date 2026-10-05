<?php
/**
 * AH5 Office - Dashboard & reports (daily / monthly / yearly, receivable, payable)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Income  = customer payments actually received (cash basis)
 * Expense = office expenses + payments made to suppliers
 * Amounts are grouped per currency; a base-currency total uses each
 * document's stored fx_rate so old figures never move.
 */

declare(strict_types=1);

final class ReportController
{
    public static function dashboard(): void
    {
        Auth::require();

        $today      = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart  = date('Y-01-01');

        $receivable = DB::all(
            "SELECT currency, COALESCE(SUM(due_amount),0) AS amount,
                    COALESCE(SUM(due_amount * fx_rate),0) AS amount_base
             FROM invoices
             WHERE deleted_at IS NULL AND status NOT IN ('draft','cancelled') AND due_amount > 0
             GROUP BY currency",
            []
        );
        $payable = DB::all(
            "SELECT currency, COALESCE(SUM(due_amount),0) AS amount,
                    COALESCE(SUM(due_amount * fx_rate),0) AS amount_base
             FROM supplier_bills
             WHERE deleted_at IS NULL AND status <> 'cancelled' AND due_amount > 0
             GROUP BY currency",
            []
        );
        $advanceHeld = DB::all(
            "SELECT currency, COALESCE(SUM(unallocated_amount),0) AS amount
             FROM payments WHERE deleted_at IS NULL AND unallocated_amount > 0
             GROUP BY currency",
            []
        );

        $counts = [
            'customers'       => (int) DB::value('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL'),
            'suppliers'       => (int) DB::value('SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL'),
            'services'        => (int) DB::value('SELECT COUNT(*) FROM services WHERE is_active = 1'),
            'pending_jobs'    => (int) DB::value("SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND status IN ('pending','in_progress','on_hold')"),
            'overdue_invoices'=> (int) DB::value("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND due_amount > 0 AND due_date < CURDATE() AND status NOT IN ('draft','cancelled')"),
            'expiring_docs_30'=> (int) DB::value("SELECT COUNT(*) FROM customer_documents WHERE deleted_at IS NULL AND status = 'active' AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
            'queued_messages' => (int) DB::value("SELECT COUNT(*) FROM reminder_queue WHERE status = 'queued'"),
        ];

        if (!Auth::can('supplier_bills.view')) {
            $payable = [];
        }

        $today = date('Y-m-d');
        $attention = [
            'work_due' => DB::all(
                "SELECT ji.id, ji.description, j.job_no, j.due_date, c.name AS customer_name,
                        c.company_name, DATEDIFF(j.due_date, CURDATE()) AS days_to_due
                 FROM job_items ji
                 JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL
                 JOIN customers c ON c.id = j.customer_id
                 WHERE ji.status IN ('pending','in_progress')
                   AND j.due_date IS NOT NULL AND j.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                 ORDER BY j.due_date ASC LIMIT 8"
            ),
            'expiring' => DB::all(
                "SELECT d.id, d.title, d.expiry_date, c.name AS customer_name, c.company_name,
                        DATEDIFF(d.expiry_date, CURDATE()) AS days_left
                 FROM customer_documents d JOIN customers c ON c.id = d.customer_id
                 WHERE d.deleted_at IS NULL AND d.status = 'active'
                   AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY d.expiry_date ASC LIMIT 8"
            ),
            'overdue_invoices' => DB::all(
                "SELECT i.id, i.invoice_no, i.due_amount, i.currency, i.due_date,
                        DATEDIFF(CURDATE(), i.due_date) AS days_over,
                        c.name AS customer_name, c.company_name
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 WHERE i.deleted_at IS NULL AND i.due_amount > 0
                   AND i.due_date < CURDATE() AND i.status NOT IN ('draft','cancelled')
                 ORDER BY i.due_date ASC LIMIT 8"
            ),
        ];

        $accounts = DB::all(
            'SELECT id, name, type FROM accounts WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
        );
        $inHand = 0.0;
        foreach ($accounts as &$a) {
            $a['balance'] = AccountController::balance((int) $a['id']);
            $inHand += $a['balance'];
        }
        unset($a);

        $cashToday = Helper::money((float) DB::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
             FROM account_entries WHERE entry_date = ?",
            [$today]
        ));

        Response::ok([
            'counts'      => $counts,
            'attention'   => $attention,
            'cash_today'  => $cashToday,
            'accounts'    => $accounts,
            'in_hand'     => Helper::money($inHand),
            'receivable'  => $receivable,
            'payable'     => $payable,
            'advance_held'=> $advanceHeld,
            'today'       => self::periodTotals($today, $today),
            'this_month'  => self::periodTotals($monthStart, $today),
            'this_year'   => self::periodTotals($yearStart, $today),
        ]);
    }

    /** daily | monthly | yearly income-expense report. */
    public static function incomeExpense(): void
    {
        Auth::allow('reports.view');
        $period = (string) ($_GET['period'] ?? 'monthly');
        if (!in_array($period, ['daily', 'monthly', 'yearly'], true)) {
            $period = 'monthly';
        }

        [$from, $to] = self::rangeFor($period);
        $from = Helper::parseDate($_GET['from'] ?? null, $from);
        $to   = Helper::parseDate($_GET['to'] ?? null, $to);

        $groupExpr = match ($period) {
            'daily'   => '%Y-%m-%d',
            'yearly'  => '%Y',
            default   => '%Y-%m',
        };
        $p = [$from, $to];

        $income = DB::all(
            "SELECT DATE_FORMAT(payment_date, '{$groupExpr}') AS period, currency,
                    COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(amount * fx_rate),0) AS amount_base
             FROM payments
             WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?
             GROUP BY period, currency ORDER BY period ASC",
            $p
        );
        $otherIncome = DB::all(
            "SELECT DATE_FORMAT(entry_date, '{$groupExpr}') AS period, currency,
                    COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(amount * fx_rate),0) AS amount_base
             FROM expenses
             WHERE deleted_at IS NULL AND kind = 'income' AND entry_date BETWEEN ? AND ?
             GROUP BY period, currency ORDER BY period ASC",
            $p
        );
        $officeExpense = DB::all(
            "SELECT DATE_FORMAT(entry_date, '{$groupExpr}') AS period, currency,
                    COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(amount * fx_rate),0) AS amount_base
             FROM expenses
             WHERE deleted_at IS NULL AND kind = 'expense' AND entry_date BETWEEN ? AND ?
             GROUP BY period, currency ORDER BY period ASC",
            $p
        );
        $supplierPaid = DB::all(
            "SELECT DATE_FORMAT(payment_date, '{$groupExpr}') AS period, currency,
                    COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(amount * fx_rate),0) AS amount_base
             FROM supplier_payments
             WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?
             GROUP BY period, currency ORDER BY period ASC",
            $p
        );

        // merge into one timeline
        $timeline = [];
        $add = static function (array $rows, string $key) use (&$timeline): void {
            foreach ($rows as $r) {
                $k = $r['period'] . '|' . $r['currency'];
                if (!isset($timeline[$k])) {
                    $timeline[$k] = [
                        'period' => $r['period'], 'currency' => $r['currency'],
                        'customer_payments' => 0.0, 'other_income' => 0.0,
                        'office_expense' => 0.0, 'supplier_payments' => 0.0,
                    ];
                }
                $timeline[$k][$key] = Helper::money((float) $r['amount']);
            }
        };
        $add($income, 'customer_payments');
        $add($otherIncome, 'other_income');
        $add($officeExpense, 'office_expense');
        $add($supplierPaid, 'supplier_payments');

        foreach ($timeline as &$t) {
            $t['total_income']  = Helper::money($t['customer_payments'] + $t['other_income']);
            $t['total_expense'] = Helper::money($t['office_expense'] + $t['supplier_payments']);
            $t['net']           = Helper::money($t['total_income'] - $t['total_expense']);
        }
        unset($t);

        $rows = array_values($timeline);
        usort($rows, static fn ($a, $b) => [$a['period'], $a['currency']] <=> [$b['period'], $b['currency']]);

        Response::ok([
            'period'  => $period,
            'from'    => $from,
            'to'      => $to,
            'rows'    => $rows,
            'summary' => self::periodTotals($from, $to),
        ]);
    }

    /** Profit view: invoiced value vs the cost stored on each line. */
    public static function profit(): void
    {
        Auth::allow('costs.view');
        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));
        $p = [$from, $to];

        $rows = DB::all(
            "SELECT i.currency,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.subtotal),0)   AS sales,
                    COALESCE(SUM(i.total_cost),0) AS cost,
                    COALESCE(SUM(i.subtotal - i.total_cost),0) AS gross_profit
             FROM invoices i
             WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY i.currency",
            $p
        );

        $byService = DB::all(
            "SELECT COALESCE(s.name, ii.description) AS service_name, i.currency,
                    COALESCE(SUM(ii.line_total),0) AS sales,
                    COALESCE(SUM(ii.cost_price * ii.qty),0) AS cost,
                    COALESCE(SUM(ii.line_total - (ii.cost_price * ii.qty)),0) AS profit,
                    COUNT(*) AS times_sold
             FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id
             LEFT JOIN services s ON s.id = ii.service_id
             WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY service_name, i.currency
             ORDER BY profit DESC
             LIMIT 50",
            $p
        );

        Response::ok(['from' => $from, 'to' => $to, 'by_currency' => $rows, 'by_service' => $byService]);
    }



    /**
     * One report that answers everything at once: what came in, what went
     * out, whether that is profit or loss, who owes me, who I owe, where the
     * money sits, and what is coming up. Built for reading and for printing.
     */
    public static function full(): void
    {
        Auth::allow('reports.view');

        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));
        $cur  = Helper::baseCurrency();

        // ---- money in and out over the window
        $income = (float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM payments
             WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?', [$from, $to]
        );
        $otherIncome = (float) DB::value(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
             WHERE deleted_at IS NULL AND kind = 'income' AND entry_date BETWEEN ? AND ?", [$from, $to]
        );
        $expense = (float) DB::value(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
             WHERE deleted_at IS NULL AND kind = 'expense' AND entry_date BETWEEN ? AND ?", [$from, $to]
        );
        $supplierPaid = (float) DB::value(
            'SELECT COALESCE(SUM(amount),0) FROM supplier_payments
             WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?', [$from, $to]
        );

        $moneyIn  = $income + $otherIncome;
        $moneyOut = $expense + $supplierPaid;

        // ---- what the work itself earned: billed minus its cost
        $billed = DB::one(
            "SELECT COALESCE(SUM(total),0) AS billed, COALESCE(SUM(total_cost),0) AS cost
             FROM invoices
             WHERE deleted_at IS NULL AND status NOT IN ('draft','cancelled')
               AND invoice_date BETWEEN ? AND ?", [$from, $to]
        );

        // ---- standing position, as of now
        $receivable = (float) DB::value(
            "SELECT COALESCE(SUM(due_amount),0) FROM invoices
             WHERE deleted_at IS NULL AND status NOT IN ('draft','cancelled')"
        );
        $payable = (float) DB::value(
            "SELECT COALESCE(SUM(due_amount),0) FROM supplier_bills
             WHERE deleted_at IS NULL AND status <> 'cancelled'"
        );
        $advanceHeld = (float) DB::value(
            'SELECT COALESCE(SUM(unallocated_amount),0) FROM payments WHERE deleted_at IS NULL'
        );
        $advancePaid = (float) DB::value(
            'SELECT COALESCE(SUM(unallocated_amount),0) FROM supplier_payments WHERE deleted_at IS NULL'
        );

        $accounts = DB::all('SELECT id, name, type FROM accounts WHERE is_active = 1 ORDER BY sort_order ASC');
        $inHand = 0.0;
        foreach ($accounts as &$a) {
            $a['balance'] = AccountController::balance((int) $a['id']);
            $inHand += $a['balance'];
        }
        unset($a);

        Response::ok([
            'from'     => $from,
            'to'       => $to,
            'currency' => $cur,
            'company'  => [
                'name'    => DB::setting('company_name', 'Creatives iT'),
                'address' => DB::setting('company_address', ''),
                'phone'   => DB::setting('company_phone', ''),
                'email'   => DB::setting('company_email', ''),
            ],

            'trading' => [
                'money_in'       => Helper::money($moneyIn),
                'from_customers' => Helper::money($income),
                'other_income'   => Helper::money($otherIncome),
                'money_out'      => Helper::money($moneyOut),
                'office_expense' => Helper::money($expense),
                'supplier_paid'  => Helper::money($supplierPaid),
                'net'            => Helper::money($moneyIn - $moneyOut),
                // with nothing in and nothing out there is neither
                'result'         => ($moneyIn == 0.0 && $moneyOut == 0.0)
                    ? 'quiet'
                    : ($moneyIn >= $moneyOut ? 'profit' : 'loss'),
            ],

            'work' => [
                'billed'       => Helper::money((float) $billed['billed']),
                'cost'         => Helper::money((float) $billed['cost']),
                'gross_profit' => Helper::money((float) $billed['billed'] - (float) $billed['cost']),
                'margin'       => (float) $billed['billed'] > 0
                    ? round((((float) $billed['billed'] - (float) $billed['cost'])
                        / (float) $billed['billed']) * 100, 1)
                    : 0,
            ],

            'position' => [
                'receivable'   => Helper::money($receivable),
                'payable'      => Helper::money($payable),
                'advance_held' => Helper::money($advanceHeld),
                'advance_paid' => Helper::money($advancePaid),
                'in_hand'      => Helper::money($inHand),
                'net_worth'    => Helper::money($inHand + $receivable - $payable),
            ],

            'accounts' => $accounts,

            'who_owes_me' => DB::all(
                "SELECT c.id, c.name, c.company_name, c.phone,
                        COALESCE(SUM(i.due_amount),0) AS due,
                        MIN(CASE WHEN i.due_amount > 0 THEN i.due_date END) AS oldest_due,
                        DATEDIFF(CURDATE(), MIN(CASE WHEN i.due_amount > 0 THEN i.due_date END)) AS days_over
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 WHERE i.deleted_at IS NULL AND i.due_amount > 0
                   AND i.status NOT IN ('draft','cancelled')
                 GROUP BY c.id ORDER BY due DESC"
            ),

            'i_owe' => DB::all(
                "SELECT s.id, s.name, s.company_name, s.phone,
                        COALESCE(SUM(b.due_amount),0) AS due
                 FROM supplier_bills b JOIN suppliers s ON s.id = b.supplier_id
                 WHERE b.deleted_at IS NULL AND b.due_amount > 0 AND b.status <> 'cancelled'
                 GROUP BY s.id ORDER BY due DESC"
            ),

            'expense_breakdown' => DB::all(
                "SELECT COALESCE(c.name,'Uncategorised') AS category,
                        COUNT(*) AS entries, COALESCE(SUM(e.amount),0) AS total
                 FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
                 WHERE e.deleted_at IS NULL AND e.kind = 'expense'
                   AND e.entry_date BETWEEN ? AND ?
                 GROUP BY e.category_id ORDER BY total DESC",
                [$from, $to]
            ),

            'earned_by_service' => DB::all(
                "SELECT COALESCE(sv.name, ii.description) AS service,
                        SUM(ii.qty) AS qty,
                        COALESCE(SUM(ii.line_total),0) AS billed,
                        COALESCE(SUM(ii.cost_price * ii.qty),0) AS cost
                 FROM invoice_items ii
                 JOIN invoices i ON i.id = ii.invoice_id
                 LEFT JOIN services sv ON sv.id = ii.service_id
                 WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
                   AND i.invoice_date BETWEEN ? AND ?
                 GROUP BY COALESCE(sv.id, ii.description)
                 ORDER BY billed DESC LIMIT 25",
                [$from, $to]
            ),

            'coming_up' => DB::all(
                "SELECT d.title, d.expiry_date, c.name AS customer_name, c.company_name,
                        DATEDIFF(d.expiry_date, CURDATE()) AS days_left
                 FROM customer_documents d JOIN customers c ON c.id = d.customer_id
                 WHERE d.deleted_at IS NULL AND d.status = 'active'
                   AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                 ORDER BY d.expiry_date ASC LIMIT 25"
            ),

            'open_work' => (int) DB::value(
                "SELECT COUNT(*) FROM job_items ji
                 JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL
                 WHERE ji.status IN ('pending','in_progress')"
            ),
        ]);
    }


    /**
     * What the office spent, by category, broken into days, weeks or months.
     * Categories run down the side and the periods across the top, so you can
     * see both what the money goes on and whether it is creeping up.
     */
    public static function expenses(): void
    {
        Auth::allow('reports.view');

        $grain = (string) ($_GET['grain'] ?? 'monthly');
        if (!in_array($grain, ['daily', 'weekly', 'monthly'], true)) {
            $grain = 'monthly';
        }

        // a sensible window for each grain, unless asked otherwise
        $defaultFrom = match ($grain) {
            'daily'   => date('Y-m-d', strtotime('-29 days')),
            'weekly'  => date('Y-m-d', strtotime('-11 weeks')),
            default   => date('Y-m-01', strtotime('-11 months')),
        };
        $from = Helper::parseDate($_GET['from'] ?? null, $defaultFrom);
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));
        $categoryId = (int) ($_GET['category_id'] ?? 0);

        // how a date turns into a column, and what that column is called
        [$expr, $label] = match ($grain) {
            'daily'  => ["DATE_FORMAT(e.entry_date, '%Y-%m-%d')", 'Day'],
            'weekly' => ["DATE_FORMAT(DATE_SUB(e.entry_date, INTERVAL WEEKDAY(e.entry_date) DAY), '%Y-%m-%d')", 'Week of'],
            default  => ["DATE_FORMAT(e.entry_date, '%Y-%m')", 'Month'],
        };

        $params = [$from, $to];
        $categorySql = '';
        if ($categoryId > 0) {
            $categorySql = ' AND e.category_id = ?';
            $params[] = $categoryId;
        }

        $rows = DB::all(
            "SELECT {$expr} AS bucket,
                    COALESCE(c.name, 'Uncategorised') AS category,
                    c.id AS category_id,
                    COUNT(*) AS entries,
                    COALESCE(SUM(e.amount),0) AS total
             FROM expenses e
             LEFT JOIN expense_categories c ON c.id = e.category_id
             WHERE e.deleted_at IS NULL AND e.kind = 'expense'
               AND e.entry_date BETWEEN ? AND ?{$categorySql}
             GROUP BY bucket, e.category_id
             ORDER BY bucket ASC, total DESC",
            $params
        );

        // pivot it: one row per category, one column per period
        $buckets = [];
        $byCategory = [];
        foreach ($rows as $r) {
            $bucket = (string) $r['bucket'];
            $cat = (string) $r['category'];
            $buckets[$bucket] = true;
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['category' => $cat, 'cells' => [], 'total' => 0.0, 'entries' => 0];
            }
            $byCategory[$cat]['cells'][$bucket] = Helper::money((float) $r['total']);
            $byCategory[$cat]['total'] += (float) $r['total'];
            $byCategory[$cat]['entries'] += (int) $r['entries'];
        }

        $bucketList = array_keys($buckets);
        sort($bucketList);

        // fill the gaps so every row has a figure in every column
        foreach ($byCategory as &$cat) {
            foreach ($bucketList as $b) {
                $cat['cells'][$b] = $cat['cells'][$b] ?? 0.0;
            }
            $cat['total'] = Helper::money($cat['total']);
        }
        unset($cat);

        // biggest spend first: that is the one worth looking at
        $categories = array_values($byCategory);
        usort($categories, static fn ($a, $b) => $b['total'] <=> $a['total']);

        $columnTotals = [];
        foreach ($bucketList as $b) {
            $columnTotals[$b] = Helper::money(array_sum(
                array_map(static fn ($c) => (float) $c['cells'][$b], $categories)
            ));
        }

        $grand = Helper::money(array_sum(array_map(static fn ($c) => (float) $c['total'], $categories)));

        Response::ok([
            'grain'         => $grain,
            'grain_label'   => $label,
            'from'          => $from,
            'to'            => $to,
            'currency'      => Helper::baseCurrency(),
            'buckets'       => array_map(static fn ($b) => [
                'key'   => $b,
                'label' => self::bucketLabel($b, $grain),
            ], $bucketList),
            'categories'    => $categories,
            'column_totals' => $columnTotals,
            'total'         => $grand,
            'busiest'       => $categories[0]['category'] ?? null,
        ]);
    }

    /** "2026-09" reads as "Sep 2026"; a week reads as its Monday. */
    private static function bucketLabel(string $bucket, string $grain): string
    {
        return match ($grain) {
            'daily'  => date('d M', strtotime($bucket)),
            'weekly' => date('d M', strtotime($bucket)),
            default  => date('M Y', strtotime($bucket . '-01')),
        };
    }

    /** A signed link to the printable version of the report. */
    public static function printLink(): void
    {
        Auth::allow('reports.view');
        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));

        Response::ok([
            'url' => PrintAuth::link('report', 0, 'print/report.php')
                   . '&from=' . $from . '&to=' . $to,
            'expires_in' => 1800,
        ]);
    }

    /** Where the money sits, and what moved through each account. */
    public static function cashAndBank(): void
    {
        Auth::allow('reports.view');

        $from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
        $to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));

        $accounts = DB::all('SELECT * FROM accounts WHERE is_active = 1 ORDER BY sort_order ASC, name ASC');

        $rows = [];
        $totalBalance = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;

        foreach ($accounts as $a) {
            $id = (int) $a['id'];
            $in = (float) DB::value(
                "SELECT COALESCE(SUM(amount),0) FROM account_entries
                 WHERE account_id = ? AND direction = 'in' AND entry_date BETWEEN ? AND ?",
                [$id, $from, $to]
            );
            $out = (float) DB::value(
                "SELECT COALESCE(SUM(amount),0) FROM account_entries
                 WHERE account_id = ? AND direction = 'out' AND entry_date BETWEEN ? AND ?",
                [$id, $from, $to]
            );
            $balance = AccountController::balance($id);

            $rows[] = [
                'account_id' => $id,
                'name'       => $a['name'],
                'type'       => $a['type'],
                'money_in'   => Helper::money($in),
                'money_out'  => Helper::money($out),
                'net'        => Helper::money($in - $out),
                'balance'    => $balance,
            ];
            $totalBalance += $balance;
            $totalIn  += $in;
            $totalOut += $out;
        }

        // the same figures folded by kind of account
        $byType = [];
        foreach ($rows as $r) {
            $t = (string) $r['type'];
            $byType[$t] = Helper::money(($byType[$t] ?? 0) + $r['balance']);
        }

        $byMethod = DB::all(
            "SELECT method, COUNT(*) AS entries, COALESCE(SUM(amount),0) AS total
             FROM (
               SELECT method, amount FROM payments
                WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?
               UNION ALL
               SELECT method, amount FROM expenses
                WHERE deleted_at IS NULL AND entry_date BETWEEN ? AND ?
               UNION ALL
               SELECT method, amount FROM supplier_payments
                WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?
             ) t
             GROUP BY method ORDER BY total DESC",
            [$from, $to, $from, $to, $from, $to]
        );

        Response::ok([
            'from'          => $from,
            'to'            => $to,
            'currency'      => Helper::baseCurrency(),
            'accounts'      => $rows,
            'by_type'       => $byType,
            'by_method'     => $byMethod,
            'total_balance' => Helper::money($totalBalance),
            'money_in'      => Helper::money($totalIn),
            'money_out'     => Helper::money($totalOut),
        ]);
    }

    /** Everything owed to me + everything I owe, in one call. */
    public static function balances(): void
    {
        Auth::allow('reports.view');

        $receivable = DB::all(
            "SELECT currency, COALESCE(SUM(due_amount),0) AS amount, COUNT(*) AS invoices
             FROM invoices WHERE deleted_at IS NULL AND status NOT IN ('draft','cancelled')
               AND due_amount > 0 GROUP BY currency",
            []
        );
        $payable = DB::all(
            "SELECT currency, COALESCE(SUM(due_amount),0) AS amount, COUNT(*) AS bills
             FROM supplier_bills WHERE deleted_at IS NULL AND status <> 'cancelled'
               AND due_amount > 0 GROUP BY currency",
            []
        );
        $topDebtors = DB::all(
            "SELECT c.id, c.name, c.company_name, c.phone, i.currency,
                    COALESCE(SUM(i.due_amount),0) AS due,
                    DATEDIFF(CURDATE(), MIN(i.due_date)) AS days_overdue
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
               AND i.due_amount > 0
             GROUP BY c.id, i.currency ORDER BY due DESC LIMIT 20",
            []
        );
        $topCreditors = DB::all(
            "SELECT s.id, s.name, s.company_name, s.phone, b.currency,
                    COALESCE(SUM(b.due_amount),0) AS payable
             FROM supplier_bills b JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.deleted_at IS NULL AND b.status <> 'cancelled'
               AND b.due_amount > 0
             GROUP BY s.id, b.currency ORDER BY payable DESC LIMIT 20",
            []
        );

        Response::ok([
            'receivable'    => $receivable,
            'payable'       => $payable,
            'top_debtors'   => $topDebtors,
            'top_creditors' => $topCreditors,
        ]);
    }

    // ---------------- internals ----------------

    private static function periodTotals(string $from, string $to): array
    {
        $p = [$from, $to];

        $income = DB::all(
            "SELECT currency, COALESCE(SUM(amount),0) AS amount
             FROM payments WHERE deleted_at IS NULL AND payment_date BETWEEN ? AND ?
             GROUP BY currency", $p
        );
        $otherIncome = DB::all(
            "SELECT currency, COALESCE(SUM(amount),0) AS amount
             FROM expenses WHERE deleted_at IS NULL AND kind = 'income'
               AND entry_date BETWEEN ? AND ? GROUP BY currency", $p
        );
        $expense = DB::all(
            "SELECT currency, COALESCE(SUM(amount),0) AS amount
             FROM expenses WHERE deleted_at IS NULL AND kind = 'expense'
               AND entry_date BETWEEN ? AND ? GROUP BY currency", $p
        );
        $supplier = DB::all(
            "SELECT currency, COALESCE(SUM(amount),0) AS amount
             FROM supplier_payments WHERE deleted_at IS NULL
               AND payment_date BETWEEN ? AND ? GROUP BY currency", $p
        );
        $invoiced = DB::all(
            "SELECT currency, COALESCE(SUM(total),0) AS amount
             FROM invoices WHERE deleted_at IS NULL AND status NOT IN ('draft','cancelled')
               AND invoice_date BETWEEN ? AND ? GROUP BY currency", $p
        );

        $out = [];
        $fold = static function (array $rows, string $key) use (&$out): void {
            foreach ($rows as $r) {
                $cur = (string) $r['currency'];
                if (!isset($out[$cur])) {
                    $out[$cur] = ['currency' => $cur, 'invoiced' => 0.0, 'received' => 0.0,
                                  'other_income' => 0.0, 'office_expense' => 0.0, 'supplier_paid' => 0.0];
                }
                $out[$cur][$key] = Helper::money((float) $r['amount']);
            }
        };
        $fold($invoiced, 'invoiced');
        $fold($income, 'received');
        $fold($otherIncome, 'other_income');
        $fold($expense, 'office_expense');
        $fold($supplier, 'supplier_paid');

        foreach ($out as &$row) {
            $row['total_income']  = Helper::money($row['received'] + $row['other_income']);
            $row['total_expense'] = Helper::money($row['office_expense'] + $row['supplier_paid']);
            $row['net']           = Helper::money($row['total_income'] - $row['total_expense']);
        }
        unset($row);

        return ['from' => $from, 'to' => $to, 'by_currency' => array_values($out)];
    }

    private static function rangeFor(string $period): array
    {
        return match ($period) {
            'daily'  => [date('Y-m-01'), date('Y-m-t')],
            'yearly' => [date('Y-01-01', strtotime('-4 year')), date('Y-12-31')],
            default  => [date('Y-01-01'), date('Y-12-31')],
        };
    }
}
