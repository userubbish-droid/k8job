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
    $banks = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM banks ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    $products = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM products ORDER BY sort_order DESC, name ASC")->fetchAll();
} catch (Throwable $e) {}
try {
    _ensure_balance_adjust_table($pdo);
} catch (Throwable $e) {}
try {
    $rows = $pdo->query("SELECT adjust_type, name, initial_balance FROM balance_adjust")->fetchAll();
    $balance_adjust_ok = true;
    foreach ($rows as $r) {
        if ($r['adjust_type'] === 'bank') $balance_bank[$r['name']] = (float)$r['initial_balance'];
        else $balance_product[$r['name']] = (float)$r['initial_balance'];
    }
} catch (Throwable $e) {
    $balance_bank = [];
    $balance_product = [];
}
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

                <div class="card">
                    <h3>银行/渠道</h3>
                    <form method="post" style="margin-bottom:16px;">
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
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>状态</th>
                                <th>排序</th>
                                <th>创建时间</th>
                                <th class="num">目前余额</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($banks as $b): $bname = $b['name']; $cur = $balance_bank[$bname] ?? null; ?>
                            <tr>
                                <td><?= (int)$b['id'] ?></td>
                                <td><?= htmlspecialchars($bname) ?></td>
                                <td><?= ((int)$b['is_active'] === 1) ? '启用' : '禁用' ?></td>
                                <td><?= (int)$b['sort_order'] ?></td>
                                <td><?= htmlspecialchars($b['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '—' ?></td>
                                <td>
                                    <?php if ($cur === null): ?>
                                    <button type="button" class="btn btn-sm btn-primary js-balance-change" data-type="bank" data-name="<?= htmlspecialchars($bname) ?>">更改</button>
                                    <?php endif; ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_bank">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$b['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$banks): ?><tr><td colspan="7">暂无银行/渠道</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3>产品管理</h3>
                    <form method="post" style="margin-bottom:16px;">
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
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>状态</th>
                                <th>排序</th>
                                <th>创建时间</th>
                                <th class="num">目前余额</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): $pname = $p['name']; $cur = $balance_product[$pname] ?? null; ?>
                            <tr>
                                <td><?= (int)$p['id'] ?></td>
                                <td><?= htmlspecialchars($pname) ?></td>
                                <td><?= ((int)$p['is_active'] === 1) ? '启用' : '禁用' ?></td>
                                <td><?= (int)$p['sort_order'] ?></td>
                                <td><?= htmlspecialchars($p['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '—' ?></td>
                                <td>
                                    <?php if ($cur === null): ?>
                                    <button type="button" class="btn btn-sm btn-primary js-balance-change" data-type="product" data-name="<?= htmlspecialchars($pname) ?>">更改</button>
                                    <?php endif; ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_product">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$p['is_active'] === 1) ? '禁用' : '启用' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td colspan="7">暂无产品</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="balance-modal" class="balance-modal" role="dialog" aria-labelledby="balance-modal-title" aria-hidden="true" style="display:none;">
        <div class="balance-modal-backdrop"></div>
        <div class="balance-modal-box">
            <h3 id="balance-modal-title">填写余额</h3>
            <form method="post" id="balance-modal-form">
                <input type="hidden" name="action" value="save_balance">
                <input type="hidden" name="adjust_type" id="balance-modal-type">
                <input type="hidden" name="name" id="balance-modal-name">
                <div class="form-group">
                    <label for="balance-modal-input">金额</label>
                    <input type="text" name="balance" id="balance-modal-input" class="form-control" placeholder="数字" inputmode="decimal" required>
                </div>
                <div class="balance-modal-actions">
                    <button type="submit" class="btn btn-primary">确定</button>
                    <button type="button" class="btn btn-outline js-balance-modal-cancel">取消</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function(){
        var modal = document.getElementById('balance-modal');
        var form = document.getElementById('balance-modal-form');
        var input = document.getElementById('balance-modal-input');
        var typeEl = document.getElementById('balance-modal-type');
        var nameEl = document.getElementById('balance-modal-name');
        if (!modal || !form) return;

        function openModal(type, name) {
            typeEl.value = type;
            nameEl.value = name;
            input.value = '';
            input.focus();
            modal.style.display = '';
            modal.setAttribute('aria-hidden', 'false');
        }
        function closeModal() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.js-balance-change').forEach(function(btn){
            btn.addEventListener('click', function(){
                openModal(btn.getAttribute('data-type'), btn.getAttribute('data-name'));
            });
        });
        modal.querySelector('.js-balance-modal-cancel').addEventListener('click', closeModal);
        modal.querySelector('.balance-modal-backdrop').addEventListener('click', closeModal);
        form.addEventListener('submit', function(){ closeModal(); });
    })();
    </script>
    <style>
    .balance-modal { position: fixed; inset: 0; z-index: 9999; align-items: center; justify-content: center; }
    .balance-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.4); }
    .balance-modal-box { position: relative; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); min-width: 280px; }
    .balance-modal-box h3 { margin: 0 0 16px 0; font-size: 1.1rem; }
    .balance-modal-actions { margin-top: 16px; display: flex; gap: 10px; }
    </style>
</body>
</html>
