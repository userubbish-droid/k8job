<?php
require 'config.php';
require 'auth.php';
require_login();
$filter_recommend = isset($_GET['recommend']) ? trim((string)$_GET['recommend']) : '';
// 代理账号只能看自己的下线：强制 recommend = 自己的 agent_code
if (($_SESSION['user_role'] ?? '') === 'agent') {
    $filter_recommend = trim((string)($_SESSION['agent_code'] ?? ''));
}
// 从 Agent 页带 recommend 进入：有 agent 权限即可，只显示代号+输赢；否则需 customers 权限
if ($filter_recommend !== '') {
    if (!has_permission('agent')) { require_permission('customers'); }
} else {
    require_permission('customers');
}
$sidebar_current = 'customers';

$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true);
$can_view_contact_full = (($_SESSION['user_role'] ?? '') === 'boss' || ($_SESSION['user_role'] ?? '') === 'superadmin' || has_permission(PERM_VIEW_MEMBER_CONTACT));
$can_view_total_dp_wd = (($_SESSION['user_role'] ?? '') === 'boss' || ($_SESSION['user_role'] ?? '') === 'superadmin' || has_permission(PERM_VIEW_CUSTOMER_TOTAL_DP_WD));
$can_export_customers_csv = in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true);
$customers_list_colspan = 15 + ($can_view_total_dp_wd ? 2 : 0) + ($is_admin ? 2 : 0);
$company_id = current_company_id();
$has_customer_status = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'status'");
    $has_customer_status = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$msg = '';
$err = '';
if (!empty($_GET['pending_customer'])) {
    $msg = __('cust_msg_pending_review');
}

// 从 Agent 页进入（带 recommend 筛选）时不允许 POST 操作，且只显示代号+输赢，不显示客户资料
$agent_view = $filter_recommend !== '';
// 与 Agent 页同一区间：带 day_from/day_to 时按区间内流水计算 Total Win(Lose)
$pnl_day_from = '';
$pnl_day_to = '';
if ($agent_view) {
    $raw_df = isset($_GET['day_from']) ? trim((string)$_GET['day_from']) : '';
    $raw_dt = isset($_GET['day_to']) ? trim((string)$_GET['day_to']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_df)) {
        $pnl_day_from = $raw_df;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_dt)) {
        $pnl_day_to = $raw_dt;
    }
    if ($pnl_day_from !== '' && $pnl_day_to !== '' && $pnl_day_from > $pnl_day_to) {
        $tmp = $pnl_day_from;
        $pnl_day_from = $pnl_day_to;
        $pnl_day_to = $tmp;
    }
}
$agent_pnl_by_range = ($agent_view && $pnl_day_from !== '' && $pnl_day_to !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$agent_view) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('cust_err_param'));
            $stmt = $pdo->prepare("UPDATE customers SET is_active = IF(is_active=1,0,1) WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $msg = __('cust_msg_status_ok');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// REGULAR 等级：按顾客 deposit - withdraw 对扣余额计算。normal 20~1000, sliver 1001~3000, gold 3001~7000, platinum 7001+
function customer_regular_tier($balance) {
    $b = (float) $balance;
    if ($b < 20) return '—';
    if ($b <= 1000) return 'normal';
    if ($b <= 3000) return 'sliver';
    if ($b <= 7000) return 'gold';
    return 'platinum';
}

/** 记录顾客 CSV 导出（失败不阻断下载；表未建时忽略） */
function customer_export_log_try(PDO $pdo): void {
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $uname = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ''));
        if (mb_strlen($uname, 'UTF-8') > 190) {
            $uname = mb_substr($uname, 0, 190, 'UTF-8');
        }
        $role = trim((string)($_SESSION['user_role'] ?? ''));
        if (strlen($role) > 32) {
            $role = substr($role, 0, 32);
        }
        $cid = current_company_id();
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (strlen($ip) > 45) {
            $ip = substr($ip, 0, 45);
        }
        $stmt = $pdo->prepare('INSERT INTO customer_export_log (company_id, user_id, username, user_role, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$cid, $uid, $uname, $role, $ip !== '' ? $ip : null]);
    } catch (Throwable $e) {
    }
}

