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
        if ($action === 'create') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $remark = trim($_POST['remark'] ?? '');
            if ($code === '') throw new RuntimeException('请输入客户代码。');
            $stmt = $pdo->prepare("INSERT INTO customers (code, name, phone, remark, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $remark !== '' ? $remark : null, (int)($_SESSION['user_id'] ?? 0)]);
            $msg = '已添加客户。';
        } elseif ($action === 'toggle' && $is_admin) {
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

try {
    $sql = "SELECT c.id, c.code, c.name, c.phone, c.remark, c.is_active, c.created_at, u.username AS created_by_name
            FROM customers c
            LEFT JOIN users u ON u.id = c.created_by
            ORDER BY c.is_active DESC, c.code ASC";
    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $err = $err ?: 'customers 表不存在，请先在 phpMyAdmin 执行 migrate_customers.sql 或 schema.sql。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>客户资料 - 算账网</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .wrap { max-width: 980px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; }
        input, textarea { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 14px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        button:hover { background: #0056b3; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        .btn2 { background: #6c757d; }
        .btn2:hover { background: #5a6268; }
        form.inline { display:inline-block; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <h2 style="margin:0 0 8px;">客户资料</h2>
        <p class="muted"><a href="dashboard.php">返回首页</a> | <a href="transaction_create.php">去记一笔</a></p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 8px;">新增客户</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="grid">
                    <div>
                        <label>客户代码 *</label>
                        <input name="code" required placeholder="例如 C004">
                    </div>
                    <div>
                        <label>客户姓名（可选）</label>
                        <input name="name" placeholder="例如 小明">
                    </div>
                </div>
                <div class="grid">
                    <div>
                        <label>电话（可选）</label>
                        <input name="phone" placeholder="例如 0123456789">
                    </div>
                    <div>
                        <label>备注（可选）</label>
                        <input name="remark" placeholder="">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit">添加</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 8px;">列表</h3>
            <table>
                <thead>
                    <tr>
                        <th>代码</th>
                        <th>姓名</th>
                        <th>电话</th>
                        <th>备注</th>
                        <th>状态</th>
                        <th>创建人</th>
                        <th>创建时间</th>
                        <?php if ($is_admin): ?><th>操作</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['code']) ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                        <td><?= ((int)$r['is_active'] === 1) ? '启用' : '禁用' ?></td>
                        <td><?= htmlspecialchars($r['created_by_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <?php if ($is_admin): ?>
                        <td>
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
                    <tr><td colspan="<?= $is_admin ? 8 : 7 ?>">暂无数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

