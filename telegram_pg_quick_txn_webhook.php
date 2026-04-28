<?php
/**
 * PG 群聊快捷记账：读写 telegram_quick_txn_*_pg（主库）与 pg_transactions（业务库 pdo_data_for_company_id）。
 * 依赖 telegram_quick_txn_webhook.php 中的解析与回复函数（勿删该文件）。
 */
require_once __DIR__ . '/telegram_quick_txn_webhook.php';

function tqx_pg_ensure_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_config_pg (
        company_id INT UNSIGNED NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        chat_id VARCHAR(40) NULL,
        allowed_user_ids TEXT NULL,
        bank_alias_json TEXT NULL,
        product_alias_json TEXT NULL,
        staff_alias_json TEXT NULL,
        receipt_prefix TEXT NULL,
        receipt_slogan TEXT NULL,
        receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic',
        undo_window_sec INT NOT NULL DEFAULT 600,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        PRIMARY KEY (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN staff_alias_json TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_prefix TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_slogan TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic'");
    } catch (Throwable $e) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_log_pg (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        chat_id VARCHAR(40) NOT NULL,
        tg_user_id BIGINT NULL,
        tg_username VARCHAR(120) NULL,
        raw_text TEXT NULL,
        action VARCHAR(40) NOT NULL,
        token VARCHAR(40) NOT NULL,
        transaction_id INT UNSIGNED NULL,
        receipt_message_id BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_company_chat (company_id, chat_id),
        KEY idx_token (token),
        KEY idx_receipt_msg (receipt_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $pdo->exec("ALTER TABLE telegram_quick_txn_log_pg ADD COLUMN receipt_message_id BIGINT NULL");
    } catch (Throwable $e) {
    }
    foreach (['pg_simple_member_code', 'pg_simple_bank', 'pg_simple_product'] as $col) {
        try {
            $pdo->exec("ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN {$col} VARCHAR(64) NULL DEFAULT NULL");
        } catch (Throwable $e) {
        }
    }
    try {
        $pdo->exec('ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN pg_simple_currency VARCHAR(8) NULL DEFAULT NULL');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE telegram_quick_txn_config_pg ADD COLUMN receipt_fx_rate DECIMAL(14,6) NOT NULL DEFAULT 1.000000');
    } catch (Throwable $e) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_pg_chat_customer_pg (
        chat_id VARCHAR(40) NOT NULL,
        topic_key VARCHAR(32) NOT NULL DEFAULT '0',
        company_id INT UNSIGNED NOT NULL,
        member_code VARCHAR(64) NOT NULL DEFAULT '',
        default_bank VARCHAR(64) NOT NULL DEFAULT '',
        default_product VARCHAR(64) NOT NULL DEFAULT '',
        default_currency VARCHAR(8) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (chat_id, topic_key),
        KEY idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    tqx_pg_ensure_chat_customer_schema($pdo);
}

/** ISO 风格币种代码，空或非法则返回空串 */
function tqx_pg_normalize_currency(string $s): string
{
    $s = strtoupper(preg_replace('/\s+/', '', trim($s)));
    if ($s === '' || !preg_match('/^[A-Z]{2,8}$/', $s)) {
        return '';
    }

    return $s;
}

function tqx_pg_ensure_chat_customer_schema(PDO $pdo): void
{
    foreach (['default_bank', 'default_product', 'default_currency'] as $col) {
        $len = $col === 'default_currency' ? 8 : 64;
        try {
            $pdo->exec("ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN {$col} VARCHAR({$len}) NOT NULL DEFAULT ''");
        } catch (Throwable $e) {
        }
    }
    // PG 不再使用产品：保留 default_product 列兼容旧库，写入时恒为空
    $hasTopic = false;
    try {
        $q = $pdo->query("SHOW COLUMNS FROM telegram_pg_chat_customer_pg LIKE 'topic_key'");
        $hasTopic = $q && $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
    if ($hasTopic) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN topic_key VARCHAR(32) NOT NULL DEFAULT '0' AFTER chat_id");
    } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE telegram_pg_chat_customer_pg ADD COLUMN topic_key VARCHAR(32) NOT NULL DEFAULT '0'");
        } catch (Throwable $e2) {
        }
    }
    try {
        $pdo->exec("UPDATE telegram_pg_chat_customer_pg SET topic_key = '0' WHERE topic_key = '' OR topic_key IS NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE telegram_pg_chat_customer_pg DROP PRIMARY KEY');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE telegram_pg_chat_customer_pg ADD PRIMARY KEY (chat_id, topic_key)');
    } catch (Throwable $e) {
    }
}

function tqx_pg_topic_key_from_msg(array $msg): string
{
    if (isset($msg['message_thread_id']) && is_numeric($msg['message_thread_id'])) {
        $tid = (int)$msg['message_thread_id'];
        if ($tid > 0) {
            return (string)$tid;
        }
    }
    return '0';
}

/**
 * @param array<string,mixed>|null $rowGlobal
 * @param array<string,mixed>|null $rowTopic
 */
function tqx_pg_merge_bind_topic_over_global(?array $rowGlobal, ?array $rowTopic): ?array
{
    if (!is_array($rowGlobal) && !is_array($rowTopic)) {
        return null;
    }
    $base = is_array($rowGlobal) ? $rowGlobal : $rowTopic;
    if (!is_array($base)) {
        return null;
    }
    $out = $base;
    if (!is_array($rowTopic)) {
        return $out;
    }
    foreach (['member_code', 'default_bank', 'default_currency'] as $f) {
        $tv = trim((string)($rowTopic[$f] ?? ''));
        if ($tv !== '') {
            $out[$f] = $tv;
        }
    }
    $cidT = (int)($rowTopic['company_id'] ?? 0);
    if ($cidT > 0) {
        $out['company_id'] = $cidT;
    }
    return $out;
}

function tqx_pg_bind_row_upsert_fields(PDO $pdo, string $chatId, string $topicKey, int $companyId, array $fields): void
{
    $allowed = ['member_code', 'default_bank', 'default_currency'];
    $patch = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $fields)) {
            continue;
        }
        $patch[$k] = (string)$fields[$k];
    }
    if ($patch === []) {
        return;
    }
    $st = $pdo->prepare('SELECT chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = ? LIMIT 1');
    $st->execute([$chatId, $topicKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $member = array_key_exists('member_code', $patch) ? $patch['member_code'] : (string)($row['member_code'] ?? '');
        $db = array_key_exists('default_bank', $patch) ? $patch['default_bank'] : (string)($row['default_bank'] ?? '');
        $dcRaw = array_key_exists('default_currency', $patch) ? $patch['default_currency'] : (string)($row['default_currency'] ?? '');
        $dc = tqx_pg_normalize_currency($dcRaw);
        $pdo->prepare('UPDATE telegram_pg_chat_customer_pg SET member_code = ?, default_bank = ?, default_product = ?, default_currency = ? WHERE chat_id = ? AND topic_key = ? LIMIT 1')
            ->execute([$member, $db, '', $dc, $chatId, $topicKey]);
        return;
    }
    $member = array_key_exists('member_code', $patch) ? $patch['member_code'] : '';
    $db = array_key_exists('default_bank', $patch) ? $patch['default_bank'] : '';
    $dcIns = array_key_exists('default_currency', $patch) ? tqx_pg_normalize_currency($patch['default_currency']) : '';
    $pdo->prepare('INSERT INTO telegram_pg_chat_customer_pg (chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$chatId, $topicKey, $companyId, $member, $db, '', $dcIns]);
}

/**
 * 按群 + 论坛话题解析 PG 配置；否则回退到 config 表里的 chat_id。
 *
 * @return array<string,mixed>|null
 */
function tqx_pg_load_cfg_for_chat(PDO $pdoCat, string $chatId, string $topicKey = '0'): ?array
{
    tqx_pg_ensure_chat_customer_schema($pdoCat);
    if (!preg_match('/^[0-9]{1,24}$/', $topicKey)) {
        $topicKey = '0';
    }

    $st0 = $pdoCat->prepare('SELECT chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = ? LIMIT 1');
    $st0->execute([$chatId, '0']);
    $row0 = $st0->fetch(PDO::FETCH_ASSOC) ?: null;

    $rowT = null;
    if ($topicKey !== '0') {
        $stT = $pdoCat->prepare('SELECT chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = ? LIMIT 1');
        $stT->execute([$chatId, $topicKey]);
        $rowT = $stT->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $bindMerged = tqx_pg_merge_bind_topic_over_global($row0, $rowT);
    if (is_array($bindMerged) && (int)($bindMerged['company_id'] ?? 0) > 0) {
        $companyId = (int)$bindMerged['company_id'];
        $stCfg = $pdoCat->prepare("SELECT company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec,
            pg_simple_member_code, pg_simple_bank, pg_simple_product, pg_simple_currency, receipt_fx_rate
            FROM telegram_quick_txn_config_pg WHERE company_id = ? LIMIT 1");
        $stCfg->execute([$companyId]);
        $cfg = $stCfg->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cfg) || !(int)($cfg['enabled'] ?? 0)) {
            return null;
        }
        $cfg['chat_default_member_code'] = trim((string)($bindMerged['member_code'] ?? ''));
        $cfg['chat_default_bank'] = trim((string)($bindMerged['default_bank'] ?? ''));
        $cfg['chat_default_currency'] = tqx_pg_normalize_currency((string)($bindMerged['default_currency'] ?? ''));
        $cfg['chat_default_product'] = '';
        tqx_pg_fill_chat_defaults_from_any_topic($pdoCat, $chatId, $cfg);
        tqx_pg_cfg_resolve_currency($pdoCat, $cfg);

        return $cfg;
    }

    $stFind = $pdoCat->prepare("SELECT company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec,
        pg_simple_member_code, pg_simple_bank, pg_simple_product, pg_simple_currency, receipt_fx_rate
        FROM telegram_quick_txn_config_pg
        WHERE enabled = 1 AND chat_id IS NOT NULL AND chat_id <> '' AND chat_id = ?
        LIMIT 1");
    $stFind->execute([$chatId]);
    $cfg = $stFind->fetch(PDO::FETCH_ASSOC);
    if (!is_array($cfg)) {
        return null;
    }
    $cid = (int)($cfg['company_id'] ?? 0);
    if ($cid <= 0) {
        return null;
    }
    try {
        $chk = $pdoCat->prepare("SELECT 1 FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = '0' LIMIT 1");
        $chk->execute([$chatId]);
        if (!$chk->fetchColumn()) {
            $pdoCat->prepare("INSERT INTO telegram_pg_chat_customer_pg (chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency) VALUES (?, '0', ?, '', '', '', '')")
                ->execute([$chatId, $cid]);
        }
    } catch (Throwable $e) {
    }
    $mem = '';
    $db = '';
    $dcc = '';
    try {
        $stM = $pdoCat->prepare("SELECT member_code, default_bank, default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = '0' LIMIT 1");
        $stM->execute([$chatId]);
        $rM = $stM->fetch(PDO::FETCH_ASSOC);
        if (is_array($rM)) {
            $mem = trim((string)($rM['member_code'] ?? ''));
            $db = trim((string)($rM['default_bank'] ?? ''));
            $dcc = tqx_pg_normalize_currency((string)($rM['default_currency'] ?? ''));
        }
    } catch (Throwable $e) {
    }
    if ($topicKey !== '0') {
        try {
            $stT2 = $pdoCat->prepare("SELECT member_code, default_bank, default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND topic_key = ? LIMIT 1");
            $stT2->execute([$chatId, $topicKey]);
            $rT2 = $stT2->fetch(PDO::FETCH_ASSOC);
            if (is_array($rT2)) {
                foreach (['member_code' => &$mem, 'default_bank' => &$db] as $fk => &$ref) {
                    $tv = trim((string)($rT2[$fk] ?? ''));
                    if ($tv !== '') {
                        $ref = $tv;
                    }
                }
                unset($ref);
                $tv2 = tqx_pg_normalize_currency((string)($rT2['default_currency'] ?? ''));
                if ($tv2 !== '') {
                    $dcc = $tv2;
                }
            }
        } catch (Throwable $e) {
        }
    }
    $cfg['chat_default_member_code'] = $mem;
    $cfg['chat_default_bank'] = $db;
    $cfg['chat_default_currency'] = $dcc;
    $cfg['chat_default_product'] = '';
    tqx_pg_fill_chat_defaults_from_any_topic($pdoCat, $chatId, $cfg);
    tqx_pg_cfg_resolve_currency($pdoCat, $cfg);

    return $cfg;
}

/** 解析本笔记账币种：话题 /currency > 后台 pg_simple_currency > 分公司 companies.currency > MYR */
function tqx_pg_cfg_resolve_currency(PDO $pdoCat, array &$cfg): void
{
    $cid = (int)($cfg['company_id'] ?? 0);
    $comp = '';
    if ($cid > 0) {
        try {
            $st = $pdoCat->prepare('SELECT UPPER(TRIM(currency)) FROM companies WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $comp = tqx_pg_normalize_currency((string)$st->fetchColumn());
        } catch (Throwable $e) {
        }
    }
    $cfg['company_currency'] = $comp !== '' ? $comp : 'MYR';
    $chat = tqx_pg_normalize_currency(trim((string)($cfg['chat_default_currency'] ?? '')));
    $simple = tqx_pg_normalize_currency(trim((string)($cfg['pg_simple_currency'] ?? '')));
    if ($chat !== '') {
        $cfg['txn_currency'] = $chat;
    } elseif ($simple !== '') {
        $cfg['txn_currency'] = $simple;
    } elseif ($comp !== '') {
        $cfg['txn_currency'] = $comp;
    } else {
        $cfg['txn_currency'] = 'MYR';
    }
}

/** 当前话题未设客户/银行时，用本群任意话题里最近填过的值（解决 /customer 与 /bank 分在不同论坛话题） */
function tqx_pg_fill_chat_defaults_from_any_topic(PDO $pdoCat, string $chatId, array &$cfg): void
{
    if (trim((string)($cfg['chat_default_member_code'] ?? '')) === '') {
        try {
            $st = $pdoCat->prepare("SELECT member_code FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND TRIM(COALESCE(member_code,'')) <> '' ORDER BY updated_at IS NULL, updated_at DESC LIMIT 1");
            $st->execute([$chatId]);
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') {
                $cfg['chat_default_member_code'] = $v;
            }
        } catch (Throwable $e) {
        }
    }
    if (trim((string)($cfg['chat_default_bank'] ?? '')) === '') {
        try {
            $st = $pdoCat->prepare("SELECT default_bank FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND TRIM(COALESCE(default_bank,'')) <> '' ORDER BY updated_at IS NULL, updated_at DESC LIMIT 1");
            $st->execute([$chatId]);
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') {
                $cfg['chat_default_bank'] = $v;
            }
        } catch (Throwable $e) {
        }
    }
    if (tqx_pg_normalize_currency(trim((string)($cfg['chat_default_currency'] ?? ''))) === '') {
        try {
            $st = $pdoCat->prepare("SELECT default_currency FROM telegram_pg_chat_customer_pg WHERE chat_id = ? AND TRIM(COALESCE(default_currency,'')) <> '' ORDER BY updated_at IS NULL, updated_at DESC LIMIT 1");
            $st->execute([$chatId]);
            $v = tqx_pg_normalize_currency((string)$st->fetchColumn());
            if ($v !== '') {
                $cfg['chat_default_currency'] = $v;
            }
        } catch (Throwable $e) {
        }
    }
}

/**
 * 从备注尾串解析 b10%、mega/game/balance 等（与 PG 四段格式尾段规则一致）。
 *
 * @return array{bonus_pct:?float,remark:string,mega_account:string,game_id:string,balance:?float}
 */
function tqx_pg_parse_remark_tail_features(string $tail): array
{
    $bonusPct = null;
    $remark = $tail;
    if ($tail !== '') {
        if (preg_match('/^(b\s*([0-9]{1,2}(?:\.[0-9]+)?)%?)\s*(.*)$/i', $tail, $mm)) {
            $pctRaw = $mm[2];
            if (is_numeric($pctRaw)) {
                $p = (float)$pctRaw;
                if ($p >= 0 && $p <= 100) {
                    $bonusPct = $p;
                    $remark = trim((string)($mm[3] ?? ''));
                }
            }
        }
    }

    $megaAccount = '';
    $gameId = '';
    $balance = null;
    $restRemark = $remark;
    if ($remark !== '') {
        $parts = preg_split('/\s+/', $remark) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
        if (count($parts) >= 2) {
            $p0 = (string)$parts[0];
            $p1 = (string)$parts[1];
            if ($p0 !== '' && $p1 !== '') {
                $megaAccount = $p0;
                $gameId = $p1;
                if (count($parts) >= 3) {
                    $p2 = (string)$parts[2];
                    $balRaw = str_replace(',', '', $p2);
                    if (is_numeric($balRaw)) {
                        $balance = round((float)$balRaw, 2);
                        $restRemark = trim(implode(' ', array_slice($parts, 3)));
                    } else {
                        $restRemark = trim(implode(' ', array_slice($parts, 2)));
                    }
                } else {
                    $restRemark = trim(implode(' ', array_slice($parts, 2)));
                }
            }
        }
    }

    return [
        'bonus_pct' => $bonusPct,
        'remark' => $restRemark,
        'mega_account' => $megaAccount,
        'game_id' => $gameId,
        'balance' => $balance,
    ];
}

/**
 * 判断金额后第一个词是否像「渠道/银行」缩写（已设默认银行时用于区分「覆盖银行」与「纯备注」）。
 */
function tqx_pg_token_likely_bank_channel(string $tok, array $bankAlias, string $defB): bool
{
    $tok = trim($tok);
    if ($tok === '') {
        return false;
    }
    $k = tqx_norm_key($tok);
    if ($defB !== '' && tqx_norm_key($defB) === $k) {
        return true;
    }
    foreach ($bankAlias as $a => $v) {
        $ka = tqx_norm_key((string)$a);
        $kv = tqx_norm_key((string)$v);
        if ($k === $ka || $k === $kv) {
            return true;
        }
    }
    if (preg_match('/^[a-z0-9._-]{2,15}$/i', $tok)) {
        return true;
    }

    return false;
}

/**
 * 从 tokens 拆出：姓名、消息内银行（空则用默认）、备注。
 *
 * @param array<int,string> $parts0 金额后的所有词，至少 1 个（姓名）
 * @return array{name:string,bankMsg:string,remark:string,err?:string}
 */
function tqx_pg_split_name_bank_remark(array $parts0, string $defBank, array $bankAlias): array
{
    $name = trim((string)($parts0[0] ?? ''));
    if ($name === '') {
        return ['name' => '', 'bankMsg' => '', 'remark' => '', 'err' => 'no_name'];
    }
    $tail = array_slice($parts0, 1);
    $defB = trim($defBank);

    if (count($tail) === 0) {
        if ($defB === '') {
            return ['name' => $name, 'bankMsg' => '', 'remark' => '', 'err' => 'need_bank'];
        }

        return ['name' => $name, 'bankMsg' => '', 'remark' => ''];
    }

    $t1 = trim((string)($tail[0] ?? ''));
    $r = trim(implode(' ', array_slice($tail, 1)));

    if ($defB !== '') {
        if ($r === '') {
            if (tqx_pg_token_likely_bank_channel($t1, $bankAlias, $defB)) {
                return ['name' => $name, 'bankMsg' => $t1, 'remark' => ''];
            }

            return ['name' => $name, 'bankMsg' => '', 'remark' => $t1];
        }
        if (tqx_pg_token_likely_bank_channel($t1, $bankAlias, $defB)) {
            return ['name' => $name, 'bankMsg' => $t1, 'remark' => $r];
        }

        return ['name' => $name, 'bankMsg' => '', 'remark' => trim($t1 . ($r !== '' ? ' ' . $r : ''))];
    }

    if ($r === '') {
        return ['name' => $name, 'bankMsg' => $t1, 'remark' => ''];
    }

    return ['name' => $name, 'bankMsg' => $t1, 'remark' => $r];
}

/**
 * PG：+/-金额 姓名 [銀行] [備注…]；銀行省略时用 /bank 或後台默認，寫在消息裡則只覆蓋本筆 channel。
 *
 * @return array{ok:bool,err?:string,cmd?:string,data?:array}
 */
function tqx_pg_flex_to_parsed(string $sign, string $amtRaw, array $parts0, array $cfgE): array
{
    if (!is_numeric($amtRaw)) {
        return ['ok' => false, 'err' => '金额不是数字'];
    }
    $amount = round((float)$amtRaw, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'err' => '金额需大于 0'];
    }
    $bankAlias = tqx_json_decode_map((string)($cfgE['bank_alias_json'] ?? ''));
    $defB = trim((string)($cfgE['chat_default_bank'] ?? ''));
    if ($defB === '') {
        $defB = trim((string)($cfgE['pg_simple_bank'] ?? ''));
    }

    $split = tqx_pg_split_name_bank_remark($parts0, $defB, $bankAlias);
    if (isset($split['err']) && $split['err'] === 'no_name') {
        return ['ok' => false, 'err' => ''];
    }
    if (isset($split['err']) && $split['err'] === 'need_bank') {
        return ['ok' => false, 'err' => 'need_bank'];
    }

    $bankMsg = trim((string)($split['bankMsg'] ?? ''));
    $bankFinal = $bankMsg !== '' ? $bankMsg : $defB;
    if ($bankFinal === '') {
        return ['ok' => false, 'err' => 'need_bank'];
    }

    $tailFeat = tqx_pg_parse_remark_tail_features(trim((string)($split['remark'] ?? '')));

    return [
        'ok' => true,
        'cmd' => ($sign === '+') ? 'deposit' : 'withdraw',
        'data' => [
            'amount' => $amount,
            'code' => '',
            'bank' => $bankFinal,
            'product' => '',
            'bonus_pct' => $tailFeat['bonus_pct'],
            'mega_account' => $tailFeat['mega_account'],
            'game_id' => $tailFeat['game_id'],
            'balance' => $tailFeat['balance'],
            'remark' => $tailFeat['remark'],
            'name_display' => (string)($split['name'] ?? ''),
            'pg_flex' => true,
        ],
    ];
}

