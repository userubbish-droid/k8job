-- 银行互转等记录对 member 不可见：仅 admin 在流水记录中可见
-- 在 phpMyAdmin 中选中数据库后执行一次

ALTER TABLE transactions
  ADD COLUMN hide_from_member TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=仅管理员可见，如银行互转';
