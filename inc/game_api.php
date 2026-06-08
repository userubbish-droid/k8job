<?php
/**
 * 游戏平台 API（Authcode + SecretKey，VGS/918 类代理接口）
 * vendorId = Authcode，signature = SecretKey（由平台后台提供，通常为固定值）
 */

function game_api_ensure_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_api_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        master_enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO game_api_settings (id, master_enabled) VALUES (1, 1)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_api_company_config (
        company_id INT UNSIGNED NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        api_base_url VARCHAR(255) NOT NULL DEFAULT '',
        authcode VARCHAR(120) NOT NULL DEFAULT '',
        secret_key VARCHAR(120) NOT NULL DEFAULT '',
        agent_account VARCHAR(64) NOT NULL DEFAULT '',
        provider VARCHAR(32) NOT NULL DEFAULT 'vgs',
        last_test_at DATETIME NULL,
        last_test_ok TINYINT(1) NOT NULL DEFAULT 0,
        last_test_message VARCHAR(500) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function game_api_master_enabled(PDO $pdo): bool
{
    game_api_ensure_tables($pdo);
    try {
        return (int)$pdo->query('SELECT master_enabled FROM game_api_settings WHERE id = 1 LIMIT 1')->fetchColumn() === 1;
    } catch (Throwable $e) {
        return true;
    }
}

function game_api_set_master_enabled(PDO $pdo, bool $enabled, ?int $updatedBy = null): void
{
    game_api_ensure_tables($pdo);
    $st = $pdo->prepare('UPDATE game_api_settings SET master_enabled = ?, updated_by = ? WHERE id = 1');
    $st->execute([$enabled ? 1 : 0, $updatedBy]);
}

/** @return array<string,mixed> */
function game_api_default_company_config(): array
{
    return [
        'enabled' => 0,
        'api_base_url' => '',
        'authcode' => '',
        'secret_key' => '',
        'agent_account' => '',
        'provider' => 'vgs',
        'last_test_at' => null,
        'last_test_ok' => 0,
        'last_test_message' => '',
    ];
}

/** @return array<string,mixed> */
function game_api_get_company_config(PDO $pdo, int $companyId): array
{
    game_api_ensure_tables($pdo);
    if ($companyId <= 0) {
        return game_api_default_company_config();
    }
    $st = $pdo->prepare('SELECT * FROM game_api_company_config WHERE company_id = ? LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return game_api_default_company_config();
    }
    return array_merge(game_api_default_company_config(), $row);
}

function game_api_save_company_config(PDO $pdo, int $companyId, array $data, ?int $updatedBy = null): void
{
    game_api_ensure_tables($pdo);
    if ($companyId <= 0) {
        throw new InvalidArgumentException('无效 company_id');
    }

    $cur = game_api_get_company_config($pdo, $companyId);
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $url = trim((string)($data['api_base_url'] ?? $cur['api_base_url'] ?? ''));
    $auth = trim((string)($data['authcode'] ?? ''));
    $secret = trim((string)($data['secret_key'] ?? ''));
    if ($auth === '' && trim((string)($cur['authcode'] ?? '')) !== '') {
        $auth = trim((string)$cur['authcode']);
    }
    if ($secret === '' && trim((string)($cur['secret_key'] ?? '')) !== '') {
        $secret = trim((string)$cur['secret_key']);
    }
    $agent = trim((string)($data['agent_account'] ?? $cur['agent_account'] ?? ''));
    $provider = strtolower(trim((string)($data['provider'] ?? $cur['provider'] ?? 'vgs')));
    if (!in_array($provider, ['vgs'], true)) {
        $provider = 'vgs';
    }

    $st = $pdo->prepare("INSERT INTO game_api_company_config
        (company_id, enabled, api_base_url, authcode, secret_key, agent_account, provider, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            api_base_url = VALUES(api_base_url),
            authcode = VALUES(authcode),
            secret_key = VALUES(secret_key),
            agent_account = VALUES(agent_account),
            provider = VALUES(provider),
            updated_by = VALUES(updated_by)");
    $st->execute([$companyId, $enabled, $url, $auth, $secret, $agent, $provider, $updatedBy]);
}

function game_api_company_is_gaming(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$companyId]);
        return strtolower(trim((string)$st->fetchColumn())) !== 'pg';
    } catch (Throwable $e) {
        return true;
    }
}

function game_api_is_active(PDO $pdo, int $companyId): bool
{
    if (!game_api_master_enabled($pdo)) {
        return false;
    }
    $cfg = game_api_get_company_config($pdo, $companyId);
    if ((int)($cfg['enabled'] ?? 0) !== 1) {
        return false;
    }
    if (trim((string)($cfg['api_base_url'] ?? '')) === '') {
        return false;
    }
    if (trim((string)($cfg['authcode'] ?? '')) === '' || trim((string)($cfg['secret_key'] ?? '')) === '') {
        return false;
    }
    return game_api_company_is_gaming($pdo, $companyId);
}

