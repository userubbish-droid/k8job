<?php
require 'config.php';
require 'auth.php';
require_permission('transaction_create');
$sidebar_current = 'transaction_create';

$saved = false;
$error = '';
$submitted_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    // member：未点击「+」修改日期时间则用当前时间且自动通过审核
    $member_use_current = !$is_admin && isset($_POST['member_use_current_time']) && (string)$_POST['member_use_current_time'] === '1';
    if ($is_admin) {
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

        // member 使用当前时间提交则无需审核，否则待审核
        $status = $is_admin ? 'approved' : ($member_use_current ? 'approved' : 'pending');
        $approved_by = ($status === 'approved') ? (int)($_SESSION['user_id'] ?? 0) : null;
        $approved_at = ($status === 'approved') ? date('Y-m-d H:i:s') : null;
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

$today = date('Y-m-d');
$now   = date('H:i');

// 银行/产品：仅 admin 可“设置”（在 admin_banks / admin_products 管理）；员工只能从已设置的选项中选择
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$banks = [];
$products = [];
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
    <title>记一笔流水 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
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
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 520px;">
        <div class="page-header">
            <h2>记一笔流水</h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_list.php">流水记录</a></p>
        </div>

    <?php if ($saved): ?>
        <div class="card" style="margin-bottom: 20px;">
            <div class="alert alert-success" style="margin-bottom: 0;">
                <?php if ($submitted_status === 'pending'): ?>已提交，等待管理员批准。<strong>批准后</strong>才会在「银行与产品」页的 In/Out 中显示。<?php elseif (!$is_admin): ?>✔ Done<?php else: ?>已保存并生效，已计入「银行与产品」In/Out。<?php endif; ?>
            </div>
            <?php if (!empty($saved_code) || !empty($saved_product) || $saved_mode === 'WITHDRAW'): ?>
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 14px;">
                <div style="margin-bottom: 4px;"><strong><?= htmlspecialchars($saved_mode) ?></strong> <?= htmlspecialchars($saved_code) ?> <?= htmlspecialchars($saved_product) ?> · 金额 <?= number_format($saved_amount, 2) ?><?= $saved_reward_pct !== null ? '，奖励 ' . number_format($saved_reward_pct, 0) . '%' : '' ?> · 总数 <strong><?= number_format($saved_total, 2) ?></strong></div>
                <?php if ($saved_mode === 'WITHDRAW' && !empty($saved_code)): ?>
                <div class="form-hint" style="margin-bottom:4px;">顾客姓名：<?= htmlspecialchars($saved_customer_name ?: '—') ?></div>
                <div class="form-hint">银行资料：<?= htmlspecialchars($saved_customer_bank ?: '—') ?></div>
                <?php endif; ?>
                <?php if (!empty($saved_product)): ?>
                <div class="form-hint" style="margin-top:4px;"><?= htmlspecialchars($saved_code) ?> 的 <?= htmlspecialchars($saved_product) ?> 账号：<?= htmlspecialchars($saved_account) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="success-actions">
                <a href="transaction_create.php">再记一笔</a>
                <a href="transaction_list.php">看流水</a>
                <a href="dashboard.php">回首页</a>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-error"><?= $is_admin ? htmlspecialchars($error) : '✗ Faild' ?></div>
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
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
            </div>
            <p class="form-hint" style="margin-top:4px;">不勾选则使用当前时间。可输入数字如 1513 自动变为 15:13。</p>
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
                    <label>模式 *</label>
                    <select name="mode" id="mode" class="form-control" required>
                        <option value="">-- 请选 --</option>
                        <option value="DEPOSIT">DEPOSIT（入）</option>
                        <option value="WITHDRAW">WITHDRAW（出）</option>
                        <option value="FREE">FREE</option>
                        <option value="FREE WITHDRAW">FREE WITHDRAW</option>
                        <option value="EXPENSE">EXPENSE（开销）</option>
                        <option value="BANK">BANK</option>
                        <option value="REBATE">REBATE</option>
                        <option value="OTHER">OTHER</option>
                    </select>
                </div>
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
                        <?php if ($is_admin): ?><option value="其他">其他</option><?php endif; ?>
                    </select>
                    <?php if ($is_admin): ?><input type="text" name="bank_other" id="bank_other" class="form-control" placeholder="其他银行" style="display:none; margin-top:6px;"><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>产品/平台 *</label>
                    <?php if (!$is_admin && empty($products)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="product" id="product" class="form-control" required title="必选，否则银行与产品页的 In/Out 不会统计">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                        <?php if ($is_admin): ?><option value="其他">其他</option><?php endif; ?>
                    </select>
                    <?php if ($is_admin): ?><input type="text" name="product_other" id="product_other" class="form-control" placeholder="其他产品" style="display:none; margin-top:6px;"><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">金额</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>金额 *</label>
                    <input type="text" name="amount" id="amount" class="form-control" placeholder="如 630.00" required>
                </div>
                <div class="form-group">
                    <label>奖励/返点 %</label>
                    <input type="text" name="reward_pct" id="reward_pct" class="form-control" placeholder="选填，如 5" inputmode="decimal" title="按金额的百分比计算奖励">
                    <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                </div>
            </div>
            <p class="form-hint" id="reward_hint" style="margin-top:4px; display:none;">奖励 <span id="reward_amount">0</span>，总数 <strong id="reward_total">0</strong></p>
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
        <a href="transaction_list.php">流水记录</a><span>·</span>
        <a href="logout.php">退出</a>
    </p>
    </div>
        </main>
    </div>
    <script>
        (function() {
            var customerData = <?= json_encode(array_column($customers, null, 'code')) ?>;
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
                var noBankModes = ['REBATE', 'FREE', 'FREE WITHDRAW', 'EXPENSE'];
                if (bankSelect && bankMark) {
                    if (noBankModes.indexOf(mode) >= 0) {
                        bankSelect.removeAttribute('required');
                        bankMark.style.display = 'none';
                    } else {
                        bankSelect.setAttribute('required', 'required');
                        bankMark.style.display = 'inline';
                    }
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
