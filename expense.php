<?php
/**
 * Expense Statement 录入（独立页面）
 */
$_GET['quick'] = 'expense';
if (!isset($_GET['expense_kind'])) {
    $_GET['expense_kind'] = 'statement';
}
require __DIR__ . '/transaction_create.php';
