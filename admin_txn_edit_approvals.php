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
                throw new RuntimeException('Request not found');
            }
            if (($req['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Already processed');
            }
            $req_company_id = (int)($req['company_id'] ?? 0);
            if (!$head_office_scope && $req_company_id !== $company_id) {
                throw new RuntimeException('Forbidden');
            }

            $now = date('Y-m-d H:i:s');
            $approver_id = (int)($_SESSION['user_id'] ?? 0);

            if ($action === 'approve') {
                // apply changes to original transaction
                $txId = (int)($req['transaction_id'] ?? 0);
                if ($txId <= 0) {
                    throw new RuntimeException('Bad transaction id');
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
                $msg = '已批准。';
            } else {
                $pdo->prepare("UPDATE transaction_edit_requests
                    SET status='rejected', approved_by=?, approved_at=?
                    WHERE id=?")->execute([$approver_id, $now, $rid]);
                $msg = '已拒绝。';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

$rows = [];
try {
    if ($head_office_scope) {
        $sql = "SELECT r.*, u.username AS created_by_name, c.code AS company_code
                FROM transaction_edit_requests r
                LEFT JOIN users u ON u.id = r.created_by
                LEFT JOIN companies c ON c.id = r.company_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at ASC, r.id ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT r.*, u.username AS created_by_name
                FROM transaction_edit_requests r
                LEFT JOIN users u ON u.id = r.created_by
                WHERE r.status = 'pending' AND r.company_id = ?
                ORDER BY r.created_at ASC, r.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$company_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流水修改待批准 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 1200px;">
            <div class="page-header">
                <h2>流水修改待批准</h2>
                <p class="breadcrumb"><a href="dashboard.php">首页</a></p>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="card" style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <?php if ($head_office_scope): ?><th>分公司</th><?php endif; ?>
                            <th>创建人</th>
                            <th>原流水</th>
                            <th>新日期</th>
                            <th>新时间</th>
                            <th>新模式</th>
                            <th>新代码</th>
                            <th>新银行</th>
                            <th>新产品</th>
                            <th class="num">新金额</th>
                            <th class="num">Burn</th>
                            <th class="num">Bonus</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <?php if ($head_office_scope): ?><td><?= htmlspecialchars((string)($r['company_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string)($r['created_by_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a href="transaction_edit.php?id=<?= (int)$r['transaction_id'] ?>&return_to=<?= urlencode('admin_txn_edit_approvals.php') ?>">#<?= (int)$r['transaction_id'] ?></a></td>
                            <td><?= htmlspecialchars((string)$r['day'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(substr((string)$r['time'], 0, 8), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$r['mode'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($r['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($r['bank'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($r['product'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
                            <td class="num"><?= number_format((float)($r['burn'] ?? 0), 2) ?></td>
                            <td class="num"><?= number_format((float)$r['bonus'], 2) ?></td>
                            <td><?= htmlspecialchars((string)($r['remark'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-primary" type="submit">通过</button>
                                </form>
                                <form method="post" style="display:inline;" data-confirm="确定拒绝？">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button class="btn btn-sm btn-outline" type="submit">拒绝</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $head_office_scope ? 15 : 14 ?>" style="color:var(--muted); padding:18px;">暂无</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>

