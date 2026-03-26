<?php
require 'config.php';
require 'auth.php';

function ensure_transactions_expense_kind(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN expense_kind ENUM('statement','kiosk') NULL DEFAULT NULL COMMENT 'EXPENSE 分类' AFTER product");
    } catch (Throwable $e) {
        // 列已存在等
    }
}

ensure_transactions_expense_kind($pdo);

function ensure_products_kiosk_columns(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN kiosk_fee_pct DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'Kiosk %' AFTER sort_order");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN kiosk_paid_amount DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'Kiosk amount paid' AFTER kiosk_fee_pct");
    } catch (Throwable $e) {
    }
}

/** Kiosk % 输入框展示：去掉多余尾随 0（如 15.0000 → 15） */
function kiosk_format_pct_for_input($raw): string {
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = str_replace(',', '', trim((string)$raw));
    if ($s === '' || !is_numeric($s)) {
        return trim((string)$raw);
    }
    $f = (float)$s;
    $formatted = rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

/** 金额向上取整到分（两位小数） */
function kiosk_ceil_money2(float $x): float {
    return ceil(round($x * 100, 8)) / 100;
}

// 权限：
// - Kiosk Expense（quick=expense&expense_kind=kiosk）：允许拥有 statement 权限的 member 查看（不含录入）。
// - 其他 transaction_create 功能：需要 transaction_create 权限。
$quick = trim((string)($_GET['quick'] ?? ''));
$ekg_raw = trim((string)($_GET['expense_kind'] ?? $_POST['expense_kind'] ?? 'statement'));
$expense_kind_ui_pre = ($quick === 'expense' && in_array($ekg_raw, ['statement', 'kiosk'], true)) ? $ekg_raw : 'statement';
if ($quick === 'expense' && $expense_kind_ui_pre === 'kiosk') {
    require_permission('kiosk_expense_view');
} elseif ($quick === 'expense' && $expense_kind_ui_pre === 'statement') {
    require_permission('expense_statement');
} else {
    require_permission('transaction_create');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_kiosk_gp_meta']) && trim((string)($_POST['expense_kind'] ?? '')) === 'kiosk') {
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: kiosk_expense.php');
        exit;
    }
    ensure_products_kiosk_columns($pdo);
    $df = trim((string)($_POST['redirect_expense_day_from'] ?? ''));
    $dt = trim((string)($_POST['redirect_expense_day_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
        $df = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        $dt = date('Y-m-d');
    }
    if ($df > $dt) {
        $tmp = $df;
        $df = $dt;
        $dt = $tmp;
    }
    $day_from = $df;
    $day_to = $dt;
    require_once __DIR__ . '/inc/game_platform_statement_compute.php';

    $pctArr = $_POST['kiosk_pct'] ?? [];
    if (is_array($pctArr)) {
        foreach ($pctArr as $name => $pctRaw) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $pct = str_replace(',', '', trim((string)$pctRaw));
            $pctVal = ($pct === '' || !is_numeric($pct)) ? null : round((float)$pct, 4);
            $gk = strtolower($name);
            $kin = (float)($range_in_product[$gk] ?? 0);
            $kout = (float)($range_out_product[$gk] ?? 0);
            $knet = $kin - $kout;
            $paidVal = null;
            if ($pctVal !== null) {
                $paidVal = round(kiosk_ceil_money2($knet * ((float)$pctVal) / 100), 2);
            }
            try {
                $stmt = $pdo->prepare('UPDATE products SET kiosk_fee_pct = ?, kiosk_paid_amount = ? WHERE name = ? AND is_active = 1');
                $stmt->execute([$pctVal, $paidVal, $name]);
            } catch (Throwable $e) {
            }
        }
    }
    $q = [
        'expense_kind' => 'kiosk',
        'expense_day_from' => $df,
        'expense_day_to' => $dt,
    ];
    $eb = trim((string)($_POST['redirect_expense_bank'] ?? ''));
    $epr = trim((string)($_POST['redirect_expense_product'] ?? ''));
    if ($eb !== '') {
        $q['expense_bank'] = $eb;
    }
    if ($epr !== '') {
        $q['expense_product'] = $epr;
    }
    header('Location: kiosk_expense.php?' . http_build_query($q));
    exit;
}

$expense_kind_ui = 'statement';
if ($quick === 'expense') {
    $ekg = trim((string)($_GET['expense_kind'] ?? $_POST['expense_kind'] ?? 'statement'));
    $expense_kind_ui = in_array($ekg, ['statement', 'kiosk'], true) ? $ekg : 'statement';
}
$sidebar_current = $quick === 'expense'
    ? ($expense_kind_ui === 'kiosk' ? 'expense_kiosk' : 'expense_statement')
    : 'transaction_create';

$saved = false;
$error = '';
$submitted_status = '';
$expense_day_from_raw = trim((string)($_GET['expense_day_from'] ?? date('Y-m-01')));
$expense_day_to_raw = trim((string)($_GET['expense_day_to'] ?? date('Y-m-d')));
$expense_bank_filter = trim((string)($_GET['expense_bank'] ?? ''));
$expense_product_filter = trim((string)($_GET['expense_product'] ?? ''));
$expense_day_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_day_from_raw) ? $expense_day_from_raw : date('Y-m-01');
$expense_day_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_day_to_raw) ? $expense_day_to_raw : date('Y-m-d');
if ($expense_day_from > $expense_day_to) { $tmp = $expense_day_from; $expense_day_from = $expense_day_to; $expense_day_to = $tmp; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    // member：未点击「+」修改日期时间则用当前时间且自动通过审核
    $member_use_current = isset($_POST['member_use_current_time']) && (string)$_POST['member_use_current_time'] === '1';
    if ($quick === 'expense') {
        $can_edit_dt = !$member_use_current; // Expense 页统一：点 + 才使用手动日期时间
    } elseif ($is_admin) {
        $can_edit_dt = !empty($_POST['edit_dt']);
    } else {
        $can_edit_dt = !$member_use_current; // member 点了「+」才用表单里的日期时间
    }
    $day     = $can_edit_dt && isset($_POST['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['day'] ?? '')) ? trim($_POST['day']) : date('Y-m-d');
    $timeRaw = $can_edit_dt && isset($_POST['time']) ? trim($_POST['time'] ?? '00:00') : date('H:i');
    $time    = (strlen($timeRaw) === 5 && preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $timeRaw)) ? $timeRaw . ':00' : ($timeRaw ?: '00:00:00');
    $mode    = trim($_POST['mode'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $bank    = trim($_POST['bank'] ?? '');
    $product = trim($_POST['product'] ?? '');
    $amount    = str_replace(',', '', trim($_POST['amount'] ?? '0'));
    $reward_pct = str_replace(',', '', trim((string)($_POST['reward_pct'] ?? '')));
    $bonus_fix  = str_replace(',', '', trim((string)($_POST['bonus'] ?? '0')));
    $remark   = trim($_POST['remark'] ?? '');
    $expense_kind_post = trim((string)($_POST['expense_kind'] ?? ''));
    $expense_kind_save = null;
    if ($mode === 'EXPENSE') {
        $expense_kind_save = in_array($expense_kind_post, ['statement', 'kiosk'], true) ? $expense_kind_post : 'statement';
    }

    if ($day === '' || $mode === '') {
        $error = '请填写日期和模式。';
    } elseif ($mode === 'EXPENSE' && ($bank === '' || $product === '')) {
        $error = 'EXPENSE 必须填写 Bank 和 Expense 项目。';
    } elseif (!is_numeric($amount)) {
        $error = '金额请填数字。';
    } else {
        $amount = (float) $amount;
        // Bonus = 金额 × 奖励/返点% / 100；优先用百分比，否则用隐藏域 bonus
        $bonus = 0;
        if ($reward_pct !== '' && is_numeric($reward_pct)) {
            $bonus = round($amount * (float)$reward_pct / 100, 2);
        }
        if ($bonus <= 0 && $bonus_fix !== '' && is_numeric($bonus_fix) && (float)$bonus_fix > 0) {
            $bonus = round((float) $bonus_fix, 2);
        }
        $total  = round($amount + $bonus, 2);

        // member 使用当前时间提交则无需审核，否则待审核
        $status = $is_admin ? 'approved' : ($member_use_current ? 'approved' : 'pending');
        $approved_by = ($status === 'approved') ? (int)($_SESSION['user_id'] ?? 0) : null;
        $approved_at = ($status === 'approved') ? date('Y-m-d H:i:s') : null;
        $staff = (string) ($_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? ''));

        $saved = false;
        $expKindIns = ($mode === 'EXPENSE') ? $expense_kind_save : null;
        $insertBase = [$day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null, $expKindIns, $amount, $bonus, $total, $staff ?: null, $remark ?: null, $status, (int)($_SESSION['user_id'] ?? 0), $approved_by, $approved_at];
        foreach ([true, false] as $withHide) {
            try {
                if ($withHide) {
                    $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, expense_kind, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertBase);
                } else {
                    $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, expense_kind, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertBase);
                }
                $saved = true;
                break;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'expense_kind') !== false || stripos($msg, 'Unknown column') !== false) {
                    $insertBaseLegacy = [$day, $time, $mode, $code ?: null, $bank ?: null, $product ?: null, $amount, $bonus, $total, $staff ?: null, $remark ?: null, $status, (int)($_SESSION['user_id'] ?? 0), $approved_by, $approved_at];
                    try {
                        if ($withHide) {
                            $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($insertBaseLegacy);
                        } else {
                            $sql = "INSERT INTO transactions (day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($insertBaseLegacy);
                        }
                        $saved = true;
                        break;
                    } catch (Throwable $e2) {
                        throw $e;
                    }
                }
                if (stripos($msg, 'bonus') !== false || stripos($msg, 'total') !== false) {
                    $error = '数据库缺少 bonus/total 列。请在 phpMyAdmin 中执行 migrate_bonus.sql 里的 SQL。';
                    break;
                }
                if (stripos($msg, 'hide_from_member') !== false && $withHide) continue;
                throw $e;
            }
        }
        if ($saved) {
        $submitted_status = $status;
            if ($status === 'pending') {
                if (file_exists(__DIR__ . '/inc/notify.php')) {
                    require_once __DIR__ . '/inc/notify.php';
                    send_pending_approval_notify($pdo);
                }
            }
            $saved_mode = $mode;
        $saved_code = $code;
        $saved_product = $product;
        $saved_amount = $amount;
        $saved_bonus = $bonus;
        $saved_total = $total;
        $saved_reward_pct = ($reward_pct !== '' && is_numeric($reward_pct)) ? (float)$reward_pct : null;
        $saved_account = '';
        $saved_customer_name = '';
        $saved_customer_bank = '';
        if ($code !== '' && $product !== '') {
            try {
                $acc = $pdo->prepare("SELECT a.account FROM customer_product_accounts a INNER JOIN customers c ON c.id = a.customer_id WHERE c.code = ? AND a.product_name = ? LIMIT 1");
                $acc->execute([$code, $product]);
                $row = $acc->fetch();
                $saved_account = $row ? (trim($row['account'] ?? '') ?: '—') : '—';
            } catch (Throwable $e) {
                $saved_account = '—';
            }
        } else {
            $saved_account = '—';
        }
        if ($mode === 'WITHDRAW' && $code !== '') {
            try {
                $cust = $pdo->prepare("SELECT name, bank_details FROM customers WHERE code = ? LIMIT 1");
                $cust->execute([$code]);
                $crow = $cust->fetch();
                $saved_customer_name = $crow ? trim($crow['name'] ?? '') : '';
                $saved_customer_bank = $crow ? trim($crow['bank_details'] ?? '') : '';
            } catch (Throwable $e) {}
        }
        }
    }
}

