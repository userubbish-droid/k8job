-- Kiosk Game Platform：% 与 amount paid（执行一次即可）
ALTER TABLE products
  ADD COLUMN kiosk_fee_pct DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'Kiosk %' AFTER sort_order,
  ADD COLUMN kiosk_paid_amount DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'Kiosk amount paid' AFTER kiosk_fee_pct;
