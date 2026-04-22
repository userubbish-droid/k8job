<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_permissions';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$actor_role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$actor_can_set_admin_month = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);
$actor_can_set_contact_view = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);
$actor_can_set_internal_txn = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);

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

$bosses_list_ui = [];
if ($actor_is_superadmin) {
    try {
        $bosses_list_ui = $pdo->query("SELECT u.id, u.username, u.display_name, u.company_id, COALESCE(c.code, '') AS company_code
            FROM users u
            LEFT JOIN companies c ON c.id = u.company_id
            WHERE u.role = 'boss'
            ORDER BY u.company_id ASC, u.username ASC")->fetchAll();
    } catch (Throwable $e) {
        $bosses_list_ui = [];
    }
}

$perm_tab = trim((string)($_GET['tab'] ?? 'all'));
if (!in_array($perm_tab, ['all', 'admin', 'member'], true)) {
    $perm_tab = 'all';
}

$allowed_perm_user_ids = array_values(array_unique(array_merge(
    array_map('intval', array_column($members, 'id')),
    array_map('intval', array_column($admins_list_ui, 'id')),
    array_map('intval', array_column($bosses_list_ui, 'id'))
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
foreach ($bosses_list_ui as $b) {
    $perm_rows_all[] = array_merge($b, ['perm_role' => 'boss']);
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
                if ($actor_can_set_contact_view && !empty($_POST['view_customer_total_dp_wd'])) {
                    $stmt->execute([$tid, PERM_VIEW_CUSTOMER_TOTAL_DP_WD]);
                }
                header('Location: admin_permissions.php?tab=' . rawurlencode($tab_post) . '&pick=' . $tid . '&ok=1');
                exit;
            } elseif ($trole === 'admin' || $trole === 'boss') {
                if (!$actor_can_set_admin_month && !$actor_can_set_contact_view && !$actor_can_set_internal_txn) {
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
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$tid, PERM_VIEW_CUSTOMER_TOTAL_DP_WD]);
                        if (!empty($_POST['view_customer_total_dp_wd'])) {
                            $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)')->execute([$tid, PERM_VIEW_CUSTOMER_TOTAL_DP_WD]);
                        }
                    }
                    if ($actor_can_set_internal_txn) {
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$tid, PERM_TRANSACTION_VIEW_INTERNAL]);
                        if (!empty($_POST['transaction_view_internal'])) {
                            $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)')->execute([$tid, PERM_TRANSACTION_VIEW_INTERNAL]);
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
    if (!$pick_user_row || !in_array($pick_role, ['member', 'admin', 'boss'], true)) {
        $pick_id = 0;
        $pick_user_row = null;
        $pick_role = '';
    }
}

$admin_has_month = false;
$contact_user_has_view = false;
$admin_dp_wd_has_view = false;
$admin_has_internal_txn = false;
if ($pick_id > 0 && in_array($pick_role, ['admin', 'boss'], true)) {
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
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stmt->execute([$pick_id, PERM_VIEW_CUSTOMER_TOTAL_DP_WD]);
            $admin_dp_wd_has_view = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $admin_dp_wd_has_view = false;
        }
    }
    if ($actor_can_set_internal_txn) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stmt->execute([$pick_id, PERM_TRANSACTION_VIEW_INTERNAL]);
            $admin_has_internal_txn = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $admin_has_internal_txn = false;
        }
    }
}

