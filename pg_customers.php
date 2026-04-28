<?php
/**
 * PG 客户资料（pg_customers）：与 gaming 的 customers.php 分离。
 */
require 'config.php';
require 'auth.php';
require_login();
require_permission('customers');
$sidebar_current = 'customers';

if (function_exists('shard_refresh_business_pdo')) { shard_refresh_business_pdo(); }

$role = (string)($_SESSION['user_role'] ?? '');
$is_admin = in_array($role, ['admin', 'superadmin', 'boss'], true);

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
    header('Location: customers.php');
    exit;
}

if (!function_exists('pdo_data_for_company_id')) {
    $err = '缺少 PG 数据库连接函数（pdo_data_for_company_id）。';
    $rows = [];
} else {
    $pdoData = pdo_data_for_company_id($pdoCat, $company_id);
    // 兜底建表：无权限/已存在都忽略
    try {
        $pdoData->exec("CREATE TABLE IF NOT EXISTS pg_customers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(120) NULL DEFAULT NULL,
            phone VARCHAR(40) NULL DEFAULT NULL,
            remark VARCHAR(512) NULL DEFAULT NULL,
            bank_details TEXT NULL DEFAULT NULL,
            register_date DATE NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'approved',
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pg_customers_company_code (company_id, code),
            KEY idx_pg_customers_company (company_id, is_active),
            KEY idx_pg_customers_phone (company_id, phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    $q = trim((string)($_GET['q'] ?? ''));
    $where = ["company_id = ?"];
    $params = [$company_id];
    if ($q !== '') {
        $where[] = "(code LIKE ? OR name LIKE ? OR phone LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql_where = implode(' AND ', $where);
    try {
        $st = $pdoData->prepare("SELECT id, code,
            COALESCE(company_name,'') AS company_name,
            COALESCE(nick_name,'') AS nick_name,
            COALESCE(currency,'') AS currency,
            COALESCE(pct_in,0) AS pct_in,
            COALESCE(pct_out,0) AS pct_out,
            COALESCE(volume_round,'') AS volume_round,
            COALESCE(account_type,'') AS account_type,
            is_active, COALESCE(status,'') AS status, created_at
            FROM pg_customers WHERE $sql_where ORDER BY id DESC LIMIT 500");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $err = '';
    } catch (Throwable $e) {
        $rows = [];
        $err = '无法加载 PG 客户资料：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG Customers - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 100%;">
            <div class="page-header">
                <h2>PG Customers</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if (!empty($err)): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="card">
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
                    <a class="btn btn-primary" href="pg_customer_create.php">+ New Customer</a>
                    <span class="form-hint">PG 客户字段：company name / nick name / currency / % / volume / account type</span>
                </div>
                <form method="get" class="filters-bar" style="margin-bottom:12px;">
                    <div class="filters-row" style="flex-wrap:wrap; gap:10px; align-items:flex-end;">
                        <div class="filter-group" style="min-width:240px;">
                            <label>搜索</label>
                            <input class="form-control" name="q" value="<?= htmlspecialchars($q ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="code / name / phone">
                        </div>
                        <button class="btn btn-search" type="submit">查询</button>
                        <span class="form-hint" style="margin-left:auto;">最多显示 500 条</span>
                    </div>
                </form>

                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Code</th>
                            <th>Company name</th>
                            <th>Nick name</th>
                            <th>Currency</th>
                            <th>% in</th>
                            <th>% out</th>
                            <th>Volume around</th>
                            <th>Account</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($rows ?? []) as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($r['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['nick_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['currency'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="num"><?= number_format((float)($r['pct_in'] ?? 0), 2) ?></td>
                                <td class="num"><?= number_format((float)($r['pct_out'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars((string)($r['volume_round'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($r['account_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= ((int)($r['is_active'] ?? 1) === 1) ? 'ACTIVE' : 'INACTIVE' ?></td>
                                <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows ?? [])): ?>
                            <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:18px;">暂无数据</td></tr>
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

