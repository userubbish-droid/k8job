-- 顾客资料详细字段：在已有 customers 表上增加列（只执行一次，重复执行会报 Duplicate column 可忽略）
-- 在 Hostinger phpMyAdmin 里选中数据库后执行

ALTER TABLE customers
  ADD COLUMN visitor VARCHAR(20) NULL DEFAULT 'VISITOR',
  ADD COLUMN register_date DATE NULL,
  ADD COLUMN bank_details VARCHAR(255) NULL,
  ADD COLUMN regular_customer VARCHAR(10) NULL,
  ADD COLUMN sms VARCHAR(50) NULL,
  ADD COLUMN fd VARCHAR(50) NULL,
  ADD COLUMN ws VARCHAR(10) NULL,
  ADD COLUMN wc VARCHAR(10) NULL,
  ADD COLUMN verify VARCHAR(20) NULL,
  ADD COLUMN old_total_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN old_total_withdraw DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN withdraw DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN ref_918kiss VARCHAR(50) NULL,
  ADD COLUMN ref_megab VARCHAR(50) NULL;
