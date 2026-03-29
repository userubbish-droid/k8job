<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_users';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');

$msg = '';
$err = '';
$customers_for_agent = [];
$status_filter = trim((string)($_GET['status_filter'] ?? 'active'));
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'active';
}
$search_q = trim((string)($_GET['q'] ?? ''));

function redirect_self(): void {
    header('Location: admin_users.php');
    exit;
}

function um_role_badge_class(string $role): string {
    switch ($role) {
        case 'superadmin':
            return 'um-badge um-badge-bb';
        case 'boss':
            return 'um-badge um-badge-boss';
        case 'admin':
            return 'um-badge um-badge-admin';
        case 'member':
            return 'um-badge um-badge-member';
        case 'agent':
            return 'um-badge um-badge-agent';
        default:
            return 'um-badge um-badge-member';
    }
}

function um_role_badge_text(string $role): string {
    switch ($role) {
        case 'superadmin':
            return 'BIG BOSS';
        case 'boss':
            return 'BOSS';
        case 'admin':
            return 'ADMIN';
        case 'member':
            return 'MEMBER';
        case 'agent':
            return 'AGENT';
        default:
            return strtoupper($role);
    }
}

function ensure_users_role_enum(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('superadmin','boss','admin','member','agent') NOT NULL DEFAULT 'member'");
    } catch (Throwable $e) {
    }
}

function ensure_users_login_meta(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at"); } catch (Throwable $e) {}
}

ensure_users_login_meta($pdo);

