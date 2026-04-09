<?php
require 'config.php';
require 'auth.php';
require_permission('customer_create');
$sidebar_current = 'customer_create';

$msg = '';
$err = '';
$need_confirm = false;
$confirm_message = '';
$company_id = current_company_id();

// bonus_flag 列（no bonus / scam receipt）
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN bonus_flag VARCHAR(32) NULL DEFAULT NULL AFTER bank_details");
} catch (Throwable $e) {
}

// 建议下一个客户代码：按现有 C001、C009 等递增，下一个为 C010
$suggested_code = 'C001';
try {
    $stmt = $pdo->prepare("SELECT code FROM customers WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $max_num = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $c = trim($row['code'] ?? '');
        if (preg_match('/^C(\d+)$/i', $c, $m)) {
            $n = (int) $m[1];
            if ($n > $max_num) $max_num = $n;
        }
    }
    $suggested_code = 'C' . sprintf('%03d', $max_num + 1);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $bonus_flag_raw = trim((string)($_POST['bonus_flag'] ?? ''));
    $bonus_flag = in_array($bonus_flag_raw, ['no_bonus', 'scam_receipt'], true) ? $bonus_flag_raw : null;
    $recommend = trim($_POST['recommend'] ?? '');
    $confirm_override = isset($_POST['confirm_override']) && (string)$_POST['confirm_override'] === '1';

    $actor_role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
    $is_member_actor = ($actor_role === 'member');

    if ($code === '') {
        $err = '请输入客户代码。';
    } else {
        $conflicts = [];
        $has_bank_duplicate = false;
        $existing_code_same_bank = '';
        $bank_dup_codes = [];
        $bank_dup_prefixes = [];

        $extract_bank_prefixes7 = function (string $s): array {
            $s = trim($s);
            if ($s === '') return [];
            preg_match_all('/\d+/', $s, $m);
            $out = [];
            foreach (($m[0] ?? []) as $digits) {
                $digits = (string)$digits;
                if (strlen($digits) >= 7) {
                    $out[] = substr($digits, 0, 7);
                }
            }
            $out = array_values(array_unique($out));
            return $out;
        };

        $input_prefixes7 = $extract_bank_prefixes7($bank_details);
        if ($phone !== '') {
            $stmt = $pdo->prepare("SELECT code FROM customers WHERE company_id = ? AND phone = ? LIMIT 1");
            $stmt->execute([$company_id, $phone]);
            $row = $stmt->fetch();
            if ($row) {
                $conflicts[] = $row['code'] . ' 电话号码同样';
            }
        }
        if ($bank_details !== '') {
            // 规则：只要任意数字串的前 7 位相同，即视为“银行号重复”（不区分银行名）
            if (!empty($input_prefixes7)) {
                try {
                    $stb = $pdo->prepare("SELECT code, bank_details FROM customers WHERE company_id = ? AND bank_details IS NOT NULL AND bank_details <> ''");
                    $stb->execute([$company_id]);
                    while ($rr = $stb->fetch(PDO::FETCH_ASSOC)) {
                        $ecode = trim((string)($rr['code'] ?? ''));
                        $ebank = trim((string)($rr['bank_details'] ?? ''));
                        if ($ecode === '' || $ebank === '') continue;
                        $eprefixes7 = $extract_bank_prefixes7($ebank);
                        if (empty($eprefixes7)) continue;
                        $hits = array_values(array_intersect($input_prefixes7, $eprefixes7));
                        if (!empty($hits)) {
                            $has_bank_duplicate = true;
                            $existing_code_same_bank = $existing_code_same_bank !== '' ? $existing_code_same_bank : $ecode;
                            $bank_dup_codes[] = $ecode;
                            foreach ($hits as $h) $bank_dup_prefixes[] = $h;
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            if ($has_bank_duplicate) {
                $bank_dup_codes = array_values(array_unique($bank_dup_codes));
                $bank_dup_prefixes = array_values(array_unique($bank_dup_prefixes));
                $conflicts[] = (implode(',', $bank_dup_codes) ?: '客户') . ' 银行号码前7位相同（' . (implode(',', $bank_dup_prefixes) ?: '—') . '）';
            }
        }
        if ($is_member_actor && !empty($conflicts) && !$confirm_override) {
            $need_confirm = true;
            $confirm_message = '发现重复：' . implode('；', $conflicts) . '。是否仍要使用相同资料创建？确认后将进入待审核，管理员通过后才生效。';
        } else {
            try {
                $register_date = date('Y-m-d');
                $status = ($is_member_actor && !empty($conflicts)) ? 'pending' : 'approved';
                $approved_by = $status === 'approved' ? (int)($_SESSION['user_id'] ?? 0) : null;
                $approved_at = $status === 'approved' ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare("INSERT INTO customers (company_id, code, name, phone, remark, created_by, register_date, bank_details, bonus_flag, recommend, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $company_id,
                    $code,
                    $name !== '' ? $name : null,
                    $phone !== '' ? $phone : null,
                    $remark !== '' ? $remark : null,
                    (int)($_SESSION['user_id'] ?? 0),
                    $register_date,
                    $bank_details !== '' ? $bank_details : null,
                    $bonus_flag,
                    $recommend !== '' ? $recommend : null,
                    $status,
                    $approved_by,
                    $approved_at
                ]);
                $new_id = (int) $pdo->lastInsertId();
                if ($is_member_actor && $has_bank_duplicate && file_exists(__DIR__ . '/inc/notify.php')) {
                    require_once __DIR__ . '/inc/notify.php';
                    if (function_exists('send_member_duplicate_bank_customer_notify')) {
                        $member_uname = trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? ''));
                        send_member_duplicate_bank_customer_notify(
                            $pdo,
                            $company_id,
                            $member_uname,
                            $code,
                            $existing_code_same_bank,
                            $bank_details,
                            $bank_dup_codes,
                            $bank_dup_prefixes
                        );
                    }
                }
                if ($status === 'pending') {
                    if (file_exists(__DIR__ . '/inc/notify.php')) {
                        require_once __DIR__ . '/inc/notify.php';
                        if (function_exists('send_pending_customer_notify')) {
                            send_pending_customer_notify($pdo, $company_id);
                        }
                    }
                    header('Location: customers.php?pending_customer=1');
                } else {
                    header('Location: customer_edit.php?id=' . $new_id . '&created=1');
                }
                exit;
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                    $err = '该客户代码已存在，请换一个。';
                } elseif (strpos($e->getMessage(), 'recommend') !== false) {
                    $err = '请先在 phpMyAdmin 执行 migrate_customers_recommend.sql 后再保存。';
                } else {
                    $err = '保存失败：' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_lang() === 'en' ? 'New Customer' : '新增客户' ?> - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .form-modal {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(152, 176, 242, 0.32);
            border-radius: 14px;
            box-shadow: var(--card-shadow);
            padding: 18px 18px 16px;
        }
        .form-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(140, 165, 235, 0.28);
            margin-bottom: 14px;
        }
        .form-modal-title { margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; }
        .form-modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(148,163,184,0.55);
            background: #fff;
            color: #334155;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            line-height: 1;
            font-size: 18px;
        }
        .form-grid-2 { display: grid; grid-template-columns: 1fr; gap: 14px; }
        .form-section {
            border: 1px solid rgba(59, 130, 246, 0.18);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(239, 246, 255, 0.7) 0%, rgba(255,255,255,0.92) 100%);
            padding: 12px 12px 10px;
        }
        .form-section h4 { margin: 0 0 10px; font-size: 13px; font-weight: 800; color: #1e3a8a; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(140, 165, 235, 0.22);
        }
        @media (max-width: 720px) { .form-grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
    <div class="page-wrap" style="max-width: 860px;">
        <div class="page-header">
            <h2><?= app_lang() === 'en' ? 'New Customer' : '新增客户' ?></h2>
            <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
        </div>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="form-modal">
            <div class="form-modal-head">
                <div class="form-modal-title"><?= app_lang() === 'en' ? 'New Customer' : '新增客户' ?></div>
                <a class="form-modal-close" href="customers.php" aria-label="<?= app_lang() === 'en' ? 'Close' : '关闭' ?>">×</a>
            </div>
            <form method="post" autocomplete="off" id="customer-create-form">
                <input type="hidden" name="confirm_override" id="confirm_override" value="0">
                <div class="form-grid-2">
                    <div class="form-section">
                        <h4><?= app_lang() === 'en' ? 'Personal Information' : '个人信息' ?></h4>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Customer Code *' : '客户代码 *' ?></label>
                            <input name="code" class="form-control" required placeholder="<?= htmlspecialchars($suggested_code) ?>" value="<?= htmlspecialchars($_POST['code'] ?? $suggested_code) ?>">
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Name' : '姓名' ?></label>
                            <input name="name" class="form-control" placeholder="FULL NAME" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Contact' : '联系电话' ?></label>
                            <input name="phone" class="form-control" placeholder="CONTACT" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Flag' : '标记' ?></label>
                            <?php $bf_post = trim((string)($_POST['bonus_flag'] ?? '')); ?>
                            <select name="bonus_flag" class="form-control">
                                <option value=""><?= app_lang() === 'en' ? '-- None --' : '— 无 —' ?></option>
                                <option value="no_bonus" <?= $bf_post === 'no_bonus' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'No bonus' : 'No bonus' ?></option>
                                <option value="scam_receipt" <?= $bf_post === 'scam_receipt' ? 'selected' : '' ?>><?= app_lang() === 'en' ? 'Scam receipt' : 'Scam receipt' ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Bank Details' : '银行资料' ?></label>
                            <input name="bank_details" class="form-control" placeholder="<?= app_lang() === 'en' ? 'e.g. TNG 160402395453, PBB 8413574015' : '例如 TNG 160402395453、PBB 8413574015' ?>" value="<?= htmlspecialchars($_POST['bank_details'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Recommend</label>
                            <input name="recommend" class="form-control" placeholder="<?= app_lang() === 'en' ? 'Referrer / referral code' : '推荐人/推荐码' ?>" value="<?= htmlspecialchars($_POST['recommend'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label><?= app_lang() === 'en' ? 'Remark' : '备注' ?></label>
                            <textarea name="remark" class="form-control" placeholder="REMARK"><?= htmlspecialchars($_POST['remark'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="customers.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary"><?= app_lang() === 'en' ? 'Save and continue' : '保存并继续' ?></button>
                </div>
            </form>
        </div>
    </div>
        </main>
    </div>
    <?php if ($need_confirm): ?>
    <script>
    (function(){
        var form = document.getElementById('customer-create-form');
        var hidden = document.getElementById('confirm_override');
        if (!form || !hidden) return;
        if (typeof window.appModalConfirm !== 'function') return;
        window.appModalConfirm("<?= htmlspecialchars($confirm_message, ENT_QUOTES) ?>", function(){
            hidden.value = '1';
            form.submit();
        }, <?= json_encode(app_lang() === 'en' ? 'Duplicate Data' : '重复资料', JSON_UNESCAPED_UNICODE) ?>);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
