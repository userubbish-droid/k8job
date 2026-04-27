-- PG（支付网关）专用：银行/客户 与 gaming 的 banks、customers 完全分开。
-- 请在 PG 业务所在库执行（与 pg_transactions 同库）；主库-only 时则在主库执行。
-- 重复执行安全：CREATE TABLE IF NOT EXISTS。

CREATE TABLE IF NOT EXISTS pg_banks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pg_banks_company_name (company_id, name),
    KEY idx_pg_banks_company (company_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pg_customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NULL DEFAULT NULL,
    phone VARCHAR(40) NULL DEFAULT NULL,
    remark VARCHAR(512) NULL DEFAULT NULL,
    bank_details TEXT NULL DEFAULT NULL,
    register_date DATE NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pg_customers_company_code (company_id, code),
    KEY idx_pg_customers_company (company_id, is_active),
    KEY idx_pg_customers_phone (company_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status 使用 VARCHAR 便于以后改成与 gaming 相同的 pending/approved 等，无需改表类型。
-- 需要 recommend / bonus_flag 等 gaming 专用列时，仅对本表 ALTER，勿动 customers。
