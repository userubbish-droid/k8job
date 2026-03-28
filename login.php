<?php
require 'config.php';
require 'auth.php';
session_start();

function choose_landing_url_for_role(string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'agent') return 'agents.php';
    if ($role === 'superadmin') return 'dashboard.php';
    if ($role === 'admin') return 'dashboard.php';

    // member：不强制 Dashboard；按权限优先跳到可用页面
    $candidates = [
        'transaction_create' => 'transaction_create.php',
        'expense_statement'  => 'expense.php',
        'kiosk_expense_view' => 'kiosk_expense.php',
        'statement_balance'  => 'balance_summary.php',
        'statement_report'   => 'report.php',
        'kiosk_statement'    => 'kiosk_statement.php',
        'transaction_list'   => 'transaction_list.php',
        'customers'          => 'customers.php',
        'product_library'    => 'product_library.php',
        'rebate'             => 'rebate.php',
    ];
    foreach ($candidates as $perm => $url) {
        if (has_permission($perm)) return $url;
    }
    return '';
}

if (isset($_SESSION['user_id'])) {
    $target = choose_landing_url_for_role((string)($_SESSION['user_role'] ?? ''));
    if ($target !== '') {
        header('Location: ' . $target);
        exit;
    }
    // 已登录但无权限：强制退出，回登录页
    session_destroy();
    header('Location: login.php?no_perm=1');
    exit;
}

function ensure_users_login_meta(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at"); } catch (Throwable $e) {}
}

function get_login_ip(): string {
    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = (string)$_SERVER['REMOTE_ADDR'];
    }
    $ip = trim($ip);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

$error = '';
if (!empty($_GET['no_perm'])) {
    $error = '该账号未开通任何功能权限，请联系管理员在「Permissions」中勾选。';
}
if (!empty($_GET['need_company'])) {
    $error = '请先选择公司再登录。';
}

