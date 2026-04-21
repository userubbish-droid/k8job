<?php
/**
 * 提交流水後彈窗用：與 Banks & Products「Balance Now」及 statement 彙總同一口徑
 *（全量已審核流水 + balance_adjust 期初；產品鍵為 gpc_effective_product_key）。
 *
 * @return array{bank_balance: ?float, product_balance: ?float}
 */
function txn_balance_now_for_labels(PDO $pdo, int $company_id, string $bankLabel, string $productLabel): array
{
    $out = ['bank_balance' => null, 'product_balance' => null];

    $has_deleted_at = true;
    try {
        $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
    } catch (Throwable $e) {
        $has_deleted_at = false;
    }
    $del = $has_deleted_at ? ' AND deleted_at IS NULL' : '';

    $balance_bank = [];
    $balance_product = [];
    try {
        $stmtBa = $pdo->prepare('SELECT adjust_type, name, initial_balance FROM balance_adjust WHERE company_id = ?');
        $stmtBa->execute([$company_id]);
        foreach ($stmtBa->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = strtolower(trim((string)($r['name'] ?? '')));
            if ($k === '') {
                continue;
            }
            if (($r['adjust_type'] ?? '') === 'bank') {
                $balance_bank[$k] = (float)($r['initial_balance'] ?? 0);
            } elseif (($r['adjust_type'] ?? '') === 'product') {
                $balance_product[$k] = (float)($r['initial_balance'] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }

    $bankKey = strtolower(trim($bankLabel));
    if ($bankKey !== '') {
        $ti = 0.0;
        $tout = 0.0;
        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
                COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount + COALESCE(burn,0) WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS tout
                FROM transactions WHERE status = 'approved' AND company_id = ?{$del}
                AND bank IS NOT NULL AND TRIM(bank) <> '' AND LOWER(TRIM(bank)) = ?"
            );
            $stmt->execute([$company_id, $bankKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $ti = (float)($row['ti'] ?? 0);
            $tout = (float)($row['tout'] ?? 0);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
                    COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS tout
                    FROM transactions WHERE status = 'approved' AND company_id = ?{$del}
                    AND bank IS NOT NULL AND TRIM(bank) <> '' AND LOWER(TRIM(bank)) = ?"
                );
                $stmt->execute([$company_id, $bankKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $ti = (float)($row['ti'] ?? 0);
                $tout = (float)($row['tout'] ?? 0);
            } catch (Throwable $e2) {
            }
        }
        $start = (float)($balance_bank[$bankKey] ?? 0);
        $out['bank_balance'] = round($start + $ti - $tout, 2);
    }

    $productKey = strtolower(trim($productLabel));
    if ($productKey !== '') {
        $gpcKeySql = require __DIR__ . '/gpc_effective_product_key_sql.php';
        $line = '(CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END)';
        $tin = 0.0;
        $topup = 0.0;
        $tout = 0.0;
        try {
            $stmt = $pdo->prepare(
                "SELECT
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN {$line} + (CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW' THEN COALESCE(t.burn,0) ELSE 0 END) ELSE 0 END), 0) AS ti,
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN {$line} ELSE 0 END), 0) AS topup,
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'WITHDRAW' THEN t.amount + COALESCE(t.burn,0) WHEN TRIM(COALESCE(t.mode,'')) = 'EXPENSE' THEN t.amount ELSE 0 END), 0) AS tout
                FROM transactions t WHERE t.status = 'approved' AND t.company_id = ?{$del} AND ({$gpcKeySql}) = ?"
            );
            $stmt->execute([$company_id, $productKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $tin = (float)($row['ti'] ?? 0);
            $topup = (float)($row['topup'] ?? 0);
            $tout = (float)($row['tout'] ?? 0);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT
                    COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS ti,
                    COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS topup,
                    COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('WITHDRAW','EXPENSE') THEN t.amount ELSE 0 END), 0) AS tout
                    FROM transactions t WHERE t.status = 'approved' AND t.company_id = ?{$del} AND ({$gpcKeySql}) = ?"
                );
                $stmt->execute([$company_id, $productKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $tin = (float)($row['ti'] ?? 0);
                $topup = (float)($row['topup'] ?? 0);
                $tout = (float)($row['tout'] ?? 0);
            } catch (Throwable $e2) {
            }
        }
        $start = (float)($balance_product[$productKey] ?? 0);
        $out['product_balance'] = round($start - $tin + $topup + $tout, 2);
    }

    return $out;
}