try {
    // Agent 绑定下线用：客户列表（显示 code + name）
    $cid_agent_pick = current_company_id();
    if ($cid_agent_pick > 0) {
        $stmtCa = $pdo->prepare("SELECT code, name, COALESCE(recommend,'') AS recommend FROM customers WHERE company_id = ? AND code IS NOT NULL AND TRIM(code) != '' ORDER BY code ASC");
        $stmtCa->execute([$cid_agent_pick]);
        $customers_for_agent = $stmtCa->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $customers_for_agent = [];
    }
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
            $email = trim((string)($_POST['email'] ?? ''));
            $agent_customers = $_POST['agent_customers'] ?? [];

            if ($username === '' || $password === '') {
                throw new RuntimeException(__('adm_users_err_fill_user_pass'));
            }
            if (strlen($email) > 255) {
                throw new RuntimeException(__('adm_users_err_email_len'));
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(__('adm_users_err_invalid_email'));
            }
            if (!in_array($role, ['superadmin', 'boss', 'admin', 'member', 'agent'], true)) {
                throw new RuntimeException(__('adm_users_err_invalid_role'));
            }
            if ($role === 'superadmin' && !$actor_is_superadmin) {
                throw new RuntimeException(__('adm_users_err_sa_only_create'));
            }
            if ($role === 'boss' && !$actor_is_superadmin) {
                throw new RuntimeException(__('adm_users_err_boss_only'));
            }
            ensure_users_role_enum($pdo);
            if (in_array($role, ['agent', 'superadmin', 'boss'], true)) {
                if (!is_array($agent_customers) || empty($agent_customers)) {
                    if ($role === 'agent') {
                        throw new RuntimeException(__('adm_users_err_agent_customers'));
                    }
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
            $creator_uid = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, display_name, company_id, email, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if ($role === 'superadmin') {
                $company_id = null;
            } elseif ($actor_is_superadmin) {
                $pick = (int)($_POST['create_company_id'] ?? 0);
                if ($pick <= 0) {
                    throw new RuntimeException(__('adm_users_err_pick_company'));
                }
                $stmtC = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
                $stmtC->execute([$pick]);
                if (!$stmtC->fetch()) {
                    throw new RuntimeException(__('adm_users_err_company_bad'));
                }
                $company_id = $pick;
            } else {
                if (in_array($role, ['superadmin', 'boss'], true)) {
                    throw new RuntimeException(__('adm_users_err_no_perm_role'));
                }
                $cid_new = current_company_id();
                if ($cid_new <= 0) {
                    throw new RuntimeException(__('adm_users_err_company_unknown'));
                }
                $company_id = $cid_new;
            }
            $stmt->execute([
                $username,
                $hash,
                $role,
                $display_name !== '' ? $display_name : null,
                $company_id,
                $email !== '' ? $email : null,
                $creator_uid > 0 ? $creator_uid : null,
            ]);

                if ($role === 'agent') {
                    $codes = array_values(array_filter(array_map(function($x){
                        return trim((string)$x);
                    }, $agent_customers), function($x){ return $x !== ''; }));
                    $codes = array_values(array_unique($codes));
                    if (empty($codes)) {
                        throw new RuntimeException(__('adm_users_err_agent_customers'));
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
            $msg = __f('adm_users_msg_created', $username, role_label($role));
        } elseif ($action === 'change_role') {
            $id = (int)($_POST['id'] ?? 0);
            $role = trim($_POST['role'] ?? 'member');
            if ($id <= 0) throw new RuntimeException(__('adm_users_err_param'));
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException(__('adm_users_err_no_perm_account'));
            }
            if (!in_array($role, ['superadmin', 'boss', 'admin', 'member', 'agent'], true)) {
                throw new RuntimeException(__('adm_users_err_invalid_role'));
            }
            if ($role === 'superadmin' && !$actor_is_superadmin) {
                throw new RuntimeException(__('adm_users_err_sa_set_sa'));
            }
            if ($role === 'boss' && !$actor_is_superadmin) {
                throw new RuntimeException(__('adm_users_err_sa_set_boss'));
            }
            if (in_array($role, ['agent', 'superadmin', 'boss'], true)) {
                ensure_users_role_enum($pdo);
            }
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $oldRole = (string)($stmt->fetchColumn() ?: '');
            if (!$actor_is_superadmin && $oldRole === 'boss') {
                throw new RuntimeException(__('adm_users_err_boss_locked_edit'));
            }
            $self = ($id === (int)($_SESSION['user_id'] ?? 0));
            $curActorRole = (string)($_SESSION['user_role'] ?? '');
            if ($self) {
                if ($curActorRole === 'admin' && $role !== 'admin') {
                    throw new RuntimeException(__('adm_users_err_self_not_admin'));
                }
                if ($curActorRole === 'superadmin' && !in_array($role, ['superadmin', 'boss', 'admin'], true)) {
                    throw new RuntimeException(__('adm_users_err_self_bad_role'));
                }
                if ($curActorRole === 'boss' && $role !== 'boss') {
                    throw new RuntimeException(__('adm_users_err_boss_self'));
                }
            }
            if ($role === 'superadmin') {
                $stmt = $pdo->prepare("UPDATE users SET role = ?, company_id = NULL WHERE id = ?");
                $stmt->execute([$role, $id]);
            } elseif ($oldRole === 'superadmin' && $role !== 'superadmin') {
                $ncid = current_company_id();
                if ($ncid <= 0) {
                    throw new RuntimeException(__('adm_users_err_pick_company_sidebar'));
                }
                $stmt = $pdo->prepare("UPDATE users SET role = ?, company_id = ? WHERE id = ?");
                $stmt->execute([$role, $ncid, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $id]);
            }
            $msg = __('adm_users_msg_role_ok');
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('adm_users_err_param'));
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException(__('adm_users_err_no_perm_account'));
            }
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException(__('adm_users_err_disable_self'));

            $stmt = $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = __('adm_users_msg_toggle_ok');
        } elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('adm_users_err_param'));
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException(__('adm_users_err_no_perm_account'));
            }
            if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new RuntimeException(__('adm_users_err_delete_self'));

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
            $msg = __('adm_users_msg_deleted');
        } elseif ($action === 'bulk_delete') {
            $ids = $_POST['bulk_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ids = array_filter($ids, static function ($x) {
                return $x > 0;
            });
            if ($ids === []) {
                throw new RuntimeException(__('adm_users_bulk_none'));
            }
            $self_id = (int)($_SESSION['user_id'] ?? 0);
            $deleted = 0;
            foreach ($ids as $bid) {
                if ($bid === $self_id) {
                    continue;
                }
                if (!user_is_manageable_by_current_actor($pdo, $bid)) {
                    continue;
                }
                $pdo->beginTransaction();
                try {
                    try {
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$bid]);
                    } catch (Throwable $e) {
                    }
                    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$bid]);
                    $pdo->commit();
                    ++$deleted;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
            if ($deleted === 0) {
                throw new RuntimeException(__('adm_users_err_no_perm_account'));
            }
            $msg = __('adm_users_msg_bulk_deleted');
        } elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $new_password = (string) ($_POST['new_password'] ?? '');
            if ($id <= 0 || $new_password === '') throw new RuntimeException(__('adm_users_err_fill_new_pass'));
            if (!user_is_manageable_by_current_actor($pdo, $id)) {
                throw new RuntimeException(__('adm_users_err_no_perm_account'));
            }

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            $msg = __('adm_users_msg_pass_reset');
        } else {
            throw new RuntimeException(__('adm_users_err_unknown_action'));
        }
    } catch (Throwable $e) {
        $raw = (string)$e->getMessage();
        if (strpos($raw, 'Duplicate entry') !== false) {
            if (strpos($raw, 'login_scope_key') !== false || strpos($raw, 'uq_users_login_scope') !== false) {
                $err = __('adm_users_err_dup_branch');
            } elseif (strpos($raw, "for key 'username'") !== false) {
                $err = __('adm_users_err_dup_username');
            } else {
                $err = __('adm_users_err_dup_generic');
            }
        } else {
            $err = $raw;
        }
    }
}

