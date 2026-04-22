<?php
require 'config.php';
require 'auth.php';
require_admin();

$sidebar_current = 'admin_banks_products';
$company_id = effective_admin_company_id($pdo);

$has_deleted_at = true;
try {
    $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
} catch (Throwable $e) {
    $has_deleted_at = false;
}
$del = $has_deleted_at ? ' AND deleted_at IS NULL' : '';

$bank_q = trim((string)($_GET['bank'] ?? ''));
$product_q = trim((string)($_GET['product'] ?? ''));

$minD = null;
try {
    $stMin = $pdo->prepare("SELECT MIN(day) AS d FROM transactions WHERE company_id = ? AND status = 'approved'{$del}");
    $stMin->execute([$company_id]);
    $minD = $stMin->fetchColumn();
} catch (Throwable $e) {}
$day_from = (is_string($minD) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minD)) ? $minD : '1970-01-01';
$day_to = date('Y-m-d');

require_once __DIR__ . '/inc/game_platform_statement_compute.php';

$bank_key = strtolower($bank_q);
$product_key = strtolower($product_q);

$bank_calc = null;
if ($bank_key !== '') {
    $opening = null;
    foreach ($initial_bank as $nm => $iv) {
        if (strtolower(trim((string)$nm)) === $bank_key) {
            $opening = (float)$iv;
            break;
        }
    }
    if ($opening === null) {
        $opening = (float)(($cum_in_bank[$bank_key] ?? 0) - ($cum_out_bank[$bank_key] ?? 0));
    }
    $in = (float)($range_in_bank[$bank_key] ?? 0);
    $out = (float)($range_out_bank[$bank_key] ?? 0);
    $bank_calc = ['opening' => $opening, 'in' => $in, 'out' => $out, 'balance' => $opening + $in - $out];
}

$product_calc = null;
if ($product_key !== '') {
    $opening = null;
    foreach ($initial_product as $nm => $iv) {
        if (strtolower(trim((string)$nm)) === $product_key) {
            $opening = (float)$iv;
            break;
        }
    }
    if ($opening === null) {
        $opening = (float)(-($cum_in_product[$product_key] ?? 0) + ($cum_topup_product[$product_key] ?? 0) + ($cum_out_product[$product_key] ?? 0));
    }
    $in = (float)($range_in_product[$product_key] ?? 0);
    $topup = (float)($range_topup_product[$product_key] ?? 0);
    $out = (float)($range_out_product[$product_key] ?? 0);
    $product_calc = ['opening' => $opening, 'in' => $in, 'topup' => $topup, 'out' => $out, 'balance' => $opening - $in + $topup + $out];
}

