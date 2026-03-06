<?php
require 'config.php';
require 'auth.php';
require_login();

$day_from = $_GET['day_from'] ?? date('Y-m-01');
$day_to   = $_GET['day_to'] ?? date('Y-m-d');
$mode     = $_GET['mode'] ?? '';

$params = [];
$where  = ['1=1'];

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

$sql_where = implode(' AND ', $where);
$sql = "SELECT id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark
        FROM transactions
        WHERE $sql_where
        ORDER BY day DESC, time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流水列表 - 算账网</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        h2 { margin-bottom: 12px; }
        .filters { background: #f5f5f5; padding: 12px; margin-bottom: 16px; border-radius: 4px; }
        .filters label { margin-right: 8px; }
        .filters input, .filters select { padding: 6px; margin-right: 12px; }
        .filters button { padding: 6px 14px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .summary { margin: 12px 0; font-weight: bold; }
        .summary span { margin-right: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #eee; }
        tr:nth-child(even) { background: #f9f9f9; }
        .in { color: #28a745; }
        .out { color: #dc3545; }
        a { color: #007bff; }
        @media (max-width: 768px) { table { font-size: 12px; } th, td { padding: 4px; } }
    </style>
</head>
<body>
    <h2>流水列表</h2>

    <form class="filters" method="get">
        <label>从</label>
        <input type="date" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
        <label>到</label>
        <input type="date" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
        <label>模式</label>
        <select name="mode">
            <option value="">全部</option>
            <option value="DEPOSIT" <?= $mode === 'DEPOSIT' ? 'selected' : '' ?>>DEPOSIT</option>
            <option value="WITHDRAW" <?= $mode === 'WITHDRAW' ? 'selected' : '' ?>>WITHDRAW</option>
            <option value="BANK" <?= $mode === 'BANK' ? 'selected' : '' ?>>BANK</option>
            <option value="REBATE" <?= $mode === 'REBATE' ? 'selected' : '' ?>>REBATE</option>
        </select>
        <button type="submit">筛选</button>
    </form>

    <div class="summary">
        <span>总入：<span class="in"><?= number_format($total_in, 2) ?></span></span>
        <span>总出：<span class="out"><?= number_format($total_out, 2) ?></span></span>
        <span>利润：<?= number_format($profit, 2) ?></span>
    </div>

    <table>
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
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['day']) ?></td>
                <td><?= htmlspecialchars($r['time']) ?></td>
                <td><?= htmlspecialchars($r['mode']) ?></td>
                <td><?= htmlspecialchars($r['code'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['bank'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['product'] ?? '') ?></td>
                <td class="<?= $r['mode'] === 'DEPOSIT' ? 'in' : 'out' ?>"><?= number_format((float)$r['amount'], 2) ?></td>
                <td><?= number_format((float)($r['bonus'] ?? 0), 2) ?></td>
                <td><?= number_format((float)($r['total'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars($r['staff'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="11">暂无流水</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p style="margin-top: 20px;">
        <a href="transaction_create.php">记一笔</a> |
        <a href="dashboard.php">返回首页</a> |
        <a href="logout.php">退出</a>
    </p>
</body>
</html>
