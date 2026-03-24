<?php
require 'config.php';
require 'auth.php';
require_login();
require_permission('statement');

$sidebar_current = 'balance_summary';
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : null;
$day_to   = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : null;
$day      = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day']) ? $_GET['day'] : null;
if ($day_from !== null && $day_to !== null) {
    if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }
} elseif ($day !== null) {
    $day_from = $day_to = $day;
} else {
    $day_from = $day_to = date('Y-m-d');
}
$is_range = ($day_from !== $day_to);
$msg = '';
$err = '';

$by_bank = [];
$by_product = [];
$initial_bank = [];
$initial_product = [];

// Starting Balance = 区间开始日的前一日 23:59:59 时「银行与产品」的 Balance（即当日收盘余额），本页从 00:00:01 起以该数为基础
$cum_in_bank = [];
$cum_out_bank = [];
$cum_in_product = [];
$cum_topup_product = [];
$cum_out_product = [];
try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE day < ? AND status = 'approved' AND bank IS NOT NULL AND TRIM(bank) != '' GROUP BY bank");
    $stmt->execute([$day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $b = strtolower(trim((string)$r['bank']));
        if ($b !== '') {
            $cum_in_bank[$b] = ($cum_in_bank[$b] ?? 0) + (float)$r['ti'];
            $cum_out_bank[$b] = ($cum_out_bank[$b] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
        }
    }
} catch (Throwable $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'TOPUP' THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE day < ? AND status = 'approved' GROUP BY product");
    $stmt->execute([$day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['product'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $cum_in_product[$p] = ($cum_in_product[$p] ?? 0) + (float)$r['ti'];
            $cum_topup_product[$p] = ($cum_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
            $cum_out_product[$p] = ($cum_out_product[$p] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
        }
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved' AND bank IS NOT NULL AND TRIM(bank) != ''
        GROUP BY bank");
    $stmt->execute([$day_from, $day_to]);
    $range_in_bank = [];
    $range_out_bank = [];
    foreach ($stmt->fetchAll() as $r) {
        $b = strtolower(trim((string)($r['bank'] ?? '')));
        if ($b !== '') {
            $range_in_bank[$b] = ($range_in_bank[$b] ?? 0) + (float)$r['total_in'];
            $range_out_bank[$b] = ($range_out_bank[$b] ?? 0) + (float)$r['total_out'];
        }
    }
} catch (Throwable $e) { $range_in_bank = []; $range_out_bank = []; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '') AS product,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'TOPUP' THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved'
        GROUP BY product");
    $stmt->execute([$day_from, $day_to]);
    $range_in_product = [];
    $range_topup_product = [];
    $range_out_product = [];
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['product'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $range_in_product[$p] = ($range_in_product[$p] ?? 0) + (float)$r['total_in'];
            $range_topup_product[$p] = ($range_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
            $range_out_product[$p] = ($range_out_product[$p] ?? 0) + (float)$r['total_out'];
        }
    }
} catch (Throwable $e) { $range_in_product = []; $range_topup_product = []; $range_out_product = []; }

// 所有银行、产品（先取名单，便于用统一 key 做汇总）
$all_banks = [];
$all_products = [];
try {
    $all_banks = $pdo->query("SELECT name FROM banks WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
try {
    $all_products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

try {
    $rows = $pdo->query("SELECT adjust_type, name, initial_balance FROM balance_adjust")->fetchAll();
    foreach ($rows as $r) {
        $base = (float)$r['initial_balance'];
        $name = trim((string)$r['name']);
        $key = strtolower($name);
        if ($r['adjust_type'] === 'bank') {
            $initial_bank[$name] = $base + ($cum_in_bank[$key] ?? 0) - ($cum_out_bank[$key] ?? 0);
        } else {
            $initial_product[$name] = $base - ($cum_in_product[$key] ?? 0) + ($cum_topup_product[$key] ?? 0) + ($cum_out_product[$key] ?? 0);
        }
    }
} catch (Throwable $e) {
    $initial_bank = [];
    $initial_product = [];
}

// 未在 balance_adjust 中设定的：前日 Balance Now = 累计入 - 累计出（key 小写匹配）
foreach ($all_banks as $name) {
    $name = trim((string)$name);
    if ($name === '') continue;
    $key = strtolower($name);
    if (!isset($initial_bank[$name])) $initial_bank[$name] = ($cum_in_bank[$key] ?? 0) - ($cum_out_bank[$key] ?? 0);
}
foreach ($all_products as $name) {
    $name = trim((string)$name);
    if ($name === '') continue;
    $key = strtolower($name);
    if (!isset($initial_product[$name])) $initial_product[$name] = -($cum_in_product[$key] ?? 0) + ($cum_topup_product[$key] ?? 0) + ($cum_out_product[$key] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>statement - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 100%;">
                <div class="page-header">
                    <h2>statement</h2>
                    <p class="breadcrumb">
                        <a href="dashboard.php">首页</a><span>·</span>statement
                    </p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= $err ?></div><?php endif; ?>
                <?php if (!$is_admin): ?><p class="form-hint" style="margin-bottom:12px;">您仅有查看权限，不可修改任何数据。</p><?php endif; ?>

                <div class="card">
                    <p class="form-hint" style="margin-bottom:12px;">显示日期：<?= $day_from ?><?= $is_range ? ' 至 ' . $day_to : '' ?><?= !$is_range && $day_from === date('Y-m-d') ? '（当天）' : '' ?></p>
                    <div class="statement-filter-wrap" style="margin-bottom:16px;">
                        <button type="button" class="btn btn-outline" id="stmt-date-toggle">筛选日期</button>
                        <form method="get" class="stmt-date-form" id="stmt-date-form" style="display:none; margin-top:10px; align-items:center; gap:10px; flex-wrap:wrap;">
                            <label style="font-size:13px;">从</label>
                            <input type="date" name="day_from" id="stmt-day-from" value="<?= htmlspecialchars($day_from) ?>">
                            <label style="font-size:13px;">至</label>
                            <input type="date" name="day_to" id="stmt-day-to" value="<?= htmlspecialchars($day_to) ?>">
                            <button type="submit" class="btn btn-primary">查询</button>
                            <div style="flex-basis:100%; height:0;"></div>
                            <span style="font-size:13px; color:var(--muted);">快捷：</span>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="7">一个星期</button>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="30">一个月</button>
                        </form>
                    </div>
                    <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <div>
                            <h4>Bank</h4>
                            <table class="total-table">
                                <thead>
                                    <tr>
                                        <th>Bank</th>
                                        <?php if ($is_admin): ?><th class="num">Starting Balance</th><th class="num">In</th><th class="num">Out</th><?php endif; ?>
                                        <th class="num">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_banks as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)($range_in_bank[$key] ?? 0);
                                        $out = (float)($range_out_bank[$key] ?? 0);
                                        $init = $initial_bank[$name] ?? 0;
                                        $balance = $init + $in - $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num in"><?= number_format($in, 2) ?></td>
                                        <td class="num out"><?= number_format($out, 2) ?></td>
                                        <?php endif; ?>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($all_banks)): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h4>Game Platform</h4>
                            <table class="total-table">
                                <thead>
                                        <tr>
                                        <th>Game Platform</th>
                                        <?php if ($is_admin): ?><th class="num">Starting</th><th class="num">In</th><th class="num">Topup</th><th class="num">Out</th><?php endif; ?>
                                        <th class="num">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_products as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)($range_in_product[$key] ?? 0);
                                        $topup = (float)($range_topup_product[$key] ?? 0);
                                        $out = (float)($range_out_product[$key] ?? 0);
                                        $init = $initial_product[$name] ?? 0;
                                        $balance = $init - $in + $topup + $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num stmt-in"><?= $in != 0 ? '−' . number_format($in, 2) : '—' ?></td>
                                        <td class="num stmt-topup"><?= $topup != 0 ? number_format($topup, 2) : '—' ?></td>
                                        <td class="num stmt-out"><?= $out != 0 ? number_format($out, 2) : '—' ?></td>
                                        <?php endif; ?>
                                        <td class="num <?= $balance < 0 ? 'stmt-negative' : 'profit' ?>"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($all_products)): ?>
                                    <tr><td colspan="<?= $is_admin ? 6 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
<script>
(function(){
    var btn = document.getElementById('stmt-date-toggle');
    var form = document.getElementById('stmt-date-form');
    if (btn && form) {
        btn.addEventListener('click', function(){
            form.style.display = form.style.display === 'none' ? 'flex' : 'none';
        });
    }
    var fromEl = document.getElementById('stmt-day-from');
    var toEl = document.getElementById('stmt-day-to');
    document.querySelectorAll('.stmt-quick-range').forEach(function(b){
        b.addEventListener('click', function(){
            var days = parseInt(b.getAttribute('data-days'), 10) || 7;
            var end = new Date();
            var start = new Date(end);
            start.setDate(start.getDate() - (days - 1));
            var fmt = function(d) {
                var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            };
            if (fromEl) fromEl.value = fmt(start);
            if (toEl) toEl.value = fmt(end);
            if (form) form.submit();
        });
    });
})();
</script>
</body>
</html>
