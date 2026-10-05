<?php
/**
 * AH5 Office - printable invoice
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Opened with a short-lived token:
 *   /print/invoice.php?id=12&t=<print_token>
 * Get the token from  GET /api/v1/invoices/{id}/print-link
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/_template.php';

$id = (int) ($_GET['id'] ?? 0);
PrintAuth::check('invoice', $id);

$doc = DB::one(
    'SELECT i.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
            c.address AS customer_address, c.email AS customer_email
     FROM invoices i JOIN customers c ON c.id = i.customer_id
     WHERE i.id = ? AND i.deleted_at IS NULL',
    [$id]
);
if ($doc === null) {
    http_response_code(404);
    exit('Invoice not found');
}

$items = DB::all(
    'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
    [$id]
);

render_print_document($doc, $items, 'invoice');
