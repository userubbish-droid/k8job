<?php
require 'config.php';
require 'auth.php';
require_permission('agent');
$sidebar_current = 'agents';

$err = '';
$msg = '';
$agents = [];
$is_agent_user = ($_SESSION['user_role'] ?? '') === 'agent';
$agent_code = $is_agent_user ? trim((string)($_SESSION['agent_code'] ?? '')) : '';
$agent_rebate_pct_map = [];

function ensure_agent_rebate_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_rebate_settings (
        agent_code VARCHAR(80) NOT NULL PRIMARY KEY,
        rebate_pct DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL
    )");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_rebate_pct') {
        try {
            $agent = trim((string)($_POST['agent'] ?? ''));
            $pct_raw = str_replace(',', '.', trim((string)($_POST['rebate_pct'] ?? '0')));
            $pct = is_numeric($pct_raw) ? (float)$pct_raw : -1;
            if ($agent === '') {
                throw new RuntimeException('参数错误：Agent 不能为空。');
            }
            if ($pct < 0 || $pct > 100) {
                throw new RuntimeException('返水 % 请输入 0 - 100。');
            }
            if ($is_agent_user && strcasecmp($agent, $agent_code) !== 0) {
                throw new RuntimeException('你只能修改自己的返水比例。');
            }
            ensure_agent_rebate_table($pdo);
            $stmt = $pdo->prepare("INSERT INTO agent_rebate_settings (agent_code, rebate_pct, updated_by) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE rebate_pct = VALUES(rebate_pct), updated_by = VALUES(updated_by)");
            $stmt->execute([$agent, $pct, (int)($_SESSION['user_id'] ?? 0)]);
            $msg = '返水比例已保存。';
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
            FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != ''
            GROUP BY TRIM(code)
        ) sub ON TRIM(c.code) = sub.code
        WHERE c.recommend IS NOT NULL AND TRIM(c.recommend) != ''
    ";
    $params = [];
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
        $rows = $pdo->query("SELECT agent_code, rebate_pct FROM agent_rebate_settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtolower(trim((string)($r['agent_code'] ?? '')));
            if ($k === '') continue;
            $agent_rebate_pct_map[$k] = (float)($r['rebate_pct'] ?? 0);
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
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <h2>Agent</h2>
                    <p class="breadcrumb"><a href="dashboard.php">Home</a><span>·</span>Agent (from Customer Recommend)</p>
                </div>
                <?php if ($err): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
                <?php elseif ($msg): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
                <?php else: ?>
                <div class="summary">
                    <div class="summary-item"><strong>Agent</strong><span class="num"><?= count($agents) ?></span></div>
                </div>
                <div class="card" style="overflow-x: auto;">
                    <h3>列表</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th class="num">Customers</th>
                                <th class="num">Win(Loss)</th>
                                <th class="num">Rebate %</th>
                                <th class="num">Rebate Amount</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $r):
                                $agent = $r['agent'] ?? '';
                                $cnt = (int)($r['cnt'] ?? 0);
                                $winLoss = (float)($r['win_loss'] ?? 0);
                                $pct = (float)($agent_rebate_pct_map[strtolower(trim((string)$agent))] ?? 0);
                                $rebate_base = $winLoss > 0 ? $winLoss : 0; // 仅公司赢时计算反水
                                $rebate_amount = round($rebate_base * $pct / 100, 2);
                                $recommend_param = htmlspecialchars(urlencode($agent));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($agent) ?></td>
                                <td class="num"><?= $cnt ?></td>
                                <td class="num <?= $winLoss >= 0 ? 'stmt-out' : 'stmt-in' ?>"><?= number_format($winLoss, 2) ?></td>
                                <td class="num">
                                    <form method="post" style="display:inline-flex; align-items:center; gap:6px;">
                                        <input type="hidden" name="action" value="save_rebate_pct">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="text" name="rebate_pct" class="form-control" inputmode="decimal" value="<?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>" style="width:86px; text-align:right;">
                                        <button type="submit" class="btn btn-sm btn-outline">保存</button>
                                    </form>
                                </td>
                                <td class="num <?= $rebate_amount > 0 ? 'stmt-in' : '' ?>"><?= $rebate_amount > 0 ? number_format($rebate_amount, 2) : '0.00' ?></td>
                                <td><a href="customers.php?recommend=<?= $recommend_param ?>">View Customers</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="6" style="color:var(--muted); padding:24px;">No data. Agents are derived from customers whose Recommend field is filled.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
