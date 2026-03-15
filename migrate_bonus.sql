-- 为 transactions 表增加 bonus、total 列（缺哪列执行哪条）
-- 奖励/返点 % 会按「金额 × 百分比 / 100」算出 bonus 并写入此处，顾客列表 Bonus 列即按此汇总
-- 若某条提示「列名重复」，说明该列已有，可忽略，只执行另一条即可。
--
-- 执行前可先检查表结构：SHOW COLUMNS FROM transactions;
-- 执行后可检查是否有 bonus 数据：SELECT id, code, amount, bonus, total FROM transactions ORDER BY id DESC LIMIT 10;

ALTER TABLE transactions ADD COLUMN bonus DECIMAL(12,2) DEFAULT 0;
ALTER TABLE transactions ADD COLUMN total DECIMAL(12,2) DEFAULT 0;
