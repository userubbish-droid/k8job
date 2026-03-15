<?php
require 'config.php';
require 'auth.php';
require_login();
$filter_recommend = isset($_GET['recommend']) ? trim((string)$_GET['recommend']) : '';
// 从 Agent 页带 recommend 进入：有 agent 权限即可，只显示代号+输赢；否则需 customers 权限
if ($filter_recommend !== '') {
    if (!has_permission('agent')) { require_permission('customers'); }
} else {
    require_permission('customers');
}
$sidebar_current = 'customers';

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$msg = '';
$err = '';

// 从 Agent 页进入（带 recommend 筛选）时不允许 POST 操作，且只显示代号+输赢，不显示客户资料
$agent_view = $filter_recommend !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$agent_view) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE customers SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// REGULAR 等级：按顾客 deposit - withdraw 对扣余额计算。normal 20~1000, sliver 1001~3000, gold 3001~7000, platinum 7001+
function customer_regular_tier($balance) {
    $b = (float) $balance;
    if ($b < 20) return '—';
    if ($b <= 1000) return 'normal';
    if ($b <= 3000) return 'sliver';
    if ($b <= 7000) return 'gold';
    return 'platinum';
}

$summary = ['total' => 0, 'active' => 0];
$rows = [];
$balance_by_code = [];
$all_deposit_by_code = [];
$all_withdraw_by_code = [];
$all_rebate_by_code = [];
$all_free_by_code = [];
$all_free_withdraw_by_code = [];
$all_bonus_by_code = [];
$month_deposit_by_code = [];
$month_withdraw_by_code = [];
try {
    $where = [];
    $params = [];
    if ($filter_recommend !== '') {
        $where[] = "TRIM(c.recommend) = ?";
        $params[] = $filter_recommend;
    }
    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($filter_recommend !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE TRIM(recommend) = ?");
        $stmt->execute([$filter_recommend]);
        $summary['total'] = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE is_active = 1 AND TRIM(recommend) = ?");
        $stmt->execute([$filter_recommend]);
        $summary['active'] = (int) $stmt->fetchColumn();
    } else {
        $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        $summary['active'] = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
    }
    $sql = "SELECT c.id, c.code, c.name, c.phone, c.remark, c.is_active, c.created_at,
                   c.register_date, c.bank_details, c.regular_customer, c.recommend, c.created_by,
                   u.username AS created_by_name
            FROM customers c
            LEFT JOIN users u ON c.created_by = u.id
            $where_sql
            ORDER BY c.is_active DESC, c.code ASC";
    if ($params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $pdo->query($sql)->fetchAll();
    }
    $stmt = $pdo->query("SELECT code,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS balance
        FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != ''
        GROUP BY code");
    foreach ($stmt->fetchAll() as $r) {
        $balance_by_code[$r['code']] = (float) $r['balance'];
    }
    $all_deposit_by_code = [];
    $all_withdraw_by_code = [];
    $month_deposit_by_code = [];
    $month_withdraw_by_code = [];
    $stmt = $pdo->query("SELECT code,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ad,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS aw
        FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' GROUP BY code");
    foreach ($stmt->fetchAll() as $r) {
        $all_deposit_by_code[$r['code']] = (float)$r['ad'];
        $all_withdraw_by_code[$r['code']] = (float)$r['aw'];
    }
    $stmt = $pdo->query("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'REBATE' GROUP BY code");
    foreach ($stmt->fetchAll() as $r) {
        $all_rebate_by_code[$r['code']] = (float)$r['total'];
    }
    $stmt = $pdo->query("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'FREE' GROUP BY code");
    foreach ($stmt->fetchAll() as $r) {
        $all_free_by_code[$r['code']] = (float)$r['total'];
    }
    $stmt = $pdo->query("SELECT code, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' AND mode = 'FREE WITHDRAW' GROUP BY code");
    foreach ($stmt->fetchAll() as $r) {
        $all_free_withdraw_by_code[$r['code']] = (float)$r['total'];
    }
    $stmt = $pdo->query("SELECT TRIM(code) AS code, COALESCE(SUM(COALESCE(bonus, 0)), 0) AS total FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' GROUP BY TRIM(code)");
    foreach ($stmt->fetchAll() as $r) {
        $all_bonus_by_code[$r['code']] = (float)$r['total'];
    }
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $stmt = $pdo->prepare("SELECT code,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS md,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS mw
        FROM transactions WHERE status = 'approved' AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ? GROUP BY code");
    $stmt->execute([$month_start, $month_end]);
    foreach ($stmt->fetchAll() as $r) {
        $month_deposit_by_code[$r['code']] = (float)$r['md'];
        $month_withdraw_by_code[$r['code']] = (float)$r['mw'];
    }
    } catch (Throwable $e) {
    $rows = [];
    $all_deposit_by_code = [];
    $all_withdraw_by_code = [];
    $all_rebate_by_code = [];
    $all_free_by_code = [];
    $all_free_withdraw_by_code = [];
    $month_deposit_by_code = [];
    $month_withdraw_by_code = [];
    $err = $err ?: (strpos($e->getMessage(), 'recommend') !== false ? '请先在 phpMyAdmin 执行 migrate_customers_recommend.sql。' : '请先在 phpMyAdmin 执行 migrate_customers_detail.sql。') . ' (' . $e->getMessage() . ')';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>顾客列表 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap">
        <div class="page-header">
            <h2>顾客列表</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a>
                <?php if (has_permission('transaction_create')): ?><span>·</span><a href="transaction_create.php">去记一笔</a><?php endif; ?>
                <?php if (has_permission('customer_create')): ?><span>·</span><a href="customer_create.php">新增顾客</a><?php endif; ?>
                <?php if (has_permission('product_library')): ?><span>·</span><a href="product_library.php">产品账号</a><?php endif; ?>
                <?php if ($is_admin): ?><span>·</span><a href="admin_option_sets.php">选项设置</a><?php endif; ?>
            </p>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <?php if ($filter_recommend !== ''): ?>
        <div class="alert" style="background:var(--bg); border:1px solid var(--border);">
            Filtered by Agent: <strong><?= htmlspecialchars($filter_recommend) ?></strong>
            <a href="customers.php" style="margin-left:10px;">Clear filter</a>
        </div>
        <?php endif; ?>

        <div class="summary">
            <div class="summary-item"><strong>T1. Customer</strong><span class="num"><?= $summary['total'] ?></span></div>
            <div class="summary-item"><strong>Active Member</strong><span class="num"><?= $summary['active'] ?></span></div>
            <div class="summary-item"><strong>TARGET</strong><span class="num">1</span></div>
        </div>

        <div class="card" style="overflow-x: auto;">
            <h3>列表</h3>
            <?php if ($agent_view): ?>
            <p style="color:var(--muted); margin-bottom:10px;">仅显示代号与本公司输赢，不显示客户资料。</p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th class="num">Win(Loss)</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                    $agent_total_win_loss = 0;
                    foreach ($rows as $r):
                    $code = $r['code'];
                    $all_dp = $all_deposit_by_code[$code] ?? 0;
                    $all_wd = $all_withdraw_by_code[$code] ?? 0;
                    $win_loss = $all_wd - $all_dp;
                    $agent_total_win_loss += $win_loss;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($code) ?></td>
                        <td class="num <?= $win_loss >= 0 ? 'stmt-out' : 'stmt-in' ?>"><?= number_format($win_loss, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows): ?>
                    <tr style="font-weight:bold; background:var(--bg);">
                        <td>Total</td>
                        <td class="num <?= $agent_total_win_loss >= 0 ? 'stmt-out' : 'stmt-in' ?>"><?= number_format($agent_total_win_loss, 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="2" style="color:var(--muted); padding:24px;">No customers under this agent.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
            <?php if ($is_admin): ?>
            <p style="margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <button type="button" class="btn btn-sm btn-outline" id="toggle-created-by" aria-pressed="false">显示填写人</button>
                <button type="button" class="btn btn-sm btn-outline" id="toggle-contact" aria-pressed="false">隐藏 CONTACT</button>
                <button type="button" class="btn btn-sm btn-outline" id="toggle-total-dp" aria-pressed="false">隐藏 Total DP</button>
                <button type="button" class="btn btn-sm btn-outline" id="toggle-total-wd" aria-pressed="false">隐藏 Total WD</button>
            </p>
            <?php endif; ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>REGISTER DATE</th>
                        <th>FULL NAME</th>
                        <th class="col-contact">CONTACT</th>
                        <th>BANK DETAILS</th>
                        <th class="col-total-dp">Total DP</th>
                        <th class="col-total-wd">Total WD</th>
                        <th>Rebate</th>
                        <th>Free</th>
                        <th>Free Withdraw</th>
                        <th>Bonus</th>
                        <th>deposit</th>
                        <th>withdraw</th>
                        <th>REGULAR</th>
                        <th>REMARK</th>
                        <th>RECOMMEND</th>
                        <?php if ($is_admin): ?><th class="col-created-by" style="display:none;">填写人</th><?php endif; ?>
                        <?php if ($is_admin): ?><th>操作</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $code = $r['code'];
                    $all_dp = $all_deposit_by_code[$code] ?? 0;
                    $all_wd = $all_withdraw_by_code[$code] ?? 0;
                    $all_rebate = $all_rebate_by_code[$code] ?? 0;
                    $all_free = $all_free_by_code[$code] ?? 0;
                    $all_fw = $all_free_withdraw_by_code[$code] ?? 0;
                    $all_bonus = $all_bonus_by_code[$code] ?? 0;
                    $mon_dp = $month_deposit_by_code[$code] ?? 0;
                    $mon_wd = $month_withdraw_by_code[$code] ?? 0;
                ?>
                    <tr>
                        <td><a href="customer_edit.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($code) ?></a></td>
                        <td><?= htmlspecialchars($r['register_date'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td class="col-contact"><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['bank_details'] ?? '') ?></td>
                        <td class="col-total-dp num"><?= number_format($all_dp, 2) ?></td>
                        <td class="col-total-wd num"><?= number_format($all_wd, 2) ?></td>
                        <td class="num"><?= number_format($all_rebate, 2) ?></td>
                        <td class="num"><?= number_format($all_free, 2) ?></td>
                        <td class="num"><?= number_format($all_fw, 2) ?></td>
                        <td class="num"><?= number_format($all_bonus, 2) ?></td>
                        <td class="num"><?= number_format($mon_dp, 2) ?></td>
                        <td class="num"><?= number_format($mon_wd, 2) ?></td>
                        <td><?= htmlspecialchars(customer_regular_tier($balance_by_code[$code] ?? 0)) ?></td>
                        <td><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['recommend'] ?? '') ?></td>
                        <?php if ($is_admin): ?><td class="col-created-by" style="display:none;"><?= htmlspecialchars($r['created_by_name'] ?? '—') ?></td><?php endif; ?>
                        <?php if ($is_admin): ?>
                        <td>
                            <a href="customer_edit.php?id=<?= (int)$r['id'] ?>">编辑</a>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-gray"><?= ((int)$r['is_active'] === 1) ? '禁用' : '启用' ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $is_admin ? 18 : 16 ?>" style="color:var(--muted); padding:24px;">暂无数据，请先执行 migrate_customers_detail.sql 并添加顾客。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
    <script>
    (function(){
        function toggleCol(btnId, colClass, showText, hideText, columnStartsVisible) {
            columnStartsVisible = columnStartsVisible !== false;
            var btn = document.getElementById(btnId);
            if (!btn) return;
            var cells = document.querySelectorAll('th.' + colClass + ', td.' + colClass);
            btn.addEventListener('click', function(){
                var visible = columnStartsVisible ? (btn.getAttribute('aria-pressed') !== 'true') : (btn.getAttribute('aria-pressed') === 'true');
                var newVisible = !visible;
                btn.setAttribute('aria-pressed', newVisible ? (columnStartsVisible ? 'false' : 'true') : (columnStartsVisible ? 'true' : 'false'));
                btn.textContent = newVisible ? (columnStartsVisible ? showText : hideText) : (columnStartsVisible ? hideText : showText);
                cells.forEach(function(el){ el.style.display = newVisible ? '' : 'none'; });
            });
        }
        toggleCol('toggle-created-by', 'col-created-by', '显示填写人', '隐藏填写人', false);
        toggleCol('toggle-contact', 'col-contact', '显示 CONTACT', '隐藏 CONTACT', true);
        toggleCol('toggle-total-dp', 'col-total-dp', '显示 Total DP', '隐藏 Total DP', true);
        toggleCol('toggle-total-wd', 'col-total-wd', '显示 Total WD', '隐藏 Total WD', true);
    })();
    </script>
</body>
</html>