$today = date('Y-m-d');
$now   = date('H:i');
$selected_mode = trim((string)($_POST['mode'] ?? ($quick === 'expense' ? 'EXPENSE' : '')));

// 银行/产品：仅 admin 可“设置”（在 admin_banks / admin_products 管理）；员工只能从已设置的选项中选择
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$banks = [];
$products = [];
$expenses = [];
$expense_report_rows = [];
$expense_report_count = 0;
$expense_report_total = 0.0;
$expense_report_by_product = [];
$expense_filter_banks = [];
$expense_filter_products = [];
// 客户代码下拉选项（含 name、bank_details 供 WITHDRAW 时显示）
$customers = [];
try {
    $customers = $pdo->query("SELECT code, name, bank_details FROM customers WHERE is_active = 1 ORDER BY code ASC")->fetchAll();
} catch (Throwable $e) {
    $customers = [];
}
try {
    $banks = $pdo->query("SELECT name FROM banks WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $banks = [];
}
try {
    $products = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $products = [];
}
try {
    $expenses = $pdo->query("SELECT name FROM expenses WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $expenses = [];
}
// admin 无数据时用内置列表，并可选「其他」手填；员工只能用管理员设置的列表，不能改
if ($is_admin) {
    if (!$banks) $banks = ['HLB', 'CASH', 'DOUGLAS', 'KAYDEN', 'RHB', 'CIMB', 'Digi', 'Maxis', 'KAYDEN TNG'];
    if (!$products) $products = ['MEGA', 'PUSSY', '918KISS', 'JOKER', 'KING855', 'LIVE22', 'ACE333', 'VPOWER', 'LPE888', 'ALIPAY', 'STANDBY'];
    if (!$expenses) $expenses = ['Office', 'Salary', 'Ads', 'Transport'];
}

if ($quick === 'expense') {
    try {
        $ekSql = " AND COALESCE(expense_kind, 'statement') = " . $pdo->quote($expense_kind_ui);
        $expense_filter_banks = $pdo->query("SELECT DISTINCT TRIM(COALESCE(bank, '')) AS bank_name FROM transactions WHERE mode = 'EXPENSE' AND status = 'approved' AND TRIM(COALESCE(bank, '')) <> ''" . $ekSql . " ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $expense_filter_products = $pdo->query("SELECT DISTINCT TRIM(COALESCE(product, '')) AS product_name FROM transactions WHERE mode = 'EXPENSE' AND status = 'approved' AND TRIM(COALESCE(product, '')) <> ''" . $ekSql . " ORDER BY product_name ASC")->fetchAll(PDO::FETCH_COLUMN);

        $where = "status = 'approved' AND mode = 'EXPENSE' AND COALESCE(expense_kind, 'statement') = ? AND day >= ? AND day <= ?";
        $params = [$expense_kind_ui, $expense_day_from, $expense_day_to];
        if ($expense_bank_filter !== '') {
            $where .= " AND bank = ?";
            $params[] = $expense_bank_filter;
        }
        if ($expense_product_filter !== '') {
            $where .= " AND product = ?";
            $params[] = $expense_product_filter;
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE $where");
        $stmt->execute($params);
        $expense_report_total = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT day, time, bank, product, expense_kind, amount, staff, remark FROM transactions WHERE $where ORDER BY day DESC, time DESC LIMIT 120");
        $stmt->execute($params);
        $expense_report_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expense_report_count = count($expense_report_rows);

        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(product), ''), '未填写产品') AS product_name, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total_amount
            FROM transactions
            WHERE $where
            GROUP BY COALESCE(NULLIF(TRIM(product), ''), '未填写产品')
            ORDER BY total_amount DESC");
        $stmt->execute($params);
        $expense_report_by_product = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // 保持页面可用，仅在下方展示错误信息
        $error = $error !== '' ? $error : ('Expense 汇总加载失败：' . $e->getMessage());
    }
}

// Kiosk Expense：Game Platform In/Out（与 statement 同源；日期与下方 From/To 筛选一致）
$kiosk_gp_products = [];
$kiosk_gp_in = [];
$kiosk_gp_out = [];
$kiosk_product_meta = [];
if ($quick === 'expense' && $expense_kind_ui === 'kiosk') {
    $day_from = $expense_day_from;
    $day_to = $expense_day_to;
    require_once __DIR__ . '/inc/game_platform_statement_compute.php';
    $kiosk_gp_products = $all_products;
    $kiosk_gp_in = $range_in_product;
    $kiosk_gp_out = $range_out_product;
    ensure_products_kiosk_columns($pdo);
    try {
        $km = $pdo->query('SELECT name, kiosk_fee_pct, kiosk_paid_amount FROM products WHERE is_active = 1');
        foreach ($km->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = strtolower(trim((string)$r['name']));
            if ($k !== '') {
                $kiosk_product_meta[$k] = [
                    'pct' => $r['kiosk_fee_pct'],
                    'paid' => $r['kiosk_paid_amount'],
                ];
            }
        }
    } catch (Throwable $e) {
        $kiosk_product_meta = [];
    }
}

$expense_page_title = ($quick === 'expense')
    ? ($expense_kind_ui === 'kiosk' ? 'Kiosk Expense' : 'Expense Statement')
    : '记一笔流水';
$expense_entry_url = ($expense_kind_ui === 'kiosk') ? 'kiosk_expense.php' : 'expense.php';
$expense_modal_should_open = ($quick === 'expense' && $expense_kind_ui !== 'kiosk' && $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '');
$ep = $expense_modal_should_open ? $_POST : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($expense_page_title) ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .txn-wrap { max-width: 760px; }
        .txn-form-card { padding: 24px; }
        .txn-form {
            display: grid;
            gap: 18px;
        }
        .form-section {
            margin: 0;
            padding: 14px 14px 12px;
            border: 1px solid rgba(126, 154, 228, 0.22);
            border-radius: 12px;
            background: rgba(255,255,255,0.72);
        }
        .form-section-title {
            font-size: 12px;
            color: #475569;
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
        .txn-form .form-group { margin-bottom: 0; }
        .txn-form .form-control { min-height: 46px; border-radius: 10px; }
        .txn-form textarea.form-control { min-height: 110px; }
        .txn-submit { width: 100%; min-height: 48px; font-size: 16px; font-weight: 700; }
        .calc-box { margin: 12px 0; padding: 12px 14px; background: #f0f9ff; border-radius: 8px; font-size: 14px; color: #0c4a6e; }
        .success-actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; }
        .success-actions a { padding: 8px 14px; background: #fff; border: 1px solid #a7f3d0; border-radius: 6px; color: #059669; text-decoration: none; font-size: 13px; }
        .success-actions a:hover { background: #ecfdf5; }
        .expense-statement-wrap { margin-top: 14px; padding: 14px 16px; }
        .expense-statement-title { margin: 0 0 10px; font-size: 14px; }
        .expense-filter-bar { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)) auto; gap: 10px; align-items: end; margin-bottom: 12px; }
        .expense-filter-item label { display: block; font-size: 12px; margin-bottom: 4px; color: var(--muted); }
        .expense-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .expense-quick-ranges {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 2px;
            padding-top: 10px;
            border-top: 1px dashed rgba(115, 146, 230, 0.35);
        }
        .expense-quick-ranges > span { font-size: 12px; color: var(--muted); margin-right: 4px; }
        .expense-quick-hint { width: 100%; margin: 4px 0 0; font-size: 12px; color: var(--muted); }
        .expense-kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(120px, 1fr)); gap: 10px; margin-bottom: 10px; }
        .expense-kpi-card { border: 1px solid rgba(115, 146, 230, 0.25); border-radius: 10px; background: rgba(255,255,255,0.82); padding: 10px 12px; }
        .expense-kpi-card strong { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .expense-kpi-card .num { font-size: 1.2rem; font-weight: 700; line-height: 1; }
        .txn-expense-block .form-section-title { margin-bottom: 12px; }
        .txn-expense-block .form-row-2 { align-items: flex-end; }
        @media (max-width: 640px) {
            .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
            .txn-form-card { padding: 16px; }
            .form-section { padding: 12px; }
            .expense-filter-bar { grid-template-columns: 1fr 1fr; }
            .expense-kpi-grid { grid-template-columns: 1fr; }
        }
        .pretty-modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(8, 16, 40, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .pretty-modal-mask.show { display: flex; }
        .pretty-modal {
            width: min(92vw, 460px);
            background: #fff;
            border-radius: 18px;
            border: 1px solid #dbeafe;
            box-shadow: 0 22px 60px rgba(37, 99, 235, 0.28);
            transform: scale(0.9) translateY(8px);
            opacity: 0;
            transition: transform .22s ease, opacity .22s ease;
            overflow: hidden;
        }
        .pretty-modal-mask.show .pretty-modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }
        .pretty-modal-head {
            padding: 14px 18px;
            font-weight: 700;
            color: #1d4ed8;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border-bottom: 1px solid #bfdbfe;
        }
        .pretty-modal-body {
            padding: 20px 18px 12px;
            color: #0f172a;
            font-size: 15px;
            line-height: 1.6;
        }
        .pretty-modal-foot {
            display: flex;
            justify-content: flex-end;
            padding: 0 18px 16px;
        }
        .pretty-ok {
            min-width: 92px;
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.35);
        }
        /* Expense 页工具栏（参考列表页 + Add） */
        .expense-userlist-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            gap: 14px;
            margin-bottom: 16px;
            padding: 14px 18px;
            border-radius: 12px;
            background: linear-gradient(180deg, #f0f7ff 0%, #e8f2fc 100%);
            border: 1px solid rgba(59, 130, 246, 0.22);
            box-shadow: 0 4px 14px rgba(30, 64, 175, 0.06);
        }
        .btn-expense-add {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 140px;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
        }
        .btn-expense-add:hover {
            filter: brightness(1.05);
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.45);
        }
        /* Expense 录入弹窗（双栏 + Add User 风格） */
        .expense-entry-modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(8, 16, 40, 0.48);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1300;
            padding: 16px;
        }
        .expense-entry-modal-mask.show { display: flex; }
        .expense-entry-modal {
            width: min(96vw, 900px);
            max-height: min(92vh, 720px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(125, 152, 226, 0.35);
            box-shadow: 0 24px 64px rgba(30, 58, 138, 0.28);
        }
        .expense-entry-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 22px;
            font-weight: 800;
            font-size: 18px;
            color: #0f172a;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        .expense-entry-modal-x {
            border: none;
            background: #f1f5f9;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            color: #64748b;
        }
        .expense-entry-modal-x:hover { color: #0f172a; background: #e2e8f0; }
        .expense-modal-form {
            padding: 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }
        .expense-modal-2col {
            display: flex;
            flex: 1;
            min-height: 0;
            align-items: stretch;
        }
        .expense-modal-col {
            flex: 1;
            min-width: 0;
            padding: 20px 22px 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .expense-modal-col-left {
            background: #fafbff;
        }
        .expense-modal-col-right {
            background: #fff;
            border-left: 1px solid #e2e8f0;
        }
        .expense-modal-section-title {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 700;
            color: #1e3a8a;
            padding-bottom: 8px;
            border-bottom: 2px solid #3b82f6;
        }
        .expense-modal-col-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding-top: 8px;
        }
        .btn-expense-save {
            min-width: 100px;
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }
        .btn-expense-save:hover { filter: brightness(1.05); }
        .btn-expense-cancel {
            min-width: 100px;
            padding: 10px 22px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-weight: 700;
            color: #475569;
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
            cursor: pointer;
        }
        .btn-expense-cancel:hover { background: #e2e8f0; }
        .expense-modal-col-actions-right {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding-top: 8px;
        }
        .btn-expense-select-all {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 3px 10px rgba(22, 163, 74, 0.35);
        }
        .btn-expense-clear-all {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #f87171 0%, #dc2626 100%);
            box-shadow: 0 3px 10px rgba(220, 38, 38, 0.35);
        }
        .expense-chip-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .expense-chip {
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
        }
        .expense-chip:hover { border-color: #3b82f6; color: #1d4ed8; background: #eff6ff; }
        .expense-modal-hint {
            margin: 0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }
        @media (max-width: 720px) {
            .expense-modal-2col { flex-direction: column; }
            .expense-modal-col-right { border-left: none; border-top: 1px solid #e2e8f0; }
        }
        .page-wrap.txn-wrap.kiosk-expense-page { max-width: 960px; }
        .kiosk-io-summary {
            margin-bottom: 16px;
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid rgba(59, 130, 246, 0.22);
            background: linear-gradient(180deg, #f0f7ff 0%, #fff 100%);
            box-shadow: 0 4px 14px rgba(30, 64, 175, 0.06);
        }
        .kiosk-io-summary .kiosk-io-title { font-size: 15px; font-weight: 800; color: #1e3a8a; }
        .kiosk-io-summary .data-table { margin: 0; }
        .kiosk-io-summary .data-table thead th { background: #2563eb; color: #fff; }
        .kiosk-io-summary .kiosk-gp-in { color: #16a34a; font-weight: 700; }
        .kiosk-io-summary .kiosk-gp-out { color: #dc2626; font-weight: 700; }
        .kiosk-io-summary .kiosk-io-net { font-weight: 700; color: #2563eb; }
        .kiosk-gp-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .kiosk-gp-filters label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #1e3a8a;
            user-select: none;
        }
        .kiosk-gp-filters input[type="checkbox"] { width: 16px; height: 16px; accent-color: #2563eb; }
        .kiosk-gp-table.is-inout-hidden th:nth-child(2),
        .kiosk-gp-table.is-inout-hidden th:nth-child(3),
        .kiosk-gp-table.is-inout-hidden td:nth-child(2),
        .kiosk-gp-table.is-inout-hidden td:nth-child(3) { display: none; }
        .kiosk-expense-filter-in-gp { margin-bottom: 14px; }
        .kiosk-gp-meta-form { margin: 0; }
        .kiosk-gp-meta-form .form-control.kiosk-gp-input { min-height: 36px; padding: 6px 8px; font-size: 13px; max-width: 120px; margin-left: auto; }
        .kiosk-gp-meta-form .form-control.kiosk-gp-paid[readonly] { background: #f1f5f9; cursor: default; color: #334155; }
        .kiosk-gp-meta-actions { margin-top: 12px; }
        .kiosk-gp-filters input[type="checkbox"]:disabled { cursor: not-allowed; opacity: 0.55; }
        .kiosk-gp-filter-placeholder { cursor: not-allowed; color: #64748b; font-weight: 500; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap txn-wrap<?= ($quick === 'expense' && $expense_kind_ui === 'kiosk') ? ' kiosk-expense-page' : '' ?>">
        <div class="page-header">
            <h2><?= htmlspecialchars($expense_page_title) ?></h2>
            <p class="breadcrumb"><a href="dashboard.php">首页</a><span>·</span><a href="transaction_list.php">流水记录</a></p>
        </div>

    <?php if ($saved): ?>
        <div class="card" style="margin-bottom: 20px;">
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 14px;">
                <div style="margin-bottom: 4px;"><strong><?= htmlspecialchars($saved_mode) ?></strong> <?= htmlspecialchars($saved_code ?: '—') ?> <?= htmlspecialchars($saved_product ?: '—') ?> · 金额 <?= number_format($saved_amount, 2) ?><?= $saved_reward_pct !== null ? '，奖励 ' . number_format($saved_reward_pct, 0) . '%' : '' ?> · <strong>本笔 Bonus：<?= number_format($saved_bonus, 2) ?></strong> · 总数 <?= number_format($saved_total, 2) ?></div>
                <?php if ($saved_bonus > 0 && !empty($saved_code)): ?><p class="form-hint" style="margin-top:4px; color:var(--success);">此笔 Bonus 将计入顾客 <?= htmlspecialchars($saved_code) ?> 的汇总<?= $submitted_status === 'pending' ? '（需管理员批准后生效）' : '。' ?></p><?php endif; ?>
                <?php if ($saved_bonus > 0 && empty($saved_code)): ?><p class="form-hint" style="margin-top:4px; color:#b45309;">未选客户时，Bonus 不会计入顾客列表；请记一笔时选择客户。</p><?php endif; ?>
                <?php if (!empty($saved_code) || !empty($saved_product) || $saved_mode === 'WITHDRAW'): ?>
                <?php if ($saved_mode === 'WITHDRAW' && !empty($saved_code)): ?>
                <div class="form-hint" style="margin-bottom:4px;">顾客姓名：<?= htmlspecialchars($saved_customer_name ?: '—') ?></div>
                <div class="form-hint">银行资料：<?= htmlspecialchars($saved_customer_bank ?: '—') ?></div>
                <?php endif; ?>
                <?php if (!empty($saved_product) && !empty($saved_code)): ?>
                <div class="form-hint" style="margin-top:4px;"><?= htmlspecialchars($saved_code) ?> 的 <?= htmlspecialchars($saved_product) ?> 账号：<?= htmlspecialchars($saved_account) ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="success-actions">
                <a href="<?= $quick === 'expense' ? htmlspecialchars($expense_entry_url) : 'transaction_create.php' ?>">再记一笔</a>
                <a href="transaction_list.php">看流水</a>
                <a href="dashboard.php">回首页</a>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-error"><?= $is_admin ? htmlspecialchars($error) : '✗ Faild' ?></div>
    <?php endif; ?>

    <?php if ($quick === 'expense'): ?>
        <?php
        $ep_day = isset($ep['day']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$ep['day']) ? htmlspecialchars(substr((string)$ep['day'], 0, 10)) : htmlspecialchars($today);
        $ep_time = htmlspecialchars($now);
        if (!empty($ep['time'])) {
            $tr = trim((string)$ep['time']);
            if (preg_match('/^(\d{1,2}):(\d{2})/', $tr, $tm)) {
                $ep_time = htmlspecialchars(sprintf('%02d:%02d', min(23, (int)$tm[1]), min(59, (int)$tm[2])));
            }
        }
        $ep_muc = (($ep['member_use_current_time'] ?? '1') === '0');
        $ep_bank = isset($ep['bank']) ? (string)$ep['bank'] : '';
        $ep_amount = isset($ep['amount']) ? htmlspecialchars((string)$ep['amount']) : '';
        $ep_product = isset($ep['product']) ? htmlspecialchars((string)$ep['product']) : '';
        $ep_remark = isset($ep['remark']) ? htmlspecialchars((string)$ep['remark']) : '';
        ?>
        <?php if ($expense_kind_ui === 'kiosk'): ?>
        <div class="kiosk-io-summary">
            <div class="kiosk-io-title" style="margin-bottom:10px;">Game Platform</div>
            <form method="get" class="expense-filter-bar kiosk-expense-filter-in-gp" id="expense-filter-form" action="<?= htmlspecialchars($expense_entry_url) ?>">
                <input type="hidden" name="expense_kind" value="<?= htmlspecialchars($expense_kind_ui) ?>">
                <div class="expense-filter-item">
                    <label>From</label>
                    <input type="date" name="expense_day_from" id="expense-day-from" class="form-control" value="<?= htmlspecialchars($expense_day_from) ?>">
                </div>
                <div class="expense-filter-item">
                    <label>To</label>
                    <input type="date" name="expense_day_to" id="expense-day-to" class="form-control" value="<?= htmlspecialchars($expense_day_to) ?>">
                </div>
                <div class="expense-filter-item">
                    <label>Bank</label>
                    <select name="expense_bank" class="form-control">
                        <option value="">全部</option>
                        <?php foreach ($expense_filter_banks as $bank_name): ?>
                        <option value="<?= htmlspecialchars((string)$bank_name) ?>" <?= $expense_bank_filter === (string)$bank_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$bank_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="expense-filter-item">
                    <label>Product</label>
                    <select name="expense_product" class="form-control">
                        <option value="">全部</option>
                        <?php foreach ($expense_filter_products as $product_name): ?>
                        <option value="<?= htmlspecialchars((string)$product_name) ?>" <?= $expense_product_filter === (string)$product_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$product_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="expense-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    <a href="<?= htmlspecialchars($expense_entry_url) ?>" class="btn btn-back btn-sm">Reset</a>
                </div>
                <div class="expense-quick-ranges" aria-label="日期快捷">
                    <span>快捷：</span>
                    <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="yesterday" title="昨天">昨日</button>
                    <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="this_week" title="本周一至本周日">本周</button>
                    <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="last_week" title="上周一至上周日">上周</button>
                    <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="this_month" title="本月1日至本月最后一天">本月</button>
                    <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="last_month" title="上月1日至上月最后一天">上月</button>
                </div>
            </form>
            <div class="kiosk-gp-filters" role="group" aria-label="Game Platform filters">
                <label><input type="checkbox" id="kiosk-gp-show-inout" checked> Show In / Out</label>
                <label class="kiosk-gp-filter-placeholder"><input type="checkbox" id="kiosk-gp-reserved" disabled aria-disabled="true"> （待定）</label>
            </div>
            <?php if ($is_admin): ?>
            <form method="post" action="kiosk_expense.php" class="kiosk-gp-meta-form" autocomplete="off">
                <input type="hidden" name="save_kiosk_gp_meta" value="1">
                <input type="hidden" name="expense_kind" value="kiosk">
                <input type="hidden" name="redirect_expense_day_from" value="<?= htmlspecialchars($expense_day_from) ?>">
                <input type="hidden" name="redirect_expense_day_to" value="<?= htmlspecialchars($expense_day_to) ?>">
                <input type="hidden" name="redirect_expense_bank" value="<?= htmlspecialchars($expense_bank_filter) ?>">
                <input type="hidden" name="redirect_expense_product" value="<?= htmlspecialchars($expense_product_filter) ?>">
            <?php else: ?>
            <div class="kiosk-gp-meta-form">
            <?php endif; ?>
            <div style="overflow-x:auto;">
                <table class="data-table kiosk-gp-table" id="kiosk-gp-table">
                    <thead>
                        <tr>
                            <th>Game Platform</th>
                            <th class="num">In</th>
                            <th class="num">Out</th>
                            <th class="num">净额（In − Out）</th>
                            <th class="num">%</th>
                            <th class="num">amount paid</th>
                        </tr>
                    </thead>
                    <tbody id="kiosk-gp-tbody">
                        <?php foreach ($kiosk_gp_products as $gpname):
                            $gpname = trim((string)$gpname);
                            if ($gpname === '') {
                                continue;
                            }
                            $gk = strtolower($gpname);
                            $kin = (float)($kiosk_gp_in[$gk] ?? 0);
                            $kout = (float)($kiosk_gp_out[$gk] ?? 0);
                            $knet = $kin - $kout;
                            $meta = $kiosk_product_meta[$gk] ?? null;
                            $mvPctRaw = $meta && $meta['pct'] !== null && $meta['pct'] !== '' ? (string)$meta['pct'] : '';
                            $mvPctDisplay = $mvPctRaw !== '' ? kiosk_format_pct_for_input($mvPctRaw) : '';
                            $pctNum = null;
                            if ($mvPctRaw !== '') {
                                $pctClean = str_replace(',', '', trim($mvPctRaw));
                                if ($pctClean !== '' && is_numeric($pctClean)) {
                                    $pctNum = (float)$pctClean;
                                }
                            }
                            $compPaid = $pctNum !== null ? kiosk_ceil_money2($knet * $pctNum / 100) : null;
                            $mvPaidDisplay = $compPaid !== null ? number_format($compPaid, 2, '.', '') : '';
                            ?>
                        <tr class="kiosk-gp-row" data-in="<?= htmlspecialchars((string)$kin) ?>" data-out="<?= htmlspecialchars((string)$kout) ?>" data-net="<?= htmlspecialchars((string)$knet) ?>">
                            <td><?= htmlspecialchars($gpname) ?></td>
                            <td class="num kiosk-gp-in"><?= number_format($kin, 2) ?></td>
                            <td class="num kiosk-gp-out"><?= number_format($kout, 2) ?></td>
                            <td class="num kiosk-io-net"><?= number_format($knet, 2) ?></td>
                            <td class="num">
                                <?php if ($is_admin): ?>
                                <input type="text" name="kiosk_pct[<?= htmlspecialchars($gpname, ENT_QUOTES) ?>]" class="form-control kiosk-gp-input kiosk-gp-pct" inputmode="decimal" value="<?= htmlspecialchars($mvPctDisplay) ?>" placeholder="%" aria-label="%">
                                <?php else: ?>
                                <span class="kiosk-gp-readonly"><?= $mvPctDisplay !== '' ? htmlspecialchars($mvPctDisplay) : '—' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ($is_admin): ?>
                                <input type="text" name="kiosk_paid[<?= htmlspecialchars($gpname, ENT_QUOTES) ?>]" class="form-control kiosk-gp-input kiosk-gp-paid" inputmode="decimal" value="<?= htmlspecialchars($mvPaidDisplay) ?>" placeholder="0.00" aria-label="amount paid" readonly tabindex="-1" title="由净额 × % 自动计算（分位向上取整）">
                                <?php else: ?>
                                <span class="kiosk-gp-readonly"><?= $mvPaidDisplay !== '' ? htmlspecialchars($mvPaidDisplay) : '—' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($kiosk_gp_products)): ?>
                        <tr class="kiosk-gp-no-products"><td colspan="6">暂无产品，请在后台维护 Products。</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
                <?php if ($is_admin && !empty($kiosk_gp_products)): ?>
                <div class="kiosk-gp-meta-actions">
                    <button type="submit" class="btn btn-primary btn-sm">保存 % / amount paid</button>
                </div>
                <?php endif; ?>
            <?php if ($is_admin): ?>
            </form>
            <?php else: ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="expense-userlist-toolbar">
            <button type="button" class="btn-expense-add" id="expense-modal-open">+ 新增开销</button>
        </div>
        <?php endif; ?>
        <?php if ($expense_kind_ui !== 'kiosk'): ?>
        <div class="expense-entry-modal-mask<?= $expense_modal_should_open ? ' show' : '' ?>" id="expense-entry-modal" aria-modal="true" role="dialog" aria-labelledby="expense-modal-title" aria-hidden="<?= $expense_modal_should_open ? 'false' : 'true' ?>">
            <div class="expense-entry-modal">
                <div class="expense-entry-modal-head">
                    <span id="expense-modal-title">新增开销</span>
                    <button type="button" class="expense-entry-modal-x" id="expense-modal-close" aria-label="关闭">×</button>
                </div>
                <form method="post" class="expense-modal-form txn-form" id="expense-modal-form" action="">
                    <div class="expense-modal-2col">
                        <div class="expense-modal-col expense-modal-col-left">
                            <h3 class="expense-modal-section-title">开销信息</h3>
                            <div class="form-section member-dt-section" style="margin:0; padding:12px; border-radius:12px; background:#fff; border:1px solid #e2e8f0;">
                                <div class="form-section-title" style="display:flex; align-items:center; gap:6px;">
                                    日期 / 时间
                                    <input type="hidden" name="member_use_current_time" id="member_use_current_time" value="<?= $ep_muc ? '0' : '1' ?>">
                                    <button type="button" id="member_dt_toggle" class="btn btn-outline btn-sm" style="padding:2px 8px; font-size:13px; line-height:1.2;" aria-label="展开修改日期时间"><?= $ep_muc ? '−' : '+' ?></button>
                                </div>
                                <div id="member_dt_box" style="display:<?= $ep_muc ? 'block' : 'none' ?>;">
                                    <div class="form-row-2">
                                        <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= $ep_day ?>"></div>
                                        <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= $ep_time ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="mode" id="mode" value="EXPENSE">
                            <input type="hidden" name="expense_kind" value="<?= htmlspecialchars($expense_kind_ui) ?>">
                            <div class="txn-expense-block" style="margin:0;">
                                <div class="form-row-2">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>Bank <span id="bank_req_mark">*</span></label>
                                        <?php if (!$is_admin && empty($banks)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                                        <select name="bank" id="bank" class="form-control" required title="关联 Statement 与银行 In/Out 统计">
                                            <option value="">-- 请选 --</option>
                                            <?php foreach ($banks as $b): $bs = (string)$b; ?>
                                            <option value="<?= htmlspecialchars($bs) ?>" <?= ($ep_bank !== '' && $ep_bank === $bs) ? 'selected' : '' ?>><?= htmlspecialchars($bs) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>金额 *</label>
                                        <input type="text" name="amount" id="amount" class="form-control" placeholder="如 630.00" required inputmode="decimal" value="<?= $ep_amount ?>">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>备注</label>
                                    <textarea name="remark" id="expenseRemark" class="form-control" rows="3" placeholder="选填，可写明细说明"><?= $ep_remark ?></textarea>
                                </div>
                                <input type="hidden" name="reward_pct" id="reward_pct" value="0">
                                <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                            </div>
                            <div class="expense-modal-col-actions">
                                <button type="submit" class="btn-expense-save">保存</button>
                                <button type="button" class="btn-expense-cancel" id="expense-modal-cancel">取消</button>
                            </div>
                        </div>
                        <div class="expense-modal-col expense-modal-col-right">
                            <h3 class="expense-modal-section-title">Expense 项目</h3>
                            <p class="expense-modal-hint">填写或点击下方快捷类别填入「Expense 项目」；将用于分类汇总。</p>
                            <div class="form-group" style="margin-bottom:0;">
                                <label id="product_label">Expense 项目 *</label>
                                <input type="text" name="product" id="product" class="form-control" required placeholder="例如：Office / Salary" value="<?= $ep_product ?>">
                            </div>
                            <div class="expense-chip-grid" id="expense-chip-grid" aria-label="快捷类别">
                                <?php foreach ($expenses as $ex): $exs = (string)$ex; ?>
                                <button type="button" class="expense-chip" data-expense-chip="<?= htmlspecialchars($exs) ?>"><?= htmlspecialchars($exs) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div class="expense-modal-col-actions-right">
                                <button type="button" class="btn-expense-select-all" id="expense-select-all" title="选中「Expense 项目」输入框内全部文字，便于整段替换">全选文字</button>
                                <button type="button" class="btn-expense-clear-all" id="expense-clear-all" title="清空 Expense 项目与备注">清空</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
    <div class="card txn-form-card">
    <form method="post" class="txn-form">
        <?php if ($is_admin): ?>
        <div class="form-section">
            <div class="form-section-title">日期 / 时间</div>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="checkbox" name="edit_dt" value="1" id="edit_dt" style="width:18px; height:18px;">
                <span>需要修改日期/时间</span>
            </label>
            <div id="dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="form-section member-dt-section">
            <div class="form-section-title" style="display:flex; align-items:center; gap:6px;">
                time
                <input type="hidden" name="member_use_current_time" id="member_use_current_time" value="1">
                <button type="button" id="member_dt_toggle" class="btn btn-outline btn-sm" style="padding:2px 8px; font-size:13px; line-height:1.2;" aria-label="展开修改日期时间">+</button>
            </div>
            <div id="member_dt_box" style="display:none;">
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;"><label>日期</label><input type="date" name="day" id="day" class="form-control" value="<?= htmlspecialchars($today) ?>"></div>
                    <div class="form-group" style="margin-bottom:0;"><label>时间（24小时）</label><input type="text" name="time" id="time" class="form-control" value="<?= htmlspecialchars($now) ?>" placeholder="如 1513 或 14:30" maxlength="5" title="可输数字如 1513 自动变为 15:13"></div>
                </div>
                <p class="form-hint" style="margin-top:4px;">可输入数字如 1513 自动变为 15:13。修改后提交需管理员审核。</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="form-section-title">基本信息</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>模式 *</label>
                    <select name="mode" id="mode" class="form-control" required>
                        <option value="">-- 请选 --</option>
                        <option value="DEPOSIT" <?= $selected_mode === 'DEPOSIT' ? 'selected' : '' ?>>DEPOSIT</option>
                        <option value="WITHDRAW" <?= $selected_mode === 'WITHDRAW' ? 'selected' : '' ?>>WITHDRAW</option>
                        <option value="FREE" <?= $selected_mode === 'FREE' ? 'selected' : '' ?>>FREE</option>
                        <option value="FREE WITHDRAW" <?= $selected_mode === 'FREE WITHDRAW' ? 'selected' : '' ?>>FREE WITHDRAW</option>
                        <option value="EXPENSE" <?= $selected_mode === 'EXPENSE' ? 'selected' : '' ?>>EXPENSE</option>
                        <option value="BANK" <?= $selected_mode === 'BANK' ? 'selected' : '' ?>>BANK</option>
                        <option value="REBATE" <?= $selected_mode === 'REBATE' ? 'selected' : '' ?>>REBATE</option>
                        <option value="OTHER" <?= $selected_mode === 'OTHER' ? 'selected' : '' ?>>OTHER</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>customer</label>
                    <?php if (empty($customers)): ?>
                    <select name="code" class="form-control" disabled><option value="">-- 暂无 --</option></select>
                    <p class="form-hint"><a href="customers.php">先去添加客户</a></p>
                    <?php else: ?>
                    <select name="code" id="code" class="form-control">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            <div id="withdraw_customer_box" class="form-group" style="display:none; padding:10px 12px; background:#fef3c7; border-radius:8px; font-size:14px; border:1px solid #fcd34d;">
                <div style="margin-bottom:4px;"><strong>顾客姓名</strong>：<span id="withdraw_customer_name">—</span></div>
                <div><strong>银行资料</strong>：<span id="withdraw_customer_bank">—</span></div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>bank <span id="bank_req_mark">*</span></label>
                    <?php if (!$is_admin && empty($banks)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="bank" id="bank" class="form-control" title="REBATE/FREE 不必选；其他模式必选则银行与产品页会统计">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($banks as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label id="product_label">产品/平台 *</label>
                    <?php if (!$is_admin && empty($products)): ?><p class="form-hint">请联系管理员添加</p><?php endif; ?>
                    <select name="product" id="product" class="form-control" required title="必选，否则银行与产品页的 In/Out 不会统计">
                        <option value="">-- 请选 --</option>
                        <?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">金额</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>金额 *</label>
                    <input type="text" name="amount" id="amount" class="form-control" placeholder="如 630.00" required>
                </div>
                <div class="form-group">
                    <label>奖励/返点 %</label>
                    <input type="text" name="reward_pct" id="reward_pct" class="form-control" placeholder="" inputmode="decimal" title="按金额百分比计算 bonus，顾客列表 Bonus 列即此项合计">
                    <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                </div>
            </div>
            <p class="form-hint" id="reward_hint" style="margin-top:4px; display:none;">奖励 <span id="reward_amount">0</span>，总数 <strong id="reward_total">0</strong></p>
        </div>

        <div class="form-section">
            <div class="form-group">
                <label>备注</label>
                <textarea name="remark" class="form-control" rows="2" placeholder="选填"></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary txn-submit">保存</button>
    </form>
    </div>
    <?php endif; ?>

    <?php if ($quick === 'expense' && $expense_kind_ui !== 'kiosk'): ?>
    <div class="card expense-statement-wrap">
        <h4 class="expense-statement-title">Expense Statement · 明细与汇总</h4>
        <form method="get" class="expense-filter-bar" id="expense-filter-form" action="<?= htmlspecialchars($expense_entry_url) ?>">
            <input type="hidden" name="expense_kind" value="<?= htmlspecialchars($expense_kind_ui) ?>">
            <div class="expense-filter-item">
                <label>From</label>
                <input type="date" name="expense_day_from" id="expense-day-from" class="form-control" value="<?= htmlspecialchars($expense_day_from) ?>">
            </div>
            <div class="expense-filter-item">
                <label>To</label>
                <input type="date" name="expense_day_to" id="expense-day-to" class="form-control" value="<?= htmlspecialchars($expense_day_to) ?>">
            </div>
            <div class="expense-filter-item">
                <label>Bank</label>
                <select name="expense_bank" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($expense_filter_banks as $bank_name): ?>
                    <option value="<?= htmlspecialchars((string)$bank_name) ?>" <?= $expense_bank_filter === (string)$bank_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$bank_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="expense-filter-item">
                <label>Product</label>
                <select name="expense_product" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($expense_filter_products as $product_name): ?>
                    <option value="<?= htmlspecialchars((string)$product_name) ?>" <?= $expense_product_filter === (string)$product_name ? 'selected' : '' ?>><?= htmlspecialchars((string)$product_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="expense-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="<?= htmlspecialchars($expense_entry_url) ?>" class="btn btn-back btn-sm">Reset</a>
            </div>
            <div class="expense-quick-ranges" aria-label="日期快捷">
                <span>快捷：</span>
                <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="yesterday" title="昨天">昨日</button>
                <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="this_week" title="本周一至本周日">本周</button>
                <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="last_week" title="上周一至上周日">上周</button>
                <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="this_month" title="本月1日至本月最后一天">本月</button>
                <button type="button" class="btn btn-sm btn-outline expense-quick-range" data-range="last_month" title="上月1日至上月最后一天">上月</button>
            </div>
        </form>

        <div class="expense-kpi-grid">
            <div class="expense-kpi-card">
                <strong>总开销</strong>
                <span class="num out"><?= number_format($expense_report_total, 2) ?></span>
            </div>
            <div class="expense-kpi-card">
                <strong>记录数</strong>
                <span class="num"><?= (int)$expense_report_count ?></span>
            </div>
            <div class="expense-kpi-card">
                <strong>产品数</strong>
                <span class="num"><?= count($expense_report_by_product) ?></span>
            </div>
        </div>

        <div style="overflow-x:auto; margin-bottom: 10px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Count</th>
                        <th class="num">Total Expense</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_report_by_product as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['product_name'] ?? '')) ?></td>
                        <td class="num"><?= (int)($row['cnt'] ?? 0) ?></td>
                        <td class="num out"><?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$expense_report_by_product): ?><tr><td colspan="3">暂无 Product 汇总</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Kind</th>
                        <th>Bank</th>
                        <th>Product</th>
                        <th class="num">Amount</th>
                        <th>Staff</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_report_rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['day'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['time'] ?? '')) ?></td>
                        <td><?= (isset($row['expense_kind']) && $row['expense_kind'] === 'kiosk') ? 'Kiosk' : 'Statement' ?></td>
                        <td><?= htmlspecialchars((string)($row['bank'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['product'] ?? '')) ?></td>
                        <td class="num out"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars((string)($row['staff'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['remark'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$expense_report_rows): ?><tr><td colspan="8">暂无 Expense 记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <p class="breadcrumb" style="margin-top:16px;">
        <a href="dashboard.php">返回首页</a><span>·</span>
        <a href="transaction_list.php">流水记录</a><span>·</span>
        <a href="logout.php">退出</a>
    </p>
    </div>
        </main>
    </div>
    <?php if ($saved): ?>
    <div class="pretty-modal-mask" id="saved-modal-mask">
        <div class="pretty-modal" role="dialog" aria-modal="true" aria-label="提交结果">
            <div class="pretty-modal-head">系统提示</div>
            <div class="pretty-modal-body">
                <?php if ($submitted_status === 'pending'): ?>
                    已提交成功，等待管理员批准。
                <?php else: ?>
                    成功，数据已保存并生效。
                <?php endif; ?>
            </div>
            <div class="pretty-modal-foot">
                <button type="button" class="pretty-ok" id="saved-modal-ok">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <script>
        (function() {
            var customerData = <?= json_encode(array_column($customers, null, 'code')) ?>;
            var isExpenseQuick = <?= $quick === 'expense' ? 'true' : 'false' ?>;
            var productOptionsDefault = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE) ?>;
            var productOptionsExpense = <?= json_encode(array_values($expenses), JSON_UNESCAPED_UNICODE) ?>;
            function updateWithdrawCustomer() {
                var modeEl = document.getElementById('mode');
                var codeEl = document.getElementById('code');
                var box = document.getElementById('withdraw_customer_box');
                var nameEl = document.getElementById('withdraw_customer_name');
                var bankEl = document.getElementById('withdraw_customer_bank');
                if (!modeEl || !codeEl || !box) return;
                var code = (codeEl.value || '').trim();
                if (modeEl.value === 'WITHDRAW' && code) {
                    var c = customerData[code];
                    box.style.display = 'block';
                    nameEl.textContent = (c && c.name) ? c.name : '—';
                    bankEl.textContent = (c && c.bank_details) ? c.bank_details : '—';
                } else {
                    box.style.display = 'none';
                }
            }
            var modeEl = document.getElementById('mode');
            var codeEl = document.getElementById('code');
            if (modeEl) modeEl.addEventListener('change', updateWithdrawCustomer);
            if (codeEl) codeEl.addEventListener('change', updateWithdrawCustomer);
            updateWithdrawCustomer();

            function applyBankRequired() {
                var mode = (modeEl && modeEl.value) ? modeEl.value : '';
                var bankSelect = document.getElementById('bank');
                var bankMark = document.getElementById('bank_req_mark');
                var productSelect = document.getElementById('product');
                var productLabel = document.getElementById('product_label');
                var noBankModes = ['REBATE', 'FREE', 'FREE WITHDRAW'];
                if (bankSelect && bankMark) {
                    if (noBankModes.indexOf(mode) >= 0) {
                        bankSelect.removeAttribute('required');
                        bankMark.style.display = 'none';
                    } else {
                        bankSelect.setAttribute('required', 'required');
                        bankMark.style.display = 'inline';
                    }
                }
                if (productSelect && productLabel) {
                    if ((productSelect.tagName || '').toUpperCase() !== 'SELECT') return;
                    var current = productSelect.value;
                    var list = productOptionsDefault;
                    var isQuickExpenseMode = mode === 'EXPENSE' && isExpenseQuick;
                    var fallback = isQuickExpenseMode ? 'Expense 项目（可选）' : '产品/平台';
                    productLabel.textContent = isQuickExpenseMode ? fallback : (fallback + ' *');
                    productSelect.innerHTML = '<option value="">-- 请选 --</option>';
                    (list || []).forEach(function(name){
                        var op = document.createElement('option');
                        op.value = name;
                        op.textContent = name;
                        productSelect.appendChild(op);
                    });
                    if (current && list.indexOf(current) >= 0) productSelect.value = current;
                }
            }
            if (modeEl) modeEl.addEventListener('change', applyBankRequired);
            applyBankRequired();
        })();
        (function(){
            var btn = document.getElementById('member_dt_toggle');
            var box = document.getElementById('member_dt_box');
            var hidden = document.getElementById('member_use_current_time');
            if (!btn || !box || !hidden) return;
            btn.addEventListener('click', function(){
                var show = box.style.display === 'none';
                box.style.display = show ? 'block' : 'none';
                hidden.value = show ? '0' : '1';
                btn.textContent = show ? '−' : '+';
            });
        })();
        (function(){
            var timeEl = document.getElementById('time');
            if (!timeEl) return;
            function formatTimeInput() {
                var v = (timeEl.value || '').replace(/\D/g, '');
                if (v.length >= 2) {
                    var h = v.substr(0, 2);
                    if (parseInt(h, 10) > 23) h = '23';
                    if (v.length === 2) timeEl.value = h + ':';
                    else if (v.length === 3) timeEl.value = h + ':' + v.substr(2, 1);
                    else timeEl.value = h + ':' + v.substr(2, 2);
                } else {
                    timeEl.value = v;
                }
            }
            timeEl.addEventListener('input', formatTimeInput);
            timeEl.addEventListener('blur', function(){
                var v = (timeEl.value || '').replace(/\D/g, '');
                if (v.length === 3) v = v.substr(0, 2) + '0' + v.substr(2, 1);
                if (v.length >= 2) {
                    var h = Math.min(23, parseInt(v.substr(0, 2), 10) || 0);
                    var m = Math.min(59, parseInt(v.length >= 4 ? v.substr(2, 2) : (v.substr(2, 2) || '0'), 10) || 0);
                    timeEl.value = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
                }
            });
        })();
        (function(){
            var amountEl = document.getElementById('amount');
            var pctEl = document.getElementById('reward_pct');
            var bonusEl = document.getElementById('bonus_hidden');
            var hintEl = document.getElementById('reward_hint');
            var rewardAmountSpan = document.getElementById('reward_amount');
            var rewardTotalSpan = document.getElementById('reward_total');
            function updateReward() {
                var amt = parseFloat((amountEl && amountEl.value || '').replace(/,/g, '')) || 0;
                var pct = parseFloat((pctEl && pctEl.value || '').replace(/,/g, '')) || 0;
                var bonus = pct ? Math.round(amt * pct / 100 * 100) / 100 : 0;
                var total = amt + bonus;
                if (bonusEl) bonusEl.value = bonus;
                if (hintEl && rewardAmountSpan && rewardTotalSpan) {
                    if (pct > 0 && amt > 0) {
                        hintEl.style.display = 'block';
                        rewardAmountSpan.textContent = bonus.toFixed(2);
                        rewardTotalSpan.textContent = total.toFixed(2);
                    } else {
                        hintEl.style.display = 'none';
                    }
                }
            }
            if (amountEl) amountEl.addEventListener('input', updateReward);
            if (amountEl) amountEl.addEventListener('blur', updateReward);
            if (pctEl) pctEl.addEventListener('input', updateReward);
            if (pctEl) pctEl.addEventListener('blur', updateReward);
            var form = amountEl && amountEl.closest('form');
            if (form) form.addEventListener('submit', function(){ updateReward(); });
        })();
        <?php if ($is_admin && $quick !== 'expense'): ?>
        var cb = document.getElementById('edit_dt');
        if (cb) {
            cb.onchange = function() {
                var box = document.getElementById('dt_box');
                if (box) box.style.display = cb.checked ? 'block' : 'none';
            };
        }
        <?php endif; ?>
        (function(){
            var mask = document.getElementById('saved-modal-mask');
            var ok = document.getElementById('saved-modal-ok');
            if (!mask || !ok) return;
            setTimeout(function(){ mask.classList.add('show'); }, 30);
            function closeModal(){ mask.classList.remove('show'); }
            ok.addEventListener('click', closeModal);
            mask.addEventListener('click', function(e){ if (e.target === mask) closeModal(); });
        })();
        <?php if ($quick === 'expense' && $expense_kind_ui !== 'kiosk'): ?>
        (function(){
            var mask = document.getElementById('expense-entry-modal');
            var openBtn = document.getElementById('expense-modal-open');
            var closeBtn = document.getElementById('expense-modal-close');
            var cancelBtn = document.getElementById('expense-modal-cancel');
            var chipGrid = document.getElementById('expense-chip-grid');
            var selAllBtn = document.getElementById('expense-select-all');
            var clrAllBtn = document.getElementById('expense-clear-all');
            if (!mask || !openBtn) return;
            function openM() {
                mask.classList.add('show');
                mask.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                var amt = document.getElementById('amount');
                if (amt) setTimeout(function(){ amt.focus(); }, 50);
            }
            function closeM() {
                mask.classList.remove('show');
                mask.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
            openBtn.addEventListener('click', openM);
            if (closeBtn) closeBtn.addEventListener('click', closeM);
            if (cancelBtn) cancelBtn.addEventListener('click', closeM);
            mask.addEventListener('click', function(e) { if (e.target === mask) closeM(); });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && mask.classList.contains('show')) closeM();
            });
            if (chipGrid) {
                chipGrid.addEventListener('click', function(e) {
                    var t = e.target && e.target.closest('[data-expense-chip]');
                    if (!t) return;
                    var v = t.getAttribute('data-expense-chip') || '';
                    var inp = document.getElementById('product');
                    if (inp) { inp.value = v; inp.focus(); }
                });
            }
            if (selAllBtn) {
                selAllBtn.addEventListener('click', function() {
                    var inp = document.getElementById('product');
                    if (!inp) return;
                    inp.focus();
                    if (typeof inp.select === 'function') inp.select();
                });
            }
            if (clrAllBtn) {
                clrAllBtn.addEventListener('click', function() {
                    var p = document.getElementById('product');
                    var r = document.getElementById('expenseRemark');
                    if (p) p.value = '';
                    if (r) r.value = '';
                });
            }
        })();
        <?php endif; ?>
        <?php if ($quick === 'expense'): ?>
        (function(){
            var form = document.getElementById('expense-filter-form');
            var fromEl = document.getElementById('expense-day-from');
            var toEl = document.getElementById('expense-day-to');
            if (!form || !fromEl || !toEl) return;
            function fmt(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            }
            function mondayOf(d) {
                var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                var day = x.getDay();
                var diff = day === 0 ? -6 : 1 - day;
                x.setDate(x.getDate() + diff);
                return x;
            }
            document.querySelectorAll('.expense-quick-range').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var range = btn.getAttribute('data-range');
                    var now = new Date();
                    var from, to;
                    if (range === 'yesterday') {
                        var y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
                        from = to = fmt(y);
                    } else if (range === 'this_week') {
                        var mon = mondayOf(now);
                        var sun = new Date(mon);
                        sun.setDate(sun.getDate() + 6);
                        from = fmt(mon);
                        to = fmt(sun);
                    } else if (range === 'last_week') {
                        var mon = mondayOf(now);
                        var lwStart = new Date(mon);
                        lwStart.setDate(lwStart.getDate() - 7);
                        var lwEnd = new Date(lwStart);
                        lwEnd.setDate(lwEnd.getDate() + 6);
                        from = fmt(lwStart);
                        to = fmt(lwEnd);
                    } else if (range === 'this_month') {
                        var first = new Date(now.getFullYear(), now.getMonth(), 1);
                        var last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                        from = fmt(first);
                        to = fmt(last);
                    } else if (range === 'last_month') {
                        var first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                        var last = new Date(now.getFullYear(), now.getMonth(), 0);
                        from = fmt(first);
                        to = fmt(last);
                    } else {
                        return;
                    }
                    fromEl.value = from;
                    toEl.value = to;
                    form.submit();
                });
            });
        })();
        <?php endif; ?>
        <?php if ($quick === 'expense' && $expense_kind_ui === 'kiosk'): ?>
        (function(){
            var table = document.getElementById('kiosk-gp-table');
            var inoutEl = document.getElementById('kiosk-gp-show-inout');
            if (!table || !inoutEl) return;
            function apply() {
                table.classList.toggle('is-inout-hidden', !inoutEl.checked);
            }
            inoutEl.addEventListener('change', apply);
            apply();
        })();
        <?php if ($is_admin): ?>
        (function(){
            function ceilMoney2(x) {
                return Math.ceil((Number(x) + 1e-9) * 100) / 100;
            }
            function formatPctBlur(s) {
                var v = parseFloat(String(s).replace(/,/g, '').trim());
                if (isNaN(v)) {
                    return String(s).trim();
                }
                var t = v.toFixed(4).replace(/\.?0+$/, '');
                return t || '0';
            }
            function updatePaidRow(tr) {
                var net = parseFloat(tr.getAttribute('data-net'), 10);
                if (isNaN(net)) {
                    net = 0;
                }
                var pctInp = tr.querySelector('.kiosk-gp-pct');
                var paidInp = tr.querySelector('.kiosk-gp-paid');
                if (!pctInp || !paidInp) {
                    return;
                }
                var rawPct = String(pctInp.value).replace(/,/g, '').trim();
                var p = parseFloat(rawPct, 10);
                if (rawPct === '' || isNaN(p)) {
                    paidInp.value = '0.00';
                    return;
                }
                paidInp.value = ceilMoney2(net * p / 100).toFixed(2);
            }
            var rows = document.querySelectorAll('#kiosk-gp-tbody tr.kiosk-gp-row');
            rows.forEach(function(tr) {
                var pctInp = tr.querySelector('.kiosk-gp-pct');
                if (!pctInp) {
                    return;
                }
                pctInp.addEventListener('input', function() { updatePaidRow(tr); });
                pctInp.addEventListener('change', function() { updatePaidRow(tr); });
                pctInp.addEventListener('blur', function() {
                    pctInp.value = formatPctBlur(pctInp.value);
                    updatePaidRow(tr);
                });
                updatePaidRow(tr);
            });
            var metaForm = document.querySelector('form.kiosk-gp-meta-form');
            if (metaForm) {
                metaForm.addEventListener('submit', function() {
                    rows.forEach(updatePaidRow);
                });
            }
        })();
        <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>
