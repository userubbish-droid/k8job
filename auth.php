<?php
// 统一鉴权工具：所有需要登录的页面都 include 这里

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** 权限 key => 显示名称（供 admin 打勾设置 member 用） */
function get_permission_options(): array
{
    return [
        'transaction_create' => '记一笔',
        'transaction_list'   => '流水记录',
        'rebate'             => '返点 Rebate',
        'customers'          => '顾客列表',
        'customer_create'    => '新增顾客',
        'customer_edit'      => '编辑顾客（含产品账号）',
        'product_library'    => '产品账号',
        'statement'          => 'statement',
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

