-- PG：群 / 论坛话题 默认客户代号、默认银行、默认产品（catalog 主库执行一次）
-- 新装可直接建表；从旧版 PK(chat_id) 升级请依赖 PG webhook 或 admin「PG Telegram」打开时自动 ALTER，或手动执行 migrate_telegram_pg_chat_bank_topic.sql

CREATE TABLE IF NOT EXISTS telegram_pg_chat_customer_pg (
    chat_id VARCHAR(40) NOT NULL,
    topic_key VARCHAR(32) NOT NULL DEFAULT '0',
    company_id INT UNSIGNED NOT NULL,
    member_code VARCHAR(64) NOT NULL DEFAULT '',
    default_bank VARCHAR(64) NOT NULL DEFAULT '',
    default_product VARCHAR(64) NOT NULL DEFAULT '',
    default_currency VARCHAR(8) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id, topic_key),
    KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 从 config 表迁入整群绑定（topic_key=0）
INSERT IGNORE INTO telegram_pg_chat_customer_pg (chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency)
SELECT TRIM(chat_id), '0', company_id, '', '', '', ''
FROM telegram_quick_txn_config_pg
WHERE chat_id IS NOT NULL AND TRIM(chat_id) <> '' AND enabled = 1;
