-- 顾客列表 CSV 导出审计：平台总管理可查看谁、何时下载
CREATE TABLE IF NOT EXISTS customer_export_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  username VARCHAR(191) NOT NULL DEFAULT '',
  user_role VARCHAR(32) NOT NULL DEFAULT '',
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_company_id (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
