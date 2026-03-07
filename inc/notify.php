<?php
/**
 * 待审核通知：有流水需审核时发送 Telegram 提醒（免费）
 * 使用前请在 notify_config.php 中配置 TELEGRAM_BOT_TOKEN 与 TELEGRAM_CHAT_ID
 */

function send_pending_approval_notify(PDO $pdo) {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;

    try {
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($cnt <= 0) return;

    $text = "🔔 有 {$cnt} 笔流水待审核。";
    if (!empty($NOTIFY_BASE_URL)) {
        $text .= "\n" . rtrim($NOTIFY_BASE_URL, '/') . '/admin_approvals.php';
    } else {
        $text .= "\n请登录后台处理。";
    }

    // Telegram
    if (!empty($NOTIFY_TELEGRAM_BOT_TOKEN) && !empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        $url = 'https://api.telegram.org/bot' . $NOTIFY_TELEGRAM_BOT_TOKEN . '/sendMessage';
        $payload = [
            'chat_id' => $NOTIFY_TELEGRAM_CHAT_ID,
            'text'    => $text,
            'disable_web_page_preview' => true,
        ];
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($payload),
                'timeout' => 5,
            ],
        ]);
        @file_get_contents($url, false, $ctx);
    }
}
