<?php
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();

require_once __DIR__ . '/inc/ensure_txedit_request_orig_columns.php';
ensure_txedit_request_orig_columns($pdo);
require_once __DIR__ . '/inc/txedit_request_diff.php';

$sidebar_current = 'admin_txn_edit_audit';
$company_id = current_company_id();
$head_office_scope = is_superadmin_head_office_scope();

$status_filter = trim((string)($_GET['status'] ?? 'processed'));
if (!in_array($status_filter, ['all', 'pending', 'processed', 'approved', 'rejected'], true)) {
    $status_filter = 'processed';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if (!$head_office_scope) {
    $where[] = 'r.company_id = ?';
    $params[] = $company_id;
}
switch ($status_filter) {
    case 'pending':
        $where[] = "r.status = 'pending'";
        break;
    case 'approved':
        $where[] = "r.status = 'approved'";
        break;
    case 'rejected':
        $where[] = "r.status = 'rejected'";
        break;
    case 'all':
        break;
    default:
        $status_filter = 'processed';
        $where[] = "r.status IN ('approved','rejected')";
        break;
}
$whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
try {
    $cst = $pdo->prepare("SELECT COUNT(*) FROM transaction_edit_requests r {$whereSql}");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();
} catch (Throwable $e) {
    $total = 0;
}

$rows = [];
try {
    if ($head_office_scope) {
        $sql = "SELECT r.*, uc.username AS created_by_name, ua.username AS approved_by_name, c.code AS company_code
            FROM transaction_edit_requests r
            LEFT JOIN users uc ON uc.id = r.created_by
            LEFT JOIN users ua ON ua.id = r.approved_by
            LEFT JOIN companies c ON c.id = r.company_id
            {$whereSql}
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT r.*, uc.username AS created_by_name, ua.username AS approved_by_name
            FROM transaction_edit_requests r
            LEFT JOIN users uc ON uc.id = r.created_by
            LEFT JOIN users ua ON ua.id = r.approved_by
            {$whereSql}
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $rows = [];
}

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$htmlLang = app_lang() === 'en' ? 'en' : 'zh-CN';
$colspan = 17 + ($head_office_scope ? 1 : 0);

/** @param array<string,mixed> $r */
function txaudit_has_orig_snapshot(array $r): bool
{
    return ($r['orig_day'] ?? null) !== null && (string)($r['orig_day'] ?? '') !== '';
}

/** @param array<string,mixed> $r */
function txaudit_processor_disp(array $r): string
{
    $u = trim((string)($r['approved_by_name'] ?? ''));
    $tg = trim((string)($r['approved_by_tg'] ?? ''));
    if ($u !== '' && $tg !== '') {
        return $u . ' (' . $tg . ')';
    }
    if ($u !== '') {
        return $u;
    }
    if ($tg !== '') {
        return $tg;
    }
    return '—';
}

/**
 * @param array<string,mixed> $r
 * @return string HTML
 */
function txaudit_diff_cell(array $r, string $field): string
{
    if (!txaudit_has_orig_snapshot($r)) {
        $n = $r[$field] ?? null;
        if (in_array($field, ['amount', 'burn', 'bonus', 'total'], true)) {
            return txedit_diff_money(null, $n);
        }
        if ($field === 'time') {
            return txedit_diff_time(null, $n !== null ? (string)$n : null);
        }
        return txedit_diff_text(null, $n !== null ? (string)$n : null);
    }
    $okey = 'orig_' . $field;
    $old = $r[$okey] ?? null;
    $new = $r[$field] ?? null;
    if (in_array($field, ['amount', 'burn', 'bonus', 'total'], true)) {
        return txedit_diff_money($old, $new);
    }
    if ($field === 'time') {
        return txedit_diff_time($old !== null ? (string)$old : null, $new !== null ? (string)$new : null);
    }
    return txedit_diff_text($old !== null ? (string)$old : null, $new !== null ? (string)$new : null);
}

function txaudit_filter_url(string $st, int $p = 1): string
{
    $q = ['status' => $st, 'page' => $p];
    return 'admin_txn_edit_audit.php?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="<?= txedit_h($htmlLang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= txedit_h(__('txaudit_page_title')) ?> — <?= defined('SITE_TITLE') ? txedit_h((string)SITE_TITLE) : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .txaudit-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; align-items: center; }
        .txaudit-filters a { font-size: 14px; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border, #cbd5e1); text-decoration: none; color: var(--text, #334155); }
        .txaudit-filters a.is-active { background: var(--primary, #2563eb); color: #fff; border-color: transparent; }
        .txaudit-note { font-size: 13px; color: var(--muted, #64748b); margin-bottom: 12px; }
        .txaudit-pager { margin-top: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1400px;">
            <div class="page-header">
                <h2><?= txedit_h(__('txaudit_page_title')) ?></h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>
            <p class="txaudit-note"><?= txedit_h(__('txaudit_intro_note')) ?></p>
            <div class="txaudit-filters" role="tablist" aria-label="<?= txedit_h(__('txaudit_filter_aria')) ?>">
                <?php
                $filters = [
                    'processed' => __('txaudit_filter_processed'),
                    'all' => __('txaudit_filter_all'),
                    'pending' => __('txaudit_filter_pending'),
                    'approved' => __('txaudit_filter_approved'),
                    'rejected' => __('txaudit_filter_rejected'),
                ];
                foreach ($filters as $st => $lab):
                ?>
                <a role="tab" class="<?= $status_filter === $st ? 'is-active' : '' ?>" href="<?= txedit_h(txaudit_filter_url($st, 1)) ?>"><?= txedit_h($lab) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="card" style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= txedit_h(__('txaudit_col_id')) ?></th>
                            <?php if ($head_office_scope): ?><th><?= txedit_h(__('txedit_col_branch')) ?></th><?php endif; ?>
                            <th><?= txedit_h(__('txaudit_col_txn')) ?></th>
                            <th><?= txedit_h(__('txedit_col_created_by')) ?></th>
                            <th><?= txedit_h(__('txaudit_col_created_at')) ?></th>
                            <th><?= txedit_h(__('txaudit_col_status')) ?></th>
                            <th><?= txedit_h(__('txaudit_col_processor')) ?></th>
                            <th><?= txedit_h(__('txaudit_col_processed_at')) ?></th>
                            <th><?= txedit_h(__('txedit_col_date')) ?></th>
                            <th><?= txedit_h(__('txedit_col_time')) ?></th>
                            <th><?= txedit_h(__('txedit_col_mode')) ?></th>
                            <th><?= txedit_h(__('txedit_col_code')) ?></th>
                            <th><?= txedit_h(__('txedit_col_bank')) ?></th>
                            <th><?= txedit_h(__('txedit_col_product')) ?></th>
                            <th class="num"><?= txedit_h(__('txedit_col_amount')) ?></th>
                            <th class="num"><?= txedit_h(__('txedit_col_burn')) ?></th>
                            <th class="num"><?= txedit_h(__('txedit_col_bonus')) ?></th>
                            <th><?= txedit_h(__('txedit_col_remark')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <?php if ($head_office_scope): ?><td><?= txedit_h((string)($r['company_code'] ?? '')) ?></td><?php endif; ?>
                            <td><a href="transaction_edit.php?id=<?= (int)$r['transaction_id'] ?>&amp;return_to=<?= urlencode('admin_txn_edit_audit.php') ?>">#<?= (int)$r['transaction_id'] ?></a></td>
                            <td><?= txedit_h((string)($r['created_by_name'] ?? '')) ?></td>
                            <td><?= txedit_h((string)($r['created_at'] ?? '')) ?></td>
                            <td><?php
                                $st = (string)($r['status'] ?? '');
                            echo txedit_h($st === 'pending' ? __('txaudit_status_pending') : ($st === 'approved' ? __('txaudit_status_approved') : ($st === 'rejected' ? __('txaudit_status_rejected') : $st)));
                            ?></td>
                            <td><?= txedit_h(txaudit_processor_disp($r)) ?></td>
                            <td><?= txedit_h((string)($r['approved_at'] ?? '') ?: '—') ?></td>
                            <td><?= txaudit_diff_cell($r, 'day') ?></td>
                            <td><?= txaudit_diff_cell($r, 'time') ?></td>
                            <td><?= txaudit_diff_cell($r, 'mode') ?></td>
                            <td><?= txaudit_diff_cell($r, 'code') ?></td>
                            <td><?= txaudit_diff_cell($r, 'bank') ?></td>
                            <td><?= txaudit_diff_cell($r, 'product') ?></td>
                            <td class="num"><?= txaudit_diff_cell($r, 'amount') ?></td>
                            <td class="num"><?= txaudit_diff_cell($r, 'burn') ?></td>
                            <td class="num"><?= txaudit_diff_cell($r, 'bonus') ?></td>
                            <td><?= txaudit_diff_cell($r, 'remark') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= (int)$colspan ?>" style="color:var(--muted); padding:18px;"><?= txedit_h(__('txaudit_empty')) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="txaudit-pager">
                <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-outline" href="<?= txedit_h(txaudit_filter_url($status_filter, $page - 1)) ?>"><?= txedit_h(__('txaudit_prev')) ?></a>
                <?php endif; ?>
                <span><?= txedit_h(__f('txaudit_page_of', $page, $totalPages, (int)$total)) ?></span>
                <?php if ($page < $totalPages): ?>
                <a class="btn btn-sm btn-outline" href="<?= txedit_h(txaudit_filter_url($status_filter, $page + 1)) ?>"><?= txedit_h(__('txaudit_next')) ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
