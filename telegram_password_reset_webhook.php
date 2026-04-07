<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/notify.php';

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) {
    echo json_encode(['ok' => true]);
    exit;
}

$input = file_get_contents('php://input');
$update = json_decode((string)$input, true);
if (!is_array($update) || !isset($update['callback_query'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$cq = $update['callback_query'];
$cbId = (string)($cq['id'] ?? '');
$data = (string)($cq['data'] ?? '');
$msg = $cq['message'] ?? [];
$chatId = trim((string)($msg['chat']['id'] ?? ''));
$messageId = (int)($msg['message_id'] ?? 0);
$from = $cq['from'] ?? [];
$approver = trim((string)($from['username'] ?? ''));
if ($approver === '') {
    $approver = (string)($from['id'] ?? 'telegram');
}

$expectedChat = trim((string)$NOTIFY_TELEGRAM_CHAT_ID);
if ($chatId !== $expectedChat || strpos($data, 'pwreset|') !== 0) {
    if ($cbId !== '') {
        telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => 'Unauthorized',
            'show_alert' => false,
        ]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$parts = explode('|', $data);
$action = $parts[1] ?? '';
$rid = (int)($parts[2] ?? 0);
if (!in_array($action, ['approve', 'reject'], true) || $rid <= 0) {
    if ($cbId !== '') {
        telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => 'Invalid request',
            'show_alert' => false,
        ]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

try {
    $st = $pdo->prepare("SELECT id, user_id, username, status FROM password_reset_requests WHERE id = ? LIMIT 1");
    $st->execute([$rid]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException('Request not found');
    }
    if (($req['status'] ?? '') !== 'pending') {
        $doneText = "请求 #{$rid} 已处理（状态：" . (string)$req['status'] . "）";
        if ($cbId !== '') {
            telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => $doneText,
                'show_alert' => false,
            ]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    $uname = (string)$req['username'];
    $uid = (int)$req['user_id'];
    $now = date('Y-m-d H:i:s');
    $note = $action === 'approve' ? 'approved_via_telegram' : 'rejected_via_telegram';
    $text = '';

    if ($action === 'approve') {
        $temp = '12345';
        $hash = password_hash($temp, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $uid]);
        $pdo->prepare("UPDATE password_reset_requests SET status='approved', resolved_at=?, resolved_by_tg=?, resolved_note=?, temp_password=? WHERE id=?")
            ->execute([$now, $approver, $note, $temp, $rid]);
        $text = "✅ 已批准密码重置\n账号：{$uname}\n临时密码：{$temp}\n处理人：{$approver}\n时间：{$now}";
    } else {
        $pdo->prepare("UPDATE password_reset_requests SET status='rejected', resolved_at=?, resolved_by_tg=?, resolved_note=? WHERE id=?")
            ->execute([$now, $approver, $note, $rid]);
        $text = "❌ 已拒绝密码重置\n账号：{$uname}\n处理人：{$approver}\n时间：{$now}";
    }

    if ($messageId > 0) {
        telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ]);
    }
    send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $chatId, $text);
    if ($cbId !== '') {
        telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => ($action === 'approve' ? 'Approved' : 'Rejected'),
            'show_alert' => false,
        ]);
    }
} catch (Throwable $e) {
    if ($cbId !== '') {
        telegram_api_post($NOTIFY_TELEGRAM_BOT_TOKEN, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => 'Error: ' . $e->getMessage(),
            'show_alert' => true,
        ]);
    }
}

echo json_encode(['ok' => true]);
