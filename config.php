<?php
// 使用马来西亚时间 (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// 浏览器标签标题后缀（可改为你的品牌名）
if (!defined('SITE_TITLE')) define('SITE_TITLE', 'K8');

// Agent 登录后欢迎语中的品牌词，例如：Welcome to k8win {用户名}
if (!defined('AGENT_PORTAL_BRAND')) define('AGENT_PORTAL_BRAND', 'k8win');

// Hostinger MySQL 配置
$host = 'localhost';
$db   = 'u870568714_K8win96';
$user = 'u870568714_K8win996';
$pass = 'K8win@996';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('数据库连接失败：' . htmlspecialchars($e->getMessage()));
}

// 软删除支持：transactions.deleted_at（用于“删除后保留 2 个月再物理删除”）
try {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status");
} catch (Throwable $e) {
    // 列已存在 / 无权限等：不阻断页面
}

// 头像支持：users.avatar_url（用于侧栏显示头像；可留空）
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) NULL DEFAULT NULL AFTER display_name");
} catch (Throwable $e) {
}
// 用户管理列表：邮箱、创建人（可选）
try {
    $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL');
} catch (Throwable $e) {
}
try {
    $pdo->exec('ALTER TABLE users ADD COLUMN created_by_user_id INT UNSIGNED NULL DEFAULT NULL');
} catch (Throwable $e) {
}

// 代理账号：Agent 页是否显示「期/周」与「月」日期快捷（由管理员在用户编辑中勾选）
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN agent_ui_show_week TINYINT(1) NOT NULL DEFAULT 1");
} catch (Throwable $e) {
}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN agent_ui_show_month TINYINT(1) NOT NULL DEFAULT 1");
} catch (Throwable $e) {
}

// 多公司（多租户）支持：companies + company_id
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}
// 分公司币种（Statement 总公司视图按币种筛选）
try {
    $pdo->exec("ALTER TABLE companies ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'MYR'");
} catch (Throwable $e) {
}
try {
    // 默认公司：k8（id=1）
    $pdo->exec("INSERT IGNORE INTO companies (id, code, name, is_active) VALUES (1, 'k8', 'K8', 1)");
} catch (Throwable $e) {}
// 如需单独「总部」分公司账号，请在「分公司/公司」里手动新增（不再自动插入 hq，避免与侧栏「总公司」汇总混淆）

// users.company_id（superadmin 可为空；其他必须有）
try { $pdo->exec("ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL AFTER avatar_url"); } catch (Throwable $e) {}
try { $pdo->exec("CREATE INDEX idx_users_company_id ON users(company_id)"); } catch (Throwable $e) {}
// 旧账号兜底：非 superadmin 默认归到 k8（company_id=1）
try { $pdo->exec("UPDATE users SET company_id = 1 WHERE (company_id IS NULL OR company_id = 0) AND role IN ('boss','admin','member','agent')"); } catch (Throwable $e) {}
// 角色枚举含分公司老板 boss（与 superadmin / admin 等并列）
try { $pdo->exec("ALTER TABLE users MODIFY role ENUM('superadmin','boss','admin','member','agent') NOT NULL DEFAULT 'member'"); } catch (Throwable $e) {}

// users：同一用户名可在不同分公司各有一条；平台 superadmin 仍为 company_id IS NULL（login_scope_key = SA:用户名）
try {
    $__lsk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'login_scope_key'")->fetchColumn();
    if ($__lsk === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN login_scope_key VARCHAR(191) GENERATED ALWAYS AS (
            CASE WHEN company_id IS NULL
                THEN CONCAT('SA:', LOWER(username))
                ELSE CONCAT('C', company_id, ':', LOWER(username))
            END
        ) STORED");
    }
} catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_login_scope_key (login_scope_key)'); } catch (Throwable $e) {}
foreach (['username', 'users_username_unique'] as $__uk) {
    try { $pdo->exec("ALTER TABLE users DROP INDEX `{$__uk}`"); } catch (Throwable $e) {}
}
try { $pdo->exec('CREATE INDEX idx_users_company_username ON users(company_id, username)'); } catch (Throwable $e) {}

// 业务表 company_id（旧数据默认归到 1）
foreach (['customers','transactions','banks','products','expenses','customer_product_accounts','balance_adjust','user_permissions','rebate_given','agent_rebate_settings'] as $__t) {
    try { $pdo->exec("ALTER TABLE {$__t} ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_{$__t}_company_id ON {$__t}(company_id)"); } catch (Throwable $e) {}
}
// 产品删除：先禁用 → 申请删除（待审）→ Boss 批准后物理删行
try {
    $pdo->exec('ALTER TABLE products ADD COLUMN delete_pending_at DATETIME NULL DEFAULT NULL');
} catch (Throwable $e) {
}
try {
    $pdo->exec('ALTER TABLE products ADD COLUMN delete_pending_by INT UNSIGNED NULL DEFAULT NULL');
} catch (Throwable $e) {
}

