-- 按「每个 Member」设置权限（先选 member1/member2，再勾选该成员可用的功能）
-- 在 phpMyAdmin 中选中数据库后执行一次

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (user_id, permission_key)
);

-- 若已有 role_permissions 表且给 member 设过权限，可执行下面一句，把权限复制到每个 member 用户
-- INSERT IGNORE INTO user_permissions (user_id, permission_key)
-- SELECT u.id, r.permission_key FROM users u
-- CROSS JOIN role_permissions r WHERE u.role = 'member' AND r.role = 'member';
