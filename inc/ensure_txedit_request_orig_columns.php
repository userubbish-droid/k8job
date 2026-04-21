<?php
/**
 * 流水修改申請：寫入時凍結「改前」欄位，批准後仍可對照。
 * 冪等：可重複執行。
 */
function ensure_txedit_request_orig_columns(PDO $pdo): void
{
    $cols = [
        'orig_day' => 'DATE NULL DEFAULT NULL',
        'orig_time' => 'TIME NULL DEFAULT NULL',
        'orig_mode' => 'VARCHAR(32) NULL DEFAULT NULL',
        'orig_code' => 'VARCHAR(255) NULL DEFAULT NULL',
        'orig_bank' => 'VARCHAR(255) NULL DEFAULT NULL',
        'orig_product' => 'VARCHAR(255) NULL DEFAULT NULL',
        'orig_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL',
        'orig_burn' => 'DECIMAL(14,2) NULL DEFAULT NULL',
        'orig_bonus' => 'DECIMAL(14,2) NULL DEFAULT NULL',
        'orig_total' => 'DECIMAL(14,2) NULL DEFAULT NULL',
        'orig_remark' => 'TEXT NULL',
    ];
    foreach ($cols as $name => $def) {
        try {
            $pdo->exec("ALTER TABLE transaction_edit_requests ADD COLUMN {$name} {$def}");
        } catch (Throwable $e) {
            // 欄位已存在
        }
    }
    // 待審申請：補齊尚未凍結的快照（與當前流水一致，便於之後批准時保留「改前」）
    $has_deleted_at = true;
    try {
        $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
    } catch (Throwable $e) {
        $has_deleted_at = false;
    }
    $delJoin = $has_deleted_at ? ' AND t.deleted_at IS NULL' : '';
    try {
        $sql = "UPDATE transaction_edit_requests r
            INNER JOIN transactions t ON t.id = r.transaction_id AND t.company_id = r.company_id{$delJoin}
            SET r.orig_day = t.day, r.orig_time = t.time, r.orig_mode = t.mode, r.orig_code = t.code,
                r.orig_bank = t.bank, r.orig_product = t.product, r.orig_amount = t.amount,
                r.orig_burn = t.burn, r.orig_bonus = t.bonus, r.orig_total = t.total, r.orig_remark = t.remark
            WHERE r.status = 'pending' AND r.orig_day IS NULL";
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // burn 等欄位缺失時略過
    }
}
