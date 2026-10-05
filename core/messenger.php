<?php
/**
 * AH5 Office - Outbound messaging (WhatsApp Cloud API / Telegram / Messenger)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Note on channels:
 *  - WhatsApp: outside the 24h window an APPROVED TEMPLATE is required.
 *              Set wa_template_name on the message template for reminders.
 *  - Telegram: the customer must press /start on the bot once.
 *  - Messenger: Meta only allows a reply inside 24h of the customer's message,
 *               so reminders cannot be pushed there. It is used for replies only.
 */

declare(strict_types=1);

final class Messenger
{
    /** Send immediately through one channel. Returns a result array. */
    public static function send(
        string $channel,
        string $recipient,
        string $body,
        array $opts = []
    ): array {
        $channel   = strtolower($channel);
        $recipient = trim($recipient);

        if ($recipient === '') {
            return self::fail($channel, $recipient, $body, 'No recipient address for this channel');
        }

        switch ($channel) {
            case 'whatsapp':  return self::sendWhatsApp($recipient, $body, $opts);
            case 'telegram':  return self::sendTelegram($recipient, $body, $opts);
            case 'messenger': return self::sendMessenger($recipient, $body, $opts);
            case 'email':     return self::sendEmail($recipient, $body, $opts);
            case 'manual':    return self::logResult($channel, $recipient, $body, true, null, null, $opts);
            default:          return self::fail($channel, $recipient, $body, 'Unknown channel: ' . $channel);
        }
    }

    // ---------------- email ----------------

    private static function sendEmail(string $to, string $body, array $opts): array
    {
        $subject = (string) ($opts['subject']
            ?? (DB::setting('company_name', 'AH5 Office') . ' - update'));

        $result = Mailer::send($to, $subject, $body, $opts['attachment'] ?? []);

        return self::logResult(
            'email',
            $to,
            $body,
            (bool) $result['success'],
            null,
            $result['success'] ? null : (string) ($result['error'] ?? 'Send failed'),
            $opts
        );
    }

    // ---------------- WhatsApp Cloud API ----------------

    private static function sendWhatsApp(string $to, string $body, array $opts): array
    {
        $phoneId = (string) DB::setting('wa_phone_number_id', '');
        $token   = (string) DB::setting('wa_access_token', '');
        $version = (string) DB::setting('wa_api_version', 'v21.0');

        if ($phoneId === '' || $token === '') {
            return self::fail('whatsapp', $to, $body, 'WhatsApp Cloud API is not configured in settings');
        }

        $to  = (string) Helper::normalizePhone($to);
        $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

        // Template send (needed outside the 24h window)
        if (!empty($opts['template_name'])) {
            $components = [];
            if (!empty($opts['template_params']) && is_array($opts['template_params'])) {
                $params = [];
                foreach ($opts['template_params'] as $p) {
                    $params[] = ['type' => 'text', 'text' => (string) $p];
                }
                $components[] = ['type' => 'body', 'parameters' => $params];
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => [
                    'name'     => (string) $opts['template_name'],
                    'language' => ['code' => (string) ($opts['template_language'] ?? 'en')],
                ],
            ];
            if ($components) {
                $payload['template']['components'] = $components;
            }
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['preview_url' => false, 'body' => $body],
            ];
        }

        [$ok, $resp, $err] = self::http($url, $payload, ['Authorization: Bearer ' . $token]);

        $msgId = $ok ? ($resp['messages'][0]['id'] ?? null) : null;
        $error = $ok ? null : ($resp['error']['message'] ?? $err ?? 'WhatsApp send failed');

