<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_permissions';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$actor_can_set_admin_month = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);

$msg = '';
$err = '';

$members = [];
try {
    if ($actor_is_superadmin) {
        $members = $pdo->query("SELECT u.id, u.username, u.display_name, u.company_id, COALESCE(c.code, '') AS company_code
            FROM users u
            LEFT JOIN companies c ON c.id = u.company_id
            WHERE u.role = 'member'
            ORDER BY u.company_id ASC, u.username ASC")->fetchAll();
    } else {
        $cid = current_company_id();
        $stmt = $pdo->prepare("SELECT id, username, display_name, company_id FROM users WHERE role = 'member' AND company_id = ? ORDER BY username ASC");
        $stmt->execute([$cid]);
        $members = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $members = [];
}

$admins_for_month = [];
if ($actor_can_set_admin_month) {
    try {
        if ($actor_is_superadmin) {
            $admins_for_month = $pdo->query("SELECT u.id, u.username, u.display_name, u.company_id, COALESCE(c.code, '') AS company_code
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                WHERE u.role = 'admin'
                ORDER BY u.company_id ASC, u.username ASC")->fetchAll();
        } else {
            $cid = current_company_id();
            $stmt = $pdo->prepare("SELECT id, username, display_name, company_id FROM users WHERE role = 'admin' AND company_id = ? ORDER BY username ASC");
            $stmt->execute([$cid]);
            $admins_for_month = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $admins_for_month = [];
    }
}

$selected_id = (int)($_REQUEST['user_id'] ?? $_POST['user_id'] ?? 0);
if ($selected_id > 0 && !in_array($selected_id, array_column($members, 'id'), true)) {
    $selected_id = 0;
}

$selected_admin_id = (int)($_REQUEST['admin_user_id'] ?? $_POST['admin_user_id'] ?? 0);
if ($selected_admin_id > 0 && !in_array($selected_admin_id, array_column($admins_for_month, 'id'), true)) {
    $selected_admin_id = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_admin_month') {
    if (!$actor_can_set_admin_month) {
        $err = '仅 Boss 或平台 big boss 可设置 Admin 的首页本月数据权限。';
    } elseif ($selected_admin_id <= 0) {
        $err = '请选择有效的 Admin。';
    } elseif (!user_is_manageable_by_current_actor($pdo, $selected_admin_id)) {
        $err = '无权限保存该用户的设置。';
    } else {
        try {
            $chk = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $chk->execute([$selected_admin_id]);
            $rrow = $chk->fetch(PDO::FETCH_ASSOC);
            if (($rrow['role'] ?? '') !== 'admin') {
                $err = '仅可为角色为 Admin 的用户设置此项。';
            } else {
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$selected_admin_id, PERM_DASHBOARD_MONTH_DATA]);
                if (!empty($_POST['dashboard_month_data'])) {
                    $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)')->execute([$selected_admin_id, PERM_DASHBOARD_MONTH_DATA]);
                }
                $msg = '已更新该 Admin 的首页「本月数据」权限。';
            }
        } catch (Throwable $e) {
            $em = $e->getMessage();
            if (strpos($em, 'user_permissions') !== false && (strpos($em, "doesn't exist") !== false || strpos($em, '1146') !== false)) {
                $err = '保存失败：尚未创建权限表。请执行 migrate_user_permissions.sql 中的 SQL 后重试。';
            } else {
                $err = '保存失败：' . htmlspecialchars($em);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_id > 0 && ($_POST['action'] ?? '') !== 'save_admin_month') {
    if (!user_is_manageable_by_current_actor($pdo, $selected_id)) {
        $err = '无权限保存该员工的权限（仅可管理本公司 Member；平台总管理员可管理全部）。';
    } else {
        $checked = $_POST['perms'] ?? [];
        if (!is_array($checked)) {
            $checked = [];
        }
        $options = get_permission_options();
        $valid = array_keys($options);
        $to_insert = array_intersect($checked, $valid);

        try {
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$selected_id]);
            $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)");
            foreach ($to_insert as $key) {
                $stmt->execute([$selected_id, $key]);
            }
            $msg = '已保存该员工的权限。';
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, "user_permissions") !== false && (strpos($msg, "doesn't exist") !== false || strpos($msg, '1146') !== false)) {
                $err = '保存失败：尚未创建权限表。请到 Hostinger 的 phpMyAdmin 中选中当前数据库，执行一次 <strong>migrate_user_permissions.sql</strong> 里的 SQL（创建 user_permissions 表），保存后再试。';
            } else {
                $err = '保存失败：' . htmlspecialchars($msg);
            }
        }
    }
}

