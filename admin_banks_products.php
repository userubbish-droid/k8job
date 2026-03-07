<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_banks_products';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_bank') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入银行/渠道名称。');
            $stmt = $pdo->prepare("INSERT INTO banks (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $sort]);
            $msg = '已添加银行/渠道。';
        } elseif ($action === 'create_product') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入产品名称。');
            $stmt = $pdo->prepare("INSERT INTO products (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $sort]);
            $msg = '已添加产品。';
        } elseif ($action === 'toggle_bank') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE banks SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        } elseif ($action === 'toggle_product') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE products SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$banks = [];
$products = [];
try {
    $banks = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM banks ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    $products = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM products ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>银行与产品 - 算账网</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 960px;">
                <div class="page-header">
                    <h2>银行与产品</h2>
                    <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span>银行/渠道与产品管理</p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="card">
                    <h3>银行/渠道</h3>
                    <form method="post" style="margin-bottom:16px;">
                        <input type="hidden" name="action" value="create_bank">
                        <div class="form-group">
                            <label>名称 *</label>
                            <input name="name" class="form-control" required placeholder="例如 HLB">
                        </div>
                        <div class="form-group">
                            <label>排序</label>
                            <input name="sort_order" class="form-control" type="number" value="0">
                        </div>
                        <button type="submit" class="btn btn-primary">添加</button>
                    </form>
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
                            <?php foreach ($banks as $b): ?>
                            <tr>
                                <td><?= (int)$b['id'] ?></td>
                                <td><?= htmlspecialchars($b['name']) ?></td>
                                <td><?= ((int)$b['is_active'] === 1) ? '启用' : '禁用' ?></td>
                                <td><?= (int)$b['sort_order'] ?></td>
                                <td><?= htmlspecialchars($b['created_at']) ?></td>
                                <td>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="action" value="toggle_bank">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$b['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$banks): ?><tr><td colspan="6">暂无银行/渠道</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3>产品管理</h3>
                    <form method="post" style="margin-bottom:16px;">
                        <input type="hidden" name="action" value="create_product">
                        <div class="form-group">
                            <label>名称 *</label>
                            <input name="name" class="form-control" required placeholder="例如 MEGA">
                        </div>
                        <div class="form-group">
                            <label>排序</label>
                            <input name="sort_order" class="form-control" type="number" value="0">
                        </div>
                        <button type="submit" class="btn btn-primary">添加</button>
                    </form>
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
                                        <input type="hidden" name="action" value="toggle_product">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$p['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td colspan="6">暂无产品</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
