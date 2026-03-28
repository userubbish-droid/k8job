<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_users';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');

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
    $sql = "ALTER TABLE users MODIFY role ENUM('superadmin','admin','member','agent') NOT NULL DEFAULT 'member'";
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
            if (!in_array($role, ['superadmin', 'admin', 'member', 'agent'], true)) {
                throw new RuntimeException('角色不正确。');
            }
            if ($role === 'superadmin' && !$actor_is_superadmin) {
                throw new RuntimeException('仅平台管理员可创建 superadmin 账号。');
            }

            if (in_array($role, ['agent', 'superadmin'], true)) {
                // 兼容旧库：旧版本 users.role 仅支持 admin/member
                ensure_users_role_supports_agent($pdo);
                if (!is_array($agent_customers) || empty($agent_customers)) {
                    if ($role === 'agent') {
                        throw new RuntimeException('请选择至少 1 个客户（该客户将归属此 Agent）。');
                    }
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, display_name, company_id) VALUES (?, ?, ?, ?, ?)");
            if ($role === 'superadmin') {
                $company_id = null;
            } elseif ($actor_is_superadmin) {
                $pick = (int)($_POST['create_company_id'] ?? 0);
                if ($pick <= 0) {
                    throw new RuntimeException('请选择该账号所属的分公司。');
                }
                $stmtC = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
                $stmtC->execute([$pick]);
                if (!$stmtC->fetch()) {
                    throw new RuntimeException('所选分公司无效或已停用。');
                }
                $company_id = $pick;
            } else {
                $cid_new = current_company_id();
                if ($cid_new <= 0) {
                    throw new RuntimeException('无法确定所属公司，请重新登录。');
                }
                $company_id = $cid_new;
            }
            $stmt->execute([$username, $hash, $role, $display_name !== '' ? $display_name : null, $company_id]);

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
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException('无权限操作该账号。');
            }
            if (!in_array($role, ['superadmin', 'admin', 'member', 'agent'], true)) {
                throw new RuntimeException('角色不正确。');
            }
            if ($role === 'superadmin' && !$actor_is_superadmin) {
                throw new RuntimeException('仅平台管理员可将账号设为 superadmin。');
            }
            if (in_array($role, ['agent', 'superadmin'], true)) {
                // 兼容旧库：旧版本 users.role 仅支持 admin/member
                ensure_users_role_supports_agent($pdo);
            }
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $oldRole = (string)($stmt->fetchColumn() ?: '');
            $self = ($id === (int)($_SESSION['user_id'] ?? 0));
            $curActorRole = (string)($_SESSION['user_role'] ?? '');
            if ($self) {
                if ($curActorRole === 'admin' && $role !== 'admin') {
                    throw new RuntimeException('不能把当前登录账号改为非 admin。');
                }
                if ($curActorRole === 'superadmin' && !in_array($role, ['superadmin', 'admin'], true)) {
                    throw new RuntimeException('不能把当前账号改为该角色。');
                }
            }
            if ($role === 'superadmin') {
                $stmt = $pdo->prepare("UPDATE users SET role = ?, company_id = NULL WHERE id = ?");
                $stmt->execute([$role, $id]);
            } elseif ($oldRole === 'superadmin' && $role !== 'superadmin') {
                $ncid = current_company_id();
                if ($ncid <= 0) {
                    throw new RuntimeException('请先在侧栏选择公司，以便将该账号归入该公司。');
                }
                $stmt = $pdo->prepare("UPDATE users SET role = ?, company_id = ? WHERE id = ?");
                $stmt->execute([$role, $ncid, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $id]);
            }
            $msg = '角色已更新。';
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException('无权限操作该账号。');
            }
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException('不能禁用自己。');

            $stmt = $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新账号状态。';
        } elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException('无权限操作该账号。');
            }
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
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException('无权限操作该账号。');
            }

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            $msg = '密码已重置。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $raw = (string)$e->getMessage();
        if (strpos($raw, 'Duplicate entry') !== false) {
            if (strpos($raw, 'login_scope_key') !== false || strpos($raw, 'uq_users_login_scope') !== false) {
                $err = '创建失败：该分公司下此用户名已存在；其他分公司可使用相同用户名。';
            } elseif (strpos($raw, "for key 'username'") !== false) {
                $err = '创建失败：用户名已存在，请直接在下方账号列表修改角色或重置密码。';
            } else {
                $err = '创建失败：数据冲突（可能为重复用户名），请检查分公司与用户名组合。';
            }
        } else {
            $err = $raw;
        }
    }
}

