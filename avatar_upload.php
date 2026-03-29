<?php
/**
 * 当前登录用户上传自定义头像（保存到 uploads/avatars/{id}.ext，并写入 users.avatar_url）。
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$file = $_FILES['avatar'] ?? null;
if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'nofile']);
    exit;
}

$maxBytes = 2 * 1024 * 1024;
if ((int)($file['size'] ?? 0) > $maxBytes) {
    echo json_encode(['ok' => false, 'error' => 'toobig']);
    exit;
}

$tmp = $file['tmp_name'];
$fi = new finfo(FILEINFO_MIME_TYPE);
$mime = $fi->file($tmp) ?: '';
$map = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($map[$mime])) {
    echo json_encode(['ok' => false, 'error' => 'type']);
    exit;
}
$ext = $map[$mime];

$info = @getimagesize($tmp);
if ($info === false) {
    echo json_encode(['ok' => false, 'error' => 'image']);
    exit;
}
[$w, $h] = $info;
if ($w < 32 || $h < 32 || $w > 4096 || $h > 4096) {
    echo json_encode(['ok' => false, 'error' => 'dimensions']);
    exit;
}

$dir = __DIR__ . '/uploads/avatars';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    echo json_encode(['ok' => false, 'error' => 'mkdir']);
    exit;
}

foreach (glob($dir . DIRECTORY_SEPARATOR . $uid . '.*') ?: [] as $old) {
    if (is_file($old)) {
        @unlink($old);
    }
}

$dest = $dir . DIRECTORY_SEPARATOR . $uid . '.' . $ext;
if (!move_uploaded_file($tmp, $dest)) {
    echo json_encode(['ok' => false, 'error' => 'move']);
    exit;
}

$rel = 'uploads/avatars/' . $uid . '.' . $ext;
try {
    $stmt = $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ? LIMIT 1');
    $stmt->execute([$rel, $uid]);
    $_SESSION['avatar_url'] = $rel;
    echo json_encode(['ok' => true, 'url' => $rel]);
} catch (Throwable $e) {
    @unlink($dest);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
