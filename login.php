<?php
require 'config.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');

    if ($user === '' || $pass === '') {
        $error = '请输入用户名和密码';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role, display_name, is_active FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $u = $stmt->fetch();

        if (!$u || (int)$u['is_active'] !== 1 || !password_verify($pass, $u['password_hash'])) {
            $error = '用户名或密码错误';
        } else {
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['user_name'] = $u['display_name'] ?: $u['username'];
            $_SESSION['user_role'] = $u['role'];
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 - 算账网</title>
    <style>
        body { font-family: sans-serif; max-width: 320px; margin: 40px auto; padding: 0 16px; }
        h2 { margin-bottom: 16px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input { width: 100%; padding: 8px; box-sizing: border-box; margin-top: 4px; }
        button { margin-top: 16px; padding: 10px 20px; width: 100%; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .err { color: #dc3545; margin-top: 8px; }
    </style>
</head>
<body>
    <h2>算账网 · 登录</h2>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <label>用户名</label>
        <input type="text" name="user" required>
        <label>密码</label>
        <input type="password" name="pass" required>
        <button type="submit">登录</button>
    </form>
    <p style="margin-top: 12px; font-size: 12px; color: #666;">
        还没创建账号？先访问 <a href="setup.php">setup.php</a> 创建（创建完请删除 setup.php）。
    </p>
</body>
</html>
