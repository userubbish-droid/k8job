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
    <title>NEW REGISTER CUSTOMER - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .form-modal {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(152, 176, 242, 0.32);
            border-radius: 14px;
            box-shadow: var(--card-shadow);
            padding: 18px 18px 16px;
        }
        .form-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(140, 165, 235, 0.28);
            margin-bottom: 14px;
        }
        .form-modal-title { margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; }
        .form-modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(148,163,184,0.55);
            background: #fff;
            color: #334155;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            line-height: 1;
            font-size: 18px;
        }
        .form-grid-2 { display: grid; grid-template-columns: 1fr; gap: 14px; }
        .form-section {
            border: 1px solid rgba(59, 130, 246, 0.18);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(239, 246, 255, 0.7) 0%, rgba(255,255,255,0.92) 100%);
            padding: 12px 12px 10px;
        }
        .form-section h4 { margin: 0 0 10px; font-size: 13px; font-weight: 800; color: #1e3a8a; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(140, 165, 235, 0.22);
        }
        @media (max-width: 720px) { .form-grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 860px;">
        <div class="page-header">
            <h2>新增客户</h2>
            <p class="breadcrumb"><a href="customers.php">← 返回顾客列表</a></p>
        </div>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="form-modal">
            <div class="form-modal-head">
                <div class="form-modal-title">New Customer</div>
                <a class="form-modal-close" href="customers.php" aria-label="关闭">×</a>
            </div>
            <form method="post" autocomplete="off">
                <div class="form-grid-2">
                    <div class="form-section">
                        <h4>Personal Information</h4>
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
                            <label>Recommend</label>
                            <input name="recommend" class="form-control" placeholder="推荐人/推荐码" value="<?= htmlspecialchars($_POST['recommend'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>备注</label>
                            <textarea name="remark" class="form-control" placeholder="REMARK"><?= htmlspecialchars($_POST['remark'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="customers.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">保存并继续</button>
                </div>
            </form>
        </div>
    </div>
        </main>
    </div>
</body>
</html>
