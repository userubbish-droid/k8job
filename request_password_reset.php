<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/notify.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
i18n_bootstrap();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = mb_strtoupper(trim((string)($_POST['user'] ?? '')), 'UTF-8');
$company_code_in = mb_strtoupper(trim((string)($_POST['company_code'] ?? '')), 'UTF-8');
$company_code = $company_code_in === '' ? '' : mb_strtolower($company_code_in, 'UTF-8');

if ($user === '' || $company_code === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        echo json_encode(['ok' => false, 'error' => 'telegram_not_configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stC = $pdo->prepare("SELECT id FROM companies WHERE is_active = 1 AND LOWER(TRIM(code)) = ? LIMIT 1");
    $stC->execute([$company_code]);
    $cid = (int)$stC->fetchColumn();
    if ($cid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'company_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stU = $pdo->prepare("SELECT id, username, role, is_active FROM users WHERE LOWER(TRIM(username)) = LOWER(?) AND company_id = ? LIMIT 1");
    $stU->execute([$user, $cid]);
    $u = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)($u['is_active'] ?? 0) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $uid = (int)$u['id'];
    $uname = (string)$u['username'];

    $stDup = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stDup->execute([$uid]);
    $pendingId = (int)$stDup->fetchColumn();
    if ($pendingId > 0) {
        echo json_encode(['ok' => true, 'message' => 'already_pending', 'notice' => __('login_reset_pending')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO password_reset_requests (company_id, user_id, username, status, requested_at) VALUES (?, ?, ?, 'pending', ?)");
    $ins->execute([$cid, $uid, $uname, $now]);
    $rid = (int)$pdo->lastInsertId();

    $text = "🔐 密码重置申请\n公司：{$company_code}\n账号：{$uname}\n申请时间：{$now}\n请求ID：#{$rid}\n请在此消息下方直接批准或拒绝。";
    $inline = [[
        ['text' => '✅ 批准', 'callback_data' => 'pwreset|approve|' . $rid],
        ['text' => '❌ 拒绝', 'callback_data' => 'pwreset|reject|' . $rid],
    ]];

    $sendResult = send_telegram_message_with_keyboard($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text, $inline);
    if ($sendResult === false || strpos((string)$sendResult, '"ok":true') === false) {
        $pdo->prepare("DELETE FROM password_reset_requests WHERE id = ?")->execute([$rid]);
        echo json_encode(['ok' => false, 'error' => 'telegram_send_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'sent', 'notice' => __('login_reset_sent')], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
