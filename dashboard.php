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
    <link rel="stylesheet" href="style.css">
    <style>
        .dashboard-layout { display: flex; min-height: 100vh; }
        .dashboard-sidebar {
            width: 200px;
            min-width: 200px;
            background: #fff;
            border-right: 1px solid var(--border);
            padding: 20px 0;
            flex-shrink: 0;
        }
        .dashboard-sidebar .nav-item {
            display: block;
            padding: 10px 20px;
            color: #475569;
            text-decoration: none;
            font-size: 14px;
            border-left: 3px solid transparent;
        }
        .dashboard-sidebar .nav-item:hover { background: #f8fafc; color: var(--primary); }
        .dashboard-sidebar .nav-item.primary { color: var(--primary); font-weight: 600; border-left-color: var(--primary); }
        .dashboard-main { flex: 1; padding: 24px; overflow: auto; }
        .stat-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 12px; }
        .stat-row:last-child { margin-bottom: 0; }
        .stat-item { flex: 1; min-width: 100px; }
        .stat-item .label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .stat-item .value { font-size: 1.35rem; font-weight: 700; }
        .stat-item .value.in { color: var(--success); }
        .stat-item .value.out { color: var(--danger); }
        .stat-item .value.profit { color: var(--primary); }
        .month-toggle { margin-bottom: 16px; font-size: 14px; color: #64748b; }
        .month-toggle input { margin-right: 8px; cursor: pointer; }
        #month-card { display: none; }
        #month-card.visible { display: block; }
        @media (max-width: 768px) {
            .dashboard-layout { flex-direction: column; }
            .dashboard-sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--border); padding: 12px; display: flex; flex-wrap: wrap; gap: 8px; }
            .dashboard-sidebar .nav-item { padding: 8px 12px; border-left: none; border-radius: 6px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <aside class="dashboard-sidebar">
            <?php if (has_permission('transaction_create')): ?><a href="transaction_create.php" class="nav-item primary">记一笔流水</a><?php endif; ?>
            <?php if (has_permission('transaction_list')): ?><a href="transaction_list.php" class="nav-item">流水列表</a><?php endif; ?>
            <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item">客户资料</a><?php endif; ?>
            <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item">顾客产品资料库</a><?php endif; ?>
            <?php if (has_permission('customer_create')): ?><a href="customer_create.php" class="nav-item">填写顾客资料</a><?php endif; ?>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <a href="admin_users.php" class="nav-item">用户管理</a>
                <a href="admin_banks.php" class="nav-item">银行管理</a>
                <a href="admin_products.php" class="nav-item">产品管理</a>
                <a href="admin_option_sets.php" class="nav-item">选项设置</a>
                <a href="admin_permissions.php" class="nav-item">权限设置</a>
                <a href="admin_approvals.php" class="nav-item">待批准<?= $pending_count ? '（' . (int)$pending_count . '）' : '' ?></a>
            <?php endif; ?>
            <a href="logout.php" class="nav-item">退出</a>
        </aside>

        <main class="dashboard-main">
            <div class="page-header">
                <h1>算账网</h1>
                <p class="breadcrumb">
                    欢迎，<?= htmlspecialchars($_SESSION['user_name'] ?? '用户') ?>
                    <span>（<?= htmlspecialchars($_SESSION['user_role'] ?? 'member') ?>）</span>
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
                <div class="stat-row">
                    <div class="stat-item">
                        <div class="label">总入 DEPOSIT</div>
                        <div class="value in"><?= number_format((float)$day_in, 2) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">总出 WITHDRAW</div>
                        <div class="value out"><?= number_format((float)$day_out, 2) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">利润</div>
                        <div class="value profit"><?= number_format($day_profit, 2) ?></div>
                    </div>
                </div>
            </div>

            <label class="month-toggle">
                <input type="checkbox" id="show_month" onchange="document.getElementById('month-card').classList.toggle('visible', this.checked)">
                显示本月统计
            </label>
            <div class="card" id="month-card">
                <h3>本月（<?= $month_start ?> ~ <?= $month_end ?>）</h3>
                <div class="stat-row">
                    <div class="stat-item">
                        <div class="label">总入</div>
                        <div class="value in"><?= number_format((float)$month_in, 2) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">总出</div>
                        <div class="value out"><?= number_format((float)$month_out, 2) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">利润</div>
                        <div class="value profit"><?= number_format($month_profit, 2) ?></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
