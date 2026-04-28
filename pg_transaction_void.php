<?php
/**
 * PG 流水作废：将 pg_transactions.status 置为 rejected（非物理删除）
 */
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
require_admin();

if (function_exists('shard_refresh_business_pdo')) {
    shard_refresh_business_pdo();
}
$pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
$company_id = current_company_id();

$bk = '';
try {
    $stBk = $pdoCat->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $bk = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {
}
if ($bk !== 'pg' || !function_exists('pdo_data_for_company_id')) {
    header('Location: transaction_list.php');
    exit;
}

$pdoData = pdo_data_for_company_id($pdoCat, $company_id);
$id = (int)($_REQUEST['id'] ?? 0);
$return_to = trim((string)($_REQUEST['return_to'] ?? ''));
if ($return_to === '' || strpos($return_to, 'pg_transaction_list.php') !== 0) {
    $return_to = 'pg_transaction_list.php';
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

$suffix = ' [void by ' . str_replace(["\0", "\r", "\n"], '', $who) . ']';
$stmt = $pdoData->prepare('UPDATE pg_transactions SET status = \'rejected\',
    remark = CONCAT(IFNULL(remark,\'\'), ?)
    WHERE id = ? AND company_id = ? AND status = \'approved\'');
$stmt->execute([$suffix, $id, $company_id]);

header('Location: ' . $return_to);
exit;
