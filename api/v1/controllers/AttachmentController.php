<?php
/**
 * AH5 Office - files attached to anything
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Proof of a payment, a scanned passport, a sample form on a document type.
 * One place so every file behaves the same: upload, view in the browser,
 * download, remove.
 */

declare(strict_types=1);

final class AttachmentController
{
    /** Which permission decides who may touch files on each thing. */
    private const RULES = [
        'document'         => ['documents.view', 'documents.edit'],
        'document_type'    => ['documents.view', 'documents.edit'],
        'payment'          => ['payments.view', 'payments.entry'],
        'supplier_payment' => ['supplier_bills.view', 'supplier_bills.entry'],
        'supplier_bill'    => ['supplier_bills.view', 'supplier_bills.entry'],
        'expense'          => ['expenses.view', 'expenses.entry'],
        'invoice'          => ['invoices.view', 'invoices.edit'],
        'customer'         => ['customers.view', 'customers.edit'],
        'supplier'         => ['suppliers.view', 'suppliers.edit'],
        'job'              => ['jobs.view', 'jobs.edit'],
    ];

    /** Where each kind of thing lives, so we can check it exists. */
    private const TABLES = [
        'document'         => 'customer_documents',
        'document_type'    => 'document_types',
        'payment'          => 'payments',
        'supplier_payment' => 'supplier_payments',
        'supplier_bill'    => 'supplier_bills',
        'expense'          => 'expenses',
        'invoice'          => 'invoices',
        'customer'         => 'customers',
        'supplier'         => 'suppliers',
        'job'              => 'jobs',
    ];

    public static function index(string $type, string $id): void
    {
        self::guard($type, 'view');
        self::mustExist($type, (int) $id);

        $rows = DB::all(
            'SELECT a.*, u.name AS uploaded_by_name
             FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by
             WHERE a.entity_type = ? AND a.entity_id = ?
             ORDER BY a.created_at ASC, a.id ASC',
            [$type, (int) $id]
        );

        foreach ($rows as &$r) {
            $r['view_url']     = PrintAuth::fileLink((int) $r['id'], true);
            $r['download_url'] = PrintAuth::fileLink((int) $r['id'], false);
            $r['can_view']     = self::viewableInBrowser((string) ($r['mime_type'] ?? ''));
            $r['size_label']   = self::sizeLabel((int) $r['file_size']);
        }
        unset($r);

        Response::ok($rows);
    }

    public static function store(string $type, string $id): void
    {
        self::guard($type, 'edit');
        self::mustExist($type, (int) $id);

        [$path, $original] = Uploads::receive('file', $type . (int) $id);
        $full = Uploads::resolve($path);

        $attachmentId = DB::insert('attachments', [
            'entity_type' => $type,
            'entity_id'   => (int) $id,
            'file_path'   => $path,
            'file_name'   => $original,
            'mime_type'   => $full !== null ? Uploads::mimeFor($full) : null,
            'file_size'   => $full !== null ? (int) filesize($full) : 0,
            'label'       => isset($_POST['label']) ? mb_substr((string) $_POST['label'], 0, 160) : null,
            'uploaded_by' => Auth::userId(),
        ]);

        Helper::logActivity($type, (int) $id, 'file_upload', $original);

        $row = DB::one('SELECT * FROM attachments WHERE id = ?', [$attachmentId]);
        $row['view_url']     = PrintAuth::fileLink($attachmentId, true);
        $row['download_url'] = PrintAuth::fileLink($attachmentId, false);
        $row['can_view']     = self::viewableInBrowser((string) ($row['mime_type'] ?? ''));
        $row['size_label']   = self::sizeLabel((int) $row['file_size']);

        Response::created($row, 'File attached');
    }

