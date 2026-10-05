<?php
/**
 * AH5 Office - invoice and quotation appearance
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Three ready designs, an accent colour and a few switches cover most needs.
 * If you want something the switches cannot express, write your own HTML and
 * drop placeholders into it - see PLACEHOLDERS below.
 */

declare(strict_types=1);

final class InvoiceDesign
{
    public const TEMPLATES = [
        'classic'   => 'Classic — coloured header bar, the familiar business look',
        'modern'    => 'Modern — wide spacing, hairline rules, accent on the total only',
        'compact'   => 'Compact — tight rows, for invoices with many lines',
        'bold'      => 'Bold — a solid accent band across the top, large numbers',
        'minimal'   => 'Minimal — no rules at all, just air and type',
        'stamp'     => 'Stamp — a bordered sheet, like a paid receipt book',
        'sidebar'   => 'Sidebar — your details down a coloured left column',
        'statement' => 'Statement — bank-statement plainness, zebra rows',
        'letter'    => 'Letter — reads like a letter on your headed paper',
        'ledger'    => 'Ledger — ruled like an accounts book, serif type',
    ];

    /** What you may write inside a custom template. */
    public const PLACEHOLDERS = [
        '{{doc_type}}'        => 'INVOICE or QUOTATION',
        '{{doc_no}}'          => 'Document number',
        '{{doc_date}}'        => 'Date of the document',
        '{{due_date}}'        => 'Payment due date, or valid-until on a quotation',
        '{{currency}}'        => 'Currency code',
        '{{subject}}'         => 'Subject line',
        '{{status}}'          => 'Paid, Partially Paid, Unpaid',
        '{{company_name}}'    => 'Your business name',
        '{{company_address}}' => 'Your address',
        '{{company_phone}}'   => 'Your phone',
        '{{company_email}}'   => 'Your email',
        '{{company_website}}' => 'Your website',
        '{{company_tax}}'     => 'Your TRN / BIN',
        '{{company_licence}}' => 'Your trade licence',
        '{{logo}}'            => 'Your logo as an <img> tag',
        '{{signature}}'       => 'Your signature image as an <img> tag',
        '{{customer_name}}'   => 'Customer contact name',
        '{{customer_company}}'=> 'Customer company',
        '{{customer_address}}'=> 'Customer address',
        '{{customer_phone}}'  => 'Customer phone',
        '{{items}}'           => 'The whole line-item table',
        '{{subtotal}}'        => 'Subtotal',
        '{{discount}}'        => 'Discount amount',
        '{{vat}}'             => 'VAT amount',
        '{{total}}'           => 'Grand total',
        '{{paid}}'            => 'Amount paid',
        '{{due}}'             => 'Balance due',
        '{{terms}}'           => 'Terms text',
        '{{notes}}'           => 'Notes text',
        '{{footer_note}}'     => 'The footer line from Settings',
        '{{bank_details}}'    => 'Your bank details',
        '{{credit}}'          => 'The Creatives iT credit line',
    ];

    /** Current design choices, with sensible fallbacks. */
    public static function settings(): array
    {
        $accent = (string) DB::setting('invoice_accent', '#1d4ed8');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#1d4ed8';
        }
        $template = (string) DB::setting('invoice_template', 'classic');
        if (!isset(self::TEMPLATES[$template])) {
            $template = 'classic';
        }

