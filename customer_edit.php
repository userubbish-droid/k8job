<?php
require 'config.php';
require 'auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: customers.php');
    exit;
}

$row = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch();
if (!$row) {
    header('Location: customers.php');
    exit;
}

// 选项来源：VERIFY 从 option_sets 表读取
$option_lists = ['verify' => []];
try {
    $stmt = $pdo->prepare("SELECT option_value FROM option_sets WHERE option_type = 'verify' ORDER BY sort_order, option_value");
    $stmt->execute();
    $option_lists['verify'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $option_lists['verify'] = [];
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $register_date = trim($_POST['register_date'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $regular_customer = trim($_POST['regular_customer'] ?? '');
    $verify = trim($_POST['verify'] ?? '');
    $old_total_deposit = str_replace(',', '', trim($_POST['old_total_deposit'] ?? '0'));
    $old_total_withdraw = str_replace(',', '', trim($_POST['old_total_withdraw'] ?? '0'));
    $deposit = str_replace(',', '', trim($_POST['deposit'] ?? '0'));
    $withdraw = str_replace(',', '', trim($_POST['withdraw'] ?? '0'));
    $ref_918kiss = trim($_POST['ref_918kiss'] ?? '');
    $ref_megab = trim($_POST['ref_megab'] ?? '');

    if ($code === '') {
        $err = '客户代码不能为空。';
    } else {
        $old_total_deposit = is_numeric($old_total_deposit) ? (float)$old_total_deposit : 0;
        $old_total_withdraw = is_numeric($old_total_withdraw) ? (float)$old_total_withdraw : 0;
        $deposit = is_numeric($deposit) ? (float)$deposit : 0;
        $withdraw = is_numeric($withdraw) ? (float)$withdraw : 0;

        try {
            $stmt = $pdo->prepare("UPDATE customers SET code=?, name=?, phone=?, remark=?, register_date=?, bank_details=?, regular_customer=?,
                verify=?, old_total_deposit=?, old_total_withdraw=?, deposit=?, withdraw=?, ref_918kiss=?, ref_megab=? WHERE id=?");
            $stmt->execute([
                $code, $name ?: null, $phone ?: null, $remark ?: null, $register_date ?: null, $bank_details ?: null, $regular_customer ?: null,
                $verify ?: null, $old_total_deposit, $old_total_withdraw, $deposit, $withdraw, $ref_918kiss ?: null, $ref_megab ?: null, $id
            ]);
            $msg = '已保存。';
            $row = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $row->execute([$id]);
            $row = $row->fetch();
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>编辑顾客 - <?= htmlspecialchars($row['code']) ?></title>
    <style>
        body { font-family: sans-serif; margin: 20px; max-width: 900px; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        label { display:block; margin-top: 10px; font-weight: 700; font-size: 13px; }
        input, select, textarea { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        button { padding: 8px 16px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; margin-top: 12px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin:0 0 8px;">编辑顾客 - <?= htmlspecialchars($row['code']) ?></h2>
        <p><a href="customers.php">← 返回顾客资料</a></p>
        <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post">
            <div class="grid">
                <div><label>CODE *</label><input name="code" required value="<?= htmlspecialchars($row['code']) ?>"></div>
                <div><label>REGISTER DATE</label><input type="date" name="register_date" value="<?= htmlspecialchars($row['register_date'] ?? '') ?>"></div>
                <div><label>FULL NAME</label><input name="name" value="<?= htmlspecialchars($row['name'] ?? '') ?>"></div>
                <div><label>CONTACT</label><input name="phone" value="<?= htmlspecialchars($row['phone'] ?? '') ?>"></div>
                <div><label>REGULAR CUSTOMER</label><select name="regular_customer"><option value="">-</option><option value="Y" <?= ($row['regular_customer'] ?? '') === 'Y' ? 'selected' : '' ?>>Y</option><option value="N" <?= ($row['regular_customer'] ?? '') === 'N' ? 'selected' : '' ?>>N</option></select></div>
                <div><label>VERIFY</label><select name="verify"><option value="">—</option><?php foreach ($option_lists['verify'] as $v): ?><option value="<?= htmlspecialchars($v) ?>" <?= ($row['verify'] ?? '') === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
            </div>
            <div style="margin-top:12px;"><label>BANK DETAILS</label><input name="bank_details" value="<?= htmlspecialchars($row['bank_details'] ?? '') ?>" placeholder="TNG 160402395453, PBB 8413574015"></div>
            <div style="margin-top:12px;"><label>REMARK</label><input name="remark" value="<?= htmlspecialchars($row['remark'] ?? '') ?>"></div>
            <div class="grid" style="margin-top:12px;">
                <div><label>OLD TOTAL DEPOSIT</label><input name="old_total_deposit" value="<?= htmlspecialchars($row['old_total_deposit'] ?? '0') ?>"></div>
                <div><label>OLD TOTAL WITHDRAW</label><input name="old_total_withdraw" value="<?= htmlspecialchars($row['old_total_withdraw'] ?? '0') ?>"></div>
                <div><label>DEPOSIT</label><input name="deposit" value="<?= htmlspecialchars($row['deposit'] ?? '0') ?>"></div>
                <div><label>WITHDRAW</label><input name="withdraw" value="<?= htmlspecialchars($row['withdraw'] ?? '0') ?>"></div>
                <div><label>918KISS</label><input name="ref_918kiss" value="<?= htmlspecialchars($row['ref_918kiss'] ?? '') ?>"></div>
                <div><label>MEGAB</label><input name="ref_megab" value="<?= htmlspecialchars($row['ref_megab'] ?? '') ?>"></div>
            </div>
            <button type="submit">保存</button>
        </form>
    </div>
</body>
</html>
