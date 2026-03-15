<?php
require 'config.php';
require 'auth.php';
require_permission('agent');
$sidebar_current = 'agents';

$err = '';
$agents = [];
$is_agent_user = ($_SESSION['user_role'] ?? '') === 'agent';
$agent_code = $is_agent_user ? trim((string)($_SESSION['agent_code'] ?? '')) : '';

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
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $r):
                                $agent = $r['agent'] ?? '';
                                $cnt = (int)($r['cnt'] ?? 0);
                                $winLoss = (float)($r['win_loss'] ?? 0);
                                $recommend_param = htmlspecialchars(urlencode($agent));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($agent) ?></td>
                                <td class="num"><?= $cnt ?></td>
                                <td class="num <?= $winLoss >= 0 ? 'stmt-out' : 'stmt-in' ?>"><?= number_format($winLoss, 2) ?></td>
                                <td><a href="customers.php?recommend=<?= $recommend_param ?>">View Customers</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="4" style="color:var(--muted); padding:24px;">No data. Agents are derived from customers whose Recommend field is filled.</td></tr>
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
