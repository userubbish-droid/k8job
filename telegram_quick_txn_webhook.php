    <?php
/**
 * Telegram 群聊快捷记账（独立文件，可整套删除）
 *
 * 支持：
 * - +100 C011 P M [remark...]
 * - -200 C012 P M [remark...]
 * - undo <token>
 *
 * 缩写映射与权限在 admin_telegram_quick_txn.php 配置。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/notify.php';
require_once __DIR__ . '/inc/transaction_soft_delete.php';

function tqx_ensure_tables(PDO $pdo): void {
    // 配置表：按公司配置允许群/允许用户/别名映射等
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_config (
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
    )");
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config ADD COLUMN staff_alias_json TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config ADD COLUMN receipt_prefix TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config ADD COLUMN receipt_slogan TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_quick_txn_config ADD COLUMN receipt_style VARCHAR(20) NOT NULL DEFAULT 'classic'"); } catch (Throwable $e) {}
    // 日志表：用于撤销
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_quick_txn_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        chat_id VARCHAR(40) NOT NULL,
        tg_user_id BIGINT NULL,
        tg_username VARCHAR(120) NULL,
        raw_text TEXT NULL,
        action VARCHAR(40) NOT NULL,
        token VARCHAR(40) NOT NULL,
        transaction_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_company_chat (company_id, chat_id),
        KEY idx_token (token)
    )");
}

function tqx_json_decode_map(?string $json): array {
    $json = trim((string)$json);
    if ($json === '') return [];
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

function tqx_norm_key(string $s): string {
    return strtolower(trim($s));
}

function tqx_reply(string $botToken, string $chatId, string $text): void {
    if ($botToken === '' || $chatId === '' || $text === '') return;
    send_telegram_message($botToken, $chatId, $text);
}

/**
 * @return array{ok:bool,err?:string,cmd?:string,data?:array}
 */
