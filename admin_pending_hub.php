<?php
/**
 * 流水与客户同时有待审项时，铃铛指向此页，便于分流到对应审核页。
 */
require 'config.php';
require 'auth.php';
require_admin();

$sidebar_current = 'admin_pending_hub';
$company_id = current_company_id();
$role_sa = ($_SESSION['user_role'] ?? '') === 'superadmin';

$cnt_tx = 0;
$cnt_cust = 0;
try {
    $has_deleted_at = true;
    try {
        $pdo->query("SELECT deleted_at FROM transactions LIMIT 0");
    } catch (Throwable $e) {
        $has_deleted_at = false;
    }
    $del = $has_deleted_at ? " AND deleted_at IS NULL" : "";
    if ($company_id === 0 && $role_sa) {
        $cnt_tx = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'{$del}")->fetchColumn();
        try {
            $cnt_cust = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'pending'")->fetchColumn();
        } catch (Throwable $e) {
            $cnt_cust = 0;
        }
    } elseif ($company_id > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND status = 'pending'{$del}");
        $st->execute([$company_id]);
        $cnt_tx = (int) $st->fetchColumn();
        try {
            $stc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND status = 'pending'");
            $stc->execute([$company_id]);
            $cnt_cust = (int) $stc->fetchColumn();
        } catch (Throwable $e) {
            $cnt_cust = 0;
        }
    }
} catch (Throwable $e) {
}

if ($cnt_tx <= 0 && $cnt_cust <= 0) {
    header('Location: dashboard.php');
    exit;
}

// 仅一种待审时直接跳转，少点一次
if ($cnt_tx > 0 && $cnt_cust <= 0) {
    header('Location: admin_approvals.php');
    exit;
}
if ($cnt_cust > 0 && $cnt_tx <= 0) {
    header('Location: admin_customer_approvals.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>待处理 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 640px;">
            <div class="page-header">
                <h2>待处理</h2>
                <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span>请选择要处理的类型</p>
            </div>
            <div class="card" style="margin-bottom: 16px;">
                <h3 style="margin-top:0;">流水待审核</h3>
                <p style="margin: 8px 0 12px; color: var(--muted);">当前待审：<strong><?= (int)$cnt_tx ?></strong> 笔</p>
                <a class="btn btn-primary" href="admin_approvals.php">前往审核流水</a>
            </div>
            <div class="card">
                <h3 style="margin-top:0;">客户资料待审核</h3>
                <p style="margin: 8px 0 12px; color: var(--muted);">当前待审：<strong><?= (int)$cnt_cust ?></strong> 位</p>
                <a class="btn btn-primary" href="admin_customer_approvals.php">前往审核客户</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
