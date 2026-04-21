<?php
/**
 * 與 balance_summary / Banks 頁同源：載入 game_platform_statement_compute（最早流水日～今天），
 * 回傳單一銀行、單一產品在「該區間末」的餘額（與 statement 公式一致）。
 *
 * @return array{bank_balance: ?float, product_balance: ?float}
 */
function ledger_stmt_balance_snapshot(PDO $pdo, int $company_id, string $bankLabel, string $productLabel): array
{
    $out = ['bank_balance' => null, 'product_balance' => null];
    $has_deleted_at = true;
    try {
        $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
    } catch (Throwable $e) {
        $has_deleted_at = false;
    }
    $del = $has_deleted_at ? ' AND deleted_at IS NULL' : '';
    try {
        $stMin = $pdo->prepare("SELECT MIN(day) AS d FROM transactions WHERE company_id = ? AND status = 'approved'{$del}");
        $stMin->execute([$company_id]);
        $minD = $stMin->fetchColumn();
    } catch (Throwable $e) {
        $minD = null;
    }
    $day_from = (is_string($minD) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minD)) ? $minD : '1970-01-01';
    $day_to = date('Y-m-d');

    try {
        require_once __DIR__ . '/game_platform_statement_compute.php';
    } catch (Throwable $e) {
        return $out;
    }

    $bk = strtolower(trim($bankLabel));
    if ($bk !== '') {
        foreach ($initial_bank as $nm => $ival) {
            if (strtolower(trim((string)$nm)) === $bk) {
                $rin = (float)($range_in_bank[$bk] ?? 0);
                $rout = (float)($range_out_bank[$bk] ?? 0);
                $out['bank_balance'] = round((float)$ival + $rin - $rout, 2);
                break;
            }
        }
        if ($out['bank_balance'] === null) {
            $open = (float)(($cum_in_bank[$bk] ?? 0) - ($cum_out_bank[$bk] ?? 0));
            $rin = (float)($range_in_bank[$bk] ?? 0);
            $rout = (float)($range_out_bank[$bk] ?? 0);
            $out['bank_balance'] = round($open + $rin - $rout, 2);
        }
    }

    $pk = strtolower(trim($productLabel));
    if ($pk !== '') {
        foreach ($initial_product as $nm => $ival) {
            if (strtolower(trim((string)$nm)) === $pk) {
                $rin = (float)($range_in_product[$pk] ?? 0);
                $top = (float)($range_topup_product[$pk] ?? 0);
                $rout = (float)($range_out_product[$pk] ?? 0);
                $out['product_balance'] = round((float)$ival - $rin + $top + $rout, 2);
                break;
            }
        }
        if ($out['product_balance'] === null) {
            $open = (float)(-($cum_in_product[$pk] ?? 0) + ($cum_topup_product[$pk] ?? 0) + ($cum_out_product[$pk] ?? 0));
            $rin = (float)($range_in_product[$pk] ?? 0);
            $top = (float)($range_topup_product[$pk] ?? 0);
            $rout = (float)($range_out_product[$pk] ?? 0);
            $out['product_balance'] = round($open - $rin + $top + $rout, 2);
        }
    }

    return $out;
}
