<?php
require 'config.php';
require 'auth.php';
require_permission('agent');
$sidebar_current = 'agents';
$company_id = current_company_id();

$err = '';
$msg = '';
$warn = '';
$agents = [];
$is_agent_user = ($_SESSION['user_role'] ?? '') === 'agent';
$agent_code = $is_agent_user ? trim((string)($_SESSION['agent_code'] ?? '')) : '';
$agent_rebate_settings_map = [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

/** Agent 端：周一至周日为一周；月为自然月 1 日—末日。仅允许四种 period。 */
function agents_agent_period_range(string $period): array
{
    $allowed = ['this_week', 'last_week', 'this_month', 'last_month'];
    if (!in_array($period, $allowed, true)) {
        $period = 'this_week';
    }
    $ref = new DateTime('today');
    if ($period === 'this_month' || $period === 'last_month') {
        if ($period === 'this_month') {
            $from = $ref->format('Y-m-01');
            $to = $ref->format('Y-m-t');
            return [$from, $to, $period];
        }
        $lm = (clone $ref)->modify('first day of last month');
        $from = $lm->format('Y-m-d');
        $to = $lm->format('Y-m-t');
        return [$from, $to, $period];
    }
    $dow = (int)$ref->format('N');
    $monday_this = clone $ref;
    $monday_this->modify('-' . ($dow - 1) . ' days');
    if ($period === 'last_week') {
        $monday_this->modify('-7 days');
    }
    $sunday = clone $monday_this;
    $sunday->modify('+6 days');
    return [$monday_this->format('Y-m-d'), $sunday->format('Y-m-d'), $period];
}

$agent_period = 'this_week';
$agent_ui_show_week = true;
$agent_ui_show_month = true;
if ($is_agent_user) {
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stPrefs = $pdo->prepare('SELECT agent_ui_show_week, agent_ui_show_month FROM users WHERE id = ? LIMIT 1');
            $stPrefs->execute([$uid]);
            $prefRow = $stPrefs->fetch(PDO::FETCH_ASSOC);
            if ($prefRow) {
                $agent_ui_show_week = (int)($prefRow['agent_ui_show_week'] ?? 1) === 1;
                $agent_ui_show_month = (int)($prefRow['agent_ui_show_month'] ?? 1) === 1;
            }
        }
    } catch (Throwable $e) {
        $agent_ui_show_week = true;
        $agent_ui_show_month = true;
    }
    $week_periods = ['this_week', 'last_week'];
    $month_periods = ['this_month', 'last_month'];
    $allowed_periods = [];
    if ($agent_ui_show_week) {
        $allowed_periods = array_merge($allowed_periods, $week_periods);
    }
    if ($agent_ui_show_month) {
        $allowed_periods = array_merge($allowed_periods, $month_periods);
    }
    if ($allowed_periods === []) {
        $allowed_periods = ['this_week'];
    }
    $p = strtolower(trim((string)($_GET['period'] ?? '')));
    if (!in_array($p, $allowed_periods, true)) {
        if (in_array('this_week', $allowed_periods, true)) {
            $p = 'this_week';
        } elseif (in_array('this_month', $allowed_periods, true)) {
            $p = 'this_month';
        } else {
            $p = $allowed_periods[0];
        }
    }
    [$day_from, $day_to, $agent_period] = agents_agent_period_range($p);
} else {
    $day_from_raw = isset($_REQUEST['day_from']) && trim((string)$_REQUEST['day_from']) !== '' ? $_REQUEST['day_from'] : $yesterday;
    $day_to_raw = isset($_REQUEST['day_to']) && trim((string)$_REQUEST['day_to']) !== '' ? $_REQUEST['day_to'] : $yesterday;
    $day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_from_raw) ? substr($day_from_raw, 0, 10) : $today;
    $day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', $day_to_raw) ? substr($day_to_raw, 0, 10) : $today;
    if ($day_from > $day_to) {
        $t = $day_from;
        $day_from = $day_to;
        $day_to = $t;
    }
}

