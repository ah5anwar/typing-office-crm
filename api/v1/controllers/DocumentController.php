<?php
/**
 * AH5 Office - Customer documents & expiry reminders
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class DocumentController
{
    // ---------------- document types and their fields ----------------

    public static function types(): void
    {
        Auth::allow('documents.view');

        $rows = DB::all('SELECT * FROM document_types WHERE is_active = 1 ORDER BY name ASC');
        foreach ($rows as &$t) {
            $t['fields'] = DB::all(
                'SELECT * FROM document_type_fields
                 WHERE doc_type_id = ? AND is_archived = 0
                 ORDER BY sort_order ASC, id ASC',
                [(int) $t['id']]
            );
            $t['in_use'] = (int) DB::value(
                'SELECT COUNT(*) FROM customer_documents WHERE doc_type_id = ? AND deleted_at IS NULL',
                [(int) $t['id']]
            );
            $t['file_count'] = (int) DB::value(
                "SELECT COUNT(*) FROM attachments WHERE entity_type = 'document_type' AND entity_id = ?",
                [(int) $t['id']]
            );
        }
        unset($t);

        Response::ok($rows);
    }

    public static function storeType(): void
    {
        Auth::allow('documents.edit');

        $in = Validator::make()
            ->check('name', 'required|string|max:120', 'Type name')
            ->check('default_remind_days', 'nullable|string|max:60')
            ->validate();

        if (DB::value('SELECT id FROM document_types WHERE name = ?', [$in['name']]) !== null) {
            Response::error('A document type with that name already exists', 422);
        }

        $id = DB::insert('document_types', Helper::dropNulls([
            'name'                => $in['name'],
            'default_remind_days' => $in['default_remind_days'],
        ]));

        self::syncFields($id, Validator::input()['fields'] ?? []);
        Helper::logActivity('document_type', $id, 'create', (string) $in['name']);

        Response::created(self::findType($id), 'Document type added');
    }

    public static function updateType(string $id): void
    {
        Auth::allow('documents.edit');
        self::findType((int) $id);

        $v = Validator::make()
            ->check('name', 'nullable|string|max:120')
            ->check('default_remind_days', 'nullable|string|max:60')
            ->check('is_active', 'nullable|bool');
        $data = Helper::dropNulls($v->present($v->validate()), ['name', 'default_remind_days', 'is_active']);

        if ($data) {
            DB::update('document_types', $data, 'id = ?', [(int) $id]);
        }

        $fields = Validator::input()['fields'] ?? null;
        if (is_array($fields)) {
            self::syncFields((int) $id, $fields);
        }

        Response::ok(self::findType((int) $id), 'Document type updated');
    }

    public static function destroyType(string $id): void
    {
        Auth::allow('documents.delete');
        self::findType((int) $id);

        $used = (int) DB::value(
            'SELECT COUNT(*) FROM customer_documents WHERE doc_type_id = ? AND deleted_at IS NULL',
            [(int) $id]
        );
        if ($used > 0) {
            DB::update('document_types', ['is_active' => 0], 'id = ?', [(int) $id]);
            Response::ok(null, $used . ' document(s) still use this type, so it was switched off instead of removed');
        }

        DB::run('DELETE FROM document_types WHERE id = ?', [(int) $id]);
        Response::ok(null, 'Document type removed');
    }

    /**
     * Replace the field list of a type.
     * A field already carrying saved values is kept (its id survives), so the
     * information stored against existing documents is never orphaned.
     */
    private static function syncFields(int $typeId, array $fields): void
    {
        if (!is_array($fields)) {
            return;
        }
        $keep = [];
        $sort = 0;

        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $key = trim((string) ($f['field_key'] ?? ''));
            if ($key === '') {
                $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label) ?? 'field');
                $key = trim($key, '_') ?: 'field';
            }
            $key = mb_substr($key, 0, 60);

            $type = (string) ($f['field_type'] ?? 'text');
            if (!in_array($type, ['text', 'number', 'date', 'select', 'textarea', 'checkbox', 'file'], true)) {
                $type = 'text';
            }

            $row = [
                'label'       => mb_substr($label, 0, 120),
                'field_type'  => $type,
                'options'     => isset($f['options']) ? mb_substr((string) $f['options'], 0, 2000) : null,
                'placeholder' => isset($f['placeholder']) ? mb_substr((string) $f['placeholder'], 0, 160) : null,
                'is_required'    => !empty($f['is_required']) ? 1 : 0,
                'allow_multiple' => !empty($f['allow_multiple']) ? 1 : 0,
                'is_archived'    => 0,
                'sort_order'  => $sort++,
            ];

            $existing = isset($f['id']) ? (int) $f['id'] : 0;
            if ($existing > 0 && DB::value('SELECT id FROM document_type_fields WHERE id = ? AND doc_type_id = ?',
                    [$existing, $typeId]) !== null) {
                DB::update('document_type_fields', $row, 'id = ?', [$existing]);
                $keep[] = $existing;
                continue;
            }

            // a key can only appear once inside a type
            $byKey = DB::value('SELECT id FROM document_type_fields WHERE doc_type_id = ? AND field_key = ?',
                [$typeId, $key]);
            if ($byKey !== null) {
                DB::update('document_type_fields', $row, 'id = ?', [(int) $byKey]);
                $keep[] = (int) $byKey;
                continue;
            }

            $row['doc_type_id'] = $typeId;
            $row['field_key']   = $key;
            $keep[] = DB::insert('document_type_fields', $row);
        }

        $existing = DB::all('SELECT id FROM document_type_fields WHERE doc_type_id = ?', [$typeId]);
        foreach ($existing as $row) {
            if (in_array((int) $row['id'], $keep, true)) {
                continue;
            }
            $hasValues = (int) DB::value('SELECT COUNT(*) FROM document_field_values WHERE field_id = ?',
                [(int) $row['id']]);
            if ($hasValues === 0) {
                DB::run('DELETE FROM document_type_fields WHERE id = ?', [(int) $row['id']]);
                continue;
            }
            // answers already given are never thrown away; the field is only
            // retired, so it disappears from new documents but old ones still show it
            DB::update('document_type_fields', ['is_archived' => 1], 'id = ?', [(int) $row['id']]);
        }
    }

    private static function findType(int $id): array
    {
        $row = DB::one('SELECT * FROM document_types WHERE id = ?', [$id]);
        if ($row === null) {
            Response::notFound('Document type not found');
        }
        $row['fields'] = DB::all(
            'SELECT * FROM document_type_fields
             WHERE doc_type_id = ? AND is_archived = 0
             ORDER BY sort_order ASC, id ASC',
            [$id]
        );
        return $row;
    }

    public static function index(): void
    {
        Auth::allow('documents.view');
        [$page, $perPage, $offset] = Helper::pagination();

        $where  = ['d.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['customer_id'])) {
            $where[]  = 'd.customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        if (!empty($_GET['status'])) {
            $where[]  = 'd.status = ?';
            $params[] = (string) $_GET['status'];
        }
        if (!empty($_GET['expiring_in'])) {
            $where[]  = 'd.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND d.status = "active"';
            $params[] = (int) $_GET['expiring_in'];
        }
        if (isset($_GET['expired']) && $_GET['expired'] === '1') {
            $where[] = 'd.expiry_date < CURDATE()';
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string) $_GET['q']) . '%';
            $where[] = '(d.title LIKE ? OR d.doc_number LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            "SELECT COUNT(*) FROM customer_documents d JOIN customers c ON c.id = d.customer_id WHERE {$whereSql}",
            $params
        );
        $rows = DB::all(
            "SELECT d.*, dt.name AS doc_type_name, c.name AS customer_name, c.company_name,
                    c.whatsapp AS customer_whatsapp, c.telegram_chat_id,
                    DATEDIFF(d.expiry_date, CURDATE()) AS days_left,
                    (SELECT COUNT(*) FROM attachments a
                      WHERE a.entity_type = 'document' AND a.entity_id = d.id) AS file_count
             FROM customer_documents d
             JOIN customers c ON c.id = d.customer_id
             LEFT JOIN document_types dt ON dt.id = d.doc_type_id
             WHERE {$whereSql}
             ORDER BY d.expiry_date ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated($rows, $total, $page, $perPage);
    }

    public static function show(string $id): void
    {
        Auth::allow('documents.view');
        $doc = self::find((int) $id);
        $doc['fields'] = self::fieldsWithValues((int) $id, $doc['doc_type_id'] ?? null);
        $doc['files']  = DB::all(
            'SELECT id, file_name, mime_type, file_size, label, created_at
             FROM attachments WHERE entity_type = "document" AND entity_id = ?
             ORDER BY created_at ASC',
            [(int) $id]
        );
        Response::ok($doc);
    }

    public static function store(): void
    {
        Auth::allow('documents.edit');
        $in = self::validateInput()->validate();

        $remindDays = $in['remind_days'] ?? null;
        if ($remindDays === null && !empty($in['doc_type_id'])) {
            $remindDays = DB::value('SELECT default_remind_days FROM document_types WHERE id = ?',
                [(int) $in['doc_type_id']]);
        }

        $id = DB::insert('customer_documents', Helper::dropNulls([
            'customer_id'     => (int) $in['customer_id'],
            'doc_type_id'     => $in['doc_type_id'],
            'service_id'      => $in['service_id'],
            'title'           => $in['title'],
            'doc_number'      => $in['doc_number'],
            'holder_name'     => $in['holder_name'],
            'issue_date'      => $in['issue_date'],
            'expiry_date'     => $in['expiry_date'],
            'remind_days'     => $remindDays,
            'remind_self'     => $in['remind_self'],
            'remind_customer' => $in['remind_customer'],
            'renewal_amount'  => $in['renewal_amount'],
            'renewal_cost'    => $in['renewal_cost'],
            'currency'        => $in['currency'],
            'note'            => $in['note'],
        ], ['remind_days','remind_self','remind_customer','renewal_amount','renewal_cost','currency']));

        $filesNeeded = self::saveFieldValues(
            $id, (int) ($in['doc_type_id'] ?? 0), Validator::input()['fields'] ?? [], true
        );

        Helper::logActivity('document', $id, 'create', (string) $in['title']);
        $doc = self::find($id);
        $doc['fields'] = self::fieldsWithValues($id, $doc['doc_type_id'] ?? null);
        $doc['files_needed'] = $filesNeeded;

        Response::created($doc, $filesNeeded
            ? 'Document added - now attach: ' . implode(', ', array_column($filesNeeded, 'label'))
            : 'Document added');
    }

    public static function update(string $id): void
    {
        Auth::allow('documents.edit');
        self::find((int) $id);

        $v     = self::validateInput(false);
        $clean = $v->validate();
        $data  = Helper::dropNulls($v->present([
            'doc_type_id'     => $clean['doc_type_id'] ?? null,
            'service_id'      => $clean['service_id'] ?? null,
            'title'           => $clean['title'] ?? null,
            'doc_number'      => $clean['doc_number'] ?? null,
            'holder_name'     => $clean['holder_name'] ?? null,
            'issue_date'      => $clean['issue_date'] ?? null,
            'expiry_date'     => $clean['expiry_date'] ?? null,
            'remind_days'     => $clean['remind_days'] ?? null,
            'remind_self'     => $clean['remind_self'] ?? null,
            'remind_customer' => $clean['remind_customer'] ?? null,
            'renewal_amount'  => $clean['renewal_amount'] ?? null,
            'renewal_cost'    => $clean['renewal_cost'] ?? null,
            'currency'        => $clean['currency'] ?? null,
            'status'          => $clean['status'] ?? null,
            'note'            => $clean['note'] ?? null,
        ]), ['title','expiry_date','remind_days','remind_self','remind_customer',
             'renewal_amount','renewal_cost','currency','status']);

        if ($data) {
            DB::update('customer_documents', $data, 'id = ?', [(int) $id]);
        }

        $fresh = self::find((int) $id);
        $fields = Validator::input()['fields'] ?? null;
        if (is_array($fields)) {
            self::saveFieldValues((int) $id, (int) ($fresh['doc_type_id'] ?? 0), $fields);
        }
        $fresh['fields'] = self::fieldsWithValues((int) $id, $fresh['doc_type_id'] ?? null);

        Response::ok($fresh, 'Document updated');
    }

    public static function destroy(string $id): void
    {
        Auth::allow('documents.delete');
        self::find((int) $id);
        DB::update('customer_documents', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Response::ok(null, 'Document deleted');
    }

    /** Renew: push the expiry forward and keep the old date in the note. */
    public static function renew(string $id): void
    {
        Auth::allow('documents.edit');
        $doc = self::find((int) $id);

        $in = Validator::make()
            ->check('new_expiry_date', 'required|date', 'New expiry date')
            ->check('months', 'nullable|int|min:1|max:120')
            ->validate();

        DB::update('customer_documents', [
            'issue_date'     => date('Y-m-d'),
            'expiry_date'    => $in['new_expiry_date'],
            'status'         => 'active',
            'last_remind_at' => null,
        ], 'id = ?', [(int) $id]);

        Helper::logActivity('document', (int) $id, 'renew',
            'old expiry ' . $doc['expiry_date'] . ' -> ' . $in['new_expiry_date']);

        Response::ok(self::find((int) $id), 'Document renewed');
    }

    /** Everything expiring soon, grouped for the dashboard. */
    public static function expiring(): void
    {
        Auth::allow('documents.view');
        $days = isset($_GET['days']) ? max(1, min(365, (int) $_GET['days'])) : 30;

        $rows = DB::all(
            "SELECT d.id, d.title, d.doc_number, d.expiry_date, d.renewal_amount, d.currency,
                    DATEDIFF(d.expiry_date, CURDATE()) AS days_left,
                    dt.name AS doc_type_name,
                    c.id AS customer_id, c.name AS customer_name, c.company_name,
                    c.whatsapp AS customer_whatsapp, c.telegram_chat_id
             FROM customer_documents d
             JOIN customers c ON c.id = d.customer_id
             LEFT JOIN document_types dt ON dt.id = d.doc_type_id
             WHERE d.deleted_at IS NULL AND d.status = 'active'
               AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY d.expiry_date ASC",
            [$days]
        );

        $expired = $soon = [];
        foreach ($rows as $r) {
            if ((int) $r['days_left'] < 0) {
                $expired[] = $r;
            } else {
                $soon[] = $r;
            }
        }

        Response::ok([
            'days'    => $days,
            'expired' => $expired,
            'soon'    => $soon,
            'count'   => ['expired' => count($expired), 'soon' => count($soon)],
        ]);
    }

    /** Manual reminder for one document (ম্যানুয়ালি রিমাইন্ডার). */
    public static function remind(string $id): void
    {
        Auth::allow('documents.remind');
        $doc = self::find((int) $id);

        $in = Validator::make()
            ->check('channel', 'nullable|in:whatsapp,telegram,messenger,email', 'Channel')
            ->check('to', 'nullable|in:customer,self,both')
            ->check('body', 'nullable|string|max:2000')
            ->validate();

        $channel = (string) ($in['channel'] ?? 'whatsapp');
        $target  = (string) ($in['to'] ?? 'customer');
        $tpl     = Messenger::template('doc_expiry', $channel);

        $body = $in['body'] ?? Helper::renderTemplate(
            $tpl['body'] ?? '{{doc_title}} expires on {{expiry_date}} ({{days_left}} days left).',
            [
                'customer_name' => $doc['customer_name'],
                'doc_title'     => $doc['title'],
                'expiry_date'   => date('d/m/Y', strtotime((string) $doc['expiry_date'])),
                'days_left'     => (string) $doc['days_left'],
            ]
        );

        $results = [];

        if ($target === 'customer' || $target === 'both') {
            $to = Messenger::recipientFor($doc, $channel);
            $results['customer'] = $to === null
                ? ['success' => false, 'error' => 'Customer has no ' . $channel . ' address']
                : Messenger::send($channel, $to, $body, [
                    'party_type'        => 'customer',
                    'party_id'          => (int) $doc['customer_id'],
                    'template_name'     => $tpl['wa_template_name'] ?? null,
                    'template_language' => $tpl['wa_language'] ?? 'en',
                    'template_params'   => [$doc['customer_name'], $doc['title'],
                                            date('d/m/Y', strtotime((string) $doc['expiry_date']))],
                ]);
        }

        if ($target === 'self' || $target === 'both') {
            $selfTo = $channel === 'telegram'
                ? (string) DB::setting('self_telegram_chat_id', '')
                : (string) DB::setting('self_whatsapp', '');
            $selfBody = $doc['customer_name'] . ' - ' . $doc['title'] . ' expires '
                . date('d/m/Y', strtotime((string) $doc['expiry_date']))
                . ' (' . $doc['days_left'] . ' days left)';
            $results['self'] = $selfTo === ''
                ? ['success' => false, 'error' => 'Your own ' . $channel . ' number is not set in settings']
                : Messenger::send($channel, $selfTo, $selfBody, ['party_type' => 'self']);
        }

        DB::update('customer_documents', ['last_remind_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $id]);
        Response::ok(['body' => $body, 'results' => $results], 'Reminder sent');
    }


    // ---------------- internals ----------------



    /** The type's fields with whatever this document has answered. */
    private static function fieldsWithValues(int $documentId, $typeId): array
    {
        if (empty($typeId)) {
            return [];
        }
        $rows = DB::all(
            'SELECT f.id, f.field_key, f.label, f.field_type, f.options, f.placeholder,
                    f.is_required, f.allow_multiple, f.is_archived, f.sort_order, v.value
             FROM document_type_fields f
             LEFT JOIN document_field_values v ON v.field_id = f.id AND v.document_id = ?
             WHERE f.doc_type_id = ?
               AND (f.is_archived = 0 OR v.value IS NOT NULL)
             ORDER BY f.is_archived ASC, f.sort_order ASC, f.id ASC',
            [$documentId, (int) $typeId]
        );

        // a file field carries its answer as an attachment labelled with the field
        foreach ($rows as &$f) {
            if ($f['field_type'] !== 'file') {
                continue;
            }
            $found = DB::all(
                "SELECT id, file_name, mime_type, file_size FROM attachments
                 WHERE entity_type = 'document' AND entity_id = ? AND label = ?
                 ORDER BY id ASC",
                [$documentId, 'field:' . $f['field_key']]
            );
            $f['files'] = array_map(static fn ($file) => [
                'id'           => (int) $file['id'],
                'file_name'    => $file['file_name'],
                'size_label'   => AttachmentController::sizeLabel((int) $file['file_size']),
                'can_view'     => AttachmentController::viewableInBrowser((string) ($file['mime_type'] ?? '')),
                'view_url'     => PrintAuth::fileLink((int) $file['id'], true),
                'download_url' => PrintAuth::fileLink((int) $file['id'], false),
            ], $found);
            // the single-file shape older screens expect
            $f['file'] = $f['files'][0] ?? null;
        }
        unset($f);

        return $rows;
    }

    /**
     * Save the answers. Accepts either { field_key: value } or
     * [{ field_id, value }] so the panel and the API can both stay simple.
     */
    private static function saveFieldValues(int $documentId, int $typeId, $answers, bool $isNew = false): array
    {
        if ($typeId <= 0 || !is_array($answers)) {
            return [];
        }
        $fields = DB::all(
            'SELECT id, field_key, label, is_required, is_archived, field_type
             FROM document_type_fields WHERE doc_type_id = ?',
            [$typeId]
        );
        if (!$fields) {
            return [];
        }

        $byKey = [];
        $byId  = [];
        foreach ($fields as $f) {
            $byKey[$f['field_key']] = $f;
            $byId[(int) $f['id']]   = $f;
        }

        $values = [];
        foreach ($answers as $key => $entry) {
            if (is_array($entry) && isset($entry['field_id'])) {
                $f = $byId[(int) $entry['field_id']] ?? null;
                $v = $entry['value'] ?? null;
            } else {
                $f = $byKey[(string) $key] ?? null;
                $v = $entry;
            }
            if ($f === null || is_array($v)) {
                continue;
            }
            $values[(int) $f['id']] = $v === null ? null : (string) $v;
        }

        $missing = [];
        $filesNeeded = [];
        foreach ($fields as $f) {
            if ((int) $f['is_required'] !== 1 || (int) $f['is_archived'] === 1) {
                continue;
            }
            if (($f['field_type'] ?? '') === 'file') {
                // A file cannot be uploaded before the document exists, so on
                // a new record this is reported back rather than refused.
                $has = (int) DB::value(
                    "SELECT COUNT(*) FROM attachments
                     WHERE entity_type = 'document' AND entity_id = ? AND label = ?",
                    [$documentId, 'field:' . $f['field_key']]
                );
                if ($has === 0) {
                    if ($isNew) {
                        $filesNeeded[] = ['field_key' => $f['field_key'], 'label' => $f['label']];
                    } else {
                        $missing[$f['field_key']] = [$f['label'] . ' needs a file'];
                    }
                }
                continue;
            }
            $given = $values[(int) $f['id']] ?? null;
            if ($given === null || trim($given) === '') {
                $missing[$f['field_key']] = [$f['label'] . ' is required'];
            }
        }
        if ($missing) {
            Response::validation($missing);
        }

        foreach ($values as $fieldId => $value) {
            if ($value === null || $value === '') {
                DB::run('DELETE FROM document_field_values WHERE document_id = ? AND field_id = ?',
                    [$documentId, $fieldId]);
                continue;
            }
            DB::run(
                'INSERT INTO document_field_values (document_id, field_id, value) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$documentId, $fieldId, $value]
            );
        }

        return $filesNeeded;
    }

    private static function find(int $id): array
    {
        $row = DB::one(
            'SELECT d.*, dt.name AS doc_type_name, c.name AS customer_name, c.company_name,
                    c.whatsapp, c.phone, c.telegram_chat_id, c.messenger_psid,
                    DATEDIFF(d.expiry_date, CURDATE()) AS days_left
             FROM customer_documents d
             JOIN customers c ON c.id = d.customer_id
             LEFT JOIN document_types dt ON dt.id = d.doc_type_id
             WHERE d.id = ? AND d.deleted_at IS NULL',
            [$id]
        );
        if ($row === null) {
            Response::notFound('Document not found');
        }
        return $row;
    }

    private static function validateInput(bool $isCreate = true): Validator
    {
        $req = $isCreate ? 'required|' : 'nullable|';
        return Validator::make()
            ->check('customer_id', $req . 'int|exists:customers', 'Customer')
            ->check('title', $req . 'string|max:200', 'Title')
            ->check('expiry_date', $req . 'date', 'Expiry date')
            ->check('doc_type_id', 'nullable|int|exists:document_types')
            ->check('service_id', 'nullable|int|exists:services')
            ->check('doc_number', 'nullable|string|max:120')
            ->check('holder_name', 'nullable|string|max:160')
            ->check('issue_date', 'nullable|date')
            ->check('remind_days', 'nullable|string|max:60')
            ->check('remind_self', 'nullable|bool')
            ->check('remind_customer', 'nullable|bool')
            ->check('renewal_amount', 'nullable|number|min:0')
            ->check('renewal_cost', 'nullable|number|min:0')
            ->check('currency', 'nullable|currency')
            ->check('status', 'nullable|in:active,renewed,expired,cancelled')
            ->check('note', 'nullable|string|max:5000');
    }
}