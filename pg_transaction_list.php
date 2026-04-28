<?php
/**
 * PG 分公司流水（读 pg_transactions；列与 gaming 的 transaction_list 分离）
 */
require 'config.php';
require 'auth.php';
require_permission('transaction_list');
$sidebar_current = 'transaction_list';

if (function_exists('shard_refresh_business_pdo')) {
    shard_refresh_business_pdo();
}

$pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
$company_id = current_company_id();

$bk = '';
try {
    $stBk = $pdoCat->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $bk = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {
}
if ($bk !== 'pg') {
    header('Location: transaction_list.php');
    exit;
}

if (!function_exists('pdo_data_for_company_id')) {
    header('Location: transaction_list.php');
    exit;
}
$pdoData = pdo_data_for_company_id($pdoCat, $company_id);

$role = (string)($_SESSION['user_role'] ?? '');
$is_admin = in_array($role, ['admin', 'superadmin', 'boss'], true);
$can_member_time_filter = ($role === 'member') && has_permission('transaction_time_filter');

$day_from_raw = $_GET['day_from'] ?? date('Y-m-d');
$day_to_raw = $_GET['day_to'] ?? date('Y-m-d');
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$day_from_raw) ? substr((string)$day_from_raw, 0, 10) : date('Y-m-d');
$day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$day_to_raw) ? substr((string)$day_to_raw, 0, 10) : date('Y-m-d');
if (!$is_admin && !$can_member_time_filter) {
    $today = date('Y-m-d');
    $day_from = $today;
    $day_to = $today;
}
$status = trim((string)($_GET['status'] ?? 'approved'));
$code = trim((string)($_GET['code'] ?? ''));
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$params = [$company_id];
$where = ['company_id = ?'];

