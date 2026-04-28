<?php
/**
 * PG 新增客户（写入 pg_customers）。
 *
 * 你要的字段：
 * 1) company_name
 * 2) nick_name
 * 3) currency
 * 4) pct_in
 * 5) pct_out
 * 6) volume_round
 * 7) account_type: special / mix / non
 */
require 'config.php';
require 'auth.php';
require_login();
require_permission('customer_create');
$sidebar_current = 'customer_create';

if (function_exists('shard_refresh_business_pdo')) { shard_refresh_business_pdo(); }

$company_id = current_company_id();
$pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;

// 仅 PG 公司可用
$bk = '';
try {
    $stBk = $pdoCat->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stBk->execute([$company_id]);
    $bk = strtolower(trim((string)$stBk->fetchColumn()));
} catch (Throwable $e) {}
if ($bk !== 'pg') {
    header('Location: customer_create.php');
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

function pg_norm_currency(string $raw): string
{
    // PG：允许手填，不强制 ISO，仅做去空白与长度限制
    $c = strtoupper(trim((string)$raw));
    $c = preg_replace('/\s+/', ' ', $c);
    $c = trim((string)$c);
    if ($c === '') {
        return '';
    }
    if (mb_strlen($c, 'UTF-8') > 16) {
        $c = mb_substr($c, 0, 16, 'UTF-8');
    }
    return $c;
}

function pg_norm_pct($raw): float
{
    $s = str_replace(',', '', trim((string)$raw));
    if ($s === '') return 0.0;
    $v = (float)$s;
    if (!is_finite($v)) return 0.0;
    if ($v < 0) $v = 0;
    if ($v > 100) $v = 100;
    return $v;
}

function pg_norm_round($raw): string
{
    // PG：Volume around 允许手填（自由文本）
    $v = trim((string)$raw);
    if ($v === '') {
        return '';
    }
    if (mb_strlen($v, 'UTF-8') > 32) {
        $v = mb_substr($v, 0, 32, 'UTF-8');
    }
    return $v;
}

function pg_norm_account_type($raw): string
{
    $v = strtolower(trim((string)$raw));
    return in_array($v, ['special', 'mix', 'non'], true) ? $v : 'non';
}

if ($pdoData) {
    // ensure base table (from migrate_pg_banks_and_customers.sql)
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

    // ensure PG extra fields
    $alters = [
        "ALTER TABLE pg_customers ADD COLUMN company_name VARCHAR(120) NULL DEFAULT NULL",
        "ALTER TABLE pg_customers ADD COLUMN nick_name VARCHAR(120) NULL DEFAULT NULL",
        "ALTER TABLE pg_customers ADD COLUMN currency VARCHAR(8) NULL DEFAULT NULL",
        "ALTER TABLE pg_customers ADD COLUMN pct_in DECIMAL(6,2) NOT NULL DEFAULT 0",
        "ALTER TABLE pg_customers ADD COLUMN pct_out DECIMAL(6,2) NOT NULL DEFAULT 0",
        "ALTER TABLE pg_customers ADD COLUMN volume_round VARCHAR(16) NOT NULL DEFAULT 'none'",
        "ALTER TABLE pg_customers ADD COLUMN account_type VARCHAR(16) NOT NULL DEFAULT 'non'",
    ];
    foreach ($alters as $sql) {
        try { $pdoData->exec($sql); } catch (Throwable $e) {}
    }
}

// defaults
$def_currency = '';
try {
    $stC = $pdoCat->prepare('SELECT currency FROM companies WHERE id = ? LIMIT 1');
    $stC->execute([$company_id]);
    $def_currency = pg_norm_currency((string)$stC->fetchColumn());
} catch (Throwable $e) {}
if ($def_currency === '') $def_currency = 'MYR';

$form = [
    'code' => '',
    'company_name' => '',
    'nick_name' => '',
    'currency' => $def_currency,
    'pct_in' => '0',
    'pct_out' => '0',
    'volume_round' => '',
    'account_type' => 'non',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdoData) {
    $form['code'] = trim((string)($_POST['code'] ?? ''));
    $form['company_name'] = trim((string)($_POST['company_name'] ?? ''));
    $form['nick_name'] = trim((string)($_POST['nick_name'] ?? ''));
    $form['currency'] = pg_norm_currency((string)($_POST['currency'] ?? ''));
    $pctIn = pg_norm_pct($_POST['pct_in'] ?? '0');
    $pctOut = pg_norm_pct($_POST['pct_out'] ?? '0');
    $form['pct_in'] = (string)$pctIn;
    $form['pct_out'] = (string)$pctOut;
    $form['volume_round'] = pg_norm_round($_POST['volume_round'] ?? 'none');
    $form['account_type'] = pg_norm_account_type($_POST['account_type'] ?? 'non');

    try {
        if ($form['code'] === '' || strlen($form['code']) > 64) {
            throw new RuntimeException('排行 必填，最多 64 字符。');
        }
        if ($form['currency'] === '') {
            $form['currency'] = $def_currency;
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $st = $pdoData->prepare("INSERT INTO pg_customers
            (company_id, code, company_name, nick_name, currency, pct_in, pct_out, volume_round, account_type, status, is_active, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 1, ?, NOW())");
        $st->execute([
            $company_id,
            $form['code'],
            $form['company_name'] !== '' ? $form['company_name'] : null,
            $form['nick_name'] !== '' ? $form['nick_name'] : null,
            $form['currency'],
            $pctIn,
            $pctOut,
            $form['volume_round'],
            $form['account_type'],
            $uid > 0 ? $uid : null,
        ]);
        header('Location: pg_customers.php?created=1');
        exit;
    } catch (Throwable $e) {
        $raw = (string)$e->getMessage();
        if (strpos($raw, 'Duplicate') !== false || strpos($raw, '1062') !== false) {
            $err = '该排行已存在，请换一个。';
        } else {
            $err = $raw;
        }
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG New Customer - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 860px;">
            <div class="page-header">
                <h2>PG New Customer</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="card">
                <form method="post" class="filters-bar filters-bar-flow" style="margin-bottom:0;">
                    <div class="filter-group" style="min-width:240px;">
                        <label>排行 *</label>
                        <input class="form-control" name="code" required maxlength="64" value="<?= htmlspecialchars($form['code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="例如 A1 / C001">
                    </div>
                    <div class="filter-group" style="min-width:240px;">
                        <label>Company name</label>
                        <input class="form-control" name="company_name" maxlength="120" value="<?= htmlspecialchars($form['company_name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="filter-group" style="min-width:240px;">
                        <label>Nick name</label>
                        <input class="form-control" name="nick_name" maxlength="120" value="<?= htmlspecialchars($form['nick_name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="filter-group" style="min-width:160px;">
                        <label>Currency</label>
                        <input class="form-control" name="currency" maxlength="16" value="<?= htmlspecialchars($form['currency'], ENT_QUOTES, 'UTF-8') ?>" placeholder="例如 MYR">
                    </div>
                    <div class="filter-group" style="min-width:140px;">
                        <label>% in</label>
                        <input class="form-control" name="pct_in" inputmode="decimal" value="<?= htmlspecialchars($form['pct_in'], ENT_QUOTES, 'UTF-8') ?>" placeholder="0 - 100">
                    </div>
                    <div class="filter-group" style="min-width:140px;">
                        <label>% out</label>
                        <input class="form-control" name="pct_out" inputmode="decimal" value="<?= htmlspecialchars($form['pct_out'], ENT_QUOTES, 'UTF-8') ?>" placeholder="0 - 100">
                    </div>
                    <div class="filter-group" style="min-width:200px;">
                        <label>Volume around</label>
                        <input class="form-control" name="volume_round" maxlength="32" value="<?= htmlspecialchars($form['volume_round'], ENT_QUOTES, 'UTF-8') ?>" placeholder="手动填写（可空）">
                    </div>
                    <div class="filter-group" style="min-width:220px;">
                        <label>Account</label>
                        <select class="form-control" name="account_type">
                            <option value="special" <?= $form['account_type'] === 'special' ? 'selected' : '' ?>>special</option>
                            <option value="mix" <?= $form['account_type'] === 'mix' ? 'selected' : '' ?>>mix</option>
                            <option value="non" <?= $form['account_type'] === 'non' ? 'selected' : '' ?>>non</option>
                        </select>
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button class="btn btn-primary" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>

