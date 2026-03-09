<?php
/**
 * 余额通知阈值设置（隐藏入口，仅管理员，不放在侧栏）
 * 银行：余额超过设定值时 Telegram 通知；产品：余额低于设定值时 Telegram 通知。
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_admin();

$sidebar_current = '';
$data_dir = __DIR__ . '/data';
$config_path = $data_dir . '/balance_notify.json';

require_once __DIR__ . '/inc/balance_notify.php';
$cfg = balance_notify_get_config();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bank_above = trim($_POST['bank_above'] ?? '');
    $product_below = trim($_POST['product_below'] ?? '');
    $bank_above_val = $bank_above === '' ? null : (is_numeric($bank_above) ? (float)$bank_above : null);
    $product_below_val = $product_below === '' ? null : (is_numeric($product_below) ? (float)$product_below : null);
    if ($bank_above !== '' && ($bank_above_val === null || $bank_above_val < 0)) {
        $err = '银行「超过」请填有效数字或留空。';
    } elseif ($product_below !== '' && ($product_below_val === null || $product_below_val < 0)) {
        $err = '产品「低于」请填有效数字或留空。';
    } else {
        $data = [
            'bank_above'    => $bank_above_val,
            'product_below' => $product_below_val,
        ];
        if (!is_dir($data_dir)) @mkdir($data_dir, 0755, true);
        if (@file_put_contents($config_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
            $msg = '已保存。银行超过设定值会通知；产品低于设定值会通知。同一项 24 小时内不重复通知。';
            $cfg = balance_notify_get_config();
        } else {
            $err = '无法写入 data/balance_notify.json，请检查目录权限。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>余额通知设置 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= (int)filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 560px;">
                <div class="page-header">
                    <h2>余额通知（Telegram）</h2>
                    <p class="breadcrumb"><a href="admin_banks_products.php">银行与产品</a><span>·</span>隐藏设置</p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
                <div class="card">
                    <p class="form-hint" style="margin-bottom: 14px;">打开「银行与产品」页面时会根据当前余额检查以下阈值，并通过 Telegram 通知（同一条目 24 小时内只通知一次）。</p>
                    <form method="post">
                        <div class="form-group">
                            <label>银行余额超过多少时通知（留空=不通知）</label>
                            <input type="text" name="bank_above" class="form-control" placeholder="例如 10000" inputmode="decimal" value="<?= $cfg['bank_above'] !== null ? htmlspecialchars((string)$cfg['bank_above']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>产品余额低于多少时通知（留空=不通知）</label>
                            <input type="text" name="product_below" class="form-control" placeholder="例如 500" inputmode="decimal" value="<?= $cfg['product_below'] !== null ? htmlspecialchars((string)$cfg['product_below']) : '' ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">保存</button>
                        <a href="admin_banks_products.php" class="btn btn-outline" style="margin-left:8px;">返回银行与产品</a>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
