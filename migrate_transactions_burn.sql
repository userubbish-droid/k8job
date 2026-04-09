-- transactions：添加 burn 字段（WITHDRAW / FREE WITHDRAW 的小数尾差）
-- 例：实际可出 200.17，出款 200，burn 0.17
ALTER TABLE transactions
  ADD COLUMN burn DECIMAL(14,2) NULL DEFAULT NULL AFTER amount;