// 公司列表（用于登录选择）
$companies = [];
try {
    $companies = $pdo->query("SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $companies = [];
}
$default_company_id = 0;
foreach ($companies as $c) {
    $default_company_id = (int)($c['id'] ?? 0);
    if ($default_company_id > 0) break;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $login_as = trim($_POST['login_as'] ?? 'admin'); // admin | member（含 agent 账号）
    $company_code = strtolower(trim((string)($_POST['company_code'] ?? '')));
    $remember = !empty($_POST['remember']);

    if ($user === '' || $pass === '') {
        $error = '请输入用户名和密码';
    } else {
        $cid_for_branch = 0;
        if ($company_code !== '') {
            try {
                $stmtC0 = $pdo->prepare("SELECT id FROM companies WHERE is_active = 1 AND LOWER(TRIM(code)) = ? LIMIT 1");
                $stmtC0->execute([$company_code]);
                $cid_for_branch = (int)$stmtC0->fetchColumn();
            } catch (Throwable $e) {}
        } else {
            $cid_for_branch = $default_company_id;
        }

        $stmt_sa = $pdo->prepare("SELECT id, username, password_hash, role, display_name, avatar_url, company_id, is_active FROM users WHERE username = ? AND company_id IS NULL LIMIT 1");
        $stmt_sa->execute([$user]);
        $u_sa = $stmt_sa->fetch(PDO::FETCH_ASSOC);

        $u_br = null;
        if ($cid_for_branch > 0) {
            $stmt_br = $pdo->prepare("SELECT id, username, password_hash, role, display_name, avatar_url, company_id, is_active FROM users WHERE username = ? AND company_id = ? LIMIT 1");
            $stmt_br->execute([$user, $cid_for_branch]);
            $u_br = $stmt_br->fetch(PDO::FETCH_ASSOC);
        }

        $u = null;
        if ($u_sa && password_verify($pass, (string)($u_sa['password_hash'] ?? ''))) {
            if (strtolower(trim((string)($u_sa['role'] ?? ''))) === 'superadmin') {
                $u = $u_sa;
            }
        }
        if (!$u && $u_br && password_verify($pass, (string)($u_br['password_hash'] ?? ''))) {
            $u = $u_br;
        }

        $db_role = strtolower(trim((string)($u['role'] ?? '')));
        if (!$u) {
            $error = '用户名或密码错误';
        } elseif ((int)$u['is_active'] !== 1) {
            $error = '该账号已禁用';
        } elseif ($login_as === 'admin' && !in_array($db_role, ['admin', 'superadmin'], true)) {
            $error = '请使用 Admin 登录入口，或该账号不是管理员';
        } elseif ($login_as === 'member' && !in_array($db_role, ['member', 'agent'], true)) {
            $error = '当前账号角色为 ' . ($db_role !== '' ? $db_role : '未设置') . '，Member / Agent 入口仅允许 member 或 agent。请到「用户管理」修改角色。';
        } elseif ($login_as !== 'admin' && $db_role === 'superadmin') {
            $error = '平台 superadmin 请使用 Admin 登录入口。';
        } else {
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['user_name'] = $u['display_name'] ?: $u['username'];
            $_SESSION['user_role'] = $db_role;
            $_SESSION['avatar_url'] = trim((string)($u['avatar_url'] ?? ''));
            $db_company_id = (int)($u['company_id'] ?? 0);

            // company 绑定规则：
            // - superadmin：可选任意 company（必须选择一个用于当前会话）
            // - admin/member/agent：必须绑定到自己的 company_id（忽略输入）
            if ($db_role === 'superadmin') {
                // superadmin：允许不填公司，默认进入第一家（一般是 k8）；填了则按 code 切换
                $use_company = 0;
                if ($company_code !== '') {
                    try {
                        $stmtC = $pdo->prepare("SELECT id FROM companies WHERE is_active = 1 AND LOWER(TRIM(code)) = ? LIMIT 1");
                        $stmtC->execute([$company_code]);
                        $use_company = (int)$stmtC->fetchColumn();
                    } catch (Throwable $e) {}
                }
                if ($use_company <= 0) $use_company = $default_company_id;
                if ($use_company <= 0) {
                    $error = '暂无可用公司，请先创建公司。';
                    session_destroy();
                } else {
                    $_SESSION['company_id'] = $use_company;
                }
            } else {
                if ($db_company_id <= 0) {
                    session_destroy();
                    $error = '该账号未绑定公司，请到用户管理里设置 company_id。';
                } else {
                    $_SESSION['company_id'] = $db_company_id;
                }
            }
            if ($error !== '') {
                // fallthrough to show error
            } else {
            try {
                ensure_users_login_meta($pdo);
                $ip = get_login_ip();
                $stmt2 = $pdo->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                $stmt2->execute([$ip !== '' ? $ip : null, (int)$u['id']]);
            } catch (Throwable $e) {
            }
            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + 86400 * 14, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            if ($db_role === 'agent') {
                $_SESSION['agent_code'] = $u['username']; // 与 customers.recommend 对应
                header('Location: agents.php');
            } else {
                $target = choose_landing_url_for_role($db_role);
                if ($target === '') {
                    // 登录成功但无任何权限：不让进入系统
                    session_destroy();
                    $error = '该账号未开通任何功能权限，请联系管理员在「Permissions」中勾选。';
                } else {
                    header('Location: ' . $target);
                    exit;
                }
            }
            if ($error === '') exit;
            }
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
        .input-wrap select {
            width: 100%;
            padding: 12px 12px 12px 42px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: #fff;
            appearance: auto;
        }
        .input-wrap select:focus { outline: none; border-color: #3b82f6; }
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
        .login-modal-mask {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(8, 16, 40, 0.45);
            z-index: 1000;
            padding: 20px;
        }
        .login-modal-mask.show { display: flex; }
        .login-modal {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 16px;
            border: 1px solid #dbeafe;
            box-shadow: 0 20px 55px rgba(37,99,235,0.28);
            overflow: hidden;
        }
        .login-modal-head {
            padding: 12px 16px;
            font-weight: 700;
            color: #1d4ed8;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border-bottom: 1px solid #bfdbfe;
        }
        .login-modal-body { padding: 18px 16px 10px; color: #0f172a; }
        .login-modal-foot { padding: 0 16px 14px; text-align: right; }
        .login-modal-ok {
            border: none;
            border-radius: 999px;
            padding: 8px 16px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
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
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                <input type="text" name="company_code" placeholder="Company（分公司代码）" value="<?= htmlspecialchars((string)($_POST['company_code'] ?? '')) ?>" autocomplete="organization">
            </div>
            <p style="margin:-4px 0 12px 44px;font-size:12px;color:#64748b;line-height:1.45;">多分公司时必填或建议填写，与「分公司管理」中的代码一致；各分公司可有相同用户名。留空则按默认第一家匹配。</p>
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

    </div>
    <div class="login-modal-mask" id="login-modal-mask" aria-hidden="true">
        <div class="login-modal" role="dialog" aria-modal="true" aria-label="系统提示">
            <div class="login-modal-head">系统提示</div>
            <div class="login-modal-body" id="login-modal-body"></div>
            <div class="login-modal-foot">
                <button type="button" class="login-modal-ok" id="login-modal-ok">OK</button>
            </div>
        </div>
    </div>

    <script>
        function showLoginModal(message) {
            var mask = document.getElementById('login-modal-mask');
            var body = document.getElementById('login-modal-body');
            if (!mask || !body) return;
            body.textContent = message || '';
            mask.classList.add('show');
            mask.setAttribute('aria-hidden', 'false');
        }
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
            showLoginModal('请联系管理员重置密码。');
        });
        (function(){
            var mask = document.getElementById('login-modal-mask');
            var ok = document.getElementById('login-modal-ok');
            if (!mask || !ok) return;
            function close() {
                mask.classList.remove('show');
                mask.setAttribute('aria-hidden', 'true');
            }
            ok.addEventListener('click', close);
            mask.addEventListener('click', function(e){ if (e.target === mask) close(); });
        })();
    </script>
</body>
</html>