$options = get_permission_options();
$current = [];
$member_contact_has_view = false;
$member_dp_wd_has_view = false;
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
        try {
            $stc = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
            $stc->execute([$pick_id, PERM_VIEW_CUSTOMER_TOTAL_DP_WD]);
            $member_dp_wd_has_view = (bool) $stc->fetch();
        } catch (Throwable $e) {
            $member_dp_wd_has_view = false;
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
        /* 右侧权限：与侧栏相同的分组 DOM / 折叠逻辑（nav-group-toggle + display），浅色主内容区样式 */
        .perm-ui-detail-title { font-size: 15px; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
        .perm-ui-detail-title span {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
        }
        .perm-nav-panel { margin: 0; }
        .perm-nav-panel .perm-nav-groups { margin: 0; padding: 0; }
        .perm-nav-panel .nav-group { border-bottom: 1px solid var(--border); }
        .perm-nav-panel .nav-group:last-child { border-bottom: none; }
        .perm-nav-panel .perm-other-group { margin-top: 4px; border-top: 1px solid var(--border); }
        .perm-nav-panel .perm-other-group .nav-group-sub .perm-item-plain:first-child { border-top: none; margin-top: 0; padding-top: 4px; }
        .perm-nav-panel .nav-group-toggle.nav-item {
            width: 100%;
            box-sizing: border-box;
            margin: 0;
            padding: 12px 4px 12px 0;
            display: flex;
            flex-direction: row;
            align-items: center;
            cursor: pointer;
            text-align: left;
            background: none;
            border: none;
            font: inherit;
            color: #0f172a;
            font-size: 15px;
            font-weight: 700;
            justify-content: flex-start;
            border-radius: var(--radius-sm, 8px);
        }
        .perm-nav-panel .nav-group-toggle.nav-item:hover { background: rgba(59, 130, 246, 0.06); }
        .perm-nav-panel .nav-group-toggle .nav-group-label { flex: 1; }
        .perm-nav-panel .nav-group-toggle .nav-icon {
            width: 18px;
            height: 18px;
            margin-right: 12px;
            border-radius: 6px;
            background: rgba(15, 23, 42, 0.12);
            flex-shrink: 0;
        }
        .perm-nav-panel .nav-group-chevron {
            margin-left: 8px;
            font-size: 12px;
            font-weight: 900;
            color: var(--muted);
            opacity: 0.95;
        }
        .perm-nav-panel .nav-group-sub {
            padding-left: 12px;
            margin: 2px 0 10px 22px;
            border-left: 2px solid rgba(99, 102, 241, 0.35);
        }
        .perm-nav-panel .perm-perm-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 14px;
            margin: 0 0 3px 0;
            border-radius: var(--radius-sm, 8px);
        }
        .perm-nav-panel .perm-perm-row:hover { background: rgba(99, 102, 241, 0.06); }
        .perm-nav-panel .perm-perm-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            cursor: pointer;
        }
        .perm-nav-panel .perm-label {
            font-weight: 600;
            font-size: 14px;
            color: #1f2937;
            cursor: pointer;
        }
        .perm-nav-panel .perm-item-plain {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            margin-top: 8px;
            border-top: 1px solid var(--border);
        }
        .perm-nav-panel .perm-item-plain input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .perm-nav-panel .btn-primary { margin-top: 16px; }
        @media (max-width: 768px) {
            .perm-nav-panel .nav-group-sub { margin-left: 16px; }
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
        .perm-ui-role--boss { background: #ffedd5; color: #9a3412; }
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
            <h2><?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?></h2>
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
                                <span class="perm-ui-role <?= $pr === 'admin' ? 'perm-ui-role--admin' : ($pr === 'boss' ? 'perm-ui-role--boss' : 'perm-ui-role--member') ?>"><?= htmlspecialchars(strtoupper($pr), ENT_QUOTES, 'UTF-8') ?></span>
                                <?= $line ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($pick_id <= 0 || !$pick_user_row): ?>
                        <p class="perm-ui-empty"><?= htmlspecialchars(__('perm_select_user_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php elseif ($pick_role === 'member'): ?>
                        <div class="perm-nav-panel" role="region" aria-label="<?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <h3 class="perm-ui-detail-title"><?= htmlspecialchars((string)$pick_user_row['username'], ENT_QUOTES, 'UTF-8') ?><span><?= htmlspecialchars(__('perm_tab_member'), ENT_QUOTES, 'UTF-8') ?></span></h3>
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
                                ];
                                $group_id = 0;
                            ?>
                            <div class="perm-nav-groups" id="perm-groups">
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
                                    $toggle_id = 'perm-toggle-' . $group_id;
                                    $sub_id = 'perm-group-sub-' . $group_id;
                                ?>
                                <div class="nav-group" data-group="<?= (int)$group_id ?>">
                                    <button type="button" class="nav-group-toggle nav-item" id="<?= htmlspecialchars($toggle_id, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="<?= $expanded ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($sub_id, ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="nav-icon" aria-hidden="true"></span>
                                        <span class="nav-group-label"><?= htmlspecialchars($glabel) ?></span>
                                        <span class="nav-group-chevron" aria-hidden="true"><?= $expanded ? '▾' : '▸' ?></span>
                                    </button>
                                    <div class="nav-group-sub" id="<?= htmlspecialchars($sub_id, ENT_QUOTES, 'UTF-8') ?>" role="region" aria-labelledby="<?= htmlspecialchars($toggle_id, ENT_QUOTES, 'UTF-8') ?>" style="display:<?= $expanded ? 'block' : 'none' ?>">
                                        <?php foreach ($keys as $key):
                                            $label = (string)($options[$key] ?? $key);
                                        ?>
                                        <div class="perm-perm-row">
                                            <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($key) ?>" id="perm_<?= htmlspecialchars($key) ?>" <?= in_array($key, $current, true) ? 'checked' : '' ?>>
                                            <label class="perm-label" for="perm_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="nav-group perm-other-group" data-group="other">
                                <button type="button" class="nav-group-toggle nav-item" id="perm-toggle-other-m" aria-expanded="false" aria-controls="perm-group-sub-other-m">
                                    <span class="nav-icon" aria-hidden="true"></span>
                                    <span class="nav-group-label"><?= htmlspecialchars(__('perm_group_other'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="nav-group-chevron" aria-hidden="true">▸</span>
                                </button>
                                <div class="nav-group-sub" id="perm-group-sub-other-m" role="region" aria-labelledby="perm-toggle-other-m" style="display:none">
                                    <?php if (array_key_exists('transaction_edit_request', $options)): ?>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="perms[]" value="transaction_edit_request" id="perm_other_tx_edit_req_m" <?= in_array('transaction_edit_request', $current, true) ? 'checked' : '' ?>>
                                        <label class="perm-label" for="perm_other_tx_edit_req_m"><?= htmlspecialchars((string)($options['transaction_edit_request'] ?? 'transaction_edit_request'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (array_key_exists('transaction_time_filter', $options)): ?>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="perms[]" value="transaction_time_filter" id="perm_other_tx_time_filter_m" <?= in_array('transaction_time_filter', $current, true) ? 'checked' : '' ?>>
                                        <label class="perm-label" for="perm_other_tx_time_filter_m"><?= htmlspecialchars((string)($options['transaction_time_filter'] ?? 'transaction_time_filter'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($actor_can_set_contact_view): ?>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="view_member_contact" value="1" id="view_member_contact_m" <?= $member_contact_has_view ? 'checked' : '' ?>>
                                        <label class="perm-label" for="view_member_contact_m"><?= htmlspecialchars(__('perm_allow_view_customer_phone'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="view_customer_total_dp_wd" value="1" id="view_customer_total_dp_wd_m" <?= $member_dp_wd_has_view ? 'checked' : '' ?>>
                                        <label class="perm-label" for="view_customer_total_dp_wd_m"><?= htmlspecialchars(__('perm_allow_view_customer_total_dp_wd'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('perm_btn_save_permissions'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                        </div>
                    <?php elseif ($pick_role === 'admin' || $pick_role === 'boss'): ?>
                        <div class="perm-nav-panel" role="region" aria-label="<?= htmlspecialchars(__('perm_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <h3 class="perm-ui-detail-title"><?= htmlspecialchars((string)$pick_user_row['username'], ENT_QUOTES, 'UTF-8') ?><span><?= htmlspecialchars(strtoupper($pick_role), ENT_QUOTES, 'UTF-8') ?></span></h3>
                        <?php if (!$actor_can_set_admin_month && !$actor_can_set_contact_view && !$actor_can_set_internal_txn): ?>
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
                            <?php if ($actor_can_set_internal_txn): ?>
                            <div class="perm-item perm-item-plain">
                                <input type="checkbox" name="transaction_view_internal" value="1" id="admin_txn_internal" <?= $admin_has_internal_txn ? 'checked' : '' ?>>
                                <label class="perm-label" for="admin_txn_internal"><?= htmlspecialchars(__('perm_allow_transaction_view_internal'), ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                            <?php endif; ?>
                            <div class="nav-group perm-other-group" data-group="other-admin">
                                <button type="button" class="nav-group-toggle nav-item" id="perm-toggle-other-a" aria-expanded="false" aria-controls="perm-group-sub-other-a">
                                    <span class="nav-icon" aria-hidden="true"></span>
                                    <span class="nav-group-label"><?= htmlspecialchars(__('perm_group_other'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="nav-group-chevron" aria-hidden="true">▸</span>
                                </button>
                                <div class="nav-group-sub" id="perm-group-sub-other-a" role="region" aria-labelledby="perm-toggle-other-a" style="display:none">
                                    <?php if (array_key_exists('transaction_edit_request', $options)): ?>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="perms[]" value="transaction_edit_request" id="perm_other_tx_edit_req_a" <?= in_array('transaction_edit_request', $current, true) ? 'checked' : '' ?>>
                                        <label class="perm-label" for="perm_other_tx_edit_req_a"><?= htmlspecialchars((string)($options['transaction_edit_request'] ?? 'transaction_edit_request'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($actor_can_set_contact_view): ?>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="view_member_contact" value="1" id="view_member_contact_a" <?= $contact_user_has_view ? 'checked' : '' ?>>
                                        <label class="perm-label" for="view_member_contact_a"><?= htmlspecialchars(__('perm_allow_view_customer_phone'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <div class="perm-item perm-item-plain">
                                        <input type="checkbox" name="view_customer_total_dp_wd" value="1" id="view_customer_total_dp_wd_a" <?= $admin_dp_wd_has_view ? 'checked' : '' ?>>
                                        <label class="perm-label" for="view_customer_total_dp_wd_a"><?= htmlspecialchars(__('perm_allow_view_customer_total_dp_wd'), ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
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
</body>
</html>
