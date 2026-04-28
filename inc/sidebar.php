<?php
// 固定侧栏，各选项页共用。使用前需定义 $sidebar_current（当前页标识，用于高亮）
if (!isset($sidebar_current)) $sidebar_current = '';
$sidebar_pending = 0;
$sidebar_pending_customers = 0;
$sidebar_pending_pwreset = 0;
$sidebar_pending_txedit = 0;
if (in_array(($_SESSION['user_role'] ?? ''), ['admin', 'superadmin', 'boss'], true) && !empty($pdo) && function_exists('current_company_id')) {
    try {
        $pdoSide = (function_exists('pdo_business')) ? pdo_business() : $pdo;
        $cid = current_company_id();
        $has_deleted_at = true;
        try { $pdoSide->query("SELECT deleted_at FROM transactions LIMIT 0"); } catch (Throwable $e) { $has_deleted_at = false; }
        $del = $has_deleted_at ? " AND deleted_at IS NULL" : "";
        if ($cid === 0 && (($_SESSION['user_role'] ?? '') === 'superadmin')) {
            $sidebar_pending = (int) $pdoSide->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'{$del}")->fetchColumn();
            try {
                $sidebar_pending_customers = (int) $pdoSide->query("SELECT COUNT(*) FROM customers WHERE status = 'pending'")->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_customers = 0;
            }
            try {
                $sidebar_pending_pwreset = (int) $pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'")->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_pwreset = 0;
            }
            try {
                $sidebar_pending_txedit = (int) $pdo->query("SELECT COUNT(*) FROM transaction_edit_requests WHERE status = 'pending'")->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_txedit = 0;
            }
        } elseif ($cid > 0) {
            $st = $pdoSide->prepare("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND status = 'pending'{$del}");
            $st->execute([$cid]);
            $sidebar_pending = (int) $st->fetchColumn();
            try {
                $stc = $pdoSide->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND status = 'pending'");
                $stc->execute([$cid]);
                $sidebar_pending_customers = (int) $stc->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_customers = 0;
            }
            try {
                $stp = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE company_id = ? AND status = 'pending'");
                $stp->execute([$cid]);
                $sidebar_pending_pwreset = (int) $stp->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_pwreset = 0;
            }
            try {
                $stx = $pdo->prepare("SELECT COUNT(*) FROM transaction_edit_requests WHERE company_id = ? AND status = 'pending'");
                $stx->execute([$cid]);
                $sidebar_pending_txedit = (int) $stx->fetchColumn();
            } catch (Throwable $e) {
                $sidebar_pending_txedit = 0;
            }
        }
    } catch (Throwable $e) {}
}
$sidebar_pending_total = $sidebar_pending + $sidebar_pending_customers + $sidebar_pending_pwreset + $sidebar_pending_txedit;
if (($sidebar_pending > 0 && $sidebar_pending_customers > 0)
    || ($sidebar_pending > 0 && $sidebar_pending_pwreset > 0)
    || ($sidebar_pending_customers > 0 && $sidebar_pending_pwreset > 0)
    || ($sidebar_pending_txedit > 0 && ($sidebar_pending > 0 || $sidebar_pending_customers > 0 || $sidebar_pending_pwreset > 0))) {
    $sidebar_bell_href = 'admin_pending_hub.php';
} elseif ($sidebar_pending_customers > 0) {
    $sidebar_bell_href = 'admin_customer_approvals.php';
} elseif ($sidebar_pending_pwreset > 0) {
    $sidebar_bell_href = 'admin_password_resets.php';
} elseif ($sidebar_pending_txedit > 0) {
    $sidebar_bell_href = 'admin_txn_edit_approvals.php';
} else {
    $sidebar_bell_href = 'admin_approvals.php';
}
$sidebar_bell_title = __('bell_pending');
if ($sidebar_pending > 0 || $sidebar_pending_customers > 0) {
    $sidebar_bell_title = __f('bell_pending_detail', $sidebar_pending, $sidebar_pending_customers);
}
if ($sidebar_pending_pwreset > 0) {
    $sidebar_bell_title = __('bell_pending');
}
$sidebar_user_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$__ur = $_SESSION['user_role'] ?? '';
$sidebar_user_role = $__ur === 'superadmin' ? __('role_bb') : ($__ur === 'boss' ? __('role_boss') : ($__ur === 'admin' ? __('role_admin') : ($__ur === 'agent' ? __('role_agent') : __('role_staff'))));
$sidebar_user_initial = function_exists('mb_substr')
    ? mb_substr($sidebar_user_name, 0, 1, 'UTF-8')
    : substr((string) $sidebar_user_name, 0, 1);
