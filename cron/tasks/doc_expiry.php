<?php
/**
 * Cron task: document expiry reminders
 * Queues a message to the customer and/or to me, on each of the
 * remind_days milestones (e.g. 30,15,7,1 days before expiry).
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

return static function (): string {
    $docs = DB::all(
        "SELECT d.*, dt.name AS doc_type_name,
                c.id AS cid, c.name AS customer_name, c.whatsapp, c.phone,
                c.telegram_chat_id, c.messenger_psid,
                DATEDIFF(d.expiry_date, CURDATE()) AS days_left
         FROM customer_documents d
         JOIN customers c ON c.id = d.customer_id
         LEFT JOIN document_types dt ON dt.id = d.doc_type_id
         WHERE d.deleted_at IS NULL AND d.status = 'active' AND c.deleted_at IS NULL
           AND d.expiry_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
           AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)"
    );

    $tpl        = Messenger::template('doc_expiry', 'whatsapp');
    $selfWa     = (string) DB::setting('self_whatsapp', '');
    $selfTg     = (string) DB::setting('self_telegram_chat_id', '');
    $queued     = 0;
    $selfLines  = [];

    foreach ($docs as $doc) {
        $daysLeft   = (int) $doc['days_left'];
        $milestones = array_map('intval', array_filter(explode(',', (string) $doc['remind_days'])));

        if (!in_array($daysLeft, $milestones, true)) {
            continue;
        }
        // already reminded today?
        if (!empty($doc['last_remind_at']) && date('Y-m-d', strtotime((string) $doc['last_remind_at'])) === date('Y-m-d')) {
            continue;
        }

        $body = Helper::renderTemplate(
            $tpl['body'] ?? '{{doc_title}} expires on {{expiry_date}} ({{days_left}} days left).',
            [
                'customer_name' => $doc['customer_name'],
                'doc_title'     => $doc['title'],
                'expiry_date'   => date('d/m/Y', strtotime((string) $doc['expiry_date'])),
                'days_left'     => (string) $daysLeft,
            ]
        );

        if ((int) $doc['remind_customer'] === 1) {
            foreach (['whatsapp', 'telegram'] as $channel) {
                $to = Messenger::recipientFor($doc, $channel);
                if ($to === null) {
                    continue;
                }
                Messenger::queue([
                    'ref_type'   => 'document',
                    'ref_id'     => (int) $doc['id'],
                    'party_type' => 'customer',
                    'party_id'   => (int) $doc['cid'],
                    'channel'    => $channel,
                    'recipient'  => $to,
                    'body'       => $body,
                    'template_code' => 'doc_expiry',
                    'payload'    => [
                        'template_name'     => $tpl['wa_template_name'] ?? null,
                        'template_language' => $tpl['wa_language'] ?? 'en',
                        'template_params'   => [
                            $doc['customer_name'],
                            $doc['title'],
                            date('d/m/Y', strtotime((string) $doc['expiry_date'])),
                        ],
                    ],
                ]);
                $queued++;
                break; // one channel per customer is enough
            }
        }

        if ((int) $doc['remind_self'] === 1) {
            $selfLines[] = '• ' . $doc['customer_name'] . ' - ' . $doc['title']
                . ' (' . $daysLeft . ' দিন বাকি, ' . date('d/m/Y', strtotime((string) $doc['expiry_date'])) . ')';
        }

        DB::update('customer_documents', ['last_remind_at' => date('Y-m-d H:i:s')],
            'id = ?', [(int) $doc['id']]);
    }

    // one digest to me instead of many separate pings
    if ($selfLines) {
        $digest = "📄 মেয়াদ শেষ হচ্ছে:\n" . implode("\n", $selfLines);
        if ($selfWa !== '') {
            Messenger::queue([
                'ref_type' => 'document', 'party_type' => 'self',
                'channel'  => 'whatsapp', 'recipient' => $selfWa, 'body' => $digest,
            ]);
            $queued++;
        } elseif ($selfTg !== '') {
            Messenger::queue([
                'ref_type' => 'document', 'party_type' => 'self',
                'channel'  => 'telegram', 'recipient' => $selfTg, 'body' => $digest,
            ]);
            $queued++;
        }
    }

    // mark anything past its date
    $expired = DB::run(
        "UPDATE customer_documents SET status = 'expired'
         WHERE deleted_at IS NULL AND status = 'active' AND expiry_date < CURDATE()"
    )->rowCount();

    return 'queued ' . $queued . ' reminder(s), marked ' . $expired . ' expired';
};
