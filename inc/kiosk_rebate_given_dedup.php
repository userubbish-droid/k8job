<?php
/**
 * 返点页 rebate_given 与 mode=REBATE 流水：同客户代号 + 入账 line 与 rebate_amount 一致（±0.01）视为同一笔，避免 Rebate 列与弹窗重复计算/展示。
 *
 * @param array<string,string> $code_to_gp strtolower(code) => strtolower(product)
 * @return array{paired_txn_ids: int[], subtract_line_by_gp: array<string,float>}
 */
function gpc_rebate_pair_given_with_txns(
    PDO $pdo,
    int $company_id,
    string $day_from,
    string $day_to,
    string $gpc_gp_key_sql,
    array $code_to_gp
): array {
    $paired_txn_ids = [];
    $subtract_line_by_gp = [];

    $lineOf = static function (array $r): float {
        $tot = $r['total'] ?? null;
        if ($tot !== null && $tot !== '' && (float)$tot != 0.0) {
            return (float)$tot;
        }
        return (float)($r['amount'] ?? 0) + (float)($r['bonus'] ?? 0);
    };

    $rgRows = [];
    try {
        $st = $pdo->prepare("SELECT TRIM(code) AS cd, rebate_amount FROM rebate_given
            WHERE company_id = ? AND rebate_amount IS NOT NULL AND day >= ? AND day <= ?
            ORDER BY TRIM(code), day");
        $st->execute([$company_id, $day_from, $day_to]);
        $rgRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['paired_txn_ids' => [], 'subtract_line_by_gp' => []];
    }

    $txRows = [];
    try {
        $sql = "SELECT t.id, TRIM(t.code) AS cd, t.amount, t.bonus, t.total,
            ($gpc_gp_key_sql) AS eff_gp
            FROM transactions t
            WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved' AND t.deleted_at IS NULL
            AND TRIM(COALESCE(t.mode,'')) = 'REBATE'";
        $st = $pdo->prepare($sql);
        $st->execute([$company_id, $day_from, $day_to]);
        $txRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['paired_txn_ids' => [], 'subtract_line_by_gp' => []];
    }

    $usedTxn = [];
    foreach ($rgRows as $rg) {
        $cd = strtolower(trim((string)($rg['cd'] ?? '')));
        $amt = (float)($rg['rebate_amount'] ?? 0);
        if ($cd === '' || abs($amt) < 0.0001) {
            continue;
        }
        $gp = $code_to_gp[$cd] ?? '';
        if ($gp === '') {
            continue;
        }
        foreach ($txRows as $tx) {
            $tid = (int)($tx['id'] ?? 0);
            if ($tid <= 0 || isset($usedTxn[$tid])) {
                continue;
            }
            $tcd = strtolower(trim((string)($tx['cd'] ?? '')));
            $tgp = strtolower(trim((string)($tx['eff_gp'] ?? '')));
            if ($tcd !== $cd || $tgp !== $gp) {
                continue;
            }
            $ln = $lineOf($tx);
            if (abs($ln - $amt) > 0.009) {
                continue;
            }
            $usedTxn[$tid] = true;
            $paired_txn_ids[] = $tid;
            $subtract_line_by_gp[$gp] = ($subtract_line_by_gp[$gp] ?? 0) + $ln;
            break;
        }
    }

    return ['paired_txn_ids' => $paired_txn_ids, 'subtract_line_by_gp' => $subtract_line_by_gp];
}
