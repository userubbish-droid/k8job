<?php
// 所有带侧栏的页面在 <head> 中引入，保证左侧竖排导航一致显示
$__css_mtime = @filemtime(__DIR__ . '/../style.css') ?: 0;
?>
<link rel="stylesheet" href="style.css?v=<?= $__css_mtime ?>">
<style>
.dashboard-layout { display: flex !important; flex-direction: row !important; min-height: 100vh; }
.dashboard-sidebar { display: flex !important; flex-direction: column !important; flex-wrap: nowrap !important; width: 228px !important; min-width: 228px !important; background: #fff; border-right: 1px solid #e2e8f0; padding: 20px 0; flex-shrink: 0; }
.dashboard-sidebar .nav-item { display: flex !important; width: 100%; box-sizing: border-box; padding: 12px 20px; margin: 0 12px 6px 12px; color: #475569; text-decoration: none; border-radius: 8px; white-space: nowrap; align-items: center; }
.dashboard-main { flex: 1; padding: 28px 32px; overflow: auto; background: #f8fafc; min-width: 0; }
</style>