$admin_has_month = false;
if ($actor_can_set_admin_month && $selected_admin_id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
        $stmt->execute([$selected_admin_id, PERM_DASHBOARD_MONTH_DATA]);
        $admin_has_month = (bool) $stmt->fetch();
    } catch (Throwable $e) {
        $admin_has_month = false;
    }
}

$options = get_permission_options();
$current = [];
if ($selected_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$selected_id]);
        $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $current = [];
        $msg = $e->getMessage();
        if (empty($err) && (strpos($msg, "user_permissions") !== false && (strpos($msg, "doesn't exist") !== false || strpos($msg, '1146') !== false))) {
            $err = '当前数据库缺少 <strong>user_permissions</strong> 表。请到 phpMyAdmin 选中数据库，执行 <strong>migrate_user_permissions.sql</strong> 中的 SQL 创建该表后刷新本页。';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>权限设置 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
        .perm-list { list-style: none; padding: 0; margin: 0; }
        .perm-list li { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .perm-list li:last-child { border-bottom: none; }
        .perm-list input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .member-select { margin-bottom: 20px; }
        /* 与侧栏一致：整行实线分隔，全宽对齐卡片内容区 */
        .perm-groups { margin: 0 -28px; padding: 0; list-style: none; width: calc(100% + 56px); }
        .perm-group { border-bottom: 1px solid var(--border); }
        .perm-group:last-child { border-bottom: none; }
        .perm-group-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 28px;
            border: none;
            background: transparent;
            color: #0f172a;
            font-weight: 700;
            cursor: pointer;
            text-align: left;
            font-size: 15px;
        }
        .perm-group-toggle:hover { background: rgba(59,130,246,0.06); }
        .perm-group-chevron { margin-left: auto; color: var(--muted); font-weight: 900; }
        .perm-group-sub { display: none; padding: 0; margin: 0; }
        .perm-group-sub.show {
            display: block;
            border-top: 1px solid var(--border);
        }
        .perm-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 28px;
            margin: 0;
            border-bottom: 1px solid var(--border);
        }
        .perm-item:last-child { border-bottom: none; }
        .perm-label { font-weight: 600; color: #1f2937; font-size: 15px; }
        .perm-legacy { color: var(--muted); font-weight: 600; }
        .perm-item-plain { border: none; padding: 12px 0; }
        @media (max-width: 768px) {
            .perm-groups { margin: 0 -16px; width: calc(100% + 32px); }
            .perm-group-toggle,
            .perm-item { padding-left: 16px; padding-right: 16px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 560px;">
        <div class="page-header">
            <h2>权限设置<?= $actor_is_superadmin ? '（全平台）' : '（本公司）' ?></h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="admin_users.php">用户管理</a>
                <?= $actor_is_superadmin ? '' : '<span>·</span><span>列表仅含本公司 Member</span>' ?>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin-top:0;">Member 权限</h3>
            <form method="get" class="member-select">
                <?php if ($selected_admin_id > 0): ?><input type="hidden" name="admin_user_id" value="<?= (int)$selected_admin_id ?>"><?php endif; ?>
                <div class="form-group">
                    <label>选择 Member</label>
                    <select name="user_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($members as $m):
                            $m_cc = trim((string)($m['company_code'] ?? ''));
                            $co_tag = ($actor_is_superadmin && $m_cc !== '') ? (' [' . $m_cc . ']') : '';
                        ?>
                            <option value="<?= (int)$m['id'] ?>" <?= $selected_id === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['username']) ?>
                                <?= $m['display_name'] ? '（' . htmlspecialchars($m['display_name']) . '）' : '' ?><?= htmlspecialchars($co_tag) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selected_id > 0): ?>
            <form method="post">
                <input type="hidden" name="user_id" value="<?= $selected_id ?>">
                <?php
                    // 分组标题与顺序对齐 inc/sidebar.php 的 nav_* 文案（__() 与侧栏一致）
                    $perm_groups = [
                        __('nav_home') => ['home_dashboard', 'statement_report'],
                        __('nav_statement') => ['statement_balance'],
                        __('nav_transactions') => ['transaction_list'],
                        __('nav_add') => ['transaction_create', 'customer_create'],
                        __('nav_expense') => ['expense_statement', 'kiosk_expense_view', 'kiosk_statement'],
                        __('nav_rebate') => ['rebate', 'agent'],
                        __('nav_customer_detail') => ['customers', 'product_library', 'customer_edit'],
                        __('perm_group_legacy') => ['statement'],
                    ];
                    $group_id = 0;
                ?>
                <ul class="perm-groups" id="perm-groups">
                    <?php foreach ($perm_groups as $glabel => $keys):
                        $group_id++;
                        // 过滤掉不存在的 key（避免未来改名导致报错）
                        $keys = array_values(array_filter($keys, function($k) use ($options){ return array_key_exists($k, $options); }));
                        if (!$keys) continue;
                        $expanded = false;
                        foreach ($keys as $k) { if (in_array($k, $current, true)) { $expanded = true; break; } }
                    ?>
                    <li class="perm-group" data-group="<?= $group_id ?>">
                        <button type="button" class="perm-group-toggle" aria-expanded="<?= $expanded ? 'true' : 'false' ?>" aria-controls="perm-group-sub-<?= $group_id ?>">
                            <span><?= htmlspecialchars($glabel) ?></span>
                            <span class="perm-group-chevron" aria-hidden="true"><?= $expanded ? '▾' : '▸' ?></span>
                        </button>
                        <div class="perm-group-sub<?= $expanded ? ' show' : '' ?>" id="perm-group-sub-<?= $group_id ?>">
                            <?php foreach ($keys as $key):
                                $label = (string)($options[$key] ?? $key);
                                $isLegacy = ($key === 'statement');
                            ?>
                            <div class="perm-item">
                                <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($key) ?>" id="perm_<?= htmlspecialchars($key) ?>" <?= in_array($key, $current, true) ? 'checked' : '' ?>>
                                <label class="perm-label<?= $isLegacy ? ' perm-legacy' : '' ?>" for="perm_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <button type="submit" class="btn btn-primary" style="margin-top: 16px;">保存权限</button>
            </form>
            <?php else: ?>
            <?php endif; ?>
        </div>

        <?php if ($actor_can_set_admin_month): ?>
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-top:0;">Admin · 首页本月数据</h3>
            <form method="get" class="member-select">
                <?php if ($selected_id > 0): ?><input type="hidden" name="user_id" value="<?= (int)$selected_id ?>"><?php endif; ?>
                <div class="form-group">
                    <label>选择 Admin</label>
                    <select name="admin_user_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($admins_for_month as $a):
                            $a_cc = trim((string)($a['company_code'] ?? ''));
                            $co_tag = ($actor_is_superadmin && $a_cc !== '') ? (' [' . $a_cc . ']') : '';
                        ?>
                            <option value="<?= (int)$a['id'] ?>" <?= $selected_admin_id === (int)$a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['username']) ?>
                                <?= $a['display_name'] ? '（' . htmlspecialchars($a['display_name']) . '）' : '' ?><?= htmlspecialchars($co_tag) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if ($selected_admin_id > 0): ?>
            <form method="post">
                <input type="hidden" name="action" value="save_admin_month">
                <input type="hidden" name="admin_user_id" value="<?= (int)$selected_admin_id ?>">
                <?php if ($selected_id > 0): ?><input type="hidden" name="user_id" value="<?= (int)$selected_id ?>"><?php endif; ?>
                <div class="perm-item perm-item-plain">
                    <input type="checkbox" name="dashboard_month_data" value="1" id="admin_dash_month" <?= $admin_has_month ? 'checked' : '' ?>>
                    <label class="perm-label" for="admin_dash_month">允许查看首页本月数据</label>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: 12px;">保存</button>
            </form>
            <?php elseif (empty($admins_for_month)): ?>
            <p class="form-hint">当前范围内没有 Admin 账号。</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
        </main>
    </div>
    <script>
    (function(){
        document.querySelectorAll('.perm-group-toggle').forEach(function(btn){
            var subId = btn.getAttribute('aria-controls');
            var sub = subId ? document.getElementById(subId) : null;
            var chev = btn.querySelector('.perm-group-chevron');
            if (!sub) return;
            btn.addEventListener('click', function(){
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                sub.classList.toggle('show', !expanded);
                if (chev) chev.textContent = expanded ? '▸' : '▾';
            });
        });
    })();
    </script>
</body>
</html>
