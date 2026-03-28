<?php
/**
 * 界面语言：Session + Cookie（app_lang），zh / en。
 * 在 session_start() 之后 require 本文件并调用 i18n_bootstrap()。
 */

function i18n_bootstrap(): void
{
    if (!empty($_SESSION['app_lang']) && in_array($_SESSION['app_lang'], ['zh', 'en'], true)) {
        return;
    }
    $c = isset($_COOKIE['app_lang']) ? (string)$_COOKIE['app_lang'] : '';
    if ($c === 'en' || $c === 'zh') {
        $_SESSION['app_lang'] = $c;
        return;
    }
    $_SESSION['app_lang'] = 'zh';
}

function app_lang(): string
{
    $l = $_SESSION['app_lang'] ?? 'zh';
    return ($l === 'en') ? 'en' : 'zh';
}

function i18n_set_lang(string $lang): void
{
    if ($lang !== 'en' && $lang !== 'zh') {
        $lang = 'zh';
    }
    $_SESSION['app_lang'] = $lang;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('app_lang', $lang, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** @return array<string, array{zh:string,en:string}> */
function i18n_dictionary(): array
{
    return [
        // 侧栏导航
        'nav_home' => ['zh' => '首页', 'en' => 'Home'],
        'nav_dashboard' => ['zh' => '仪表盘', 'en' => 'Dashboard'],
        'nav_report' => ['zh' => '报表', 'en' => 'Report'],
        'nav_statement' => ['zh' => '对账单', 'en' => 'Statement'],
        'nav_add' => ['zh' => '新增', 'en' => 'Add'],
        'nav_add_transaction' => ['zh' => '记一笔', 'en' => 'Add Transaction'],
        'nav_new_customer' => ['zh' => '新客户', 'en' => 'New Customer'],
        'nav_expense' => ['zh' => '开销', 'en' => 'Expense'],
        'nav_expense_statement' => ['zh' => '开销对账', 'en' => 'Expense Statement'],
        'nav_kiosk_expense' => ['zh' => 'Kiosk 开销', 'en' => 'Kiosk Expense'],
        'nav_kiosk_statement' => ['zh' => 'Kiosk 对账', 'en' => 'Kiosk Statement'],
        'nav_transactions' => ['zh' => '流水', 'en' => 'Transactions'],
        'nav_rebate' => ['zh' => '返点', 'en' => 'Rebate'],
        'nav_customer_rebate' => ['zh' => '客户返点', 'en' => 'Customer Rebate'],
        'nav_agent_rebate' => ['zh' => '代理返点', 'en' => 'Agent Rebate'],
        'nav_customer_detail' => ['zh' => '客户明细', 'en' => 'Customer Detail'],
        'nav_customers' => ['zh' => '客户列表', 'en' => 'Customers'],
        'nav_product_accounts' => ['zh' => '产品账号', 'en' => 'Product Accounts'],
        'nav_user_management' => ['zh' => '用户管理', 'en' => 'User Management'],
        'nav_companies' => ['zh' => '分公司 / 公司', 'en' => 'Companies'],
        'nav_banks_products' => ['zh' => '银行与产品', 'en' => 'Banks & Products'],
        'nav_permissions' => ['zh' => '权限', 'en' => 'Permissions'],
        'nav_logout' => ['zh' => '退出', 'en' => 'Logout'],
        'nav_agent' => ['zh' => '代理', 'en' => 'Agent'],
        'nav_menu' => ['zh' => '菜单', 'en' => 'MENU'],
        'nav_open_menu' => ['zh' => '打开导航', 'en' => 'Open menu'],
        'nav_close_menu' => ['zh' => '关闭菜单', 'en' => 'Close menu'],
        'company_hq' => ['zh' => '总公司', 'en' => 'Head office'],
        'role_bb' => ['zh' => '平台总管理', 'en' => 'Big Boss'],
        'role_boss' => ['zh' => '老板', 'en' => 'Boss'],
        'role_admin' => ['zh' => '管理员', 'en' => 'Admin'],
        'role_staff' => ['zh' => '员工', 'en' => 'Staff'],
        'role_agent' => ['zh' => '代理', 'en' => 'Agent'],
        'role_member' => ['zh' => '会员', 'en' => 'Member'],
        'bell_pending' => ['zh' => '待处理', 'en' => 'Pending'],
        'bell_pending_detail' => ['zh' => '待处理：流水 %d，客户 %d', 'en' => 'Pending: %d tx, %d customers'],
        'bell_aria' => ['zh' => '待处理 %d', 'en' => '%d pending'],
        'lang_switch_aria' => ['zh' => '界面语言', 'en' => 'Language'],
        'confirm_prompt_default' => ['zh' => '确定继续吗？', 'en' => 'Continue?'],
        'modal_system_title' => ['zh' => '系统提示', 'en' => 'Notice'],
        'modal_confirm_title' => ['zh' => '确认操作', 'en' => 'Confirm'],
        'btn_ok' => ['zh' => '确定', 'en' => 'OK'],
        'btn_cancel' => ['zh' => '取消', 'en' => 'Cancel'],
        // 首页
        'user_default' => ['zh' => '用户', 'en' => 'User'],
        'dash_title' => ['zh' => 'K8 欢迎（%s）', 'en' => 'Welcome to K8 (%s)'],
        'dash_greet' => ['zh' => '欢迎，', 'en' => 'Welcome, '],
        'dash_today' => ['zh' => '今日（%s）', 'en' => 'Today (%s)'],
        'dash_today_in' => ['zh' => '今日入账', 'en' => "Today's deposit"],
        'dash_today_out' => ['zh' => '今日出账', 'en' => "Today's withdrawal"],
        'dash_today_profit' => ['zh' => '今日利润', 'en' => "Today's profit"],
        'dash_member_hint' => ['zh' => '以下为只读汇总，银行与产品的增删改仅管理员可操作。', 'en' => 'Read-only summary below. Only admins can change banks/products.'],
        'dash_sec_customers_today' => ['zh' => '今日客户与单数', 'en' => "Today's customers & orders"],
        'dash_active_customers' => ['zh' => '上线客户数', 'en' => 'Active customers'],
        'dash_orders_count' => ['zh' => '单数', 'en' => 'Orders'],
        'dash_sec_new_customers' => ['zh' => '今日新顾客与单数', 'en' => "New customers today"],
        'dash_new_customers' => ['zh' => '新顾客数', 'en' => 'New customers'],
        'dash_new_customer_orders' => ['zh' => '新客单数', 'en' => 'Their orders'],
        'dash_show_month' => ['zh' => '显示本月数据', 'en' => 'Show this month'],
        'dash_month' => ['zh' => '本月（%s ~ %s）', 'en' => 'This month (%s ~ %s)'],
        'dash_month_in' => ['zh' => '本月入账', 'en' => 'Month deposit'],
        'dash_month_out' => ['zh' => '本月出账', 'en' => 'Month withdrawal'],
        'dash_month_expense' => ['zh' => '本月开销', 'en' => 'Month expenses'],
        'dash_month_profit' => ['zh' => '本月利润', 'en' => 'Month profit'],
        'dash_db_err_title' => ['zh' => '系统提示：数据库还没升级完成', 'en' => 'Database migration required'],
        'dash_db_err_msg' => ['zh' => '错误信息', 'en' => 'Error'],
        'dash_db_fix' => ['zh' => '解决方法', 'en' => 'Fix'],
        'dash_db_fix_body' => [
            'zh' => '到 Hostinger 的 phpMyAdmin 执行迁移 SQL：<code>migrate_approval.sql</code>（新增 status 等字段）。<br>另外如果你要用客户下拉，也执行：<code>migrate_customers.sql</code>（新增 customers 表）。',
            'en' => 'In Hostinger phpMyAdmin, run <code>migrate_approval.sql</code> (adds status, etc.).<br>For the customer dropdown, also run <code>migrate_customers.sql</code> (creates the customers table).',
        ],
        'dash_page_title' => ['zh' => '首页 - %s', 'en' => 'Home - %s'],
    ];
}

function __(string $key): string
{
    static $dict = null;
    if ($dict === null) {
        $dict = i18n_dictionary();
    }
    $lang = app_lang();
    if (!isset($dict[$key])) {
        return $key;
    }
    return $dict[$key][$lang] ?? $dict[$key]['zh'];
}

function __f(string $key, ...$args): string
{
    return sprintf(__($key), ...$args);
}
