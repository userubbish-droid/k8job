<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_permissions';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$actor_role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$actor_can_set_admin_month = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);
$actor_can_set_contact_view = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);

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
        $stmt = $pdo->prepare("SELECT id, username, display_name, company_id, '' AS company_code FROM users WHERE role = 'member' AND company_id = ? ORDER BY username ASC");
        $stmt->execute([$cid]);
        $members = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $members = [];
}

$admins_list_ui = [];
if (in_array($actor_role, ['boss', 'superadmin'], true)) {
    try {
        if ($actor_is_superadmin) {
            $admins_list_ui = $pdo->query("SELECT u.id, u.username, u.display_name, u.company_id, COALESCE(c.code, '') AS company_code
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                WHERE u.role = 'admin'
                ORDER BY u.company_id ASC, u.username ASC")->fetchAll();
        } else {
            $cid = current_company_id();
            $stmt = $pdo->prepare("SELECT id, username, display_name, company_id, '' AS company_code FROM users WHERE role = 'admin' AND company_id = ? ORDER BY username ASC");
            $stmt->execute([$cid]);
            $admins_list_ui = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $admins_list_ui = [];
    }
}

$perm_tab = trim((string)($_GET['tab'] ?? 'all'));
if (!in_array($perm_tab, ['all', 'admin', 'member'], true)) {
    $perm_tab = 'all';
}

$allowed_perm_user_ids = array_values(array_unique(array_merge(
    array_map('intval', array_column($members, 'id')),
    array_map('intval', array_column($admins_list_ui, 'id'))
)));

$pick_id = (int)($_GET['pick'] ?? 0);
if ($pick_id > 0 && !in_array($pick_id, $allowed_perm_user_ids, true)) {
    $pick_id = 0;
}

$perm_rows_all = [];
foreach ($members as $m) {
    $perm_rows_all[] = array_merge($m, ['perm_role' => 'member']);
}
foreach ($admins_list_ui as $a) {
    $perm_rows_all[] = array_merge($a, ['perm_role' => 'admin']);
}
usort($perm_rows_all, static function (array $x, array $y): int {
    $cmp = strcmp((string)($x['username'] ?? ''), (string)($y['username'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int)($x['id'] ?? 0)) <=> ((int)($y['id'] ?? 0));
});

$perm_rows_filtered = $perm_rows_all;
if ($perm_tab === 'admin') {
    $perm_rows_filtered = array_values(array_filter($perm_rows_all, static function (array $r): bool {
        return ($r['perm_role'] ?? '') === 'admin';
    }));
} elseif ($perm_tab === 'member') {
    $perm_rows_filtered = array_values(array_filter($perm_rows_all, static function (array $r): bool {
        return ($r['perm_role'] ?? '') === 'member';
    }));
}

if ($pick_id > 0) {
    $vids = array_map('intval', array_column($perm_rows_filtered, 'id'));
    if (!in_array($pick_id, $vids, true)) {
        $pick_id = 0;
    }
}

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $msg = __('perm_ok_panel_saved');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_perm_panel') {
    $tab_post = trim((string)($_POST['tab'] ?? 'all'));
    if (!in_array($tab_post, ['all', 'admin', 'member'], true)) {
        $tab_post = 'all';
    }
    $tid = (int)($_POST['target_user_id'] ?? 0);
    if ($tid <= 0 || !in_array($tid, $allowed_perm_user_ids, true)) {
        $err = __('perm_err_pick_invalid');
    } elseif (!user_is_manageable_by_current_actor($pdo, $tid)) {
        $err = __('perm_err_save_user_forbidden');
    } else {
        try {
            $chk = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$tid]);
            $trole = strtolower(trim((string)($chk->fetchColumn() ?: '')));
            if ($trole === 'member') {
                $checked = $_POST['perms'] ?? [];
                if (!is_array($checked)) {
                    $checked = [];
                }
                $options = get_permission_options();
                $valid = array_keys($options);
                $to_insert = array_intersect($checked, $valid);
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$tid]);
                $stmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)');
                foreach ($to_insert as $key) {
                    $stmt->execute([$tid, $key]);
                }
                if ($actor_can_set_contact_view && !empty($_POST['view_member_contact'])) {
                    $stmt->execute([$tid, PERM_VIEW_MEMBER_CONTACT]);
                }
                header('Location: admin_permissions.php?tab=' . rawurlencode($tab_post) . '&pick=' . $tid . '&ok=1');
                exit;
            } elseif ($trole === 'admin') {
                if (!$actor_can_set_admin_month && !$actor_can_set_contact_view) {
                    $err = __('perm_err_boss_only_admin_opts');
                } else {
                    if ($actor_can_set_admin_month) {
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$tid, PERM_DASHBOARD_MONTH_DATA]);
                        if (!empty($_POST['dashboard_month_data'])) {
                            $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)')->execute([$tid, PERM_DASHBOARD_MONTH_DATA]);
                        }
                    }
                    if ($actor_can_set_contact_view) {
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$tid, PERM_VIEW_MEMBER_CONTACT]);
                        if (!empty($_POST['view_member_contact'])) {
                            $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)')->execute([$tid, PERM_VIEW_MEMBER_CONTACT]);
                        }
                    }
                    header('Location: admin_permissions.php?tab=' . rawurlencode($tab_post) . '&pick=' . $tid . '&ok=1');
                    exit;
                }
            } else {
                $err = __('perm_err_role_not_supported');
            }
        } catch (Throwable $e) {
            $em = $e->getMessage();
            if (strpos($em, 'user_permissions') !== false && (strpos($em, "doesn't exist") !== false || strpos($em, '1146') !== false)) {
                $err = __('perm_err_migration_short');
            } else {
                $err = __f('perm_err_save_failed_reason', htmlspecialchars($em, ENT_QUOTES, 'UTF-8'));
            }
        }
    }
}