/**
 * PG 专用：+金额 代号 银行 [备注…]，无「产品」段（与 gaming 五段格式区分）
 *
 * @return array{ok:bool,err?:string,cmd?:string,data?:array}
 */
function tqx_pg_parse_money_line(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'err' => 'Empty'];
    }
    if (!preg_match('/^([+-])\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s+(\S+)\s+(\S+)(?:\s+(.*))?$/u', $text, $m)) {
        return ['ok' => false, 'err' => ''];
    }
    $sign = $m[1];
    $amtRaw = str_replace(',', '', $m[2]);
    $code = $m[3];
    $bank = $m[4];
    $tail = isset($m[5]) ? trim((string)$m[5]) : '';

    if (!is_numeric($amtRaw)) {
        return ['ok' => false, 'err' => '金额不是数字'];
    }
    $amount = round((float)$amtRaw, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'err' => '金额需大于 0'];
    }

    $tailFeat = tqx_pg_parse_remark_tail_features($tail);

    return [
        'ok' => true,
        'cmd' => ($sign === '+') ? 'deposit' : 'withdraw',
        'data' => [
            'amount' => $amount,
            'code' => $code,
            'bank' => $bank,
            'product' => '',
            'bonus_pct' => $tailFeat['bonus_pct'],
            'mega_account' => $tailFeat['mega_account'],
            'game_id' => $tailFeat['game_id'],
            'balance' => $tailFeat['balance'],
            'remark' => $tailFeat['remark'],
        ],
    ];
}

