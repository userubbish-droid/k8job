<?php
require 'config.php';
require 'auth.php';
require_superadmin();

$sidebar_current = 'admin_customer_export_log';

function export_log_role_label(string $role): string {
    if ($role === 'superadmin') {
        return __('role_bb');
    }
    if ($role === 'boss') {
        return __('role_boss');
    }
    if ($role === 'admin') {
        return __('role_admin');
    }
    if ($role === 'agent') {
        return __('role_agent');
    }
    if ($role === 'member') {
        return __('role_staff');
    }
    return $role === '' ? '—' : $role;
}

$rows = [];
$log_err = '';
try {
    $stmt = $pdo->query(
        'SELECT l.id, l.company_id, l.user_id, l.username, l.user_role, l.ip, l.created_at,
                COALESCE(c.code, \'\') AS company_code, COALESCE(c.name, \'\') AS company_name
         FROM customer_export_log l
         LEFT JOIN companies c ON c.id = l.company_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 500'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $log_err = __('cust_export_log_err_migrate');
}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('cust_export_log_title'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <h2><?= htmlspecialchars(__('cust_export_log_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>
                <?php if ($log_err): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($log_err, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="card" style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('cust_export_log_col_time'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('cust_export_log_col_user'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('cust_export_log_col_role'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('cust_export_log_col_company'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('cust_export_log_col_ip'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $cid = (int)($r['company_id'] ?? 0);
                                if ($cid === 0) {
                                    $co = __('company_hq');
                                } else {
                                    $cc = trim((string)($r['company_code'] ?? ''));
                                    $cn = trim((string)($r['company_name'] ?? ''));
                                    $co = $cc !== '' || $cn !== '' ? trim($cc . ($cc !== '' && $cn !== '' ? ' — ' : '') . $cn) : ('#' . $cid);
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($r['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(export_log_role_label((string)($r['user_role'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($co, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($r['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$log_err && !$rows): ?>
                                <tr><td colspan="5" style="color:var(--muted); padding:24px;"><?= htmlspecialchars(__('cust_export_log_empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
