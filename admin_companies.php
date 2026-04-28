<?php
require 'config.php';
require 'auth.php';
require_superadmin();
$sidebar_current = 'admin_companies';

$msg = '';
$err = '';

/** 确保 companies 有业务类型列（博彩 / 支付网关） */
function companies_ensure_business_kind(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE companies ADD COLUMN business_kind ENUM('gaming','pg') NOT NULL DEFAULT 'gaming' AFTER currency");
    } catch (Throwable $e) {
        // 已存在则忽略
    }
}

/** 确保 companies 有 UI 背景色列（用于用户管理按分公司着色） */
function companies_ensure_ui_color(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE companies ADD COLUMN ui_color VARCHAR(16) NULL DEFAULT NULL AFTER business_kind");
    } catch (Throwable $e) {
        // 已存在则忽略
    }
}

companies_ensure_business_kind($pdo);
companies_ensure_ui_color($pdo);

function normalize_company_code(string $raw): string {
    return strtolower(preg_replace('/\s+/', '', trim($raw)));
}

function normalize_ui_color(?string $raw): ?string
{
    $s = strtoupper(trim((string)$raw));
    if ($s === '') {
        return null;
    }
    // 仅允许 #RRGGBB
    if (!preg_match('/^#[0-9A-F]{6}$/', $s)) {
        return null;
    }
    return $s;
}

/** @return string[] 仍引用该 company_id 的表摘要（非空则不可删） */
function company_delete_blockers(PDO $pdo, int $company_id): array
{
    $tables = [
        'users', 'customers', 'transactions', 'banks', 'products', 'expenses',
        'customer_product_accounts', 'balance_adjust', 'user_permissions',
        'rebate_given', 'agent_rebate_settings',
    ];
    $blockers = [];
    foreach ($tables as $t) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $n = (int)$stmt->fetchColumn();
            if ($n > 0) {
                $blockers[] = $t . ' ×' . $n;
            }
        } catch (Throwable $e) {
            // 表或列不存在则跳过
        }
    }
    // PG 分库中的业务数据（users 仅在主库，此处不重复计 users）
    if (function_exists('shard_pg') && shard_pg() && $company_id > 0) {
        $pg = shard_pg();
        $pgTables = ['customers', 'transactions', 'banks', 'products', 'expenses',
            'customer_product_accounts', 'balance_adjust', 'user_permissions',
            'rebate_given', 'agent_rebate_settings'];
        foreach ($pgTables as $t) {
            $n2 = function_exists('shard_table_company_count') ? shard_table_company_count($pg, $t, $company_id) : 0;
            if ($n2 > 0) {
                $blockers[] = $t . '(PG) ×' . $n2;
            }
        }
    }
    return $blockers;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create') {
            $code = normalize_company_code((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($code === '' || strlen($code) > 32 || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code)) {
                throw new RuntimeException('公司代码：必填，小写字母/数字，可含 - _，最长 32。');
            }
            if ($name === '' || strlen($name) > 120) {
                throw new RuntimeException('公司显示名称：必填，最长 120 字。');
            }
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'MYR')));
            if (!preg_match('/^[A-Z]{2,8}$/', $currency)) {
                $currency = 'MYR';
            }
            $biz = strtolower(trim((string)($_POST['business_kind'] ?? 'gaming')));
            if (!in_array($biz, ['gaming', 'pg'], true)) {
                $biz = 'gaming';
            }
            $ui_color = normalize_ui_color($_POST['ui_color'] ?? null);
            $stmt = $pdo->prepare('INSERT INTO companies (code, name, currency, business_kind, ui_color, is_active) VALUES (?, ?, ?, ?, ?, 1)');
            $stmt->execute([$code, $name, $currency, $biz, $ui_color]);
            $newId = (int)$pdo->lastInsertId();
            if ($newId > 0 && function_exists('companies_shard_upsert_from_catalog')) {
                companies_shard_upsert_from_catalog($pdo, $newId);
            }
            $msg = '已新增分公司：' . $code . '（类型：' . ($biz === 'pg' ? 'Payment Gateway (PG)' : 'Gaming') . '）';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $code = normalize_company_code((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($id <= 0) {
                throw new RuntimeException('参数错误。');
            }
            if ($code === '' || strlen($code) > 32 || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code)) {
                throw new RuntimeException('公司代码格式不正确。');
            }
            if ($name === '' || strlen($name) > 120) {
                throw new RuntimeException('公司显示名称不正确。');
            }
            $stmt = $pdo->prepare('SELECT id FROM companies WHERE LOWER(TRIM(code)) = ? AND id != ? LIMIT 1');
            $stmt->execute([$code, $id]);
            if ($stmt->fetch()) {
                throw new RuntimeException('该公司代码已被其他分公司使用。');
            }
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'MYR')));
            if (!preg_match('/^[A-Z]{2,8}$/', $currency)) {
                $currency = 'MYR';
            }
            $biz = strtolower(trim((string)($_POST['business_kind'] ?? 'gaming')));
            if (!in_array($biz, ['gaming', 'pg'], true)) {
                $biz = 'gaming';
            }
            $ui_color = normalize_ui_color($_POST['ui_color'] ?? null);
            $stmt = $pdo->prepare('UPDATE companies SET code = ?, name = ?, currency = ?, business_kind = ?, ui_color = ? WHERE id = ?');
            $stmt->execute([$code, $name, $currency, $biz, $ui_color, $id]);
            if (function_exists('companies_shard_upsert_from_catalog')) {
                companies_shard_upsert_from_catalog($pdo, $id);
            }
            $msg = '已保存。';
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('参数错误。');
            }
            $active_cnt = (int)$pdo->query('SELECT COUNT(*) FROM companies WHERE is_active = 1')->fetchColumn();
            $stmt = $pdo->prepare('SELECT is_active FROM companies WHERE id = ?');
            $stmt->execute([$id]);
            $cur = (int)$stmt->fetchColumn();
            if ($cur === 1 && $active_cnt <= 1) {
                throw new RuntimeException('至少保留一家「启用」的分公司，否则无法登录。');
            }
            $pdo->prepare('UPDATE companies SET is_active = IF(is_active=1,0,1) WHERE id = ?')->execute([$id]);
            if (function_exists('companies_shard_upsert_from_catalog')) {
                companies_shard_upsert_from_catalog($pdo, $id);
            }
            $msg = '已更新启用状态。';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('参数错误。');
            }
            if ($id === 1) {
                throw new RuntimeException('不能删除默认公司（id=1），避免系统无法使用。');
            }
            $total = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
            if ($total <= 1) {
                throw new RuntimeException('至少保留一条公司记录。');
            }
            $blockers = company_delete_blockers($pdo, $id);
            if ($blockers !== []) {
                throw new RuntimeException('该公司仍有数据，无法删除：' . implode('，', $blockers) . '。请先清空或迁移后再删。');
            }
            $pdo->prepare('DELETE FROM companies WHERE id = ? LIMIT 1')->execute([$id]);
            if (function_exists('companies_shard_delete_row')) {
                companies_shard_delete_row($id);
            }
            $msg = '已删除该分公司。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $raw = (string)$e->getMessage();
        if (strpos($raw, 'Duplicate entry') !== false && strpos($raw, 'code') !== false) {
            $err = '公司代码已存在，请换一个。';
        } else {
            $err = $raw;
        }
    }
}

