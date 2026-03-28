<?php
// 统一鉴权工具：所有需要登录的页面都 include 这里

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** 权限 key => 显示名称（供 admin 打勾设置 member 用） */
function get_permission_options(): array
{
    return [
        // 文案/顺序尽量与左侧侧栏一致（便于 admin 开关）
        // Home
        'home_dashboard'     => 'Dashboard',
        'statement_report'   => 'Report',
        // Statement
        'statement_balance'  => 'Statement',
        // Expense
        'expense_statement'  => 'Expense Statement',
        'kiosk_expense_view' => 'Kiosk Expense',
        'kiosk_statement'    => 'Kiosk Statement',
        // Add / Transactions / Rebate / Customers
        'transaction_create' => 'Add Transaction',
        'transaction_list'   => 'Transactions',
        'rebate'             => 'Rebate',
        'customers'          => 'Customers',
        'customer_create'    => 'New Customer',
        'customer_edit'      => 'Edit Customer (incl. Product Accounts)',
        'product_library'    => 'Product Accounts',
        // Agent
        'agent'              => 'Agent',

        // 兼容：旧版只用 statement 一个开关；保留供旧账号继续生效（一般不需要再勾选）
        'statement'          => 'Statement（legacy old switch）',
    ];
}

/** Admin 专属：首页「本月数据」须由 Boss / 平台 big boss 在权限页勾选后才有 */
const PERM_DASHBOARD_MONTH_DATA = 'dashboard_month_data';

/**
 * 当前用户是否拥有某权限。boss / superadmin 全允许；admin 除「本月数据」外全允许；member 查 user_permissions。
 */
function has_permission(string $key): bool
{
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'superadmin') {
        return true;
    }
    if ($role === 'boss') {
        return true;
    }
    if ($role === 'admin') {
        if ($key === PERM_DASHBOARD_MONTH_DATA) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) {
                return false;
            }
            global $pdo;
            if (!isset($pdo)) {
                return false;
            }
            try {
                $stmt = $pdo->prepare('SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
                $stmt->execute([$uid, PERM_DASHBOARD_MONTH_DATA]);
                return (bool) $stmt->fetch();
            } catch (Throwable $e) {
                return false;
            }
        }
        return true;
    }
    if ($role === 'agent') {
        return $key === 'agent'; // agent 角色只能看 agent 页
    }
    if ($role !== 'member') {
        return false;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        // 向后兼容：如果 member 勾了旧的 statement，则默认拥有所有 statement_* 与 kiosk_* 查看权限
        if (in_array($key, ['statement_report', 'statement_balance', 'kiosk_statement', 'kiosk_expense_view'], true)) {
            $stmt = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = 'statement' LIMIT 1");
            $stmt->execute([$uid]);
            if ((bool)$stmt->fetch()) {
                return true;
            }
        }
        $stmt = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1");
        $stmt->execute([$uid, $key]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 无该权限则 403 并退出。
 */
function require_permission(string $key): void
{
    require_login();
    if (!has_permission($key)) {
        http_response_code(403);
        echo '无权限访问此功能。如需开通，请联系管理员在「权限设置」中勾选。';
        exit;
    }
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    $role = $_SESSION['user_role'] ?? '';
    // 多公司：除平台 superadmin 外必须绑定 company_id（含 boss / admin / member / agent）
    if ($role !== 'superadmin') {
        $cid = (int)($_SESSION['company_id'] ?? 0);
        if ($cid <= 0) {
            // 回登录页重新选择公司
            session_destroy();
            header('Location: login.php?need_company=1');
            exit;
        }
    }
    if ($role === 'agent') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $allow = ['agents.php', 'logout.php'];
        if ($script === 'customers.php' && isset($_GET['recommend']) && trim((string)$_GET['recommend']) !== '') {
            $allow[] = 'customers.php';
        }
        if (!in_array($script, $allow, true)) {
            header('Location: agents.php');
            exit;
        }
    }
}

function require_admin(): void
{
    require_login();
    if (!in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true)) {
        http_response_code(403);
        echo '无权限（仅管理员或老板可访问）';
        exit;
    }
}

function require_superadmin(): void
{
    require_login();
    if (($_SESSION['user_role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        echo '无权限（仅平台 big boss 可访问）';
        exit;
    }
}

/** superadmin 在侧栏选择「总公司」时 session 为 0，表示全部分公司合计（仅部分页面如 Dashboard 做汇总） */
function is_superadmin_all_companies_scope(): bool {
    return ($_SESSION['user_role'] ?? '') === 'superadmin'
        && (int)($_SESSION['company_id'] ?? 0) === 0;
}

/** 当前公司 ID（superadmin 也会有：用于切换查看；0 表示总公司/全部分公司汇总视图） */
function current_company_id(): int {
    return (int)($_SESSION['company_id'] ?? 0);
}

/** 界面展示用：数据库存英文 role，superadmin 显示为 big boss */
function role_label(string $role): string
{
    switch (strtolower(trim($role))) {
        case 'superadmin':
            return 'big boss';
        case 'boss':
            return 'boss';
        case 'admin':
            return 'admin';
        case 'member':
            return 'member';
        case 'agent':
            return 'agent';
        default:
            return $role;
    }
}

/**
 * 当前登录者是否可管理目标用户：平台 superadmin 仅由 superadmin 管理；superadmin 可管理任意分公司的账号；
 * 分公司 admin / boss 仅可管理本公司且非 superadmin 的用户。
 */
function user_is_manageable_by_current_actor(PDO $pdo, int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role, company_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $role = (string)($row['role'] ?? '');
    $actor_role = (string)($_SESSION['user_role'] ?? '');
    $actor_sa = $actor_role === 'superadmin';
    $actor_company_mgr = in_array($actor_role, ['admin', 'boss'], true);
    if ($role === 'superadmin') {
        return $actor_sa;
    }
    if ($actor_sa) {
        return true;
    }
    $cid = current_company_id();
    if ($cid <= 0 || !$actor_company_mgr) {
        return false;
    }
    return (int)($row['company_id'] ?? 0) === $cid;
}