        return self::logResult('whatsapp', $to, $body, $ok, $msgId, $error, $opts);
    }

    // ---------------- Telegram ----------------

    private static function sendTelegram(string $chatId, string $body, array $opts): array
    {
        $token = (string) DB::setting('telegram_bot_token', '');
        if ($token === '') {
            return self::fail('telegram', $chatId, $body, 'Telegram bot token is not set');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        [$ok, $resp, $err] = self::http($url, [
            'chat_id' => $chatId,
            'text'    => $body,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        $success = $ok && !empty($resp['ok']);
        $msgId   = $success ? (string) ($resp['result']['message_id'] ?? '') : null;
        $error   = $success ? null : ($resp['description'] ?? $err ?? 'Telegram send failed');

        return self::logResult('telegram', $chatId, $body, $success, $msgId, $error, $opts);
    }

    // ---------------- Messenger (reply only) ----------------

    private static function sendMessenger(string $psid, string $body, array $opts): array
    {
        $token = (string) DB::setting('messenger_page_token', '');
        if ($token === '') {
            return self::fail('messenger', $psid, $body, 'Messenger page token is not set');
        }

        $url = 'https://graph.facebook.com/v21.0/me/messages?access_token=' . urlencode($token);
        [$ok, $resp, $err] = self::http($url, [
            'recipient'      => ['id' => $psid],
            'message'        => ['text' => $body],
            'messaging_type' => 'RESPONSE',
        ]);

        $msgId = $ok ? ($resp['message_id'] ?? null) : null;
        $error = $ok ? null : ($resp['error']['message'] ?? $err
            ?? 'Messenger send failed (Meta allows sending only within 24h of the customer message)');

        return self::logResult('messenger', $psid, $body, $ok, $msgId, $error, $opts);
    }

    // ---------------- queue ----------------

    /** Put a message in the queue for the cron worker. */
    public static function queue(array $row): int
    {
        return DB::insert('reminder_queue', Helper::dropNulls([
            'ref_type'      => $row['ref_type'] ?? 'custom',
            'ref_id'        => $row['ref_id'] ?? null,
            'party_type'    => $row['party_type'] ?? 'customer',
            'party_id'      => $row['party_id'] ?? null,
            'channel'       => $row['channel'] ?? 'whatsapp',
            'recipient'     => (string) $row['recipient'],
            'template_code' => $row['template_code'] ?? null,
            'body'          => (string) $row['body'],
            'payload_json'  => isset($row['payload']) ? json_encode($row['payload'], JSON_UNESCAPED_UNICODE) : null,
            'scheduled_at'  => $row['scheduled_at'] ?? date('Y-m-d H:i:s'),
        ], ['ref_type','party_type','channel','scheduled_at']));
    }

    /** Process due queue rows. Called by cron. */
    public static function processQueue(int $limit = 20): array
    {
        $rows = DB::all(
            "SELECT * FROM reminder_queue
             WHERE status = 'queued' AND scheduled_at <= NOW() AND attempts < 3
             ORDER BY scheduled_at ASC LIMIT " . max(1, min(100, $limit))
        );

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            DB::update('reminder_queue', ['status' => 'sending', 'attempts' => (int) $row['attempts'] + 1],
                'id = ?', [(int) $row['id']]);

            $opts = ['queue_id' => (int) $row['id'], 'party_type' => $row['party_type'], 'party_id' => $row['party_id']];
            if (!empty($row['payload_json'])) {
                $payload = json_decode((string) $row['payload_json'], true);
                if (is_array($payload)) {
                    $opts = array_merge($opts, $payload);
                }
            }

            $res = self::send((string) $row['channel'], (string) $row['recipient'], (string) $row['body'], $opts);

            if ($res['success']) {
                DB::update('reminder_queue', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'error' => null],
                    'id = ?', [(int) $row['id']]);
                $sent++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                DB::update('reminder_queue', [
                    'status' => $attempts >= 3 ? 'failed' : 'queued',
                    'error'  => mb_substr((string) $res['error'], 0, 255),
                ], 'id = ?', [(int) $row['id']]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    // ---------------- ready-made messages ----------------

    public static function template(string $code, string $channel = 'any'): ?array
    {
        return DB::one(
            'SELECT * FROM message_templates
             WHERE code = ? AND is_active = 1 AND (channel = ? OR channel = "any")
             ORDER BY channel = ? DESC LIMIT 1',
            [$code, $channel, $channel]
        );
    }

    /** "টাকা জমা হয়েছে" message after a payment. */
    public static function sendPaymentReceipt(int $paymentId, string $channel = 'whatsapp'): array
    {
        $pay = DB::one(
            'SELECT p.*, c.name AS customer_name, c.whatsapp, c.telegram_chat_id, c.messenger_psid
             FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.id = ?',
            [$paymentId]
        );
        if ($pay === null) {
            return ['success' => false, 'error' => 'Payment not found'];
        }

        $balance = Helper::money((float) DB::value(
            "SELECT COALESCE(SUM(due_amount),0) FROM invoices
             WHERE customer_id = ? AND currency = ? AND deleted_at IS NULL
               AND status NOT IN ('draft','cancelled')",
            [(int) $pay['customer_id'], (string) $pay['currency']]
        ));

        $tpl  = self::template('payment_received', $channel);
        $body = Helper::renderTemplate(
            $tpl['body'] ?? 'Payment {{amount}} {{currency}} received. Due: {{balance}} {{currency}}.',
            [
                'customer_name' => $pay['customer_name'],
                'amount'        => number_format((float) $pay['amount'], 2),
                'currency'      => $pay['currency'],
                'balance'       => number_format($balance, 2),
            ]
        );

        $recipient = self::recipientFor($pay, $channel);
        if ($recipient === null) {
            return ['success' => false, 'error' => 'Customer has no ' . $channel . ' address saved'];
        }

        $res = self::send($channel, $recipient, $body, [
            'party_type'        => 'customer',
            'party_id'          => (int) $pay['customer_id'],
            'template_name'     => $tpl['wa_template_name'] ?? null,
            'template_language' => $tpl['wa_language'] ?? 'en',
            'template_params'   => [$pay['customer_name'], number_format((float) $pay['amount'], 2), $pay['currency']],
        ]);

        if ($res['success']) {
            DB::update('payments', ['notify_sent' => 1], 'id = ?', [$paymentId]);
        }
        return $res;
    }

    /** Pick the right address on a customer/supplier row for a channel. */
    public static function recipientFor(array $party, string $channel): ?string
    {
        $value = match ($channel) {
            'whatsapp'  => $party['whatsapp'] ?? $party['phone'] ?? null,
            'telegram'  => $party['telegram_chat_id'] ?? null,
            'messenger' => $party['messenger_psid'] ?? null,
            'email'     => $party['email'] ?? null,
            default     => $party['phone'] ?? null,
        };
        $value = $value !== null ? trim((string) $value) : '';
        return $value === '' ? null : $value;
    }

    // ---------------- plumbing ----------------

    /** POST JSON. Returns [ok, decodedBody, errorString]. */
    private static function http(string $url, array $payload, array $headers = []): array
    {
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = array_merge(['Content-Type: application/json'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return [false, [], $err !== '' ? $err : 'Network error'];
            }
            $decoded = json_decode((string) $raw, true);
            return [$code >= 200 && $code < 300, is_array($decoded) ? $decoded : [], $err !== '' ? $err : null];
        }

        // fallback for hosts without cURL
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 25,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return [false, [], 'Network error (allow_url_fopen)'];
        }
        $code    = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        $decoded = json_decode((string) $raw, true);
        return [$code >= 200 && $code < 300, is_array($decoded) ? $decoded : [], null];
    }

    private static function logResult(
        string $channel, string $recipient, string $body,
        bool $ok, ?string $msgId, ?string $error, array $opts
    ): array {
        DB::insert('message_log', [
            'queue_id'        => $opts['queue_id'] ?? null,
            'party_type'      => $opts['party_type'] ?? 'customer',
            'party_id'        => isset($opts['party_id']) ? (int) $opts['party_id'] : null,
            'channel'         => $channel,
            'direction'       => 'out',
            'recipient'       => mb_substr($recipient, 0, 120),
            'body'            => $body,
            'provider_msg_id' => $msgId,
            'status'          => $ok ? 'sent' : 'failed',
            'error'           => $error !== null ? mb_substr($error, 0, 255) : null,
            'sent_by'         => Auth::userId(),
        ]);

        return ['success' => $ok, 'channel' => $channel, 'recipient' => $recipient,
                'message_id' => $msgId, 'error' => $error];
    }

    private static function fail(string $channel, string $recipient, string $body, string $error): array
    {
        return self::logResult($channel, $recipient, $body, false, null, $error, []);
    }
}
