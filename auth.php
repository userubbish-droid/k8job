<?php
// 统一鉴权工具：所有需要登录的页面都 include 这里

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** 权限 key => 显示名称（供 admin 打勾设置 member 用） */
function get_permission_options(): array
{
    return [
        // 文案尽量与左侧侧栏一致（便于 admin 开关）
        'home_dashboard'     => 'Dashboard',
        'transaction_create' => 'Add Transaction',
        'expense_statement'  => 'Expense Statement',
        'kiosk_expense_view' => 'Kiosk Expense',
        'transaction_list'   => 'Transactions',
        'rebate'             => 'Rebate',
        'customers'          => 'Customers',
        'customer_create'    => 'New Customer',
        'customer_edit'      => 'Edit Customer (incl. Product Accounts)',
        'product_library'    => 'Product Accounts',
        // 兼容：旧版只用 statement 一个开关；新版拆分为多个开关（statement_*）
        'statement'          => 'Statement (legacy master)',
        'statement_report'   => 'Report',
        'statement_balance'  => 'Statement',
        'kiosk_statement'    => 'Kiosk Statement',
        'agent'              => 'Agent',
    ];
}

/**
 * 当前用户是否拥有某权限。admin 默认全部允许；member 查 user_permissions 表（按用户）。
 */
function has_permission(string $key): bool
{
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'admin') {
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
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo '无权限（仅 admin 可访问）';
        exit;
    }
}

