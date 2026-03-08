<?php
// 一次性初始化页面：创建 users 表里的账号（admin/member）
// 用完请删除此文件，避免被别人创建账号。
require 'config.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'member');
    $display_name = trim($_POST['display_name'] ?? '');

    if ($username === '' || $password === '') {
        $err = '请填写用户名和密码。';
    } elseif (!in_array($role, ['admin', 'member'], true)) {
        $err = '角色不正确。';
    } else {
        try {
            // 确保 users 表存在（如果你忘了执行 schema.sql）
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','member') NOT NULL DEFAULT 'member',
                display_name VARCHAR(80) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $role, $display_name !== '' ? $display_name : null]);
            $msg = '创建成功：' . htmlspecialchars($username) . '（' . htmlspecialchars($role) . '）。现在可以去 login.php 登录。';
        } catch (Throwable $e) {
            $err = '创建失败：' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>初始化账号 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <style>
        body { font-family: sans-serif; max-width: 520px; margin: 40px auto; padding: 0 16px; }
        h2 { margin: 0 0 10px; }
        .tip { color: #666; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; }
        input, select { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
        button { margin-top: 16px; padding: 10px 16px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; margin-top: 10px; color: #155724; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; margin-top: 10px; color: #721c24; }
        code { background: #f2f2f2; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h2>初始化账号</h2>
    <div class="tip">
        这是一次性工具：用完请删除 <code>setup.php</code>。<br>
        你可以先创建一个 <b>admin</b> 账号，再创建一个 <b>member</b> 账号。
    </div>

    <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post">
        <label>用户名 *</label>
        <input name="username" required placeholder="例如 admin / member01">

        <label>密码 *</label>
        <input name="password" type="password" required>

        <label>角色 *</label>
        <select name="role" required>
            <option value="admin">admin</option>
            <option value="member" selected>member</option>
        </select>

        <label>显示名称（可选）</label>
        <input name="display_name" placeholder="例如 老板 / 小明">

        <button type="submit">创建账号</button>
    </form>

    <p style="margin-top: 16px;">
        创建完成后去 <a href="login.php">login.php</a> 登录。
    </p>
</body>
</html>

