-- customers：标记客户特殊状态（no bonus / scam receipt）
ALTER TABLE customers
  ADD COLUMN bonus_flag VARCHAR(32) NULL DEFAULT NULL AFTER bank_details;

