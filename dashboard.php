<?php
require 'config.php';
require 'auth.php';
require_login();

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

$db_error = '';
$day_in = $day_out = $day_profit = 0;
$month_in = $month_out = $month_profit = 0;
$pending_count = 0;

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

    if (($_SESSION['user_role'] ?? '') === 'admin') {
        $pending_count = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    }
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
    <style>
        * { box-sizing: border-box; }
        body { font-family: sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .wrap { max-width: 640px; margin: 0 auto; }
        h1 { margin: 0 0 8px; font-size: 1.5rem; color: #333; }
        .user { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { margin: 0 0 16px; font-size: 1rem; color: #666; font-weight: 600; }
        .row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; }
        .row:last-child { margin-bottom: 0; }
        .item { flex: 1; min-width: 120px; }
        .item .label { font-size: 0.85rem; color: #888; margin-bottom: 4px; }
        .item .value { font-size: 1.25rem; font-weight: 600; }
        .item .value.in { color: #28a745; }
        .item .value.out { color: #dc3545; }
        .item .value.profit { color: #007bff; }
        .nav { margin-top: 24px; }
        .nav a { display: inline-block; margin-right: 12px; padding: 10px 16px; background: #007bff; color: #fff; text-decoration: none; border-radius: 6px; font-size: 0.9rem; }
        .nav a:hover { background: #0056b3; }
        .nav a.outline { background: transparent; color: #666; border: 1px solid #ddd; }
        .nav a.outline:hover { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>算账网</h1>
        <p class="user">
            欢迎，<?= htmlspecialchars($_SESSION['user_name'] ?? '用户') ?>
            （<?= htmlspecialchars($_SESSION['user_role'] ?? 'member') ?>）
        </p>

        <?php if ($db_error): ?>
            <div class="card" style="border:1px solid #f5c6cb; background:#f8d7da;">
                <h2 style="color:#721c24;">系统提示：数据库还没升级完成</h2>
                <div style="color:#721c24; font-size: 13px; line-height: 1.6;">
                    <div><b>错误信息</b>：<?= htmlspecialchars($db_error) ?></div>
                    <div style="margin-top:10px;">
                        <b>解决方法</b>：到 Hostinger 的 phpMyAdmin 执行迁移 SQL：<code>migrate_approval.sql</code>（新增 status 等字段）。<br>
                        另外如果你要用客户下拉，也执行：<code>migrate_customers.sql</code>（新增 customers 表）。
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>今日（<?= htmlspecialchars($today) ?>）</h2>
            <div class="row">
                <div class="item">
                    <div class="label">总入 DEPOSIT</div>
                    <div class="value in"><?= number_format((float)$day_in, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">总出 WITHDRAW</div>
                    <div class="value out"><?= number_format((float)$day_out, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">利润</div>
                    <div class="value profit"><?= number_format($day_profit, 2) ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>本月（<?= $month_start ?> ~ <?= $month_end ?>）</h2>
            <div class="row">
                <div class="item">
                    <div class="label">总入</div>
                    <div class="value in"><?= number_format((float)$month_in, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">总出</div>
                    <div class="value out"><?= number_format((float)$month_out, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">利润</div>
                    <div class="value profit"><?= number_format($month_profit, 2) ?></div>
                </div>
            </div>
        </div>

        <div class="nav">
            <a href="transaction_create.php">记一笔流水</a>
            <a href="transaction_list.php">流水列表</a>
            <a href="customers.php" class="outline">客户资料</a>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <a href="admin_users.php" class="outline">用户管理</a>
                <a href="admin_banks.php" class="outline">银行管理</a>
                <a href="admin_products.php" class="outline">产品管理</a>
                <a href="admin_approvals.php" class="outline">待批准<?= $pending_count ? '（' . (int)$pending_count . '）' : '' ?></a>
            <?php endif; ?>
            <a href="logout.php" class="outline">退出</a>
        </div>
    </div>
</body>
</html>
