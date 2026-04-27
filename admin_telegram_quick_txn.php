<?php
/**
 * Telegram 群聊快捷记账设置（独立文件，可整套删除）
 * 仅 Boss / BigBoss 可设置。
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();

require_once __DIR__ . '/inc/notify.php';

$sidebar_current = 'admin_telegram_quick_txn';
$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$company_id = effective_admin_company_id($pdo);

$msg = '';
$err = '';

// ensure tables (same as webhook)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_config (
        company_id INT UNSIGNED NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        chat_id VARCHAR(40) NULL,
        allowed_user_ids TEXT NULL,
        bank_alias_json TEXT NULL,
        product_alias_json TEXT NULL,
        staff_alias_json TEXT NULL,
        undo_window_sec INT NOT NULL DEFAULT 600,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        PRIMARY KEY (company_id)
    )");
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config ADD COLUMN staff_alias_json TEXT NULL"); } catch (Throwable $e) {}
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    try {
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
        $prod_alias = trim((string)($_POST['product_alias'] ?? ''));
        $prod_map = [];
        foreach (preg_split('/\r?\n/', $prod_alias) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (!str_contains($line, '=')) continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && $v !== '') $prod_map[strtolower($k)] = $v;
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

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $st = $pdo->prepare("INSERT INTO telegram_quick_txn_config (company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, undo_window_sec, updated_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                               enabled=VALUES(enabled),
                               chat_id=VALUES(chat_id),
                               allowed_user_ids=VALUES(allowed_user_ids),
                               bank_alias_json=VALUES(bank_alias_json),
                               product_alias_json=VALUES(product_alias_json),
                               staff_alias_json=VALUES(staff_alias_json),
                               undo_window_sec=VALUES(undo_window_sec),
                               updated_by=VALUES(updated_by)");
        $st->execute([
            $company_pick,
            $enabled,
            ($chat_id !== '' ? $chat_id : null),
            $allow_json,
            json_encode($bank_map, JSON_UNESCAPED_UNICODE),
            json_encode($prod_map, JSON_UNESCAPED_UNICODE),
            json_encode($staff_map, JSON_UNESCAPED_UNICODE),
            $undo_window,
            $uid > 0 ? $uid : null,
        ]);
        $msg = '已保存。';
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
    'undo_window_sec' => 600,
];
if (!$err) {
    try {
        $st = $pdo->prepare("SELECT * FROM telegram_quick_txn_config WHERE company_id = ? LIMIT 1");
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
$prod_lines = _map_to_lines((string)($cur['product_alias_json'] ?? '{}'));
$staff_lines = _map_to_lines((string)($cur['staff_alias_json'] ?? '{}'));
?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram 快捷记账设置 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 860px;">
            <div class="page-header">
                <h2>Telegram 快捷记账设置</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="card">
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
                        启用快捷记账（仅 + / - / undo）
                    </label>

                    <label style="margin-top:12px; font-weight:700;">群 Chat ID（必填）</label>
                    <input class="form-control" name="chat_id" value="<?= htmlspecialchars((string)($cur['chat_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="-100xxxxxxxxxx">
                    <p class="form-hint">把 Bot 拉进群后，在群里先发一句话，再打开 <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> 找到 chat.id。</p>

                    <label style="margin-top:12px; font-weight:700;">允许记账的用户（必填，仅管理员）</label>
                    <textarea class="form-control" name="allowed_user_ids" rows="4" placeholder="填 Telegram user_id 或 username，每行一个（必填）"><?= htmlspecialchars($allow_lines, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="form-hint">安全要求：白名单为空时，机器人对所有人都不记账（无效）。</p>

                    <label style="margin-top:12px; font-weight:700;">撤销时间窗（秒）</label>
                    <input class="form-control" type="number" name="undo_window_sec" value="<?= (int)($cur['undo_window_sec'] ?? 600) ?>" min="30" max="86400">

                    <label style="margin-top:12px; font-weight:700;">银行缩写映射（每行：短写 = 全称）</label>
                    <textarea class="form-control" name="bank_alias" rows="5" placeholder="P = Parking&#10;HLB = HLB"><?= htmlspecialchars($bank_lines, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <label style="margin-top:12px; font-weight:700;">产品缩写映射（每行：短写 = 全称）</label>
                    <textarea class="form-control" name="product_alias" rows="5" placeholder="M = MEGA"><?= htmlspecialchars($prod_lines, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <label style="margin-top:12px; font-weight:700;">员工别名映射（每行：Telegram user_id = 显示名）</label>
                    <textarea class="form-control" name="staff_alias" rows="4" placeholder="7390307542 = CS1"><?= htmlspecialchars($staff_lines, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div style="margin-top:14px;">
                        <button class="btn btn-primary" type="submit"><?= htmlspecialchars(__('btn_save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>

                <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
                <div class="form-hint">
                    指令示例：<br>
                    <code>+100 C011 P M b10 remark</code><br>
                    <code>-200 C012 P M remark</code><br>
                    撤销：<code>undo &lt;token&gt;</code>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>

