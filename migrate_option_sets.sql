-- 顾客资料里 SMS / FD / WS / WC / VERIFY 的选项来源表（只执行一次）
-- 在 Hostinger phpMyAdmin 里选中数据库后执行

CREATE TABLE IF NOT EXISTS option_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_type VARCHAR(20) NOT NULL COMMENT 'sms, fd, ws, wc, verify',
  option_value VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (option_type, option_value)
);

-- 预置一些常用选项（可选，执行后可在「选项设置」里增删）
INSERT IGNORE INTO option_sets (option_type, option_value, sort_order) VALUES
('sms', 'Yes', 1), ('sms', 'No', 2),
('fd', 'Yes', 1), ('fd', 'No', 2),
('ws', 'YES', 1), ('ws', 'No', 2),
('wc', 'Yes', 1), ('wc', 'No', 2),
('verify', 'Unique', 1);
