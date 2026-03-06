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
            width: 220px;
            min-width: 220px;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            border-right: 1px solid var(--border);
            padding: 16px 0;
            flex-shrink: 0;
        }
        .dashboard-sidebar .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            margin: 2px 10px;
            color: #475569;
            text-decoration: none;
            font-size: 14px;
            border-radius: 8px;
            border-left: 3px solid transparent;
            transition: background 0.15s, color 0.15s;
        }
        .dashboard-sidebar .nav-item .nav-icon {
            width: 20px; height: 20px; margin-right: 10px;
            border-radius: 4px;
            background: #e2e8f0;
            flex-shrink: 0;
        }
        .dashboard-sidebar .nav-item.primary .nav-icon { background: var(--primary); opacity: 0.9; }
        .dashboard-sidebar .nav-item:hover { background: #fff; color: var(--primary); box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dashboard-sidebar .nav-item.primary { background: #fff; color: var(--primary); font-weight: 600; border-left-color: var(--primary); box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dashboard-main { flex: 1; padding: 24px; overflow: auto; background: #fff; }
        .stat-cards { display: flex; flex-wrap: wrap; gap: 16px; }
        .stat-card {
            flex: 1; min-width: 120px;
            padding: 16px 20px;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            background: #fff;
        }
        .stat-card.in { border-left: 4px solid var(--success); background: linear-gradient(135deg, #f0fdf4 0%, #fff 100%); }
        .stat-card.out { border-left: 4px solid var(--danger); background: linear-gradient(135deg, #fef2f2 0%, #fff 100%); }
        .stat-card.profit { border-left: 4px solid var(--primary); background: linear-gradient(135deg, #eff6ff 0%, #fff 100%); }
        .stat-card .label { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .stat-card .value { font-size: 1.4rem; font-weight: 700; }
        .stat-card.in .value { color: var(--success); }
        .stat-card.out .value { color: var(--danger); }
        .stat-card.profit .value { color: var(--primary); }
        .month-toggle { margin-bottom: 16px; font-size: 14px; color: #64748b; display: flex; align-items: center; gap: 8px; }
        .month-toggle input { cursor: pointer; width: 18px; height: 18px; }
        #month-card { display: none; }
        #month-card.visible { display: block; }
        .welcome-role { font-size: 13px; color: var(--muted); }
        .welcome-role .role-badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; background: var(--primary-light); color: var(--primary); }
        @media (max-width: 768px) {
            .dashboard-layout { flex-direction: column; }
            .dashboard-sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--border); padding: 12px; display: flex; flex-wrap: wrap; gap: 6px; }
            .dashboard-sidebar .nav-item { padding: 12px 14px; min-height: 44px; margin: 0; border-left: none; border-radius: 8px; }
            .dashboard-main { padding: 16px; }
            .stat-cards { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <aside class="dashboard-sidebar">
            <?php if (has_permission('transaction_create')): ?><a href="transaction_create.php" class="nav-item primary"><span class="nav-icon"></span>记一笔</a><?php endif; ?>
            <?php if (has_permission('transaction_list')): ?><a href="transaction_list.php" class="nav-item"><span class="nav-icon"></span>流水记录</a><?php endif; ?>
            <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item"><span class="nav-icon"></span>顾客列表</a><?php endif; ?>
            <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item"><span class="nav-icon"></span>产品账号</a><?php endif; ?>
            <?php if (has_permission('customer_create')): ?><a href="customer_create.php" class="nav-item"><span class="nav-icon"></span>新增顾客</a><?php endif; ?>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <a href="admin_users.php" class="nav-item"><span class="nav-icon"></span>账号管理</a>
                <a href="admin_banks.php" class="nav-item"><span class="nav-icon"></span>银行/渠道</a>
                <a href="admin_products.php" class="nav-item"><span class="nav-icon"></span>产品管理</a>
                <a href="admin_option_sets.php" class="nav-item"><span class="nav-icon"></span>选项设置</a>
                <a href="admin_permissions.php" class="nav-item"><span class="nav-icon"></span>员工权限</a>
                <a href="admin_approvals.php" class="nav-item"><span class="nav-icon"></span>待审核<?= $pending_count ? '（' . (int)$pending_count . '）' : '' ?></a>
            <?php endif; ?>
            <a href="logout.php" class="nav-item"><span class="nav-icon"></span>退出登录</a>
        </aside>

        <main class="dashboard-main">
            <div class="page-header">
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
