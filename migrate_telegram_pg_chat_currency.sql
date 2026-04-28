-- PG：群/话题默认币种（catalog 主库，与 default_bank 并列）
-- Webhook 的 tqx_pg_ensure_chat_customer_schema 也会自动 ALTER，本文件便于手工执行。

ALTER TABLE telegram_pg_chat_customer_pg
    ADD COLUMN default_currency VARCHAR(8) NOT NULL DEFAULT '' AFTER default_product;

ALTER TABLE telegram_quick_txn_config_pg
    ADD COLUMN pg_simple_currency VARCHAR(8) NULL DEFAULT NULL AFTER pg_simple_product;
