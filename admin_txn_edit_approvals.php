<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_txn_edit_approvals';

$company_id = current_company_id();
$head_office_scope = is_superadmin_head_office_scope();
if (function_exists('shard_refresh_business_pdo')) {
    shard_refresh_business_pdo();
}

$msg = '';
$err = '';

// ensure table exists (still recommend migrate)
try { $pdo->query("SELECT 1 FROM transaction_edit_requests LIMIT 1"); } catch (Throwable $e) {}
require_once __DIR__ . '/inc/ensure_txedit_request_orig_columns.php';
ensure_txedit_request_orig_columns($pdo);
if (!$head_office_scope && $company_id > 0 && function_exists('pdo_data_for_company_id')) {
    $pdEnsure = pdo_data_for_company_id($pdo, $company_id);
    if ($pdEnsure !== $pdo) {
        ensure_txedit_request_orig_columns($pdo, $pdEnsure);
    }
}
require_once __DIR__ . '/inc/txedit_request_diff.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $cat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
            $cat->beginTransaction();
            $st = $cat->prepare("SELECT * FROM transaction_edit_requests WHERE id = ? FOR UPDATE");
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

            $pdoData = function_exists('pdo_data_for_company_id') ? pdo_data_for_company_id($cat, $req_company_id) : $cat;
            $split = ($pdoData !== $cat);

            if ($action === 'approve') {
                $txId = (int)($req['transaction_id'] ?? 0);
                if ($txId <= 0) {
                    throw new RuntimeException(__('txedit_err_bad_txn'));
                }
                $beforeSnap = null;
                if ($split) {
                    $hasBurnCol = true;
                    try {
                        $pdoData->query('SELECT burn FROM transactions LIMIT 0');
                    } catch (Throwable $e) {
                        $hasBurnCol = false;
                    }
                    $selCols = $hasBurnCol
                        ? 'day, time, mode, code, bank, product, amount, burn, bonus, total, remark'
                        : 'day, time, mode, code, bank, product, amount, bonus, total, remark';
                    $stCur = $pdoData->prepare("SELECT {$selCols} FROM transactions WHERE id = ? AND company_id = ? LIMIT 1");
                    $stCur->execute([$txId, $req_company_id]);
                    $beforeSnap = $stCur->fetch(PDO::FETCH_ASSOC);
                    if (!$beforeSnap) {
                        throw new RuntimeException(__('txedit_err_bad_txn'));
                    }
                }

                $upd = $pdoData->prepare("UPDATE transactions
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

                $stUpdReq = $cat->prepare("UPDATE transaction_edit_requests
                    SET status='approved', approved_by=?, approved_at=?
                    WHERE id=? AND status='pending'");
                $stUpdReq->execute([$approver_id, $now, $rid]);
                if ($stUpdReq->rowCount() < 1) {
                    if ($split && is_array($beforeSnap)) {
                        $hasBurnB = array_key_exists('burn', $beforeSnap);
                        if ($hasBurnB) {
                            $rev = $pdoData->prepare('UPDATE transactions SET day=?, time=?, mode=?, code=?, bank=?, product=?, amount=?, burn=?, bonus=?, total=?, remark=? WHERE id=? AND company_id=?');
                            $rev->execute([
                                (string)$beforeSnap['day'],
                                (string)$beforeSnap['time'],
                                (string)$beforeSnap['mode'],
                                $beforeSnap['code'],
                                $beforeSnap['bank'],
                                $beforeSnap['product'],
                                (float)$beforeSnap['amount'],
                                $beforeSnap['burn'] !== null && $beforeSnap['burn'] !== '' ? (float)$beforeSnap['burn'] : null,
                                (float)$beforeSnap['bonus'],
                                (float)$beforeSnap['total'],
                                $beforeSnap['remark'],
                                $txId,
                                $req_company_id,
                            ]);
                        } else {
                            $rev = $pdoData->prepare('UPDATE transactions SET day=?, time=?, mode=?, code=?, bank=?, product=?, amount=?, bonus=?, total=?, remark=? WHERE id=? AND company_id=?');
                            $rev->execute([
                                (string)$beforeSnap['day'],
                                (string)$beforeSnap['time'],
                                (string)$beforeSnap['mode'],
                                $beforeSnap['code'],
                                $beforeSnap['bank'],
                                $beforeSnap['product'],
                                (float)$beforeSnap['amount'],
                                (float)$beforeSnap['bonus'],
                                (float)$beforeSnap['total'],
                                $beforeSnap['remark'],
                                $txId,
                                $req_company_id,
                            ]);
                        }
                    }
                    throw new RuntimeException(__('txedit_err_already_processed'));
                }
                $msg = __('txedit_msg_approved');
            } else {
                $cat->prepare("UPDATE transaction_edit_requests
                    SET status='rejected', approved_by=?, approved_at=?
                    WHERE id=?")->execute([$approver_id, $now, $rid]);
                $msg = __('txedit_msg_rejected');
            }
            $cat->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $err = $e->getMessage();
        }
    }
}

