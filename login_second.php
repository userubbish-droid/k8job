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
            background:
                radial-gradient(ellipse 120% 90% at 100% -22%, rgba(139, 92, 246, 0.28) 0%, transparent 50%),
                radial-gradient(ellipse 100% 80% at -8% 48%, rgba(14, 165, 233, 0.22) 0%, transparent 46%),
                radial-gradient(ellipse 88% 68% at 92% 96%, rgba(192, 132, 252, 0.14) 0%, transparent 44%),
                linear-gradient(125deg, #bddff0 0%, #b4cff8 20%, #a8dcf0 42%, #cbbcf0 68%, #dfcef8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #334155;
        }
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(51, 65, 85, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(51, 65, 85, 0.04) 1px, transparent 1px);
            background-size: 40px 40px;
            opacity: 0.42;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 0;
            background: radial-gradient(ellipse 120% 92% at 50% 28%, transparent 42%, rgba(15, 23, 42, 0.038) 100%);
            pointer-events: none;
        }
        .card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.75) inset;
            padding: 32px 36px;
            width: 100%;
            max-width: 380px;
        }
        h1 { font-size: 1.15rem; margin: 0 0 20px; color: #0f172a; }
        .err {
            background: #fff1f2;
            color: #9f1239;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid rgba(244, 63, 94, 0.25);
        }
        label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 8px; }
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
        }
        input:focus { outline: none; border-color: rgba(77, 100, 248, 0.65); box-shadow: 0 0 0 3px rgba(77, 100, 248, 0.2); }
        .btn {
            width: 100%;
            margin-top: 18px;
            padding: 14px;
            background: linear-gradient(180deg, #6378fa 0%, #4d64f8 48%, #2e3dad 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(77, 100, 248, 0.35);
            transition: filter 0.2s, box-shadow 0.2s;
        }
        .btn:hover { filter: brightness(1.05); box-shadow: 0 8px 26px rgba(77, 100, 248, 0.4); }
        .back { display: inline-block; margin-top: 16px; font-size: 13px; color: #4d64f8; font-weight: 600; text-decoration: none; }
        .back:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <?php $login2_lang_to = rawurlencode('login_second.php'); ?>
    <div style="position:fixed; top:14px; right:16px; z-index:2; font-size:13px; font-weight:600;">
        <a href="switch_lang.php?lang=en&amp;to=<?= htmlspecialchars($login2_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#4d64f8; text-decoration:none; font-weight:600;">Eng</a>
        <span style="color:#94a3b8; margin:0 8px;">|</span>
        <a href="switch_lang.php?lang=zh&amp;to=<?= htmlspecialchars($login2_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#4d64f8; text-decoration:none; font-weight:600;">中文</a>
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
