<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_banks_products';
$company_id = effective_admin_company_id($pdo);
$actor_role = (string)($_SESSION['user_role'] ?? '');
$actor_is_boss_like = in_array($actor_role, ['boss', 'superadmin'], true);

function _ensure_balance_adjust_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_adjust (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL DEFAULT 1,
        adjust_type ENUM('bank','product','expense') NOT NULL,
        name VARCHAR(80) NOT NULL,
        initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        UNIQUE KEY uq_balance_adjust_company (company_id, adjust_type, name)
    )");
    try {
        // 兼容旧库：补 expense 枚举值
        $pdo->exec("ALTER TABLE balance_adjust MODIFY adjust_type ENUM('bank','product','expense') NOT NULL");
    } catch (Throwable $e) {}
}

function _ensure_expenses_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL DEFAULT 1,
        name VARCHAR(80) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_expenses_company_name (company_id, name)
    )");
}

$msg = '';
$err = '';
$open_notify_form = false;
$bank_status_filter = trim((string)($_GET['bank_status_filter'] ?? 'all'));
$product_status_filter = trim((string)($_GET['product_status_filter'] ?? 'all'));
$expense_status_filter = trim((string)($_GET['expense_status_filter'] ?? 'all'));
if (!in_array($bank_status_filter, ['all', 'active', 'inactive'], true)) $bank_status_filter = 'all';
if (!in_array($product_status_filter, ['all', 'active', 'inactive', 'pending_delete'], true)) $product_status_filter = 'all';
if (!in_array($expense_status_filter, ['all', 'active', 'inactive'], true)) $expense_status_filter = 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_bank') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException(__('abp_err_bank_name'));
            try {
                $stmt = $pdo->prepare("INSERT INTO banks (company_id, name, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$company_id, $name, $sort]);
                $msg = __('abp_msg_bank_added');
            } catch (Throwable $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    throw new RuntimeException(__f('abp_err_bank_dup', htmlspecialchars($name)));
                }
                throw $e;
            }
        } elseif ($action === 'create_product') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException(__('abp_err_product_name'));
            try {
                $stmt = $pdo->prepare("INSERT INTO products (company_id, name, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$company_id, $name, $sort]);
                $msg = __('abp_msg_product_added');
            } catch (Throwable $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    throw new RuntimeException(__f('abp_err_product_dup', htmlspecialchars($name)));
                }
                throw $e;
            }
        } elseif ($action === 'create_expense') {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException(__('abp_err_expense_name'));
            try {
                _ensure_expenses_table($pdo);
                $stmt = $pdo->prepare("INSERT INTO expenses (company_id, name, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$company_id, $name, $sort]);
                $msg = __('abp_msg_expense_added');
            } catch (Throwable $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    throw new RuntimeException(__f('abp_err_expense_dup', htmlspecialchars($name)));
                }
                throw $e;
            }
        } elseif ($action === 'toggle_bank') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('abp_err_bad_param'));
            $stmt = $pdo->prepare("UPDATE banks SET is_active = IF(is_active=1,0,1) WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $msg = __('abp_msg_status_updated');
        } elseif ($action === 'toggle_product') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('abp_err_bad_param'));
            $chk = $pdo->prepare('SELECT is_active, delete_pending_at FROM products WHERE id = ? AND company_id = ? LIMIT 1');
            $chk->execute([$id, $company_id]);
            $pr = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$pr) {
                throw new RuntimeException(__('abp_err_product_not_found'));
            }
            if ((int)($pr['is_active'] ?? 0) === 0 && !empty($pr['delete_pending_at'])) {
                throw new RuntimeException(__('abp_err_enable_before_delete_req'));
            }
            $stmt = $pdo->prepare("UPDATE products SET is_active = IF(is_active=1,0,1) WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $msg = __('abp_msg_status_updated');
        } elseif ($action === 'request_product_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(__('abp_err_bad_param'));
            }
            $chk = $pdo->prepare('SELECT is_active, delete_pending_at, name FROM products WHERE id = ? AND company_id = ? LIMIT 1');
            $chk->execute([$id, $company_id]);
            $pr = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$pr) {
                throw new RuntimeException(__('abp_err_product_not_found'));
            }
            if ((int)($pr['is_active'] ?? 0) !== 0) {
                throw new RuntimeException(__('abp_err_disable_before_delete'));
            }
            if (!empty($pr['delete_pending_at'])) {
                throw new RuntimeException(__('abp_err_delete_already_pending'));
            }
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $pdo->prepare('UPDATE products SET delete_pending_at = NOW(), delete_pending_by = ? WHERE id = ? AND company_id = ?')->execute([$uid > 0 ? $uid : null, $id, $company_id]);
            $msg = __('abp_msg_delete_requested');
        } elseif ($action === 'cancel_product_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(__('abp_err_bad_param'));
            }
            $st = $pdo->prepare('UPDATE products SET delete_pending_at = NULL, delete_pending_by = NULL WHERE id = ? AND company_id = ? AND delete_pending_at IS NOT NULL');
            $st->execute([$id, $company_id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException(__('abp_err_no_pending_delete'));
            }
            $msg = __('abp_msg_delete_cancelled');
        } elseif ($action === 'approve_product_delete') {
            if (!$actor_is_boss_like) {
                throw new RuntimeException(__('abp_err_boss_only_delete'));
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(__('abp_err_bad_param'));
            }
            $chk = $pdo->prepare('SELECT name FROM products WHERE id = ? AND company_id = ? AND delete_pending_at IS NOT NULL LIMIT 1');
            $chk->execute([$id, $company_id]);
            $pr = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$pr) {
                throw new RuntimeException(__('abp_err_no_pending_approve'));
            }
            $pname = trim((string)($pr['name'] ?? ''));
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM balance_adjust WHERE company_id = ? AND adjust_type = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))')->execute([$company_id, 'product', $pname]);
                $pdo->prepare('DELETE FROM products WHERE id = ? AND company_id = ?')->execute([$id, $company_id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $msg = __f('abp_msg_delete_approved', $pname);
        } elseif ($action === 'toggle_expense') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException(__('abp_err_bad_param'));
            _ensure_expenses_table($pdo);
            $stmt = $pdo->prepare("UPDATE expenses SET is_active = IF(is_active=1,0,1) WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $msg = __('abp_msg_status_updated');
        } elseif ($action === 'do_transfer') {
            if (empty($_POST['confirm_submit'])) throw new RuntimeException(__('abp_err_confirm_transfer'));
            $from_bank = trim($_POST['from_bank'] ?? '');
            $to_bank   = trim($_POST['to_bank'] ?? '');
            $amount    = str_replace(',', '', trim($_POST['amount'] ?? '0'));
            $remark    = trim($_POST['transfer_remark'] ?? '');
            $day_raw   = trim($_POST['transfer_day'] ?? '');
            if ($from_bank === '' || $to_bank === '' || $from_bank === $to_bank) throw new RuntimeException(__('abp_err_pick_banks'));
            if (!is_numeric($amount) || (float)$amount <= 0) throw new RuntimeException(__('abp_err_amount'));
            $amount = (float)$amount;
            $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day_raw) ? $day_raw : date('Y-m-d');
            $time = date('H:i:s');
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $staff = (string)($_SESSION['user_name'] ?? $uid);
            $remark_out = $remark !== '' ? __f('abp_remark_to_bank_remark', $to_bank, $remark) : __f('abp_remark_to_bank', $to_bank);
            $remark_in  = $remark !== '' ? __f('abp_remark_from_bank_remark', $from_bank, $remark) : __f('abp_remark_from_bank', $from_bank);
            try {
                $cols = "company_id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals = "?, ?, ?, 'WITHDRAW', NULL, ?, NULL, ?, 0, ?, ?, ?, 'approved', ?, ?, NOW(), 1";
                $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($vals)");
                $stmt->execute([$company_id, $day, $time, $from_bank, $amount, $amount, $staff, $remark_out, $uid, $uid]);
                $cols2 = "company_id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals2 = "?, ?, ?, 'DEPOSIT', NULL, ?, NULL, ?, 0, ?, ?, ?, 'approved', ?, ?, NOW(), 1";
                $stmt2 = $pdo->prepare("INSERT INTO transactions ($cols2) VALUES ($vals2)");
                $stmt2->execute([$company_id, $day, $time, $to_bank, $amount, $amount, $staff, $remark_in, $uid, $uid]);
                $msg = __f('abp_msg_transfer_recorded', $from_bank, number_format($amount, 2), $to_bank);
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'hide_from_member') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    throw new RuntimeException(__('abp_err_migrate_hide'));
                }
                throw $e;
            }
        } elseif ($action === 'do_product_topup') {
            $product = trim($_POST['product'] ?? '');
            $amount  = str_replace(',', '', trim($_POST['amount'] ?? '0'));
            if ($product === '') throw new RuntimeException(__('abp_err_pick_product'));
            if (!is_numeric($amount) || (float)$amount <= 0) throw new RuntimeException(__('abp_err_topup_amount'));
            $amount = (float)$amount;
            $day = date('Y-m-d');
            $time = date('H:i:s');
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $staff = (string)($_SESSION['user_name'] ?? $uid);
            try {
                $cols = "company_id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at, hide_from_member";
                $vals = "?, ?, ?, 'TOPUP', NULL, NULL, ?, ?, 0, ?, ?, ?, 'approved', ?, ?, NOW(), 1";
                $stmt = $pdo->prepare("INSERT INTO transactions ($cols) VALUES ($vals)");
                $stmt->execute([$company_id, $day, $time, $product, $amount, $amount, $staff, __('abp_remark_topup'), $uid, $uid]);
                $msg = __f('abp_msg_topup_ok', $product, number_format($amount, 2));
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'hide_from_member') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    throw new RuntimeException(__('abp_err_migrate_hide'));
                }
                throw $e;
            }
        } elseif ($action === 'save_balance_notify') {
            $data_dir = __DIR__ . '/data';
            $config_path = $data_dir . '/balance_notify.json';
            $bank_arr = isset($_POST['bank']) && is_array($_POST['bank']) ? $_POST['bank'] : [];
            $product_arr = isset($_POST['product']) && is_array($_POST['product']) ? $_POST['product'] : [];
            $bank_cfg = [];
            foreach ($bank_arr as $k => $v) {
                $key = strtolower(trim((string)$k));
                if ($key === '') continue;
                $v = trim((string)$v);
                if ($v === '') continue;
                if (is_numeric($v) && (float)$v > 0) $bank_cfg[$key] = (float)$v;
            }
            $product_cfg = [];
            foreach ($product_arr as $k => $v) {
                $key = strtolower(trim((string)$k));
                if ($key === '') continue;
                $v = trim((string)$v);
                if ($v === '') continue;
                if (is_numeric($v) && (float)$v > 0) $product_cfg[$key] = (float)$v;
            }
            if (!is_dir($data_dir)) @mkdir($data_dir, 0755, true);
            $data = ['bank' => $bank_cfg, 'product' => $product_cfg];
            if (@file_put_contents($config_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) throw new RuntimeException(__('abp_err_write_notify'));
            $msg = __('abp_msg_notify_saved');
            $open_notify_form = true;
        } elseif ($action === 'save_balance') {
            $type = $_POST['adjust_type'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $val = str_replace(',', '', trim($_POST['balance'] ?? '0'));
            if ($name === '' || !in_array($type, ['bank', 'product', 'expense'], true) || !is_numeric($val)) throw new RuntimeException(__('abp_err_bad_param'));
            try {
                $stmt = $pdo->prepare("INSERT INTO balance_adjust (company_id, adjust_type, name, initial_balance, updated_at, updated_by) VALUES (?, ?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE initial_balance = VALUES(initial_balance), updated_at = NOW(), updated_by = VALUES(updated_by)");
                $stmt->execute([$company_id, $type, $name, (float)$val, (int)($_SESSION['user_id'] ?? 0)]);
                $msg = __('abp_msg_balance_saved');
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'balance_adjust') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
                    _ensure_balance_adjust_table($pdo);
                    try {
                        $stmt = $pdo->prepare("INSERT INTO balance_adjust (company_id, adjust_type, name, initial_balance, updated_at, updated_by) VALUES (?, ?, ?, ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE initial_balance = VALUES(initial_balance), updated_at = NOW(), updated_by = VALUES(updated_by)");
                        $stmt->execute([$company_id, $type, $name, (float)$val, (int)($_SESSION['user_id'] ?? 0)]);
                        $msg = __('abp_msg_balance_saved');
                    } catch (Throwable $e2) {
                        throw new RuntimeException(__('abp_err_balance_adjust_create'));
                    }
                } else {
                    throw $e;
                }
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    // 提交成功后重定向到本页 GET，避免刷新时重复提交（PRG）
    if ($msg !== '' && $err === '') {
        $_SESSION['admin_banks_flash'] = ['msg' => $msg, 'open_notify' => $open_notify_form];
        header('Location: admin_banks_products.php');
        exit;
    }
}

if (isset($_SESSION['admin_banks_flash'])) {
    $msg = $_SESSION['admin_banks_flash']['msg'] ?? '';
    $open_notify_form = !empty($_SESSION['admin_banks_flash']['open_notify']);
    unset($_SESSION['admin_banks_flash']);
}

$banks = [];
$products = [];
$expenses = [];
$balance_bank = [];
$balance_product = [];
$balance_expense = [];
$balance_adjust_ok = false;
try {
    $sql = "SELECT id, name, is_active, sort_order, created_at FROM banks WHERE company_id = ?";
    if ($bank_status_filter === 'active') $sql .= " AND is_active = 1";
    elseif ($bank_status_filter === 'inactive') $sql .= " AND is_active = 0";
    $sql .= " ORDER BY sort_order ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id]);
    $banks = $stmt->fetchAll();
} catch (Throwable $e) {}
try {
    $sql = "SELECT id, name, is_active, sort_order, created_at, delete_pending_at, delete_pending_by FROM products WHERE company_id = ?";
    if ($product_status_filter === 'active') {
        $sql .= ' AND is_active = 1 AND (delete_pending_at IS NULL)';
    } elseif ($product_status_filter === 'inactive') {
        $sql .= ' AND is_active = 0 AND (delete_pending_at IS NULL)';
    } elseif ($product_status_filter === 'pending_delete') {
        $sql .= ' AND delete_pending_at IS NOT NULL';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id]);
    $products = $stmt->fetchAll();
} catch (Throwable $e) {
    $products = [];
}
$products_full = [];
try {
    $stpf = $pdo->prepare('SELECT id, name, is_active, sort_order, created_at, delete_pending_at, delete_pending_by FROM products WHERE company_id = ? ORDER BY sort_order ASC, name ASC');
    $stpf->execute([$company_id]);
    $products_full = $stpf->fetchAll();
} catch (Throwable $e) {
}
$products_usable = array_values(array_filter($products_full, static function ($p) {
    return (int)($p['is_active'] ?? 0) === 1 && empty($p['delete_pending_at']);
}));
try {
    _ensure_expenses_table($pdo);
    $sql = "SELECT id, name, is_active, sort_order, created_at FROM expenses WHERE company_id = ?";
    if ($expense_status_filter === 'active') $sql .= " AND is_active = 1";
    elseif ($expense_status_filter === 'inactive') $sql .= " AND is_active = 0";
    $sql .= " ORDER BY sort_order ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id]);
    $expenses = $stmt->fetchAll();
} catch (Throwable $e) {}
try {
    _ensure_balance_adjust_table($pdo);
} catch (Throwable $e) {}
try {
    $stmtBa = $pdo->prepare("SELECT adjust_type, name, initial_balance FROM balance_adjust WHERE company_id = ?");
    $stmtBa->execute([$company_id]);
    $rows = $stmtBa->fetchAll();
    $balance_adjust_ok = true;
    foreach ($rows as $r) {
        $k = strtolower(trim((string)$r['name']));
        if ($k === '') continue;
        if ($r['adjust_type'] === 'bank') $balance_bank[$k] = (float)$r['initial_balance'];
        elseif ($r['adjust_type'] === 'product') $balance_product[$k] = (float)$r['initial_balance'];
        else $balance_expense[$k] = (float)$r['initial_balance'];
    }
} catch (Throwable $e) {
    $balance_bank = [];
    $balance_product = [];
    $balance_expense = [];
}

