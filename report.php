<?php
require 'config.php';
require 'auth.php';
require_permission('statement_report');
$sidebar_current = 'report';

$err = '';
$today = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to = date('Y-m-t');
$company_id = current_company_id();
$day_from_raw = $_GET['day_from'] ?? $default_from;
$day_to_raw = $_GET['day_to'] ?? $default_to;
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_from_raw) ? substr($day_from_raw, 0, 10) : $default_from;
$day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_to_raw) ? substr($day_to_raw, 0, 10) : $default_to;
if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }

$total_in = 0.0;
$total_out = 0.0;
$total_expenses = 0.0;
$profit = 0.0;
$approved_count = 0;
$mode_rows = [];
$top_customer_rows = [];
$contra_in = 0.0;
$contra_out = 0.0;
$contra_rows = [];
$expense_total = 0.0;
$expense_rows = [];
$expense_product_rows = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT'
                               AND bank IS NOT NULL AND TRIM(bank) <> ''
                               AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %'))
                              THEN amount ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN mode = 'WITHDRAW'
                               AND bank IS NOT NULL AND TRIM(bank) <> ''
                               AND (remark IS NULL OR (remark NOT LIKE '转至 %' AND remark NOT LIKE '来自 %'))
                              THEN amount ELSE 0 END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS total_expenses,
            COUNT(*) AS approved_count
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ?
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_in = (float)($row['total_in'] ?? 0);
    $total_out = (float)($row['total_out'] ?? 0);
    $total_expenses = (float)($row['total_expenses'] ?? 0);
    $approved_count = (int)($row['approved_count'] ?? 0);
    $profit = $total_in - $total_out - $total_expenses;

    $stmt = $pdo->prepare("
        SELECT mode, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amt
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ?
        GROUP BY mode
        ORDER BY amt DESC
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $mode_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT code,
               COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
               COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ? AND code IS NOT NULL AND TRIM(code) <> ''
        GROUP BY code
        ORDER BY (COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0)) DESC
        LIMIT 10
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $top_customer_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bank Contra（互转）：按备注「转至/来自」识别
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' AND remark LIKE '来自 %' THEN amount ELSE 0 END), 0) AS contra_in,
            COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' AND remark LIKE '转至 %' THEN amount ELSE 0 END), 0) AS contra_out
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ?
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $contra_in = (float)($row['contra_in'] ?? 0);
    $contra_out = (float)($row['contra_out'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT day, time, bank, mode, amount, remark
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ?
          AND ((mode = 'DEPOSIT' AND remark LIKE '来自 %')
            OR (mode = 'WITHDRAW' AND remark LIKE '转至 %'))
        ORDER BY day DESC, time DESC
        LIMIT 30
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $contra_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Expense（开销）
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS expense_total
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ? AND mode = 'EXPENSE'
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $expense_total = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT day, time, code, bank, product, amount, remark
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ? AND mode = 'EXPENSE'
        ORDER BY day DESC, time DESC
        LIMIT 30
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $expense_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(product), ''), '未填写产品') AS product_name,
            COUNT(*) AS cnt,
            COALESCE(SUM(amount), 0) AS total_amount,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(bank), '') ORDER BY bank SEPARATOR ', ') AS bank_list
        FROM transactions
        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND day >= ? AND day <= ? AND mode = 'EXPENSE'
        GROUP BY COALESCE(NULLIF(TRIM(product), ''), '未填写产品')
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $expense_product_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = '报表加载失败：' . $e->getMessage();
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .report-wrap { max-width: 1160px; }
        .report-filter-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.92), rgba(242,247,255,0.9));
            border: 1px solid rgba(115, 146, 230, 0.28);
        }
        .report-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .report-kpi-item {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(125, 152, 226, 0.24);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 6px 14px rgba(43, 71, 146, 0.08);
        }
        .report-kpi-item strong {
            display: block;
            font-size: 11px;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .report-kpi-item .num {
            font-size: 1.65rem;
            font-weight: 700;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .report-section-card {
            padding: 14px 16px;
            margin-bottom: 14px;
            border-radius: 14px;
            border: 1px solid rgba(114, 146, 238, 0.24);
            box-shadow: 0 8px 20px rgba(36, 56, 115, 0.08);
        }
        .report-collapse-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 0;
            font-size: 17px;
            color: #1e293b;
        }
        .report-collapse-btn {
            border: 1px solid rgba(86, 118, 203, 0.34);
            background: linear-gradient(180deg, #f8fbff, #ecf3ff);
            color: #2747c7;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .report-collapse-body { margin-top: 12px; }
        .report-collapse-body.collapsed { display: none; }
        .report-mini-summary { margin-bottom: 10px; gap: 10px; }
        .report-mini-summary .summary-item { min-width: 130px; padding: 12px 14px; }
        .report-mini-summary .summary-item .num { font-size: 1.2rem; }
        @media (max-width: 980px) {
            .report-kpi-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
        }
        @media (max-width: 640px) {
            .report-kpi-grid { grid-template-columns: 1fr; }
            .report-section-card { padding: 12px; }
            .report-collapse-head { font-size: 16px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap report-wrap">
                <div class="page-header">
                    <h2>Report</h2>
                    <p class="breadcrumb"><a href="dashboard.php"><?= app_lang() === 'en' ? 'Home' : '首页' ?></a><span>·</span><?= app_lang() === 'en' ? 'Report' : '数据报表' ?></p>
                </div>

                <form class="filters-bar filters-bar-flow report-filter-card" method="get" style="margin-bottom:16px;">
                    <div class="filters-row filters-row-main">
                        <div class="filter-group">
                            <label>From:</label>
                            <input type="date" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                        </div>
                        <div class="filter-group">
                            <label>To:</label>
                            <input type="date" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                        </div>
                        <button type="submit" class="btn btn-search">Search</button>
                    </div>
                    <div class="filters-row filters-row-presets">
                        <a href="report.php?<?= http_build_query(['day_from' => date('Y-m-d', strtotime('monday this week')), 'day_to' => date('Y-m-d', strtotime('sunday this week'))]) ?>" class="btn btn-preset">This Week</a>
                        <a href="report.php?<?= http_build_query(['day_from' => date('Y-m-01'), 'day_to' => date('Y-m-t')]) ?>" class="btn btn-preset">This Month</a>
                    </div>
                </form>

                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="report-kpi-grid">
                    <div class="report-kpi-item"><strong>总入</strong><span class="num" style="color:var(--success);"><?= number_format($total_in, 2) ?></span></div>
                    <div class="report-kpi-item"><strong>总出</strong><span class="num" style="color:var(--danger);"><?= number_format($total_out, 2) ?></span></div>
                    <div class="report-kpi-item"><strong>开销</strong><span class="num" style="color:#b45309;"><?= number_format($total_expenses, 2) ?></span></div>
                    <div class="report-kpi-item"><strong>利润</strong><span class="num"><?= number_format($profit, 2) ?></span></div>
                </div>

                <div class="card report-section-card">
                    <h3 class="report-collapse-head">
                        <span><?= app_lang() === 'en' ? 'Mode Summary' : '模式汇总' ?></span>
                        <button type="button" class="report-collapse-btn js-report-toggle" data-target="report-mode-body" aria-expanded="false"><?= app_lang() === 'en' ? 'Expand' : '展开' ?></button>
                    </h3>
                    <div id="report-mode-body" class="report-collapse-body collapsed" style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mode</th>
                                <th class="num">Count</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mode_rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($r['mode'] ?? '')) ?></td>
                                <td class="num"><?= (int)($r['cnt'] ?? 0) ?></td>
                                <td class="num"><?= number_format((float)($r['amt'] ?? 0), 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($mode_rows)): ?><tr><td colspan="3">暂无数据</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="card report-section-card">
                    <h3 class="report-collapse-head">
                        <span><?= app_lang() === 'en' ? 'Top 10 Net Customers' : '客户净额 Top 10' ?></span>
                        <button type="button" class="report-collapse-btn js-report-toggle" data-target="report-top-body" aria-expanded="false"><?= app_lang() === 'en' ? 'Expand' : '展开' ?></button>
                    </h3>
                    <div id="report-top-body" class="report-collapse-body collapsed" style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th class="num">Deposit</th>
                                <th class="num">Withdraw</th>
                                <th class="num">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_customer_rows as $r):
                                $in = (float)($r['total_in'] ?? 0);
                                $out = (float)($r['total_out'] ?? 0);
                                $net = $in - $out;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($r['code'] ?? '')) ?></td>
                                <td class="num"><?= number_format($in, 2) ?></td>
                                <td class="num"><?= number_format($out, 2) ?></td>
                                <td class="num <?= $net >= 0 ? 'num in' : 'num out' ?>"><?= number_format($net, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_customer_rows)): ?><tr><td colspan="4">暂无数据</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="card report-section-card">
                    <h3 class="report-collapse-head">
                        <span>Bank Contra</span>
                        <button type="button" class="report-collapse-btn js-report-toggle" data-target="report-contra-body" aria-expanded="false"><?= app_lang() === 'en' ? 'Expand' : '展开' ?></button>
                    </h3>
                    <div id="report-contra-body" class="report-collapse-body collapsed">
                        <div class="summary report-mini-summary">
                            <div class="summary-item"><strong>Contra In</strong><span class="num" style="color:var(--success);"><?= number_format($contra_in, 2) ?></span></div>
                            <div class="summary-item"><strong>Contra Out</strong><span class="num" style="color:var(--danger);"><?= number_format($contra_out, 2) ?></span></div>
                            <div class="summary-item"><strong>Diff</strong><span class="num"><?= number_format($contra_in - $contra_out, 2) ?></span></div>
                        </div>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Time</th>
                                        <th>Bank</th>
                                        <th>Mode</th>
                                        <th class="num">Amount</th>
                                        <th>Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contra_rows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($r['day'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['time'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['bank'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['mode'] ?? '')) ?></td>
                                        <td class="num"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                        <td><?= htmlspecialchars((string)($r['remark'] ?? '')) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($contra_rows)): ?><tr><td colspan="6">暂无互转记录</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card report-section-card">
                    <h3 class="report-collapse-head">
                        <span>Expense</span>
                        <button type="button" class="report-collapse-btn js-report-toggle" data-target="report-expense-body" aria-expanded="false"><?= app_lang() === 'en' ? 'Expand' : '展开' ?></button>
                    </h3>
                    <div id="report-expense-body" class="report-collapse-body collapsed">
                        <div class="summary report-mini-summary">
                            <div class="summary-item"><strong>Expense Total</strong><span class="num" style="color:#b45309;"><?= number_format($expense_total, 2) ?></span></div>
                            <div class="summary-item"><strong>Count</strong><span class="num"><?= count($expense_rows) ?></span></div>
                            <div class="summary-item"><strong>Product Count</strong><span class="num"><?= count($expense_product_rows) ?></span></div>
                        </div>
                        <div style="overflow-x:auto; margin-bottom: 10px;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Related Bank</th>
                                        <th class="num">Count</th>
                                        <th class="num">Total Expense</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expense_product_rows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($r['product_name'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['bank_list'] ?? '-')) ?></td>
                                        <td class="num"><?= (int)($r['cnt'] ?? 0) ?></td>
                                        <td class="num"><?= number_format((float)($r['total_amount'] ?? 0), 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($expense_product_rows)): ?><tr><td colspan="4">暂无 Product 开销汇总</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Time</th>
                                        <th>Code</th>
                                        <th>Bank</th>
                                        <th>Product</th>
                                        <th class="num">Amount</th>
                                        <th>Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expense_rows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($r['day'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['time'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['code'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['bank'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($r['product'] ?? '')) ?></td>
                                        <td class="num"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                        <td><?= htmlspecialchars((string)($r['remark'] ?? '')) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($expense_rows)): ?><tr><td colspan="7">暂无开销记录</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
    (function(){
        var expandText = <?= json_encode(app_lang() === 'en' ? 'Expand' : '展开', JSON_UNESCAPED_UNICODE) ?>;
        var collapseText = <?= json_encode(app_lang() === 'en' ? 'Collapse' : '收起', JSON_UNESCAPED_UNICODE) ?>;
        document.querySelectorAll('.js-report-toggle').forEach(function(btn){
            var targetId = btn.getAttribute('data-target');
            var body = targetId ? document.getElementById(targetId) : null;
            if (!body) return;
            btn.addEventListener('click', function(){
                var collapsed = body.classList.contains('collapsed');
                if (collapsed) {
                    body.classList.remove('collapsed');
                    btn.textContent = collapseText;
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    body.classList.add('collapsed');
                    btn.textContent = expandText;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        });
    })();
    </script>
</body>
</html>

