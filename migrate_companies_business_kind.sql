-- 分公司业务类型：博彩(gaming) 与 支付网关(pg) 分开标识（报表/后续逻辑可按此过滤）
-- 在 phpMyAdmin 选中数据库后执行一次即可；重复执行若报 Duplicate column 可忽略。

ALTER TABLE companies
  ADD COLUMN business_kind ENUM('gaming', 'pg') NOT NULL DEFAULT 'gaming' AFTER currency;
