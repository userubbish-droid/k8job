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
ensure_users_second_password_hash($pdo);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $display_name = trim((string)($_POST['display_name'] ?? ''));
    $avatar_url = trim((string)($_POST['avatar_url'] ?? ''));
    $is_active = isset($_POST['is_active']) && (string)$_POST['is_active'] === '1' ? 1 : 0;
    if ($username === '') {
        $err = __('user_edit_err_user_empty');
    } elseif (!user_is_manageable_by_current_actor($pdo, $id)) {
        $err = __('user_edit_err_no_perm_save');
    } else {
            try {
            $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmtRole->execute([$id]);
            $editUserRole = (string)($stmtRole->fetchColumn() ?: '');
            if ($editUserRole === 'agent') {
                $agent_ui_show_week = !empty($_POST['agent_ui_show_week']) ? 1 : 0;
                $agent_ui_show_month = !empty($_POST['agent_ui_show_month']) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE users SET username = ?, display_name = ?, avatar_url = ?, is_active = ?, agent_ui_show_week = ?, agent_ui_show_month = ? WHERE id = ?");
                $stmt->execute([
                    $username,
                    $display_name !== '' ? $display_name : null,
                    $avatar_url !== '' ? $avatar_url : null,
                    $is_active,
                    $agent_ui_show_week,
                    $agent_ui_show_month,
                    $id,
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, display_name = ?, avatar_url = ?, is_active = ? WHERE id = ?");
                $stmt->execute([
                    $username,
                    $display_name !== '' ? $display_name : null,
                    $avatar_url !== '' ? $avatar_url : null,
                    $is_active,
                    $id,
                ]);
            }
            $msg = '已保存。';
        } catch (Throwable $e) {
            $raw = (string)$e->getMessage();
            if (strpos($raw, 'Duplicate entry') !== false) {
                if (strpos($raw, 'login_scope_key') !== false || strpos($raw, 'uq_users_login_scope') !== false) {
                    $err = '保存失败：该分公司下此用户名已被占用。';
                } elseif (strpos($raw, "for key 'username'") !== false) {
                    $err = '保存失败：用户名已存在。';
                } else {
                    $err = '保存失败：' . $raw;
                }
            } else {
                $err = '保存失败：' . $raw;
            }
        }
    }
    if ($err === '' && user_actor_can_set_second_password($pdo, $id)) {
        $np2 = (string)($_POST['new_second_password'] ?? '');
        if ($np2 !== '') {
            if (mb_strlen($np2, 'UTF-8') < 4) {
                $err = __('user_edit_err_second_short');
                $msg = '';
            } else {
                try {
                    $h2 = password_hash($np2, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE users SET second_password_hash = ? WHERE id = ?')->execute([$h2, $id]);
                    $msg = ($msg !== '' ? $msg . ' ' : '') . __('user_edit_second_updated');
                } catch (Throwable $e) {
                    $err = __('user_edit_err_second_fail') . $e->getMessage();
                    $msg = '';
                }
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT id, username, role, display_name, avatar_url, is_active, COALESCE(agent_ui_show_week, 1) AS agent_ui_show_week, COALESCE(agent_ui_show_month, 1) AS agent_ui_show_month, last_login_at, last_login_ip, created_at, second_password_hash FROM users WHERE id = ? LIMIT 1");
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
    echo htmlspecialchars(__('user_edit_forbidden'), ENT_QUOTES, 'UTF-8');
    exit;
}
$can_set_second = user_actor_can_set_second_password($pdo, $id);
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__f('user_edit_page_title', defined('SITE_TITLE') ? SITE_TITLE : 'K8'), ENT_QUOTES, 'UTF-8') ?></title>
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
                                <label><?= htmlspecialchars(__('adm_users_lbl_username_req'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input class="form-control" name="username" value="<?= htmlspecialchars((string)$u['username']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('lbl_display_name'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input class="form-control" name="display_name" value="<?= htmlspecialchars((string)($u['display_name'] ?? '')) ?>" placeholder="<?= htmlspecialchars(__('user_edit_ph_display'), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?= htmlspecialchars(__('user_edit_lbl_avatar'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input class="form-control" name="avatar_url" value="<?= htmlspecialchars((string)($u['avatar_url'] ?? '')) ?>" placeholder="<?= htmlspecialchars(__('user_edit_ph_avatar'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('lbl_role'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input class="form-control" value="<?= htmlspecialchars(function_exists('role_label') ? role_label((string)($u['role'] ?? '')) : (string)($u['role'] ?? '')) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>状态</label>
                                <select class="form-control" name="is_active">
                                    <option value="1" <?= ((int)$u['is_active'] === 1) ? 'selected' : '' ?>>启用</option>
                                    <option value="0" <?= ((int)$u['is_active'] !== 1) ? 'selected' : '' ?>>禁用</option>
                                </select>
                            </div>
                        </div>

                        <?php if (($u['role'] ?? '') === 'agent'): ?>
                        <?php
                        $agent_ui_w = (int)($u['agent_ui_show_week'] ?? 1) === 1;
                        $agent_ui_m = (int)($u['agent_ui_show_month'] ?? 1) === 1;
                        ?>
                        <div class="form-group" style="margin-top:8px;">
                            <label><?= htmlspecialchars(__('user_edit_agent_portal_section'), ENT_QUOTES, 'UTF-8') ?></label>
                            <p class="form-hint" style="margin-bottom:10px;"><?= htmlspecialchars(__('user_edit_agent_period_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="agent-ui-prefs">
                                <label class="agent-ui-pref-check">
                                    <input type="checkbox" name="agent_ui_show_week" value="1" <?= $agent_ui_w ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars(__('user_edit_agent_period_week'), ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                                <label class="agent-ui-pref-check">
                                    <input type="checkbox" name="agent_ui_show_month" value="1" <?= $agent_ui_m ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars(__('user_edit_agent_period_month'), ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                            </div>
                        </div>
                        <style>
                            .agent-ui-prefs { display: flex; flex-wrap: wrap; gap: 22px; align-items: center; margin-top: 4px; }
                            .agent-ui-pref-check { display: inline-flex; align-items: center; gap: 10px; margin: 0; cursor: pointer;
                                font-weight: 700; color: #1e3a8a; font-size: 14px; user-select: none; }
                            .agent-ui-pref-check input[type="checkbox"] { width: 18px; height: 18px; accent-color: #2563eb; cursor: pointer; flex-shrink: 0; }
                        </style>
                        <?php endif; ?>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('lbl_login_ip'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input class="form-control" value="<?= htmlspecialchars((string)($u['last_login_ip'] ?? '')) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Last Login</label>
                                <input class="form-control" value="<?= htmlspecialchars((string)($u['last_login_at'] ?? '')) ?>" disabled>
                            </div>
                        </div>

                        <?php if ($can_set_second): ?>
                        <div class="form-group" style="margin-top:18px; padding-top:16px; border-top:1px solid var(--border);">
                            <label><?= htmlspecialchars(__('user_edit_second_label'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input class="form-control" type="password" name="new_second_password" autocomplete="new-password" placeholder="<?= htmlspecialchars(__('user_edit_second_ph'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <?php endif; ?>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 12px;">
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                            <a href="admin_users.php" class="btn btn-outline"><?= htmlspecialchars(__('btn_back'), ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

