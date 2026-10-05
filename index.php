<?php
/**
 * AH5 Office - the public home page
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * What a visitor sees at domain.com. Everything on it is written from
 * Settings -> Website. The page reads only the site tables and the
 * company's own contact details; nothing about customers, money or files
 * is loaded here, so there is nothing to leak.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

// Not installed yet? Point the visitor at the installer rather than an error.
if (!defined('DB_NAME') || !file_exists(__DIR__ . '/core/config.local.php')) {
    header('Location: install/');
    exit;
}

if (DB::setting('site_enabled', '1') !== '1') {
    header('Location: admin/');
    exit;
}

/* ---------------------------------------------------------------- language */

$lang = 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'bn'], true)) {
    $lang = (string) $_GET['lang'];
    setcookie('ah5_lang', $lang, time() + 31536000, '/');
} elseif (isset($_COOKIE['ah5_lang']) && in_array($_COOKIE['ah5_lang'], ['en', 'bn'], true)) {
    $lang = (string) $_COOKIE['ah5_lang'];
} else {
    $default = (string) DB::setting('site_default_lang', 'en');
    $lang = in_array($default, ['en', 'bn'], true) ? $default : 'en';
}

/* ------------------------------------------------------------------ content */

$_GET['lang'] = $lang;
$page = (static function () {
    ob_start();
    $captured = null;
    Response::$capture = static function (array $payload) use (&$captured): void {
        $captured = $payload['data'] ?? null;
    };
    SiteController::page();
    Response::$capture = null;
    ob_end_clean();
    return $captured;
})();

if (!is_array($page) || empty($page['enabled'])) {
    header('Location: admin/');
    exit;
}

$co       = $page['company'];
$accent   = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $page['theme']['accent'])
            ? $page['theme']['accent'] : '#0F766E';
$theme    = in_array($page['theme']['name'], ['clean', 'bold', 'dark', 'portal'], true)
            ? $page['theme']['name'] : 'clean';
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$para     = static fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));

/**
 * A link someone typed into a button or ticker line. htmlspecialchars()
 * alone still lets a "javascript:" URL through unharmed into an href - it
 * only escapes quotes and brackets, not the scheme - so every button and
 * link on this page is built with this instead, which allows only the
 * schemes a real destination would use.
 */
$link = static function (?string $v, string $fallback = '#contact') use ($e): string {
    $v = trim((string) $v);
    if ($v === '') {
        return $fallback;
    }
    if (str_starts_with($v, '#') || str_starts_with($v, '/')) {
        return $e($v);
    }
    if (preg_match('#^(https?://|mailto:|tel:)#i', $v)) {
        return $e($v);
    }
    // an address with no scheme at all ("example.com") is treated as https
    if (!str_contains($v, ':')) {
        return $e('https://' . $v);
    }
    return $e($fallback);
};
$title    = $co['name'] !== '' ? $co['name'] : $co['app_name'];

$t = static fn (string $en, string $bn): string => $lang === 'bn' ? $bn : $en;

$waNumber = preg_replace('/[^0-9]/', '', (string) $co['whatsapp']);
$sections = $page['sections'];
$social   = $page['social'] ?? ['facebook' => '', 'instagram' => '', 'youtube' => ''];
$apps     = $page['apps'] ?? ['play_store' => '', 'app_store' => ''];
$hasContact = false;
foreach ($sections as $s) {
    if ($s['kind'] === 'contact') { $hasContact = true; break; }
}
// the app-download box lives inside the notices sidebar when there is one;
// this only stays true if no 'notices' section ever gets the chance to
// draw it, so the feature is never simply lost
$appBandPending = $apps['play_store'] !== '' || $apps['app_store'] !== '';

