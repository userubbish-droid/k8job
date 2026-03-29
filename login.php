<?php
require 'config.php';
require 'auth.php';
session_start();

if (!empty($_GET['abandon_second'])) {
    unset($_SESSION[AUTH_LOGIN_PENDING_SECOND]);
    header('Location: login.php');
    exit;
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

$error = '';
if (!empty($_GET['no_perm'])) {
    $error = __('login_err_no_perm');
}
if (!empty($_GET['need_company'])) {
    $error = __('login_err_need_company');
}
if (!empty($_GET['second_expired'])) {
    $error = __('login_err_second_expired');
}
if (!empty($_GET['login_err'])) {
    $le = urldecode((string)$_GET['login_err']);
    if ($le !== '') {
        $error = mb_substr($le, 0, 400, 'UTF-8');
    }
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
    $user = mb_strtoupper(trim((string)($_POST['user'] ?? '')), 'UTF-8');
    $pass = (string) ($_POST['pass'] ?? '');
    $login_as = trim($_POST['login_as'] ?? 'admin'); // admin | member（含 agent 账号）
    $company_code_in = mb_strtoupper(trim((string)($_POST['company_code'] ?? '')), 'UTF-8');
    $company_code = $company_code_in === '' ? '' : mb_strtolower($company_code_in, 'UTF-8');
    $remember = !empty($_POST['remember']);

    if ($user === '' || $pass === '') {
        $error = __('login_err_user_pass');
    } else {
        ensure_users_second_password_hash($pdo);
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

        $stmt_sa = $pdo->prepare("SELECT id, username, password_hash, second_password_hash, role, display_name, avatar_url, company_id, is_active FROM users WHERE LOWER(TRIM(username)) = LOWER(?) AND company_id IS NULL LIMIT 1");
        $stmt_sa->execute([$user]);
        $u_sa = $stmt_sa->fetch(PDO::FETCH_ASSOC);

        $u_br = null;
        if ($cid_for_branch > 0) {
            $stmt_br = $pdo->prepare("SELECT id, username, password_hash, second_password_hash, role, display_name, avatar_url, company_id, is_active FROM users WHERE LOWER(TRIM(username)) = LOWER(?) AND company_id = ? LIMIT 1");
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
            $error = __('login_err_bad_creds');
        } elseif ((int)$u['is_active'] !== 1) {
            $error = __('login_err_disabled');
        } elseif ($login_as === 'admin' && !in_array($db_role, ['admin', 'superadmin', 'boss'], true)) {
            $error = __('login_err_admin_gate');
        } elseif ($login_as === 'member' && !in_array($db_role, ['member', 'agent'], true)) {
            $error = __f('login_err_member_gate', $db_role !== '' ? role_label($db_role) : __('login_err_role_unset'));
        } elseif ($login_as !== 'admin' && $db_role === 'superadmin') {
            $error = __('login_err_sa_admin_only');
        } else {
            if (in_array($db_role, ['admin', 'member'], true)) {
                $h2 = trim((string)($u['second_password_hash'] ?? ''));
                if ($h2 === '') {
                    $error = __('login_err_second_not_setup');
                } else {
                    $_SESSION[AUTH_LOGIN_PENDING_SECOND] = [
                        'uid' => (int)$u['id'],
                        'exp' => time() + 600,
                        'remember' => $remember,
                    ];
                    header('Location: login_second.php');
                    exit;
                }
            } else {
                $result = auth_commit_login_session($pdo, $u, $remember, $company_code, $default_company_id);
                if (!$result['ok']) {
                    $error = $result['error'];
                } else {
                    header('Location: ' . $result['location']);
                    exit;
                }
            }
        }
    }
}
$login_as = $_POST['login_as'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'en' ? 'en' : 'zh-CN' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(__f('login_page_title', defined('SITE_TITLE') ? SITE_TITLE : 'K8'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
            margin: 0;
            min-height: 100vh;
            color: #3a5560;
            background:
                radial-gradient(ellipse 125% 95% at 88% -8%, rgba(8, 145, 178, 0.2) 0%, transparent 52%),
                radial-gradient(ellipse 95% 85% at -5% 55%, rgba(14, 116, 144, 0.15) 0%, transparent 48%),
                radial-gradient(ellipse 70% 55% at 50% 100%, rgba(34, 211, 238, 0.11) 0%, transparent 50%),
                linear-gradient(165deg, #f0fdff 0%, #ecfeff 30%, #f3fafb 58%, #eef8f9 100%);
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
            background: radial-gradient(ellipse 115% 90% at 48% 22%, transparent 48%, rgba(21, 94, 117, 0.055) 100%);
            pointer-events: none;
        }
        .login-card {
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
            position: relative;
            z-index: 1;
        }
        .tabs {
            display: flex;
            margin-bottom: 28px;
            border-radius: 999px;
            overflow: hidden;
            padding: 5px;
            background: linear-gradient(180deg, rgba(236, 254, 255, 0.94) 0%, rgba(207, 250, 254, 0.92) 100%);
            border: 1px solid rgba(103, 232, 249, 0.42);
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.85);
        }
        .tabs a {
            flex: 1;
            padding: 13px 16px;
            text-align: center;
            text-decoration: none;
            color: #48656e;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.22s, color 0.22s, box-shadow 0.22s;
            border-radius: 999px;
        }
        .tabs a.active {
            background: linear-gradient(90deg, #155e75 0%, #0e7490 42%, #0891b2 72%, #22d3ee 100%);
            color: #fff;
            box-shadow: 0 6px 20px rgba(14, 116, 144, 0.32);
        }
        .tabs a:not(.active):hover { color: #164e63; background: rgba(255, 255, 255, 0.55); }
        .input-wrap {
            position: relative;
            margin-bottom: 16px;
        }
        .input-wrap svg {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #22d3ee;
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 14px 18px 14px 48px;
            border: 1.5px solid rgba(8, 145, 178, 0.52);
            border-radius: 999px;
            font-size: 14px;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .input-wrap input:focus {
            outline: none;
            border-color: #0891b2;
            box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.22);
        }
        .input-wrap input::placeholder { color: #5f7a82; }
        .input-wrap input.login-field-upper {
            text-transform: uppercase;
        }
        .input-wrap select {
            width: 100%;
            padding: 14px 18px 14px 48px;
            border: 1.5px solid rgba(8, 145, 178, 0.52);
            border-radius: 999px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
            appearance: auto;
        }
        .input-wrap select:focus { outline: none; border-color: #0891b2; box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.22); }
        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 16px 0 24px;
            font-size: 13px;
        }
        .remember { display: flex; align-items: center; gap: 8px; color: #516a72; }
        .remember input { width: auto; margin: 0; accent-color: #0891b2; }
        .forget { color: #0e7490; text-decoration: none; font-weight: 600; }
        .forget:hover { text-decoration: underline; color: #155e75; }
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(90deg, #155e75 0%, #0e7490 40%, #0891b2 72%, #22d3ee 100%);
            color: #fff;
            border: none;
            border-radius: 999px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: filter 0.2s, box-shadow 0.2s;
            box-shadow: 0 8px 28px rgba(14, 116, 144, 0.3);
        }
        .btn-login:hover { filter: brightness(1.04); box-shadow: 0 10px 34px rgba(14, 116, 144, 0.36); }
        .err {
            background: #fff1f2;
            color: #9f1239;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid rgba(244, 63, 94, 0.25);
        }
        .foot {
            margin-top: 20px;
            font-size: 12px;
            color: #5a6b82;
            text-align: center;
        }
        .login-modal-mask {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(30, 58, 138, 0.36);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1000;
            padding: 20px;
        }
        .login-modal-mask.show { display: flex; }
        .login-modal {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 22px;
            border: 1px solid rgba(103, 232, 249, 0.45);
            box-shadow: 0 28px 60px rgba(14, 116, 144, 0.13), 0 0 0 1px rgba(255, 255, 255, 0.9) inset;
            overflow: hidden;
        }
        .login-modal-head {
            padding: 14px 18px;
            font-weight: 700;
            color: #164e63;
            background: linear-gradient(180deg, #ecfeff 0%, #cffafe 100%);
            border-bottom: 1px solid rgba(103, 232, 249, 0.45);
        }
        .login-modal-body { padding: 18px 18px 10px; color: #0f172a; }
        .login-modal-foot { padding: 0 18px 16px; text-align: right; }
        .login-modal-ok {
            border: none;
            border-radius: 999px;
            padding: 9px 18px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(90deg, #0e7490 0%, #0891b2 100%);
            box-shadow: 0 4px 16px rgba(14, 116, 144, 0.28);
        }
    </style>
</head>
<body>
    <?php $login_lang_to = rawurlencode('login.php'); ?>
    <div style="position:fixed; top:14px; right:16px; z-index:2; font-size:13px; font-weight:600;">
        <a href="switch_lang.php?lang=en&amp;to=<?= htmlspecialchars($login_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#0e7490; text-decoration:none; font-weight:600;">Eng</a>
        <span style="color:#6b8790; margin:0 8px;">|</span>
        <a href="switch_lang.php?lang=zh&amp;to=<?= htmlspecialchars($login_lang_to, ENT_QUOTES, 'UTF-8') ?>" style="color:#0e7490; text-decoration:none; font-weight:600;">中文</a>
    </div>
    <div class="login-card">
        <div class="tabs">
            <a href="#" class="tab <?= $login_as === 'admin' ? 'active' : '' ?>" data-tab="admin"><?= htmlspecialchars(__('login_tab_admin'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="#" class="tab <?= $login_as === 'member' ? 'active' : '' ?>" data-tab="member"><?= htmlspecialchars(__('login_tab_member'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post" id="loginForm">
            <input type="hidden" name="login_as" id="login_as" value="<?= htmlspecialchars($login_as) ?>">

            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                <input type="text" name="company_code" class="login-field-upper" placeholder="<?= htmlspecialchars(__('login_ph_company'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(mb_strtoupper(trim((string)($_POST['company_code'] ?? '')), 'UTF-8')) ?>" autocomplete="organization">
            </div>
            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                <input type="text" name="user" class="login-field-upper" placeholder="<?= htmlspecialchars(__('login_ph_username'), ENT_QUOTES, 'UTF-8') ?>" required value="<?= htmlspecialchars(mb_strtoupper(trim((string)($_POST['user'] ?? '')), 'UTF-8')) ?>">
            </div>
            <div class="input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                <input type="password" name="pass" placeholder="<?= htmlspecialchars(__('login_ph_password'), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="row">
                <label class="remember">
                    <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    <?= htmlspecialchars(__('login_remember'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <a href="#" class="forget"><?= htmlspecialchars(__('login_forget'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>

            <button type="submit" class="btn-login"><?= htmlspecialchars(__('login_submit'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>

    </div>
    <div class="login-modal-mask" id="login-modal-mask" aria-hidden="true">
        <div class="login-modal" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars(__('modal_system_title'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="login-modal-head"><?= htmlspecialchars(__('modal_system_title'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="login-modal-body" id="login-modal-body"></div>
            <div class="login-modal-foot">
                <button type="button" class="login-modal-ok" id="login-modal-ok"><?= htmlspecialchars(__('btn_ok'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
    </div>

    <script>
        window.__LOGIN_I18N = <?= json_encode(['resetContact' => __('login_reset_contact')], JSON_UNESCAPED_UNICODE) ?>;
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
            showLoginModal((window.__LOGIN_I18N && window.__LOGIN_I18N.resetContact) || '');
        });
        document.querySelectorAll('input.login-field-upper').forEach(function(el) {
            el.addEventListener('input', function() {
                var start = this.selectionStart;
                var end = this.selectionEnd;
                this.value = this.value.toUpperCase();
                if (start !== null && end !== null) {
                    this.setSelectionRange(start, end);
                }
            });
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
