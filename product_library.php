<?php
require 'config.php';
require 'auth.php';
require_login();

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$rows = [];
$err = '';
try {
    $sql = "SELECT a.id, a.customer_id, a.product_name, a.account, a.password, c.code
            FROM customer_product_accounts a
            LEFT JOIN customers c ON c.id = a.customer_id
            ORDER BY c.code ASC, a.product_name ASC";
    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $err = '无法加载数据，请确认已执行 migrate_customer_products.sql 创建 customer_product_accounts 表。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>顾客产品资料库 - 算账网</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-wrap" style="max-width: 960px;">
        <div class="page-header">
            <h2>顾客产品资料库</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="customers.php">顾客资料</a><span>·</span>
                <a href="customer_create.php">填写顾客资料</a>
                <?php if ($is_admin): ?><span>·</span><a href="admin_products.php">产品管理</a><?php endif; ?>
            </p>
        </div>

        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3>顾客产品资料</h3>
            <p class="form-hint" style="margin-bottom:12px;">格式：顾客代码 CODE / 产品（MEGA、918KISS 等）/ 账号 / 密码。在「编辑顾客」中为顾客添加产品账号后，会在此显示。</p>
            <?php if ($rows): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE（顾客代码）</th>
                        <th>产品（MEGA / 918KISS 等）</th>
                        <th>账号</th>
                        <th>密码</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="customer_edit.php?id=<?= (int)$r['customer_id'] ?>"><?= htmlspecialchars($r['code'] ?? '-') ?></a></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td><?= htmlspecialchars($r['account'] ?? '') ?></td>
                        <td><?= ($r['password'] !== null && $r['password'] !== '') ? '••••••' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="form-hint">暂无记录。请先在 <a href="customers.php">顾客资料</a> 中进入某顾客的编辑页，为其添加产品（如 MEGA、918KISS）及账号、密码后，数据会显示在此。</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
