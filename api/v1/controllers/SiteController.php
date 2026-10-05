<?php
/**
 * AH5 Office - the public site
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Two jobs. It hands the home page everything it needs to draw itself,
 * and it lets the owner write that page from Settings. The public read is
 * deliberately narrow: sections, their rows, and the company's own contact
 * details. Nothing about customers, money or files passes through here.
 */

declare(strict_types=1);

final class SiteController
{
    /** Sections that may exist, and what each one is for. */
    public const KINDS = [
        'banner'   => 'The first thing a visitor sees — one slide, or several',
        'ticker'   => 'A line of short notices that scrolls by',
        'services' => 'What you do — fills in from your own services if left empty',
        'forms'    => 'A second grid of cards, for things people can ask for',
        'stats'    => 'A row of numbers, e.g. "40+ projects delivered"',
        'notices'  => 'A short list of news or announcements',
        'problems' => 'Problems you solve',
        'audience' => 'Who you work with',
        'process'  => 'How the work goes',
        'payment'  => 'How people can pay you',
        'about'    => 'About the company, or a closing call to action',
        'contact'  => 'How to reach you',
    ];

    // ------------------------------------------------------------ public

    /**
     * Everything the home page renders, in one call. No login: this is the
     * public face of the business.
     */
    public static function page(): void
    {
        if (DB::setting('site_enabled', '1') !== '1') {
            Response::ok(['enabled' => false]);
        }

        $lang = self::lang($_GET['lang'] ?? null);
        $sections = DB::all(
            'SELECT * FROM site_sections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );

        $out = [];
        foreach ($sections as $s) {
            $out[] = [
                'id'        => (int) $s['id'],
                'kind'      => $s['kind'],
                'eyebrow'   => self::pick($s, 'eyebrow', $lang),
                'heading'   => self::pick($s, 'heading', $lang),
                'body'      => self::pick($s, 'body', $lang),
                'image'     => self::imageUrl($s['image'] ?? null),
                'cta_label' => self::pick($s, 'cta_label', $lang),
                'cta_link'  => $s['cta_link'],
                'items'     => self::itemsFor((int) $s['id'], (string) $s['kind'], $lang),
            ];
        }

        Response::ok([
            'enabled'  => true,
            'lang'     => $lang,
            'company'  => self::companyCard($lang),
            'theme'    => [
                'name'   => DB::setting('site_theme', 'clean'),
                'accent' => DB::setting('site_accent', '#0F766E'),
            ],
            'social'   => [
                'facebook'  => DB::setting('site_social_facebook', ''),
                'instagram' => DB::setting('site_social_instagram', ''),
                'youtube'   => DB::setting('site_social_youtube', ''),
            ],
            'apps'     => [
                'play_store' => DB::setting('site_app_playstore', ''),
                'app_store'  => DB::setting('site_app_appstore', ''),
            ],
            'payment_methods' => DB::all(
                'SELECT name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC'
            ),
            'sections' => $out,
        ]);
    }

    /**
     * Just the name and mark. The login screen needs these before anyone
     * has signed in, so this is public - and carries nothing else.
     */
    public static function brand(): void
    {
        Response::ok([
            'app_name'     => DB::setting('app_name', 'AH5 Office'),
            'company_name' => DB::setting('company_name', ''),
            'logo'         => DB::setting('app_logo', '') !== ''
                ? '../asset.php?key=app_logo' : null,
            'favicon'      => DB::setting('app_favicon', '') !== ''
                ? '../asset.php?key=app_favicon' : null,
            'build'        => APP_BUILD,
        ]);
    }

    /** Someone filled in the contact form. */
    public static function enquire(): void
    {
        if (DB::setting('site_enabled', '1') !== '1') {
            Response::error('The site is not accepting messages', 403);
        }

        $in = Validator::make()
            ->check('name', 'required|string|max:160', 'Your name')
            ->check('phone', 'nullable|string|max:40')
            ->check('email', 'nullable|email|max:190')
            ->check('subject', 'nullable|string|max:200')
            ->check('message', 'required|string|max:5000', 'Message')
            ->validate();

        $body = Validator::input();

        // a form filled in under two seconds is a robot, and a real person
        // never fills in a field that is hidden from them
        if (!empty($body['website'])) {
            Response::ok(null, 'Thank you - we will be in touch');   // quietly dropped
        }
        $opened = (int) ($body['opened_at'] ?? 0);
        if ($opened > 0 && (time() - $opened) < 2) {
            Response::ok(null, 'Thank you - we will be in touch');
        }

        if (empty($in['phone']) && empty($in['email'])) {
            Response::validation(['phone' => ['Leave a phone number or an email so we can reply']]);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $recent = (int) DB::value(
            'SELECT COUNT(*) FROM enquiries WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            [$ip]
        );
        if ($recent >= 5) {
            Response::error('That is a lot of messages at once. Please try again shortly.', 429);
        }

        $id = DB::insert('enquiries', Helper::dropNulls([
            'name'    => trim((string) $in['name']),
            'phone'   => $in['phone'] ?? null,
            'email'   => $in['email'] ?? null,
            'subject' => $in['subject'] ?? null,
            'message' => trim((string) $in['message']),
            'ip'      => $ip !== '' ? $ip : null,
        ]));

        self::tellTheOwner($id, (string) $in['name'], $in['phone'] ?? ($in['email'] ?? ''),
            (string) $in['message']);

        Response::created(['id' => $id], 'Thank you - we will be in touch');
    }

    /**
     * Queue a note to the owner rather than sending it here, so a slow
     * WhatsApp call never leaves the visitor staring at a spinner.
     */
    private static function tellTheOwner(int $id, string $name, string $reply, string $message): void
    {
        if (DB::setting('site_notify_whatsapp', '1') !== '1') {
            return;
        }
        $to = trim((string) DB::setting('self_whatsapp', ''));
        if ($to === '') {
            return;
        }

        $text = "New message from your website\n\n"
              . $name . ($reply !== '' ? ' (' . $reply . ')' : '') . "\n\n"
              . mb_substr($message, 0, 400);

        try {
            DB::insert('reminder_queue', [
                'ref_type'     => 'enquiry',
                'ref_id'       => $id,
                'party_type'   => 'self',
                'party_id'     => null,
                'channel'      => 'whatsapp',
                'recipient'    => $to,
                'body'         => $text,
                'status'       => 'queued',
                'scheduled_at' => date('Y-m-d H:i:s'),
            ]);
            DB::update('enquiries', ['notified_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        } catch (Throwable $e) {
            // the message is safely stored either way; the note is a courtesy
        }
    }

    // ------------------------------------------------------------- admin

    /** The whole page as the owner edits it: both languages, inactive too. */
    public static function index(): void
    {
        Auth::allow('settings.manage');

        $sections = DB::all('SELECT * FROM site_sections ORDER BY sort_order ASC, id ASC');
        foreach ($sections as &$s) {
            $s['items'] = DB::all(
                'SELECT i.*, sv.name AS service_name
                 FROM site_items i LEFT JOIN services sv ON sv.id = i.service_id
                 WHERE i.section_id = ? ORDER BY i.sort_order ASC, i.id ASC',
                [(int) $s['id']]
            );
            $s['image_url'] = self::imageUrl($s['image'] ?? null);
            foreach ($s['items'] as &$it) {
                $it['image_url'] = self::imageUrl($it['image'] ?? null);
            }
            unset($it);
        }
        unset($s);

        Response::ok([
            'kinds'    => self::KINDS,
            'sections' => $sections,
            'settings' => [
                'site_enabled'          => DB::setting('site_enabled', '1'),
                'site_theme'            => DB::setting('site_theme', 'clean'),
                'site_accent'           => DB::setting('site_accent', '#0F766E'),
                'site_default_lang'     => DB::setting('site_default_lang', 'en'),
                'site_meta_description' => DB::setting('site_meta_description', ''),
                'site_notify_whatsapp'  => DB::setting('site_notify_whatsapp', '1'),
                'site_tagline_en'       => DB::setting('site_tagline_en', ''),
                'site_tagline_bn'       => DB::setting('site_tagline_bn', ''),
                'site_social_facebook'  => DB::setting('site_social_facebook', ''),
                'site_social_instagram' => DB::setting('site_social_instagram', ''),
                'site_social_youtube'   => DB::setting('site_social_youtube', ''),
                'site_app_playstore'    => DB::setting('site_app_playstore', ''),
                'site_app_appstore'     => DB::setting('site_app_appstore', ''),
            ],
            'themes'   => [
                'clean'  => 'Clean — white, roomy, quiet type',
                'bold'   => 'Bold — large headings on a solid colour',
                'dark'   => 'Dark — light text on deep navy',
                'portal' => 'Portal — dark green header, icon cards, a notice strip',
            ],
        ]);
    }

    public static function storeSection(): void
    {
        Auth::allow('settings.manage');

        $in = Validator::make()
            ->check('kind', 'required|in:' . implode(',', array_keys(self::KINDS)), 'Section')
            ->check('heading_en', 'nullable|string|max:200')
            ->validate();

        $next = (int) DB::value('SELECT COALESCE(MAX(sort_order),0) + 1 FROM site_sections');
        $id = DB::insert('site_sections', [
            'kind'       => $in['kind'],
            'sort_order' => $next,
            'heading_en' => $in['heading_en'] ?? ucfirst((string) $in['kind']),
        ]);

        Response::created(DB::one('SELECT * FROM site_sections WHERE id = ?', [$id]), 'Section added');
    }

    public static function updateSection(string $id): void
    {
        Auth::allow('settings.manage');
        self::findSection((int) $id);

        $v = Validator::make()
            ->check('is_active', 'nullable|bool')
            ->check('sort_order', 'nullable|int')
            ->check('eyebrow_en', 'nullable|string|max:120')
            ->check('eyebrow_bn', 'nullable|string|max:120')
            ->check('heading_en', 'nullable|string|max:200')
            ->check('heading_bn', 'nullable|string|max:200')
            ->check('body_en', 'nullable|string|max:8000')
            ->check('body_bn', 'nullable|string|max:8000')
            ->check('cta_label_en', 'nullable|string|max:80')
            ->check('cta_label_bn', 'nullable|string|max:80')
            ->check('cta_link', 'nullable|string|max:255');

        $data = $v->present($v->validate());
        if ($data) {
            DB::update('site_sections', $data, 'id = ?', [(int) $id]);
        }

        Response::ok(DB::one('SELECT * FROM site_sections WHERE id = ?', [(int) $id]), 'Saved');
    }

    public static function destroySection(string $id): void
    {
        Auth::allow('settings.manage');
        self::findSection((int) $id);
        DB::run('DELETE FROM site_sections WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Section removed');
    }

    /** Put the sections in the order the owner dragged them into. */
    public static function reorder(): void
    {
        Auth::allow('settings.manage');
        $order = Validator::input()['order'] ?? [];
        if (!is_array($order)) {
            Response::validation(['order' => ['Send the section ids in the order you want']]);
        }
        foreach (array_values($order) as $position => $sectionId) {
            DB::update('site_sections', ['sort_order' => $position], 'id = ?', [(int) $sectionId]);
        }
        Response::ok(null, 'Order saved');
    }

    // ---------------- the rows inside a section ----------------

    public static function storeItem(string $sectionId): void
    {
        Auth::allow('settings.manage');
        self::findSection((int) $sectionId);

        $in = Validator::make()
            ->check('service_id', 'nullable|int')
            ->check('title_en', 'nullable|string|max:200')
            ->check('body_en', 'nullable|string|max:5000')
            ->validate();

        if (empty($in['service_id']) && empty($in['title_en'])) {
            Response::validation(['title_en' => ['Give it a title, or pick one of your services']]);
        }

        $next = (int) DB::value(
            'SELECT COALESCE(MAX(sort_order),0) + 1 FROM site_items WHERE section_id = ?',
            [(int) $sectionId]
        );
        $id = DB::insert('site_items', Helper::dropNulls([
            'section_id' => (int) $sectionId,
            'service_id' => $in['service_id'] ?? null,
            'sort_order' => $next,
            'title_en'   => $in['title_en'] ?? null,
            'body_en'    => $in['body_en'] ?? null,
        ], ['section_id', 'sort_order']));

        Response::created(DB::one('SELECT * FROM site_items WHERE id = ?', [$id]), 'Added');
    }

    public static function updateItem(string $id): void
    {
        Auth::allow('settings.manage');
        if (DB::one('SELECT id FROM site_items WHERE id = ?', [(int) $id]) === null) {
            Response::notFound('Not found');
        }

        $v = Validator::make()
            ->check('service_id', 'nullable|int')
            ->check('is_active', 'nullable|bool')
            ->check('sort_order', 'nullable|int')
            ->check('title_en', 'nullable|string|max:200')
            ->check('title_bn', 'nullable|string|max:200')
            ->check('body_en', 'nullable|string|max:5000')
            ->check('body_bn', 'nullable|string|max:5000')
            ->check('icon', 'nullable|string|max:40')
            // a hero slide's button, or a ticker line's link
            ->check('cta_label_en', 'nullable|string|max:80')
            ->check('cta_label_bn', 'nullable|string|max:80')
            ->check('cta_link', 'nullable|string|max:255');

        $data = $v->present($v->validate());
        if ($data) {
            DB::update('site_items', $data, 'id = ?', [(int) $id]);
        }

        Response::ok(DB::one('SELECT * FROM site_items WHERE id = ?', [(int) $id]), 'Saved');
    }

    public static function destroyItem(string $id): void
    {
        Auth::allow('settings.manage');
        DB::run('DELETE FROM site_items WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Removed');
    }

    /** A picture for a section or one of its rows. */
    public static function uploadImage(string $target, string $id): void
    {
        Auth::allow('settings.manage');

        $table = $target === 'item' ? 'site_items' : 'site_sections';
        $row = DB::one("SELECT * FROM `{$table}` WHERE id = ?", [(int) $id]);
        if ($row === null) {
            Response::notFound('Not found');
        }

        [$stored, $original] = Uploads::receive('file', 'site');

        $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            Uploads::delete($stored);
            Response::error('Use a picture: JPG, PNG, WebP or GIF', 422);
        }

        if (!empty($row['image'])) {
            Uploads::delete((string) $row['image']);
        }
        DB::update($table, ['image' => $stored], 'id = ?', [(int) $id]);

        Response::ok([
            'image_url' => self::imageUrl($stored),
            'file_name' => $original,
        ], 'Picture added');
    }

    public static function removeImage(string $target, string $id): void
    {
        Auth::allow('settings.manage');
        $table = $target === 'item' ? 'site_items' : 'site_sections';
        $row = DB::one("SELECT image FROM `{$table}` WHERE id = ?", [(int) $id]);
        if ($row !== null && !empty($row['image'])) {
            Uploads::delete((string) $row['image']);
        }
        DB::update($table, ['image' => null], 'id = ?', [(int) $id]);
        Response::ok(null, 'Picture removed');
    }

    // ---------------- internals ----------------

    /** Bengali if asked for and written, English otherwise. */
    private static function pick(array $row, string $field, string $lang): ?string
    {
        if ($lang === 'bn') {
            $bn = $row[$field . '_bn'] ?? null;
            if ($bn !== null && trim((string) $bn) !== '') {
                return (string) $bn;
            }
        }
        $en = $row[$field . '_en'] ?? null;
        return ($en !== null && trim((string) $en) !== '') ? (string) $en : null;
    }

    private static function lang($asked): string
    {
        $value = is_string($asked) ? strtolower($asked) : '';
        if (in_array($value, ['en', 'bn'], true)) {
            return $value;
        }
        $default = (string) DB::setting('site_default_lang', 'en');
        return in_array($default, ['en', 'bn'], true) ? $default : 'en';
    }

    /**
     * A services section with no rows of its own falls back to the service
     * list, so the page is useful the moment you install it.
     */
    private static function itemsFor(int $sectionId, string $kind, string $lang): array
    {
        $rows = DB::all(
            'SELECT i.*, sv.name AS service_name, sv.description AS service_description
             FROM site_items i LEFT JOIN services sv ON sv.id = i.service_id
             WHERE i.section_id = ? AND i.is_active = 1
             ORDER BY i.sort_order ASC, i.id ASC',
            [$sectionId]
        );

        if (!$rows && $kind === 'services') {
            $rows = array_map(static fn ($sv) => [
                'id'                  => 0,
                'service_id'          => $sv['id'],
                'service_name'        => $sv['name'],
                'service_description' => $sv['description'],
                'title_en'            => null, 'title_bn' => null,
                'body_en'             => null, 'body_bn' => null,
                'image'               => null, 'icon' => null,
                'cta_label_en'        => null, 'cta_label_bn' => null, 'cta_link' => null,
            ], DB::all(
                'SELECT id, name, description FROM services
                 WHERE is_active = 1 AND show_in_list = 1
                 ORDER BY sort_order ASC, name ASC LIMIT 12'
            ));
        }

        return array_map(static fn ($r) => [
            'title'     => self::pick($r, 'title', $lang) ?? ($r['service_name'] ?? null),
            'body'      => self::pick($r, 'body', $lang) ?? ($r['service_description'] ?? null),
            'image'     => self::imageUrl($r['image'] ?? null),
            'icon'      => $r['icon'] ?? null,
            // a hero slide's own button, or a ticker line's own link
            'cta_label' => self::pick($r, 'cta_label', $lang),
            'cta_link'  => $r['cta_link'] ?? null,
        ], $rows);
    }

    /** Only the details a business puts on its own front door. */
    private static function companyCard(string $lang = 'en'): array
    {
        $tagline = $lang === 'bn' && trim((string) DB::setting('site_tagline_bn', '')) !== ''
            ? DB::setting('site_tagline_bn', '')
            : DB::setting('site_tagline_en', '');

        return [
            'app_name' => DB::setting('app_name', 'AH5 Office'),
            'name'     => DB::setting('company_name', 'Creatives iT'),
            'tagline'  => $tagline,
            'phone'    => DB::setting('company_phone', ''),
            'whatsapp' => DB::setting('self_whatsapp', ''),
            'email'    => DB::setting('company_email', ''),
            'website'  => DB::setting('company_website', ''),
            'address'  => DB::setting('company_address', ''),
            'logo'     => DB::setting('app_logo', '') !== '' ? 'asset.php?key=app_logo' : null,
            'meta'     => DB::setting('site_meta_description', ''),
        ];
    }

    private static function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        return 'asset.php?site=' . rawurlencode(basename($path));
    }

    private static function findSection(int $id): array
    {
        $row = DB::one('SELECT * FROM site_sections WHERE id = ?', [$id]);
        if ($row === null) {
            Response::notFound('Section not found');
        }
        return $row;
    }
}
