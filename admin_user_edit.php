<?php
require 'config.php';
require 'auth.php';
require_admin();

$sidebar_current = 'admin_users';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: admin_users.php');
    exit;
}

function ensure_users_login_meta(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at"); } catch (Throwable $e) {}
}
ensure_users_login_meta($pdo);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $display_name = trim((string)($_POST['display_name'] ?? ''));
    $avatar_url = trim((string)($_POST['avatar_url'] ?? ''));
    $is_active = isset($_POST['is_active']) && (string)$_POST['is_active'] === '1' ? 1 : 0;
    if ($username === '') {
        $err = '用户名不能为空。';
    } elseif (!user_is_manageable_by_current_actor($pdo, $id)) {
        $err = '无权限保存该账号。';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, display_name = ?, avatar_url = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $username,
                $display_name !== '' ? $display_name : null,
                $avatar_url !== '' ? $avatar_url : null,
                $is_active,
                $id
            ]);
            $msg = '已保存。';
        } catch (Throwable $e) {
            $raw = (string)$e->getMessage();
            if (strpos($raw, 'Duplicate entry') !== false && strpos($raw, "for key 'username'") !== false) {
                $err = '保存失败：用户名已存在。';
            } else {
                $err = '保存失败：' . $raw;
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT id, username, role, display_name, avatar_url, is_active, last_login_at, last_login_ip, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
} catch (Throwable $e) {
    $u = null;
}
if (!$u) {
    header('Location: admin_users.php');
    exit;
}
if (!user_is_manageable_by_current_actor($pdo, $id)) {
    http_response_code(403);
    echo '无权限编辑该账号（本公司账号与平台 superadmin 已分开管理）。';
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>编辑用户 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 720px;">
                <div class="page-header">
                    <h2>编辑用户</h2>
                    <p class="breadcrumb">
                        <a href="admin_users.php">User Management</a><span>·</span>
                        #<?= (int)$u['id'] ?> <?= htmlspecialchars((string)$u['username']) ?>
                    </p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="card">
                    <form method="post">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>用户名 *</label>
                                <input class="form-control" name="username" value="<?= htmlspecialchars((string)$u['username']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>显示名</label>
                                <input class="form-control" name="display_name" value="<?= htmlspecialchars((string)($u['display_name'] ?? '')) ?>" placeholder="选填">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>头像 URL（可选）</label>
                            <input class="form-control" name="avatar_url" value="<?= htmlspecialchars((string)($u['avatar_url'] ?? '')) ?>" placeholder="例如：https://.../avatar.png">
                            <p class="form-hint">留空则侧栏显示首字母。建议使用 https 图片地址。</p>
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>角色</label>
                                <input class="form-control" value="<?= htmlspecialchars((string)($u['role'] ?? '')) ?>" disabled>
                                <p class="form-hint">角色请回到列表页用「改角色」。</p>
                            </div>
                            <div class="form-group">
                                <label>状态</label>
                                <select class="form-control" name="is_active">
                                    <option value="1" <?= ((int)$u['is_active'] === 1) ? 'selected' : '' ?>>启用</option>
                                    <option value="0" <?= ((int)$u['is_active'] !== 1) ? 'selected' : '' ?>>禁用</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>Login IP</label>
                                <input class="form-control" value="<?= htmlspecialchars((string)($u['last_login_ip'] ?? '')) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Last Login</label>
                                <input class="form-control" value="<?= htmlspecialchars((string)($u['last_login_at'] ?? '')) ?>" disabled>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 12px;">
                            <button type="submit" class="btn btn-primary">保存</button>
                            <a href="admin_users.php" class="btn btn-outline">返回</a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