function customer_mask_contact_for_member(string $phone): string {
    $p = trim($phone);
    if ($p === '') {
        return '';
    }
    $len = mb_strlen($p, 'UTF-8');
    if ($len <= 6) {
        return str_repeat('*', $len);
    }
    $head = mb_substr($p, 0, 3, 'UTF-8');
    $tail = mb_substr($p, -3, null, 'UTF-8');
    return $head . str_repeat('*', $len - 6) . $tail;
}

$summary = ['total' => 0, 'active' => 0];
$rows = [];
$balance_by_code = [];
$all_deposit_by_code = [];
$all_withdraw_by_code = [];
$all_burn_by_code = [];
$all_rebate_by_code = [];
$all_free_by_code = [];
$all_free_withdraw_by_code = [];
$all_bonus_by_code = [];
$month_deposit_by_code = [];
$month_withdraw_by_code = [];
$month_burn_by_code = [];
$agent_range_dp = [];
$agent_range_wd = [];
$agent_range_burn = [];
try {
    $where = [];
    $params = [];
    $where[] = "c.company_id = ?";
    $params[] = $company_id;
    if ($has_customer_status) {
        // 待审核客户不出现在 Customers 列表（统一在 admin_customer_approvals.php 处理）
        $where[] = "c.status = 'approved'";
    }
    if ($filter_recommend !== '') {
        $where[] = "TRIM(c.recommend) = ?";
        $params[] = $filter_recommend;
    }
    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($filter_recommend !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND " . ($has_customer_status ? "status='approved' AND " : "") . "TRIM(recommend) = ?");
        $stmt->execute([$company_id, $filter_recommend]);
        $summary['total'] = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND is_active = 1 AND " . ($has_customer_status ? "status='approved' AND " : "") . "TRIM(recommend) = ?");
        $stmt->execute([$company_id, $filter_recommend]);
        $summary['active'] = (int) $stmt->fetchColumn();
    } else {
        $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE company_id = " . (int)$company_id . ($has_customer_status ? " AND status='approved'" : ""))->fetchColumn();
        $summary['active'] = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE company_id = " . (int)$company_id . " AND is_active = 1" . ($has_customer_status ? " AND status='approved'" : ""))->fetchColumn();
    }
    $sql = "SELECT c.id, c.code, c.name, c.phone, c.remark, c.is_active, c.created_at,
                   c.register_date, c.bank_details, c.bonus_flag, c.regular_customer, c.recommend, c.created_by,
                   u.username AS created_by_name
            FROM customers c
            LEFT JOIN users u ON c.created_by = u.id
            $where_sql
            ORDER BY c.is_active DESC, c.code ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Balance：DEPOSIT − WITHDRAW − BURN（Burn 不计入 Withdraw 列，独立扣减）
    try {
        $stmt = $pdo->prepare("SELECT code,
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS balance
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != ''
            GROUP BY code");
        $stmt->execute([$company_id]);
        foreach ($stmt->fetchAll() as $r) {
            $balance_by_code[$r['code']] = (float) $r['balance'];
        }
    } catch (Throwable $eBal) {
        $stmt = $pdo->prepare("SELECT code,
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS balance
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != ''
            GROUP BY code");
        $stmt->execute([$company_id]);
        foreach ($stmt->fetchAll() as $r) {
            $balance_by_code[$r['code']] = (float) $r['balance'];
        }
    }
    $all_deposit_by_code = [];
    $all_withdraw_by_code = [];
    $month_deposit_by_code = [];
    $month_withdraw_by_code = [];
    // All-time DP/WD（Withdraw 不含 burn）
    $stmt = $pdo->prepare("SELECT code,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ad,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS aw
        FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' GROUP BY code");
    $stmt->execute([$company_id]);
    foreach ($stmt->fetchAll() as $r) {
        $all_deposit_by_code[$r['code']] = (float)$r['ad'];
        $all_withdraw_by_code[$r['code']] = (float)$r['aw'];
    }
    // All-time Burn：WITHDRAW + FREE WITHDRAW 的 burn（独立列）
    try {
        $stmt = $pdo->prepare("SELECT code,
            COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS bb
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' GROUP BY code");
        $stmt->execute([$company_id]);
        foreach ($stmt->fetchAll() as $r) {
            $all_burn_by_code[$r['code']] = (float)$r['bb'];
        }
    } catch (Throwable $eBurnAll) {
        $all_burn_by_code = [];
    }
    $stmt = $pdo->prepare("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'REBATE' GROUP BY code");
    $stmt->execute([$company_id]);
    foreach ($stmt->fetchAll() as $r) {
        $all_rebate_by_code[$r['code']] = (float)$r['total'];
    }
    $stmt = $pdo->prepare("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'FREE' GROUP BY code");
    $stmt->execute([$company_id]);
    foreach ($stmt->fetchAll() as $r) {
        $all_free_by_code[$r['code']] = (float)$r['total'];
    }
    // FREE WITHDRAW：不含 burn（burn 另算到 Burn 列）
    $stmt = $pdo->prepare("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'FREE WITHDRAW' GROUP BY code");
    $stmt->execute([$company_id]);
    foreach ($stmt->fetchAll() as $r) {
        $all_free_withdraw_by_code[$r['code']] = (float)$r['total'];
    }
    $stmt = $pdo->prepare("SELECT TRIM(code) AS code, COALESCE(SUM(COALESCE(bonus, 0)), 0) AS total FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' GROUP BY TRIM(code)");
    $stmt->execute([$company_id]);
    foreach ($stmt->fetchAll() as $r) {
        $all_bonus_by_code[$r['code']] = (float)$r['total'];
    }
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    // Month DP/WD（Withdraw 不含 burn）
    $stmt = $pdo->prepare("SELECT code,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS md,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS mw
        FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ? GROUP BY code");
    $stmt->execute([$company_id, $month_start, $month_end]);
    foreach ($stmt->fetchAll() as $r) {
        $month_deposit_by_code[$r['code']] = (float)$r['md'];
        $month_withdraw_by_code[$r['code']] = (float)$r['mw'];
    }
    // Month Burn（独立列）
    try {
        $stmt = $pdo->prepare("SELECT code,
            COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS mb
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ? GROUP BY code");
        $stmt->execute([$company_id, $month_start, $month_end]);
        foreach ($stmt->fetchAll() as $r) {
            $month_burn_by_code[$r['code']] = (float)$r['mb'];
        }
    } catch (Throwable $eBurnMon) {
        $month_burn_by_code = [];
    }
    if ($agent_pnl_by_range) {
        // 与 agents.php Win/Loss 同源：按 TRIM(code) 汇总区间内 DEPOSIT−WITHDRAW
        $stmt = $pdo->prepare("SELECT TRIM(code) AS code,
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ad,
            COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS aw
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != ''
            AND day >= ? AND day <= ?
            GROUP BY TRIM(code)");
        $stmt->execute([$company_id, $pnl_day_from, $pnl_day_to]);
        foreach ($stmt->fetchAll() as $r) {
            $ck = trim((string)($r['code'] ?? ''));
            if ($ck === '') {
                continue;
            }
            $agent_range_dp[$ck] = (float)$r['ad'];
            $agent_range_wd[$ck] = (float)$r['aw'];
        }
        try {
            $stmt = $pdo->prepare("SELECT TRIM(code) AS code,
                COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS bb
                FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != ''
                AND day >= ? AND day <= ?
                GROUP BY TRIM(code)");
            $stmt->execute([$company_id, $pnl_day_from, $pnl_day_to]);
            foreach ($stmt->fetchAll() as $r) {
                $ck = trim((string)($r['code'] ?? ''));
                if ($ck === '') continue;
                $agent_range_burn[$ck] = (float)$r['bb'];
            }
        } catch (Throwable $ePnlBurn) {
            $agent_range_burn = [];
        }
    }
    } catch (Throwable $e) {
    $rows = [];
    $all_deposit_by_code = [];
    $all_withdraw_by_code = [];
    $all_burn_by_code = [];
    $all_rebate_by_code = [];
    $all_free_by_code = [];
    $all_free_withdraw_by_code = [];
    $month_deposit_by_code = [];
    $month_withdraw_by_code = [];
    $month_burn_by_code = [];
    $agent_range_dp = [];
    $agent_range_wd = [];
    $agent_range_burn = [];
    $err = $err ?: (strpos($e->getMessage(), 'recommend') !== false ? __('cust_err_migrate_recommend') : __('cust_err_migrate_detail')) . ' (' . $e->getMessage() . ')';
}

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    if (!$can_export_customers_csv) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo __('cust_export_err_boss_only');
        exit;
    }
    customer_export_log_try($pdo);
    $filename = 'customers_' . date('Ymd_His') . '.csv';
    if ($agent_view) {
        $safe_rec = preg_replace('/[^\w\-.@]+/', '_', $filter_recommend);
        $filename = 'customers_agent_' . ($safe_rec !== '' ? $safe_rec . '_' : '') . date('Ymd_His') . '.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($agent_view) {
        fputcsv($out, [__('cust_csv_code'), __('cust_csv_winlose')]);
        foreach ($rows as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($agent_pnl_by_range) {
                $all_dp = $agent_range_dp[$code] ?? 0;
                $all_wd = $agent_range_wd[$code] ?? 0;
            } else {
                $all_dp = $all_deposit_by_code[$code] ?? 0;
                $all_wd = $all_withdraw_by_code[$code] ?? 0;
            }
            $win_loss = $all_dp - $all_wd;
            fputcsv($out, [$code, number_format($win_loss, 2, '.', '')]);
        }
        fclose($out);
        exit;
    }
    $hdr = [
        __('cust_csv_code'),
        __('cust_csv_register_date'),
        __('cust_csv_full_name'),
        __('cust_csv_contact'),
        __('cust_csv_bank_details'),
    ];
    if ($can_view_total_dp_wd) {
        $hdr[] = __('cust_csv_total_dp');
        $hdr[] = __('cust_csv_total_wd');
    }
    $hdr[] = __('cust_csv_rebate');
    $hdr[] = __('cust_csv_free');
    $hdr[] = __('cust_csv_free_withdraw');
    $hdr[] = __('cust_csv_burn');
    $hdr[] = __('cust_csv_bonus');
    $hdr[] = __('cust_csv_deposit_month');
    $hdr[] = __('cust_csv_withdraw_month');
    $hdr[] = __('cust_csv_regular');
    $hdr[] = __('cust_csv_remark');
    $hdr[] = __('cust_csv_recommend');
    if ($is_admin) {
        $hdr[] = __('cust_csv_created_by');
        $hdr[] = __('cust_csv_is_active');
    }
    fputcsv($out, $hdr);
    foreach ($rows as $r) {
        $code = (string)($r['code'] ?? '');
        $phone_raw = (string)($r['phone'] ?? '');
        $phone_export = $can_view_contact_full ? $phone_raw : customer_mask_contact_for_member($phone_raw);
        $row = [
            $code,
            (string)($r['register_date'] ?? ''),
            (string)($r['name'] ?? ''),
            $phone_export,
            (string)($r['bank_details'] ?? ''),
        ];
        if ($can_view_total_dp_wd) {
            $all_dp = $all_deposit_by_code[$code] ?? 0;
            $all_wd = $all_withdraw_by_code[$code] ?? 0;
            $row[] = number_format($all_dp, 2, '.', '');
            $row[] = number_format($all_wd, 2, '.', '');
        }
        $all_rebate = $all_rebate_by_code[$code] ?? 0;
        $all_free = $all_free_by_code[$code] ?? 0;
        $all_fw = $all_free_withdraw_by_code[$code] ?? 0;
        $all_burn = $all_burn_by_code[$code] ?? 0;
        $all_bonus = $all_bonus_by_code[$code] ?? 0;
        $mon_dp = $month_deposit_by_code[$code] ?? 0;
        $mon_wd = $month_withdraw_by_code[$code] ?? 0;
        $row[] = number_format($all_rebate, 2, '.', '');
        $row[] = number_format($all_free, 2, '.', '');
        $row[] = number_format($all_fw, 2, '.', '');
        $row[] = number_format($all_burn, 2, '.', '');
        $row[] = number_format($all_bonus, 2, '.', '');
        $row[] = number_format($mon_dp, 2, '.', '');
        $row[] = number_format($mon_wd, 2, '.', '');
        $row[] = customer_regular_tier($balance_by_code[$code] ?? 0);
        $row[] = (string)($r['remark'] ?? '');
        $row[] = (string)($r['recommend'] ?? '');
        if ($is_admin) {
            $row[] = (string)($r['created_by_name'] ?? '');
            $row[] = ((int)($r['is_active'] ?? 0) === 1) ? __('status_enabled') : __('status_disabled');
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$export_csv_href = '';
if ($can_export_customers_csv) {
    $export_csv_q = ['export' => 'csv'];
    if ($agent_view) {
        $export_csv_q['recommend'] = $filter_recommend;
        if ($pnl_day_from !== '') {
            $export_csv_q['day_from'] = $pnl_day_from;
        }
        if ($pnl_day_to !== '') {
            $export_csv_q['day_to'] = $pnl_day_to;
        }
    }
    $export_csv_href = 'customers.php?' . http_build_query($export_csv_q);
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('nav_customers'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .column-toggle-bar {
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .column-toggle-btn {
            min-width: 108px;
            border-radius: 999px;
            border: 1px solid rgba(99, 131, 214, 0.28);
            background: linear-gradient(180deg, #f8fbff 0%, #edf3ff 100%);
            color: #37518f;
            font-weight: 700;
        }
        .column-toggle-btn.is-off {
            opacity: .66;
            filter: grayscale(18%);
        }
        /* Total Win(Lose) = DP − WD；负=红，正=蓝，平=灰 */
        .cust-pnl-cust-wins { color: var(--danger); font-weight: 700; font-variant-numeric: tabular-nums; }
        .cust-pnl-company-wins { color: #2563eb; font-weight: 700; font-variant-numeric: tabular-nums; }
        .cust-pnl-even { color: #64748b; font-weight: 600; font-variant-numeric: tabular-nums; }
        .cust-list-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }
        .cust-list-title-row h3 { margin: 0; }
        /* customer flags */
        .cust-flag-no-bonus td { background: #fef9c3 !important; } /* light yellow */
        .cust-flag-scam td { background: #fee2e2 !important; } /* soft red */
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap">
        <div class="page-header">
            <h2><?= htmlspecialchars(__('nav_customers'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="breadcrumb">
                <a href="dashboard.php"><?= htmlspecialchars(__('nav_home'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php if (has_permission('transaction_create')): ?><span>·</span><a href="transaction_create.php"><?= htmlspecialchars(__('nav_go_add_transaction'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                <?php if (has_permission('customer_create')): ?><span>·</span><a href="customer_create.php"><?= htmlspecialchars(__('nav_new_customer'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                <?php if (has_permission('product_library')): ?><span>·</span><a href="product_library.php"><?= htmlspecialchars(__('nav_product_accounts'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                <?php if ($is_admin): ?><span>·</span><a href="admin_option_sets.php"><?= htmlspecialchars(__('nav_option_sets'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="summary">
            <div class="summary-item"><strong>T1. Customer</strong><span class="num"><?= $summary['total'] ?></span></div>
            <div class="summary-item"><strong>Active Member</strong><span class="num"><?= $summary['active'] ?></span></div>
            <div class="summary-item"><strong>TARGET</strong><span class="num">1</span></div>
        </div>

        <div class="card" style="overflow-x: auto;">
            <?php if ($agent_view): ?>
            <div class="cust-list-title-row">
                <h3><?= htmlspecialchars(__('cust_agent_list_title'), ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if ($can_export_customers_csv): ?>
                <a href="<?= htmlspecialchars($export_csv_href, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline cust-export-csv-link" data-confirm="<?= htmlspecialchars(__('cust_export_confirm_download'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__('ui_btn_export'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php endif; ?>
            </div>
            <?php if ($agent_pnl_by_range): ?>
            <p class="form-hint" style="margin:-6px 0 14px; color:var(--muted);"><?= htmlspecialchars(__f('cust_agent_pnl_range', $pnl_day_from, $pnl_day_to), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
            <p class="form-hint" style="margin:-6px 0 14px; color:var(--muted);"><?= htmlspecialchars(__('cust_agent_pnl_alltime'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th class="num">Total Win(Lose)</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                    $agent_total_win_loss = 0;
                    foreach ($rows as $r):
                    $code = trim((string)($r['code'] ?? ''));
                    if ($agent_pnl_by_range) {
                        $all_dp = $agent_range_dp[$code] ?? 0;
                        $all_wd = $agent_range_wd[$code] ?? 0;
                    } else {
                        $all_dp = $all_deposit_by_code[$code] ?? 0;
                        $all_wd = $all_withdraw_by_code[$code] ?? 0;
                    }
                    $win_loss = $all_dp - $all_wd;
                    $agent_total_win_loss += $win_loss;
                    $wl_cls = $win_loss < 0 ? 'cust-pnl-cust-wins' : ($win_loss > 0 ? 'cust-pnl-company-wins' : 'cust-pnl-even');
                ?>
                    <tr>
                        <td><?= htmlspecialchars($code) ?></td>
                        <td class="num <?= $wl_cls ?>"><?= number_format($win_loss, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows): ?>
                    <?php
                    $tot_cls = $agent_total_win_loss < 0 ? 'cust-pnl-cust-wins' : ($agent_total_win_loss > 0 ? 'cust-pnl-company-wins' : 'cust-pnl-even');
                    ?>
                    <tr style="font-weight:bold;">
                        <td><?= htmlspecialchars(__('ui_total'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="num <?= $tot_cls ?>"><?= number_format($agent_total_win_loss, 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="2" style="color:var(--muted); padding:24px;">No customers under this agent.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="cust-list-title-row">
                <h3><?= htmlspecialchars(__('ui_label_list'), ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if ($can_export_customers_csv): ?>
                <a href="<?= htmlspecialchars($export_csv_href, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline cust-export-csv-link" data-confirm="<?= htmlspecialchars(__('cust_export_confirm_download'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__('ui_btn_export'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php endif; ?>
            </div>
            <?php if ($is_admin): ?>
            <div class="column-toggle-bar">
                <button type="button" class="btn btn-sm column-toggle-btn is-off" id="toggle-created-by" aria-pressed="false"><?= htmlspecialchars(__('ui_col_created_by'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="btn btn-sm column-toggle-btn" id="toggle-contact" aria-pressed="false">CONTACT</button>
                <?php if ($can_view_total_dp_wd): ?>
                <button type="button" class="btn btn-sm column-toggle-btn" id="toggle-total-dp" aria-pressed="false">Total DP</button>
                <button type="button" class="btn btn-sm column-toggle-btn" id="toggle-total-wd" aria-pressed="false">Total WD</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>REGISTER DATE</th>
                        <th>FULL NAME</th>
                        <th class="col-contact">CONTACT</th>
                        <th>BANK DETAILS</th>
                        <?php if ($can_view_total_dp_wd): ?><th class="col-total-dp">Total DP</th><?php endif; ?>
                        <?php if ($can_view_total_dp_wd): ?><th class="col-total-wd">Total WD</th><?php endif; ?>
                        <th>Rebate</th>
                        <th>Free</th>
                        <th>Free Withdraw</th>
                        <th>Burn</th>
                        <th>Bonus</th>
                        <th>deposit</th>
                        <th>withdraw</th>
                        <th>REGULAR</th>
                        <th>REMARK</th>
                        <th>RECOMMEND</th>
                        <?php if ($is_admin): ?><th class="col-created-by" style="display:none;"><?= htmlspecialchars(__('ui_col_created_by'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                        <?php if ($is_admin): ?><th><?= htmlspecialchars(__('ui_col_actions'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $code = $r['code'];
                    $flag = trim((string)($r['bonus_flag'] ?? ''));
                    $flag_cls = $flag === 'no_bonus' ? 'cust-flag-no-bonus' : ($flag === 'scam_receipt' ? 'cust-flag-scam' : '');
                    $all_dp = $all_deposit_by_code[$code] ?? 0;
                    $all_wd = $all_withdraw_by_code[$code] ?? 0;
                    $all_rebate = $all_rebate_by_code[$code] ?? 0;
                    $all_free = $all_free_by_code[$code] ?? 0;
                    $all_fw = $all_free_withdraw_by_code[$code] ?? 0;
                    $all_burn = $all_burn_by_code[$code] ?? 0;
                    $all_bonus = $all_bonus_by_code[$code] ?? 0;
                    $mon_dp = $month_deposit_by_code[$code] ?? 0;
                    $mon_wd = $month_withdraw_by_code[$code] ?? 0;
                ?>
                    <tr class="<?= $flag_cls ?>">
                        <td><a href="customer_edit.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($code) ?></a></td>
                        <td><?= htmlspecialchars($r['register_date'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <?php
                            $phone_raw = (string)($r['phone'] ?? '');
                            $phone_show = $can_view_contact_full ? $phone_raw : customer_mask_contact_for_member($phone_raw);
                        ?>
                        <td class="col-contact"><?= htmlspecialchars($phone_show) ?></td>
                        <td><?= htmlspecialchars($r['bank_details'] ?? '') ?></td>
                        <?php if ($can_view_total_dp_wd): ?><td class="col-total-dp num"><?= number_format($all_dp, 2) ?></td><?php endif; ?>
                        <?php if ($can_view_total_dp_wd): ?><td class="col-total-wd num"><?= number_format($all_wd, 2) ?></td><?php endif; ?>
                        <td class="num"><?= number_format($all_rebate, 2) ?></td>
                        <td class="num"><?= number_format($all_free, 2) ?></td>
                        <td class="num"><?= number_format($all_fw, 2) ?></td>
                        <td class="num"><?= number_format($all_burn, 2) ?></td>
                        <td class="num"><?= number_format($all_bonus, 2) ?></td>
                        <td class="num"><?= number_format($mon_dp, 2) ?></td>
                        <td class="num"><?= number_format($mon_wd, 2) ?></td>
                        <td><?= htmlspecialchars(customer_regular_tier($balance_by_code[$code] ?? 0)) ?></td>
                        <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['recommend'] ?? '') ?></td>
                        <?php if ($is_admin): ?><td class="col-created-by" style="display:none;"><?= htmlspecialchars($r['created_by_name'] ?? '—') ?></td><?php endif; ?>
                        <?php if ($is_admin): ?>
                        <td>
                            <a href="customer_edit.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars(__('cust_edit'), ENT_QUOTES, 'UTF-8') ?></a>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-gray"><?= ((int)$r['is_active'] === 1) ? htmlspecialchars(__('cust_btn_disable'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('cust_btn_enable'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= (int)$customers_list_colspan ?>" style="color:var(--muted); padding:24px;"><?= htmlspecialchars(__('cust_err_migrate'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
    <script>
    (function(){
        function toggleCol(btnId, colClass, columnStartsVisible) {
            columnStartsVisible = columnStartsVisible !== false;
            var btn = document.getElementById(btnId);
            if (!btn) return;
            var cells = document.querySelectorAll('th.' + colClass + ', td.' + colClass);
            function setOff(off) {
                if (off) btn.classList.add('is-off');
                else btn.classList.remove('is-off');
            }
            setOff(!columnStartsVisible);
            btn.addEventListener('click', function(){
                var visible = columnStartsVisible ? (btn.getAttribute('aria-pressed') !== 'true') : (btn.getAttribute('aria-pressed') === 'true');
                var newVisible = !visible;
                btn.setAttribute('aria-pressed', newVisible ? (columnStartsVisible ? 'false' : 'true') : (columnStartsVisible ? 'true' : 'false'));
                cells.forEach(function(el){ el.style.display = newVisible ? '' : 'none'; });
                setOff(!newVisible);
            });
        }
        toggleCol('toggle-created-by', 'col-created-by', false);
        toggleCol('toggle-contact', 'col-contact', true);
        toggleCol('toggle-total-dp', 'col-total-dp', true);
        toggleCol('toggle-total-wd', 'col-total-wd', true);
        document.querySelectorAll('a.cust-export-csv-link[data-confirm]').forEach(function(a){
            a.addEventListener('click', function(e){
                e.preventDefault();
                var href = a.getAttribute('href') || '';
                var msg = a.getAttribute('data-confirm') || '';
                if (window.appModalConfirm) {
                    window.appModalConfirm(msg, function(){ window.location.href = href; });
                } else if (confirm(msg)) {
                    window.location.href = href;
                }
            });
        });
    })();
    </script>
</body>
</html>
