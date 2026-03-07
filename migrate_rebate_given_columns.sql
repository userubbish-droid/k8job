-- 返点「已给」时保存填写的 % 与计算出的返点金额，提交后页面显示（若表已存在则只执行此迁移）
ALTER TABLE rebate_given
  ADD COLUMN rebate_pct DECIMAL(10,2) NULL,
  ADD COLUMN rebate_amount DECIMAL(12,2) NULL;
