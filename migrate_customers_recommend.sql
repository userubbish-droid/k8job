-- 顾客表增加 recommend 字段（推荐人/推荐码等）
-- 在 phpMyAdmin 中选中数据库后执行一次

ALTER TABLE customers ADD COLUMN recommend VARCHAR(255) NULL DEFAULT NULL;
