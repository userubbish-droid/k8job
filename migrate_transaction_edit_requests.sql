-- member 修改流水（待批准）
CREATE TABLE IF NOT EXISTS transaction_edit_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  transaction_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  time TIME NOT NULL,
  mode VARCHAR(32) NOT NULL,
  code VARCHAR(255) NULL,
  bank VARCHAR(255) NULL,
  product VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  burn DECIMAL(14,2) NULL DEFAULT NULL,
  bonus DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  remark TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  approved_by_tg VARCHAR(128) NULL,
  PRIMARY KEY (id),
  KEY idx_company_status (company_id, status, created_at),
  KEY idx_txn (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

