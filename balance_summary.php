<?php
require 'config.php';
require 'auth.php';
require_login();

$sidebar_current = 'balance_summary';
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$day = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day']) ? $_GET['day'] : date('Y-m-d');
$msg = '';
$err = '';

$yesterday = date('Y-m-d', strtotime($day . ' -1 day'));
$by_bank = [];
$by_product = [];
$initial_bank = [];
$initial_product = [];
$yesterday_closing_bank = [];
$yesterday_closing_product = [];

try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '—') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY bank ORDER BY 2 + 3 DESC");
    $stmt->execute([$day]);
    $by_bank = $stmt->fetchAll();
} catch (Throwable $e) { $by_bank = []; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY product ORDER BY 2 + 3 DESC");
    $stmt->execute([$day]);
    $by_product = $stmt->fetchAll();
} catch (Throwable $e) { $by_product = []; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '—') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS closing
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY bank");
    $stmt->execute([$yesterday]);
    foreach ($stmt->fetchAll() as $r) { $yesterday_closing_bank[$r['bank']] = (float)$r['closing']; }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS closing
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY product");
    $stmt->execute([$yesterday]);
    foreach ($stmt->fetchAll() as $r) { $yesterday_closing_product[$r['product']] = (float)$r['closing']; }
} catch (Throwable $e) {}

try {
    $rows = $pdo->query("SELECT adjust_type, name, initial_balance FROM balance_adjust")->fetchAll();
    foreach ($rows as $r) {
        if ($r['adjust_type'] === 'bank') $initial_bank[$r['name']] = (float)$r['initial_balance'];
        else $initial_product[$r['name']] = (float)$r['initial_balance'];
    }
} catch (Throwable $e) {
    $initial_bank = [];
    $initial_product = [];
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
                        初始余额 = 昨日余额；今日余额 = 初始 + 入账 − 出账
                    </p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= $err ?></div><?php endif; ?>
                <?php if (!$is_admin): ?><p class="form-hint" style="margin-bottom:12px;">您仅有查看权限，不可修改任何数据。</p><?php endif; ?>

                <div class="card">
                    <p class="form-hint" style="margin-bottom:12px;">显示日期：<?= $day ?><?= $day === date('Y-m-d') ? '（当天）' : '' ?></p>
                    <div class="statement-filter-wrap" style="margin-bottom:16px;">
                        <button type="button" class="btn btn-outline" id="stmt-date-toggle">筛选日期</button>
                        <form method="get" class="stmt-date-form" id="stmt-date-form" style="display:none; margin-top:10px; align-items:center; gap:8px; flex-wrap:wrap;">
                            <input type="date" name="day" value="<?= htmlspecialchars($day) ?>">
                            <button type="submit" class="btn btn-primary">查询</button>
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
                                        $init = isset($initial_bank[$name]) ? $initial_bank[$name] : ($yesterday_closing_bank[$name] ?? 0);
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
                                        $init = isset($initial_product[$name]) ? $initial_product[$name] : ($yesterday_closing_product[$name] ?? 0);
                                        $balance = $init + $in - $out;
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
                    <p class="form-hint" style="margin-top:12px;">初始 = 昨日余额；余额 = 初始 + 入 − 出。产品 In=－、Out=＋。</p>
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
})();
</script>
</body>
</html>