    public static function destroy(string $attachmentId): void
    {
        $row = DB::one('SELECT * FROM attachments WHERE id = ?', [(int) $attachmentId]);
        if ($row === null) {
            Response::notFound('File not found');
        }
        self::guard((string) $row['entity_type'], 'edit');

        Uploads::delete((string) $row['file_path']);
        DB::run('DELETE FROM attachments WHERE id = ?', [(int) $attachmentId]);

        Helper::logActivity((string) $row['entity_type'], (int) $row['entity_id'],
            'file_removed', (string) $row['file_name']);
        Response::ok(null, 'File removed');
    }

    public static function rename(string $attachmentId): void
    {
        $row = DB::one('SELECT * FROM attachments WHERE id = ?', [(int) $attachmentId]);
        if ($row === null) {
            Response::notFound('File not found');
        }
        self::guard((string) $row['entity_type'], 'edit');

        $in = Validator::make()->check('label', 'nullable|string|max:160')->validate();
        DB::update('attachments', ['label' => $in['label']], 'id = ?', [(int) $attachmentId]);
        Response::ok(null, 'Label saved');
    }

    /** How many files each of a list of things has - used to show a paperclip. */
    public static function counts(string $type): void
    {
        self::guard($type, 'view');
        $rows = DB::all(
            'SELECT entity_id, COUNT(*) AS files FROM attachments
             WHERE entity_type = ? GROUP BY entity_id',
            [$type]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['entity_id']] = (int) $r['files'];
        }
        Response::ok($out);
    }

    // ---------------- internals ----------------

    /** PDFs and images open in a tab; everything else is downloaded. */
    public static function viewableInBrowser(string $mime): bool
    {
        return $mime === 'application/pdf' || str_starts_with($mime, 'image/');
    }

    /**
     * A customer's or supplier's picture. Not an attachment - it lives on
     * their own row, because there is only ever one and it is shown in
     * lists where fetching a file list would be wasteful.
     */
    public static function uploadPhoto(string $party, string $id): void
    {
        $table = $party === 'supplier' ? 'suppliers' : 'customers';
        Auth::allow($party === 'supplier' ? 'suppliers.edit' : 'customers.edit');

        $row = DB::one("SELECT id, photo FROM `{$table}` WHERE id = ? AND deleted_at IS NULL",
            [(int) $id]);
        if ($row === null) {
            Response::notFound(ucfirst($party) . ' not found');
        }

        [$stored, $original] = Uploads::receive('file', 'photo');
        $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            Uploads::delete($stored);
            Response::error('Use a photo: JPG, PNG or WebP', 422);
        }

        if (!empty($row['photo'])) {
            Uploads::delete((string) $row['photo']);
        }
        DB::update($table, ['photo' => $stored], 'id = ?', [(int) $id]);

        Response::ok([
            'photo_url' => 'asset.php?photo=' . rawurlencode(basename($stored)),
            'file_name' => $original,
        ], 'Picture saved');
    }

    public static function removePhoto(string $party, string $id): void
    {
        $table = $party === 'supplier' ? 'suppliers' : 'customers';
        Auth::allow($party === 'supplier' ? 'suppliers.edit' : 'customers.edit');

        $row = DB::one("SELECT photo FROM `{$table}` WHERE id = ?", [(int) $id]);
        if ($row !== null && !empty($row['photo'])) {
            Uploads::delete((string) $row['photo']);
        }
        DB::update($table, ['photo' => null], 'id = ?', [(int) $id]);
        Response::ok(null, 'Picture removed');
    }

    public static function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }

    private static function guard(string $type, string $action): void
    {
        if (!isset(self::RULES[$type])) {
            Response::notFound('Unknown attachment type');
        }
        [$viewPerm, $editPerm] = self::RULES[$type];
        Auth::allow($action === 'view' ? $viewPerm : $editPerm);
    }

    private static function mustExist(string $type, int $id): void
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) {
            Response::notFound('Unknown attachment type');
        }
        if (DB::value("SELECT id FROM `{$table}` WHERE id = ?", [$id]) === null) {
            Response::notFound('That record does not exist');
        }
    }
}
