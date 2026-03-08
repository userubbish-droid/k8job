<?php
require 'config.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $login_as = trim($_POST['login_as'] ?? 'admin'); // admin | member
    $company_id = trim($_POST['company_id'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($user === '' || $pass === '') {
        $error = '请输入用户名和密码';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role, display_name, is_active FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $u = $stmt->fetch();

        if (!$u || (int)$u['is_active'] !== 1 || !password_verify($pass, $u['password_hash'])) {
            $error = '用户名或密码错误';
        } elseif ($login_as === 'admin' && $u['role'] !== 'admin') {
            $error = '请使用 Admin 登录入口，或该账号不是管理员';
        } elseif ($login_as === 'member' && $u['role'] !== 'member') {
            $error = '请使用 Member 登录入口，或该账号不是员工';
        } else {
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['user_name'] = $u['display_name'] ?: $u['username'];
            $_SESSION['user_role'] = $u['role'];
            if ($company_id !== '') $_SESSION['company_id'] = $company_id;
            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + 86400 * 14, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            header('Location: dashboard.php');
            exit;
        }
    }
}
$login_as = $_POST['login_as'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #e8f4fc 0%, #f0f7ff 50%, #fff 100%);
            background-size: 100% 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            padding-bottom: max(20px, env(safe-area-inset-bottom));
            position: relative;
            overflow: hidden;
        }
        @media (max-width: 640px) {
            body { padding: 16px; }
            .login-card { padding: 24px 20px; }
            .input-wrap input { min-height: 44px; font-size: 16px; }
            .btn-login { min-height: 48px; font-size: 16px; }
        }
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(circle at 20% 30%, rgba(0, 120, 255, 0.06) 0%, transparent 50%),
                              radial-gradient(circle at 80% 70%, rgba(0, 120, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 120, 255, 0.15);
            padding: 32px 40px;
            width: 100%;
            max-width: 380px;
            position: relative;
            z-index: 1;
        }
        .tabs {
            display: flex;
            margin-bottom: 24px;
            border-radius: 10px;
            overflow: hidden;
            background: #f0f4f8;
        }
        .tabs a {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .tabs a.active {
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #fff;
            border-radius: 8px;
            margin: 2px;
        }
        .tabs a:not(.active):hover { color: #334155; }
        .input-wrap {
            position: relative;
            margin-bottom: 16px;
        }
        .input-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #94a3b8;
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 12px 12px 12px 42px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .input-wrap input:focus {
            outline: none;
            border-color: #3b82f6;
        }
        .input-wrap input::placeholder { color: #94a3b8; }
        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 16px 0 24px;
            font-size: 13px;
        }
        .remember { display: flex; align-items: center; gap: 8px; color: #64748b; }
        .remember input { width: auto; margin: 0; }
        .forget { color: #3b82f6; text-decoration: none; }
        .forget:hover { text-decoration: underline; }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-login:hover { opacity: 0.95; }
        .err {
            background: #fef2f2;
            color: #dc2626;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .foot {
            margin-top: 20px;
            font-size: 12px;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="tabs">
            <a href="#" class="tab <?= $login_as === 'admin' ? 'active' : '' ?>" data-tab="admin">Admin</a>
            <a href="#" class="tab <?= $login_as === 'member' ? 'active' : '' ?>" data-tab="member">Member</a>
        </div>

        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post" id="loginForm">
            <input type="hidden" name="login_as" id="login_as" value="<?= htmlspecialchars($login_as) ?>">

            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                <input type="text" name="company_id" placeholder="Company Id" value="<?= htmlspecialchars($_POST['company_id'] ?? '') ?>">
            </div>
            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                <input type="text" name="user" placeholder="Username" required value="<?= htmlspecialchars($_POST['user'] ?? '') ?>">
            </div>
            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                <input type="password" name="pass" placeholder="Password" required>
            </div>

            <div class="row">
                <label class="remember">
                    <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    Remember me
                </label>
                <a href="#" class="forget">Forget Password?</a>
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>

        <p class="foot">没有账号？请联系管理员在「用户管理」中创建。</p>
    </div>

    <script>
        document.querySelectorAll('.tab').forEach(function(t) {
            t.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.tab').forEach(function(x) { x.classList.remove('active'); });
                this.classList.add('active');
                document.getElementById('login_as').value = this.getAttribute('data-tab');
            });
        });
        document.querySelector('.forget').addEventListener('click', function(e) {
            e.preventDefault();
            alert('请联系管理员重置密码。');
        });
    </script>
</body>
</html>
