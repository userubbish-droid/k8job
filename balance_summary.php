<?php
require 'config.php';
require 'auth.php';
require_login();

$sidebar_current = 'balance_summary';
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$today = date('Y-m-d');
$msg = '';
$err = '';

// 仅 admin 可调整初始余额；member 只能看
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_initial') {
        $type = $_POST['adjust_type'] ?? '';
        $name = trim((string)($_POST['name'] ?? ''));
        $initial = str_replace(',', '', trim((string)($_POST['initial_balance'] ?? '0')));
        if ($name !== '' && in_array($type, ['bank', 'product'], true) && is_numeric($initial)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO balance_adjust (adjust_type, name, initial_balance, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE initial_balance = VALUES(initial_balance), updated_at = NOW(), updated_by = VALUES(updated_by)");
                $stmt->execute([$type, $name, (float)$initial, (int)($_SESSION['user_id'] ?? 0)]);
                $msg = '已保存初始余额。';
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'balance_adjust') !== false && (strpos($e->getMessage(), "doesn't exist") !== false)) {
                    $err = '请先在 phpMyAdmin 执行 migrate_balance_adjust.sql 创建 balance_adjust 表。';
                } else {
                    $err = '保存失败：' . $e->getMessage();
                }
            }
        }
    }
}

$by_bank = [];
$by_product = [];
$initial_bank = [];
$initial_product = [];

try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '—') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY bank ORDER BY 2 + 3 DESC");
    $stmt->execute([$today]);
    $by_bank = $stmt->fetchAll();
} catch (Throwable $e) { $by_bank = []; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE day = ? AND status = 'approved'
        GROUP BY product ORDER BY 2 + 3 DESC");
    $stmt->execute([$today]);
    $by_product = $stmt->fetchAll();
} catch (Throwable $e) { $by_product = []; }

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
    <title>余额汇总 - 算账网</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 100%;">
                <div class="page-header">
                    <h2>余额汇总</h2>
                    <p class="breadcrumb">
                        <a href="dashboard.php">首页</a><span>·</span>
                        银行与产品：余额 = 初始余额 + 入账 − 出账（仅今日流水）
                    </p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= $err ?></div><?php endif; ?>

                <div class="card">
                    <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <div>
                            <h4>银行目前有多少（今日）</h4>
                            <table class="total-table">
                                <thead>
                                    <tr>
                                        <th>银行/渠道</th>
                                        <?php if ($is_admin): ?><th class="num">初始余额</th><?php endif; ?>
                                        <th class="num">入账</th>
                                        <th class="num">出账</th>
                                        <th class="num">余额</th>
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
                                        <td class="num">
                                            <form method="post" class="inline" style="display:inline-flex;align-items:center;gap:6px;">
                                                <input type="hidden" name="action" value="save_initial">
                                                <input type="hidden" name="adjust_type" value="bank">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                                                <input type="text" name="initial_balance" value="<?= sprintf('%.2f', $init) ?>" class="form-control" style="width:90px;padding:6px 8px;text-align:right;">
                                                <button type="submit" class="btn btn-sm btn-primary">改</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                        <td class="num in"><?= number_format($in, 2) ?></td>
                                        <td class="num out"><?= number_format($out, 2) ?></td>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($by_bank)): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 4 ?>">暂无今日银行流水</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h4>产品剩下多少（今日）</h4>
                            <table class="total-table">
                                <thead>
                                    <tr>
                                        <th>产品</th>
                                        <?php if ($is_admin): ?><th class="num">初始余额</th><?php endif; ?>
                                        <th class="num">入账</th>
                                        <th class="num">出账</th>
                                        <th class="num">余额</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($by_product as $r):
                                        $name = $r['product'] ?? '—';
                                        $in = (float)($r['total_in'] ?? 0);
                                        $out = (float)($r['total_out'] ?? 0);
                                        $init = $initial_product[$name] ?? 0;
                                        $balance = $init + $in - $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num">
                                            <form method="post" class="inline" style="display:inline-flex;align-items:center;gap:6px;">
                                                <input type="hidden" name="action" value="save_initial">
                                                <input type="hidden" name="adjust_type" value="product">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                                                <input type="text" name="initial_balance" value="<?= sprintf('%.2f', $init) ?>" class="form-control" style="width:90px;padding:6px 8px;text-align:right;">
                                                <button type="submit" class="btn btn-sm btn-primary">改</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                        <td class="num in"><?= number_format($in, 2) ?></td>
                                        <td class="num out"><?= number_format($out, 2) ?></td>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($by_product)): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 4 ?>">暂无今日产品流水</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p class="form-hint" style="margin-top:12px;">说明：初始余额由管理员在表中填写（如银行目前 5000、顾客今日进 300 出 400，则余额 = 5000 + 300 − 400 = 4900）。入账/出账为今日已批准流水汇总。</p>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