$show_create_modal_on_load = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && $err !== '');

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
    $sql = "SELECT u.id, u.username, u.role, u.display_name, COALESCE(u.email,'') AS email, u.is_active, u.last_login_at, u.last_login_ip, u.created_at, u.created_by_user_id,
                   COALESCE(ucr.username, '') AS created_by_username
            FROM users u
            LEFT JOIN users ucr ON ucr.id = u.created_by_user_id
            WHERE u.role != 'superadmin' AND u.company_id = ?" . $status_sql . ' ORDER BY u.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$view_company_id]);
    $company_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$all_company_users = [];
if ($actor_is_superadmin) {
    $sql_all = "SELECT u.id, u.username, u.role, u.display_name, COALESCE(u.email,'') AS email, u.is_active, u.last_login_at, u.last_login_ip, u.created_at, u.company_id, u.created_by_user_id,
                       COALESCE(c.code, '') AS company_code, COALESCE(c.name, '') AS company_name,
                       COALESCE(ucr.username, '') AS created_by_username
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                LEFT JOIN users ucr ON ucr.id = u.created_by_user_id
                WHERE u.role != 'superadmin'" . $status_sql . '
                ORDER BY u.company_id ASC, u.id DESC';
    $all_company_users = $pdo->query($sql_all)->fetchAll(PDO::FETCH_ASSOC);
}

$superadmin_users = [];
if ($actor_is_superadmin) {
    $sql_sa = "SELECT u.id, u.username, u.role, u.display_name, COALESCE(u.email,'') AS email, u.is_active, u.last_login_at, u.last_login_ip, u.created_at, u.created_by_user_id,
                      COALESCE(ucr.username, '') AS created_by_username
               FROM users u
               LEFT JOIN users ucr ON ucr.id = u.created_by_user_id
               WHERE u.role = 'superadmin'" . $status_sql . ' ORDER BY u.id DESC';
    $superadmin_users = $pdo->query($sql_sa)->fetchAll(PDO::FETCH_ASSOC);
}

$users_primary_list = $actor_is_superadmin ? $all_company_users : $company_users;
$users_primary_show_company_col = $actor_is_superadmin;
$users_primary_colspan = $users_primary_show_company_col ? 12 : 11;

if ($search_q !== '') {
    $low = static function (string $s): string {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    };
    $needle = $low($search_q);
    $filter_user_row = static function (array $u) use ($needle, $low): bool {
        $a = $low((string)($u['username'] ?? ''));
        $b = $low((string)($u['display_name'] ?? ''));
        $em = $low((string)($u['email'] ?? ''));
        return (strpos($a, $needle) !== false || strpos($b, $needle) !== false || strpos($em, $needle) !== false);
    };
    $users_primary_list = array_values(array_filter($users_primary_list, $filter_user_row));
    if ($actor_is_superadmin) {
        $superadmin_users = array_values(array_filter($superadmin_users, $filter_user_row));
    }
}

/** 分公司管理员改角色：不可设 boss / superadmin */
$role_opts_company = ['admin', 'member', 'agent'];
/** 平台 big boss 改分公司账号角色（顺序：boss → admin → member → agent） */
$role_opts_company_sa = ['boss', 'admin', 'member', 'agent'];
/** 平台 big boss 改平台账号角色（顺序：big boss → boss → admin → member → agent） */
$role_opts_platform = ['superadmin', 'boss', 'admin', 'member', 'agent'];

