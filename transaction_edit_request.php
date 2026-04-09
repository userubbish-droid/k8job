<?php
require 'config.php';
require 'auth.php';
require_login();
require_permission('transaction_list');
require_permission('transaction_edit_request');

$sidebar_current = 'transaction_list';
$company_id = current_company_id();

// 尝试确保表存在（仍建议执行 migrate_transaction_edit_requests.sql）
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS transaction_edit_requests (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      transaction_id INT UNSIGNED NOT NULL,
      day DATE NOT NULL,
      time TIME NOT NULL,
      mode VARCHAR(32) NOT NULL,
      code VARCHAR(255) NULL,
      bank VARCHAR(255) NULL,
      product VARCHAR(255) NULL,
      amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      burn DECIMAL(14,2) NULL DEFAULT NULL,
      bonus DECIMAL(14,2) NOT NULL DEFAULT 0,
      total DECIMAL(14,2) NOT NULL DEFAULT 0,
      remark TEXT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      created_by INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL,
      approved_by INT UNSIGNED NULL,
      approved_at DATETIME NULL,
      approved_by_tg VARCHAR(128) NULL,
      PRIMARY KEY (id),
      KEY idx_company_status (company_id, status, created_at),
      KEY idx_txn (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$id = (int)($_GET['id'] ?? 0);
$return_to = trim((string)($_GET['return_to'] ?? 'transaction_list.php'));
if (strpos($return_to, 'transaction_list.php') !== 0) {
    $return_to = 'transaction_list.php';
}

$row = null;
if ($id > 0) {
    $sql = "SELECT id, day, time, mode, code, bank, product, amount, burn, bonus, total, remark, status
            FROM transactions WHERE id = ? AND company_id = ?";
    try {
        $pdo->query("SELECT deleted_at FROM transactions LIMIT 0");
        $sql .= " AND deleted_at IS NULL";
    } catch (Throwable $e) {}
    $st = $pdo->prepare($sql);
    $st->execute([$id, $company_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$row) {
    header('Location: ' . $return_to);
    exit;
}

$err = '';
$msg = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day = trim((string)($_POST['day'] ?? ''));
    $timeRaw = trim((string)($_POST['time'] ?? '00:00'));
    $mode = trim((string)($_POST['mode'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $bank = trim((string)($_POST['bank'] ?? ''));
    $product = trim((string)($_POST['product'] ?? ''));
    $amountRaw = str_replace(',', '', trim((string)($_POST['amount'] ?? '0')));
    $burnRaw = str_replace(',', '', trim((string)($_POST['burn'] ?? '')));
    $bonusRaw = str_replace(',', '', trim((string)($_POST['bonus'] ?? '0')));
    $remark = trim((string)($_POST['remark'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $err = '日期格式错误。';
    } elseif (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $timeRaw)) {
        $err = '时间格式错误。';
    } elseif ($mode === '') {
        $err = '请选择模式。';
    } elseif (!is_numeric($amountRaw) || !is_numeric($bonusRaw) || ($burnRaw !== '' && !is_numeric($burnRaw))) {
        $err = '金额格式错误。';
    } else {
        $time = $timeRaw . ':00';
        $amount = round((float)$amountRaw, 2);
        $bonus = round((float)$bonusRaw, 2);
        $burn = $burnRaw !== '' ? round((float)$burnRaw, 2) : null;
        $total = round($amount + $bonus, 2);
        $uid = (int)($_SESSION['user_id'] ?? 0);

        try {
            $ins = $pdo->prepare("INSERT INTO transaction_edit_requests
                (company_id, transaction_id, day, time, mode, code, bank, product, amount, burn, bonus, total, remark, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())");
            $ins->execute([
                $company_id,
                $id,
                $day,
                $time,
                $mode,
                $code !== '' ? $code : null,
                $bank !== '' ? $bank : null,
                $product !== '' ? $product : null,
                $amount,
                $burn,
                $bonus,
                $total,
                $remark !== '' ? $remark : null,
                $uid,
            ]);
            $rid = (int)$pdo->lastInsertId();

            if (file_exists(__DIR__ . '/inc/notify.php')) {
                require_once __DIR__ . '/inc/notify.php';
                if (function_exists('send_pending_txn_edit_request_notify')) {
                    send_pending_txn_edit_request_notify($pdo, $company_id, $rid);
                }
            }

            $saved = true;
            $msg = '已提交修改申请，等待批准后才会生效。';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

function teq_v($k, $fallback = '') {
    return htmlspecialchars((string)($k ?? $fallback), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Edit #<?= (int)$row['id'] ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 720px;">
            <div class="page-header">
                <h2>Request Edit #<?= (int)$row['id'] ?></h2>
                <p class="breadcrumb"><a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__('btn_back'), ENT_QUOTES, 'UTF-8') ?></a></p>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="card">
                <form method="post">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Day</label>
                            <input type="date" name="day" class="form-control" value="<?= teq_v($_POST['day'] ?? $row['day']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Time</label>
                            <input type="text" name="time" class="form-control" value="<?= teq_v($_POST['time'] ?? substr((string)($row['time'] ?? '00:00'), 0, 5)) ?>" placeholder="HH:MM" required>
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Mode</label>
                            <?php $m = (string)($_POST['mode'] ?? $row['mode']); ?>
                            <select name="mode" class="form-control" required>
                                <option value="">-- Select --</option>
                                <?php foreach (['DEPOSIT','WITHDRAW','FREE','FREE WITHDRAW','BANK','REBATE','EXPENSE','OTHER'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $m === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Customer</label>
                            <input type="text" name="code" class="form-control" value="<?= teq_v($_POST['code'] ?? $row['code']) ?>">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Bank</label>
                            <input type="text" name="bank" class="form-control" value="<?= teq_v($_POST['bank'] ?? $row['bank']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Product</label>
                            <input type="text" name="product" class="form-control" value="<?= teq_v($_POST['product'] ?? $row['product']) ?>">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Amount</label>
                            <input type="text" name="amount" class="form-control" value="<?= teq_v($_POST['amount'] ?? $row['amount']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Burn</label>
                            <input type="text" name="burn" class="form-control" value="<?= teq_v($_POST['burn'] ?? ($row['burn'] ?? '')) ?>" placeholder="e.g. 0.17">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Bonus</label>
                            <input type="text" name="bonus" class="form-control" value="<?= teq_v($_POST['bonus'] ?? ($row['bonus'] ?? 0)) ?>">
                        </div>
                        <div class="form-group">
                            <label>Total</label>
                            <input type="text" class="form-control" value="<?= teq_v(($row['total'] ?? 0)) ?>" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Remark</label>
                        <textarea name="remark" class="form-control" rows="2"><?= teq_v($_POST['remark'] ?? ($row['remark'] ?? '')) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" <?= $saved ? 'disabled' : '' ?>>Submit request</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>

