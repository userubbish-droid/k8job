<?php
require 'config.php';
require 'auth.php';
require_login();

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
    <style>
        body { font-family: sans-serif; margin: 20px; background: #f5f5f5; }
        .wrap { max-width: 1400px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .summary { display: flex; gap: 24px; margin-bottom: 16px; flex-wrap: wrap; }
        .summary-item { background: #e8f4fc; padding: 12px 20px; border-radius: 8px; }
        .summary-item strong { display: block; font-size: 12px; color: #666; }
        .summary-item span { font-size: 20px; font-weight: 700; color: #0d6efd; }
        label { display:block; margin-top: 10px; font-weight: 700; }
        input, textarea, select { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 14px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        button:hover { background: #0056b3; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #e8f4fc; font-weight: 600; white-space: nowrap; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        .btn2 { background: #6c757d; padding: 4px 8px; font-size: 12px; }
        .btn2:hover { background: #5a6268; }
        form.inline { display:inline-block; margin-right: 4px; }
        .num { text-align: right; }
        .total-col { background: #fff3cd; font-weight: 600; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2 style="margin:0 0 8px;">顾客资料</h2>
        <p class="muted"><a href="dashboard.php">返回首页</a> | <a href="transaction_create.php">去记一笔</a><?php if ($is_admin): ?> | <a href="admin_option_sets.php">选项设置（SMS/FD/WS/WC/VERIFY）</a><?php endif; ?></p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="summary">
            <div class="summary-item"><strong>T1. Customer</strong><span><?= $summary['total'] ?></span></div>
            <div class="summary-item"><strong>Active Member</strong><span><?= $summary['active'] ?></span></div>
            <div class="summary-item"><strong>TARGET</strong><span>1</span></div>
        </div>

        <div class="card" style="overflow-x: auto;">
            <h3 style="margin:0 0 8px;">列表</h3>
            <table>
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
                                <button type="submit" class="btn2"><?= ((int)$r['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $is_admin ? 10 : 9 ?>">暂无数据，请先执行 migrate_customers_detail.sql 并添加顾客。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
