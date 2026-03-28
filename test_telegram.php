<?php
/**
 * Telegram 通知测试页（用完后可删除或限制仅管理员访问）
 * 在浏览器打开：https://你的域名.com/test_telegram.php
 * 会尝试发一条测试消息到你的 Telegram，并显示 API 返回结果，便于排查收不到的原因。
 */
require 'config.php';
require 'auth.php';
require_admin();

$result = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['send'])) {
    $token = $NOTIFY_TELEGRAM_BOT_TOKEN ?? '';
    $chat_id = $NOTIFY_TELEGRAM_CHAT_ID ?? '';

    if ($token === '' || $chat_id === '') {
        $result = '错误：未配置 BOT TOKEN 或 CHAT ID，请检查 notify_config.php 是否已创建并填写。';
    } else {
        $text = "🧪 这是一条测试消息。\n你的 K8 待审核通知已配置成功，有流水待审核时会收到推送。";
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err !== '') {
                $result = 'cURL 错误：' . htmlspecialchars($err);
            } else {
                $result = $raw;
                $sent = true;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $post,
                    'timeout' => 15,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            $result = $raw !== false ? $raw : '请求失败（可能服务器无法访问 api.telegram.org 或 allow_url_fopen 未开启）';
            $sent = ($raw !== false && strpos($raw, '"ok":true') !== false);
        }
    }
}

$has_config = !empty($NOTIFY_TELEGRAM_BOT_TOKEN) && !empty($NOTIFY_TELEGRAM_CHAT_ID);
$config_file_loaded = defined('NOTIFY_CONFIG_LOADED') && NOTIFY_CONFIG_LOADED;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram 通知测试 - K8</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #f1f5f9; }
        .card { background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h1 { margin: 0 0 16px; font-size: 1.25rem; color: #0f172a; }
        pre { background: #f8fafc; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; color: #334155; }
        .btn { display: inline-block; padding: 10px 20px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #1d4ed8; }
        .ok { color: #059669; }
        .err { color: #dc2626; }
        p { margin: 0 0 12px; color: #475569; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Telegram 通知测试</h1>
        <?php if (!$has_config): ?>
            <?php if (!$config_file_loaded): ?>
                <p class="err">未检测到配置：<strong>未找到或无法加载 <code>notify_config.php</code></strong>。</p>
                <p>请将本地的 <code>notify_config.php</code> 上传到<strong>服务器上与 <code>config.php</code>、<code>test_telegram.php</code> 相同的目录</strong>（例如 cPanel 里和 dashboard.php 同一层），该文件不在 Git 里，需手动上传。</p>
            <?php else: ?>
                <p class="err">未检测到配置：<code>notify_config.php</code> 已加载，但 <strong>BOT TOKEN 或 CHAT ID 为空</strong>。请在文件中填写正确的值并保存。</p>
            <?php endif; ?>
        <?php else: ?>
            <p>点击下方按钮会向你的 Telegram 发送一条<strong>测试消息</strong>。请查看手机/电脑 Telegram 是否收到。</p>
            <form method="post" style="margin-top:16px;">
                <button type="submit" class="btn">发送测试消息</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($result !== ''): ?>
    <div class="card">
        <h2 style="margin:0 0 12px; font-size:1rem;">API 返回结果</h2>
        <?php if ($sent): ?>
            <p class="ok">✓ 若 Telegram 已收到消息，说明配置正确。收不到请检查是否找对了对话（和你的 Bot 的私聊）。</p>
        <?php else: ?>
            <p class="err">若下面 JSON 里 "ok":false，请根据 "description" 排查（例如 chat_id 错误、未先给 Bot 发过消息等）。</p>
            <?php if (is_string($result) && (strpos($result, '"error_code":404') !== false || strpos($result, 'Not Found') !== false)): ?>
                <p class="err"><strong>提示：</strong> 返回 404 Not Found 多半是 <strong>BOT TOKEN 错误或已失效</strong>。请到 Telegram @BotFather → 你的 Bot → API Token 重新复制完整 token 到 <code>notify_config.php</code>。</p>
            <?php endif; ?>
        <?php endif; ?>
        <pre><?= htmlspecialchars(is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <?php endif; ?>
    <p style="font-size:12px; color:#94a3b8;"><a href="dashboard.php">返回首页</a></p>
</body>
</html>
