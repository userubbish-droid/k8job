<?php
/**
 * PG 分公司：Telegram 快捷记账设置（表 telegram_quick_txn_config_pg；与 gaming 的 admin_telegram_quick_txn.php 分开）
 * 仅 Boss / BigBoss；仅 business_kind=pg 的公司可保存。
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();

require_once __DIR__ . '/inc/notify.php';
require_once __DIR__ . '/telegram_pg_quick_txn_webhook.php';

$sidebar_current = 'admin_telegram_pg';
$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$company_id = effective_admin_company_id($pdo);

$msg = '';
$err = '';

// ensure tables (same as webhook)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_config_pg (
        company_id INT UNSIGNED NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        chat_id VARCHAR(40) NULL,
        allowed_user_ids TEXT NULL,
        bank_alias_json TEXT NULL,
        product_alias_json TEXT NULL,
        staff_alias_json TEXT NULL,
        receipt_prefix TEXT NULL,
        receipt_slogan TEXT NULL,
        receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic',
        undo_window_sec INT NOT NULL DEFAULT 600,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        PRIMARY KEY (company_id)
    )");
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN staff_alias_json TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_prefix TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_slogan TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic'"); } catch (Throwable $e) {}
    foreach (['pg_simple_member_code', 'pg_simple_bank', 'pg_simple_product'] as $__pgc) {
        try { $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN {$__pgc} VARCHAR(64) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_pg_chat_customer_pg (
        chat_id VARCHAR(40) NOT NULL,
        topic_key VARCHAR(32) NOT NULL DEFAULT '0',
        company_id INT UNSIGNED NOT NULL,
        member_code VARCHAR(64) NOT NULL DEFAULT '',
        default_bank VARCHAR(64) NOT NULL DEFAULT '',
        default_product VARCHAR(64) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (chat_id, topic_key),
        KEY idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    tqx_pg_ensure_tables($pdo);
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// company pick for superadmin
$companies = [];
$company_pick = $company_id;
if ($actor_is_superadmin) {
    try {
        $companies = $pdo->query('SELECT id, code, name, is_active FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companies = [];
    }
    $pick_raw = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
    if ($pick_raw > 0) $company_pick = $pick_raw;
    if ($company_pick <= 0) $company_pick = $company_id > 0 ? $company_id : 1;
}

$company_pick_pg_kind = false;
try {
    $stKind = $pdo->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
    $stKind->execute([(int)$company_pick]);
    $company_pick_pg_kind = (strtolower(trim((string)$stKind->fetchColumn())) === 'pg');
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    try {
        if (!$company_pick_pg_kind) {
            throw new RuntimeException('当前所选公司不是 PG（companies.business_kind 需为 pg）。请在「分公司」里把该公司设为 PG，或切换分公司后再保存。');
        }
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $chat_id = trim((string)($_POST['chat_id'] ?? ''));
        $undo_window = (int)($_POST['undo_window_sec'] ?? 600);
        $undo_window = max(30, min(86400, $undo_window));

        $allow = trim((string)($_POST['allowed_user_ids'] ?? ''));
        $allow_arr = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $allow) ?: []), fn($x) => $x !== ''));
        $allow_json = json_encode($allow_arr, JSON_UNESCAPED_UNICODE);

        $bank_alias = trim((string)($_POST['bank_alias'] ?? ''));
        $bank_map = [];
        foreach (preg_split('/\r?\n/', $bank_alias) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (!str_contains($line, '=')) continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && $v !== '') $bank_map[strtolower($k)] = $v;
        }
        $staff_alias = trim((string)($_POST['staff_alias'] ?? ''));
        $staff_map = [];
        foreach (preg_split('/\r?\n/', $staff_alias) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (!str_contains($line, '=')) continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && $v !== '') $staff_map[$k] = $v;
        }

        $receipt_prefix = trim((string)($_POST['receipt_prefix'] ?? ''));
        $receipt_slogan = trim((string)($_POST['receipt_slogan'] ?? ''));
        $receipt_style = strtolower(trim((string)($_POST['receipt_style'] ?? 'classic')));
        if (!in_array($receipt_style, ['classic', 'game'], true)) $receipt_style = 'classic';

        $pg_simple_member_code = trim((string)($_POST['pg_simple_member_code'] ?? ''));
        $pg_simple_bank = trim((string)($_POST['pg_simple_bank'] ?? ''));

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $st = $pdo->prepare("INSERT INTO telegram_quick_txn_config_pg (company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec, pg_simple_member_code, pg_simple_bank, pg_simple_product, updated_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                               enabled=VALUES(enabled),
                               chat_id=VALUES(chat_id),
                               allowed_user_ids=VALUES(allowed_user_ids),
                               bank_alias_json=VALUES(bank_alias_json),
                               product_alias_json=VALUES(product_alias_json),
                               staff_alias_json=VALUES(staff_alias_json),
                               receipt_prefix=VALUES(receipt_prefix),
                               receipt_slogan=VALUES(receipt_slogan),
                               receipt_style=VALUES(receipt_style),
                               undo_window_sec=VALUES(undo_window_sec),
                               pg_simple_member_code=VALUES(pg_simple_member_code),
                               pg_simple_bank=VALUES(pg_simple_bank),
                               pg_simple_product=VALUES(pg_simple_product),
                               updated_by=VALUES(updated_by)");
        $st->execute([
            $company_pick,
            $enabled,
            ($chat_id !== '' ? $chat_id : null),
            $allow_json,
            json_encode($bank_map, JSON_UNESCAPED_UNICODE),
            '{}',
            json_encode($staff_map, JSON_UNESCAPED_UNICODE),
            ($receipt_prefix !== '' ? $receipt_prefix : null),
            ($receipt_slogan !== '' ? $receipt_slogan : null),
            $receipt_style,
            $undo_window,
            ($pg_simple_member_code !== '' ? $pg_simple_member_code : null),
            ($pg_simple_bank !== '' ? $pg_simple_bank : null),
            null,
            $uid > 0 ? $uid : null,
        ]);
        $msg = '已保存。';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pg_chat_customers']) && !$err && $company_pick_pg_kind) {
    try {
        $ids = $_POST['bind_chat_id'] ?? [];
        $tkeys = $_POST['bind_topic_key'] ?? [];
        $codes = $_POST['bind_member_code'] ?? [];
        $banks = $_POST['bind_default_bank'] ?? [];
        if (!is_array($ids) || !is_array($codes)) {
            throw new RuntimeException('表单无效。');
        }
        foreach ($ids as $i => $chid) {
            $chid = trim((string)$chid);
            if ($chid === '') {
                continue;
            }
            $tk = isset($tkeys[$i]) ? trim((string)$tkeys[$i]) : '0';
            if ($tk === '' || !preg_match('/^[0-9]{1,24}$/', $tk)) {
                $tk = '0';
            }
            $mem = trim((string)($codes[$i] ?? ''));
            $bks = trim((string)($banks[$i] ?? ''));
            if (strlen($mem) > 64 || strlen($bks) > 64) {
                throw new RuntimeException('字段过长（最多 64 字符）：' . $chid);
            }
            $stChk = $pdo->prepare('SELECT company_id FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = ? LIMIT 1');
            $stChk->execute([$chid, $tk]);
            $exCid = (int)$stChk->fetchColumn();
            if ($exCid > 0 && $exCid !== (int)$company_pick) {
                continue;
            }
            $pdo->prepare('INSERT INTO telegram_pg_chat_customer_pg (chat_id, topic_key, company_id, member_code, default_bank, default_product) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE member_code = VALUES(member_code), default_bank = VALUES(default_bank), default_product = VALUES(default_product), company_id = VALUES(company_id)')
                ->execute([$chid, $tk, (int)$company_pick, $mem, $bks, '']);
        }
        $msg = ($msg !== '' ? $msg . ' ' : '') . '已保存各群/话题默认（客户、银行）。';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$cur = [
    'enabled' => 0,
    'chat_id' => '',
    'allowed_user_ids' => '[]',
    'bank_alias_json' => '{}',
    'product_alias_json' => '{}',
    'staff_alias_json' => '{}',
    'receipt_prefix' => '',
    'receipt_slogan' => '',
    'receipt_style' => 'classic',
    'undo_window_sec' => 600,
    'pg_simple_member_code' => '',
    'pg_simple_bank' => '',
];
if (!$err) {
    try {
        $st = $pdo->prepare("SELECT * FROM telegram_quick_txn_config_pg WHERE company_id = ? LIMIT 1");
        $st->execute([$company_pick]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $cur = array_merge($cur, $r);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

function _map_to_lines(string $json): string {
    $m = json_decode($json, true);
    if (!is_array($m)) return '';
    $lines = [];
    foreach ($m as $k => $v) {
        $k = trim((string)$k);
        $v = trim((string)$v);
        if ($k === '' || $v === '') continue;
        $lines[] = $k . ' = ' . $v;
    }
    return implode("\n", $lines);
}

$allow_arr = json_decode((string)($cur['allowed_user_ids'] ?? '[]'), true);
$allow_lines = '';
if (is_array($allow_arr)) {
    $allow_lines = implode("\n", array_values(array_filter(array_map('strval', $allow_arr), fn($x)=>trim($x)!=='')));
}
$bank_lines = _map_to_lines((string)($cur['bank_alias_json'] ?? '{}'));
$staff_lines = _map_to_lines((string)($cur['staff_alias_json'] ?? '{}'));

$pg_chat_bindings = [];
if (!$err) {
    try {
        $stBindList = $pdo->prepare('SELECT chat_id, topic_key, member_code, default_bank, updated_at FROM telegram_pg_chat_customer_pg WHERE company_id = ? ORDER BY chat_id ASC, topic_key ASC');
        $stBindList->execute([(int)$company_pick]);
        $pg_chat_bindings = $stBindList->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $pg_chat_bindings = [];
    }
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PG Telegram 快捷记账 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 860px;">
            <div class="page-header">
                <h2>PG Telegram 快捷记账</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="card">
                <p class="form-hint" style="margin-bottom:14px;">
                    <strong>入口：</strong>浏览器打开 <code>admin_telegram_pg.php</code>（侧栏「PG Telegram」）。<br>
                    <strong>Bot Token：</strong>在 <code>PG_notify_config.php</code> 或 <code>notify_config.php</code> 填写 <code>$PG_TELEGRAM_BOT_TOKEN</code>（与 gaming 的 <code>$NOTIFY_TELEGRAM_BOT_TOKEN</code> 分开）。<br>
                    <strong>Webhook：</strong>Telegram 指向 <code>https://你的域名/telegram_pg_webhook.php</code>（不要用 password_reset 那个 URL）。
                </p>
                <?php if (!$company_pick_pg_kind): ?>
                <div class="alert alert-error" style="margin-bottom:14px;">
                    当前查看的公司不是 <code>business_kind = pg</code>。请用上方分公司下拉选 PG 公司，或到 <a href="admin_companies.php">分公司管理</a> 将该公司设为 PG。非 PG 公司无法保存此处配置。
                </div>
                <?php endif; ?>
                <?php if ($actor_is_superadmin): ?>
                    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px;">
                        <div style="min-width:260px; flex:1;">
                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;"><?= htmlspecialchars(__('lbl_company'), ENT_QUOTES, 'UTF-8') ?></label>
                            <select class="form-control" name="company_id" onchange="this.form.submit()">
                                <?php foreach ($companies as $c):
                                    $cid = (int)($c['id'] ?? 0);
                                    if ($cid <= 0) continue;
                                    $cc = trim((string)($c['code'] ?? ''));
                                    $cn = trim((string)($c['name'] ?? ''));
                                    $lab = trim($cc !== '' ? ($cc . ($cn !== '' ? ' - ' . $cn : '')) : ($cn !== '' ? $cn : (string)$cid));
                                ?>
                                    <option value="<?= $cid ?>" <?= $cid === (int)$company_pick ? 'selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button class="btn btn-primary" type="submit">OK</button></noscript>
                    </form>
                <?php endif; ?>

                <form method="post">
                    <?php if ($actor_is_superadmin): ?><input type="hidden" name="company_id" value="<?= (int)$company_pick ?>"><?php endif; ?>
                    <label style="display:flex; gap:10px; align-items:center;">
                        <input type="checkbox" name="enabled" value="1" <?= ((int)($cur['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                        启用 PG 快捷记账（仅 + / - / undo；写入 pg_transactions）
                    </label>

                    <label style="margin-top:12px; font-weight:700;">群 Chat ID（兼容旧版；多群时以群内 /setup 为准）</label>
                    <input class="form-control" name="chat_id" value="<?= htmlspecialchars((string)($cur['chat_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="-100xxxxxxxxxx">
                    <p class="form-hint">多群场景：每个群在群内发 <code>/setup</code> 绑定同一 PG 公司后，再发 <code>/customer 代号</code> 指定该群默认客户。此处 Chat ID 仍可作为参考或单群部署时填写。获取 chat.id：把 PG Bot 拉进群后发消息，用 PG token 调 <code>getUpdates</code>。</p>

                    <label style="margin-top:12px; font-weight:700;">极简指令（群内只发 +金额 / -金额）</label>
                    <p class="form-hint">群内可设 <code>/customer</code> <code>/bank</code>（按论坛话题分别设置）；未设时回退下方两项。齐备后极简 <code>+100</code>。完整格式：<code>+100 C001 HLB 备注</code>（无产品段）。</p>
                    <div style="display:grid; grid-template-columns:1fr; gap:8px;">
                        <input class="form-control" name="pg_simple_member_code" value="<?= htmlspecialchars((string)($cur['pg_simple_member_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="简易代号（如 C001）">
                        <input class="form-control" name="pg_simple_bank" value="<?= htmlspecialchars((string)($cur['pg_simple_bank'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="简易银行/渠道（如 HLB）">
                    </div>

                    <label style="margin-top:12px; font-weight:700;">允许记账的用户（必填，仅管理员）</label>
                    <textarea class="form-control" name="allowed_user_ids" rows="4" placeholder="填 Telegram user_id 或 username，每行一个（必填）"><?= htmlspecialchars($allow_lines, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="form-hint">安全要求：白名单为空时，机器人对所有人都不记账（无效）。</p>

                    <label style="margin-top:12px; font-weight:700;">撤销时间窗（秒）</label>
                    <input class="form-control" type="number" name="undo_window_sec" value="<?= (int)($cur['undo_window_sec'] ?? 600) ?>" min="30" max="86400">

                    <label style="margin-top:12px; font-weight:700;">机器人回执文案（可自定义）</label>
                    <input class="form-control" name="receipt_prefix" value="<?= htmlspecialchars((string)($cur['receipt_prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：完成记账 / 记录成功 / 你自定义的词">
                    <p class="form-hint">会显示在回执第一行：<code>✅ &lt;文案&gt; PG 入款/出款</code></p>

                    <label style="margin-top:12px; font-weight:700;">回执第二行祝福语（可选）</label>
                    <input class="form-control" name="receipt_slogan" value="<?= htmlspecialchars((string)($cur['receipt_slogan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：Semoga boss dapat rezeki 🥰">

                    <label style="margin-top:12px; font-weight:700;">回执格式</label>
                    <select class="form-control" name="receipt_style">
                        <?php $rs = strtolower((string)($cur['receipt_style'] ?? 'classic')); ?>
                        <option value="classic" <?= $rs === 'classic' ? 'selected' : '' ?>>经典（显示金额/银行/备注）</option>
                        <option value="game" <?= $rs === 'game' ? 'selected' : '' ?>>游戏样式（PG Bot 当前以经典回执为主，此项保留兼容）</option>
                    </select>

                    <label style="margin-top:12px; font-weight:700;">银行缩写映射（每行：短写 = 全称）</label>
                    <textarea class="form-control" name="bank_alias" rows="5" placeholder="P = Parking&#10;HLB = HLB"><?= htmlspecialchars($bank_lines, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <label style="margin-top:12px; font-weight:700;">员工别名映射（每行：Telegram user_id = 显示名）</label>
                    <textarea class="form-control" name="staff_alias" rows="4" placeholder="7390307542 = CS1"><?= htmlspecialchars($staff_lines, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div style="margin-top:14px;">
                        <button class="btn btn-primary" type="submit" <?= !$company_pick_pg_kind ? 'disabled' : '' ?>><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>

                <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
                <h3 style="font-size:1rem; margin-bottom:10px;">各群 / 论坛话题 默认（客户、银行）</h3>
                <p class="form-hint" style="margin-bottom:10px;">群内：<code>/customer</code> <code>/bank</code>；<code>topic_key=0</code> 为整群，数字为论坛话题 ID（发 <code>/id</code> 可见）。极简 <code>+100</code> 用此处；完整格式以消息中的银行为准。</p>
                <?php if ($company_pick_pg_kind): ?>
                <form method="post">
                    <?php if ($actor_is_superadmin): ?><input type="hidden" name="company_id" value="<?= (int)$company_pick ?>"><?php endif; ?>
                    <input type="hidden" name="save_pg_chat_customers" value="1">
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>chat_id</th><th>topic_key</th><th>客户代号</th><th>默认银行</th></tr></thead>
                            <tbody>
                            <?php if (empty($pg_chat_bindings)): ?>
                                <tr><td colspan="4" class="form-hint">尚无记录。请在目标群用 PG Bot 发 <code>/setup</code> 后再试，或下方手动添加一行。</td></tr>
                            <?php else: ?>
                                <?php foreach ($pg_chat_bindings as $br):
                                    $bcid = htmlspecialchars((string)($br['chat_id'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $btk = htmlspecialchars((string)($br['topic_key'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                    $bmem = htmlspecialchars((string)($br['member_code'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $bbnk = htmlspecialchars((string)($br['default_bank'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td><input type="hidden" name="bind_chat_id[]" value="<?= $bcid ?>"><code><?= $bcid ?></code></td>
                                    <td><input type="hidden" name="bind_topic_key[]" value="<?= $btk ?>"><code><?= $btk ?></code></td>
                                    <td><input class="form-control" name="bind_member_code[]" value="<?= $bmem ?>" placeholder="C001"></td>
                                    <td><input class="form-control" name="bind_default_bank[]" value="<?= $bbnk ?>" placeholder="HLB"></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                                <tr>
                                    <td><input class="form-control" name="bind_chat_id[]" value="" placeholder="-100xxxxxxxxxx"></td>
                                    <td><input class="form-control" name="bind_topic_key[]" value="" placeholder="0 或话题ID"></td>
                                    <td><input class="form-control" name="bind_member_code[]" value="" placeholder="代号"></td>
                                    <td><input class="form-control" name="bind_default_bank[]" value="" placeholder="银行"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary" type="submit" style="margin-top:10px;">保存</button>
                </form>
                <?php endif; ?>

                <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
                <div class="form-hint">
                    <strong>极简（代号 + 银行 + 群或后台默认）：</strong><br>
                    <code>+100</code> 或 <code>-50</code><br><br>
                    <strong>完整格式（无产品段）：</strong><br>
                    <code>+100 C011 HLB b10 remark</code> / <code>-200 C012 HLB remark</code>（本话题已 <code>/customer</code> 时代号以群设置为准；银行以本消息为准）<br>
                    撤销：<code>undo &lt;token&gt;</code>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>

