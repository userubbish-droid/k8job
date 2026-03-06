<?php
require 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

// 今日统计
$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                              COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                       FROM transactions WHERE day = ?");
$stmt->execute([$today]);
$day = $stmt->fetch();
$day_in   = $day['total_in'];
$day_out  = $day['total_out'];
$day_profit = $day_in - $day_out;

// 本月统计
$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS total_in,
                              COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS total_out
                       FROM transactions WHERE day >= ? AND day <= ?");
$stmt->execute([$month_start, $month_end]);
$month = $stmt->fetch();
$month_in    = $month['total_in'];
$month_out   = $month['total_out'];
$month_profit = $month_in - $month_out;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>首页 - 算账网</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .wrap { max-width: 640px; margin: 0 auto; }
        h1 { margin: 0 0 8px; font-size: 1.5rem; color: #333; }
        .user { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { margin: 0 0 16px; font-size: 1rem; color: #666; font-weight: 600; }
        .row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; }
        .row:last-child { margin-bottom: 0; }
        .item { flex: 1; min-width: 120px; }
        .item .label { font-size: 0.85rem; color: #888; margin-bottom: 4px; }
        .item .value { font-size: 1.25rem; font-weight: 600; }
        .item .value.in { color: #28a745; }
        .item .value.out { color: #dc3545; }
        .item .value.profit { color: #007bff; }
        .nav { margin-top: 24px; }
        .nav a { display: inline-block; margin-right: 12px; padding: 10px 16px; background: #007bff; color: #fff; text-decoration: none; border-radius: 6px; font-size: 0.9rem; }
        .nav a:hover { background: #0056b3; }
        .nav a.outline { background: transparent; color: #666; border: 1px solid #ddd; }
        .nav a.outline:hover { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>算账网</h1>
        <p class="user">欢迎，<?= htmlspecialchars($_SESSION['user_name'] ?? '用户') ?></p>

        <div class="card">
            <h2>今日（<?= htmlspecialchars($today) ?>）</h2>
            <div class="row">
                <div class="item">
                    <div class="label">总入 DEPOSIT</div>
                    <div class="value in"><?= number_format((float)$day_in, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">总出 WITHDRAW</div>
                    <div class="value out"><?= number_format((float)$day_out, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">利润</div>
                    <div class="value profit"><?= number_format($day_profit, 2) ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>本月（<?= $month_start ?> ~ <?= $month_end ?>）</h2>
            <div class="row">
                <div class="item">
                    <div class="label">总入</div>
                    <div class="value in"><?= number_format((float)$month_in, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">总出</div>
                    <div class="value out"><?= number_format((float)$month_out, 2) ?></div>
                </div>
                <div class="item">
                    <div class="label">利润</div>
                    <div class="value profit"><?= number_format($month_profit, 2) ?></div>
                </div>
            </div>
        </div>

        <div class="nav">
            <a href="transaction_create.php">记一笔流水</a>
            <a href="transaction_list.php">流水列表</a>
            <a href="logout.php" class="outline">退出</a>
        </div>
    </div>
</body>
</html>
