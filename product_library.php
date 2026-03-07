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
$msg = '';

// 顾客列表（用于 add 表单下拉）
$customers_list = [];
try {
    $customers_list = $pdo->query("SELECT id, code FROM customers WHERE is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}

// 在产品账号页添加产品账号（与编辑顾客页相同功能）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $product_name = trim($_POST['product_name'] ?? '');
    $account = trim($_POST['account'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($password === '') $password = 'Aaaa8888';
    if ($customer_id <= 0 || $product_name === '') {
        $err = '请选择顾客和产品。';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO customer_product_accounts (customer_id, product_name, account, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$customer_id, $product_name, $account ?: null, $password]);
            $msg = '已添加。';
            header("Location: product_library.php?msg=1");
            exit;
        } catch (Throwable $e) {
            $err = '添加失败：' . $e->getMessage();
        }
    }
}
if (isset($_GET['msg'])) {
    $msg = '已添加。';
}
$show_add_box = !empty($err) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product';

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
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
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
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                顾客产品资料
                <button type="button" id="product_lib_add_btn" class="btn btn-outline btn-sm" style="padding:2px 10px; font-size:13px;">add</button>
            </h3>
            <div id="product_lib_add_box" style="display:<?= $show_add_box ? 'block' : 'none' ?>; margin-top:12px; padding:12px; background:#f8fafc; border:1px solid var(--border); border-radius:8px;">
                <form method="post">
                    <input type="hidden" name="action" value="add_product">
                    <div class="form-row-2" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; align-items:end;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>顾客</label>
                            <select name="customer_id" class="form-control" required>
                                <option value="">-- 请选 --</option>
                                <?php foreach ($customers_list as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>产品</label>
                            <select name="product_name" class="form-control" required>
                                <option value="">-- 请选 --</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>账号</label>
                            <input name="account" class="form-control" placeholder="账号">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>密码</label>
                            <input name="password" type="text" class="form-control" placeholder="不填则 Aaaa8888">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <button type="submit" class="btn btn-primary btn-sm">添加</button>
                        </div>
                    </div>
                </form>
            </div>
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
                                $pwd = ($cell['password'] ?? '') !== '' ? htmlspecialchars($cell['password']) : '—';
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
    <script>
    (function(){
        var btn = document.getElementById('product_lib_add_btn');
        var box = document.getElementById('product_lib_add_box');
        if (btn && box) {
            btn.addEventListener('click', function(){ box.style.display = box.style.display === 'none' ? 'block' : 'none'; });
        }
    })();
    </script>
</body>
</html>