$pick_role = '';
$pick_user_row = null;
if ($pick_id > 0) {
    try {
        $pr = $pdo->prepare('SELECT id, username, display_name, role FROM users WHERE id = ? LIMIT 1');
        $pr->execute([$pick_id]);
        $pick_user_row = $pr->fetch(PDO::FETCH_ASSOC);
        if ($pick_user_row) {
            $pick_role = strtolower(trim((string)($pick_user_row['role'] ?? '')));
        }
    } catch (Throwable $e) {
        $pick_user_row = null;
        $pick_role = '';
    }
    if (!$pick_user_row || !in_array($pick_role, ['member', 'admin'], true)) {
        $pick_id = 0;
        $pick_user_row = null;
        $pick_role = '';
    }
}

$admin_has_month = false;
$contact_user_has_view = false;
if ($pick_id > 0 && $pick_role === 'admin') {
    if ($actor_can_set_admin_month) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stmt->execute([$pick_id, PERM_DASHBOARD_MONTH_DATA]);
            $admin_has_month = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $admin_has_month = false;
        }
    }
    if ($actor_can_set_contact_view) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stmt->execute([$pick_id, PERM_VIEW_MEMBER_CONTACT]);
            $contact_user_has_view = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $contact_user_has_view = false;
        }
    }
}

$options = get_permission_options();
$current = [];
$member_contact_has_view = false;
if ($pick_id > 0 && $pick_role === 'member') {
    try {
        $stmt = $pdo->prepare('SELECT permission_key FROM user_permissions WHERE user_id = ?');
        $stmt->execute([$pick_id]);
        $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $current = [];
        if (empty($err) && (strpos($e->getMessage(), 'user_permissions') !== false && (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), '1146') !== false))) {
            $err = __('perm_err_missing_table_page');
        }
    }
    if ($actor_can_set_contact_view) {
        try {
            $stc = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stc->execute([$pick_id, PERM_VIEW_MEMBER_CONTACT]);
            $member_contact_has_view = (bool) $stc->fetch();
        } catch (Throwable $e) {
            $member_contact_has_view = false;
        }
    }
}

function perm_ui_build_pick_url(string $tab, int $userId): string {
    $q = ['tab' => $tab];
    if ($userId > 0) {
        $q['pick'] = $userId;
    }
    return 'admin_permissions.php?' . http_build_query($q);
}

