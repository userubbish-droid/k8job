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
$month_in = $month_out = $month_expenses = $month_profit = 0;
$day_customers_count = 0;
$day_orders_count = 0;
$day_new_customers = 0;
$day_new_customer_orders = 0;

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

    // 本月统计（只统计已批准）：入账、出账、开销（EXPENSE）
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                                  COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out,
                                  COALESCE(SUM(CASE WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS total_expenses
                           FROM transactions WHERE day >= ? AND day <= ? AND status = 'approved'");
    $stmt->execute([$month_start, $month_end]);
    $month = $stmt->fetch();
    $month_in       = (float)($month['total_in'] ?? 0);
    $month_out      = (float)($month['total_out'] ?? 0);
    $month_expenses = (float)($month['total_expenses'] ?? 0);
    $month_profit   = $month_in - $month_out - $month_expenses;

    // 今日上线客户数（今日已批准流水中不重复的顾客 code 数）
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT code) FROM transactions WHERE day = ? AND status = 'approved' AND code IS NOT NULL AND code != ''");
    $stmt->execute([$today]);
    $day_customers_count = (int) $stmt->fetchColumn();
    // 今日单数（今日已批准流水条数）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE day = ? AND status = 'approved'");
    $stmt->execute([$today]);
    $day_orders_count = (int) $stmt->fetchColumn();

    // 几个新顾客（今日新增的顾客数，按 customers 表 created_at）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $day_new_customers = (int) $stmt->fetchColumn();

    // 新客户进多少单（今日已批准流水中，顾客代码属于「今日新增顾客」的条数）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t INNER JOIN customers c ON c.code = t.code AND DATE(c.created_at) = ? WHERE t.day = ? AND t.status = 'approved'");
    $stmt->execute([$today, $today]);
    $day_new_customer_orders = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>首页 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-header dashboard-header">
                <h1>K8 欢迎（<?= htmlspecialchars($_SESSION['user_name'] ?? '用户') ?>）</h1>
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
                <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px;">
                <?php if (($_SESSION['user_role'] ?? '') === 'member'): ?>
                <p class="form-hint" style="margin-top:0; grid-column: 1 / -1;">以下为只读汇总，银行与产品的增删改仅管理员可操作。</p>
                <?php endif; ?>
                    <div>
                        <h4>今日客户与单数</h4>
                        <div class="stat-cards" style="margin-top:8px;">
                            <div class="stat-card" style="border-left-color: var(--primary);">
                                <div class="label">上线客户数</div>
                                <div class="value" style="color: var(--primary);"><?= (int)$day_customers_count ?></div>
                            </div>
                            <div class="stat-card" style="border-left-color: var(--muted);">
                                <div class="label">几张单</div>
                                <div class="value" style="color: #475569;"><?= (int)$day_orders_count ?></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4>今日新顾客与单数</h4>
                        <div class="stat-cards" style="margin-top:8px;">
                            <div class="stat-card" style="border-left-color: var(--success);">
                                <div class="label">几个新顾客</div>
                                <div class="value" style="color: var(--success);"><?= (int)$day_new_customers ?></div>
                            </div>
                            <div class="stat-card" style="border-left-color: var(--primary);">
                                <div class="label">新客户进多少单</div>
                                <div class="value" style="color: var(--primary);"><?= (int)$day_new_customer_orders ?></div>
                            </div>
                        </div>
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
                    <div class="stat-card expense">
                        <div class="label">本月开销</div>
                        <div class="value"><?= number_format((float)$month_expenses, 2) ?></div>
                    </div>
                    <div class="stat-card profit">
                        <div class="label">本月利润</div>
                        <div class="value"><?= number_format($month_profit, 2) ?></div>
                    </div>
                </div>
                <p class="form-hint" style="margin-top:8px; margin-bottom:0;">本月利润 = 入账 − 出账 − 开销</p>
            </div>
        </main>
    </div>
</body>
</html>
