<?php
require 'config.php';
require 'auth.php';
require_login();

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day     = trim($_POST['day'] ?? '');
    $time    = trim($_POST['time'] ?? '00:00');
    $mode    = trim($_POST['mode'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $bank    = trim($_POST['bank'] ?? '');
    $product = trim($_POST['product'] ?? '');
    if ($bank === '其他') $bank = trim($_POST['bank_other'] ?? '');
    if ($product === '其他') $product = trim($_POST['product_other'] ?? '');
    $amount  = str_replace(',', '', trim($_POST['amount'] ?? '0'));
    $bonus   = str_replace(',', '', trim($_POST['bonus'] ?? '0'));
    $staff   = trim($_POST['staff'] ?? '');
    $remark  = trim($_POST['remark'] ?? '');

    if ($day === '' || $mode === '') {
        $error = '请填写日期和模式。';
    } elseif (!is_numeric($amount)) {
        $error = '金额请填数字。';
    } else {
        $amount = (float) $amount;
        $bonus  = (float) $bonus;
        $total  = $amount + $bonus;

        $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null, $amount, $bonus, $total, $staff ?: null, $remark ?: null]);
        $saved = true;
    }
}

$today = date('Y-m-d');
$now   = date('H:i');

// 银行/产品下拉：优先从数据库读取（admin 可在管理页维护），没有表/无数据时回退到内置列表
$banks = [];
$products = [];
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
if (!$banks) {
    $banks = ['HLB', 'CASH', 'DOUGLAS', 'KAYDEN', 'RHB', 'CIMB', 'Digi', 'Maxis', 'KAYDEN TNG'];
}
if (!$products) {
    $products = ['MEGA', 'PUSSY', '918KISS', 'JOKER', 'KING855', 'LIVE22', 'ACE333', 'VPOWER', 'LPE888', 'ALIPAY', 'STANDBY'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>记一笔流水 - 算账网</title>
    <style>
        body { font-family: sans-serif; max-width: 520px; margin: 20px auto; padding: 0 16px; }
        h2 { margin-bottom: 12px; }
        .msg { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .msg.ok { background: #d4edda; color: #155724; }
        .msg.err { background: #f8d7da; color: #721c24; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; box-sizing: border-box; margin-top: 4px; }
        button { margin-top: 16px; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <h2>记一笔流水</h2>
    <?php if ($saved): ?>
        <div class="msg ok">已保存。 <a href="transaction_create.php">再记一笔</a> | <a href="transaction_list.php">看流水</a> | <a href="dashboard.php">回首页</a></div>
    <?php elseif ($error): ?>
        <div class="msg err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label>日期 *</label>
        <input type="date" name="day" value="<?= htmlspecialchars($today) ?>" required>

        <label>时间</label>
        <input type="time" name="time" value="<?= htmlspecialchars($now) ?>">

        <label>模式 *</label>
        <select name="mode" required>
            <option value="">-- 请选 --</option>
            <option value="DEPOSIT">DEPOSIT（入）</option>
            <option value="WITHDRAW">WITHDRAW（出）</option>
            <option value="BANK">BANK</option>
            <option value="REBATE">REBATE</option>
            <option value="OTHER">OTHER</option>
        </select>

        <label>客户代码</label>
        <input type="text" name="code" placeholder="如 C004">

        <label>银行/渠道</label>
        <select name="bank" id="bank">
            <option value="">-- 请选 --</option>
            <?php foreach ($banks as $b): ?>
            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
            <option value="其他">其他</option>
        </select>
        <input type="text" name="bank_other" id="bank_other" placeholder="输入其他银行/渠道" style="display:none; margin-top:6px;">

        <label>产品/平台</label>
        <select name="product" id="product">
            <option value="">-- 请选 --</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
            <option value="其他">其他</option>
        </select>
        <input type="text" name="product_other" id="product_other" placeholder="输入其他产品/平台" style="display:none; margin-top:6px;">

        <label>金额 *</label>
        <input type="text" name="amount" placeholder="如 630.00" required>

        <label>奖励/返点</label>
        <input type="text" name="bonus" placeholder="0" value="0">

        <label>员工</label>
        <input type="text" name="staff">

        <label>备注</label>
        <textarea name="remark" rows="2"></textarea>

        <button type="submit">保存</button>
    </form>

    <p style="margin-top: 20px;">
        <a href="dashboard.php">返回首页</a> |
        <a href="transaction_list.php">流水列表</a> |
        <a href="logout.php">退出</a>
    </p>
    <script>
        function toggleOther(selId, inputId) {
            var sel = document.getElementById(selId);
            var inp = document.getElementById(inputId);
            if (sel.value === '其他') { inp.style.display = 'block'; inp.focus(); } else { inp.style.display = 'none'; inp.value = ''; }
        }
        document.getElementById('bank').onchange = function() { toggleOther('bank', 'bank_other'); };
        document.getElementById('product').onchange = function() { toggleOther('product', 'product_other'); };
    </script>
</body>
</html>
