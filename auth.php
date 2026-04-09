<?php
// 统一鉴权工具：所有需要登录的页面都 include 这里

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/inc/i18n.php';
i18n_bootstrap();

/** 权限 key => 显示名称（供 admin 打勾设置 member 用） */
function get_permission_options(): array
{
    return [
        'home_dashboard'     => __('perm_home_dashboard'),
        'statement_report'   => __('perm_statement_report'),
        'statement_balance'  => __('perm_statement_balance'),
        'expense_statement'  => __('perm_expense_statement'),
        'kiosk_expense_view' => __('perm_kiosk_expense_view'),
        'kiosk_statement'    => __('perm_kiosk_statement'),
        'transaction_create' => __('perm_transaction_create'),
        'transaction_list'   => __('perm_transaction_list'),
        'rebate'             => __('perm_rebate'),
        'customers'          => __('perm_customers'),
        'customer_create'    => __('perm_customer_create'),
        'customer_edit'      => __('perm_customer_edit'),
        'product_library'    => __('perm_product_library'),
        'agent'              => __('perm_agent'),
    ];
}

/** Admin 专属：首页「本月数据」须由 Boss / 平台 big boss 在权限页勾选后才有 */
const PERM_DASHBOARD_MONTH_DATA = 'dashboard_month_data';
const PERM_VIEW_MEMBER_CONTACT = 'view_member_contact';
/** Customers 列表 Total DP / Total WD 列（Boss/Superadmin 在权限页为 Admin/Member 勾选） */
const PERM_VIEW_CUSTOMER_TOTAL_DP_WD = 'view_customer_total_dp_wd';

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
        if (in_array($key, [PERM_DASHBOARD_MONTH_DATA, PERM_VIEW_MEMBER_CONTACT, PERM_VIEW_CUSTOMER_TOTAL_DP_WD], true)) {
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
                $stmt->execute([$uid, $key]);
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
        echo htmlspecialchars(__('err_403_feature'), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

/**
 * 从数据库刷新当前用户的头像 URL 到 session（另一台设备上改过头像后，本机 session 仍是旧值）。
 */
function auth_sync_session_avatar_from_db(): void
{
    global $pdo;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || !isset($pdo)) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT avatar_url FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $raw = $st->fetchColumn();
        $_SESSION['avatar_url'] = trim((string)($raw ?? ''));
    } catch (Throwable $e) {
        // 忽略
    }
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    auth_sync_session_avatar_from_db();
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
        echo htmlspecialchars(__('err_403_superadmin_only'), ENT_QUOTES, 'UTF-8');
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

/**
 * 单公司后台（银行/产品等）使用的 company_id：Superadmin 选「总公司」视图 (0) 时，改为第一家启用分公司，
 * 避免 WHERE company_id=0 无数据；仍可在侧栏切换到指定分公司。汇总视图本身不变（见 current_company_id）。
 */
function effective_admin_company_id(PDO $pdo): int {
    $cid = current_company_id();
    if (($_SESSION['user_role'] ?? '') === 'superadmin' && $cid === 0) {
        try {
            $n = (int) $pdo->query('SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
            return $n > 0 ? $n : 1;
        } catch (Throwable $e) {
            return 1;
        }
    }
    return $cid;
}

/** Superadmin 且侧栏为「总公司」汇总（session company_id = 0） */
function is_superadmin_head_office_scope(): bool {
    return ($_SESSION['user_role'] ?? '') === 'superadmin' && current_company_id() === 0;
}

/** 界面展示用：数据库存英文 role，superadmin 显示为 big boss */
function role_label(string $role): string
{
    switch (strtolower(trim($role))) {
        case 'superadmin':
            return __('role_bb');
        case 'boss':
            return __('role_boss');
        case 'admin':
            return __('role_admin');
        case 'member':
            return __('role_member');
        case 'agent':
            return __('role_agent');
        default:
            return $role !== '' ? $role : __('login_err_role_unset');
    }
}

/**
 * 当前登录者是否可管理目标用户：平台 superadmin 仅由 superadmin 管理；superadmin 可管理任意分公司的账号；
 * 分公司 admin / boss 仅可管理本公司且非 superadmin 的用户。
 * 另：分公司 Admin 不可管理另一名 Admin（含权限、改资料、改角色等），仅 Boss 或平台 superadmin 可管理 Admin。
 * 另：分公司 Admin 不可管理更高层级（Boss、平台 superadmin）。
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
    $role = strtolower(trim((string)($row['role'] ?? '')));
    $actor_role = (string)($_SESSION['user_role'] ?? '');
    $actor_sa = $actor_role === 'superadmin';
    $actor_company_mgr = in_array($actor_role, ['admin', 'boss'], true);
    if ($role === 'superadmin') {
        return $actor_sa;
    }
    if ($actor_sa) {
        return true;
    }
    // 分公司 Admin 不可管理 Boss / 平台 superadmin（列表不展示、也不可直链操作）
    if ($actor_role === 'admin' && in_array($role, ['boss', 'superadmin'], true)) {
        return false;
    }
    // 分公司 Admin 不能操作另一名 Admin；本人除外（改自己资料/密码等）
    if ($actor_role === 'admin' && $role === 'admin' && $user_id !== (int)($_SESSION['user_id'] ?? 0)) {
        return false;
    }
    $cid = current_company_id();
    if ($cid <= 0 || !$actor_company_mgr) {
        return false;
    }
    return (int)($row['company_id'] ?? 0) === $cid;
}

/** 主密码通过后、待输入二级密码时的 session 标记（仅 admin / member） */
const AUTH_LOGIN_PENDING_SECOND = '__login_pending_second';

function ensure_users_second_password_hash(PDO $pdo): void
{
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN second_password_hash VARCHAR(255) NULL COMMENT \'二级密码 admin/member\' AFTER password_hash');
    } catch (Throwable $e) {
    }
}

