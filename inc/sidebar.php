<?php
// 固定侧栏，各选项页共用。使用前需定义 $sidebar_current（当前页标识，用于高亮）
if (!isset($sidebar_current)) $sidebar_current = '';
$sidebar_pending = 0;
if (($_SESSION['user_role'] ?? '') === 'admin' && !empty($pdo)) {
    try {
        $sidebar_pending = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {}
}
?>
<aside class="dashboard-sidebar">
    <div class="sidebar-drawer-header">
        <button type="button" class="sidebar-close" id="sidebar-close" aria-label="关闭导航">×</button>
    </div>
    <a href="dashboard.php" class="nav-item <?= $sidebar_current === 'dashboard' ? 'primary' : '' ?>"><span class="nav-icon"></span>首页</a>
    <?php if (has_permission('transaction_create')): ?><a href="transaction_create.php" class="nav-item <?= $sidebar_current === 'transaction_create' ? 'primary' : '' ?>"><span class="nav-icon"></span>记一笔</a><?php endif; ?>
    <?php if (has_permission('transaction_list')): ?><a href="transaction_list.php" class="nav-item <?= $sidebar_current === 'transaction_list' ? 'primary' : '' ?>"><span class="nav-icon"></span>流水记录</a><?php endif; ?>
    <?php if (has_permission('rebate')): ?><a href="rebate.php" class="nav-item <?= $sidebar_current === 'rebate' ? 'primary' : '' ?>"><span class="nav-icon"></span>返点 Rebate</a><?php endif; ?>
    <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item <?= $sidebar_current === 'customers' ? 'primary' : '' ?>"><span class="nav-icon"></span>顾客列表</a><?php endif; ?>
    <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item <?= $sidebar_current === 'product_library' ? 'primary' : '' ?>"><span class="nav-icon"></span>产品账号</a><?php endif; ?>
    <a href="balance_summary.php" class="nav-item <?= $sidebar_current === 'balance_summary' ? 'primary' : '' ?>"><span class="nav-icon"></span>余额汇总</a>
    <?php if (has_permission('customer_create')): ?><a href="customer_create.php" class="nav-item <?= $sidebar_current === 'customer_create' ? 'primary' : '' ?>"><span class="nav-icon"></span>新增顾客</a><?php endif; ?>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin_users.php" class="nav-item <?= $sidebar_current === 'admin_users' ? 'primary' : '' ?>"><span class="nav-icon"></span>账号管理</a>
        <a href="admin_banks.php" class="nav-item <?= $sidebar_current === 'admin_banks' ? 'primary' : '' ?>"><span class="nav-icon"></span>银行/渠道</a>
        <a href="admin_products.php" class="nav-item <?= $sidebar_current === 'admin_products' ? 'primary' : '' ?>"><span class="nav-icon"></span>产品管理</a>
        <a href="admin_option_sets.php" class="nav-item <?= $sidebar_current === 'admin_option_sets' ? 'primary' : '' ?>"><span class="nav-icon"></span>选项设置</a>
        <a href="admin_permissions.php" class="nav-item <?= $sidebar_current === 'admin_permissions' ? 'primary' : '' ?>"><span class="nav-icon"></span>员工权限</a>
        <a href="admin_approvals.php" class="nav-item <?= $sidebar_current === 'admin_approvals' ? 'primary' : '' ?>"><span class="nav-icon"></span>待审核<?= $sidebar_pending ? '（' . $sidebar_pending . '）' : '' ?></a>
    <?php endif; ?>
    <a href="logout.php" class="nav-item"><span class="nav-icon"></span>退出登录</a>
</aside>
<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="打开导航"><span class="sidebar-toggle-icon">☰</span> MENU</button>
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>
<script>
(function(){
    var btn = document.getElementById('sidebar-toggle');
    var overlay = document.getElementById('sidebar-overlay');
    if (!btn || !overlay) return;
    // 网页版（桌面）默认展开侧栏；手机版默认收起
    if (window.innerWidth > 768) document.body.classList.add('sidebar-open');
    function syncAria() { overlay.setAttribute('aria-hidden', document.body.classList.contains('sidebar-open') ? 'false' : 'true'); }
    syncAria();
    function closeSidebar() { document.body.classList.remove('sidebar-open'); syncAria(); }
    btn.addEventListener('click', function(){ document.body.classList.toggle('sidebar-open'); syncAria(); });
    overlay.addEventListener('click', closeSidebar);
    var closeBtn = document.getElementById('sidebar-close');
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    document.querySelectorAll('.dashboard-sidebar a').forEach(function(a){ a.addEventListener('click', function(){ if (window.innerWidth <= 768) closeSidebar(); }); });
})();
</script>
