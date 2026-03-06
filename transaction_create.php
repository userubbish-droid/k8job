<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_create');

$saved = false;
$error = '';
$submitted_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    $can_edit_dt = $is_admin && !empty($_POST['edit_dt']);
    $day     = $can_edit_dt ? trim($_POST['day'] ?? '') : date('Y-m-d');
    $time    = $can_edit_dt ? trim($_POST['time'] ?? '00:00') : date('H:i:s');
    $mode    = trim($_POST['mode'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $bank    = trim($_POST['bank'] ?? '');
    $product = trim($_POST['product'] ?? '');
    if ($is_admin && $bank === '其他') $bank = trim($_POST['bank_other'] ?? '');
    if ($is_admin && $product === '其他') $product = trim($_POST['product_other'] ?? '');
    $amount    = str_replace(',', '', trim($_POST['amount'] ?? '0'));
    $reward_pct = str_replace(',', '', trim($_POST['reward_pct'] ?? ''));
    $bonus_fix  = str_replace(',', '', trim($_POST['bonus'] ?? '0'));
    $remark   = trim($_POST['remark'] ?? '');

    if ($day === '' || $mode === '') {
        $error = '请填写日期和模式。';
    } elseif (!is_numeric($amount)) {
        $error = '金额请填数字。';
    } else {
        $amount = (float) $amount;
        if ($reward_pct !== '' && is_numeric($reward_pct)) {
            $bonus = round($amount * (float)$reward_pct / 100, 2);
        } else {
            $bonus = is_numeric($bonus_fix) ? (float) $bonus_fix : 0;
        }
        $total  = $amount + $bonus;

        $status = $is_admin ? 'approved' : 'pending';
        $approved_by = $is_admin ? (int)($_SESSION['user_id'] ?? 0) : null;
        $approved_at = $is_admin ? date('Y-m-d H:i:s') : null;
        $staff = (string) ($_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? ''));

        $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null,
            $amount, $bonus, $total, $staff ?: null, $remark ?: null,
            $status, (int)($_SESSION['user_id'] ?? 0), $approved_by, $approved_at
        ]);
        $saved = true;
        $submitted_status = $status;
        $saved_mode = $mode;
        $saved_code = $code;
        $saved_product = $product;
        $saved_amount = $amount;
        $saved_bonus = $bonus;
        $saved_total = $total;
        $saved_reward_pct = ($reward_pct !== '' && is_numeric($reward_pct)) ? (float)$reward_pct : null;
        $saved_account = '';
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
    }
}

$today = date('Y-m-d');
$now   = date('H:i');

