-- 为 transactions 表增加 bonus、total 列（若表里还没有这两列再执行）
-- 奖励/返点 % 会按「金额 × 百分比 / 100」算出 bonus 并写入此处，顾客列表 Bonus 列即按此汇总
-- 在 phpMyAdmin 中选中数据库后执行。若提示列已存在，说明已有该列，可忽略。

ALTER TABLE transactions
  ADD COLUMN bonus DECIMAL(12,2) DEFAULT 0,
  ADD COLUMN total DECIMAL(12,2) DEFAULT 0;
