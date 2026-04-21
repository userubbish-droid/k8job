-- 流水修改申請：凍結「改前」快照（批准後仍可對照）
-- 若應用已自動 ALTER，此檔可選擇性執行

ALTER TABLE transaction_edit_requests ADD COLUMN orig_day DATE NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_time TIME NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_mode VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_code VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_bank VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_product VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_amount DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_burn DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_bonus DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_total DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE transaction_edit_requests ADD COLUMN orig_remark TEXT NULL;
