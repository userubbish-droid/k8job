<?php
/**
 * 与 admin_banks_products 列表、Telegram 阈值：同一套 statement 区间与合并期初。
 *
 * @return array{
 *   total_in_bank: array<string,float>,
 *   total_out_bank: array<string,float>,
 *   total_in_product: array<string,float>,
 *   total_topup_product: array<string,float>,
 *   total_out_product: array<string,float>,
 *   cum_in_bank: array<string,float>,
 *   cum_out_bank: array<string,float>,
 *   cum_in_product: array<string,float>,
 *   cum_topup_product: array<string,float>,
 *   cum_out_product: array<string,float>,
 *   initial_bank: array<string,float>,
 *   initial_product: array<string,float>,
 *   bank_balances_for_notify: array<string,float>,
 *   product_balances_for_notify: array<string,float>,
 *   diag_error: string
 * }
 */
function balance_notify_ledger_snapshot(PDO $pdo, int $company_id): array
{
    $empty = [
        'total_in_bank' => [],
        'total_out_bank' => [],
        'total_in_product' => [],
        'total_topup_product' => [],
        'total_out_product' => [],
        'cum_in_bank' => [],
        'cum_out_bank' => [],
        'cum_in_product' => [],
        'cum_topup_product' => [],
        'cum_out_product' => [],
        'initial_bank' => [],
        'initial_product' => [],
        'bank_balances_for_notify' => [],
        'product_balances_for_notify' => [],
        'diag_error' => '',
    ];

    $abp_has_deleted_at = true;
    try {
        $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
    } catch (Throwable $e) {
        $abp_has_deleted_at = false;
    }
    $abp_del = $abp_has_deleted_at ? ' AND deleted_at IS NULL' : '';

    $minD = null;
    try {
        $stMin = $pdo->prepare("SELECT MIN(day) AS d FROM transactions WHERE company_id = ? AND status = 'approved'{$abp_del}");
        $stMin->execute([$company_id]);
        $minD = $stMin->fetchColumn();
    } catch (Throwable $e) {
    }
    $day_from = (is_string($minD) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minD)) ? $minD : '1970-01-01';
    $day_to = date('Y-m-d');

    $total_in_bank = [];
    $total_out_bank = [];
    $total_in_product = [];
    $total_topup_product = [];
    $total_out_product = [];
    $cum_in_bank = [];
    $cum_out_bank = [];
    $cum_in_product = [];
    $cum_topup_product = [];
    $cum_out_product = [];
    $initial_bank = [];
    $initial_product = [];
    $diag_error = '';

    try {
        require_once __DIR__ . '/game_platform_statement_compute.php';
        $total_in_bank = $range_in_bank;
        $total_out_bank = $range_out_bank;
        $total_in_product = $range_in_product;
        $total_topup_product = $range_topup_product;
        $total_out_product = $range_out_product;
    } catch (Throwable $e) {
        $diag_error = $e->getMessage();
        $empty['diag_error'] = $diag_error;
        return $empty;
    }

    $banks_active = [];
    try {
        $stb = $pdo->prepare('SELECT name FROM banks WHERE company_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC');
        $stb->execute([$company_id]);
        $banks_active = $stb->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }

    $products_for_notify = [];
    try {
        $stp = $pdo->prepare('SELECT name FROM products WHERE company_id = ? AND is_active = 1 AND (delete_pending_at IS NULL) ORDER BY sort_order ASC, name ASC');
        $stp->execute([$company_id]);
        $products_for_notify = $stp->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }

    $bank_balances_for_notify = [];
    foreach ($banks_active as $bname) {
        $bname = trim((string)$bname);
        if ($bname === '') {
            continue;
        }
        $bkey = strtolower($bname);
        $tin = (float)($total_in_bank[$bkey] ?? 0);
        $tout = (float)($total_out_bank[$bkey] ?? 0);
        $merged_open = isset($initial_bank[$bname]) ? (float)$initial_bank[$bname] : (float)(($cum_in_bank[$bkey] ?? 0) - ($cum_out_bank[$bkey] ?? 0));
        $bank_balances_for_notify[$bname] = $merged_open + $tin - $tout;
    }

    $product_balances_for_notify = [];
    foreach ($products_for_notify as $pname) {
        $pname = trim((string)$pname);
        if ($pname === '') {
            continue;
        }
        $pkey = strtolower($pname);
        $tin = (float)($total_in_product[$pkey] ?? 0);
        $topup = (float)($total_topup_product[$pkey] ?? 0);
        $tout = (float)($total_out_product[$pkey] ?? 0);
        $merged_open = isset($initial_product[$pname]) ? (float)$initial_product[$pname]
            : (float)(-($cum_in_product[$pkey] ?? 0) + ($cum_topup_product[$pkey] ?? 0) + ($cum_out_product[$pkey] ?? 0));
        $product_balances_for_notify[$pname] = $merged_open - $tin + $topup + $tout;
    }

    return [
        'total_in_bank' => $total_in_bank,
        'total_out_bank' => $total_out_bank,
        'total_in_product' => $total_in_product,
        'total_topup_product' => $total_topup_product,
        'total_out_product' => $total_out_product,
        'cum_in_bank' => $cum_in_bank,
        'cum_out_bank' => $cum_out_bank,
        'cum_in_product' => $cum_in_product,
        'cum_topup_product' => $cum_topup_product,
        'cum_out_product' => $cum_out_product,
        'initial_bank' => $initial_bank,
        'initial_product' => $initial_product,
        'bank_balances_for_notify' => $bank_balances_for_notify,
        'product_balances_for_notify' => $product_balances_for_notify,
        'diag_error' => $diag_error,
    ];
}
