<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
require_admin(); // 仅管理员可删除流水

require_once __DIR__ . '/inc/transaction_soft_delete.php';
transaction_ensure_soft_delete_columns($pdo);
transaction_purge_soft_deleted($pdo, 100);

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

$who = trim((string)($_SESSION['user_name'] ?? ''));
if ($who === '') {
    $who = (string)(int)($_SESSION['user_id'] ?? 0);
}
if (strlen($who) > 120) {
    $who = substr($who, 0, 120);
}

// 软删除：标记 deleted_at / deleted_by，满 100 天后由 purge 物理删除
$stmt = $pdo->prepare('UPDATE transactions SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
$stmt->execute([$who !== '' ? $who : null, $id, current_company_id()]);
header('Location: ' . $return_to);
exit;
