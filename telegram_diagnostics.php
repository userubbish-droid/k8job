<?php
require 'config.php';
require 'auth.php';
require_admin();

$configLoaded = defined('NOTIFY_CONFIG_LOADED') && NOTIFY_CONFIG_LOADED;
$hasToken = !empty($NOTIFY_TELEGRAM_BOT_TOKEN);
$hasChatId = !empty($NOTIFY_TELEGRAM_CHAT_ID);
$baseUrl = trim((string)($NOTIFY_BASE_URL ?? ''));
$expectedWebhook = $baseUrl !== '' ? (rtrim($baseUrl, '/') . '/telegram_password_reset_webhook.php') : '';
$notifyPath = __DIR__ . '/notify_config.php';
$notifyExists = is_file($notifyPath);
$notifySize = $notifyExists ? (int)@filesize($notifyPath) : 0;
$tokenLen = mb_strlen((string)($NOTIFY_TELEGRAM_BOT_TOKEN ?? ''), 'UTF-8');
$chatLen = mb_strlen((string)($NOTIFY_TELEGRAM_CHAT_ID ?? ''), 'UTF-8');

$webhookInfoRaw = '';
$webhookInfoErr = '';
$sendTestRaw = '';
$sendTestErr = '';

function tg_http_get(string $url): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return false;
        }
        return is_string($out) ? $out : false;
    }
    return @file_get_contents($url);
}

if ($hasToken) {
    $u = 'https://api.telegram.org/bot' . $NOTIFY_TELEGRAM_BOT_TOKEN . '/getWebhookInfo';
    $webhookInfoRaw = (string)tg_http_get($u);
    if ($webhookInfoRaw === '') {
        $webhookInfoErr = 'Failed to request getWebhookInfo.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    if (!$hasToken || !$hasChatId) {
        $sendTestErr = 'Token or Chat ID is empty.';
    } else {
        $msg = '🧪 Telegram diagnostics test at ' . date('Y-m-d H:i:s');
        $u = 'https://api.telegram.org/bot' . $NOTIFY_TELEGRAM_BOT_TOKEN . '/sendMessage';
        $post = http_build_query([
            'chat_id' => $NOTIFY_TELEGRAM_CHAT_ID,
            'text' => $msg,
            'disable_web_page_preview' => true,
        ]);
        if (function_exists('curl_init')) {
            $ch = curl_init($u);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $out = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err !== '') {
                $sendTestErr = $err;
            } else {
                $sendTestRaw = (string)$out;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $post,
                    'timeout' => 12,
                ],
            ]);
            $out = @file_get_contents($u, false, $ctx);
            if ($out === false) {
                $sendTestErr = 'sendMessage request failed.';
            } else {
                $sendTestRaw = (string)$out;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram Diagnostics</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php $sidebar_current = ''; include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 900px;">
            <div class="page-header">
                <h2>Telegram Diagnostics</h2>
                <p class="breadcrumb"><a href="dashboard.php">Home</a></p>
            </div>
            <div class="card">
                <p><strong>notify_config loaded:</strong> <?= $configLoaded ? 'YES' : 'NO' ?></p>
                <p><strong>notify_config path:</strong> <code><?= htmlspecialchars($notifyPath, ENT_QUOTES, 'UTF-8') ?></code></p>
                <p><strong>notify_config exists:</strong> <?= $notifyExists ? 'YES' : 'NO' ?><?= $notifyExists ? (' (size: ' . $notifySize . ' bytes)') : '' ?></p>
                <p><strong>BOT token present:</strong> <?= $hasToken ? 'YES' : 'NO' ?></p>
                <p><strong>BOT token length:</strong> <?= (int)$tokenLen ?></p>
                <p><strong>Chat ID present:</strong> <?= $hasChatId ? 'YES' : 'NO' ?></p>
                <p><strong>Chat ID length:</strong> <?= (int)$chatLen ?></p>
                <p><strong>NOTIFY_BASE_URL:</strong> <?= htmlspecialchars($baseUrl !== '' ? $baseUrl : '(empty)', ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Expected webhook:</strong> <?= htmlspecialchars($expectedWebhook !== '' ? $expectedWebhook : '(cannot build)', ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="card" style="margin-top:14px;">
                <h3 style="margin-top:0;">getWebhookInfo</h3>
                <?php if ($webhookInfoErr !== ''): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($webhookInfoErr, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <pre style="white-space:pre-wrap; background:#f8fafc; padding:10px; border-radius:8px;"><?= htmlspecialchars($webhookInfoRaw !== '' ? $webhookInfoRaw : '(no response)', ENT_QUOTES, 'UTF-8') ?></pre>
            </div>

            <div class="card" style="margin-top:14px;">
                <h3 style="margin-top:0;">Send test message</h3>
                <form method="post">
                    <button class="btn btn-primary" type="submit" name="send_test" value="1">Send Test</button>
                </form>
                <?php if ($sendTestErr !== ''): ?>
                    <div class="alert alert-error" style="margin-top:10px;"><?= htmlspecialchars($sendTestErr, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($sendTestRaw !== ''): ?>
                    <pre style="white-space:pre-wrap; background:#f8fafc; padding:10px; border-radius:8px; margin-top:10px;"><?= htmlspecialchars($sendTestRaw, ENT_QUOTES, 'UTF-8') ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
