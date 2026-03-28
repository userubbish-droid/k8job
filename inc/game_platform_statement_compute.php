<?php
/**
 * Game Platform / statement 产品维度数据（与 balance_summary 同源）。
 * 依赖：$pdo, $day_from, $day_to（Y-m-d）；$company_id 可选，缺省用 current_company_id()
 * 产出：$cum_*、$range_*、$all_banks、$all_products、$initial_*、$range_breakdown_product
 */
if (!isset($pdo, $day_from, $day_to)) {
    return;
}
$gpc_cid = isset($company_id) ? (int)$company_id : (function_exists('current_company_id') ? (int)current_company_id() : -1);
if ($gpc_cid <= 0) {
    $gpc_cid = -1;
}

$cum_in_bank = [];
$cum_out_bank = [];
$cum_in_product = [];
$cum_topup_product = [];
$cum_out_product = [];

try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE company_id = ? AND day < ? AND status = 'approved' AND deleted_at IS NULL AND bank IS NOT NULL AND TRIM(bank) != '' GROUP BY bank");
    $stmt->execute([$gpc_cid, $day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $b = strtolower(trim((string)$r['bank']));
        if ($b !== '') {
            $cum_in_bank[$b] = ($cum_in_bank[$b] ?? 0) + (float)$r['ti'];
            $cum_out_bank[$b] = ($cum_out_bank[$b] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
        }
    }
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '—') AS product,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'TOPUP' THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE company_id = ? AND day < ? AND status = 'approved' AND deleted_at IS NULL GROUP BY product");
    $stmt->execute([$gpc_cid, $day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['product'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $cum_in_product[$p] = ($cum_in_product[$p] ?? 0) + (float)$r['ti'];
            $cum_topup_product[$p] = ($cum_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
            $cum_out_product[$p] = ($cum_out_product[$p] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
        }
    }
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL AND bank IS NOT NULL AND TRIM(bank) != ''
        GROUP BY bank");
    $stmt->execute([$gpc_cid, $day_from, $day_to]);
    $range_in_bank = [];
    $range_out_bank = [];
    foreach ($stmt->fetchAll() as $r) {
        $b = strtolower(trim((string)($r['bank'] ?? '')));
        if ($b !== '') {
            $range_in_bank[$b] = ($range_in_bank[$b] ?? 0) + (float)$r['total_in'];
            $range_out_bank[$b] = ($range_out_bank[$b] ?? 0) + (float)$r['total_out'];
        }
    }
} catch (Throwable $e) {
    $range_in_bank = [];
    $range_out_bank = [];
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '') AS product,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'TOPUP' THEN (CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END) ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions WHERE company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL
        GROUP BY product");
    $stmt->execute([$gpc_cid, $day_from, $day_to]);
    $range_in_product = [];
    $range_topup_product = [];
    $range_out_product = [];
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['product'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $range_in_product[$p] = ($range_in_product[$p] ?? 0) + (float)$r['total_in'];
            $range_topup_product[$p] = ($range_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
            $range_out_product[$p] = ($range_out_product[$p] ?? 0) + (float)$r['total_out'];
        }
    }
} catch (Throwable $e) {
    $range_in_product = [];
    $range_topup_product = [];
    $range_out_product = [];
}

/** 区间内按模式拆分（与 statement In 使用同一套 total/amount+bonus 规则） */
$range_breakdown_product = [];
try {
    $line = '(CASE WHEN total IS NOT NULL AND total != 0 THEN total ELSE amount + COALESCE(bonus,0) END)';
    $stmt = $pdo->prepare("SELECT COALESCE(product, '') AS product,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN $line ELSE 0 END), 0) AS dep,
        COALESCE(SUM(CASE WHEN mode = 'REBATE' THEN $line ELSE 0 END), 0) AS reb,
        COALESCE(SUM(CASE WHEN mode = 'FREE' THEN $line ELSE 0 END), 0) AS fr,
        COALESCE(SUM(CASE WHEN mode = 'FREE WITHDRAW' THEN $line ELSE 0 END), 0) AS fwd,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN COALESCE(bonus,0) ELSE 0 END), 0) AS bns
        FROM transactions WHERE company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL
        GROUP BY product");
    $stmt->execute([$gpc_cid, $day_from, $day_to]);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['product'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $range_breakdown_product[$p] = [
                'dep' => (float)$r['dep'],
                'reb' => (float)$r['reb'],
                'fr' => (float)$r['fr'],
                'fwd' => (float)$r['fwd'],
                'bns' => (float)$r['bns'],
            ];
        }
    }
} catch (Throwable $e) {
    $range_breakdown_product = [];
}

$all_banks = [];
$all_products = [];
try {
    $st = $pdo->prepare("SELECT name FROM banks WHERE company_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    $st->execute([$gpc_cid]);
    $all_banks = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
}
try {
    $st = $pdo->prepare("SELECT name FROM products WHERE company_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    $st->execute([$gpc_cid]);
    $all_products = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
}

$initial_bank = [];
$initial_product = [];
try {
    $st = $pdo->prepare("SELECT adjust_type, name, initial_balance FROM balance_adjust WHERE company_id = ?");
    $st->execute([$gpc_cid]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $base = (float)$r['initial_balance'];
        $name = trim((string)$r['name']);
        $key = strtolower($name);
        if ($r['adjust_type'] === 'bank') {
            $initial_bank[$name] = $base + ($cum_in_bank[$key] ?? 0) - ($cum_out_bank[$key] ?? 0);
        } else {
            $initial_product[$name] = $base - ($cum_in_product[$key] ?? 0) + ($cum_topup_product[$key] ?? 0) + ($cum_out_product[$key] ?? 0);
        }
    }
} catch (Throwable $e) {
    $initial_bank = [];
    $initial_product = [];
}

foreach ($all_banks as $name) {
    $name = trim((string)$name);
    if ($name === '') {
        continue;
    }
    $key = strtolower($name);
    if (!isset($initial_bank[$name])) {
        $initial_bank[$name] = ($cum_in_bank[$key] ?? 0) - ($cum_out_bank[$key] ?? 0);
    }
}
foreach ($all_products as $name) {
    $name = trim((string)$name);
    if ($name === '') {
        continue;
    }
    $key = strtolower($name);
    if (!isset($initial_product[$name])) {
        $initial_product[$name] = -($cum_in_product[$key] ?? 0) + ($cum_topup_product[$key] ?? 0) + ($cum_out_product[$key] ?? 0);
    }
}