function tqx_parse_command(string $text): array {
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'err' => 'Empty'];

    // id: return chat_id/user_id/username
    if (preg_match('/^(id|\\/id)\\b/i', $text)) {
        return ['ok' => true, 'cmd' => 'id'];
    }

    // setup: bind this group chat id & set first admin
    // - /setup
    // - /setup 3
    // - /setup CS1
    if (preg_match('/^(setup|\\/setup)\\b/i', $text)) {
        $arg = '';
        if (preg_match('/^(?:setup|\\/setup)\\s+(.+?)\\s*$/i', $text, $m)) {
            $arg = trim((string)$m[1]);
        }
        return ['ok' => true, 'cmd' => 'setup', 'data' => ['arg' => $arg]];
    }

    // admin whitelist management
    // - add admin 123456
    // - remove admin 123456
    // - list admin
    // slash variants (work with bot privacy mode):
    // - /addadmin [id]
    // - /removeadmin [id]
    // - /listadmin
    if (preg_match('/^\\/(addadmin|removeadmin|deladmin|listadmin)\\b/i', $text)) {
        $t = strtolower($text);
        if (preg_match('/^\\/(listadmin)\\b/i', $t)) {
            return ['ok' => true, 'cmd' => 'admin_list'];
        }
        if (preg_match('/^\\/(addadmin)\\s+([0-9]{4,20})\\b/i', $text, $m)) {
            return ['ok' => true, 'cmd' => 'admin_add', 'data' => ['user_id' => (string)$m[2]]];
        }
        if (preg_match('/^\\/(removeadmin|deladmin)\\s+([0-9]{4,20})\\b/i', $text, $m)) {
            return ['ok' => true, 'cmd' => 'admin_remove', 'data' => ['user_id' => (string)$m[2]]];
        }
        if (preg_match('/^\\/(addadmin)\\s*$/i', $text)) {
            return ['ok' => true, 'cmd' => 'admin_add_reply'];
        }
        if (preg_match('/^\\/(removeadmin|deladmin)\\s*$/i', $text)) {
            return ['ok' => true, 'cmd' => 'admin_remove_reply'];
        }
        return ['ok' => false, 'err' => "格式：/addadmin <id> / /removeadmin <id> / /listadmin（也可回复某人消息发 /addadmin）"];
    }

    if (preg_match('/^(add\\s+admin|remove\\s+admin|del\\s+admin|list\\s+admin)\\b/i', $text)) {
        $t = strtolower($text);
        if (strpos($t, 'list admin') === 0) {
            return ['ok' => true, 'cmd' => 'admin_list'];
        }
        if (preg_match('/^(add\\s+admin)\\s+([0-9]{4,20})\\b/i', $text, $m)) {
            return ['ok' => true, 'cmd' => 'admin_add', 'data' => ['user_id' => (string)$m[2]]];
        }
        if (preg_match('/^((remove|del)\\s+admin)\\s+([0-9]{4,20})\\b/i', $text, $m)) {
            return ['ok' => true, 'cmd' => 'admin_remove', 'data' => ['user_id' => (string)$m[3]]];
        }
        if (preg_match('/^(add\\s+admin)\\s*$/i', $text)) {
            return ['ok' => true, 'cmd' => 'admin_add_reply'];
        }
        if (preg_match('/^((remove|del)\\s+admin)\\s*$/i', $text)) {
            return ['ok' => true, 'cmd' => 'admin_remove_reply'];
        }
        return ['ok' => false, 'err' => "格式：add admin <id> / remove admin <id> / list admin（也可回复某人消息发 add admin）"];
    }

    // cancel (reply-to bot receipt preferred)
    if (preg_match('/^(cancel|撤销|取消)\b/i', $text)) {
        // optional token: cancel XXXXX
        if (preg_match('/^(?:cancel|撤销|取消)\s+([A-Za-z0-9_-]{6,40})$/i', $text, $m2)) {
            return ['ok' => true, 'cmd' => 'undo', 'data' => ['token' => $m2[1]]];
        }
        return ['ok' => true, 'cmd' => 'cancel'];
    }

    // undo TOKEN
    if (preg_match('/^undo\s+([A-Za-z0-9_-]{6,40})$/i', $text, $m)) {
        return ['ok' => true, 'cmd' => 'undo', 'data' => ['token' => $m[1]]];
    }

    // +100 C011 P M [b10] [mega_account] [game_id] [balance] [remark...]
    if (!preg_match('/^([+-])\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s+(\S+)\s+(\S+)\s+(\S+)(?:\s+(.*))?$/u', $text, $m)) {
        return ['ok' => false, 'err' => "格式不对。例：+100 C011 P M 备注 或 -200 C012 P M 备注"];
    }
    $sign = $m[1];
    $amtRaw = str_replace(',', '', $m[2]);
    $code = $m[3];
    $bank = $m[4];
    $product = $m[5];
    $tail = isset($m[6]) ? trim((string)$m[6]) : '';

    if (!is_numeric($amtRaw)) return ['ok' => false, 'err' => '金额不是数字'];
    $amount = round((float)$amtRaw, 2);
    if ($amount <= 0) return ['ok' => false, 'err' => '金额需大于 0'];

    $bonusPct = null;
    $remark = $tail;
    if ($tail !== '') {
        // b10 或 b10%
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

    // 可选解析：mega账号 / 游戏ID / 余额（尽量简单：按空格切 3 个字段）
    $megaAccount = '';
    $gameId = '';
    $balance = null;
    $restRemark = $remark;
    if ($remark !== '') {
        $parts = preg_split('/\s+/', $remark) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
        if (count($parts) >= 3) {
            $p0 = (string)$parts[0];
            $p1 = (string)$parts[1];
            $p2 = (string)$parts[2];
            // 余额必须是数字（允许逗号）
            $balRaw = str_replace(',', '', $p2);
            if ($p0 !== '' && $p1 !== '' && is_numeric($balRaw)) {
                $megaAccount = $p0;
                $gameId = $p1;
                $balance = round((float)$balRaw, 2);
                $restRemark = trim(implode(' ', array_slice($parts, 3)));
            }
        }
    }

    return [
        'ok' => true,
        'cmd' => ($sign === '+') ? 'deposit' : 'withdraw',
        'data' => [
            'amount' => $amount,
            'code' => $code,
            'bank' => $bank,
            'product' => $product,
            'bonus_pct' => $bonusPct,
            'mega_account' => $megaAccount,
            'game_id' => $gameId,
            'balance' => $balance,
            'remark' => $restRemark,
        ],
    ];
}

/**
 * 主处理入口：由 telegram_password_reset_webhook.php 分发调用
 */
