<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_list');

$id = (int)($_REQUEST['id'] ?? 0);
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
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

if ($id <= 0) {
    header('Location: ' . $return_to);
    exit;
}

// member 只能删除自己 pending 的记录；admin 可删除任意
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT status, created_by FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t || ($t['status'] ?? '') !== 'pending' || (int)($t['created_by'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) {
        http_response_code(403);
        echo '无权限：只能删除自己待批准的流水。';
        exit;
    }
}

$stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
$stmt->execute([$id]);
header('Location: ' . $return_to);
exit;
