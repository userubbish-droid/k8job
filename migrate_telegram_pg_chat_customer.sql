-- PG：每个 Telegram 群绑定一个默认「客户代号」（member_code），该群内所有记账使用该代号。
-- 在 catalog（与 telegram_quick_txn_config_pg 相同库）执行一次。

CREATE TABLE IF NOT EXISTS telegram_pg_chat_customer_pg (
    chat_id VARCHAR(40) NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    member_code VARCHAR(64) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id),
    KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 把旧版「仅 config 表里 chat_id」的绑定迁到新表（member_code 留空，由群内 /customer 或后台再填）
INSERT IGNORE INTO telegram_pg_chat_customer_pg (chat_id, company_id, member_code)
SELECT TRIM(chat_id), company_id, ''
FROM telegram_quick_txn_config_pg
WHERE chat_id IS NOT NULL AND TRIM(chat_id) <> '' AND enabled = 1;
