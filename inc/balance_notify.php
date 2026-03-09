<?php
/**
 * 余额阈值 Telegram 通知
 * 银行：余额超过设定值时通知；产品：余额低于设定值时通知。
 * 阈值在银行与产品页按项设置，存于 data/balance_notify.json
 * 同一银行/产品 24 小时内只通知一次（cooldown 存于 data/balance_notify_sent.json）
 */

/**
 * 返回每项阈值：['bank' => ['hlb' => 5000, 'cash' => 8000], 'product' => ['mega' => 500]]
 * 兼容旧格式 bank_above/product_below（视为未设置，需重新保存为分项）
 */
function balance_notify_get_config() {
    $path = defined('BALANCE_NOTIFY_CONFIG_PATH') ? BALANCE_NOTIFY_CONFIG_PATH : (__DIR__ . '/../data/balance_notify.json');
    if (!is_file($path)) return ['bank' => [], 'product' => []];
    $raw = @file_get_contents($path);
    if ($raw === false) return ['bank' => [], 'product' => []];
    $data = @json_decode($raw, true);
    if (!is_array($data)) return ['bank' => [], 'product' => []];
    $bank = [];
    if (isset($data['bank']) && is_array($data['bank'])) {
        foreach ($data['bank'] as $k => $v) {
            $key = strtolower(trim((string)$k));
            if ($key === '') continue;
            if (is_numeric($v) && (float)$v > 0) $bank[$key] = (float)$v;
        }
    }
    $product = [];
    if (isset($data['product']) && is_array($data['product'])) {
        foreach ($data['product'] as $k => $v) {
            $key = strtolower(trim((string)$k));
            if ($key === '') continue;
            if (is_numeric($v) && (float)$v > 0) $product[$key] = (float)$v;
        }
    }
    return ['bank' => $bank, 'product' => $product];
}

function balance_notify_get_sent_log() {
    $path = defined('BALANCE_NOTIFY_SENT_PATH') ? BALANCE_NOTIFY_SENT_PATH : (__DIR__ . '/../data/balance_notify_sent.json');
    if (!is_file($path)) return ['bank' => [], 'product' => []];
    $raw = @file_get_contents($path);
    if ($raw === false) return ['bank' => [], 'product' => []];
    $data = @json_decode($raw, true);
    if (!is_array($data)) return ['bank' => [], 'product' => []];
    return [
        'bank'    => isset($data['bank']) && is_array($data['bank']) ? $data['bank'] : [],
        'product' => isset($data['product']) && is_array($data['product']) ? $data['product'] : [],
    ];
}

function balance_notify_save_sent_log($log) {
    $dir = defined('BALANCE_NOTIFY_DATA_DIR') ? BALANCE_NOTIFY_DATA_DIR : (__DIR__ . '/../data');
    $path = $dir . '/balance_notify_sent.json';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** 24 小时冷却 */
function balance_notify_can_send($sent_log, $type, $key) {
    $key = strtolower(trim((string)$key));
    $arr = $type === 'bank' ? $sent_log['bank'] : $sent_log['product'];
    if (!isset($arr[$key])) return true;
    $last = strtotime($arr[$key]);
    return $last === false || (time() - $last) >= 86400; // 24h
}

function balance_notify_mark_sent(&$sent_log, $type, $key) {
    $key = strtolower(trim((string)$key));
    if ($type === 'bank') {
        $sent_log['bank'][$key] = date('Y-m-d H:i:s');
    } else {
        $sent_log['product'][$key] = date('Y-m-d H:i:s');
    }
}

/**
 * 根据当前银行/产品余额检查阈值并发送 Telegram 通知（若已配置）
 * 每个银行/产品使用各自设定的阈值。
 * @param array $bank_balances   [ '银行名' => 当前余额, ... ]
 * @param array $product_balances [ '产品名' => 当前余额, ... ]
 */
function check_balance_notify(array $bank_balances, array $product_balances) {
    global $NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID;

    if (empty($NOTIFY_TELEGRAM_BOT_TOKEN) || empty($NOTIFY_TELEGRAM_CHAT_ID)) return;

    $cfg = balance_notify_get_config();
    $bank_thresholds = $cfg['bank'];
    $product_thresholds = $cfg['product'];
    if (empty($bank_thresholds) && empty($product_thresholds)) return;

    if (!function_exists('send_telegram_message')) {
        require_once __DIR__ . '/notify.php';
    }

    $sent = balance_notify_get_sent_log();
    $changed = false;

    foreach ($bank_balances as $name => $balance) {
        $key = strtolower(trim((string)$name));
        $threshold = isset($bank_thresholds[$key]) ? (float)$bank_thresholds[$key] : null;
        if ($threshold !== null && $threshold > 0 && $balance > $threshold && balance_notify_can_send($sent, 'bank', $name)) {
            $text = "📈 银行余额提醒\n银行：{$name}\n当前余额：" . number_format($balance, 2) . "\n已超过设定值：" . number_format($threshold, 2);
            send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
            balance_notify_mark_sent($sent, 'bank', $name);
            $changed = true;
        }
    }

    foreach ($product_balances as $name => $balance) {
        $key = strtolower(trim((string)$name));
        $threshold = isset($product_thresholds[$key]) ? (float)$product_thresholds[$key] : null;
        if ($threshold !== null && $threshold > 0 && $balance < $threshold && balance_notify_can_send($sent, 'product', $name)) {
            $text = "📉 产品余额提醒\n产品：{$name}\n当前余额：" . number_format($balance, 2) . "\n已低于设定值：" . number_format($threshold, 2);
            send_telegram_message($NOTIFY_TELEGRAM_BOT_TOKEN, $NOTIFY_TELEGRAM_CHAT_ID, $text);
            balance_notify_mark_sent($sent, 'product', $name);
            $changed = true;
        }
    }

    if ($changed) balance_notify_save_sent_log($sent);
}
