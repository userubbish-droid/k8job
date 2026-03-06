<?php
require 'config.php';
require 'auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: customers.php');
    exit;
}

$row = $pdo->prepare("SELECT id, code, name, phone, remark, register_date, bank_details, created_at FROM customers WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch();
if (!$row) {
    header('Location: customers.php');
    exit;
}

// 产品列表（来自 product 管理）
$products = [];
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
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
    if ($product_name === '') {
        $err = '请选择产品。';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO customer_product_accounts (customer_id, product_name, account, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $product_name, $account ?: null, $password ?: null]);
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

    if ($code === '') {
        $err = '客户代码不能为空。';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE customers SET code=?, name=?, phone=?, remark=?, register_date=?, bank_details=? WHERE id=?");
            $stmt->execute([$code, $name ?: null, $phone ?: null, $remark ?: null, $register_date ?: null, $bank_details ?: null, $id]);
            $msg = '已保存。';
            $row = $pdo->prepare("SELECT id, code, name, phone, remark, register_date, bank_details, created_at FROM customers WHERE id = ?");
            $row->execute([$id]);
            $row = $row->fetch();
        } catch (Throwable $e) {
            $err = $e->getMessage();
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
    <title>编辑顾客 - <?= htmlspecialchars($row['code']) ?></title>
    <style>
        body { font-family: sans-serif; margin: 20px; max-width: 900px; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; font-size: 13px; }
        input, select, textarea { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 16px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; margin-top: 12px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        a { color: #007bff; }
        .product-accounts { margin-top: 16px; }
        .product-accounts table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .product-accounts th, .product-accounts td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .product-accounts th { background: #e8f4fc; }
        .add-product-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 8px; align-items: end; margin-top: 8px; }
        .add-product-row label { margin-top: 0; }
        .btn-sm { padding: 4px 10px; font-size: 12px; background: #dc3545; color: #fff; border-radius: 4px; text-decoration: none; display: inline-block; }
        .btn-sm:hover { background: #c82333; color: #fff; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin:0 0 8px;">编辑顾客 - <?= htmlspecialchars($row['code']) ?></h2>
        <p><a href="customers.php">← 返回顾客资料</a></p>
        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post">
            <div class="grid">
                <div><label>CODE *</label><input name="code" required value="<?= htmlspecialchars($row['code']) ?>"></div>
                <div><label>REGISTER DATE</label><input type="date" name="register_date" value="<?= htmlspecialchars($display_register_date) ?>" title="可根据填写时间自动记录"></div>
                <div><label>FULL NAME</label><input name="name" value="<?= htmlspecialchars($row['name'] ?? '') ?>"></div>
                <div><label>CONTACT</label><input name="phone" value="<?= htmlspecialchars($row['phone'] ?? '') ?>"></div>
            </div>
            <div style="margin-top:12px;"><label>BANK DETAILS</label><input name="bank_details" value="<?= htmlspecialchars($row['bank_details'] ?? '') ?>" placeholder="TNG 160402395453, PBB 8413574015"></div>
            <div style="margin-top:12px;"><label>REMARK</label><textarea name="remark" rows="2"><?= htmlspecialchars($row['remark'] ?? '') ?></textarea></div>
            <button type="submit">保存</button>
        </form>
    </div>

    <div class="card product-accounts">
        <h3 style="margin:0 0 8px;">产品账号</h3>
        <p class="muted" style="font-size:12px; color:#666; margin:0 0 8px;">从下方选择产品并填写账号、密码后添加；可在「产品管理」中维护产品选项。</p>

        <form method="post">
            <input type="hidden" name="action" value="add_product">
            <div class="add-product-row">
                <div>
                    <label>产品</label>
                    <select name="product_name" required>
                        <option value="">— 请选择 —</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>账号</label><input name="account" placeholder="账号"></div>
                <div><label>密码</label><input name="password" type="password" placeholder="密码"></div>
                <div><button type="submit">添加</button></div>
            </div>
        </form>

        <?php if ($product_accounts): ?>
        <table style="margin-top:12px;">
            <thead>
                <tr><th>产品</th><th>账号</th><th>密码</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($product_accounts as $pa): ?>
                <tr>
                    <td><?= htmlspecialchars($pa['product_name']) ?></td>
                    <td><?= htmlspecialchars($pa['account'] ?? '') ?></td>
                    <td><?= $pa['password'] !== '' && $pa['password'] !== null ? '••••••' : '—' ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确定删除这条产品账号？');">
                            <input type="hidden" name="action" value="del_product">
                            <input type="hidden" name="account_id" value="<?= (int)$pa['id'] ?>">
                            <button type="submit" class="btn-sm">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="margin-top:12px; color:#888; font-size:13px;">暂无产品账号，请在上方选择产品并填写账号、密码后点击「添加」。</p>
        <?php endif; ?>
    </div>
</body>
</html>