function tqx_pg_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tqx_pg_fmt_receipt_amount(float $v): string
{
    $v = round($v, 2);
    if (abs($v - round($v, 0)) < 0.00001) {
        return (string)(int)round($v);
    }

    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

function tqx_pg_receipt_row_label(?string $extRef, string $memberCode, ?string $remark, int $maxChars = 26): string
{
    $parts = [];
    $e = trim((string)$extRef);
    if ($e !== '') {
        $parts[] = $e;
    }
    $c = trim($memberCode);
    if ($c !== '') {
        $parts[] = $c;
    }
    $r = trim((string)$remark);
    if ($r !== '') {
        $parts[] = preg_replace('/\s+/u', ' ', $r);
    }
    $s = implode(' ', $parts);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $maxChars) {
            return mb_substr($s, 0, $maxChars, 'UTF-8') . '..';
        }
    } elseif (strlen($s) > $maxChars) {
        return substr($s, 0, $maxChars) . '..';
    }

    return $s;
}

/**
 * @param list<array<string,mixed>> $rowsIn
 * @param list<array<string,mixed>> $rowsOut
 */
function tqx_pg_build_receipt_body_html(
    int $nIn,
    int $nOut,
    array $rowsIn,
    array $rowsOut,
    string $flow,
    int $txId,
    float $principal,
    float $booked,
    float $todayIn,
    float $todayOut,
    float $fx,
    string $txnCur,
    string $token
): string {
    $b = [];
    $b[] = '<b>已入账 (' . (string)(int)$nIn . '笔)</b>';
    if ($rowsIn === []) {
        $b[] = '<i>（无）</i>';
    } else {
        foreach ($rowsIn as $r) {
            if (!is_array($r)) {
                continue;
            }
            $idR = (int)($r['id'] ?? 0);
            $t = (string)($r['txn_time'] ?? '');
            $t5 = strlen($t) >= 5 ? substr($t, 0, 5) : $t;
            $am = round((float)($r['amount'] ?? 0), 2);
            $isCurrent = ($idR === $txId && $flow === 'in');
            $L = $isCurrent ? $principal : $am;
            $R = $isCurrent ? $booked : $am;
            $lbl = tqx_pg_receipt_row_label(
                isset($r['external_ref']) ? (string)$r['external_ref'] : null,
                (string)($r['member_code'] ?? ''),
                isset($r['remark']) ? (string)$r['remark'] : null
            );
            $line = tqx_pg_h($t5) . ' ' . tqx_pg_fmt_receipt_amount($L) . ' = ' . tqx_pg_fmt_receipt_amount($R);
            if ($lbl !== '') {
                $line .= ' ' . tqx_pg_h($lbl);
            }
            $b[] = $line;
        }
    }
    $b[] = '';
    $b[] = '<b>已下发 (' . (string)(int)$nOut . '笔)</b>';
    if ($rowsOut === []) {
        $b[] = '<i>（无）</i>';
    } else {
        foreach ($rowsOut as $r) {
            if (!is_array($r)) {
                continue;
            }
            $idR = (int)($r['id'] ?? 0);
            $t = (string)($r['txn_time'] ?? '');
            $t5 = strlen($t) >= 5 ? substr($t, 0, 5) : $t;
            $am = round((float)($r['amount'] ?? 0), 2);
            $isCurrent = ($idR === $txId && $flow === 'out');
            $L = $isCurrent ? $principal : $am;
            $R = $isCurrent ? $booked : $am;
            $lbl = tqx_pg_receipt_row_label(
                isset($r['external_ref']) ? (string)$r['external_ref'] : null,
                (string)($r['member_code'] ?? ''),
                isset($r['remark']) ? (string)$r['remark'] : null
            );
            $line = tqx_pg_h($t5) . ' ' . tqx_pg_fmt_receipt_amount($L) . ' = ' . tqx_pg_fmt_receipt_amount($R);
            if ($lbl !== '') {
                $line .= ' ' . tqx_pg_h($lbl);
            }
            $b[] = $line;
        }
    }
    $b[] = '';
    $due = round($todayIn * $fx, 2);
    $paid = round($todayOut, 2);
    $pending = max(0.0, round($due - $paid, 2));
    $b[] = '总入款额：' . tqx_pg_fmt_receipt_amount($todayIn);
    $fxDisp = (abs($fx - round($fx, 0)) < 0.0000001) ? (string)(int)$fx : rtrim(rtrim(number_format($fx, 6, '.', ''), '0'), '.');
    $b[] = '固定汇率：' . tqx_pg_h($fxDisp) . '（' . tqx_pg_h($txnCur) . '）';
    $b[] = '应下发：' . tqx_pg_fmt_receipt_amount($due);
    $b[] = '已下发：' . tqx_pg_fmt_receipt_amount($paid);
    $b[] = '未下发：' . tqx_pg_fmt_receipt_amount($pending);
    $b[] = '';
    $b[] = '撤销：<code>' . tqx_pg_h('undo ' . $token) . '</code>';

    return implode("\n", $b);
}

