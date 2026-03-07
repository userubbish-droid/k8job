<?php
require 'config.php';
require 'auth.php';
require_permission('rebate');
$sidebar_current = 'rebate';

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
$err = '';

$day = $_REQUEST['day'] ?? date('Y-m-d');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d');

// 提交勾选的「已给了」或 取消单个（仅 admin）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $post_day = trim($_POST['day'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_day)) {
        try {
            if ($action === 'submit') {
                $given = isset($_POST['given']) && is_array($_POST['given']) ? $_POST['given'] : [];
                $given = array_filter(array_map('trim', $given));
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $pdo->prepare("INSERT INTO rebate_given (day, code, given_at, given_by) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE given_at = NOW(), given_by = VALUES(given_by)");
                foreach ($given as $code) {
                    if ($code !== '') $stmt->execute([$post_day, $code]);
                }
                $msg = count($given) ? '已标记所选客户为「已给」。' : '请勾选「已给了」再提交。';
            } elseif ($action === 'cancel' && $is_admin) {
                $code = trim($_POST['code'] ?? '');
                if ($code !== '') {
                    $stmt = $pdo->prepare("DELETE FROM rebate_given WHERE day = ? AND code = ?");
                    $stmt->execute([$post_day, $code]);
                    $msg = '已取消该客户「已给」状态。';
                }
            } elseif ($action === 'cancel' && !$is_admin) {
                $err = '仅管理员可取消。';
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'rebate_given') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
                $err = '请先在 phpMyAdmin 执行 migrate_rebate_given.sql 创建 rebate_given 表。';
            } else {
                $err = '操作失败：' . $e->getMessage();
            }
        }
    }
    $_SESSION['rebate_msg'] = $msg;
    $_SESSION['rebate_err'] = $err;
    header('Location: rebate.php?day=' . urlencode($post_day ?: $day));
    exit;
}

$msg = $_SESSION['rebate_msg'] ?? '';
$err = $_SESSION['rebate_err'] ?? '';
unset($_SESSION['rebate_msg'], $_SESSION['rebate_err']);

$given_codes = [];
try {
    $stmt = $pdo->prepare("SELECT code FROM rebate_given WHERE day = ?");
    $stmt->execute([$day]);
    $given_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// 当日按客户汇总：进、出、对扣(余额)。只统计已批准流水
$stmt = $pdo->prepare("
    SELECT code,
           COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
           COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
    FROM transactions
    WHERE day = ? AND status = 'approved' AND code IS NOT NULL AND TRIM(code) <> ''
    GROUP BY code
    ORDER BY code ASC
");
$stmt->execute([$day]);
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
    <title>返点 Rebate - 算账网</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <style>
    .rebate-table th.num, .rebate-table td.num { text-align: right; }
    .rebate-table input.percent { width: 72px; text-align: right; padding: 6px 8px; }
    .rebate-table .total-amount { font-weight: 600; color: var(--primary); }
    .rebate-total-row { font-weight: 700; background: var(--primary-soft); }
    .rebate-table tr.rebate-given-row { background: var(--success-soft); }
    .rebate-table tr.rebate-given-row td { color: #0f5132; }
    .rebate-given-label { font-size: 12px; color: var(--success); font-weight: 600; margin-left: 6px; }
    .rebate-code-cell { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .rebate-code-cell input[type=checkbox] { margin: 0; accent-color: var(--primary); }
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
            <form method="get" class="filters-bar" style="margin-bottom:16px;">
                <label>日期</label>
                <input type="date" name="day" value="<?= htmlspecialchars($day) ?>">
                <button type="submit" class="btn btn-primary">查询</button>
            </form>
            <p class="form-hint">按客户汇总当日进、出，对扣 = 进 − 出 = 余额；填写 % 后自动计算返点金额。勾选「已给了」并提交后，member 只看到还没给的；admin 端已给的显示绿色。</p>

            <form method="post" id="rebate-form">
                <input type="hidden" name="day" value="<?= htmlspecialchars($day) ?>">
                <input type="hidden" name="action" value="submit">
                <table class="data-table rebate-table" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th>客户代码</th>
                            <th class="num">今日进</th>
                            <th class="num">今日出</th>
                            <th class="num">对扣（余额）</th>
                            <th class="num">%</th>
                            <th class="num">返点金额 (Total Amount)</th>
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
                        ?>
                        <tr data-balance="<?= $balance ?>" data-row="<?= $i ?>" data-code="<?= htmlspecialchars($code) ?>" class="<?= $is_given ? 'rebate-given-row' : '' ?>">
                            <td class="rebate-code-cell">
                                <?php if ($is_given): ?>
                                    <span class="rebate-given-label">已给</span>
                                    <?= htmlspecialchars($code) ?>
                                <?php else: ?>
                                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;">
                                        <input type="checkbox" name="given[]" value="<?= htmlspecialchars($code) ?>">
                                        <span>已给了</span>
                                    </label>
                                    <?= htmlspecialchars($code) ?>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($in, 2) ?></td>
                            <td class="num"><?= number_format($out, 2) ?></td>
                            <td class="num"><?= number_format($balance, 2) ?></td>
                            <td class="num">
                                <?php if (!$is_given): ?>
                                <input type="text" class="form-control percent js-percent" name="pct_<?= $i ?>" placeholder="%" inputmode="decimal" data-row="<?= $i ?>">
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="num total-amount js-total" data-row="<?= $i ?>"><?= $is_given ? '—' : '—' ?></td>
                            <?php if ($is_admin && $is_given): ?>
                            <td class="num">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="day" value="<?= htmlspecialchars($day) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('确定取消该客户「已给」？');">取消</button>
                                </form>
                            </td>
                            <?php elseif ($is_admin): ?>
                            <td class="num">—</td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows_display)): ?>
                        <tr><td colspan="<?= $is_admin ? 7 : 6 ?>"><?= $day ?> <?= $is_admin ? '暂无流水或全部已给' : '暂无未给的客户（或当日无流水）' ?></td></tr>
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
                    <button type="submit" class="btn btn-primary">提交（确认已给出）</button>
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
            var balance = parseNum(tr.getAttribute('data-balance'));
            var totalCell = tr.querySelector('.js-total');
            var pctInput = tr.querySelector('.js-percent');
            if (!totalCell) return;
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
                if (totalCell && totalCell.textContent !== '—') sumRebate += parseNum(totalCell.textContent);
            });
            var foot = table.querySelector('tfoot');
            if (foot) {
                var fn = function(s, v){ var e = foot.querySelector(s); if (e) e.textContent = v.toFixed(2); };
                fn('.js-sum-in', sumIn);
                fn('.js-sum-out', sumOut);
                fn('.js-sum-balance', sumBalance);
                fn('.js-sum-rebate', sumRebate);
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