/** The "get the app" box - drawn once, wherever it ends up living. */
$appBox = static function () use ($apps, $t, $link, $e): string {
    ob_start(); ?>
    <div class="side-box side-app">
      <span class="side-box-title"><?= $t('Mobile app', 'মোবাইল অ্যাপ') ?></span>
      <p><?= $t('Get the app and reach us anytime.', 'অ্যাপ ডাউনলোড করুন এবং যেকোনো সময় সেবা নিন।') ?></p>
      <div class="app-links">
        <?php if ($apps['play_store'] !== ''): ?>
          <a href="<?= $link($apps['play_store']) ?>" target="_blank" rel="noopener">▶ Play Store</a>
        <?php endif; ?>
        <?php if ($apps['app_store'] !== ''): ?>
          <a href="<?= $link($apps['app_store']) ?>" target="_blank" rel="noopener"> App Store</a>
        <?php endif; ?>
      </div>
    </div>
    <?php return ob_get_clean();
};

/** The phone / WhatsApp box - only appears when there is a number to show. */
$helplineBox = static function () use ($co, $waNumber, $t, $e): string {
    if ($co['phone'] === '' && $waNumber === '') {
        return '';
    }
    ob_start(); ?>
    <div class="side-box side-help">
      <span class="side-box-title"><?= $t('Helpline', 'হেল্পলাইন') ?></span>
      <?php if ($co['phone'] !== ''): ?><strong class="side-help-number"><?= $e($co['phone']) ?></strong><?php endif; ?>
      <p><?= $t('Reach us anytime for help.', '২৪/৭ সরাসরি সহায়তার জন্য যোগাযোগ করুন।') ?></p>
      <?php if ($co['phone'] !== ''): ?>
        <a class="btn primary sm" href="tel:<?= $e($co['phone']) ?>"><?= $t('Call now', 'এখনই কল করুন') ?></a>
      <?php endif; ?>
      <?php if ($waNumber !== ''): ?>
        <a class="btn sm" href="https://wa.me/<?= $e($waNumber) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

// the app-download box lives inside the notices sidebar when there is one;
// this only stays true if no 'notices' section ever gets the chance to
// draw it, so the feature is never simply lost
$hasTopBar = $co['phone'] !== '' || $co['email'] !== ''
    || $social['facebook'] !== '' || $social['instagram'] !== '' || $social['youtube'] !== '';
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?><?= $sections && !empty($sections[0]['heading']) ? ' — ' . $e($sections[0]['heading']) : '' ?></title>
<?php if ($co['meta'] !== ''): ?>
  <meta name="description" content="<?= $e($co['meta']) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:type" content="website">
<?php if ($co['logo']): ?><link rel="icon" href="<?= $e($co['logo']) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="site/site.css?b=<?= APP_BUILD ?>">
<style>:root { --accent: <?= $accent ?>; }</style>
</head>
<body class="theme-<?= $theme ?> lang-<?= $lang ?>">

<?php if ($hasTopBar): ?>
  <div class="topbar">
    <div class="wrap">
      <div class="topbar-contact">
        <?php if ($co['phone'] !== ''): ?>
          <a href="tel:<?= $e($co['phone']) ?>">☎ <?= $e($co['phone']) ?></a>
        <?php endif; ?>
        <?php if ($co['email'] !== ''): ?>
          <a href="mailto:<?= $e($co['email']) ?>">✉ <?= $e($co['email']) ?></a>
        <?php endif; ?>
      </div>
      <div class="topbar-social">
        <?php if ($hasContact): ?>
          <a href="#contact"><?= $t('Get in touch', 'যোগাযোগ') ?></a>
        <?php endif; ?>
        <?php if ($social['facebook'] !== ''): ?>
          <a href="<?= $link($social['facebook']) ?>" target="_blank" rel="noopener" aria-label="Facebook">f</a>
        <?php endif; ?>
        <?php if ($social['instagram'] !== ''): ?>
          <a href="<?= $link($social['instagram']) ?>" target="_blank" rel="noopener" aria-label="Instagram">ig</a>
        <?php endif; ?>
        <?php if ($social['youtube'] !== ''): ?>
          <a href="<?= $link($social['youtube']) ?>" target="_blank" rel="noopener" aria-label="YouTube">yt</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<header class="bar">
  <a class="brand" href="./">
    <?php if ($co['logo']): ?>
      <!-- the logo is the name; showing both reads as a stutter -->
      <img src="<?= $e($co['logo']) ?>" alt="<?= $e($title) ?>">
    <?php else: ?>
      <span><?= $e($title) ?></span>
    <?php endif; ?>
    <?php if ($co['tagline'] !== ''): ?>
      <small class="brand-tag"><?= $e($co['tagline']) ?></small>
    <?php endif; ?>
  </a>

  <button type="button" class="menu-toggle" id="menu-toggle"
          aria-expanded="false" aria-controls="site-nav" aria-label="<?= $e($t('Open menu', 'মেনু খুলুন')) ?>">
    <span></span><span></span><span></span>
  </button>

  <div class="nav-scrim" id="nav-scrim" hidden></div>

  <nav id="site-nav">
    <a href="./"><?= $t('Home', 'হোম') ?></a>
    <?php foreach ($sections as $s): ?>
      <?php if (in_array($s['kind'], ['services', 'forms', 'problems', 'process'], true) && $s['heading']): ?>
        <a href="#s<?= (int) $s['id'] ?>"><?= $e($s['heading']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($hasContact): ?>
      <a href="#contact" class="nav-contact"><?= $t('Contact', 'যোগাযোগ') ?></a>
    <?php endif; ?>

    <div class="nav-foot">
      <a class="lang" href="?lang=<?= $lang === 'bn' ? 'en' : 'bn' ?>"
         title="<?= $t('Read this in Bengali', 'ইংরেজিতে পড়ুন') ?>">
        <?= $lang === 'bn' ? 'English' : 'বাংলা' ?>
      </a>
      <?php if ($hasContact): ?>
        <a class="cta" href="#contact"><?= $t('Get in touch', 'যোগাযোগ') ?></a>
      <?php endif; ?>
    </div>
  </nav>

  <form class="site-search" id="site-search" role="search" onsubmit="return false;">
    <input type="search" name="q" placeholder="<?= $e($t('Search our services…', 'সেবা বা তথ্য খুঁজুন…')) ?>"
           aria-label="<?= $e($t('Search our services', 'সেবা খুঁজুন')) ?>">
    <button type="submit" aria-label="<?= $e($t('Search', 'খুঁজুন')) ?>">🔍</button>
  </form>
</header>

<main>
<?php foreach ($sections as $s):
    $id   = 's' . (int) $s['id'];
    $kind = (string) $s['kind'];
    $anchor = $kind === 'contact' ? 'contact' : $id;
    ?>

  <?php if ($kind === 'banner'):
      // The section's own words are always slide one - exactly what a
      // single hero always showed, so an existing one-banner site keeps
      // its exact content. Any rows added below it become slide two,
      // three and so on; nothing you already wrote ever disappears just
      // because you added another slide.
      $firstSlide = [
          'title' => $s['heading'], 'body' => $s['body'], 'image' => $s['image'],
          'cta_label' => $s['cta_label'], 'cta_link' => $s['cta_link'],
      ];
      $slides = array_merge([$firstSlide], $s['items']);
      ?>
    <section class="hero" id="<?= $anchor ?>">
      <?php if ($s['eyebrow']): ?>
        <div class="wrap"><span class="eyebrow"><?= $e($s['eyebrow']) ?></span></div>
      <?php endif; ?>

      <div class="hero-slides" data-slider<?= count($slides) > 1 ? '' : ' data-single' ?>>
        <?php foreach ($slides as $n => $slide): ?>
          <div class="hero-slide<?= $n === 0 ? ' is-active' : '' ?>" <?= $n === 0 ? '' : 'hidden' ?>>
            <div class="wrap">
              <div class="hero-text">
                <?php if ($slide['title']): ?><h1><?= $e($slide['title']) ?></h1><?php endif; ?>
                <?php if ($slide['body']): ?><p class="lead"><?= $para($slide['body']) ?></p><?php endif; ?>
                <div class="hero-actions">
                  <?php if (!empty($slide['cta_label'])): ?>
                    <a class="btn primary" href="<?= $link($slide['cta_link']) ?>"><?= $e($slide['cta_label']) ?></a>
                  <?php endif; ?>
                  <?php if ($waNumber !== ''): ?>
                    <a class="btn" href="https://wa.me/<?= $e($waNumber) ?>" target="_blank" rel="noopener">
                      <?= $t('WhatsApp us', 'হোয়াটসঅ্যাপে লিখুন') ?>
                    </a>
                  <?php endif; ?>
                </div>
              </div>
              <?php if (!empty($slide['image'])): ?>
                <div class="hero-image"><img src="<?= $e($slide['image']) ?>" alt="" loading="lazy"></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($slides) > 1): ?>
        <button type="button" class="hero-nav prev" data-slide-prev aria-label="<?= $e($t('Previous', 'আগেরটি')) ?>">‹</button>
        <button type="button" class="hero-nav next" data-slide-next aria-label="<?= $e($t('Next', 'পরেরটি')) ?>">›</button>
        <div class="hero-dots" data-slide-dots>
          <?php foreach ($slides as $n => $slide): ?>
            <button type="button" data-slide-go="<?= $n ?>" class="<?= $n === 0 ? 'is-active' : '' ?>"
                    aria-label="<?= $e($t('Slide', 'স্লাইড')) . ' ' . ($n + 1) ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  <?php elseif ($kind === 'ticker'):
      $lines = array_values(array_filter(array_map(static fn ($it) => $it['title'] ?? null, $s['items'])));
      if (!$lines) { continue; }
      ?>
    <div class="ticker" id="<?= $anchor ?>">
      <span class="ticker-tag"><?= $e($s['heading'] ?: $t('Notice', 'বিজ্ঞপ্তি')) ?></span>
      <div class="ticker-track">
        <?php
          // the strip loops, so the line list is drawn twice back to back
          foreach ([1, 2] as $pass):
            foreach ($s['items'] as $it):
              if (empty($it['title'])) { continue; }
        ?>
          <span class="ticker-item">
            <?php if (!empty($it['cta_link'])): ?>
              <a href="<?= $link($it['cta_link']) ?>"><?= $e($it['title']) ?></a>
            <?php else: ?>
              <?= $e($it['title']) ?>
            <?php endif; ?>
          </span>
        <?php
            endforeach;
          endforeach;
        ?>
      </div>
    </div>

  <?php elseif ($kind === 'stats'):
      if (!$s['items']) { continue; }
      ?>
    <section class="stats" id="<?= $anchor ?>">
      <div class="wrap">
        <?php if ($s['heading']): ?><h2 class="sr-only"><?= $e($s['heading']) ?></h2><?php endif; ?>
        <div class="stats-grid">
          <?php foreach ($s['items'] as $it): ?>
            <?php if (empty($it['title'])) { continue; } ?>
            <div class="stat">
              <strong><?= $e($it['title']) ?></strong>
              <?php if ($it['body']): ?><span><?= $e($it['body']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

  <?php elseif ($kind === 'notices'): ?>
    <section class="notices-wrap" id="<?= $anchor ?>">
      <div class="wrap notices-grid">
        <div class="notices-main">
          <div class="section-head">
            <?php if ($s['eyebrow']): ?><span class="eyebrow"><?= $e($s['eyebrow']) ?></span><?php endif; ?>
            <?php if ($s['heading']): ?><h2><?= $e($s['heading']) ?></h2><?php endif; ?>
            <?php if ($s['body']): ?><p class="lead"><?= $para($s['body']) ?></p><?php endif; ?>
          </div>

          <?php if ($s['items']): ?>
            <div class="notice-list">
              <?php foreach ($s['items'] as $it): ?>
                <article class="notice-row">
                  <?php if ($it['title']): ?><h3><?= $e($it['title']) ?></h3><?php endif; ?>
                  <?php if ($it['body']): ?><p><?= $para($it['body']) ?></p><?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="notices-empty"><?= $t('Nothing posted yet.', 'কোনো সংবাদ পাওয়া যায়নি।') ?></p>
          <?php endif; ?>
        </div>

        <aside class="notices-side">
          <?php if ($s['items']): ?>
            <div class="side-box side-notice">
              <span class="side-box-title"><?= $t('Notices', 'গুরুত্বপূর্ণ বিজ্ঞপ্তি') ?></span>
              <ul>
                <?php foreach (array_slice($s['items'], 0, 4) as $it): ?>
                  <?php if ($it['title']): ?>
                    <li><?= $e($it['title']) ?> <span class="tag-new"><?= $t('New', 'নতুন') ?></span></li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?= $helplineBox() ?>
          <?php if ($appBandPending): $appBandPending = false; ?>
            <?= $appBox() ?>
          <?php endif; ?>
        </aside>
      </div>
    </section>

  <?php elseif ($kind === 'about'): ?>
    <section class="cta-band" id="<?= $anchor ?>">
      <div class="wrap cta-band-inner">
        <div class="cta-icon" aria-hidden="true">✦</div>
        <div class="cta-words">
          <?php if ($s['eyebrow']): ?><span class="eyebrow"><?= $e($s['eyebrow']) ?></span><?php endif; ?>
          <?php if ($s['heading']): ?><h2><?= $e($s['heading']) ?></h2><?php endif; ?>
          <?php if ($s['body']): ?><p class="lead"><?= $para($s['body']) ?></p><?php endif; ?>
        </div>
        <?php if ($s['cta_label']): ?>
          <a class="btn primary" href="<?= $link($s['cta_link']) ?>"><?= $e($s['cta_label']) ?> →</a>
        <?php endif; ?>
      </div>
    </section>

  <?php elseif ($kind === 'payment'): ?>
    <section class="band" id="<?= $anchor ?>">
      <div class="wrap narrow">
        <?php if ($s['eyebrow']): ?><span class="eyebrow"><?= $e($s['eyebrow']) ?></span><?php endif; ?>
        <?php if ($s['heading']): ?><h2><?= $e($s['heading']) ?></h2><?php endif; ?>
        <?php if ($s['body']): ?><p class="lead"><?= $para($s['body']) ?></p><?php endif; ?>
        <ul class="chips">
          <?php foreach ($page['payment_methods'] as $m): ?>
            <li><?= $e($m['name']) ?></li>
          <?php endforeach; ?>
          <?php foreach ($s['items'] as $it): ?>
            <?php if ($it['title']): ?><li><?= $e($it['title']) ?></li><?php endif; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

  <?php elseif ($kind === 'contact'): ?>
    <section class="contact" id="contact">
      <div class="wrap">
        <div class="contact-words">
          <?php if ($s['eyebrow']): ?><span class="eyebrow"><?= $e($s['eyebrow']) ?></span><?php endif; ?>
          <?php if ($s['heading']): ?><h2><?= $e($s['heading']) ?></h2><?php endif; ?>
          <?php if ($s['body']): ?><p class="lead"><?= $para($s['body']) ?></p><?php endif; ?>

          <ul class="details">
            <?php if ($co['phone'] !== ''): ?>
              <li><span><?= $t('Phone', 'ফোন') ?></span>
                <a href="tel:<?= $e($co['phone']) ?>"><?= $e($co['phone']) ?></a></li>
            <?php endif; ?>
            <?php if ($waNumber !== ''): ?>
              <li><span>WhatsApp</span>
                <a href="https://wa.me/<?= $e($waNumber) ?>" target="_blank" rel="noopener"><?= $e($co['whatsapp']) ?></a></li>
            <?php endif; ?>
            <?php if ($co['email'] !== ''): ?>
              <li><span><?= $t('Email', 'ইমেইল') ?></span>
                <a href="mailto:<?= $e($co['email']) ?>"><?= $e($co['email']) ?></a></li>
            <?php endif; ?>
            <?php if ($co['address'] !== ''): ?>
              <li><span><?= $t('Address', 'ঠিকানা') ?></span><?= $para($co['address']) ?></li>
            <?php endif; ?>
          </ul>
        </div>

        <form class="enquiry" id="enquiry-form" autocomplete="on">
          <div class="row">
            <label>
              <span><?= $t('Your name', 'আপনার নাম') ?> *</span>
              <input name="name" required maxlength="160">
            </label>
            <label>
              <span><?= $t('Phone', 'ফোন') ?></span>
              <input name="phone" maxlength="40" inputmode="tel">
            </label>
          </div>
          <div class="row">
            <label>
              <span><?= $t('Email', 'ইমেইল') ?></span>
              <input name="email" type="email" maxlength="190">
            </label>
            <label>
              <span><?= $t('What is it about', 'কী বিষয়ে') ?></span>
              <input name="subject" maxlength="200">
            </label>
          </div>
          <label>
            <span><?= $t('Your message', 'আপনার বার্তা') ?> *</span>
            <textarea name="message" rows="5" required maxlength="5000"></textarea>
          </label>

          <!-- a real visitor never sees this, so a robot filling it gives itself away -->
          <div class="trap" aria-hidden="true">
            <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
          </div>
          <input type="hidden" name="opened_at" value="<?= time() ?>">

          <button type="submit" class="btn primary"><?= $t('Send', 'পাঠান') ?></button>
          <p class="form-note" role="status"></p>
        </form>
      </div>
    </section>

  <?php else: /* services, forms, problems, audience, process */ ?>
    <section class="cards <?= $e($kind) ?>" id="<?= $anchor ?>">
      <div class="wrap">
        <div class="section-head<?= !empty($s['cta_label']) ? ' section-head-row' : '' ?>">
          <div>
            <?php if ($s['eyebrow']): ?><span class="eyebrow"><?= $e($s['eyebrow']) ?></span><?php endif; ?>
            <?php if ($s['heading']): ?><h2><?= $e($s['heading']) ?></h2><?php endif; ?>
            <?php if ($s['body']): ?><p class="lead"><?= $para($s['body']) ?></p><?php endif; ?>
          </div>
          <?php if (!empty($s['cta_label'])): ?>
            <a class="btn head-link" href="<?= $link($s['cta_link']) ?>"><?= $e($s['cta_label']) ?> →</a>
          <?php endif; ?>
        </div>

        <?php if ($s['items']): ?>
          <div class="grid<?= $kind === 'process' ? ' steps' : '' ?><?= in_array($kind, ['services', 'forms'], true) ? ' icon-grid' : '' ?>"
               <?= in_array($kind, ['services', 'forms'], true) ? 'data-searchable' : '' ?>>
            <?php foreach ($s['items'] as $i => $it): ?>
              <article class="card">
                <?php if ($kind === 'process'): ?>
                  <span class="step-no"><?= $i + 1 ?></span>
                <?php elseif ($it['image']): ?>
                  <img src="<?= $e($it['image']) ?>" alt="" loading="lazy">
                <?php elseif (in_array($kind, ['services', 'forms'], true)): ?>
                  <span class="card-icon"><?= $it['icon'] ? $e($it['icon']) : mb_substr((string) $it['title'], 0, 1) ?></span>
                <?php endif; ?>
                <?php if ($it['title']): ?><h3><?= $e($it['title']) ?></h3><?php endif; ?>
                <?php if ($it['body']): ?><p><?= $para($it['body']) ?></p><?php endif; ?>
                <?php if (!empty($it['cta_label'])): ?>
                  <a class="card-link" href="<?= $link($it['cta_link']) ?>"><?= $e($it['cta_label']) ?></a>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

<?php endforeach; ?>
</main>

<?php if ($appBandPending): ?>
  <div class="app-band">
    <div class="wrap">
      <span><?= $t('Get the app', 'অ্যাপ নামিয়ে নিন') ?></span>
      <div class="app-links">
        <?php if ($apps['play_store'] !== ''): ?>
          <a href="<?= $link($apps['play_store']) ?>" target="_blank" rel="noopener">▶ Play Store</a>
        <?php endif; ?>
        <?php if ($apps['app_store'] !== ''): ?>
          <a href="<?= $link($apps['app_store']) ?>" target="_blank" rel="noopener"> App Store</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<footer class="foot">
  <div class="wrap foot-top">
    <div class="foot-about">
      <a class="brand" href="./">
        <?php if ($co['logo']): ?>
          <img src="<?= $e($co['logo']) ?>" alt="<?= $e($title) ?>">
        <?php else: ?>
          <span><?= $e($title) ?></span>
        <?php endif; ?>
      </a>
      <?php if ($co['tagline'] !== ''): ?><p><?= $e($co['tagline']) ?></p><?php endif; ?>
      <?php if ($social['facebook'] !== '' || $social['instagram'] !== '' || $social['youtube'] !== ''): ?>
        <div class="foot-social">
          <?php if ($social['facebook'] !== ''): ?><a href="<?= $link($social['facebook']) ?>" target="_blank" rel="noopener">f</a><?php endif; ?>
          <?php if ($social['instagram'] !== ''): ?><a href="<?= $link($social['instagram']) ?>" target="_blank" rel="noopener">ig</a><?php endif; ?>
          <?php if ($social['youtube'] !== ''): ?><a href="<?= $link($social['youtube']) ?>" target="_blank" rel="noopener">yt</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="foot-col">
      <h4><?= $t('Quick links', 'দ্রুত লিংক') ?></h4>
      <a href="./"><?= $t('Home', 'হোম') ?></a>
      <?php foreach ($sections as $s): ?>
        <?php if (in_array($s['kind'], ['services', 'forms'], true) && $s['heading']): ?>
          <a href="#s<?= (int) $s['id'] ?>"><?= $e($s['heading']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($hasContact): ?><a href="#contact"><?= $t('Contact', 'যোগাযোগ') ?></a><?php endif; ?>
    </div>

    <div class="foot-col">
      <h4><?= $t('Contact', 'যোগাযোগ') ?></h4>
      <?php if ($co['address'] !== ''): ?><span><?= $para($co['address']) ?></span><?php endif; ?>
      <?php if ($co['phone'] !== ''): ?><a href="tel:<?= $e($co['phone']) ?>"><?= $e($co['phone']) ?></a><?php endif; ?>
      <?php if ($co['email'] !== ''): ?><a href="mailto:<?= $e($co['email']) ?>"><?= $e($co['email']) ?></a><?php endif; ?>
    </div>
  </div>

  <div class="wrap foot-bottom">
    <span>&copy; <?= date('Y') ?> <?= $e($title) ?></span>
    <span>
      <a href="admin/"><?= $t('Sign in', 'লগইন') ?></a>
      <?php if (DB::setting('invoice_show_credit', '1') === '1'): ?>
        · <?= $t('Built by', 'নির্মাণে') ?>
        <a href="https://anwar.com.bd" target="_blank" rel="noopener">Anwar Hossain</a>
      <?php endif; ?>
    </span>
  </div>
</footer>

<script>
(function () {
  var form = document.getElementById('enquiry-form');
  if (!form) return;
  var note = form.querySelector('.form-note');
  var button = form.querySelector('button[type=submit]');

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    button.disabled = true;
    note.textContent = <?= json_encode($t('Sending…', 'পাঠানো হচ্ছে…')) ?>;
    note.className = 'form-note';

    var body = {};
    new FormData(form).forEach(function (value, key) { body[key] = value; });

    fetch('api/v1/site/enquiry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (res.ok && res.d.success) {
          form.reset();
          note.textContent = res.d.message || <?= json_encode($t('Thank you', 'ধন্যবাদ')) ?>;
          note.className = 'form-note good';
        } else {
          var errs = res.d.errors ? Object.values(res.d.errors)[0] : null;
          note.textContent = (errs && errs[0]) || res.d.message
            || <?= json_encode($t('Could not send. Please try again.', 'পাঠানো যায়নি। আবার চেষ্টা করুন।')) ?>;
          note.className = 'form-note bad';
        }
      })
      .catch(function () {
        note.textContent = <?= json_encode($t('Could not reach the server.', 'সার্ভারে পৌঁছানো যায়নি।')) ?>;
        note.className = 'form-note bad';
      })
      .then(function () { button.disabled = false; });
  });
})();

