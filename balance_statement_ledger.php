<?php
/**
 * Statement 钻取：按银行/产品（或 PG channel/customer）列出区间内流水及逐笔 before/after 余额。
 */
ob_start();
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();
require_permission('statement_balance');

function bsl_json_out(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, ['admin', 'boss', 'superadmin'], true)) {
    bsl_json_out(['ok' => false, 'error' => 'Forbidden']);
}

$company_id = current_company_id();
if (isset($_GET['company_id']) && (int)$_GET['company_id'] > 0) {
    $company_id = (int)$_GET['company_id'];
} elseif (is_superadmin_head_office_scope() && isset($_GET['stmt_co']) && (int)$_GET['stmt_co'] > 0) {
    $company_id = (int)$_GET['stmt_co'];
} elseif ($company_id <= 0 && function_exists('effective_admin_company_id')) {
    $company_id = effective_admin_company_id($pdo);
}
if ($company_id <= 0) {
    bsl_json_out(['ok' => false, 'error' => 'Invalid company']);
}

$pdoTxn = (function_exists('pdo_business') ? pdo_business() : $pdo);

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : '';
$day_to = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : '';
if ($day_from === '' || $day_to === '') {
    bsl_json_out(['ok' => false, 'error' => 'Invalid date range']);
}
if ($day_from > $day_to) {
    $t = $day_from;
    $day_from = $day_to;
    $day_to = $t;
}

$entity_type = strtolower(trim((string)($_GET['entity_type'] ?? '')));
$entity_name = trim((string)($_GET['entity_name'] ?? ''));
if ($entity_name === '') {
    bsl_json_out(['ok' => false, 'error' => 'Missing entity']);
}

$pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
$biz_kind = 'gaming';
try {
    $stBk = $pdoCat->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $biz_kind = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {
}
if (!in_array($biz_kind, ['gaming', 'pg'], true)) {
    $biz_kind = 'gaming';
}

function bsl_txn_line_amount(array $r): float
{
    $tot = $r['total'] ?? null;
    if ($tot !== null && $tot !== '' && (float)$tot != 0.0) {
        return (float)$tot;
    }
    return (float)($r['amount'] ?? 0) + (float)($r['bonus'] ?? 0);
}

function bsl_gaming_bank_delta(string $mode, float $amount): float
{
    $m = strtoupper(trim($mode));
    if ($m === 'DEPOSIT') {
        return $amount;
    }
    if ($m === 'WITHDRAW' || $m === 'EXPENSE') {
        return -$amount;
    }
    return 0.0;
}

function bsl_gaming_product_delta(string $mode, float $amount, float $bonus, float $total, float $burn): float
{
    $m = strtoupper(trim($mode));
    $line = ($total != 0.0) ? $total : ($amount + $bonus);
    if (in_array($m, ['DEPOSIT', 'REBATE', 'FREE'], true)) {
        return -$line;
    }
    if ($m === 'FREE WITHDRAW') {
        return -($line + $burn);
    }
    if ($m === 'TOPUP') {
        return $line;
    }
    if ($m === 'WITHDRAW') {
        return $amount + $burn;
    }
    if ($m === 'EXPENSE') {
        return $amount;
    }
    return 0.0;
}

function bsl_fmt(float $v): string
{
    return number_format($v, 2, '.', '');
}

function bsl_table_has_column(PDO $db, string $table, string $column): bool
{
    try {
        $db->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 与 balance_summary 左表「Starting」同源：balance_adjust + day < day_from 的累计 */
function bsl_gaming_bank_opening(PDO $db, int $cid, string $dayFrom, string $bankKey, bool $hasDeletedAt): float
{
    $del = $hasDeletedAt ? ' AND deleted_at IS NULL' : '';
    $base = 0.0;
    try {
        $st = $db->prepare("SELECT initial_balance FROM balance_adjust
            WHERE company_id = ? AND adjust_type = 'bank' AND LOWER(TRIM(name)) = ? LIMIT 1");
        $st->execute([$cid, $bankKey]);
        $base = (float)$st->fetchColumn();
    } catch (Throwable $e) {
    }
    $ti = 0.0;
    $tout = 0.0;
    try {
        $st = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
            COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS tout
            FROM transactions
            WHERE company_id = ? AND day < ? AND status = 'approved'{$del}
              AND LOWER(TRIM(COALESCE(bank,''))) = ?");
        $st->execute([$cid, $dayFrom, $bankKey]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ti = (float)($r['ti'] ?? 0);
        $tout = (float)($r['tout'] ?? 0);
    } catch (Throwable $e) {
    }
    return round($base + $ti - $tout, 2);
}

function bsl_gaming_product_opening(PDO $db, int $cid, string $dayFrom, string $productKey, bool $hasDeletedAt, string $gpcSql, bool $hasBurn): float
{
    $del = $hasDeletedAt ? ' AND t.deleted_at IS NULL' : '';
    $base = 0.0;
    try {
        $st = $db->prepare("SELECT initial_balance FROM balance_adjust
            WHERE company_id = ? AND adjust_type = 'product' AND LOWER(TRIM(name)) = ? LIMIT 1");
        $st->execute([$cid, $productKey]);
        $base = (float)$st->fetchColumn();
    } catch (Throwable $e) {
    }
    $line = '(CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END)';
    $fwdBurn = $hasBurn ? "(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW' THEN COALESCE(t.burn,0) ELSE 0 END)" : '0';
    $wdExpr = $hasBurn ? 't.amount + COALESCE(t.burn,0)' : 't.amount';
    $ti = 0.0;
    $topup = 0.0;
    $tout = 0.0;
    try {
        $sql = "SELECT
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN {$line} + {$fwdBurn} ELSE 0 END), 0) AS ti,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN {$line} ELSE 0 END), 0) AS topup,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'WITHDRAW' THEN {$wdExpr} WHEN TRIM(COALESCE(t.mode,'')) = 'EXPENSE' THEN t.amount ELSE 0 END), 0) AS tout
            FROM transactions t
            WHERE t.company_id = ? AND t.day < ? AND t.status = 'approved'{$del}
              AND ({$gpcSql}) = ?";
        $st = $db->prepare($sql);
        $st->execute([$cid, $dayFrom, $productKey]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ti = (float)($r['ti'] ?? 0);
        $topup = (float)($r['topup'] ?? 0);
        $tout = (float)($r['tout'] ?? 0);
    } catch (Throwable $e) {
        try {
            $sql = "SELECT
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN {$line} ELSE 0 END), 0) AS ti,
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN {$line} ELSE 0 END), 0) AS topup,
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('WITHDRAW','EXPENSE') THEN t.amount ELSE 0 END), 0) AS tout
                FROM transactions t
                WHERE t.company_id = ? AND t.day < ? AND t.status = 'approved'{$del}
                  AND ({$gpcSql}) = ?";
            $st = $db->prepare($sql);
            $st->execute([$cid, $dayFrom, $productKey]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $ti = (float)($r['ti'] ?? 0);
            $topup = (float)($r['topup'] ?? 0);
            $tout = (float)($r['tout'] ?? 0);
        } catch (Throwable $e2) {
        }
    }
    return round($base - $ti + $topup + $tout, 2);
}

