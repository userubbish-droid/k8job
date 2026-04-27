<?php
/**
 * Telegram Bot 连线诊断：调用官方 getWebhookInfo / getMe（仅 Boss / BigBoss）
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();
require_once __DIR__ . '/inc/notify.php';

$sidebar_current = 'admin_telegram_bot_status';

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
        pre.raw { max-height: 220px; overflow: auto; font-size: 11px; background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; margin-top: 10px; }
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
            <p class="muted" style="margin-bottom:16px;">
                本页通过 Telegram 官方接口读取 <strong>getMe</strong>（机器人是否有效）与 <strong>getWebhookInfo</strong>（Webhook URL 与最近错误）。<br>
                Gaming 机器人 token 来自 <code>notify_config.php</code> 的 <code>$NOTIFY_TELEGRAM_BOT_TOKEN</code>；PG 来自 <code>$PG_TELEGRAM_BOT_TOKEN</code>。<br>
                PG 自检亦可打开：<a href="telegram_pg_webhook.php" target="_blank" rel="noopener">telegram_pg_webhook.php</a>（GET，仅看 token 是否读到）。
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
                    <div class="kv"><strong>Webhook URL</strong>：<br><code><?= $url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '<span class="bad">（空：未 setWebhook，Telegram 不会 POST 到你的服务器）</span>' ?></code></div>
                    <?php if ($pending > 0): ?>
                        <div class="kv muted">待投递更新数：<?= $pending ?>（若长期很大，多半是 Webhook 连不上）</div>
                    <?php endif; ?>
                    <?php if ($maxConn > 0): ?>
                        <div class="kv muted">max_connections：<?= $maxConn ?></div>
                    <?php endif; ?>
                    <?php if ($lastErr !== ''): ?>
                        <div class="kv bad" style="margin-top:8px;"><strong>last_error_message</strong>：<br><?= htmlspecialchars($lastErr, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($d['label'] === 'Gaming / 通知（NOTIFY）' && $url !== ''): ?>
                        <p class="muted" style="margin-top:10px;">Gaming 群聊快捷记账与密码重置等，通常与同一 Webhook URL 共用（由你 setWebhook 时填写）。</p>
                    <?php elseif (strpos($d['label'], 'PG') !== false && $url !== ''): ?>
                        <p class="muted" style="margin-top:10px;">PG 应指向 <code>…/telegram_pg_webhook.php</code>，勿与 <code>telegram_password_reset_webhook.php</code> 混用。</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <p class="muted">若 URL 正确仍无反应：查服务器 HTTPS 证书、防火墙；群内 <code>+</code> 指令需 @BotFather 对该 bot <code>/setprivacy</code> → Disable。</p>
        </div>
    </main>
</div>
</body>
</html>
