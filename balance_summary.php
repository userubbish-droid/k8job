<?php
require 'config.php';
require 'auth.php';
require_login();
require_permission('statement_balance');

$sidebar_current = 'balance_summary';
$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'boss', 'superadmin'], true);

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : null;
$day_to   = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : null;
$day      = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day']) ? $_GET['day'] : null;
if ($day_from !== null && $day_to !== null) {
    if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }
} elseif ($day !== null) {
    $day_from = $day_to = $day;
} else {
    $day_from = $day_to = date('Y-m-d');
}
$is_range = ($day_from !== $day_to);
$msg = '';
$err = '';

$head_office_stmt = is_superadmin_head_office_scope();

$stmt_co = 0;
if ($head_office_stmt && isset($_GET['stmt_co'])) {
    $stmt_co = (int)$_GET['stmt_co'];
}

$company_rows = [];
if ($head_office_stmt) {
    try {
        $company_rows = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $company_rows = [];
    }
}

$company_id = current_company_id();
if ($head_office_stmt) {
    $ids_ok = array_map('intval', array_column($company_rows, 'id'));
    if ($stmt_co > 0 && in_array($stmt_co, $ids_ok, true)) {
        $company_id = $stmt_co;
    } elseif ($ids_ok !== []) {
        $company_id = $ids_ok[0];
    } else {
        $company_id = -1;
    }
}

