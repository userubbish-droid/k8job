<?php
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

require_once __DIR__ . '/inc/avatar_presets.php';

$preset = strtolower(trim((string)($_POST['preset'] ?? '')));
$url = avatar_preset_url($preset);
if ($url === null) {
    echo json_encode(['ok' => false, 'error' => 'preset']);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ? LIMIT 1');
    $stmt->execute([$url, $uid]);
    $_SESSION['avatar_url'] = $url;
    echo json_encode(['ok' => true, 'url' => $url, 'preset' => $preset]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db']);
}
