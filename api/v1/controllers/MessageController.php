<?php
/**
 * AH5 Office - Messaging endpoints
 * (custom message, service-list message, due reminder, queue, templates)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class MessageController
{
    // ---------------- templates ----------------

    public static function templates(): void
    {
        Auth::allow('messages.view');
        Response::ok(DB::all('SELECT * FROM message_templates ORDER BY code ASC, channel ASC'));
    }

    public static function updateTemplate(string $id): void
    {
        Auth::allow('settings.manage');
        $v = Validator::make()
            ->check('name', 'nullable|string|max:140')
            ->check('body', 'nullable|string|max:5000')
            ->check('wa_template_name', 'nullable|string|max:120')
            ->check('wa_language', 'nullable|string|max:10')
            ->check('is_active', 'nullable|bool');
        $data = Helper::dropNulls($v->present($v->validate()), ['name','body','wa_language','is_active']);

        if ($data) {
            DB::update('message_templates', $data, 'id = ?', [(int) $id]);
        }
        $row = DB::one('SELECT * FROM message_templates WHERE id = ?', [(int) $id]);
        if ($row === null) {
            Response::notFound('Template not found');
        }
        Response::ok($row, 'Template updated');
    }

    // ---------------- one-click service list ----------------

    /**
     * Build (and optionally send) the "we offer these services" message.
     * Body: { service_ids:[1,2], customer_id?:1, channel?:whatsapp, send?:true, with_price?:false }
     */
    public static function serviceList(): void
    {
        Auth::allow('messages.send');
        $body       = Validator::input();
        $serviceIds = $body['service_ids'] ?? [];
        $withPrice  = !empty($body['with_price']);
        $customerId = isset($body['customer_id']) && $body['customer_id'] !== '' ? (int) $body['customer_id'] : null;

        if (!is_array($serviceIds) || !$serviceIds) {
            $services = DB::all('SELECT * FROM services WHERE is_active = 1 AND show_in_list = 1 ORDER BY sort_order ASC, name ASC');
        } else {
            $ids = array_map('intval', $serviceIds);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $services = DB::all("SELECT * FROM services WHERE id IN ({$ph}) ORDER BY sort_order ASC, name ASC", $ids);
        }
        if (!$services) {
            Response::error('No service selected', 422);
        }

        $customer = null;
        $currency = strtoupper((string) ($body['currency'] ?? BASE_CURRENCY));
        if ($customerId !== null) {
            $customer = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
            if ($customer === null) {
                Response::notFound('Customer not found');
            }
            if (empty($body['currency'])) {
                $currency = Helper::baseCurrency();
            }
        }

        $lines = [];
        foreach ($services as $i => $s) {
            $name = (string) $s['name'];
            $line = ($i + 1) . '. ' . $name;
            if ($withPrice) {
                [$sellCol] = Helper::priceColumns($currency);
                $price = (float) $s[$sellCol];
                if ($price > 0) {
                    $line .= ' - ' . number_format($price, 2) . ' ' . $currency;
                }
            }
            $lines[] = $line;
        }

        $tpl  = Messenger::template('service_list', (string) ($body['channel'] ?? 'any'));
        $text = Helper::renderTemplate(
            $tpl['body'] ?? "{{customer_name}}\n{{service_list}}",
            [
                'customer_name'  => $customer['name'] ?? '',
                'service_list'   => implode("\n", $lines),
                'company_phone'  => (string) DB::setting('company_phone', ''),
                'company_name'   => (string) DB::setting('company_name', 'Creatives iT'),
            ]
        );
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        $result = ['text' => $text, 'service_count' => count($services), 'currency' => $currency];

        // send only if asked; otherwise it is just copy-ready text
        if (!empty($body['send']) && $customer !== null) {
            $channel = (string) ($body['channel'] ?? 'whatsapp');
            $to      = Messenger::recipientFor($customer, $channel);
            $result['sent'] = $to === null
                ? ['success' => false, 'error' => 'Customer has no ' . $channel . ' address']
                : Messenger::send($channel, $to, $text, [
                    'party_type' => 'customer',
                    'party_id'   => (int) $customer['id'],
                ]);
        }

        Response::ok($result);
    }

    // ---------------- custom message ----------------

    /**
     * Free-form message to one or many customers/suppliers.
     * Body: { party_type, party_ids:[..], channel, body, schedule_at? }
     */
    public static function custom(): void
    {
        Auth::allow('messages.send');
        $in = Validator::make()
            ->check('body', 'required|string|max:4000', 'Message')
            ->check('party_type', 'nullable|in:customer,supplier,self')
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email')
            ->check('schedule_at', 'nullable|datetime')
            ->validate();

        $partyType = (string) ($in['party_type'] ?? 'customer');
        $channel   = (string) ($in['channel'] ?? 'whatsapp');
        $ids       = Validator::input()['party_ids'] ?? [];

        if ($partyType === 'self') {
            $to = $channel === 'telegram'
                ? (string) DB::setting('self_telegram_chat_id', '')
                : (string) DB::setting('self_whatsapp', '');
            if ($to === '') {
                Response::error('Your own ' . $channel . ' address is not set in settings', 422);
            }
            Response::ok(['results' => [Messenger::send($channel, $to, (string) $in['body'], ['party_type' => 'self'])]]);
        }

        if (!is_array($ids) || !$ids) {
            Response::error('Select at least one recipient', 422);
        }

        $table   = $partyType === 'supplier' ? 'suppliers' : 'customers';
        $idList  = array_map('intval', $ids);
        $ph      = implode(',', array_fill(0, count($idList), '?'));
        $parties = DB::all("SELECT * FROM `{$table}` WHERE id IN ({$ph}) AND deleted_at IS NULL", $idList);

        $results  = [];
        $schedule = $in['schedule_at'] ?? null;

        foreach ($parties as $party) {
            $to   = Messenger::recipientFor($party, $channel);
            $text = Helper::renderTemplate((string) $in['body'], [
                'customer_name' => $party['name'],
                'supplier_name' => $party['name'],
                'company_name'  => $party['company_name'] ?? '',
            ]);

            if ($to === null) {
                $results[] = ['party_id' => (int) $party['id'], 'name' => $party['name'],
                              'success' => false, 'error' => 'No ' . $channel . ' address'];
                continue;
            }

            if ($schedule !== null) {
                $qid = Messenger::queue([
                    'ref_type'     => 'custom',
                    'party_type'   => $partyType,
                    'party_id'     => (int) $party['id'],
                    'channel'      => $channel,
                    'recipient'    => $to,
                    'body'         => $text,
                    'scheduled_at' => $schedule,
                ]);
                $results[] = ['party_id' => (int) $party['id'], 'name' => $party['name'],
                              'success' => true, 'queued' => true, 'queue_id' => $qid];
            } else {
                $res = Messenger::send($channel, $to, $text, [
                    'party_type' => $partyType, 'party_id' => (int) $party['id'],
                ]);
                $results[] = array_merge(['party_id' => (int) $party['id'], 'name' => $party['name']], $res);
            }
        }

        Response::ok(['results' => $results], $schedule !== null ? 'Messages queued' : 'Messages sent');
    }

    // ---------------- due reminder ----------------

    /** Manual due reminder for one invoice or a whole customer. */
    public static function dueReminder(): void
    {
        Auth::allow('messages.send');
        $in = Validator::make()
            ->check('invoice_id', 'nullable|int|exists:invoices')
            ->check('customer_id', 'nullable|int|exists:customers')
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email')
            ->check('body', 'nullable|string|max:2000')
            ->validate();

        $channel = (string) ($in['channel'] ?? 'whatsapp');
        $tpl     = Messenger::template('due_reminder', $channel);

        if (!empty($in['invoice_id'])) {
            $inv = DB::one(
                'SELECT i.*, c.name AS customer_name, c.whatsapp, c.phone, c.telegram_chat_id, c.messenger_psid
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 WHERE i.id = ? AND i.deleted_at IS NULL',
                [(int) $in['invoice_id']]
            );
            if ($inv === null) {
                Response::notFound('Invoice not found');
            }
            $text = $in['body'] ?? Helper::renderTemplate(
                $tpl['body'] ?? 'Invoice {{invoice_no}} due: {{due_amount}} {{currency}}',
                [
                    'customer_name' => $inv['customer_name'],
                    'invoice_no'    => $inv['invoice_no'],
                    'due_amount'    => number_format((float) $inv['due_amount'], 2),
                    'currency'      => $inv['currency'],
                ]
            );
            $to = Messenger::recipientFor($inv, $channel);
            if ($to === null) {
                Response::error('Customer has no ' . $channel . ' address', 422);
            }
            $res = Messenger::send($channel, $to, $text, [
                'party_type'        => 'customer',
                'party_id'          => (int) $inv['customer_id'],
                'template_name'     => $tpl['wa_template_name'] ?? null,
                'template_language' => $tpl['wa_language'] ?? 'en',
                'template_params'   => [$inv['customer_name'], $inv['invoice_no'],
                                        number_format((float) $inv['due_amount'], 2) . ' ' . $inv['currency']],
            ]);
            DB::update('invoices', ['last_remind_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $inv['id']]);
            Response::ok(['body' => $text, 'result' => $res], 'Reminder sent');
        }

        if (empty($in['customer_id'])) {
            Response::error('Give an invoice_id or a customer_id', 422);
        }

        $customer = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL', [(int) $in['customer_id']]);
        if ($customer === null) {
            Response::notFound('Customer not found');
        }
        $dues = DB::all(
            "SELECT currency, COALESCE(SUM(due_amount),0) AS due, COUNT(*) AS n
             FROM invoices WHERE customer_id = ? AND deleted_at IS NULL
               AND status NOT IN ('draft','cancelled') AND due_amount > 0
             GROUP BY currency",
            [(int) $customer['id']]
        );
        if (!$dues) {
            Response::error('This customer has no outstanding due', 422);
        }

        $parts = [];
        foreach ($dues as $d) {
            $parts[] = number_format((float) $d['due'], 2) . ' ' . $d['currency'] . ' (' . $d['n'] . ')';
        }
        $text = $in['body'] ?? Helper::renderTemplate(
            $tpl['body'] ?? 'Total due: {{due_amount}} {{currency}}',
            [
                'customer_name' => $customer['name'],
                'invoice_no'    => '-',
                'due_amount'    => implode(', ', $parts),
                'currency'      => '',
            ]
        );
        $to = Messenger::recipientFor($customer, $channel);
        if ($to === null) {
            Response::error('Customer has no ' . $channel . ' address', 422);
        }
        $res = Messenger::send($channel, $to, $text, [
            'party_type' => 'customer', 'party_id' => (int) $customer['id'],
        ]);

        Response::ok(['body' => $text, 'result' => $res], 'Reminder sent');
    }

    // ---------------- log & queue ----------------


    /**
     * Send an invoice or a quotation to the customer.
     * WhatsApp and Telegram get a link they can open; email gets the same
     * link plus the document attached as a PDF-ready page.
     */
    public static function sendDocument(): void
    {
        Auth::allow('messages.send');

        $in = Validator::make()
            ->check('type', 'required|in:invoice,quotation', 'Document type')
            ->check('id', 'required|int', 'Document')
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email')
            ->check('note', 'nullable|string|max:500')
            ->validate();

        $isInvoice = $in['type'] === 'invoice';
        $table = $isInvoice ? 'invoices' : 'quotations';
        $row = DB::one(
            "SELECT d.*, c.id AS cid, c.name AS customer_name, c.company_name, c.phone,
                    c.whatsapp, c.email, c.telegram_chat_id, c.messenger_psid
             FROM `{$table}` d JOIN customers c ON c.id = d.customer_id
             WHERE d.id = ? AND d.deleted_at IS NULL",
            [(int) $in['id']]
        );
        if ($row === null) {
            Response::notFound(ucfirst((string) $in['type']) . ' not found');
        }

        $channel = (string) ($in['channel'] ?? 'whatsapp');
        $to = Messenger::recipientFor($row, $channel);
        if ($to === null) {
            Response::error('This customer has no ' . $channel . ' address on file', 422);
        }

        $docNo = (string) ($isInvoice ? $row['invoice_no'] : $row['quote_no']);
        $link  = PrintAuth::link($isInvoice ? 'invoice' : 'quotation', (int) $row['id'],
            'print/' . ($isInvoice ? 'invoice' : 'quotation') . '.php');

        $cur  = Helper::baseCurrency();
        $body = Helper::renderTemplate(
            (string) DB::setting(
                $isInvoice ? 'tpl_invoice_send' : 'tpl_quotation_send',
                $isInvoice
                    ? "{{customer_name}}, your invoice {{doc_no}} for {{total}} {{currency}} is ready.\n"
                      . "Due: {{due}} {{currency}}\nOpen it here: {{link}}\n\n- {{company}}"
                    : "{{customer_name}}, here is quotation {{doc_no}} for {{total}} {{currency}}.\n"
                      . "Open it here: {{link}}\n\n- {{company}}"
            ),
            [
                'customer_name' => (string) $row['customer_name'],
                'company_name'  => (string) ($row['company_name'] ?? ''),
                'doc_no'        => $docNo,
                'total'         => Helper::formatMoney((float) $row['total']),
                'due'           => Helper::formatMoney((float) ($row['due_amount'] ?? 0)),
                'currency'      => $cur,
                'link'          => $link,
                'company'       => (string) DB::setting('company_name', 'Creatives iT'),
            ]
        );

        if (!empty($in['note'])) {
            $body .= "\n\n" . $in['note'];
        }

        $result = Messenger::send($channel, $to, $body, [
            'party_type' => 'customer',
            'party_id'   => (int) $row['cid'],
            'ref_type'   => $in['type'],
            'ref_id'     => (int) $row['id'],
            'subject'    => ($isInvoice ? 'Invoice ' : 'Quotation ') . $docNo
                          . ' from ' . DB::setting('company_name', 'Creatives iT'),
        ]);

        if ($isInvoice && $result['success'] && $row['status'] === 'draft') {
            DB::update('invoices', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $row['id']]);
        }

        Response::ok([
            'result' => $result,
            'body'   => $body,          // so you can copy it if the send fails
            'link'   => $link,
            'to'     => $to,
        ], $result['success'] ? ucfirst((string) $in['type']) . ' sent' : 'Could not send - the text is ready to copy');
    }

    /**
     * Send someone their standing: what they have paid, what is left,
     * and any advance still sitting with you. Works for suppliers too.
     */
    public static function sendStatement(): void
    {
        Auth::allow('messages.send');

        $in = Validator::make()
            ->check('party_type', 'required|in:customer,supplier', 'Party')
            ->check('party_id', 'required|int', 'Party')
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email')
            ->validate();

        $isCustomer = $in['party_type'] === 'customer';
        $table = $isCustomer ? 'customers' : 'suppliers';
        $party = DB::one("SELECT * FROM `{$table}` WHERE id = ? AND deleted_at IS NULL", [(int) $in['party_id']]);
        if ($party === null) {
            Response::notFound(ucfirst((string) $in['party_type']) . ' not found');
        }

        $channel = (string) ($in['channel'] ?? 'whatsapp');
        $to = Messenger::recipientFor($party, $channel);
        if ($to === null) {
            Response::error('No ' . $channel . ' address on file for them', 422);
        }

        $cur = Helper::baseCurrency();

        if ($isCustomer) {
            $figures = DB::one(
                "SELECT COALESCE(SUM(total),0) AS billed,
                        COALESCE(SUM(paid_amount),0) AS paid,
                        COALESCE(SUM(due_amount),0) AS due
                 FROM invoices
                 WHERE customer_id = ? AND deleted_at IS NULL AND status NOT IN ('draft','cancelled')",
                [(int) $party['id']]
            );
            $advance = (float) DB::value(
                'SELECT COALESCE(SUM(unallocated_amount),0) FROM payments
                 WHERE customer_id = ? AND deleted_at IS NULL',
                [(int) $party['id']]
            );
            $openWork = (int) DB::value(
                "SELECT COUNT(*) FROM job_items ji JOIN jobs j ON j.id = ji.job_id AND j.deleted_at IS NULL
                 WHERE j.customer_id = ? AND ji.status IN ('pending','in_progress')",
                [(int) $party['id']]
            );
        } else {
            $figures = DB::one(
                "SELECT COALESCE(SUM(amount),0) AS billed,
                        COALESCE(SUM(paid_amount),0) AS paid,
                        COALESCE(SUM(due_amount),0) AS due
                 FROM supplier_bills WHERE supplier_id = ? AND deleted_at IS NULL AND status <> 'cancelled'",
                [(int) $party['id']]
            );
            $advance = (float) DB::value(
                'SELECT COALESCE(SUM(unallocated_amount),0) FROM supplier_payments
                 WHERE supplier_id = ? AND deleted_at IS NULL',
                [(int) $party['id']]
            );
            $openWork = (int) DB::value(
                "SELECT COUNT(*) FROM job_supplier_assign
                 WHERE supplier_id = ? AND status IN ('pending','in_progress')",
                [(int) $party['id']]
            );
        }

        $lines = [];
        $lines[] = ($party['name'] ?? '') . ',';
        $lines[] = '';
        $lines[] = ($isCustomer ? 'Your account with ' : 'Our account with ')
                 . DB::setting('company_name', 'Creatives iT') . ':';
        $lines[] = 'Billed: ' . Helper::formatMoney((float) $figures['billed']) . ' ' . $cur;
        $lines[] = ($isCustomer ? 'Paid: ' : 'Paid to you: ')
                 . Helper::formatMoney((float) $figures['paid']) . ' ' . $cur;
        $lines[] = ($isCustomer ? 'Still due: ' : 'Still owed: ')
                 . Helper::formatMoney((float) $figures['due']) . ' ' . $cur;
        if ($advance > 0) {
            $lines[] = ($isCustomer ? 'Advance with us: ' : 'Advance paid to you: ')
                     . Helper::formatMoney($advance) . ' ' . $cur;
        }
        if ($openWork > 0) {
            $lines[] = 'Work still open: ' . $openWork;
        }
        $lines[] = '';
        $lines[] = DB::setting('company_name', 'Creatives iT');

        $body = implode("\n", $lines);

        $result = Messenger::send($channel, $to, $body, [
            'party_type' => (string) $in['party_type'],
            'party_id'   => (int) $party['id'],
            'ref_type'   => 'statement',
            'subject'    => 'Your account statement - ' . DB::setting('company_name', 'Creatives iT'),
        ]);

        Response::ok(['result' => $result, 'body' => $body, 'to' => $to],
            $result['success'] ? 'Statement sent' : 'Could not send - the text is ready to copy');
    }


    /**
     * Send several at once: a run of invoices, quotations, supplier bills,
     * statements or document reminders. Each one is attempted on its own, so
     * a customer with no WhatsApp number does not stop the rest, and the
     * reply says exactly who went and who did not.
     */
    public static function sendMany(): void
    {
        Auth::allow('messages.send');

        $in = Validator::make()
            ->check('kind', 'required|in:invoice,quotation,supplier_bill,statement,document', 'What to send')
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email')
            ->check('note', 'nullable|string|max:500')
            ->validate();

        $ids = Validator::input()['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            Response::validation(['ids' => ['Tick at least one to send']]);
        }
        if (count($ids) > 100) {
            Response::error('That is too many at once — send up to 100', 422);
        }

        $channel = (string) ($in['channel'] ?? 'whatsapp');
        $kind    = (string) $in['kind'];

        $sent = [];
        $failed = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                $result = self::sendOne($kind, $id, $channel, (string) ($in['note'] ?? ''));
                if ($result['success']) {
                    $sent[] = ['id' => $id, 'to' => $result['to'], 'name' => $result['name']];
                } else {
                    $failed[] = ['id' => $id, 'name' => $result['name'], 'why' => $result['why']];
                }
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'name' => '#' . $id, 'why' => $e->getMessage()];
            }
        }

        $message = count($sent) . ' sent';
        if ($failed) {
            $message .= ', ' . count($failed) . ' could not go';
        }

        Response::ok([
            'sent'   => $sent,
            'failed' => $failed,
        ], $message);
    }

    /**
     * One item, whatever kind it is. Returns why it did not go rather than
     * throwing, so a run of fifty keeps moving.
     */
    private static function sendOne(string $kind, int $id, string $channel, string $note): array
    {
        if ($kind === 'statement') {
            $party = DB::one('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL', [$id]);
            $type = 'customer';
            if ($party === null) {
                $party = DB::one('SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL', [$id]);
                $type = 'supplier';
            }
            if ($party === null) {
                return ['success' => false, 'name' => '#' . $id, 'why' => 'not found'];
            }
            $to = Messenger::recipientFor($party, $channel);
            if ($to === null) {
                return ['success' => false, 'name' => $party['name'], 'why' => 'no ' . $channel . ' address'];
            }
            $body = self::statementText($type, $party);
            $res = Messenger::send($channel, $to, $body, [
                'party_type' => $type, 'party_id' => $id, 'ref_type' => 'statement',
                'subject' => 'Your account statement - ' . DB::setting('company_name', ''),
            ]);
            return [
                'success' => (bool) $res['success'], 'to' => $to, 'name' => $party['name'],
                'why' => $res['success'] ? '' : (string) ($res['error'] ?? 'send failed'),
            ];
        }

        if ($kind === 'document') {
            $doc = DB::one(
                'SELECT d.*, c.name, c.company_name, c.phone, c.whatsapp, c.email,
                        c.telegram_chat_id, c.messenger_psid
                 FROM customer_documents d JOIN customers c ON c.id = d.customer_id
                 WHERE d.id = ? AND d.deleted_at IS NULL',
                [$id]
            );
            if ($doc === null) {
                return ['success' => false, 'name' => '#' . $id, 'why' => 'not found'];
            }
            $to = Messenger::recipientFor($doc, $channel);
            if ($to === null) {
                return ['success' => false, 'name' => $doc['title'], 'why' => 'no ' . $channel . ' address'];
            }
            $days = (int) ((strtotime((string) $doc['expiry_date']) - time()) / 86400);
            $body = $doc['name'] . ",\n\n" . $doc['title'] . ' expires on '
                  . date('d/m/Y', strtotime((string) $doc['expiry_date']))
                  . ($days >= 0 ? ' — ' . $days . ' day(s) from now.' : ' — it has already passed.')
                  . "\n\n" . DB::setting('company_name', '');
            $res = Messenger::send($channel, $to, $body, [
                'party_type' => 'customer', 'party_id' => (int) $doc['customer_id'],
                'ref_type' => 'document', 'ref_id' => $id,
                'subject' => $doc['title'] . ' — renewal due',
            ]);
            return [
                'success' => (bool) $res['success'], 'to' => $to, 'name' => $doc['title'],
                'why' => $res['success'] ? '' : (string) ($res['error'] ?? 'send failed'),
            ];
        }

        if ($kind === 'supplier_bill') {
            $bill = DB::one(
                'SELECT b.*, s.name, s.company_name, s.phone, s.whatsapp, s.email,
                        s.telegram_chat_id, s.messenger_psid
                 FROM supplier_bills b JOIN suppliers s ON s.id = b.supplier_id
                 WHERE b.id = ? AND b.deleted_at IS NULL',
                [$id]
            );
            if ($bill === null) {
                return ['success' => false, 'name' => '#' . $id, 'why' => 'not found'];
            }
            $to = Messenger::recipientFor($bill, $channel);
            if ($to === null) {
                return ['success' => false, 'name' => $bill['name'], 'why' => 'no ' . $channel . ' address'];
            }
            $cur = Helper::baseCurrency();
            $body = $bill['name'] . ",\n\nBill " . $bill['bill_no'] . ': '
                  . Helper::formatMoney((float) $bill['amount']) . ' ' . $cur . "\n"
                  . 'Still owing: ' . Helper::formatMoney((float) $bill['due_amount']) . ' ' . $cur
                  . "\n\n" . DB::setting('company_name', '');
            $res = Messenger::send($channel, $to, $body, [
                'party_type' => 'supplier', 'party_id' => (int) $bill['supplier_id'],
                'ref_type' => 'supplier_bill', 'ref_id' => $id,
                'subject' => 'Bill ' . $bill['bill_no'],
            ]);
            return [
                'success' => (bool) $res['success'], 'to' => $to, 'name' => $bill['name'],
                'why' => $res['success'] ? '' : (string) ($res['error'] ?? 'send failed'),
            ];
        }

        // invoice or quotation
        $isInvoice = $kind === 'invoice';
        $table = $isInvoice ? 'invoices' : 'quotations';
        $row = DB::one(
            "SELECT d.*, c.id AS cid, c.name AS customer_name, c.company_name, c.phone,
                    c.whatsapp, c.email, c.telegram_chat_id, c.messenger_psid
             FROM `{$table}` d JOIN customers c ON c.id = d.customer_id
             WHERE d.id = ? AND d.deleted_at IS NULL",
            [$id]
        );
        if ($row === null) {
            return ['success' => false, 'name' => '#' . $id, 'why' => 'not found'];
        }

        $to = Messenger::recipientFor($row, $channel);
        if ($to === null) {
            return [
                'success' => false, 'name' => (string) $row['customer_name'],
                'why' => 'no ' . $channel . ' address',
            ];
        }

        $docNo = (string) ($isInvoice ? $row['invoice_no'] : $row['quote_no']);
        $link  = PrintAuth::link($isInvoice ? 'invoice' : 'quotation', $id,
            'print/' . ($isInvoice ? 'invoice' : 'quotation') . '.php');
        $cur   = Helper::baseCurrency();

        $body = Helper::renderTemplate(
            (string) DB::setting(
                $isInvoice ? 'tpl_invoice_send' : 'tpl_quotation_send',
                $isInvoice
                    ? "{{customer_name}}, your invoice {{doc_no}} for {{total}} {{currency}} is ready.\n"
                      . "Due: {{due}} {{currency}}\nOpen it here: {{link}}\n\n- {{company}}"
                    : "{{customer_name}}, here is quotation {{doc_no}} for {{total}} {{currency}}.\n"
                      . "Open it here: {{link}}\n\n- {{company}}"
            ),
            [
                'customer_name' => (string) $row['customer_name'],
                'company_name'  => (string) ($row['company_name'] ?? ''),
                'doc_no'        => $docNo,
                'total'         => Helper::formatMoney((float) $row['total']),
                'due'           => Helper::formatMoney((float) ($row['due_amount'] ?? 0)),
                'currency'      => $cur,
                'link'          => $link,
                'company'       => (string) DB::setting('company_name', ''),
            ]
        );
        if ($note !== '') {
            $body .= "\n\n" . $note;
        }

        $res = Messenger::send($channel, $to, $body, [
            'party_type' => 'customer', 'party_id' => (int) $row['cid'],
            'ref_type' => $kind, 'ref_id' => $id,
            'subject' => ($isInvoice ? 'Invoice ' : 'Quotation ') . $docNo
                       . ' from ' . DB::setting('company_name', ''),
        ]);

        if ($isInvoice && $res['success'] && $row['status'] === 'draft') {
            DB::update('invoices', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')],
                'id = ?', [$id]);
        }

        return [
            'success' => (bool) $res['success'], 'to' => $to,
            'name' => $docNo . ' — ' . $row['customer_name'],
            'why' => $res['success'] ? '' : (string) ($res['error'] ?? 'send failed'),
        ];
    }

    /** The standing of one customer or supplier, as words. */
    private static function statementText(string $type, array $party): string
    {
        $cur = Helper::baseCurrency();
        $id = (int) $party['id'];

        if ($type === 'customer') {
            $f = DB::one(
                "SELECT COALESCE(SUM(total),0) AS billed, COALESCE(SUM(paid_amount),0) AS paid,
                        COALESCE(SUM(due_amount),0) AS due
                 FROM invoices WHERE customer_id = ? AND deleted_at IS NULL
                   AND status NOT IN ('draft','cancelled')", [$id]
            );
            $advance = (float) DB::value(
                'SELECT COALESCE(SUM(unallocated_amount),0) FROM payments
                 WHERE customer_id = ? AND deleted_at IS NULL', [$id]
            );
        } else {
            $f = DB::one(
                "SELECT COALESCE(SUM(amount),0) AS billed, COALESCE(SUM(paid_amount),0) AS paid,
                        COALESCE(SUM(due_amount),0) AS due
                 FROM supplier_bills WHERE supplier_id = ? AND deleted_at IS NULL
                   AND status <> 'cancelled'", [$id]
            );
            $advance = (float) DB::value(
                'SELECT COALESCE(SUM(unallocated_amount),0) FROM supplier_payments
                 WHERE supplier_id = ? AND deleted_at IS NULL', [$id]
            );
        }

        $lines = [
            $party['name'] . ',',
            '',
            ($type === 'customer' ? 'Your account with ' : 'Our account with ')
                . DB::setting('company_name', '') . ':',
            'Billed: ' . Helper::formatMoney((float) $f['billed']) . ' ' . $cur,
            ($type === 'customer' ? 'Paid: ' : 'Paid to you: ')
                . Helper::formatMoney((float) $f['paid']) . ' ' . $cur,
            ($type === 'customer' ? 'Still due: ' : 'Still owed: ')
                . Helper::formatMoney((float) $f['due']) . ' ' . $cur,
        ];
        if ($advance > 0) {
            $lines[] = ($type === 'customer' ? 'Advance with us: ' : 'Advance paid to you: ')
                     . Helper::formatMoney($advance) . ' ' . $cur;
        }
        $lines[] = '';
        $lines[] = DB::setting('company_name', '');

        return implode("\n", $lines);
    }

    public static function log(): void
    {
        Auth::allow('messages.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['party_type'])) {
            $where[]  = 'party_type = ?';
            $params[] = (string) $_GET['party_type'];
        }
        if (!empty($_GET['party_id'])) {
            $where[]  = 'party_id = ?';
            $params[] = (int) $_GET['party_id'];
        }
        if (!empty($_GET['channel'])) {
            $where[]  = 'channel = ?';
            $params[] = (string) $_GET['channel'];
        }
        if (!empty($_GET['status'])) {
            $where[]  = 'status = ?';
            $params[] = (string) $_GET['status'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value("SELECT COUNT(*) FROM message_log WHERE {$whereSql}", $params);
        $rows  = DB::all(
            "SELECT * FROM message_log WHERE {$whereSql} ORDER BY created_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function queue(): void
    {
        Auth::allow('messages.view');
        [$page, $perPage, $offset] = Helper::pagination();
        $status = (string) ($_GET['status'] ?? 'queued');

        $total = (int) DB::value('SELECT COUNT(*) FROM reminder_queue WHERE status = ?', [$status]);
        $rows  = DB::all(
            "SELECT * FROM reminder_queue WHERE status = ?
             ORDER BY scheduled_at ASC LIMIT {$perPage} OFFSET {$offset}",
            [$status]
        );
        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function cancelQueued(string $id): void
    {
        Auth::allow('messages.send');
        $n = DB::update('reminder_queue', ['status' => 'cancelled'],
            "id = ? AND status = 'queued'", [(int) $id]);
        if ($n === 0) {
            Response::notFound('Queued message not found');
        }
        Response::ok(null, 'Queued message cancelled');
    }

    /** Push the queue immediately instead of waiting for cron. */
    public static function flushQueue(): void
    {
        Auth::allow('messages.send');
        $res = Messenger::processQueue(50);
        Response::ok($res, 'Queue processed');
    }
}
