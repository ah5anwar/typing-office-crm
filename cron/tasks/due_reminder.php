<?php
/**
 * Cron task: outstanding payment reminders
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

return static function (): string {
    $milestones = array_map('intval', array_filter(explode(',', (string) DB::setting('due_reminder_days', '3,7,15,30'))));
    if (!$milestones) {
        return 'no reminder days configured';
    }

    $invoices = DB::all(
        "SELECT i.*, c.id AS cid, c.name AS customer_name, c.whatsapp, c.phone,
                c.telegram_chat_id, c.messenger_psid,
                DATEDIFF(CURDATE(), i.due_date) AS days_overdue
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.deleted_at IS NULL AND c.deleted_at IS NULL
           AND i.remind_enabled = 1 AND i.due_amount > 0
           AND i.status NOT IN ('draft','cancelled')
           AND i.due_date IS NOT NULL AND i.due_date < CURDATE()"
    );

    $tpl    = Messenger::template('due_reminder', 'whatsapp');
    $queued = 0;
    $selfLines = [];

    foreach ($invoices as $inv) {
        $overdue = (int) $inv['days_overdue'];
        if (!in_array($overdue, $milestones, true)) {
            continue;
        }
        if (!empty($inv['last_remind_at']) && date('Y-m-d', strtotime((string) $inv['last_remind_at'])) === date('Y-m-d')) {
            continue;
        }

        $body = Helper::renderTemplate(
            $tpl['body'] ?? 'Invoice {{invoice_no}} due {{due_amount}} {{currency}}',
            [
                'customer_name' => $inv['customer_name'],
                'invoice_no'    => $inv['invoice_no'],
                'due_amount'    => number_format((float) $inv['due_amount'], 2),
                'currency'      => $inv['currency'],
            ]
        );

        foreach (['whatsapp', 'telegram'] as $channel) {
            $to = Messenger::recipientFor($inv, $channel);
            if ($to === null) {
                continue;
            }
            Messenger::queue([
                'ref_type'      => 'invoice',
                'ref_id'        => (int) $inv['id'],
                'party_type'    => 'customer',
                'party_id'      => (int) $inv['cid'],
                'channel'       => $channel,
                'recipient'     => $to,
                'body'          => $body,
                'template_code' => 'due_reminder',
                'payload'       => [
                    'template_name'     => $tpl['wa_template_name'] ?? null,
                    'template_language' => $tpl['wa_language'] ?? 'en',
                    'template_params'   => [
                        $inv['customer_name'],
                        $inv['invoice_no'],
                        number_format((float) $inv['due_amount'], 2) . ' ' . $inv['currency'],
                    ],
                ],
            ]);
            $queued++;
            break;
        }

        $selfLines[] = '• ' . $inv['customer_name'] . ' - ' . $inv['invoice_no'] . ' - '
            . number_format((float) $inv['due_amount'], 2) . ' ' . $inv['currency']
            . ' (' . $overdue . ' দিন পার)';

        DB::update('invoices', ['last_remind_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $inv['id']]);
    }

    // mark overdue invoices so the dashboard is accurate
    DB::run(
        "UPDATE invoices SET status = 'overdue'
         WHERE deleted_at IS NULL AND due_amount > 0 AND due_date < CURDATE()
           AND status IN ('sent','partial')"
    );

    if ($selfLines) {
        $selfWa = (string) DB::setting('self_whatsapp', '');
        $selfTg = (string) DB::setting('self_telegram_chat_id', '');
        $digest = "💰 বকেয়া রিমাইন্ডার:\n" . implode("\n", $selfLines);
        if ($selfWa !== '') {
            Messenger::queue(['ref_type' => 'invoice', 'party_type' => 'self',
                'channel' => 'whatsapp', 'recipient' => $selfWa, 'body' => $digest]);
            $queued++;
        } elseif ($selfTg !== '') {
            Messenger::queue(['ref_type' => 'invoice', 'party_type' => 'self',
                'channel' => 'telegram', 'recipient' => $selfTg, 'body' => $digest]);
            $queued++;
        }
    }

    return 'queued ' . $queued . ' due reminder(s)';
};