function bsl_pg_entity_opening(PDO $db, int $cid, string $dayFrom, string $col, string $entityKey): float
{
    if ($col !== 'channel') {
        $col = 'member_code';
    }
    try {
        $st = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) AS bal
            FROM pg_transactions
            WHERE company_id = ? AND status = 'approved' AND txn_day < ?
              AND LOWER(TRIM({$col})) = ?");
        $st->execute([$cid, $dayFrom, $entityKey]);
        return round((float)$st->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

try {
    if ($biz_kind === 'pg') {
        if (!function_exists('pdo_data_for_company_id')) {
            throw new RuntimeException('PG data connection unavailable');
        }
        $pdoData = pdo_data_for_company_id($pdoCat, $company_id);
        $key = strtolower($entity_name);
        if ($entity_type === 'bank' || $entity_type === 'channel') {
            $opening = bsl_pg_entity_opening($pdoData, $company_id, $day_from, 'channel', $key);
            $sql = "SELECT id, txn_day AS day, txn_time AS time, flow, amount, member_code, channel, COALESCE(remark,'') AS remark
                FROM pg_transactions
                WHERE company_id = ? AND status = 'approved'
                  AND txn_day >= ? AND txn_day <= ?
                  AND LOWER(TRIM(channel)) = ?
                ORDER BY txn_day ASC, txn_time ASC, id ASC LIMIT 500";
            $params = [$company_id, $day_from, $day_to, $key];
            $entity_label = 'Channel';
        } elseif ($entity_type === 'product' || $entity_type === 'customer') {
            $opening = bsl_pg_entity_opening($pdoData, $company_id, $day_from, 'member_code', $key);
            $sql = "SELECT id, txn_day AS day, txn_time AS time, flow, amount, member_code, channel, COALESCE(remark,'') AS remark
                FROM pg_transactions
                WHERE company_id = ? AND status = 'approved'
                  AND txn_day >= ? AND txn_day <= ?
                  AND LOWER(TRIM(member_code)) = ?
                ORDER BY txn_day ASC, txn_time ASC, id ASC LIMIT 500";
            $params = [$company_id, $day_from, $day_to, $key];
            $entity_label = 'Customer';
        } else {
            throw new RuntimeException('Invalid entity type');
        }
        $st = $pdoData->prepare($sql);
        $st->execute($params);
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rawRows) >= 500;
    } else {
        $hasDeletedAt = bsl_table_has_column($pdoTxn, 'transactions', 'deleted_at');
        $hasBurn = bsl_table_has_column($pdoTxn, 'transactions', 'burn');
        $del = $hasDeletedAt ? ' AND t.deleted_at IS NULL' : '';
        $burnCol = $hasBurn ? 't.burn' : '0 AS burn';
        $gpc_gp_key_sql = require __DIR__ . '/inc/gpc_effective_product_key_sql.php';
        $entity_key = strtolower($entity_name);

        if ($entity_type === 'bank') {
            $opening = bsl_gaming_bank_opening($pdoTxn, $company_id, $day_from, $entity_key, $hasDeletedAt);
            $sql = "SELECT t.id, t.day, t.time, TRIM(t.mode) AS mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, {$burnCol}, t.remark
                FROM transactions t
                WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved'{$del}
                  AND LOWER(TRIM(COALESCE(t.bank,''))) = ?
                ORDER BY t.day ASC, t.time ASC, t.id ASC LIMIT 500";
            $st = $pdoTxn->prepare($sql);
            $st->execute([$company_id, $day_from, $day_to, $entity_key]);
            $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
            $truncated = count($rawRows) >= 500;
            $entity_label = 'Bank';
        } elseif ($entity_type === 'product') {
            $opening = bsl_gaming_product_opening($pdoTxn, $company_id, $day_from, $entity_key, $hasDeletedAt, $gpc_gp_key_sql, $hasBurn);
            $sql = "SELECT t.id, t.day, t.time, TRIM(t.mode) AS mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, {$burnCol}, t.remark,
                ({$gpc_gp_key_sql}) AS eff_gp
                FROM transactions t
                WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved'{$del}
                  AND ({$gpc_gp_key_sql}) = ?
                ORDER BY t.day ASC, t.time ASC, t.id ASC LIMIT 500";
            $st = $pdoTxn->prepare($sql);
            $st->execute([$company_id, $day_from, $day_to, $entity_key]);
            $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
            $truncated = count($rawRows) >= 500;
            $entity_label = 'Game Platform';
        } else {
            throw new RuntimeException('Invalid entity type');
        }
    }

    $run = round($opening, 2);
    $rows = [];
    foreach ($rawRows as $r) {
        if ($biz_kind === 'pg') {
            $amt = (float)($r['amount'] ?? 0);
            $flow = strtolower(trim((string)($r['flow'] ?? '')));
            $delta = ($flow === 'out') ? -$amt : $amt;
            $before = $run;
            $after = round($before + $delta, 2);
            $run = $after;
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'date' => (string)($r['day'] ?? ''),
                'time' => substr((string)($r['time'] ?? ''), 0, 8),
                'code' => (string)($r['member_code'] ?? ''),
                'mode' => strtoupper($flow === 'out' ? 'OUT' : 'IN'),
                'bank' => (string)($r['channel'] ?? ''),
                'product' => '',
                'amount' => bsl_fmt($amt),
                'delta' => bsl_fmt($delta),
                'before' => bsl_fmt($before),
                'after' => bsl_fmt($after),
                'remark' => (string)($r['remark'] ?? ''),
            ];
            continue;
        }
        $mode = (string)($r['mode'] ?? '');
        $amount = (float)($r['amount'] ?? 0);
        $bonus = (float)($r['bonus'] ?? 0);
        $total = (float)($r['total'] ?? 0);
        $burn = (float)($r['burn'] ?? 0);
        $delta = ($entity_type === 'bank')
            ? bsl_gaming_bank_delta($mode, $amount)
            : bsl_gaming_product_delta($mode, $amount, $bonus, $total, $burn);
        $before = $run;
        $after = round($before + $delta, 2);
        $run = $after;
        $rows[] = [
            'id' => (int)($r['id'] ?? 0),
            'date' => (string)($r['day'] ?? ''),
            'time' => substr((string)($r['time'] ?? ''), 0, 8),
            'code' => trim((string)($r['code'] ?? '')),
            'mode' => strtoupper(trim($mode)),
            'bank' => trim((string)($r['bank'] ?? '')),
            'product' => trim((string)($r['product'] ?? '')),
            'amount' => bsl_fmt(bsl_txn_line_amount($r)),
            'delta' => bsl_fmt($delta),
            'before' => bsl_fmt($before),
            'after' => bsl_fmt($after),
            'remark' => trim((string)($r['remark'] ?? '')),
        ];
    }

    bsl_json_out([
        'ok' => true,
        'entity_type' => $entity_type,
        'entity_name' => $entity_name,
        'entity_label' => $entity_label,
        'day_from' => $day_from,
        'day_to' => $day_to,
        'opening_balance' => bsl_fmt($opening),
        'closing_balance' => bsl_fmt($run),
        'rows' => $rows,
        'truncated' => $truncated,
    ]);
} catch (Throwable $e) {
    bsl_json_out(['ok' => false, 'error' => $e->getMessage()]);
}
