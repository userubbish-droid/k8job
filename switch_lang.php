<?php
require __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/inc/i18n.php';
i18n_bootstrap();

$lang = isset($_GET['lang']) ? strtolower(trim((string)$_GET['lang'])) : 'en';
if ($lang !== 'en' && $lang !== 'zh') {
    $lang = 'en';
}
i18n_set_lang($lang);

$to = isset($_GET['to']) ? rawurldecode(trim((string)$_GET['to'])) : 'dashboard.php';
if ($to === '' || preg_match('#^https?://#i', $to) !== 0 || strpbrk($to, "\r\n") !== false || strpos($to, '..') !== false) {
    $to = 'dashboard.php';
}
if ($to[0] === '/') {
    $to = ltrim($to, '/');
}

header('Location: ' . $to);
exit;