$sidebar_avatar_url = trim((string)($_SESSION['avatar_url'] ?? ''));
$sidebar_show_avatar_picker = !empty($_SESSION['user_id']);
$avatar_presets_list = [];
$avatar_picker_current_id = null;
if ($sidebar_show_avatar_picker) {
    require_once __DIR__ . '/avatar_presets.php';
    $avatar_presets_list = avatar_presets_list();
    $avatar_picker_current_id = avatar_preset_id_from_url($sidebar_avatar_url);
}
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
        <?php if ($sidebar_show_avatar_picker): ?>
        <button type="button" class="sidebar-avatar sidebar-avatar-btn" id="sidebar-avatar-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="avatar-picker-popover" title="<?= htmlspecialchars(__('avatar_pick_btn'), ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($sidebar_avatar_url !== ''): ?>
                <img id="sidebar-avatar-img" class="sidebar-avatar-fill" src="<?= htmlspecialchars($sidebar_avatar_url) ?>" alt="" loading="lazy" decoding="async">
                <span id="sidebar-avatar-letter" class="sidebar-avatar-letter" hidden aria-hidden="true"><?= htmlspecialchars($sidebar_user_initial) ?></span>
            <?php else: ?>
                <span id="sidebar-avatar-letter" class="sidebar-avatar-letter"><?= htmlspecialchars($sidebar_user_initial) ?></span>
            <?php endif; ?>
        </button>
        <div class="avatar-picker-popover" id="avatar-picker-popover" role="dialog" aria-modal="true" aria-labelledby="avatar-picker-title" hidden>
            <div class="avatar-picker-title" id="avatar-picker-title"><?= htmlspecialchars(__('avatar_pick_title'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="avatar-picker-grid avatar-picker-grid-presets" role="group" aria-label="<?= htmlspecialchars(__('avatar_pick_title'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($avatar_presets_list as $p):
                    $_av_abs = __DIR__ . '/../' . $p['url'];
                    $_av_src = $p['url'] . (is_file($_av_abs) ? '?v=' . (int)filemtime($_av_abs) : '');
                ?>
                <button type="button" class="avatar-picker-cell<?= ($avatar_picker_current_id === $p['id']) ? ' is-selected' : '' ?>" data-preset="<?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= htmlspecialchars($_av_src, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                </button>
                <?php endforeach; ?>
            </div>
            <div class="avatar-picker-upload">
                <p class="avatar-picker-upload-hint"><?= htmlspecialchars(__('avatar_upload_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                <input type="file" id="avatar-file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <button type="button" class="avatar-upload-btn" id="avatar-upload-btn"><?= htmlspecialchars(__('avatar_upload_btn'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
        <?php else: ?>
        <div class="sidebar-avatar" aria-hidden="true">
            <?php if ($sidebar_avatar_url !== ''): ?>
                <img src="<?= htmlspecialchars($sidebar_avatar_url) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <?= htmlspecialchars($sidebar_user_initial) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['dashboard', 'report', 'customer_report'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-home-menu" id="nav-toggle-home-menu">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars(__('nav_home'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-home-menu" role="region" aria-labelledby="nav-toggle-home-menu" style="display:<?= in_array($sidebar_current, ['dashboard', 'report', 'customer_report'], true) ? 'block' : 'none' ?>">
            <?php if (has_permission('home_dashboard')): ?><a href="dashboard.php" class="nav-item nav-sub-item <?= $sidebar_current === 'dashboard' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('statement_report')): ?><a href="report.php" class="nav-item nav-sub-item <?= $sidebar_current === 'report' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_report'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php if (has_permission('statement_report')): ?><a href="customer_report.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customer_report' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_customer_report'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (has_permission('statement_balance')): ?><a href="balance_summary.php" class="nav-item <?= $sidebar_current === 'balance_summary' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_statement'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
    <?php
    $__txn_href = 'transaction_list.php';
    $__is_pg_company = false;
    if (has_permission('transaction_list') && !empty($pdo) && function_exists('current_company_id')) {
        try {
            $__cid = (int)current_company_id();
            if ($__cid > 0) {
                $pdoCatSide = function_exists('shard_catalog') ? shard_catalog() : $pdo;
                $sts = $pdoCatSide->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
                $sts->execute([$__cid]);
                if (strtolower(trim((string)$sts->fetchColumn())) === 'pg') {
                    $__txn_href = 'pg_transaction_list.php';
                    $__is_pg_company = true;
                }
            }
        } catch (Throwable $e) {
        }
    }
    ?>
    <?php if (has_permission('transaction_list')): ?><a href="<?= htmlspecialchars($__txn_href, ENT_QUOTES, 'UTF-8') ?>" class="nav-item <?= $sidebar_current === 'transaction_list' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_transactions'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
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
    <?php if (!$__is_pg_company && (has_permission('rebate') || has_permission('agent'))): ?>
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
    <?php if (has_permission('customers') || has_permission('product_library') || $sidebar_is_superadmin): ?>
    <div class="nav-group" data-group="account-customer">
        <button type="button" class="nav-group-toggle nav-item" aria-expanded="<?= in_array($sidebar_current, ['customers', 'product_library', 'admin_customer_export_log'], true) ? 'true' : 'false' ?>" aria-controls="nav-sub-account-customer" id="nav-toggle-account-customer">
            <span class="nav-icon"></span>
            <span class="nav-group-label"><?= htmlspecialchars($__is_pg_company ? 'Detail' : __('nav_customer_detail'), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-group-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-group-sub" id="nav-sub-account-customer" role="region" aria-labelledby="nav-toggle-account-customer" style="display:<?= in_array($sidebar_current, ['customers', 'product_library', 'admin_customer_export_log'], true) ? 'block' : 'none' ?>">
            <?php if ($__is_pg_company): ?>
                <?php if ($sidebar_show_admin_nav): ?><a href="admin_banks_products.php" class="nav-item nav-sub-item <?= ($sidebar_current === 'admin_banks' || $sidebar_current === 'admin_products' || $sidebar_current === 'admin_banks_products') ? 'primary' : '' ?>"><span class="nav-icon"></span>Bank detail</a><?php endif; ?>
                <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customers' ? 'primary' : '' ?>"><span class="nav-icon"></span>Customer detail</a><?php endif; ?>
            <?php else: ?>
                <?php if (has_permission('customers')): ?><a href="customers.php" class="nav-item nav-sub-item <?= $sidebar_current === 'customers' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_customers'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                <?php if (has_permission('product_library')): ?><a href="product_library.php" class="nav-item nav-sub-item <?= $sidebar_current === 'product_library' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_product_accounts'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                <?php if ($sidebar_is_superadmin): ?><a href="admin_customer_export_log.php" class="nav-item nav-sub-item <?= $sidebar_current === 'admin_customer_export_log' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_customer_export_log'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
            <?php endif; ?>
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
        <?php if (in_array(($_SESSION['user_role'] ?? ''), ['boss', 'superadmin'], true)): ?>
        <a href="admin_txn_edit_audit.php" class="nav-item <?= $sidebar_current === 'admin_txn_edit_audit' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_txn_edit_audit'), ENT_QUOTES, 'UTF-8') ?></a>
        <a href="admin_telegram_pg.php" class="nav-item <?= $sidebar_current === 'admin_telegram_pg' ? 'primary' : '' ?>"><span class="nav-icon"></span>PG Telegram</a>
        <a href="admin_telegram_bot_status.php" class="nav-item <?= $sidebar_current === 'admin_telegram_bot_status' ? 'primary' : '' ?>"><span class="nav-icon"></span>Telegram 连线</a>
        <?php endif; ?>
        <div class="sidebar-nav-divider sidebar-nav-divider--before-logout" role="presentation" aria-hidden="true"></div>
    <?php endif; ?>
    <a href="change_password.php" class="nav-item <?= $sidebar_current === 'change_password' ? 'primary' : '' ?>"><span class="nav-icon"></span><?= htmlspecialchars(__('nav_change_password'), ENT_QUOTES, 'UTF-8') ?></a>
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
    'avatarSaved' => __('avatar_pick_saved'),
    'avatarErr' => __('avatar_pick_err'),
    'avatarUploadSize' => __('avatar_upload_err_size'),
    'avatarUploadType' => __('avatar_upload_err_type'),
    'avatarUploadImage' => __('avatar_upload_err_image'),
    'avatarUploadDims' => __('avatar_upload_err_dims'),
], JSON_UNESCAPED_UNICODE) ?>;
(function(){
    var btn = document.getElementById('sidebar-toggle');
    var overlay = document.getElementById('sidebar-overlay');
    if (btn && overlay) {
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
    }
    function bindNavGroupToggles() {
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
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindNavGroupToggles);
    } else {
        bindNavGroupToggles();
    }

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

    // 侧栏头像选择器
    (function(){
        var btn = document.getElementById('sidebar-avatar-btn');
        var pop = document.getElementById('avatar-picker-popover');
        if (!btn || !pop) return;
        /* 抽屉侧栏带 transform 时，fixed 会相对侧栏定位导致弹层被裁成 ~280px；挂到 body 才相对视口 */
        if (pop.parentNode !== document.body) {
            document.body.appendChild(pop);
        }
        var imgEl = document.getElementById('sidebar-avatar-img');
        var letterEl = document.getElementById('sidebar-avatar-letter');
        var I = window.__APP_I18N || {};
        function positionAvatarPopover() {
            var margin = 8;
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            var maxW = Math.min(300, vw - margin * 2);
            pop.style.width = maxW + 'px';
            pop.style.maxWidth = (vw - margin * 2) + 'px';
            var r = btn.getBoundingClientRect();
            var left = r.left + r.width / 2 - maxW / 2;
            left = Math.max(margin, Math.min(left, vw - maxW - margin));
            var top = r.bottom + margin;
            pop.style.left = left + 'px';
            pop.style.top = top + 'px';
            var h = pop.offsetHeight;
            if (top + h > vh - margin) {
                top = r.top - h - margin;
                if (top < margin) top = margin;
                pop.style.top = top + 'px';
            }
        }
        function setOpen(open) {
            if (open) {
                pop.removeAttribute('hidden');
                btn.setAttribute('aria-expanded', 'true');
                positionAvatarPopover();
                requestAnimationFrame(function() { positionAvatarPopover(); });
            } else {
                pop.setAttribute('hidden', 'hidden');
                btn.setAttribute('aria-expanded', 'false');
            }
        }
        function toggle() { setOpen(pop.hasAttribute('hidden')); }
        function onPopoverReposition() {
            if (!pop.hasAttribute('hidden')) positionAvatarPopover();
        }
        window.addEventListener('resize', onPopoverReposition);
        var sideEl = document.querySelector('.dashboard-sidebar');
        if (sideEl) sideEl.addEventListener('scroll', onPopoverReposition, { passive: true });
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            toggle();
        });
        function applyAvatarFromServer(url) {
            if (!url) return;
            var showUrl = url;
            if (url.indexOf('uploads/avatars/') === 0 || url.indexOf('assets/avatars/') === 0) {
                showUrl = url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
            }
            var im = imgEl;
            if (!im) {
                im = document.createElement('img');
                im.id = 'sidebar-avatar-img';
                im.className = 'sidebar-avatar-fill';
                im.alt = '';
                im.decoding = 'async';
                btn.insertBefore(im, btn.firstChild);
                imgEl = im;
            }
            im.src = showUrl;
            im.removeAttribute('hidden');
            if (letterEl) {
                letterEl.setAttribute('hidden', 'hidden');
                letterEl.setAttribute('aria-hidden', 'true');
            }
        }
        function avatarUploadFail(code) {
            var map = {
                toobig: I.avatarUploadSize,
                type: I.avatarUploadType,
                image: I.avatarUploadImage,
                dimensions: I.avatarUploadDims
            };
            window.appModalAlert(map[code] || I.avatarErr || 'Error');
        }
        pop.querySelectorAll('.avatar-picker-cell').forEach(function(cell){
            cell.addEventListener('click', function(){
                var preset = cell.getAttribute('data-preset');
                if (!preset) return;
                pop.querySelectorAll('.avatar-picker-cell').forEach(function(c){ c.classList.remove('is-selected'); });
                cell.classList.add('is-selected');
                var body = 'preset=' + encodeURIComponent(preset);
                fetch('avatar_pick_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin'
                }).then(function(r){ return r.json(); }).then(function(data){
                    if (!data || !data.ok || !data.url) {
                        window.appModalAlert(I.avatarErr || 'Error');
                        return;
                    }
                    applyAvatarFromServer(data.url);
                    setOpen(false);
                }).catch(function(){
                    window.appModalAlert(I.avatarErr || 'Error');
                });
            });
        });
        var fileIn = document.getElementById('avatar-file-input');
        var upBtn = document.getElementById('avatar-upload-btn');
        if (fileIn && upBtn) {
            upBtn.addEventListener('click', function(){ fileIn.click(); });
            fileIn.addEventListener('change', function(){
                if (!fileIn.files || !fileIn.files[0]) return;
                var fd = new FormData();
                fd.append('avatar', fileIn.files[0]);
                fetch('avatar_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        fileIn.value = '';
                        if (!data || !data.ok || !data.url) {
                            avatarUploadFail(data && data.error ? data.error : '');
                            return;
                        }
                        pop.querySelectorAll('.avatar-picker-cell').forEach(function(c){ c.classList.remove('is-selected'); });
                        applyAvatarFromServer(data.url);
                        setOpen(false);
                    })
                    .catch(function(){ fileIn.value = ''; window.appModalAlert(I.avatarErr || 'Error'); });
            });
        }
        document.addEventListener('click', function(e){
            if (!pop.hasAttribute('hidden') && !pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
                setOpen(false);
            }
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && !pop.hasAttribute('hidden')) setOpen(false);
        });
    })();
})();
</script>
