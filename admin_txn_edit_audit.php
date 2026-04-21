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
$colspan = 8 + ($head_office_scope ? 1 : 0);

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

function txaudit_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** @param mixed $val */
function txaudit_fmt_field(string $field, $val): string
{
    if (in_array($field, ['amount', 'burn', 'bonus', 'total'], true)) {
        if ($val === null || $val === '') {
            return '—';
        }
        return number_format((float)$val, 2);
    }
    if ($field === 'time') {
        $d = txedit_time_disp($val !== null ? (string)$val : null);
        return $d === '' ? '—' : $d;
    }
    $t = trim((string)($val ?? ''));
    return $t === '' ? '—' : $t;
}

/** @param mixed $a @param mixed $b */
function txaudit_equal(string $field, $a, $b): bool
{
    if (in_array($field, ['amount', 'burn', 'bonus', 'total'], true)) {
        return txedit_money_eq($a, $b);
    }
    if ($field === 'time') {
        return txedit_time_disp($a !== null ? (string)$a : null) === txedit_time_disp($b !== null ? (string)$b : null);
    }
    return trim((string)($a ?? '')) === trim((string)($b ?? ''));
}

/**
 * @param array<string,mixed> $r
 * @return array{legacy: bool, items: list<array{label: string, before: string, after: string}>}
 */
function txaudit_collect_changes(array $r): array
{
    $fieldLabels = [
        'day' => __('txaudit_f_day'),
        'time' => __('txaudit_f_time'),
        'mode' => __('txaudit_f_mode'),
        'code' => __('txaudit_f_code'),
        'bank' => __('txaudit_f_bank'),
        'product' => __('txaudit_f_product'),
        'amount' => __('txaudit_f_amount'),
        'burn' => __('txaudit_f_burn'),
        'bonus' => __('txaudit_f_bonus'),
        'total' => __('txaudit_f_total'),
        'remark' => __('txaudit_f_remark'),
    ];
    if (!txaudit_has_orig_snapshot($r)) {
        return ['legacy' => true, 'items' => []];
    }
    $items = [];
    foreach ($fieldLabels as $f => $lab) {
        $old = $r['orig_' . $f] ?? null;
        $new = $r[$f] ?? null;
        if (!txaudit_equal($f, $old, $new)) {
            $items[] = [
                'label' => $lab,
                'before' => txaudit_fmt_field($f, $old),
                'after' => txaudit_fmt_field($f, $new),
            ];
        }
    }
    return ['legacy' => false, 'items' => $items];
}

/**
 * @param array<string,mixed> $r
 * @return list<array{label: string, value: string}>
 */
function txaudit_legacy_requested_lines(array $r): array
{
    $order = ['day', 'time', 'mode', 'code', 'bank', 'product', 'amount', 'burn', 'bonus', 'total', 'remark'];
    $labels = [
        'day' => __('txaudit_f_day'),
        'time' => __('txaudit_f_time'),
        'mode' => __('txaudit_f_mode'),
        'code' => __('txaudit_f_code'),
        'bank' => __('txaudit_f_bank'),
        'product' => __('txaudit_f_product'),
        'amount' => __('txaudit_f_amount'),
        'burn' => __('txaudit_f_burn'),
        'bonus' => __('txaudit_f_bonus'),
        'total' => __('txaudit_f_total'),
        'remark' => __('txaudit_f_remark'),
    ];
    $out = [];
    foreach ($order as $f) {
        $v = txaudit_fmt_field($f, $r[$f] ?? null);
        if ($v === '—') {
            continue;
        }
        $out[] = ['label' => $labels[$f], 'value' => $f === 'remark' ? trim((string)($r['remark'] ?? '')) : $v];
    }
    return $out;
}

/**
 * @param array<string,mixed> $r
 * @return string HTML
 */
