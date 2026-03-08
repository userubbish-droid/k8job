<?php
// 所有带侧栏的页面在 <head> 中引入，保证左侧竖排导航一致显示
$__css_mtime = @filemtime(__DIR__ . '/../style.css') ?: 0;
$__base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$__base_prefix = $__base !== '' ? $__base . '/' : '';
?>
<link rel="icon" href="<?= $__base_prefix ?>favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="style.css?v=<?= $__css_mtime ?>">
<style>
body { background: linear-gradient(165deg, #e4e8ef 0%, #e8ecf2 25%, #eef1f5 50%, #eaeef3 75%, #e6eaf0 100%); background-attachment: fixed; }
.dashboard-layout { display: flex !important; flex-direction: row !important; min-height: 100vh; }
.dashboard-sidebar { display: flex !important; flex-direction: column !important; flex-wrap: nowrap !important; width: 228px !important; min-width: 228px !important; background: linear-gradient(180deg, rgba(238,241,246,0.98) 0%, rgba(232,236,242,0.96) 50%, rgba(228,232,239,0.98) 100%); border-right: 1px solid rgba(220,226,236,0.6); padding: 20px 0; flex-shrink: 0; }
.dashboard-sidebar .nav-item { display: flex !important; width: 100%; box-sizing: border-box; padding: 12px 20px; margin: 0 12px 6px 12px; color: #475569; text-decoration: none; border-radius: 8px; white-space: nowrap; align-items: center; }
.dashboard-main { flex: 1; padding: 28px 32px; overflow: auto; background: transparent !important; min-width: 0; }
</style>
