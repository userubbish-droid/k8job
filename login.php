<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');
    // 简单单用户：用户名 admin，密码 123（上线后请改密码或改成数据库验证）
    if ($user === 'admin' && $pass === '123') {
        $_SESSION['user_id']   = 1;
        $_SESSION['user_name'] = 'Admin';
        header('Location: dashboard.php');
        exit;
    }
    $error = '用户名或密码错误';
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
        <input type="text" name="user" value="admin" required>
        <label>密码</label>
        <input type="password" name="pass" placeholder="默认 123" required>
        <button type="submit">登录</button>
    </form>
    <p style="margin-top: 12px; font-size: 12px; color: #666;">默认：admin / 123（上线后请修改）</p>
</body>
</html>
