<?php
require 'config.php';
require 'auth.php';
require_login();
if (($_SESSION['user_role'] ?? '') === 'agent') {
    header('Location: agents.php');
    exit;
}
require_permission('home_dashboard');

$show_dashboard_month = (($_SESSION['user_role'] ?? '') !== 'admin') || has_permission(PERM_DASHBOARD_MONTH_DATA);

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$company_id = current_company_id();
$dashboard_all_companies = function_exists('is_superadmin_all_companies_scope') && is_superadmin_all_companies_scope();

$sidebar_current = 'dashboard';
$db_error = '';
$day_in = $day_out = $day_profit = 0;
$month_in = $month_out = $month_expenses = $month_profit = 0;
$day_free = $day_free_withdraw = $day_rebate = $day_bonus = 0;
$month_free = $month_free_withdraw = $month_rebate = $month_bonus = 0;
$day_customers_count = 0;
$day_orders_count = 0;
$day_new_customers = 0;
$day_new_customer_orders = 0;
$top_customer_rows = [];

try {
    $has_register_date = false;
    try {
        $stmt_col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'register_date'");
        $has_register_date = ((int)$stmt_col->fetchColumn() > 0);
    } catch (Throwable $e) {
        $has_register_date = false;
    }
    $customer_day_filter = $has_register_date ? "DATE(register_date) = ?" : "DATE(created_at) = ?";
    $customer_day_filter_alias = $has_register_date ? "DATE(c.register_date) = ?" : "DATE(c.created_at) = ?";

    $tx_day_where = $dashboard_all_companies ? "day = ? AND status = 'approved' AND deleted_at IS NULL" : "company_id = ? AND day = ? AND status = 'approved' AND deleted_at IS NULL";
    $tx_month_where = $dashboard_all_companies ? "day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL" : "company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL";

    $sum_line = "COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' AND bank IS NOT NULL AND TRIM(bank) != '' AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %')) THEN amount ELSE 0 END), 0) AS total_in,
                                  COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' AND bank IS NOT NULL AND TRIM(bank) != '' AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %')) THEN amount ELSE 0 END), 0) AS total_out,
                                  COALESCE(SUM(CASE WHEN mode = 'FREE' THEN amount ELSE 0 END), 0) AS free,
                                  COALESCE(SUM(CASE WHEN mode = 'FREE WITHDRAW' THEN amount ELSE 0 END), 0) AS free_withdraw,
                                  COALESCE(SUM(CASE WHEN mode = 'REBATE' THEN amount ELSE 0 END), 0) AS rebate,
                                  COALESCE(SUM(COALESCE(bonus, 0)), 0) AS bonus";

    // 今日统计（只统计已批准）；入账/出账仅统计银行渠道且排除银行互转（remark 转至/来自）
    $stmt = $pdo->prepare("SELECT {$sum_line} FROM transactions WHERE {$tx_day_where}");
    $stmt->execute($dashboard_all_companies ? [$today] : [$company_id, $today]);
    $day = $stmt->fetch();
    $day_in   = (float)($day['total_in'] ?? 0);
    $day_out  = (float)($day['total_out'] ?? 0);
    $day_profit = $day_in - $day_out;
    $day_free = (float)($day['free'] ?? 0);
    $day_free_withdraw = (float)($day['free_withdraw'] ?? 0);
    $day_rebate = (float)($day['rebate'] ?? 0);
    $day_bonus = (float)($day['bonus'] ?? 0);

    if ($show_dashboard_month) {
        // 本月统计（只统计已批准）；入账/出账仅统计银行渠道且排除银行互转（remark 转至/来自）
        $sum_line_month = "COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' AND bank IS NOT NULL AND TRIM(bank) != '' AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %')) THEN amount ELSE 0 END), 0) AS total_in,
                                      COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' AND bank IS NOT NULL AND TRIM(bank) != '' AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %')) THEN amount ELSE 0 END), 0) AS total_out,
                                      COALESCE(SUM(CASE WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS total_expenses,
                                      COALESCE(SUM(CASE WHEN mode = 'FREE' THEN amount ELSE 0 END), 0) AS free,
                                      COALESCE(SUM(CASE WHEN mode = 'FREE WITHDRAW' THEN amount ELSE 0 END), 0) AS free_withdraw,
                                      COALESCE(SUM(CASE WHEN mode = 'REBATE' THEN amount ELSE 0 END), 0) AS rebate,
                                      COALESCE(SUM(COALESCE(bonus, 0)), 0) AS bonus";
        $stmt = $pdo->prepare("SELECT {$sum_line_month} FROM transactions WHERE {$tx_month_where}");
        $stmt->execute($dashboard_all_companies ? [$month_start, $month_end] : [$company_id, $month_start, $month_end]);
        $month = $stmt->fetch();
        $month_in       = (float)($month['total_in'] ?? 0);
        $month_out      = (float)($month['total_out'] ?? 0);
        $month_expenses = (float)($month['total_expenses'] ?? 0);
        $month_profit   = $month_in - $month_out - $month_expenses;
        $month_free = (float)($month['free'] ?? 0);
        $month_free_withdraw = (float)($month['free_withdraw'] ?? 0);
        $month_rebate = (float)($month['rebate'] ?? 0);
        $month_bonus = (float)($month['bonus'] ?? 0);
    }

    // Customer report：Top 10 Net Customers（本月区间）
    try {
        $stmt = $pdo->prepare("
            SELECT code,
                   COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                   COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out,
                   COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS total_burn
            FROM transactions
            WHERE " . ($dashboard_all_companies ? "day >= ? AND day <= ?" : "company_id = ? AND day >= ? AND day <= ?") . "
              AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) <> ''
            GROUP BY code
            ORDER BY (COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0)
                      - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0)
                      - COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0)) DESC
            LIMIT 10
        ");
        $stmt->execute($dashboard_all_companies ? [$month_start, $month_end] : [$company_id, $month_start, $month_end]);
        $top_customer_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eTop) {
        // burn 列不存在时回退（不含 burn 扣减）
        $stmt = $pdo->prepare("
            SELECT code,
                   COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                   COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
            FROM transactions
            WHERE " . ($dashboard_all_companies ? "day >= ? AND day <= ?" : "company_id = ? AND day >= ? AND day <= ?") . "
              AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) <> ''
            GROUP BY code
            ORDER BY (COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0)) DESC
            LIMIT 10
        ");
        $stmt->execute($dashboard_all_companies ? [$month_start, $month_end] : [$company_id, $month_start, $month_end]);
        $top_customer_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 今日上线客户数（分公司内不重复 code；总公司视图按 company_id+code 区分不同分公司同名客户）
    if ($dashboard_all_companies) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(company_id, ':', TRIM(code))) FROM transactions WHERE day = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != ''");
        $stmt->execute([$today]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT code) FROM transactions WHERE company_id = ? AND day = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND code != ''");
        $stmt->execute([$company_id, $today]);
    }
    $day_customers_count = (int) $stmt->fetchColumn();

    // 今日单数（今日已批准流水条数）
    if ($dashboard_all_companies) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE day = ? AND status = 'approved' AND deleted_at IS NULL");
        $stmt->execute([$today]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND day = ? AND status = 'approved' AND deleted_at IS NULL");
        $stmt->execute([$company_id, $today]);
    }
    $day_orders_count = (int) $stmt->fetchColumn();

    // 几个新顾客（今日新增的顾客数）
    if ($dashboard_all_companies) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE {$customer_day_filter}");
        $stmt->execute([$today]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND {$customer_day_filter}");
        $stmt->execute([$company_id, $today]);
    }
    $day_new_customers = (int) $stmt->fetchColumn();

    // 新客户进多少单（今日已批准流水中，顾客与本公司「今日注册」顾客一致；占位符顺序与 JOIN/WHERE 一致）
    if ($dashboard_all_companies) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t INNER JOIN customers c ON c.code = t.code AND c.company_id = t.company_id AND {$customer_day_filter_alias} WHERE t.day = ? AND t.status = 'approved' AND t.deleted_at IS NULL");
        $stmt->execute([$today, $today]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t INNER JOIN customers c ON c.code = t.code AND c.company_id = t.company_id AND {$customer_day_filter_alias} WHERE t.company_id = ? AND c.company_id = ? AND t.day = ? AND t.status = 'approved' AND t.deleted_at IS NULL");
        $stmt->execute([$today, $company_id, $company_id, $today]);
    }
    $day_new_customer_orders = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

