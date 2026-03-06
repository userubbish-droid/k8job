<?php
require 'config.php';
require 'auth.php';
require_admin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入银行/渠道名称。');
            $stmt = $pdo->prepare("INSERT INTO banks (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $sort]);
            $msg = '已添加。';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE banks SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// 如果表还没建（忘了执行 schema.sql），这里给出友好错误
try {
    $banks = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM banks ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {
    $banks = [];
    $err = $err ?: 'banks 表不存在，请先在 phpMyAdmin 执行 schema.sql。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>银行/渠道管理 - 算账网</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .wrap { max-width: 860px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; }
        input { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 14px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        button:hover { background: #0056b3; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        .btn2 { background: #6c757d; }
        .btn2:hover { background: #5a6268; }
        .inline { display:inline-block; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2 style="margin:0 0 12px;">银行/渠道管理（仅 admin）</h2>
        <p class="muted"><a href="dashboard.php">返回首页</a> | <a href="admin_products.php">产品管理</a> | <a href="admin_users.php">用户管理</a></p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 8px;">新增银行/渠道</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <label>名称 *</label>
                <input name="name" required placeholder="例如 HLB / CASH">
                <label>排序（数字越大越靠前，可选）</label>
                <input name="sort_order" type="number" value="0">
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
                        <th>ID</th>
                        <th>名称</th>
                        <th>状态</th>
                        <th>排序</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($banks as $b): ?>
                    <tr>
                        <td><?= (int)$b['id'] ?></td>
                        <td><?= htmlspecialchars($b['name']) ?></td>
                        <td><?= ((int)$b['is_active'] === 1) ? '启用' : '禁用' ?></td>
                        <td><?= (int)$b['sort_order'] ?></td>
                        <td><?= htmlspecialchars($b['created_at']) ?></td>
                        <td>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                <button type="submit" class="btn2"><?= ((int)$b['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$banks): ?>
                    <tr><td colspan="6">暂无数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

