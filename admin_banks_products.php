<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_banks_products';

function _ensure_balance_adjust_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_adjust (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adjust_type ENUM('bank','product') NOT NULL,
        name VARCHAR(80) NOT NULL,
        initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        UNIQUE KEY (adjust_type, name)
    )");
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_bank') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入银行/渠道名称。');
            try {
                $stmt = $pdo->prepare("INSERT INTO banks (name, sort_order) VALUES (?, ?)");
                $stmt->execute([$name, $sort]);
                $msg = '已添加银行/渠道。';
            } catch (Throwable $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    throw new RuntimeException('银行/渠道「' . htmlspecialchars($name) . '」已存在，请换一个名称。');
                }
                throw $e;
            }
        } elseif ($action === 'create_product') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('请输入产品名称。');
            try {
                $stmt = $pdo->prepare("INSERT INTO products (name, sort_order) VALUES (?, ?)");
                $stmt->execute([$name, $sort]);
                $msg = '已添加产品。';
            } catch (Throwable $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    throw new RuntimeException('产品「' . htmlspecialchars($name) . '」已存在，请换一个名称。');
                }
                throw $e;
            }
        } elseif ($action === 'toggle_bank') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE banks SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        } elseif ($action === 'toggle_product') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('参数错误。');
            $stmt = $pdo->prepare("UPDATE products SET is_active = IF(is_active=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
            $msg = '已更新状态。';
        } elseif ($action === 'do_transfer') {
            $from_bank = trim($_POST['from_bank'] ?? '');
            $to_bank   = trim($_POST['to_bank'] ?? '');
            $amount    = str_replace(',', '', trim($_POST['amount'] ?? '0'));
            if ($from_bank === '' || $to_bank === '' || $from_bank === $to_bank) throw new RuntimeException('请选择不同的转出、转入银行。');
            if (!is_numeric($amount) || (float)$amount <= 0) throw new RuntimeException('请输入正确金额。');
            $amount = (float)$amount;
            $day = date('Y-m-d');
            $time = date('H:i:s');
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $staff = (string)($_SESSION['user_name'] ?? $uid);
            $remark_out = '转至 ' . $to_bank;
            $remark_in  = '来自 ' . $from_bank;
            try {
                $cols = "day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals = "?, ?, 'WITHDRAW', NULL, ?, NULL, ?, 0, ?, ?, ?, 'approved', ?, ?, NOW(), 1";
                $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($vals)");
                $stmt->execute([$day, $time, $from_bank, $amount, $amount, $staff, $remark_out, $uid, $uid]);
                $cols2 = "day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals2 = "?, ?, 'DEPOSIT', NULL, ?, NULL, ?, 0, ?, ?, ?, 'approved', ?, ?, NOW(), 1";
                $stmt2 = $pdo->prepare("INSERT INTO transactions ($cols2) VALUES ($vals2)");
                $stmt2->execute([$day, $time, $to_bank, $amount, $amount, $staff, $remark_in, $uid, $uid]);
                $msg = $from_bank . ' 转 ' . number_format($amount, 2) . ' 至 ' . $to_bank . ' 已记录，可在流水记录中查看（member 不可见）。';
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'hide_from_member') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    throw new RuntimeException('请先在 phpMyAdmin 执行 migrate_hide_from_member.sql 后再使用转账功能。');
                }
                throw $e;
            }
        } elseif ($action === 'do_product_topup') {
            $product = trim($_POST['product'] ?? '');
            $amount  = str_replace(',', '', trim($_POST['amount'] ?? '0'));
            if ($product === '') throw new RuntimeException('请选择产品。');
            if (!is_numeric($amount) || (float)$amount <= 0) throw new RuntimeException('请输入正确加额数目。');
            $amount = (float)$amount;
            $day = date('Y-m-d');
            $time = date('H:i:s');
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $staff = (string)($_SESSION['user_name'] ?? $uid);
            try {
                $cols = "day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals = "?, ?, 'DEPOSIT', NULL, NULL, ?, ?, 0, ?, ?, '产品加额', 'approved', ?, ?, NOW(), 1";
                $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($vals)");
                $stmt->execute([$day, $time, $product, $amount, $amount, $staff, $uid, $uid]);
                $msg = $product . ' 已加额 ' . number_format($amount, 2) . '，Balance 已更新。';
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'hide_from_member') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    throw new RuntimeException('请先在 phpMyAdmin 执行 migrate_hide_from_member.sql 后再使用加额功能。');
                }
                throw $e;
            }
        } elseif ($action === 'save_balance') {
            $type = $_POST['adjust_type'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $val = str_replace(',', '', trim($_POST['balance'] ?? '0'));
            if ($name === '' || !in_array($type, ['bank', 'product'], true) || !is_numeric($val)) throw new RuntimeException('参数错误。');
            try {
                $stmt = $pdo->prepare("INSERT INTO balance_adjust (adjust_type, name, initial_balance, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE initial_balance = VALUES(initial_balance), updated_at = NOW(), updated_by = VALUES(updated_by)");
                $stmt->execute([$type, $name, (float)$val, (int)($_SESSION['user_id'] ?? 0)]);
                $msg = '已更新为更改余额。';
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'balance_adjust') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
                    _ensure_balance_adjust_table($pdo);
                    try {
                        $stmt = $pdo->prepare("INSERT INTO balance_adjust (adjust_type, name, initial_balance, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE initial_balance = VALUES(initial_balance), updated_at = NOW(), updated_by = VALUES(updated_by)");
                        $stmt->execute([$type, $name, (float)$val, (int)($_SESSION['user_id'] ?? 0)]);
                        $msg = '已更新为更改余额。';
                    } catch (Throwable $e2) {
                        throw new RuntimeException('无法创建 balance_adjust 表，请检查数据库用户是否有建表权限，或在 phpMyAdmin 执行 migrate_balance_adjust.sql。');
                    }
                } else {
                    throw $e;
                }
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$banks = [];
$products = [];
$balance_bank = [];
$balance_product = [];
$balance_adjust_ok = false;
try {
    $banks = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM banks ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    $products = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM products ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    _ensure_balance_adjust_table($pdo);
} catch (Throwable $e) {}
try {
    $rows = $pdo->query("SELECT adjust_type, name, initial_balance FROM balance_adjust")->fetchAll();
    $balance_adjust_ok = true;
    foreach ($rows as $r) {
        $k = strtolower(trim((string)$r['name']));
        if ($k === '') continue;
        if ($r['adjust_type'] === 'bank') $balance_bank[$k] = (float)$r['initial_balance'];
        else $balance_product[$k] = (float)$r['initial_balance'];
    }
} catch (Throwable $e) {
    $balance_bank = [];
    $balance_product = [];
}

// Balance Now = Starting Balance + 入账(deposit) − 出账(withdraw)，按银行/产品对扣
$total_in_bank = [];
$total_out_bank = [];
$total_in_product = [];
$total_out_product = [];
$diag_bank_rows = [];
$diag_product_rows = [];
$diag_error = '';
try {
    $stmt = $pdo->query("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE status = 'approved' GROUP BY COALESCE(bank, '')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $bankVal = $r['bank'] ?? $r['Bank'] ?? '';
        $k = strtolower(trim((string)$bankVal));
        if ($k === '') continue;
        $ti = (float)($r['ti'] ?? $r['TI'] ?? 0);
        $to = (float)($r['tout'] ?? $r['TO'] ?? 0);
        $total_in_bank[$k] = $ti;
        $total_out_bank[$k] = $to;
        $diag_bank_rows[$k] = ['in' => $ti, 'out' => $to];
    }
} catch (Throwable $e) {
    $diag_error = $e->getMessage();
}
try {
    $stmt = $pdo->query("SELECT COALESCE(product, '') AS product,
        COALESCE(SUM(CASE WHEN mode IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE status = 'approved' GROUP BY COALESCE(product, '')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $prodVal = $r['product'] ?? $r['Product'] ?? '';
        $k = strtolower(trim((string)$prodVal));
        if ($k === '') continue;
        $ti = (float)($r['ti'] ?? $r['TI'] ?? 0);
        $to = (float)($r['tout'] ?? $r['TO'] ?? 0);
        $total_in_product[$k] = $ti;
        $total_out_product[$k] = $to;
        $diag_product_rows[$k] = ['in' => $ti, 'out' => $to];
    }
} catch (Throwable $e) {
    if (empty($diag_error)) $diag_error = $e->getMessage();
}

// 仅当有待审核流水时显示提示
$cnt_pending = 0;
try {
    $cnt_pending = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>银行与产品 - 算账网</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 960px;">
                <div class="page-header">
                    <h2>银行与产品</h2>
                    <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span>银行/渠道与产品管理</p>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
                <?php if (!empty($diag_error)): ?>
                <div class="alert alert-error">汇总流水时出错：<?= htmlspecialchars($diag_error) ?></div>
                <?php endif; ?>
                <?php if ($cnt_pending > 0): ?>
                <div class="alert" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;">
                    <span style="font-size:1.2em;" aria-hidden="true">⚠</span> <strong><?= (int)$cnt_pending ?> 笔流水需通过审核</strong>，通过后才会计入上方 In/Out。<a href="admin_approvals.php">去审核</a>
                </div>
                <?php endif; ?>

                <div class="card">
                    <h3 style="display:flex;align-items:center;gap:8px;">
                        银行/渠道
                        <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="bank-add-wrap" aria-label="显示添加表单">+</button>
                    </h3>
                    <div class="bank-transfer-box" style="margin-bottom:16px; padding:12px 14px; background:#f8fafc; border:1px solid var(--border); border-radius:8px;">
                        <div style="font-weight:600; margin-bottom:8px; font-size:13px;">银行互转（contra）</div>
                        <form method="post" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                            <input type="hidden" name="action" value="do_transfer">
                            <span style="font-size:13px;">从</span>
                            <select name="from_bank" class="form-control" required style="width:auto; min-width:100px;">
                                <option value="">— 转出 —</option>
                                <?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); ?>
                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span style="font-size:13px;">转</span>
                            <input type="text" name="amount" class="form-control" placeholder="金额" inputmode="decimal" required style="width:90px;">
                            <span style="font-size:13px;">至</span>
                            <select name="to_bank" class="form-control" required style="width:auto; min-width:100px;">
                                <option value="">— 转入 —</option>
                                <?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); ?>
                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">确定</button>
                        </form>
                        <p class="form-hint" style="margin:8px 0 0; font-size:12px;">转出银行 − 金额，转入银行 + 金额，会记入流水（member 不可见）。</p>
                    </div>
                    <div id="bank-add-wrap" style="display:none; margin-bottom:16px;">
                        <form method="post" style="margin-bottom:0;">
                            <input type="hidden" name="action" value="create_bank">
                            <div class="form-group">
                                <label>名称 *</label>
                                <input name="name" class="form-control" required placeholder="例如 HLB">
                            </div>
                            <div class="form-group">
                                <label>排序</label>
                                <input name="sort_order" class="form-control" type="number" value="0">
                            </div>
                            <button type="submit" class="btn btn-primary">添加</button>
                        </form>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>状态</th>
                                <th>排序</th>
                                <th>创建时间</th>
                                <th class="num">Starting Balance</th>
                                <th class="num">In</th>
                                <th class="num">Out</th>
                                <th class="num">Balance</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($banks as $b):
                                $bname = trim((string)$b['name']);
                                $bkey = strtolower($bname);
                                $cur = $balance_bank[$bkey] ?? null;
                                $start = $cur !== null ? (float)$cur : 0;
                                $tin = $total_in_bank[$bkey] ?? 0;
                                $tout = $total_out_bank[$bkey] ?? 0;
                                $balance_now = $start + $tin - $tout;
                            ?>
                            <tr>
                                <td><?= (int)$b['id'] ?></td>
                                <td><?= htmlspecialchars($bname) ?></td>
                                <td><?= ((int)$b['is_active'] === 1) ? '启用' : '禁用' ?></td>
                                <td><?= (int)$b['sort_order'] ?></td>
                                <td><?= htmlspecialchars($b['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '0.00' ?></td>
                                <td class="num in"><?= number_format($tin, 2) ?></td>
                                <td class="num out"><?= number_format($tout, 2) ?></td>
                                <td class="num profit"><?= number_format($balance_now, 2) ?></td>
                                <td>
                                    <span class="balance-cell-inline">
                                        <button type="button" class="btn btn-sm btn-primary js-balance-change">更改</button>
                                        <form method="post" class="balance-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="save_balance">
                                            <input type="hidden" name="adjust_type" value="bank">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($bname) ?>">
                                            <input type="text" name="balance" class="balance-inline-input" placeholder="Starting Balance" inputmode="decimal" required size="6" value="<?= $cur !== null ? htmlspecialchars(sprintf('%.2f', $cur)) : '' ?>" title="仅修改 Starting Balance">
                                            <button type="submit" class="btn btn-sm btn-primary">确定</button>
                                            <button type="button" class="btn btn-sm btn-outline js-balance-inline-cancel">取消</button>
                                        </form>
                                    </span>
                                    <span class="transfer-cell-inline" style="display:inline-block;">
                                        <button type="button" class="btn btn-sm btn-outline js-transfer-open" data-bank="<?= htmlspecialchars($bname) ?>">转账</button>
                                        <form method="post" class="transfer-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="do_transfer">
                                            <input type="hidden" name="from_bank" class="transfer-from-bank" value="">
                                            <span class="transfer-label">转至</span>
                                            <select name="to_bank" class="form-control transfer-to-bank" required style="display:inline-block;width:auto;min-width:90px;">
                                                <option value="">— 选银行 —</option>
                                                <?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); if ($oname === $bname) continue; ?>
                                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="amount" class="form-control transfer-amount" placeholder="金额" inputmode="decimal" required size="6" style="display:inline-block;width:80px;">
                                            <button type="submit" class="btn btn-sm btn-primary">确定</button>
                                            <button type="button" class="btn btn-sm btn-outline js-transfer-cancel">取消</button>
                                        </form>
                                    </span>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_bank">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$b['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$banks): ?><tr><td colspan="10">暂无银行/渠道</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <p class="form-hint" style="margin-top:10px;">「更改」仅可修改 <strong>Starting Balance</strong>。公式与 Statement 一致：<strong>Balance = Starting Balance + In − Out</strong>（入账 In、出账 Out 为全部已审核流水合计）。</p>
                </div>

                <div class="card">
                    <h3 style="display:flex;align-items:center;gap:8px;">
                        产品管理
                        <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="product-add-wrap" aria-label="显示添加表单">+</button>
                    </h3>
                    <div class="product-topup-box" style="margin-bottom:16px; padding:12px 14px; background:#f8fafc; border:1px solid var(--border); border-radius:8px;">
                        <div style="font-weight:600; margin-bottom:8px; font-size:13px;">产品加额（balance 不够时加分）</div>
                        <form method="post" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                            <input type="hidden" name="action" value="do_product_topup">
                            <span style="font-size:13px;">选择产品</span>
                            <select name="product" class="form-control" required style="width:auto; min-width:110px;">
                                <option value="">— 请选 —</option>
                                <?php foreach ($products as $op): $oname = trim((string)$op['name']); ?>
                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span style="font-size:13px;">加</span>
                            <input type="text" name="amount" class="form-control" placeholder="数目" inputmode="decimal" required style="width:90px;">
                            <button type="submit" class="btn btn-primary">提交</button>
                        </form>
                        <p class="form-hint" style="margin:8px 0 0; font-size:12px;">提交后该产品的 In 会增加（显示为负），公式与 Statement Game Platform 一致：Balance = Starting − In + Out。</p>
                    </div>
                    <div id="product-add-wrap" style="display:none; margin-bottom:16px;">
                        <form method="post" style="margin-bottom:0;">
                            <input type="hidden" name="action" value="create_product">
                            <div class="form-group">
                                <label>名称 *</label>
                                <input name="name" class="form-control" required placeholder="例如 MEGA">
                            </div>
                            <div class="form-group">
                                <label>排序</label>
                                <input name="sort_order" class="form-control" type="number" value="0">
                            </div>
                            <button type="submit" class="btn btn-primary">添加</button>
                        </form>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>状态</th>
                                <th>排序</th>
                                <th>创建时间</th>
                                <th class="num">Starting Balance</th>
                                <th class="num">In</th>
                                <th class="num">Out</th>
                                <th class="num">Balance</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($products as $p):
                                $pname = trim((string)$p['name']);
                                $pkey = strtolower($pname);
                                $cur = $balance_product[$pkey] ?? null;
                                $start = $cur !== null ? (float)$cur : 0;
                                $tin = $total_in_product[$pkey] ?? 0;
                                $tout = $total_out_product[$pkey] ?? 0;
                                $balance_now = $start - $tin + $tout;
                            ?>
                            <tr>
                                <td><?= (int)$p['id'] ?></td>
                                <td><?= htmlspecialchars($pname) ?></td>
                                <td><?= ((int)$p['is_active'] === 1) ? '启用' : '禁用' ?></td>
                                <td><?= (int)$p['sort_order'] ?></td>
                                <td><?= htmlspecialchars($p['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '0.00' ?></td>
                                <td class="num out"><?= $tin != 0 ? '−' . number_format($tin, 2) : '—' ?></td>
                                <td class="num in"><?= $tout != 0 ? '+' . number_format($tout, 2) : '—' ?></td>
                                <td class="num profit"><?= number_format($balance_now, 2) ?></td>
                                <td>
                                    <span class="balance-cell-inline">
                                        <button type="button" class="btn btn-sm btn-primary js-balance-change">更改</button>
                                        <form method="post" class="balance-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="save_balance">
                                            <input type="hidden" name="adjust_type" value="product">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($pname) ?>">
                                            <input type="text" name="balance" class="balance-inline-input" placeholder="Starting Balance" inputmode="decimal" required size="6" value="<?= $cur !== null ? htmlspecialchars(sprintf('%.2f', $cur)) : '' ?>" title="仅修改 Starting Balance">
                                            <button type="submit" class="btn btn-sm btn-primary">确定</button>
                                            <button type="button" class="btn btn-sm btn-outline js-balance-inline-cancel">取消</button>
                                        </form>
                                    </span>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_product">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$p['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td colspan="10">暂无产品</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <p class="form-hint" style="margin-top:10px;">「更改」仅可修改 <strong>Starting Balance</strong>。公式（与 Statement Game Platform 一致）：<strong>Balance = Starting − In + Out</strong>，In 显示为负、Out 显示为正。</p>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function(){
        document.querySelectorAll('.balance-cell-inline').forEach(function(cell){
            var btn = cell.querySelector('.js-balance-change');
            var form = cell.querySelector('.balance-inline-form');
            var input = cell.querySelector('.balance-inline-input');
            var cancel = cell.querySelector('.js-balance-inline-cancel');
            if (!btn || !form) return;
            btn.addEventListener('click', function(){
                btn.style.display = 'none';
                form.style.display = 'inline-flex';
                if (input) { input.value = ''; input.focus(); }
            });
            if (cancel) cancel.addEventListener('click', function(){
                form.style.display = 'none';
                btn.style.display = '';
            });
        });
        document.querySelectorAll('.js-toggle-add').forEach(function(btn){
            var id = btn.getAttribute('data-target');
            if (!id) return;
            var el = document.getElementById(id);
            if (!el) return;
            btn.addEventListener('click', function(){
                var show = el.style.display === 'none' || !el.style.display;
                el.style.display = show ? 'block' : 'none';
                btn.textContent = show ? '−' : '+';
            });
        });
        document.querySelectorAll('.transfer-cell-inline').forEach(function(cell){
            var btn = cell.querySelector('.js-transfer-open');
            var form = cell.querySelector('.transfer-inline-form');
            var fromInput = cell.querySelector('.transfer-from-bank');
            var cancel = cell.querySelector('.js-transfer-cancel');
            if (!btn || !form) return;
            btn.addEventListener('click', function(){
                if (fromInput) fromInput.value = btn.getAttribute('data-bank') || '';
                btn.style.display = 'none';
                form.style.display = 'inline-flex';
                form.style.flexWrap = 'wrap';
                form.style.alignItems = 'center';
                form.style.gap = '6px';
                var amt = cell.querySelector('.transfer-amount');
                if (amt) { amt.value = ''; amt.focus(); }
            });
            if (cancel) cancel.addEventListener('click', function(){
                form.style.display = 'none';
                btn.style.display = '';
            });
        });
    })();
    </script>
    <style>
    /* 表单默认由 HTML 的 style="display:none" 隐藏；点击「更改」后 JS 设为 inline-flex 才显示 */
    .balance-cell-inline .balance-inline-form { align-items: center; gap: 6px; vertical-align: middle; }
    .balance-cell-inline .balance-inline-input { width: 72px; padding: 4px 6px; font-size: 0.9rem; text-align: right; }
    .transfer-cell-inline .transfer-inline-form { align-items: center; gap: 6px; vertical-align: middle; }
    .transfer-cell-inline .transfer-label { font-size: 13px; margin-right: 4px; }
    </style>
</body>
</html>
