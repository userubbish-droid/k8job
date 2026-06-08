<?php
/**
 * Telegram Bot 连线诊断 + Token 配置（仅 Boss / BigBoss）
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();
require_once __DIR__ . '/inc/notify.php';
require_once __DIR__ . '/inc/telegram_config_persist.php';

$sidebar_current = 'admin_telegram_bot_status';
$rootDir = __DIR__;
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_notify') {
            $cur = telegram_cfg_read_notify_file($rootDir);
            $newTok = trim((string)($_POST['notify_bot_token'] ?? ''));
            $newChat = trim((string)($_POST['notify_chat_id'] ?? ''));
            $newBase = trim((string)($_POST['notify_base_url'] ?? ''));
            if ($newTok === '') {
                $newTok = $cur['token'] !== '' ? $cur['token'] : trim((string)($NOTIFY_TELEGRAM_BOT_TOKEN ?? ''));
            }
            if (!telegram_cfg_validate_bot_token($newTok)) {
                throw new RuntimeException('Gaming Bot Token 格式不正确（应为 123456789:AAH...）');
            }
            if ($newTok === '') {
                throw new RuntimeException('请填写 Gaming Bot Token');
            }
            telegram_cfg_write_notify_file($rootDir, $newTok, $newChat, $newBase);
            $msg = 'Gaming Bot Token 已保存到 notify_config.php';
        } elseif ($action === 'save_pg') {
            $curPg = telegram_cfg_read_pg_file($rootDir);
            $newPg = trim((string)($_POST['pg_bot_token'] ?? ''));
            if ($newPg === '') {
                $newPg = $curPg !== '' ? $curPg : trim((string)($PG_TELEGRAM_BOT_TOKEN ?? ''));
            }
            if (!telegram_cfg_validate_bot_token($newPg)) {
                throw new RuntimeException('PG Bot Token 格式不正确');
            }
            if ($newPg === '') {
                throw new RuntimeException('请填写 PG Bot Token');
            }
            telegram_cfg_write_pg_file($rootDir, $newPg);
            $msg = 'PG Bot Token 已保存到 PG_notify_config.php';
        } else {
            throw new RuntimeException('无效操作');
        }
        header('Location: admin_telegram_bot_status.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $msg = $msg ?: 'Token 已保存，下方为最新连线检测结果。';
    // 重新加载配置（本请求已 include config.php，需再读文件）
    $nf = telegram_cfg_read_notify_file($rootDir);
    if ($nf['token'] !== '') {
        $NOTIFY_TELEGRAM_BOT_TOKEN = $nf['token'];
    }
    if ($nf['chat_id'] !== '') {
        $NOTIFY_TELEGRAM_CHAT_ID = $nf['chat_id'];
    }
    if ($nf['base_url'] !== '') {
        $NOTIFY_BASE_URL = $nf['base_url'];
    }
    $pgf = telegram_cfg_read_pg_file($rootDir);
    if ($pgf !== '') {
        $PG_TELEGRAM_BOT_TOKEN = $pgf;
    }
}

$notifyFile = telegram_cfg_read_notify_file($rootDir);
$pgFile = telegram_cfg_read_pg_file($rootDir);
$notifyChatDisplay = $notifyFile['chat_id'] !== '' ? $notifyFile['chat_id'] : trim((string)($NOTIFY_TELEGRAM_CHAT_ID ?? ''));
$notifyBaseDisplay = $notifyFile['base_url'] !== '' ? $notifyFile['base_url'] : trim((string)($NOTIFY_BASE_URL ?? ''));
$notifyFileExists = is_file($rootDir . '/notify_config.php');
$pgFileExists = is_file($rootDir . '/PG_notify_config.php');

function _mask_token(string $t): string
{
    $t = trim($t);
    if ($t === '') {
        return '（未配置）';
    }
    if (strlen($t) <= 12) {
        return substr($t, 0, 4) . '…';
    }
    return substr($t, 0, 8) . '…' . substr($t, -4);
}

function _decode_telegram_json(string|false $raw): ?array
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function _fetch_bot_diag(string $label, string $token): array
{
    $out = [
        'label' => $label,
        'token_masked' => _mask_token($token),
        'configured' => ($token !== ''),
        'getMe' => null,
        'getWebhookInfo' => null,
        'error' => null,
    ];
    if ($token === '') {
        return $out;
    }
    $meRaw = function_exists('telegram_api_get') ? telegram_api_get($token, 'getMe') : false;
    $out['getMe'] = _decode_telegram_json($meRaw);
    if ($out['getMe'] === null) {
        $out['error'] = '无法请求 Telegram（网络/cURL/或 token 无效）';
        return $out;
    }
    $whRaw = telegram_api_get($token, 'getWebhookInfo');
    $out['getWebhookInfo'] = _decode_telegram_json($whRaw);
    if ($out['getWebhookInfo'] === null) {
        $out['error'] = ($out['error'] ?? '') . ' getWebhookInfo 无响应。';
    }
    return $out;
}

$notifyTok = trim((string)($NOTIFY_TELEGRAM_BOT_TOKEN ?? ''));
$pgTok = trim((string)($PG_TELEGRAM_BOT_TOKEN ?? ''));

$rows = [];
$rows[] = _fetch_bot_diag('Gaming / 通知（NOTIFY）', $notifyTok);
$rows[] = _fetch_bot_diag('PG 专用', $pgTok);

$gamingWebhookHint = $notifyBaseDisplay !== ''
    ? rtrim($notifyBaseDisplay, '/') . '/telegram_password_reset_webhook.php'
    : '（先填 NOTIFY_BASE_URL，例如 https://你的域名.com）';

?>
<!doctype html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram Bot 连线状态 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .diag-card { margin-bottom: 18px; padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg, #fff); }
        .diag-card h3 { margin: 0 0 10px; font-size: 1.05rem; }
        .kv { font-size: 14px; line-height: 1.6; }
        .kv code { word-break: break-all; font-size: 12px; }
        .ok { color: #15803d; }
        .bad { color: #b91c1c; }
        .muted { color: var(--muted, #64748b); font-size: 13px; }
        .token-setup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        @media (max-width: 768px) { .token-setup-grid { grid-template-columns: 1fr; } }
        .token-setup-card { padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg, #fff); }
        .token-setup-card h3 { margin: 0 0 12px; font-size: 1rem; }
        .token-setup-card label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
        .token-setup-card .form-hint { margin: 4px 0 0; font-size: 12px; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 900px;">
            <div class="page-header">
                <h2>Telegram Bot 连线状态</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="token-setup-grid">
                <div class="token-setup-card">
                    <h3>Gaming / 通知 Bot Token</h3>
                    <p class="muted" style="margin:0 0 8px;">
                        写入 <code>notify_config.php</code>
                        <?= $notifyFileExists ? '' : '（将新建）' ?>
                        · 当前：<code><?= htmlspecialchars(_mask_token($notifyTok), ENT_QUOTES, 'UTF-8') ?></code>
                    </p>
                    <form method="post">
                        <input type="hidden" name="action" value="save_notify">
                        <label>Bot Token *</label>
                        <input type="password" name="notify_bot_token" class="form-control" autocomplete="off"
                               placeholder="<?= $notifyTok !== '' ? '留空则保留现有 token' : '123456789:AAH...' ?>">
                        <p class="form-hint">从 @BotFather 复制。留空且已有配置时不会覆盖。</p>
                        <label>通知群 Chat ID</label>
                        <input type="text" name="notify_chat_id" class="form-control"
                               value="<?= htmlspecialchars($notifyChatDisplay, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="-100xxxxxxxxxx">
                        <p class="form-hint">待审核流水通知用；可留空。</p>
                        <label>NOTIFY_BASE_URL</label>
                        <input type="url" name="notify_base_url" class="form-control"
                               value="<?= htmlspecialchars($notifyBaseDisplay, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://你的域名.com">
                        <p class="form-hint">Webhook 建议：<code><?= htmlspecialchars($gamingWebhookHint, ENT_QUOTES, 'UTF-8') ?></code></p>
                        <button type="submit" class="btn btn-primary" style="margin-top:12px;">保存 Gaming 配置</button>
                    </form>
                </div>
                <div class="token-setup-card">
                    <h3>PG 专用 Bot Token</h3>
                    <p class="muted" style="margin:0 0 8px;">
                        写入 <code>PG_notify_config.php</code>
                        <?= $pgFileExists ? '' : '（将新建）' ?>
                        · 当前：<code><?= htmlspecialchars(_mask_token($pgTok), ENT_QUOTES, 'UTF-8') ?></code>
                    </p>
                    <form method="post">
                        <input type="hidden" name="action" value="save_pg">
                        <label>PG Bot Token *</label>
                        <input type="password" name="pg_bot_token" class="form-control" autocomplete="off"
                               placeholder="<?= $pgTok !== '' ? '留空则保留现有 token' : '另建一个 PG 专用 Bot' ?>">
                        <p class="form-hint">PG 与 Gaming 请用<strong>不同</strong> Bot。留空且已有配置时不会覆盖。</p>
                        <p class="form-hint" style="margin-top:8px;">Webhook 应指向：<br><code>https://你的域名.com/telegram_pg_webhook.php</code></p>
                        <button type="submit" class="btn btn-primary" style="margin-top:12px;">保存 PG 配置</button>
                    </form>
                </div>
            </div>

            <p class="muted" style="margin-bottom:16px;">
                保存后下方自动检测 <strong>getMe</strong> 与 <strong>getWebhookInfo</strong>。
                PG 自检：<a href="telegram_pg_webhook.php" target="_blank" rel="noopener">telegram_pg_webhook.php</a>
            </p>

            <?php foreach ($rows as $d): ?>
            <div class="diag-card">
                <h3><?= htmlspecialchars($d['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="kv">Token（脱敏）：<code><?= htmlspecialchars($d['token_masked'], ENT_QUOTES, 'UTF-8') ?></code></div>
                <?php if (!$d['configured']): ?>
                    <p class="bad">未配置 token，跳过检测。</p>
                <?php elseif (!empty($d['error'])): ?>
                    <p class="bad"><?= htmlspecialchars($d['error'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <?php
                    $meOk = ($d['getMe']['ok'] ?? false) === true;
                    $meDesc = (string)($d['getMe']['description'] ?? '');
                    $uname = (string)($d['getMe']['result']['username'] ?? '');
                    $bid = (int)($d['getMe']['result']['id'] ?? 0);
                    ?>
                    <p class="<?= $meOk ? 'ok' : 'bad' ?>">getMe：<?= $meOk ? '✓ 有效' : '✗ 失败' ?><?= $uname !== '' ? ' @' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') : '' ?><?= $bid > 0 ? ' <span class="muted">(id ' . $bid . ')</span>' : '' ?></p>
                    <?php if (!$meOk && $meDesc !== ''): ?>
                        <p class="bad" style="font-size:13px;"><?= htmlspecialchars($meDesc, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php
                    $wh = $d['getWebhookInfo'] ?? [];
                    $whOk = ($wh['ok'] ?? false) === true;
                    $res = is_array($wh['result'] ?? null) ? $wh['result'] : [];
                    $url = trim((string)($res['url'] ?? ''));
                    $lastErr = trim((string)($res['last_error_message'] ?? ''));
                    $pending = isset($res['pending_update_count']) ? (int)$res['pending_update_count'] : 0;
                    $maxConn = isset($res['max_connections']) ? (int)$res['max_connections'] : 0;
                    ?>
                    <p class="<?= $whOk ? 'ok' : 'bad' ?>">getWebhookInfo：<?= $whOk ? '✓ 已请求' : '✗ 异常' ?></p>
                    <div class="kv"><strong>Webhook URL</strong>：<br><code><?= $url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '<span class="bad">（空：未 setWebhook）</span>' ?></code></div>
                    <?php if ($pending > 0): ?>
                        <div class="kv muted">待投递更新数：<?= $pending ?></div>
                    <?php endif; ?>
                    <?php if ($maxConn > 0): ?>
                        <div class="kv muted">max_connections：<?= $maxConn ?></div>
                    <?php endif; ?>
                    <?php if ($lastErr !== ''): ?>
                        <div class="kv bad" style="margin-top:8px;"><strong>last_error_message</strong>：<br><?= htmlspecialchars($lastErr, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <p class="muted">保存后若 Webhook 仍为空，需在 Telegram 设置 Webhook URL；群内 <code>+</code> 指令需 @BotFather <code>/setprivacy</code> → Disable。</p>
        </div>
    </main>
</div>
</body>
</html>
