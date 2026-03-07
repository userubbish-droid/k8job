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

// 提交（确认已给出）或 取消（仅 admin）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $post_day = trim($_POST['day'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_day)) {
        try {
            if ($action === 'submit') {
                $stmt = $pdo->prepare("INSERT INTO rebate_submit (day, submitted_at, submitted_by) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE submitted_at = NOW(), submitted_by = VALUES(submitted_by)");
                $stmt->execute([$post_day, (int)($_SESSION['user_id'] ?? 0)]);
                $msg = '已确认该日返点已给出，不可自行取消。';
            } elseif ($action === 'cancel' && $is_admin) {
                $stmt = $pdo->prepare("DELETE FROM rebate_submit WHERE day = ?");
                $stmt->execute([$post_day]);
                $msg = '已取消「已给出」状态。';
            } elseif ($action === 'cancel' && !$is_admin) {
                $err = '仅管理员可取消。';
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'rebate_submit') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
                $err = '请先在 phpMyAdmin 执行 migrate_rebate_submit.sql 创建 rebate_submit 表。';
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

$submitted = false;
try {
    $stmt = $pdo->prepare("SELECT 1 FROM rebate_submit WHERE day = ? LIMIT 1");
    $stmt->execute([$day]);
    $submitted = (bool) $stmt->fetch();
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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            <p class="form-hint">按客户汇总当日进、出，对扣 = 进 − 出 = 余额；填写 % 后自动计算返点金额（余额 × %）。</p>

            <table class="data-table rebate-table" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>客户代码</th>
                        <th class="num">今日进</th>
                        <th class="num">今日出</th>
                        <th class="num">对扣（余额）</th>
                        <th class="num">%</th>
                        <th class="num">返点金额 (Total Amount)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r):
                        $code = $r['code'] ?? '';
                        $in = (float)($r['total_in'] ?? 0);
                        $out = (float)($r['total_out'] ?? 0);
                        $balance = $in - $out;
                    ?>
                    <tr data-balance="<?= $balance ?>" data-row="<?= $i ?>">
                        <td><?= htmlspecialchars($code) ?></td>
                        <td class="num"><?= number_format($in, 2) ?></td>
                        <td class="num"><?= number_format($out, 2) ?></td>
                        <td class="num"><?= number_format($balance, 2) ?></td>
                        <td class="num">
                            <input type="text" class="form-control percent js-percent" name="pct_<?= $i ?>" placeholder="%" inputmode="decimal" data-row="<?= $i ?>">
                        </td>
                        <td class="num total-amount js-total" data-row="<?= $i ?>">—</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="6"><?= $day ?> 暂无已批准流水</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($rows) && $is_admin): ?>
                <tfoot>
                    <tr class="rebate-total-row">
                        <td>合计</td>
                        <td class="num js-sum-in">0.00</td>
                        <td class="num js-sum-out">0.00</td>
                        <td class="num js-sum-balance">0.00</td>
                        <td class="num">—</td>
                        <td class="num js-sum-rebate">0.00</td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>

            <div class="rebate-actions" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border);">
                <?php if ($submitted): ?>
                    <span class="rebate-status" style="color:var(--success); font-weight:600;">已给出</span>
                    <?php if ($is_admin): ?>
                    <form method="post" style="display:inline-block; margin-left:12px;">
                        <input type="hidden" name="day" value="<?= htmlspecialchars($day) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-outline" onclick="return confirm('确定取消「已给出」？仅管理员可操作。');">取消</button>
                    </form>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="post" style="display:inline-block;">
                        <input type="hidden" name="day" value="<?= htmlspecialchars($day) ?>">
                        <input type="hidden" name="action" value="submit">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('确认已给出后不能自行取消，确定提交？');">提交（确认已给出）</button>
                    </form>
                <?php endif; ?>
            </div>
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
            var row = tr.getAttribute('data-row');
            var pctInput = tr.querySelector('.js-percent');
            var totalCell = tr.querySelector('.js-total');
            if (!pctInput || !totalCell) return;
            var pct = parseNum(pctInput.value);
            var amount = pct === 0 ? 0 : (balance * pct / 100);
            totalCell.textContent = amount === 0 ? '—' : amount.toFixed(2);
        }
        function updateSums() {
            var sumIn = 0, sumOut = 0, sumBalance = 0, sumRebate = 0;
            table.querySelectorAll('tbody tr[data-balance]').forEach(function(tr){
                var cells = tr.querySelectorAll('td');
                if (cells.length < 6) return;
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
