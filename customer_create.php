<?php
require 'config.php';
require 'auth.php';
require_permission('customer_create');
$sidebar_current = 'customer_create';

$msg = '';
$err = '';

// 建议下一个客户代码：按现有 C001、C009 等递增，下一个为 C010
$suggested_code = 'C001';
try {
    $stmt = $pdo->query("SELECT code FROM customers");
    $max_num = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $c = trim($row['code'] ?? '');
        if (preg_match('/^C(\d+)$/i', $c, $m)) {
            $n = (int) $m[1];
            if ($n > $max_num) $max_num = $n;
        }
    }
    $suggested_code = 'C' . sprintf('%03d', $max_num + 1);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $recommend = trim($_POST['recommend'] ?? '');

    if ($code === '') {
        $err = '请输入客户代码。';
    } else {
        $conflicts = [];
        if ($phone !== '') {
            $stmt = $pdo->prepare("SELECT code FROM customers WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $row = $stmt->fetch();
            if ($row) {
                $conflicts[] = $row['code'] . ' 电话号码同样';
            }
        }
        if ($bank_details !== '') {
            $stmt = $pdo->prepare("SELECT code FROM customers WHERE bank_details = ? LIMIT 1");
            $stmt->execute([$bank_details]);
            $row = $stmt->fetch();
            if ($row) {
                $conflicts[] = $row['code'] . ' 银行号码同样';
            }
        }
        if (!empty($conflicts)) {
            $err = '顾客已注册：' . implode('；', $conflicts);
        } else {
            try {
                $register_date = date('Y-m-d');
                $stmt = $pdo->prepare("INSERT INTO customers (code, name, phone, remark, created_by, register_date, bank_details, recommend) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $code,
                    $name !== '' ? $name : null,
                    $phone !== '' ? $phone : null,
                    $remark !== '' ? $remark : null,
                    (int)($_SESSION['user_id'] ?? 0),
                    $register_date,
                    $bank_details !== '' ? $bank_details : null,
                    $recommend !== '' ? $recommend : null
                ]);
                $new_id = (int) $pdo->lastInsertId();
                header('Location: customer_edit.php?id=' . $new_id . '&created=1');
                exit;
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    $err = '该客户代码已存在，请换一个。';
                } elseif (strpos($e->getMessage(), 'recommend') !== false) {
                    $err = '请先在 phpMyAdmin 执行 migrate_customers_recommend.sql 后再保存。';
                } else {
                    $err = '保存失败：' . $e->getMessage();
                }
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
    <title>新增顾客 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 560px;">
        <div class="page-header">
            <h2>新增顾客</h2>
            <p class="breadcrumb"><a href="customers.php">← 返回顾客列表</a></p>
        </div>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="card">
            <form method="post">
                <div class="form-group">
                    <label>客户代码 *</label>
                    <input name="code" class="form-control" required placeholder="<?= htmlspecialchars($suggested_code) ?>" value="<?= htmlspecialchars($_POST['code'] ?? $suggested_code) ?>">
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
                <div class="form-group">
                    <label>Recommend</label>
                    <input name="recommend" class="form-control" placeholder="推荐人/推荐码" value="<?= htmlspecialchars($_POST['recommend'] ?? '') ?>">
                </div>
                <p class="form-hint">注册日期将按当前日期自动记录。</p>
                <button type="submit" class="btn btn-primary">保存并继续</button>
            </form>
        </div>
    </div>
        </main>
    </div>
</body>
</html>
