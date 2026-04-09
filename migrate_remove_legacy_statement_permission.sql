-- 已废弃：member 权限键 statement（旧版「对账总开关」），现仅使用 statement_report / statement_balance 等细项。
-- 在 phpMyAdmin 选中数据库后执行一次，清理历史数据（可选；不执行也不影响代码，只是库里会残留无用行）。
DELETE FROM user_permissions WHERE permission_key = 'statement';
