<?php
/**
 * Statement 单元格钻取：按银行/产品（或 PG channel/customer）列出区间内流水及逐笔 before/after 余额。
 * 仅 admin / boss / superadmin 可访问（页面端控制入口）。
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();
require_permission('statement_balance');

header('Content-Type: application/json; charset=utf-8');

$role = strtolower(trim((string)($_SESSION['user_role'] ?? ''));
if (!in_array($role, ['admin', 'boss', 'superadmin'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$company_id = current_company_id();
if (is_superadmin_head_office_scope() && isset($_GET['stmt_co'])) {
    $pick = (int)$_GET['stmt_co'];
    if ($pick > 0) {
        $company_id = $pick;
    }
}

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : '';
$day_to = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : '';
if ($day_from === '' || $day_to === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid date range'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($day_from > $day_to) {
    $t = $day_from;
    $day_from = $day_to;
    $day_to = $t;
}

$entity_type = strtolower(trim((string)($_GET['entity_type'] ?? '')));
$entity_name = trim((string)($_GET['entity_name'] ?? ''));
if ($entity_name === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing entity'], JSON_UNESCAPED_UNICODE);
    exit;
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

try {
    if ($biz_kind === 'pg') {
        if (!function_exists('pdo_data_for_company_id')) {
            throw new RuntimeException('PG data connection unavailable');
        }
        $pdoData = pdo_data_for_company_id($pdoCat, $company_id);
        require_once __DIR__ . '/inc/pg_statement_compute.php';

        $key = strtolower($entity_name);
        $opening = 0.0;
        if ($entity_type === 'bank' || $entity_type === 'channel') {
            $opening = (float)(($pg_initial_channel ?? [])[$key] ?? 0);
            $sql = "SELECT id, txn_day AS day, txn_time AS time, flow, amount, member_code, channel, COALESCE(remark,'') AS remark, COALESCE(staff,'') AS staff
                FROM pg_transactions
                WHERE company_id = ? AND status = 'approved'
                  AND txn_day >= ? AND txn_day <= ?
                  AND LOWER(TRIM(channel)) = ?
                ORDER BY txn_day ASC, txn_time ASC, id ASC
                LIMIT 500";
            $params = [$company_id, $day_from, $day_to, $key];
            $entity_label = 'Channel';
        } elseif ($entity_type === 'product' || $entity_type === 'customer') {
            $opening = (float)(($pg_initial_customer ?? [])[$key] ?? 0);
            $sql = "SELECT id, txn_day AS day, txn_time AS time, flow, amount, member_code, channel, COALESCE(remark,'') AS remark, COALESCE(staff,'') AS staff
                FROM pg_transactions
                WHERE company_id = ? AND status = 'approved'
                  AND txn_day >= ? AND txn_day <= ?
                  AND LOWER(TRIM(member_code)) = ?
                ORDER BY txn_day ASC, txn_time ASC, id ASC
                LIMIT 500";
            $params = [$company_id, $day_from, $day_to, $key];
            $entity_label = 'Customer';
        } else {
            throw new RuntimeException('Invalid entity type');
        }

        $st = $pdoData->prepare($sql);
        $st->execute($params);
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rawRows) >= 500;

        $run = round($opening, 2);
        $rows = [];
        foreach ($rawRows as $r) {
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
        }

        echo json_encode([
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
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Gaming
    $has_deleted_at = true;
    try {
        $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
    } catch (Throwable $e) {
        $has_deleted_at = false;
    }
    $del = $has_deleted_at ? ' AND t.deleted_at IS NULL' : '';
    $gpc_gp_key_sql = require __DIR__ . '/inc/gpc_effective_product_key_sql.php';

    require_once __DIR__ . '/inc/game_platform_statement_compute.php';

    $entity_key = strtolower($entity_name);
    $opening = 0.0;

    if ($entity_type === 'bank') {
        foreach ($initial_bank as $nm => $ival) {
            if (strtolower(trim((string)$nm)) === $entity_key) {
                $opening = (float)$ival;
                break;
            }
        }
        if ($opening === 0.0 && isset($cum_in_bank[$entity_key])) {
            $opening = (float)(($cum_in_bank[$entity_key] ?? 0) - ($cum_out_bank[$entity_key] ?? 0));
        }

        $sql = "SELECT t.id, t.day, t.time, TRIM(t.mode) AS mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, t.burn, t.remark
            FROM transactions t
            WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved'{$del}
              AND LOWER(TRIM(COALESCE(t.bank,''))) = ?
            ORDER BY t.day ASC, t.time ASC, t.id ASC
            LIMIT 500";
        $st = $pdo->prepare($sql);
        $st->execute([$company_id, $day_from, $day_to, $entity_key]);
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rawRows) >= 500;
        $entity_label = 'Bank';
    } elseif ($entity_type === 'product') {
        foreach ($initial_product as $nm => $ival) {
            if (strtolower(trim((string)$nm)) === $entity_key) {
                $opening = (float)$ival;
                break;
            }
        }
        if ($opening === 0.0) {
            $opening = (float)(-($cum_in_product[$entity_key] ?? 0) + ($cum_topup_product[$entity_key] ?? 0) + ($cum_out_product[$entity_key] ?? 0));
        }

        $sql = "SELECT t.id, t.day, t.time, TRIM(t.mode) AS mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, t.burn, t.remark,
            ($gpc_gp_key_sql) AS eff_gp
            FROM transactions t
            WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved'{$del}
            ORDER BY t.day ASC, t.time ASC, t.id ASC
            LIMIT 600";
        $st = $pdo->prepare($sql);
        $st->execute([$company_id, $day_from, $day_to]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC);
        $rawRows = [];
        foreach ($all as $r) {
            if (strtolower(trim((string)($r['eff_gp'] ?? ''))) === $entity_key) {
                $rawRows[] = $r;
            }
        }
        $truncated = count($all) >= 600;
        $entity_label = 'Game Platform';
    } else {
        throw new RuntimeException('Invalid entity type');
    }

    $run = round($opening, 2);
    $rows = [];
    foreach ($rawRows as $r) {
        $mode = (string)($r['mode'] ?? '');
        $amount = (float)($r['amount'] ?? 0);
        $bonus = (float)($r['bonus'] ?? 0);
        $total = (float)($r['total'] ?? 0);
        $burn = (float)($r['burn'] ?? 0);
        if ($entity_type === 'bank') {
            $delta = bsl_gaming_bank_delta($mode, $amount);
        } else {
            $delta = bsl_gaming_product_delta($mode, $amount, $bonus, $total, $burn);
        }
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

    echo json_encode([
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
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