$disp_o = app_lang() === 'en' ? ' (' : '（';
$disp_c = app_lang() === 'en' ? ')' : '）';
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
        /* 右侧权限区：视觉对齐 .dashboard-sidebar（深蓝渐变 + 白字 + 分组缩进） */
        .perm-detail-sidebar {
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 12px 0 40px rgba(8, 12, 28, 0.45);
            background:
                radial-gradient(200px 200px at 10% 8%, rgba(139, 92, 246, 0.32) 0%, transparent 72%),
                radial-gradient(260px 240px at 100% 100%, rgba(167, 139, 250, 0.14) 0%, transparent 70%),
                linear-gradient(195deg, #121a2e 0%, #151d32 45%, #0c1222 100%);
            border: 1px solid rgba(0, 0, 0, 0.28);
        }
        .perm-detail-sidebar__title {
            margin: 0;
            padding: 16px 20px 14px;
            font-size: 16px;
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.02em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }
        .perm-detail-sidebar__title span {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255, 255, 255, 0.55);
        }
        .perm-detail-sidebar--empty {
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .perm-detail-sidebar__empty-text {
            margin: 0;
            padding: 28px 22px;
            font-size: 14px;
            line-height: 1.55;
            color: rgba(255, 255, 255, 0.68);
            text-align: center;
        }
        .perm-detail-sidebar .form-hint {
            margin: 0 20px 16px;
            color: rgba(255, 255, 255, 0.65) !important;
            font-size: 13px;
            line-height: 1.5;
        }
        .perm-detail-sidebar form { padding-bottom: 6px; }
        .perm-detail-sidebar .perm-groups {
            margin: 0;
            padding: 6px 0 10px;
            list-style: none;
            width: 100%;
        }
        .perm-detail-sidebar .perm-group {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .perm-detail-sidebar .perm-group:last-child { border-bottom: none; }
        .perm-detail-sidebar .perm-group-toggle {
            width: calc(100% - 22px);
            box-sizing: border-box;
            margin: 0 11px 5px 11px;
            padding: 11px 18px;
            display: flex;
            flex-direction: row;
            align-items: center;
            border: none;
            border-radius: var(--radius-md, 12px);
            border-left: 3px solid transparent;
            background: transparent;
            color: rgba(255, 255, 255, 0.92);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .perm-detail-sidebar .perm-group-toggle::before {
            content: '';
            width: 18px;
            height: 18px;
            margin-right: 12px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.25);
            flex-shrink: 0;
        }
        .perm-detail-sidebar .perm-group-toggle:hover {
            background: rgba(255, 255, 255, 0.09);
            color: #fff;
        }
        .perm-detail-sidebar .perm-group-chevron {
            margin-left: auto;
            color: rgba(255, 255, 255, 0.75);
            font-weight: 900;
            font-size: 12px;
        }
        .perm-detail-sidebar .perm-group-sub {
            display: none;
            padding: 0 0 6px 0;
            margin: 2px 11px 8px 33px;
            border-left: 2px solid rgba(129, 140, 248, 0.35);
        }
        .perm-detail-sidebar .perm-group-sub.show { display: block; }
        .perm-detail-sidebar .perm-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 14px;
            margin: 0 0 3px 0;
            border-radius: var(--radius-sm, 8px);
            border-bottom: none;
            transition: background 0.15s ease;
        }
        .perm-detail-sidebar .perm-item:hover {
            background: rgba(255, 255, 255, 0.06);
        }
        .perm-detail-sidebar .perm-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            accent-color: #a5b4fc;
            cursor: pointer;
        }
        .perm-detail-sidebar .perm-label {
            font-weight: 600;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.9);
            cursor: pointer;
        }
        .perm-detail-sidebar .perm-legacy {
            color: rgba(255, 255, 255, 0.55);
        }
        .perm-detail-sidebar .perm-item-plain {
            border: none;
            margin: 10px 11px 0;
            padding: 12px 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0;
        }
        .perm-detail-sidebar .perm-item-plain:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .perm-detail-sidebar .btn-primary {
            display: block;
            width: calc(100% - 22px);
            margin: 14px 11px 12px;
            padding: 12px 16px;
            border-radius: var(--radius-md, 12px);
            border: 1px solid rgba(165, 180, 252, 0.45);
            background: linear-gradient(90deg, rgba(129, 140, 248, 0.42) 0%, rgba(77, 100, 248, 0.28) 100%);
            color: #fff !important;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: filter 0.15s ease, box-shadow 0.15s ease;
        }
        .perm-detail-sidebar .btn-primary:hover {
            filter: brightness(1.06);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }
        @media (max-width: 768px) {
            .perm-detail-sidebar .perm-group-toggle { width: calc(100% - 16px); margin-left: 8px; margin-right: 8px; }
            .perm-detail-sidebar .perm-group-sub { margin-left: 26px; margin-right: 8px; }
            .perm-detail-sidebar .btn-primary { width: calc(100% - 16px); margin-left: 8px; margin-right: 8px; }
        }
        .perm-ui-seg {
            display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px;
        }
        .perm-ui-seg a {
            padding: 8px 16px; border-radius: 999px; font-weight: 700; font-size: 13px;
            text-decoration: none; border: 1px solid rgba(99, 102, 241, 0.35);
            background: rgba(255,255,255,0.9); color: #4338ca;
        }
        .perm-ui-seg a:hover { background: rgba(238, 242, 255, 0.95); }
        .perm-ui-seg a.is-active {
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            color: #fff; border-color: transparent;
        }
        .perm-ui-layout {
            display: grid; grid-template-columns: minmax(200px, 260px) 1fr; gap: 20px; align-items: start;
        }
        @media (max-width: 768px) {
            .perm-ui-layout { grid-template-columns: 1fr; }
        }
        .perm-ui-list {
            list-style: none; margin: 0; padding: 0; max-height: min(52vh, 420px); overflow: auto;
            border: 1px solid var(--border); border-radius: 12px; background: rgba(255,255,255,0.92);
        }
        .perm-ui-list li { border-bottom: 1px solid var(--border); }
        .perm-ui-list li:last-child { border-bottom: none; }
        .perm-ui-list a {
            display: block; padding: 10px 12px; text-decoration: none; color: #0f172a; font-size: 14px;
        }
        .perm-ui-list a:hover { background: rgba(99, 102, 241, 0.06); }
        .perm-ui-list a.is-active {
            background: rgba(99, 102, 241, 0.12); font-weight: 700;
        }
        .perm-ui-role {
            display: inline-block; font-size: 10px; font-weight: 800; letter-spacing: 0.04em;
            padding: 2px 6px; border-radius: 4px; margin-right: 6px; vertical-align: middle;
        }
        .perm-ui-role--admin { background: #fee2e2; color: #991b1b; }
        .perm-ui-role--member { background: #d1fae5; color: #065f46; }
        .perm-ui-empty { color: var(--muted); font-size: 14px; padding: 12px 0; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 920px;">
        <div class="page-header">
            <h2><?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($actor_is_superadmin ? __('perm_scope_all') : __('perm_scope_company'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="breadcrumb">
                <a href="dashboard.php"><?= htmlspecialchars(__('nav_home'), ENT_QUOTES, 'UTF-8') ?></a><span>·</span>
                <a href="admin_users.php"><?= htmlspecialchars(__('nav_user_management'), ENT_QUOTES, 'UTF-8') ?></a>
                <?= $actor_is_superadmin ? '' : '<span>·</span><span>' . htmlspecialchars(__('perm_breadcrumb_co_extra'), ENT_QUOTES, 'UTF-8') . '</span>' ?>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <nav class="perm-ui-seg" aria-label="<?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                <a href="<?= htmlspecialchars(perm_ui_build_pick_url('all', 0), ENT_QUOTES, 'UTF-8') ?>" class="<?= $perm_tab === 'all' ? 'is-active' : '' ?>"><?= htmlspecialchars(__('perm_tab_all'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="<?= htmlspecialchars(perm_ui_build_pick_url('admin', 0), ENT_QUOTES, 'UTF-8') ?>" class="<?= $perm_tab === 'admin' ? 'is-active' : '' ?>"><?= htmlspecialchars(__('perm_tab_admin'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="<?= htmlspecialchars(perm_ui_build_pick_url('member', 0), ENT_QUOTES, 'UTF-8') ?>" class="<?= $perm_tab === 'member' ? 'is-active' : '' ?>"><?= htmlspecialchars(__('perm_tab_member'), ENT_QUOTES, 'UTF-8') ?></a>
            </nav>

            <div class="perm-ui-layout">
                <div>
                    <div class="form-hint" style="margin-bottom:8px; font-weight:700;"><?= htmlspecialchars(__('perm_accounts_heading'), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!$perm_rows_filtered): ?>
                        <p class="perm-ui-empty"><?= htmlspecialchars(__('perm_no_accounts_in_tab'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php else: ?>
                    <ul class="perm-ui-list">
                        <?php foreach ($perm_rows_filtered as $row):
                            $rid = (int)$row['id'];
                            $pr = (string)($row['perm_role'] ?? '');
                            $href = htmlspecialchars(perm_ui_build_pick_url($perm_tab, $rid), ENT_QUOTES, 'UTF-8');
                            $cc = trim((string)($row['company_code'] ?? ''));
                            $co_tag = ($actor_is_superadmin && $cc !== '') ? (' [' . htmlspecialchars($cc, ENT_QUOTES, 'UTF-8') . ']') : '';
                            $dn = trim((string)($row['display_name'] ?? ''));
                            $line = htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8')
                                . ($dn !== '' ? htmlspecialchars($disp_o, ENT_QUOTES, 'UTF-8') . htmlspecialchars($dn) . htmlspecialchars($disp_c, ENT_QUOTES, 'UTF-8') : '')
                                . $co_tag;
                        ?>
                        <li>
                            <a href="<?= $href ?>" class="<?= $pick_id === $rid ? 'is-active' : '' ?>">
                                <span class="perm-ui-role <?= $pr === 'admin' ? 'perm-ui-role--admin' : 'perm-ui-role--member' ?>"><?= htmlspecialchars(strtoupper($pr), ENT_QUOTES, 'UTF-8') ?></span>
                                <?= $line ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($pick_id <= 0 || !$pick_user_row): ?>
                        <div class="perm-detail-sidebar perm-detail-sidebar--empty" role="region" aria-label="<?= htmlspecialchars(__('perm_select_user_hint'), ENT_QUOTES, 'UTF-8') ?>">
                            <p class="perm-detail-sidebar__empty-text"><?= htmlspecialchars(__('perm_select_user_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php elseif ($pick_role === 'member'): ?>
                        <div class="perm-detail-sidebar" role="region" aria-label="<?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <h3 class="perm-detail-sidebar__title"><?= htmlspecialchars((string)$pick_user_row['username'], ENT_QUOTES, 'UTF-8') ?><span><?= htmlspecialchars(__('perm_tab_member'), ENT_QUOTES, 'UTF-8') ?></span></h3>
                        <form method="post">
                            <input type="hidden" name="action" value="save_perm_panel">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($perm_tab, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="target_user_id" value="<?= (int)$pick_id ?>">
                            <?php
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
                                    $keys = array_values(array_filter($keys, static function ($k) use ($options) { return array_key_exists($k, $options); }));
                                    if (!$keys) {
                                        continue;
                                    }
                                    $expanded = false;
                                    foreach ($keys as $k) {
                                        if (in_array($k, $current, true)) {
                                            $expanded = true;
                                            break;
                                        }
                                    }
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
                            <?php if ($actor_can_set_contact_view): ?>
                            <div class="perm-item perm-item-plain">
                                <input type="checkbox" name="view_member_contact" value="1" id="view_member_contact_m" <?= $member_contact_has_view ? 'checked' : '' ?>>
                                <label class="perm-label" for="view_member_contact_m"><?= htmlspecialchars(__('perm_allow_view_customer_phone'), ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('perm_btn_save_permissions'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                        </div>
                    <?php elseif ($pick_role === 'admin'): ?>
                        <div class="perm-detail-sidebar" role="region" aria-label="<?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <h3 class="perm-detail-sidebar__title"><?= htmlspecialchars((string)$pick_user_row['username'], ENT_QUOTES, 'UTF-8') ?><span><?= htmlspecialchars(__('perm_tab_admin'), ENT_QUOTES, 'UTF-8') ?></span></h3>
                        <?php if (!$actor_can_set_admin_month && !$actor_can_set_contact_view): ?>
                            <p class="form-hint"><?= htmlspecialchars(__('perm_err_boss_only_admin_opts'), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="save_perm_panel">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($perm_tab, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="target_user_id" value="<?= (int)$pick_id ?>">
                            <?php if ($actor_can_set_admin_month): ?>
                            <div class="perm-item perm-item-plain">
                                <input type="checkbox" name="dashboard_month_data" value="1" id="admin_dash_month" <?= $admin_has_month ? 'checked' : '' ?>>
                                <label class="perm-label" for="admin_dash_month"><?= htmlspecialchars(__('perm_allow_dashboard_month'), ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                            <?php endif; ?>
                            <?php if ($actor_can_set_contact_view): ?>
                            <div class="perm-item perm-item-plain">
                                <input type="checkbox" name="view_member_contact" value="1" id="view_member_contact_a" <?= $contact_user_has_view ? 'checked' : '' ?>>
                                <label class="perm-label" for="view_member_contact_a"><?= htmlspecialchars(__('perm_allow_view_customer_phone'), ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                        <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>
    <script>
    (function(){
        document.querySelectorAll('.perm-detail-sidebar .perm-group-toggle').forEach(function(btn){
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
