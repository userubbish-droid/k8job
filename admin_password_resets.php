<?php
require 'config.php';
require 'auth.php';
require_admin();
$sidebar_current = 'admin_password_resets';

$company_id = current_company_id();
$head_office_scope = is_superadmin_head_office_scope();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $resolver = 'web:' . trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'admin'));
    if ($id > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $st = $pdo->prepare('SELECT id, user_id, username, company_id, status FROM password_reset_requests WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $err = __('pwreset_err_not_found');
            } elseif (($req['status'] ?? '') !== 'pending') {
                $err = __('pwreset_err_not_pending');
            } elseif (!$head_office_scope && (int)$req['company_id'] !== $company_id) {
                $err = __('pwreset_err_forbidden');
            } else {
                $now = date('Y-m-d H:i:s');
                $uid = (int)$req['user_id'];
                $rid = (int)$req['id'];
                if ($action === 'approve') {
                    $temp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
                    $hash = password_hash($temp, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
                    $pdo->prepare("UPDATE password_reset_requests SET status='approved', resolved_at=?, resolved_by_tg=?, resolved_note=?, temp_password=? WHERE id=? AND status='pending'")
                        ->execute([$now, $resolver, 'approved_via_web', $temp, $rid]);
                    $msg = __f('pwreset_ok_approve', $temp);
                } else {
                    $pdo->prepare("UPDATE password_reset_requests SET status='rejected', resolved_at=?, resolved_by_tg=?, resolved_note=? WHERE id=? AND status='pending'")
                        ->execute([$now, $resolver, 'rejected_via_web', $rid]);
                    $msg = __('pwreset_ok_reject');
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$rows = [];
try {
    if ($head_office_scope) {
        $sql = 'SELECT r.id, LOWER(TRIM(c.code)) AS company_code, r.username, r.requested_at
                FROM password_reset_requests r
                LEFT JOIN companies c ON c.id = r.company_id
                WHERE r.status = \'pending\'
                ORDER BY r.requested_at ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = 'SELECT r.id, LOWER(TRIM(c.code)) AS company_code, r.username, r.requested_at
                FROM password_reset_requests r
                LEFT JOIN companies c ON c.id = r.company_id
                WHERE r.status = \'pending\' AND r.company_id = ?
                ORDER BY r.requested_at ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$company_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}
$htmlLang = app_lang() === 'en' ? 'en' : 'zh-CN';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__('pwreset_admin_title'), ENT_QUOTES, 'UTF-8') ?> — <?= defined('SITE_TITLE') ? htmlspecialchars((string)SITE_TITLE, ENT_QUOTES, 'UTF-8') : 'K8' ?></title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .wrap { max-width: 900px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .ok { background: #d4edda; padding: 10px; border-radius: 6px; color: #155724; margin-bottom: 10px; }
        .err { background: #f8d7da; padding: 10px; border-radius: 6px; color: #721c24; margin-bottom: 10px; }
        .btn { padding: 6px 10px; border: 0; border-radius: 6px; cursor: pointer; color: #fff; }
        .approve { background: #28a745; }
        .reject { background: #dc3545; }
        .muted { color: #666; font-size: 12px; }
        a { color: #007bff; }
        form { display: inline-block; margin-right: 6px; }
    </style>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="wrap">
                <h2 style="margin:0 0 8px;"><?= htmlspecialchars(__('pwreset_admin_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="muted"><?= htmlspecialchars(__('pwreset_admin_sub'), ENT_QUOTES, 'UTF-8') ?> <a href="dashboard.php"><?= htmlspecialchars(__('txn_link_home'), ENT_QUOTES, 'UTF-8') ?></a></p>

                <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
                <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(__('pwreset_col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                            <?php if ($head_office_scope): ?><th><?= htmlspecialchars(__('pwreset_col_company'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                            <th><?= htmlspecialchars(__('pwreset_col_user'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('pwreset_col_time'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('pwreset_col_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <?php if ($head_office_scope): ?><td><?= htmlspecialchars((string)($r['company_code'] ?? '')) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string)($r['username'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($r['requested_at'] ?? '')) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn approve" type="submit"><?= htmlspecialchars(__('pwreset_btn_approve'), ENT_QUOTES, 'UTF-8') ?></button>
                                </form>
                                <form method="post" data-confirm="<?= htmlspecialchars(__('pwreset_confirm_reject'), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button class="btn reject" type="submit"><?= htmlspecialchars(__('pwreset_btn_reject'), ENT_QUOTES, 'UTF-8') ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                        <tr><td colspan="<?= $head_office_scope ? 5 : 4 ?>"><?= htmlspecialchars(__('pwreset_empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
