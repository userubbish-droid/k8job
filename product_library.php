<?php
require 'config.php';
require 'auth.php';
require_login();

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$products = [];
$err = '';
try {
    $products = $pdo->query("SELECT id, name, is_active, sort_order FROM products ORDER BY sort_order DESC, name ASC")->fetchAll();
    foreach ($products as &$p) {
        $p['customer_count'] = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM customer_product_accounts WHERE product_name = ?");
            $st->execute([$p['name']]);
            $p['customer_count'] = (int) $st->fetchColumn();
        } catch (Throwable $_) {}
        unset($st);
    }
    unset($p);
} catch (Throwable $e) {
    $products = [];
    $err = '无法加载产品列表，请确认已执行 schema.sql 并已在「产品管理」中添加产品。';
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
            <h3 style="margin:0 0 12px;">产品列表</h3>
            <p class="muted" style="margin-bottom:12px;">以下为在「产品管理」中已添加的产品（如 MEGA、918KISS 等），顾客编辑时可选择这些产品并填写账号与密码。</p>
            <?php if ($products): ?>
            <table>
                <thead>
                    <tr>
                        <th>产品名称</th>
                        <th>状态</th>
                        <th>已关联顾客数</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td>
                            <span class="badge <?= (int)$p['is_active'] === 1 ? 'badge-on' : 'badge-off' ?>">
                                <?= (int)$p['is_active'] === 1 ? '启用' : '停用' ?>
                            </span>
                        </td>
                        <td><span class="count"><?= (int)($p['customer_count'] ?? 0) ?></span> 人</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="muted">暂无产品。<?php if ($is_admin): ?>请到 <a href="admin_products.php">产品管理</a> 添加产品（如 MEGA、918KISS 等）。<?php else: ?>请联系管理员在「产品管理」中添加产品。<?php endif; ?></p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
