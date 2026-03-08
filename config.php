<?php
// 使用马来西亚时间 (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// 浏览器标签标题后缀（可改为你的品牌名）
if (!defined('SITE_TITLE')) define('SITE_TITLE', 'K8');

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

// 待审核通知（Telegram，免费）：有流水待审核时推送到 Telegram
$NOTIFY_TELEGRAM_BOT_TOKEN = '';
$NOTIFY_TELEGRAM_CHAT_ID  = '';
$NOTIFY_BASE_URL = '';  // 如 https://你的域名.com，用于通知里的链接
if (file_exists(__DIR__ . '/notify_config.php')) {
    include __DIR__ . '/notify_config.php';
}