// Balance Now = Starting Balance + 入账(deposit) − 出账(withdraw)，按银行/产品对扣
$total_in_bank = [];
$total_out_bank = [];
$total_in_product = [];
$total_topup_product = [];
$total_out_product = [];
$total_in_expense = [];
$total_out_expense = [];
$diag_bank_rows = [];
$diag_product_rows = [];
$diag_error = '';
try {
    $stmt = $pdo->prepare("SELECT COALESCE(bank, '') AS bank,
        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','EXPENSE') THEN amount ELSE 0 END), 0) AS tout
        FROM transactions WHERE status = 'approved' AND company_id = ? GROUP BY COALESCE(bank, '')");
    $stmt->execute([$company_id]);
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
$__gpc_gp_key = require __DIR__ . '/inc/gpc_effective_product_key_sql.php';
try {
    $stmt = $pdo->prepare("SELECT {$__gpc_gp_key} AS gp,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('DEPOSIT','REBATE','FREE','FREE WITHDRAW') THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) = 'TOPUP' THEN (CASE WHEN t.total IS NOT NULL AND t.total != 0 THEN t.total ELSE t.amount + COALESCE(t.bonus,0) END) ELSE 0 END), 0) AS topup,
        COALESCE(SUM(CASE WHEN TRIM(COALESCE(t.mode,'')) IN ('WITHDRAW','EXPENSE') THEN t.amount ELSE 0 END), 0) AS tout
        FROM transactions t WHERE t.status = 'approved' AND t.company_id = ? GROUP BY gp");
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $prodVal = $r['gp'] ?? $r['GP'] ?? '';
        $k = strtolower(trim((string)$prodVal));
        if ($k === '') continue;
        $ti = (float)($r['ti'] ?? $r['TI'] ?? 0);
        $topup = (float)($r['topup'] ?? 0);
        $to = (float)($r['tout'] ?? $r['TO'] ?? 0);
        $total_in_product[$k] = $ti;
        $total_topup_product[$k] = $topup;
        $total_out_product[$k] = $to;
        $diag_product_rows[$k] = ['in' => $ti, 'topup' => $topup, 'out' => $to];
    }
} catch (Throwable $e) {
    if (empty($diag_error)) $diag_error = $e->getMessage();
}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(product, '') AS expense_name,
        COALESCE(SUM(CASE WHEN mode = 'EXPENSE' THEN amount ELSE 0 END), 0) AS tout
        FROM transactions
        WHERE status = 'approved' AND company_id = ?
        GROUP BY COALESCE(product, '')");
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $nameVal = $r['expense_name'] ?? '';
        $k = strtolower(trim((string)$nameVal));
        if ($k === '') continue;
        $total_in_expense[$k] = 0;
        $total_out_expense[$k] = (float)($r['tout'] ?? 0);
    }
} catch (Throwable $e) {
    if (empty($diag_error)) $diag_error = $e->getMessage();
}

