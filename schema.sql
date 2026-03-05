-- 在 Hostinger phpMyAdmin 里选中你的数据库后，执行本文件

CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  time TIME NOT NULL,
  mode VARCHAR(20) NOT NULL,
  code VARCHAR(20),
  bank VARCHAR(50),
  product VARCHAR(50),
  amount DECIMAL(12,2) NOT NULL,
  bonus DECIMAL(12,2) DEFAULT 0,
  total DECIMAL(12,2) DEFAULT 0,
  staff VARCHAR(50),
  remark VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
