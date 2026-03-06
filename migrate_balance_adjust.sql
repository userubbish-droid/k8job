-- 银行/产品初始余额（admin 可调），用于计算「余额 = 初始 + 入账 - 出账」
-- 在 phpMyAdmin 中选中数据库后执行一次

CREATE TABLE IF NOT EXISTS balance_adjust (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  adjust_type ENUM('bank','product') NOT NULL,
  name VARCHAR(80) NOT NULL COMMENT '银行名或产品名',
  initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  UNIQUE KEY (adjust_type, name)
);
