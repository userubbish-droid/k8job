<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_txn_edit_approvals';

$company_id = current_company_id();
$head_office_scope = is_superadmin_head_office_scope();

$msg = '';
$err = '';

// ensure table exists (still recommend migrate)
try { $pdo->query("SELECT 1 FROM transaction_edit_requests LIMIT 1"); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM transaction_edit_requests WHERE id = ? FOR UPDATE");
            $st->execute([$rid]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                throw new RuntimeException(__('txedit_err_not_found'));
            }
            if (($req['status'] ?? '') !== 'pending') {
                throw new RuntimeException(__('txedit_err_already_processed'));
            }
            $req_company_id = (int)($req['company_id'] ?? 0);
            if (!$head_office_scope && $req_company_id !== $company_id) {
                throw new RuntimeException(__('txedit_err_forbidden'));
            }

            $now = date('Y-m-d H:i:s');
            $approver_id = (int)($_SESSION['user_id'] ?? 0);

            if ($action === 'approve') {
                // apply changes to original transaction
                $txId = (int)($req['transaction_id'] ?? 0);
                if ($txId <= 0) {
                    throw new RuntimeException(__('txedit_err_bad_txn'));
                }
                $upd = $pdo->prepare("UPDATE transactions
                    SET day = ?, time = ?, mode = ?, code = ?, bank = ?, product = ?, amount = ?, burn = ?, bonus = ?, total = ?, remark = ?
                    WHERE id = ? AND company_id = ?");
                $upd->execute([
                    (string)$req['day'],
                    (string)$req['time'],
                    (string)$req['mode'],
                    $req['code'],
                    $req['bank'],
                    $req['product'],
                    (float)$req['amount'],
                    $req['burn'] !== null && $req['burn'] !== '' ? (float)$req['burn'] : null,
                    (float)$req['bonus'],
                    (float)$req['total'],
                    $req['remark'],
                    $txId,
                    $req_company_id,
                ]);
                $pdo->prepare("UPDATE transaction_edit_requests
                    SET status='approved', approved_by=?, approved_at=?
                    WHERE id=?")->execute([$approver_id, $now, $rid]);
                $msg = __('txedit_msg_approved');
            } else {
                $pdo->prepare("UPDATE transaction_edit_requests
                    SET status='rejected', approved_by=?, approved_at=?
                    WHERE id=?")->execute([$approver_id, $now, $rid]);
                $msg = __('txedit_msg_rejected');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

$rows = [];
$txJoinCols = "t.day AS orig_day, t.time AS orig_time, t.mode AS orig_mode, t.code AS orig_code,
                t.bank AS orig_bank, t.product AS orig_product, t.amount AS orig_amount,
                t.burn AS orig_burn, t.bonus AS orig_bonus, t.total AS orig_total, t.remark AS orig_remark";
try {
    if ($head_office_scope) {
        $sql = "SELECT r.*, u.username AS created_by_name, c.code AS company_code, {$txJoinCols}
                FROM transaction_edit_requests r
                LEFT JOIN users u ON u.id = r.created_by
                LEFT JOIN companies c ON c.id = r.company_id
                LEFT JOIN transactions t ON t.id = r.transaction_id AND t.company_id = r.company_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at ASC, r.id ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT r.*, u.username AS created_by_name, {$txJoinCols}
                FROM transaction_edit_requests r
                LEFT JOIN users u ON u.id = r.created_by
                LEFT JOIN transactions t ON t.id = r.transaction_id AND t.company_id = r.company_id
                WHERE r.status = 'pending' AND r.company_id = ?
                ORDER BY r.created_at ASC, r.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$company_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

$htmlLang = app_lang() === 'en' ? 'en' : 'zh-CN';
$txeditColCount = 14 + ($head_office_scope ? 1 : 0);

/** @return string HTML */
function txedit_h(?string $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function txedit_time_disp(?string $t): string
{
    if ($t === null || $t === '') {
        return '';
    }
    return substr(trim((string)$t), 0, 8);
}

/** @param mixed $v */
function txedit_money_disp($v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float)$v, 2);
}

/** @param mixed $a @param mixed $b */
function txedit_money_eq($a, $b): bool
{
    $fa = ($a === null || $a === '') ? null : round((float)$a, 2);
    $fb = ($b === null || $b === '') ? null : round((float)$b, 2);
    if ($fa === null && $fb === null) {
        return true;
    }
    if ($fa === null || $fb === null) {
        return false;
    }
    return abs($fa - $fb) < 0.0001;
}

/** @return string HTML */
function txedit_diff_text(?string $old, ?string $new): string
{
    $o = trim((string)($old ?? ''));
    $n = trim((string)($new ?? ''));
    if ($o === $n) {
        $one = $n === '' ? '—' : txedit_h($n);
        return $one;
    }
    $os = $o === '' ? '—' : txedit_h($o);
    $ns = $n === '' ? '—' : txedit_h($n);
    return $os . ' <span class="muted">→</span> ' . $ns;
}

/** @return string HTML */
function txedit_diff_time(?string $old, ?string $new): string
{
    return txedit_diff_text(txedit_time_disp($old), txedit_time_disp($new));
}

/** @param mixed $old @param mixed $new @return string HTML */
function txedit_diff_money($old, $new): string
{
    if (txedit_money_eq($old, $new)) {
        return txedit_h(txedit_money_disp($new));
    }
    return txedit_h(txedit_money_disp($old)) . ' <span class="muted">→</span> ' . txedit_h(txedit_money_disp($new));
}
?>
<!doctype html>
<html lang="<?= txedit_h($htmlLang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= txedit_h(__('txedit_page_title')) ?> — <?= defined('SITE_TITLE') ? txedit_h((string)SITE_TITLE) : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1200px;">
            <div class="page-header">
                <h2><?= txedit_h(__('txedit_page_title')) ?></h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= txedit_h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= txedit_h($err) ?></div><?php endif; ?>

            <div class="card" style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= txedit_h(__('txedit_col_id')) ?></th>
                            <?php if ($head_office_scope): ?><th><?= txedit_h(__('txedit_col_branch')) ?></th><?php endif; ?>
                            <th><?= txedit_h(__('txedit_col_created_by')) ?></th>
                            <th><?= txedit_h(__('txedit_col_transaction')) ?></th>
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
                            <th><?= txedit_h(__('txedit_col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <?php if ($head_office_scope): ?><td><?= txedit_h((string)($r['company_code'] ?? '')) ?></td><?php endif; ?>
                            <td><?= txedit_h((string)($r['created_by_name'] ?? '')) ?></td>
                            <td><a href="transaction_edit.php?id=<?= (int)$r['transaction_id'] ?>&return_to=<?= urlencode('admin_txn_edit_approvals.php') ?>">#<?= (int)$r['transaction_id'] ?></a></td>
                            <td><?= txedit_diff_text($r['orig_day'] ?? null, $r['day'] ?? null) ?></td>
                            <td><?= txedit_diff_time($r['orig_time'] ?? null, $r['time'] ?? null) ?></td>
                            <td><?= txedit_diff_text($r['orig_mode'] ?? null, $r['mode'] ?? null) ?></td>
                            <td><?= txedit_diff_text($r['orig_code'] ?? null, $r['code'] ?? null) ?></td>
                            <td><?= txedit_diff_text($r['orig_bank'] ?? null, $r['bank'] ?? null) ?></td>
                            <td><?= txedit_diff_text($r['orig_product'] ?? null, $r['product'] ?? null) ?></td>
                            <td class="num"><?= txedit_diff_money($r['orig_amount'] ?? null, $r['amount'] ?? null) ?></td>
                            <td class="num"><?= txedit_diff_money($r['orig_burn'] ?? null, $r['burn'] ?? null) ?></td>
                            <td class="num"><?= txedit_diff_money($r['orig_bonus'] ?? null, $r['bonus'] ?? null) ?></td>
                            <td><?= txedit_diff_text($r['orig_remark'] ?? null, $r['remark'] ?? null) ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-primary" type="submit"><?= txedit_h(__('txedit_btn_approve')) ?></button>
                                </form>
                                <form method="post" style="display:inline;" data-confirm="<?= txedit_h(__('txedit_confirm_reject')) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button class="btn btn-sm btn-outline" type="submit"><?= txedit_h(__('txedit_btn_reject')) ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= (int)$txeditColCount ?>" style="color:var(--muted); padding:18px;"><?= txedit_h(__('txedit_empty')) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>

