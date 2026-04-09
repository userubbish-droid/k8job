<?php
/**
 * 余额通知阈值设置（隐藏入口，仅管理员）
 * 按银行/产品分别设置：银行超过设定值通知，产品低于设定值通知。
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_admin();

$sidebar_current = '';
$data_dir = __DIR__ . '/data';
$config_path = $data_dir . '/balance_notify.json';

require_once __DIR__ . '/inc/balance_notify.php';
$cfg = balance_notify_get_config();
$banks = [];
$products = [];
try {
    $banks = $pdo->query("SELECT id, name FROM banks ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    $products = $pdo->query("SELECT id, name FROM products ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) {}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bank_arr = isset($_POST['bank']) && is_array($_POST['bank']) ? $_POST['bank'] : [];
    $product_arr = isset($_POST['product']) && is_array($_POST['product']) ? $_POST['product'] : [];
    $bank_cfg = [];
    foreach ($bank_arr as $k => $v) {
        $key = strtolower(trim((string)$k));
        if ($key === '') continue;
        $v = trim((string)$v);
        if ($v === '') continue;
        if (is_numeric($v) && (float)$v > 0) $bank_cfg[$key] = (float)$v;
    }
    $product_cfg = [];
    foreach ($product_arr as $k => $v) {
        $key = strtolower(trim((string)$k));
        if ($key === '') continue;
        $v = trim((string)$v);
        if ($v === '') continue;
        if (is_numeric($v) && (float)$v > 0) $product_cfg[$key] = (float)$v;
    }
    if (!is_dir($data_dir)) @mkdir($data_dir, 0755, true);
    $data = ['bank' => $bank_cfg, 'product' => $product_cfg];
    if (@file_put_contents($config_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
        $msg = '已保存。按银行/产品分别设置，同项 24 小时内不重复通知。';
        $cfg = balance_notify_get_config();
    } else {
        $err = '无法写入 data/balance_notify.json，请检查目录权限。';
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
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
                <div class="card">
                    <p class="form-hint" style="margin-bottom: 14px;">按银行/产品分别设置。银行：余额超过设定值即通知；产品：余额低于设定值即通知。留空=不通知。同项 24 小时内不重复。</p>
                    <form method="post" style="display:flex;flex-wrap:wrap;gap:20px 24px;">
                        <div style="min-width:180px;">
                            <label class="block" style="font-size:12px;margin-bottom:6px;">银行（超过即通知）</label>
                            <?php foreach ($banks as $b): $bname = trim((string)$b['name']); $bkey = strtolower($bname); $val = $cfg['bank'][$bkey] ?? ''; ?>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <label style="min-width:4em;font-size:12px;margin:0;"><?= htmlspecialchars($bname) ?></label>
                                <input type="text" name="bank[<?= htmlspecialchars($bkey) ?>]" class="form-control" placeholder="留空" inputmode="decimal" value="<?= $val !== '' && $val > 0 ? htmlspecialchars((string)$val) : '' ?>" style="width:88px;">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="min-width:180px;">
                            <label class="block" style="font-size:12px;margin-bottom:6px;">产品（低于即通知）</label>
                            <?php foreach ($products as $p): $pname = trim((string)$p['name']); $pkey = strtolower($pname); $val = $cfg['product'][$pkey] ?? ''; ?>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <label style="min-width:4em;font-size:12px;margin:0;"><?= htmlspecialchars($pname) ?></label>
                                <input type="text" name="product[<?= htmlspecialchars($pkey) ?>]" class="form-control" placeholder="留空" inputmode="decimal" value="<?= $val !== '' && $val > 0 ? htmlspecialchars((string)$val) : '' ?>" style="width:88px;">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="width:100%;margin-top:10px;">
                            <button type="submit" class="btn btn-primary">保存</button>
                            <a href="admin_banks_products.php" class="btn btn-outline" style="margin-left:8px;">返回银行与产品</a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
