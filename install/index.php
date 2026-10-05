<?php
/**
 * AH5 Office - installation wizard
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Upload the whole folder, open  https://yourdomain/install/  and follow
 * the five steps. The database tables are created for you, so there is no
 * .sql file to import by hand.
 */

declare(strict_types=1);

require __DIR__ . '/installer.php';

session_start();
mb_internal_encoding('UTF-8');

if (Installer::isInstalled() && (($_GET['step'] ?? '') !== 'done')) {
    render_locked();
    exit;
}

$state  = $_SESSION['ah5_install'] ?? [];
$step   = (int) ($_GET['step'] ?? 1);
$errors = [];
$notice = null;

// ---------------------------------------------------------------- actions

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'database') {
        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
        ];

        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = 'Database name and username are both required.';
        } else {
            [$pdo, $err] = Installer::connect($db);
            if ($pdo === null) {
                $errors[] = $err;
            } else {
                [$done, $sqlErrors] = Installer::importSchema($pdo);
                if ($sqlErrors) {
                    $errors[] = 'The tables could not be created:';
                    foreach ($sqlErrors as $e) {
                        $errors[] = $e;
                    }
                } else {
                    $state['db']     = $db;
                    $state['tables'] = Installer::tableCount($pdo, $db['name']);
                    $state['ran']    = $done;
                    $_SESSION['ah5_install'] = $state;
                    header('Location: ?step=3');
                    exit;
                }
            }
        }
        $step = 2;
    }

    if ($action === 'admin') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass  = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password2'] ?? '');

        if ($name === '' || $email === '') {
            $errors[] = 'Your name and email are both required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look right.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'The password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $errors[] = 'The two passwords do not match.';
        } else {
            [$pdo, $err] = Installer::connect($state['db'] ?? []);
            if ($pdo === null) {
                $errors[] = $err;
            } else {
                [$ok, $problem] = Installer::createAdmin($pdo, $name, $email, $pass);
                if (!$ok) {
                    $errors[] = $problem;
                } else {
                    $state['admin_email'] = mb_strtolower($email);
                    $state['admin_name']  = $name;
                    $_SESSION['ah5_install'] = $state;
                    header('Location: ?step=4');
                    exit;
                }
            }
        }
        $step = 3;
    }

    if ($action === 'business') {
        $timezone = (string) ($_POST['timezone'] ?? 'Asia/Dhaka');
        $currency = strtoupper((string) ($_POST['currency'] ?? 'BDT'));
        if (!in_array($timezone, Installer::timezones(), true)) {
            $timezone = 'Asia/Dhaka';
        }
        if (!in_array($currency, ['BDT', 'AED', 'USD'], true)) {
            $currency = 'BDT';
        }

        [$pdo, $err] = Installer::connect($state['db'] ?? []);
        if ($pdo === null) {
            $errors[] = $err;
            $step = 4;
        } else {
            $secret = Installer::randomSecret();
            $config = Installer::configContents($state['db'], $secret, $timezone, $currency);

            if (!Installer::writeConfig($config)) {
                $state['manual_config'] = $config;
                $errors[] = 'The core/ folder is not writable, so the settings file could not be saved. '
                          . 'Create core/config.local.php yourself with the contents shown below, then continue.';
                $_SESSION['ah5_install'] = $state;
                $step = 4;
            } else {
                Installer::saveSettings($pdo, [
                    'company_name'       => trim((string) ($_POST['company_name'] ?? 'Creatives iT')),
                    'company_owner'      => (string) ($state['admin_name'] ?? ''),
                    'company_email'      => trim((string) ($_POST['company_email'] ?? '')),
                    'company_phone'      => trim((string) ($_POST['company_phone'] ?? '')),
                    'company_address'    => trim((string) ($_POST['company_address'] ?? '')),
                    'self_whatsapp'      => trim((string) ($_POST['self_whatsapp'] ?? '')),
                    'default_timezone'   => $timezone,
                    'base_currency'      => $currency,
                    'cron_key'           => bin2hex(random_bytes(16)),
                ]);

                Installer::finish();
                $state['done'] = true;
                $_SESSION['ah5_install'] = $state;
                header('Location: ?step=done');
                exit;
            }
        }
    }

    if ($action === 'remove') {
        unset($_SESSION['ah5_install']);
        if (Installer::removeSelf()) {
            header('Location: ' . Installer::basePath() . '/admin/');
            exit;
        }
        $notice = 'The install folder could not delete itself. Please remove it in cPanel File Manager.';
        $step = 'done';
    }
}

