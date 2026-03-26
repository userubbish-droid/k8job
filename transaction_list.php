<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
$sidebar_current = 'transaction_list';

$day_from_raw = $_GET['day_from'] ?? date('Y-m-d');
$day_to_raw   = $_GET['day_to'] ?? date('Y-m-d');
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_from_raw) ? substr($day_from_raw, 0, 10) : date('Y-m-d');
$day_to   = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_to_raw)   ? substr($day_to_raw, 0, 10)   : date('Y-m-d');
$mode     = $_GET['mode'] ?? '';
$code     = trim($_GET['code'] ?? '');
$bank     = trim($_GET['bank'] ?? '');
$product  = trim($_GET['product'] ?? '');
$status   = trim($_GET['status'] ?? '');
$export   = ($_GET['export'] ?? '') === 'csv';
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$params = [];
$where  = ['1=1'];

// 软删除：隐藏已删除流水（保留 2 个月后物理删除）
try {
    $pdo->query("SELECT deleted_at FROM transactions LIMIT 0");
    $where[] = "deleted_at IS NULL";
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'deleted_at') === false) throw $e;
}

// 审批过滤：默认只看已批准
if ($is_admin) {
    if ($status === '' || $status === 'approved') {
        $where[] = "status = 'approved'";
    } elseif (in_array($status, ['pending', 'rejected'], true)) {
        $where[] = "status = '$status'";
    } elseif ($status === 'all') {
        // 不加过滤
    } else {
        $where[] = "status = 'approved'";
        $status = 'approved';
    }
} else {
    if ($status === 'pending') {
        $where[] = "status = 'pending'";
        $where[] = "created_by = ?";
        $params[] = (int)($_SESSION['user_id'] ?? 0);
    } else {
        $where[] = "status = 'approved'";
        $status = 'approved';
    }
    try {
        $pdo->query("SELECT hide_from_member FROM transactions LIMIT 0");
        $where[] = "COALESCE(hide_from_member, 0) = 0";
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'hide_from_member') === false) throw $e;
    }
}

if ($day_from !== '') {
    $where[] = 'day >= ?';
    $params[] = $day_from;
}
if ($day_to !== '') {
    $where[] = 'day <= ?';
    $params[] = $day_to;
}
if ($mode !== '') {
    $where[] = 'mode = ?';
    $params[] = $mode;
}
if ($code !== '') {
    $where[] = 'code = ?';
    $params[] = $code;
}
if ($bank !== '') {
    $where[] = 'bank = ?';
    $params[] = $bank;
}
if ($product !== '') {
    $where[] = 'product = ?';
    $params[] = $product;
}

$sql_where = implode(' AND ', $where);

// 导出 CSV：按当前筛选导出全部匹配记录（不分页）
if ($export) {
    $export_sql = "SELECT id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark
                   FROM transactions WHERE $sql_where ORDER BY day DESC, time DESC";
    $export_stmt = $pdo->prepare($export_sql);
    $export_stmt->execute($params);
    $export_rows = $export_stmt->fetchAll();
    $filename = 'transactions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['day','time','mode','code','bank','product','amount','bonus','total','staff','remark']);
    foreach ($export_rows as $r) {
        fputcsv($out, [$r['day'],$r['time'],$r['mode'],$r['code'],$r['bank'],$r['product'],$r['amount'],$r['bonus'],$r['total'],$r['staff'],$r['remark']]);
    }
    fclose($out);
    exit;
}

// 总数（分页用）
$count_sql = "SELECT COUNT(*) FROM transactions WHERE $sql_where";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_rows = (int) $count_stmt->fetchColumn();
$total_pages = $total_rows > 0 ? (int) ceil($total_rows / $per_page) : 1;
$page = min($page, max(1, $total_pages));
$offset = ($page - 1) * $per_page;

$sql = "SELECT id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark
        FROM transactions
        WHERE $sql_where
        ORDER BY day DESC, time DESC
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// 用于下拉筛选（从已有流水中取 distinct，简单可靠）
$distinct_base = "FROM transactions WHERE ";
try {
    $pdo->query("SELECT deleted_at FROM transactions LIMIT 0");
    $distinct_base .= "deleted_at IS NULL AND ";
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'deleted_at') === false) throw $e;
}
$banks = $pdo->query("SELECT DISTINCT bank $distinct_base bank IS NOT NULL AND bank <> '' ORDER BY bank ASC")->fetchAll(PDO::FETCH_COLUMN);
$products = $pdo->query("SELECT DISTINCT product $distinct_base product IS NOT NULL AND product <> '' ORDER BY product ASC")->fetchAll(PDO::FETCH_COLUMN);
$codes = $pdo->query("SELECT DISTINCT code $distinct_base code IS NOT NULL AND code <> '' ORDER BY code ASC")->fetchAll(PDO::FETCH_COLUMN);

// 当前筛选下的总入、总出、利润
$sum_sql = "SELECT
    COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
    COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
