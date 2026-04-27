-- PG（支付网关）专用流水表：与 gaming 的 `transactions` 完全分开，结构尽量简单。
-- 执行位置：
--   - 若 PG 业务数据在「分库」：请在 PG 分库执行（与 pg_banks、pg_customers 同库，见 migrate_pg_banks_and_customers.sql）。
--   - 若 PG 仍写在主库：在主库执行即可。
-- 重复执行安全：仅 CREATE TABLE IF NOT EXISTS。

CREATE TABLE IF NOT EXISTS pg_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    txn_day DATE NOT NULL,
    txn_time TIME NULL DEFAULT NULL,
    -- in=入款 / out=出款（可按你网关习惯在程序里映射）
    flow ENUM('in', 'out') NOT NULL DEFAULT 'in',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NULL DEFAULT NULL,
    -- 网关单号、渠道名、会员代号等（可空，按实际上线再填）
    external_ref VARCHAR(128) NULL DEFAULT NULL,
    channel VARCHAR(80) NULL DEFAULT NULL,
    member_code VARCHAR(64) NULL DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    remark TEXT NULL,
    staff VARCHAR(120) NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT UNSIGNED NULL DEFAULT NULL,
    approved_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pg_txn_company_day (company_id, txn_day),
    KEY idx_pg_txn_company_status (company_id, status),
    KEY idx_pg_txn_external (company_id, external_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 需要更多栏位时，仅对本表 ALTER，勿动 gaming 的 transactions。
