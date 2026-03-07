<?php
require 'config.php';
require 'auth.php';
require_login();

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

// Statement 的 Starting Balance = 区间前一天收盘的 Balance Now
$cum_in_bank = [];
$cum_out_bank = [];
$cum_in_product = [];
$cum_out_product = [];
try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS to
        FROM transactions WHERE day < ? AND status = 'approved' AND bank IS NOT NULL AND TRIM(bank) != '' GROUP BY bank");
    $stmt->execute([$day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $b = trim((string)$r['bank']);
        if ($b !== '') {
            $cum_in_bank[$b] = (float)$r['ti'];
            $cum_out_bank[$b] = (float)$r['to'];
        }
    }
} catch (Throwable $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS to
        FROM transactions WHERE day < ? AND status = 'approved' GROUP BY product");
    $stmt->execute([$day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $cum_in_product[$r['product']] = (float)$r['ti'];
        $cum_out_product[$r['product']] = (float)$r['to'];
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved' AND bank IS NOT NULL AND TRIM(bank) != ''
        GROUP BY bank ORDER BY 2 + 3 DESC");
    $stmt->execute([$day_from, $day_to]);
    $by_bank = $stmt->fetchAll();
} catch (Throwable $e) { $by_bank = []; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved'
        GROUP BY product ORDER BY 2 + 3 DESC");
    $stmt->execute([$day_from, $day_to]);
    $by_product = $stmt->fetchAll();
} catch (Throwable $e) { $by_product = []; }

try {
    $rows = $pdo->query("SELECT adjust_type, name, initial_balance FROM balance_adjust")->fetchAll();
    foreach ($rows as $r) {
        $base = (float)$r['initial_balance'];
        $name = $r['name'];
        if ($r['adjust_type'] === 'bank') {
            $initial_bank[$name] = $base + ($cum_in_bank[$name] ?? 0) - ($cum_out_bank[$name] ?? 0);
        } else {
            $initial_product[$name] = $base - ($cum_in_product[$name] ?? 0) + ($cum_out_product[$name] ?? 0);
        }
    }
} catch (Throwable $e) {
    $initial_bank = [];
    $initial_product = [];
}
// 未在 balance_adjust 中设定的银行/产品：前日 Balance Now = 0 + 累计入 - 累计出
foreach ($by_bank as $r) {
    $name = $r['bank'] ?? '—';
    if (!isset($initial_bank[$name])) $initial_bank[$name] = ($cum_in_bank[$name] ?? 0) - ($cum_out_bank[$name] ?? 0);
}
foreach ($by_product as $r) {
    $name = $r['product'] ?? '—';
    if (!isset($initial_product[$name])) $initial_product[$name] = -($cum_in_product[$name] ?? 0) + ($cum_out_product[$name] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>statement - 算账网</title>
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
                        <a href="dashboard.php">首页</a><span>·</span>
                        Starting Balance = 前一天的 Balance Now；本页 <strong>Balance</strong> = 当日收盘余额，与「银行与产品」页当日的 Balance Now 一致
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
                                    <?php foreach ($by_bank as $r):
                                        $name = $r['bank'] ?? '—';
                                        $in = (float)($r['total_in'] ?? 0);
                                        $out = (float)($r['total_out'] ?? 0);
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
                                    <?php if (empty($by_bank)): ?>
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
                                        <?php if ($is_admin): ?><th class="num">Starting</th><th class="num">In</th><th class="num">Out</th><?php endif; ?>
                                        <th class="num">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($by_product as $r):
                                        $name = $r['product'] ?? '—';
                                        $in = (float)($r['total_in'] ?? 0);
                                        $out = (float)($r['total_out'] ?? 0);
                                        $init = $initial_product[$name] ?? 0;
                                        $balance = $init - $in + $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num out"><?= $in != 0 ? '−' . number_format($in, 2) : '—' ?></td>
                                        <td class="num in"><?= $out != 0 ? '+' . number_format($out, 2) : '—' ?></td>
                                        <?php endif; ?>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($by_product)): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p class="form-hint" style="margin-top:12px;">Starting Balance = 前一天收盘的 Balance Now。本页 <strong>Balance</strong> = 当日收盘余额，即「银行与产品」里该日的 Balance Now（Starting Balance + 累计入 − 累计出）。产品 In=－、Out=＋。</p>
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
