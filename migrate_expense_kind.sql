-- EXPENSE 分为 statement / kiosk，需在 phpMyAdmin 执行一次
ALTER TABLE transactions
ADD COLUMN expense_kind ENUM('statement','kiosk') NULL DEFAULT NULL COMMENT 'EXPENSE 分类：statement=Expense Statement，kiosk=Kiosk Expense' AFTER product;

-- 旧数据视为 statement
UPDATE transactions SET expense_kind = 'statement' WHERE mode = 'EXPENSE' AND expense_kind IS NULL;
