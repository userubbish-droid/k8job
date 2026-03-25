-- users：记录最后登录时间与 IP（执行一次即可）
ALTER TABLE users
  ADD COLUMN last_login_at DATETIME NULL AFTER is_active,
  ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at;

