-- 返点「已给出」记录：某日一旦提交则 member 不可取消，仅 admin 可取消
CREATE TABLE IF NOT EXISTS rebate_submit (
  day DATE NOT NULL PRIMARY KEY,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by INT UNSIGNED NULL
);