$companies = [];
try {
    $companies = $pdo->query('SELECT id, code, name, currency, business_kind, ui_color, is_active, created_at FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $companies = [];
}

$open_create_panel = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && $err !== '');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>分公司管理 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .companies-toolbar {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 14px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(148, 163, 184, 0.14);
            margin-bottom: 16px;
        }
        .companies-toolbar .btn-add-company {
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(79, 125, 255, 0.35);
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <h2>分公司管理</h2>
                    <?php include __DIR__ . '/inc/breadcrumb_back.php'; ?>
                </div>

                <div class="companies-toolbar">
                    <button type="button" class="btn btn-primary btn-add-company" id="btn-add-company" aria-expanded="<?= $open_create_panel ? 'true' : 'false' ?>" aria-controls="company-create-panel">Add Company</button>
                </div>

                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="card" id="company-create-panel" style="display:<?= $open_create_panel ? 'block' : 'none' ?>;">
                    <h3>新增分公司</h3>
                    <form method="post" class="filters-bar filters-bar-flow" style="margin-bottom:0;">
                        <input type="hidden" name="action" value="create">
                        <div class="filter-group">
                            <label>代码（英文小写，如 abc）</label>
                            <input class="form-control" name="code" required maxlength="32" placeholder="例如 branch2" pattern="[a-z0-9][a-z0-9_-]*">
                        </div>
                        <div class="filter-group">
                            <label>显示名称</label>
                            <input class="form-control" name="name" required maxlength="120" placeholder="例如 分公司二">
                        </div>
                        <div class="filter-group">
                            <label>币种</label>
                            <select class="form-control" name="currency">
                                <?php foreach (['MYR', 'SGD', 'USD', 'CNY', 'EUR', 'THB', 'IDR'] as $cur): ?>
                                <option value="<?= htmlspecialchars($cur) ?>"<?= $cur === 'MYR' ? ' selected' : '' ?>><?= htmlspecialchars($cur) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>业务类型</label>
                            <select class="form-control" name="business_kind" title="与另一行业数据区分标识；报表后续可按类型筛选">
                                <option value="gaming" selected>Gaming（博彩）</option>
                                <option value="pg">Payment Gateway（PG）</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>列表背景色（可选）</label>
                            <input class="form-control" type="color" name="ui_color" value="#DBEAFE" title="用于整站背景主题色（按当前公司生效）" style="width:88px;padding:6px 8px;">
                        </div>
                        <div class="filter-group" style="align-self:flex-end;">
                            <button type="submit" class="btn btn-primary">新增</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <h3>分公司列表</h3>
                    <div style="overflow-x:auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>代码、名称、币种与类型</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $c): ?>
                                <?php
                                    $bk = strtolower(trim((string)($c['business_kind'] ?? 'gaming')));
                                    if (!in_array($bk, ['gaming', 'pg'], true)) {
                                        $bk = 'gaming';
                                    }
                                ?>
                                <tr>
                                    <td><?= (int)$c['id'] ?></td>
                                    <td>
                                        <form method="post" class="admin-users-role-form" style="flex-wrap:wrap;max-width:520px;">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <input class="form-control" name="code" value="<?= htmlspecialchars($c['code']) ?>" maxlength="32" required pattern="[a-z0-9][a-z0-9_-]*" title="小写字母数字，可含 - _" style="width:120px;">
                                            <input class="form-control" name="name" value="<?= htmlspecialchars($c['name']) ?>" maxlength="120" required style="min-width:160px;flex:1;">
                                            <select class="form-control" name="currency" style="width:88px;" title="币种">
                                                <?php
                                                $ccur = strtoupper(trim((string)($c['currency'] ?? 'MYR')));
                                                foreach (['MYR', 'SGD', 'USD', 'CNY', 'EUR', 'THB', 'IDR'] as $cur):
                                                ?>
                                                <option value="<?= htmlspecialchars($cur) ?>"<?= $ccur === $cur ? ' selected' : '' ?>><?= htmlspecialchars($cur) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select class="form-control" name="business_kind" style="width:120px;" title="业务类型">
                                                <option value="gaming"<?= $bk === 'gaming' ? ' selected' : '' ?>>Gaming</option>
                                                <option value="pg"<?= $bk === 'pg' ? ' selected' : '' ?>>PG</option>
                                            </select>
                                            <?php
                                                $uco = strtoupper(trim((string)($c['ui_color'] ?? '')));
                                                if (!preg_match('/^#[0-9A-F]{6}$/', $uco)) {
                                                    $uco = '';
                                                }
                                            ?>
                                            <input class="form-control" type="color" name="ui_color" value="<?= htmlspecialchars($uco !== '' ? $uco : '#DBEAFE') ?>" title="用户管理列表背景色（可选）" style="width:66px;padding:6px 8px;">
                                            <button type="submit" class="btn btn-sm btn-primary">保存</button>
                                        </form>
                                    </td>
                                    <td><?= (int)$c['is_active'] === 1 ? '启用' : '停用' ?></td>
                                    <td><?= htmlspecialchars((string)($c['created_at'] ?? '')) ?></td>
                                    <td>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-gray"><?= (int)$c['is_active'] === 1 ? '停用' : '启用' ?></button>
                                        </form>
                                        <?php if ((int)$c['id'] !== 1): ?>
                                        <form method="post" class="inline" data-confirm="确定删除该分公司？仅当无用户、客户、流水等数据时可删；不可恢复。">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$companies): ?>
                                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px;">暂无数据（请检查数据库 companies 表）。</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('btn-add-company');
        var panel = document.getElementById('company-create-panel');
        if (!btn || !panel) return;
        btn.addEventListener('click', function(){
            var open = panel.style.display === 'none' || panel.style.display === '';
            panel.style.display = open ? 'block' : 'none';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var first = panel.querySelector('input[name="code"]');
                if (first) setTimeout(function(){ first.focus(); }, 200);
            }
        });
    })();
    </script>
</body>
</html>