$dash_brand = defined('SITE_TITLE') ? SITE_TITLE : 'K8';
if ($company_id > 0) {
    try {
        $stBrand = $pdo->prepare('SELECT TRIM(COALESCE(code, \'\')) AS c, TRIM(COALESCE(name, \'\')) AS n FROM companies WHERE id = ? LIMIT 1');
        $stBrand->execute([$company_id]);
        $br = $stBrand->fetch(PDO::FETCH_ASSOC);
        if ($br) {
            $bc = (string)($br['c'] ?? '');
            $bn = (string)($br['n'] ?? '');
            if ($bc !== '') {
                $dash_brand = $bc;
            } elseif ($bn !== '') {
                $dash_brand = $bn;
            }
        }
    } catch (Throwable $e) {
        /* 保持 SITE_TITLE */
    }
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $dash_site = $dash_brand; ?>
    <title><?= htmlspecialchars(__f('dash_page_title', $dash_site), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        /* 首页统计卡片做紧凑版 */
        .dashboard-compact .stat-cards { gap: 12px; }
        .dashboard-compact .stat-card { padding: 14px 16px; min-width: 112px; border-radius: 12px; }
        .dashboard-compact .stat-card .label { font-size: 10px; margin-bottom: 6px; }
        .dashboard-compact .stat-card .value { font-size: 1.05rem; }
        .dashboard-compact .card h3 { margin-bottom: 12px; }
        .dashboard-compact .total-table-wrap { margin-top: 12px; }
        .dash-collapse-head { display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .dash-collapse-body.collapsed { display:none; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main dashboard-compact">
            <div class="page-header dashboard-header">
                <?php $dash_uname = $_SESSION['user_name'] ?? __('user_default'); ?>
                <h1><?= htmlspecialchars(__f('dash_title', $dash_brand, $dash_uname), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="welcome-role">
                    <?= htmlspecialchars(__('dash_greet'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($dash_uname, ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php
                    $__r = $_SESSION['user_role'] ?? '';
                    $__badge = $__r === 'superadmin' ? __('role_bb') : ($__r === 'boss' ? __('role_boss') : ($__r === 'admin' ? __('role_admin') : ($__r === 'member' ? __('role_member') : ($__r === 'agent' ? __('role_agent') : __('role_staff')))));
                    ?>
                    <span class="role-badge"><?= htmlspecialchars($__badge, ENT_QUOTES, 'UTF-8') ?></span>
                </p>
            </div>

            <?php if ($db_error): ?>
                <div class="card alert-error">
                    <h3 style="margin-top:0; color:#991b1b;"><?= htmlspecialchars(__('dash_db_err_title'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div style="font-size: 13px; line-height: 1.6;">
                        <div><b><?= htmlspecialchars(__('dash_db_err_msg'), ENT_QUOTES, 'UTF-8') ?></b>：<?= htmlspecialchars($db_error) ?></div>
                        <div style="margin-top:10px;">
                            <b><?= htmlspecialchars(__('dash_db_fix'), ENT_QUOTES, 'UTF-8') ?></b>：<?= __('dash_db_fix_body') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3><?= htmlspecialchars(__f('dash_today', $today), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="stat-cards">
                    <div class="stat-card in">
                        <div class="label"><?= htmlspecialchars(__('dash_today_in'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format((float)$day_in, 2) ?></div>
                    </div>
                    <div class="stat-card out">
                        <div class="label"><?= htmlspecialchars(__('dash_today_out'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format((float)$day_out, 2) ?></div>
                    </div>
                    <div class="stat-card profit">
                        <div class="label"><?= htmlspecialchars(__('dash_today_profit'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format($day_profit, 2) ?></div>
                    </div>
                </div>
                <div class="stat-cards" style="margin-top: 12px;">
                    <div class="stat-card" style="border-left-color: #0d9488;">
                        <div class="label">FREE</div>
                        <div class="value" style="color: #0d9488;"><?= number_format($day_free, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #b45309;">
                        <div class="label">FREE WITHDRAW</div>
                        <div class="value" style="color: #b45309;"><?= number_format($day_free_withdraw, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #7c3aed;">
                        <div class="label">REBATE</div>
                        <div class="value" style="color: #7c3aed;"><?= number_format($day_rebate, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #0891b2;">
                        <div class="label">BONUS</div>
                        <div class="value" style="color: #0891b2;"><?= number_format($day_bonus, 2) ?></div>
                    </div>
                </div>
                <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px;">
                <?php if (($_SESSION['user_role'] ?? '') === 'member'): ?>
                <p class="form-hint" style="margin-top:0; grid-column: 1 / -1;"><?= htmlspecialchars(__('dash_member_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                    <div>
                        <h4><?= htmlspecialchars(__('dash_sec_customers_today'), ENT_QUOTES, 'UTF-8') ?></h4>
                        <div class="stat-cards" style="margin-top:8px;">
                            <div class="stat-card" style="border-left-color: var(--primary);">
                                <div class="label"><?= htmlspecialchars(__('dash_active_customers'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="value" style="color: var(--primary);"><?= (int)$day_customers_count ?></div>
                            </div>
                            <div class="stat-card" style="border-left-color: var(--muted);">
                                <div class="label"><?= htmlspecialchars(__('dash_orders_count'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="value" style="color: #475569;"><?= (int)$day_orders_count ?></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4><?= htmlspecialchars(__('dash_sec_new_customers'), ENT_QUOTES, 'UTF-8') ?></h4>
                        <div class="stat-cards" style="margin-top:8px;">
                            <div class="stat-card" style="border-left-color: var(--success);">
                                <div class="label"><?= htmlspecialchars(__('dash_new_customers'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="value" style="color: var(--success);"><?= (int)$day_new_customers ?></div>
                            </div>
                            <div class="stat-card" style="border-left-color: var(--primary);">
                                <div class="label"><?= htmlspecialchars(__('dash_new_customer_orders'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="value" style="color: var(--primary);"><?= (int)$day_new_customer_orders ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3><?= htmlspecialchars(__('dash_customer_report'), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="dash-collapse-head" style="margin-top:6px;">
                    <div style="font-weight:800; color:#1e3a8a;"><?= htmlspecialchars(__('dash_top10_net_customers'), ENT_QUOTES, 'UTF-8') ?></div>
                    <button type="button" class="btn btn-sm btn-outline js-dash-toggle" data-target="dash-top-customers" aria-expanded="false">
                        <?= htmlspecialchars(__('ui_btn_expand'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <div id="dash-top-customers" class="dash-collapse-body collapsed" style="overflow-x:auto; margin-top:10px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('cust_col_customer'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_deposit'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_withdraw'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_net'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_customer_rows as $r):
                                $in = (float)($r['total_in'] ?? 0);
                                $out = (float)($r['total_out'] ?? 0);
                                $burn = (float)($r['total_burn'] ?? 0);
                                $net = $in - $out - $burn;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($r['code'] ?? '')) ?></td>
                                <td class="num"><?= number_format($in, 2) ?></td>
                                <td class="num"><?= number_format($out, 2) ?></td>
                                <td class="num <?= $net >= 0 ? 'in' : 'out' ?>"><?= number_format($net, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_customer_rows)): ?>
                                <tr><td colspan="4" style="color:var(--muted); padding:18px;"><?= htmlspecialchars(__('cust_empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($show_dashboard_month): ?>
            <label class="month-toggle">
                <input type="checkbox" id="show_month" onchange="document.getElementById('month-card').classList.toggle('visible', this.checked)">
                <?= htmlspecialchars(__('dash_show_month'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <div class="card" id="month-card">
                <h3><?= htmlspecialchars(__f('dash_month', $month_start, $month_end), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="stat-cards">
                    <div class="stat-card in">
                        <div class="label"><?= htmlspecialchars(__('dash_month_in'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format((float)$month_in, 2) ?></div>
                    </div>
                    <div class="stat-card out">
                        <div class="label"><?= htmlspecialchars(__('dash_month_out'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format((float)$month_out, 2) ?></div>
                    </div>
                    <div class="stat-card expense">
                        <div class="label"><?= htmlspecialchars(__('dash_month_expense'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format((float)$month_expenses, 2) ?></div>
                    </div>
                    <div class="stat-card profit">
                        <div class="label"><?= htmlspecialchars(__('dash_month_profit'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="value"><?= number_format($month_profit, 2) ?></div>
                    </div>
                </div>
                <div class="stat-cards" style="margin-top: 12px;">
                    <div class="stat-card" style="border-left-color: #0d9488;">
                        <div class="label">FREE</div>
                        <div class="value" style="color: #0d9488;"><?= number_format($month_free, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #b45309;">
                        <div class="label">FREE WITHDRAW</div>
                        <div class="value" style="color: #b45309;"><?= number_format($month_free_withdraw, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #7c3aed;">
                        <div class="label">REBATE</div>
                        <div class="value" style="color: #7c3aed;"><?= number_format($month_rebate, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #0891b2;">
                        <div class="label">BONUS</div>
                        <div class="value" style="color: #0891b2;"><?= number_format($month_bonus, 2) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
    (function(){
        document.querySelectorAll('.js-dash-toggle').forEach(function(btn){
            btn.addEventListener('click', function(){
                var id = btn.getAttribute('data-target');
                if (!id) return;
                var body = document.getElementById(id);
                if (!body) return;
                var collapsed = body.classList.contains('collapsed');
                body.classList.toggle('collapsed', !collapsed);
                btn.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
                btn.textContent = collapsed ? (<?= json_encode(__('ui_btn_collapse')) ?>) : (<?= json_encode(__('ui_btn_expand')) ?>);
            });
        });
    })();
    </script>
</body>
</html>