$companies_for_create = [];
if ($actor_is_superadmin) {
    try {
        $companies_for_create = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companies_for_create = [];
    }
}

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$bulk_confirm_tpl_json = json_encode(__('adm_users_bulk_confirm'), JSON_UNESCAPED_UNICODE);
$bulk_none_json = json_encode(__('adm_users_bulk_none'), JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__f('adm_users_page_title', defined('SITE_TITLE') ? SITE_TITLE : 'K8'), ENT_QUOTES, 'UTF-8') ?></title>
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
        .um-page-bg {
            background: linear-gradient(180deg, #dbeafe 0%, #eff6ff 38%, #f8fafc 100%);
            padding-bottom: 28px;
            margin: 0 -8px;
            padding-left: 8px;
            padding-right: 8px;
        }
        .um-table-panel {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.22);
            margin-bottom: 20px;
        }
        .um-table-panel > h3 {
            margin: 0;
            padding: 16px 18px 0;
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
        }
        .um-table-wrap { overflow-x: auto; }
        table.um-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.um-table thead th {
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
            color: #fff;
            font-weight: 700;
            text-align: left;
            padding: 14px 11px;
            border: none;
            white-space: nowrap;
        }
        table.um-table tbody tr:nth-child(even) { background: #eff6ff; }
        table.um-table tbody tr:nth-child(odd) { background: #fff; }
        table.um-table tbody tr:hover { filter: brightness(0.98); }
        table.um-table tbody td { padding: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.22); vertical-align: middle; color: #1e293b; }
        .um-col-narrow { width: 44px; text-align: center; }
        .um-badge { display: inline-block; padding: 5px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.05em; }
        .um-badge-admin { background: #dc2626; color: #fff; }
        .um-badge-bb { background: #450a0a; color: #fff; }
        .um-badge-boss { background: #ea580c; color: #fff; }
        .um-badge-member { background: #bbf7d0; color: #166534; }
        .um-badge-agent { background: #a5f3fc; color: #0e7490; }
        .um-status-active { display: inline-block; background: #16a34a; color: #fff; padding: 5px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.04em; }
        .um-status-inactive { display: inline-block; background: #e2e8f0; color: #475569; padding: 5px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .um-actions { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
        .um-icon-edit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            text-decoration: none;
            font-size: 17px;
            line-height: 1;
            border: 1px solid #bfdbfe;
        }
        .um-icon-edit:hover { background: #dbeafe; color: #1e3a8a; }
        .um-more { position: relative; }
        .um-more > summary {
            list-style: none;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            font-size: 20px;
            line-height: 32px;
            text-align: center;
            user-select: none;
        }
        .um-more > summary::-webkit-details-marker { display: none; }
        .um-more[open] > summary { background: #e2e8f0; }
        .um-more-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 240px;
            max-width: min(92vw, 320px);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 14px 44px rgba(15, 23, 42, 0.16);
            border: 1px solid #e2e8f0;
            padding: 12px;
            z-index: 40;
        }
        .um-more-panel .um-mini-label { font-size: 11px; font-weight: 700; color: #64748b; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.04em; }
        .um-more-panel .form-control { width: 100%; margin-bottom: 6px; box-sizing: border-box; }
        .um-more-panel form { margin: 0 0 12px; }
        .um-more-panel form:last-child { margin-bottom: 0; }
        .admin-users-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 14px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(148, 163, 184, 0.14);
            margin-bottom: 16px;
        }
        .admin-users-toolbar .btn-add-user {
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(79, 125, 255, 0.35);
            white-space: nowrap;
        }
        .admin-users-toolbar .toolbar-search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 380px;
        }
        .admin-users-toolbar .toolbar-search-wrap .toolbar-search-input {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid rgba(148, 163, 184, 0.55);
            border-radius: 10px;
            font-size: 14px;
            background: #fff;
        }
        .admin-users-toolbar .toolbar-search-wrap .toolbar-search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 125, 255, 0.2);
        }
        .admin-users-toolbar .toolbar-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.5;
            pointer-events: none;
            font-size: 15px;
            line-height: 1;
        }
        .admin-users-toolbar .toolbar-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        .admin-users-toolbar .toolbar-check input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .admin-users-toolbar .toolbar-spacer { flex: 1; min-width: 12px; }
        .admin-users-toolbar .um-btn-bulk-del { font-weight: 700; white-space: nowrap; }
        .admin-users-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2500;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }
        .admin-users-modal.is-open {
            display: flex;
        }
        .admin-users-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            cursor: pointer;
        }
        .admin-users-modal-panel {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 560px;
            max-height: min(90vh, 720px);
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 64px rgba(15, 23, 42, 0.22);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .admin-users-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-light);
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2;
        }
        .admin-users-modal-head h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
        }
        .admin-users-modal-x {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 10px;
            background: rgba(241, 245, 249, 0.95);
            color: #475569;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
        }
        .admin-users-modal-x:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .admin-users-modal-body {
            padding: 18px 20px 22px;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap um-page-bg">
        <div class="page-header">
            <h2><?= htmlspecialchars($actor_is_superadmin ? __('adm_users_title_sa') : __('adm_users_title_co'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>

        <form method="get" action="admin_users.php" class="admin-users-toolbar" id="admin-users-toolbar-form" autocomplete="off">
            <input type="hidden" name="status_filter" id="toolbar_status_filter" value="<?= htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="btn btn-primary btn-add-user" id="btn-add-user"><?= htmlspecialchars(__('adm_users_btn_add'), ENT_QUOTES, 'UTF-8') ?></button>
            <div class="toolbar-search-wrap">
                <span class="toolbar-search-icon" aria-hidden="true">🔍</span>
                <input type="search" name="q" class="toolbar-search-input" placeholder="<?= htmlspecialchars(__('adm_users_search_ph'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($search_q, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="toolbar-spacer" aria-hidden="true"></div>
            <label class="toolbar-check">
                <input type="checkbox" id="toolbar_cb_inactive" <?= in_array($status_filter, ['all', 'inactive'], true) ? 'checked' : '' ?>>
                <?= htmlspecialchars(__('adm_users_include_inactive'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <button type="button" class="btn btn-danger um-btn-bulk-del" id="um-bulk-delete-btn"><?= htmlspecialchars(__('adm_users_bulk_delete'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <form id="um-bulk-delete-form" method="post" style="display:none;" aria-hidden="true">
            <input type="hidden" name="action" value="bulk_delete">
        </form>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err && !($show_create_modal_on_load ?? false)): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div id="create-account-modal" class="admin-users-modal<?= !empty($show_create_modal_on_load) ? ' is-open' : '' ?>" aria-hidden="<?= !empty($show_create_modal_on_load) ? 'false' : 'true' ?>">
            <div class="admin-users-modal-backdrop" id="create-account-modal-backdrop" tabindex="-1"></div>
            <div class="admin-users-modal-panel" role="dialog" aria-modal="true" aria-labelledby="create-account-title">
                <div class="admin-users-modal-head">
                    <h3 id="create-account-title"><?= htmlspecialchars(__('adm_users_create_title'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <button type="button" class="admin-users-modal-x" id="create-account-modal-close" aria-label="<?= htmlspecialchars(__('aria_close'), ENT_QUOTES, 'UTF-8') ?>">×</button>
                </div>
                <div class="admin-users-modal-body">
            <?php if ($show_create_modal_on_load ?? false): ?>
            <div class="alert alert-error" style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>
            <?php if ($actor_is_superadmin): ?>
            <?php if (!$companies_for_create): ?>
            <div class="alert alert-error" role="status"><?= htmlspecialchars(__('adm_users_no_active_company'), ENT_QUOTES, 'UTF-8') ?> <a href="admin_companies.php"><?= htmlspecialchars(__('adm_users_companies_link'), ENT_QUOTES, 'UTF-8') ?></a><?= htmlspecialchars(__('adm_users_no_active_company_tail'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php endif; ?>
            <form method="post" id="create-account-form">
                <input type="hidden" name="action" value="create">
                <div class="admin-users-grid">
                    <div class="form-group">
                        <label><?= htmlspecialchars(__('adm_users_lbl_username_req'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="username" required>
                    </div>
                    <div class="form-group">
                        <label><?= htmlspecialchars(__('adm_users_lbl_password_req'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                </div>
                <div class="admin-users-grid">
                    <div class="form-group">
                        <label><?= htmlspecialchars(__('adm_users_lbl_role_req'), ENT_QUOTES, 'UTF-8') ?></label>
                        <select class="form-control" name="role" id="create_role" required>
                            <?php if ($actor_is_superadmin): ?>
                            <option value="superadmin"><?= htmlspecialchars(role_label('superadmin')) ?></option>
                            <option value="boss"><?= htmlspecialchars(role_label('boss')) ?></option>
                            <option value="admin"><?= htmlspecialchars(role_label('admin')) ?></option>
                            <option value="member" selected><?= htmlspecialchars(role_label('member')) ?></option>
                            <option value="agent"><?= htmlspecialchars(role_label('agent')) ?></option>
                            <?php else: ?>
                            <option value="admin"><?= htmlspecialchars(role_label('admin')) ?></option>
                            <option value="member" selected><?= htmlspecialchars(role_label('member')) ?></option>
                            <option value="agent"><?= htmlspecialchars(role_label('agent')) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= htmlspecialchars(__('adm_users_lbl_display_opt'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="display_name" placeholder="<?= htmlspecialchars(__('adm_users_ph_display_example'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><?= htmlspecialchars(__('adm_users_lbl_email_opt'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" type="email" name="email" maxlength="255" autocomplete="off" placeholder="name@example.com">
                </div>
                <?php if ($actor_is_superadmin && $companies_for_create): ?>
                <div class="form-group" id="create_company_wrap">
                    <label><?= htmlspecialchars(__('adm_users_lbl_company_req'), ENT_QUOTES, 'UTF-8') ?></label>
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
                    <p class="form-hint" style="margin-top:8px;"><?= htmlspecialchars(__('adm_users_create_company_hint_sa'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php endif; ?>
                <div class="agent-customer-box" id="agent_customer_box" style="display:none;">
                    <h4><?= htmlspecialchars(__('adm_users_agent_pick_title'), ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="agent-customer-hint"><?= htmlspecialchars(__('adm_users_agent_pick_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="agent-customer-list" role="group" aria-label="<?= htmlspecialchars(__('adm_users_agent_pick_title'), ENT_QUOTES, 'UTF-8') ?>">
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
                            <span class="agent-customer-meta"><?= $crec !== '' ? (htmlspecialchars(__('adm_users_cur_referrer'), ENT_QUOTES, 'UTF-8') . htmlspecialchars($crec)) : (htmlspecialchars(__('adm_users_cur_referrer'), ENT_QUOTES, 'UTF-8') . '—') ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$customers_for_agent): ?>
                            <div style="color:var(--muted); padding:6px 2px;"><?= htmlspecialchars(__('adm_users_no_customers_bind'), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="admin-users-actions" style="margin-top: 12px;">
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('btn_create'), ENT_QUOTES, 'UTF-8') ?></button>
                    <button type="button" class="btn btn-outline" id="create-account-cancel"><?= htmlspecialchars(__('btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </form>
                </div>
            </div>
        </div>

        <div class="um-table-panel">
            <?php if ($actor_is_superadmin): ?>
            <h3><?= htmlspecialchars(__('adm_users_card_users_sa'), ENT_QUOTES, 'UTF-8') ?></h3>
            <?php endif; ?>
            <?php if (!$actor_is_superadmin && $view_company_id <= 0): ?>
                <div class="alert alert-error" role="status" style="margin:12px 18px;"><?= htmlspecialchars(__('adm_users_err_context'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="um-table-wrap">
            <table class="um-table" id="um-table-primary">
                <thead>
                    <tr>
                        <th class="um-col-narrow"><input type="checkbox" id="um-select-all-primary" aria-label="Select all"></th>
                        <th class="um-col-narrow"><?= htmlspecialchars(__('adm_users_th_no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php if ($users_primary_show_company_col): ?><th><?= htmlspecialchars(__('lbl_company'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                        <th><?= htmlspecialchars(__('adm_users_th_login_id'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_display_name'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('adm_users_th_email'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_role'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_last_login'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('adm_users_th_created_by'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users_primary_list as $idx => $u):
                    $uid = (int)$u['id'];
                    $is_self = ($uid === $session_user_id);
                    $r = (string)($u['role'] ?? '');
                    $co_code = trim((string)($u['company_code'] ?? ''));
                    $co_name = trim((string)($u['company_name'] ?? ''));
                    $co_disp = ($co_code !== '' || $co_name !== '')
                        ? ($co_code . ($co_name !== '' ? ' — ' . $co_name : ''))
                        : (((int)($u['company_id'] ?? 0) > 0) ? ('#' . (int)$u['company_id']) : '—');
                    $em = trim((string)($u['email'] ?? ''));
                    $cb = trim((string)($u['created_by_username'] ?? ''));
                    $ll = trim((string)($u['last_login_at'] ?? ''));
                ?>
                    <tr>
                        <td class="um-col-narrow"><input type="checkbox" class="um-row-chk um-row-chk-primary" form="um-bulk-delete-form" name="bulk_ids[]" value="<?= $uid ?>"<?= $is_self ? ' disabled' : '' ?>></td>
                        <td class="um-col-narrow"><?= (int)($idx + 1) ?></td>
                        <?php if ($users_primary_show_company_col): ?><td><?= htmlspecialchars($co_disp) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string)$u['username']) ?></td>
                        <td><?= htmlspecialchars(trim((string)($u['display_name'] ?? ''))) ?></td>
                        <td><?= $em !== '' ? htmlspecialchars($em) : '—' ?></td>
                        <td><span class="<?= htmlspecialchars(um_role_badge_class($r), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(um_role_badge_text($r), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?php if ((int)$u['is_active'] === 1): ?><span class="um-status-active"><?= htmlspecialchars(__('adm_users_um_active'), ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><span class="um-status-inactive"><?= htmlspecialchars(__('adm_users_um_inactive'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                        <td><?= $ll !== '' ? htmlspecialchars($ll) : '—' ?></td>
                        <td><?= $cb !== '' ? htmlspecialchars($cb) : '—' ?></td>
                        <td class="um-actions">
                            <a class="um-icon-edit" href="admin_user_edit.php?id=<?= $uid ?>" title="<?= htmlspecialchars(__('btn_edit'), ENT_QUOTES, 'UTF-8') ?>">✎</a>
                            <details class="um-more">
                                <summary title="<?= htmlspecialchars(__('adm_users_more_actions'), ENT_QUOTES, 'UTF-8') ?>">⋯</summary>
                                <div class="um-more-panel" onclick="event.stopPropagation();">
                                    <?php if (!$actor_is_superadmin && $r === 'boss'): ?>
                                        <p class="form-hint" style="margin:0;"><?= htmlspecialchars(role_label('boss') . __('adm_users_boss_locked'), ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php else: ?>
                                    <div class="um-mini-label"><?= htmlspecialchars(__('lbl_role'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <select name="role" class="form-control">
                                            <?php foreach (($actor_is_superadmin ? $role_opts_company_sa : $role_opts_company) as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>" <?= $r === $opt ? 'selected' : '' ?>><?= htmlspecialchars(role_label($opt)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;"><?= htmlspecialchars(__('btn_change_role'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-gray btn-sm" style="width:100%;"><?= ((int)$u['is_active'] === 1) ? htmlspecialchars(__('btn_disable'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('btn_enable'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <form method="post" data-confirm="<?= htmlspecialchars(__('adm_users_confirm_delete'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="width:100%;"<?= $is_self ? ' disabled' : '' ?>><?= htmlspecialchars(__('btn_delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <div class="um-mini-label"><?= htmlspecialchars(__('btn_reset_password'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post" class="admin-users-reset-form">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <input name="new_password" type="password" placeholder="<?= htmlspecialchars(__('ph_new_password'), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;"><?= htmlspecialchars(__('btn_reset_password'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users_primary_list): ?>
                    <tr><td colspan="<?= (int)$users_primary_colspan ?>" class="admin-users-center"><?= htmlspecialchars($actor_is_superadmin ? __('adm_users_empty_sa') : ($view_company_id > 0 ? __('adm_users_empty_co') : __('adm_users_empty_no_co')), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php if ($actor_is_superadmin): ?>
        <?php $superadmin_colspan = 11; ?>
        <div class="um-table-panel">
            <h3><?= htmlspecialchars(__('adm_users_sa_section'), ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="um-table-wrap">
            <table class="um-table" id="um-table-sa">
                <thead>
                    <tr>
                        <th class="um-col-narrow"><input type="checkbox" id="um-select-all-sa" aria-label="Select all"></th>
                        <th class="um-col-narrow"><?= htmlspecialchars(__('adm_users_th_no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('adm_users_th_login_id'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_display_name'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('adm_users_th_email'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_role'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_last_login'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('adm_users_th_created_by'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('lbl_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($superadmin_users as $idx => $u):
                    $uid = (int)$u['id'];
                    $is_self = ($uid === $session_user_id);
                    $r = (string)($u['role'] ?? '');
                    $em = trim((string)($u['email'] ?? ''));
                    $cb = trim((string)($u['created_by_username'] ?? ''));
                    $ll = trim((string)($u['last_login_at'] ?? ''));
                ?>
                    <tr>
                        <td class="um-col-narrow"><input type="checkbox" class="um-row-chk um-row-chk-sa" form="um-bulk-delete-form" name="bulk_ids[]" value="<?= $uid ?>"<?= $is_self ? ' disabled' : '' ?>></td>
                        <td class="um-col-narrow"><?= (int)($idx + 1) ?></td>
                        <td><?= htmlspecialchars((string)$u['username']) ?></td>
                        <td><?= htmlspecialchars(trim((string)($u['display_name'] ?? ''))) ?></td>
                        <td><?= $em !== '' ? htmlspecialchars($em) : '—' ?></td>
                        <td><span class="<?= htmlspecialchars(um_role_badge_class($r), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(um_role_badge_text($r), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?php if ((int)$u['is_active'] === 1): ?><span class="um-status-active"><?= htmlspecialchars(__('adm_users_um_active'), ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><span class="um-status-inactive"><?= htmlspecialchars(__('adm_users_um_inactive'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                        <td><?= $ll !== '' ? htmlspecialchars($ll) : '—' ?></td>
                        <td><?= $cb !== '' ? htmlspecialchars($cb) : '—' ?></td>
                        <td class="um-actions">
                            <a class="um-icon-edit" href="admin_user_edit.php?id=<?= $uid ?>" title="<?= htmlspecialchars(__('btn_edit'), ENT_QUOTES, 'UTF-8') ?>">✎</a>
                            <details class="um-more">
                                <summary title="<?= htmlspecialchars(__('adm_users_more_actions'), ENT_QUOTES, 'UTF-8') ?>">⋯</summary>
                                <div class="um-more-panel" onclick="event.stopPropagation();">
                                    <div class="um-mini-label"><?= htmlspecialchars(__('lbl_role'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <select name="role" class="form-control">
                                            <?php foreach ($role_opts_platform as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>" <?= $r === $opt ? 'selected' : '' ?>><?= htmlspecialchars(role_label($opt)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;"><?= htmlspecialchars(__('btn_change_role'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-gray btn-sm" style="width:100%;"><?= ((int)$u['is_active'] === 1) ? htmlspecialchars(__('btn_disable'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('btn_enable'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <form method="post" data-confirm="<?= htmlspecialchars(__('adm_users_confirm_delete'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="width:100%;"<?= $is_self ? ' disabled' : '' ?>><?= htmlspecialchars(__('btn_delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <div class="um-mini-label"><?= htmlspecialchars(__('btn_reset_password'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post" class="admin-users-reset-form">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <input name="new_password" type="password" placeholder="<?= htmlspecialchars(__('ph_new_password'), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;"><?= htmlspecialchars(__('btn_reset_password'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$superadmin_users): ?>
                    <tr><td colspan="<?= (int)$superadmin_colspan ?>" class="admin-users-center"><?= htmlspecialchars(__('adm_users_empty_sa_list'), ENT_QUOTES, 'UTF-8') ?></td></tr>
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
        var modal = document.getElementById('create-account-modal');
        var btnAdd = document.getElementById('btn-add-user');
        var btnClose = document.getElementById('create-account-modal-close');
        var btnCancel = document.getElementById('create-account-cancel');
        var backdrop = document.getElementById('create-account-modal-backdrop');
        function openCreateModal() {
            if (!modal) return;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            var firstInput = modal.querySelector('input[name="username"]');
            if (firstInput) {
                setTimeout(function(){ firstInput.focus(); }, 50);
            }
        }
        function closeCreateModal() {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (btnAdd) btnAdd.focus();
        }
        if (btnAdd && modal) {
            btnAdd.addEventListener('click', function(){ openCreateModal(); });
        }
        if (btnClose) btnClose.addEventListener('click', closeCreateModal);
        if (btnCancel) btnCancel.addEventListener('click', closeCreateModal);
        if (backdrop) backdrop.addEventListener('click', closeCreateModal);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
                closeCreateModal();
            }
        });
        if (modal && modal.classList.contains('is-open')) {
            var firstInput = modal.querySelector('input[name="username"]');
            if (firstInput) setTimeout(function(){ firstInput.focus(); }, 80);
        }
        var tbForm = document.getElementById('admin-users-toolbar-form');
        if (tbForm) {
            var hiddenSf = document.getElementById('toolbar_status_filter');
            var cbInact = document.getElementById('toolbar_cb_inactive');
            if (cbInact && hiddenSf) {
                cbInact.addEventListener('change', function(){
                    hiddenSf.value = cbInact.checked ? 'all' : 'active';
                    tbForm.submit();
                });
            }
        }
        var bulkForm = document.getElementById('um-bulk-delete-form');
        var bulkBtn = document.getElementById('um-bulk-delete-btn');
        var bulkTpl = <?= $bulk_confirm_tpl_json ?>;
        var bulkNone = <?= $bulk_none_json ?>;
        function umBindSelectAll(masterId, rowClass) {
            var m = document.getElementById(masterId);
            if (!m) return;
            m.addEventListener('change', function(){
                document.querySelectorAll('.' + rowClass).forEach(function(c){
                    if (!c.disabled) c.checked = m.checked;
                });
            });
        }
        umBindSelectAll('um-select-all-primary', 'um-row-chk-primary');
        umBindSelectAll('um-select-all-sa', 'um-row-chk-sa');
        if (bulkBtn && bulkForm) {
            bulkBtn.addEventListener('click', function(){
                var n = 0;
                for (var i = 0; i < bulkForm.elements.length; i++) {
                    var el = bulkForm.elements[i];
                    if (el.name === 'bulk_ids[]' && el.type === 'checkbox' && el.checked && !el.disabled) n++;
                }
                if (n === 0) { alert(bulkNone); return; }
                var msg = bulkTpl.indexOf('%d') >= 0 ? bulkTpl.replace('%d', String(n)) : bulkTpl;
                if (!confirm(msg)) return;
                bulkForm.submit();
            });
        }
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

