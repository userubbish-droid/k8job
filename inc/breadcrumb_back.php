<?php
declare(strict_types=1);
/**
 * Single "Back" crumb (browser history). Optional: set $breadcrumb_back_style before include, e.g. 'margin-top:20px;'.
 */
$__bb_style = '';
if (isset($breadcrumb_back_style)) {
    $__bb_style = trim((string)$breadcrumb_back_style);
    unset($breadcrumb_back_style);
}
?>
<p class="breadcrumb"<?= $__bb_style !== '' ? ' style="' . htmlspecialchars($__bb_style, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><a href="javascript:history.back()"><?= htmlspecialchars(__('btn_back'), ENT_QUOTES, 'UTF-8') ?></a></p>
