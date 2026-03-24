<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_create');
$quick = trim((string)($_GET['quick'] ?? ''));
$sidebar_current = $quick === 'expense' ? 'expense_create' : 'transaction_create';

$saved = false;
$error = '';
$submitted_status = '';
$expense_day_from_raw = trim((string)($_GET['expense_day_from'] ?? date('Y-m-01')));
$expense_day_to_raw = trim((string)($_GET['expense_day_to'] ?? date('Y-m-d')));
$expense_bank_filter = trim((string)($_GET['expense_bank'] ?? ''));
$expense_product_filter = trim((string)($_GET['expense_product'] ?? ''));
$expense_day_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_day_from_raw) ? $expense_day_from_raw : date('Y-m-01');
$expense_day_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_day_to_raw) ? $expense_day_to_raw : date('Y-m-d');
if ($expense_day_from > $expense_day_to) { $tmp = $expense_day_from; $expense_day_from = $expense_day_to; $expense_day_to = $tmp; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    // member：未点击「+」修改日期时间则用当前时间且自动通过审核
    $member_use_current = isset($_POST['member_use_current_time']) && (string)$_POST['member_use_current_time'] === '1';
    if ($quick === 'expense') {
        $can_edit_dt = !$member_use_current; // Expense 页统一：点 + 才使用手动日期时间
    } elseif ($is_admin) {
        $can_edit_dt = !empty($_POST['edit_dt']);
    } else {
        $can_edit_dt = !$member_use_current; // member 点了「+」才用表单里的日期时间
    }
    $day     = $can_edit_dt && isset($_POST['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['day'] ?? '')) ? trim($_POST['day']) : date('Y-m-d');
    $timeRaw = $can_edit_dt && isset($_POST['time']) ? trim($_POST['time'] ?? '00:00') : date('H:i');
    $time    = (strlen($timeRaw) === 5 && preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $timeRaw)) ? $timeRaw . ':00' : ($timeRaw ?: '00:00:00');
    $mode    = trim($_POST['mode'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $bank    = trim($_POST['bank'] ?? '');
    $product = trim($_POST['product'] ?? '');
    $amount    = str_replace(',', '', trim($_POST['amount'] ?? '0'));
    $reward_pct = str_replace(',', '', trim((string)($_POST['reward_pct'] ?? '')));
    $bonus_fix  = str_replace(',', '', trim((string)($_POST['bonus'] ?? '0')));
    $remark   = trim($_POST['remark'] ?? '');

    if ($day === '' || $mode === '') {
        $error = '请填写日期和模式。';
    } elseif ($mode === 'EXPENSE' && ($bank === '' || $product === '')) {
        $error = 'EXPENSE 必须填写 Bank 和 Expense 项目。';
    } elseif (!is_numeric($amount)) {
        $error = '金额请填数字。';
    } else {
        $amount = (float) $amount;
        // Bonus = 金额 × 奖励/返点% / 100；优先用百分比，否则用隐藏域 bonus
        $bonus = 0;
        if ($reward_pct !== '' && is_numeric($reward_pct)) {
            $bonus = round($amount * (float)$reward_pct / 100, 2);
        }
        if ($bonus <= 0 && $bonus_fix !== '' && is_numeric($bonus_fix) && (float)$bonus_fix > 0) {
            $bonus = round((float) $bonus_fix, 2);
        }
        $total  = round($amount + $bonus, 2);

        // member 使用当前时间提交则无需审核，否则待审核
        $status = $is_admin ? 'approved' : ($member_use_current ? 'approved' : 'pending');
        $approved_by = ($status === 'approved') ? (int)($_SESSION['user_id'] ?? 0) : null;
        $approved_at = ($status === 'approved') ? date('Y-m-d H:i:s') : null;
        $staff = (string) ($_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? ''));

        $saved = false;
        $insertBase = [$day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null, $amount, $bonus, $total, $staff ?: null, $remark ?: null, $status, (int)($_SESSION['user_id'] ?? 0), $approved_by, $approved_at];
        foreach ([true, false] as $withHide) {
            try {
                if ($withHide) {
                    $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertBase);
                } else {
                    $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertBase);
                }
                $saved = true;
                break;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'bonus') !== false || stripos($msg, 'total') !== false || stripos($msg, 'Unknown column') !== false) {
                    $error = '数据库缺少 bonus/total 列。请在 phpMyAdmin 中执行 migrate_bonus.sql 里的 SQL。';
                    break;
                }
                if (stripos($msg, 'hide_from_member') !== false && $withHide) continue;
                throw $e;
            }
        }
        if ($saved) {
        $submitted_status = $status;
            if ($status === 'pending') {
                if (file_exists(__DIR__ . '/inc/notify.php')) {
                    require_once __DIR__ . '/inc/notify.php';
                    send_pending_approval_notify($pdo);
                }
            }
            $saved_mode = $mode;
        $saved_code = $code;
        $saved_product = $product;
        $saved_amount = $amount;
        $saved_bonus = $bonus;
        $saved_total = $total;
        $saved_reward_pct = ($reward_pct !== '' && is_numeric($reward_pct)) ? (float)$reward_pct : null;
        $saved_account = '';
        $saved_customer_name = '';
        $saved_customer_bank = '';
        if ($code !== '' && $product !== '') {
            try {
                $acc = $pdo->prepare("SELECT a.account FROM customer_product_accounts a INNER JOIN customers c ON c.id = a.customer_id WHERE c.code = ? AND a.product_name = ? LIMIT 1");
                $acc->execute([$code, $product]);
                $row = $acc->fetch();
                $saved_account = $row ? (trim($row['account'] ?? '') ?: '—') : '—';
            } catch (Throwable $e) {
                $saved_account = '—';
            }
        } else {
            $saved_account = '—';
        }
        if ($mode === 'WITHDRAW' && $code !== '') {
            try {
                $cust = $pdo->prepare("SELECT name, bank_details FROM customers WHERE code = ? LIMIT 1");
                $cust->execute([$code]);
                $crow = $cust->fetch();
                $saved_customer_name = $crow ? trim($crow['name'] ?? '') : '';
                $saved_customer_bank = $crow ? trim($crow['bank_details'] ?? '') : '';
            } catch (Throwable $e) {}
        }
        }
    }
}

