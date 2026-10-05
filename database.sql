-- =============================================================
--  AH5 Office Management System  ( Creatives iT )
--  Designed & Developed by Anwar Hossain  -- https://anwar.com.bd
--  Single master schema file. Update THIS file for any change.
--  MySQL 5.7+ / MariaDB 10.3+  |  utf8mb4
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================
-- 1. SYSTEM / AUTH
-- =============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(160)  NOT NULL,
  `phone`         VARCHAR(30)   DEFAULT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `role`          ENUM('admin','staff') NOT NULL DEFAULT 'admin',
  `avatar`        VARCHAR(255)  DEFAULT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `last_login_at` DATETIME      DEFAULT NULL,
  `token_version` INT UNSIGNED NOT NULL DEFAULT 0,  -- bumped to kill live access tokens
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `token_hash`   CHAR(64)      NOT NULL,           -- sha256 of refresh token
  `device_name`  VARCHAR(120)  DEFAULT NULL,       -- "Anwar Pixel 8", "Chrome Desktop"
  `platform`     ENUM('web','android','ios','other') NOT NULL DEFAULT 'web',
  `ip`           VARCHAR(45)   DEFAULT NULL,
  `expires_at`   DATETIME      NOT NULL,
  `revoked_at`   DATETIME      DEFAULT NULL,
  `last_used_at` DATETIME      DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `ix_tokens_user` (`user_id`,`revoked_at`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`   INT UNSIGNED NOT NULL,
  `perm_key`  VARCHAR(60)  NOT NULL,   -- see core/permissions.php for the catalogue
  `granted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_perm` (`user_id`,`perm_key`),
  KEY `ix_perm_user` (`user_id`),
  CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name`   VARCHAR(80)  NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequential document numbers: AH5-ddmmyy + 2 digit, resets daily
CREATE TABLE IF NOT EXISTS `doc_counters` (
  `doc_type`  ENUM('invoice','quotation','payment','supplier_bill','supplier_payment') NOT NULL,
  `doc_date`  DATE         NOT NULL,
  `last_seq`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`doc_type`,`doc_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  DEFAULT NULL,
  `entity`     VARCHAR(50)   NOT NULL,   -- customer / invoice / payment ...
  `entity_id`  BIGINT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(40)   NOT NULL,   -- create / update / delete / send
  `note`       VARCHAR(255)  DEFAULT NULL,
  `meta_json`  TEXT          DEFAULT NULL,
  `ip`         VARCHAR(45)   DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_log_entity` (`entity`,`entity_id`),
  KEY `ix_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 2. CUSTOMERS
-- =============================================================

CREATE TABLE IF NOT EXISTS `customers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(20)   DEFAULT NULL,     -- CUS-0001
  `name`             VARCHAR(160)  NOT NULL,         -- contact person
  `company_name`     VARCHAR(200)  DEFAULT NULL,
  `type`             ENUM('individual','company') NOT NULL DEFAULT 'individual',
  `phone`            VARCHAR(30)   DEFAULT NULL,
  `phone_alt`        VARCHAR(30)   DEFAULT NULL,
  `whatsapp`         VARCHAR(30)   DEFAULT NULL,     -- E.164, e.g. 8801XXXXXXXXX
  `telegram_chat_id` VARCHAR(40)   DEFAULT NULL,     -- filled after /start
  `messenger_psid`   VARCHAR(60)   DEFAULT NULL,
  `email`            VARCHAR(160)  DEFAULT NULL,
  `website`          VARCHAR(200)  DEFAULT NULL,
  `address`          VARCHAR(255)  DEFAULT NULL,
  `city`             VARCHAR(80)   DEFAULT NULL,
  `country`          VARCHAR(80)   DEFAULT NULL,
  `trade_license`    VARCHAR(80)   DEFAULT NULL,
  `tax_number`       VARCHAR(80)   DEFAULT NULL,     -- TRN / BIN / VAT
  `default_currency` CHAR(3)       NOT NULL DEFAULT 'BDT',
  `opening_balance`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- + means customer owes
  `credit_limit`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `photo`         VARCHAR(255) DEFAULT NULL,   -- a face to recognise them by
  `notes`            TEXT          DEFAULT NULL,
  `tags`             VARCHAR(255)  DEFAULT NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`       INT UNSIGNED  DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_code` (`code`),
  KEY `ix_cus_name` (`name`),
  KEY `ix_cus_company` (`company_name`),
  KEY `ix_cus_phone` (`phone`),
  KEY `ix_cus_status` (`status`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 3. SERVICES
-- =============================================================

CREATE TABLE IF NOT EXISTS `service_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`   INT UNSIGNED  DEFAULT NULL,
  `name`          VARCHAR(180)  NOT NULL,
  `description`   TEXT          DEFAULT NULL,
  `unit`          VARCHAR(40)   NOT NULL DEFAULT 'pcs',   -- pcs / month / year / hour
  `cost_primary`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,   -- what it costs me
  `sell_primary`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,   -- what the customer pays
  `is_recurring`  TINYINT(1)    NOT NULL DEFAULT 0,
  `recurring_months` SMALLINT UNSIGNED DEFAULT NULL,      -- 12 = yearly renewal
  `has_expiry`    TINYINT(1)    NOT NULL DEFAULT 0,       -- domain/hosting/licence
  `show_in_list`  TINYINT(1)    NOT NULL DEFAULT 1,       -- for one-click service message
  `sort_order`    INT           NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_srv_cat` (`category_id`),
  KEY `ix_srv_active` (`is_active`),
  CONSTRAINT `fk_srv_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 4. SUPPLIERS
-- =============================================================

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(20)   DEFAULT NULL,     -- SUP-0001
  `name`             VARCHAR(160)  NOT NULL,
  `company_name`     VARCHAR(200)  DEFAULT NULL,
  `phone`            VARCHAR(30)   DEFAULT NULL,
  `whatsapp`         VARCHAR(30)   DEFAULT NULL,
  `telegram_chat_id` VARCHAR(40)   DEFAULT NULL,
  `email`            VARCHAR(160)  DEFAULT NULL,
  `address`          VARCHAR(255)  DEFAULT NULL,
  `country`          VARCHAR(80)   DEFAULT NULL,
  `default_currency` CHAR(3)       NOT NULL DEFAULT 'BDT',
  `opening_balance`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- + means I owe supplier
  `payment_terms`    VARCHAR(120)  DEFAULT NULL,
  `photo`        VARCHAR(255) DEFAULT NULL,   -- a face to recognise them by
  `notes`            TEXT          DEFAULT NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_code` (`code`),
  KEY `ix_sup_name` (`name`),
  KEY `ix_sup_status` (`status`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- a service may have 0, 1, 2 or 3+ suppliers
CREATE TABLE IF NOT EXISTS `service_suppliers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`   INT UNSIGNED  NOT NULL,
  `supplier_id`  INT UNSIGNED  NOT NULL,
  `supplier_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency`     CHAR(3)       NOT NULL DEFAULT 'BDT',
  `is_preferred` TINYINT(1)    NOT NULL DEFAULT 0,
  `lead_days`    SMALLINT UNSIGNED DEFAULT NULL,
  `note`         VARCHAR(255)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_srv_sup` (`service_id`,`supplier_id`),
  KEY `ix_ss_sup` (`supplier_id`),
  CONSTRAINT `fk_ss_srv` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 5. JOBS  (কাস্টমার কাজ দিলে এন্ট্রি)
-- =============================================================

CREATE TABLE IF NOT EXISTS `jobs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_no`        VARCHAR(30)   NOT NULL,          -- JOB-0001
  `customer_id`   INT UNSIGNED  NOT NULL,
  `title`         VARCHAR(200)  NOT NULL,
  `description`   TEXT          DEFAULT NULL,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `received_date` DATE          NOT NULL,
  `due_date`      DATE          DEFAULT NULL,
  `delivered_date` DATE         DEFAULT NULL,
  `priority`      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`        ENUM('pending','in_progress','on_hold','completed','delivered','cancelled')
                  NOT NULL DEFAULT 'pending',
  `est_total`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `est_cost`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `is_invoiced`   TINYINT(1)    NOT NULL DEFAULT 0,
  `remind_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`         TEXT          DEFAULT NULL,
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_no` (`job_no`),
  KEY `ix_job_cus` (`customer_id`,`status`),
  KEY `ix_job_status` (`status`,`due_date`),
  CONSTRAINT `fk_job_cus` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`         BIGINT UNSIGNED NOT NULL,
  `service_id`     INT UNSIGNED  DEFAULT NULL,     -- NULL = custom line
  `description`    VARCHAR(255)  NOT NULL,
  `qty`            DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit_price`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- customer price (editable)
  `cost_price`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- my cost snapshot
  `line_total`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status`         ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`   DATETIME      DEFAULT NULL,
  `is_invoiced`    TINYINT(1)    NOT NULL DEFAULT 0,
  `invoice_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `sort_order`     INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_ji_job` (`job_id`),
  KEY `ix_ji_srv` (`service_id`),
  KEY `ix_ji_status` (`status`),
  CONSTRAINT `fk_ji_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ji_srv` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- which supplier is doing which job line (manual complete)
CREATE TABLE IF NOT EXISTS `job_supplier_assign` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`        BIGINT UNSIGNED NOT NULL,
  `job_item_id`   BIGINT UNSIGNED DEFAULT NULL,
  `supplier_id`   INT UNSIGNED  NOT NULL,
  `work_detail`   VARCHAR(255)  DEFAULT NULL,
  `agreed_cost`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `assigned_date` DATE          NOT NULL,
  `due_date`      DATE          DEFAULT NULL,
  `status`        ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`  DATETIME      DEFAULT NULL,      -- set manually by admin
  `is_billed`     TINYINT(1)    NOT NULL DEFAULT 0,
  `supplier_bill_id` BIGINT UNSIGNED DEFAULT NULL,
  `note`          TEXT          DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_jsa_job` (`job_id`),
  KEY `ix_jsa_sup` (`supplier_id`,`status`),
  KEY `ix_jsa_item` (`job_item_id`),
  CONSTRAINT `fk_jsa_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jsa_item` FOREIGN KEY (`job_item_id`) REFERENCES `job_items`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jsa_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 6. QUOTATIONS
-- =============================================================

CREATE TABLE IF NOT EXISTS `quotations` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_no`      VARCHAR(30)   NOT NULL,          -- AH5Q-ddmmyy##
  `customer_id`   INT UNSIGNED  NOT NULL,
  `quote_date`    DATE          NOT NULL,
  `valid_until`   DATE          DEFAULT NULL,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`       DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `subtotal`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_type` ENUM('none','percent','flat') NOT NULL DEFAULT 'none',
  `discount_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vat_percent`   DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  `vat_amount`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
  `converted_invoice_id` BIGINT UNSIGNED DEFAULT NULL,
  `subject`       VARCHAR(200)  DEFAULT NULL,
  `terms`         TEXT          DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `pdf_path`      VARCHAR(255)  DEFAULT NULL,
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quote_no` (`quote_no`),
  KEY `ix_q_cus` (`customer_id`,`status`),
  CONSTRAINT `fk_q_cus` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quotation_items` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quotation_id` BIGINT UNSIGNED NOT NULL,
  `service_id`   INT UNSIGNED  DEFAULT NULL,
  `description`  VARCHAR(255)  NOT NULL,
  `qty`          DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit`         VARCHAR(40)   DEFAULT NULL,
  `unit_price`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cost_price`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `sort_order`   INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_qi_q` (`quotation_id`),
  CONSTRAINT `fk_qi_q` FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qi_srv` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 7. INVOICES
-- =============================================================

CREATE TABLE IF NOT EXISTS `invoices` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no`    VARCHAR(30)   NOT NULL,          -- AH5-ddmmyy##
  `customer_id`   INT UNSIGNED  NOT NULL,
  `job_id`        BIGINT UNSIGNED DEFAULT NULL,
  `quotation_id`  BIGINT UNSIGNED DEFAULT NULL,
  `invoice_date`  DATE          NOT NULL,
  `due_date`      DATE          DEFAULT NULL,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`       DECIMAL(18,6) NOT NULL DEFAULT 1.000000,  -- to base currency, locked at issue
  `subtotal`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_type` ENUM('none','percent','flat') NOT NULL DEFAULT 'none',
  `discount_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vat_percent`   DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  `vat_amount`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `due_amount`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_cost`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,      -- sum of cost_price => profit
  `status`        ENUM('draft','sent','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `subject`       VARCHAR(200)  DEFAULT NULL,
  `terms`         TEXT          DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `pdf_path`      VARCHAR(255)  DEFAULT NULL,
  `sent_at`       DATETIME      DEFAULT NULL,
  `remind_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  `last_remind_at` DATETIME     DEFAULT NULL,
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_no` (`invoice_no`),
  KEY `ix_inv_cus` (`customer_id`,`status`),
  KEY `ix_inv_date` (`invoice_date`),
  KEY `ix_inv_due` (`due_date`,`status`),
  CONSTRAINT `fk_inv_cus` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_quote` FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  BIGINT UNSIGNED NOT NULL,
  `service_id`  INT UNSIGNED  DEFAULT NULL,
  `job_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255)  NOT NULL,
  `qty`         DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit`        VARCHAR(40)   DEFAULT NULL,
  `unit_price`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,   -- fully editable per invoice
  `cost_price`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `period_start` DATE         DEFAULT NULL,            -- for renewals
  `period_end`   DATE         DEFAULT NULL,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_ii_inv` (`invoice_id`),
  KEY `ix_ii_srv` (`service_id`),
  CONSTRAINT `fk_ii_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ii_srv` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ii_jobitem` FOREIGN KEY (`job_item_id`) REFERENCES `job_items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 8. CUSTOMER PAYMENTS  (advance + against invoice)
-- =============================================================

CREATE TABLE IF NOT EXISTS `payments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no`    VARCHAR(30)   NOT NULL,
  `customer_id`   INT UNSIGNED  NOT NULL,
  `payment_date`  DATE          NOT NULL,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`       DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `amount`        DECIMAL(14,2) NOT NULL,
  `allocated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unallocated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,  -- remaining advance
  `is_advance`    TINYINT(1)    NOT NULL DEFAULT 0,
  `method`        VARCHAR(60) NOT NULL DEFAULT 'Cash',   -- from payment_methods, your own list
  `account_id`   INT UNSIGNED DEFAULT NULL,   -- which account it went into or out of
  `reference`     VARCHAR(120)  DEFAULT NULL,          -- trxid / cheque no
  `account_name`  VARCHAR(120)  DEFAULT NULL,
  `note`          VARCHAR(255)  DEFAULT NULL,
  `notify_sent`   TINYINT(1)    NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_no` (`receipt_no`),
  KEY `ix_pay_cus` (`customer_id`,`payment_date`),
  CONSTRAINT `fk_pay_cus` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `amount`     DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pa_pay` (`payment_id`),
  KEY `ix_pa_inv` (`invoice_id`),
  CONSTRAINT `fk_pa_pay` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 9. SUPPLIER BILLS & PAYMENTS
-- =============================================================

CREATE TABLE IF NOT EXISTS `supplier_bills` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_no`      VARCHAR(30)   NOT NULL,
  `supplier_id`  INT UNSIGNED  NOT NULL,
  `job_id`       BIGINT UNSIGNED DEFAULT NULL,
  `bill_date`    DATE          NOT NULL,
  `due_date`     DATE          DEFAULT NULL,
  `currency`     CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`      DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `amount`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `due_amount`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status`       ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `description`  VARCHAR(255)  DEFAULT NULL,
  `note`         TEXT          DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bill_no` (`bill_no`),
  KEY `ix_sb_sup` (`supplier_id`,`status`),
  CONSTRAINT `fk_sb_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_no`   VARCHAR(30)   NOT NULL,
  `supplier_id`  INT UNSIGNED  NOT NULL,
  `payment_date` DATE          NOT NULL,
  `currency`     CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`      DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `amount`       DECIMAL(14,2) NOT NULL,
  `allocated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unallocated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `method`       VARCHAR(60) NOT NULL DEFAULT 'Cash',   -- from payment_methods, your own list
  `account_id`   INT UNSIGNED DEFAULT NULL,   -- which account it went into or out of
  `reference`    VARCHAR(120)  DEFAULT NULL,
  `note`         VARCHAR(255)  DEFAULT NULL,
  `notify_sent`  TINYINT(1)    NOT NULL DEFAULT 0,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_voucher` (`voucher_no`),
  KEY `ix_sp_sup` (`supplier_id`,`payment_date`),
  CONSTRAINT `fk_sp_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_payment_allocations` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_payment_id` BIGINT UNSIGNED NOT NULL,
  `supplier_bill_id`   BIGINT UNSIGNED NOT NULL,
  `amount`             DECIMAL(14,2) NOT NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_spa_pay` (`supplier_payment_id`),
  KEY `ix_spa_bill` (`supplier_bill_id`),
  CONSTRAINT `fk_spa_pay` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spa_bill` FOREIGN KEY (`supplier_bill_id`) REFERENCES `supplier_bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 10. CUSTOMER DOCUMENTS (expiry reminders)
-- =============================================================

CREATE TABLE IF NOT EXISTS `document_types` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,   -- Domain, Hosting, Trade Licence, Visa, Passport
  `default_remind_days` VARCHAR(60) NOT NULL DEFAULT '30,15,7,1', -- comma separated
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctype_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_documents` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`   INT UNSIGNED  NOT NULL,
  `doc_type_id`   INT UNSIGNED  DEFAULT NULL,
  `service_id`    INT UNSIGNED  DEFAULT NULL,   -- link to hosting/domain service
  `title`         VARCHAR(200)  NOT NULL,       -- "example.com domain"
  `doc_number`    VARCHAR(120)  DEFAULT NULL,
  `holder_name`   VARCHAR(160)  DEFAULT NULL,
  `issue_date`    DATE          DEFAULT NULL,
  `expiry_date`   DATE          NOT NULL,
  `remind_days`   VARCHAR(60)   NOT NULL DEFAULT '30,15,7,1',
  `remind_self`   TINYINT(1)    NOT NULL DEFAULT 1,
  `remind_customer` TINYINT(1)  NOT NULL DEFAULT 1,
  `renewal_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `renewal_cost`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency`      CHAR(3)       NOT NULL DEFAULT 'BDT',
  `status`        ENUM('active','renewed','expired','cancelled') NOT NULL DEFAULT 'active',
  `last_remind_at` DATETIME     DEFAULT NULL,
  `note`          TEXT          DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_doc_cus` (`customer_id`,`status`),
  KEY `ix_doc_expiry` (`expiry_date`,`status`),
  CONSTRAINT `fk_doc_cus` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_type` FOREIGN KEY (`doc_type_id`) REFERENCES `document_types`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_srv` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Each document type decides what information it holds.
CREATE TABLE IF NOT EXISTS `document_type_fields` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type_id` INT UNSIGNED NOT NULL,
  `field_key`   VARCHAR(60)  NOT NULL,    -- machine name, unique inside the type
  `label`       VARCHAR(120) NOT NULL,    -- what the form shows
  `field_type`  ENUM('text','number','date','select','textarea','checkbox','file') NOT NULL DEFAULT 'text',
  `options`     TEXT         DEFAULT NULL, -- one choice per line, for select
  `placeholder` VARCHAR(160) DEFAULT NULL,
  `is_required` TINYINT(1)   NOT NULL DEFAULT 0,
  `allow_multiple` TINYINT(1) NOT NULL DEFAULT 0,  -- a file field that takes several
  `is_archived` TINYINT(1)   NOT NULL DEFAULT 0,  -- removed from the form, old answers kept
  `sort_order`  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_field` (`doc_type_id`,`field_key`),
  CONSTRAINT `fk_dtf_type` FOREIGN KEY (`doc_type_id`) REFERENCES `document_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_field_values` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `field_id`    INT UNSIGNED    NOT NULL,
  `value`       TEXT            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_field` (`document_id`,`field_id`),
  KEY `ix_dfv_field` (`field_id`),
  CONSTRAINT `fk_dfv_doc`   FOREIGN KEY (`document_id`) REFERENCES `customer_documents`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dfv_field` FOREIGN KEY (`field_id`)    REFERENCES `document_type_fields`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Any file attached to anything: proof of a payment, a scanned licence,
-- a sample form on a document type. One table so nothing is a special case.
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('document','document_type','payment','supplier_payment',
                     'expense','supplier_bill','invoice','customer','supplier','job') NOT NULL,
  `entity_id`   BIGINT UNSIGNED NOT NULL,
  `file_path`   VARCHAR(255) NOT NULL,     -- relative to storage/
  `file_name`   VARCHAR(200) NOT NULL,     -- what the file was called
  `mime_type`   VARCHAR(100) DEFAULT NULL,
  `file_size`   INT UNSIGNED NOT NULL DEFAULT 0,
  `label`       VARCHAR(160) DEFAULT NULL, -- "front side", "bank slip"
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_att_entity` (`entity_type`,`entity_id`),
  KEY `ix_att_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- ACCOUNTS - where the money actually sits
-- =============================================================
-- Cash box, bank account, bKash wallet: you name them yourself.
-- Every payment, expense and supplier payment lands in one of these,
-- so at any moment the system can say how much is where.

CREATE TABLE IF NOT EXISTS `accounts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,          -- "Office cash", "City Bank 1234"
  `type`            ENUM('cash','bank','mobile','card','other') NOT NULL DEFAULT 'cash',
  `account_number`  VARCHAR(60)  DEFAULT NULL,
  `bank_name`       VARCHAR(120) DEFAULT NULL,
  `branch`          VARCHAR(120) DEFAULT NULL,
  `currency`        CHAR(3)      NOT NULL DEFAULT 'BDT',
  `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_date`    DATE         DEFAULT NULL,
  `is_default`      TINYINT(1)   NOT NULL DEFAULT 0, -- pre-selected on money forms
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`      INT          NOT NULL DEFAULT 0,
  `note`            VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_name` (`name`),
  KEY `ix_account_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every movement of money, whatever caused it. This is the cash book.
CREATE TABLE IF NOT EXISTS `account_entries` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`     INT UNSIGNED NOT NULL,
  `entry_date`     DATE         NOT NULL,
  `direction`      ENUM('in','out') NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `currency`       CHAR(3)      NOT NULL DEFAULT 'BDT',
  `source`         ENUM('payment','supplier_payment','expense','income','transfer','opening','adjustment')
                   NOT NULL DEFAULT 'adjustment',
  `ref_id`         BIGINT UNSIGNED DEFAULT NULL,     -- the payment/expense row that caused it
  `transfer_group` CHAR(20)     DEFAULT NULL,        -- ties the two halves of a transfer
  `description`    VARCHAR(255) DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ae_account` (`account_id`,`entry_date`),
  KEY `ix_ae_source` (`source`,`ref_id`),
  KEY `ix_ae_transfer` (`transfer_group`),
  CONSTRAINT `fk_ae_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `accounts` (`name`,`type`,`is_default`,`sort_order`) VALUES
 ('Office cash','cash',1,0),
 ('Bank account','bank',0,1);


-- How money changes hands: cash, bKash, a cheque. Your list, your words.
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL,      -- what you call it
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_method_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `payment_methods` (`name`,`sort_order`) VALUES
 ('Cash',0), ('Bank transfer',1), ('bKash',2), ('Nagad',3),
 ('Rocket',4), ('Cheque',5), ('Card',6), ('Online',7);


-- =============================================================
-- THE PUBLIC SITE
-- =============================================================
-- What a visitor sees at domain.com. Everything here is written and
-- ordered from Settings -> Website; nothing about customers, money or
-- files is ever read by the public page.

CREATE TABLE IF NOT EXISTS `site_sections` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`        ENUM('banner','ticker','stats','services','forms','notices',
                     'problems','audience','process','payment','about','contact')
                NOT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `eyebrow_en`  VARCHAR(120) DEFAULT NULL,   -- the small line above the heading
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The rows inside a section: one service, one problem you solve, one step.
-- Point it at a service and the name comes from your own list; leave that
-- empty and you write it by hand instead.
CREATE TABLE IF NOT EXISTS `site_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id`  INT UNSIGNED NOT NULL,
  `service_id`  INT UNSIGNED DEFAULT NULL,   -- optional: pull the name from Services
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `title_en`    VARCHAR(200) DEFAULT NULL,
  `title_bn`    VARCHAR(200) DEFAULT NULL,
  `body_en`     TEXT         DEFAULT NULL,
  `body_bn`     TEXT         DEFAULT NULL,
  `image`       VARCHAR(255) DEFAULT NULL,
  `icon`        VARCHAR(40)  DEFAULT NULL,
  `cta_label_en` VARCHAR(80) DEFAULT NULL,   -- a slide's or ticker line's own button
  `cta_label_bn` VARCHAR(80) DEFAULT NULL,
  `cta_link`     VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_item_section` (`section_id`,`sort_order`),
  CONSTRAINT `fk_item_section` FOREIGN KEY (`section_id`)
    REFERENCES `site_sections`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_service` FOREIGN KEY (`service_id`)
    REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Someone wrote to you from the site.
CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(160) NOT NULL,
  `phone`       VARCHAR(40)  DEFAULT NULL,
  `email`       VARCHAR(190) DEFAULT NULL,
  `subject`     VARCHAR(200) DEFAULT NULL,
  `message`     TEXT         DEFAULT NULL,
  `status`      ENUM('new','read','replied','closed','spam') NOT NULL DEFAULT 'new',
  `customer_id` INT UNSIGNED DEFAULT NULL,   -- set once you turn them into a customer
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `notified_at` DATETIME     DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_enquiry_status` (`status`,`created_at`),
  CONSTRAINT `fk_enquiry_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A starting page, so the site is never blank on the day you install it.
INSERT IGNORE INTO `site_sections`
 (`id`,`kind`,`sort_order`,`eyebrow_en`,`heading_en`,`body_en`,`cta_label_en`,`cta_link`) VALUES
 (1,'banner',0,'Creatives iT','Websites, apps and design that earn their keep',
   'We build and look after the digital side of small businesses in Bangladesh and the UAE — from the domain name to the finished site.',
   'Talk to us','#contact'),
 (2,'ticker',1,NULL,NULL,NULL,NULL,NULL),
 (3,'services',2,'What we do','Services',
   'Pick what you need, or ask and we will tell you honestly whether you need it.',NULL,NULL),
 (4,'stats',3,NULL,'A few numbers',NULL,NULL,NULL),
 (5,'notices',4,'Latest','News and notices',NULL,NULL,NULL),
 (6,'problems',5,'Why people call us','Problems we solve',
   NULL,NULL,NULL),
 (7,'forms',6,'Get started','Request a service',
   'Pick what you need done and send us the details.',NULL,NULL),
 (8,'audience',7,'Who we work with','Our clients',NULL,NULL,NULL),
 (9,'process',8,'How it goes','From first call to finished work',NULL,NULL,NULL),
 (10,'payment',9,'Payment','How you can pay us',
   'Whichever is easiest for you.',NULL,NULL),
 (11,'about',10,'Need something built','Have a project in mind',
   'Tell us what you are trying to do and we will tell you honestly what it takes.',
   'Get in touch','#contact'),
 (12,'contact',11,'Get in touch','Tell us what you need',
   'Send a message and we will reply the same day.',NULL,NULL);

INSERT IGNORE INTO `site_items` (`section_id`,`sort_order`,`title_en`,`body_en`) VALUES
 (2,0,'We reply to every message the same working day.',NULL),
 (2,1,'Now booking new website projects for next month.',NULL),
 (4,0,'40+','Projects delivered'),
 (4,1,'8+','Years building for clients'),
 (4,2,'2','Countries served'),
 (4,3,'100%','Invoiced line by line'),
 (5,0,'A note on how we price','Every quotation is fixed and written down before any work starts — nothing added afterwards.'),
 (5,1,'Renewal reminders','Domains, licences and visas on file are watched and you are reminded before they lapse.'),
 (6,0,'Nobody answers after the site is built','We stay on. Renewals, fixes and changes are part of the deal, not an extra.'),
 (6,1,'Papers and renewals get forgotten','We keep track of every domain, licence and visa date and remind you before it lapses.'),
 (6,2,'You do not know what you are paying for','Every invoice lists the work line by line, in plain words.'),
 (7,0,'New website','Tell us roughly what you need and we will quote it.'),
 (7,1,'Fix or change something','Already have a site? We can take over or patch what is there.'),
 (7,2,'Ongoing support','A monthly arrangement so someone is always looking after it.'),
 (8,0,'Small businesses','One or two people, no IT department, work to get on with.'),
 (8,1,'Shops and traders','A simple site, a catalogue, and a number people can reach.'),
 (8,2,'Agencies and studios','White-label build work when your own hands are full.'),
 (9,0,'We talk','Tell us the problem, not the solution. The call is free.'),
 (9,1,'You get a quotation','Fixed price, written down, nothing added later.'),
 (9,2,'We build','You see it as it grows, not only at the end.'),
 (9,3,'We hand over and stay','Training, files, passwords — and we are still here afterwards.');

-- =============================================================
-- 11. OFFICE EXPENSES / INCOME
-- =============================================================

CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(120) NOT NULL,
  `kind`      ENUM('expense','income') NOT NULL DEFAULT 'expense',
  `is_active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expcat` (`name`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_no`   VARCHAR(30)   DEFAULT NULL,
  `category_id`  INT UNSIGNED  DEFAULT NULL,
  `kind`         ENUM('expense','income') NOT NULL DEFAULT 'expense', -- other income too
  `entry_date`   DATE          NOT NULL,
  `title`        VARCHAR(200)  NOT NULL,
  `currency`     CHAR(3)       NOT NULL DEFAULT 'BDT',
  `fx_rate`      DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `amount`       DECIMAL(14,2) NOT NULL,
  `method`       VARCHAR(60) NOT NULL DEFAULT 'Cash',   -- from payment_methods, your own list
  `account_id`   INT UNSIGNED DEFAULT NULL,   -- which account it went into or out of
  `paid_to`      VARCHAR(160)  DEFAULT NULL,
  `supplier_id`  INT UNSIGNED  DEFAULT NULL,
  `reference`    VARCHAR(120)  DEFAULT NULL,
  `attachment`   VARCHAR(255)  DEFAULT NULL,
  `note`         TEXT          DEFAULT NULL,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_exp_date` (`entry_date`,`kind`),
  KEY `ix_exp_cat` (`category_id`),
  CONSTRAINT `fk_exp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exp_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 12. MESSAGING  (WhatsApp / Telegram / Messenger / SMS)
-- =============================================================

CREATE TABLE IF NOT EXISTS `message_templates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(60)  NOT NULL,   -- doc_expiry / due_reminder / payment_received ...
  `name`        VARCHAR(140) NOT NULL,
  `channel`     ENUM('whatsapp','telegram','messenger','sms','email','any') NOT NULL DEFAULT 'any',
  `wa_template_name` VARCHAR(120) DEFAULT NULL,  -- approved Cloud API template name
  `wa_language` VARCHAR(10)  NOT NULL DEFAULT 'en',
  `body`        TEXT         NOT NULL,   -- {{customer_name}} {{amount}} {{expiry_date}}
  `variables`   VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tpl` (`code`,`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminder_queue` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_type`     ENUM('document','invoice','job','custom','supplier_bill','enquiry') NOT NULL,
  `ref_id`       BIGINT UNSIGNED DEFAULT NULL,
  `party_type`   ENUM('customer','self') NOT NULL DEFAULT 'customer',
  `party_id`     INT UNSIGNED  DEFAULT NULL,
  `channel`      ENUM('whatsapp','telegram','messenger','sms','email') NOT NULL DEFAULT 'whatsapp',
  `recipient`    VARCHAR(120)  NOT NULL,   -- phone / chat_id / psid
  `template_code` VARCHAR(60)  DEFAULT NULL,
  `body`         TEXT          NOT NULL,   -- rendered text
  `payload_json` TEXT          DEFAULT NULL,
  `scheduled_at` DATETIME      NOT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status`       ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  `error`        VARCHAR(255)  DEFAULT NULL,
  `sent_at`      DATETIME      DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_rq_due` (`status`,`scheduled_at`),
  KEY `ix_rq_ref` (`ref_type`,`ref_id`),
  KEY `ix_rq_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_id`    BIGINT UNSIGNED DEFAULT NULL,
  `party_type`  ENUM('customer','self') NOT NULL DEFAULT 'customer',
  `party_id`    INT UNSIGNED  DEFAULT NULL,
  `channel`     ENUM('whatsapp','telegram','messenger','sms','email','manual') NOT NULL,
  `direction`   ENUM('out','in') NOT NULL DEFAULT 'out',
  `recipient`   VARCHAR(120)  DEFAULT NULL,
  `body`        TEXT          DEFAULT NULL,
  `provider_msg_id` VARCHAR(160) DEFAULT NULL,
  `status`      ENUM('sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
  `error`       VARCHAR(255)  DEFAULT NULL,
  `sent_by`     INT UNSIGNED  DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ml_party` (`party_type`,`party_id`,`created_at`),
  KEY `ix_ml_provider` (`provider_msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 13. CRON (master dispatcher pattern)
-- =============================================================

CREATE TABLE IF NOT EXISTS `cron_tasks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key`      VARCHAR(60)  NOT NULL,
  `title`         VARCHAR(140) NOT NULL,
  `handler_file`  VARCHAR(160) NOT NULL,   -- cron/tasks/xxx.php
  `interval_min`  INT UNSIGNED NOT NULL DEFAULT 60,
  `run_at_time`   TIME         DEFAULT NULL,  -- for once-a-day tasks
  `timezone`      VARCHAR(40)  NOT NULL DEFAULT 'Asia/Dhaka',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_run_at`   DATETIME     DEFAULT NULL,
  `next_run_at`   DATETIME     DEFAULT NULL,
  `last_status`   ENUM('ok','running') DEFAULT NULL,
  `last_message`  VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_key` (`task_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key`   VARCHAR(60)  NOT NULL,
  `started_at` DATETIME     NOT NULL,
  `finished_at` DATETIME    DEFAULT NULL,
  `status`     ENUM('ok','error') DEFAULT NULL,
  `message`    VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cl_task` (`task_key`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 14. SEED DATA
-- =============================================================

INSERT IGNORE INTO `settings` (`key_name`,`value`) VALUES
 ('company_name','Creatives iT'),
 ('company_owner','Anwar Hossain'),
 ('company_email','info@creativesit.com'),
 ('company_phone',''),
 ('company_address',''),
 ('company_logo',''),
 ('base_currency','BDT'),
 ('invoice_prefix','AH5-'),
 ('quotation_prefix','AH5Q-'),
 ('receipt_prefix','AH5R-'),
 ('supplier_bill_prefix','AH5SB-'),
 ('supplier_voucher_prefix','AH5SV-'),
 ('default_timezone','Asia/Dhaka'),
 ('invoice_due_days','7'),
 ('due_reminder_days','3,7,15,30'),
 ('wa_phone_number_id',''),
 ('wa_business_account_id',''),
 ('wa_access_token',''),
 ('wa_api_version','v21.0'),
 ('telegram_bot_token',''),
 ('messenger_page_token',''),
 ('self_whatsapp',''),
 ('self_telegram_chat_id',''),
 ('credit_footer','Designed & Developed by Anwar Hossain'),
 ('company_website',''),
 ('company_trade_license',''),
 ('company_tax_number',''),
 ('company_bank_details',''),
 ('company_signature',''),
 ('invoice_template','classic'),
 ('invoice_accent','#1d4ed8'),
 ('invoice_paper','A4'),
 ('invoice_show_logo','1'),
 ('invoice_show_signature','0'),
 ('invoice_show_unit','1'),
 ('invoice_show_bank','1'),
 ('invoice_footer_note','Thank you for your business.'),
 ('invoice_use_custom','0'),
 ('invoice_show_credit','1'),
 ('site_social_facebook',''),
 ('site_social_instagram',''),
 ('site_social_youtube',''),
 ('site_app_playstore',''),
 ('site_app_appstore',''),
 ('invoice_custom_html',''),
 ('app_name','AH5 Office'),
 ('app_logo',''),
 ('app_favicon',''),
 ('site_enabled','1'),
 ('site_theme','clean'),
 ('site_accent','#0F766E'),
 ('site_default_lang','en'),
 ('site_tagline_en',''),
 ('site_tagline_bn',''),
 ('site_meta_description',''),
 ('site_notify_whatsapp','1'),
 ('email_enabled','0'),
 ('email_mode','gmail'),
 ('gmail_address',''),
 ('gmail_app_password',''),
 ('smtp_host',''),
 ('smtp_port','587'),
 ('smtp_secure','tls'),
 ('smtp_user',''),
 ('smtp_pass',''),
 ('smtp_from_email',''),
 ('smtp_from_name',''),
 ('self_email','');

INSERT IGNORE INTO `document_types` (`name`,`default_remind_days`) VALUES
 ('Domain','30,15,7,1'),
 ('Hosting','30,15,7,1'),
 ('SSL Certificate','30,15,7'),
 ('Trade Licence','60,30,15,7'),
 ('Visa','60,30,15,7'),
 ('Passport','90,60,30'),
 ('National ID','90,30'),
 ('Emirates ID','60,30,15,7'),
 ('Driving Licence','60,30,7'),
 ('VAT Registration','60,30,7'),
 ('Tenancy / Ijari','60,30,15,7'),
 ('Software Licence','30,15,7');

-- The questions each of those types asks. Add or change them from the panel.
INSERT IGNORE INTO `document_type_fields` (`doc_type_id`,`field_key`,`label`,`field_type`,`is_required`,`sort_order`)
SELECT t.id, f.k, f.l, f.ft, f.req, f.so FROM `document_types` t JOIN (
  SELECT 'Passport' tname, 'passport_number' k, 'Passport number' l, 'text' ft, 1 req, 0 so UNION ALL
  SELECT 'Passport', 'full_name', 'Name as printed', 'text', 0, 1 UNION ALL
  SELECT 'Passport', 'nationality', 'Nationality', 'text', 0, 2 UNION ALL
  SELECT 'Passport', 'issue_place', 'Place of issue', 'text', 0, 3 UNION ALL
  SELECT 'Visa', 'visa_number', 'Visa number', 'text', 1, 0 UNION ALL
  SELECT 'Visa', 'visa_type', 'Visa type', 'text', 0, 1 UNION ALL
  SELECT 'Visa', 'sponsor', 'Sponsor', 'text', 0, 2 UNION ALL
  SELECT 'Trade Licence', 'licence_number', 'Licence number', 'text', 1, 0 UNION ALL
  SELECT 'Trade Licence', 'issuing_authority', 'Issuing authority', 'text', 0, 1 UNION ALL
  SELECT 'Trade Licence', 'business_activity', 'Business activity', 'text', 0, 2 UNION ALL
  SELECT 'National ID', 'nid_number', 'NID number', 'text', 1, 0 UNION ALL
  SELECT 'Emirates ID', 'eid_number', 'Emirates ID number', 'text', 1, 0 UNION ALL
  SELECT 'Driving Licence', 'licence_number', 'Licence number', 'text', 1, 0 UNION ALL
  SELECT 'Driving Licence', 'vehicle_class', 'Vehicle class', 'text', 0, 1 UNION ALL
  SELECT 'VAT Registration', 'vat_number', 'VAT / BIN number', 'text', 1, 0 UNION ALL
  SELECT 'Tenancy / Ijari', 'contract_number', 'Contract number', 'text', 1, 0 UNION ALL
  SELECT 'Tenancy / Ijari', 'property_address', 'Property address', 'textarea', 0, 1 UNION ALL
  SELECT 'Tenancy / Ijari', 'annual_rent', 'Annual rent', 'number', 0, 2 UNION ALL
  SELECT 'Domain', 'registrar', 'Registrar', 'text', 0, 0 UNION ALL
  SELECT 'Hosting', 'control_panel', 'Control panel URL', 'text', 0, 0
) f ON f.tname = t.name;

INSERT IGNORE INTO `expense_categories` (`name`,`kind`) VALUES
 ('Office Rent','expense'),('Utility Bill','expense'),('Internet','expense'),
 ('Salary','expense'),('Transport','expense'),('Food','expense'),
 ('Marketing','expense'),('Software Subscription','expense'),
 ('Equipment','expense'),('Bank Charge','expense'),('Others','expense'),
 ('Other Income','income');

INSERT IGNORE INTO `cron_tasks` (`task_key`,`title`,`handler_file`,`interval_min`,`run_at_time`) VALUES
 ('doc_expiry','Document expiry reminder','cron/tasks/doc_expiry.php',1440,'09:00:00'),
 ('due_reminder','Invoice due reminder','cron/tasks/due_reminder.php',1440,'10:00:00'),
 ('job_pending','Pending job reminder','cron/tasks/job_pending.php',1440,'09:30:00'),
 ('queue_send','Send queued messages','cron/tasks/queue_send.php',5,NULL);

INSERT IGNORE INTO `message_templates` (`code`,`name`,`channel`,`body`,`variables`) VALUES
 ('doc_expiry','Document expiry notice','any',
  'প্রিয় {{customer_name}}, আপনার {{doc_title}} এর মেয়াদ {{expiry_date}} তারিখে শেষ হবে ({{days_left}} দিন বাকি)। নবায়নের জন্য যোগাযোগ করুন। - Creatives iT',
  'customer_name,doc_title,expiry_date,days_left'),
 ('due_reminder','Payment due reminder','any',
  'প্রিয় {{customer_name}}, ইনভয়েস {{invoice_no}} এর বকেয়া {{due_amount}} {{currency}}। পরিশোধের অনুরোধ করা হলো। - Creatives iT',
  'customer_name,invoice_no,due_amount,currency'),
 ('payment_received','Payment received','any',
  'প্রিয় {{customer_name}}, আপনার {{amount}} {{currency}} পেমেন্ট গ্রহণ করা হয়েছে। বর্তমান বকেয়া {{balance}} {{currency}}। ধন্যবাদ। - Creatives iT',
  'customer_name,amount,currency,balance'),
 ('supplier_payment','Supplier payment note','any',
  'প্রিয় {{supplier_name}}, আপনাকে {{amount}} {{currency}} পরিশোধ করা হয়েছে। রেফারেন্স: {{reference}}। - Creatives iT',
  'supplier_name,amount,currency,reference'),
 ('service_list','Our services','any',
  'আসসালামু আলাইকুম {{customer_name}},\nআমরা নিম্নলিখিত সেবা দিয়ে থাকি:\n{{service_list}}\nযোগাযোগ: {{company_phone}} - Creatives iT',
  'customer_name,service_list,company_phone');

SET FOREIGN_KEY_CHECKS = 1;
