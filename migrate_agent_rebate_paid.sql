-- Agent 返水：增加已发放状态
ALTER TABLE agent_rebate_settings
  ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN paid_at DATETIME NULL;

