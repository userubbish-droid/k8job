-- 只用于“已经建过 transactions 表”的情况
-- 在 Hostinger phpMyAdmin 里选中数据库后执行

ALTER TABLE transactions
  ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN created_by INT UNSIGNED NULL,
  ADD COLUMN approved_by INT UNSIGNED NULL,
  ADD COLUMN approved_at DATETIME NULL;

