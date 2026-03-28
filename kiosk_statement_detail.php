<?php
/**
 * Kiosk Statement 单元格钻取明细（JSON）。GET：day_from, day_to, product, bucket
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();
require_permission('kiosk_statement');

header('Content-Type: application/json; charset=utf-8');

$company_id = current_company_id();
$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true);

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : '';
$day_to   = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : '';
if ($day_from === '' || $day_to === '') {
    echo json_encode(['ok' => false, 'error' => '日期无效'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($day_from > $day_to) {
    $t = $day_from;
    $day_from = $day_to;
    $day_to = $t;
}

$product_raw = trim((string)($_GET['product'] ?? ''));
$bucket = trim((string)($_GET['bucket'] ?? ''));
$allowed = ['dep', 'reb', 'fr', 'fwd', 'bns', 'topup', 'out', 'in'];
if ($product_raw === '' || !in_array($bucket, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gp_key = strtolower($product_raw);
$gpc_gp_key_sql = require __DIR__ . '/inc/gpc_effective_product_key_sql.php';
$limit = 400;
$trunc = false;

function ksd_txn_line(array $r): float
{
    $tot = $r['total'] ?? null;
    if ($tot !== null && $tot !== '' && (float)$tot != 0.0) {
        return (float)$tot;
    }
    return (float)($r['amount'] ?? 0) + (float)($r['bonus'] ?? 0);
}

function ksd_build_code_to_gp(PDO $pdo, int $cid): array
{
    $map = [];
    try {
        $stc = $pdo->prepare("SELECT TRIM(c.code) AS cd,
            (SELECT TRIM(a2.product_name) FROM customer_product_accounts a2
             WHERE a2.customer_id = c.id AND a2.company_id = c.company_id
             ORDER BY a2.id ASC LIMIT 1) AS pn
            FROM customers c WHERE c.company_id = ? AND TRIM(c.code) <> ''");
        $stc->execute([$cid]);
        foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $rw) {
            $cd = trim((string)($rw['cd'] ?? ''));
            $pn = trim((string)($rw['pn'] ?? ''));
            if ($cd !== '' && $pn !== '') {
                $map[strtolower($cd)] = strtolower($pn);
            }
        }
    } catch (Throwable $e) {
    }
    return $map;
}

function ksd_fetch_txn_for_bucket(PDO $pdo, string $gpc_gp_key_sql, int $cid, string $df, string $dt, string $gp_key, string $modeCond, int $limit): array
{
    $lim = (int)$limit + 1;
    $sql = "SELECT * FROM (
        SELECT t.id, t.day, t.time, TRIM(t.mode) AS mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, t.remark,
            ($gpc_gp_key_sql) AS eff_gp
        FROM transactions t
        WHERE t.company_id = ? AND t.day >= ? AND t.day <= ? AND t.status = 'approved' AND t.deleted_at IS NULL
        AND ($modeCond)
    ) sub WHERE sub.eff_gp = ? ORDER BY sub.day ASC, sub.time ASC, sub.id ASC LIMIT $lim";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cid, $df, $dt, $gp_key]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$titles = [
    'dep' => 'Deposit（入账）',
    'reb' => 'Rebate（返点）',
    'fr' => 'FREE',
    'fwd' => 'FREE WITHDRAW',
    'bns' => 'Bonus',
    'topup' => 'Topup（产品加额）',
    'out' => 'Out（WITHDRAW + EXPENSE）',
    'in' => 'In 合计（入账类汇总）',
];

$out = [
    'ok' => true,
    'title' => $product_raw . ' · ' . ($titles[$bucket] ?? $bucket),
    'bucket' => $bucket,
    'sections' => [],
    'truncated' => false,
    'can_edit_txn' => $is_admin,
];

try {
    switch ($bucket) {
        case 'dep':
            $modeCond = "TRIM(COALESCE(t.mode,'')) = 'DEPOSIT'";
            break;
        case 'reb':
            $modeCond = "TRIM(COALESCE(t.mode,'')) = 'REBATE'";
            break;
        case 'fr':
            $modeCond = "TRIM(COALESCE(t.mode,'')) = 'FREE'";
            break;
        case 'fwd':
            $modeCond = "TRIM(COALESCE(t.mode,'')) = 'FREE WITHDRAW'";
            break;
        case 'topup':
            $modeCond = "TRIM(COALESCE(t.mode,'')) = 'TOPUP'";
            break;
        case 'out':
            $modeCond = "TRIM(COALESCE(t.mode,'')) IN ('WITHDRAW','EXPENSE')";
            break;
        case 'in':
        case 'bns':
            $modeCond = "TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW')";
            break;
        default:
            $modeCond = '1=0';
    }

    if ($bucket === 'reb') {
        require_once __DIR__ . '/inc/kiosk_rebate_given_dedup.php';
        $codeToGpPair = ksd_build_code_to_gp($pdo, $company_id);
        $pairInfo = gpc_rebate_pair_given_with_txns($pdo, $company_id, $day_from, $day_to, $gpc_gp_key_sql, $codeToGpPair);
        $pairedTxnSet = array_flip(array_map('intval', $pairInfo['paired_txn_ids'] ?? []));

        $rows = ksd_fetch_txn_for_bucket($pdo, $gpc_gp_key_sql, $company_id, $day_from, $day_to, $gp_key, $modeCond, $limit);
        if (count($rows) > $limit) {
            $trunc = true;
            $rows = array_slice($rows, 0, $limit);
        }
        $sec1 = ['label' => '流水（mode = REBATE；已与返点页「已给」同客户+同金额配对的单据不重复列出）', 'columns' => ['日期', '时间', '代号', '产品', '银行', '金额', '奖励', '合计', '备注'], 'rows' => []];
        foreach ($rows as $r) {
            $tid = (int)($r['id'] ?? 0);
            if ($tid > 0 && isset($pairedTxnSet[$tid])) {
                continue;
            }
            $line = ksd_txn_line($r);
            $sec1['rows'][] = [
                'cells' => [
                    (string)($r['day'] ?? ''),
                    substr((string)($r['time'] ?? ''), 0, 8),
                    (string)($r['code'] ?? '—'),
                    (string)($r['product'] ?? '—'),
                    (string)($r['bank'] ?? '—'),
                    number_format((float)($r['amount'] ?? 0), 2, '.', ''),
                    number_format((float)($r['bonus'] ?? 0), 2, '.', ''),
                    number_format($line, 2, '.', ''),
                    (string)($r['remark'] ?? ''),
                ],
                'txn_id' => (int)($r['id'] ?? 0),
            ];
        }
        $out['sections'][] = $sec1;

        $codeToGp = $codeToGpPair;
        $sec2 = ['label' => '返点页「已给」（rebate_given）', 'columns' => ['记录日', '客户代号', '返点%', '金额', '标记时间'], 'rows' => []];
        try {
            $st = $pdo->prepare("SELECT day, code, rebate_pct, rebate_amount, given_at FROM rebate_given
                WHERE company_id = ? AND rebate_amount IS NOT NULL AND day >= ? AND day <= ? ORDER BY day ASC, code ASC LIMIT 500");
            $st->execute([$company_id, $day_from, $day_to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rg) {
                $cd = strtolower(trim((string)($rg['code'] ?? '')));
                if ($cd === '') {
                    continue;
                }
                if (($codeToGp[$cd] ?? '') !== $gp_key) {
                    continue;
                }
                $sec2['rows'][] = [
                    'cells' => [
                        (string)($rg['day'] ?? ''),
                        (string)($rg['code'] ?? ''),
                        $rg['rebate_pct'] !== null && $rg['rebate_pct'] !== '' ? number_format((float)$rg['rebate_pct'], 2, '.', '') : '—',
                        number_format((float)($rg['rebate_amount'] ?? 0), 2, '.', ''),
                        (string)($rg['given_at'] ?? ''),
                    ],
                ];
            }
        } catch (Throwable $e) {
        }
        $out['sections'][] = $sec2;
    } elseif ($bucket === 'bns') {
        $rows = ksd_fetch_txn_for_bucket($pdo, $gpc_gp_key_sql, $company_id, $day_from, $day_to, $gp_key, $modeCond, $limit);
        if (count($rows) > $limit) {
            $trunc = true;
            $rows = array_slice($rows, 0, $limit);
        }
        $sec = ['label' => 'Bonus 相关入账行（含推导）', 'columns' => ['日期', '时间', '模式', '代号', 'amount', 'bonus', 'line', '推导', '备注'], 'rows' => []];
        foreach ($rows as $r) {
            $line = ksd_txn_line($r);
            $bonus = (float)($r['bonus'] ?? 0);
            $imputed = max(0.0, $line - (float)($r['amount'] ?? 0) - $bonus);
            if (abs($bonus) < 0.0001 && $imputed < 0.0001) {
                continue;
            }
            $sec['rows'][] = [
                'cells' => [
                    (string)($r['day'] ?? ''),
                    substr((string)($r['time'] ?? ''), 0, 8),
                    (string)($r['mode'] ?? ''),
                    (string)($r['code'] ?? '—'),
                    number_format((float)($r['amount'] ?? 0), 2, '.', ''),
                    number_format($bonus, 2, '.', ''),
                    number_format($line, 2, '.', ''),
                    number_format($imputed, 2, '.', ''),
                    (string)($r['remark'] ?? ''),
                ],
                'txn_id' => (int)($r['id'] ?? 0),
            ];
        }
        $out['sections'][] = $sec;
    } else {
        $rows = ksd_fetch_txn_for_bucket($pdo, $gpc_gp_key_sql, $company_id, $day_from, $day_to, $gp_key, $modeCond, $limit);
        if (count($rows) > $limit) {
            $trunc = true;
            $rows = array_slice($rows, 0, $limit);
        }
        $sec = ['label' => '流水', 'columns' => ['日期', '时间', '模式', '代号', '产品', '银行', '金额', '奖励', '合计', '备注'], 'rows' => []];
        foreach ($rows as $r) {
            $line = ksd_txn_line($r);
            $sec['rows'][] = [
                'cells' => [
                    (string)($r['day'] ?? ''),
                    substr((string)($r['time'] ?? ''), 0, 8),
                    (string)($r['mode'] ?? ''),
                    (string)($r['code'] ?? '—'),
                    (string)($r['product'] ?? '—'),
                    (string)($r['bank'] ?? '—'),
                    number_format((float)($r['amount'] ?? 0), 2, '.', ''),
                    number_format((float)($r['bonus'] ?? 0), 2, '.', ''),
                    number_format($line, 2, '.', ''),
                    (string)($r['remark'] ?? ''),
                ],
                'txn_id' => (int)($r['id'] ?? 0),
            ];
        }
        $out['sections'][] = $sec;
    }

    $out['truncated'] = $trunc;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => '加载失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