// 银行/产品/Expense：名称改为「分区内唯一」
foreach ([['banks', 'uq_banks_company_name'], ['products', 'uq_products_company_name'], ['expenses', 'uq_expenses_company_name']] as $__pair) {
    $__t = $__pair[0];
    $__uq = $__pair[1];
    foreach (['name', "{$__t}_name_unique", 'name_2'] as $__idx) {
        try { $pdo->exec("ALTER TABLE `{$__t}` DROP INDEX `{$__idx}`"); } catch (Throwable $e) {}
    }
    try { $pdo->exec("ALTER TABLE `{$__t}` ADD UNIQUE KEY `{$__uq}` (company_id, name)"); } catch (Throwable $e) {}
}

// agent_rebate_settings：主键 (company_id, agent_code)，各分公司返水设置互不干扰
try { $pdo->exec('ALTER TABLE agent_rebate_settings DROP PRIMARY KEY'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE agent_rebate_settings ADD PRIMARY KEY (company_id, agent_code)'); } catch (Throwable $e) {}

// rebate_given：主键含 company_id，避免不同分公司同日同客户代号冲突
try { $pdo->exec('ALTER TABLE rebate_given DROP PRIMARY KEY'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE rebate_given ADD PRIMARY KEY (company_id, day, code)'); } catch (Throwable $e) {}

// balance_adjust：期初余额按分公司区分
foreach (['adjust_type', 'uq_balance_adjust_legacy', 'balance_adjust_adjust_type_name_unique'] as $__idx) {
    try { $pdo->exec("ALTER TABLE balance_adjust DROP INDEX `{$__idx}`"); } catch (Throwable $e) {}
}
try { $pdo->exec('ALTER TABLE balance_adjust ADD UNIQUE KEY uq_balance_adjust_company (company_id, adjust_type, name)'); } catch (Throwable $e) {}

// customers 审核支持：status（默认 approved）
try { $pdo->exec("ALTER TABLE customers ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_active"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE customers ADD COLUMN approved_by INT UNSIGNED NULL AFTER status"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE customers ADD COLUMN approved_at DATETIME NULL AFTER approved_by"); } catch (Throwable $e) {}

// 忘记密码 Telegram 审批：申请记录
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        username VARCHAR(120) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        resolved_by_tg VARCHAR(120) NULL,
        resolved_note VARCHAR(255) NULL,
        temp_password VARCHAR(64) NULL,
        KEY idx_prr_user_status (user_id, status),
        KEY idx_prr_company_status (company_id, status)
    )");
} catch (Throwable $e) {}

// Telegram 通知：密钥勿写在此文件（避免进 Git）。复制 notify_config.php.example 为 notify_config.php 并填写（已在 .gitignore）
$NOTIFY_TELEGRAM_BOT_TOKEN = '';
$NOTIFY_TELEGRAM_CHAT_ID  = '';
$NOTIFY_BASE_URL = '';
// PG 专用快捷记账 Bot（与上方 NOTIFY 机器人分开；由 telegram_pg_webhook.php 使用）
$PG_TELEGRAM_BOT_TOKEN = '';
// 切勿在 include notify_config.php 之前 define('NOTIFY_CONFIG_LOADED', true)，否则若 notify 里用
// if (!defined('NOTIFY_CONFIG_LOADED')) { ... 全部赋值 ... } 包裹，会导致整段被跳过、token 永远为空。
if (is_file(__DIR__ . '/notify_config.php')) {
    include __DIR__ . '/notify_config.php';
}
if (is_file(__DIR__ . '/notify_config.php') && !defined('NOTIFY_CONFIG_LOADED')) {
    define('NOTIFY_CONFIG_LOADED', true);
}

// PG Bot token：notify_config 里未填时，可读主机环境变量（Hostinger：hPanel → Environment variables，名称 PG_TELEGRAM_BOT_TOKEN）
// 不少 PHP-FPM 配置下 getenv() 拿不到面板注入的变量，但 $_SERVER / $_ENV 里有，故依次尝试。
if (!isset($PG_TELEGRAM_BOT_TOKEN) || trim((string)$PG_TELEGRAM_BOT_TOKEN) === '') {
    $ev = getenv('PG_TELEGRAM_BOT_TOKEN');
    if (!is_string($ev) || trim($ev) === '') {
        $ev = (isset($_SERVER['PG_TELEGRAM_BOT_TOKEN']) && is_string($_SERVER['PG_TELEGRAM_BOT_TOKEN']))
            ? $_SERVER['PG_TELEGRAM_BOT_TOKEN'] : false;
    }
    if (!is_string($ev) || trim($ev) === '') {
        $ev = (isset($_ENV['PG_TELEGRAM_BOT_TOKEN']) && is_string($_ENV['PG_TELEGRAM_BOT_TOKEN']))
            ? $_ENV['PG_TELEGRAM_BOT_TOKEN'] : false;
    }
    if (is_string($ev) && trim($ev) !== '') {
        $PG_TELEGRAM_BOT_TOKEN = trim($ev);
    }
}

// 可选：PG 分库（catalog 仍为全局 $pdo，业务表用 pdo_business()）
require_once __DIR__ . '/inc/shard_pdo.php';
shard_register_catalog($pdo);
shard_try_connect_pg_from_config();
