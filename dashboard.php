<?php
require 'config.php';
require 'auth.php';
require_login();

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

$sidebar_current = 'dashboard';
$db_error = '';
$day_in = $day_out = $day_profit = 0;
$month_in = $month_out = $month_profit = 0;
$by_product_today = [];
$by_bank_today = [];

try {
    // 今日统计（只统计已批准）
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                                  COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                           FROM transactions WHERE day = ? AND status = 'approved'");
    $stmt->execute([$today]);
    $day = $stmt->fetch();
    $day_in   = (float)($day['total_in'] ?? 0);
    $day_out  = (float)($day['total_out'] ?? 0);
    $day_profit = $day_in - $day_out;

    // 本月统计（只统计已批准）
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                                  COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                           FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved'");
    $stmt->execute([$month_start, $month_end]);
    $month = $stmt->fetch();
    $month_in    = (float)($month['total_in'] ?? 0);
    $month_out   = (float)($month['total_out'] ?? 0);
    $month_profit = $month_in - $month_out;

    // 今日按产品汇总（已批准）
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
                           COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                           COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                           FROM transactions WHERE day = ? AND status = 'approved'
                           GROUP BY product ORDER BY 2 + 3 DESC");
    $stmt->execute([$today]);
    $by_product_today = $stmt->fetchAll();

    // 今日按银行/渠道汇总（已批准）
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '—') AS bank,
                           COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                           COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                           FROM transactions WHERE day = ? AND status = 'approved'
                           GROUP BY bank ORDER BY 2 + 3 DESC");
    $stmt->execute([$today]);
    $by_bank_today = $stmt->fetchAll();
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>首页 - 算账网</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-header dashboard-header">
                <h1>算账网</h1>
                <p class="welcome-role">
                    欢迎，<strong><?= htmlspecialchars($_SESSION['user_name'] ?? '用户') ?></strong>
                    <span class="role-badge"><?= ($_SESSION['user_role'] ?? '') === 'admin' ? '管理员' : '员工' ?></span>
                </p>
            </div>

            <?php if ($db_error): ?>
                <div class="card alert-error">
                    <h3 style="margin-top:0; color:#991b1b;">系统提示：数据库还没升级完成</h3>
                    <div style="font-size: 13px; line-height: 1.6;">
                        <div><b>错误信息</b>：<?= htmlspecialchars($db_error) ?></div>
                        <div style="margin-top:10px;">
                            <b>解决方法</b>：到 Hostinger 的 phpMyAdmin 执行迁移 SQL：<code>migrate_approval.sql</code>（新增 status 等字段）。<br>
                            另外如果你要用客户下拉，也执行：<code>migrate_customers.sql</code>（新增 customers 表）。
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>今日（<?= htmlspecialchars($today) ?>）</h3>
                <div class="stat-cards">
                    <div class="stat-card in">
                        <div class="label">今日入账</div>
                        <div class="value"><?= number_format((float)$day_in, 2) ?></div>
                    </div>
                    <div class="stat-card out">
                        <div class="label">今日出账</div>
                        <div class="value"><?= number_format((float)$day_out, 2) ?></div>
                    </div>
                    <div class="stat-card profit">
                        <div class="label">今日利润</div>
                        <div class="value"><?= number_format($day_profit, 2) ?></div>
                    </div>
                </div>
                <?php if (!empty($by_product_today) || !empty($by_bank_today)): ?>
                <?php if (($_SESSION['user_role'] ?? '') === 'member'): ?>
                <p class="form-hint" style="margin-top:12px;">以下为只读汇总，银行与产品的增删改仅管理员可操作。您可查看银行目前多少、产品剩下多少。</p>
                <?php endif; ?>
                <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <?php if (!empty($by_product_today)): ?>
                    <div>
                        <h4>产品剩下多少（今日）</h4>
                        <table class="total-table">
                            <thead><tr><th>产品</th><th class="num">入账</th><th class="num">出账</th><th class="num">利润</th></tr></thead>
                            <tbody>
                            <?php foreach ($by_product_today as $r): $pi = (float)($r['total_in'] ?? 0); $po = (float)($r['total_out'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['product'] ?? '—') ?></td>
                                    <td class="num in"><?= number_format($pi, 2) ?></td>
                                    <td class="num out"><?= number_format($po, 2) ?></td>
                                    <td class="num profit"><?= number_format($pi - $po, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($by_bank_today)): ?>
                    <div>
                        <h4>银行目前有多少（今日）</h4>
                        <table class="total-table">
                            <thead><tr><th>银行/渠道</th><th class="num">入账</th><th class="num">出账</th><th class="num">利润</th></tr></thead>
                            <tbody>
                            <?php foreach ($by_bank_today as $r): $bi = (float)($r['total_in'] ?? 0); $bo = (float)($r['total_out'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['bank'] ?? '—') ?></td>
                                    <td class="num in"><?= number_format($bi, 2) ?></td>
                                    <td class="num out"><?= number_format($bo, 2) ?></td>
                                    <td class="num profit"><?= number_format($bi - $bo, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <label class="month-toggle">
                <input type="checkbox" id="show_month" onchange="document.getElementById('month-card').classList.toggle('visible', this.checked)">
                显示本月数据
            </label>
            <div class="card" id="month-card">
                <h3>本月（<?= $month_start ?> ~ <?= $month_end ?>）</h3>
                <div class="stat-cards">
                    <div class="stat-card in">
                        <div class="label">本月入账</div>
                        <div class="value"><?= number_format((float)$month_in, 2) ?></div>
                    </div>
                    <div class="stat-card out">
                        <div class="label">本月出账</div>
                        <div class="value"><?= number_format((float)$month_out, 2) ?></div>
                    </div>
                    <div class="stat-card profit">
                        <div class="label">本月利润</div>
                        <div class="value"><?= number_format($month_profit, 2) ?></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
