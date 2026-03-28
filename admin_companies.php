<?php
require 'config.php';
require 'auth.php';
require_superadmin();
$sidebar_current = 'admin_companies';

$msg = '';
$err = '';

function normalize_company_code(string $raw): string {
    return strtolower(preg_replace('/\s+/', '', trim($raw)));
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
            $stmt = $pdo->prepare('INSERT INTO companies (code, name, is_active) VALUES (?, ?, 1)');
            $stmt->execute([$code, $name]);
            $msg = '已新增分公司：' . $code;
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
            $stmt = $pdo->prepare('UPDATE companies SET code = ?, name = ? WHERE id = ?');
            $stmt->execute([$code, $name, $id]);
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
    $companies = $pdo->query('SELECT id, code, name, is_active, created_at FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $companies = [];
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>分公司管理 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap">
                <div class="page-header">
                    <h2>分公司管理</h2>
                    <p class="breadcrumb">
                        <a href="dashboard.php">首页</a><span>·</span>
                        <a href="admin_users.php">用户管理</a><span>·</span>
                        <span>分公司（仅 superadmin）</span>
                    </p>
                </div>

                <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <div class="card">
                    <h3>新增分公司</h3>
                    <p class="agent-customer-hint" style="margin-top:-6px;">登录页的 Company 填这里的<strong>代码</strong>；侧栏切换公司也来自此列表。新建后请在「用户管理」为该公司创建 admin 等账号。<strong>删除</strong>仅当该公司在业务表中无任何引用时可用（模拟空公司可直接删）。</p>
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
                                    <th>代码与名称</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $c): ?>
                                <tr>
                                    <td><?= (int)$c['id'] ?></td>
                                    <td>
                                        <form method="post" class="admin-users-role-form" style="flex-wrap:wrap;max-width:420px;">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <input class="form-control" name="code" value="<?= htmlspecialchars($c['code']) ?>" maxlength="32" required pattern="[a-z0-9][a-z0-9_-]*" title="小写字母数字，可含 - _" style="width:120px;">
                                            <input class="form-control" name="name" value="<?= htmlspecialchars($c['name']) ?>" maxlength="120" required style="min-width:160px;flex:1;">
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
</body>
</html>
