<?php
// Transaction 相关页背景：配色方案 2（薰衣草紫 + 淡灰粉）
?>
<style>
    body.page-txn-lavender {
        background:
            radial-gradient(ellipse 125% 92% at 88% -8%, rgba(167, 139, 250, 0.18) 0%, transparent 52%),
            radial-gradient(ellipse 100% 88% at -5% 48%, rgba(192, 132, 252, 0.12) 0%, transparent 48%),
            radial-gradient(ellipse 70% 58% at 50% 100%, rgba(233, 213, 255, 0.14) 0%, transparent 50%),
            linear-gradient(162deg, #f5f3ff 0%, #faf5ff 28%, #f3e8ff 52%, #faf5ff 100%);
        background-attachment: fixed;
    }
    body.page-txn-lavender::before {
        background-image:
            linear-gradient(rgba(139, 92, 246, 0.055) 1px, transparent 1px),
            linear-gradient(90deg, rgba(192, 132, 252, 0.045) 1px, transparent 1px);
        background-size: 40px 40px, 40px 40px;
        mask-image: radial-gradient(ellipse 95% 85% at 50% 45%, #000 0%, transparent 72%);
        -webkit-mask-image: radial-gradient(ellipse 95% 85% at 50% 45%, #000 0%, transparent 72%);
    }
    body.page-txn-lavender::after {
        background: linear-gradient(
            105deg,
            transparent 0%,
            rgba(255, 255, 255, 0.34) 24%,
            transparent 40%,
            transparent 58%,
            rgba(245, 243, 255, 0.5) 74%,
            transparent 90%
        );
        opacity: 0.88;
    }
</style>