        return [
            'template'       => $template,
            'accent'         => $accent,
            'paper'          => DB::setting('invoice_paper', 'A4') === 'Letter' ? 'Letter' : 'A4',
            'show_logo'      => DB::setting('invoice_show_logo', '1') === '1',
            'show_signature' => DB::setting('invoice_show_signature', '0') === '1',
            'show_unit'      => DB::setting('invoice_show_unit', '1') === '1',
            'show_bank'      => DB::setting('invoice_show_bank', '1') === '1',
            'footer_note'    => (string) DB::setting('invoice_footer_note', ''),
            'use_custom'     => DB::setting('invoice_use_custom', '0') === '1',
            'show_credit'    => DB::setting('invoice_show_credit', '1') === '1',
            'custom_html'    => (string) DB::setting('invoice_custom_html', ''),
        ];
    }

    /** Company details used on both designs. */
    public static function company(): array
    {
        return [
            'name'     => (string) DB::setting('company_name', 'Creatives iT'),
            'owner'    => (string) DB::setting('company_owner', ''),
            'address'  => (string) DB::setting('company_address', ''),
            'phone'    => (string) DB::setting('company_phone', ''),
            'email'    => (string) DB::setting('company_email', ''),
            'website'  => (string) DB::setting('company_website', ''),
            'tax'      => (string) DB::setting('company_tax_number', ''),
            'licence'  => (string) DB::setting('company_trade_license', ''),
            'bank'     => (string) DB::setting('company_bank_details', ''),
            'logo'     => (string) DB::setting('company_logo', ''),
            'signature'=> (string) DB::setting('company_signature', ''),
        ];
    }

    /** A usable <img src> for a stored image, or null. */
    public static function imageSrc(string $settingKey): ?string
    {
        $stored = (string) DB::setting($settingKey, '');
        if ($stored === '') {
            return null;
        }
        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return $stored;
        }
        $full = Uploads::resolve($stored);
        if ($full === null || !is_file($full)) {
            return null;
        }
        // inline, so the printed page never depends on a second request
        $data = @file_get_contents($full);
        if ($data === false) {
            return null;
        }
        return 'data:' . Uploads::mimeFor($full) . ';base64,' . base64_encode($data);
    }

    /**
     * Fill a custom template. Every value is escaped except the parts we
     * build ourselves ({{items}}, {{logo}}, {{signature}}), so a customer
     * name containing HTML can never break or hijack the page.
     */
    public static function renderCustom(string $html, array $doc, array $items, string $kind): string
    {
        $design  = self::settings();
        $company = self::company();
        $cur     = (string) $doc['currency'];
        $e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m       = static fn ($v): string => Helper::formatMoney($v);
        $isInv   = $kind === 'invoice';

        $logo = self::imageSrc('company_logo');
        $sign = self::imageSrc('company_signature');

        $status = 'Unpaid';
        if ($isInv) {
            $status = (float) $doc['due_amount'] <= 0.004 ? 'Paid'
                : ((float) $doc['paid_amount'] > 0 ? 'Partially Paid' : 'Unpaid');
        }

        $replacements = [
            '{{doc_type}}'        => $isInv ? 'INVOICE' : 'QUOTATION',
            '{{doc_no}}'          => $e($isInv ? $doc['invoice_no'] : $doc['quote_no']),
            '{{doc_date}}'        => $e(date('d/m/Y', strtotime((string) ($isInv ? $doc['invoice_date'] : $doc['quote_date'])))),
            '{{due_date}}'        => $e(self::secondDate($doc, $isInv)),
            '{{currency}}'        => $e($cur),
            '{{subject}}'         => $e($doc['subject'] ?? ''),
            '{{status}}'          => $e($status),
            '{{company_name}}'    => $e($company['name']),
            '{{company_address}}' => nl2br($e($company['address'])),
            '{{company_phone}}'   => $e($company['phone']),
            '{{company_email}}'   => $e($company['email']),
            '{{company_website}}' => $e($company['website']),
            '{{company_tax}}'     => $e($company['tax']),
            '{{company_licence}}' => $e($company['licence']),
            '{{logo}}'            => $logo === null ? '' : '<img src="' . $logo . '" alt="" style="max-height:70px">',
            '{{signature}}'       => $sign === null ? '' : '<img src="' . $sign . '" alt="" style="max-height:60px">',
            '{{customer_name}}'   => $e($doc['customer_name'] ?? ''),
            '{{customer_company}}'=> $e($doc['company_name'] ?? ''),
            '{{customer_address}}'=> nl2br($e($doc['customer_address'] ?? '')),
            '{{customer_phone}}'  => $e($doc['customer_phone'] ?? ''),
            '{{items}}'           => self::itemsTable($items, $design, $cur),
            '{{subtotal}}'        => $m($doc['subtotal']) . ' ' . $e($cur),
            '{{discount}}'        => $m($doc['discount_amount']) . ' ' . $e($cur),
            '{{vat}}'             => $m($doc['vat_amount']) . ' ' . $e($cur),
            '{{total}}'           => $m($doc['total']) . ' ' . $e($cur),
            '{{paid}}'            => $isInv ? $m($doc['paid_amount']) . ' ' . $e($cur) : '',
            '{{due}}'             => $isInv ? $m($doc['due_amount']) . ' ' . $e($cur) : '',
            '{{terms}}'           => nl2br($e($doc['terms'] ?? '')),
            '{{notes}}'           => nl2br($e($doc['notes'] ?? '')),
            '{{footer_note}}'     => $e($design['footer_note']),
            '{{bank_details}}'    => nl2br($e($company['bank'])),
            '{{credit}}'          => 'Designed &amp; Developed by '
                . '<a href="https://anwar.com.bd">Anwar Hossain</a> · '
                . '<a href="https://creativesit.com">Creatives iT</a>',
        ];

        $out = strtr($html, $replacements);

        // anything the user left unfilled should not show as raw braces
        return (string) preg_replace('/\{\{[a-z_]+\}\}/i', '', $out);
    }

    public static function secondDate(array $doc, bool $isInvoice): string
    {
        $value = $isInvoice ? ($doc['due_date'] ?? null) : ($doc['valid_until'] ?? null);
        return $value === null ? '' : date('d/m/Y', strtotime((string) $value));
    }

    /** The line table, shared by the presets and the custom template. */
    public static function itemsTable(array $items, array $design, string $currency): string
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = static fn ($v): string => Helper::formatMoney($v);

        $rows = '';
        foreach ($items as $i => $it) {
            $qty = rtrim(rtrim(number_format((float) $it['qty'], 2), '0'), '.');
            if ($design['show_unit'] && !empty($it['unit'])) {
                $qty .= ' ' . $e($it['unit']);
            }
            $period = '';
            if (!empty($it['period_start']) && !empty($it['period_end'])) {
                $period = '<br><span class="period">'
                    . $e(date('d/m/Y', strtotime((string) $it['period_start']))) . ' – '
                    . $e(date('d/m/Y', strtotime((string) $it['period_end']))) . '</span>';
            }
            $rows .= '<tr>'
                . '<td class="idx">' . ($i + 1) . '</td>'
                . '<td>' . $e($it['description']) . $period . '</td>'
                . '<td class="num">' . $qty . '</td>'
                . '<td class="num">' . $m($it['unit_price']) . '</td>'
                . '<td class="num">' . $m($it['line_total']) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty">No items</td></tr>';
        }

        // fixed widths so a long description wraps instead of pushing the
        // money columns off the right edge of the paper
        return '<table class="items">'
            . '<colgroup>'
            . '<col class="c-idx"><col class="c-desc"><col class="c-qty">'
            . '<col class="c-rate"><col class="c-amount">'
            . '</colgroup>'
            . '<thead><tr>'
            . '<th class="idx">#</th><th>Description</th>'
            . '<th class="num">Qty</th><th class="num">Rate</th>'
            . '<th class="num">Amount</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** CSS for the chosen preset. */
    public static function css(array $design): string
    {
        $accent = $design['accent'];
        $paper  = $design['paper'] === 'Letter' ? 'Letter' : 'A4';

        $shared = "
  /* A4 is 210mm wide; 14mm either side leaves 182mm to work in. Everything
     below is held inside that, so a long line wraps instead of being cut
     off at the right edge when it prints. */
  @page { size: {$paper}; margin: 14mm; }

  * { box-sizing: border-box; }
  html, body { max-width: 100%; }
  .sheet { max-width: 182mm; margin: 0 auto; overflow-wrap: break-word; }
  table { width: 100%; max-width: 100%; table-layout: fixed; border-collapse: collapse; }
  table.items th, table.items td { overflow-wrap: break-word; word-break: break-word; }
  table.items th:first-child, table.items td:first-child { width: auto; }
  col.c-idx    { width: 9mm; }
  col.c-qty    { width: 16mm; }
  col.c-rate   { width: 26mm; }
  col.c-amount { width: 28mm; }
  /* description takes whatever is left */
  img { max-width: 100%; height: auto; }
  pre, code { white-space: pre-wrap; word-break: break-word; }

  @media print {
    .sheet { max-width: none; box-shadow: none; }
    table.items thead { display: table-header-group; }   /* repeat on page two */
    table.items tr { break-inside: avoid; }
    .totals, .sign, footer { break-inside: avoid; }
  }
  * { box-sizing: border-box; }
  body { font-family: \"Nirmala UI\", \"Hind Siliguri\", \"Noto Sans Bengali\", system-ui,
         -apple-system, \"Segoe UI\", Arial, sans-serif;
         color:#1f2937; margin:0; padding:24px; background:#eef1f5;
         font-size:13px; line-height:1.6; -webkit-font-smoothing:antialiased; }
  .sheet { max-width:820px; margin:0 auto; background:#fff; padding:40px 42px;
           box-shadow:0 1px 3px rgba(15,23,42,.08), 0 12px 36px rgba(15,23,42,.10);
           border-radius:8px; }
  .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums;
         font-feature-settings:'tnum' 1; }
  .idx { width:34px; color:#94a3b8; font-size:11.5px; }
  .period { font-size:11px; color:#64748b; }
  .empty { text-align:center; color:#94a3b8; padding:18px; }
  table.items { width:100%; border-collapse:collapse; margin-bottom:20px; }

  /* the totals read like a receipt: quiet lines, then the one that matters */
  .totals { width:290px; margin-left:auto; }
  .totals td { padding:7px 0; font-size:13px; color:#475569; }
  .totals td:last-child { text-align:right; font-variant-numeric:tabular-nums; color:#1f2937; }
  .totals tr.grand td { border-top:2px solid {$accent}; padding-top:12px;
                        font-size:18px; font-weight:700; color:{$accent};
                        letter-spacing:-.4px; }
  .totals tr.due td { color:#b91c1c; font-weight:600; }

  .stamp { display:inline-block; padding:4px 13px; border-radius:20px; font-size:10.5px;
           font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
  .paid { background:#dcfce7; color:#15803d; }
  .partial { background:#fef3c7; color:#b45309; }
  .unpaid { background:#fee2e2; color:#b91c1c; }
  .notes { margin-top:28px; padding-top:18px; border-top:1px solid #e2e8f0;
           font-size:12px; color:#475569; display:flex; gap:32px; flex-wrap:wrap; }
  .notes > div { flex:1 1 220px; min-width:0; }
  .notes h4 { margin:0 0 5px; font-size:10.5px; letter-spacing:.12em;
              text-transform:uppercase; color:#94a3b8; font-weight:700; }
  .sign { margin-top:34px; text-align:right; }
  .sign img { max-height:60px; display:block; margin-left:auto; }
  .sign span { display:inline-block; border-top:1px solid #94a3b8; padding-top:5px;
               margin-top:6px; font-size:12px; color:#475569; }
  footer { margin-top:30px; padding-top:14px; border-top:1px solid #e2e8f0;
           text-align:center; font-size:11px; color:#94a3b8; }
  footer a { color:{$accent}; text-decoration:none; }
  .toolbar { max-width:820px; margin:0 auto 14px; text-align:right; }
  .toolbar button { background:{$accent}; color:#fff; border:0; padding:10px 22px;
                    border-radius:7px; font-size:14px; font-weight:550; cursor:pointer;
                    box-shadow:0 1px 3px rgba(15,23,42,.2); }
  .toolbar button:active { transform:translateY(1px); }
  @media print { body { background:#fff; padding:0; }
                 .sheet { box-shadow:none; padding:0; border-radius:0; }
                 .toolbar { display:none; } }
";

        $byTemplate = [
            // the familiar one: coloured bar, filled table head
            'classic' => "
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:24px;
         border-bottom:2px solid {$accent}; padding-bottom:18px; margin-bottom:22px; }
  .brand h1 { margin:0 0 4px; font-size:21px; color:{$accent}; letter-spacing:.3px; }
  .doc-meta h2 { margin:0 0 8px; font-size:24px; letter-spacing:3px; color:#0f172a; }
  table.items th { background:{$accent}; color:#fff; text-align:left; padding:9px 10px;
                   font-size:11px; text-transform:uppercase; letter-spacing:.6px; }
  table.items td { padding:9px 10px; border-bottom:1px solid #e2e8f0; vertical-align:top; }
  table.items tr:nth-child(even) td { background:#f8fafc; }
",
            // roomy and quiet, accent only where it counts
            'modern' => "
  .sheet { padding:46px 46px; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:30px;
         padding-bottom:26px; margin-bottom:30px; }
  .brand h1 { margin:0 0 4px; font-size:19px; color:#0f172a; font-weight:600; letter-spacing:-.2px; }
  .doc-meta h2 { margin:0 0 10px; font-size:12px; letter-spacing:.28em; color:{$accent};
                 font-weight:700; text-transform:uppercase; }
  table.items th { text-align:left; padding:0 10px 9px; border-bottom:1px solid #cbd5e1;
                   font-size:10px; text-transform:uppercase; letter-spacing:.14em; color:#64748b; }
  table.items td { padding:13px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
",
            // many lines on one page
            'compact' => "
  .sheet { padding:28px 30px; font-size:12px; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:18px;
         border-bottom:1px solid {$accent}; padding-bottom:12px; margin-bottom:16px; }
  .brand h1 { margin:0 0 2px; font-size:17px; color:{$accent}; }
  .doc-meta h2 { margin:0 0 6px; font-size:18px; letter-spacing:2px; color:#0f172a; }
  table.items th { background:#f1f5f9; text-align:left; padding:6px 8px; font-size:10px;
                   text-transform:uppercase; letter-spacing:.5px; color:#475569; }
  table.items td { padding:5px 8px; border-bottom:1px solid #eef2f7; }
  .totals td { padding:4px 0; }
",
            // a solid band across the head, numbers you can read across a desk
            'bold' => "
  .sheet { padding:0 0 38px; overflow:hidden; }
  .top { background:{$accent}; color:#fff; padding:30px 40px 26px; margin:0 0 28px;
         display:flex; justify-content:space-between; align-items:flex-start; gap:24px; }
  .brand h1 { margin:0 0 4px; font-size:24px; color:#fff; font-weight:700; letter-spacing:-.4px; }
  .brand p { color:rgba(255,255,255,.82) !important; }
  .doc-meta h2 { margin:0 0 8px; font-size:30px; letter-spacing:-.5px; color:#fff; font-weight:700; }
  .doc-meta td, .doc-meta td:first-child { color:rgba(255,255,255,.85) !important; }
  .parties, .items, .totals, .notes, .sign, footer { margin-left:40px; margin-right:40px; }
  table.items { width:calc(100% - 80px); margin-left:40px; }
  table.items th { text-align:left; padding:10px 12px; background:#f1f5f9;
                   font-size:11px; text-transform:uppercase; letter-spacing:.7px; color:#334155; }
  table.items td { padding:12px 12px; border-bottom:1px solid #e8edf3; }
  .totals { width:320px; margin-right:40px; }
  .totals tr.grand td { font-size:19px; }
",
            // nothing but air and type
            'minimal' => "
  .sheet { padding:52px 48px; box-shadow:none; border:1px solid #eee; }
  .top { display:flex; justify-content:space-between; align-items:baseline; gap:30px;
         margin-bottom:44px; }
  .brand h1 { margin:0; font-size:16px; font-weight:600; letter-spacing:.02em; color:#111; }
  .brand p { font-size:11.5px; }
  .doc-meta h2 { margin:0 0 6px; font-size:11px; letter-spacing:.3em; text-transform:uppercase;
                 color:#999; font-weight:600; }
  table.items th { text-align:left; padding:0 0 10px; font-size:10px; letter-spacing:.2em;
                   text-transform:uppercase; color:#aaa; border:0; }
  table.items td { padding:10px 0; border:0; }
  table.items td:not(:first-child):not(:nth-child(2)) { padding-left:14px; }
  .totals tr.grand td { border-top:1px solid #111; color:#111; }
  .notes { border-top:1px solid #eee; }
",
            // a bordered sheet, like a receipt book
            'stamp' => "
  .sheet { padding:34px; border:3px double {$accent}; border-radius:2px; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:22px;
         border-bottom:1px dashed {$accent}; padding-bottom:16px; margin-bottom:20px; }
  .brand h1 { margin:0 0 4px; font-size:20px; color:{$accent}; letter-spacing:.04em; }
  .doc-meta h2 { margin:0 0 8px; font-size:20px; letter-spacing:.2em; color:{$accent};
                 border:2px solid {$accent}; padding:4px 12px; display:inline-block; }
  table.items th { text-align:left; padding:8px 10px; border-top:1px solid {$accent};
                   border-bottom:1px solid {$accent}; font-size:10.5px;
                   text-transform:uppercase; letter-spacing:.1em; }
  table.items td { padding:8px 10px; border-bottom:1px dotted #cbd5e1; }
  .totals tr.grand td { border-top:1px solid {$accent}; }
",
            // your details run down a coloured column
            'sidebar' => "
  .sheet { padding:0; display:flex; overflow:hidden; }
  .top { display:block; background:{$accent}; color:#fff; width:220px; flex:none;
         padding:34px 24px; margin:0; border:0; }
  .brand h1 { margin:0 0 6px; font-size:18px; color:#fff; }
  .brand p { color:rgba(255,255,255,.85) !important; font-size:11.5px; }
  .doc-meta { text-align:left; margin-top:28px; min-width:0; }
  .doc-meta h2 { margin:0 0 10px; font-size:14px; letter-spacing:.2em; color:#fff; }
  .doc-meta table { margin:0; }
  .doc-meta td, .doc-meta td:first-child { color:rgba(255,255,255,.85) !important;
                 padding-left:0; font-size:11.5px; }
  .sheet > *:not(.top) { margin-left:26px; margin-right:26px; }
  .sheet > .top ~ * { max-width:calc(100% - 272px); }
  table.items th { text-align:left; padding:9px 10px; background:#f4f6fa; font-size:10.5px;
                   text-transform:uppercase; letter-spacing:.08em; color:#475569; }
  table.items td { padding:9px 10px; border-bottom:1px solid #eef2f7; }
",
            // plain as a bank statement
            'statement' => "
  .sheet { padding:34px 36px; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:20px;
         border-bottom:1px solid #d5dae2; padding-bottom:14px; margin-bottom:18px; }
  .brand h1 { margin:0 0 3px; font-size:17px; color:#1f2937; font-weight:600; }
  .doc-meta h2 { margin:0 0 6px; font-size:13px; letter-spacing:.16em; text-transform:uppercase;
                 color:#475569; }
  table.items th { text-align:left; padding:7px 10px; background:#eef1f5; font-size:10.5px;
                   text-transform:uppercase; letter-spacing:.08em; color:#475569;
                   border-top:1px solid #d5dae2; border-bottom:1px solid #d5dae2; }
  table.items td { padding:7px 10px; border-bottom:1px solid #f0f2f6; }
  table.items tr:nth-child(odd) td { background:#fafbfc; }
  .totals tr.grand td { border-top:1px solid #334155; color:#1f2937; }
",
            // reads like a letter on headed paper
            'letter' => "
  .sheet { padding:48px 52px; font-size:13.5px; line-height:1.7; }
  .top { display:block; text-align:center; border-bottom:1px solid #e2e8f0;
         padding-bottom:20px; margin-bottom:28px; }
  .brand h1 { margin:0 0 6px; font-size:22px; color:{$accent}; letter-spacing:.03em; }
  .brand img { margin:0 auto 10px; }
  .doc-meta { text-align:center; margin-top:14px; min-width:0; }
  .doc-meta h2 { margin:0 0 8px; font-size:13px; letter-spacing:.24em; text-transform:uppercase;
                 color:#64748b; }
  .doc-meta table { margin:0 auto; }
  .doc-meta td:first-child { padding-left:0; }
  table.items th { text-align:left; padding:8px 10px; border-bottom:1px solid #94a3b8;
                   font-size:10.5px; text-transform:uppercase; letter-spacing:.1em; color:#475569; }
  table.items td { padding:10px 10px; border-bottom:1px solid #f1f5f9; }
  .sign { text-align:left; }
  .sign img, .sign span { margin-left:0; }
",
            // ruled like an accounts book
            'ledger' => "
  body { font-family: Georgia, 'Times New Roman', 'Noto Serif Bengali', serif; }
  .sheet { padding:36px 38px; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:22px;
         border-bottom:3px double #334155; padding-bottom:16px; margin-bottom:20px; }
  .brand h1 { margin:0 0 4px; font-size:21px; color:#1f2937; font-weight:normal;
              letter-spacing:.02em; }
  .doc-meta h2 { margin:0 0 8px; font-size:22px; letter-spacing:.06em; color:#1f2937;
                 font-weight:normal; }
  table.items th { text-align:left; padding:8px 10px; border-bottom:2px solid #334155;
                   font-size:11px; letter-spacing:.06em; text-transform:uppercase;
                   color:#334155; font-weight:normal; }
  table.items td { padding:9px 10px; border-bottom:1px solid #dfe3ea; }
  table.items td.num, table.items th.num { border-left:1px solid #dfe3ea; }
  .totals tr.grand td { border-top:3px double #334155; color:#1f2937; }
",
        ];

        return $shared . ($byTemplate[$design['template']] ?? $byTemplate['classic']);
    }
}
