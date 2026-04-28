-- PG 专用 Telegram 快捷记账（与 gaming 的 telegram_quick_txn_config / telegram_quick_txn_log 完全分开）
-- 将来 PG 独立 Bot 的 Webhook、后台配置只读写这两张 *_pg 表，避免与 gaming 字段/记录混用。
-- 在 phpMyAdmin 选中与后台相同的数据库（通常为 catalog 主库）后执行一次即可；已存在则跳过（IF NOT EXISTS）。

CREATE TABLE IF NOT EXISTS telegram_quick_txn_config_pg (
    company_id INT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    chat_id VARCHAR(40) NULL,
    allowed_user_ids TEXT NULL,
    bank_alias_json TEXT NULL,
    product_alias_json TEXT NULL,
    staff_alias_json TEXT NULL,
    receipt_prefix TEXT NULL,
    receipt_slogan TEXT NULL,
    receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic',
    undo_window_sec INT NOT NULL DEFAULT 600,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_quick_txn_log_pg (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    chat_id VARCHAR(40) NOT NULL,
    tg_user_id BIGINT NULL,
    tg_username VARCHAR(120) NULL,
    raw_text TEXT NULL,
    action VARCHAR(40) NOT NULL,
    token VARCHAR(40) NOT NULL,
    transaction_id INT UNSIGNED NULL,
    receipt_message_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company_chat (company_id, chat_id),
    KEY idx_token (token),
    KEY idx_receipt_msg (receipt_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 若 PG 业务需要与 gaming 不同的栏位，请仅对 *_pg 表执行 ALTER，勿改 gaming 原表。

-- 极简「+金额 / -金额」：后台填三项后自动展开为完整记账行（亦可由 webhook 首次访问时自动 ALTER）
-- ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN pg_simple_member_code VARCHAR(64) NULL;
-- ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN pg_simple_bank VARCHAR(64) NULL;
-- ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN pg_simple_product VARCHAR(64) NULL;
