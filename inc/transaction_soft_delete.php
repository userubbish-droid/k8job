<?php
/**
 * 流水软删除：deleted_at / deleted_by；超过 $days 天物理删除。
 */
function transaction_ensure_soft_delete_columns(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN deleted_by VARCHAR(120) NULL DEFAULT NULL");
    } catch (Throwable $e) {
    }
}

function transaction_purge_soft_deleted(PDO $pdo, int $days = 100): void
{
    $d = max(1, min(3650, (int)$days));
    try {
        $pdo->exec("DELETE FROM transactions WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL {$d} DAY)");
    } catch (Throwable $e) {
    }
}
