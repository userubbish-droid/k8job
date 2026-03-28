<?php
// 固定侧栏，各选项页共用。使用前需定义 $sidebar_current（当前页标识，用于高亮）
if (!isset($sidebar_current)) $sidebar_current = '';
$sidebar_pending = 0;
$sidebar_pending_customers = 0;
if (in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true) && !empty($pdo) && function_exists('current_company_id')) {
    try {
        $cid = current_company_id();
        $has_deleted_at = true;
        try { $pdo->query("SELECT deleted_at FROM transactions LIMIT 0"); } catch (Throwable $e) { $has_deleted_at = false; }
        $del = $has_deleted_at ? " AND deleted_at IS NULL" : "";
        if ($cid === 0 && (($_SESSION['user_role'] ?? '') === 'superadmin')) {
            $sidebar_pending = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'{$del}")->fetchColumn();
            try {
                $sidebar_pending_customers = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'pending'")->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_customers = 0;
            }
        } elseif ($cid > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND status = 'pending'{$del}");
            $st->execute([$cid]);
            $sidebar_pending = (int) $st->fetchColumn();
            try {
                $stc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND status = 'pending'");
                $stc->execute([$cid]);
                $sidebar_pending_customers = (int) $stc->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_customers = 0;
            }
        }
    } catch (Throwable $e) {}
}
$sidebar_pending_total = $sidebar_pending + $sidebar_pending_customers;
if ($sidebar_pending > 0 && $sidebar_pending_customers > 0) {
    $sidebar_bell_href = 'admin_pending_hub.php';
} elseif ($sidebar_pending_customers > 0) {
    $sidebar_bell_href = 'admin_customer_approvals.php';
} else {
    $sidebar_bell_href = 'admin_approvals.php';
}
$sidebar_bell_title = __('bell_pending');
if ($sidebar_pending > 0 || $sidebar_pending_customers > 0) {
    $sidebar_bell_title = __f('bell_pending_detail', $sidebar_pending, $sidebar_pending_customers);
}
$sidebar_user_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$__ur = $_SESSION['user_role'] ?? '';
$sidebar_user_role = $__ur === 'superadmin' ? __('role_bb') : ($__ur === 'boss' ? __('role_boss') : ($__ur === 'admin' ? __('role_admin') : ($__ur === 'agent' ? __('role_agent') : __('role_staff'))));
$sidebar_user_initial = mb_substr($sidebar_user_name, 0, 1, 'UTF-8');
$sidebar_avatar_url = trim((string)($_SESSION['avatar_url'] ?? ''));
$sidebar_company_id = (int)($_SESSION['company_id'] ?? 0);
$sidebar_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$sidebar_company_label = '';
try {
    if (!empty($pdo) && $sidebar_company_id === 0 && $sidebar_is_superadmin) {
        $sidebar_company_label = __('company_hq');
    } elseif (!empty($pdo) && $sidebar_company_id > 0) {
        $stmt = $pdo->prepare("SELECT code, name FROM companies WHERE id = ? LIMIT 1");
        $stmt->execute([$sidebar_company_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($r) {
            $sidebar_company_label = trim((string)($r['code'] ?? '')) . ' - ' . trim((string)($r['name'] ?? ''));
        }
    }
} catch (Throwable $e) {}
$sidebar_companies = [];
if ($sidebar_is_superadmin) {
    try {
        $sidebar_companies = $pdo->query("SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $sidebar_companies = [];
    }
}
$sidebar_uri = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
$sidebar_path = parse_url($sidebar_uri, PHP_URL_PATH) ?: 'dashboard.php';
$sidebar_qs = parse_url($sidebar_uri, PHP_URL_QUERY);
$sidebar_lang_rel = basename($sidebar_path);
if ($sidebar_lang_rel === '' || $sidebar_lang_rel === '.' || $sidebar_lang_rel === '/') {
    $sidebar_lang_rel = 'dashboard.php';
}
if ($sidebar_qs !== null && $sidebar_qs !== '') {
    $sidebar_lang_rel .= '?' . $sidebar_qs;
}
$sidebar_lang_to = rawurlencode($sidebar_lang_rel);
?>
<aside class="dashboard-sidebar">
    <div class="sidebar-top-row">
        <div class="sidebar-lang-bar">
            <nav class="sidebar-lang-switch" aria-label="<?= htmlspecialchars(__('lang_switch_aria'), ENT_QUOTES, 'UTF-8') ?>">
                <a href="switch_lang.php?lang=en&amp;to=<?= htmlspecialchars($sidebar_lang_to, ENT_QUOTES, 'UTF-8') ?>" class="<?= app_lang() === 'en' ? 'is-active' : '' ?>">Eng</a>
                <span class="sidebar-lang-sep" aria-hidden="true">|</span>
                <a href="switch_lang.php?lang=zh&amp;to=<?= htmlspecialchars($sidebar_lang_to, ENT_QUOTES, 'UTF-8') ?>" class="<?= app_lang() === 'zh' ? 'is-active' : '' ?>">中文</a>
            </nav>
        </div>
        <div class="sidebar-drawer-header">
            <button type="button" class="sidebar-close" id="sidebar-close" aria-label="<?= htmlspecialchars(__('nav_close_menu'), ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>
    </div>
    <div class="sidebar-profile">
        <div class="sidebar-avatar" aria-hidden="true">
            <?php if ($sidebar_avatar_url !== ''): ?>
                <img src="<?= htmlspecialchars($sidebar_avatar_url) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <?= htmlspecialchars($sidebar_user_initial) ?>
            <?php endif; ?>
        </div>
        <div class="sidebar-name"><?= htmlspecialchars($sidebar_user_name) ?></div>
        <div class="sidebar-role"><?= htmlspecialchars($sidebar_user_role) ?></div>
        <?php if ($sidebar_is_superadmin && $sidebar_company_label !== ''): ?>
            <div class="sidebar-role" style="margin-top:-6px; text-transform:none; letter-spacing:0; font-size:12px; color:rgba(255,255,255,0.78);">
                <?= htmlspecialchars($sidebar_company_label) ?>
            </div>
        <?php endif; ?>
        <?php if ($sidebar_is_superadmin): ?>
            <form method="post" action="switch_company.php" style="width:100%; margin-top: 8px;">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'dashboard.php') ?>">
                <select name="company_id" class="form-control" onchange="this.form.submit()" style="width:100%; min-height:40px; border-radius:12px; border:1px solid rgba(255,255,255,0.22); background: rgba(255,255,255,0.12); color:#fff;">
                    <option value="0" <?= $sidebar_company_id === 0 ? 'selected' : '' ?> style="color:#0f172a;"><?= htmlspecialchars(__('company_hq'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($sidebar_companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $sidebar_company_id ? 'selected' : '' ?> style="color:#0f172a;">
                            <?= htmlspecialchars((string)$c['code']) ?> - <?= htmlspecialchars((string)$c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
    <div class="sidebar-sep" aria-hidden="true"></div>
    <?php if (($_SESSION['user_role'] ?? '') === 'agent'): ?>
    <a href="agents.php" class="nav-item <?= $sidebar_current === 'agents' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_agent'), ENT_QUOTES, 'UTF-8') ?></a>
    <?php else: ?>
    <?php if (has_permission('home_dashboard') || has_permission('statement_report')): ?>
    <div class="nav-group" data-group="home-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['dashboard', 'report'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-home-menu" id="nav-toggle-home-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_home'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-home-menu" role="region" aria-labelledby="nav-toggle-home-menu" style="display:<?= in_array($sidebar_current, ['dashboard', 'report'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('home_dashboard')): ?><a href="dashboard.php" class="nav-item nav-sub-item <?= $sidebar_current === 'dashboard' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('statement_report')): ?><a href="report.php" class="nav-item nav-sub-item <?= $sidebar_current === 'report' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_report'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('statement_balance')): ?><a href="balance_summary.php" class="nav-item <?= $sidebar_current === 'balance_summary' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_statement'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
    <?php if (has_permission('transaction_list')): ?><a href="transaction_list.php" class="nav-item <?= $sidebar_current === 'transaction_list' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_transactions'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
    <?php if (has_permission('transaction_create') || has_permission('customer_create')): ?>
    <div class="nav-group" data-group="add-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['transaction_create', 'customer_create'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-add-menu" id="nav-toggle-add-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_add'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-add-menu" role="region" aria-labelledby="nav-toggle-add-menu" style="display:<?= in_array($sidebar_current, ['transaction_create', 'customer_create'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('transaction_create')): ?><a href="transaction_create.php" class="nav-item nav-sub-item <?= $sidebar_current === 'transaction_create' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_add_transaction'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('customer_create')): ?><a href="customer_create.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customer_create' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_new_customer'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('expense_statement') || has_permission('kiosk_expense_view') || has_permission('kiosk_statement')): ?>
    <div class="nav-group" data-group="expense-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['expense_statement', 'expense_kiosk', 'kiosk_statement'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-expense-menu" id="nav-toggle-expense-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_expense'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-expense-menu" role="region" aria-labelledby="nav-toggle-expense-menu" style="display:<?= in_array($sidebar_current, ['expense_statement', 'expense_kiosk', 'kiosk_statement'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('expense_statement')): ?><a href="expense.php" class="nav-item nav-sub-item <?= $sidebar_current === 'expense_statement' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_expense_statement'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('kiosk_expense_view')): ?><a href="kiosk_expense.php" class="nav-item nav-sub-item <?= $sidebar_current === 'expense_kiosk' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_kiosk_expense'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('kiosk_statement')): ?><a href="kiosk_statement.php" class="nav-item nav-sub-item <?= $sidebar_current === 'kiosk_statement' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_kiosk_statement'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('rebate') || has_permission('agent')): ?>
    <div class="nav-group" data-group="rebate-menu">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['rebate', 'agents'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-rebate-menu" id="nav-toggle-rebate-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_rebate'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-rebate-menu" role="region" aria-labelledby="nav-toggle-rebate-menu" style="display:<?= in_array($sidebar_current, ['rebate', 'agents'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('rebate')): ?><a href="rebate.php" class="nav-item nav-sub-item <?= $sidebar_current === 'rebate' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_customer_rebate'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('agent')): ?><a href="agents.php" class="nav-item nav-sub-item <?= $sidebar_current === 'agents' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_agent_rebate'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('customers') || has_permission('product_library')): ?>
    <div class="nav-group" data-group="account-customer">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['customers', 'product_library'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-account-customer" id="nav-toggle-account-customer">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_customer_detail'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-account-customer" role="region" aria-labelledby="nav-toggle-account-customer" style="display:<?= in_array($sidebar_current, ['customers', 'product_library'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customers' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_customers'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item nav-sub-item <?= $sidebar_current === 'product_library' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_product_accounts'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php
    $sidebar_show_admin_nav = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true);
    ?>
    <?php if ($sidebar_show_admin_nav): ?>
        <div class="sidebar-nav-divider" role="presentation" aria-hidden="true"></div>
        <?php if ($sidebar_is_superadmin): ?>
        <a href="admin_companies.php" class="nav-item <?= $sidebar_current === 'admin_companies' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_companies'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
        <a href="admin_users.php" class="nav-item <?= $sidebar_current === 'admin_users' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_user_management'), ENT_QUOTES, 'UTF-8') ?></a>
        <a href="admin_banks_products.php" class="nav-item <?= ($sidebar_current === 'admin_banks' || $sidebar_current === 'admin_products' || $sidebar_current === 'admin_banks_products') ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_banks_products'), ENT_QUOTES, 'UTF-8') ?></a>
        <a href="admin_permissions.php" class="nav-item <?= $sidebar_current === 'admin_permissions' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_permissions'), ENT_QUOTES, 'UTF-8') ?></a>
        <div class="sidebar-nav-divider sidebar-nav-divider--before-logout" role="presentation" aria-hidden="true"></div>
    <?php endif; ?>
    <a href="logout.php" class="nav-item nav-item-logout"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_logout'), ENT_QUOTES, 'UTF-8') ?></a>
</aside>
<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="<?= htmlspecialchars(__('nav_open_menu'), ENT_QUOTES, 'UTF-8') ?>"><span class="sidebar-toggle-icon">☰</span> <?= htmlspecialchars(__('nav_menu'), ENT_QUOTES, 'UTF-8') ?></button>
<?php if (in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true) && $sidebar_pending_total > 0): ?>
<a href="<?= htmlspecialchars($sidebar_bell_href) ?>" class="sidebar-bell" title="<?= htmlspecialchars($sidebar_bell_title) ?>" aria-label="<?= htmlspecialchars(__f('bell_aria', (int)$sidebar_pending_total), ENT_QUOTES, 'UTF-8') ?>"><span class="sidebar-bell-icon">🔔</span><span class="sidebar-bell-badge"><?= (int)$sidebar_pending_total ?></span></a>
<?php endif; ?>
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>
<style>
/* 确保所有页面都有统一弹窗样式（即使未引入 style.css） */
.app-modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
.app-modal-mask.show { display: flex; }
.app-modal { width: min(92vw, 460px); background: #fff; border-radius: 16px; border: 1px solid rgba(199, 210, 254, 0.65); box-shadow: 0 24px 50px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(255, 255, 255, 0.8) inset; transform: scale(.94) translateY(12px); opacity: 0; transition: transform .28s cubic-bezier(0.33, 1, 0.68, 1), opacity .28s ease; overflow: hidden; }
.app-modal-mask.show .app-modal { transform: scale(1) translateY(0); opacity: 1; }
.app-modal-head { padding: 16px 20px; font-weight: 700; color: #2e3dad; background: linear-gradient(180deg, #eef2ff 0%, #e0e7ff 100%); border-bottom: 1px solid rgba(165, 180, 252, 0.55); }
.app-modal-body { padding: 20px 20px 14px; color: #0f172a; font-size: 15px; line-height: 1.65; }
.app-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 0 20px 18px; }
</style>
<div class="app-modal-mask" id="app-modal-mask" aria-hidden="true">
    <div class="app-modal" role="dialog" aria-modal="true" aria-labelledby="app-modal-title">
        <div class="app-modal-head" id="app-modal-title"><?= htmlspecialchars(__('modal_system_title'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="app-modal-body" id="app-modal-message"></div>
        <div class="app-modal-foot" id="app-modal-footer"></div>
    </div>
</div>
<script>
window.__APP_I18N = <?= json_encode([
    'modalSystem' => __('modal_system_title'),
    'modalConfirm' => __('modal_confirm_title'),
    'ok' => __('btn_ok'),
    'cancel' => __('btn_cancel'),
    'confirmDefault' => __('confirm_prompt_default'),
], JSON_UNESCAPED_UNICODE) ?>;
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
        var I = window.__APP_I18N || {};
        modalTitle.textContent = title || I.modalSystem || 'Notice';
        modalMsg.textContent = message || '';
        modalFooter.innerHTML = '';
        (buttons || []).forEach(function(b){
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = b.className || 'btn btn-outline';
            btn.textContent = b.text || I.ok || 'OK';
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
        var I = window.__APP_I18N || {};
        openModal(title || I.modalSystem, message || '', [{ text: I.ok, className: 'btn btn-primary' }]);
    };
    window.appModalConfirm = function(message, onConfirm, title) {
        var I = window.__APP_I18N || {};
        openModal(title || I.modalConfirm, message || '', [
            { text: I.cancel, className: 'btn btn-outline' },
            { text: I.ok, className: 'btn btn-primary', onClick: onConfirm }
        ]);
    };

    // 自动接管 data-confirm
    document.querySelectorAll('form[data-confirm]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var msg = form.getAttribute('data-confirm') || (window.__APP_I18N && window.__APP_I18N.confirmDefault) || '';
            window.appModalConfirm(msg, function(){ form.submit(); });
        });
    });
    document.querySelectorAll('a[data-confirm]').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            var href = a.getAttribute('href') || '#';
            var msg = a.getAttribute('data-confirm') || (window.__APP_I18N && window.__APP_I18N.confirmDefault) || '';
            window.appModalConfirm(msg, function(){ window.location.href = href; });
        });
    });
})();
</script>
