<?php
/**
 * PG Bank detail：记录每个 pg_banks 的银行资料（账号/姓名/备注等）。
 */
require 'config.php';
require 'auth.php';
require_login();
require_admin();
$sidebar_current = 'admin_banks_products';

if (function_exists('shard_refresh_business_pdo')) { shard_refresh_business_pdo(); }

$company_id = current_company_id();

// 仅 PG 公司可用
$pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
$bk = '';
try {
    $stBk = $pdoCat->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $bk = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {}
if ($bk !== 'pg') {
    header('Location: admin_banks_products.php');
    exit;
}

if (!function_exists('pdo_data_for_company_id')) {
    $pdoData = null;
    $err = '缺少 PG 数据库连接函数（pdo_data_for_company_id）。';
} else {
    $pdoData = pdo_data_for_company_id($pdoCat, $company_id);
    $err = '';
}

$msg = '';

if ($pdoData) {
    // ensure tables
    try {
        $pdoData->exec("CREATE TABLE IF NOT EXISTS pg_banks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pg_banks_company_name (company_id, name),
            KEY idx_pg_banks_company (company_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
        $pdoData->exec("CREATE TABLE IF NOT EXISTS pg_bank_details (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            bank_id INT UNSIGNED NOT NULL,
            details TEXT NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pg_bank_details_company_bank (company_id, bank_id),
            KEY idx_pg_bank_details_company (company_id, bank_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $bank_id = (int)($_POST['bank_id'] ?? 0);
                $details = trim((string)($_POST['details'] ?? ''));
                if ($bank_id <= 0) throw new RuntimeException('参数错误。');
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $st = $pdoData->prepare("INSERT INTO pg_bank_details (company_id, bank_id, details, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE details = VALUES(details), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
                $st->execute([$company_id, $bank_id, ($details !== '' ? $details : null), $uid > 0 ? $uid : null]);
                $msg = '已保存。';
            } else {
                throw new RuntimeException('未知操作。');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$banks = [];
$details_by_bank_id = [];
if ($pdoData) {
    try {
        $st = $pdoData->prepare('SELECT id, name, is_active, sort_order FROM pg_banks WHERE company_id = ? ORDER BY sort_order DESC, name ASC');
        $st->execute([$company_id]);
        $banks = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $banks = [];
        $err = $err ?: ('无法加载 pg_banks：' . $e->getMessage());
    }
    try {
        $st = $pdoData->prepare('SELECT bank_id, COALESCE(details, "") AS details FROM pg_bank_details WHERE company_id = ?');
        $st->execute([$company_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bid = (int)($r['bank_id'] ?? 0);
            if ($bid > 0) {
                $details_by_bank_id[$bid] = (string)($r['details'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $details_by_bank_id = [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG Bank detail - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .pgbd-grid { display:grid; grid-template-columns: 1fr; gap: 12px; }
        .pgbd-row { display:grid; grid-template-columns: 180px 1fr 120px; gap: 10px; align-items: start; }
        .pgbd-name { font-weight: 800; color: var(--text); padding-top: 10px; }
        .pgbd-name small { display:block; font-weight: 600; color: var(--muted); margin-top: 4px; }
        .pgbd-actions { padding-top: 6px; }
        textarea.form-control { min-height: 88px; resize: vertical; }
        @media (max-width: 860px) { .pgbd-row { grid-template-columns: 1fr; } .pgbd-actions { padding-top: 0; } }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1100px;">
            <div class="page-header">
                <h2>PG Bank detail</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="card">
                <p class="form-hint" style="margin-top:0;">这里记录每个 Bank/Channel 的银行资料（账号、户名、备注等）。不会影响流水统计口径。</p>

                <div class="pgbd-grid">
                    <?php foreach ($banks as $b):
                        $bid = (int)($b['id'] ?? 0);
                        $nm = trim((string)($b['name'] ?? ''));
                        if ($bid <= 0 || $nm === '') continue;
                        $d = $details_by_bank_id[$bid] ?? '';
                        $active = (int)($b['is_active'] ?? 1) === 1;
                    ?>
                    <form method="post" class="pgbd-row">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="bank_id" value="<?= $bid ?>">
                        <div class="pgbd-name">
                            <?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?>
                            <small><?= $active ? 'Enabled' : 'Disabled' ?></small>
                        </div>
                        <div>
                            <textarea class="form-control" name="details" placeholder="例如：\nAccount name: ...\nAccount no: ...\nBank: ...\nRemark: ..."><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="pgbd-actions">
                            <button class="btn btn-primary" type="submit" style="width:100%;">保存</button>
                            <a class="btn btn-outline" href="pg_banks.php" style="width:100%;margin-top:8px;display:inline-block;text-align:center;">回 Bank</a>
                        </div>
                    </form>
                    <?php endforeach; ?>

                    <?php if (!$banks): ?>
                        <div style="text-align:center;color:var(--muted);padding:18px;">暂无 Bank/Channel。请先到 Bank 页面新增。</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>