// 银行/产品：仅 admin 可“设置”（在 admin_banks / admin_products 管理）；员工只能从已设置的选项中选择
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$banks = [];
$products = [];
// 客户代码下拉选项
$customers = [];
try {
    $customers = $pdo->query("SELECT code, name FROM customers WHERE is_active = 1 ORDER BY code ASC")->fetchAll();
} catch (Throwable $e) {
    $customers = [];
}
try {
    $banks = $pdo->query("SELECT name FROM banks WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $banks = [];
}
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}
// admin 无数据时用内置列表，并可选「其他」手填；员工只能用管理员设置的列表，不能改
if ($is_admin) {
    if (!$banks) $banks = ['HLB', 'CASH', 'DOUGLAS', 'KAYDEN', 'RHB', 'CIMB', 'Digi', 'Maxis', 'KAYDEN TNG'];
    if (!$products) $products = ['MEGA', 'PUSSY', '918KISS', 'JOKER', 'KING855', 'LIVE22', 'ACE333', 'VPOWER', 'LPE888', 'ALIPAY', 'STANDBY'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>记一笔流水 - 算账网</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .form-section { margin-bottom: 20px; }
        .form-section-title { font-size: 12px; color: var(--muted); font-weight: 600; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.03em; }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .calc-box { margin: 12px 0; padding: 12px 14px; background: #f0f9ff; border-radius: 8px; font-size: 14px; color: #0c4a6e; }
        .success-actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; }
        .success-actions a { padding: 8px 14px; background: #fff; border: 1px solid #a7f3d0; border-radius: 6px; color: #059669; text-decoration: none; font-size: 13px; }
        .success-actions a:hover { background: #ecfdf5; }
        @media (max-width: 640px) {
            .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="page-wrap" style="max-width: 520px;">
        <div class="page-header">
            <h2>记一笔流水</h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_list.php">流水列表</a></p>
        </div>

    <?php if ($saved): ?>
        <div class="card" style="margin-bottom: 20px;">
            <div class="alert alert-success" style="margin-bottom: 0;">
                <?php if ($submitted_status === 'pending'): ?>已提交，等待管理员批准。<?php else: ?>已保存并生效。<?php endif; ?>
            </div>
            <?php if (!empty($saved_code) || !empty($saved_product)): ?>
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 14px;">
                <div style="margin-bottom: 4px;"><strong><?= htmlspecialchars($saved_mode) ?></strong> <?= htmlspecialchars($saved_code) ?> <?= htmlspecialchars($saved_product) ?> · 金额 <?= number_format($saved_amount, 2) ?><?= $saved_reward_pct !== null ? '，奖励 ' . number_format($saved_reward_pct, 0) . '%' : '' ?> · 总数 <strong><?= number_format($saved_total, 2) ?></strong></div>
                <div class="form-hint"><?= htmlspecialchars($saved_code) ?> 的 <?= htmlspecialchars($saved_product) ?> 账号：<?= htmlspecialchars($saved_account) ?></div>
            </div>
            <?php endif; ?>
            <div class="success-actions">
                <a href="transaction_create.php">再记一笔</a>
                <a href="transaction_list.php">看流水</a>
                <a href="dashboard.php">回首页</a>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
    <form method="post">
        <?php if ($is_admin): ?>
        <div class="form-section">
            <div class="form-section-title">日期 / 时间</div>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="checkbox" name="edit_dt" value="1" id="edit_dt" style="width:18px; height:18px;">
                <span>需要修改日期/时间</span>
            </label>
            <div id="dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间</label><input type="time" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>"></div>
                </div>
            </div>
            <p class="form-hint" style="margin-top:4px;">不勾选则使用当前时间。</p>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="form-section-title">基本信息</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>模式 *</label>
                    <select name="mode" class="form-control" required>
                        <option value="">-- 请选 --</option>
                        <option value="DEPOSIT">DEPOSIT（入）</option>
                        <option value="WITHDRAW">WITHDRAW（出）</option>
                        <option value="BANK">BANK</option>
                        <option value="REBATE">REBATE</option>
                        <option value="OTHER">OTHER</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>客户代码</label>
                    <?php if (empty($customers)): ?>
                    <select name="code" class="form-control" disabled><option value="">-- 暂无 --</option></select>
                    <p class="form-hint"><a href="customers.php">先去添加客户</a></p>
                    <?php else: ?>
                    <select name="code" class="form-control">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code'] . (empty($c['name']) ? '' : ' - ' . $c['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>银行/渠道</label>
                    <?php if (!$is_admin && empty($banks)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="bank" id="bank" class="form-control">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($banks as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
                        <?php if ($is_admin): ?><option value="其他">其他</option><?php endif; ?>
                    </select>
                    <?php if ($is_admin): ?><input type="text" name="bank_other" id="bank_other" class="form-control" placeholder="其他银行" style="display:none; margin-top:6px;"><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>产品/平台</label>
                    <?php if (!$is_admin && empty($products)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="product" id="product" class="form-control">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                        <?php if ($is_admin): ?><option value="其他">其他</option><?php endif; ?>
                    </select>
                    <?php if ($is_admin): ?><input type="text" name="product_other" id="product_other" class="form-control" placeholder="其他产品" style="display:none; margin-top:6px;"><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">金额与奖励</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>金额 *</label>
                    <input type="text" name="amount" id="amount" class="form-control" placeholder="如 630.00" required>
                </div>
                <div class="form-group">
                    <label>奖励 %</label>
                    <input type="text" name="reward_pct" id="reward_pct" class="form-control" placeholder="如 10" value="">
                    <p class="form-hint">填数字即百分比，不填可用下方固定金额</p>
                </div>
            </div>
            <div class="form-group">
                <label>奖励/返点（固定金额）</label>
                <input type="text" name="bonus" id="bonus" class="form-control" placeholder="0" value="0">
            </div>
            <div id="calc_summary" class="calc-box" style="display:none;"><span id="calc_text"></span></div>
        </div>

        <div class="form-section">
            <div class="form-group">
                <label>备注</label>
                <textarea name="remark" class="form-control" rows="2" placeholder="选填"></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">保存</button>
    </form>
    </div>

    <p class="breadcrumb" style="margin-top:16px;">
        <a href="dashboard.php">返回首页</a><span>·</span>
        <a href="transaction_list.php">流水列表</a><span>·</span>
        <a href="logout.php">退出</a>
    </p>
    </div>
    <script>
        (function() {
            var amountEl = document.getElementById('amount');
            var rewardPctEl = document.getElementById('reward_pct');
            var bonusEl = document.getElementById('bonus');
            var summaryEl = document.getElementById('calc_summary');
            var textEl = document.getElementById('calc_text');
            function updateCalc() {
                var amount = parseFloat((amountEl && amountEl.value) ? amountEl.value.replace(/,/g,'') : '') || 0;
                var pct = parseFloat((rewardPctEl && rewardPctEl.value) ? rewardPctEl.value.replace(/,/g,'') : '') || NaN;
                var bonusFix = parseFloat((bonusEl && bonusEl.value) ? bonusEl.value.replace(/,/g,'') : '') || 0;
                var bonus = 0;
                if (!isNaN(pct) && pct !== 0) {
                    bonus = Math.round(amount * pct / 100 * 100) / 100;
                } else {
                    bonus = bonusFix;
                }
                var total = amount + bonus;
                if (amount > 0) {
                    summaryEl.style.display = 'block';
                    textEl.textContent = '奖励金额：' + bonus.toFixed(2) + '，总数：' + total.toFixed(2);
                } else {
                    summaryEl.style.display = 'none';
                }
            }
            if (amountEl) amountEl.addEventListener('input', updateCalc);
            if (amountEl) amountEl.addEventListener('change', updateCalc);
            if (rewardPctEl) rewardPctEl.addEventListener('input', updateCalc);
            if (bonusEl) bonusEl.addEventListener('input', updateCalc);
            updateCalc();
        })();
        <?php if ($is_admin): ?>
        function toggleOther(selId, inputId) {
            var sel = document.getElementById(selId);
            var inp = document.getElementById(inputId);
            if (inp && sel.value === '其他') { inp.style.display = 'block'; inp.focus(); } else if (inp) { inp.style.display = 'none'; inp.value = ''; }
        }
        document.getElementById('bank').onchange = function() { toggleOther('bank', 'bank_other'); };
        document.getElementById('product').onchange = function() { toggleOther('product', 'product_other'); };

        var cb = document.getElementById('edit_dt');
        if (cb) {
            cb.onchange = function() {
                var box = document.getElementById('dt_box');
                if (box) box.style.display = cb.checked ? 'block' : 'none';
            };
        }
        <?php endif; ?>
    </script>
</body>
</html>
