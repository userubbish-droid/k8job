<?php
require 'config.php';
require 'auth.php';
require_login();

$id = (int)($_REQUEST['id'] ?? 0);
$params = array_filter([
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

$stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
$stmt->execute([$id]);
header('Location: ' . $return_to);
exit;
