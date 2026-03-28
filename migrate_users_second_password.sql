-- 二级密码（仅 admin / member 登录第二步校验；由 Boss / 平台 big boss 在用户编辑中设置）
-- 执行一次即可（若列已存在会报错，可忽略）
ALTER TABLE users
ADD COLUMN second_password_hash VARCHAR(255) NULL COMMENT '二级密码' AFTER password_hash;
