-- 扩展 users.role，支持 agent 账号
ALTER TABLE users
  MODIFY role ENUM('admin','member','agent') NOT NULL DEFAULT 'member';

