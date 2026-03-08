<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_permissions';

$msg = '';
$err = '';

$members = [];
try {
    $members = $pdo->query("SELECT id, username, display_name FROM users WHERE role = 'member' ORDER BY username ASC")->fetchAll();
} catch (Throwable $e) {
    $members = [];
}

$selected_id = (int)($_REQUEST['user_id'] ?? $_POST['user_id'] ?? 0);
if ($selected_id > 0 && !in_array($selected_id, array_column($members, 'id'), true)) {
    $selected_id = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_id > 0) {
    $checked = $_POST['perms'] ?? [];
    if (!is_array($checked)) {
        $checked = [];
    }
    $options = get_permission_options();
    $valid = array_keys($options);
    $to_insert = array_intersect($checked, $valid);

    try {
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$selected_id]);
        $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)");
        foreach ($to_insert as $key) {
            $stmt->execute([$selected_id, $key]);
        }
        $msg = '已保存该员工的权限。';
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, "user_permissions") !== false && (strpos($msg, "doesn't exist") !== false || strpos($msg, '1146') !== false)) {
            $err = '保存失败：尚未创建权限表。请到 Hostinger 的 phpMyAdmin 中选中当前数据库，执行一次 <strong>migrate_user_permissions.sql</strong> 里的 SQL（创建 user_permissions 表），保存后再试。';
        } else {
            $err = '保存失败：' . htmlspecialchars($msg);
        }
    }
}

$options = get_permission_options();
$current = [];
if ($selected_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$selected_id]);
        $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $current = [];
        $msg = $e->getMessage();
        if (empty($err) && (strpos($msg, "user_permissions") !== false && (strpos($msg, "doesn't exist") !== false || strpos($msg, '1146') !== false))) {
            $err = '当前数据库缺少 <strong>user_permissions</strong> 表。请到 phpMyAdmin 选中数据库，执行 <strong>migrate_user_permissions.sql</strong> 中的 SQL 创建该表后刷新本页。';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>权限设置 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
        .perm-list { list-style: none; padding: 0; margin: 0; }
        .perm-list li { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .perm-list li:last-child { border-bottom: none; }
        .perm-list input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .member-select { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
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
            <p class="form-hint" style="margin-bottom: 16px;">先选择要设置的 Member，再勾选该成员可以「看到并操作」的功能。Admin 不受限制。</p>

            <form method="get" class="member-select">
                <div class="form-group">
                    <label>选择 Member</label>
                    <select name="user_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= $selected_id === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['username']) ?>
                                <?= $m['display_name'] ? '（' . htmlspecialchars($m['display_name']) . '）' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selected_id > 0): ?>
            <form method="post">
                <input type="hidden" name="user_id" value="<?= $selected_id ?>">
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
            <?php else: ?>
            <p class="form-hint">请先从上方下拉选择一名 Member（如 member1、member2），再为其勾选权限。</p>
            <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
</body>
</html>
