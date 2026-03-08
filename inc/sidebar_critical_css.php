<?php
// 所有带侧栏的页面在 <head> 中引入，保证左侧竖排导航一致显示
$__css_mtime = @filemtime(__DIR__ . '/../style.css') ?: 0;
$__base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$__base_prefix = $__base !== '' ? $__base . '/' : '';
?>
<link rel="icon" href="<?= $__base_prefix ?>favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="style.css?v=<?= $__css_mtime ?>">
<style>
body { background: linear-gradient(165deg, #d4dae3 0%, #dce2eb 20%, #e2e7ef 40%, #e6eaf1 60%, #e0e5ed 80%, #d8dde6 100%); background-attachment: fixed; }
.dashboard-layout { display: flex !important; flex-direction: row !important; min-height: 100vh; }
.dashboard-sidebar { display: flex !important; flex-direction: column !important; flex-wrap: nowrap !important; width: 228px !important; min-width: 228px !important; background: linear-gradient(180deg, rgba(232,236,243,0.98) 0%, rgba(224,229,238,0.96) 50%, rgba(218,224,235,0.98) 100%); border-right: 1px solid rgba(212,220,230,0.7); padding: 20px 0; flex-shrink: 0; }
.dashboard-sidebar .nav-item { display: flex !important; width: 100%; box-sizing: border-box; padding: 12px 20px; margin: 0 12px 6px 12px; color: #475569; text-decoration: none; border-radius: 8px; white-space: nowrap; align-items: center; }
.dashboard-main { flex: 1; padding: 28px 32px; overflow: auto; background: transparent !important; min-width: 0; }
</style>
