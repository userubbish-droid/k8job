<?php
require 'config.php';
require 'auth.php';
require_login();

$sidebar_current = 'change_password';
$msg = '';
$err = '';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_password = (string)($_POST['old_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($old_password === '' || $new_password === '' || $confirm_password === '') {
        $err = app_lang() === 'en' ? 'Please fill in all fields.' : '请填写所有字段。';
    } elseif (mb_strlen($new_password, 'UTF-8') < 6) {
        $err = app_lang() === 'en' ? 'New password must be at least 6 characters.' : '新密码至少需要 6 位。';
    } elseif ($new_password !== $confirm_password) {
        $err = app_lang() === 'en' ? 'New password and confirmation do not match.' : '新密码与确认密码不一致。';
    } else {
        try {
            $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $hash = (string)$st->fetchColumn();
            if ($hash === '' || !password_verify($old_password, $hash)) {
                $err = app_lang() === 'en' ? 'Current password is incorrect.' : '当前密码不正确。';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $up->execute([$new_hash, $uid]);
                $msg = app_lang() === 'en' ? 'Password updated successfully.' : '密码修改成功。';
            }
        } catch (Throwable $e) {
            $err = (app_lang() === 'en' ? 'Failed to update password: ' : '修改密码失败：') . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_lang() === 'en' ? 'Change Password' : '修改密码' ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 560px;">
                <div class="page-header">
                    <h2><?= app_lang() === 'en' ? 'Change Password' : '修改密码' ?></h2>
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>

                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <div class="card">
                    <form method="post" autocomplete="off">
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Current Password' : '当前密码' ?></label>
                            <input type="password" name="old_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'New Password' : '新密码' ?></label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Confirm New Password' : '确认新密码' ?></label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div style="display:flex; gap:10px; margin-top: 10px;">
                            <button type="submit" class="btn btn-primary"><?= app_lang() === 'en' ? 'Save' : '保存' ?></button>
                            <a href="dashboard.php" class="btn btn-outline"><?= app_lang() === 'en' ? 'Back' : '返回' ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