$today = date('Y-m-d');
$now   = date('H:i');
$selected_mode = trim((string)($_POST['mode'] ?? ($quick === 'expense' ? 'EXPENSE' : '')));

// 银行/产品：仅 admin 可“设置”（在 admin_banks / admin_products 管理）；员工只能从已设置的选项中选择
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$banks = [];
$products = [];
$expenses = [];
$expense_report_rows = [];
$expense_report_count = 0;
$expense_report_total = 0.0;
$expense_report_by_product = [];
$expense_filter_banks = [];
$expense_filter_products = [];
// 客户代码下拉选项（含 name、bank_details 供 WITHDRAW 时显示）
$customers = [];
try {
    $customers = $pdo->query("SELECT code, name, bank_details FROM customers WHERE is_active = 1 ORDER BY code ASC")->fetchAll();
} catch (Throwable $e) {
    $customers = [];
}
try {
    $banks = $pdo->query("SELECT name FROM banks WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $banks = [];
}
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}
try {
    $expenses = $pdo->query("SELECT name FROM expenses WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $expenses = [];
}
// admin 无数据时用内置列表，并可选「其他」手填；员工只能用管理员设置的列表，不能改
if ($is_admin) {
    if (!$banks) $banks = ['HLB', 'CASH', 'DOUGLAS', 'KAYDEN', 'RHB', 'CIMB', 'Digi', 'Maxis', 'KAYDEN TNG'];
    if (!$products) $products = ['MEGA', 'PUSSY', '918KISS', 'JOKER', 'KING855', 'LIVE22', 'ACE333', 'VPOWER', 'LPE888', 'ALIPAY', 'STANDBY'];
    if (!$expenses) $expenses = ['Office', 'Salary', 'Ads', 'Transport'];
}

if ($quick === 'expense') {
    try {
        $expense_filter_banks = $pdo->query("SELECT DISTINCT TRIM(COALESCE(bank, '')) AS bank_name FROM transactions WHERE mode = 'EXPENSE' AND status = 'approved' AND TRIM(COALESCE(bank, '')) <> '' ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $expense_filter_products = $pdo->query("SELECT DISTINCT TRIM(COALESCE(product, '')) AS product_name FROM transactions WHERE mode = 'EXPENSE' AND status = 'approved' AND TRIM(COALESCE(product, '')) <> '' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_COLUMN);

        $where = "status = 'approved' AND mode = 'EXPENSE' AND day >= ? AND day <= ?";
        $params = [$expense_day_from, $expense_day_to];
        if ($expense_bank_filter !== '') {
            $where .= " AND bank = ?";
            $params[] = $expense_bank_filter;
        }
        if ($expense_product_filter !== '') {
            $where .= " AND product = ?";
            $params[] = $expense_product_filter;
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE $where");
        $stmt->execute($params);
        $expense_report_total = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT day, time, bank, product, amount, staff, remark FROM transactions WHERE $where ORDER BY day DESC, time DESC LIMIT 120");
        $stmt->execute($params);
        $expense_report_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expense_report_count = count($expense_report_rows);

        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(product), ''), '未填写产品') AS product_name, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total_amount
            FROM transactions
            WHERE $where
            GROUP BY COALESCE(NULLIF(TRIM(product), ''), '未填写产品')
            ORDER BY total_amount DESC");
        $stmt->execute($params);
        $expense_report_by_product = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // 保持页面可用，仅在下方展示错误信息
        $error = $error !== '' ? $error : ('Expense 汇总加载失败：' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>记一笔流水 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .txn-wrap { max-width: 760px; }
        .txn-form-card { padding: 24px; }
        .txn-form {
            display: grid;
            gap: 18px;
        }
        .form-section {
            margin: 0;
            padding: 14px 14px 12px;
            border: 1px solid rgba(126, 154, 228, 0.22);
            border-radius: 12px;
            background: rgba(255,255,255,0.72);
        }
        .form-section-title {
            font-size: 12px;
            color: #475569;
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
        .txn-form .form-group { margin-bottom: 0; }
        .txn-form .form-control { min-height: 46px; border-radius: 10px; }
        .txn-form textarea.form-control { min-height: 110px; }
        .txn-submit { width: 100%; min-height: 48px; font-size: 16px; font-weight: 700; }
        .calc-box { margin: 12px 0; padding: 12px 14px; background: #f0f9ff; border-radius: 8px; font-size: 14px; color: #0c4a6e; }
        .success-actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; }
        .success-actions a { padding: 8px 14px; background: #fff; border: 1px solid #a7f3d0; border-radius: 6px; color: #059669; text-decoration: none; font-size: 13px; }
        .success-actions a:hover { background: #ecfdf5; }
        .expense-statement-wrap { margin-top: 14px; padding: 14px 16px; }
        .expense-statement-title { margin: 0 0 10px; font-size: 14px; }
        .expense-filter-bar { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)) auto; gap: 10px; align-items: end; margin-bottom: 12px; }
        .expense-filter-item label { display: block; font-size: 12px; margin-bottom: 4px; color: var(--muted); }
        .expense-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .expense-kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(120px, 1fr)); gap: 10px; margin-bottom: 10px; }
        .expense-kpi-card { border: 1px solid rgba(115, 146, 230, 0.25); border-radius: 10px; background: rgba(255,255,255,0.82); padding: 10px 12px; }
        .expense-kpi-card strong { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .expense-kpi-card .num { font-size: 1.2rem; font-weight: 700; line-height: 1; }
        @media (max-width: 640px) {
            .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
            .txn-form-card { padding: 16px; }
            .form-section { padding: 12px; }
            .expense-filter-bar { grid-template-columns: 1fr 1fr; }
            .expense-kpi-grid { grid-template-columns: 1fr; }
        }
        .pretty-modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(8, 16, 40, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .pretty-modal-mask.show { display: flex; }
        .pretty-modal {
            width: min(92vw, 460px);
            background: #fff;
            border-radius: 18px;
            border: 1px solid #dbeafe;
            box-shadow: 0 22px 60px rgba(37, 99, 235, 0.28);
            transform: scale(0.9) translateY(8px);
            opacity: 0;
            transition: transform .22s ease, opacity .22s ease;
            overflow: hidden;
        }
        .pretty-modal-mask.show .pretty-modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }
        .pretty-modal-head {
            padding: 14px 18px;
            font-weight: 700;
            color: #1d4ed8;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border-bottom: 1px solid #bfdbfe;
        }
        .pretty-modal-body {
            padding: 20px 18px 12px;
            color: #0f172a;
            font-size: 15px;
            line-height: 1.6;
        }
        .pretty-modal-foot {
            display: flex;
            justify-content: flex-end;
            padding: 0 18px 16px;
        }
        .pretty-ok {
            min-width: 92px;
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.35);
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap txn-wrap">
        <div class="page-header">
            <h2>记一笔流水</h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_list.php">流水记录</a></p>
        </div>

    <?php if ($saved): ?>
        <div class="card" style="margin-bottom: 20px;">
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 14px;">
                <div style="margin-bottom: 4px;"><strong><?= htmlspecialchars($saved_mode) ?></strong> <?= htmlspecialchars($saved_code ?: '—') ?> <?= htmlspecialchars($saved_product ?: '—') ?> · 金额 <?= number_format($saved_amount, 2) ?><?= $saved_reward_pct !== null ? '，奖励 ' . number_format($saved_reward_pct, 0) . '%' : '' ?> · <strong>本笔 Bonus：<?= number_format($saved_bonus, 2) ?></strong> · 总数 <?= number_format($saved_total, 2) ?></div>
                <?php if ($saved_bonus > 0 && !empty($saved_code)): ?><p class="form-hint" style="margin-top:4px; color:var(--success);">此笔 Bonus 将计入顾客 <?= htmlspecialchars($saved_code) ?> 的汇总<?= $submitted_status === 'pending' ? '（需管理员批准后生效）' : '。' ?></p><?php endif; ?>
                <?php if ($saved_bonus > 0 && empty($saved_code)): ?><p class="form-hint" style="margin-top:4px; color:#b45309;">未选客户时，Bonus 不会计入顾客列表；请记一笔时选择客户。</p><?php endif; ?>
                <?php if (!empty($saved_code) || !empty($saved_product) || $saved_mode === 'WITHDRAW'): ?>
                <?php if ($saved_mode === 'WITHDRAW' && !empty($saved_code)): ?>
                <div class="form-hint" style="margin-bottom:4px;">顾客姓名：<?= htmlspecialchars($saved_customer_name ?: '—') ?></div>
                <div class="form-hint">银行资料：<?= htmlspecialchars($saved_customer_bank ?: '—') ?></div>
                <?php endif; ?>
                <?php if (!empty($saved_product) && !empty($saved_code)): ?>
                <div class="form-hint" style="margin-top:4px;"><?= htmlspecialchars($saved_code) ?> 的 <?= htmlspecialchars($saved_product) ?> 账号：<?= htmlspecialchars($saved_account) ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="success-actions">
                <a href="transaction_create.php">再记一笔</a>
                <a href="transaction_list.php">看流水</a>
                <a href="dashboard.php">回首页</a>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-error"><?= $is_admin ? htmlspecialchars($error) : '✗ Faild' ?></div>
    <?php endif; ?>

    <div class="card txn-form-card">
    <form method="post" class="txn-form">
        <?php if ($quick === 'expense'): ?>
        <div class="form-section member-dt-section">
            <div class="form-section-title" style="display:flex; align-items:center; gap:6px;">
                日期 / 时间
                <input type="hidden" name="member_use_current_time" id="member_use_current_time" value="1">
                <button type="button" id="member_dt_toggle" class="btn btn-outline btn-sm" style="padding:2px 8px; font-size:13px; line-height:1.2;" aria-label="展开修改日期时间">+</button>
            </div>
            <div id="member_dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
            </div>
        </div>
        <?php elseif ($is_admin): ?>
        <div class="form-section">
            <div class="form-section-title">日期 / 时间</div>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="checkbox" name="edit_dt" value="1" id="edit_dt" style="width:18px; height:18px;">
                <span>需要修改日期/时间</span>
            </label>
            <div id="dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="form-section member-dt-section">
            <div class="form-section-title" style="display:flex; align-items:center; gap:6px;">
                time
                <input type="hidden" name="member_use_current_time" id="member_use_current_time" value="1">
                <button type="button" id="member_dt_toggle" class="btn btn-outline btn-sm" style="padding:2px 8px; font-size:13px; line-height:1.2;" aria-label="展开修改日期时间">+</button>
            </div>
            <div id="member_dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
                <p class="form-hint" style="margin-top:4px;">可输入数字如 1513 自动变为 15:13。修改后提交需管理员审核。</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="form-section-title">基本信息</div>
            <div class="form-row-2">
                <div class="form-group">
                    <?php if ($quick === 'expense'): ?>
                    <label>模式</label>
                    <input type="hidden" name="mode" id="mode" value="EXPENSE">
                    <input type="text" class="form-control" value="EXPENSE" readonly>
                    <?php else: ?>
                    <label>模式 *</label>
                    <select name="mode" id="mode" class="form-control" required>
                        <option value="">-- 请选 --</option>
                        <option value="DEPOSIT" <?= $selected_mode === 'DEPOSIT' ? 'selected' : '' ?>>DEPOSIT</option>
                        <option value="WITHDRAW" <?= $selected_mode === 'WITHDRAW' ? 'selected' : '' ?>>WITHDRAW</option>
                        <option value="FREE" <?= $selected_mode === 'FREE' ? 'selected' : '' ?>>FREE</option>
                        <option value="FREE WITHDRAW" <?= $selected_mode === 'FREE WITHDRAW' ? 'selected' : '' ?>>FREE WITHDRAW</option>
                        <option value="EXPENSE" <?= $selected_mode === 'EXPENSE' ? 'selected' : '' ?>>EXPENSE</option>
                        <option value="BANK" <?= $selected_mode === 'BANK' ? 'selected' : '' ?>>BANK</option>
                        <option value="REBATE" <?= $selected_mode === 'REBATE' ? 'selected' : '' ?>>REBATE</option>
                        <option value="OTHER" <?= $selected_mode === 'OTHER' ? 'selected' : '' ?>>OTHER</option>
                    </select>
                    <?php endif; ?>
                </div>
                <?php if ($quick !== 'expense'): ?>
                <div class="form-group">
                    <label>customer</label>
                    <?php if (empty($customers)): ?>
                    <select name="code" class="form-control" disabled><option value="">-- 暂无 --</option></select>
                    <p class="form-hint"><a href="customers.php">先去添加客户</a></p>
                    <?php else: ?>
                    <select name="code" id="code" class="form-control">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div id="withdraw_customer_box" class="form-group" style="display:none; padding:10px 12px; background:#fef3c7; border-radius:8px; font-size:14px; border:1px solid #fcd34d;">
                <div style="margin-bottom:4px;"><strong>顾客姓名</strong>：<span id="withdraw_customer_name">—</span></div>
                <div><strong>银行资料</strong>：<span id="withdraw_customer_bank">—</span></div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>bank <span id="bank_req_mark">*</span></label>
                    <?php if (!$is_admin && empty($banks)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="bank" id="bank" class="form-control" title="REBATE/FREE 不必选；其他模式必选则银行与产品页会统计">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($banks as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <?php if ($quick === 'expense'): ?>
                    <label id="product_label">Expense 项目 *</label>
                    <input type="text" name="product" id="product" class="form-control" required placeholder="例如：rental / office / salary">
                    <p class="form-hint" style="margin-top:4px;">用于 Expense 分类汇总。</p>
                    <?php else: ?>
                    <label id="product_label">产品/平台 *</label>
                    <?php if (!$is_admin && empty($products)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="product" id="product" class="form-control" required title="必选，否则银行与产品页的 In/Out 不会统计">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-group">
                <label>备注</label>
                <textarea name="remark" class="form-control" rows="2" placeholder="<?= $quick === 'expense' ? '例如：rental' : '选填' ?>"></textarea>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">金额</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>金额 *</label>
                    <input type="text" name="amount" id="amount" class="form-control" placeholder="如 630.00" required>
                </div>
                <?php if ($quick !== 'expense'): ?>
                <div class="form-group">
                    <label>奖励/返点 %</label>
                    <input type="text" name="reward_pct" id="reward_pct" class="form-control" placeholder="" inputmode="decimal" title="按金额百分比计算 bonus，顾客列表 Bonus 列即此项合计">
                    <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                </div>
                <?php else: ?>
                <input type="hidden" name="reward_pct" id="reward_pct" value="0">
                <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                <?php endif; ?>
            </div>
            <?php if ($quick !== 'expense'): ?>
            <p class="form-hint" id="reward_hint" style="margin-top:4px; display:none;">奖励 <span id="reward_amount">0</span>，总数 <strong id="reward_total">0</strong></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary txn-submit">保存</button>
    </form>
    </div>

    <?php if ($quick === 'expense'): ?>
    <div class="card expense-statement-wrap">
        <h4 class="expense-statement-title">Expense 明细与汇总</h4>
        <form method="get" class="expense-filter-bar">
            <input type="hidden" name="quick" value="expense">
            <div class="expense-filter-item">
                <label>From</label>
                <input type="date" name="expense_day_from" class="form-control" value="<?= htmlspecialchars($expense_day_from) ?>">
            </div>
            <div class="expense-filter-item">
                <label>To</label>
                <input type="date" name="expense_day_to" class="form-control" value="<?= htmlspecialchars($expense_day_to) ?>">
            </div>
            <div class="expense-filter-item">
                <label>Bank</label>
                <select name="expense_bank" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($expense_filter_banks as $bank_name): ?>
                    <option value="<?= htmlspecialchars((string)$bank_name) ?>" <?= $expense_bank_filter === (string)$bank_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$bank_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="expense-filter-item">
                <label>Product</label>
                <select name="expense_product" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($expense_filter_products as $product_name): ?>
                    <option value="<?= htmlspecialchars((string)$product_name) ?>" <?= $expense_product_filter === (string)$product_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$product_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="expense-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="transaction_create.php?quick=expense" class="btn btn-back btn-sm">Reset</a>
            </div>
        </form>

        <div class="expense-kpi-grid">
            <div class="expense-kpi-card">
                <strong>总开销</strong>
                <span class="num out"><?= number_format($expense_report_total, 2) ?></span>
            </div>
            <div class="expense-kpi-card">
                <strong>记录数</strong>
                <span class="num"><?= (int)$expense_report_count ?></span>
            </div>
            <div class="expense-kpi-card">
                <strong>产品数</strong>
                <span class="num"><?= count($expense_report_by_product) ?></span>
            </div>
        </div>

        <div style="overflow-x:auto; margin-bottom: 10px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Count</th>
                        <th class="num">Total Expense</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_report_by_product as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['product_name'] ?? '')) ?></td>
                        <td class="num"><?= (int)($row['cnt'] ?? 0) ?></td>
                        <td class="num out"><?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$expense_report_by_product): ?><tr><td colspan="3">暂无 Product 汇总</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Bank</th>
                        <th>Product</th>
                        <th class="num">Amount</th>
                        <th>Staff</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_report_rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['day'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['time'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['bank'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['product'] ?? '')) ?></td>
                        <td class="num out"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars((string)($row['staff'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['remark'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$expense_report_rows): ?><tr><td colspan="7">暂无 Expense 记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <p class="breadcrumb" style="margin-top:16px;">
        <a href="dashboard.php">返回首页</a><span>·</span>
        <a href="transaction_list.php">流水记录</a><span>·</span>
        <a href="logout.php">退出</a>
    </p>
    </div>
        </main>
    </div>
    <?php if ($saved): ?>
    <div class="pretty-modal-mask" id="saved-modal-mask">
        <div class="pretty-modal" role="dialog" aria-modal="true" aria-label="提交结果">
            <div class="pretty-modal-head">系统提示</div>
            <div class="pretty-modal-body">
                <?php if ($submitted_status === 'pending'): ?>
                    已提交成功，等待管理员批准。
                <?php else: ?>
                    成功，数据已保存并生效。
                <?php endif; ?>
            </div>
            <div class="pretty-modal-foot">
                <button type="button" class="pretty-ok" id="saved-modal-ok">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <script>
        (function() {
            var customerData = <?= json_encode(array_column($customers, null, 'code')) ?>;
            var isExpenseQuick = <?= $quick === 'expense' ? 'true' : 'false' ?>;
            var productOptionsDefault = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE) ?>;
            var productOptionsExpense = <?= json_encode(array_values($expenses), JSON_UNESCAPED_UNICODE) ?>;
            function updateWithdrawCustomer() {
                var modeEl = document.getElementById('mode');
                var codeEl = document.getElementById('code');
                var box = document.getElementById('withdraw_customer_box');
                var nameEl = document.getElementById('withdraw_customer_name');
                var bankEl = document.getElementById('withdraw_customer_bank');
                if (!modeEl || !codeEl || !box) return;
                var code = (codeEl.value || '').trim();
                if (modeEl.value === 'WITHDRAW' && code) {
                    var c = customerData[code];
                    box.style.display = 'block';
                    nameEl.textContent = (c && c.name) ? c.name : '—';
                    bankEl.textContent = (c && c.bank_details) ? c.bank_details : '—';
                } else {
                    box.style.display = 'none';
                }
            }
            var modeEl = document.getElementById('mode');
            var codeEl = document.getElementById('code');
            if (modeEl) modeEl.addEventListener('change', updateWithdrawCustomer);
            if (codeEl) codeEl.addEventListener('change', updateWithdrawCustomer);
            updateWithdrawCustomer();

            function applyBankRequired() {
                var mode = (modeEl && modeEl.value) ? modeEl.value : '';
                var bankSelect = document.getElementById('bank');
                var bankMark = document.getElementById('bank_req_mark');
                var productSelect = document.getElementById('product');
                var productLabel = document.getElementById('product_label');
                var noBankModes = ['REBATE', 'FREE', 'FREE WITHDRAW'];
                if (bankSelect && bankMark) {
                    if (noBankModes.indexOf(mode) >= 0) {
                        bankSelect.removeAttribute('required');
                        bankMark.style.display = 'none';
                    } else {
                        bankSelect.setAttribute('required', 'required');
                        bankMark.style.display = 'inline';
                    }
                }
                if (productSelect && productLabel) {
                    if ((productSelect.tagName || '').toUpperCase() !== 'SELECT') return;
                    var current = productSelect.value;
                    var list = productOptionsDefault;
                    var isQuickExpenseMode = mode === 'EXPENSE' && isExpenseQuick;
                    var fallback = isQuickExpenseMode ? 'Expense 项目（可选）' : '产品/平台';
                    productLabel.textContent = isQuickExpenseMode ? fallback : (fallback + ' *');
                    productSelect.innerHTML = '<option value="">-- 请选 --</option>';
                    (list || []).forEach(function(name){
                        var op = document.createElement('option');
                        op.value = name;
                        op.textContent = name;
                        productSelect.appendChild(op);
                    });
                    if (current && list.indexOf(current) >= 0) productSelect.value = current;
                }
            }
            if (modeEl) modeEl.addEventListener('change', applyBankRequired);
            applyBankRequired();
        })();
        (function(){
            var btn = document.getElementById('member_dt_toggle');
            var box = document.getElementById('member_dt_box');
            var hidden = document.getElementById('member_use_current_time');
            if (!btn || !box || !hidden) return;
            btn.addEventListener('click', function(){
                var show = box.style.display === 'none';
                box.style.display = show ? 'block' : 'none';
                hidden.value = show ? '0' : '1';
                btn.textContent = show ? '−' : '+';
            });
        })();
        (function(){
            var timeEl = document.getElementById('time');
            if (!timeEl) return;
            function formatTimeInput() {
                var v = (timeEl.value || '').replace(/\D/g, '');
                if (v.length >= 2) {
                    var h = v.substr(0, 2);
                    if (parseInt(h, 10) > 23) h = '23';
                    if (v.length === 2) timeEl.value = h + ':';
                    else if (v.length === 3) timeEl.value = h + ':' + v.substr(2, 1);
                    else timeEl.value = h + ':' + v.substr(2, 2);
                } else {
                    timeEl.value = v;
                }
            }
            timeEl.addEventListener('input', formatTimeInput);
            timeEl.addEventListener('blur', function(){
                var v = (timeEl.value || '').replace(/\D/g, '');
                if (v.length === 3) v = v.substr(0, 2) + '0' + v.substr(2, 1);
                if (v.length >= 2) {
                    var h = Math.min(23, parseInt(v.substr(0, 2), 10) || 0);
                    var m = Math.min(59, parseInt(v.length >= 4 ? v.substr(2, 2) : (v.substr(2, 2) || '0'), 10) || 0);
                    timeEl.value = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
                }
            });
        })();
        (function(){
            var amountEl = document.getElementById('amount');
            var pctEl = document.getElementById('reward_pct');
            var bonusEl = document.getElementById('bonus_hidden');
            var hintEl = document.getElementById('reward_hint');
            var rewardAmountSpan = document.getElementById('reward_amount');
            var rewardTotalSpan = document.getElementById('reward_total');
            function updateReward() {
                var amt = parseFloat((amountEl && amountEl.value || '').replace(/,/g, '')) || 0;
                var pct = parseFloat((pctEl && pctEl.value || '').replace(/,/g, '')) || 0;
                var bonus = pct ? Math.round(amt * pct / 100 * 100) / 100 : 0;
                var total = amt + bonus;
                if (bonusEl) bonusEl.value = bonus;
                if (hintEl && rewardAmountSpan && rewardTotalSpan) {
                    if (pct > 0 && amt > 0) {
                        hintEl.style.display = 'block';
                        rewardAmountSpan.textContent = bonus.toFixed(2);
                        rewardTotalSpan.textContent = total.toFixed(2);
                    } else {
                        hintEl.style.display = 'none';
                    }
                }
            }
            if (amountEl) amountEl.addEventListener('input', updateReward);
            if (amountEl) amountEl.addEventListener('blur', updateReward);
            if (pctEl) pctEl.addEventListener('input', updateReward);
            if (pctEl) pctEl.addEventListener('blur', updateReward);
            var form = amountEl && amountEl.closest('form');
            if (form) form.addEventListener('submit', function(){ updateReward(); });
        })();
        <?php if ($is_admin && $quick !== 'expense'): ?>
        var cb = document.getElementById('edit_dt');
        if (cb) {
            cb.onchange = function() {
                var box = document.getElementById('dt_box');
                if (box) box.style.display = cb.checked ? 'block' : 'none';
            };
        }
        <?php endif; ?>
        (function(){
            var mask = document.getElementById('saved-modal-mask');
            var ok = document.getElementById('saved-modal-ok');
            if (!mask || !ok) return;
            setTimeout(function(){ mask.classList.add('show'); }, 30);
            function closeModal(){ mask.classList.remove('show'); }
            ok.addEventListener('click', closeModal);
            mask.addEventListener('click', function(e){ if (e.target === mask) closeModal(); });
        })();
    </script>
</body>
</html>
