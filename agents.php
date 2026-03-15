<?php
require 'config.php';
require 'auth.php';
require_permission('agent');
$sidebar_current = 'agents';

$err = '';
$agents = [];

try {
    $stmt = $pdo->query("SELECT TRIM(recommend) AS agent, COUNT(*) AS cnt FROM customers WHERE recommend IS NOT NULL AND TRIM(recommend) != '' GROUP BY TRIM(recommend) ORDER BY agent ASC");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $r):
                                $agent = $r['agent'] ?? '';
                                $cnt = (int)($r['cnt'] ?? 0);
                                $recommend_param = htmlspecialchars(urlencode($agent));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($agent) ?></td>
                                <td class="num"><?= $cnt ?></td>
                                <td><a href="customers.php?recommend=<?= $recommend_param ?>">View Customers</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="3" style="color:var(--muted); padding:24px;">No data. Agents are derived from customers whose Recommend field is filled.</td></tr>
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
