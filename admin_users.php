<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_users';

$msg = '';
$err = '';
$customers_for_agent = [];
$status_filter = trim((string)($_GET['status_filter'] ?? 'all'));
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'all';
}

function redirect_self(): void {
    header('Location: admin_users.php');
    exit;
}

function ensure_users_role_supports_agent(PDO $pdo): void {
    $sql = "ALTER TABLE users MODIFY role ENUM('admin','member','agent') NOT NULL DEFAULT 'member'";
    $pdo->exec($sql);
}

function ensure_users_login_meta(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at"); } catch (Throwable $e) {}
}

ensure_users_login_meta($pdo);

try {
    // Agent 绑定下线用：客户列表（显示 code + name）
    $customers_for_agent = $pdo->query("SELECT code, name, COALESCE(recommend,'') AS recommend FROM customers WHERE code IS NOT NULL AND TRIM(code) != '' ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customers_for_agent = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $role = trim($_POST['role'] ?? 'member');
            $display_name = trim($_POST['display_name'] ?? '');
            $agent_customers = $_POST['agent_customers'] ?? [];

            if ($username === '' || $password === '') {
                throw new RuntimeException('请填写用户名和密码。');
            }
            if (!in_array($role, ['admin', 'member', 'agent'], true)) {
                throw new RuntimeException('角色不正确。');
            }

            if ($role === 'agent') {
                // 兼容旧库：旧版本 users.role 仅支持 admin/member
                ensure_users_role_supports_agent($pdo);
                if (!is_array($agent_customers) || empty($agent_customers)) {
                    throw new RuntimeException('请选择至少 1 个客户（该客户将归属此 Agent）。');
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $role, $display_name !== '' ? $display_name : null]);

                if ($role === 'agent') {
                    $codes = array_values(array_filter(array_map(function($x){
                        return trim((string)$x);
                    }, $agent_customers), function($x){ return $x !== ''; }));
                    $codes = array_values(array_unique($codes));
                    if (empty($codes)) {
                        throw new RuntimeException('请选择至少 1 个客户（该客户将归属此 Agent）。');
                    }
                    $placeholders = implode(',', array_fill(0, count($codes), '?'));
                    $params = array_merge([$username], $codes);
                    $up = $pdo->prepare("UPDATE customers SET recommend = ? WHERE code IN ($placeholders)");
                    $up->execute($params);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $msg = "已创建账号：{$username}（{$role}）";
        } elseif ($action === 'change_role') {
            $id = (int)($_POST['id'] ?? 0);
            $role = trim($_POST['role'] ?? 'member');
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if (!in_array($role, ['admin', 'member', 'agent'], true)) {
                throw new RuntimeException('角色不正确。');
            }
            if ($role === 'agent') {
                // 兼容旧库：旧版本 users.role 仅支持 admin/member
                ensure_users_role_supports_agent($pdo);
            }
            if ($id === (int)($_SESSION['user_id'] ?? 0) && $role !== 'admin') {
                throw new RuntimeException('不能把当前登录账号改为非 admin。');
            }
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $id]);
            $msg = '角色已更新。';
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException('不能禁用自己。');

            $stmt = $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新账号状态。';
        } elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException('不能删除自己。');

            // 删除账号同时清理权限表（若表不存在则忽略）
            $pdo->beginTransaction();
            try {
                try {
                    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$id]);
                } catch (Throwable $e) {
                    // user_permissions 表可能未创建，不阻断删除
                }
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $msg = '账号已删除。';
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
        $raw = (string)$e->getMessage();
        if (strpos($raw, 'Duplicate entry') !== false && strpos($raw, "for key 'username'") !== false) {
            $err = '创建失败：用户名已存在，请直接在下方账号列表修改角色或重置密码。';
        } else {
            $err = $raw;
        }
    }
}

