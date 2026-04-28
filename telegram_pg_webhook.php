<?php
/**
 * PG 专用 Telegram Bot Webhook 入口（与 gaming 的 telegram_password_reset_webhook / telegram_quick_txn_webhook 完全分离）
 *
 * === 如何添加 PG Bot ===
 *
 * 1) Telegram：@BotFather → /newbot → 复制新 Bot 的 HTTP API Token（勿与 NOTIFY 里 gaming 机器人共用）。
 *
 * 2) 配置：复制 notify_config.php.example 为 notify_config.php，填写：
 *     $PG_TELEGRAM_BOT_TOKEN = '你的PG_bot_token';
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
 *     指令与 gaming 类似：+100 CODE BANK CHAN 备注、undo、cancel、白名单 /addadmin 等（见 telegram_pg_quick_txn_webhook.php）。
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/notify.php';

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

// notify_config.php 与 config.php 同级；变量在 include 后应已存在（勿写在别的路径）
$token = '';
if (isset($PG_TELEGRAM_BOT_TOKEN)) {
    $token = trim((string)$PG_TELEGRAM_BOT_TOKEN);
}

// 浏览器 GET：自检是否读到 token、PHP 是否正常（勿公开传播此 URL）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $notifyPath = __DIR__ . '/notify_config.php';
    $probeG = getenv('PG_TELEGRAM_BOT_TOKEN');
    $probe = [
        'getenv' => is_string($probeG) && trim($probeG) !== '',
        'server' => isset($_SERVER['PG_TELEGRAM_BOT_TOKEN']) && is_string($_SERVER['PG_TELEGRAM_BOT_TOKEN'])
            && trim($_SERVER['PG_TELEGRAM_BOT_TOKEN']) !== '',
        'env' => isset($_ENV['PG_TELEGRAM_BOT_TOKEN']) && is_string($_ENV['PG_TELEGRAM_BOT_TOKEN'])
            && trim($_ENV['PG_TELEGRAM_BOT_TOKEN']) !== '',
    ];
    $rawNotify = is_readable($notifyPath) ? @file_get_contents($notifyPath) : false;
    $hasPgVarLine = is_string($rawNotify) && preg_match('/\$PG_TELEGRAM_BOT_TOKEN\s*=/', $rawNotify) === 1;
    $pgRhsQuoted = is_string($rawNotify) && preg_match(
        '/\$PG_TELEGRAM_BOT_TOKEN\s*=\s*([\'"])\s*\1\s*;/',
        $rawNotify
    ) === 1;
    $out = [
        'pg_webhook' => 'ok',
        'notify_config_file_exists' => is_file($notifyPath),
        'notify_config_realpath' => is_file($notifyPath) ? realpath($notifyPath) : null,
        'notify_config_declares_pg_token' => $hasPgVarLine,
        'notify_config_pg_assignment_empty_string' => $pgRhsQuoted,
        'token_configured' => ($token !== ''),
        'pg_token_length_on_this_server' => strlen($token),
        'probe_env_nonempty' => $probe,
        'fix_if_token_false' => '本服务器仍读不到 PG token。任选其一：1) 在 notify_config.php 中写 PHP 变量名 $PG_TELEGRAM_BOT_TOKEN（勿写成 $_PG_…）；赋值为带引号的完整 token 字符串；2) 或在 Hostinger「环境变量」新增 PG_TELEGRAM_BOT_TOKEN，并上传最新 config.php。改后刷新本页。若 notify_config_declares_pg_token 为 false，说明文件里根本没有该赋值行；若为 true 且 pg_assignment_empty_string 为 true，说明仍是空字符串；若二者为 true/false 仍 length 0，请上传已修复「先 include 再 define NOTIFY_CONFIG_LOADED」的 config.php。',
        'hint' => 'POST updates come from Telegram; setWebhook must point here. If group +100 has no reply, use @BotFather /setprivacy -> Disable.',
    ];
    if ($token === '' && ($probe['getenv'] || $probe['server'] || $probe['env'])) {
        $out['fix_if_probe_true_but_token_empty'] = '面板里已配置 PG_TELEGRAM_BOT_TOKEN，但 PHP 未写入 $PG_TELEGRAM_BOT_TOKEN：请把当前仓库里的 config.php 上传到与 telegram_pg_webhook.php 同目录并覆盖（需含对 $_SERVER/$_ENV 的读取）。';
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
