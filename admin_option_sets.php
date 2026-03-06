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
    <style>
        body { font-family: sans-serif; margin: 20px; max-width: 700px; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        h3 { margin: 0 0 10px; font-size: 14px; color: #333; }
        label { display: block; margin-top: 8px; font-weight: 600; font-size: 12px; }
        input { padding: 6px 10px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 6px 12px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        .btn2 { background: #dc3545; font-size: 12px; padding: 4px 8px; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        a { color: #007bff; }
        ul { list-style: none; padding: 0; margin: 8px 0 0; }
        li { padding: 6px 0; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
        form.inline { display: inline-block; margin-left: 8px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin:0 0 8px;">选项设置（SMS / FD / WS / WC / VERIFY）</h2>
        <p style="margin:0 0 12px; font-size: 12px; color: #666;">这里维护的选项会出现在「顾客资料」编辑页的下拉里。</p>
        <p><a href="customers.php">← 顾客资料</a> | <a href="dashboard.php">首页</a></p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <?php foreach ($types as $type => $label): ?>
        <div class="card" style="margin-bottom: 12px;">
            <h3><?= htmlspecialchars($label) ?>（<?= $type ?>）</h3>
            <form method="post" style="display:flex; gap:8px; align-items:flex-end;">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="option_type" value="<?= htmlspecialchars($type) ?>">
                <div style="flex:1;">
                    <label>新增选项值</label>
                    <input name="option_value" placeholder="例如 Yes / No / Unique">
                </div>
                <button type="submit">添加</button>
            </form>
            <ul>
                <?php foreach ($options_by_type[$type] as $opt): ?>
                <li>
                    <span><?= htmlspecialchars($opt['option_value']) ?></span>
                    <form method="post" class="inline" onsubmit="return confirm('确定删除？');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="option_type" value="<?= htmlspecialchars($type) ?>">
                        <input type="hidden" name="id" value="<?= (int)$opt['id'] ?>">
                        <button type="submit" class="btn2">删除</button>
                    </form>
                </li>
                <?php endforeach; ?>
                <?php if (empty($options_by_type[$type])): ?>
                <li style="color:#888;">暂无选项，上方添加后顾客编辑页可选用。</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
