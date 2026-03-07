-- 返点按客户「已给了」：每行可勾选，提交后 member 只显示还没给的；admin 端已给显示绿色、可取消；保存 % 与返点金额
CREATE TABLE IF NOT EXISTS rebate_given (
  day DATE NOT NULL,
  code VARCHAR(50) NOT NULL,
  given_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  given_by INT UNSIGNED NULL,
  rebate_pct DECIMAL(10,2) NULL,
  rebate_amount DECIMAL(12,2) NULL,
  PRIMARY KEY (day, code)
);
