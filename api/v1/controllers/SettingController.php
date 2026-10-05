<?php
/**
 * AH5 Office - Settings
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class SettingController
{
    /** Keys that must never be returned to the client in full. */
    /** Never handed back to the panel in the clear. */
    private const SECRET_KEYS = [
        'wa_access_token', 'telegram_bot_token', 'messenger_page_token',
        'smtp_pass', 'gmail_app_password',
    ];

    public static function index(): void
    {
        Auth::allow('settings.manage');
        $rows = DB::all('SELECT key_name, value FROM settings ORDER BY key_name ASC');

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r['key_name'];
            $val = (string) ($r['value'] ?? '');
            if (in_array($key, self::SECRET_KEYS, true)) {
                $out[$key] = $val === '' ? '' : '••••••' . substr($val, -4);
            } else {
                $out[$key] = $val;
            }
        }
        Response::ok($out);
    }

    public static function update(): void
    {
        Auth::allow('settings.manage');
        $body = Validator::input();
        if (!is_array($body) || !$body) {
            Response::error('Nothing to update', 422);
        }

        $saved = [];
        foreach ($body as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{2,80}$/', $key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $value = (string) $value;
            // never overwrite a secret with its masked display value
            if (in_array($key, self::SECRET_KEYS, true) && str_starts_with($value, '••••••')) {
                continue;
            }
            DB::run(
                'INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, $value]
            );
            $saved[] = $key;
        }

        Helper::logActivity('settings', null, 'update', implode(', ', $saved));
        Response::ok(['saved' => $saved], 'Settings saved');
    }

    /** Check that the WhatsApp / Telegram credentials actually work. */
    public static function testChannel(): void
    {
        Auth::allow('settings.manage');
        $in = Validator::make()
            ->check('channel', 'required|in:whatsapp,telegram,email', 'Channel')
            ->check('to', 'nullable|string|max:120')
            ->validate();

        $channel = (string) $in['channel'];
        $to      = (string) ($in['to'] ?? '');
        if ($to === '') {
            $to = $channel === 'telegram'
                ? (string) DB::setting('self_telegram_chat_id', '')
                : (string) DB::setting('self_whatsapp', '');
        }
        if ($to === '') {
            Response::error('Give a test recipient, or set your own number in settings', 422);
        }

        $res = Messenger::send($channel, $to, APP_NAME . ' test message - ' . date('d/m/Y H:i'),
            ['party_type' => 'self']);
        Response::ok($res, $res['success'] ? 'Test message sent' : 'Test message failed');
    }


    // ---------------- company logo and signature ----------------

    /** Upload the logo or the signature image used on invoices. */
    public static function uploadImage(): void
    {
        Auth::allow('settings.manage');

        // logo and signature go on invoices; app_logo and app_favicon are
        // your mark inside the panel and on the website
        $keys = [
            'logo'        => 'company_logo',
            'signature'   => 'company_signature',
            'app_logo'    => 'app_logo',
            'app_favicon' => 'app_favicon',
        ];

        $kind = (string) ($_POST['kind'] ?? 'logo');
        if (!isset($keys[$kind])) {
            Response::error('Choose the logo, the signature, the app logo or the favicon', 422);
        }

        [$path, $original] = Uploads::receive('file', $keys[$kind]);

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            Uploads::delete($path);
            Response::error('The image must be a JPG, PNG or WebP file', 422);
        }

        $key = $keys[$kind];
        $old = (string) DB::setting($key, '');
        if ($old !== '' && !str_starts_with($old, 'http')) {
            Uploads::delete($old);
        }

        DB::run('INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $path]);

        Helper::logActivity('settings', null, 'image_upload', $kind . ': ' . $original);

        // the panel's own mark needs no signed link - it is shown before login
        $url = in_array($key, ['app_logo', 'app_favicon'], true)
            ? '../asset.php?key=' . $key . '&v=' . time()
            : PrintAuth::assetLink($key);

        Response::created(['key' => $key, 'path' => $path, 'url' => $url],
            str_replace('_', ' ', $kind) . ' uploaded');
    }

    public static function removeImage(string $kind): void
    {
        Auth::allow('settings.manage');
        $keys = [
            'logo' => 'company_logo', 'signature' => 'company_signature',
            'app_logo' => 'app_logo', 'app_favicon' => 'app_favicon',
        ];
        if (!isset($keys[$kind])) {
            Response::notFound('Nothing to remove');
        }
        $key = $keys[$kind];
        $old = (string) DB::setting($key, '');
        if ($old !== '' && !str_starts_with($old, 'http')) {
            Uploads::delete($old);
        }
        DB::run('INSERT INTO settings (key_name, value) VALUES (?, "")
                 ON DUPLICATE KEY UPDATE value = ""', [$key]);
        Response::ok(null, ucfirst($kind) . ' removed');
    }



    // ---------------- how money changes hands ----------------

    /** Your own list: Cash, bKash, Cheque - whatever you actually use. */
    public static function methods(): void
    {
        Auth::require();
        $all = ($_GET['all'] ?? '') === '1';
        $rows = DB::all(
            'SELECT m.*,
                    (SELECT COUNT(*) FROM payments p WHERE p.method = m.name AND p.deleted_at IS NULL)
                  + (SELECT COUNT(*) FROM expenses e WHERE e.method = m.name AND e.deleted_at IS NULL)
                  + (SELECT COUNT(*) FROM supplier_payments s WHERE s.method = m.name AND s.deleted_at IS NULL)
                    AS used_count
             FROM payment_methods m'
            . ($all ? '' : ' WHERE m.is_active = 1')
            . ' ORDER BY m.is_active DESC, m.sort_order ASC, m.name ASC'
        );
        Response::ok($rows);
    }

    public static function storeMethod(): void
    {
        Auth::allow('settings.manage');
        $in = Validator::make()->check('name', 'required|string|max:60', 'Name')->validate();

        if (DB::value('SELECT id FROM payment_methods WHERE name = ?', [$in['name']]) !== null) {
            Response::error('You already have one called that', 422);
        }
        $next = (int) DB::value('SELECT COALESCE(MAX(sort_order),0) + 1 FROM payment_methods');
        $id = DB::insert('payment_methods', ['name' => $in['name'], 'sort_order' => $next]);

        Response::created(DB::one('SELECT * FROM payment_methods WHERE id = ?', [$id]), 'Added');
    }

    /** Renaming carries the entries along, so history stays readable. */
    public static function updateMethod(string $id): void
    {
        Auth::allow('settings.manage');
        $row = DB::one('SELECT * FROM payment_methods WHERE id = ?', [(int) $id]);
        if ($row === null) {
            Response::notFound('Not found');
        }

        $v = Validator::make()
            ->check('name', 'nullable|string|max:60')
            ->check('is_active', 'nullable|bool');
        $data = Helper::dropNulls($v->present($v->validate()), ['name', 'is_active']);

        $newName = $data['name'] ?? null;
        if ($newName !== null && $newName !== $row['name']) {
            if (DB::value('SELECT id FROM payment_methods WHERE name = ? AND id <> ?',
                    [$newName, (int) $id]) !== null) {
                Response::error('You already have one called that', 422);
            }
            foreach (['payments', 'expenses', 'supplier_payments'] as $table) {
                DB::run("UPDATE `{$table}` SET method = ? WHERE method = ?", [$newName, $row['name']]);
            }
        }

        if ($data) {
            DB::update('payment_methods', $data, 'id = ?', [(int) $id]);
        }
        Response::ok(DB::one('SELECT * FROM payment_methods WHERE id = ?', [(int) $id]), 'Saved');
    }

    public static function destroyMethod(string $id): void
    {
        Auth::allow('settings.manage');
        $row = DB::one('SELECT * FROM payment_methods WHERE id = ?', [(int) $id]);
        if ($row === null) {
            Response::notFound('Not found');
        }

        $used = (int) DB::value('SELECT COUNT(*) FROM payments WHERE method = ? AND deleted_at IS NULL', [$row['name']])
              + (int) DB::value('SELECT COUNT(*) FROM expenses WHERE method = ? AND deleted_at IS NULL', [$row['name']])
              + (int) DB::value('SELECT COUNT(*) FROM supplier_payments WHERE method = ? AND deleted_at IS NULL', [$row['name']]);

        if ($used > 0) {
            DB::update('payment_methods', ['is_active' => 0], 'id = ?', [(int) $id]);
            Response::ok(null, $used . ' entr(ies) already use it, so it was switched off instead of removed');
        }

        DB::run('DELETE FROM payment_methods WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Removed');
    }

    // ---------------- storage housekeeping ----------------

    /**
     * How much room the files are taking, and how much of it belongs to
     * records you have deleted. Nothing is removed until you say so - a
     * passport scan should never disappear because of an accidental delete.
     */
    /**
     * Every file some record still points at: attachments, website pictures,
     * profile photos, and your own logo and signature. Anything on disk that
     * is not in here is genuinely unclaimed.
     *
     * This is deliberately one function rather than two similar lists — when
     * a new kind of file is added, forgetting it here would quietly delete
     * people's pictures.
     */
    private static function claimedFiles(): array
    {
        $paths = array_merge(
            array_column(DB::all('SELECT file_path FROM attachments'), 'file_path'),
            array_column(DB::all("SELECT image FROM site_sections WHERE image IS NOT NULL AND image <> ''"), 'image'),
            array_column(DB::all("SELECT image FROM site_items WHERE image IS NOT NULL AND image <> ''"), 'image'),
            array_column(DB::all("SELECT photo FROM customers WHERE photo IS NOT NULL AND photo <> ''"), 'photo'),
            array_column(DB::all("SELECT photo FROM suppliers WHERE photo IS NOT NULL AND photo <> ''"), 'photo'),
            array_filter(array_map(
                static fn ($k) => (string) DB::setting($k, ''),
                ['company_logo', 'company_signature', 'app_logo', 'app_favicon']
            ))
        );

        return array_map(static fn ($p) => basename((string) $p), $paths);
    }


    /**
     * Bring the database up to date with what this build expects. Only
     * ever ADDS a missing column, table, or a wider set of choices on an
     * existing one - it never drops, renames or empties anything, and
     * running it again once everything is already there does nothing.
     *
     * This used to be its own page under install/, gated by asking for the
     * admin password again. That meant it vanished the moment install/ was
     * deleted - which the installer itself tells you to do right after
     * setting up - so the one tool meant to survive every future update
     * disappeared with the first one. Being an ordinary settings.manage
     * endpoint like everything else here means it can never do that again.
     */
    public static function upgradeSchema(): void
    {
        Auth::allow('settings.manage');
        $result = self::runSchemaUpgrade();
        Response::ok(['steps' => $result['steps'], 'added' => $result['added']], $result['added'] > 0
            ? $result['added'] . ' change(s) made — nothing of yours was touched'
            : 'Already up to date — nothing to change');
    }

    /**
     * The actual work, kept separate from the HTTP handler above so a zip
     * update (BackupController::update) can run this itself right after
     * applying new files, in the same request, rather than the person
     * having to know to come back and press this a second time.
     */
    public static function runSchemaUpgrade(): array
    {
        $columnExists = static function (string $table, string $column): bool {
            return (int) DB::value(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) > 0;
        };
        $columnType = static function (string $table, string $column) {
            return (string) (DB::value(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) ?? '');
        };
        $tableExists = static function (string $table): bool {
            return (int) DB::value(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) > 0;
        };

        // Every schema change this project has ever needed, in the order
        // they can safely be applied. A future round adds to the end of
        // this list; nothing already shipped is ever edited.
        $steps = [
            [
                'label' => 'Customers can have a photo',
                'done'  => static fn () => $columnExists('customers', 'photo'),
                'apply' => static fn () => DB::run(
                    "ALTER TABLE `customers` ADD COLUMN `photo` VARCHAR(255) DEFAULT NULL AFTER `notes`"
                ),
            ],
            [
                'label' => 'Suppliers can have a photo',
                'done'  => static fn () => $columnExists('suppliers', 'photo'),
                'apply' => static fn () => DB::run(
                    "ALTER TABLE `suppliers` ADD COLUMN `photo` VARCHAR(255) DEFAULT NULL AFTER `notes`"
                ),
            ],
            [
                'label' => 'A document type field can take several files at once',
                'done'  => static fn () => $columnExists('document_type_fields', 'allow_multiple'),
                'apply' => static fn () => DB::run(
                    "ALTER TABLE `document_type_fields`
                     ADD COLUMN `allow_multiple` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_required`"
                ),
            ],
            [
                'label' => 'An invoice or quotation can carry a flat-amount discount',
                'done'  => static fn () => str_contains($columnType('invoices', 'discount_type'), "'flat'")
                    && str_contains($columnType('quotations', 'discount_type'), "'flat'"),
                'apply' => static function () {
                    DB::run("ALTER TABLE `invoices`
                             MODIFY `discount_type` ENUM('none','percent','flat') NOT NULL DEFAULT 'none'");
                    DB::run("ALTER TABLE `quotations`
                             MODIFY `discount_type` ENUM('none','percent','flat') NOT NULL DEFAULT 'none'");
                },
            ],
            [
                'label' => 'The payment methods table exists',
                'done'  => static fn () => $tableExists('payment_methods'),
                'apply' => static fn () => DB::run(
                    "CREATE TABLE IF NOT EXISTS `payment_methods` (
                        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `name`       VARCHAR(60)  NOT NULL,
                        `sort_order` INT          NOT NULL DEFAULT 0,
                        `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                ),
            ],
            [
                'label' => "The website tables exist (the sections, their rows, and messages sent from the site)",
                'done'  => static fn () => $tableExists('site_sections') && $tableExists('site_items')
                    && $tableExists('enquiries'),
                'apply' => static function () use ($tableExists) {
                    if (!$tableExists('site_sections')) {
                        DB::run(
                            "CREATE TABLE `site_sections` (
                                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                `kind`        ENUM('banner','ticker','stats','services','forms','notices',
                                                 'problems','audience','process','payment','about','contact')
                                            NOT NULL,
                                `sort_order`  INT          NOT NULL DEFAULT 0,
                                `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
                                `eyebrow_en`  VARCHAR(120) DEFAULT NULL,
                                `eyebrow_bn`  VARCHAR(120) DEFAULT NULL,
                                `heading_en`  VARCHAR(200) DEFAULT NULL,
                                `heading_bn`  VARCHAR(200) DEFAULT NULL,
                                `body_en`     TEXT         DEFAULT NULL,
                                `body_bn`     TEXT         DEFAULT NULL,
                                `image`       VARCHAR(255) DEFAULT NULL,
                                `cta_label_en` VARCHAR(80) DEFAULT NULL,
                                `cta_label_bn` VARCHAR(80) DEFAULT NULL,
                                `cta_link`    VARCHAR(255) DEFAULT NULL,
                                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                PRIMARY KEY (`id`),
                                KEY `ix_section_order` (`is_active`,`sort_order`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                        );
                    }
                    if (!$tableExists('site_items')) {
                        DB::run(
                            "CREATE TABLE `site_items` (
                                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                `section_id`  INT UNSIGNED NOT NULL,
                                `service_id`  INT UNSIGNED DEFAULT NULL,
                                `sort_order`  INT          NOT NULL DEFAULT 0,
                                `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
                                `title_en`    VARCHAR(200) DEFAULT NULL,
                                `title_bn`    VARCHAR(200) DEFAULT NULL,
                                `body_en`     TEXT         DEFAULT NULL,
                                `body_bn`     TEXT         DEFAULT NULL,
                                `image`       VARCHAR(255) DEFAULT NULL,
                                `icon`        VARCHAR(40)  DEFAULT NULL,
                                `cta_label_en` VARCHAR(80) DEFAULT NULL,
                                `cta_label_bn` VARCHAR(80) DEFAULT NULL,
                                `cta_link`     VARCHAR(255) DEFAULT NULL,
                                PRIMARY KEY (`id`),
                                KEY `ix_item_section` (`section_id`,`sort_order`),
                                CONSTRAINT `fk_item_section` FOREIGN KEY (`section_id`)
                                    REFERENCES `site_sections`(`id`) ON DELETE CASCADE,
                                CONSTRAINT `fk_item_service` FOREIGN KEY (`service_id`)
                                    REFERENCES `services`(`id`) ON DELETE SET NULL
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                        );
                    }
                    if (!$tableExists('enquiries')) {
                        DB::run(
                            "CREATE TABLE `enquiries` (
                                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                `name`        VARCHAR(160) NOT NULL,
                                `phone`       VARCHAR(40)  DEFAULT NULL,
                                `email`       VARCHAR(190) DEFAULT NULL,
                                `subject`     VARCHAR(200) DEFAULT NULL,
                                `message`     TEXT         DEFAULT NULL,
                                `status`      ENUM('new','read','replied','closed','spam') NOT NULL DEFAULT 'new',
                                `customer_id` INT UNSIGNED DEFAULT NULL,
                                `ip`          VARCHAR(45)  DEFAULT NULL,
                                `notified_at` DATETIME     DEFAULT NULL,
                                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                PRIMARY KEY (`id`),
                                KEY `ix_enquiry_status` (`status`,`created_at`),
                                CONSTRAINT `fk_enquiry_customer` FOREIGN KEY (`customer_id`)
                                    REFERENCES `customers`(`id`) ON DELETE SET NULL
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                        );
                    }
                },
            ],
            [
                'label' => "The website's pages can hold a ticker, a stats band, a forms list and a notices list",
                'done'  => static fn () => str_contains($columnType('site_sections', 'kind'), "'ticker'"),
                'apply' => static fn () => DB::run(
                    "ALTER TABLE `site_sections` MODIFY `kind`
                     ENUM('banner','ticker','stats','services','forms','notices',
                          'problems','audience','process','payment','about','contact') NOT NULL"
                ),
            ],
            [
                'label' => "A hero slide or a ticker line can carry its own button",
                'done'  => static fn () => $columnExists('site_items', 'cta_link'),
                'apply' => static function () {
                    DB::run("ALTER TABLE `site_items` ADD COLUMN `cta_label_en` VARCHAR(80) DEFAULT NULL AFTER `icon`");
                    DB::run("ALTER TABLE `site_items` ADD COLUMN `cta_label_bn` VARCHAR(80) DEFAULT NULL AFTER `cta_label_en`");
                    DB::run("ALTER TABLE `site_items` ADD COLUMN `cta_link` VARCHAR(255) DEFAULT NULL AFTER `cta_label_bn`");
                },
            ],
            [
                'label' => 'A reminder can be sent about a message from the website',
                'done'  => static fn () => str_contains($columnType('reminder_queue', 'ref_type'), "'enquiry'"),
                'apply' => static fn () => DB::run(
                    "ALTER TABLE `reminder_queue` MODIFY `ref_type`
                     ENUM('document','invoice','job','custom','supplier_bill','enquiry') NOT NULL"
                ),
            ],
        ];

        $results = [];
        foreach ($steps as $step) {
            if (($step['done'])()) {
                $results[] = ['label' => $step['label'], 'status' => 'there'];
                continue;
            }
            try {
                ($step['apply'])();
                $results[] = ['label' => $step['label'], 'status' => 'added'];
            } catch (\Throwable $e) {
                $results[] = ['label' => $step['label'], 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        $added = count(array_filter($results, static fn ($r) => $r['status'] === 'added'));
        if ($added > 0) {
            Helper::logActivity('settings', null, 'schema_upgrade', $added . ' change(s)');
        }

        return ['steps' => $results, 'added' => $added];
    }

    public static function storage(): void
    {
        Auth::allow('settings.manage');

        // Files fall into three groups: those attached to a record, the
        // pictures on your website, and your logo and signature. Counting
        // only the first would understate what the hosting actually holds.
        $attached = DB::all(
            'SELECT entity_type, COUNT(*) AS files, COALESCE(SUM(file_size),0) AS bytes
             FROM attachments GROUP BY entity_type ORDER BY bytes DESC'
        );

        $byType = array_map(static fn ($r) => [
            'entity_type' => $r['entity_type'],
            'files'       => (int) $r['files'],
            'size'        => AttachmentController::sizeLabel((int) $r['bytes']),
            'bytes'       => (int) $r['bytes'],
        ], $attached);

        $attachedFiles = array_sum(array_column($byType, 'files'));
        $attachedBytes = array_sum(array_column($byType, 'bytes'));

        // website pictures
        $sitePaths = array_merge(
            array_column(DB::all("SELECT image FROM site_sections WHERE image IS NOT NULL AND image <> ''"), 'image'),
            array_column(DB::all("SELECT image FROM site_items WHERE image IS NOT NULL AND image <> ''"), 'image')
        );
        [$siteFiles, $siteBytes] = self::weigh($sitePaths);
        if ($siteFiles > 0) {
            $byType[] = ['entity_type' => 'website pictures', 'files' => $siteFiles,
                'size' => AttachmentController::sizeLabel($siteBytes), 'bytes' => $siteBytes];
        }

        // profile photos
        $photoPaths = array_merge(
            array_column(DB::all("SELECT photo FROM customers WHERE photo IS NOT NULL AND photo <> ''"), 'photo'),
            array_column(DB::all("SELECT photo FROM suppliers WHERE photo IS NOT NULL AND photo <> ''"), 'photo')
        );
        [$photoFiles, $photoBytes] = self::weigh($photoPaths);
        if ($photoFiles > 0) {
            $byType[] = ['entity_type' => 'profile pictures', 'files' => $photoFiles,
                'size' => AttachmentController::sizeLabel($photoBytes), 'bytes' => $photoBytes];
        }

        // your own marks
        $brandPaths = [];
        foreach (['company_logo', 'company_signature', 'app_logo', 'app_favicon'] as $key) {
            $value = (string) DB::setting($key, '');
            if ($value !== '' && !str_starts_with($value, 'http')) {
                $brandPaths[] = $value;
            }
        }
        [$brandFiles, $brandBytes] = self::weigh($brandPaths);
        if ($brandFiles > 0) {
            $byType[] = ['entity_type' => 'logo and signature', 'files' => $brandFiles,
                'size' => AttachmentController::sizeLabel($brandBytes), 'bytes' => $brandBytes];
        }

        // anything on disk that nothing points at any more
        $known = self::claimedFiles();
        $stray = [];
        $strayBytes = 0;
        foreach (glob(DOCS_PATH . '/*') ?: [] as $file) {
            $name = basename($file);
            if ($name === '.gitkeep' || $name === '.htaccess' || !is_file($file)) {
                continue;
            }
            if (!in_array($name, $known, true)) {
                $stray[] = ['file_name' => $name, 'size' => AttachmentController::sizeLabel(filesize($file))];
                $strayBytes += (int) filesize($file);
            }
        }

        $orphans = self::orphanRows();
        $orphanBytes = 0;
        foreach ($orphans as $o) {
            $orphanBytes += (int) $o['file_size'];
        }

        $total = $attachedBytes + $siteBytes + $photoBytes + $brandBytes + $strayBytes;

        Response::ok([
            'total_files'   => $attachedFiles + $siteFiles + $photoFiles + $brandFiles + count($stray),
            'total_size'    => AttachmentController::sizeLabel($total),
            'total_bytes'   => $total,
            'by_type'       => $byType,

            'orphan_files'  => count($orphans),
            'orphan_size'   => AttachmentController::sizeLabel($orphanBytes),
            'orphans'       => array_slice(array_map(static fn ($o) => [
                'id'          => (int) $o['id'],
                'file_name'   => $o['file_name'],
                'entity_type' => $o['entity_type'],
                'size'        => AttachmentController::sizeLabel((int) $o['file_size']),
                'created_at'  => $o['created_at'],
            ], $orphans), 0, 50),

            'stray_files'   => count($stray),
            'stray_size'    => AttachmentController::sizeLabel($strayBytes),
            'stray'         => array_slice($stray, 0, 50),
        ]);
    }

    /** How many of these paths are really on disk, and how big. */
    private static function weigh(array $paths): array
    {
        $files = 0;
        $bytes = 0;
        foreach (array_unique($paths) as $path) {
            $full = Uploads::resolve((string) $path);
            if ($full !== null && is_file($full)) {
                $files++;
                $bytes += (int) filesize($full);
            }
        }
        return [$files, $bytes];
    }

    /** Delete the files whose record is gone. Asked for explicitly. */
    public static function cleanStorage(): void
    {
        Auth::allow('settings.manage');

        $orphans = self::orphanRows();
        $removed = 0;
        $freed = 0;

        foreach ($orphans as $o) {
            Uploads::delete((string) $o['file_path']);
            DB::run('DELETE FROM attachments WHERE id = ?', [(int) $o['id']]);
            $freed += (int) $o['file_size'];
            $removed++;
        }

        // files left on disk that no record, picture, photo or logo points at
        $known = self::claimedFiles();

        foreach (glob(DOCS_PATH . '/*') ?: [] as $file) {
            $name = basename($file);
            if ($name === '.gitkeep' || $name === '.htaccess' || !is_file($file)) {
                continue;
            }
            if (!in_array($name, $known, true)) {
                $freed += (int) filesize($file);
                @unlink($file);
                $removed++;
            }
        }

        Helper::logActivity('settings', null, 'storage_cleanup', $removed . ' file(s)');

        Response::ok([
            'removed' => $removed,
            'freed'   => AttachmentController::sizeLabel($freed),
        ], $removed
            ? $removed . ' file(s) removed, ' . AttachmentController::sizeLabel($freed) . ' freed'
            : 'Nothing to clear - every file still belongs to a live record');
    }

    /** Attachments whose parent record has been deleted. */
    private static function orphanRows(): array
    {
        return DB::all(
            "SELECT a.* FROM attachments a
             WHERE (a.entity_type = 'document'
                    AND NOT EXISTS (SELECT 1 FROM customer_documents d
                                     WHERE d.id = a.entity_id AND d.deleted_at IS NULL))
                OR (a.entity_type = 'customer'
                    AND NOT EXISTS (SELECT 1 FROM customers c
                                     WHERE c.id = a.entity_id AND c.deleted_at IS NULL))
                OR (a.entity_type = 'supplier'
                    AND NOT EXISTS (SELECT 1 FROM suppliers s
                                     WHERE s.id = a.entity_id AND s.deleted_at IS NULL))
                OR (a.entity_type = 'invoice'
                    AND NOT EXISTS (SELECT 1 FROM invoices i
                                     WHERE i.id = a.entity_id AND i.deleted_at IS NULL))
                OR (a.entity_type = 'payment'
                    AND NOT EXISTS (SELECT 1 FROM payments p
                                     WHERE p.id = a.entity_id AND p.deleted_at IS NULL))
                OR (a.entity_type = 'expense'
                    AND NOT EXISTS (SELECT 1 FROM expenses e
                                     WHERE e.id = a.entity_id AND e.deleted_at IS NULL))
                OR (a.entity_type = 'supplier_bill'
                    AND NOT EXISTS (SELECT 1 FROM supplier_bills b
                                     WHERE b.id = a.entity_id AND b.deleted_at IS NULL))
                OR (a.entity_type = 'supplier_payment'
                    AND NOT EXISTS (SELECT 1 FROM supplier_payments sp
                                     WHERE sp.id = a.entity_id AND sp.deleted_at IS NULL))
                OR (a.entity_type = 'job'
                    AND NOT EXISTS (SELECT 1 FROM jobs j
                                     WHERE j.id = a.entity_id AND j.deleted_at IS NULL))
             ORDER BY a.created_at ASC"
        );
    }

    // ---------------- cron setup shown in the panel ----------------

    /** Everything needed to add the cron job in cPanel, ready to copy. */
    public static function cronSetup(): void
    {
        Auth::allow('settings.manage');

        $phpBinary = PHP_BINARY !== '' && str_contains(PHP_BINARY, 'php') ? PHP_BINARY : '/usr/local/bin/php';
        $script    = BASE_PATH . '/cron/master.php';
        $key       = (string) DB::setting('cron_key', '');

        $tasks = DB::all('SELECT * FROM cron_tasks ORDER BY id ASC');
        $lastRun = DB::value('SELECT MAX(last_run_at) FROM cron_tasks');
        $everRan = $lastRun !== null;

        Response::ok([
            'needed'      => 1,
            'schedule'    => '*/5 * * * *',
            'schedule_note' => 'Every 5 minutes',
            'command'     => $phpBinary . ' ' . $script,
            'cpanel_steps' => [
                'Open cPanel and go to Cron Jobs.',
                'Under Common Settings pick "Once Per Five Minutes".',
                'Paste the command below into the Command box.',
                'Press Add New Cron Job. That is the only cron this system needs.',
            ],
            'url_fallback' => $key === '' ? null : self::siteRoot() . '/cron/master.php?key=' . $key,
            'url_note'     => 'Only if your host has no command-line cron. Use it as a URL cron, every 5 minutes.',
            'php_binary'   => $phpBinary,
            'script_path'  => $script,
            'ever_ran'     => $everRan,
            'last_run_at'  => $lastRun,
            'tasks'        => $tasks,
            'recent'       => DB::all('SELECT * FROM cron_log ORDER BY started_at DESC LIMIT 15'),
        ]);
    }

    private static function siteRoot(): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $root   = rtrim(str_replace('/api/v1/index.php', '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return ($https ? 'https://' : 'http://') . $host . $root;
    }


    // ---------------- invoice design ----------------

    /** What the design screen needs: choices, presets and placeholder help. */
    public static function invoiceDesign(): void
    {
        Auth::allow('settings.manage');

        $templates = [];
        foreach (InvoiceDesign::TEMPLATES as $key => $label) {
            $templates[] = ['key' => $key, 'label' => $label];
        }
        $placeholders = [];
        foreach (InvoiceDesign::PLACEHOLDERS as $tag => $meaning) {
            $placeholders[] = ['tag' => $tag, 'meaning' => $meaning];
        }

        Response::ok([
            'current'      => InvoiceDesign::settings(),
            'templates'    => $templates,
            'placeholders' => $placeholders,
            'company'      => InvoiceDesign::company(),
            'starter_html' => self::starterHtml(),
        ]);
    }

    public static function saveInvoiceDesign(): void
    {
        Auth::allow('settings.manage');

        $in = Validator::make()
            ->check('template', 'nullable|in:classic,modern,compact,bold,minimal,stamp,sidebar,statement,letter,ledger')
            ->check('accent', 'nullable|string|max:7')
            ->check('paper', 'nullable|in:A4,Letter')
            ->check('footer_note', 'nullable|string|max:255')
            ->check('show_logo', 'nullable|bool')
            ->check('show_signature', 'nullable|bool')
            ->check('show_unit', 'nullable|bool')
            ->check('show_bank', 'nullable|bool')
            ->check('use_custom', 'nullable|bool')
            ->check('show_credit', 'nullable|bool')
            ->validate();

        $accent = (string) ($in['accent'] ?? '#1d4ed8');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            Response::validation(['accent' => ['Use a colour like #1d4ed8']]);
        }

        $custom = (string) (Validator::input()['custom_html'] ?? '');
        if (strlen($custom) > 60000) {
            Response::validation(['custom_html' => ['The template is too long (60 KB limit)']]);
        }
        if (!empty($in['use_custom']) && trim($custom) === '') {
            Response::validation(['custom_html' =>
                ['Write your template first, or switch custom HTML back off']]);
        }

        self::persist([
            'invoice_template'       => $in['template'] ?? 'classic',
            'invoice_accent'         => strtolower($accent),
            'invoice_paper'          => $in['paper'] ?? 'A4',
            'invoice_footer_note'    => (string) ($in['footer_note'] ?? ''),
            'invoice_show_logo'      => (string) (int) ($in['show_logo'] ?? 0),
            'invoice_show_signature' => (string) (int) ($in['show_signature'] ?? 0),
            'invoice_show_unit'      => (string) (int) ($in['show_unit'] ?? 0),
            'invoice_show_bank'      => (string) (int) ($in['show_bank'] ?? 0),
            'invoice_use_custom'     => (string) (int) ($in['use_custom'] ?? 0),
            'invoice_show_credit'    => (string) (int) ($in['show_credit'] ?? 0),
            'invoice_custom_html'    => $custom,
        ]);

        Helper::logActivity('settings', null, 'invoice_design');
        Response::ok(InvoiceDesign::settings(), 'Invoice design saved');
    }

    /** A preview link that shows a made-up invoice in the current design. */
    public static function designPreviewLink(): void
    {
        Auth::allow('settings.manage');
        Response::ok(['url' => PrintAuth::link('preview', 0, 'print/preview.php'), 'expires_in' => 1800]);
    }

    private static function persist(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::run('INSERT INTO settings (key_name, value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, (string) $value]);
        }
    }

    private static function starterHtml(): string
    {
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\">\n"
             . "<style>\n"
             . "  body { font-family: system-ui, sans-serif; padding: 30px; color:#1f2937; }\n"
             . "  .head { display:flex; justify-content:space-between; margin-bottom:24px; }\n"
             . "  table.items { width:100%; border-collapse:collapse; }\n"
             . "  table.items th { text-align:left; border-bottom:2px solid #333; padding:8px; }\n"
             . "  table.items td { border-bottom:1px solid #eee; padding:8px; }\n"
             . "  .num { text-align:right; }\n"
             . "</style></head><body>\n"
             . "  <div class=\"head\">\n"
             . "    <div>{{logo}}<h2>{{company_name}}</h2><p>{{company_address}}<br>{{company_phone}}</p></div>\n"
             . "    <div style=\"text-align:right\">\n"
             . "      <h1>{{doc_type}}</h1>\n"
             . "      <p>{{doc_no}}<br>{{doc_date}}</p>\n"
             . "    </div>\n"
             . "  </div>\n\n"
             . "  <p><strong>Bill to:</strong><br>{{customer_company}}<br>{{customer_name}}<br>{{customer_phone}}</p>\n\n"
             . "  {{items}}\n\n"
             . "  <p style=\"text-align:right;font-size:18px\"><strong>Total: {{total}}</strong><br>\n"
             . "     Paid: {{paid}}<br>Due: {{due}}</p>\n\n"
             . "  <p>{{terms}}</p>\n"
             . "  <p style=\"text-align:center;color:#888\">{{footer_note}}</p>\n"
             . "  <p style=\"text-align:center;font-size:11px;color:#aaa\">{{credit}}</p>\n"
             . "</body></html>";
    }

    /** Try the mail settings by sending yourself one message. */
    public static function testEmail(): void
    {
        Auth::allow('settings.manage');

        $to = trim((string) (Validator::input()['to'] ?? DB::setting('self_email', '')));
        if ($to === '') {
            Response::error('Put your own email address in Settings first', 422);
        }

        $result = Mailer::send(
            $to,
            'Test from ' . DB::setting('company_name', 'AH5 Office'),
            "This is a test message.\n\nIf it reached you, invoices and reminders can go out by email too.\n\n"
            . DB::setting('company_name', 'Creatives iT')
        );

        Response::ok($result, $result['success']
            ? 'Sent - check that inbox'
            : 'Could not send: ' . ($result['error'] ?? 'unknown error'));
    }

    /** Cron task list + last run status. */
    public static function cronStatus(): void
    {
        Auth::allow('settings.manage');
        Response::ok([
            'tasks' => DB::all('SELECT * FROM cron_tasks ORDER BY task_key ASC'),
            'recent'=> DB::all('SELECT * FROM cron_log ORDER BY started_at DESC LIMIT 20'),
        ]);
    }

    public static function activityLog(): void
    {
        Auth::allow('activity.view');
        [$page, $perPage, $offset] = Helper::pagination();
        $total = (int) DB::value('SELECT COUNT(*) FROM activity_log');
        $rows  = DB::all(
            "SELECT a.*, u.name AS user_name FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC, a.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        Response::paginated($rows, $total, $page, $perPage);
    }
}
