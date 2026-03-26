<?php
require 'config.php';
require 'auth.php';
require_login();

if (($_SESSION['user_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo '无权限（仅 superadmin 可切换公司）。';
    exit;
}

$cid = (int)($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
$return_to = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
if ($return_to === '' || strpos($return_to, 'http') === 0 || strpos($return_to, '//') === 0) {
    $return_to = 'dashboard.php';
}

if ($cid <= 0) {
    header('Location: ' . $return_to);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$cid]);
    $ok = (bool)$stmt->fetchColumn();
    if ($ok) {
        $_SESSION['company_id'] = $cid;
    }
} catch (Throwable $e) {
}

header('Location: ' . $return_to);
exit;

