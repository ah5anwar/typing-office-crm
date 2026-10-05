<?php
/**
 * AH5 Office - printable invoice and quotation
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Rendered as HTML on purpose: the browser's "Save as PDF" keeps Bengali
 * conjuncts correct, which PDF libraries mangle without a shaping engine.
 * The look comes from Settings > Invoice design.
 */

declare(strict_types=1);

/**
 * @param array  $doc   invoice or quotation row (+ customer fields)
 * @param array  $items line items
 * @param string $kind  'invoice' | 'quotation'
 */
function render_print_document(array $doc, array $items, string $kind): void
{
    $design = InvoiceDesign::settings();

    // your own HTML wins, if you switched it on
    if ($design['use_custom'] && trim($design['custom_html']) !== '') {
        echo InvoiceDesign::renderCustom($design['custom_html'], $doc, $items, $kind);
        return;
    }

    $company   = InvoiceDesign::company();
    $isInvoice = $kind === 'invoice';
    $title     = $isInvoice ? 'INVOICE' : 'QUOTATION';
    $docNo     = (string) ($isInvoice ? $doc['invoice_no'] : $doc['quote_no']);
    $docDate   = (string) ($isInvoice ? $doc['invoice_date'] : $doc['quote_date']);
    $secondLabel = $isInvoice ? 'Due Date' : 'Valid Until';
    $secondDate  = InvoiceDesign::secondDate($doc, $isInvoice);

    $cur  = (string) $doc['currency'];
    $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $m    = static fn ($v): string => Helper::formatMoney($v);
    $logo = $design['show_logo'] ? InvoiceDesign::imageSrc('company_logo') : null;
    $sign = $design['show_signature'] ? InvoiceDesign::imageSrc('company_signature') : null;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($docNo) ?></title>
<style><?= InvoiceDesign::css($design) ?>
  .brand p { margin:1px 0; font-size:12px; color:#64748b; }
  .brand img { max-height:64px; margin-bottom:8px; }
  .doc-meta { text-align:right; min-width:210px; }
  .doc-meta table { margin-left:auto; border-collapse:collapse; }
  .doc-meta td { padding:2px 0 2px 14px; font-size:12px; }
  .doc-meta td:first-child { color:#64748b; padding-left:0; }
  .parties { display:flex; justify-content:space-between; gap:30px; margin-bottom:22px; }
  .parties h3 { margin:0 0 6px; font-size:11px; text-transform:uppercase;
                letter-spacing:1px; color:#64748b; }
  .parties strong { font-size:14px; }
  .parties p { margin:2px 0; font-size:12px; color:#475569; }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>

<div class="sheet">
  <div class="top">
    <div class="brand">
      <?php if ($logo !== null): ?><img src="<?= $logo ?>" alt=""><?php endif; ?>
      <h1><?= $e($company['name']) ?></h1>
      <?php foreach (['address', 'phone', 'email', 'website'] as $line): ?>
        <?php if ($company[$line] !== ''): ?><p><?= $e($company[$line]) ?></p><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($company['tax'] !== ''): ?><p>TRN/BIN: <?= $e($company['tax']) ?></p><?php endif; ?>
      <?php if ($company['licence'] !== ''): ?><p>Licence: <?= $e($company['licence']) ?></p><?php endif; ?>
    </div>
    <div class="doc-meta">
      <h2><?= $title ?></h2>
      <table>
        <tr><td>No</td><td><strong><?= $e($docNo) ?></strong></td></tr>
        <tr><td>Date</td><td><?= $e(date('d/m/Y', strtotime($docDate))) ?></td></tr>
        <?php if ($secondDate !== ''): ?>
          <tr><td><?= $secondLabel ?></td><td><?= $e($secondDate) ?></td></tr>
        <?php endif; ?>
        <tr><td>Currency</td><td><?= $e($cur) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="parties">
    <div>
      <h3><?= $isInvoice ? 'Bill To' : 'Quotation For' ?></h3>
      <strong><?= $e($doc['company_name'] ?: $doc['customer_name']) ?></strong>
      <?php if (!empty($doc['company_name'])): ?><p><?= $e($doc['customer_name']) ?></p><?php endif; ?>
      <?php if (!empty($doc['customer_address'])): ?><p><?= $e($doc['customer_address']) ?></p><?php endif; ?>
      <?php if (!empty($doc['customer_phone'])): ?><p><?= $e($doc['customer_phone']) ?></p><?php endif; ?>
    </div>
    <?php if ($isInvoice):
        $due  = (float) $doc['due_amount'];
        $cls  = $due <= 0.004 ? 'paid' : ((float) $doc['paid_amount'] > 0 ? 'partial' : 'unpaid');
        $word = $due <= 0.004 ? 'Paid' : ((float) $doc['paid_amount'] > 0 ? 'Partially Paid' : 'Unpaid'); ?>
      <div style="text-align:right">
        <h3>Status</h3>
        <span class="stamp <?= $cls ?>"><?= $word ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($doc['subject'])): ?>
    <p style="margin:0 0 14px"><strong>Subject:</strong> <?= $e($doc['subject']) ?></p>
  <?php endif; ?>

  <?= InvoiceDesign::itemsTable($items, $design, $cur) ?>

  <table class="totals">
    <tr><td>Subtotal</td><td><?= $m($doc['subtotal']) ?> <?= $e($cur) ?></td></tr>
    <?php if ((float) $doc['discount_amount'] > 0): ?>
      <tr><td>Discount<?= $doc['discount_type'] === 'percent' ? ' (' . $m($doc['discount_value']) . '%)' : '' ?></td>
          <td>- <?= $m($doc['discount_amount']) ?> <?= $e($cur) ?></td></tr>
    <?php endif; ?>
    <?php if ((float) $doc['vat_amount'] > 0): ?>
      <tr><td>VAT (<?= $m($doc['vat_percent']) ?>%)</td>
          <td><?= $m($doc['vat_amount']) ?> <?= $e($cur) ?></td></tr>
    <?php endif; ?>
    <tr class="grand"><td>Total</td><td><?= $m($doc['total']) ?> <?= $e($cur) ?></td></tr>
    <?php if ($isInvoice): ?>
      <tr><td>Paid</td><td><?= $m($doc['paid_amount']) ?> <?= $e($cur) ?></td></tr>
      <tr class="due"><td>Balance Due</td><td><?= $m($doc['due_amount']) ?> <?= $e($cur) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php
    $hasTerms = !empty($doc['terms']) || !empty($doc['notes']);
    $showBank = $design['show_bank'] && $company['bank'] !== '';
  ?>
  <?php if ($showBank || $hasTerms): ?>
    <div class="notes">
      <?php if ($showBank): ?>
        <div>
          <h4>Bank details</h4>
          <p><?= nl2br($e($company['bank'])) ?></p>
        </div>
      <?php endif; ?>
      <?php if (!empty($doc['terms'])): ?>
        <div><h4>Terms</h4><p><?= nl2br($e($doc['terms'])) ?></p></div>
      <?php endif; ?>
      <?php if (!empty($doc['notes'])): ?>
        <div><h4>Notes</h4><p><?= nl2br($e($doc['notes'])) ?></p></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($sign !== null || $design['show_signature']): ?>
    <div class="sign">
      <?php if ($sign !== null): ?><img src="<?= $sign ?>" alt=""><?php endif; ?>
      <span><?= $e($company['owner'] !== '' ? $company['owner'] : $company['name']) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($design['footer_note'] !== ''): ?>
    <p style="margin-top:24px;text-align:center;color:#64748b;font-size:12px">
      <?= $e($design['footer_note']) ?></p>
  <?php endif; ?>

  <?php if ($design['show_credit']): ?>
    <footer>
      Designed &amp; Developed by <a href="https://anwar.com.bd" target="_blank" rel="noopener">Anwar Hossain</a>
      &middot; <a href="https://creativesit.com" target="_blank" rel="noopener">Creatives iT</a>
    </footer>
  <?php endif; ?>
</div>
</body>
</html><?php
}
