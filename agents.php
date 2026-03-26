<?php
require 'config.php';
require 'auth.php';
require_permission('agent');
$sidebar_current = 'agents';

$err = '';
$msg = '';
$warn = '';
$agents = [];
$is_agent_user = ($_SESSION['user_role'] ?? '') === 'agent';
$agent_code = $is_agent_user ? trim((string)($_SESSION['agent_code'] ?? '')) : '';
$agent_rebate_settings_map = [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$day_from_raw = isset($_REQUEST['day_from']) && trim((string)$_REQUEST['day_from']) !== '' ? $_REQUEST['day_from'] : $yesterday;
$day_to_raw = isset($_REQUEST['day_to']) && trim((string)$_REQUEST['day_to']) !== '' ? $_REQUEST['day_to'] : $yesterday;
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_from_raw) ? substr($day_from_raw, 0, 10) : $today;
$day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_to_raw) ? substr($day_to_raw, 0, 10) : $today;
if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }

function ensure_agent_rebate_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_rebate_settings (
        agent_code VARCHAR(80) NOT NULL PRIMARY KEY,
        rebate_pct DECIMAL(10,2) NOT NULL DEFAULT 0,
        rebate_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_paid TINYINT(1) NOT NULL DEFAULT 0,
        paid_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL
    )");
    try {
        $c0 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'rebate_enabled'")->fetchColumn();
        if ($c0 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN rebate_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {}
    try {
        $c1 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'is_paid'")->fetchColumn();
        if ($c1 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {}
    try {
        $c2 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'paid_at'")->fetchColumn();
        if ($c2 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN paid_at DATETIME NULL");
        }
    } catch (Throwable $e) {}
}

function get_agent_win_loss(PDO $pdo, string $agent, string $day_from, string $day_to): float {
    $agent = trim($agent);
    if ($agent === '') return 0.0;
    $sql = "SELECT COALESCE(SUM(sub.pnl), 0) AS win_loss
            FROM customers c
            LEFT JOIN (
                SELECT TRIM(code) AS code,
                       SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END) - SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END) AS pnl
            FROM transactions
            WHERE status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ?
                GROUP BY TRIM(code)
            ) sub ON TRIM(c.code) = sub.code
            WHERE c.recommend IS NOT NULL AND TRIM(c.recommend) != '' AND TRIM(c.recommend) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$day_from, $day_to, $agent]);
    return (float)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_agent_user) {
    $warn = '代理账号仅可查看 Win(Loss) 与 Commission，不能修改本页设置。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_rebate_pct') {
        try {
            $agent = trim((string)($_POST['agent'] ?? ''));
            $pct_raw = str_replace(',', '.', trim((string)($_POST['rebate_pct'] ?? '0')));
            $pct = is_numeric($pct_raw) ? (float)$pct_raw : -1;
            $is_paid = !empty($_POST['is_paid']) ? 1 : 0;
            if ($agent === '') {
                throw new RuntimeException('参数错误：Agent 不能为空。');
            }
            if ($pct < 0 || $pct > 100) {
                throw new RuntimeException('返水 % 请输入 0 - 100。');
            }
            if ($is_agent_user && strcasecmp($agent, $agent_code) !== 0) {
                throw new RuntimeException('你只能修改自己的返水比例。');
            }
            $post_day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)($_POST['day_from'] ?? ''))) ? substr(trim((string)$_POST['day_from']), 0, 10) : $day_from;
            $post_day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)($_POST['day_to'] ?? ''))) ? substr(trim((string)$_POST['day_to']), 0, 10) : $day_to;
            $current_win_loss = get_agent_win_loss($pdo, $agent, $post_day_from, $post_day_to);
            if ($current_win_loss >= 0) {
                // 仅负数可给：正数或 0 一律不可标记已给
                $is_paid = 0;
            }
            ensure_agent_rebate_table($pdo);
            $stmt = $pdo->prepare("INSERT INTO agent_rebate_settings (agent_code, rebate_pct, is_paid, paid_at, updated_by) VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE rebate_pct = VALUES(rebate_pct), is_paid = VALUES(is_paid), paid_at = VALUES(paid_at), updated_by = VALUES(updated_by)");
            $paid_at = $is_paid ? date('Y-m-d H:i:s') : null;
            $stmt->execute([$agent, $pct, $is_paid, $paid_at, (int)($_SESSION['user_id'] ?? 0)]);
            $msg = '返水比例/状态已保存。';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } elseif ($action === 'toggle_rebate_enabled') {
        try {
            $agent = trim((string)($_POST['agent'] ?? ''));
            $enabled = !empty($_POST['rebate_enabled']) ? 1 : 0;
            if ($agent === '') {
                throw new RuntimeException('参数错误：Agent 不能为空。');
            }
            if ($is_agent_user && strcasecmp($agent, $agent_code) !== 0) {
                throw new RuntimeException('你只能操作自己的开关。');
            }
            ensure_agent_rebate_table($pdo);
            $stmt = $pdo->prepare("INSERT INTO agent_rebate_settings (agent_code, rebate_enabled, updated_by) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE rebate_enabled = VALUES(rebate_enabled), updated_by = VALUES(updated_by)");
            $stmt->execute([$agent, $enabled, (int)($_SESSION['user_id'] ?? 0)]);
            if ($enabled === 0) {
                // 暂停反水时，顺便清除已给状态，避免误会
                $stmt2 = $pdo->prepare("UPDATE agent_rebate_settings SET is_paid = 0, paid_at = NULL WHERE agent_code = ?");
                $stmt2->execute([$agent]);
            }
            $msg = $enabled ? '已启用该 Agent 的反水。' : '已暂停该 Agent 的反水。';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

try {
    // 本公司输赢 = 该 Agent 下所有顾客的 (withdraw - deposit)；正=公司赢，负=公司输
    $sql = "
        SELECT TRIM(c.recommend) AS agent, COUNT(*) AS cnt,
               COALESCE(SUM(sub.pnl), 0) AS win_loss
        FROM customers c
        LEFT JOIN (
            SELECT TRIM(code) AS code,
                   SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END) - SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END) AS pnl
            FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ?
            GROUP BY TRIM(code)
        ) sub ON TRIM(c.code) = sub.code
        WHERE c.recommend IS NOT NULL AND TRIM(c.recommend) != ''
    ";
    $params = [$day_from, $day_to];
    if ($is_agent_user && $agent_code !== '') {
        $sql .= " AND TRIM(c.recommend) = ?";
        $params[] = $agent_code;
    }
    $sql .= " GROUP BY TRIM(c.recommend) ORDER BY agent ASC";
    if ($params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $agents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($is_agent_user && $agent_code !== '' && empty($agents)) {
        $agents = [['agent' => $agent_code, 'cnt' => 0, 'win_loss' => 0]];
    }
    try {
        ensure_agent_rebate_table($pdo);
        $rows = $pdo->query("SELECT agent_code, rebate_pct, rebate_enabled, is_paid, paid_at FROM agent_rebate_settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtolower(trim((string)($r['agent_code'] ?? '')));
            if ($k === '') continue;
            $agent_rebate_settings_map[$k] = [
                'pct' => (float)($r['rebate_pct'] ?? 0),
                'rebate_enabled' => !isset($r['rebate_enabled']) ? true : ((int)$r['rebate_enabled'] === 1),
                'is_paid' => (int)($r['is_paid'] ?? 0) === 1,
                'paid_at' => (string)($r['paid_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // 若无建表权限，不阻断主页面
    }
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'recommend') !== false) {
        $err = '请先在 phpMyAdmin 执行 migrate_customers_recommend.sql。';
    } else {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .agent-winloss-pos { color: var(--success); font-weight: 700; }
        .agent-winloss-neg { color: var(--danger); font-weight: 700; }
        .agent-paid-wrap { display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; font-size: 12px; color: #475569; }
        .agent-paid-at { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .agent-pct-view { display: inline-flex; align-items: center; gap: 8px; justify-content: flex-end; width: 100%; }
        .agent-pct-edit { display: none; align-items: center; gap: 6px; justify-content: flex-end; width: 100%; }
        .agent-pct-badge { font-weight: 600; color: #1f2937; }
        .agent-pct-note { font-size: 12px; color: var(--muted); }
        .agent-self-table { max-width: 520px; }
        .agent-self-table th,
        .agent-self-table td { font-size: 15px; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <h2><?= $is_agent_user ? '我的代理数据' : 'Agent' ?></h2>
                    <p class="breadcrumb">
                        <?php if ($is_agent_user): ?>
                        <span>Win(Loss)</span><span>·</span><span>Commission</span>
                        <?php else: ?>
                        <a href="dashboard.php">Home</a><span>·</span>Agent (from Customer Recommend)
                        <?php endif; ?>
                    </p>
                </div>
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
                <form class="filters-bar filters-bar-flow" method="get" style="margin-bottom:16px;">
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
                        <a href="agents.php?<?= http_build_query(['day_from' => $this_week_start, 'day_to' => $this_week_end]) ?>" class="btn btn-preset">This Week</a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $last_week_start, 'day_to' => $last_week_end]) ?>" class="btn btn-preset">Last Week</a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $this_month_start, 'day_to' => $this_month_end]) ?>" class="btn btn-preset">This Month</a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $last_month_start, 'day_to' => $last_month_end]) ?>" class="btn btn-preset">Last Month</a>
                    </div>
                </form>
                <?php if ($warn): ?>
                    <div class="alert alert-error" role="status"><?= htmlspecialchars($warn) ?></div>
                <?php endif; ?>
                <?php if ($err): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
                <?php elseif ($msg): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if (!$err): ?>
                <?php if (!$is_agent_user): ?>
                <div class="summary">
                    <div class="summary-item"><strong>Agent</strong><span class="num"><?= count($agents) ?></span></div>
                </div>
                <?php endif; ?>
                <div class="card<?= $is_agent_user ? ' agent-self-table' : '' ?>" style="overflow-x: auto;">
                    <h3><?= $is_agent_user ? '汇总' : '列表' ?></h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php if ($is_agent_user): ?>
                                <th class="num">Win(Loss)</th>
                                <th class="num">Commission</th>
                                <?php else: ?>
                                <th>Agent</th>
                                <th class="num">Customers</th>
                                <th class="num">Win(Loss)</th>
                                <th class="num">Rebate %</th>
                                <th class="num">Rebate Amount</th>
                                <th>Rebate Switch</th>
                                <th>Paid</th>
                                <th>操作</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $r):
                                $agent = $r['agent'] ?? '';
                                $cnt = (int)($r['cnt'] ?? 0);
                                $winLoss = (float)($r['win_loss'] ?? 0);
                                $setting = $agent_rebate_settings_map[strtolower(trim((string)$agent))] ?? ['pct' => 0, 'rebate_enabled' => true, 'is_paid' => false, 'paid_at' => ''];
                                $pct = (float)($setting['pct'] ?? 0);
                                $rebate_enabled = !empty($setting['rebate_enabled']);
                                $rebate_base = $winLoss < 0 ? abs($winLoss) : 0; // 仅负数时可给（按绝对值计算）
                                if (!$rebate_enabled) $rebate_base = 0;
                                $rebate_amount = round($rebate_base * $pct / 100, 2);
                                $is_paid = !empty($setting['is_paid']);
                                $paid_at = trim((string)($setting['paid_at'] ?? ''));
                                $recommend_param = htmlspecialchars(urlencode($agent));
                                $can_pay = $winLoss < 0 && $rebate_enabled;
                                if (!$can_pay) $is_paid = false;
                            ?>
                            <tr>
                                <?php if ($is_agent_user): ?>
                                <td class="num <?= $winLoss >= 0 ? 'agent-winloss-pos' : 'agent-winloss-neg' ?>"><?= number_format($winLoss, 2) ?></td>
                                <td class="num <?= $rebate_amount > 0 ? 'agent-winloss-pos' : '' ?>"><?= number_format($rebate_amount, 2) ?></td>
                                <?php else: ?>
                                <td><?= htmlspecialchars($agent) ?></td>
                                <td class="num"><?= $cnt ?></td>
                                <td class="num <?= $winLoss >= 0 ? 'agent-winloss-pos' : 'agent-winloss-neg' ?>"><?= number_format($winLoss, 2) ?></td>
                                <td class="num">
                                    <form method="post" class="js-agent-rebate-form" style="display:inline-flex; align-items:center; gap:6px;">
                                        <input type="hidden" name="action" value="save_rebate_pct">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" class="js-winloss" value="<?= htmlspecialchars((string)$winLoss) ?>">
                                        <div class="agent-pct-view js-pct-view">
                                            <span class="agent-pct-badge"><?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>%</span>
                                            <button type="button" class="btn btn-sm btn-outline js-pct-edit-btn">编辑</button>
                                        </div>
                                        <div class="agent-pct-edit js-pct-edit">
                                            <input type="text" name="rebate_pct" class="form-control js-rebate-pct" inputmode="decimal" value="<?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>" style="width:86px; text-align:right;">
                                            <button type="submit" class="btn btn-sm btn-outline">保存</button>
                                            <button type="button" class="btn btn-sm btn-outline js-pct-cancel-btn">取消</button>
                                        </div>
                                    </form>
                                </td>
                                <td class="num js-rebate-amount <?= $rebate_amount > 0 ? 'agent-winloss-pos' : '' ?>"><?= number_format($rebate_amount, 2) ?></td>
                                <td>
                                    <form method="post" style="display:inline-flex; align-items:center; gap:8px;">
                                        <input type="hidden" name="action" value="toggle_rebate_enabled">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" name="rebate_enabled" value="<?= $rebate_enabled ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= $rebate_enabled ? 'btn-danger' : 'btn-primary' ?>">
                                            <?= $rebate_enabled ? '暂停' : '启用' ?>
                                        </button>
                                        <span class="agent-pct-note"><?= $rebate_enabled ? '需要给' : '不需要给' ?></span>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" class="js-agent-paid-form" style="display:inline-flex; align-items:center; gap:8px;">
                                        <input type="hidden" name="action" value="save_rebate_pct">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" name="rebate_pct" value="<?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>">
                                        <label class="agent-paid-wrap" style="margin-left:0;">
                                            <input type="checkbox" name="is_paid" value="1" <?= $is_paid ? 'checked' : '' ?> <?= $can_pay ? '' : 'disabled' ?>>
                                            已给 Agent
                                        </label>
                                        <button type="submit" class="btn btn-sm btn-outline" <?= $can_pay ? '' : 'disabled' ?>>保存</button>
                                    </form>
                                    <?php if (!$rebate_enabled): ?><span class="agent-pct-note">（已暂停反水）</span>
                                    <?php elseif (!$can_pay): ?><span class="agent-pct-note">（Win/Loss 非负，不能给）</span><?php endif; ?>
                                    <?php if ($paid_at !== ''): ?><span class="agent-paid-at">（<?= htmlspecialchars($paid_at) ?>）</span><?php endif; ?>
                                </td>
                                <td><a href="customers.php?recommend=<?= $recommend_param ?>">View Customers</a></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="<?= $is_agent_user ? '2' : '8' ?>" style="color:var(--muted); padding:24px;">No data. Agents are derived from customers whose Recommend field is filled.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
    (function(){
        function toNum(v) {
            var n = parseFloat(String(v || '').replace(/[,%\s]/g, ''));
            return isNaN(n) ? 0 : n;
        }
        document.querySelectorAll('.js-agent-rebate-form').forEach(function(form){
            var pctInput = form.querySelector('.js-rebate-pct');
            var winLossInput = form.querySelector('.js-winloss');
            var amountCell = form.closest('tr') ? form.closest('tr').querySelector('.js-rebate-amount') : null;
            var viewBox = form.querySelector('.js-pct-view');
            var editBox = form.querySelector('.js-pct-edit');
            var editBtn = form.querySelector('.js-pct-edit-btn');
            var cancelBtn = form.querySelector('.js-pct-cancel-btn');
            if (!pctInput || !winLossInput || !amountCell) return;
            function recalc(){
                var winLoss = toNum(winLossInput.value);
                var pct = toNum(pctInput.value);
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                var base = winLoss < 0 ? Math.abs(winLoss) : 0;
                var amount = base * pct / 100;
                amountCell.textContent = amount.toFixed(2);
            }
            pctInput.addEventListener('input', recalc);
            if (editBtn && viewBox && editBox) {
                editBtn.addEventListener('click', function(){
                    viewBox.style.display = 'none';
                    editBox.style.display = 'inline-flex';
                    pctInput.focus();
                    pctInput.select();
                });
            }
            if (cancelBtn && viewBox && editBox) {
                cancelBtn.addEventListener('click', function(){
                    editBox.style.display = 'none';
                    viewBox.style.display = 'inline-flex';
                });
            }
            recalc();
        });
    })();
    </script>
</body>
</html>
