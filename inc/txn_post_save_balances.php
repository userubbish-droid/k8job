<?php
/**
 * 提交流水後彈窗用：與 Banks & Products、balance_summary（statement）同源（ledger_stmt_balance_snapshot）。
 *
 * @return array{bank_balance: ?float, product_balance: ?float}
 */
function txn_balance_now_for_labels(PDO $pdo, int $company_id, string $bankLabel, string $productLabel): array
{
    require_once __DIR__ . '/ledger_stmt_balance_snapshot.php';
    return ledger_stmt_balance_snapshot($pdo, $company_id, $bankLabel, $productLabel);
}
