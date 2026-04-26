<?php
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();
$sidebar_current = 'admin_legacy_profit';

$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$session_company_id = current_company_id();
$company_id = effective_admin_company_id($pdo);
$company_pick = $company_id;
$companies = [];
$company_pick_label = '';

if ($actor_is_superadmin) {
    try {
        $companies = $pdo->query('SELECT id, code, name, is_active FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companies = [];
    }
    $pick_raw = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
    if ($pick_raw > 0) {
        $company_pick = $pick_raw;
    }
    // 不允许选择 0（总公司汇总）来写入，避免混淆
    if ($company_pick <= 0) {
        $company_pick = $company_id > 0 ? $company_id : 1;
    }
    foreach ($companies as $c) {
        if ((int)($c['id'] ?? 0) === (int)$company_pick) {
            $cc = trim((string)($c['code'] ?? ''));
            $cn = trim((string)($c['name'] ?? ''));
            $company_pick_label = trim($cc !== '' ? ($cc . ($cn !== '' ? ' - ' . $cn : '')) : ($cn !== '' ? $cn : ''));
            break;
        }
    }
} else {
    // boss：只能写入自己公司（session company_id）
    $company_pick = $company_id;
}
$msg = '';
$err = '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_profit_adjust (
        company_id INT UNSIGNED NOT NULL,
        legacy_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
        as_of DATE NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        PRIMARY KEY (company_id)
    )");
} catch (Throwable $e) {
    $err = '初始化失败：' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    try {
        $legacy_raw = str_replace(',', '', trim((string)($_POST['legacy_profit'] ?? '0')));
        if (!is_numeric($legacy_raw)) {
            throw new RuntimeException(__('report_legacy_profit_err_num'));
        }
        $legacy_profit = round((float)$legacy_raw, 2);
        $as_of_raw = trim((string)($_POST['as_of'] ?? ''));
        $as_of = preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_raw) ? $as_of_raw : null;
        $uid = (int)($_SESSION['user_id'] ?? 0);

        $st = $pdo->prepare("INSERT INTO company_profit_adjust (company_id, legacy_profit, as_of, updated_by)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE legacy_profit = VALUES(legacy_profit), as_of = VALUES(as_of), updated_by = VALUES(updated_by)");
        $st->execute([$company_pick, $legacy_profit, $as_of, $uid > 0 ? $uid : null]);
        $msg = __('report_legacy_profit_saved');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$legacy_profit_cur = 0.0;
$as_of_cur = '';
if (!$err) {
    try {
        $st = $pdo->prepare("SELECT legacy_profit, as_of FROM company_profit_adjust WHERE company_id = ? LIMIT 1");
        $st->execute([$company_pick]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $legacy_profit_cur = (float)($row['legacy_profit'] ?? 0);
            $as_of_cur = trim((string)($row['as_of'] ?? ''));
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('report_legacy_profit_title'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 760px;">
                <div class="page-header">
                    <h2><?= htmlspecialchars(__('report_legacy_profit_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>

                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <div class="card">
                    <p class="form-hint" style="margin-top:0;"><?= htmlspecialchars(__('report_legacy_profit_desc'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($actor_is_superadmin): ?>
                        <form method="get" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom: 14px;">
                            <div style="min-width: 260px; flex: 1;">
                                <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;"><?= htmlspecialchars(__('lbl_company'), ENT_QUOTES, 'UTF-8') ?></label>
                                <select class="form-control" name="company_id" style="width:100%;" onchange="this.form.submit()">
                                    <?php foreach ($companies as $c):
                                        $cid = (int)($c['id'] ?? 0);
                                        $cc = trim((string)($c['code'] ?? ''));
                                        $cn = trim((string)($c['name'] ?? ''));
                                        $lab = trim($cc !== '' ? ($cc . ($cn !== '' ? ' - ' . $cn : '')) : ($cn !== '' ? $cn : (string)$cid));
                                        if ($cid <= 0) continue;
                                    ?>
                                        <option value="<?= $cid ?>" <?= $cid === (int)$company_pick ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?><?= ((int)($c['is_active'] ?? 1) === 0) ? ' (Disabled)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <noscript><button class="btn btn-primary" type="submit"><?= htmlspecialchars(__('btn_search'), ENT_QUOTES, 'UTF-8') ?></button></noscript>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                        <?php if ($actor_is_superadmin): ?>
                            <input type="hidden" name="company_id" value="<?= (int)$company_pick ?>">
                        <?php endif; ?>
                        <div style="min-width: 240px; flex: 1;">
                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;"><?= htmlspecialchars(__('report_legacy_profit_amount'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input class="form-control" type="text" name="legacy_profit" inputmode="decimal" value="<?= htmlspecialchars(sprintf('%.2f', $legacy_profit_cur), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div style="min-width: 200px;">
                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;"><?= htmlspecialchars(__('report_legacy_profit_asof'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input class="form-control" type="date" name="as_of" value="<?= htmlspecialchars($as_of_cur, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <button class="btn btn-primary" type="submit"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

