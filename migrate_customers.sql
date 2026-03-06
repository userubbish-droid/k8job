-- 只用于“已经建过数据库表”的情况：新增 customers 表
-- 在 Hostinger phpMyAdmin 里选中数据库后执行

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(80) NULL,
  phone VARCHAR(30) NULL,
  remark VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

