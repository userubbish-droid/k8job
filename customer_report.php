<?php
require 'config.php';
require 'auth.php';
require_login();
if (($_SESSION['user_role'] ?? '') === 'agent') {
    header('Location: agents.php');
    exit;
}
require_permission('statement_report');
$sidebar_current = 'customer_report';

$today = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to = date('Y-m-t');
$company_id = current_company_id();

$day_from_raw = $_GET['day_from'] ?? $default_from;
$day_to_raw = $_GET['day_to'] ?? $default_to;
$day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$day_from_raw) ? substr((string)$day_from_raw, 0, 10) : $default_from;
$day_to   = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$day_to_raw)   ? substr((string)$day_to_raw, 0, 10)   : $default_to;
if ($day_from > $day_to) { $t = $day_from; $day_from = $day_to; $day_to = $t; }

$rows = [];
$err = '';
try {
    // 显示所有在区间内有流水（上线）的客户：按 code 汇总
    // Burn 不计入 Withdraw 列，独立扣减到 Net
    $stmt = $pdo->prepare("
        SELECT
            TRIM(t.code) AS code,
            MIN(c.id) AS customer_id,
            MIN(c.name) AS customer_name,
            COALESCE(SUM(CASE WHEN t.mode = 'DEPOSIT' THEN t.amount ELSE 0 END), 0) AS dep,
            COALESCE(SUM(CASE WHEN t.mode = 'WITHDRAW' THEN t.amount ELSE 0 END), 0) AS wd,
            COALESCE(SUM(CASE WHEN t.mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(t.burn, 0) ELSE 0 END), 0) AS burn
        FROM transactions t
        LEFT JOIN customers c
          ON c.company_id = t.company_id AND TRIM(c.code) = TRIM(t.code)
        WHERE t.company_id = ?
          AND t.status = 'approved' AND t.deleted_at IS NULL
          AND t.day >= ? AND t.day <= ?
          AND t.code IS NOT NULL AND TRIM(t.code) <> ''
        GROUP BY TRIM(t.code)
        ORDER BY (COALESCE(SUM(CASE WHEN t.mode = 'DEPOSIT' THEN t.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN t.mode = 'WITHDRAW' THEN t.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN t.mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(t.burn, 0) ELSE 0 END), 0)) DESC,
                 TRIM(t.code) ASC
    ");
    $stmt->execute([$company_id, $day_from, $day_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // burn 列不存在则回退（net = dep - wd）
    try {
        $stmt = $pdo->prepare("
            SELECT
                TRIM(t.code) AS code,
                MIN(c.id) AS customer_id,
                MIN(c.name) AS customer_name,
                COALESCE(SUM(CASE WHEN t.mode = 'DEPOSIT' THEN t.amount ELSE 0 END), 0) AS dep,
                COALESCE(SUM(CASE WHEN t.mode = 'WITHDRAW' THEN t.amount ELSE 0 END), 0) AS wd,
                0 AS burn
            FROM transactions t
            LEFT JOIN customers c
              ON c.company_id = t.company_id AND TRIM(c.code) = TRIM(t.code)
            WHERE t.company_id = ?
              AND t.status = 'approved' AND t.deleted_at IS NULL
              AND t.day >= ? AND t.day <= ?
              AND t.code IS NOT NULL AND TRIM(t.code) <> ''
            GROUP BY TRIM(t.code)
            ORDER BY (COALESCE(SUM(CASE WHEN t.mode = 'DEPOSIT' THEN t.amount ELSE 0 END), 0)
                      - COALESCE(SUM(CASE WHEN t.mode = 'WITHDRAW' THEN t.amount ELSE 0 END), 0)) DESC,
                     TRIM(t.code) ASC
        ");
        $stmt->execute([$company_id, $day_from, $day_to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $err = $e->getMessage();
        $rows = [];
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('nav_customer_report'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1160px;">
            <div class="page-header">
                <h2><?= htmlspecialchars(__('nav_customer_report'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="breadcrumb">
                    <a href="dashboard.php"><?= htmlspecialchars(__('nav_home'), ENT_QUOTES, 'UTF-8') ?></a>
                    <span>·</span>
                    <span><?= htmlspecialchars(__('nav_customer_report'), ENT_QUOTES, 'UTF-8') ?></span>
                </p>
            </div>

            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="card">
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
                        <button type="submit" class="btn btn-search"><?= htmlspecialchars(__('btn_search'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>

                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('cust_col_customer'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_deposit'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_withdraw'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_csv_burn'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('cust_col_net'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $code = (string)($r['code'] ?? '');
                            $cid = (int)($r['customer_id'] ?? 0);
                            $in = (float)($r['dep'] ?? 0);
                            $out = (float)($r['wd'] ?? 0);
                            $burn = (float)($r['burn'] ?? 0);
                            $net = $in - $out - $burn;
                        ?>
                            <tr>
                                <td>
                                    <?php if ($cid > 0): ?>
                                        <a href="customer_edit.php?id=<?= $cid ?>"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= number_format($in, 2) ?></td>
                                <td class="num"><?= number_format($out, 2) ?></td>
                                <td class="num"><?= number_format($burn, 2) ?></td>
                                <td class="num <?= $net >= 0 ? 'in' : 'out' ?>"><?= number_format($net, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="5" style="color:var(--muted); padding:18px;"><?= htmlspecialchars(__('cust_empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>