FROM transactions WHERE $sql_where";
$sum_stmt = $pdo->prepare($sum_sql);
$sum_stmt->execute($params);
$sum = $sum_stmt->fetch();
$total_in  = $sum['total_in'];
$total_out = $sum['total_out'];
$profit    = $total_in - $total_out;

// 分页链接保留当前筛选参数
$q = array_filter([
    'status'   => $status,
    'day_from' => $day_from,
    'day_to'   => $day_to,
    'mode'     => $mode,
    'code'     => $code,
    'bank'     => $bank,
    'product'  => $product,
]);
$query_string = http_build_query($q);
$base_url = 'transaction_list.php' . ($query_string ? '?' . $query_string . '&' : '?');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流水记录 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap">
        <div class="page-header">
            <h2>流水记录</h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_create.php">记一笔</a></p>
        </div>

    <?php if ($is_admin):
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this_week_start = date('Y-m-d', strtotime('monday this week'));
        $this_week_end = date('Y-m-d', strtotime('sunday this week'));
        $last_week_start = date('Y-m-d', strtotime('monday last week'));
        $last_week_end = date('Y-m-d', strtotime('sunday last week'));
        $this_month_start = date('Y-m-01');
        $this_month_end = date('Y-m-t');
        $last_month_start = date('Y-m-01', strtotime('first day of last month'));
        $last_month_end = date('Y-m-t', strtotime('last day of last month'));
        $base_q = array_filter(['status' => $status, 'mode' => $mode, 'code' => $code, 'bank' => $bank, 'product' => $product]);
    ?>
    <div class="list-advanced-toggle-wrap" style="margin-bottom:12px;">
        <button type="button" class="btn btn-outline" id="list-advanced-toggle" aria-expanded="true">收起筛选与汇总</button>
    </div>
    <div class="list-advanced-wrap" id="list-advanced-wrap">
    <form class="filters-bar filters-bar-flow" method="get" id="flow-filter-form">
        <div class="filters-row filters-row-main">
            <div class="filter-group">
                <label>From:</label>
                <input type="datetime-local" name="day_from" id="flow-day-from" value="<?= htmlspecialchars($day_from) ?>T00:00" step="60">
            </div>
            <div class="filter-group">
                <label>To:</label>
                <input type="datetime-local" name="day_to" id="flow-day-to" value="<?= htmlspecialchars($day_to) ?>T23:59" step="60">
            </div>
            <div class="filter-group">
                <label>All Currency</label>
                <select aria-label="Currency" title="保留项">
                    <option>All Currency</option>
                </select>
            </div>
            <div class="filter-group">
                <label>All Game</label>
                <select name="product">
                    <option value="">All Game</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $product === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>All Status</label>
                <select name="status">
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>已批准</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>待批准</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>已拒绝</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>全部</option>
                </select>
            </div>
            <input type="hidden" name="page" value="1">
            <button type="submit" class="btn btn-search">Search</button>
            <a href="dashboard.php" class="btn btn-back">Back</a>
        </div>
        <div class="filters-row filters-row-presets">
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $today, 'day_to' => $today])) ?>" class="btn btn-preset">Today</a>
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $yesterday, 'day_to' => $yesterday])) ?>" class="btn btn-preset">Yesterday</a>
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $this_week_start, 'day_to' => $this_week_end])) ?>" class="btn btn-preset">This Week</a>
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $last_week_start, 'day_to' => $last_week_end])) ?>" class="btn btn-preset">Last Week</a>
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $this_month_start, 'day_to' => $this_month_end])) ?>" class="btn btn-preset">This Month</a>
            <a href="transaction_list.php?<?= http_build_query(array_merge($base_q, ['day_from' => $last_month_start, 'day_to' => $last_month_end])) ?>" class="btn btn-preset">Last Month</a>
            <a href="transaction_list.php?<?= $query_string ? htmlspecialchars($query_string) . '&' : '' ?>export=csv" class="btn btn-preset">导出</a>
        </div>
        <div class="filters-row filters-row-more collapsed" id="flow-more-wrap">
            <div class="filter-group"><label>模式</label>
                <select name="mode">
                    <option value="">全部</option>
                    <option value="DEPOSIT" <?= $mode === 'DEPOSIT' ? 'selected' : '' ?>>DEPOSIT</option>
                    <option value="WITHDRAW" <?= $mode === 'WITHDRAW' ? 'selected' : '' ?>>WITHDRAW</option>
                    <option value="FREE" <?= $mode === 'FREE' ? 'selected' : '' ?>>FREE</option>
                    <option value="FREE WITHDRAW" <?= $mode === 'FREE WITHDRAW' ? 'selected' : '' ?>>FREE WITHDRAW</option>
                    <option value="BANK" <?= $mode === 'BANK' ? 'selected' : '' ?>>BANK</option>
                    <option value="REBATE" <?= $mode === 'REBATE' ? 'selected' : '' ?>>REBATE</option>
                </select>
            </div>
            <div class="filter-group"><label>代码</label>
                <select name="code">
                    <option value="">全部</option>
                    <?php foreach ($codes as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $code === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group"><label>银行</label>
                <select name="bank">
                    <option value="">全部</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>" <?= $bank === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="button" class="btn btn-outline btn-more-toggle" id="flow-more-toggle" style="margin-top:8px;">更多筛选</button>
    </form>

    <div class="summary">
        <div class="summary-item"><strong>总入</strong><span class="num" style="color:var(--success);"><?= number_format($total_in, 2) ?></span></div>
        <div class="summary-item"><strong>总出</strong><span class="num" style="color:var(--danger);"><?= number_format($total_out, 2) ?></span></div>
        <div class="summary-item"><strong>利润</strong><span class="num"><?= number_format($profit, 2) ?></span></div>
    </div>
    </div>
    <?php endif; ?>

    <p class="form-hint">共 <?= $total_rows ?> 条，第 <?= $page ?>/<?= $total_pages ?> 页</p>

    <div class="card" style="overflow-x:auto; padding:0;">
    <table class="data-table">
        <thead>
            <tr>
                <th>日期</th>
                <th>时间</th>
                <th>模式</th>
                <th>代码</th>
                <th>银行</th>
                <th>产品</th>
                <th>金额</th>
                <th>奖励</th>
                <th>合计</th>
                <th>员工</th>
                <th>备注</th>
                <?php if ($is_admin): ?><th>操作</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $rmk = trim($r['remark'] ?? '');
                $display_mode = $r['mode'];
                if ($rmk === '产品加额') $display_mode = 'topup';
                elseif ($rmk !== '' && (strpos($rmk, '转至 ') === 0 || strpos($rmk, '来自 ') === 0)) $display_mode = 'contra';
            ?>
            <tr>
                <td><?= htmlspecialchars($r['day']) ?></td>
                <td><?= htmlspecialchars($r['time']) ?></td>
                <td><?= htmlspecialchars($display_mode) ?></td>
                <td><?= htmlspecialchars($r['code'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['bank'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['product'] ?? '') ?></td>
                <td class="num <?= ($r['mode'] === 'DEPOSIT' || $r['mode'] === 'TOPUP') ? 'value-in' : 'value-out' ?>"><?= number_format((float)$r['amount'], 2) ?></td>
                <td><?= number_format((float)($r['bonus'] ?? 0), 2) ?></td>
                <td><?= number_format((float)($r['total'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars($r['staff'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                <?php if ($is_admin): ?>
                <td>
                    <?php $edit_return = 'transaction_list.php?' . ($query_string ? $query_string . '&' : '') . 'page=' . $page; ?>
                    <a href="transaction_edit.php?id=<?= (int)$r['id'] ?>&return_to=<?= urlencode($edit_return) ?>">编辑</a>
                    <a href="transaction_delete.php?id=<?= (int)$r['id'] ?>&<?= $query_string ?>&page=<?= $page ?>" data-confirm="确定删除这条流水？">删除</a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="<?= $is_admin ? 12 : 11 ?>">暂无流水</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination" style="margin-top:16px;">
        <?php if ($page > 1): ?>
            <a href="<?= $base_url ?>page=<?= $page - 1 ?>">上一页</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="<?= $base_url ?>page=<?= $page + 1 ?>">下一页</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p class="breadcrumb" style="margin-top:20px;">
        <a href="transaction_create.php">记一笔</a><span>·</span>
        <a href="dashboard.php">返回首页</a><span>·</span>
        <a href="logout.php">退出</a>
    </p>
    </div>
        </main>
    </div>
<?php if ($is_admin): ?>
<script>
(function(){
    var btn = document.getElementById('list-advanced-toggle');
    var wrap = document.getElementById('list-advanced-wrap');
    if (btn && wrap) {
        function updateBtn() {
            var open = !wrap.classList.contains('collapsed');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.textContent = open ? '收起筛选与汇总' : '展开筛选与汇总';
        }
        btn.addEventListener('click', function(){ wrap.classList.toggle('collapsed'); updateBtn(); });
        updateBtn();
    }
    var moreBtn = document.getElementById('flow-more-toggle');
    var moreWrap = document.getElementById('flow-more-wrap');
    if (moreBtn && moreWrap) {
        moreBtn.addEventListener('click', function(){
            moreWrap.classList.toggle('collapsed');
            moreBtn.textContent = moreWrap.classList.contains('collapsed') ? '更多筛选' : '收起更多';
        });
    }
    var form = document.getElementById('flow-filter-form');
    if (form) {
        form.addEventListener('submit', function(){
            var from = document.getElementById('flow-day-from');
            var to = document.getElementById('flow-day-to');
            if (from && from.value) from.value = from.value.slice(0, 10) + 'T00:00';
            if (to && to.value) to.value = to.value.slice(0, 10) + 'T23:59';
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
