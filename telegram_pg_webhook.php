<?php
/**
 * PG 专用 Telegram Bot Webhook 入口（与 gaming 的 telegram_password_reset_webhook / telegram_quick_txn_webhook 完全分离）
 *
 * === 如何添加 PG Bot ===
 *
 * 1) Telegram：@BotFather → /newbot → 复制新 Bot 的 HTTP API Token（勿与 NOTIFY 里 gaming 机器人共用）。
 *
 * 2) 配置：复制 PG_notify_config.php.example 为 PG_notify_config.php，填写：
 *     $PG_TELEGRAM_BOT_TOKEN = '你的PG_bot_token'（与 notify_config.php 同级；多域名建议只传此文件）
 *
 * 3) 数据库：
 *     - 在主库（catalog）执行 migrate_telegram_quick_txn_pg_tables.sql → telegram_quick_txn_config_pg / telegram_quick_txn_log_pg
 *     - 在 PG 业务库执行 migrate_pg_transactions.sql、migrate_pg_banks_and_customers.sql（与分库策略一致）
 *
 * 4) 注册 Webhook（必须 HTTPS，与现有网站证书一致）：
 *     浏览器或 curl：
 *     https://api.telegram.org/bot<PG_TOKEN>/setWebhook?url=https://你的域名/telegram_pg_webhook.php
 *     查看状态：getWebhookInfo
 *
 * 5) 使用：把 PG Bot 拉进群 → 群内发 /setup 或 /setup <company_id|公司代码>
 *     绑定公司必须是 companies.business_kind = pg。
 *     绑定后在当前话题发：/customer 代号、/bank 渠道（论坛各话题可分别设）；极简 +金额 用上述默认；完整格式 +100 C001 HLB 备注（无产品段，渠道以消息为准，见 telegram_pg_quick_txn_webhook.php）。
 *     指令与 gaming 类似：+100 CODE BANK CHAN 备注、undo、cancel、白名单 /addadmin 等。
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/notify.php';

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

// $PG_TELEGRAM_BOT_TOKEN 由 config.php 从 notify_config.php / PG_notify_config.php / 环境变量 加载
$token = '';
if (isset($PG_TELEGRAM_BOT_TOKEN)) {
    $token = trim((string)$PG_TELEGRAM_BOT_TOKEN);
}

// 浏览器 GET：自检是否读到 token、PHP 是否正常（勿公开传播此 URL）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $notifyPath = __DIR__ . '/notify_config.php';
    $pgNotifyPath = __DIR__ . '/PG_notify_config.php';
    $probeG = getenv('PG_TELEGRAM_BOT_TOKEN');
    $probe = [
        'getenv' => is_string($probeG) && trim($probeG) !== '',
        'server' => isset($_SERVER['PG_TELEGRAM_BOT_TOKEN']) && is_string($_SERVER['PG_TELEGRAM_BOT_TOKEN'])
            && trim($_SERVER['PG_TELEGRAM_BOT_TOKEN']) !== '',
        'env' => isset($_ENV['PG_TELEGRAM_BOT_TOKEN']) && is_string($_ENV['PG_TELEGRAM_BOT_TOKEN'])
            && trim($_ENV['PG_TELEGRAM_BOT_TOKEN']) !== '',
    ];
    $rawNotify = is_readable($notifyPath) ? @file_get_contents($notifyPath) : false;
    $hasPgVarLine = is_string($rawNotify) && preg_match('/\$PG_TELEGRAM_BOT_TOKEN\s*=/', $rawNotify) > 0;
    $pgRhsQuoted = is_string($rawNotify) && preg_match(
        '/\$PG_TELEGRAM_BOT_TOKEN\s*=\s*([\'"])\s*\1\s*;/',
        $rawNotify
    ) > 0;
    $bytes = (is_file($notifyPath) && is_readable($notifyPath)) ? @filesize($notifyPath) : null;
    $nameSubstrPresent = is_string($rawNotify) && stripos($rawNotify, 'PG_TELEGRAM_BOT_TOKEN') !== false;
    $likelyOldFileNoPg = is_int($bytes) && $bytes > 0 && $bytes < 480 && !$hasPgVarLine;
    $rawPgNotify = is_readable($pgNotifyPath) ? @file_get_contents($pgNotifyPath) : false;
    $pgFileHasLine = is_string($rawPgNotify) && preg_match('/\$PG_TELEGRAM_BOT_TOKEN\s*=/', $rawPgNotify) > 0;
    $pgFileRhsEmpty = is_string($rawPgNotify) && preg_match(
        '/\$PG_TELEGRAM_BOT_TOKEN\s*=\s*([\'"])\s*\1\s*;/',
        $rawPgNotify
    ) > 0;
    $pgFileBytes = (is_file($pgNotifyPath) && is_readable($pgNotifyPath)) ? @filesize($pgNotifyPath) : null;
    $out = [
        'pg_webhook' => 'ok',
        'webhook_script_dir' => realpath(__DIR__) ?: __DIR__,
        'pg_notify_config_file_exists' => is_file($pgNotifyPath),
        'pg_notify_config_realpath' => is_file($pgNotifyPath) ? realpath($pgNotifyPath) : null,
        'pg_notify_config_bytes' => $pgFileBytes,
        'pg_notify_config_declares_pg_token' => $pgFileHasLine,
        'pg_notify_config_assignment_empty_string' => $pgFileRhsEmpty,
        'notify_config_file_exists' => is_file($notifyPath),
        'notify_config_readable' => is_file($notifyPath) && is_readable($notifyPath),
        'notify_config_bytes' => $bytes,
        'notify_config_realpath' => is_file($notifyPath) ? realpath($notifyPath) : null,
        'notify_config_declares_pg_token' => $hasPgVarLine,
        'notify_config_pg_assignment_empty_string' => $pgRhsQuoted,
        'notify_config_file_contains_pg_token_text' => $nameSubstrPresent,
        'likely_notify_is_old_copy_without_pg_line' => $likelyOldFileNoPg,
        'token_configured' => ($token !== ''),
        'pg_token_length_on_this_server' => strlen($token),
        'probe_env_nonempty' => $probe,
        'hint' => 'POST updates come from Telegram; setWebhook must point here. If group +100 has no reply, use @BotFather /setprivacy -> Disable.',
    ];
    if ($token === '') {
        $out['fix_if_token_false'] = '本服务器仍读不到 PG token。推荐：将本机 PG_notify_config.php 上传到 webhook_script_dir（与 telegram_pg_webhook.php 同级），并上传已包含 include_once PG_notify_config.php 的 config.php。备选：在 notify_config.php 顶层写 $PG_TELEGRAM_BOT_TOKEN；或在 Hostinger 环境变量设 PG_TELEGRAM_BOT_TOKEN。若 pg_notify_config_file_exists 为 false，请先上传 PG_notify_config.php。';
    } else {
        $out['summary'] = 'PG token 已加载。Gaming 仍只使用 notify_config.php 里的 $NOTIFY_TELEGRAM_*；notify 内无 $PG_TELEGRAM_BOT_TOKEN 行属正常（由 PG_notify_config.php 提供）。';
    }
    if ($token === '' && ($probe['getenv'] || $probe['server'] || $probe['env'])) {
        $out['fix_if_probe_true_but_token_empty'] = '面板里已配置 PG_TELEGRAM_BOT_TOKEN，但 PHP 未写入 $PG_TELEGRAM_BOT_TOKEN：请把当前仓库里的 config.php 上传到与 telegram_pg_webhook.php 同目录并覆盖（需含对 $_SERVER/$_ENV 的读取）。';
    }
    if ($likelyOldFileNoPg && !is_file($pgNotifyPath)) {
        $out['fix_likely_old_notify'] = 'notify_config.php 仍为「仅 NOTIFY」小文件且本目录尚无 PG_notify_config.php：请上传 PG_notify_config.php + 已 include 该文件的 config.php 到本 public_html。';
    }
    if ($token === '' && is_file($pgNotifyPath) && !$pgFileHasLine) {
        $out['fix_pg_notify_syntax'] = 'PG_notify_config.php 已存在但未检测到合法行「$PG_TELEGRAM_BOT_TOKEN = \'…\';」，请对照本机 PG_notify_config.php.example 修正。';
    }
    if ($token === '' && is_file($pgNotifyPath) && $pgFileRhsEmpty) {
        $out['fix_pg_notify_token_empty_string'] = 'PG_notify_config.php 里 $PG_TELEGRAM_BOT_TOKEN 仍为空字符串，请填入完整 token 并保存。';
    }
    if (is_file($pgNotifyPath) && $pgFileHasLine && !$pgFileRhsEmpty && $token === '') {
        $out['fix_config_not_including_pg_file'] = 'PG_notify_config.php 内容看似正常但 PHP 未读到 token：请确认已上传最新 config.php（含 include_once PG_notify_config.php），且与本脚本同目录。';
    }
    if ($token === '' && $nameSubstrPresent && !$hasPgVarLine) {
        $out['fix_pg_name_text_but_invalid_line'] = 'notify_config.php 里出现了 PG_TELEGRAM_BOT_TOKEN 文本，但不是合法 PHP 赋值行：检查全角＄或拼写。更推荐改用 PG_notify_config.php 单独放 token。';
    }
    if ($token === '' && !is_file($pgNotifyPath)) {
        $out['fix_missing_pg_notify_file'] = '当前目录没有 PG_notify_config.php。请把本机项目里的 PG_notify_config.php 上传到 webhook_script_dir 所示的 public_html（与 telegram_pg_webhook.php 同级），并上传已 include 该文件的 config.php。';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($token === '') {
    echo json_encode(['ok' => true]);
    exit;
}

$input = file_get_contents('php://input');
$update = json_decode((string)$input, true);
if (!is_array($update)) {
    echo json_encode(['ok' => true]);
    exit;
}

// 群消息在 message；少数客户端为 edited_message
$msgBlock = $update['message'] ?? ($update['edited_message'] ?? null);
if (!is_array($msgBlock)) {
    echo json_encode(['ok' => true]);
    exit;
}
$update['message'] = $msgBlock;

try {
    require_once __DIR__ . '/telegram_pg_quick_txn_webhook.php';
    if (function_exists('telegram_pg_quick_txn_handle_update')) {
        telegram_pg_quick_txn_handle_update($pdo, $update, $token);
    }
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[telegram_pg_webhook] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}

echo json_encode(['ok' => true]);
