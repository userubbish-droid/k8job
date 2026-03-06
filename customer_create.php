<?php
require 'config.php';
require 'auth.php';
require_login();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');

    if ($code === '') {
        $err = '请输入客户代码。';
    } else {
        try {
            $register_date = date('Y-m-d');
            $stmt = $pdo->prepare("INSERT INTO customers (code, name, phone, remark, created_by, register_date, bank_details) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $code,
                $name !== '' ? $name : null,
                $phone !== '' ? $phone : null,
                $remark !== '' ? $remark : null,
                (int)($_SESSION['user_id'] ?? 0),
                $register_date,
                $bank_details !== '' ? $bank_details : null
            ]);
            $new_id = (int) $pdo->lastInsertId();
            header('Location: customer_edit.php?id=' . $new_id . '&created=1');
            exit;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                $err = '该客户代码已存在，请换一个。';
            } else {
                $err = '保存失败：' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>填写顾客资料 - 算账网</title>
    <style>
        body { font-family: sans-serif; margin: 20px; max-width: 700px; background: #f5f5f5; }
        .card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        label { display: block; margin-top: 12px; font-weight: 700; font-size: 13px; }
        input, textarea { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        textarea { min-height: 60px; }
        button { padding: 10px 20px; background: #007bff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; margin-top: 16px; }
        button:hover { background: #0056b3; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        a { color: #007bff; }
        .muted { font-size: 12px; color: #666; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin:0 0 8px;">填写顾客资料</h2>
        <p class="muted"><a href="customers.php">← 返回顾客资料</a></p>
        <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post">
            <div>
                <label>客户代码 *</label>
                <input name="code" required placeholder="例如 C001" value="<?= htmlspecialchars($_POST['code'] ?? '') ?>">
            </div>
            <div>
                <label>姓名</label>
                <input name="name" placeholder="FULL NAME" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <div>
                <label>联系电话</label>
                <input name="phone" placeholder="CONTACT" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            <div>
                <label>银行资料</label>
                <input name="bank_details" placeholder="例如 TNG 160402395453、PBB 8413574015" value="<?= htmlspecialchars($_POST['bank_details'] ?? '') ?>">
            </div>
            <div>
                <label>备注</label>
                <textarea name="remark" placeholder="REMARK"><?= htmlspecialchars($_POST['remark'] ?? '') ?></textarea>
            </div>
            <p class="muted">注册日期将按当前日期自动记录。</p>
            <button type="submit">保存并继续</button>
        </form>
    </div>
</body>
</html>
