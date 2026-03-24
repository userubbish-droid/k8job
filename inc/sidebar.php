<?php
// 固定侧栏，各选项页共用。使用前需定义 $sidebar_current（当前页标识，用于高亮）
if (!isset($sidebar_current)) $sidebar_current = '';
$sidebar_pending = 0;
if (($_SESSION['user_role'] ?? '') === 'admin' && !empty($pdo)) {
    try {
        $sidebar_pending = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {}
}
$sidebar_user_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$sidebar_user_role = ($_SESSION['user_role'] ?? '') === 'admin' ? 'Admin' : (($_SESSION['user_role'] ?? '') === 'agent' ? 'Agent' : 'Staff');
$sidebar_user_initial = mb_substr($sidebar_user_name, 0, 1, 'UTF-8');
?>
<aside class="dashboard-sidebar">
    <div class="sidebar-drawer-header">
        <button type="button" class="sidebar-close" id="sidebar-close" aria-label="Close menu">×</button>
    </div>
    <div class="sidebar-profile">
        <div class="sidebar-avatar" aria-hidden="true"><?= htmlspecialchars($sidebar_user_initial) ?></div>
        <div class="sidebar-name"><?= htmlspecialchars($sidebar_user_name) ?></div>
        <div class="sidebar-role"><?= htmlspecialchars($sidebar_user_role) ?></div>
    </div>
    <div class="sidebar-sep" aria-hidden="true"></div>
    <?php if (($_SESSION['user_role'] ?? '') === 'agent'): ?>
    <a href="agents.php" class="nav-item <?= $sidebar_current === 'agents' ? 'primary' : '' ?>"><span class="nav-icon"></span>Agent</a>
    <?php else: ?>
    <div class="nav-group" data-group="home-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['dashboard', 'report', 'kiosk_statement'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-home-menu" id="nav-toggle-home-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label">Home</span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-home-menu" role="region" aria-labelledby="nav-toggle-home-menu" style="display:<?= in_array($sidebar_current, ['dashboard', 'report', 'kiosk_statement'], true) ? 'block' : 'none' ?>">
            <a href="dashboard.php" class="nav-item nav-sub-item <?= $sidebar_current === 'dashboard' ? 'primary' : '' ?>"><span class="nav-icon"></span>Dashboard</a>
            <?php if (has_permission('statement')): ?><a href="report.php" class="nav-item nav-sub-item <?= $sidebar_current === 'report' ? 'primary' : '' ?>"><span class="nav-icon"></span>Report</a><?php endif; ?>
            <?php if (has_permission('statement')): ?><a href="kiosk_statement.php" class="nav-item nav-sub-item <?= $sidebar_current === 'kiosk_statement' ? 'primary' : '' ?>"><span class="nav-icon"></span>Kiosk Statement</a><?php endif; ?>
        </div>
    </div>
    <?php if (has_permission('transaction_create') || has_permission('customer_create')): ?>
    <div class="nav-group" data-group="add-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['transaction_create', 'customer_create'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-add-menu" id="nav-toggle-add-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label">Add</span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-add-menu" role="region" aria-labelledby="nav-toggle-add-menu" style="display:<?= in_array($sidebar_current, ['transaction_create', 'customer_create'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('transaction_create')): ?><a href="transaction_create.php" class="nav-item nav-sub-item <?= $sidebar_current === 'transaction_create' ? 'primary' : '' ?>"><span class="nav-icon"></span>Add Transaction</a><?php endif; ?>
            <?php if (has_permission('customer_create')): ?><a href="customer_create.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customer_create' ? 'primary' : '' ?>"><span class="nav-icon"></span>New Customer</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('transaction_create')): ?>
    <div class="nav-group" data-group="expense-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['expense_statement', 'expense_kiosk'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-expense-menu" id="nav-toggle-expense-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label">Expense</span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-expense-menu" role="region" aria-labelledby="nav-toggle-expense-menu" style="display:<?= in_array($sidebar_current, ['expense_statement', 'expense_kiosk'], true) ? 'block' : 'none' ?>">
            <a href="expense.php" class="nav-item nav-sub-item <?= $sidebar_current === 'expense_statement' ? 'primary' : '' ?>"><span class="nav-icon"></span>Expense Statement</a>
            <a href="kiosk_expense.php" class="nav-item nav-sub-item <?= $sidebar_current === 'expense_kiosk' ? 'primary' : '' ?>"><span class="nav-icon"></span>Kiosk Expense</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('transaction_list')): ?><a href="transaction_list.php" class="nav-item <?= $sidebar_current === 'transaction_list' ? 'primary' : '' ?>"><span class="nav-icon"></span>Transactions</a><?php endif; ?>
    <?php if (has_permission('rebate') || has_permission('agent')): ?>
    <div class="nav-group" data-group="rebate-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['rebate', 'agents'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-rebate-menu" id="nav-toggle-rebate-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label">Rebate</span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-rebate-menu" role="region" aria-labelledby="nav-toggle-rebate-menu" style="display:<?= in_array($sidebar_current, ['rebate', 'agents'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('rebate')): ?><a href="rebate.php" class="nav-item nav-sub-item <?= $sidebar_current === 'rebate' ? 'primary' : '' ?>"><span class="nav-icon"></span>Customer Rebate</a><?php endif; ?>
            <?php if (has_permission('agent')): ?><a href="agents.php" class="nav-item nav-sub-item <?= $sidebar_current === 'agents' ? 'primary' : '' ?>"><span class="nav-icon"></span>Agent Rebate</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('customers') || has_permission('customer_create') || has_permission('product_library')): ?>
    <div class="nav-group" data-group="account-customer">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['customers', 'product_library'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-account-customer" id="nav-toggle-account-customer">
            <span class="nav-icon"></span>
            <span class="nav-group-label">Customer Detail</span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-account-customer" role="region" aria-labelledby="nav-toggle-account-customer" style="display:<?= in_array($sidebar_current, ['customers', 'product_library'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customers' ? 'primary' : '' ?>"><span class="nav-icon"></span>Customers</a><?php endif; ?>
            <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item nav-sub-item <?= $sidebar_current === 'product_library' ? 'primary' : '' ?>"><span class="nav-icon"></span>Product Accounts</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('statement')): ?><a href="balance_summary.php" class="nav-item <?= $sidebar_current === 'balance_summary' ? 'primary' : '' ?>"><span class="nav-icon"></span>Statement</a><?php endif; ?>
    <?php endif; ?>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin_users.php" class="nav-item <?= $sidebar_current === 'admin_users' ? 'primary' : '' ?>"><span class="nav-icon"></span>User Management</a>
        <a href="admin_banks_products.php" class="nav-item <?= ($sidebar_current === 'admin_banks' || $sidebar_current === 'admin_products' || $sidebar_current === 'admin_banks_products') ? 'primary' : '' ?>"><span class="nav-icon"></span>Banks & Products</a>
        <a href="admin_permissions.php" class="nav-item <?= $sidebar_current === 'admin_permissions' ? 'primary' : '' ?>"><span class="nav-icon"></span>Permissions</a>
    <?php endif; ?>
    <a href="logout.php" class="nav-item"><span class="nav-icon"></span>Logout</a>
</aside>
<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="打开导航"><span class="sidebar-toggle-icon">☰</span> MENU</button>
<?php if (($_SESSION['user_role'] ?? '') === 'admin' && $sidebar_pending > 0): ?>
<a href="admin_approvals.php" class="sidebar-bell" title="Pending <?= $sidebar_pending ?>" aria-label="Pending approvals"><span class="sidebar-bell-icon">🔔</span><span class="sidebar-bell-badge"><?= $sidebar_pending ?></span></a>
<?php endif; ?>
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>
<style>
/* 确保所有页面都有统一弹窗样式（即使未引入 style.css） */
.app-modal-mask { position: fixed; inset: 0; background: rgba(8, 16, 40, 0.48); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
.app-modal-mask.show { display: flex; }
.app-modal { width: min(92vw, 460px); background: #fff; border-radius: 18px; border: 1px solid #dbeafe; box-shadow: 0 22px 60px rgba(37,99,235,0.28); transform: scale(.92) translateY(10px); opacity: 0; transition: transform .2s ease, opacity .2s ease; overflow: hidden; }
.app-modal-mask.show .app-modal { transform: scale(1) translateY(0); opacity: 1; }
.app-modal-head { padding: 14px 18px; font-weight: 700; color: #1d4ed8; background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%); border-bottom: 1px solid #bfdbfe; }
.app-modal-body { padding: 20px 18px 12px; color: #0f172a; font-size: 15px; line-height: 1.6; }
.app-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 0 18px 16px; }
</style>
<div class="app-modal-mask" id="app-modal-mask" aria-hidden="true">
    <div class="app-modal" role="dialog" aria-modal="true" aria-labelledby="app-modal-title">
        <div class="app-modal-head" id="app-modal-title">系统提示</div>
        <div class="app-modal-body" id="app-modal-message"></div>
        <div class="app-modal-foot" id="app-modal-footer"></div>
    </div>
</div>
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
    document.querySelectorAll('.nav-group-toggle').forEach(function(btn){
        var sub = btn.getAttribute('aria-controls') && document.getElementById(btn.getAttribute('aria-controls'));
        var chevron = btn.querySelector('.nav-group-chevron');
        if (!sub) return;
        btn.addEventListener('click', function(){
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', !expanded);
            sub.style.display = !expanded ? 'block' : 'none';
            if (chevron) chevron.textContent = !expanded ? '▾' : '▸';
        });
        if (chevron && btn.getAttribute('aria-expanded') === 'false') chevron.textContent = '▸';
    });

    // 全站统一弹窗：alert / confirm
    var modalMask = document.getElementById('app-modal-mask');
    var modalTitle = document.getElementById('app-modal-title');
    var modalMsg = document.getElementById('app-modal-message');
    var modalFooter = document.getElementById('app-modal-footer');
    function closeModal() {
        if (!modalMask) return;
        modalMask.classList.remove('show');
        modalMask.setAttribute('aria-hidden', 'true');
    }
    function openModal(title, message, buttons) {
        if (!modalMask || !modalTitle || !modalMsg || !modalFooter) return;
        modalTitle.textContent = title || '系统提示';
        modalMsg.textContent = message || '';
        modalFooter.innerHTML = '';
        (buttons || []).forEach(function(b){
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = b.className || 'btn btn-outline';
            btn.textContent = b.text || 'OK';
            btn.addEventListener('click', function(){
                closeModal();
                if (typeof b.onClick === 'function') b.onClick();
            });
            modalFooter.appendChild(btn);
        });
        modalMask.classList.add('show');
        modalMask.setAttribute('aria-hidden', 'false');
    }
    if (modalMask) {
        modalMask.addEventListener('click', function(e){ if (e.target === modalMask) closeModal(); });
    }
    window.appModalAlert = function(message, title) {
        openModal(title || '系统提示', message || '', [{ text: 'OK', className: 'btn btn-primary' }]);
    };
    window.appModalConfirm = function(message, onConfirm, title) {
        openModal(title || '确认操作', message || '', [
            { text: '取消', className: 'btn btn-outline' },
            { text: '确定', className: 'btn btn-primary', onClick: onConfirm }
        ]);
    };

    // 自动接管 data-confirm
    document.querySelectorAll('form[data-confirm]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var msg = form.getAttribute('data-confirm') || '确定继续吗？';
            window.appModalConfirm(msg, function(){ form.submit(); });
        });
    });
    document.querySelectorAll('a[data-confirm]').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            var href = a.getAttribute('href') || '#';
            var msg = a.getAttribute('data-confirm') || '确定继续吗？';
            window.appModalConfirm(msg, function(){ window.location.href = href; });
        });
    });
})();
</script>