/** @return array{ok:bool,http_code?:int,raw?:string,data?:array,error?:string} */
function game_api_request(array $config, string $cmd, array $extra = []): array
{
    $url = rtrim(trim((string)($config['api_base_url'] ?? '')), '/');
    $vendorId = trim((string)($config['authcode'] ?? ''));
    $signature = trim((string)($config['secret_key'] ?? ''));
    if ($url === '' || $vendorId === '' || $signature === '') {
        return ['ok' => false, 'error' => 'API 未配置完整（URL / Authcode / SecretKey）'];
    }

    $payload = array_merge([
        'cmd' => $cmd,
        'vendorId' => $vendorId,
        'signature' => $signature,
        'timestamp' => time(),
        'syslang' => 1,
    ], $extra);

    $body = http_build_query($payload);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl 初始化失败'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => '请求失败：' . $err, 'http_code' => $http];
    }
    if (!is_string($raw)) {
        return ['ok' => false, 'error' => '空响应', 'http_code' => $http];
    }

    $data = json_decode(trim($raw), true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => '响应不是 JSON', 'http_code' => $http, 'raw' => $raw];
    }

    $ec = (int)($data['errorCode'] ?? -1);
    if ($ec !== 0) {
        $msg = trim((string)($data['errorMessage'] ?? '未知错误'));
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ('errorCode=' . $ec), 'http_code' => $http, 'raw' => $raw, 'data' => $data];
    }

    return ['ok' => true, 'http_code' => $http, 'raw' => $raw, 'data' => $data];
}

/** @return array{ok:bool,message:string,balance?:float|null} */
function game_api_test_connection(array $config): array
{
    $hello = game_api_request($config, 'Hello');
    if ($hello['ok']) {
        $result = (string)($hello['data']['result'] ?? '');
        return ['ok' => true, 'message' => $result !== '' ? ('Hello 成功：' . $result) : 'Hello 成功'];
    }

    $bal = game_api_request($config, 'GetAgentBalance');
    if ($bal['ok']) {
        $result = $bal['data']['result'] ?? null;
        $balance = is_numeric((string)$result) ? (float)$result : null;
        return [
            'ok' => true,
            'message' => $balance !== null ? ('代理余额：' . $balance) : 'GetAgentBalance 成功',
            'balance' => $balance,
        ];
    }

    $err = (string)($bal['error'] ?? $hello['error'] ?? '连接失败');
    return ['ok' => false, 'message' => $err];
}

function game_api_record_test_result(PDO $pdo, int $companyId, bool $ok, string $message): void
{
    game_api_ensure_tables($pdo);
    $st = $pdo->prepare('UPDATE game_api_company_config SET last_test_at = NOW(), last_test_ok = ?, last_test_message = ? WHERE company_id = ?');
    $st->execute([$ok ? 1 : 0, mb_substr($message, 0, 500, 'UTF-8'), $companyId]);
}

/** @return array{ok:bool,error?:string,balance?:float,data?:array} */
function game_api_get_agent_balance(array $config): array
{
    $res = game_api_request($config, 'GetAgentBalance');
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? '失败')];
    }
    $result = $res['data']['result'] ?? null;
    return [
        'ok' => true,
        'balance' => is_numeric((string)$result) ? (float)$result : null,
        'data' => $res['data'],
    ];
}

/** @return array{ok:bool,error?:string,balance?:float,data?:array} */
function game_api_get_player_balance(array $config, string $user): array
{
    $user = trim($user);
    if ($user === '') {
        return ['ok' => false, 'error' => '玩家账号为空'];
    }
    $res = game_api_request($config, 'GetBalance', ['user' => $user]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? '失败')];
    }
    $result = $res['data']['result'] ?? null;
    return [
        'ok' => true,
        'balance' => is_numeric((string)$result) ? (float)$result : null,
        'data' => $res['data'],
    ];
}

/** @return array{ok:bool,error?:string,data?:array} */
function game_api_change_balance(array $config, string $user, float $money, ?string $order = null): array
{
    $user = trim($user);
    if ($user === '') {
        return ['ok' => false, 'error' => '玩家账号为空'];
    }
    if ($money == 0.0) {
        return ['ok' => false, 'error' => '金额不能为 0'];
    }
    $extra = ['user' => $user, 'money' => $money];
    if ($order !== null && trim($order) !== '') {
        $extra['order'] = trim($order);
    }
    $res = game_api_request($config, 'ChangeBalance', $extra);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? '失败')];
    }
    return ['ok' => true, 'data' => $res['data']];
}

function game_api_mask_secret(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '（未配置）';
    }
    if (strlen($s) <= 8) {
        return substr($s, 0, 2) . '…';
    }
    return substr($s, 0, 4) . '…' . substr($s, -4);
}
