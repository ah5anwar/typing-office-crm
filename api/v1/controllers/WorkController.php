<?php
/**
 * AH5 Office - the work list
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * One place to answer two questions:
 *   what work have customers given me, and what is still outstanding
 *   what have I passed to suppliers, and what have they finished
 *
 * The data already lives on jobs; this only reads it back the way you
 * actually look at it - everything at once, or one party at a time.
 */

declare(strict_types=1);

final class WorkController
{
    /** Work customers have given me, line by line. */
    public static function customerWork(): void
    {
        Auth::allow('jobs.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['j.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['customer_id'])) {
            $where[]  = 'j.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }

        $status = (string) ($_GET['status'] ?? 'pending');
        if ($status === 'pending') {
            $where[] = "ji.status IN ('pending','in_progress')";
        } elseif ($status === 'done') {
            $where[] = "ji.status = 'completed'";
        } elseif ($status === 'cancelled') {
            $where[] = "ji.status = 'cancelled'";
        }

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(ji.description LIKE ? OR j.title LIKE ? OR j.job_no LIKE ?
                         OR c.name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $q, $q, $q, $q, $q);
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
            "SELECT COUNT(*) FROM job_items ji
             JOIN jobs j ON j.id = ji.job_id
             JOIN customers c ON c.id = j.customer_id
             WHERE {$whereSql}",
            $params
        );

        $rows = DB::all(
            "SELECT ji.id, ji.description, ji.qty, ji.unit_price, ji.cost_price, ji.line_total,
                    ji.status, ji.completed_at, ji.is_invoiced,
                    j.id AS job_id, j.job_no, j.title AS job_title, j.received_date, j.due_date,
                    j.priority, j.currency,
                    c.id AS customer_id, c.name AS customer_name, c.company_name, c.phone,
                    s.name AS service_name,
                    DATEDIFF(j.due_date, CURDATE()) AS days_to_due,
                    (SELECT GROUP_CONCAT(sup.name SEPARATOR ', ')
                       FROM job_supplier_assign a JOIN suppliers sup ON sup.id = a.supplier_id
                      WHERE a.job_item_id = ji.id) AS given_to
             FROM job_items ji
             JOIN jobs j ON j.id = ji.job_id
             JOIN customers c ON c.id = j.customer_id
             LEFT JOIN services s ON s.id = ji.service_id
             WHERE {$whereSql}
             ORDER BY (j.due_date IS NULL), j.due_date ASC, j.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, self::customerSummary());
    }

    /** Work I have passed to suppliers. */
    public static function supplierWork(): void
    {
        Auth::allow('suppliers.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['j.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['supplier_id'])) {
            $where[]  = 'a.supplier_id = ?';
            $params[] = (int) $_GET['supplier_id'];
        }

        $status = (string) ($_GET['status'] ?? 'pending');
        if ($status === 'pending') {
            $where[] = "a.status IN ('pending','in_progress')";
        } elseif ($status === 'done') {
            $where[] = "a.status = 'completed'";
        } elseif ($status === 'cancelled') {
            $where[] = "a.status = 'cancelled'";
        }

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(a.work_detail LIKE ? OR j.title LIKE ? OR sup.name LIKE ? OR c.name LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            "SELECT COUNT(*) FROM job_supplier_assign a
             JOIN jobs j ON j.id = a.job_id
             JOIN suppliers sup ON sup.id = a.supplier_id
             JOIN customers c ON c.id = j.customer_id
             WHERE {$whereSql}",
            $params
        );

        $rows = DB::all(
            "SELECT a.id, a.work_detail, a.agreed_cost, a.currency, a.assigned_date, a.due_date,
                    a.status, a.completed_at, a.is_billed,
                    j.id AS job_id, j.job_no, j.title AS job_title,
                    sup.id AS supplier_id, sup.name AS supplier_name, sup.phone AS supplier_phone,
                    c.id AS customer_id, c.name AS customer_name, c.company_name,
                    ji.description AS item_description,
                    DATEDIFF(a.due_date, CURDATE()) AS days_to_due
             FROM job_supplier_assign a
             JOIN jobs j ON j.id = a.job_id
             JOIN suppliers sup ON sup.id = a.supplier_id
             JOIN customers c ON c.id = j.customer_id
             LEFT JOIN job_items ji ON ji.id = a.job_item_id
             WHERE {$whereSql}
             ORDER BY (a.due_date IS NULL), a.due_date ASC, a.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage, self::supplierSummary());
    }

    /** Counts per customer, for the sidebar of the work page. */
    public static function byCustomer(): void
    {
        Auth::allow('jobs.view');

        $rows = DB::all(
            "SELECT c.id, c.name, c.company_name, c.phone,
                    SUM(ji.status IN ('pending','in_progress')) AS pending,
                    SUM(ji.status = 'completed')                AS done,
                    COUNT(*)                                    AS total,
                    SUM(CASE WHEN ji.status IN ('pending','in_progress')
                             THEN ji.line_total ELSE 0 END)     AS pending_value,
                    MIN(CASE WHEN ji.status IN ('pending','in_progress')
                             THEN j.due_date END)               AS next_due
             FROM job_items ji
             JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL
             JOIN customers c ON c.id = j.customer_id
             GROUP BY c.id
             HAVING total > 0
             ORDER BY pending DESC, c.name ASC"
        );

        Response::ok(['rows' => $rows, 'summary' => self::customerSummary()]);
    }

    /** Counts per supplier. */
    public static function bySupplier(): void
    {
        Auth::allow('suppliers.view');

        $rows = DB::all(
            "SELECT sup.id, sup.name, sup.company_name, sup.phone,
                    SUM(a.status IN ('pending','in_progress')) AS pending,
                    SUM(a.status = 'completed')                AS done,
                    COUNT(*)                                   AS total,
                    SUM(CASE WHEN a.status IN ('pending','in_progress')
                             THEN a.agreed_cost ELSE 0 END)    AS pending_cost,
                    MIN(CASE WHEN a.status IN ('pending','in_progress')
                             THEN a.due_date END)              AS next_due
             FROM job_supplier_assign a
             JOIN jobs j ON j.id = a.job_id AND j.deleted_at IS NULL
             JOIN suppliers sup ON sup.id = a.supplier_id
             GROUP BY sup.id
             HAVING total > 0
             ORDER BY pending DESC, sup.name ASC"
        );

        Response::ok(['rows' => $rows, 'summary' => self::supplierSummary()]);
    }

    // ---------------- internals ----------------

    private static function customerSummary(): array
    {
        $row = DB::one(
            "SELECT SUM(ji.status IN ('pending','in_progress')) AS pending,
                    SUM(ji.status = 'completed')                AS done,
                    SUM(ji.status IN ('pending','in_progress')
                        AND j.due_date < CURDATE())             AS overdue,
                    COUNT(*)                                    AS total
             FROM job_items ji
             JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL"
        );
        return ['counts' => $row ?? []];
    }

    private static function supplierSummary(): array
    {
        $row = DB::one(
            "SELECT SUM(a.status IN ('pending','in_progress')) AS pending,
                    SUM(a.status = 'completed')                AS done,
                    SUM(a.status IN ('pending','in_progress')
                        AND a.due_date < CURDATE())            AS overdue,
                    COUNT(*)                                   AS total
             FROM job_supplier_assign a
             JOIN jobs j ON j.id = a.job_id AND j.deleted_at IS NULL"
        );
        return ['counts' => $row ?? []];
    }
}
