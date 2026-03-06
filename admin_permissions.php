<?php
require 'config.php';
require 'auth.php';
require_admin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = trim($_POST['role'] ?? 'member');
    if ($role !== 'member') {
        $err = '目前仅支持设置 member 权限。';
    } else {
        $checked = $_POST['perms'] ?? [];
        if (!is_array($checked)) {
            $checked = [];
        }
        $options = get_permission_options();
        $valid = array_keys($options);
        $to_insert = array_intersect($checked, $valid);

        try {
            $pdo->prepare("DELETE FROM role_permissions WHERE role = ?")->execute([$role]);
            $stmt = $pdo->prepare("INSERT INTO role_permissions (role, permission_key) VALUES (?, ?)");
            foreach ($to_insert as $key) {
                $stmt->execute([$role, $key]);
            }
            $msg = '已保存。Member 只能看到并操作已勾选的项目。';
        } catch (Throwable $e) {
            $err = '保存失败：' . $e->getMessage();
        }
    }
}

$options = get_permission_options();
$current = [];
try {
    $stmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role = 'member'");
    $stmt->execute();
    $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $current = array_keys($options);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>权限设置 - 算账网</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .perm-list { list-style: none; padding: 0; margin: 0; }
        .perm-list li { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .perm-list li:last-child { border-bottom: none; }
        .perm-list input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="page-wrap" style="max-width: 560px;">
        <div class="page-header">
            <h2>Member 权限设置</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="admin_users.php">用户管理</a>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <p class="form-hint" style="margin-bottom: 16px;">勾选 Member 可以「看到并操作」的功能；未勾选则 Member 登录后无法进入该功能。Admin 不受限制。</p>
            <form method="post">
                <input type="hidden" name="role" value="member">
                <ul class="perm-list">
                    <?php foreach ($options as $key => $label): ?>
                    <li>
                        <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($key) ?>" id="perm_<?= htmlspecialchars($key) ?>" <?= in_array($key, $current, true) ? 'checked' : '' ?>>
                        <label for="perm_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <button type="submit" class="btn btn-primary" style="margin-top: 16px;">保存权限</button>
            </form>
        </div>
    </div>
</body>
</html>
