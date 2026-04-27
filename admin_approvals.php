<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_approvals';

$company_id = current_company_id();
$head_office_scope = is_superadmin_head_office_scope();
if (function_exists('shard_refresh_business_pdo')) {
    shard_refresh_business_pdo();
}
$pdoBiz = function_exists('pdo_business') ? pdo_business() : $pdo;

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            if ($action === 'approve') {
                if ($head_office_scope) {
                    $stmt = $pdoBiz->prepare("UPDATE transactions SET status='approved', approved_by=?, approved_at=? WHERE id=? AND status='pending'");
                    $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $id]);
                } else {
                    $stmt = $pdoBiz->prepare("UPDATE transactions SET status='approved', approved_by=?, approved_at=? WHERE id=? AND company_id=? AND status='pending'");
                    $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $id, $company_id]);
                }
                $msg = '已批准。';
            } elseif ($action === 'reject') {
                if ($head_office_scope) {
                    $stmt = $pdoBiz->prepare("UPDATE transactions SET status='rejected', approved_by=?, approved_at=? WHERE id=? AND status='pending'");
                    $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $id]);
                } else {
                    $stmt = $pdoBiz->prepare("UPDATE transactions SET status='rejected', approved_by=?, approved_at=? WHERE id=? AND company_id=? AND status='pending'");
                    $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $id, $company_id]);
                }
                $msg = '已拒绝。';
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$rows = [];
try {
    if ($head_office_scope) {
        $sql = "SELECT t.id, t.company_id, COALESCE(c.code, '') AS company_code, t.day, t.time, t.mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, t.staff, t.remark,
                       t.created_at, u.username AS created_by_name
                FROM transactions t
                LEFT JOIN users u ON u.id = t.created_by
                LEFT JOIN companies c ON c.id = t.company_id
                WHERE t.status = 'pending'
                ORDER BY t.created_at ASC";
        $rows = $pdoBiz->query($sql)->fetchAll();
    } else {
        $sql = "SELECT t.id, t.day, t.time, t.mode, t.code, t.bank, t.product, t.amount, t.bonus, t.total, t.staff, t.remark,
                       t.created_at, t.created_by
                FROM transactions t
                WHERE t.status = 'pending' AND t.company_id = ?
                ORDER BY t.created_at ASC";
        $st = $pdoBiz->prepare($sql);
        $st->execute([$company_id]);
        $rows = $st->fetchAll();
        $userIds = [];
        foreach ($rows as $rw) {
            $uid = (int)($rw['created_by'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }
        $idList = array_keys($userIds);
        $namesById = [];
        if ($idList !== []) {
            $ph = implode(',', array_fill(0, count($idList), '?'));
            $stu = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($ph)");
            $stu->execute($idList);
            while ($u = $stu->fetch(PDO::FETCH_ASSOC)) {
                $namesById[(int)$u['id']] = (string)($u['username'] ?? '');
            }
        }
        foreach ($rows as &$rw) {
            $cb = (int)($rw['created_by'] ?? 0);
            $rw['created_by_name'] = $namesById[$cb] ?? '';
        }
        unset($rw);
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
    <title>待批准 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .btn { padding: 6px 10px; border: 0; border-radius: 6px; cursor: pointer; color: #fff; }
        .approve { background: #28a745; }
        .reject { background: #dc3545; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        form { display: inline-block; margin-right: 6px; }
    </style>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="wrap" style="max-width: 1100px;">
        <h2 style="margin:0 0 8px;">待批准（仅 admin）</h2>
        <p class="muted"><a href="dashboard.php">返回首页</a> | <a href="transaction_list.php?status=pending">查看所有待批准（列表视图）</a></p>

        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <?php if ($head_office_scope): ?><th>分公司</th><?php endif; ?>
                    <th>创建人</th>
                    <th>日期</th>
                    <th>时间</th>
                    <th>模式</th>
                    <th>代码</th>
                    <th>银行</th>
                    <th>产品</th>
                    <th>金额</th>
                    <th>奖励</th>
                    <th>备注</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <?php if ($head_office_scope): ?><td><?= htmlspecialchars($r['company_code'] ?? '') ?></td><?php endif; ?>
                    <td><?= htmlspecialchars($r['created_by_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['day']) ?></td>
                    <td><?= htmlspecialchars($r['time']) ?></td>
                    <td><?= htmlspecialchars($r['mode']) ?></td>
                    <td><?= htmlspecialchars($r['code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['bank'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['product'] ?? '') ?></td>
                    <td><?= number_format((float)$r['amount'], 2) ?></td>
                    <td><?= number_format((float)($r['bonus'] ?? 0), 2) ?></td>
                    <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="btn approve" type="submit">通过</button>
                        </form>
                        <form method="post" data-confirm="确定拒绝这条流水？">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button class="btn reject" type="submit">拒绝</button>
                        </form>
                        <a href="transaction_edit.php?id=<?= (int)$r['id'] ?>&return_to=<?= urlencode('admin_approvals.php') ?>">编辑</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="<?= $head_office_scope ? 13 : 12 ?>">暂无待批准</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
        </main>
    </div>
</body>
</html>

