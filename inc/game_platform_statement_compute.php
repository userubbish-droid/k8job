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

$gpc_gp_key_sql = require __DIR__ . '/gpc_effective_product_key_sql.php';

/** 客户代号 → 游戏平台键（与 gpc 回推一致：该客户 id 最小的产品账号） */
$gpc_code_to_gp = [];
try {
    $stc = $pdo->prepare("SELECT TRIM(c.code) AS cd,
        (SELECT TRIM(a2.product_name) FROM customer_product_accounts a2
         WHERE a2.customer_id = c.id AND a2.company_id = c.company_id
         ORDER BY a2.id ASC LIMIT 1) AS pn
        FROM customers c WHERE c.company_id = ? AND TRIM(c.code) <> ''");
    $stc->execute([$gpc_cid]);
    foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $rw) {
        $cd = trim((string)($rw['cd'] ?? ''));
        $pn = trim((string)($rw['pn'] ?? ''));
        if ($cd !== '' && $pn !== '') {
            $gpc_code_to_gp[strtolower($cd)] = strtolower($pn);
        }
    }
} catch (Throwable $e) {
}

$cum_in_bank = [];
$cum_out_bank = [];
$cum_in_product = [];
$cum_topup_product = [];
$cum_out_product = [];

try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount + COALESCE(burn,0) WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS tout
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
    // burn 列不存在时回退
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
    } catch (Throwable $e2) {}
}

try {
    $line = "(CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END)";
    $stmt = $pdo->prepare("SELECT {$gpc_gp_key_sql} AS gp,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN $line + (CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW' THEN COALESCE(t.burn,0) ELSE 0 END) ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN $line ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'WITHDRAW' THEN t.amount + COALESCE(t.burn,0) WHEN TRIM(COALESCE(t.mode,'')) = 'EXPENSE' THEN t.amount ELSE 0 END), 0) AS tout
        FROM transactions t WHERE t.company_id = ? AND t.day < ? AND t.status = 'approved' AND t.deleted_at IS NULL GROUP BY gp");
    $stmt->execute([$gpc_cid, $day_from]);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['gp'] ?? '')));
        if ($p !== '' && $p !== '—') {
            $cum_in_product[$p] = ($cum_in_product[$p] ?? 0) + (float)$r['ti'];
            $cum_topup_product[$p] = ($cum_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
            $cum_out_product[$p] = ($cum_out_product[$p] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
        }
    }
} catch (Throwable $e) {
    try {
        $stmt = $pdo->prepare("SELECT {$gpc_gp_key_sql} AS gp,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS ti,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS topup,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('WITHDRAW','EXPENSE') THEN t.amount ELSE 0 END), 0) AS tout
            FROM transactions t WHERE t.company_id = ? AND t.day < ? AND t.status = 'approved' AND t.deleted_at IS NULL GROUP BY gp");
        $stmt->execute([$gpc_cid, $day_from]);
        foreach ($stmt->fetchAll() as $r) {
            $p = strtolower(trim((string)($r['gp'] ?? '')));
            if ($p !== '' && $p !== '—') {
                $cum_in_product[$p] = ($cum_in_product[$p] ?? 0) + (float)$r['ti'];
                $cum_topup_product[$p] = ($cum_topup_product[$p] ?? 0) + (float)($r['topup'] ?? 0);
                $cum_out_product[$p] = ($cum_out_product[$p] ?? 0) + (float)($r['tout'] ?? $r['to'] ?? 0);
            }
        }
    } catch (Throwable $e2) {}
}

try {
    $stmt = $pdo->prepare("SELECT bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount + COALESCE(burn,0) WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS total_out
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
    // ignore
}

try {
    $line = "(CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END)";
    $stmt = $pdo->prepare("SELECT {$gpc_gp_key_sql} AS gp,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN $line + (CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW' THEN COALESCE(t.burn,0) ELSE 0 END) ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN $line ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'WITHDRAW' THEN t.amount + COALESCE(t.burn,0) WHEN TRIM(COALESCE(t.mode,'')) = 'EXPENSE' THEN t.amount ELSE 0 END), 0) AS total_out
        FROM transactions t WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved' AND t.deleted_at IS NULL
        GROUP BY gp");
    $stmt->execute([$gpc_cid, $day_from, $day_to]);
    $range_in_product = [];
    $range_topup_product = [];
    $range_out_product = [];
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['gp'] ?? '')));
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
    $line = '(CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END)';
    $stmt = $pdo->prepare("SELECT {$gpc_gp_key_sql} AS gp,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'DEPOSIT' THEN $line ELSE 0 END), 0) AS dep,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'REBATE' THEN $line ELSE 0 END), 0) AS reb,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE' THEN $line ELSE 0 END), 0) AS fr,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW' THEN $line + COALESCE(t.burn,0) ELSE 0 END), 0) AS fwd,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW')
            THEN COALESCE(t.bonus,0) + GREATEST(0, $line - t.amount - COALESCE(t.bonus,0)) ELSE 0 END), 0) AS bns
        FROM transactions t WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved' AND t.deleted_at IS NULL
        GROUP BY gp");
    $stmt->execute([$gpc_cid, $day_from, $day_to]);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower(trim((string)($r['gp'] ?? '')));
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

/** 返点页「已给」并入 Rebate 列；与同额 REBATE 流水配对后只算一次（见 inc/kiosk_rebate_given_dedup.php） */
require_once __DIR__ . '/kiosk_rebate_given_dedup.php';
try {
    $pair = gpc_rebate_pair_given_with_txns($pdo, $gpc_cid, $day_from, $day_to, $gpc_gp_key_sql, $gpc_code_to_gp);
    foreach (($pair['subtract_line_by_gp'] ?? []) as $gpk => $sub) {
        $gpk = strtolower((string)$gpk);
        if ($gpk === '') {
            continue;
        }
        if (!isset($range_breakdown_product[$gpk])) {
            continue;
        }
        $range_breakdown_product[$gpk]['reb'] -= (float)$sub;
    }
} catch (Throwable $e) {
}

try {
    $stmtRg2 = $pdo->prepare("SELECT TRIM(code) AS cd, rebate_amount FROM rebate_given
        WHERE company_id = ? AND rebate_amount IS NOT NULL AND day >= ? AND day <= ?");
    $stmtRg2->execute([$gpc_cid, $day_from, $day_to]);
    foreach ($stmtRg2->fetchAll(PDO::FETCH_ASSOC) as $rw) {
        $cd = strtolower(trim((string)($rw['cd'] ?? '')));
        $amt = (float)($rw['rebate_amount'] ?? 0);
        if ($cd === '' || $amt == 0.0) {
            continue;
        }
        $gp = $gpc_code_to_gp[$cd] ?? '';
        if ($gp === '') {
            continue;
        }
        if (!isset($range_breakdown_product[$gp])) {
            $range_breakdown_product[$gp] = [
                'dep' => 0.0,
                'reb' => 0.0,
                'fr' => 0.0,
                'fwd' => 0.0,
                'bns' => 0.0,
            ];
        }
        $range_breakdown_product[$gp]['reb'] += $amt;
    }
} catch (Throwable $e) {
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
    $st = $pdo->prepare("SELECT name FROM products WHERE company_id = ? AND is_active = 1 AND (delete_pending_at IS NULL) ORDER BY sort_order ASC, name ASC");
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
