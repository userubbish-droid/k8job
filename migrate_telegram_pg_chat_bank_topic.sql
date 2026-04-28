-- 从旧版 telegram_pg_chat_customer_pg（仅主键 chat_id）升级到 (chat_id, topic_key) + 银行/产品列
-- 在 catalog 执行；若列或主键已存在，对应行会报错，可忽略后继续下一行。

ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN default_bank VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN default_product VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN topic_key VARCHAR(32) NOT NULL DEFAULT '0' AFTER chat_id;

UPDATE telegram_pg_chat_customer_pg SET topic_key = '0' WHERE topic_key = '' OR topic_key IS NULL;

ALTER TABLE telegram_pg_chat_customer_pg DROP PRIMARY KEY;
ALTER TABLE telegram_pg_chat_customer_pg ADD PRIMARY KEY (chat_id, topic_key);
