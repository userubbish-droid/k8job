<?php
require 'config.php';
require 'auth.php';
require_permission('product_library');
$sidebar_current = 'product_library';

$company_id = current_company_id();
$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'boss'], true);

$products = [];
$by_code = [];
$codes = [];
$err = '';
$msg = '';

// 顾客列表（用于 add 表单下拉）
$customers_list = [];
try {
    $st = $pdo->prepare("SELECT id, code FROM customers WHERE company_id = ? AND is_active = 1 ORDER BY code ASC");
    $st->execute([$company_id]);
    $customers_list = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $st = $pdo->prepare("SELECT name FROM products WHERE company_id = ? AND is_active = 1 AND (delete_pending_at IS NULL) ORDER BY sort_order ASC, name ASC");
    $st->execute([$company_id]);
    $products = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}

// 在产品账号页添加产品账号（与编辑顾客页相同功能）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $product_name = trim($_POST['product_name'] ?? '');
    $account = trim($_POST['account'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($password === '') $password = 'Aaaa8888';
    if ($customer_id <= 0 || $product_name === '') {
        $err = '请选择顾客和产品。';
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND company_id = ? LIMIT 1");
            $chk->execute([$customer_id, $company_id]);
            if (!$chk->fetch()) {
                throw new RuntimeException('顾客不属于当前分公司。');
            }
            $stmt = $pdo->prepare("INSERT INTO customer_product_accounts (company_id, customer_id, product_name, account, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $customer_id, $product_name, $account ?: null, $password]);
            $msg = '已添加。';
            header("Location: product_library.php?msg=1");
            exit;
        } catch (Throwable $e) {
            $err = '添加失败：' . $e->getMessage();
        }
    }
}
if (isset($_GET['msg'])) {
    $msg = '已添加。';
}
$show_add_box = !empty($err) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product';

try {
    $st = $pdo->prepare("SELECT a.customer_id, a.product_name, a.account, a.password, c.code
            FROM customer_product_accounts a
            INNER JOIN customers c ON c.id = a.customer_id AND c.company_id = ?
            WHERE a.company_id = ?
            ORDER BY c.code ASC, a.product_name ASC, a.id ASC");
    $st->execute([$company_id, $company_id]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $code = $r['code'] ?? '';
        if ($code === '') continue;
        if (!isset($by_code[$code])) $by_code[$code] = [];
        $pn = $r['product_name'];
        if (!isset($by_code[$code][$pn])) $by_code[$code][$pn] = [];
        $by_code[$code][$pn][] = [
            'account'  => $r['account'] ?? '',
            'password' => $r['password'] ?? '',
            'customer_id' => (int)$r['customer_id'],
        ];
    }
    $codes = array_keys($by_code);
    sort($codes);
    if (empty($products) && !empty($by_code)) {
        $seen = [];
        foreach ($by_code as $list) {
            foreach (array_keys($list) as $pn) { $seen[$pn] = true; }
        }
        $products = array_keys($seen);
        sort($products);
    }
} catch (Throwable $e) {
    $codes = [];
    $err = '无法加载数据，请确认已执行 migrate_customer_products.sql。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>产品账号 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .product-cell { font-size: 13px; white-space: nowrap; }
        .product-cell .id { color: #0f172a; }
        .product-cell .ps { color: var(--muted); margin-top: 2px; }
        .addacct-mask {
            position: fixed;
            inset: 0;
            background: rgba(8, 16, 40, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1600;
            padding: 18px;
        }
        .addacct-mask.show { display: flex; }
        .addacct-modal {
            width: min(980px, 96vw);
            max-height: min(86vh, 900px);
            overflow: auto;
            background: #fff;
            border-radius: 18px;
            border: 1px solid #dbeafe;
            box-shadow: 0 22px 60px rgba(37, 99, 235, 0.28);
        }
        .addacct-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            font-weight: 800;
            color: #0f172a;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border-bottom: 1px solid #bfdbfe;
        }
        .addacct-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(148,163,184,0.55);
            background: rgba(255,255,255,0.9);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .addacct-body { padding: 16px 18px 10px; }
        .addacct-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .addacct-sec { padding: 12px; border: 1px solid rgba(59,130,246,0.18); border-radius: 12px; background: linear-gradient(180deg, rgba(239,246,255,0.55) 0%, rgba(255,255,255,0.92) 100%); }
        .addacct-sec h4 { margin: 0 0 10px; font-size: 13px; font-weight: 800; color: #1e3a8a; }
        .seg {
            display: inline-flex;
            background: rgba(241,245,249,0.9);
            border: 1px solid rgba(148,163,184,0.45);
            border-radius: 999px;
            padding: 3px;
            gap: 4px;
        }
        .seg button {
            border: none;
            background: transparent;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 700;
            cursor: pointer;
            color: #334155;
        }
        .seg button.active {
            background: linear-gradient(180deg, #6e8dff 0%, var(--primary) 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(79, 125, 255, 0.35);
        }
        .addacct-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 18px 16px;
            border-top: 1px solid rgba(140,165,235,0.25);
        }
        @media (max-width: 820px) { .addacct-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 100%;">
        <div class="page-header">
            <h2>产品账号</h2>
            <p class="breadcrumb">
                <a href="dashboard.php">首页</a><span>·</span>
                <a href="customers.php">顾客列表</a>
                <?php if (has_permission('customer_create')): ?><span>·</span><a href="customer_create.php">新增顾客</a><?php endif; ?>
                <?php if ($is_admin): ?><span>·</span><a href="admin_products.php">产品管理</a><?php endif; ?>
            </p>
        </div>

        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                顾客产品资料
                <button type="button" id="product_lib_add_btn" class="btn btn-outline btn-sm" style="padding:2px 10px; font-size:13px;">Add Account</button>
            </h3>
            <div class="addacct-mask<?= $show_add_box ? ' show' : '' ?>" id="addacct-mask" aria-hidden="<?= $show_add_box ? 'false' : 'true' ?>">
                <div class="addacct-modal" role="dialog" aria-modal="true" aria-label="Add Account">
                    <div class="addacct-head">
                        <span>Add Account</span>
                        <button type="button" class="addacct-close" id="addacct-close" aria-label="关闭">×</button>
                    </div>
                    <form method="post" id="addacct-form" autocomplete="off">
                        <input type="hidden" name="action" value="add_product">
                        <div class="addacct-body">
                            <div class="addacct-grid">
                                <div class="addacct-sec">
                                    <h4>Personal Information</h4>
                                    <div class="form-group">
                                        <label>Customer *</label>
                                        <select name="customer_id" class="form-control" required>
                                            <option value="">Select Customer</option>
                                            <?php foreach ($customers_list as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['code']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Game / Product *</label>
                                        <select name="product_name" class="form-control" required>
                                            <option value="">Select Game</option>
                                            <?php foreach ($products as $p): ?>
                                            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Account ID *</label>
                                        <input name="account" class="form-control" placeholder="Account ID">
                                    </div>
                                </div>
                                <div class="addacct-sec">
                                    <h4>Payment</h4>
                                    <div class="form-group">
                                        <label>Password *</label>
                                        <input name="password" type="text" class="form-control" placeholder="不填则 Aaaa8888">
                                    </div>
                                    <div class="form-group">
                                        <label>Payment Alert</label>
                                        <div class="seg" role="group" aria-label="Payment Alert">
                                            <button type="button" class="active" data-alert="yes">Yes</button>
                                            <button type="button" data-alert="no">No</button>
                                        </div>
                                        <input type="hidden" name="payment_alert" id="payment_alert" value="yes">
                                    </div>
                                    <div class="form-group">
                                        <label>Remark</label>
                                        <input name="remark" class="form-control" placeholder="Remark（仅显示，不入库）">
                                    </div>
                                </div>
                            </div>
                            <div class="addacct-sec" style="margin-top:14px;">
                                <h4>Advanced Account</h4>
                                <div class="form-row-2" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>Other Currency</label>
                                        <input class="form-control" placeholder="MYR（展示用）" disabled>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>Company</label>
                                        <input class="form-control" placeholder="—" disabled>
                                    </div>
                                </div>
                                <p class="form-hint" style="margin:8px 0 0;">此区块仅用于对齐你提供的模板视觉（当前系统未落库）。</p>
                            </div>
                        </div>
                        <div class="addacct-foot">
                            <button type="button" class="btn btn-outline" id="addacct-cancel">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Account</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php if ($products || $codes): ?>
            <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <?php foreach ($products as $p): ?>
                        <th><?= htmlspecialchars($p) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($codes)): ?>
                    <tr><td colspan="<?= count($products) + 1 ?>">暂无记录。请到「编辑顾客」为顾客添加产品及账号、密码。</td></tr>
                <?php else: ?>
                <?php foreach ($codes as $code):
                    $cid = 0;
                    foreach ($by_code[$code] as $list) { if (!empty($list)) { $cid = (int)($list[0]['customer_id'] ?? 0); break; } }
                    ?>
                    <tr>
                        <td><a href="customer_edit.php?id=<?= $cid ?>"><?= htmlspecialchars($code) ?></a></td>
                        <?php foreach ($products as $p): ?>
                        <td class="product-cell">
                            <?php
                            $cells = $by_code[$code][$p] ?? [];
                            if (!empty($cells)):
                                foreach ($cells as $idx => $entry):
                                    $acc = trim($entry['account'] ?? '');
                                    $pwd = trim($entry['password'] ?? '');
                                    $suffix = $idx === 0 ? '' : '~' . ($idx + 1);
                                    $accDisplay = $acc !== '' ? htmlspecialchars($acc) . $suffix : '—';
                                    $pwdDisplay = $pwd !== '' ? htmlspecialchars($pwd) : '—';
                            ?>
                            <div class="id">id：<?= $accDisplay ?></div>
                            <div class="ps">ps：<?= $pwdDisplay ?></div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <p class="form-hint">暂无数据。请先在「产品管理」添加产品，再在「编辑顾客」里为顾客添加各产品的账号与密码。</p>
            <?php endif; ?>
        </div>
    </div>
        </main>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('product_lib_add_btn');
        var mask = document.getElementById('addacct-mask');
        var closeBtn = document.getElementById('addacct-close');
        var cancelBtn = document.getElementById('addacct-cancel');
        if (!btn || !mask) return;
        function openM() { mask.classList.add('show'); mask.setAttribute('aria-hidden', 'false'); }
        function closeM() { mask.classList.remove('show'); mask.setAttribute('aria-hidden', 'true'); }
        btn.addEventListener('click', openM);
        if (closeBtn) closeBtn.addEventListener('click', closeM);
        if (cancelBtn) cancelBtn.addEventListener('click', closeM);
        mask.addEventListener('click', function(e){ if (e.target === mask) closeM(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && mask.classList.contains('show')) closeM(); });

        // Segmented control
        var seg = mask.querySelector('.seg');
        var hidden = document.getElementById('payment_alert');
        if (seg && hidden) {
            seg.querySelectorAll('button').forEach(function(b){
                b.addEventListener('click', function(){
                    seg.querySelectorAll('button').forEach(function(x){ x.classList.remove('active'); });
                    b.classList.add('active');
                    hidden.value = b.getAttribute('data-alert') || 'yes';
                });
            });
        }
    })();
    </script>
</body>
</html>