// 余额阈值 Telegram 通知：银行超过设定 / 产品低于设定（隐藏设置见 admin_balance_notify.php）
$bank_balances_for_notify = [];
$product_balances_for_notify = [];
foreach ($banks as $b) {
    if ((int)($b['is_active'] ?? 1) !== 1) continue;
    $bname = trim((string)$b['name']);
    $bkey = strtolower($bname);
    $start = (float)($balance_bank[$bkey] ?? 0);
    $tin = (float)($total_in_bank[$bkey] ?? 0);
    $tout = (float)($total_out_bank[$bkey] ?? 0);
    $bank_balances_for_notify[$bname] = $start + $tin - $tout;
}
foreach ($products_full as $p) {
    if ((int)($p['is_active'] ?? 1) !== 1) continue;
    if (!empty($p['delete_pending_at'])) {
        continue;
    }
    $pname = trim((string)$p['name']);
    $pkey = strtolower($pname);
    $start = (float)($balance_product[$pkey] ?? 0);
    $tin = (float)($total_in_product[$pkey] ?? 0);
    $topup = (float)($total_topup_product[$pkey] ?? 0);
    $tout = (float)($total_out_product[$pkey] ?? 0);
    $product_balances_for_notify[$pname] = $start - $tin + $topup + $tout;
}
require_once __DIR__ . '/inc/balance_notify.php';
check_balance_notify($bank_balances_for_notify, $product_balances_for_notify);
$balance_notify_cfg = balance_notify_get_config();

