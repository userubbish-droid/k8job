-- 可选：若未走 config.php 自动建表/插入，可手动执行
-- 在 companies 中保留一条「总公司」，代码 hq，供总部 admin/member 绑定 company_id
INSERT IGNORE INTO companies (code, name, is_active) VALUES ('hq', '总公司', 1);
