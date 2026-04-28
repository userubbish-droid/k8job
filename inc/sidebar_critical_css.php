<?php
// 带侧栏的页面在 <head> 中引入：先加载 style.css，仅补充避免首屏布局错乱的规则。
// 背景渐变、侧栏外观等全部由 style.css 统一，此处不再覆盖 body / .dashboard-sidebar（否则部分页面会一直显示旧灰底）。
$__css_mtime = @filemtime(__DIR__ . '/../style.css') ?: 0;
$__base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$__base_prefix = $__base !== '' ? $__base . '/' : '';
?>
<link rel="icon" href="<?= $__base_prefix ?>favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="<?= $__base_prefix ?>style.css?v=<?= $__css_mtime ?>">
<?php
// Per-company theme (background color) override
// Safe include: falls back to default CSS on any error.
@include __DIR__ . '/company_theme.php';
?>
<style>
.dashboard-layout { display: flex !important; flex-direction: row !important; min-height: 100vh; }
.dashboard-main {
    flex: 1;
    padding: 28px 32px 40px;
    overflow: auto;
    background: transparent !important;
    min-width: 0;
}
</style>