// 仅当有待审核流水时显示提示
$cnt_pending = 0;
try {
    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'pending' AND company_id = ?");
    $stmtP->execute([$company_id]);
    $cnt_pending = (int)$stmtP->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('nav_banks_products'), ENT_QUOTES, 'UTF-8') ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 960px;">
                <div class="page-header">
                    <h2><?= htmlspecialchars(__('nav_banks_products'), ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
                <?php if (!empty($diag_error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars(__('abp_diag_error'), ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($diag_error) ?></div>
                <?php endif; ?>
                <?php if ($cnt_pending > 0): ?>
                <div class="alert" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;">
                    <span style="font-size:1.2em;" aria-hidden="true">⚠</span> <?= htmlspecialchars(__f('abp_pending_txn_line', (int)$cnt_pending), ENT_QUOTES, 'UTF-8') ?> <a href="admin_approvals.php"><?= htmlspecialchars(__('abp_go_approvals'), ENT_QUOTES, 'UTF-8') ?></a>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="bank-telegram-header">
                        <div class="bank-telegram-item">
                            <h3 style="display:flex;align-items:center;gap:8px;margin:0;">
                                <?= htmlspecialchars(__('abp_section_bank'), ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="bank-add-wrap" aria-label="<?= htmlspecialchars(__('abp_aria_expand_transfer'), ENT_QUOTES, 'UTF-8') ?>">+</button>
                            </h3>
                            <div id="bank-add-wrap" style="display:none; margin-top:10px;">
                                <div class="bank-transfer-box bank-contra-compact">
                                    <div class="bank-contra-title"><?= htmlspecialchars(__('abp_bank_contra_title'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post" id="bank-contra-form" class="bank-contra-form">
                                        <input type="hidden" name="action" value="do_transfer">
                                        <div class="bank-contra-row">
                                            <span class="bank-contra-label">Type</span>
                                            <input type="text" class="form-control bank-contra-input" value="CONTRA" readonly tabindex="-1">
                                            <span class="bank-contra-label">Date</span>
                                            <input type="date" name="transfer_day" class="form-control bank-contra-input" value="<?= date('Y-m-d') ?>">
                                            <span class="bank-contra-label">Account</span>
                                            <select name="to_bank" id="contra-to-bank" class="form-control bank-contra-select" required><option value="">— To —</option><?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); ?><option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option><?php endforeach; ?></select>
                                            <select name="from_bank" id="contra-from-bank" class="form-control bank-contra-select" required><option value="">— From —</option><?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); ?><option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option><?php endforeach; ?></select>
                                            <button type="button" class="btn btn-sm btn-outline" id="contra-reverse">Reverse</button>
                                            <span class="bank-contra-label">Amount</span>
                                            <input type="text" name="amount" class="form-control bank-contra-input" placeholder="<?= htmlspecialchars(__('abp_placeholder_amount'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required style="width:88px;">
                                            <span class="bank-contra-label">Remark</span>
                                            <input type="text" name="transfer_remark" class="form-control bank-contra-input" placeholder="<?= htmlspecialchars(__('abp_placeholder_optional'), ENT_QUOTES, 'UTF-8') ?>" style="width:100px;">
                                        </div>
                                        <div class="bank-contra-row bank-contra-row-footer">
                                            <label class="bank-contra-check"><input type="checkbox" name="confirm_submit" value="1" required> <?= htmlspecialchars(__('abp_confirm_checkbox'), ENT_QUOTES, 'UTF-8') ?></label>
                                            <button type="submit" class="btn btn-primary btn-sm"><?= htmlspecialchars(__('abp_btn_submit'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </div>
                                    </form>
                                </div>
                                <form method="post" style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border);">
                                    <input type="hidden" name="action" value="create_bank">
                                    <div class="form-group">
                                        <label><?= htmlspecialchars(__('abp_label_name_req'), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input name="name" class="form-control" required placeholder="<?= htmlspecialchars(__('abp_placeholder_bank_ex'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label><?= htmlspecialchars(__('abp_label_sort'), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input name="sort_order" class="form-control" type="number" value="0">
                                    </div>
                                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('abp_btn_add'), ENT_QUOTES, 'UTF-8') ?></button>
                                </form>
                            </div>
                        </div>
                        <div class="bank-telegram-item">
                            <div class="bank-contra-title" style="display:flex;align-items:center;gap:8px;margin:0;">
                                <?= htmlspecialchars(__('abp_telegram_notify'), ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="balance-notify-wrap" aria-label="<?= htmlspecialchars(__('abp_aria_expand_settings'), ENT_QUOTES, 'UTF-8') ?>"><?= $open_notify_form ? '−' : '+' ?></button>
                            </div>
                            <div id="balance-notify-wrap" style="display:<?= $open_notify_form ? 'block' : 'none' ?>; margin-top:8px;">
                                <div class="bank-contra-compact bank-notify-settings">
                                    <form method="post" class="bank-contra-form">
                                        <input type="hidden" name="action" value="save_balance_notify">
                                        <div class="balance-notify-grid">
                                            <div class="balance-notify-group">
                                                <span class="bank-contra-label"><?= htmlspecialchars(__('abp_notify_bank_above'), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php foreach ($banks as $b): $bname = trim((string)$b['name']); $bkey = strtolower($bname); $val = $balance_notify_cfg['bank'][$bkey] ?? ''; ?>
                                                <div class="balance-notify-row">
                                                    <label class="balance-notify-name"><?= htmlspecialchars($bname) ?></label>
                                                    <input type="text" name="bank[<?= htmlspecialchars($bkey) ?>]" class="form-control bank-contra-input" placeholder="<?= htmlspecialchars(__('abp_placeholder_leave_empty'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" value="<?= $val !== '' && $val > 0 ? htmlspecialchars((string)$val) : '' ?>" style="width:88px;">
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="balance-notify-group">
                                                <span class="bank-contra-label"><?= htmlspecialchars(__('abp_notify_product_below'), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php foreach ($products_full as $p): $pname = trim((string)$p['name']); $pkey = strtolower($pname); $val = $balance_notify_cfg['product'][$pkey] ?? ''; ?>
                                                <div class="balance-notify-row">
                                                    <label class="balance-notify-name"><?= htmlspecialchars($pname) ?></label>
                                                    <input type="text" name="product[<?= htmlspecialchars($pkey) ?>]" class="form-control bank-contra-input" placeholder="<?= htmlspecialchars(__('abp_placeholder_leave_empty'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" value="<?= $val !== '' && $val > 0 ? htmlspecialchars((string)$val) : '' ?>" style="width:88px;">
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="bank-contra-row bank-contra-row-footer" style="margin-top:10px;">
                                            <button type="submit" class="btn btn-primary btn-sm"><?= htmlspecialchars(__('abp_btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('abp_col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_name'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_status'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_sort'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_in'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_out'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_actions'), ENT_QUOTES, 'UTF-8') ?></th>
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
                                <td><?= ((int)$b['is_active'] === 1) ? htmlspecialchars(__('abp_status_enabled'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('abp_status_disabled'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$b['sort_order'] ?></td>
                                <td><?= htmlspecialchars($b['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '0.00' ?></td>
                                <td class="num in"><?= number_format($tin, 2) ?></td>
                                <td class="num out"><?= number_format($tout, 2) ?></td>
                                <td class="num profit"><?= number_format($balance_now, 2) ?></td>
                                <td>
                                    <span class="balance-cell-inline">
                                        <button type="button" class="btn btn-sm btn-primary js-balance-change"><?= htmlspecialchars(__('abp_btn_change'), ENT_QUOTES, 'UTF-8') ?></button>
                                        <form method="post" class="balance-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="save_balance">
                                            <input type="hidden" name="adjust_type" value="bank">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($bname) ?>">
                                            <input type="text" name="balance" class="balance-inline-input" placeholder="<?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required size="6" value="<?= $cur !== null ? htmlspecialchars(sprintf('%.2f', $cur)) : '' ?>" title="<?= htmlspecialchars(__('abp_title_starting_balance_only'), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('abp_btn_ok'), ENT_QUOTES, 'UTF-8') ?></button>
                                            <button type="button" class="btn btn-sm btn-outline js-balance-inline-cancel"><?= htmlspecialchars(__('abp_btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    </span>
                                    <span class="transfer-cell-inline" style="display:inline-block;">
                                        <button type="button" class="btn btn-sm btn-outline js-transfer-open" data-bank="<?= htmlspecialchars($bname) ?>"><?= htmlspecialchars(__('abp_btn_transfer'), ENT_QUOTES, 'UTF-8') ?></button>
                                        <form method="post" class="transfer-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="do_transfer">
                                            <input type="hidden" name="from_bank" class="transfer-from-bank" value="">
                                            <span class="transfer-label"><?= htmlspecialchars(__('abp_transfer_to_label'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <select name="to_bank" class="form-control transfer-to-bank" required style="display:inline-block;width:auto;min-width:90px;">
                                                <option value=""><?= htmlspecialchars(__('abp_select_bank_placeholder'), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php foreach ($banks as $ob): $oname = trim((string)$ob['name']); if ($oname === $bname) continue; ?>
                                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="amount" class="form-control transfer-amount" placeholder="<?= htmlspecialchars(__('abp_placeholder_amount'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required size="6" style="display:inline-block;width:80px;">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('abp_btn_ok'), ENT_QUOTES, 'UTF-8') ?></button>
                                            <button type="button" class="btn btn-sm btn-outline js-transfer-cancel"><?= htmlspecialchars(__('abp_btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    </span>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_bank">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$b['is_active'] === 1) ? htmlspecialchars(__('abp_status_disabled'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('abp_status_enabled'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$banks): ?><tr><td colspan="10"><?= htmlspecialchars(__('abp_empty_banks'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="card">
                    <h3 class="section-title-inline">
                        <?= htmlspecialchars(__('abp_section_product_mgmt'), ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="product-add-wrap" aria-label="<?= htmlspecialchars(__('abp_aria_expand_topup_add'), ENT_QUOTES, 'UTF-8') ?>">+</button>
                    </h3>
                    <div id="product-add-wrap" style="display:none; margin-bottom:16px;">
                        <div class="product-topup-box" style="margin-bottom:16px; padding:12px 14px; background:rgba(248,250,252,0.9); border:1px solid var(--border); border-radius:6px;">
                            <div style="font-weight:600; margin-bottom:8px; font-size:13px;"><?= htmlspecialchars(__('abp_product_topup_title'), ENT_QUOTES, 'UTF-8') ?></div>
                            <form method="post" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                                <input type="hidden" name="action" value="do_product_topup">
                                <span style="font-size:13px;"><?= htmlspecialchars(__('abp_select_product'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="product" class="form-control" required style="width:auto; min-width:110px;">
                                    <option value=""><?= htmlspecialchars(__('abp_option_select'), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php foreach ($products_usable as $op): $oname = trim((string)$op['name']); ?>
                                    <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span style="font-size:13px;"><?= htmlspecialchars(__('abp_label_add_amount'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="amount" class="form-control" placeholder="<?= htmlspecialchars(__('abp_placeholder_amount_num'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required style="width:90px;">
                                <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('abp_btn_submit'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </div>
                        <form method="post" style="padding-top:14px; border-top:1px solid var(--border);">
                            <input type="hidden" name="action" value="create_product">
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('abp_label_name_req'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input name="name" class="form-control" required placeholder="<?= htmlspecialchars(__('abp_placeholder_product_ex'), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('abp_label_sort'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input name="sort_order" class="form-control" type="number" value="0">
                            </div>
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('abp_btn_add'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('abp_col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_name'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_status'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_sort'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_in'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_topup'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_out'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($products as $p):
                                $pname = trim((string)$p['name']);
                                $pkey = strtolower($pname);
                                $pend_del = !empty($p['delete_pending_at']);
                                $cur = $balance_product[$pkey] ?? null;
                                $start = $cur !== null ? (float)$cur : 0;
                                $tin = $total_in_product[$pkey] ?? 0;
                                $topup = $total_topup_product[$pkey] ?? 0;
                                $tout = $total_out_product[$pkey] ?? 0;
                                $balance_now = $start - $tin + $topup + $tout;
                                $status_cell = $pend_del ? __('abp_status_disabled_pending_del') : (((int)$p['is_active'] === 1) ? __('abp_status_enabled') : __('abp_status_disabled'));
                            ?>
                            <tr<?= $pend_del ? ' style="background:rgba(254,242,242,0.88);"' : '' ?>>
                                <td><?= (int)$p['id'] ?></td>
                                <td><?= htmlspecialchars($pname) ?></td>
                                <td><?= htmlspecialchars($status_cell, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$p['sort_order'] ?></td>
                                <td><?= htmlspecialchars($p['created_at']) ?></td>
                                <td class="num"><?= $cur !== null ? number_format($cur, 2) : '0.00' ?></td>
                                <td class="num in"><?= $tin != 0 ? '−' . number_format($tin, 2) : '—' ?></td>
                                <td class="num topup"><?= $topup != 0 ? number_format($topup, 2) : '—' ?></td>
                                <td class="num out"><?= $tout != 0 ? number_format($tout, 2) : '—' ?></td>
                                <td class="num profit"><?= number_format($balance_now, 2) ?></td>
                                <td>
                                    <span class="balance-cell-inline">
                                        <button type="button" class="btn btn-sm btn-primary js-balance-change"><?= htmlspecialchars(__('abp_btn_change'), ENT_QUOTES, 'UTF-8') ?></button>
                                        <form method="post" class="balance-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="save_balance">
                                            <input type="hidden" name="adjust_type" value="product">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($pname) ?>">
                                            <input type="text" name="balance" class="balance-inline-input" placeholder="<?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required size="6" value="<?= $cur !== null ? htmlspecialchars(sprintf('%.2f', $cur)) : '' ?>" title="<?= htmlspecialchars(__('abp_title_starting_balance_only'), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('abp_btn_ok'), ENT_QUOTES, 'UTF-8') ?></button>
                                            <button type="button" class="btn btn-sm btn-outline js-balance-inline-cancel"><?= htmlspecialchars(__('abp_btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    </span>
                                    <?php if (!$pend_del): ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="toggle_product">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$p['is_active'] === 1) ? htmlspecialchars(__('abp_status_disabled'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('abp_status_enabled'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$pend_del && (int)$p['is_active'] === 0): ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;" data-confirm="<?= htmlspecialchars(__('abp_confirm_request_delete'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="request_product_delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="border-color:#dc2626;color:#b91c1c;"><?= htmlspecialchars(__('abp_btn_request_delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($pend_del): ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;">
                                        <input type="hidden" name="action" value="cancel_product_delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= htmlspecialchars(__('abp_btn_cancel_request'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <?php if ($actor_is_boss_like): ?>
                                    <form method="post" class="inline" style="display:inline;margin-left:8px;" data-confirm="<?= htmlspecialchars(__('abp_confirm_approve_delete'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="approve_product_delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('abp_btn_approve_delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td colspan="11"><?= htmlspecialchars(__('abp_empty_products'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="card">
                    <h3 class="section-title-inline">
                        <?= htmlspecialchars(__('abp_section_expense_mgmt'), ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn btn-sm btn-outline js-toggle-add" data-target="expense-add-wrap" aria-label="<?= htmlspecialchars(__('abp_aria_expand_expense'), ENT_QUOTES, 'UTF-8') ?>">+</button>
                    </h3>
                    <div id="expense-add-wrap" style="display:none; margin-bottom:16px;">
                        <form method="post" style="padding-top:14px; border-top:1px solid var(--border);">
                            <input type="hidden" name="action" value="create_expense">
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('abp_label_name_req'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input name="name" class="form-control" required placeholder="<?= htmlspecialchars(__('abp_placeholder_expense_ex'), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(__('abp_label_sort'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input name="sort_order" class="form-control" type="number" value="0">
                            </div>
                            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('abp_btn_add'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('abp_col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_name'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_status'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_sort'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_in'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_out'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="num"><?= htmlspecialchars(__('abp_col_balance'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('abp_col_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $e): ?>
                            <tr>
                                <td><?= (int)$e['id'] ?></td>
                                <td><?= htmlspecialchars((string)$e['name']) ?></td>
                                <td><?= ((int)$e['is_active'] === 1) ? htmlspecialchars(__('abp_status_enabled'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('abp_status_disabled'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$e['sort_order'] ?></td>
                                <td><?= htmlspecialchars((string)$e['created_at']) ?></td>
                                <?php
                                    $ename = trim((string)$e['name']);
                                    $ekey = strtolower($ename);
                                    $ecur = $balance_expense[$ekey] ?? null;
                                    $estart = $ecur !== null ? (float)$ecur : 0;
                                    $ein = (float)($total_in_expense[$ekey] ?? 0);
                                    $eout = (float)($total_out_expense[$ekey] ?? 0);
                                    $ebalance = $estart + $ein - $eout;
                                ?>
                                <td class="num"><?= $ecur !== null ? number_format($ecur, 2) : '0.00' ?></td>
                                <td class="num in"><?= $ein != 0 ? number_format($ein, 2) : '—' ?></td>
                                <td class="num out"><?= $eout != 0 ? number_format($eout, 2) : '—' ?></td>
                                <td class="num profit"><?= number_format($ebalance, 2) ?></td>
                                <td>
                                    <span class="balance-cell-inline">
                                        <button type="button" class="btn btn-sm btn-primary js-balance-change"><?= htmlspecialchars(__('abp_btn_change'), ENT_QUOTES, 'UTF-8') ?></button>
                                        <form method="post" class="balance-inline-form" style="display:none;">
                                            <input type="hidden" name="action" value="save_balance">
                                            <input type="hidden" name="adjust_type" value="expense">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($ename) ?>">
                                            <input type="text" name="balance" class="balance-inline-input" placeholder="<?= htmlspecialchars(__('abp_col_starting_balance'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" required size="6" value="<?= $ecur !== null ? htmlspecialchars(sprintf('%.2f', $ecur)) : '' ?>" title="<?= htmlspecialchars(__('abp_title_starting_balance_only'), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('abp_btn_ok'), ENT_QUOTES, 'UTF-8') ?></button>
                                            <button type="button" class="btn btn-sm btn-outline js-balance-inline-cancel"><?= htmlspecialchars(__('abp_btn_cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    </span>
                                    <form method="post" class="inline" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_expense">
                                        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?= ((int)$e['is_active'] === 1) ? htmlspecialchars(__('abp_status_disabled'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(__('abp_status_enabled'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function(){
        var toSel = document.getElementById('contra-to-bank');
        var fromSel = document.getElementById('contra-from-bank');
        var revBtn = document.getElementById('contra-reverse');
        if (toSel && fromSel && revBtn) {
            revBtn.addEventListener('click', function(){
                var t = toSel.value;
                toSel.value = fromSel.value;
                fromSel.value = t;
            });
        }
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
    .balance-notify-grid { display: flex; flex-wrap: wrap; gap: 20px 24px; }
    .balance-notify-group { min-width: 180px; }
    .balance-notify-group .bank-contra-label { display: block; margin-bottom: 6px; font-size: 12px; }
    .balance-notify-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .balance-notify-name { min-width: 4em; font-size: 12px; margin: 0; }
    .bank-telegram-header { display: flex; flex-wrap: wrap; gap: 24px 32px; align-items: flex-start; }
    .bank-telegram-item { flex: 1; min-width: 260px; }
    .section-title-inline { display:flex; align-items:center; gap:8px; margin:0; }
    .data-table td { vertical-align: middle; }
    .data-table td .btn { white-space: nowrap; }
    .balance-cell-inline, .transfer-cell-inline { margin-right: 8px; }
    @media (max-width: 768px) {
        .bank-telegram-item { min-width: 100%; }
    }
    </style>
</body>
</html>
