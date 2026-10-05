<?php
/**
 * AH5 Office - Shared helpers
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class Helper
{
    /**
     * Document number: PREFIX + ddmmyy + 2-digit sequence, resets daily.
     * e.g. AH5-07082601
     */
    public static function docNumber(string $docType, ?string $date = null): string
    {
        $map = [
            'invoice'          => 'invoice_prefix',
            'quotation'        => 'quotation_prefix',
            'payment'          => 'receipt_prefix',
            'supplier_bill'    => 'supplier_bill_prefix',
            'supplier_payment' => 'supplier_voucher_prefix',
        ];
        if (!isset($map[$docType])) {
            throw new InvalidArgumentException('Unknown doc type: ' . $docType);
        }

        $defaults = [
            'invoice_prefix'          => 'AH5-',
            'quotation_prefix'        => 'AH5Q-',
            'receipt_prefix'          => 'AH5R-',
            'supplier_bill_prefix'    => 'AH5SB-',
            'supplier_voucher_prefix' => 'AH5SV-',
        ];
        $key    = $map[$docType];
        $prefix = DB::setting($key, $defaults[$key]);

        $date  = $date ?: date('Y-m-d');
        $stamp = date('dmy', strtotime($date));

        // Atomic increment AND read-back, safe against two entries in the
        // same second. LAST_INSERT_ID(expr) inside the UPDATE clause makes
        // MySQL remember that value for *this connection only*; the
        // SELECT LAST_INSERT_ID() below reads it back regardless of what
        // any other, simultaneous request does to the same counter row in
        // between - unlike a plain follow-up SELECT, which could pick up
        // a neighbour's increment and hand out the same number twice.
        DB::run(
            'INSERT INTO doc_counters (doc_type, doc_date, last_seq) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)',
            [$docType, $date]
        );
        $seq = (int) DB::value('SELECT LAST_INSERT_ID()');

        return $prefix . $stamp . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Sequential party code: CUS-0001 / SUP-0001. Two people adding a
     * customer at the exact same moment could both compute the same next
     * number; `code` has a unique constraint so the loser fails cleanly
     * rather than silently duplicating one - `insertWithFreshCode` is what
     * catches that and tries again with a newly computed code.
     */
    public static function partyCode(string $table, string $prefix): string
    {
        $last = (int) DB::value(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0)
             FROM `{$table}` WHERE code LIKE ?",
            [strlen($prefix) + 1, $prefix . '%']
        );
        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Insert a row whose `code` came from partyCode(), retrying with a
     * fresh code if another request grabbed the same number in between.
     * $build receives the code and returns the full row to insert.
     */
    public static function insertWithFreshCode(string $table, string $prefix, callable $build): int
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $code = self::partyCode($table, $prefix);
            try {
                return DB::insert($table, $build($code));
            } catch (PDOException $e) {
                $isDuplicate = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'code');
                if (!$isDuplicate || $attempt === 3) {
                    throw $e;
                }
                // someone else just took this number - compute a new one and retry
            }
        }
        throw new RuntimeException('Could not generate a unique code for ' . $table);
    }

    /** Normalise a phone number to digits only (E.164 without +). */
    public static function normalizePhone(?string $phone, string $defaultCountry = '880'): ?string
    {
        if ($phone === null) {
            return null;
        }
        $p = preg_replace('/[^0-9+]/', '', $phone);
        if ($p === '' || $p === null) {
            return null;
        }
        $p = ltrim($p, '+');
        // Local BD number: 01XXXXXXXXX -> 8801XXXXXXXXX
        if (strlen($p) === 11 && str_starts_with($p, '01')) {
            $p = $defaultCountry . substr($p, 1);
        }
        return $p;
    }

    /**
     * Remove NULL values so MySQL column defaults apply.
     * $only = null  -> drop every null (use on INSERT)
     * $only = [...] -> drop nulls only for those NOT NULL columns (use on UPDATE)
     */
    public static function dropNulls(array $data, ?array $only = null): array
    {
        foreach ($data as $k => $v) {
            if ($v === null && ($only === null || in_array($k, $only, true))) {
                unset($data[$k]);
            }
        }
        return $data;
    }

    /**
     * Parse a user-supplied date. Returns null when it is not a real date,
     * so a bad query string can never crash the request.
     */
    public static function parseDate($value, ?string $fallback = null): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return $fallback;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return $fallback;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * Cash in hand, or money that moved through a bank.
     * If you do not say, we work it out from how it was paid.
     */
    public static function moneyMode(?string $given, ?string $method): string
    {
        if ($given === 'cash' || $given === 'bank') {
            return $given;
        }
        return in_array((string) $method, ['cash', ''], true) || $method === null ? 'cash' : 'bank';
    }

    /**
     * A number the way it is spoken: 1,800 - not 1,800.00.
     * Paisa only show when there actually are some.
     */
    public static function formatMoney($value): string
    {
        $amount = (float) $value;
        $hasFraction = abs(fmod($amount, 1)) > 0.004;
        return number_format($amount, $hasFraction ? 2 : 0);
    }

    public static function money($value): float
    {
        return round((float) $value, 2);
    }

    public static function isValidCurrency(?string $c): bool
    {
        return $c !== null && in_array(strtoupper($c), SUPPORTED_CURRENCIES, true);
    }

    /** Convert an amount to base currency using a stored fx_rate. */
    public static function toBase(float $amount, float $fxRate): float
    {
        return round($amount * ($fxRate > 0 ? $fxRate : 1), 2);
    }

    /**
     * There is one office currency, so nothing needs converting.
     * Kept so older rows and callers still line up.
     */
    public static function fxRate(string $currency = '', ?string $date = null): float
    {
        return 1.0;
    }

    /** Render {{placeholders}} in a message template body. */
    public static function renderTemplate(string $body, array $vars): string
    {
        $out = $body;
        foreach ($vars as $k => $v) {
            $out = str_replace('{{' . $k . '}}', (string) $v, $out);
        }
        // strip any placeholder left unfilled
        return (string) preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $out);
    }

    public static function logActivity(string $entity, ?int $entityId, string $action, ?string $note = null, array $meta = []): void
    {
        try {
            DB::insert('activity_log', [
                'user_id'   => Auth::userId(),
                'entity'    => $entity,
                'entity_id' => $entityId,
                'action'    => $action,
                'note'      => $note !== null ? mb_substr($note, 0, 255) : null,
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip'        => self::clientIp(),
            ]);
        } catch (Throwable $e) {
            error_log('activity_log failed: ' . $e->getMessage());
        }
    }

    public static function clientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /** page / per_page from the query string, clamped. */
    public static function pagination(): array
    {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 25);
        $perPage = max(1, min(200, $perPage));
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /** Whitelist a sort column coming from the client. */
    public static function sortColumn(array $allowed, string $default): array
    {
        $col = (string) ($_GET['sort'] ?? $default);
        if (!in_array($col, $allowed, true)) {
            $col = $default;
        }
        $dir = strtoupper((string) ($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        return [$col, $dir];
    }

    /**
     * The one currency this office works in. Set it once in Settings and
     * every screen, invoice and report uses it - there is no second one.
     */
    public static function baseCurrency(): string
    {
        static $cached = null;
        if ($cached === null) {
            $value = strtoupper((string) DB::setting('base_currency', BASE_CURRENCY));
            $cached = self::isValidCurrency($value) ? $value : 'BDT';
        }
        return $cached;
    }

    /** Kept for older calls: the answer is always the office currency. */
    public static function currencyFor(?int $customerId): string
    {
        return self::baseCurrency();
    }

    /** Service prices live in one pair of columns now. */
    public static function priceColumns(string $currency = ''): array
    {
        return ['sell_primary', 'cost_primary'];
    }

    /**
     * Strip cost and profit figures from a row or list of rows when the
     * signed-in user is not allowed to see them.
     */
    public static function scrubCosts($data)
    {
        if (Auth::seesCosts()) {
            return $data;
        }
        $hidden = ['cost_price', 'total_cost', 'cost_primary', 'cost_secondary', 'cost',
                   'profit', 'profit_primary', 'profit_secondary', 'gross_profit',
                   'est_cost', 'supplier_cost', 'renewal_cost', 'agreed_cost'];

        if (!is_array($data)) {
            return $data;
        }
        $isList = array_keys($data) === range(0, count($data) - 1);
        if ($isList) {
            return array_map([self::class, 'scrubCosts'], $data);
        }
        foreach ($data as $k => $v) {
            if (in_array($k, $hidden, true)) {
                unset($data[$k]);
            } elseif (is_array($v)) {
                $data[$k] = self::scrubCosts($v);
            }
        }
        return $data;
    }

    public static function safeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
        return mb_substr(trim($name, '._-'), 0, 120) ?: 'file';
    }
}
