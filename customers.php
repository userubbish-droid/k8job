<?php
require 'config.php';
require 'auth.php';
require_permission('customers');

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE customers SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$summary = ['total' => 0, 'active' => 0];
$rows = [];
try {
    $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $summary['active'] = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
    $sql = "SELECT c.id, c.code, c.name, c.phone, c.remark, c.is_active, c.created_at,
                   c.register_date, c.bank_details, c.regular_customer, c.verify
            FROM customers c
            ORDER BY c.is_active DESC, c.code ASC";
    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $err = $err ?: '请先在 phpMyAdmin 执行 migrate_customers_detail.sql。' . ' (' . $e->getMessage() . ')';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>顾客资料 - 算账网</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-wrap">
        <div class="page-header">
            <h2>顾客资料</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a>
                <?php if (has_permission('transaction_create')): ?><span>·</span><a href="transaction_create.php">去记一笔</a><?php endif; ?>
                <?php if (has_permission('customer_create')): ?><span>·</span><a href="customer_create.php">填写顾客资料</a><?php endif; ?>
                <?php if (has_permission('product_library')): ?><span>·</span><a href="product_library.php">顾客产品资料库</a><?php endif; ?>
                <?php if ($is_admin): ?><span>·</span><a href="admin_option_sets.php">选项设置</a><?php endif; ?>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="summary">
            <div class="summary-item"><strong>T1. Customer</strong><span class="num"><?= $summary['total'] ?></span></div>
            <div class="summary-item"><strong>Active Member</strong><span class="num"><?= $summary['active'] ?></span></div>
            <div class="summary-item"><strong>TARGET</strong><span class="num">1</span></div>
        </div>

        <div class="card" style="overflow-x: auto;">
            <h3>列表</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>REGISTER DATE</th>
                        <th>FULL NAME</th>
                        <th>CONTACT</th>
                        <th>BANK DETAILS</th>
                        <th>REGULAR</th>
                        <th>REMARK</th>
                        <th>VERIFY</th>
                        <?php if ($is_admin): ?><th>操作</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="customer_edit.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['code']) ?></a></td>
                        <td><?= htmlspecialchars($r['register_date'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['bank_details'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['regular_customer'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['verify'] ?? '') ?></td>
                        <?php if ($is_admin): ?>
                        <td>
                            <a href="customer_edit.php?id=<?= (int)$r['id'] ?>">编辑</a>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-gray"><?= ((int)$r['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $is_admin ? 10 : 9 ?>" style="color:var(--muted); padding:24px;">暂无数据，请先执行 migrate_customers_detail.sql 并添加顾客。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