function telegram_quick_txn_handle_update(PDO $pdo, array $update, string $botToken): array {
    tqx_ensure_tables($pdo);

    $msg = $update['message'] ?? null;
    if (!is_array($msg)) return ['ok' => true];

    $chat = $msg['chat'] ?? [];
    $chatId = trim((string)($chat['id'] ?? ''));
    $text = trim((string)($msg['text'] ?? ''));
    if ($chatId === '' || $text === '') return ['ok' => true];

    $from = $msg['from'] ?? [];
    $tgUserId = isset($from['id']) ? (int)$from['id'] : 0;
    $tgUsername = trim((string)($from['username'] ?? ''));
    $tgFirst = trim((string)($from['first_name'] ?? ''));
    $tgLast = trim((string)($from['last_name'] ?? ''));
    $tgFull = trim($tgFirst . ' ' . $tgLast);
    $who = $tgUsername !== '' ? $tgUsername : ($tgFull !== '' ? $tgFull : ($tgUserId > 0 ? (string)$tgUserId : 'telegram'));

    // 仅处理快捷记账/撤销/管理命令
    if (!preg_match('/^(\+|-|undo\b|cancel\b|撤销|取消|id\b|\/id\b|setup\b|\/setup\b|add\s+admin\b|remove\s+admin\b|del\s+admin\b|list\s+admin\b|\\/(addadmin|removeadmin|deladmin|listadmin)\\b)/i', $text)) {
        return ['ok' => true];
    }

    $parsed = tqx_parse_command($text);
    if (!$parsed['ok']) {
        tqx_reply($botToken, $chatId, (string)($parsed['err'] ?? 'Invalid'));
        return ['ok' => true];
    }

    // id：不需要白名单（但只在已配置 chat 生效）
    if (($parsed['cmd'] ?? '') === 'id') {
        $out = "chat_id={$chatId}\nuser_id=" . ($tgUserId > 0 ? (string)$tgUserId : '-') . "\nusername=" . ($tgUsername !== '' ? $tgUsername : '-');
        tqx_reply($botToken, $chatId, $out);
        return ['ok' => true];
    }

    // setup：不需要预先配置 chat_id。绑定后才允许 + / - / cancel / undo
    if (($parsed['cmd'] ?? '') === 'setup') {
        $arg = trim((string)($parsed['data']['arg'] ?? ''));

        // 选公司：若只有一个启用公司则自动；否则要求提供 company_id 或 company code
        $pickCompanyId = 0;
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE is_active = 1")->fetchColumn();
            if ($cnt === 1) {
                $pickCompanyId = (int)$pdo->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
        } catch (Throwable $e) {
            // companies 表不存在时，退回 1
        }
        if ($pickCompanyId <= 0 && $arg !== '') {
            // numeric id
            if (preg_match('/^[0-9]+$/', $arg)) {
                $pickCompanyId = (int)$arg;
            } else {
                // code
                try {
                    $st = $pdo->prepare("SELECT id FROM companies WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) LIMIT 1");
                    $st->execute([$arg]);
                    $pickCompanyId = (int)$st->fetchColumn();
                } catch (Throwable $e) {}
            }
        }
        if ($pickCompanyId <= 0) {
            $hint = "无法自动选择公司。请用：/setup <company_id> 或 /setup <company_code>\n例如：/setup 3";
            tqx_reply($botToken, $chatId, $hint);
            return ['ok' => true];
        }

        // upsert config（合并白名单：不覆盖已有 admin）
        $allow = [];
        try {
            $stCur = $pdo->prepare("SELECT allowed_user_ids FROM telegram_quick_txn_config WHERE company_id = ? LIMIT 1");
            $stCur->execute([$pickCompanyId]);
            $curJson = (string)($stCur->fetchColumn() ?: '');
            $curArr = tqx_json_decode_map($curJson);
            foreach ($curArr as $v) {
                $v = trim((string)$v);
                if ($v !== '') $allow[] = $v;
            }
        } catch (Throwable $e) {
            $allow = [];
        }
        $me = '';
        if ($tgUserId > 0) $me = (string)$tgUserId;
        elseif ($tgUsername !== '') $me = $tgUsername;
        if ($me !== '' && !in_array($me, $allow, true)) {
            $allow[] = $me;
        }

        $uid0 = null;
        $stUp = $pdo->prepare("INSERT INTO telegram_quick_txn_config
            (company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec, updated_by)
            VALUES (?, 1, ?, ?,
                        COALESCE((SELECT bank_alias_json FROM telegram_quick_txn_config x WHERE x.company_id=? LIMIT 1), '{}'),
                        COALESCE((SELECT product_alias_json FROM telegram_quick_txn_config y WHERE y.company_id=? LIMIT 1), '{}'),
                        COALESCE((SELECT staff_alias_json FROM telegram_quick_txn_config s WHERE s.company_id=? LIMIT 1), '{}'),
                        COALESCE((SELECT receipt_prefix FROM telegram_quick_txn_config p WHERE p.company_id=? LIMIT 1), NULL),
                        COALESCE((SELECT receipt_slogan FROM telegram_quick_txn_config q WHERE q.company_id=? LIMIT 1), NULL),
                        COALESCE((SELECT receipt_style FROM telegram_quick_txn_config r WHERE r.company_id=? LIMIT 1), 'classic'),
                        COALESCE((SELECT undo_window_sec FROM telegram_quick_txn_config z WHERE z.company_id=? LIMIT 1), 600),
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
            $uid0
        ]);

        $out = "✅ 已绑定本群\nchat_id={$chatId}\ncompany_id={$pickCompanyId}\n已将你设为 admin（白名单）";
        tqx_reply($botToken, $chatId, $out);
        return ['ok' => true];
    }

    // 从这里开始：必须已配置 chat_id 对应 company
    // 做法：用配置表里 chat_id 反查 company_id
    $stFind = $pdo->prepare("SELECT company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec
                             FROM telegram_quick_txn_config
                             WHERE enabled = 1 AND chat_id IS NOT NULL AND chat_id <> '' AND chat_id = ?
                             LIMIT 1");
    $stFind->execute([$chatId]);
    $cfg = $stFind->fetch(PDO::FETCH_ASSOC);
    if (!$cfg) {
        // 未绑定的群：静默
        return ['ok' => true];
    }

    $companyId = (int)($cfg['company_id'] ?? 0);
    if ($companyId <= 0) return ['ok' => true];

    // 白名单（必填）：只允许指定 Telegram 用户记账/撤销
    $allowJson = (string)($cfg['allowed_user_ids'] ?? '');
    $allowArr = tqx_json_decode_map($allowJson);
    $allow = [];
    foreach ($allowArr as $v) {
        $v = trim((string)$v);
        if ($v !== '') $allow[] = $v;
    }
    $is_admin_sender = false;
    if ($tgUserId > 0 && in_array((string)$tgUserId, $allow, true)) $is_admin_sender = true;
    if ($tgUsername !== '' && in_array($tgUsername, $allow, true)) $is_admin_sender = true;
    if (!$is_admin_sender) {
        // 未配置白名单或不在白名单：一律无效
        tqx_reply($botToken, $chatId, "Unauthorized");
        return ['ok' => true];
    }

    $bankAlias = tqx_json_decode_map((string)($cfg['bank_alias_json'] ?? ''));
    $prodAlias = tqx_json_decode_map((string)($cfg['product_alias_json'] ?? ''));
    $staffAlias = tqx_json_decode_map((string)($cfg['staff_alias_json'] ?? ''));
    if ($tgUserId > 0) {
        $k = (string)$tgUserId;
        if (isset($staffAlias[$k]) && trim((string)$staffAlias[$k]) !== '') {
            $who = trim((string)$staffAlias[$k]);
        }
    }

    // admin 白名单管理（仅现有 admin 可操作）
    if (in_array(($parsed['cmd'] ?? ''), ['admin_list', 'admin_add', 'admin_remove', 'admin_add_reply', 'admin_remove_reply'], true)) {
        $cmd = (string)$parsed['cmd'];
        if ($cmd === 'admin_list') {
            tqx_reply($botToken, $chatId, $allow ? ("Admins:\n" . implode("\n", $allow)) : "白名单为空。");
            return ['ok' => true];
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
                tqx_reply($botToken, $chatId, "请回复要添加/移除的那个人的消息。");
                return ['ok' => true];
            }
        }
        if ($targetId === '' || !preg_match('/^[0-9]{4,20}$/', $targetId)) {
            tqx_reply($botToken, $chatId, "ID 不正确。");
            return ['ok' => true];
        }
        $new = $allow;
        if ($cmd === 'admin_add' || $cmd === 'admin_add_reply') {
            if (!in_array($targetId, $new, true)) $new[] = $targetId;
        } else {
            // 防止把自己移除导致“无人可管”
            if ($tgUserId > 0 && (string)$tgUserId === $targetId) {
                tqx_reply($botToken, $chatId, "不能移除自己（避免锁死管理权限）。");
                return ['ok' => true];
            }
            $new = array_values(array_filter($new, fn($x) => (string)$x !== $targetId));
        }
        $pdo->prepare("UPDATE telegram_quick_txn_config SET allowed_user_ids = ? WHERE company_id = ? LIMIT 1")
            ->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $companyId]);
        tqx_reply($botToken, $chatId, "✅ 已更新 admin 列表。可用：list admin");
        return ['ok' => true];
    }

    if (($parsed['cmd'] ?? '') === 'cancel') {
        // 必须 reply 到机器人回执，才能定位 token
        $rep = $msg['reply_to_message'] ?? null;
        $repText = is_array($rep) ? trim((string)($rep['text'] ?? '')) : '';
        $token = '';
        if ($repText !== '' && preg_match('/\bundo\s+([A-Za-z0-9_-]{6,40})\b/i', $repText, $mm)) {
            $token = $mm[1];
        } elseif ($repText !== '' && preg_match('/#([A-Za-z0-9_-]{6,40})\b/', $repText, $mm2)) {
            $token = $mm2[1];
        }
        if ($token === '') {
            tqx_reply($botToken, $chatId, "请回复机器人那条回执消息再发 cancel。");
            return ['ok' => true];
        }
        $parsed = ['cmd' => 'undo', 'data' => ['token' => $token], 'ok' => true];
    }

    if (($parsed['cmd'] ?? '') === 'undo') {
        $token = (string)($parsed['data']['token'] ?? '');
        if ($token === '') return ['ok' => true];

        $stLog = $pdo->prepare("SELECT * FROM telegram_quick_txn_log WHERE token = ? AND company_id = ? LIMIT 1");
        $stLog->execute([$token, $companyId]);
        $log = $stLog->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            tqx_reply($botToken, $chatId, "找不到可撤销记录。");
            return ['ok' => true];
        }
        // 白名单 admin 可撤销任意记录（仍限制时间窗）
        $undoWin = (int)($cfg['undo_window_sec'] ?? 600);
        $createdAt = (string)($log['created_at'] ?? '');
        if ($createdAt !== '') {
            $ts = strtotime($createdAt);
            if ($ts && (time() - $ts) > max(30, $undoWin)) {
                tqx_reply($botToken, $chatId, "已超出可撤销时间。");
                return ['ok' => true];
            }
        }
        $txId = (int)($log['transaction_id'] ?? 0);
        if ($txId <= 0) {
            tqx_reply($botToken, $chatId, "该记录不可撤销。");
            return ['ok' => true];
        }

        transaction_ensure_soft_delete_columns($pdo);
        $stmt = $pdo->prepare("UPDATE transactions SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
        $stmt->execute([$who, $txId, $companyId]);
        if ($stmt->rowCount() > 0) {
            tqx_reply($botToken, $chatId, "✅ 已撤销 #{$token}");
        } else {
            tqx_reply($botToken, $chatId, "撤销失败（可能已删除/不存在）。");
        }
        return ['ok' => true];
    }

    $data = $parsed['data'] ?? [];
    $amount = (float)($data['amount'] ?? 0);
    $code = trim((string)($data['code'] ?? ''));
    $bankIn = trim((string)($data['bank'] ?? ''));
    $prodIn = trim((string)($data['product'] ?? ''));
    $remark = trim((string)($data['remark'] ?? ''));
    $megaAccount = trim((string)($data['mega_account'] ?? ''));
    $gameId = trim((string)($data['game_id'] ?? ''));
    $balance = $data['balance'] ?? null;
    $bonusPct = $data['bonus_pct'] ?? null;

    // alias translate
    $bankKey = tqx_norm_key($bankIn);
    if ($bankKey !== '' && isset($bankAlias[$bankKey])) $bankIn = (string)$bankAlias[$bankKey];
    $prodKey = tqx_norm_key($prodIn);
    if ($prodKey !== '' && isset($prodAlias[$prodKey])) $prodIn = (string)$prodAlias[$prodKey];

    if ($code === '' || $bankIn === '' || $prodIn === '') {
        tqx_reply($botToken, $chatId, "缺少字段。例：+100 C011 P M 备注");
        return ['ok' => true];
    }

    $mode = (($parsed['cmd'] ?? '') === 'deposit') ? 'DEPOSIT' : 'WITHDRAW';
    $bonus = 0.0;
    if ($bonusPct !== null && is_numeric((string)$bonusPct)) {
        $bonus = round($amount * ((float)$bonusPct) / 100, 2);
    }
    $total = round($amount + $bonus, 2);
    $day = date('Y-m-d');
    $time = date('H:i:s');
    $status = 'approved';
    $uid0 = null;

    // 回执样式（配置优先；但若用户在指令里带了“游戏资料三件套”，则自动切到 game）
    $receiptPrefix = trim((string)($cfg['receipt_prefix'] ?? ''));
    if ($receiptPrefix === '') $receiptPrefix = '完成记账';
    $receiptSlogan = trim((string)($cfg['receipt_slogan'] ?? ''));
    $receiptStyle = strtolower(trim((string)($cfg['receipt_style'] ?? 'classic')));
    if (!in_array($receiptStyle, ['classic', 'game'], true)) $receiptStyle = 'classic';

    $hasGameTriplet = false;
    if ($megaAccount !== '' && $gameId !== '' && $balance !== null && is_numeric((string)$balance)) {
        $hasGameTriplet = true;
    }
    $effectiveStyle = $receiptStyle;
    if ($hasGameTriplet) $effectiveStyle = 'game';

    if ($effectiveStyle === 'game') {
        // 若用户没手动填：自动从后台资料补全（customer_product_accounts + 余额计算）
        $balOk = ($balance !== null && is_numeric((string)$balance));
        if ($megaAccount === '' || $gameId === '' || !$balOk) {
            $foundAccount = false;
            try {
                // 先找 customer_id
                $stC = $pdo->prepare("SELECT id FROM customers WHERE company_id = ? AND TRIM(code) = ? LIMIT 1");
                $stC->execute([$companyId, $code]);
                $custId = (int)$stC->fetchColumn();
                if ($custId > 0) {
                    // 按产品名匹配（优先），取最新一条账号
                    $stA = $pdo->prepare("SELECT account, password FROM customer_product_accounts
                                          WHERE company_id = ? AND customer_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?))
                                          ORDER BY created_at DESC, id DESC LIMIT 1");
                    $stA->execute([$companyId, $custId, $prodIn]);
                    $acc = $stA->fetch(PDO::FETCH_ASSOC);
                    if (!$acc) {
                        // 找不到同产品时，退回任意一条（避免完全没回执）
                        $stA2 = $pdo->prepare("SELECT product_name, account, password FROM customer_product_accounts
                                               WHERE company_id = ? AND customer_id = ?
                                               ORDER BY created_at DESC, id DESC LIMIT 1");
                        $stA2->execute([$companyId, $custId]);
                        $acc = $stA2->fetch(PDO::FETCH_ASSOC);
                    }
                    if (is_array($acc)) {
                        $megaAccount = $megaAccount !== '' ? $megaAccount : trim((string)($acc['account'] ?? ''));
                        $gameId = $gameId !== '' ? $gameId : trim((string)($acc['password'] ?? ''));
                        $foundAccount = ($megaAccount !== '' && $gameId !== '');
                    }
                }
            } catch (Throwable $e) {
                $foundAccount = false;
            }

            // 自动余额：按后台 transactions 计算（与 Customers 页 Balance 一致）
            if ($balance === null || !is_numeric((string)$balance)) {
                try {
                    $stB = $pdo->prepare("SELECT
                        COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0)
                        - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0)
                        - COALESCE(SUM(CASE WHEN mode IN ('WITHDRAW','FREE WITHDRAW') THEN COALESCE(burn, 0) ELSE 0 END), 0) AS balance
                        FROM transactions
                        WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) = ?");
                    $stB->execute([$companyId, $code]);
                    $balance = round((float)$stB->fetchColumn(), 2);
                } catch (Throwable $eBal) {
                    try {
                        $stB2 = $pdo->prepare("SELECT
                            COALESCE(SUM(CASE WHEN mode = 'DEPOSIT' THEN amount ELSE 0 END), 0)
                            - COALESCE(SUM(CASE WHEN mode = 'WITHDRAW' THEN amount ELSE 0 END), 0) AS balance
                            FROM transactions
                            WHERE company_id = ? AND status = 'approved' AND deleted_at IS NULL AND code IS NOT NULL AND TRIM(code) = ?");
                        $stB2->execute([$companyId, $code]);
                        $balance = round((float)$stB2->fetchColumn(), 2);
                    } catch (Throwable $eBal2) {
                        // ignore
                    }
                }
            }

            $balOk2 = ($balance !== null && is_numeric((string)$balance));
            if ($megaAccount === '' || $gameId === '' || !$balOk2) {
                $hint = "我找不到这个 CODE 的后台资料。\n"
                      . "请先到后台客户资料里，为该客户添加「产品账号」（账号/密码）。\n"
                      . "或你也可以手动发：+100 {$code} {$bankIn} {$prodIn} <MEGA账号> <游戏ID> <余额>";
                tqx_reply($botToken, $chatId, $hint);
                return ['ok' => true];
            }
        }
    }

    // 备注里补充游戏资料（方便后台查）
    $extraBits = [];
    if ($receiptStyle === 'game') {
        if ($megaAccount !== '') $extraBits[] = "MEGA={$megaAccount}";
        if ($gameId !== '') $extraBits[] = "GAME={$gameId}";
        if ($balance !== null && is_numeric((string)$balance)) $extraBits[] = "BAL=" . round((float)$balance, 2);
    }
    if ($extraBits) {
        $extraStr = '[TG ' . implode(' ', $extraBits) . ']';
        $remark = $remark !== '' ? ($remark . ' ' . $extraStr) : $extraStr;
    }

    // 写入 transactions
    try {
        $pdo->prepare("INSERT INTO transactions (company_id, day, time, mode, code, bank, product, amount, bonus, total, staff, remark, status, created_by, approved_by, approved_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$companyId, $day, $time, $mode, $code, $bankIn, $prodIn, $amount, $bonus, $total, $who, ($remark !== '' ? $remark : null), $status, $uid0, $uid0]);
        $txId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        tqx_reply($botToken, $chatId, "写入失败：" . $e->getMessage());
        return ['ok' => true];
    }

    // token 用于撤销（回执里带 undo token，支持 reply + cancel）
    $token = substr(hash('sha256', $chatId . '|' . $tgUserId . '|' . microtime(true) . '|' . $txId), 0, 10);
    $pdo->prepare("INSERT INTO telegram_quick_txn_log (company_id, chat_id, tg_user_id, tg_username, raw_text, action, token, transaction_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$companyId, $chatId, $tgUserId > 0 ? $tgUserId : null, $tgUsername !== '' ? $tgUsername : null, $text, $mode, $token, $txId]);

    if ($effectiveStyle === 'game') {
        $reply = "✅ {$receiptPrefix} {$mode}\n({$code})";
        if ($receiptSlogan !== '') $reply .= "\n{$receiptSlogan}";
        $reply .= "\n🎮：{$prodIn}";
        $reply .= "\n🆔：{$megaAccount}";
        $reply .= "\n🔢：{$gameId}";
        $reply .= "\n💰：" . round((float)$balance, 2);
        // 仍保留撤销提示
        $reply .= "\n撤销：回复此消息发送 cancel（或直接发：undo {$token}）";
    } else {
        $reply = "✅ {$receiptPrefix} {$mode}\n金额：{$amount}\n代号：{$code}\n银行：{$bankIn}\n产品：{$prodIn}";
        if ($bonus > 0) $reply .= "\nBonus：{$bonus}";
        if ($remark !== '') $reply .= "\n备注：{$remark}";
        $reply .= "\n撤销：回复此消息发送 cancel（或直接发：undo {$token}）";
    }
    tqx_reply($botToken, $chatId, $reply);

    return ['ok' => true];
}

