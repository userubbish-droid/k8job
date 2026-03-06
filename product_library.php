<?php
require 'config.php';
require 'auth.php';
require_permission('product_library');
$sidebar_current = 'product_library';

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$products = [];
$by_code = [];
$codes = [];
$err = '';

try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}

try {
    $rows = $pdo->query("SELECT a.customer_id, a.product_name, a.account, a.password, c.code
            FROM customer_product_accounts a
            LEFT JOIN customers c ON c.id = a.customer_id
            ORDER BY c.code ASC, a.product_name ASC")->fetchAll();
    foreach ($rows as $r) {
        $code = $r['code'] ?? '';
        if ($code === '') continue;
        if (!isset($by_code[$code])) $by_code[$code] = [];
        $by_code[$code][$r['product_name']] = [
            'account'  => $r['account'] ?? '',
            'password' => $r['password'] ?? '',
            'customer_id' => (int)$r['customer_id'],
        ];
    }
    $codes = array_keys($by_code);
    sort($codes);
    if (empty($products) && !empty($by_code)) {
        $seen = [];
        foreach ($by_code as $list) {
            foreach (array_keys($list) as $pn) { $seen[$pn] = true; }
        }
        $products = array_keys($seen);
        sort($products);
    }
} catch (Throwable $e) {
    $codes = [];
    $err = '无法加载数据，请确认已执行 migrate_customer_products.sql。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>产品账号 - 算账网</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .product-cell { font-size: 13px; white-space: nowrap; }
        .product-cell .id { color: #0f172a; }
        .product-cell .ps { color: var(--muted); margin-top: 2px; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 100%;">
        <div class="page-header">
            <h2>产品账号</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="customers.php">顾客列表</a>
                <?php if (has_permission('customer_create')): ?><span>·</span><a href="customer_create.php">新增顾客</a><?php endif; ?>
                <?php if ($is_admin): ?><span>·</span><a href="admin_products.php">产品管理</a><?php endif; ?>
            </p>
        </div>

        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3>顾客产品资料</h3>
            <p class="form-hint" style="margin-bottom:12px;">第一列为顾客 CODE，后面各列为产品（MEGA、918KISS 等）。每格显示该顾客在该产品下的账号与密码。在「编辑顾客」里添加后在此显示。</p>
            <?php if ($products || $codes): ?>
            <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <?php foreach ($products as $p): ?>
                        <th><?= htmlspecialchars($p) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($codes)): ?>
                    <tr><td colspan="<?= count($products) + 1 ?>">暂无记录。请到「编辑顾客」为顾客添加产品及账号、密码。</td></tr>
                <?php else: ?>
                <?php foreach ($codes as $code): ?>
                    <tr>
                        <?php $first_cell = reset($by_code[$code]); $cid = (int)($first_cell['customer_id'] ?? 0); ?>
                        <td><a href="customer_edit.php?id=<?= $cid ?>"><?= htmlspecialchars($code) ?></a></td>
                        <?php foreach ($products as $p): ?>
                        <td class="product-cell">
                            <?php
                            $cell = $by_code[$code][$p] ?? null;
                            if ($cell && (($cell['account'] ?? '') !== '' || ($cell['password'] ?? '') !== '')):
                                $acc = htmlspecialchars($cell['account'] ?? '');
                                $pwd = ($cell['password'] ?? '') !== '' ? '••••••' : '—';
                            ?>
                            <div class="id">id：<?= $acc ?: '—' ?></div>
                            <div class="ps">ps：<?= $pwd ?></div>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <p class="form-hint">暂无数据。请先在「产品管理」添加产品，再在「编辑顾客」里为顾客添加各产品的账号与密码。</p>
            <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
</body>
</html>