$rows = [];
$txJoinCols = "COALESCE(r.orig_day, t.day) AS orig_day, COALESCE(r.orig_time, t.time) AS orig_time, COALESCE(r.orig_mode, t.mode) AS orig_mode, COALESCE(r.orig_code, t.code) AS orig_code,
                COALESCE(r.orig_bank, t.bank) AS orig_bank, COALESCE(r.orig_product, t.product) AS orig_product, COALESCE(r.orig_amount, t.amount) AS orig_amount,
                COALESCE(r.orig_burn, t.burn) AS orig_burn, COALESCE(r.orig_bonus, t.bonus) AS orig_bonus, COALESCE(r.orig_total, t.total) AS orig_total, COALESCE(r.orig_remark, t.remark) AS orig_remark";
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
        $pdoDataList = function_exists('pdo_data_for_company_id') ? pdo_data_for_company_id($pdo, $company_id) : $pdo;
        if ($pdoDataList === $pdo) {
            $sql = "SELECT r.*, u.username AS created_by_name, {$txJoinCols}
                    FROM transaction_edit_requests r
                    LEFT JOIN users u ON u.id = r.created_by
                    LEFT JOIN transactions t ON t.id = r.transaction_id AND t.company_id = r.company_id
                    WHERE r.status = 'pending' AND r.company_id = ?
                    ORDER BY r.created_at ASC, r.id ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$company_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT r.*, u.username AS created_by_name
                    FROM transaction_edit_requests r
                    LEFT JOIN users u ON u.id = r.created_by
                    WHERE r.status = 'pending' AND r.company_id = ?
                    ORDER BY r.created_at ASC, r.id ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$company_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            foreach ($rows as $rw) {
                $tid = (int)($rw['transaction_id'] ?? 0);
                if ($tid > 0) {
                    $ids[$tid] = true;
                }
            }
            $idList = array_keys($ids);
            $byT = [];
            if ($idList !== []) {
                $ph = implode(',', array_fill(0, count($idList), '?'));
                $hasBurn = true;
                try {
                    $pdoDataList->query('SELECT burn FROM transactions LIMIT 0');
                } catch (Throwable $e) {
                    $hasBurn = false;
                }
                $bSel = $hasBurn ? ', burn' : '';
                $stT = $pdoDataList->prepare("SELECT id, day, time, mode, code, bank, product, amount, bonus, total, remark{$bSel}
                    FROM transactions WHERE company_id = ? AND id IN ($ph)");
                $stT->execute(array_merge([$company_id], $idList));
                while ($t = $stT->fetch(PDO::FETCH_ASSOC)) {
                    $byT[(int)$t['id']] = $t;
                }
            }
            foreach ($rows as &$r) {
                $tid = (int)($r['transaction_id'] ?? 0);
                $t = $byT[$tid] ?? null;
                if (!$t) {
                    continue;
                }
                $pairs = [
                    ['orig_day', 'day'], ['orig_time', 'time'], ['orig_mode', 'mode'], ['orig_code', 'code'],
                    ['orig_bank', 'bank'], ['orig_product', 'product'], ['orig_amount', 'amount'],
                    ['orig_burn', 'burn'], ['orig_bonus', 'bonus'], ['orig_total', 'total'], ['orig_remark', 'remark'],
                ];
                foreach ($pairs as [$ok, $tk]) {
                    $ov = $r[$ok] ?? null;
                    if ($ov !== null && (string)$ov !== '') {
                        continue;
                    }
                    $r[$ok] = $t[$tk] ?? null;
                }
            }
            unset($r);
        }
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

$htmlLang = app_lang() === 'en' ? 'en' : 'zh-CN';
$txeditColCount = 14 + ($head_office_scope ? 1 : 0);
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
                <?php if (in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true)): ?>
                <p class="breadcrumb" style="margin-top:4px;"><a href="admin_txn_edit_audit.php"><?= txedit_h(__('txedit_link_audit_log')) ?></a></p>
                <?php endif; ?>
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