$status_sql = '';
if ($status_filter === 'active') {
    $status_sql = ' AND is_active = 1';
} elseif ($status_filter === 'inactive') {
    $status_sql = ' AND is_active = 0';
}

$view_company_id = current_company_id();
$view_company_label = '';
if ($view_company_id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT code, name FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$view_company_id]);
        $cr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cr) {
            $view_company_label = trim((string)($cr['code'] ?? '')) . ' — ' . trim((string)($cr['name'] ?? ''));
        }
    } catch (Throwable $e) {
        $view_company_label = '#' . $view_company_id;
    }
}

$company_users = [];
if (!$actor_is_superadmin && $view_company_id > 0) {
    $sql = "SELECT id, username, role, display_name, is_active, last_login_at, last_login_ip, created_at FROM users
            WHERE role != 'superadmin' AND company_id = ?" . $status_sql . ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$view_company_id]);
    $company_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$all_company_users = [];
if ($actor_is_superadmin) {
    $sql_all = "SELECT u.id, u.username, u.role, u.display_name, u.is_active, u.last_login_at, u.last_login_ip, u.created_at, u.company_id,
                       COALESCE(c.code, '') AS company_code, COALESCE(c.name, '') AS company_name
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                WHERE u.role != 'superadmin'" . $status_sql . '
                ORDER BY u.company_id ASC, u.id DESC';
    $all_company_users = $pdo->query($sql_all)->fetchAll(PDO::FETCH_ASSOC);
}

$superadmin_users = [];
if ($actor_is_superadmin) {
    $sql_sa = "SELECT id, username, role, display_name, is_active, last_login_at, last_login_ip, created_at FROM users
               WHERE role = 'superadmin'" . $status_sql . ' ORDER BY id DESC';
    $superadmin_users = $pdo->query($sql_sa)->fetchAll(PDO::FETCH_ASSOC);
}

$users_primary_list = $actor_is_superadmin ? $all_company_users : $company_users;
$users_primary_show_company_col = $actor_is_superadmin;
$users_primary_colspan = $users_primary_show_company_col ? 10 : 9;

$role_opts_company = ['admin', 'member', 'agent'];
$role_opts_platform = ['superadmin', 'admin', 'member', 'agent'];

$companies_for_create = [];
if ($actor_is_superadmin) {
    try {
        $companies_for_create = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companies_for_create = [];
    }
}
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
            <h2><?= $actor_is_superadmin ? '用户管理（全平台）' : '用户管理（本公司）' ?></h2>
            <p class="breadcrumb">当前：<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>（<?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>）<?= $actor_is_superadmin ? ' · 可查看所有分公司账号与平台管理员' : ' · 仅本公司账号，无平台 superadmin' ?></p>
            <?php if ($actor_is_superadmin): ?>
            <p class="agent-customer-hint" style="margin-top:10px;">新增或停用<strong>分公司</strong>（公司代码、登录可选公司）请到 <a href="admin_companies.php">分公司管理</a>。</p>
            <?php endif; ?>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3>创建账号</h3>
            <?php if ($actor_is_superadmin): ?>
            <p class="agent-customer-hint" style="margin-top:-6px;">新建 admin / member / agent 时请在下方选择<strong>所属分公司</strong>（与「分公司管理」里的代码对应）；创建平台 <strong>superadmin</strong> 时不选公司。分公司管理员登录请在登录页 Company 填该公司代码。</p>
            <?php if (!$companies_for_create): ?>
            <div class="alert alert-error" role="status">当前没有「启用」的分公司，请先到 <a href="admin_companies.php">分公司管理</a> 新增。</div>
            <?php endif; ?>
            <?php else: ?>
            <p class="agent-customer-hint" style="margin-top:-6px;">新账号仅可创建在本公司；平台 superadmin 仅平台总管理员可见与创建。</p>
            <?php endif; ?>
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
                            <?php if ($actor_is_superadmin): ?>
                            <option value="superadmin">superadmin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>显示名称（可选）</label>
                        <input class="form-control" name="display_name" placeholder="例如 小明">
                    </div>
                </div>
                <?php if ($actor_is_superadmin && $companies_for_create): ?>
                <div class="form-group" id="create_company_wrap">
                    <label>所属分公司 *</label>
                    <select class="form-control" name="create_company_id" id="create_company_id" required>
                        <?php
                        $sess_cid = current_company_id();
                        foreach ($companies_for_create as $co):
                            $cid = (int)($co['id'] ?? 0);
                            if ($cid <= 0) {
                                continue;
                            }
                        ?>
                        <option value="<?= $cid ?>" <?= $cid === $sess_cid ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)($co['code'] ?? ''))) ?> — <?= htmlspecialchars(trim((string)($co['name'] ?? ''))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
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

        <?php
        $filter_links = '<div class="admin-users-actions" style="margin-bottom:10px;">'
            . '<a class="btn btn-sm ' . ($status_filter === 'all' ? 'btn-primary' : 'btn-outline') . '" href="admin_users.php?status_filter=all">显示全部</a>'
            . '<a class="btn btn-sm ' . ($status_filter === 'active' ? 'btn-primary' : 'btn-outline') . '" href="admin_users.php?status_filter=active">仅启用</a>'
            . '<a class="btn btn-sm ' . ($status_filter === 'inactive' ? 'btn-primary' : 'btn-outline') . '" href="admin_users.php?status_filter=inactive">仅禁用</a>'
            . '</div>';
        ?>
        <div class="card">
            <h3><?= $actor_is_superadmin ? '全部分公司账号' : '本公司账号' ?><?= !$actor_is_superadmin && $view_company_label !== '' ? '（' . htmlspecialchars($view_company_label) . '）' : '' ?></h3>
            <p class="agent-customer-hint" style="margin-top:-6px;"><?= $actor_is_superadmin
                ? '汇总所有公司的 admin / member / agent；平台 superadmin 仅在下方单独列表。本表仅 superadmin 登录后可见。'
                : '不含平台 superadmin；与当前侧栏所选公司一致。分公司管理员无法查看其他公司或平台管理员。' ?></p>
            <?= $filter_links ?>
            <?php if (!$actor_is_superadmin && $view_company_id <= 0): ?>
                <div class="alert alert-error" role="status">无法加载本公司账号（缺少公司上下文），请重新登录。</div>
            <?php endif; ?>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <?php if ($users_primary_show_company_col): ?><th>公司</th><?php endif; ?>
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
                <?php foreach ($users_primary_list as $u):
                    $co_code = trim((string)($u['company_code'] ?? ''));
                    $co_name = trim((string)($u['company_name'] ?? ''));
                    $co_disp = ($co_code !== '' || $co_name !== '')
                        ? ($co_code . ($co_name !== '' ? ' — ' . $co_name : ''))
                        : (((int)($u['company_id'] ?? 0) > 0) ? ('#' . (int)$u['company_id']) : '—');
                ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <?php if ($users_primary_show_company_col): ?><td><?= htmlspecialchars($co_disp) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                        <td>
                            <form method="post" class="admin-users-role-form">
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <select name="role" class="form-control">
                                    <?php foreach ($role_opts_company as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($u['role'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
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
                <?php if (!$users_primary_list): ?>
                    <tr><td colspan="<?= (int)$users_primary_colspan ?>" class="admin-users-center"><?= $actor_is_superadmin ? '暂无各分公司账号' : ($view_company_id > 0 ? '暂无本公司账号' : '未选择公司') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php if ($actor_is_superadmin): ?>
        <div class="card">
            <h3>平台管理员（superadmin）</h3>
            <p class="agent-customer-hint" style="margin-top:-6px;">不归属任何公司；与上方各分公司账号分开。<strong>仅 superadmin 登录后显示本区块</strong>；将账号降级为分公司角色时，将归入侧栏当前所选公司。</p>
            <?= $filter_links ?>
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
                <?php foreach ($superadmin_users as $u): ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                        <td>
                            <form method="post" class="admin-users-role-form">
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <select name="role" class="form-control">
                                    <?php foreach ($role_opts_platform as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($u['role'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
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
                <?php if (!$superadmin_users): ?>
                    <tr><td colspan="9" class="admin-users-center">暂无 superadmin 账号</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
        </main>
    </div>
    <script>
    (function(){
        var roleEl = document.getElementById('create_role');
        var box = document.getElementById('agent_customer_box');
        var companyWrap = document.getElementById('create_company_wrap');
        var companySel = document.getElementById('create_company_id');
        function sync() {
            var v = roleEl ? (roleEl.value || '') : '';
            if (box) {
                box.style.display = v === 'agent' ? 'block' : 'none';
            }
            if (companyWrap && companySel) {
                var hideCo = v === 'superadmin';
                companyWrap.style.display = hideCo ? 'none' : '';
                companySel.required = !hideCo;
            }
        }
        if (roleEl) {
            roleEl.addEventListener('change', sync);
            sync();
        }
    })();
    </script>
</body>
</html>

