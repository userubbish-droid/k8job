<?php
require 'config.php';
require 'auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

ensure_users_second_password_hash($pdo);

$pend = $_SESSION[AUTH_LOGIN_PENDING_SECOND] ?? null;
$uid = is_array($pend) ? (int)($pend['uid'] ?? 0) : 0;
$exp = is_array($pend) ? (int)($pend['exp'] ?? 0) : 0;
$error = '';

if ($uid <= 0 || $exp < time()) {
    unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);
    header('Location: login.php?second_expired=1');
    exit;
}

$companies = [];
try {
    $companies = $pdo->query('SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $companies = [];
}
$default_company_id = 0;
foreach ($companies as $cid) {
    $default_company_id = (int)$cid;
    if ($default_company_id > 0) {
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass2 = (string)($_POST['second_pass'] ?? '');
    $stmt = $pdo->prepare('SELECT id, username, password_hash, second_password_hash, role, display_name, avatar_url, company_id, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['is_active'] !== 1) {
        unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);
        header('Location: login.php');
        exit;
    }
    $db_role = strtolower(trim((string)($u['role'] ?? '')));
    if (!in_array($db_role, ['admin', 'member'], true)) {
        unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);
        header('Location: login.php');
        exit;
    }
    $h2 = trim((string)($u['second_password_hash'] ?? ''));
    if ($h2 === '' || !password_verify($pass2, $h2)) {
        $error = __('login2_err_wrong');
    } else {
        $remember = !empty($pend['remember']);
        $result = auth_commit_login_session($pdo, $u, $remember, '', $default_company_id);
        if (!$result['ok']) {
            unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);
            header('Location: login.php?login_err=' . rawurlencode($result['error']));
            exit;
        }
        header('Location: ' . $result['location']);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__f('login2_page_title', defined('SITE_TITLE') ? SITE_TITLE : 'K8'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
            margin: 0;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
            color: #3a5560;
            background:
                radial-gradient(ellipse 125% 95% at 88% -8%, rgba(8, 145, 178, 0.2) 0%, transparent 52%),
                radial-gradient(ellipse 95% 85% at -5% 55%, rgba(14, 116, 144, 0.15) 0%, transparent 48%),
                radial-gradient(ellipse 70% 55% at 50% 100%, rgba(34, 211, 238, 0.11) 0%, transparent 50%),
                linear-gradient(165deg, #f0fdff 0%, #ecfeff 30%, #f3fafb 58%, #eef8f9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image:
                repeating-linear-gradient(125deg, transparent, transparent 22px, rgba(8, 145, 178, 0.05) 22px, rgba(8, 145, 178, 0.05) 23px),
                linear-gradient(rgba(14, 116, 144, 0.036) 1px, transparent 1px),
                linear-gradient(90deg, rgba(6, 182, 212, 0.03) 1px, transparent 1px);
            background-size: 100% 100%, 44px 44px, 44px 44px;
            opacity: 0.72;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 0;
            background: radial-gradient(ellipse 115% 90% at 48% 22%, transparent 48%, rgba(30, 64, 175, 0.05) 100%);
            pointer-events: none;
        }
        .card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 26px;
            border: 1px solid rgba(6, 182, 212, 0.32);
            box-shadow:
                0 28px 64px rgba(14, 116, 144, 0.11),
                0 2px 12px rgba(14, 116, 144, 0.048),
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
            padding: 36px 40px;
            width: 100%;
            max-width: 390px;
        }
        h1 { font-size: 1.15rem; margin: 0 0 20px; color: #164e63; }
        .err {
            background: #fff1f2;
            color: #9f1239;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid rgba(244, 63, 94, 0.25);
        }
        label { display: block; font-size: 13px; font-weight: 600; color: #3a5560; margin-bottom: 8px; }
        input[type="password"] {
            width: 100%;
            padding: 14px 18px;
            border: 1.5px solid rgba(8, 145, 178, 0.52);
            border-radius: 999px;
            font-size: 15px;
            background: #fff;
        }
        input::placeholder { color: #5f7a82; }
        input:focus { outline: none; border-color: #0891b2; box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.22); }
        .btn {
            width: 100%;
            margin-top: 18px;
            padding: 15px;
            background: linear-gradient(90deg, #155e75 0%, #0e7490 40%, #0891b2 72%, #22d3ee 100%);
            color: #fff;
            border: none;
            border-radius: 999px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 8px 28px rgba(14, 116, 144, 0.3);
            transition: filter 0.2s, box-shadow 0.2s;
        }
        .btn:hover { filter: brightness(1.04); box-shadow: 0 10px 34px rgba(14, 116, 144, 0.36); }
        .back { display: inline-block; margin-top: 16px; font-size: 13px; color: #0e7490; font-weight: 600; text-decoration: none; }
        .back:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <?php $login2_lang_to = rawurlencode('login_second.php'); ?>
    <div style="position:fixed; top:14px; right:16px; z-index:2; font-size:13px; font-weight:600;">
        <a href="switch_lang.php?lang=en&amp;to=<?= htmlspecialchars($login2_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#0e7490; text-decoration:none; font-weight:600;">Eng</a>
        <span style="color:#6b8790; margin:0 8px;">|</span>
        <a href="switch_lang.php?lang=zh&amp;to=<?= htmlspecialchars($login2_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#0e7490; text-decoration:none; font-weight:600;">中文</a>
    </div>
    <div class="card">
        <h1><?= htmlspecialchars(__('login2_title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="second_pass"><?= htmlspecialchars(__('login2_label'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="password" name="second_pass" id="second_pass" required autofocus placeholder="<?= htmlspecialchars(__('login2_ph'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn"><?= htmlspecialchars(__('login2_submit'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <a class="back" href="login.php?abandon_second=1"><?= htmlspecialchars(__('login2_back'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</body>
</html>