// mobile menu: a full slide-down panel with every link at a size that is
// easy to read and tap, not the cramped strip a squeezed-in header nav
// would otherwise become on a phone
(function () {
  var toggle = document.getElementById('menu-toggle');
  var nav = document.getElementById('site-nav');
  var scrim = document.getElementById('nav-scrim');
  if (!toggle || !nav) return;

  function isOpen() { return document.body.classList.contains('nav-open'); }

  function open() {
    document.body.classList.add('nav-open');
    toggle.setAttribute('aria-expanded', 'true');
    if (scrim) scrim.hidden = false;
  }

  function close() {
    document.body.classList.remove('nav-open');
    toggle.setAttribute('aria-expanded', 'false');
    if (scrim) scrim.hidden = true;
  }

  toggle.addEventListener('click', function () { isOpen() ? close() : open(); });
  if (scrim) scrim.addEventListener('click', close);
  nav.querySelectorAll('a').forEach(function (a) {
    a.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && isOpen()) close();
  });
  // resizing past the mobile breakpoint should never leave the panel
  // stuck open behind the now-restored desktop nav
  window.addEventListener('resize', function () {
    if (window.innerWidth > 860 && isOpen()) close();
  });
})();

// hero slider: several slides rotate on their own and can be stepped
// through by hand; a single-slide hero has none of this chrome at all
(function () {
  var track = document.querySelector('[data-slider]');
  if (!track || track.hasAttribute('data-single')) return;

  var slides = Array.prototype.slice.call(track.querySelectorAll('.hero-slide'));
  var dots = Array.prototype.slice.call(document.querySelectorAll('[data-slide-dots] button'));
  var current = 0, timer;

  function show(n) {
    current = (n + slides.length) % slides.length;
    slides.forEach(function (el, i) {
      el.hidden = i !== current;
      el.classList.toggle('is-active', i === current);
    });
    dots.forEach(function (el, i) { el.classList.toggle('is-active', i === current); });
  }

  function restart() {
    clearInterval(timer);
    timer = setInterval(function () { show(current + 1); }, 6000);
  }

  var prev = document.querySelector('[data-slide-prev]');
  var next = document.querySelector('[data-slide-next]');
  if (prev) prev.addEventListener('click', function () { show(current - 1); restart(); });
  if (next) next.addEventListener('click', function () { show(current + 1); restart(); });
  dots.forEach(function (el, i) {
    el.addEventListener('click', function () { show(i); restart(); });
  });

  track.addEventListener('mouseenter', function () { clearInterval(timer); });
  track.addEventListener('mouseleave', restart);
  restart();
})();

// search: filters the service/form cards by their own visible title, and
// jumps to the first match - there is nothing to fetch, it is all on the
// page already
(function () {
  var form = document.getElementById('site-search');
  if (!form) return;
  var input = form.querySelector('input');
  var groups = Array.prototype.slice.call(document.querySelectorAll('[data-searchable]'));
  if (!groups.length) return;

  function apply() {
    var q = input.value.trim().toLowerCase();
    var firstMatch = null;
    groups.forEach(function (grid) {
      Array.prototype.forEach.call(grid.querySelectorAll('.card'), function (card) {
        var title = (card.querySelector('h3') || {}).textContent || '';
        var hit = q === '' || title.toLowerCase().indexOf(q) !== -1;
        card.classList.toggle('is-dim', !hit);
        if (hit && q !== '' && !firstMatch) firstMatch = card;
      });
    });
    return firstMatch;
  }

  input.addEventListener('input', apply);
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var hit = apply();
    (hit || groups[0]).scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
})();
</script>
</body>
</html>
