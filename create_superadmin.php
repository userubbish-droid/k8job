<?php
// 一次性工具：创建 superadmin（老板账号）
// 用完请删除此文件，避免被别人创建老板账号。
require 'config.php';

$msg = '';
$err = '';

function ensure_users_role_supports_superadmin(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('superadmin','boss','admin','member','agent') NOT NULL DEFAULT 'member'");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL AFTER avatar_url");
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $display_name = trim((string)($_POST['display_name'] ?? ''));
    if ($username === '' || $password === '') {
        $err = '请填写用户名和密码。';
    } else {
        try {
            ensure_users_role_supports_superadmin($pdo);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, display_name, company_id, is_active) VALUES (?, ?, 'superadmin', ?, NULL, 1)");
            $stmt->execute([$username, $hash, $display_name !== '' ? $display_name : null]);
            $msg = '创建成功：' . htmlspecialchars($username) . '（superadmin）。现在去 login.php 用 Admin 入口登录。';
        } catch (Throwable $e) {
            $raw = (string)$e->getMessage();
            if (strpos($raw, 'Duplicate') !== false || strpos($raw, '1062') !== false) {
                $err = '创建失败：用户名已存在。';
            } else {
                $err = '创建失败：' . $raw;
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>创建 superadmin - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <style>
        body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif; max-width: 560px; margin: 40px auto; padding: 0 16px; }
        h2 { margin: 0 0 10px; }
        .tip { color: #475569; margin-bottom: 16px; }
        label { display:block; margin-top: 12px; font-weight: 700; }
        input { width: 100%; padding: 10px 12px; margin-top: 6px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; }
        button { margin-top: 16px; padding: 10px 16px; background: #2563eb; color: #fff; border: 0; border-radius: 10px; cursor: pointer; font-weight: 700; }
        .ok { background: #ecfdf5; padding: 10px 12px; border-radius: 10px; margin-top: 12px; color: #065f46; border: 1px solid #a7f3d0; }
        .err { background: #fef2f2; padding: 10px 12px; border-radius: 10px; margin-top: 12px; color: #991b1b; border: 1px solid #fecaca; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
    <h2>创建 superadmin（老板账号）</h2>
    <div class="tip">
        这是一次性工具：用完请删除 <code>create_superadmin.php</code>。<br>
        创建后用 <code>login.php</code> 的 <b>Admin</b> 入口登录即可。
    </div>

    <?php if ($msg): ?><div class="ok"><?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post">
        <label>用户名 *</label>
        <input name="username" required placeholder="例如 boss">

        <label>密码 *</label>
        <input name="password" type="password" required>

        <label>显示名称（可选）</label>
        <input name="display_name" placeholder="例如 老板">

        <button type="submit">创建 superadmin</button>
    </form>

    <p style="margin-top: 16px;">
        创建完成后去 <a href="login.php">login.php</a> 登录。
    </p>
</body>
</html>