function txaudit_render_change_block(array $r): string
{
    $ch = txaudit_collect_changes($r);
    if ($ch['legacy']) {
        $lines = txaudit_legacy_requested_lines($r);
        $html = '<div class="txaudit-box txaudit-box--legacy">';
        $html .= '<p class="txaudit-legacy-hint">' . txaudit_esc(__('txaudit_legacy_hint')) . '</p>';
        if ($lines === []) {
            $html .= '<p class="txaudit-muted">—</p>';
        } else {
            $html .= '<ul class="txaudit-kvlist">';
            foreach ($lines as $ln) {
                $val = $ln['value'];
                $valHtml = $val !== '' && strpos($val, "\n") !== false
                    ? nl2br(txaudit_esc($val))
                    : txaudit_esc($val);
                $html .= '<li><span class="txaudit-k">' . txaudit_esc($ln['label']) . '</span> ';
                $html .= '<span class="txaudit-v">' . $valHtml . '</span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        return $html;
    }
    if ($ch['items'] === []) {
        return '<span class="txaudit-muted">' . txaudit_esc(__('txaudit_no_field_change')) . '</span>';
    }
    $html = '<ul class="txaudit-changes">';
    foreach ($ch['items'] as $it) {
        $html .= '<li>';
        $html .= '<span class="txaudit-lbl">' . txaudit_esc($it['label']) . '</span> ';
        $html .= '<span class="txaudit-old">' . txaudit_esc($it['before']) . '</span> ';
        $html .= '<span class="txaudit-arr">→</span> ';
        $html .= '<span class="txaudit-new">' . txaudit_esc($it['after']) . '</span>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
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
        .txaudit-note { font-size: 13px; color: var(--muted, #64748b); margin-bottom: 12px; max-width: 52rem; line-height: 1.55; }
        .txaudit-pager { margin-top: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; }
        .txaudit-muted { color: #64748b; font-size: 13px; }
        .txaudit-pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
            letter-spacing: 0.02em;
        }
        .txaudit-pill--pending { background: #fef3c7; color: #92400e; }
        .txaudit-pill--approved { background: #dcfce7; color: #166534; }
        .txaudit-pill--rejected { background: #fee2e2; color: #991b1b; }
        .txaudit-change-cell { min-width: 220px; max-width: 420px; vertical-align: top; }
        .txaudit-changes { list-style: none; margin: 0; padding: 0; font-size: 13px; line-height: 1.55; }
        .txaudit-changes li { margin: 8px 0; padding-bottom: 6px; border-bottom: 1px dashed rgba(148, 163, 184, 0.45); }
        .txaudit-changes li:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
        .txaudit-lbl { font-weight: 700; color: #475569; margin-right: 6px; }
        .txaudit-old { color: #9a3412; font-weight: 500; }
        .txaudit-arr { color: #94a3b8; margin: 0 4px; font-weight: 600; }
        .txaudit-new { color: #166534; font-weight: 700; }
        .txaudit-box--legacy { font-size: 13px; line-height: 1.5; }
        .txaudit-legacy-hint { margin: 0 0 10px; padding: 8px 10px; background: #f8fafc; border-radius: 8px; color: #475569; border: 1px solid #e2e8f0; }
        .txaudit-kvlist { list-style: none; margin: 0; padding: 0; }
        .txaudit-kvlist li { margin: 5px 0; display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: baseline; }
        .txaudit-k { flex: 0 0 auto; min-width: 4.5em; font-weight: 600; color: #64748b; }
        .txaudit-v { color: #0f172a; word-break: break-word; }
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
                            <th><?= txedit_h(__('txaudit_col_changes')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <?php $stRaw = (string)($r['status'] ?? ''); ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <?php if ($head_office_scope): ?><td><?= txedit_h((string)($r['company_code'] ?? '')) ?></td><?php endif; ?>
                            <td><a href="transaction_edit.php?id=<?= (int)$r['transaction_id'] ?>&amp;return_to=<?= urlencode('admin_txn_edit_audit.php') ?>">#<?= (int)$r['transaction_id'] ?></a></td>
                            <td><?= txedit_h((string)($r['created_by_name'] ?? '')) ?></td>
                            <td><?= txedit_h((string)($r['created_at'] ?? '')) ?></td>
                            <td><?php
                                $stLab = $stRaw === 'pending' ? __('txaudit_status_pending') : ($stRaw === 'approved' ? __('txaudit_status_approved') : ($stRaw === 'rejected' ? __('txaudit_status_rejected') : $stRaw));
                            $pillClass = $stRaw === 'pending' ? 'pending' : ($stRaw === 'approved' ? 'approved' : ($stRaw === 'rejected' ? 'rejected' : 'pending'));
                            echo '<span class="txaudit-pill txaudit-pill--' . txaudit_esc($pillClass) . '">' . txaudit_esc($stLab) . '</span>';
                            ?></td>
                            <td><?= txedit_h(txaudit_processor_disp($r)) ?></td>
                            <td><?= txedit_h((string)($r['approved_at'] ?? '') ?: '—') ?></td>
                            <td class="txaudit-change-cell"><?= txaudit_render_change_block($r) ?></td>
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
