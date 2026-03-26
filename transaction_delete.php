<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
require_admin(); // 仅管理员可删除流水

$ensure_deleted_at = function(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status");
    } catch (Throwable $e) {
    }
};

$purge_old_deleted = function(PDO $pdo): void {
    try {
        $pdo->exec("DELETE FROM transactions WHERE deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL 2 MONTH)");
    } catch (Throwable $e) {
    }
};

$id = (int)($_REQUEST['id'] ?? 0);
$return_to = trim($_REQUEST['return_to'] ?? '');
if ($return_to !== '' && (strpos($return_to, 'transaction_list.php') === 0 || strpos($return_to, 'rebate.php') === 0)) {
    $return_to = $return_to;
} else {
    $params = array_filter([
        'status'   => $_REQUEST['status'] ?? '',
        'page'     => $_REQUEST['page'] ?? '',
        'day_from' => $_REQUEST['day_from'] ?? '',
        'day_to'   => $_REQUEST['day_to'] ?? '',
        'mode'     => $_REQUEST['mode'] ?? '',
        'code'     => $_REQUEST['code'] ?? '',
        'bank'     => $_REQUEST['bank'] ?? '',
        'product'  => $_REQUEST['product'] ?? '',
    ]);
    $return_to = 'transaction_list.php' . ($params ? '?' . http_build_query($params) : '');
}

if ($id <= 0) {
    header('Location: ' . $return_to);
    exit;
}

$ensure_deleted_at($pdo);
$purge_old_deleted($pdo);

// 软删除：先标记 deleted_at，保留 2 个月后再物理删除
$stmt = $pdo->prepare("UPDATE transactions SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$id]);
header('Location: ' . $return_to);
exit;
