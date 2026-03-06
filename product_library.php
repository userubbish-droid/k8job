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
    <style>
        body { font-family: sans-serif; margin: 20px; background: #f5f5f5; }
        .wrap { max-width: 900px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; }
        th { background: #e8f4fc; font-weight: 600; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .badge-on { background: #d4edda; color: #155724; }
        .badge-off { background: #f8d7da; color: #721c24; }
        .count { font-weight: 600; color: #0d6efd; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2 style="margin:0 0 8px;">顾客产品资料库</h2>
        <p class="muted">
            <a href="dashboard.php">返回首页</a>
            | <a href="customers.php">顾客资料</a>
            | <a href="customer_create.php">填写顾客资料</a>
            <?php if ($is_admin): ?> | <a href="admin_products.php">产品管理（添加/编辑产品）</a><?php endif; ?>
        </p>

        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 12px;">顾客产品资料</h3>
            <p class="muted" style="margin-bottom:12px;">格式：顾客代码 CODE / 产品（MEGA、918KISS 等）/ 账号 / 密码。在「编辑顾客」中为顾客添加产品账号后，会在此显示。</p>
            <?php if ($rows): ?>
            <table>
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
            <p class="muted">暂无记录。请先在 <a href="customers.php">顾客资料</a> 中进入某顾客的编辑页，为其添加产品（如 MEGA、918KISS）及账号、密码后，数据会显示在此。</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
