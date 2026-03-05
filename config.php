<?php
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
