<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_customer_approvals';

$msg = '';
$err = '';
$company_id = current_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            if ($action === 'approve') {
                $stmt = $pdo->prepare("UPDATE customers SET status='approved', approved_by=?, approved_at=? WHERE company_id=? AND id=? AND status='pending'");
                $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $company_id, $id]);
                $msg = '已通过。';
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE customers SET status='rejected', approved_by=?, approved_at=? WHERE company_id=? AND id=? AND status='pending'");
                $stmt->execute([(int)($_SESSION['user_id'] ?? 0), date('Y-m-d H:i:s'), $company_id, $id]);
                $msg = '已拒绝。';
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$rows = [];
try {
    $rows = $pdo->prepare("SELECT c.id, c.code, c.name, c.phone, c.bank_details, c.recommend, c.remark, c.created_at,
                                  u.username AS created_by_name
                           FROM customers c
                           LEFT JOIN users u ON u.id = c.created_by
                           WHERE c.company_id = ? AND c.status = 'pending'
                           ORDER BY c.created_at ASC");
    $rows->execute([$company_id]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>客户待审核 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 1100px;">
                <div class="page-header">
                    <h2>客户待审核</h2>
                    <p class="breadcrumb"><a href="customers.php">Customers</a><span>·</span><a href="customer_create.php">New Customer</a></p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="card" style="overflow-x:auto;">
                    <h3>Pending</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>创建人</th>
                                <th>CODE</th>
                                <th>NAME</th>
                                <th>PHONE</th>
                                <th>BANK</th>
                                <th>RECOMMEND</th>
                                <th>REMARK</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= htmlspecialchars((string)($r['created_by_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['code'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['phone'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['bank_details'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['recommend'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['remark'] ?? '')) ?></td>
                                <td>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-sm btn-primary">通过</button>
                                    </form>
                                    <form method="post" class="inline" data-confirm="确定拒绝该客户？">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-sm btn-danger">拒绝</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                            <tr><td colspan="9" style="color:var(--muted); padding:22px;">暂无待审核客户。</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

