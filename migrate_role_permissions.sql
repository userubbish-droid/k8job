-- Member 权限表：admin 可勾选 member 能看/能操作哪些功能
-- 在 phpMyAdmin 中选中数据库后执行一次

CREATE TABLE IF NOT EXISTS role_permissions (
  role VARCHAR(20) NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (role, permission_key)
);

-- 默认给 member 全部权限（与未做权限前的行为一致），之后可在「权限设置」里用打勾调整
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('member', 'transaction_create'),
('member', 'transaction_list'),
('member', 'customers'),
('member', 'customer_create'),
('member', 'customer_edit'),
('member', 'product_library');
