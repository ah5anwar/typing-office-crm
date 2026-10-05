<?php
/**
 * AH5 Office - printable quotation
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/_template.php';

$id = (int) ($_GET['id'] ?? 0);
PrintAuth::check('quotation', $id);

$doc = DB::one(
    'SELECT q.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone,
            c.address AS customer_address, c.email AS customer_email
     FROM quotations q JOIN customers c ON c.id = q.customer_id
     WHERE q.id = ? AND q.deleted_at IS NULL',
    [$id]
);
if ($doc === null) {
    http_response_code(404);
    exit('Quotation not found');
}

$items = DB::all(
    'SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC, id ASC',
    [$id]
);

render_print_document($doc, $items, 'quotation');
