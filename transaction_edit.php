<?php
require 'config.php';
require 'auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$return_to = trim($_GET['return_to'] ?? 'transaction_list.php');
if (strpos($return_to, 'transaction_list.php') !== 0) {
    $return_to = 'transaction_list.php';
}

$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
}
if (!$row) {
    header('Location: ' . $return_to);
    exit;
}

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day     = trim($_POST['day'] ?? '');
    $time    = trim($_POST['time'] ?? '00:00');
    $mode    = trim($_POST['mode'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $bank    = trim($_POST['bank'] ?? '');
    $product = trim($_POST['product'] ?? '');
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    if ($is_admin && $bank === '其他') $bank = trim($_POST['bank_other'] ?? '');
    if ($is_admin && $product === '其他') $product = trim($_POST['product_other'] ?? '');
    $amount  = str_replace(',', '', trim($_POST['amount'] ?? '0'));
    $bonus   = str_replace(',', '', trim($_POST['bonus'] ?? '0'));
    $staff   = trim($_POST['staff'] ?? '');
    $remark  = trim($_POST['remark'] ?? '');
    $return_to = trim($_POST['return_to'] ?? $return_to);
    if (strpos($return_to, 'transaction_list.php') !== 0) $return_to = 'transaction_list.php';

    if ($day === '' || $mode === '') {
        $error = '请填写日期和模式。';
    } elseif (!is_numeric($amount)) {
        $error = '金额请填数字。';
    } else {
        $amount = (float) $amount;
        $bonus  = (float) $bonus;
        $total  = $amount + $bonus;
        $stmt = $pdo->prepare("UPDATE transactions SET day=?, time=?, mode=?, code=?, bank=?, product=?, amount=?, bonus=?, total=?, staff=?, remark=? WHERE id=?");
        $stmt->execute([$day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null, $amount, $bonus, $total, $staff ?: null, $remark ?: null, $id]);
        $saved = true;
        header('Location: ' . $return_to);
        exit;
    }
}

// 银行/产品：admin 可改列表且可选「其他」；员工只能从管理员设置的选项中选择
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$banks = [];
$products = [];
try {
    $banks = $pdo->query("SELECT name FROM banks WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $banks = []; }
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $products = []; }
if ($is_admin) {
    if (!$banks) $banks = ['HLB', 'CASH', 'DOUGLAS', 'KAYDEN', 'RHB', 'CIMB', 'Digi', 'Maxis', 'KAYDEN TNG'];
    if (!$products) $products = ['MEGA', 'PUSSY', '918KISS', 'JOKER', 'KING855', 'LIVE22', 'ACE333', 'VPOWER', 'LPE888', 'ALIPAY', 'STANDBY'];
}

$day    = $row['day'];
$time   = $row['time'];
$mode   = $row['mode'];
$code   = $row['code'] ?? '';
$bank   = $row['bank'] ?? '';
$product= $row['product'] ?? '';
$amount = $row['amount'];
$bonus  = $row['bonus'] ?? 0;
$staff  = $row['staff'] ?? '';
$remark = $row['remark'] ?? '';
// 编辑时：若当前值不在列表中（如已被管理员删除），仍保留显示
if (!$is_admin) {
    if ($bank !== '' && !in_array($bank, $banks, true)) $banks = array_merge([$bank], $banks);
    if ($product !== '' && !in_array($product, $products, true)) $products = array_merge([$product], $products);
}
$bank_other = ($is_admin && !in_array($bank, $banks, true) && $bank !== '') ? $bank : '';
$product_other = ($is_admin && !in_array($product, $products, true) && $product !== '') ? $product : '';
if ($bank_other !== '') $bank = '其他';
if ($product_other !== '') $product = '其他';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>编辑流水 - 算账网</title>
    <style>
        body { font-family: sans-serif; max-width: 520px; margin: 20px auto; padding: 0 16px; }
        h2 { margin-bottom: 12px; }
        .msg { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .msg.err { background: #f8d7da; color: #721c24; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; box-sizing: border-box; margin-top: 4px; }
        button { margin-top: 16px; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <h2>编辑流水 #<?= (int)$row['id'] ?></h2>
    <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">
        <label>日期 *</label>
        <input type="date" name="day" value="<?= htmlspecialchars($day) ?>" required>
        <label>时间</label>
        <input type="time" name="time" value="<?= htmlspecialchars($time) ?>">
        <label>模式 *</label>
        <select name="mode" required>
            <option value="">-- 请选 --</option>
            <option value="DEPOSIT" <?= $mode === 'DEPOSIT' ? 'selected' : '' ?>>DEPOSIT（入）</option>
            <option value="WITHDRAW" <?= $mode === 'WITHDRAW' ? 'selected' : '' ?>>WITHDRAW（出）</option>
            <option value="BANK" <?= $mode === 'BANK' ? 'selected' : '' ?>>BANK</option>
            <option value="REBATE" <?= $mode === 'REBATE' ? 'selected' : '' ?>>REBATE</option>
            <option value="OTHER" <?= $mode === 'OTHER' ? 'selected' : '' ?>>OTHER</option>
        </select>
        <label>客户代码</label>
        <input type="text" name="code" value="<?= htmlspecialchars($code) ?>">
        <label>银行/渠道</label>
        <select name="bank" id="bank">
            <option value="">-- 请选 --</option>
            <?php foreach ($banks as $b): ?>
            <option value="<?= htmlspecialchars($b) ?>" <?= $bank === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
            <?php if ($is_admin): ?><option value="其他" <?= $bank === '其他' ? 'selected' : '' ?>>其他</option><?php endif; ?>
        </select>
        <?php if ($is_admin): ?><input type="text" name="bank_other" id="bank_other" value="<?= htmlspecialchars($bank_other) ?>" placeholder="输入其他银行/渠道" style="margin-top:6px;<?= $bank !== '其他' ? ' display:none;' : '' ?>"><?php endif; ?>
        <label>产品/平台</label>
        <select name="product" id="product">
            <option value="">-- 请选 --</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= $product === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
            <?php if ($is_admin): ?><option value="其他" <?= $product === '其他' ? 'selected' : '' ?>>其他</option><?php endif; ?>
        </select>
        <?php if ($is_admin): ?><input type="text" name="product_other" id="product_other" value="<?= htmlspecialchars($product_other) ?>" placeholder="输入其他产品/平台" style="margin-top:6px;<?= $product !== '其他' ? ' display:none;' : '' ?>"><?php endif; ?>
        <label>金额 *</label>
        <input type="text" name="amount" value="<?= htmlspecialchars($amount) ?>" required>
        <label>奖励/返点</label>
        <input type="text" name="bonus" value="<?= htmlspecialchars($bonus) ?>">
        <label>员工</label>
        <input type="text" name="staff" value="<?= htmlspecialchars($staff) ?>">
        <label>备注</label>
        <textarea name="remark" rows="2"><?= htmlspecialchars($remark) ?></textarea>
        <button type="submit">保存</button>
    </form>
    <p style="margin-top: 16px;">
        <a href="<?= htmlspecialchars($return_to) ?>">返回列表</a> |
        <a href="dashboard.php">首页</a>
    </p>
    <?php if ($is_admin): ?>
    <script>
        function toggleOther(selId, inputId) {
            var sel = document.getElementById(selId);
            var inp = document.getElementById(inputId);
            if (inp && sel.value === '其他') { inp.style.display = 'block'; inp.focus(); } else if (inp) { inp.style.display = 'none'; inp.value = ''; }
        }
        document.getElementById('bank').onchange = function() { toggleOther('bank', 'bank_other'); };
        document.getElementById('product').onchange = function() { toggleOther('product', 'product_other'); };
    </script>
    <?php endif; ?>
</body>
</html>
