<?php
require 'config.php';
require 'auth.php';
require_admin();
require_once __DIR__ . '/inc/notify.php';

$msg = '';
$err = '';
$base = trim((string)($NOTIFY_BASE_URL ?? ''));
$webhookUrl = '';
if ($base !== '') {
    $webhookUrl = rtrim($base, '/') . '/telegram_password_reset_webhook.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN)) {
        $err = '未配置 TELEGRAM BOT TOKEN。';
    } elseif ($webhookUrl === '') {
        $err = '未配置 NOTIFY_BASE_URL，无法自动设置 webhook。';
    } else {
        $res = telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'setWebhook', ['url' => $webhookUrl]);
        if ($res === false) {
            $err = '设置失败：请求 Telegram API 失败。';
        } else {
            $msg = 'Webhook 设置请求已发送。返回：' . $res;
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>设置 Telegram Webhook</title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="dashboard-layout">
    <?php $sidebar_current = ''; include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width:720px;">
            <div class="page-header">
                <h2>设置 Telegram Webhook</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>
            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <div class="card">
                <p>当前 webhook URL：</p>
                <p><code><?= htmlspecialchars($webhookUrl !== '' ? $webhookUrl : '(NOTIFY_BASE_URL 未设置)', ENT_QUOTES, 'UTF-8') ?></code></p>
                <form method="post">
                    <button class="btn btn-primary" type="submit">一键设置 webhook</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
