<?php
require 'config.php';
require 'auth.php';
require_permission('rebate');
$sidebar_current = 'rebate';

$company_id = current_company_id();
$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true);
$msg = '';
$err = '';

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$day_from_raw = isset($_REQUEST['day_from']) && trim((string)$_REQUEST['day_from']) !== '' ? $_REQUEST['day_from'] : $yesterday;
$day_to_raw   = isset($_REQUEST['day_to']) && trim((string)$_REQUEST['day_to']) !== '' ? $_REQUEST['day_to'] : $yesterday;
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_from_raw) ? substr($day_from_raw, 0, 10) : $today;
$day_to   = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_to_raw)   ? substr($day_to_raw, 0, 10)   : $today;
if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }
$day = $day_to; // 提交「已给」时写入 rebate_given 的 day（区间结束日）

// 提交勾选的「已给了」或 取消单个（仅 admin）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $post_day = trim($_POST['day'] ?? '');
    $post_day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', trim($_POST['day_from'] ?? '')) ? substr(trim($_POST['day_from']), 0, 10) : $day_from;
    $post_day_to   = preg_match('/^\d{4}-\d{2}-\d{2}/', trim($_POST['day_to'] ?? ''))   ? substr(trim($_POST['day_to']), 0, 10)   : $day_to;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_day)) {
        try {
            if ($action === 'submit') {
                $given = isset($_POST['given']) && is_array($_POST['given']) ? $_POST['given'] : [];
                $given = array_filter(array_map('trim', $given));
                $pct = isset($_POST['pct']) && is_array($_POST['pct']) ? $_POST['pct'] : [];
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if (!empty($given)) {
                    $placeholders = implode(',', array_fill(0, count($given), '?'));
                    $stmt_bal = $pdo->prepare("SELECT code, COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS balance FROM transactions WHERE company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL AND code IN ($placeholders) GROUP BY code");
                    $stmt_bal->execute(array_merge([$company_id, $post_day_from, $post_day_to], $given));
                    $balances = [];
                    while ($row = $stmt_bal->fetch(PDO::FETCH_ASSOC)) {
                        $balances[$row['code']] = (float)$row['balance'];
                    }
                    $stmt = $pdo->prepare("INSERT INTO rebate_given (company_id, day, code, given_at, given_by, rebate_pct, rebate_amount) VALUES (?, ?, ?, NOW(), ?, ?, ?) ON DUPLICATE KEY UPDATE given_at = NOW(), given_by = VALUES(given_by), rebate_pct = VALUES(rebate_pct), rebate_amount = VALUES(rebate_amount)");
                    foreach ($given as $code) {
                        if ($code === '') continue;
                        $balance = $balances[$code] ?? 0;
                        if ($balance < 0) {
                            continue; // Win/Lose 为负不必给，忽略误勾或篡改
                        }
                        $pct_val = isset($pct[$code]) ? (float)str_replace(',', '.', trim($pct[$code])) : 0;
                        $rebate_amount = $pct_val > 0 ? round($balance * $pct_val / 100, 2) : 0;
                        $stmt->execute([$company_id, $post_day_to, $code, $uid, $pct_val ?: null, $rebate_amount]);
                    }
                }
                $msg = count($given) ? '已标记所选客户为「已给」，返点金额已保存。' : '请勾选「已给了」再提交。';
            } elseif ($action === 'cancel' && $is_admin) {
                $code = trim($_POST['code'] ?? '');
                if ($code !== '') {
                    $stmt = $pdo->prepare("DELETE FROM rebate_given WHERE company_id = ? AND day >= ? AND day <= ? AND code = ?");
                    $stmt->execute([$company_id, $post_day_from, $post_day_to, $code]);
                    $msg = '已取消该客户「已给」状态。';
                }
            } elseif ($action === 'cancel' && !$is_admin) {
                $err = '仅管理员可取消。';
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'rebate_given') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
                $err = '请先在 phpMyAdmin 执行 migrate_rebate_given.sql 创建 rebate_given 表。';
            } elseif (strpos($e->getMessage(), 'Unknown column') !== false && strpos($e->getMessage(), 'rebate_pct') !== false) {
                $err = '请执行 migrate_rebate_given_columns.sql 为 rebate_given 表添加 rebate_pct、rebate_amount 列。';
            } else {
                $err = '操作失败：' . $e->getMessage();
            }
        }
    }
    $_SESSION['rebate_msg'] = $msg;
    $_SESSION['rebate_err'] = $err;
    header('Location: rebate.php?' . http_build_query(['day_from' => $post_day_from, 'day_to' => $post_day_to]));
    exit;
}

