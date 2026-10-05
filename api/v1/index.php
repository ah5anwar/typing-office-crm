<?php
/**
 * AH5 Office - API v1 front controller
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/bootstrap.php';

Response::cors();

$router = new Router();

// ---------------- Public ----------------
$router->get('/', static function (): void {
    Response::ok(['app' => APP_NAME, 'version' => APP_VERSION, 'time' => date('c')]);
});
$router->get('/ping', static function (): void {
    Response::ok([
        'pong'  => true,
        'time'  => date('c'),
        'date'  => date('Y-m-d'),
        'build' => APP_BUILD,
    ]);
});

// ---------------- The public site (no login) ----------------
$router->get('/site/brand',            [SiteController::class, 'brand']);
$router->get('/site/page',             [SiteController::class, 'page']);
$router->post('/site/enquiry',         [SiteController::class, 'enquire']);

$router->post('/auth/login',   [AuthController::class, 'login']);
$router->post('/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/auth/logout',  [AuthController::class, 'logout']);

// ---------------- Protected ----------------
$router->get('/auth/me',              [AuthController::class, 'me']);
$router->patch('/auth/me',            [AuthController::class, 'updateProfile']);
$router->post('/auth/change-password',[AuthController::class, 'changePassword']);
$router->get('/auth/devices',         [AuthController::class, 'devices']);
$router->delete('/auth/devices/{id}', [AuthController::class, 'revokeDevice']);

// Customers
$router->get('/customers',            [CustomerController::class, 'index']);
$router->post('/customers',           [CustomerController::class, 'store']);
$router->get('/customers/{id}',       [CustomerController::class, 'show']);
$router->patch('/customers/{id}',     [CustomerController::class, 'update']);
$router->delete('/customers/{id}',    [CustomerController::class, 'destroy']);
$router->get('/customers/{id}/summary', [CustomerController::class, 'summary']);
$router->get('/customers/{id}/payments', [CustomerController::class, 'payments']);
$router->get('/customers/{id}/overview', [CustomerController::class, 'overview']);

// Service categories
$router->get('/service-categories',        [ServiceController::class, 'categories']);
$router->post('/service-categories',       [ServiceController::class, 'storeCategory']);
$router->patch('/service-categories/{id}', [ServiceController::class, 'updateCategory']);
$router->delete('/service-categories/{id}',[ServiceController::class, 'destroyCategory']);

// Services
$router->get('/services',           [ServiceController::class, 'index']);
$router->post('/services',          [ServiceController::class, 'store']);
$router->get('/services/{id}',      [ServiceController::class, 'show']);
$router->patch('/services/{id}',    [ServiceController::class, 'update']);
$router->delete('/services/{id}',   [ServiceController::class, 'destroy']);
$router->post('/services/{id}/suppliers',              [ServiceController::class, 'attachSupplier']);
$router->delete('/services/{id}/suppliers/{supplier}', [ServiceController::class, 'detachSupplier']);

// Suppliers
$router->get('/suppliers',          [SupplierController::class, 'index']);
$router->post('/suppliers',         [SupplierController::class, 'store']);
$router->get('/suppliers/{id}',     [SupplierController::class, 'show']);
$router->patch('/suppliers/{id}',   [SupplierController::class, 'update']);
$router->delete('/suppliers/{id}',  [SupplierController::class, 'destroy']);
$router->get('/suppliers/{id}/summary', [SupplierController::class, 'summary']);
// "কে কি কাজ করে" - one click view
$router->post('/give-work',            [SupplierController::class, 'giveWork']);
$router->get('/who-does-what',      [SupplierController::class, 'whoDoesWhat']);

// Jobs
$router->get('/jobs',                 [JobController::class, 'index']);
$router->post('/jobs',                [JobController::class, 'store']);
$router->get('/jobs/{id}',            [JobController::class, 'show']);
$router->patch('/jobs/{id}',          [JobController::class, 'update']);
$router->delete('/jobs/{id}',         [JobController::class, 'destroy']);
$router->patch('/jobs/{id}/items/{item}',   [JobController::class, 'setItemStatus']);
$router->post('/jobs/{id}/suppliers',       [JobController::class, 'assignSupplier']);
$router->patch('/jobs/{id}/suppliers/{as}', [JobController::class, 'setAssignStatus']);
$router->delete('/jobs/{id}/suppliers/{as}',[JobController::class, 'removeAssign']);

// Quotations
$router->get('/quotations',           [QuotationController::class, 'index']);
$router->post('/quotations',          [QuotationController::class, 'store']);
$router->get('/quotations/{id}',      [QuotationController::class, 'show']);
$router->patch('/quotations/{id}',    [QuotationController::class, 'update']);
$router->delete('/quotations/{id}',   [QuotationController::class, 'destroy']);
$router->post('/quotations/{id}/convert', [QuotationController::class, 'convert']);
$router->get('/quotations/{id}/print-link', static function (string $id): void {
    Auth::require();
    if (DB::value('SELECT id FROM quotations WHERE id = ? AND deleted_at IS NULL', [(int) $id]) === null) {
        Response::notFound('Quotation not found');
    }
    Response::ok(['url' => PrintAuth::link('quotation', (int) $id), 'expires_in' => 1800]);
});

// Invoices
$router->get('/invoices',             [InvoiceController::class, 'index']);
$router->post('/invoices',            [InvoiceController::class, 'store']);
$router->get('/invoices/{id}',        [InvoiceController::class, 'show']);
$router->patch('/invoices/{id}',      [InvoiceController::class, 'update']);
$router->delete('/invoices/{id}',     [InvoiceController::class, 'destroy']);
$router->post('/invoices/{id}/cancel',    [InvoiceController::class, 'cancel']);
$router->post('/invoices/{id}/mark-sent', [InvoiceController::class, 'markSent']);
$router->get('/invoices/{id}/print-link',  static function (string $id): void {
    Auth::require();
    if (DB::value('SELECT id FROM invoices WHERE id = ? AND deleted_at IS NULL', [(int) $id]) === null) {
        Response::notFound('Invoice not found');
    }
    Response::ok(['url' => PrintAuth::link('invoice', (int) $id), 'expires_in' => 1800]);
});

// Customer payments
$router->get('/payments',             [PaymentController::class, 'index']);
$router->post('/payments',            [PaymentController::class, 'store']);
$router->get('/customers/{id}/open-invoices', [PaymentController::class, 'openInvoices']);
$router->get('/payments/due-list',    [PaymentController::class, 'dueList']);
$router->get('/payments/received',    [PaymentController::class, 'receivedList']);
$router->get('/payments/{id}',        [PaymentController::class, 'show']);
$router->patch('/payments/{id}',      [PaymentController::class, 'update']);
$router->delete('/payments/{id}',     [PaymentController::class, 'destroy']);
$router->post('/payments/{id}/apply-advance', [PaymentController::class, 'applyAdvance']);

// Supplier bills & payments
$router->get('/supplier-bills',       [SupplierBillController::class, 'index']);
$router->post('/supplier-bills',      [SupplierBillController::class, 'store']);
$router->get('/supplier-bills/payable', [SupplierBillController::class, 'payableList']);
$router->get('/supplier-bills/{id}',  [SupplierBillController::class, 'show']);
$router->delete('/supplier-bills/{id}',[SupplierBillController::class, 'destroyBill']);
$router->get('/supplier-payments',    [SupplierBillController::class, 'payments']);
$router->post('/supplier-payments',   [SupplierBillController::class, 'pay']);
$router->delete('/supplier-payments/{id}', [SupplierBillController::class, 'destroyPayment']);

// Documents
$router->get('/document-types',        [DocumentController::class, 'types']);
$router->post('/document-types',       [DocumentController::class, 'storeType']);
$router->patch('/document-types/{id}', [DocumentController::class, 'updateType']);
$router->delete('/document-types/{id}',[DocumentController::class, 'destroyType']);
$router->get('/documents',            [DocumentController::class, 'index']);
$router->post('/documents',           [DocumentController::class, 'store']);
$router->get('/documents/expiring',   [DocumentController::class, 'expiring']);
$router->get('/documents/{id}',       [DocumentController::class, 'show']);
$router->patch('/documents/{id}',     [DocumentController::class, 'update']);
$router->delete('/documents/{id}',    [DocumentController::class, 'destroy']);
$router->post('/documents/{id}/renew',  [DocumentController::class, 'renew']);
$router->post('/documents/{id}/remind', [DocumentController::class, 'remind']);

// Expenses / other income
$router->get('/expense-categories',   [ExpenseController::class, 'categories']);
$router->post('/expense-categories',  [ExpenseController::class, 'storeCategory']);
$router->patch('/expense-categories/{id}', [ExpenseController::class, 'updateCategory']);
$router->delete('/expense-categories/{id}',[ExpenseController::class, 'destroyCategory']);
$router->get('/expenses',             [ExpenseController::class, 'index']);
$router->post('/expenses',            [ExpenseController::class, 'store']);
$router->patch('/expenses/{id}',      [ExpenseController::class, 'update']);
$router->delete('/expenses/{id}',     [ExpenseController::class, 'destroy']);

// Reports
$router->get('/reports/dashboard',      [ReportController::class, 'dashboard']);
$router->get('/reports/income-expense', [ReportController::class, 'incomeExpense']);
$router->get('/reports/profit',         [ReportController::class, 'profit']);
$router->get('/reports/balances',       [ReportController::class, 'balances']);
$router->get('/reports/cash-bank',      [ReportController::class, 'cashAndBank']);
$router->get('/reports/expenses',      [ReportController::class, 'expenses']);
$router->get('/reports/full',           [ReportController::class, 'full']);
$router->get('/reports/print-link',     [ReportController::class, 'printLink']);

// Messaging
$router->get('/message-templates',      [MessageController::class, 'templates']);
$router->patch('/message-templates/{id}',[MessageController::class, 'updateTemplate']);
$router->post('/messages/service-list', [MessageController::class, 'serviceList']);
$router->post('/messages/custom',       [MessageController::class, 'custom']);
$router->post('/messages/due-reminder', [MessageController::class, 'dueReminder']);
$router->post('/messages/send-document',[MessageController::class, 'sendDocument']);
$router->post('/messages/send-many',   [MessageController::class, 'sendMany']);
$router->post('/messages/statement',    [MessageController::class, 'sendStatement']);
$router->get('/messages/log',           [MessageController::class, 'log']);
$router->get('/messages/queue',         [MessageController::class, 'queue']);
$router->delete('/messages/queue/{id}', [MessageController::class, 'cancelQueued']);
$router->post('/messages/queue/flush',  [MessageController::class, 'flushQueue']);

// Staff accounts and permissions
$router->get('/users',                [UserController::class, 'index']);
$router->get('/users/permissions',    [UserController::class, 'catalogue']);
$router->post('/users',               [UserController::class, 'store']);
$router->get('/users/{id}',           [UserController::class, 'show']);
$router->patch('/users/{id}',         [UserController::class, 'update']);
$router->delete('/users/{id}',        [UserController::class, 'destroy']);
$router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
$router->post('/users/{id}/sign-out-all',   [UserController::class, 'signOutEverywhere']);

// Work list
$router->get('/work/customers',       [WorkController::class, 'customerWork']);
$router->get('/work/suppliers',       [WorkController::class, 'supplierWork']);
$router->get('/work/by-customer',     [WorkController::class, 'byCustomer']);
$router->get('/work/by-supplier',     [WorkController::class, 'bySupplier']);

// Files attached to anything
$router->get('/attachments/{type}/{id}',    [AttachmentController::class, 'index']);
$router->post('/attachments/{type}/{id}',   [AttachmentController::class, 'store']);
$router->post('/photo/{party}/{id}',   [AttachmentController::class, 'uploadPhoto']);
$router->delete('/photo/{party}/{id}', [AttachmentController::class, 'removePhoto']);
$router->get('/attachment-counts/{type}',   [AttachmentController::class, 'counts']);
$router->patch('/attachments/{id}',         [AttachmentController::class, 'rename']);
$router->delete('/attachments/{id}',        [AttachmentController::class, 'destroy']);

// Accounts - the cash book
$router->get('/accounts',                 [AccountController::class, 'index']);
$router->post('/accounts',                [AccountController::class, 'store']);
$router->get('/accounts/{id}',            [AccountController::class, 'show']);
$router->patch('/accounts/{id}',          [AccountController::class, 'update']);
$router->delete('/accounts/{id}',         [AccountController::class, 'destroy']);
$router->get('/accounts/{id}/statement',  [AccountController::class, 'statement']);
$router->post('/accounts/{id}/adjust',    [AccountController::class, 'adjust']);
$router->post('/account-transfer',        [AccountController::class, 'transfer']);

// Settings
$router->get('/settings',             [SettingController::class, 'index']);
$router->patch('/settings',           [SettingController::class, 'update']);
$router->post('/settings/test-channel',[SettingController::class, 'testChannel']);
$router->post('/settings/image',       [SettingController::class, 'uploadImage']);
$router->delete('/settings/image/{kind}', [SettingController::class, 'removeImage']);
$router->get('/payment-methods',       [SettingController::class, 'methods']);
$router->post('/payment-methods',      [SettingController::class, 'storeMethod']);
$router->patch('/payment-methods/{id}',[SettingController::class, 'updateMethod']);
$router->delete('/payment-methods/{id}',[SettingController::class, 'destroyMethod']);
// The site, as the owner writes it
$router->get('/enquiries',             [EnquiryController::class, 'index']);
$router->get('/enquiries/{id}',        [EnquiryController::class, 'show']);
$router->patch('/enquiries/{id}',      [EnquiryController::class, 'update']);
$router->delete('/enquiries/{id}',     [EnquiryController::class, 'destroy']);
$router->post('/enquiries/{id}/convert', [EnquiryController::class, 'convert']);

$router->get('/site',                  [SiteController::class, 'index']);
$router->post('/site/sections',        [SiteController::class, 'storeSection']);
$router->patch('/site/sections/{id}',  [SiteController::class, 'updateSection']);
$router->delete('/site/sections/{id}', [SiteController::class, 'destroySection']);
$router->post('/site/reorder',         [SiteController::class, 'reorder']);
$router->post('/site/sections/{id}/items', [SiteController::class, 'storeItem']);
$router->patch('/site/items/{id}',     [SiteController::class, 'updateItem']);
$router->delete('/site/items/{id}',    [SiteController::class, 'destroyItem']);
$router->post('/site/image/{target}/{id}',   [SiteController::class, 'uploadImage']);
$router->delete('/site/image/{target}/{id}', [SiteController::class, 'removeImage']);

$router->post('/settings/upgrade-schema', [SettingController::class, 'upgradeSchema']);
$router->post('/settings/clear-opcache', [BackupController::class, 'clearOpcache']);

$router->get('/backups', [BackupController::class, 'list']);
$router->post('/backups', [BackupController::class, 'create']);
$router->get('/backups/{name}/download', [BackupController::class, 'download']);
$router->delete('/backups/{name}', [BackupController::class, 'delete']);
$router->post('/system/update', [BackupController::class, 'update']);
$router->post('/system/rollback', [BackupController::class, 'rollback']);
$router->get('/settings/cron',         [SettingController::class, 'cronSetup']);
$router->get('/settings/storage',      [SettingController::class, 'storage']);
$router->post('/settings/storage/clean',[SettingController::class, 'cleanStorage']);
$router->post('/settings/test-email',  [SettingController::class, 'testEmail']);
$router->get('/settings/invoice-design',  [SettingController::class, 'invoiceDesign']);
$router->patch('/settings/invoice-design',[SettingController::class, 'saveInvoiceDesign']);
$router->get('/settings/design-preview',  [SettingController::class, 'designPreviewLink']);
$router->get('/settings/asset/{key}',  static function (string $key): void {
    Auth::require();
    if (!in_array($key, ['company_logo', 'company_signature'], true)) {
        Response::notFound('Unknown image');
    }
    $stored = (string) DB::setting($key, '');
    Response::ok(['key' => $key, 'set' => $stored !== '',
                  'url' => $stored === '' ? null : PrintAuth::assetLink($key)]);
});
$router->get('/cron-status',          [SettingController::class, 'cronStatus']);
$router->get('/activity-log',         [SettingController::class, 'activityLog']);

$router->dispatch();
