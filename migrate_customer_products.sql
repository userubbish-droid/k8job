-- 顾客的产品账号（从 product 选产品，填账号与密码）
-- 在 Hostinger phpMyAdmin 里选中数据库后执行一次

CREATE TABLE IF NOT EXISTS customer_product_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL DEFAULT 1,
  customer_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(80) NOT NULL,
  account VARCHAR(255) NULL,
  password VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