$msg = $_SESSION['rebate_msg'] ?? '';
$err = $_SESSION['rebate_err'] ?? '';
unset($_SESSION['rebate_msg'], $_SESSION['rebate_err']);

$given_codes = [];
$given_info = [];
try {
    $stmt = $pdo->prepare("SELECT code, rebate_pct, rebate_amount, day FROM rebate_given WHERE company_id = ? AND day >= ? AND day <= ? ORDER BY day DESC");
    $stmt->execute([$company_id, $day_from, $day_to]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $c = $row['code'];
        if (!in_array($c, $given_codes, true)) $given_codes[] = $c;
        if (!isset($given_info[$c])) $given_info[$c] = ['pct' => $row['rebate_pct'], 'amount' => $row['rebate_amount']];
    }
} catch (Throwable $e) {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT code FROM rebate_given WHERE company_id = ? AND day >= ? AND day <= ?");
        $stmt->execute([$company_id, $day_from, $day_to]);
        $given_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e2) {}
}

// 区间内按客户汇总：进、出、对扣(余额)。只统计已批准流水
$stmt = $pdo->prepare("
    SELECT code,
           COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
           COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
    FROM transactions
    WHERE company_id = ? AND day >= ? AND day <= ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) <> ''
    GROUP BY code
    ORDER BY code ASC