function choose_landing_url_for_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'agent') {
        return 'agents.php';
    }
    if ($role === 'superadmin' || $role === 'boss' || $role === 'admin') {
        return 'dashboard.php';
    }
    $candidates = [
        'transaction_create' => 'transaction_create.php',
        'expense_statement'  => 'expense.php',
        'kiosk_expense_view' => 'kiosk_expense.php',
        'statement_balance'  => 'balance_summary.php',
        'statement_report'   => 'report.php',
        'kiosk_statement'    => 'kiosk_statement.php',
        'transaction_list'   => 'transaction_list.php',
        'customers'          => 'customers.php',
        'product_library'    => 'product_library.php',
        'rebate'             => 'rebate.php',
    ];
    foreach ($candidates as $perm => $url) {
        if (has_permission($perm)) {
            return $url;
        }
    }
    return '';
}

/**
 * 主密码（及二级密码若需要）已全部通过：写入 session、更新 last_login、可选 remember。
 *
 * @return array{ok: true, location: string}|array{ok: false, error: string}
 */
function auth_commit_login_session(PDO $pdo, array $u, bool $remember, string $company_code_lower, int $default_company_id): array
{
    $db_role = strtolower(trim((string)($u['role'] ?? '')));
    unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);

    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_name'] = $u['display_name'] ?: $u['username'];
    $_SESSION['user_role'] = $db_role;
    $_SESSION['avatar_url'] = trim((string)($u['avatar_url'] ?? ''));
    $db_company_id = (int)($u['company_id'] ?? 0);
    $error = '';

    if ($db_role === 'superadmin') {
        $use_company = 0;
        if ($company_code_lower !== '') {
            try {
                $stmtC = $pdo->prepare('SELECT id FROM companies WHERE is_active = 1 AND LOWER(TRIM(code)) = ? LIMIT 1');
                $stmtC->execute([$company_code_lower]);
                $use_company = (int)$stmtC->fetchColumn();
            } catch (Throwable $e) {
            }
        }
        if ($use_company <= 0 && $default_company_id > 0) {
            $_SESSION['company_id'] = 0;
        } elseif ($use_company > 0) {
            $_SESSION['company_id'] = $use_company;
        } else {
            $error = __('auth_err_no_company_available');
        }
    } else {
        if ($db_company_id <= 0) {
            $error = __('auth_err_user_no_company');
        } else {
            $_SESSION['company_id'] = $db_company_id;
        }
    }

    if ($error !== '') {
        foreach (['user_id', 'user_name', 'user_role', 'company_id', 'avatar_url', 'agent_code'] as $k) {
            unset($_SESSION[$k]);
        }
        return ['ok' => false, 'error' => $error];
    }

    try {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active');
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at');
        } catch (Throwable $e) {
        }
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim((string)$_SERVER['REMOTE_ADDR']);
        }
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }
        $stmt2 = $pdo->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?');
        $stmt2->execute([$ip !== '' ? $ip : null, (int)$u['id']]);
    } catch (Throwable $e) {
    }

    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), time() + 86400 * 14, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    if ($db_role === 'agent') {
        $_SESSION['agent_code'] = $u['username'];
        return ['ok' => true, 'location' => 'agents.php'];
    }

    $target = choose_landing_url_for_role($db_role);
    if ($target === '') {
        foreach (['user_id', 'user_name', 'user_role', 'company_id', 'avatar_url', 'agent_code'] as $k) {
            unset($_SESSION[$k]);
        }
        return ['ok' => false, 'error' => __('login_err_no_perm')];
    }
    return ['ok' => true, 'location' => $target];
}

/**
 * 仅 Boss / 平台 big boss 可为他人设置二级密码；对象须为 admin 或 member 且可被当前操作者管理。
 */
function user_actor_can_set_second_password(PDO $pdo, int $target_user_id): bool
{
    $actor = (string)($_SESSION['user_role'] ?? '');
    if (!in_array($actor, ['boss', 'superadmin'], true)) {
        return false;
    }
    if (!user_is_manageable_by_current_actor($pdo, $target_user_id)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$target_user_id]);
    $r = strtolower(trim((string)$stmt->fetchColumn()));
    return in_array($r, ['admin', 'member'], true);
}