// ------------------------------------------------------------------ guards

if ($step === 3 && empty($state['db'])) {
    $step = 2;
}
if ($step === 4 && empty($state['admin_email'])) {
    $step = empty($state['db']) ? 2 : 3;
}

$requirements = $step === 1 ? Installer::requirements() : [];
$canContinue  = $step === 1 ? Installer::requirementsPass($requirements) : true;

// ------------------------------------------------------------------ output

$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$stepNumber = $step === 'done' ? 5 : (int) $step;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install AH5 Office</title>
<style>
  :root {
    --ink:#14213D; --ink-soft:#1E3A5F; --paper:#FBFAF7; --surface:#fff;
    --rule:#E4E0D6; --text:#1B2233; --muted:#6C7383;
    --in:#0F766E; --in-bg:#E6F2F0; --out:#B3261E; --out-bg:#FBEAE8; --hold:#9A6100; --hold-bg:#FBF1DF;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--paper);color:var(--text);font-size:14.5px;line-height:1.55;
       font-family:"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .wrap{max-width:720px;margin:0 auto;padding:34px 18px 70px}
  header{margin-bottom:26px}
  header h1{margin:0 0 2px;font-size:23px;letter-spacing:-.3px}
  header p{margin:0;color:var(--muted);font-size:13.5px}
  .eyebrow{font-size:10px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#9AA0AC}

  .steps{display:flex;gap:0;margin:22px 0 24px;border:1px solid var(--rule);
         border-radius:6px;overflow:hidden;background:var(--surface)}
  .steps div{flex:1;padding:9px 6px;text-align:center;font-size:11.5px;color:var(--muted);
             border-right:1px solid var(--rule)}
  .steps div:last-child{border-right:0}
  .steps div.on{background:var(--ink);color:#fff;font-weight:600}
  .steps div.past{background:var(--in-bg);color:var(--in)}

  .card{background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        padding:22px;box-shadow:0 1px 2px rgba(20,33,61,.05)}
  h2{margin:0 0 4px;font-size:17px}
  .lede{margin:0 0 18px;color:var(--muted);font-size:13.5px}

  table.req{width:100%;border-collapse:collapse}
  table.req td{padding:8px 0;border-bottom:1px solid #F0EDE5;vertical-align:top}
  table.req td:last-child{text-align:right;white-space:nowrap}
  .pill{display:inline-block;padding:2px 9px;border-radius:3px;font-size:11px;font-weight:600;
        text-transform:uppercase;letter-spacing:.04em}
  .ok{background:var(--in-bg);color:var(--in)} .no{background:var(--out-bg);color:var(--out)}
  .warn{background:var(--hold-bg);color:var(--hold)}
  .detail{display:block;color:var(--muted);font-size:12px;margin-top:1px}

  label{display:block;margin-bottom:14px}
  label > span{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px}
  input,select{width:100%;padding:9px 11px;border:1px solid var(--rule);border-radius:6px;
               font-size:14px;font-family:inherit;background:var(--surface);color:var(--text)}
  input:focus,select:focus{outline:2px solid var(--ink-soft);outline-offset:1px;border-color:var(--ink-soft)}
  .row{display:flex;gap:14px}.row > *{flex:1}
  .hint{color:#9AA0AC;font-size:12px;margin-top:3px;display:block}

  button{background:var(--ink);color:#fff;border:0;padding:11px 22px;border-radius:6px;
         font-size:14.5px;font-family:inherit;font-weight:500;cursor:pointer}
  button:hover{background:var(--ink-soft)}
  button.ghost{background:var(--surface);color:var(--text);border:1px solid var(--rule)}
  .actions{display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:10px}

  .msg{padding:12px 14px;border-radius:6px;font-size:13.5px;margin-bottom:16px}
  .msg.err{background:var(--out-bg);color:#7f1d1d}
  .msg.info{background:var(--hold-bg);color:#7a4a00}
  .msg.good{background:var(--in-bg);color:#0b5b55}
  .msg ul{margin:6px 0 0;padding-left:18px}

  pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:6px;overflow:auto;
      font-size:12px;line-height:1.5}
  code{background:#F0EDE5;padding:1px 5px;border-radius:3px;font-size:12.5px}
  ol.next{padding-left:18px;margin:0}
  ol.next li{margin-bottom:9px}
  footer{margin-top:26px;text-align:center;font-size:11.5px;color:#9AA0AC}
  footer a{color:var(--ink-soft);text-decoration:none}
</style>
</head>
<body>
<div class="wrap">

<header>
  <span class="eyebrow">Creatives iT</span>
  <h1>Install AH5 Office</h1>
  <p>Five steps. The database tables are created for you.</p>
</header>

<div class="steps">
  <?php foreach (['Server', 'Database', 'Your account', 'Business', 'Finish'] as $i => $title): ?>
    <div class="<?= $stepNumber === $i + 1 ? 'on' : ($stepNumber > $i + 1 ? 'past' : '') ?>">
      <?= ($i + 1) . '. ' . $title ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">

<?php if ($errors): ?>
  <div class="msg err">
    <strong>Could not continue</strong>
    <ul><?php foreach ($errors as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($notice): ?>
  <div class="msg info"><?= $e($notice) ?></div>
<?php endif; ?>

<?php if ($step === 1): ?>

  <h2>Does this server fit?</h2>
  <p class="lede">A quick look at your hosting before anything is written.</p>

  <table class="req">
    <?php foreach ($requirements as [$label, $ok, $detail, $fatal]): ?>
      <tr>
        <td><?= $e($label) ?><span class="detail"><?= $e($detail) ?></span></td>
        <td><span class="pill <?= $ok ? 'ok' : ($fatal ? 'no' : 'warn') ?>">
          <?= $ok ? 'ok' : ($fatal ? 'needed' : 'optional') ?>
        </span></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if (!$canContinue): ?>
    <div class="msg err" style="margin-top:18px">
      Fix the items marked <strong>needed</strong>, then reload this page.
      In cPanel, MultiPHP Manager sets the PHP version and File Manager sets folder permissions.
    </div>
  <?php endif; ?>

  <div class="actions">
    <span></span>
    <?php if ($canContinue): ?>
      <a href="?step=2"><button type="button">Continue</button></a>
    <?php else: ?>
      <a href="?step=1"><button type="button" class="ghost">Check again</button></a>
    <?php endif; ?>
  </div>

<?php elseif ($step === 2): ?>

  <h2>Connect the database</h2>
  <p class="lede">
    Create an empty database and a user in cPanel &gt; MySQL Databases, give the user
    <strong>ALL PRIVILEGES</strong>, then put the details here. The tables are created automatically.
  </p>

  <form method="post">
    <input type="hidden" name="action" value="database">
    <label><span>Database host</span>
      <input name="db_host" value="<?= $e($_POST['db_host'] ?? ($state['db']['host'] ?? 'localhost')) ?>" required>
      <span class="hint">On cPanel this is almost always localhost</span>
    </label>
    <label><span>Database name</span>
      <input name="db_name" value="<?= $e($_POST['db_name'] ?? ($state['db']['name'] ?? '')) ?>" required
             placeholder="username_ah5office">
    </label>
    <div class="row">
      <label><span>Database user</span>
        <input name="db_user" value="<?= $e($_POST['db_user'] ?? ($state['db']['user'] ?? '')) ?>" required
               placeholder="username_ah5user">
      </label>
      <label><span>Database password</span>
        <input type="password" name="db_pass" value="">
      </label>
    </div>
    <div class="actions">
      <a href="?step=1"><button type="button" class="ghost">Back</button></a>
      <button type="submit">Connect and create tables</button>
    </div>
  </form>

<?php elseif ($step === 3): ?>

  <div class="msg good">
    Tables created — <strong><?= (int) ($state['tables'] ?? 0) ?></strong> tables are now in
    <code><?= $e($state['db']['name'] ?? '') ?></code>.
  </div>

  <h2>Your owner account</h2>
  <p class="lede">This account always has every permission. Staff accounts are added later from the panel.</p>

  <form method="post">
    <input type="hidden" name="action" value="admin">
    <label><span>Your name</span>
      <input name="name" value="<?= $e($_POST['name'] ?? 'Anwar Hossain') ?>" required>
    </label>
    <label><span>Email — you sign in with this</span>
      <input type="email" name="email" value="<?= $e($_POST['email'] ?? '') ?>" required>
    </label>
    <div class="row">
      <label><span>Password</span>
        <input type="password" name="password" required minlength="8">
        <span class="hint">at least 8 characters</span>
      </label>
      <label><span>Repeat password</span>
        <input type="password" name="password2" required minlength="8">
      </label>
    </div>
    <div class="actions">
      <a href="?step=2"><button type="button" class="ghost">Back</button></a>
      <button type="submit">Create my account</button>
    </div>
  </form>

<?php elseif ($step === 4): ?>

  <h2>About your business</h2>
  <p class="lede">These appear on invoices and quotations. You can change all of it later in Settings.</p>

  <?php if (!empty($state['manual_config'])): ?>
    <div class="msg info">
      Create the file <code>core/config.local.php</code> with exactly this, then submit again:
      <pre><?= $e($state['manual_config']) ?></pre>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="business">
    <label><span>Business name</span>
      <input name="company_name" value="<?= $e($_POST['company_name'] ?? 'Creatives iT') ?>" required>
    </label>
    <div class="row">
      <label><span>Phone</span>
        <input name="company_phone" value="<?= $e($_POST['company_phone'] ?? '') ?>">
      </label>
      <label><span>Email</span>
        <input type="email" name="company_email" value="<?= $e($_POST['company_email'] ?? '') ?>">
      </label>
    </div>
    <label><span>Address</span>
      <input name="company_address" value="<?= $e($_POST['company_address'] ?? '') ?>">
    </label>
    <div class="row">
      <label><span>Main currency</span>
        <select name="currency">
          <?php foreach (['BDT' => 'BDT — Bangladeshi Taka', 'AED' => 'AED — UAE Dirham', 'USD' => 'USD — US Dollar'] as $code => $title): ?>
            <option value="<?= $code ?>" <?= ($_POST['currency'] ?? 'BDT') === $code ? 'selected' : '' ?>><?= $e($title) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Reports total in this one; invoices can still use others</span>
      </label>
      <label><span>Time zone</span>
        <select name="timezone">
          <?php foreach (Installer::timezones() as $tz): ?>
            <option value="<?= $e($tz) ?>" <?= ($_POST['timezone'] ?? 'Asia/Dhaka') === $tz ? 'selected' : '' ?>><?= $e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label><span>Your own WhatsApp number</span>
      <input name="self_whatsapp" value="<?= $e($_POST['self_whatsapp'] ?? '') ?>" placeholder="8801XXXXXXXXX">
      <span class="hint">Daily reminders about expiring documents and unpaid invoices come to this number</span>
    </label>
    <div class="actions">
      <a href="?step=3"><button type="button" class="ghost">Back</button></a>
      <button type="submit">Finish installation</button>
    </div>
  </form>

<?php else: ?>

  <div class="msg good"><strong>AH5 Office is installed.</strong>
    Sign in with <code><?= $e($state['admin_email'] ?? 'your email') ?></code>.</div>

  <h2>Three things left</h2>
  <ol class="next">
    <li><strong>Delete the install folder.</strong> Use the button below, or remove
        <code>install/</code> in cPanel File Manager.</li>
    <li><strong>Add one cron job</strong> in cPanel &gt; Cron Jobs, every five minutes.
        This sends the expiry and payment reminders:
        <pre>/usr/local/bin/php <?= $e(Installer::root()) ?>/cron/master.php</pre></li>
    <li><strong>Open Settings</strong> in the panel and add your WhatsApp Cloud API details,
        so reminders can actually go out.</li>
  </ol>

  <div class="actions">
    <a href="<?= $e(Installer::basePath()) ?>/admin/"><button type="button" class="ghost">Open the panel</button></a>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="remove">
      <button type="submit">Delete install folder and open panel</button>
    </form>
  </div>

<?php endif; ?>

</div>

<footer>
  Designed &amp; Developed by
  <a href="https://anwar.com.bd" target="_blank" rel="noopener">Anwar Hossain</a> ·
  <a href="https://creativesit.com" target="_blank" rel="noopener">Creatives iT</a>
</footer>
</div>
</body>
</html>
<?php

function render_locked(): void
{
    ?><!DOCTYPE html>
    <html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Already installed</title>
    <style>
      body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#FBFAF7;color:#1B2233;
           display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}
      .box{background:#fff;border:1px solid #E4E0D6;border-radius:6px;padding:28px;max-width:460px}
      h1{margin:0 0 8px;font-size:19px}
      p{color:#6C7383;font-size:14px;line-height:1.6}
      code{background:#F0EDE5;padding:1px 5px;border-radius:3px;font-size:12.5px}
      a{color:#1E3A5F}
    </style></head><body>
    <div class="box">
      <h1>AH5 Office is already installed</h1>
      <p>The installer will not run again. Please delete the <code>install/</code> folder from
         the server — leaving it there is a security risk.</p>
      <p>To install from scratch on purpose, delete <code>core/install.lock</code> first.</p>
      <p><a href="<?= htmlspecialchars(Installer::basePath(), ENT_QUOTES) ?>/admin/">Open the admin panel</a></p>
    </div></body></html><?php
}
