<?php
/**
 * AH5 Office - the printable business report
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Everything on one sheet: what came in, what went out, whether that is
 * profit or loss, who owes you, who you owe, where the money sits.
 * Rendered as HTML so the browser's "Save as PDF" keeps Bengali correct.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

PrintAuth::check('report', 0);

$from = Helper::parseDate($_GET['from'] ?? null, date('Y-m-01'));
$to   = Helper::parseDate($_GET['to'] ?? null, date('Y-m-d'));

// the report controller already knows how to work all this out
$_GET['from'] = $from;
$_GET['to']   = $to;

/** Grab the report body without letting Response::ok end the request. */
$data = (static function () {
    ob_start();
    $captured = null;
    Response::$capture = static function (array $payload) use (&$captured): void {
        $captured = $payload['data'] ?? null;
    };
    ReportController::full();
    Response::$capture = null;
    ob_end_clean();
    return $captured;
})();

if (!is_array($data)) {
    http_response_code(500);
    exit('Could not build the report.');
}

$design = InvoiceDesign::settings();
$accent = $design['accent'];
$cur    = (string) $data['currency'];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$m      = static fn ($v): string => Helper::formatMoney($v);
$d      = static fn ($v): string => $v ? date('d/m/Y', strtotime((string) $v)) : '—';
$logo   = InvoiceDesign::imageSrc('company_logo');