$biz_kind = 'gaming';
try {
    $pdoCatBk = function_exists('shard_catalog') ? shard_catalog() : $pdo;
    $stBk = $pdoCatBk->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $biz_kind = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {
    $biz_kind = 'gaming';
}
if (!in_array($biz_kind, ['gaming', 'pg'], true)) {
    $biz_kind = 'gaming';
}

$has_deleted_at = true;
try {
    $pdo->query('SELECT deleted_at FROM transactions LIMIT 0');
} catch (Throwable $e) {
    $has_deleted_at = false;
}
$del = $has_deleted_at ? ' AND deleted_at IS NULL' : '';
$min_approved_day = null;
try {
    $stMin = $pdo->prepare("SELECT MIN(day) AS d FROM transactions WHERE company_id = ? AND status = 'approved'{$del}");
    $stMin->execute([$company_id]);
    $min_approved_day = $stMin->fetchColumn();
} catch (Throwable $e) {
    $min_approved_day = null;
}
$min_approved_day = (is_string($min_approved_day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $min_approved_day)) ? $min_approved_day : null;

if ($biz_kind === 'pg') {
    require_once __DIR__ . '/inc/pg_statement_compute.php';
} else {
    require_once __DIR__ . '/inc/game_platform_statement_compute.php';
}

/** @param array<string, scalar> $extra */
function balance_summary_stmt_url(string $df, string $dt, array $extra = []): string {
    $q = array_merge(['day_from' => $df, 'day_to' => $dt], $extra);
    $q = array_filter($q, static function ($v) {
        return $v !== null && $v !== '';
    });
    return 'balance_summary.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>statement - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <?php if ($head_office_stmt): ?>
    <style>
        .stmt-head-office-bar { margin-bottom: 18px; padding: 14px 16px; background: var(--card-bg, #fff); border-radius: 10px; border: 1px solid rgba(148, 163, 184, 0.25); }
        .stmt-company-pick-row { display: flex; align-items: flex-start; flex-wrap: wrap; gap: 12px 16px; }
        .stmt-company-pick-label { font-weight: 700; font-size: 14px; color: var(--text, #1e293b); padding-top: 8px; flex-shrink: 0; }
        .stmt-company-pills { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .stmt-pill {
            display: inline-flex; flex-direction: column; align-items: center; justify-content: center;
            min-width: 72px; padding: 10px 16px; border-radius: 999px; font-size: 14px; font-weight: 700;
            text-decoration: none; color: var(--text, #334155); background: #f1f5f9; border: 1px solid #cbd5e1;
            transition: background .15s, color .15s, border-color .15s;
            letter-spacing: 0.02em;
        }
        .stmt-pill small { display: block; font-size: 11px; font-weight: 500; color: var(--muted, #64748b); margin-top: 2px; max-width: 140px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stmt-pill.active small { color: rgba(255,255,255,0.9); }
        .stmt-pill:hover { background: #e2e8f0; }
        .stmt-pill.active { background: var(--primary, #2563eb); color: #fff; border-color: var(--primary, #2563eb); }
    </style>
    <?php endif; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 100%;">
                <div class="page-header">
                    <h2>statement</h2>
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= $err ?></div><?php endif; ?>

                <div class="card">
                    <?php if ($head_office_stmt): ?>
                    <div class="stmt-head-office-bar">
                        <div class="stmt-company-pick-row">
                            <span class="stmt-company-pick-label">公司</span>
                            <div class="stmt-company-pills" role="tablist" aria-label="选择分公司对账单">
                                <?php foreach ($company_rows as $cr):
                                    $cid = (int)$cr['id'];
                                    $ccode = trim((string)($cr['code'] ?? ''));
                                    $cname = trim((string)($cr['name'] ?? ''));
                                    $pill_title = $cname !== '' ? $ccode . ' — ' . $cname : $ccode;
                                    $is_active_pill = ($cid === $company_id);
                                ?>
                                <a class="stmt-pill<?= $is_active_pill ? ' active' : '' ?>"
                                   role="tab"
                                   aria-selected="<?= $is_active_pill ? 'true' : 'false' ?>"
                                   title="<?= htmlspecialchars($pill_title, ENT_QUOTES, 'UTF-8') ?>"
                                   href="<?= htmlspecialchars(balance_summary_stmt_url($day_from, $day_to, ['stmt_co' => $cid]), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($ccode !== '' ? $ccode : ('#' . $cid)) ?>
                                    <?php if ($cname !== ''): ?><small><?= htmlspecialchars($cname) ?></small><?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($company_rows === []): ?>
                        <p class="form-hint" style="margin:10px 0 0;">暂无启用的分公司，请到「分公司管理」新增或启用公司。</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <p class="form-hint" style="margin-bottom:12px;"><?= app_lang() === 'en' ? 'Showing date: ' : '显示日期：' ?><?= $day_from ?><?= $is_range ? (app_lang() === 'en' ? ' to ' : ' 至 ') . $day_to : '' ?><?= !$is_range && $day_from === date('Y-m-d') ? (app_lang() === 'en' ? ' (Today)' : '（当天）') : '' ?></p>
                    <div class="statement-filter-wrap" style="margin-bottom:16px;">
                        <button type="button" class="btn btn-outline" id="stmt-date-toggle"><?= app_lang() === 'en' ? 'Filter date' : '筛选日期' ?></button>
                        <form method="get" class="stmt-date-form" id="stmt-date-form" style="display:none; margin-top:10px; align-items:center; gap:10px; flex-wrap:wrap;">
                            <label style="font-size:13px;"><?= app_lang() === 'en' ? 'From' : '从' ?></label>
                            <input type="date" name="day_from" id="stmt-day-from" value="<?= htmlspecialchars($day_from) ?>">
                            <label style="font-size:13px;"><?= app_lang() === 'en' ? 'To' : '至' ?></label>
                            <input type="date" name="day_to" id="stmt-day-to" value="<?= htmlspecialchars($day_to) ?>">
                            <?php if ($head_office_stmt && $company_id > 0): ?>
                            <input type="hidden" name="stmt_co" value="<?= (int)$company_id ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary"><?= app_lang() === 'en' ? 'Search' : '查询' ?></button>
                            <div style="flex-basis:100%; height:0;"></div>
                            <span style="font-size:13px; color:var(--muted);"><?= app_lang() === 'en' ? 'Quick:' : '快捷：' ?></span>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="7"><?= app_lang() === 'en' ? '1 week' : '一个星期' ?></button>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="30"><?= app_lang() === 'en' ? '1 month' : '一个月' ?></button>
                            <?php if ($is_admin && $min_approved_day): ?>
                            <button type="button" class="btn btn-sm btn-outline" id="stmt-quick-all" data-from="<?= htmlspecialchars($min_approved_day, ENT_QUOTES, 'UTF-8') ?>"><?= app_lang() === 'en' ? 'All history' : '全历史' ?></button>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="total-table-wrap" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <div>
                            <h4><?= $biz_kind === 'pg' ? 'Channel' : 'Bank' ?></h4>
                            <table class="total-table">
                                <thead>
                                    <tr>
                                        <th><?= $biz_kind === 'pg' ? 'Channel' : 'Bank' ?></th>
                                        <?php if ($is_admin): ?><th class="num">Starting Balance</th><th class="num">In</th><th class="num">Out</th><?php endif; ?>
                                        <th class="num">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($biz_kind === 'pg'): ?>
                                    <?php foreach (($pg_all_channels ?? []) as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)(($pg_range_in_channel ?? [])[$key] ?? 0);
                                        $out = (float)(($pg_range_out_channel ?? [])[$key] ?? 0);
                                        $init = (float)(($pg_initial_channel ?? [])[$key] ?? 0);
                                        $balance = $init + $in - $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num in"><?= number_format($in, 2) ?></td>
                                        <td class="num out"><?= number_format($out, 2) ?></td>
                                        <?php endif; ?>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($pg_all_channels ?? [])): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <?php foreach ($all_banks as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)($range_in_bank[$key] ?? 0);
                                        $out = (float)($range_out_bank[$key] ?? 0);
                                        $init = $initial_bank[$name] ?? 0;
                                        $balance = $init + $in - $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num in"><?= number_format($in, 2) ?></td>
                                        <td class="num out"><?= number_format($out, 2) ?></td>
                                        <?php endif; ?>
                                        <td class="num profit"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($all_banks)): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h4><?= $biz_kind === 'pg' ? 'Customer' : 'Game Platform' ?></h4>
                            <table class="total-table">
                                <thead>
                                        <tr>
                                        <th><?= $biz_kind === 'pg' ? 'Customer' : 'Game Platform' ?></th>
                                        <?php if ($is_admin): ?>
                                            <th class="num">Starting</th>
                                            <th class="num">In</th>
                                            <?php if ($biz_kind === 'pg'): ?>
                                                <th class="num">Cash out</th>
                                            <?php else: ?>
                                                <th class="num">Topup</th>
                                                <th class="num">Out</th>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <th class="num">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($biz_kind === 'pg'): ?>
                                    <?php foreach (($pg_all_customers ?? []) as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)(($pg_range_in_customer ?? [])[$key] ?? 0);
                                        $out_total = (float)(($pg_range_out_customer ?? [])[$key] ?? 0);
                                        $cashout = (float)(($pg_range_cashout_customer ?? [])[$key] ?? 0);
                                        $init = (float)(($pg_initial_customer ?? [])[$key] ?? 0);
                                        // Balance 一定按“全部 out”口径计算；Cash out 只是 out 的一个子集展示
                                        $balance = $init + $in - $out_total;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num stmt-in"><?= $in != 0 ? number_format($in, 2) : '—' ?></td>
                                        <td class="num stmt-out"><?= $cashout != 0 ? number_format($cashout, 2) : '—' ?></td>
                                        <?php endif; ?>
                                        <td class="num <?= $balance < 0 ? 'stmt-negative' : 'profit' ?>"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($pg_all_customers ?? [])): ?>
                                    <tr><td colspan="<?= $is_admin ? 5 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <?php foreach ($all_products as $name):
                                        $name = trim((string)$name);
                                        if ($name === '') continue;
                                        $key = strtolower($name);
                                        $in = (float)($range_in_product[$key] ?? 0);
                                        $topup = (float)($range_topup_product[$key] ?? 0);
                                        $out = (float)($range_out_product[$key] ?? 0);
                                        $init = $initial_product[$name] ?? 0;
                                        $balance = $init - $in + $topup + $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="num"><?= number_format($init, 2) ?></td>
                                        <td class="num stmt-in"><?= $in != 0 ? '−' . number_format($in, 2) : '—' ?></td>
                                        <td class="num stmt-topup"><?= $topup != 0 ? number_format($topup, 2) : '—' ?></td>
                                        <td class="num stmt-out"><?= $out != 0 ? number_format($out, 2) : '—' ?></td>
                                        <?php endif; ?>
                                        <td class="num <?= $balance < 0 ? 'stmt-negative' : 'profit' ?>"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($all_products)): ?>
                                    <tr><td colspan="<?= $is_admin ? 6 : 2 ?>">暂无</td></tr>
                                    <?php endif; ?>
                                    <?php endif; ?>
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
    var btn = document.getElementById('stmt-date-toggle');
    var form = document.getElementById('stmt-date-form');
    if (btn && form) {
        btn.addEventListener('click', function(){
            form.style.display = form.style.display === 'none' ? 'flex' : 'none';
        });
    }
    var fromEl = document.getElementById('stmt-day-from');
    var toEl = document.getElementById('stmt-day-to');
    document.querySelectorAll('.stmt-quick-range').forEach(function(b){
        b.addEventListener('click', function(){
            var days = parseInt(b.getAttribute('data-days'), 10) || 7;
            var end = new Date();
            var start = new Date(end);
            start.setDate(start.getDate() - (days - 1));
            var fmt = function(d) {
                var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            };
            if (fromEl) fromEl.value = fmt(start);
            if (toEl) toEl.value = fmt(end);
            if (form) form.submit();
        });
    });
    var allBtn = document.getElementById('stmt-quick-all');
    if (allBtn) {
        allBtn.addEventListener('click', function(){
            var df = allBtn.getAttribute('data-from');
            var end = new Date();
            var fmt = function(d) {
                var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            };
            if (fromEl) fromEl.value = df;
            if (toEl) toEl.value = fmt(end);
            if (form) form.submit();
        });
    }
})();
</script>
</body>
</html>