$agent_welcome_user = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ''));
if ($agent_welcome_user === '') {
    $agent_welcome_user = __('perm_agent');
}
$agent_welcome_line = __f('agent_welcome', defined('AGENT_PORTAL_BRAND') ? AGENT_PORTAL_BRAND : 'k8win', $agent_welcome_user);

function ensure_agent_rebate_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_rebate_settings (
        company_id INT UNSIGNED NOT NULL DEFAULT 1,
        agent_code VARCHAR(80) NOT NULL,
        rebate_pct DECIMAL(10,2) NOT NULL DEFAULT 0,
        rebate_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_paid TINYINT(1) NOT NULL DEFAULT 0,
        paid_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        PRIMARY KEY (company_id, agent_code)
    )");
    try {
        $c0 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'rebate_enabled'")->fetchColumn();
        if ($c0 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN rebate_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {}
    try {
        $c1 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'is_paid'")->fetchColumn();
        if ($c1 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {}
    try {
        $c2 = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_rebate_settings' AND COLUMN_NAME = 'paid_at'")->fetchColumn();
        if ($c2 === 0) {
            $pdo->exec("ALTER TABLE agent_rebate_settings ADD COLUMN paid_at DATETIME NULL");
        }
    } catch (Throwable $e) {}
}

function get_agent_win_loss(PDO $pdo, string $agent, string $day_from, string $day_to, int $company_id): float {
    $agent = trim($agent);
    if ($agent === '') return 0.0;
    $sql = "SELECT COALESCE(SUM(sub.pnl), 0) AS win_loss
            FROM customers c
            LEFT JOIN (
                SELECT TRIM(code) AS code,
                       SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END) - SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END) AS pnl
            FROM transactions
            WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ?
                GROUP BY TRIM(code)
            ) sub ON TRIM(c.code) = sub.code
            WHERE c.company_id = ? AND c.recommend IS NOT NULL AND TRIM(c.recommend) != '' AND TRIM(c.recommend) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $day_from, $day_to, $company_id, $agent]);
    return (float)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_agent_user) {
    $warn = __('agent_warn_readonly');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_rebate_pct') {
        try {
            $agent = trim((string)($_POST['agent'] ?? ''));
            $pct_raw = str_replace(',', '.', trim((string)($_POST['rebate_pct'] ?? '0')));
            $pct = is_numeric($pct_raw) ? (float)$pct_raw : -1;
            $is_paid = !empty($_POST['is_paid']) ? 1 : 0;
            if ($agent === '') {
                throw new RuntimeException(__('agent_err_agent_empty'));
            }
            if ($pct < 0 || $pct > 100) {
                throw new RuntimeException(__('agent_err_pct_range'));
            }
            if ($is_agent_user && strcasecmp($agent, $agent_code) !== 0) {
                throw new RuntimeException(__('agent_err_own_pct'));
            }
            $post_day_from = preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)($_POST['day_from'] ?? ''))) ? substr(trim((string)$_POST['day_from']), 0, 10) : $day_from;
            $post_day_to = preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)($_POST['day_to'] ?? ''))) ? substr(trim((string)$_POST['day_to']), 0, 10) : $day_to;
            $current_win_loss = get_agent_win_loss($pdo, $agent, $post_day_from, $post_day_to, $company_id);
            if ($current_win_loss <= 0) {
                // 仅正 Win(Loss) 可给：0 或负数一律不可标记已给
                $is_paid = 0;
            }
            ensure_agent_rebate_table($pdo);
            $stmt = $pdo->prepare("INSERT INTO agent_rebate_settings (company_id, agent_code, rebate_pct, is_paid, paid_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE rebate_pct = VALUES(rebate_pct), is_paid = VALUES(is_paid), paid_at = VALUES(paid_at), updated_by = VALUES(updated_by)");
            $paid_at = $is_paid ? date('Y-m-d H:i:s') : null;
            $stmt->execute([$company_id, $agent, $pct, $is_paid, $paid_at, (int)($_SESSION['user_id'] ?? 0)]);
            $msg = __('agent_msg_saved');
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } elseif ($action === 'toggle_rebate_enabled') {
        try {
            $agent = trim((string)($_POST['agent'] ?? ''));
            $enabled = !empty($_POST['rebate_enabled']) ? 1 : 0;
            if ($agent === '') {
                throw new RuntimeException(__('agent_err_agent_empty'));
            }
            if ($is_agent_user && strcasecmp($agent, $agent_code) !== 0) {
                throw new RuntimeException(__('agent_err_own_toggle'));
            }
            ensure_agent_rebate_table($pdo);
            $stmt = $pdo->prepare("INSERT INTO agent_rebate_settings (company_id, agent_code, rebate_enabled, updated_by) VALUES (?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE rebate_enabled = VALUES(rebate_enabled), updated_by = VALUES(updated_by)");
            $stmt->execute([$company_id, $agent, $enabled, (int)($_SESSION['user_id'] ?? 0)]);
            if ($enabled === 0) {
                // 暂停反水时，顺便清除已给状态，避免误会
                $stmt2 = $pdo->prepare("UPDATE agent_rebate_settings SET is_paid = 0, paid_at = NULL WHERE company_id = ? AND agent_code = ?");
                $stmt2->execute([$company_id, $agent]);
            }
            $msg = $enabled ? __('agent_msg_enabled') : __('agent_msg_paused');
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

try {
    // Win(Loss) = 该 Agent 下所有顾客的 (deposit - withdraw)；与顾客列表一致；正=入款大于出款（可作返水基数）
    $sql = "
        SELECT TRIM(c.recommend) AS agent, COUNT(*) AS cnt,
               COALESCE(SUM(sub.pnl), 0) AS win_loss
        FROM customers c
        LEFT JOIN (
            SELECT TRIM(code) AS code,
                   SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END) - SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END) AS pnl
            FROM transactions WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) != '' AND day >= ? AND day <= ?
            GROUP BY TRIM(code)
        ) sub ON TRIM(c.code) = sub.code
        WHERE c.company_id = ? AND c.recommend IS NOT NULL AND TRIM(c.recommend) != ''
    ";
    $params = [$company_id, $day_from, $day_to, $company_id];
    if ($is_agent_user && $agent_code !== '') {
        $sql .= " AND TRIM(c.recommend) = ?";
        $params[] = $agent_code;
    }
    $sql .= " GROUP BY TRIM(c.recommend) ORDER BY agent ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 保底显示：即使没有下线客户/没有走单，也显示 users.role=agent 的账号（Win/Loss=0）
    try {
        $stAgents = $pdo->prepare("SELECT username FROM users WHERE company_id = ? AND role = 'agent' AND is_active = 1");
        $stAgents->execute([$company_id]);
        $known = [];
        foreach ($agents as $row0) {
            $k0 = strtolower(trim((string)($row0['agent'] ?? '')));
            if ($k0 !== '') {
                $known[$k0] = true;
            }
        }
        foreach ($stAgents->fetchAll(PDO::FETCH_ASSOC) as $ua) {
            $uname = trim((string)($ua['username'] ?? ''));
            if ($uname === '') {
                continue;
            }
            $ku = strtolower($uname);
            if (isset($known[$ku])) {
                continue;
            }
            if ($is_agent_user && $agent_code !== '' && strcasecmp($uname, $agent_code) !== 0) {
                continue;
            }
            $agents[] = ['agent' => $uname, 'cnt' => 0, 'win_loss' => 0];
            $known[$ku] = true;
        }
        usort($agents, static function (array $a, array $b): int {
            return strcasecmp((string)($a['agent'] ?? ''), (string)($b['agent'] ?? ''));
        });
    } catch (Throwable $e) {
        // 不影响主流程
    }

    if ($is_agent_user && $agent_code !== '' && empty($agents)) {
        $agents = [['agent' => $agent_code, 'cnt' => 0, 'win_loss' => 0]];
    }
    try {
        ensure_agent_rebate_table($pdo);
        $st = $pdo->prepare("SELECT agent_code, rebate_pct, rebate_enabled, is_paid, paid_at FROM agent_rebate_settings WHERE company_id = ?");
        $st->execute([$company_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtolower(trim((string)($r['agent_code'] ?? '')));
            if ($k === '') continue;
            $agent_rebate_settings_map[$k] = [
                'pct' => (float)($r['rebate_pct'] ?? 0),
                'rebate_enabled' => !isset($r['rebate_enabled']) ? true : ((int)$r['rebate_enabled'] === 1),
                'is_paid' => (int)($r['is_paid'] ?? 0) === 1,
                'paid_at' => (string)($r['paid_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // 若无建表权限，不阻断主页面
    }
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'recommend') !== false) {
        $err = __('agent_err_migrate');
    } else {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__f('agent_page_title', defined('SITE_TITLE') ? SITE_TITLE : 'K8'), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .agent-winloss-pos { color: var(--success); font-weight: 700; }
        .agent-winloss-neg { color: var(--danger); font-weight: 700; }
        /* Agent 本人汇总：赢 = 蓝，输 = 红，平 = 灰 */
        .agent-winloss-win { color: #2563eb; font-weight: 700; font-variant-numeric: tabular-nums; }
        .agent-winloss-loss { color: var(--danger); font-weight: 700; font-variant-numeric: tabular-nums; }
        .agent-winloss-even { color: #64748b; font-weight: 600; font-variant-numeric: tabular-nums; }
        a.agent-winloss-link {
            text-decoration: none;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        a.agent-winloss-link:hover { text-decoration: underline; }
        /* 压过 style.css .data-table a { color: var(--primary) }，负数保持红色 */
        .agent-self-table .data-table a.agent-winloss-link.agent-winloss-loss { color: var(--danger); }
        .agent-self-table .data-table a.agent-winloss-link.agent-winloss-win { color: #2563eb; }
        .agent-self-table .data-table a.agent-winloss-link.agent-winloss-even { color: #64748b; }
        a.agent-summary-customers-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        a.agent-summary-customers-link:hover .agent-summary-name { color: var(--primary); text-decoration: underline; }
        .agent-view-customers-hint {
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            margin-top: 4px;
        }
        a.agent-summary-customers-link:hover .agent-view-customers-hint { text-decoration: underline; }
        .agent-summary-name { font-weight: 700; color: #0f172a; font-size: 15px; }
        .agent-summary-code { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .agent-commission-amt { font-weight: 700; font-variant-numeric: tabular-nums; color: #059669; }
        .agent-commission-zero { color: #64748b; font-variant-numeric: tabular-nums; }
        .agent-paid-cell { vertical-align: middle; }
        .agent-paid-form { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
        .agent-paid-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .agent-paid-check {
            display: inline-flex; align-items: center; gap: 6px; margin: 0;
            font-size: 13px; color: #475569; cursor: pointer; user-select: none;
        }
        .agent-paid-check input:disabled { cursor: not-allowed; }
        .agent-paid-check input:disabled + span { opacity: 0.55; }
        .agent-paid-meta { font-size: 11px; color: var(--muted); line-height: 1.3; }
        .agent-pct-view { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; width: 100%; }
        .agent-pct-edit { display: none; align-items: center; gap: 6px; justify-content: flex-end; width: 100%; }
        .agent-pct-badge { font-weight: 600; color: #1f2937; font-variant-numeric: tabular-nums; }
        .btn-pct-edit {
            padding: 2px 8px; min-width: 30px; font-size: 14px; line-height: 1.2;
            border-radius: 6px;
        }
        .agent-self-table { max-width: 640px; }
        .agent-self-table th,
        .agent-self-table td { font-size: 15px; }
        .agent-period-pill {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
            padding: 12px 18px 12px 14px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
            max-width: 100%;
        }
        .agent-period-pill svg {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            color: #2563eb;
        }
        .agent-period-pill-body { min-width: 0; }
        .agent-period-pill-dates {
            font-size: 16px;
            font-weight: 700;
            color: #1e3a8a;
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.02em;
            line-height: 1.35;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <?php if ($is_agent_user): ?>
                    <h2 style="font-size:1.35rem; font-weight:700; color:#0f172a; letter-spacing:0.02em;"><?= htmlspecialchars($agent_welcome_line) ?></h2>
                    <?php
                    $agent_df_show = date('d/m/Y', strtotime($day_from));
                    $agent_dt_show = date('d/m/Y', strtotime($day_to));
                    ?>
                    <div class="agent-period-pill" role="status" aria-label="<?= htmlspecialchars(__('agent_period_aria'), ENT_QUOTES, 'UTF-8') ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <div class="agent-period-pill-body">
                            <div class="agent-period-pill-dates"><?= htmlspecialchars($agent_df_show) ?> - <?= htmlspecialchars($agent_dt_show) ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <h2><?= htmlspecialchars(__('perm_agent'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="breadcrumb">
                        <a href="dashboard.php"><?= htmlspecialchars(__('agent_breadcrumb_home'), ENT_QUOTES, 'UTF-8') ?></a><span>·</span><?= htmlspecialchars(__('agent_breadcrumb_sub'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php
                    $ref_adm = new DateTime('today');
                    $dow_adm = (int)$ref_adm->format('N');
                    $mon_this = clone $ref_adm;
                    $mon_this->modify('-' . ($dow_adm - 1) . ' days');
                    $this_week_start = $mon_this->format('Y-m-d');
                    $this_week_end = (clone $mon_this)->modify('+6 days')->format('Y-m-d');
                    $mon_last = clone $mon_this;
                    $mon_last->modify('-7 days');
                    $last_week_start = $mon_last->format('Y-m-d');
                    $last_week_end = (clone $mon_last)->modify('+6 days')->format('Y-m-d');
                    $this_month_start = $ref_adm->format('Y-m-01');
                    $this_month_end = $ref_adm->format('Y-m-t');
                    $lm_adm = (clone $ref_adm)->modify('first day of last month');
                    $last_month_start = $lm_adm->format('Y-m-d');
                    $last_month_end = $lm_adm->format('Y-m-t');
                ?>
                <?php if ($is_agent_user): ?>
                <div class="filters-bar filters-bar-flow" style="margin-bottom:16px;">
                    <div class="filters-row filters-row-presets" style="flex-wrap:wrap;">
                        <?php if ($agent_ui_show_week): ?>
                        <a href="agents.php?period=this_week" class="btn btn-preset<?= $agent_period === 'this_week' ? ' btn-primary' : '' ?>"><?= htmlspecialchars(__('agent_btn_this_week'), ENT_QUOTES, 'UTF-8') ?></a>
                        <a href="agents.php?period=last_week" class="btn btn-preset<?= $agent_period === 'last_week' ? ' btn-primary' : '' ?>"><?= htmlspecialchars(__('agent_btn_last_week'), ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endif; ?>
                        <?php if ($agent_ui_show_month): ?>
                        <a href="agents.php?period=this_month" class="btn btn-preset<?= $agent_period === 'this_month' ? ' btn-primary' : '' ?>"><?= htmlspecialchars(__('agent_btn_this_month'), ENT_QUOTES, 'UTF-8') ?></a>
                        <a href="agents.php?period=last_month" class="btn btn-preset<?= $agent_period === 'last_month' ? ' btn-primary' : '' ?>"><?= htmlspecialchars(__('agent_btn_last_month'), ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <form class="filters-bar filters-bar-flow" method="get" style="margin-bottom:16px;">
                    <div class="filters-row filters-row-main">
                        <div class="filter-group">
                            <label><?= htmlspecialchars(__('agent_filter_from'), ENT_QUOTES, 'UTF-8') ?>:</label>
                            <input type="date" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                        </div>
                        <div class="filter-group">
                            <label>To:</label>
                            <input type="date" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                        </div>
                        <button type="submit" class="btn btn-search"><?= htmlspecialchars(__('btn_search'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    <div class="filters-row filters-row-presets">
                        <a href="agents.php?<?= http_build_query(['day_from' => $this_week_start, 'day_to' => $this_week_end]) ?>" class="btn btn-preset"><?= htmlspecialchars(__('agent_btn_this_week'), ENT_QUOTES, 'UTF-8') ?></a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $last_week_start, 'day_to' => $last_week_end]) ?>" class="btn btn-preset"><?= htmlspecialchars(__('agent_btn_last_week'), ENT_QUOTES, 'UTF-8') ?></a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $this_month_start, 'day_to' => $this_month_end]) ?>" class="btn btn-preset"><?= htmlspecialchars(__('agent_btn_this_month'), ENT_QUOTES, 'UTF-8') ?></a>
                        <a href="agents.php?<?= http_build_query(['day_from' => $last_month_start, 'day_to' => $last_month_end]) ?>" class="btn btn-preset"><?= htmlspecialchars(__('agent_btn_last_month'), ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                </form>
                <?php endif; ?>
                <?php if ($warn): ?>
                    <div class="alert alert-error" role="status"><?= htmlspecialchars($warn) ?></div>
                <?php endif; ?>
                <?php if ($err): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
                <?php elseif ($msg): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if (!$err): ?>
                <?php if (!$is_agent_user): ?>
                <div class="summary">
                    <div class="summary-item"><strong><?= htmlspecialchars(__('perm_agent'), ENT_QUOTES, 'UTF-8') ?></strong><span class="num"><?= count($agents) ?></span></div>
                </div>
                <?php endif; ?>
                <div class="card<?= $is_agent_user ? ' agent-self-table' : '' ?>" style="overflow-x: auto;">
                    <h3><?= htmlspecialchars($is_agent_user ? __('agent_list_title_self') : __('agent_list_title_admin'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php if ($is_agent_user): ?>
                                <th><?= htmlspecialchars(__('agent_col_name'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_winloss'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_commission'), ENT_QUOTES, 'UTF-8') ?></th>
                                <?php else: ?>
                                <th><?= htmlspecialchars(__('agent_col_agent'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_customers'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_winloss'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_rebate_pct'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('agent_col_rebate_amt'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('agent_col_rebate_switch'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('agent_col_paid'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('lbl_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $r):
                                $agent = $r['agent'] ?? '';
                                $cnt = (int)($r['cnt'] ?? 0);
                                $winLoss = (float)($r['win_loss'] ?? 0);
                                $setting = $agent_rebate_settings_map[strtolower(trim((string)$agent))] ?? ['pct' => 0, 'rebate_enabled' => true, 'is_paid' => false, 'paid_at' => ''];
                                $pct = (float)($setting['pct'] ?? 0);
                                $rebate_enabled = !empty($setting['rebate_enabled']);
                                $rebate_base = $winLoss > 0 ? $winLoss : 0; // 仅正数时可给（与 deposit-withdraw 约定一致）
                                if (!$rebate_enabled) $rebate_base = 0;
                                $rebate_amount = round($rebate_base * $pct / 100, 2);
                                $is_paid = !empty($setting['is_paid']);
                                $paid_at = trim((string)($setting['paid_at'] ?? ''));
                                $recommend_param = htmlspecialchars(urlencode($agent));
                                $can_pay = $winLoss > 0 && $rebate_enabled;
                                if (!$can_pay) $is_paid = false;
                                $paid_disable_title = '';
                                if (!$rebate_enabled) {
                                    $paid_disable_title = __('agent_paid_pause');
                                } elseif (!$can_pay) {
                                    $paid_disable_title = __('agent_paid_not_positive');
                                }
                                $wl_class_agent = 'agent-winloss-even';
                                if ($winLoss < 0) {
                                    $wl_class_agent = 'agent-winloss-loss';
                                } elseif ($winLoss > 0) {
                                    $wl_class_agent = 'agent-winloss-win';
                                }
                                $agent_row_display_name = trim((string)($_SESSION['user_name'] ?? ''));
                                if ($agent_row_display_name === '') {
                                    $agent_row_display_name = trim((string)$agent) !== '' ? trim((string)$agent) : (string)($agent_code ?: 'Agent');
                                }
                                $cust_detail_href = 'customers.php?' . http_build_query([
                                    'recommend' => trim((string)$agent),
                                    'day_from' => $day_from,
                                    'day_to' => $day_to,
                                ]);
                            ?>
                            <tr>
                                <?php if ($is_agent_user): ?>
                                <td>
                                    <a href="<?= htmlspecialchars($cust_detail_href) ?>" class="agent-summary-customers-link" title="<?= htmlspecialchars(__('agent_title_winloss_detail'), ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="agent-summary-name"><?= htmlspecialchars($agent_row_display_name) ?></div>
                                        <div class="agent-view-customers-hint"><?= htmlspecialchars(__('agent_view_customers'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php
                                        $code_show = trim((string)$agent);
                                        if ($code_show !== '' && strcasecmp($code_show, $agent_row_display_name) !== 0):
                                        ?>
                                        <div class="agent-summary-code"><?= htmlspecialchars($code_show) ?></div>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td class="num">
                                    <a href="<?= htmlspecialchars($cust_detail_href) ?>" class="agent-winloss-link <?= htmlspecialchars($wl_class_agent) ?>" title="<?= htmlspecialchars(__('agent_title_winloss_detail'), ENT_QUOTES, 'UTF-8') ?>"><?= number_format($winLoss, 2) ?></a>
                                </td>
                                <td class="num <?= $rebate_amount > 0 ? 'agent-commission-amt' : 'agent-commission-zero' ?>"><?= number_format($rebate_amount, 2) ?></td>
                                <?php else: ?>
                                <td><?= htmlspecialchars($agent) ?></td>
                                <td class="num"><?= $cnt ?></td>
                                <td class="num">
                                    <a href="<?= htmlspecialchars($cust_detail_href) ?>" class="agent-winloss-link <?= $winLoss >= 0 ? 'agent-winloss-pos' : 'agent-winloss-neg' ?>" title="<?= htmlspecialchars(__('agent_title_winloss_detail'), ENT_QUOTES, 'UTF-8') ?>"><?= number_format($winLoss, 2) ?></a>
                                </td>
                                <td class="num">
                                    <form method="post" class="js-agent-rebate-form" style="display:inline-flex; align-items:center; gap:6px;">
                                        <input type="hidden" name="action" value="save_rebate_pct">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" class="js-winloss" value="<?= htmlspecialchars((string)$winLoss) ?>">
                                        <div class="agent-pct-view js-pct-view">
                                            <span class="agent-pct-badge"><?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>%</span>
                                            <button type="button" class="btn btn-sm btn-outline btn-pct-edit js-pct-edit-btn" title="<?= htmlspecialchars(__('agent_edit_rebate_title'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('agent_edit_rebate_title'), ENT_QUOTES, 'UTF-8') ?>">✎</button>
                                        </div>
                                        <div class="agent-pct-edit js-pct-edit">
                                            <input type="text" name="rebate_pct" class="form-control js-rebate-pct" inputmode="decimal" value="<?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>" style="width:86px; text-align:right;">
                                            <button type="submit" class="btn btn-sm btn-outline"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                                            <button type="button" class="btn btn-sm btn-outline js-pct-cancel-btn"><?= htmlspecialchars(__('btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </div>
                                    </form>
                                </td>
                                <td class="num js-rebate-amount <?= $rebate_amount > 0 ? 'agent-winloss-pos' : '' ?>"><?= number_format($rebate_amount, 2) ?></td>
                                <td>
                                    <form method="post" class="agent-rebate-toggle-form">
                                        <input type="hidden" name="action" value="toggle_rebate_enabled">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" name="rebate_enabled" value="<?= $rebate_enabled ? '0' : '1' ?>">
                                        <button type="submit"
                                            class="btn btn-sm <?= $rebate_enabled ? 'btn-danger' : 'btn-primary' ?>"
                                            title="<?= htmlspecialchars($rebate_enabled ? __('agent_toggle_pause_title') : __('agent_toggle_resume_title'), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($rebate_enabled ? __('agent_btn_pause') : __('agent_btn_resume'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="agent-paid-cell">
                                    <form method="post" class="agent-paid-form js-agent-paid-form">
                                        <input type="hidden" name="action" value="save_rebate_pct">
                                        <input type="hidden" name="agent" value="<?= htmlspecialchars($agent) ?>">
                                        <input type="hidden" name="day_from" value="<?= htmlspecialchars($day_from) ?>">
                                        <input type="hidden" name="day_to" value="<?= htmlspecialchars($day_to) ?>">
                                        <input type="hidden" name="rebate_pct" value="<?= htmlspecialchars(number_format($pct, 2, '.', '')) ?>">
                                        <div class="agent-paid-row">
                                            <label class="agent-paid-check"<?= $paid_disable_title !== '' ? ' title="' . htmlspecialchars($paid_disable_title) . '"' : '' ?>>
                                                <input type="checkbox" name="is_paid" value="1" <?= $is_paid ? 'checked' : '' ?> <?= $can_pay ? '' : 'disabled' ?>>
                                                <span><?= htmlspecialchars(__('agent_paid_label'), ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                            <button type="submit" class="btn btn-sm btn-outline" <?= $can_pay ? '' : 'disabled' ?> title="<?= htmlspecialchars($can_pay ? __('agent_save_paid_title') : $paid_disable_title, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </div>
                                        <?php if ($paid_at !== ''): ?>
                                        <div class="agent-paid-meta"><?= htmlspecialchars($paid_at) ?></div>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td><a href="<?= htmlspecialchars($cust_detail_href) ?>"><?= htmlspecialchars(__('agent_view_customers'), ENT_QUOTES, 'UTF-8') ?></a></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="<?= $is_agent_user ? '3' : '8' ?>" style="color:var(--muted); padding:24px;"><?= htmlspecialchars(__('agent_empty_row'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
    (function(){
        function toNum(v) {
            var n = parseFloat(String(v || '').replace(/[,%\s]/g, ''));
            return isNaN(n) ? 0 : n;
        }
        document.querySelectorAll('.js-agent-rebate-form').forEach(function(form){
            var pctInput = form.querySelector('.js-rebate-pct');
            var winLossInput = form.querySelector('.js-winloss');
            var amountCell = form.closest('tr') ? form.closest('tr').querySelector('.js-rebate-amount') : null;
            var viewBox = form.querySelector('.js-pct-view');
            var editBox = form.querySelector('.js-pct-edit');
            var editBtn = form.querySelector('.js-pct-edit-btn');
            var cancelBtn = form.querySelector('.js-pct-cancel-btn');
            if (!pctInput || !winLossInput || !amountCell) return;
            function recalc(){
                var winLoss = toNum(winLossInput.value);
                var pct = toNum(pctInput.value);
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                var base = winLoss > 0 ? winLoss : 0;
                var amount = base * pct / 100;
                amountCell.textContent = amount.toFixed(2);
            }
            pctInput.addEventListener('input', recalc);
            if (editBtn && viewBox && editBox) {
                editBtn.addEventListener('click', function(){
                    viewBox.style.display = 'none';
                    editBox.style.display = 'inline-flex';
                    pctInput.focus();
                    pctInput.select();
                });
            }
            if (cancelBtn && viewBox && editBox) {
                cancelBtn.addEventListener('click', function(){
                    editBox.style.display = 'none';
                    viewBox.style.display = 'inline-flex';
                });
            }
            recalc();
        });
    })();
    </script>
</body>
</html>
