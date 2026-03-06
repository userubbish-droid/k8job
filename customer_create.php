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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-wrap" style="max-width: 560px;">
        <div class="page-header">
            <h2>填写顾客资料</h2>
            <p class="breadcrumb"><a href="customers.php">← 返回顾客资料</a></p>
        </div>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <form method="post">
                <div class="form-group">
                    <label>客户代码 *</label>
                    <input name="code" class="form-control" required placeholder="例如 C001" value="<?= htmlspecialchars($_POST['code'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>姓名</label>
                    <input name="name" class="form-control" placeholder="FULL NAME" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>联系电话</label>
                    <input name="phone" class="form-control" placeholder="CONTACT" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>银行资料</label>
                    <input name="bank_details" class="form-control" placeholder="例如 TNG 160402395453、PBB 8413574015" value="<?= htmlspecialchars($_POST['bank_details'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>备注</label>
                    <textarea name="remark" class="form-control" placeholder="REMARK"><?= htmlspecialchars($_POST['remark'] ?? '') ?></textarea>
                </div>
                <p class="form-hint">注册日期将按当前日期自动记录。</p>
                <button type="submit" class="btn btn-primary">保存并继续</button>
            </form>
        </div>
    </div>
</body>
</html>
