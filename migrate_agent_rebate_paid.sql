-- Agent 返水：增加已发放状态
ALTER TABLE agent_rebate_settings
  ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN paid_at DATETIME NULL;

-- 可选：若老版本没有 updated_at / updated_by，可自行补齐（如已存在会报重复列，可忽略）
-- ALTER TABLE agent_rebate_settings
--   ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--   ADD COLUMN updated_by INT UNSIGNED NULL;

