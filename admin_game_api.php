<?php
/**
 * 游戏平台 API 配置（Gaming 分公司 · Boss / BigBoss）
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();
require_once __DIR__ . '/inc/game_api.php';

$sidebar_current = 'admin_game_api';
$actor_is_superadmin = (($_SESSION['user_role'] ?? '') === 'superadmin');
$company_id = effective_admin_company_id($pdo);
$uid = (int)($_SESSION['user_id'] ?? 0);

$msg = '';
$err = '';

game_api_ensure_tables($pdo);

$companies = [];
$company_pick = $company_id;
if ($actor_is_superadmin) {
    try {
        $companies = $pdo->query("SELECT id, code, name, business_kind, is_active FROM companies ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companies = [];
    }
    $pick_raw = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
    if ($pick_raw > 0) {
        $company_pick = $pick_raw;
    }
    if ($company_pick <= 0) {
        $company_pick = $company_id > 0 ? $company_id : 1;
    }
}

$master_on = game_api_master_enabled($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_master') {
            $on = !empty($_POST['master_enabled']);
            game_api_set_master_enabled($pdo, $on, $uid);
            $master_on = $on;
            $msg = $on ? '已启用全局游戏 API' : '已停用全局游戏 API（所有公司 API 调用均不会执行）';
        } elseif ($action === 'save_company') {
            $cid = $actor_is_superadmin ? (int)($_POST['company_id'] ?? 0) : $company_id;
            if ($cid <= 0) {
                throw new RuntimeException('请选择分公司');
            }
            if (!game_api_company_is_gaming($pdo, $cid)) {
                throw new RuntimeException('仅 Gaming（博彩）分公司可配置游戏 API');
            }
            $en = !empty($_POST['enabled']);
            $url = trim((string)($_POST['api_base_url'] ?? ''));
            $auth = trim((string)($_POST['authcode'] ?? ''));
            $sec = trim((string)($_POST['secret_key'] ?? ''));
            $curCfg = game_api_get_company_config($pdo, $cid);
            if ($sec === '' && trim((string)($curCfg['secret_key'] ?? '')) !== '') {
                $sec = trim((string)$curCfg['secret_key']);
            }
            if ($en && ($url === '' || $auth === '' || $sec === '')) {
                throw new RuntimeException('启用 API 时请填写 API 地址、Authcode 与 SecretKey');
            }
            game_api_save_company_config($pdo, $cid, [
                'enabled' => $en,
                'api_base_url' => $url,
                'authcode' => $auth,
                'secret_key' => $sec,
                'agent_account' => trim((string)($_POST['agent_account'] ?? '')),
            ], $uid);
            $company_pick = $cid;
            $msg = '已保存 API 配置';
        } elseif ($action === 'test') {
            $cid = $actor_is_superadmin ? (int)($_POST['company_id'] ?? 0) : $company_id;
            if ($cid <= 0) {
                throw new RuntimeException('请选择分公司');
            }
            $cfg = game_api_get_company_config($pdo, $cid);
            $cfg['api_base_url'] = trim((string)($_POST['api_base_url'] ?? $cfg['api_base_url']));
            $newAuth = trim((string)($_POST['authcode'] ?? ''));
            $newSec = trim((string)($_POST['secret_key'] ?? ''));
            if ($newAuth !== '') {
                $cfg['authcode'] = $newAuth;
            }
            if ($newSec !== '') {
                $cfg['secret_key'] = $newSec;
            }
            $test = game_api_test_connection($cfg);
            game_api_record_test_result($pdo, $cid, $test['ok'], $test['message']);
            $company_pick = $cid;
            if ($test['ok']) {
                $msg = '测试成功：' . $test['message'];
            } else {
                $err = '测试失败：' . $test['message'];
            }
        } else {
            throw new RuntimeException('无效操作');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$cur = game_api_get_company_config($pdo, $company_pick);
$is_gaming = game_api_company_is_gaming($pdo, $company_pick);
$api_active = game_api_is_active($pdo, $company_pick);

$gaming_companies = [];
foreach ($companies as $c) {
    $bk = strtolower(trim((string)($c['business_kind'] ?? 'gaming')));
    if ($bk !== 'pg') {
        $gaming_companies[] = $c;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>游戏平台 API - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .api-card { padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg, #fff); margin-bottom: 18px; }
        .api-card h3 { margin: 0 0 12px; font-size: 1.05rem; }
        .api-status { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .api-status.on { background: #dcfce7; color: #15803d; }
        .api-status.off { background: #fee2e2; color: #b91c1c; }
        .api-status.warn { background: #fef3c7; color: #b45309; }
        .api-hint { font-size: 13px; color: var(--muted, #64748b); line-height: 1.6; margin: 8px 0 0; }
        .api-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 860px;">
            <div class="page-header">
                <h2>游戏平台 API</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="api-card">
                <h3>全局开关</h3>
                <p class="api-hint">关闭后，<strong>所有分公司</strong>的游戏 API 调用（查余额、上下分等）一律停止，各公司配置仍保留。</p>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="save_master">
                    <label style="display:flex; gap:10px; align-items:center; font-weight:600;">
                        <input type="checkbox" name="master_enabled" value="1" <?= $master_on ? 'checked' : '' ?>>
                        启用全局游戏 API
                    </label>
                    <div class="api-actions">
                        <button type="submit" class="btn btn-primary">保存全局开关</button>
                        <span class="api-status <?= $master_on ? 'on' : 'off' ?>"><?= $master_on ? '全局：已启用' : '全局：已停用' ?></span>
                    </div>
                </form>
            </div>

            <div class="api-card">
                <h3>分公司 API 配置（Gaming）</h3>
                <p class="api-hint">
                    从游戏平台代理后台复制 <strong>Authcode</strong>、<strong>SecretKey</strong> 与 API 地址（对接 URL）。
                    字段对应：Authcode → <code>vendorId</code>，SecretKey → <code>signature</code>。
                    另请在平台后台把本服务器<strong>公网 IP</strong> 加入白名单。
                </p>

                <?php if ($actor_is_superadmin): ?>
                <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin:14px 0;">
                    <div style="min-width:260px; flex:1;">
                        <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">分公司</label>
                        <select class="form-control" name="company_id" onchange="this.form.submit()">
                            <?php foreach ($gaming_companies as $c):
                                $cid = (int)($c['id'] ?? 0);
                                if ($cid <= 0) continue;
                                $cc = trim((string)($c['code'] ?? ''));
                                $cn = trim((string)($c['name'] ?? ''));
                                $lab = trim($cc !== '' ? ($cc . ($cn !== '' ? ' - ' . $cn : '')) : ($cn !== '' ? $cn : (string)$cid));
                            ?>
                                <option value="<?= $cid ?>" <?= $cid === (int)$company_pick ? 'selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <noscript><button class="btn btn-primary" type="submit">OK</button></noscript>
                </form>
                <?php endif; ?>

                <?php if (!$is_gaming): ?>
                    <div class="alert alert-error" style="margin-top:12px;">当前公司为 PG 类型，不支持游戏平台 API。请选择 Gaming 分公司。</div>
                <?php else: ?>
                    <?php
                    $stLabel = '未配置';
                    $stClass = 'warn';
                    if (!$master_on) {
                        $stLabel = '全局已停用';
                        $stClass = 'off';
                    } elseif ($api_active) {
                        $stLabel = '已连接（可用）';
                        $stClass = 'on';
                    } elseif ((int)($cur['enabled'] ?? 0) === 1) {
                        $stLabel = '已启用但凭证不完整';
                        $stClass = 'warn';
                    }
                    ?>
                    <p style="margin:12px 0 0;">
                        状态：<span class="api-status <?= $stClass ?>"><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($cur['last_test_at'])): ?>
                            <span class="api-hint" style="display:inline; margin-left:8px;">
                                上次测试 <?= htmlspecialchars((string)$cur['last_test_at'], ENT_QUOTES, 'UTF-8') ?> —
                                <?= (int)($cur['last_test_ok'] ?? 0) === 1 ? '成功' : '失败' ?>：
                                <?= htmlspecialchars((string)($cur['last_test_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </p>

                    <form method="post" style="margin-top:14px;">
                        <input type="hidden" name="action" value="save_company">
                        <?php if ($actor_is_superadmin): ?><input type="hidden" name="company_id" value="<?= (int)$company_pick ?>"><?php endif; ?>

                        <label style="display:flex; gap:10px; align-items:center; font-weight:600;">
                            <input type="checkbox" name="enabled" value="1" <?= ((int)($cur['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                            启用本公司游戏 API
                        </label>

                        <label style="margin-top:12px; font-weight:700;">代理账号（备注，如 918VG003）</label>
                        <input class="form-control" name="agent_account" value="<?= htmlspecialchars((string)($cur['agent_account'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="可选，方便识别">

                        <label style="margin-top:12px; font-weight:700;">API 地址（对接 URL）*</label>
                        <input class="form-control" name="api_base_url" value="<?= htmlspecialchars((string)($cur['api_base_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://api.xxx.com/...">

                        <label style="margin-top:12px; font-weight:700;">Authcode *</label>
                        <input class="form-control" name="authcode" value="<?= htmlspecialchars((string)($cur['authcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="平台后台 Authcode" autocomplete="off">
                        <p class="api-hint">当前：<?= htmlspecialchars(game_api_mask_secret((string)($cur['authcode'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>

                        <label style="margin-top:12px; font-weight:700;">SecretKey *</label>
                        <input type="password" class="form-control" name="secret_key" placeholder="<?= trim((string)($cur['secret_key'] ?? '')) !== '' ? '留空则保留现有 SecretKey' : '平台后台 SecretKey' ?>" autocomplete="off">
                        <p class="api-hint">当前：<?= htmlspecialchars(game_api_mask_secret((string)($cur['secret_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>

                        <div class="api-actions">
                            <button type="submit" class="btn btn-primary">保存配置</button>
                        </div>
                    </form>

                    <form method="post" class="api-actions">
                        <input type="hidden" name="action" value="test">
                        <?php if ($actor_is_superadmin): ?><input type="hidden" name="company_id" value="<?= (int)$company_pick ?>"><?php endif; ?>
                        <input type="hidden" name="api_base_url" value="<?= htmlspecialchars((string)($cur['api_base_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="authcode" value="<?= htmlspecialchars((string)($cur['authcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-secondary">测试连接（Hello / 代理余额）</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="api-card">
                <h3>说明</h3>
                <ul class="api-hint" style="margin:0; padding-left:1.2rem;">
                    <li>仅 <strong>Gaming（博彩）</strong> 分公司使用；PG 公司无此 API。</li>
                    <li>新建 Gaming 公司时可勾选「启用游戏平台 API」，再到此页填写凭证。</li>
                    <li>测试成功后会显示代理余额；失败请检查 URL、Authcode、SecretKey 与 IP 白名单。</li>
                    <li>后续可在 Telegram 进单或流水页面调用 <code>game_api_change_balance()</code> 自动上下分（需另行开启）。</li>
                </ul>
            </div>
        </div>
    </main>
</div>
</body>
</html>
