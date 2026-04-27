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
}

function telegram_pg_quick_txn_handle_update(PDO $pdo, array $update, string $botToken): void
{
    $pdoCat = function_exists('shard_catalog') ? shard_catalog() : $pdo;
    tqx_pg_ensure_tables($pdoCat);

    $msg = $update['message'] ?? null;
    if (!is_array($msg)) {
        return;
    }

    $chat = $msg['chat'] ?? [];
    $chatId = trim((string)($chat['id'] ?? ''));
    $text = trim((string)($msg['text'] ?? ''));
    if ($chatId === '' || $text === '') {
        return;
    }

    $from = $msg['from'] ?? [];
    $tgUserId = isset($from['id']) ? (int)$from['id'] : 0;
    $tgUsername = trim((string)($from['username'] ?? ''));
    $tgFirst = trim((string)($from['first_name'] ?? ''));
    $tgLast = trim((string)($from['last_name'] ?? ''));
    $tgFull = trim($tgFirst . ' ' . $tgLast);
    $who = $tgUsername !== '' ? $tgUsername : ($tgFull !== '' ? $tgFull : ($tgUserId > 0 ? (string)$tgUserId : 'telegram'));

    if (!preg_match('/^(\+|-|undo\b|cancel\b|撤销|取消|id\b|\/id\b|setup\b|\/setup\b|add\s+admin\b|remove\s+admin\b|del\s+admin\b|list\s+admin\b|\\/(addadmin|removeadmin|deladmin|listadmin)\\b)/i', $text)) {
        return;
    }

    $parsed = tqx_parse_command($text);
    if (!$parsed['ok']) {
        tqx_reply($botToken, $chatId, (string)($parsed['err'] ?? 'Invalid'));
        return;
    }

    if (($parsed['cmd'] ?? '') === 'id') {
        $out = "chat_id={$chatId}\nuser_id=" . ($tgUserId > 0 ? (string)$tgUserId : '-') . "\nusername=" . ($tgUsername !== '' ? $tgUsername : '-');
        tqx_reply($botToken, $chatId, $out);
        return;
    }

    if (($parsed['cmd'] ?? '') === 'setup') {
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

        tqx_reply($botToken, $chatId, "✅ PG 群已绑定\nchat_id={$chatId}\ncompany_id={$pickCompanyId}\n已加入白名单。");
        return;
    }

    $stFind = $pdoCat->prepare("SELECT company_id, enabled, chat_id, allowed_user_ids, bank_alias_json, product_alias_json, staff_alias_json, receipt_prefix, receipt_slogan, receipt_style, undo_window_sec
        FROM telegram_quick_txn_config_pg
        WHERE enabled = 1 AND chat_id IS NOT NULL AND chat_id <> '' AND chat_id = ?
        LIMIT 1");
    $stFind->execute([$chatId]);
    $cfg = $stFind->fetch(PDO::FETCH_ASSOC);
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
    $prodAlias = tqx_json_decode_map((string)($cfg['product_alias_json'] ?? ''));
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
    $code = trim((string)($data['code'] ?? ''));
    $bankIn = trim((string)($data['bank'] ?? ''));
    $prodIn = trim((string)($data['product'] ?? ''));
    $remark = trim((string)($data['remark'] ?? ''));
    $bonusPct = $data['bonus_pct'] ?? null;

    $bankKey = tqx_norm_key($bankIn);
    if ($bankKey !== '' && isset($bankAlias[$bankKey])) {
        $bankIn = (string)$bankAlias[$bankKey];
    }
    $prodKey = tqx_norm_key($prodIn);
    if ($prodKey !== '' && isset($prodAlias[$prodKey])) {
        $prodIn = (string)$prodAlias[$prodKey];
    }

    if ($code === '' || $bankIn === '' || $prodIn === '') {
        tqx_reply($botToken, $chatId, '缺少字段。例：+100 C011 CHANNEL1 PG 备注');
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
    $channel = trim($bankIn . ' | ' . $prodIn, " |\t\n\r\0\x0B");
    $lineRemark = $remark;
    if ($bonus > 0) {
        $lineRemark = trim($lineRemark . " [bonus {$bonus}]");
    }

    try {
        $pdoData->prepare('INSERT INTO pg_transactions (company_id, txn_day, txn_time, flow, amount, currency, external_ref, channel, member_code, status, remark, staff, created_by, created_at, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NOW())')
            ->execute([$companyId, $day, $time, $flow, $total, $channel, $code, 'approved', ($lineRemark !== '' ? $lineRemark : null), $who]);
        $txId = (int)$pdoData->lastInsertId();
    } catch (Throwable $e) {
        tqx_reply($botToken, $chatId, '写入失败：' . $e->getMessage());
        return;
    }

    $receiptPrefix = trim((string)($cfg['receipt_prefix'] ?? ''));
    $modeLabel = $flow === 'in' ? 'PG 入款' : 'PG 出款';
    $head = $receiptPrefix !== '' ? "✅ {$receiptPrefix} {$modeLabel}" : "✅ {$modeLabel}";
    $reply = $head . "\n金额：" . number_format($total, 2, '.', '') . "\n代号：{$code}\n渠道：{$channel}";
    if ($lineRemark !== '') {
        $reply .= "\n备注：{$lineRemark}";
    }

    $token = substr(hash('sha256', $chatId . '|' . $tgUserId . '|' . microtime(true) . '|' . $txId), 0, 10);
    $reply .= "\n撤销：undo {$token}";

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

    $receiptMid = tqx_reply($botToken, $chatId, $reply);
    if ($receiptMid) {
        try {
            $pdoCat->prepare('UPDATE telegram_quick_txn_log_pg SET receipt_message_id = ? WHERE company_id = ? AND chat_id = ? AND token = ? AND transaction_id = ? ORDER BY id DESC LIMIT 1')
                ->execute([(int)$receiptMid, $companyId, $chatId, $token, $txId]);
        } catch (Throwable $e) {
        }
    }
}