function telegram_pg_quick_txn_handle_update(PDO $pdo, array $update, string $botToken): void
{
    $pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
    tqx_pg_ensure_tables($pdoCat);

    $GLOBALS['__tqx_message_thread_id'] = null;
    $GLOBALS['__tqx_reply_markup'] = null;
    $msg = $update['message'] ?? null;
    if (!is_array($msg)) {
        return;
    }

    if (isset($msg['message_thread_id']) && is_numeric($msg['message_thread_id'])) {
        $tid = (int)$msg['message_thread_id'];
        if ($tid > 0) {
            $GLOBALS['__tqx_message_thread_id'] = $tid;
        }
    }

    $chat = $msg['chat'] ?? [];
    $chatId = trim((string)($chat['id'] ?? ''));
    $text = trim((string)($msg['text'] ?? ''));
    if ($chatId === '' || $text === '') {
        return;
    }

    $topicKey = tqx_pg_topic_key_from_msg($msg);

    $from = $msg['from'] ?? [];
    $tgUserId = isset($from['id']) ? (int)$from['id'] : 0;
    $tgUsername = trim((string)($from['username'] ?? ''));
    $tgFirst = trim((string)($from['first_name'] ?? ''));
    $tgLast = trim((string)($from['last_name'] ?? ''));
    $tgFull = trim($tgFirst . ' ' . $tgLast);
    $who = $tgUsername !== '' ? $tgUsername : ($tgFull !== '' ? $tgFull : ($tgUserId > 0 ? (string)$tgUserId : 'telegram'));

    if (preg_match('/^(customer|\/customer|客户|\/客户)(?:\s+(.+))?$/iu', $text, $mCust)) {
        $cfgCust = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
        if (!$cfgCust) {
            tqx_reply($botToken, $chatId, '本群尚未绑定 PG 公司。请先发 /setup <公司ID或公司代码>');
            return;
        }
        $allowJsonC = (string)($cfgCust['allowed_user_ids'] ?? '');
        $allowArrC = tqx_json_decode_map($allowJsonC);
        $allowC = [];
        foreach ($allowArrC as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $allowC[] = $v;
            }
        }
        $isAdminCust = false;
        if ($tgUserId > 0 && in_array((string)$tgUserId, $allowC, true)) {
            $isAdminCust = true;
        }
        if ($tgUsername !== '' && in_array($tgUsername, $allowC, true)) {
            $isAdminCust = true;
        }
        if (!$isAdminCust) {
            tqx_reply($botToken, $chatId, 'Unauthorized');
            return;
        }
        $curMem = trim((string)($cfgCust['chat_default_member_code'] ?? ''));
        $rawArg = trim((string)($mCust[2] ?? ''));
        if ($rawArg === '') {
            $show = $curMem !== '' ? $curMem : '（未设置）';
            $tkHint = $topicKey !== '0' ? "（当前论坛话题 topic={$topicKey}）" : '';
            tqx_reply($botToken, $chatId, "本话题默认客户代号{$tkHint}：{$show}\n设置：/customer C001\n清除：/customer -");
            return;
        }
        $clear = in_array(strtolower($rawArg), ['-', 'clear', 'none', '空'], true);
        $newCode = $clear ? '' : $rawArg;
        if (!$clear) {
            if (strlen($newCode) > 64) {
                tqx_reply($botToken, $chatId, '代号过长（最多 64 字符）。');
                return;
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $newCode)) {
                tqx_reply($botToken, $chatId, '代号含非法字符。');
                return;
            }
        }
        $cidCust = (int)($cfgCust['company_id'] ?? 0);
        try {
            tqx_pg_bind_row_upsert_fields($pdoCat, $chatId, $topicKey, $cidCust, ['member_code' => $newCode]);
        } catch (Throwable $e) {
            tqx_reply($botToken, $chatId, '保存失败：' . $e->getMessage());
            return;
        }
        $afterShow = $newCode !== '' ? $newCode : '（已清除）';
        tqx_reply($botToken, $chatId, "✅ 默认客户代号已设为：{$afterShow}\n+金额 姓名 [銀行] [備注] 时 member_code 用此处；单段 +金额 词 可作仅备注（须已设 /bank）。");
        return;
    }

    if (preg_match('/^(bank|\/bank|银行|\/银行)(?:\s+(.+))?$/iu', $text, $mBank)) {
        $cfgB = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
        if (!$cfgB) {
            tqx_reply($botToken, $chatId, '本群尚未绑定 PG 公司。请先发 /setup');
            return;
        }
        $allowJsonB = (string)($cfgB['allowed_user_ids'] ?? '');
        $allowArrB = tqx_json_decode_map($allowJsonB);
        $allowB = [];
        foreach ($allowArrB as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $allowB[] = $v;
            }
        }
        $isAdmB = ($tgUserId > 0 && in_array((string)$tgUserId, $allowB, true))
            || ($tgUsername !== '' && in_array($tgUsername, $allowB, true));
        if (!$isAdmB) {
            tqx_reply($botToken, $chatId, 'Unauthorized');
            return;
        }
        $curB = trim((string)($cfgB['chat_default_bank'] ?? ''));
        $rawB = trim((string)($mBank[2] ?? ''));
        if ($rawB === '') {
            $showB = $curB !== '' ? $curB : '（未设置）';
            $tkHint = $topicKey !== '0' ? " topic={$topicKey}" : '';
            tqx_reply($botToken, $chatId, "本话题默认银行/渠道{$tkHint}：{$showB}\n设置：/bank HLB 或 银行 CHANNEL1\n清除：/bank -\n+金额 姓名 可省略銀行时用此处；消息裡寫銀行則只覆蓋該筆。純 +金额 亦用此默認。");
            return;
        }
        $clearB = in_array(strtolower($rawB), ['-', 'clear', 'none', '空'], true);
        $newB = $clearB ? '' : $rawB;
        if (!$clearB && (strlen($newB) > 64 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $newB))) {
            tqx_reply($botToken, $chatId, '内容过长或含非法字符（最多 64 字符）。');
            return;
        }
        try {
            tqx_pg_bind_row_upsert_fields($pdoCat, $chatId, $topicKey, (int)($cfgB['company_id'] ?? 0), ['default_bank' => $newB]);
        } catch (Throwable $e) {
            tqx_reply($botToken, $chatId, '保存失败：' . $e->getMessage());
            return;
        }
        tqx_reply($botToken, $chatId, '✅ 默认银行/渠道已更新。+金额 姓名 未写銀行时用此处；消息里写銀行则只覆盖该笔记录。');
        return;
    }

    if (preg_match('/^(currency|\/currency|币种|\/币种|幣種|\/幣種)(?:\s+(.+))?$/iu', $text, $mCur)) {
        $cfgC = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
        if (!$cfgC) {
            tqx_reply($botToken, $chatId, '本群尚未绑定 PG 公司。请先发 /setup');
            return;
        }
        $allowJsonC = (string)($cfgC['allowed_user_ids'] ?? '');
        $allowArrC = tqx_json_decode_map($allowJsonC);
        $allowC = [];
        foreach ($allowArrC as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $allowC[] = $v;
            }
        }
        $isAdmC = ($tgUserId > 0 && in_array((string)$tgUserId, $allowC, true))
            || ($tgUsername !== '' && in_array($tgUsername, $allowC, true));
        if (!$isAdmC) {
            tqx_reply($botToken, $chatId, 'Unauthorized');
            return;
        }
        $rawC = trim((string)($mCur[2] ?? ''));
        if ($rawC === '') {
            $eff = (string)($cfgC['txn_currency'] ?? 'MYR');
            $tkHint = $topicKey !== '0' ? " topic={$topicKey}" : '';
            $compC = (string)($cfgC['company_currency'] ?? 'MYR');
            tqx_reply($botToken, $chatId, "本话题记账币种{$tkHint}：{$eff}\n（分公司默认：{$compC}；后台可设 pg_simple_currency）\n设置：/currency USD\n清除：/currency -");
            return;
        }
        $clearC = in_array(strtolower($rawC), ['-', 'clear', 'none', '空'], true);
        $norm = $clearC ? '' : tqx_pg_normalize_currency($rawC);
        if (!$clearC && $norm === '') {
            tqx_reply($botToken, $chatId, '币种须为 2～8 位大写字母（如 MYR、USD）。');
            return;
        }
        try {
            tqx_pg_bind_row_upsert_fields($pdoCat, $chatId, $topicKey, (int)($cfgC['company_id'] ?? 0), ['default_currency' => $norm]);
        } catch (Throwable $e) {
            tqx_reply($botToken, $chatId, '保存失败：' . $e->getMessage());
            return;
        }
        $cfgAfter = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
        $show = $cfgAfter ? (string)($cfgAfter['txn_currency'] ?? 'MYR') : 'MYR';
        tqx_reply($botToken, $chatId, '✅ 本话题记账币种已更新。此后入账 pg_transactions.currency 为：' . $show . '（清除后按后台/分公司默认）。');
        return;
    }

    if (!preg_match('/^(\+|-|undo\b|cancel\b|撤销|取消|id\b|\/id\b|setup\b|\/setup\b|add\s+admin\b|remove\s+admin\b|del\s+admin\b|list\s+admin\b|\\/(addadmin|removeadmin|deladmin|listadmin)\\b)/i', $text)) {
        return;
    }

    $parsed = tqx_parse_command($text);
    if (!$parsed['ok']) {
        $t0 = trim($text);
        if (preg_match('/^([+-])\s*([0-9][0-9,]*(?:\.[0-9]+)?)(?:\s+(.+))?$/u', $t0, $mxAmt)) {
            $sign0 = (string)$mxAmt[1];
            $amtRaw0 = str_replace(',', '', (string)$mxAmt[2]);
            $rest0 = isset($mxAmt[3]) ? trim((string)$mxAmt[3]) : '';
            if ($rest0 !== '' && is_numeric($amtRaw0) && round((float)$amtRaw0, 2) > 0) {
                $parts0 = preg_split('/\s+/', $rest0) ?: [];
                $parts0 = array_values(array_filter(array_map('trim', $parts0), fn($x) => $x !== ''));
                $n0 = count($parts0);
                $cfgE = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
                if ($n0 === 1 && is_array($cfgE)) {
                    $memE = trim((string)($cfgE['chat_default_member_code'] ?? ''));
                    $sbE = trim((string)($cfgE['chat_default_bank'] ?? ''));
                    if ($sbE === '') {
                        $sbE = trim((string)($cfgE['pg_simple_bank'] ?? ''));
                    }
                    if ($memE !== '' && $sbE !== '') {
                        $tailFeat = tqx_pg_parse_remark_tail_features((string)$parts0[0]);
                        $amtF = round((float)$amtRaw0, 2);
                        $parsed = [
                            'ok' => true,
                            'cmd' => $sign0 === '+' ? 'deposit' : 'withdraw',
                            'data' => [
                                'amount' => $amtF,
                                'code' => $memE,
                                'bank' => $sbE,
                                'product' => '',
                                'bonus_pct' => $tailFeat['bonus_pct'],
                                'mega_account' => $tailFeat['mega_account'],
                                'game_id' => $tailFeat['game_id'],
                                'balance' => $tailFeat['balance'],
                                'remark' => $tailFeat['remark'],
                            ],
                        ];
                    }
                }
                if (!$parsed['ok'] && is_array($cfgE) && $n0 >= 1) {
                    $fx = tqx_pg_flex_to_parsed($sign0, $amtRaw0, $parts0, $cfgE);
                    if ($fx['ok']) {
                        $parsed = $fx;
                    } elseif (trim((string)($fx['err'] ?? '')) !== '') {
                        $parsed = ['ok' => false, 'err' => (string)$fx['err']];
                    }
                }
            }
        }
    }
    $pgShort = null;
    if (!$parsed['ok']) {
        if (preg_match('/^\+[\s]*([0-9][0-9,]*(?:\.[0-9]+)?)\s*$/u', $text, $wm)) {
            $pgShort = ['sign' => '+', 'amt' => str_replace(',', '', (string)$wm[1])];
        } elseif (preg_match('/^-[\s]*([0-9][0-9,]*(?:\.[0-9]+)?)\s*$/u', $text, $wm2)) {
            $pgShort = ['sign' => '-', 'amt' => str_replace(',', '', (string)$wm2[1])];
        } else {
            $hint = "PG：+金额 姓名 [銀行] [備注]（銀行/備注可省；未設 /bank 時須在消息裡寫銀行；已設默認銀行時消息裡寫銀行只覆蓋本筆）。已设 /customer 时「+金额 一段」可作仅备注。纯 +金额 须已设 /customer 与 /bank。\n";
            if (trim((string)($parsed['err'] ?? '')) === 'need_bank') {
                $hint = "PG：未設默認銀行時，請寫 +金额 姓名 銀行 [備注]，或先 /bank。\n";
            }
            tqx_reply($botToken, $chatId, $hint . '（解析失败：' . trim((string)($parsed['err'] ?? 'Invalid')) . '）');
            return;
        }
    }

    if ($pgShort === null && ($parsed['cmd'] ?? '') === 'id') {
        $out = "chat_id={$chatId}\ntopic_key={$topicKey}\nuser_id=" . ($tgUserId > 0 ? (string)$tgUserId : '-') . "\nusername=" . ($tgUsername !== '' ? $tgUsername : '-');
        tqx_reply($botToken, $chatId, $out);
        return;
    }

    if ($pgShort === null && ($parsed['cmd'] ?? '') === 'setup') {
        $arg = trim((string)($parsed['data']['arg'] ?? ''));
        $pickCompanyId = 0;
        try {
            $cnt = (int)$pdoCat->query("SELECT COUNT(*) FROM companies WHERE is_active = 1")->fetchColumn();
            if ($cnt === 1) {
                $pickCompanyId = (int)$pdoCat->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
        } catch (Throwable $e) {
        }
        if ($pickCompanyId <= 0 && $arg !== '') {
            if (preg_match('/^[0-9]+$/', $arg)) {
                $pickCompanyId = (int)$arg;
            } else {
                try {
                    $st = $pdoCat->prepare("SELECT id FROM companies WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) LIMIT 1");
                    $st->execute([$arg]);
                    $pickCompanyId = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                }
            }
        }
        if ($pickCompanyId <= 0) {
            tqx_reply($botToken, $chatId, "无法自动选择公司。请用：/setup <company_id> 或 /setup <company_code>");
            return;
        }

        $bk = '';
        try {
            $stBk = $pdoCat->prepare("SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1");
            $stBk->execute([$pickCompanyId]);
            $bk = strtolower(trim((string)$stBk->fetchColumn()));
        } catch (Throwable $e) {
        }
        if ($bk !== 'pg') {
            tqx_reply($botToken, $chatId, "PG 专用 Bot 只能绑定 business_kind=pg 的公司。当前公司不是 PG。");
            return;
        }

        $allow = [];
        try {
            $stCur = $pdoCat->prepare("SELECT allowed_user_ids FROM telegram_quick_txn_config_pg WHERE company_id = ? LIMIT 1");
            $stCur->execute([$pickCompanyId]);
            $curJson = (string)($stCur->fetchColumn() ?: '');
            foreach (tqx_json_decode_map($curJson) as $v) {
                $v = trim((string)$v);
                if ($v !== '') {
                    $allow[] = $v;
                }
            }
        } catch (Throwable $e) {
        }
        $me = '';
        if ($tgUserId > 0) {
            $me = (string)$tgUserId;
        } elseif ($tgUsername !== '') {
            $me = $tgUsername;
        }
        if ($me !== '' && !in_array($me, $allow, true)) {
            $allow[] = $me;
        }

        $uid0 = null;
        $stUp = $pdoCat->prepare("INSERT INTO telegram_quick_txn_config_pg
            (company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec, updated_by)
            VALUES (?, 1, ?, ?,
                COALESCE((SELECT bank_alias_json FROM telegram_quick_txn_config_pg x WHERE x.company_id=? LIMIT 1), '{}'),
                COALESCE((SELECT product_alias_json FROM telegram_quick_txn_config_pg y WHERE y.company_id=? LIMIT 1), '{}'),
                COALESCE((SELECT staff_alias_json FROM telegram_quick_txn_config_pg s WHERE s.company_id=? LIMIT 1), '{}'),
                COALESCE((SELECT receipt_prefix FROM telegram_quick_txn_config_pg p WHERE p.company_id=? LIMIT 1), NULL),
                COALESCE((SELECT receipt_slogan FROM telegram_quick_txn_config_pg q WHERE q.company_id=? LIMIT 1), NULL),
                COALESCE((SELECT receipt_style FROM telegram_quick_txn_config_pg r WHERE r.company_id=? LIMIT 1), 'classic'),
                COALESCE((SELECT undo_window_sec FROM telegram_quick_txn_config_pg z WHERE z.company_id=? LIMIT 1), 600),
                ?)
            ON DUPLICATE KEY UPDATE enabled=1, chat_id=VALUES(chat_id), allowed_user_ids=VALUES(allowed_user_ids), updated_by=VALUES(updated_by)");
        $stUp->execute([
            $pickCompanyId,
            $chatId,
            json_encode($allow, JSON_UNESCAPED_UNICODE),
            $pickCompanyId,
            $pickCompanyId,
            $pickCompanyId,
            $pickCompanyId,
            $pickCompanyId,
            $pickCompanyId,
            $pickCompanyId,
            $uid0,
        ]);

        try {
            $stB = $pdoCat->prepare("INSERT INTO telegram_pg_chat_customer_pg (chat_id, topic_key, company_id, member_code, default_bank, default_product, default_currency) VALUES (?, '0', ?, '', '', '', '')
                ON DUPLICATE KEY UPDATE
                    company_id = VALUES(company_id),
                    member_code = IF(telegram_pg_chat_customer_pg.company_id <> VALUES(company_id), '', telegram_pg_chat_customer_pg.member_code),
                    default_bank = IF(telegram_pg_chat_customer_pg.company_id <> VALUES(company_id), '', telegram_pg_chat_customer_pg.default_bank),
                    default_product = IF(telegram_pg_chat_customer_pg.company_id <> VALUES(company_id), '', telegram_pg_chat_customer_pg.default_product),
                    default_currency = IF(telegram_pg_chat_customer_pg.company_id <> VALUES(company_id), '', telegram_pg_chat_customer_pg.default_currency)");
            $stB->execute([$chatId, $pickCompanyId]);
        } catch (Throwable $e) {
        }

        tqx_reply($botToken, $chatId, "✅ PG 群已绑定\nchat_id={$chatId}\ncompany_id={$pickCompanyId}\n已加入白名单。\n\n当前话题可设：/customer 代号  /bank 渠道  /currency 币种\n（论坛各话题可分别设置；PG 无产品段）");
        return;
    }

    $cfg = tqx_pg_load_cfg_for_chat($pdoCat, $chatId, $topicKey);
    if (!$cfg) {
        return;
    }

    $companyId = (int)($cfg['company_id'] ?? 0);
    if ($companyId <= 0) {
        return;
    }

    try {
        $stBk = $pdoCat->prepare("SELECT LOWER(TRIM(business_kind)) FROM companies WHERE id = ? LIMIT 1");
        $stBk->execute([$companyId]);
        $bk = strtolower(trim((string)$stBk->fetchColumn()));
        if ($bk !== 'pg') {
            tqx_reply($botToken, $chatId, "此群绑定的是非 PG 公司，请用 gaming 专用机器人。");
            return;
        }
    } catch (Throwable $e) {
    }

    $pdoData = function_exists('pdo_data_for_company_id') ? pdo_data_for_company_id($pdoCat, $companyId) : $pdoCat;

    if ($pgShort !== null) {
        $amt0 = (float)($pgShort['amt'] ?? 0);
        if ($amt0 <= 0 || !is_finite($amt0)) {
            tqx_reply($botToken, $chatId, '金额须大于 0。');
            return;
        }
        $scChat = trim((string)($cfg['chat_default_member_code'] ?? ''));
        $sc = $scChat !== '' ? $scChat : trim((string)($cfg['pg_simple_member_code'] ?? ''));
        $sbChat = trim((string)($cfg['chat_default_bank'] ?? ''));
        $sb = $sbChat !== '' ? $sbChat : trim((string)($cfg['pg_simple_bank'] ?? ''));
        if ($sc === '' || $sb === '') {
            tqx_reply($botToken, $chatId, "极简指令：发纯 +金额（也可 -金额）。\n请设 /customer 与 /bank（可设在任意论坛话题）。\n一般记账：+金额 姓名 [銀行] [備注]；已设 /customer 时可用 +金额 一段 作仅备注。\n也可：+金额 姓名 銀行 備注（未设默認銀行時銀行必填）。");
            return;
        }
        $sign = ($pgShort['sign'] ?? '+') === '-' ? '-' : '+';
        $expanded = $sign . $pgShort['amt'] . ' ' . $sc . ' ' . $sb;
        $parsed = tqx_pg_parse_money_line($expanded);
        if (!$parsed['ok']) {
            tqx_reply($botToken, $chatId, (string)($parsed['err'] ?? 'Invalid'));
            return;
        }
    }

    $allowJson = (string)($cfg['allowed_user_ids'] ?? '');
    $allowArr = tqx_json_decode_map($allowJson);
    $allow = [];
    foreach ($allowArr as $v) {
        $v = trim((string)$v);
        if ($v !== '') {
            $allow[] = $v;
        }
    }
    $is_admin_sender = false;
    if ($tgUserId > 0 && in_array((string)$tgUserId, $allow, true)) {
        $is_admin_sender = true;
    }
    if ($tgUsername !== '' && in_array($tgUsername, $allow, true)) {
        $is_admin_sender = true;
    }
    if (!$is_admin_sender) {
        tqx_reply($botToken, $chatId, 'Unauthorized');
        return;
    }

    $bankAlias = tqx_json_decode_map((string)($cfg['bank_alias_json'] ?? ''));
    $staffAlias = tqx_json_decode_map((string)($cfg['staff_alias_json'] ?? ''));
    if ($tgUserId > 0) {
        $k = (string)$tgUserId;
        if (isset($staffAlias[$k]) && trim((string)$staffAlias[$k]) !== '') {
            $who = trim((string)$staffAlias[$k]);
        }
    }

    if (in_array(($parsed['cmd'] ?? ''), ['admin_list', 'admin_add', 'admin_remove', 'admin_add_reply', 'admin_remove_reply'], true)) {
        $cmd = (string)$parsed['cmd'];
        if ($cmd === 'admin_list') {
            tqx_reply($botToken, $chatId, $allow ? ("Admins:\n" . implode("\n", $allow)) : '白名单为空。');
            return;
        }
        $targetId = '';
        if ($cmd === 'admin_add' || $cmd === 'admin_remove') {
            $targetId = trim((string)($parsed['data']['user_id'] ?? ''));
        } else {
            $rep = $msg['reply_to_message'] ?? null;
            $repFrom = is_array($rep) ? ($rep['from'] ?? null) : null;
            $repUid = is_array($repFrom) ? (string)($repFrom['id'] ?? '') : '';
            $targetId = trim($repUid);
            if ($targetId === '') {
                tqx_reply($botToken, $chatId, '请回复要添加/移除的那个人的消息。');
                return;
            }
        }
        if ($targetId === '' || !preg_match('/^[0-9]{4,20}$/', $targetId)) {
            tqx_reply($botToken, $chatId, 'ID 不正确。');
            return;
        }
        $new = $allow;
        if ($cmd === 'admin_add' || $cmd === 'admin_add_reply') {
            if (!in_array($targetId, $new, true)) {
                $new[] = $targetId;
            }
        } else {
            if ($tgUserId > 0 && (string)$tgUserId === $targetId) {
                tqx_reply($botToken, $chatId, '不能移除自己（避免锁死管理权限）。');
                return;
            }
            $new = array_values(array_filter($new, fn($x) => (string)$x !== $targetId));
        }
        $pdoCat->prepare('UPDATE telegram_quick_txn_config_pg SET allowed_user_ids = ? WHERE company_id = ? LIMIT 1')
            ->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $companyId]);
        tqx_reply($botToken, $chatId, "✅ 已更新 admin 列表。可用：list admin\n(PG bot)");
        return;
    }

    if (($parsed['cmd'] ?? '') === 'cancel') {
        $rep = $msg['reply_to_message'] ?? null;
        $repText = is_array($rep) ? trim((string)($rep['text'] ?? '')) : '';
        $repMid = is_array($rep) ? (int)($rep['message_id'] ?? 0) : 0;
        $token = '';
        if ($repText !== '' && preg_match('/\bundo\s+([A-Za-z0-9_-]{6,40})\b/i', $repText, $mm)) {
            $token = $mm[1];
        } elseif ($repText !== '' && preg_match('/#([A-Za-z0-9_-]{6,40})\b/', $repText, $mm2)) {
            $token = $mm2[1];
        }
        if ($token === '' && $repMid > 0) {
            $stLog2 = $pdoCat->prepare('SELECT token FROM telegram_quick_txn_log_pg WHERE receipt_message_id = ? AND company_id = ? AND chat_id = ? ORDER BY id DESC LIMIT 1');
            $stLog2->execute([$repMid, $companyId, $chatId]);
            $token = (string)($stLog2->fetchColumn() ?: '');
        }
        if ($token === '') {
            tqx_reply($botToken, $chatId, '请回复机器人那条回执消息再发 cancel。');
            return;
        }
        $parsed = ['cmd' => 'undo', 'data' => ['token' => $token], 'ok' => true];
    }

    if (($parsed['cmd'] ?? '') === 'undo') {
        $token = (string)($parsed['data']['token'] ?? '');
        if ($token === '') {
            return;
        }

        $stLog = $pdoCat->prepare("SELECT l.*, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS age_sec
            FROM telegram_quick_txn_log_pg l
            WHERE l.token = ? AND l.company_id = ?
            ORDER BY l.id DESC LIMIT 1");
        $stLog->execute([$token, $companyId]);
        $log = $stLog->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            tqx_reply($botToken, $chatId, '找不到可撤销记录。');
            return;
        }
        $undoWin = (int)($cfg['undo_window_sec'] ?? 600);
        $ageSec = isset($log['age_sec']) && is_numeric((string)$log['age_sec']) ? (int)$log['age_sec'] : null;
        if ($ageSec !== null && $ageSec > max(30, $undoWin)) {
            tqx_reply($botToken, $chatId, '已超出可撤销时间。');
            return;
        }
        $txId = (int)($log['transaction_id'] ?? 0);
        if ($txId <= 0) {
            tqx_reply($botToken, $chatId, '该记录不可撤销。');
            return;
        }

        $tx = null;
        try {
            $stTx = $pdoData->prepare('SELECT txn_day, txn_time, flow, amount, member_code, channel, remark, status
                FROM pg_transactions WHERE id = ? AND company_id = ? LIMIT 1');
            $stTx->execute([$txId, $companyId]);
            $tx = $stTx->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $tx = null;
        }

        $stmt = $pdoData->prepare("UPDATE pg_transactions SET status = 'rejected', remark = CONCAT(IFNULL(remark,''), ' [undo {$token}]')
            WHERE id = ? AND company_id = ? AND status = 'approved'");
        $stmt->execute([$txId, $companyId]);
        if ($stmt->rowCount() > 0) {
            $msgOut = "✅ 已撤销 #{$token}";
            if (is_array($tx)) {
                $msgOut .= "\n" . strtoupper((string)($tx['flow'] ?? '')) . ' ' . number_format((float)($tx['amount'] ?? 0), 2, '.', '');
                $c = trim((string)($tx['member_code'] ?? ''));
                if ($c !== '') {
                    $msgOut .= "\n代号：{$c}";
                }
                $ch = trim((string)($tx['channel'] ?? ''));
                if ($ch !== '') {
                    $msgOut .= "\n渠道：{$ch}";
                }
            }
            tqx_reply($botToken, $chatId, $msgOut);
        } else {
            tqx_reply($botToken, $chatId, '撤销失败（可能已撤销/不存在）。');
        }
        return;
    }

    $data = $parsed['data'] ?? [];
    $amount = (float)($data['amount'] ?? 0);
    $extRefIns = null;

    if (!empty($data['pg_flex'])) {
        $nameDisplay = trim((string)($data['name_display'] ?? ''));
        $remark = trim((string)($data['remark'] ?? ''));
        $bonusPct = $data['bonus_pct'] ?? null;
        $bankIn = trim((string)($data['bank'] ?? ''));
        if ($bankIn === '') {
            tqx_reply($botToken, $chatId, '缺少银行/渠道。请写 +金额 姓名 銀行 [備注] 或先 /bank。');
            return;
        }
        if ($nameDisplay === '') {
            tqx_reply($botToken, $chatId, '缺少姓名。例：+100 张三 [HLB] [備注]');
            return;
        }
        $memDefT = trim((string)($cfg['chat_default_member_code'] ?? ''));
        if ($memDefT !== '') {
            $code = $memDefT;
            $extRefIns = $nameDisplay;
        } else {
            $code = $nameDisplay;
            $extRefIns = null;
        }
    } else {
        $code = trim((string)($data['code'] ?? ''));
        $bankIn = trim((string)($data['bank'] ?? ''));
        $remark = trim((string)($data['remark'] ?? ''));
        $bonusPct = $data['bonus_pct'] ?? null;

        $chatDefMember = trim((string)($cfg['chat_default_member_code'] ?? ''));
        if ($chatDefMember !== '') {
            $code = $chatDefMember;
        }

        if ($code === '' || $bankIn === '') {
            tqx_reply($botToken, $chatId, '缺少字段。例：+100 C011 HLB 备注（PG 无产品段）');
            return;
        }
    }

    $bankKey = tqx_norm_key($bankIn);
    if ($bankKey !== '' && isset($bankAlias[$bankKey])) {
        $bankIn = (string)$bankAlias[$bankKey];
    }

    if ($code === '' || $bankIn === '') {
        tqx_reply($botToken, $chatId, '缺少字段。例：+100 C011 HLB 备注（PG 无产品段）');
        return;
    }

    $bonus = 0.0;
    if ($bonusPct !== null && is_numeric((string)$bonusPct)) {
        $bonus = round($amount * ((float)$bonusPct) / 100, 2);
    }
    $total = round($amount + $bonus, 2);
    $day = date('Y-m-d');
    $time = date('H:i:s');
    $flow = (($parsed['cmd'] ?? '') === 'deposit') ? 'in' : 'out';
    $channel = trim($bankIn);
    $lineRemark = $remark;
    if ($bonus > 0) {
        $lineRemark = trim($lineRemark . " [bonus {$bonus}]");
    }

    $txnCur = tqx_pg_normalize_currency((string)($cfg['txn_currency'] ?? ''));
    if ($txnCur === '') {
        $txnCur = 'MYR';
    }
    try {
        $pdoData->prepare('INSERT INTO pg_transactions (company_id, txn_day, txn_time, flow, amount, currency, external_ref, channel, member_code, status, remark, staff, created_by, created_at, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NOW())')
            ->execute([$companyId, $day, $time, $flow, $total, $txnCur, ($extRefIns !== null && $extRefIns !== '' ? $extRefIns : null), $channel, $code, 'approved', ($lineRemark !== '' ? $lineRemark : null), $who]);
        $txId = (int)$pdoData->lastInsertId();
    } catch (Throwable $e) {
        tqx_reply($botToken, $chatId, '写入失败：' . $e->getMessage());
        return;
    }

    $todayIn = 0.0;
    $todayOut = 0.0;
    $nIn = 0;
    $nOut = 0;
    $rowsIn = [];
    $rowsOut = [];
    try {
        $stSum = $pdoData->prepare("SELECT COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0), COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) FROM pg_transactions WHERE company_id = ? AND txn_day = ? AND status = 'approved'");
        $stSum->execute([$companyId, $day]);
        $rowSum = $stSum->fetch(PDO::FETCH_NUM);
        if (is_array($rowSum)) {
            $todayIn = (float)($rowSum[0] ?? 0);
            $todayOut = (float)($rowSum[1] ?? 0);
        }
        $stNi = $pdoData->prepare("SELECT COUNT(*) FROM pg_transactions WHERE company_id = ? AND txn_day = ? AND status = 'approved' AND flow = 'in'");
        $stNi->execute([$companyId, $day]);
        $nIn = (int)$stNi->fetchColumn();
        $stNo = $pdoData->prepare("SELECT COUNT(*) FROM pg_transactions WHERE company_id = ? AND txn_day = ? AND status = 'approved' AND flow = 'out'");
        $stNo->execute([$companyId, $day]);
        $nOut = (int)$stNo->fetchColumn();
        $stRi = $pdoData->prepare("SELECT id, txn_time, amount, external_ref, member_code, remark FROM pg_transactions WHERE company_id = ? AND txn_day = ? AND status = 'approved' AND flow = 'in' ORDER BY id ASC LIMIT 15");
        $stRi->execute([$companyId, $day]);
        $rowsIn = $stRi->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stRo = $pdoData->prepare("SELECT id, txn_time, amount, external_ref, member_code, remark FROM pg_transactions WHERE company_id = ? AND txn_day = ? AND status = 'approved' AND flow = 'out' ORDER BY id ASC LIMIT 15");
        $stRo->execute([$companyId, $day]);
        $rowsOut = $stRo->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    $token = substr(hash('sha256', $chatId . '|' . $tgUserId . '|' . microtime(true) . '|' . $txId), 0, 10);
    $fx = (float)($cfg['receipt_fx_rate'] ?? 1);
    if ($fx <= 0 || !is_finite($fx)) {
        $fx = 1.0;
    }
    $body = tqx_pg_build_receipt_body_html($nIn, $nOut, $rowsIn, $rowsOut, $flow, $txId, $amount, $total, $todayIn, $todayOut, $fx, $txnCur, $token);
    $reply = '';
    $rp = trim((string)($cfg['receipt_prefix'] ?? ''));
    if ($rp !== '') {
        $reply .= '<b>' . tqx_pg_h($rp) . '</b>' . "\n\n";
    }
    $reply .= $body;

    global $NOTIFY_BASE_URL;
    if (!empty($NOTIFY_BASE_URL)) {
        $GLOBALS['__tqx_reply_markup'] = [
            'inline_keyboard' => [[[
                'text' => '账单明细',
                'url' => rtrim((string)$NOTIFY_BASE_URL, '/') . '/admin_telegram_pg.php?company_id=' . $companyId,
            ]]],
        ];
    }

    $pdoCat->prepare('INSERT INTO telegram_quick_txn_log_pg (company_id, chat_id, tg_user_id, tg_username, raw_text, action, token, transaction_id, receipt_message_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)')
        ->execute([
            $companyId,
            $chatId,
            $tgUserId > 0 ? $tgUserId : null,
            $tgUsername !== '' ? $tgUsername : null,
            $text,
            $flow,
            $token,
            $txId,
        ]);

    $receiptMid = tqx_reply($botToken, $chatId, $reply, 'HTML');
    $GLOBALS['__tqx_reply_markup'] = null;
    if ($receiptMid) {
        try {
            $pdoCat->prepare('UPDATE telegram_quick_txn_log_pg SET receipt_message_id = ? WHERE company_id = ? AND chat_id = ? AND token = ? AND transaction_id = ? ORDER BY id DESC LIMIT 1')
                ->execute([(int)$receiptMid, $companyId, $chatId, $token, $txId]);
        } catch (Throwable $e) {
        }
    }
}
