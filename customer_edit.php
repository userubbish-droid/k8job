<?php
require 'config.php';
require 'auth.php';
require_permission('customer_edit');
$sidebar_current = 'customers';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: customers.php');
    exit;
}

try {
    $row = $pdo->prepare("SELECT id, code, name, phone, remark, register_date, bank_details, created_at, recommend FROM customers WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
} catch (Throwable $e) {
    $stmt = $pdo->prepare("SELECT id, code, name, phone, remark, register_date, bank_details, created_at FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) $row['recommend'] = '';
}
if (!$row) {
    header('Location: customers.php');
    exit;
}

// 产品列表（来自 product 管理）
$products = [];
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}
if (!$products) {
    $products = ['918KISS', 'MEGAB', 'MEGA'];
}

// 该顾客已有的产品账号
$product_accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, account, password FROM customer_product_accounts WHERE customer_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $product_accounts = $stmt->fetchAll();
} catch (Throwable $e) {
    $product_accounts = [];
}

$msg = '';
$err = '';

// 删除产品账号（GET 或 POST，优先处理）
if (isset($_GET['del_account']) || (isset($_POST['action']) && $_POST['action'] === 'del_product' && isset($_POST['account_id']))) {
    $aid = (int)($_GET['del_account'] ?? $_POST['account_id'] ?? 0);
    if ($aid > 0) {
        try {
            $pdo->prepare("DELETE FROM customer_product_accounts WHERE id = ? AND customer_id = ?")->execute([$aid, $id]);
            header("Location: customer_edit.php?id=$id&deleted=1");
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// 添加产品账号（单独表单提交）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $product_name = trim($_POST['product_name'] ?? '');
    $account = trim($_POST['account'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($password === '') $password = 'Aaaa8888';
    if ($product_name === '') {
        $err = '请选择产品。';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO customer_product_accounts (customer_id, product_name, account, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $product_name, $account ?: null, $password]);
            $msg = '已添加产品账号。';
            header("Location: customer_edit.php?id=$id&msg=1");
            exit;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'customer_product_accounts') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                $err = '添加失败：尚未创建产品账号表。请在 phpMyAdmin 中选中数据库，执行 migrate_customer_products.sql 创建 customer_product_accounts 表后再试。';
            } else {
                $err = '添加失败：' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['msg'])) {
    $msg = '已添加产品账号。';
}
if (isset($_GET['deleted'])) {
    $msg = '已删除。';
}
if (isset($_GET['created'])) {
    $msg = '顾客已创建，可在此补充产品账号。';
}

// 主表单：保存顾客基本信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $register_date = trim($_POST['register_date'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $recommend = trim($_POST['recommend'] ?? '');

    if ($code === '') {
        $err = '客户代码不能为空。';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE customers SET code=?, name=?, phone=?, remark=?, register_date=?, bank_details=?, recommend=? WHERE id=?");
            $stmt->execute([$code, $name ?: null, $phone ?: null, $remark ?: null, $register_date ?: null, $bank_details ?: null, $recommend !== '' ? $recommend : null, $id]);
            $msg = '已保存。';
            $row = $pdo->prepare("SELECT id, code, name, phone, remark, register_date, bank_details, created_at, recommend FROM customers WHERE id = ?");
            $row->execute([$id]);
            $row = $row->fetch();
        } catch (Throwable $e) {
            $err = (strpos($e->getMessage(), 'recommend') !== false ? '请先在 phpMyAdmin 执行 migrate_customers_recommend.sql。' : $e->getMessage());
        }
    }
}

// 注册日期若为空则显示创建日期（根据填写时间）
$display_register_date = $row['register_date'] ?? '';
if ($display_register_date === '' && !empty($row['created_at'])) {
    $display_register_date = date('Y-m-d', strtotime($row['created_at']));
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>编辑顾客 - <?= htmlspecialchars($row['code']) ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
        .add-product-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: end; margin-top: 12px; }
        .add-product-row .form-group { margin-bottom: 0; }
        @media (max-width: 640px) { .add-product-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 900px;">
        <div class="page-header">
            <h2>编辑顾客 - <?= htmlspecialchars($row['code']) ?></h2>
            <p class="breadcrumb"><a href="customers.php">← 返回顾客列表</a></p>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <form method="post">
                <div class="grid-3">
                    <div class="form-group">
                        <label>CODE *</label>
                        <input name="code" class="form-control" required value="<?= htmlspecialchars($row['code']) ?>">
                    </div>
                    <div class="form-group">
                        <label>REGISTER DATE</label>
                        <input type="date" name="register_date" class="form-control" value="<?= htmlspecialchars($display_register_date) ?>" title="可根据填写时间自动记录">
                    </div>
                    <div class="form-group">
                        <label>FULL NAME</label>
                        <input name="name" class="form-control" value="<?= htmlspecialchars($row['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>CONTACT</label>
                        <input name="phone" class="form-control" value="<?= htmlspecialchars($row['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>BANK DETAILS</label>
                    <input name="bank_details" class="form-control" value="<?= htmlspecialchars($row['bank_details'] ?? '') ?>" placeholder="TNG 160402395453, PBB 8413574015">
                </div>
                <div class="form-group">
                    <label>REMARK</label>
                    <textarea name="remark" class="form-control" rows="2"><?= htmlspecialchars($row['remark'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Recommend</label>
                    <input name="recommend" class="form-control" value="<?= htmlspecialchars($row['recommend'] ?? '') ?>" placeholder="推荐人/推荐码">
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>

        <div class="card">
            <h3>产品账号</h3>
            <p class="form-hint" style="margin-bottom:12px;">从下方选择产品并填写账号、密码后添加；可在「产品管理」中维护产品选项。</p>

            <form method="post">
                <input type="hidden" name="action" value="add_product">
                <div class="add-product-row">
                    <div class="form-group">
                        <label>产品</label>
                        <select name="product_name" class="form-control" required>
                            <option value="">-- 请选 --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>账号</label>
                        <input name="account" class="form-control" placeholder="账号">
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input name="password" type="password" class="form-control" placeholder="密码">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">添加</button>
                    </div>
                </div>
            </form>

            <?php if ($product_accounts): ?>
            <table class="data-table" style="margin-top:16px;">
            <thead>
                <tr><th>产品</th><th>账号</th><th>密码</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php
            $product_ord = [];
            foreach ($product_accounts as $pa):
                $pn = $pa['product_name'];
                $product_ord[$pn] = ($product_ord[$pn] ?? 0) + 1;
                $suffix = $product_ord[$pn] === 1 ? '' : '~' . $product_ord[$pn];
                $account_display = htmlspecialchars($pa['account'] ?? '') . $suffix;
            ?>
                <tr>
                    <td><?= htmlspecialchars($pn) ?></td>
                    <td><?= $account_display ?></td>
                    <td><?= htmlspecialchars(($pa['password'] ?? '') !== '' ? $pa['password'] : '—') ?></td>
                    <td>
                        <form method="post" class="inline" data-confirm="确定删除这条产品账号？">
                            <input type="hidden" name="action" value="del_product">
                            <input type="hidden" name="account_id" value="<?= (int)$pa['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="form-hint" style="margin-top:16px;">暂无产品账号，请在上方选择产品并填写账号、密码后点击「添加」。</p>
        <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
</body>
</html>

