<?php
/**
 * PG 银行/渠道（pg_banks）：与 gaming 的 banks 完全分开。
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
    // ensure table
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name = trim((string)($_POST['name'] ?? ''));
                $sort = (int)($_POST['sort_order'] ?? 0);
                if ($name === '') throw new RuntimeException('请输入银行/渠道名称。');
                $st = $pdoData->prepare('INSERT INTO pg_banks (company_id, name, sort_order, is_active) VALUES (?, ?, ?, 1)');
                $st->execute([$company_id, $name, $sort]);
                $msg = '已添加。';
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $sort = (int)($_POST['sort_order'] ?? 0);
                if ($id <= 0 || $name === '') throw new RuntimeException('参数错误。');
                $st = $pdoData->prepare('UPDATE pg_banks SET name = ?, sort_order = ? WHERE id = ? AND company_id = ?');
                $st->execute([$name, $sort, $id, $company_id]);
                $msg = '已保存。';
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('参数错误。');
                $st = $pdoData->prepare('UPDATE pg_banks SET is_active = IF(is_active=1,0,1) WHERE id = ? AND company_id = ?');
                $st->execute([$id, $company_id]);
                $msg = '已更新状态。';
            } else {
                throw new RuntimeException('未知操作。');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$rows = [];
if ($pdoData) {
    try {
        $st = $pdoData->prepare('SELECT id, name, sort_order, is_active, created_at FROM pg_banks WHERE company_id = ? ORDER BY sort_order DESC, name ASC');
        $st->execute([$company_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
        $err = $err ?: ('无法加载 pg_banks：' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG Bank - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1100px;">
            <div class="page-header">
                <h2>PG Bank</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="card">
                <h3 style="margin-top:0;">新增 Bank / Channel</h3>
                <form method="post" class="filters-bar filters-bar-flow" style="margin-bottom:0;">
                    <input type="hidden" name="action" value="create">
                    <div class="filter-group">
                        <label>Name</label>
                        <input class="form-control" name="name" required maxlength="80" placeholder="例如 B1 / CASH / RHB">
                    </div>
                    <div class="filter-group">
                        <label>Sort</label>
                        <input class="form-control" type="number" name="sort_order" value="0">
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button class="btn btn-primary" type="submit">新增</button>
                    </div>
                </form>
            </div>

            <div class="card" style="margin-top:16px;">
                <h3 style="margin-top:0;">列表</h3>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name / Sort</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td>
                                    <form method="post" class="admin-users-role-form" style="flex-wrap:wrap;gap:8px;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input class="form-control" name="name" value="<?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="80" required style="min-width:200px;">
                                        <input class="form-control" type="number" name="sort_order" value="<?= (int)($r['sort_order'] ?? 0) ?>" style="width:100px;">
                                        <button type="submit" class="btn btn-sm btn-primary">保存</button>
                                    </form>
                                </td>
                                <td><?= (int)$r['is_active'] === 1 ? 'Enabled' : 'Disabled' ?></td>
                                <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-gray" type="submit"><?= (int)$r['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                    <a class="btn btn-sm btn-outline" href="pg_bank_detail.php" style="margin-left:8px;">Bank detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:18px;">暂无数据</td></tr>
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

