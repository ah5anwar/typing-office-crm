<?php
/**
 * AH5 Office - invoice design preview
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Shows a made-up invoice in the current design so you can judge it
 * without touching a real one.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/_template.php';

PrintAuth::check('preview', 0);

$today = date('Y-m-d');
$doc = [
    'invoice_no'     => (string) DB::setting('invoice_prefix', 'AH5-') . date('dmy') . '01',
    'invoice_date'   => $today,
    'due_date'       => date('Y-m-d', strtotime($today . ' +7 day')),
    'currency'       => (string) DB::setting('base_currency', BASE_CURRENCY),
    'subject'        => 'Sample invoice — this is only a preview',
    'subtotal'       => 40800.00,
    'discount_type'  => 'flat',
    'discount_value' => 800.00,
    'discount_amount'=> 800.00,
    'vat_percent'    => 5.00,
    'vat_amount'     => 2000.00,
    'total'          => 42000.00,
    'paid_amount'    => 20000.00,
    'due_amount'     => 22000.00,
    'terms'          => 'Payment within 7 days of the invoice date.',
    'notes'          => 'This preview uses made-up figures. Nothing here is saved.',
    'customer_name'    => 'Rahim Uddin',
    'company_name'     => 'Rahim Traders',
    'customer_phone'   => '+8801712345678',
    'customer_address' => 'Mirpur, Dhaka',
];

$items = [
    ['description' => 'Domain registration (.com)', 'qty' => 1, 'unit' => 'year',
     'unit_price' => 1800.00, 'line_total' => 1800.00,
     'period_start' => $today, 'period_end' => date('Y-m-d', strtotime($today . ' +1 year'))],
    ['description' => 'Hosting 5GB', 'qty' => 1, 'unit' => 'year',
     'unit_price' => 4000.00, 'line_total' => 4000.00,
     'period_start' => null, 'period_end' => null],
    ['description' => 'Website development', 'qty' => 1, 'unit' => 'pcs',
     'unit_price' => 35000.00, 'line_total' => 35000.00,
     'period_start' => null, 'period_end' => null],
];

render_print_document($doc, $items, 'invoice');
