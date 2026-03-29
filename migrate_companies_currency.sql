-- Statement 总公司视图：按币种筛选分公司（默认 MYR）
ALTER TABLE companies ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'MYR';
