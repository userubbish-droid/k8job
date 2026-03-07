<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
require_admin(); // 仅管理员可删除流水

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

$stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
$stmt->execute([$id]);
header('Location: ' . $return_to);
exit;
