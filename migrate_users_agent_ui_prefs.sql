-- 代理账号：是否在 Agent 页显示「期/周」与「月」快捷（默认均开启）
-- 也可由程序在加载 config.php 时自动 ALTER；本文件供手动执行参考

ALTER TABLE users ADD COLUMN agent_ui_show_week TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN agent_ui_show_month TINYINT(1) NOT NULL DEFAULT 1;
