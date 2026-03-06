<?php
require 'config.php';
require 'auth.php';
require_admin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $option_type = trim($_POST['option_type'] ?? '');
    $allowed = ['sms', 'fd', 'ws', 'wc', 'verify'];
    if (!in_array($option_type, $allowed, true)) {
        $err = '无效类型';
    } else {
        try {
            if ($action === 'add') {
                $option_value = trim($_POST['option_value'] ?? '');
                if ($option_value === '') {
                    $err = '请输入选项值';
                } else {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO option_sets (option_type, option_value, sort_order) VALUES (?, ?, 0)");
                    $stmt->execute([$option_type, $option_value]);
                    if ($stmt->rowCount()) $msg = '已添加。';
                    else $err = '该选项已存在';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $pdo->prepare("DELETE FROM option_sets WHERE id = ?")->execute([$id]);
                    $msg = '已删除。';
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$types = [
    'sms'   => 'SMS',
    'fd'    => 'FD',
    'ws'    => 'WS',
    'wc'    => 'WC',
    'verify'=> 'VERIFY',
];
$options_by_type = [];
try {
    foreach (array_keys($types) as $t) {
        $options_by_type[$t] = $pdo->prepare("SELECT id, option_value FROM option_sets WHERE option_type = ? ORDER BY sort_order, option_value");
        $options_by_type[$t]->execute([$t]);
        $options_by_type[$t] = $options_by_type[$t]->fetchAll();
    }
} catch (Throwable $e) {
    $options_by_type = array_fill_keys(array_keys($types), []);
    $err = $err ?: '请先执行 migrate_option_sets.sql 创建 option_sets 表。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>选项设置 - 算账网</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .option-card { margin-bottom: 16px; }
        .option-card h3 { margin: 0 0 10px; font-size: 14px; color: #475569; }
        .add-row { display: flex; gap: 10px; align-items: flex-end; margin-bottom: 10px; }
        .add-row .form-group { flex: 1; margin-bottom: 0; }
        .opt-list { list-style: none; padding: 0; margin: 10px 0 0; border-top: 1px solid var(--border); }
        .opt-list li { padding: 8px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    </style>
</head>
<body>
    <div class="page-wrap" style="max-width: 720px;">
        <div class="page-header">
            <h2>选项设置（SMS / FD / WS / WC / VERIFY）</h2>
            <p class="breadcrumb">
                <a href="customers.php">顾客资料</a><span>·</span>
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="admin_products.php">产品管理</a><span>·</span>
                <a href="product_library.php">顾客产品资料库</a>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin-top:0;">产品与资料库</h3>
            <p class="form-hint" style="margin-bottom:12px;">产品（如 MEGA、918KISS）在「产品管理」中添加；顾客的产品账号（CODE / 产品 / 账号 / 密码）在「顾客产品资料库」中查看。</p>
            <p>
                <a href="admin_products.php" class="btn btn-primary">产品管理</a>
                <a href="product_library.php" class="btn btn-outline">顾客产品资料库</a>
            </p>
        </div>

        <div class="card">
            <p class="form-hint" style="margin:0 0 12px;">以下选项会出现在「顾客资料」编辑页的下拉里（如 VERIFY 等）。</p>
        <?php foreach ($types as $type => $label): ?>
        <div class="option-card">
            <h3><?= htmlspecialchars($label) ?>（<?= $type ?>）</h3>
            <form method="post" class="add-row">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="option_type" value="<?= htmlspecialchars($type) ?>">
                <div class="form-group">
                    <label>新增选项值</label>
                    <input name="option_value" class="form-control" placeholder="例如 Yes / No / Unique">
                </div>
                <button type="submit" class="btn btn-primary">添加</button>
            </form>
            <ul class="opt-list">
                <?php foreach ($options_by_type[$type] as $opt): ?>
                <li>
                    <span><?= htmlspecialchars($opt['option_value']) ?></span>
                    <form method="post" class="inline" onsubmit="return confirm('确定删除？');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="option_type" value="<?= htmlspecialchars($type) ?>">
                        <input type="hidden" name="id" value="<?= (int)$opt['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                    </form>
                </li>
                <?php endforeach; ?>
                <?php if (empty($options_by_type[$type])): ?>
                <li style="color:var(--muted);">暂无选项，上方添加后顾客编辑页可选用。</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
