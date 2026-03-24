-- Agent 返水开关：1=启用，0=暂停
ALTER TABLE agent_rebate_settings
  ADD COLUMN rebate_enabled TINYINT(1) NOT NULL DEFAULT 1;