");
$stmt->execute([$company_id, $day_from, $day_to]);
$all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// member 只显示还没给的；admin 显示全部（先显示未给的，再显示已给的绿色）
$rows_not_given = [];
$rows_given = [];
foreach ($all_rows as $r) {
    $code = $r['code'] ?? '';
    if (in_array($code, $given_codes, true)) {
        $rows_given[] = $r;
    } else {
        $rows_not_given[] = $r;
    }
}
$rows_display = $is_admin ? array_merge($rows_not_given, $rows_given) : $rows_not_given;
$rows_for_sum = $all_rows; // 合计用全部客户
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>返点 Rebate - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
    .rebate-table th.num, .rebate-table td.num { text-align: right; }
    .rebate-table input.percent { width: 72px; text-align: right; padding: 6px 8px; }
    .rebate-table .total-amount { font-weight: 600; color: var(--primary); }
    .rebate-total-row { font-weight: 700; background: var(--primary-soft); }
    /* Win/Lose 为负且未「已给」：整行浅红底，与合计行整行浅蓝同理 */
    .rebate-table tr.rebate-no-rebate-row { font-weight: 600; background: var(--danger-soft); }
    .rebate-table tr.rebate-given-row { background: var(--success-soft); }
    .rebate-table tr.rebate-given-row td { color: #0f5132; }
    .rebate-given-label { font-size: 12px; color: var(--success); font-weight: 600; margin-left: 6px; }
    .rebate-code-cell { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .rebate-code-cell input[type=checkbox] { margin: 0; accent-color: var(--primary); }
    .rebate-code-cell input[type=checkbox]:disabled { cursor: not-allowed; opacity: 0.55; }
    .rebate-winlose-neg { color: var(--danger); font-weight: 700; font-variant-numeric: tabular-nums; }
    .rebate-na-muted { color: var(--muted); font-size: 13px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap">
        <div class="page-header">
            <h2>返点 Rebate</h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_create.php">记一笔</a></p>
        </div>
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <?php
                $this_week_start = date('Y-m-d', strtotime('monday this week'));
                $this_week_end = date('Y-m-d', strtotime('sunday this week'));
                $last_week_start = date('Y-m-d', strtotime('monday last week'));
                $last_week_end = date('Y-m-d', strtotime('sunday last week'));
                $this_month_start = date('Y-m-01');
                $this_month_end = date('Y-m-t');
                $last_month_start = date('Y-m-01', strtotime('first day of last month'));
                $last_month_end = date('Y-m-t', strtotime('last day of last month'));
            ?>
            <form class="filters-bar filters-bar-flow" method="get" id="rebate-filter-form" style="margin-bottom:16px;">
                <div class="filters-row filters-row-main">
                    <div class="filter-group">
                        <label>From:</label>
                        <input type="datetime-local" name="day_from" id="rebate-day-from" value="<?= htmlspecialchars($day_from) ?>T00:00" step="60">
                    </div>
                    <div class="filter-group">
                        <label>To:</label>
                        <input type="datetime-local" name="day_to" id="rebate-day-to" value="<?= htmlspecialchars($day_to) ?>T23:59" step="60">
                    </div>
                    <button type="submit" class="btn btn-search">Search</button>
                </div>
                <div class="filters-row filters-row-presets">
                    <a href="rebate.php?<?= http_build_query(['day_from' => $today, 'day_to' => $today]) ?>" class="btn btn-preset">Today</a>
                    <a href="rebate.php?<?= http_build_query(['day_from' => $yesterday, 'day_to' => $yesterday]) ?>" class="btn btn-preset">Yesterday</a>
                    <a href="rebate.php?<?= http_build_query(['day_from' => $this_week_start, 'day_to' => $this_week_end]) ?>" class="btn btn-preset">This Week</a>
                    <a href="rebate.php?<?= http_build_query(['day_from' => $last_week_start, 'day_to' => $last_week_end]) ?>" class="btn btn-preset">Last Week</a>
                    <a href="rebate.php?<?= http_build_query(['day_from' => $this_month_start, 'day_to' => $this_month_end]) ?>" class="btn btn-preset">This Month</a>
                    <a href="rebate.php?<?= http_build_query(['day_from' => $last_month_start, 'day_to' => $last_month_end]) ?>" class="btn btn-preset">Last Month</a>
                </div>
            </form>
            <form method="post" id="rebate-form">
                <input type="hidden" name="day" value="<?= htmlspecialchars($day_to) ?>">
                <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                <input type="hidden" name="action" value="submit">
                <table class="data-table rebate-table" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th>customer</th>
                            <th class="num">deposit</th>
                            <th class="num">withdraw</th>
                            <th class="num">winlose</th>
                            <th class="num">%</th>
                            <th class="num">rebate</th>
                            <?php if ($is_admin): ?><th class="num">操作</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows_display as $i => $r):
                            $code = $r['code'] ?? '';
                            $in = (float)($r['total_in'] ?? 0);
                            $out = (float)($r['total_out'] ?? 0);
                            $balance = $in - $out;
                            $is_given = in_array($code, $given_codes, true);
                            $no_rebate = $balance < 0;
                        ?>
                        <tr data-balance="<?= htmlspecialchars((string)$balance) ?>" data-row="<?= $i ?>" data-code="<?= htmlspecialchars($code) ?>" data-no-rebate="<?= $no_rebate ? '1' : '0' ?>" class="<?= $is_given ? 'rebate-given-row' : '' ?><?= ($no_rebate && !$is_given) ? ' rebate-no-rebate-row' : '' ?>">
                            <td class="rebate-code-cell">
                                <?php if ($is_given): ?>
                                    <?= htmlspecialchars($code) ?>
                                <?php elseif ($no_rebate): ?>
                                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:not-allowed;opacity:.9;">
                                        <input type="checkbox" name="given[]" value="<?= htmlspecialchars($code) ?>" disabled title="Win/Lose 为负，不必给返点">
                                        <?= htmlspecialchars($code) ?>
                                    </label>
                                    <span class="rebate-na-muted">不必给</span>
                                <?php else: ?>
                                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                                        <input type="checkbox" name="given[]" value="<?= htmlspecialchars($code) ?>">
                                        <?= htmlspecialchars($code) ?>
                                    </label>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($in, 2) ?></td>
                            <td class="num"><?= number_format($out, 2) ?></td>
                            <td class="num <?= $no_rebate ? 'rebate-winlose-neg' : '' ?>"><?= number_format($balance, 2) ?></td>
                            <td class="num">
                                <?php if (!$is_given && $no_rebate): ?>
                                <span class="rebate-na-muted">—</span>
                                <?php elseif (!$is_given): ?>
                                <input type="text" class="form-control percent js-percent" name="pct[<?= htmlspecialchars($code) ?>]" placeholder="%" inputmode="decimal" data-row="<?= $i ?>">
                                <?php else:
                                    $info = $given_info[$code] ?? [];
                                    $pct_val = isset($info['pct']) && $info['pct'] !== null && $info['pct'] !== '' ? (float)$info['pct'] : null;
                                    $pct_display = $pct_val !== null ? number_format($pct_val, 2) . '%' : '—';
                                ?>
                                <?= $pct_display ?>
                                <?php endif; ?>
                            </td>
                            <td class="num total-amount js-total" data-row="<?= $i ?>">
                                <?php
                                if ($is_given) {
                                    if ($no_rebate) {
                                        echo '<span class="rebate-na-muted">不必给</span>';
                                    } else {
                                        $info = $given_info[$code] ?? [];
                                        $amt_val = null;
                                        if (isset($info['amount']) && $info['amount'] !== null && $info['amount'] !== '') {
                                            $amt_val = (float)$info['amount'];
                                        }
                                        if ($amt_val === null && isset($info['pct']) && $info['pct'] !== null && $info['pct'] !== '') {
                                            $amt_val = round($balance * (float)$info['pct'] / 100, 2);
                                        }
                                        echo $amt_val !== null ? number_format($amt_val, 2) : '—';
                                    }
                                } elseif ($no_rebate) {
                                    echo '<span class="rebate-na-muted">不必给</span>';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <?php if ($is_admin && $is_given): ?>
                            <td class="num">
                                <form method="post" style="display:inline;" data-confirm="确定取消该客户「已给」？">
                                    <input type="hidden" name="day" value="<?= htmlspecialchars($day_to) ?>">
                                    <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                    <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline">取消</button>
                                </form>
                            </td>
                            <?php elseif ($is_admin): ?>
                            <td class="num">—</td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows_display)): ?>
                        <tr><td colspan="<?= $is_admin ? 7 : 6 ?>"><?= $day_from === $day_to ? $day_from : $day_from . ' ~ ' . $day_to ?> <?= $is_admin ? '暂无流水或全部已给' : '暂无未给的客户（或区间内无流水）' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($rows_for_sum) && $is_admin): ?>
                    <tfoot>
                        <tr class="rebate-total-row">
                            <td>合计</td>
                            <td class="num js-sum-in">0.00</td>
                            <td class="num js-sum-out">0.00</td>
                            <td class="num js-sum-balance">0.00</td>
                            <td class="num">—</td>
                            <td class="num js-sum-rebate">0.00</td>
                            <?php if ($is_admin): ?><td class="num">—</td><?php endif; ?>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>

                <?php if (!empty($rows_not_given)): ?>
                <div class="rebate-actions" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border);">
                    <button type="submit" class="btn btn-primary">提交</button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <p class="breadcrumb" style="margin-top:20px;">
            <a href="transaction_create.php">记一笔</a><span>·</span>
            <a href="dashboard.php">返回首页</a>
        </p>
    </div>
        </main>
    </div>
    <script>
    (function(){
        var table = document.querySelector('.rebate-table');
        if (!table) return;
        function parseNum(v) { var n = parseFloat(String(v).replace(/[,%\s]/g, '')); return isNaN(n) ? 0 : n; }
        function updateRow(tr) {
            var totalCell = tr.querySelector('.js-total');
            if (!totalCell) return;
            if (tr.getAttribute('data-no-rebate') === '1') {
                totalCell.innerHTML = '<span class="rebate-na-muted">不必给</span>';
                return;
            }
            var balance = parseNum(tr.getAttribute('data-balance'));
            var pctInput = tr.querySelector('.js-percent');
            if (!pctInput) { totalCell.textContent = '—'; return; }
            var pct = parseNum(pctInput.value);
            var amount = pct === 0 ? 0 : (balance * pct / 100);
            totalCell.textContent = amount === 0 ? '—' : amount.toFixed(2);
        }
        function updateSums() {
            var sumIn = 0, sumOut = 0, sumBalance = 0, sumRebate = 0;
            table.querySelectorAll('tbody tr[data-balance]').forEach(function(tr){
                var cells = tr.querySelectorAll('td');
                if (cells.length < 5) return;
                sumIn += parseNum(cells[1].textContent);
                sumOut += parseNum(cells[2].textContent);
                sumBalance += parseNum(cells[3].textContent);
                var totalCell = tr.querySelector('.js-total');
                if (totalCell) {
                    var t = (totalCell.textContent || '').trim();
                    if (t !== '—' && t.indexOf('不必给') === -1) sumRebate += parseNum(totalCell.textContent);
                }
            });
            var foot = table.querySelector('tfoot');
            if (foot) {
                var fn = function(s, v){ var e = foot.querySelector(s); if (e) e.textContent = v.toFixed(2); };
                fn('.js-sum-in', sumIn);
                fn('.js-sum-out', sumOut);
                fn('.js-sum-balance', sumBalance);
                fn('.js-sum-rebate', sumRebate);
                var balFoot = foot.querySelector('.js-sum-balance');
                if (balFoot) balFoot.classList.toggle('rebate-winlose-neg', sumBalance < 0);
            }
        }
        table.querySelectorAll('.js-percent').forEach(function(inp){
            inp.addEventListener('input', function(){
                var tr = inp.closest('tr');
                if (tr) { updateRow(tr); updateSums(); }
            });
        });
        table.querySelectorAll('tbody tr[data-balance]').forEach(updateRow);
        updateSums();
    })();
    </script>
</body>
</html>