$rows_bank = [];
if ($bank_key !== '') {
    try {
        $st = $pdo->prepare("SELECT id, day, time, mode, bank, amount, bonus, total, burn, remark, status
            FROM transactions
            WHERE company_id = ? AND status = 'approved'{$del} AND bank IS NOT NULL AND TRIM(bank) <> '' AND LOWER(TRIM(bank)) = ?
              AND day >= ? AND day <= ?
            ORDER BY day DESC, time DESC, id DESC
            LIMIT 100");
        $st->execute([$company_id, $bank_key, $day_from, $day_to]);
        $rows_bank = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows_bank = [];
    }
}

$rows_product = [];
if ($product_key !== '') {
    try {
        $gpcKeySql = require __DIR__ . '/inc/gpc_effective_product_key_sql.php';
        $st = $pdo->prepare("SELECT t.id, t.day, t.time, t.mode, t.product, t.code, t.amount, t.bonus, t.total, t.burn, t.remark, t.status
            FROM transactions t
            WHERE t.company_id = ? AND t.status = 'approved'{$del} AND ({$gpcKeySql}) = ?
              AND t.day >= ? AND t.day <= ?
            ORDER BY t.day DESC, t.time DESC, t.id DESC
            LIMIT 100");
        $st->execute([$company_id, $product_key, $day_from, $day_to]);
        $rows_product = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows_product = [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>对账调试 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1100px;">
            <div class="page-header">
                <h2>对账调试</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <div class="card">
                <p class="form-hint" style="margin:0 0 12px;">
                    窗口：<?= htmlspecialchars($day_from) ?> ～ <?= htmlspecialchars($day_to) ?>（与 Banks 页「全历史～今天」同口径）
                </p>
                <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <div class="form-group" style="margin:0;">
                        <label>Bank（精确匹配名称）</label>
                        <input class="form-control" name="bank" value="<?= htmlspecialchars($bank_q) ?>" placeholder="例如 HLB" style="min-width:160px;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Product（gpc key）</label>
                        <input class="form-control" name="product" value="<?= htmlspecialchars($product_q) ?>" placeholder="例如 mega" style="min-width:160px;">
                    </div>
                    <button class="btn btn-primary" type="submit">计算</button>
                </form>
            </div>

            <?php if ($bank_calc): ?>
            <div class="card">
                <h3 style="margin-top:0;">Bank：<?= htmlspecialchars($bank_q) ?></h3>
                <table class="data-table">
                    <thead><tr><th class="num">Opening</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
                    <tbody><tr>
                        <td class="num"><?= number_format($bank_calc['opening'], 2) ?></td>
                        <td class="num in"><?= number_format($bank_calc['in'], 2) ?></td>
                        <td class="num out"><?= number_format($bank_calc['out'], 2) ?></td>
                        <td class="num profit"><?= number_format($bank_calc['balance'], 2) ?></td>
                    </tr></tbody>
                </table>
                <p class="form-hint" style="margin-top:10px;">Bank Out 口径：WITHDRAW + EXPENSE 的 amount（不含 burn）。</p>

                <div style="overflow:auto;max-height:360px;margin-top:10px;border:1px solid var(--border);border-radius:8px;">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Day</th><th>Time</th><th>Mode</th><th class="num">Amount</th><th class="num">Burn</th><th>Remark</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows_bank as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= htmlspecialchars((string)$r['day']) ?></td>
                                <td><?= htmlspecialchars((string)$r['time']) ?></td>
                                <td><?= htmlspecialchars((string)$r['mode']) ?></td>
                                <td class="num"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                <td class="num"><?= number_format((float)($r['burn'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars((string)($r['remark'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows_bank): ?><tr><td colspan="7">无记录</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($product_calc): ?>
            <div class="card">
                <h3 style="margin-top:0;">Product：<?= htmlspecialchars($product_q) ?></h3>
                <table class="data-table">
                    <thead><tr><th class="num">Opening</th><th class="num">In</th><th class="num">Topup</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
                    <tbody><tr>
                        <td class="num"><?= number_format($product_calc['opening'], 2) ?></td>
                        <td class="num in"><?= number_format($product_calc['in'], 2) ?></td>
                        <td class="num"><?= number_format($product_calc['topup'], 2) ?></td>
                        <td class="num out"><?= number_format($product_calc['out'], 2) ?></td>
                        <td class="num profit"><?= number_format($product_calc['balance'], 2) ?></td>
                    </tr></tbody>
                </table>
                <p class="form-hint" style="margin-top:10px;">Product Out 口径：WITHDRAW 的 amount + burn；EXPENSE 的 amount。</p>

                <div style="overflow:auto;max-height:360px;margin-top:10px;border:1px solid var(--border);border-radius:8px;">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Day</th><th>Time</th><th>Mode</th><th>Code</th><th class="num">Amount</th><th class="num">Burn</th><th>Remark</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows_product as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= htmlspecialchars((string)$r['day']) ?></td>
                                <td><?= htmlspecialchars((string)$r['time']) ?></td>
                                <td><?= htmlspecialchars((string)$r['mode']) ?></td>
                                <td><?= htmlspecialchars((string)($r['code'] ?? '')) ?></td>
                                <td class="num"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                <td class="num"><?= number_format((float)($r['burn'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars((string)($r['remark'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows_product): ?><tr><td colspan="8">无记录</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>

