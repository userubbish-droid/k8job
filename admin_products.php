<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_products';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入产品/平台名称。');
            $stmt = $pdo->prepare("INSERT INTO products (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $sort]);
            $msg = '已添加。';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE products SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

try {
    $products = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM products ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {
    $products = [];
    $err = $err ?: 'products 表不存在，请先在 phpMyAdmin 执行 schema.sql。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>产品/平台管理 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 860px;">
        <div class="page-header">
            <h2>产品/平台管理</h2>
            <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3>新增产品/平台</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>名称 *</label>
                    <input name="name" class="form-control" required placeholder="例如 MEGA / 918KISS">
                </div>
                <div class="form-group">
                    <label>排序（数字越大越靠前，可选）</label>
                    <input name="sort_order" class="form-control" type="number" value="0">
                </div>
                <button type="submit" class="btn btn-primary">添加</button>
            </form>
        </div>

        <div class="card">
            <h3>列表</h3>
            <table class="data-table">
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
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= ((int)$p['is_active'] === 1) ? '启用' : '禁用' ?></td>
                        <td><?= (int)$p['sort_order'] ?></td>
                        <td><?= htmlspecialchars($p['created_at']) ?></td>
                        <td>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-gray"><?= ((int)$p['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <tr><td colspan="6">暂无数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
        </main>
    </div>
</body>
</html>