$t = $data['trading'];
$w = $data['work'];
$p = $data['position'];
$isProfit = $t['result'] === 'profit';
$isQuiet  = $t['result'] === 'quiet';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report <?= $e($d($from)) ?> – <?= $e($d($to)) ?></title>
<style>
  @page { size: <?= $design['paper'] === 'Letter' ? 'Letter' : 'A4' ?>; margin: 12mm; }
  * { box-sizing: border-box; }
  body { font-family: "Nirmala UI", "Hind Siliguri", "Noto Sans Bengali", system-ui,
         -apple-system, "Segoe UI", Arial, sans-serif;
         margin: 0; padding: 22px; background: #f1f5f9; color: #1f2937;
         font-size: 12.5px; line-height: 1.5; }
  .sheet { max-width: 900px; margin: 0 auto; background: #fff; padding: 34px 38px;
           box-shadow: 0 6px 24px rgba(15,23,42,.10); border-radius: 6px; }

  header { display: flex; justify-content: space-between; align-items: flex-start;
           gap: 24px; border-bottom: 2px solid <?= $accent ?>; padding-bottom: 16px; margin-bottom: 22px; }
  header img { max-height: 58px; margin-bottom: 8px; }
  header h1 { margin: 0 0 3px; font-size: 19px; color: <?= $accent ?>; }
  header p { margin: 1px 0; font-size: 11.5px; color: #64748b; }
  .title { text-align: right; }
  .title h2 { margin: 0 0 6px; font-size: 15px; letter-spacing: .16em; text-transform: uppercase; color: #0f172a; }
  .title p { margin: 0; font-size: 12px; color: #475569; }

  h3 { margin: 26px 0 10px; font-size: 11px; letter-spacing: .14em; text-transform: uppercase;
       color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
  h3:first-of-type { margin-top: 0; }

  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  th { text-align: left; padding: 7px 9px; background: #f8fafc; font-size: 10px;
       text-transform: uppercase; letter-spacing: .08em; color: #64748b;
       border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
  td { padding: 7px 9px; border-bottom: 1px solid #f1f5f9; }
  tr.total td { border-top: 1px solid #94a3b8; border-bottom: 0; font-weight: 700; }

  .cards { display: flex; gap: 12px; flex-wrap: wrap; }
  .kpi { flex: 1 1 150px; border: 1px solid #e2e8f0; border-left: 3px solid #cbd5e1;
         border-radius: 5px; padding: 11px 13px; }
  .kpi span { display: block; font-size: 9.5px; letter-spacing: .12em;
              text-transform: uppercase; color: #64748b; margin-bottom: 4px; }
  .kpi strong { font-size: 19px; letter-spacing: -.4px; }
  .kpi.in    { border-left-color: #0b6e62; } .kpi.in strong    { color: #0b6e62; }
  .kpi.out   { border-left-color: #ab2318; } .kpi.out strong   { color: #ab2318; }
  .kpi.hold  { border-left-color: #8f5a00; } .kpi.hold strong  { color: #8f5a00; }
  .kpi small { display: block; color: #94a3b8; font-size: 10.5px; margin-top: 2px; }

  .verdict { margin: 18px 0 4px; padding: 14px 16px; border-radius: 6px;
             background: <?= $isQuiet ? '#f1f5f9' : ($isProfit ? '#e4f1ee' : '#fbe9e7') ?>;
             color: <?= $isQuiet ? '#475569' : ($isProfit ? '#0b6e62' : '#ab2318') ?>;
             display: flex; justify-content: space-between; align-items: baseline; gap: 16px; }
  .verdict b { font-size: 21px; letter-spacing: -.5px; }

  .two { display: flex; gap: 22px; align-items: flex-start; }
  .two > div { flex: 1; min-width: 0; }

  .late { color: #ab2318; font-size: 11px; }
  footer { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e2e8f0;
           display: flex; justify-content: space-between; font-size: 10.5px; color: #94a3b8; }
  footer a { color: <?= $accent ?>; text-decoration: none; }

  .toolbar { max-width: 900px; margin: 0 auto 14px; text-align: right; }
  .toolbar button { background: <?= $accent ?>; color: #fff; border: 0; padding: 9px 20px;
                    border-radius: 6px; font-size: 14px; cursor: pointer; }
  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; padding: 0; border-radius: 0; max-width: none; }
    .toolbar { display: none; }
    h3 { break-after: avoid; }
    table { break-inside: auto; }
    tr { break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>

<div class="sheet">
  <header>
    <div>
      <?php if ($logo !== null): ?><img src="<?= $logo ?>" alt=""><?php endif; ?>
      <h1><?= $e($data['company']['name']) ?></h1>
      <?php foreach (['address', 'phone', 'email'] as $line): ?>
        <?php if ($data['company'][$line] !== ''): ?><p><?= $e($data['company'][$line]) ?></p><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="title">
      <h2>Business Report</h2>
      <p><?= $e($d($from)) ?> &ndash; <?= $e($d($to)) ?></p>
      <p style="color:#94a3b8;font-size:11px">Prepared <?= date('d/m/Y H:i') ?></p>
    </div>
  </header>

  <!-- the one line that matters -->
  <div class="verdict">
    <div>
      <?= $isQuiet
            ? 'No money moved in this period'
            : ($isProfit ? 'You are ahead for this period' : 'You are behind for this period') ?>
      <div style="font-size:11.5px;opacity:.85">
        <?= $m($t['money_in']) ?> came in, <?= $m($t['money_out']) ?> went out
      </div>
    </div>
    <b><?= $isProfit && !$isQuiet ? '+' : '' ?><?= $m($t['net']) ?> <?= $e($cur) ?></b>
  </div>

  <h3>Where you stand today</h3>
  <div class="cards">
    <div class="kpi in"><span>Money in hand</span><strong><?= $m($p['in_hand']) ?></strong>
      <small>across <?= count($data['accounts']) ?> account(s)</small></div>
    <div class="kpi out"><span>Owed to me</span><strong><?= $m($p['receivable']) ?></strong>
      <small><?= count($data['who_owes_me']) ?> customer(s)</small></div>
    <div class="kpi hold"><span>I owe suppliers</span><strong><?= $m($p['payable']) ?></strong>
      <small><?= count($data['i_owe']) ?> supplier(s)</small></div>
    <div class="kpi"><span>Advance held</span><strong><?= $m($p['advance_held']) ?></strong>
      <small>customers' money, unapplied</small></div>
    <div class="kpi"><span>Net worth</span><strong><?= $m($p['net_worth']) ?></strong>
      <small>in hand + owed − payable</small></div>
  </div>

  <h3>Money in and out, <?= $e($d($from)) ?> to <?= $e($d($to)) ?></h3>
  <table>
    <tbody>
      <tr><td>Received from customers</td><td class="num"><?= $m($t['from_customers']) ?></td></tr>
      <tr><td>Other income</td><td class="num"><?= $m($t['other_income']) ?></td></tr>
      <tr class="total"><td>Total in</td><td class="num"><?= $m($t['money_in']) ?></td></tr>
      <tr><td>Office expenses</td><td class="num"><?= $m($t['office_expense']) ?></td></tr>
      <tr><td>Paid to suppliers</td><td class="num"><?= $m($t['supplier_paid']) ?></td></tr>
      <tr class="total"><td>Total out</td><td class="num"><?= $m($t['money_out']) ?></td></tr>
      <tr class="total"><td><?= $isQuiet ? 'Net' : ($isProfit ? 'Profit' : 'Loss') ?></td>
          <td class="num" style="color:<?= $isProfit ? '#0b6e62' : '#ab2318' ?>">
            <?= $m($t['net']) ?> <?= $e($cur) ?></td></tr>
    </tbody>
  </table>

  <h3>What the work itself earned</h3>
  <table>
    <tbody>
      <tr><td>Billed to customers</td><td class="num"><?= $m($w['billed']) ?></td></tr>
      <tr><td>What it cost me</td><td class="num"><?= $m($w['cost']) ?></td></tr>
      <tr class="total"><td>Gross profit</td>
          <td class="num"><?= $m($w['gross_profit']) ?> <?= $e($cur) ?>
            <span style="color:#94a3b8;font-weight:400">(<?= $w['margin'] ?>%)</span></td></tr>
    </tbody>
  </table>

  <div class="two">
    <div>
      <h3>Who owes me</h3>
      <table>
        <thead><tr><th>Customer</th><th class="num">Due</th><th>Since</th></tr></thead>
        <tbody>
        <?php if (!$data['who_owes_me']): ?>
          <tr><td colspan="3" style="color:#94a3b8">Nobody. Every invoice is settled.</td></tr>
        <?php else: foreach ($data['who_owes_me'] as $r): ?>
          <tr>
            <td><?= $e($r['company_name'] ?: $r['name']) ?></td>
            <td class="num"><?= $m($r['due']) ?></td>
            <td><?= $e($d($r['oldest_due'])) ?>
              <?php if ((int) $r['days_over'] > 0): ?>
                <span class="late"><?= (int) $r['days_over'] ?>d over</span>
              <?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        <?php if ($data['who_owes_me']): ?>
          <tr class="total"><td>Total</td><td class="num"><?= $m($p['receivable']) ?></td><td></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div>
      <h3>Who I owe</h3>
      <table>
        <thead><tr><th>Supplier</th><th class="num">Due</th></tr></thead>
        <tbody>
        <?php if (!$data['i_owe']): ?>
          <tr><td colspan="2" style="color:#94a3b8">Nothing outstanding.</td></tr>
        <?php else: foreach ($data['i_owe'] as $r): ?>
          <tr><td><?= $e($r['company_name'] ?: $r['name']) ?></td>
              <td class="num"><?= $m($r['due']) ?></td></tr>
        <?php endforeach; ?>
          <tr class="total"><td>Total</td><td class="num"><?= $m($p['payable']) ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <h3>Where the money sits</h3>
  <table>
    <thead><tr><th>Account</th><th>Kind</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($data['accounts'] as $a): ?>
      <tr><td><?= $e($a['name']) ?></td><td><?= $e($a['type']) ?></td>
          <td class="num"><?= $m($a['balance']) ?></td></tr>
    <?php endforeach; ?>
      <tr class="total"><td colspan="2">Total in hand</td>
          <td class="num"><?= $m($p['in_hand']) ?> <?= $e($cur) ?></td></tr>
    </tbody>
  </table>

  <?php if ($data['expense_breakdown']): ?>
    <h3>What the money went on</h3>
    <table>
      <thead><tr><th>Category</th><th class="num">Entries</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($data['expense_breakdown'] as $r): ?>
        <tr><td><?= $e($r['category']) ?></td><td class="num"><?= (int) $r['entries'] ?></td>
            <td class="num"><?= $m($r['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($data['earned_by_service']): ?>
    <h3>Which work earned it</h3>
    <table>
      <thead><tr><th>Service</th><th class="num">Qty</th><th class="num">Billed</th>
        <th class="num">Cost</th><th class="num">Profit</th></tr></thead>
      <tbody>
      <?php foreach ($data['earned_by_service'] as $r):
        $profit = (float) $r['billed'] - (float) $r['cost']; ?>
        <tr>
          <td><?= $e($r['service']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float) $r['qty'], 2), '0'), '.') ?></td>
          <td class="num"><?= $m($r['billed']) ?></td>
          <td class="num"><?= $m($r['cost']) ?></td>
          <td class="num" style="color:<?= $profit >= 0 ? '#0b6e62' : '#ab2318' ?>"><?= $m($profit) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($data['coming_up']): ?>
    <h3>Coming up — papers expiring within 60 days</h3>
    <table>
      <thead><tr><th>Document</th><th>Customer</th><th>Expires</th><th class="num">Days</th></tr></thead>
      <tbody>
      <?php foreach ($data['coming_up'] as $r): ?>
        <tr>
          <td><?= $e($r['title']) ?></td>
          <td><?= $e($r['company_name'] ?: $r['customer_name']) ?></td>
          <td><?= $e($d($r['expiry_date'])) ?></td>
          <td class="num" style="<?= (int) $r['days_left'] < 15 ? 'color:#ab2318' : '' ?>">
            <?= (int) $r['days_left'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <footer>
    <span><?= $e($data['company']['name']) ?> · <?= (int) $data['open_work'] ?> job(s) still open</span>
    <?php if ($design['show_credit']): ?>
      <span>Designed &amp; Developed by
        <a href="https://anwar.com.bd" target="_blank" rel="noopener">Anwar Hossain</a> ·
        <a href="https://creativesit.com" target="_blank" rel="noopener">Creatives iT</a></span>
    <?php endif; ?>
  </footer>
</div>
</body>
</html>
