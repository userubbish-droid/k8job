<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_users';

$msg = '';
$err = '';

function redirect_self(): void {
    header('Location: admin_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $role = trim($_POST['role'] ?? 'member');
            $display_name = trim($_POST['display_name'] ?? '');

            if ($username === '' || $password === '') {
                throw new RuntimeException('请填写用户名和密码。');
            }
            if (!in_array($role, ['admin', 'member'], true)) {
                throw new RuntimeException('角色不正确。');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $role, $display_name !== '' ? $display_name : null]);
            $msg = "已创建账号：{$username}（{$role}）";
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException('不能禁用自己。');

            $stmt = $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新账号状态。';
        } elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $new_password = (string) ($_POST['new_password'] ?? '');
            if ($id <= 0 || $new_password === '') throw new RuntimeException('请填写新密码。');

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            $msg = '密码已重置。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$users = $pdo->query("SELECT id, username, role, display_name, is_active, created_at FROM users ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>用户管理 - 算账网</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .wrap { max-width: 920px; margin: 0 auto; }
        h2 { margin: 0 0 12px; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; }
        input, select { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 14px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="wrap" style="max-width: 960px;">
        <h2>用户管理（仅 admin）</h2>
        <p class="muted">当前：<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>（<?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>）</p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 8px;">创建账号</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="row">
                    <div>
                        <label>用户名 *</label>
                        <input name="username" required>
                    </div>
                    <div>
                        <label>密码 *</label>
                        <input name="password" type="password" required>
                    </div>
                </div>
                <div class="row">
                    <div>
                        <label>角色 *</label>
                        <select name="role" required>
                            <option value="member" selected>member</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div>
                        <label>显示名称（可选）</label>
                        <input name="display_name" placeholder="例如 小明">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit">创建</button>
                    <a href="dashboard.php" style="margin-left:10px;">返回首页</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 8px;">账号列表</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>显示名</th>
                        <th>角色</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td><?= ((int)$u['is_active'] === 1) ? '启用' : '禁用' ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td>
                            <form method="post" class="inline" style="margin-right:6px;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn2"><?= ((int)$u['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input name="new_password" type="password" placeholder="新密码" style="width:160px; display:inline-block;">
                                <button type="submit">重置密码</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="7">暂无账号</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
        </main>
    </div>
</body>
</html>

