<?php
/**
 * 将 Telegram Bot Token 写入站点根目录 notify_config.php / PG_notify_config.php（Boss 后台用）
 */

function telegram_cfg_escape_php_string(string $s): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
}

function telegram_cfg_parse_assign(string $content, string $varName): ?string
{
    $vn = preg_quote($varName, '/');
    if (preg_match('/\$' . $vn . '\s*=\s*\'((?:\\\\.|[^\'])*)\'\s*;/s', $content, $m)) {
        return stripcslashes($m[1]);
    }
    if (preg_match('/\$' . $vn . '\s*=\s*"((?:\\\\.|[^"])*)"\s*;/s', $content, $m)) {
        return stripcslashes($m[1]);
    }
    return null;
}

/** @return array{token:string,chat_id:string,base_url:string} */
function telegram_cfg_read_notify_file(string $rootDir): array
{
    $out = ['token' => '', 'chat_id' => '', 'base_url' => ''];
    $path = rtrim($rootDir, '/\\') . '/notify_config.php';
    if (!is_file($path) || !is_readable($path)) {
        return $out;
    }
    $raw = (string)file_get_contents($path);
    $t = telegram_cfg_parse_assign($raw, 'NOTIFY_TELEGRAM_BOT_TOKEN');
    $c = telegram_cfg_parse_assign($raw, 'NOTIFY_TELEGRAM_CHAT_ID');
    $b = telegram_cfg_parse_assign($raw, 'NOTIFY_BASE_URL');
    if ($t !== null) {
        $out['token'] = $t;
    }
    if ($c !== null) {
        $out['chat_id'] = $c;
    }
    if ($b !== null) {
        $out['base_url'] = $b;
    }
    return $out;
}

function telegram_cfg_read_pg_file(string $rootDir): string
{
    $path = rtrim($rootDir, '/\\') . '/PG_notify_config.php';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $raw = (string)file_get_contents($path);
    $t = telegram_cfg_parse_assign($raw, 'PG_TELEGRAM_BOT_TOKEN');
    return $t !== null ? $t : '';
}

function telegram_cfg_validate_bot_token(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return true;
    }
    return (bool)preg_match('/^\d{6,15}:[A-Za-z0-9_-]{20,}$/', $token);
}

function telegram_cfg_write_notify_file(string $rootDir, string $token, string $chatId, string $baseUrl): void
{
    $path = rtrim($rootDir, '/\\') . '/notify_config.php';
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('目录不可写：' . $dir);
    }
    if (is_file($path) && !is_writable($path)) {
        throw new RuntimeException('notify_config.php 不可写');
    }
    $php = "<?php\n";
    $php .= "/**\n * Telegram 通知 / Gaming Bot（可由后台「Bot 连线状态」保存）\n */\n";
    $php .= '$NOTIFY_TELEGRAM_BOT_TOKEN = ' . telegram_cfg_escape_php_string(trim($token)) . ";\n";
    $php .= '$NOTIFY_TELEGRAM_CHAT_ID = ' . telegram_cfg_escape_php_string(trim($chatId)) . ";\n";
    $php .= '$NOTIFY_BASE_URL = ' . telegram_cfg_escape_php_string(trim($baseUrl)) . ";\n";
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('写入 notify_config.php 失败');
    }
}

function telegram_cfg_write_pg_file(string $rootDir, string $token): void
{
    $path = rtrim($rootDir, '/\\') . '/PG_notify_config.php';
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('目录不可写：' . $dir);
    }
    if (is_file($path) && !is_writable($path)) {
        throw new RuntimeException('PG_notify_config.php 不可写');
    }
    $php = "<?php\n";
    $php .= "/**\n * PG 专用 Bot Token（可由后台「Bot 连线状态」保存）\n */\n";
    $php .= '$PG_TELEGRAM_BOT_TOKEN = ' . telegram_cfg_escape_php_string(trim($token)) . ";\n";
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('写入 PG_notify_config.php 失败');
    }
}