$users_sql = "SELECT id, username, role, display_name, is_active, last_login_at, last_login_ip, created_at FROM users";
if ($status_filter === 'active') {
    $users_sql .= " WHERE is_active = 1";
} elseif ($status_filter === 'inactive') {
    $users_sql .= " WHERE is_active = 0";
}
$users_sql .= " ORDER BY id DESC";
$users = $pdo->query($users_sql)->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>用户管理 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .admin-users-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
        .admin-users-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .admin-users-role-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .admin-users-role-form .form-control { min-width: 112px; width: auto; padding: 7px 10px; }
        .admin-users-reset-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .admin-users-reset-form .form-control { width: 140px; padding: 7px 10px; }
        .admin-users-center { text-align:center; color: var(--muted); padding: 22px; }
        @media (max-width: 768px) {
            .admin-users-grid { grid-template-columns: 1fr; }
        }
        .agent-customer-box {
            margin-top: 10px;
            padding: 12px;
            border: 1px solid rgba(59, 130, 246, 0.22);
            border-radius: 12px;
            background: rgba(255,255,255,0.75);
        }
        .agent-customer-box h4 { margin: 0 0 8px; font-size: 13px; }
        .agent-customer-hint { margin: 0 0 10px; font-size: 12px; color: var(--muted); }
        .agent-customer-list {
            max-height: 220px;
            overflow: auto;
            border: 1px solid rgba(148, 163, 184, 0.45);
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
        }
        .agent-customer-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 6px 4px;
            border-bottom: 1px dashed rgba(148, 163, 184, 0.35);
        }
        .agent-customer-item:last-child { border-bottom: none; }
        .agent-customer-code { font-weight: 800; color: #1e3a8a; min-width: 74px; }
        .agent-customer-name { color: #0f172a; }
        .agent-customer-meta { margin-left: auto; font-size: 12px; color: var(--muted); white-space: nowrap; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap">
        <div class="page-header">
            <h2>用户管理（仅 admin）</h2>
            <p class="breadcrumb">当前：<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>（<?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>）</p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3>创建账号</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="admin-users-grid">
                    <div class="form-group">
                        <label>用户名 *</label>
                        <input class="form-control" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>密码 *</label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                </div>
                <div class="admin-users-grid">
                    <div class="form-group">
                        <label>角色 *</label>
                        <select class="form-control" name="role" id="create_role" required>
                            <option value="member" selected>member</option>
                            <option value="admin">admin</option>
                            <option value="agent">agent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>显示名称（可选）</label>
                        <input class="form-control" name="display_name" placeholder="例如 小明">
                    </div>
                </div>
                <div class="agent-customer-box" id="agent_customer_box" style="display:none;">
                    <h4>选择该 Agent 的客户</h4>
                    <p class="agent-customer-hint">当角色为 agent 时，创建成功后会把所选客户的 Recommend 自动设置为该 Agent 的用户名（即归属到此 Agent）。</p>
                    <div class="agent-customer-list" role="group" aria-label="Agent customers">
                        <?php foreach ($customers_for_agent as $c):
                            $ccode = trim((string)($c['code'] ?? ''));
                            if ($ccode === '') continue;
                            $cname = trim((string)($c['name'] ?? ''));
                            $crec = trim((string)($c['recommend'] ?? ''));
                        ?>
                        <label class="agent-customer-item">
                            <input type="checkbox" name="agent_customers[]" value="<?= htmlspecialchars($ccode, ENT_QUOTES) ?>">
                            <span class="agent-customer-code"><?= htmlspecialchars($ccode) ?></span>
                            <span class="agent-customer-name"><?= htmlspecialchars($cname !== '' ? $cname : '—') ?></span>
                            <span class="agent-customer-meta"><?= $crec !== '' ? ('当前 Recommend：' . htmlspecialchars($crec)) : '当前 Recommend：—' ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$customers_for_agent): ?>
                            <div style="color:var(--muted); padding:6px 2px;">暂无客户数据，无法绑定。请先创建 Customer。</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="admin-users-actions" style="margin-top: 12px;">
                    <button type="submit" class="btn btn-primary">创建</button>
                    <a href="dashboard.php" class="btn btn-outline">返回首页</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>账号列表</h3>
            <div class="admin-users-actions" style="margin-bottom:10px;">
                <a class="btn btn-sm <?= $status_filter === 'all' ? 'btn-primary' : 'btn-outline' ?>" href="admin_users.php?status_filter=all">显示全部</a>
                <a class="btn btn-sm <?= $status_filter === 'active' ? 'btn-primary' : 'btn-outline' ?>" href="admin_users.php?status_filter=active">仅启用</a>
                <a class="btn btn-sm <?= $status_filter === 'inactive' ? 'btn-primary' : 'btn-outline' ?>" href="admin_users.php?status_filter=inactive">仅禁用</a>
            </div>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>显示名</th>
                        <th>角色</th>
                        <th>状态</th>
                        <th>Login IP</th>
                        <th>Last Login</th>
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
                        <td>
                            <form method="post" class="admin-users-role-form">
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <select name="role" class="form-control">
                                    <option value="member" <?= ($u['role'] ?? '') === 'member' ? 'selected' : '' ?>>member</option>
                                    <option value="admin" <?= ($u['role'] ?? '') === 'admin' ? 'selected' : '' ?>>admin</option>
                                    <option value="agent" <?= ($u['role'] ?? '') === 'agent' ? 'selected' : '' ?>>agent</option>
                                </select>
                                <button type="submit" class="btn btn-gray btn-sm">改角色</button>
                            </form>
                        </td>
                        <td><?= ((int)$u['is_active'] === 1) ? '启用' : '禁用' ?></td>
                        <td><?= htmlspecialchars((string)($u['last_login_ip'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($u['last_login_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td>
                            <a class="btn btn-outline btn-sm inline" href="admin_user_edit.php?id=<?= (int)$u['id'] ?>">编辑</a>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-gray btn-sm"><?= ((int)$u['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                            <form method="post" class="inline" data-confirm="确定删除该账号？删除后不可恢复。">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" <?= ((int)$u['id'] === (int)($_SESSION['user_id'] ?? 0)) ? 'disabled' : '' ?>>删除</button>
                            </form>
                            <form method="post" class="admin-users-reset-form inline">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input name="new_password" type="password" placeholder="新密码" class="form-control">
                                <button type="submit" class="btn btn-primary btn-sm">重置密码</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="9" class="admin-users-center">暂无账号</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
        </main>
    </div>
    <script>
    (function(){
        var roleEl = document.getElementById('create_role');
        var box = document.getElementById('agent_customer_box');
        if (!roleEl || !box) return;
        function sync() {
            var isAgent = (roleEl.value || '') === 'agent';
            box.style.display = isAgent ? 'block' : 'none';
        }
        roleEl.addEventListener('change', sync);
        sync();
    })();
    </script>
</body>
</html>