if ($status === '' || $status === 'approved') {
    $where[] = "status = 'approved'";
    $status = 'approved';
} elseif (in_array($status, ['pending', 'rejected'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
} elseif ($status === 'all') {
    // no filter
} else {
    $where[] = "status = 'approved'";
    $status = 'approved';
}

$where[] = 'txn_day >= ?';
$params[] = $day_from;
$where[] = 'txn_day <= ?';
$params[] = $day_to;

if ($code !== '') {
    $where[] = 'member_code = ?';
    $params[] = $code;
}

$sql_where = implode(' AND ', $where);

$count_stmt = $pdoData->prepare("SELECT COUNT(*) FROM pg_transactions WHERE $sql_where");
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 1;
$page = min($page, max(1, $total_pages));
$offset = ($page - 1) * $per_page;

$sum_stmt = $pdoData->prepare("SELECT
    COALESCE(SUM(CASE WHEN flow = 'in' AND status = 'approved' THEN amount ELSE 0 END), 0) AS total_in,
    COALESCE(SUM(CASE WHEN flow = 'out' AND status = 'approved' THEN amount ELSE 0 END), 0) AS total_out
    FROM pg_transactions WHERE $sql_where");
$sum_stmt->execute($params);
$sum = $sum_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_in' => 0, 'total_out' => 0];
$total_in = (float)($sum['total_in'] ?? 0);
$total_out = (float)($sum['total_out'] ?? 0);
$profit = $total_in - $total_out;

$list_sql = "SELECT id, txn_day, txn_time, flow, member_code, channel, amount, external_ref, remark, staff, status
    FROM pg_transactions WHERE $sql_where
    ORDER BY txn_day DESC, txn_time DESC, id DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$list_stmt = $pdoData->prepare($list_sql);
$list_stmt->execute($params);
$rows = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

$stCodes = $pdoData->prepare("SELECT DISTINCT member_code FROM pg_transactions WHERE company_id = ? AND member_code IS NOT NULL AND TRIM(member_code) <> '' ORDER BY member_code ASC");
$stCodes->execute([$company_id]);
$codes = $stCodes->fetchAll(PDO::FETCH_COLUMN);

$q = array_filter([
    'status' => $status,
    'day_from' => $day_from,
    'day_to' => $day_to,
    'code' => $code,
]);
$query_string = http_build_query($q);
$base_url = 'pg_transaction_list.php' . ($query_string ? '?' . $query_string . '&' : '?');

?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG <?= app_lang() === 'en' ? 'Transactions' : '流水' ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap">
            <div class="page-header">
                <h2>PG <?= app_lang() === 'en' ? 'Transactions' : '流水记录' ?></h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($is_admin || $can_member_time_filter): ?>
            <form class="filters-bar" method="get" style="margin-bottom:12px;">
                <div class="filters-row" style="flex-wrap:wrap; gap:10px; align-items:flex-end;">
                    <div class="filter-group">
                        <label><?= app_lang() === 'en' ? 'From' : '从' ?></label>
                        <input type="date" name="day_from" value="<?= htmlspecialchars($day_from, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="filter-group">
                        <label><?= app_lang() === 'en' ? 'To' : '到' ?></label>
                        <input type="date" name="day_to" value="<?= htmlspecialchars($day_to, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <?php if ($is_admin): ?>
                    <div class="filter-group">
                        <label><?= app_lang() === 'en' ? 'Status' : '状态' ?></label>
                        <select name="status">
                            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'Approved' : '已批准' ?></option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'Pending' : '待批准' ?></option>
                            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'Rejected' : '已拒绝' ?></option>
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'All' : '全部' ?></option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>客户</label>
                        <select name="code">
                            <option value="">全部</option>
                            <?php foreach ($codes as $c): ?>
                                <option value="<?= htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8') ?>" <?= $code === (string)$c ? 'selected' : '' ?>><?= htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <input type="hidden" name="page" value="1">
                    <button type="submit" class="btn btn-search"><?= app_lang() === 'en' ? 'Search' : '查询' ?></button>
                </div>
            </form>
            <?php endif; ?>

            <div class="summary" style="margin-bottom:14px;">
                <div class="summary-item"><strong>总入</strong><span class="num" style="color:var(--success);"><?= number_format($total_in, 2) ?></span></div>
                <div class="summary-item"><strong>总出</strong><span class="num" style="color:var(--danger);"><?= number_format($total_out, 2) ?></span></div>
                <div class="summary-item"><strong>利润</strong><span class="num"><?= number_format($profit, 2) ?></span></div>
            </div>

            <p class="form-hint">共 <?= $total_rows ?> 条，第 <?= $page ?>/<?= $total_pages ?> 页</p>

            <div class="card" style="padding:0;">
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>日期</th>
                            <th>时间</th>
                            <th>模式</th>
                            <th>客户</th>
                            <th>银行</th>
                            <th>金额</th>
                            <th>名字</th>
                            <th>员工</th>
                            <th>备注</th>
                            <?php if ($is_admin): ?><th>删除</th><th>操作</th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $flow = strtolower(trim((string)($r['flow'] ?? '')));
                            $modeLabel = $flow === 'out' ? '出款' : '入款';
                            $ext = trim((string)($r['external_ref'] ?? ''));
                            $rmk = trim((string)($r['remark'] ?? ''));
                            $nameCol = $ext !== '' ? $ext : ($rmk !== '' ? $rmk : '—');
                            if (mb_strlen($nameCol, 'UTF-8') > 40) {
                                $nameCol = mb_substr($nameCol, 0, 40, 'UTF-8') . '…';
                            }
                            $st = (string)($r['status'] ?? '');
                            $delCell = $st === 'rejected' ? '已拒绝' : ($st === 'pending' ? '待批' : '—');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($r['txn_day'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(substr((string)($r['txn_time'] ?? ''), 0, 8), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['member_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="num <?= $flow === 'out' ? 'value-out' : 'value-in' ?>"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars($nameCol, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['staff'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($rmk, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php if ($is_admin): ?>
                                    <td><?= htmlspecialchars($delCell, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($st === 'approved'): ?>
                                            <?php
                                            $ret = 'pg_transaction_list.php?' . $query_string . '&page=' . $page;
                                            ?>
                                            <a href="pg_transaction_void.php?id=<?= (int)$r['id'] ?>&return_to=<?= urlencode($ret) ?>" data-confirm="确定作废此条 PG 流水？（状态改为已拒绝）">作废</a>
                                        <?php else: ?>
                                            <span class="form-hint">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="<?= $is_admin ? 11 : 9 ?>">暂无流水</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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

            <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
        </div>
    </main>
</div>
</body>
</html>
