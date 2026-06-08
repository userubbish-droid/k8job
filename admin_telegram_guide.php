<?php
/**
 * Telegram Gaming / PG 使用教程（仅 Boss / BigBoss）
 */
require 'config.php';
require 'auth.php';
require_boss_or_superadmin();

$tab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($tab, ['all', 'gaming', 'pg'], true)) {
    $tab = 'all';
}

$sidebar_current = $tab === 'pg' ? 'admin_telegram_guide_pg' : ($tab === 'gaming' ? 'admin_telegram_guide_gaming' : 'admin_telegram_guide');

$baseUrl = trim((string)($NOTIFY_BASE_URL ?? ''));
$gamingWebhook = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/telegram_password_reset_webhook.php' : 'https://你的域名.com/telegram_password_reset_webhook.php';
$pgWebhook = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/telegram_pg_webhook.php' : 'https://你的域名.com/telegram_pg_webhook.php';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram 教程 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .tg-guide-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .tg-guide-tabs a { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: inherit; font-size: 14px; font-weight: 600; }
        .tg-guide-tabs a.is-active { background: var(--primary, #2e3dad); color: #fff; border-color: transparent; }
        .tg-section { margin-bottom: 22px; padding: 18px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg, #fff); }
        .tg-section h3 { margin: 0 0 12px; font-size: 1.1rem; }
        .tg-section h4 { margin: 16px 0 8px; font-size: 0.95rem; }
        .tg-steps { margin: 0; padding-left: 1.25rem; line-height: 1.7; font-size: 14px; }
        .tg-steps li { margin-bottom: 8px; }
        .tg-links { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 0; }
        .tg-links a { font-size: 13px; }
        .tg-cmd-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        .tg-cmd-table th, .tg-cmd-table td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
        .tg-cmd-table th { background: #f8fafc; font-weight: 600; }
        .tg-cmd-table code { font-size: 12px; }
        .tg-tip { padding: 10px 12px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; font-size: 13px; margin-top: 12px; line-height: 1.6; }
        .tg-warn { padding: 10px 12px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; font-size: 13px; margin-top: 12px; line-height: 1.6; }
        .tg-checklist { list-style: none; padding: 0; margin: 0; }
        .tg-checklist li { padding: 6px 0 6px 28px; position: relative; font-size: 14px; line-height: 1.55; }
        .tg-checklist li::before { content: '☐'; position: absolute; left: 0; color: #64748b; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <main class="dashboard-main">
        <div class="page-wrap" style="max-width: 920px;">
            <div class="page-header">
                <h2>Telegram 使用教程</h2>
                <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
            </div>

            <div class="tg-guide-tabs">
                <a href="admin_telegram_guide.php" class="<?= $tab === 'all' ? 'is-active' : '' ?>">全部</a>
                <a href="admin_telegram_guide.php?tab=gaming" class="<?= $tab === 'gaming' ? 'is-active' : '' ?>">Gaming</a>
                <a href="admin_telegram_guide.php?tab=pg" class="<?= $tab === 'pg' ? 'is-active' : '' ?>">PG</a>
            </div>

            <?php if ($tab === 'all' || $tab === 'gaming'): ?>
            <div class="tg-section" id="gaming">
                <h3>🎮 Gaming Telegram 快捷记账</h3>
                <p class="form-hint" style="margin:0 0 12px;">
                    Gaming 与 PG 必须用<strong>不同的 Bot</strong>。Gaming Bot 的 Token 写在 <code>notify_config.php</code>，Webhook 指向 <code>telegram_password_reset_webhook.php</code>。
                </p>

                <h4>一、后台配置（按顺序）</h4>
                <ol class="tg-steps">
                    <li>@BotFather 创建 Bot，复制 <strong>Bot Token</strong>。</li>
                    <li>打开 <a href="admin_telegram_bot_status.php">Telegram 连线</a>，填入 Token、<strong>NOTIFY_BASE_URL</strong>（如 <code>https://k8wincs96.com</code>），保存。</li>
                    <li>同一页确认 <strong>getMe ✓</strong>、<strong>Webhook URL</strong> 已指向：<br><code><?= htmlspecialchars($gamingWebhook, ENT_QUOTES, 'UTF-8') ?></code></li>
                    <li>若 Webhook 为空：打开 <a href="set_telegram_webhook.php">设置 Telegram Webhook</a>，点「一键设置」。</li>
                    <li>把 Bot 拉进目标群，群内发 <code>/id</code>，复制 <code>chat_id</code>（必须含负号，如 <code>-1003956335892</code>），填回「通知群 Chat ID」并保存。</li>
                    <li>群内发 <code>/setup</code>（多公司时 <code>/setup 公司ID</code>），应回复「✅ 已绑定本群」。</li>
                    <li>打开 <a href="admin_telegram_quick_txn.php">Telegram 快捷记账设置</a>：勾选「启用」、配置银行/产品缩写（如 <code>P = Parking</code>、<code>M = MEGA</code>）、白名单用户。</li>
                    <li>@BotFather 对该 Bot 发 <code>/setprivacy</code> → 选 <strong>Disable</strong>（否则 <code>+300</code> 这类非斜杠消息 Bot 收不到）。</li>
                </ol>

                <div class="tg-warn">
                    <strong>常见误区：</strong>浏览器直接打开 webhook 地址只会显示 <code>{"ok":true}</code>，不能用来测试；<code>getUpdates</code> 在 Webhook 启用后会报 409，属正常。
                </div>

                <h4>二、指令格式</h4>
                <table class="tg-cmd-table">
                    <thead><tr><th>用途</th><th>格式</th><th>示例</th></tr></thead>
                    <tbody>
                        <tr><td>入款</td><td><code>+金额 代号 银行 产品 [备注]</code></td><td><code>+300 C004 P M</code></td></tr>
                        <tr><td>出款</td><td><code>-金额 代号 银行 产品 [备注]</code></td><td><code>-200 C004 P M 提现</code></td></tr>
                        <tr><td>带 Bonus</td><td>备注段加 <code>b10</code> 或 <code>b10%</code></td><td><code>+100 C011 P M b10 备注</code></td></tr>
                        <tr><td>查 ID</td><td><code>/id</code></td><td>返回 chat_id、user_id</td></tr>
                        <tr><td>绑定群</td><td><code>/setup</code> 或 <code>/setup 3</code></td><td>首次必做</td></tr>
                        <tr><td>撤销</td><td><code>undo 令牌</code> 或回复回执发 <code>cancel</code></td><td>有时间窗限制</td></tr>
                        <tr><td>管理白名单</td><td><code>/addadmin 用户ID</code>、<code>/listadmin</code></td><td>仅已有 admin 可用</td></tr>
                    </tbody>
                </table>

                <div class="tg-tip">
                    <strong>缩写说明：</strong><code>P</code>、<code>M</code> 等须在「快捷记账设置」里映射到真实银行/产品名称；客户代号（如 <code>C004</code>）须在系统客户列表中存在。
                </div>

                <h4>三、自检清单</h4>
                <ul class="tg-checklist">
                    <li>连线页 getMe 显示有效、Webhook 有 URL、无 last_error_message</li>
                    <li>群内 <code>/id</code> 有回复</li>
                    <li>群内 <code>/setup</code> 已绑定</li>
                    <li>隐私模式已 Disable</li>
                    <li>后台已启用快捷记账 + 白名单含你的 user_id</li>
                    <li>测试 <code>+300 C004 P M</code> 有回执</li>
                </ul>

                <div class="tg-links">
                    <a href="admin_telegram_bot_status.php" class="btn btn-secondary btn-sm">Telegram 连线</a>
                    <a href="set_telegram_webhook.php" class="btn btn-secondary btn-sm">设置 Webhook</a>
                    <a href="admin_telegram_quick_txn.php" class="btn btn-secondary btn-sm">快捷记账设置</a>
                    <a href="telegram_diagnostics.php" class="btn btn-secondary btn-sm">诊断工具</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'all' || $tab === 'pg'): ?>
            <div class="tg-section" id="pg">
                <h3>📦 PG Telegram 快捷记账</h3>
                <p class="form-hint" style="margin:0 0 12px;">
                    PG 使用<strong>独立 Bot</strong>，Token 写在 <code>PG_notify_config.php</code>，Webhook 必须指向 <code>telegram_pg_webhook.php</code>（不要用 Gaming 的 URL）。
                </p>

                <h4>一、后台配置（按顺序）</h4>
                <ol class="tg-steps">
                    <li>@BotFather 再建一个 PG 专用 Bot（与 Gaming 不同）。</li>
                    <li>打开 <a href="admin_telegram_bot_status.php">Telegram 连线</a>，在右侧保存 <strong>PG Bot Token</strong>。</li>
                    <li>用 PG Token 设置 Webhook URL：<br><code><?= htmlspecialchars($pgWebhook, ENT_QUOTES, 'UTF-8') ?></code><br>
                        （浏览器可打开 <a href="telegram_pg_webhook.php" target="_blank" rel="noopener">telegram_pg_webhook.php</a> 做自检）</li>
                    <li>确认目标公司在 <a href="admin_companies.php">分公司管理</a> 的 <code>business_kind = pg</code>。</li>
                    <li>打开 <a href="admin_telegram_pg.php">PG Telegram</a>，选择 PG 公司，勾选「启用 PG 快捷记账」，配置白名单与银行缩写。</li>
                    <li>把 PG Bot 拉进群，发 <code>/id</code> 取 chat_id，再发 <code>/setup</code> 或 <code>/setup 公司代码</code> 绑定 PG 公司。</li>
                    <li>设置本群默认：<code>/customer C001</code>、<code>/bank HLB</code>、<code>/currency MYR</code>（论坛话题可分别设置）。</li>
                    <li>@BotFather 对 PG Bot 执行 <code>/setprivacy</code> → <strong>Disable</strong>。</li>
                </ol>

                <h4>二、指令格式（无产品段）</h4>
                <table class="tg-cmd-table">
                    <thead><tr><th>用途</th><th>格式</th><th>示例</th></tr></thead>
                    <tbody>
                        <tr><td>完整入款</td><td><code>+金额 代号 银行 [备注]</code></td><td><code>+300 C001 HLB 备注</code></td></tr>
                        <tr><td>完整出款</td><td><code>-金额 代号 银行 [备注]</code></td><td><code>-200 C001 HLB</code></td></tr>
                        <tr><td>极简（已设 /customer /bank）</td><td><code>+金额</code> 或 <code>-金额</code></td><td><code>+300</code></td></tr>
                        <tr><td>设默认客户</td><td><code>/customer 代号</code></td><td><code>/customer C001</code></td></tr>
                        <tr><td>设默认银行</td><td><code>/bank 银行</code></td><td><code>/bank HLB</code></td></tr>
                        <tr><td>设默认币种</td><td><code>/currency 币种</code></td><td><code>/currency MYR</code></td></tr>
                        <tr><td>绑定群</td><td><code>/setup</code> 或 <code>/setup PG公司ID</code></td><td>首次必做</td></tr>
                        <tr><td>查 ID</td><td><code>/id</code></td><td>含 topic_key（论坛话题）</td></tr>
                        <tr><td>撤销</td><td><code>undo 令牌</code> / <code>cancel</code></td><td>写入 pg_transactions</td></tr>
                    </tbody>
                </table>

                <div class="tg-tip">
                    <strong>与 Gaming 的区别：</strong>PG 没有「产品」字段；多群/多话题可用 <code>/customer</code>、<code>/bank</code>、<code>/currency</code> 分别绑定；流水写入 <code>pg_transactions</code>，不影响 Gaming 的 <code>transactions</code> 表。
                </div>

                <h4>三、自检清单</h4>
                <ul class="tg-checklist">
                    <li>PG Token 与 Gaming Token 不是同一个 Bot</li>
                    <li>Webhook 指向 <code>telegram_pg_webhook.php</code></li>
                    <li>PG Telegram 页已启用 + 白名单已填</li>
                    <li>群内 <code>/setup</code> 已绑定 PG 公司</li>
                    <li>已设 <code>/customer</code>、<code>/bank</code>（极简模式还需 <code>/currency</code>）</li>
                    <li>隐私模式已 Disable</li>
                    <li>测试 <code>+100</code> 或 <code>+300 C001 HLB</code> 有回执</li>
                </ul>

                <div class="tg-links">
                    <a href="admin_telegram_bot_status.php" class="btn btn-secondary btn-sm">Telegram 连线</a>
                    <a href="admin_telegram_pg.php" class="btn btn-secondary btn-sm">PG Telegram 设置</a>
                    <a href="telegram_pg_webhook.php" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">PG Webhook 自检</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="tg-section">
                <h3>🔧 故障排查</h3>
                <table class="tg-cmd-table">
                    <thead><tr><th>现象</th><th>可能原因</th><th>处理</th></tr></thead>
                    <tbody>
                        <tr><td>完全没反应</td><td>Token 未保存 / Webhook 未设 / 隐私模式未关</td><td>查连线页；<code>/setprivacy</code> → Disable；重设 Webhook</td></tr>
                        <tr><td><code>/id</code> 有回复，<code>+300</code> 没反应</td><td>隐私模式仍开启</td><td>@BotFather Disable privacy</td></tr>
                        <tr><td>回 Unauthorized</td><td>未 <code>/setup</code> 或不在白名单</td><td>发 <code>/setup</code>；后台加 user_id</td></tr>
                        <tr><td>格式不对</td><td>指令顺序错误</td><td>Gaming：<code>+金额 代号 银行 产品</code>；PG 无产品段</td></tr>
                        <tr><td>Webhook last_error SSL</td><td>HTTPS 证书问题</td><td>修复站点 SSL 后重试</td></tr>
                        <tr><td>getUpdates 409</td><td>Webhook 已启用</td><td>正常；用群内 <code>/id</code> 代替</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
