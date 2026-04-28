<?php
/**
 * 待审核通知：有流水需审核时发送 Telegram 提醒（免费）
 * 使用前请在 notify_config.php 中配置 TELEGRAM_BOT_TOKEN 与 TELEGRAM_CHAT_ID
 */

/**
 * @param int|null $message_thread_id 论坛群话题 ID；非话题群勿传。与触发更新的 message.message_thread_id 一致时，回复会出现在同一话题。
 * @param array|null $reply_markup Telegram inline_keyboard 等，见 Bot API sendMessage
 */
function send_telegram_message($bot_token, $chat_id, $text, ?int $message_thread_id = null, ?array $reply_markup = null, ?string $parse_mode = null) {
    $url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
    $payload = [
        'chat_id' => $chat_id,
        'text'    => $text,
        'disable_web_page_preview' => true,
    ];
    if ($parse_mode !== null && $parse_mode !== '') {
        $payload['parse_mode'] = $parse_mode;
    }
    if ($message_thread_id !== null && $message_thread_id > 0) {
        $payload['message_thread_id'] = $message_thread_id;
    }
    if ($reply_markup !== null && is_array($reply_markup) && $reply_markup !== []) {
        $payload['reply_markup'] = json_encode($reply_markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
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

function telegram_api_post($bot_token, string $method, array $payload) {
    $url = 'https://api.telegram.org/bot' . $bot_token . '/' . $method;
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

/** GET 调用 Telegram Bot API（如 getWebhookInfo、getMe），返回 JSON 字符串或 false */
function telegram_api_get(string $bot_token, string $method): string|false
{
    $method = trim($method, '/');
    if ($bot_token === '' || $method === '') {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . $bot_token . '/' . $method;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return $err === '' && is_string($out) ? $out : false;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 15,
        ],
    ]);
    $out = @file_get_contents($url, false, $ctx);
    return is_string($out) && $out !== '' ? $out : false;
}

function send_telegram_message_with_keyboard($bot_token, $chat_id, $text, array $inline_keyboard) {
    return telegram_api_post($bot_token, 'sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard], JSON_UNESCAPED_UNICODE),
    ]);
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

function send_pending_customer_notify(PDO $pdo, int $company_id = 0) {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;

    if ($company_id <= 0 && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    if ($company_id <= 0) {
        return;
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ? AND status = 'pending'");
        $st->execute([$company_id]);
        $cnt = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($cnt <= 0) {
        return;
    }

    $text = "🔔 有 {$cnt} 位客户待审核。";
    if (!empty($NOTIFY_BASE_URL)) {
        $text .= "\n" . rtrim($NOTIFY_BASE_URL, '/') . '/admin_customer_approvals.php';
    } else {
        $text .= "\n请登录后台处理。";
    }

    if (!empty($NOTIFY_TELEGRAM_BOT_TOKEN) && !empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
    }
}

/**
 * Member 新建客户时，银行资料（bank_details）与本公司已有客户完全相同：额外发 Telegram 提醒管理员。
 */
function send_member_duplicate_bank_customer_notify(
    PDO $pdo,
    int $company_id,
    string $member_username,
    string $new_customer_code,
    string $existing_customer_code,
    string $bank_details,
    array $existing_customer_codes = [],
    array $matched_prefixes7 = []
): void {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;
    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        return;
    }
    if ($company_id <= 0 || $bank_details === '') {
        return;
    }

    $bank_show = $bank_details;
    if (function_exists('mb_strlen') && mb_strlen($bank_show, 'UTF-8') > 500) {
        $bank_show = mb_substr($bank_show, 0, 500, 'UTF-8') . '…';
    } elseif (strlen($bank_show) > 500) {
        $bank_show = substr($bank_show, 0, 500) . '…';
    }

    $co = '';
    try {
        $st = $pdo->prepare("SELECT code FROM companies WHERE id = ? LIMIT 1");
        $st->execute([$company_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $co = trim((string)($r['code'] ?? ''));
        }
    } catch (Throwable $e) {
    }

    $who = $member_username !== '' ? $member_username : '(member)';
    $text = "⚠️ 会员新建客户：银行号码前 7 位重复\n";
    if ($co !== '') {
        $text .= "公司：{$co}\n";
    }
    $text .= "提交会员：{$who}\n";
    $text .= "新客户代码：" . trim($new_customer_code) . "\n";
    $codes_line = trim($existing_customer_code);
    if (!empty($existing_customer_codes)) {
        $existing_customer_codes = array_values(array_unique(array_map('strval', $existing_customer_codes)));
        $codes_line = implode(',', array_filter($existing_customer_codes, function($x){ return trim((string)$x) !== ''; }));
    }
    if ($codes_line !== '') {
        $text .= "匹配客户代码：{$codes_line}\n";
    }
    if (!empty($matched_prefixes7)) {
        $matched_prefixes7 = array_values(array_unique(array_map('strval', $matched_prefixes7)));
        $pref_line = implode(',', array_filter($matched_prefixes7, function($x){ return trim((string)$x) !== ''; }));
        if ($pref_line !== '') {
            $text .= "匹配前7位：{$pref_line}\n";
        }
    }
    $text .= "银行资料：{$bank_show}";
    if (!empty($NOTIFY_BASE_URL)) {
        $text .= "\n" . rtrim($NOTIFY_BASE_URL, '/') . '/admin_customer_approvals.php';
    } else {
        $text .= "\n请登录后台处理客户审核。";
    }

    send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
}

/**
 * Member 新建客户时，电话号码与本公司已有客户完全相同：额外发 Telegram 提醒管理员。
 */
function send_member_duplicate_phone_customer_notify(
    PDO $pdo,
    int $company_id,
    string $member_username,
    string $new_customer_code,
    string $existing_customer_code,
    string $phone,
    array $existing_customer_codes = []
): void {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;
    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        return;
    }
    if ($company_id <= 0 || trim($phone) === '') {
        return;
    }

    $co = '';
    try {
        $st = $pdo->prepare("SELECT code FROM companies WHERE id = ? LIMIT 1");
        $st->execute([$company_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $co = trim((string)($r['code'] ?? ''));
        }
    } catch (Throwable $e) {
    }

    $who = $member_username !== '' ? $member_username : '(member)';
    $text = "⚠️ 会员新建客户：电话号码重复\n";
    if ($co !== '') {
        $text .= "公司：{$co}\n";
    }
    $text .= "提交会员：{$who}\n";
    $text .= "新客户代码：" . trim($new_customer_code) . "\n";
    $codes_line = trim($existing_customer_code);
    if (!empty($existing_customer_codes)) {
        $existing_customer_codes = array_values(array_unique(array_map('strval', $existing_customer_codes)));
        $codes_line = implode(',', array_filter($existing_customer_codes, function($x){ return trim((string)$x) !== ''; }));
    }
    if ($codes_line !== '') {
        $text .= "匹配客户代码：{$codes_line}\n";
    }
    $text .= "电话号码：" . trim($phone);

    if (!empty($NOTIFY_BASE_URL)) {
        $text .= "\n" . rtrim($NOTIFY_BASE_URL, '/') . '/admin_customer_approvals.php';
    } else {
        $text .= "\n请登录后台处理客户审核。";
    }

    send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
}

function send_pending_txn_edit_request_notify(PDO $pdo, int $company_id = 0, int $request_id = 0) {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $NOTIFY_BASE_URL;
    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) {
        return;
    }
    if ($company_id <= 0 && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    if ($company_id <= 0 || $request_id <= 0) {
        return;
    }
    $who = '';
    $txId = 0;
    $code = '';
    try {
        $st = $pdo->prepare("SELECT r.transaction_id, COALESCE(u.username, '') AS uname, COALESCE(r.code, '') AS code
            FROM transaction_edit_requests r
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.id = ? LIMIT 1");
        $st->execute([$request_id]);
        $rw = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rw) {
            $who = trim((string)($rw['uname'] ?? ''));
            $txId = (int)($rw['transaction_id'] ?? 0);
            $code = trim((string)($rw['code'] ?? ''));
        }
    } catch (Throwable $e) {
    }

    $text = "🔔 有 1 笔「流水修改」待批准。";
    if ($who !== '') {
        $text .= "\n提交人：{$who}";
    }
    $text .= "\n请求 #{$request_id}";
    if ($txId > 0) {
        $text .= "\n原流水：#{$txId}";
    }
    if ($code !== '') {
        $text .= "\n客户：{$code}";
    }
    if (!empty($NOTIFY_BASE_URL)) {
        $text .= "\n" . rtrim($NOTIFY_BASE_URL, '/') . "/admin_txn_edit_approvals.php";
    } else {
        $text .= "\n请登录后台处理。";
    }
    $kb = [
        [
            ['text' => '✅ Approve', 'callback_data' => "txnedit|approve|{$request_id}"],
            ['text' => '❌ Reject', 'callback_data' => "txnedit|reject|{$request_id}"],
        ],
    ];
    send_telegram_message_with_keyboard($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text, $kb);
}
