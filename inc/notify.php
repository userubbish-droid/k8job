<?php
/**
 * 待审核通知：有流水需审核时发送 Telegram 提醒（免费）
 * 使用前请在 notify_config.php 中配置 TELEGRAM_BOT_TOKEN 与 TELEGRAM_CHAT_ID
 */

function send_telegram_message($bot_token, $chat_id, $text) {
    $url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
    $payload = [
        'chat_id' => $chat_id,
        'text'    => $text,
        'disable_web_page_preview' => true,
    ];
    $post = http_build_query($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return $err === '' ? $out : false;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $post,
            'timeout' => 10,
        ],
    ]);
    return @file_get_contents($url, false, $ctx);
}

function send_pending_approval_notify(PDO $pdo, int $company_id = 0) {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;

    if ($company_id <= 0 && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    if ($company_id <= 0) {
        return;
    }

    try {
        $has_deleted_at = true;
        try { $pdo->query("SELECT deleted_at FROM transactions LIMIT 0"); } catch (Throwable $e) { $has_deleted_at = false; }
        $sql = "SELECT COUNT(*) FROM transactions WHERE company_id = ? AND status = 'pending'" . ($has_deleted_at ? " AND deleted_at IS NULL" : "");
        $st = $pdo->prepare($sql);
        $st->execute([$company_id]);
        $cnt = (int) $st->fetchColumn();
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

    if (!empty($NOTIFY_TELEGRAM_BOT_TOKEN) && !empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
    }
}
