<?php
/**
 * Per-company theme injection.
 *
 * Uses companies.ui_color (#RRGGBB) to override the global body background gradients.
 * Safe by design: any DB/permission/column issues fall back to default CSS.
 *
 * Usage (in <head>): include __DIR__ . '/company_theme.php';
 */
if (!function_exists('company_theme_hex_to_rgb')) {
    function company_theme_hex_to_rgb(string $hex): ?array
    {
        $h = strtoupper(trim($hex));
        if (!preg_match('/^#[0-9A-F]{6}$/', $h)) {
            return null;
        }
        return [
            hexdec(substr($h, 1, 2)),
            hexdec(substr($h, 3, 2)),
            hexdec(substr($h, 5, 2)),
        ];
    }
}

if (!function_exists('company_theme_mix')) {
    /** @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b */
    function company_theme_mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int)round($a[0] + ($b[0] - $a[0]) * $t),
            (int)round($a[1] + ($b[1] - $a[1]) * $t),
            (int)round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }
}

if (!function_exists('company_theme_rgba')) {
    /** @param array{0:int,1:int,2:int} $rgb */
    function company_theme_rgba(array $rgb, float $a): string
    {
        $a = max(0.0, min(1.0, $a));
        return 'rgba(' . (int)$rgb[0] . ',' . (int)$rgb[1] . ',' . (int)$rgb[2] . ',' . rtrim(rtrim(sprintf('%.3f', $a), '0'), '.') . ')';
    }
}

// Determine "active company" for theming.
// Priority: explicit $company_id (page-level override) → current_company_id() → session company_id.
$__theme_company_id = 0;
try {
    if (isset($company_id) && is_numeric($company_id) && (int)$company_id > 0) {
        $__theme_company_id = (int)$company_id;
    } elseif (function_exists('current_company_id')) {
        $__theme_company_id = (int)current_company_id();
    } else {
        $__theme_company_id = (int)($_SESSION['company_id'] ?? 0);
    }
} catch (Throwable $e) {
    $__theme_company_id = (int)($_SESSION['company_id'] ?? 0);
}

if ($__theme_company_id <= 0 || empty($pdo)) {
    return;
}

$__hex = '';
try {
    // Guard: ui_color may not exist on some installs.
    $st = $pdo->prepare("SELECT ui_color FROM companies WHERE id = ? LIMIT 1");
    $st->execute([$__theme_company_id]);
    $__hex = trim((string)($st->fetchColumn() ?: ''));
} catch (Throwable $e) {
    return;
}

$__rgb = company_theme_hex_to_rgb($__hex);
if (!$__rgb) {
    return;
}

$__white = [255, 255, 255];
$__c1 = $__rgb;
$__c2 = company_theme_mix($__rgb, $__white, 0.55);
$__c3 = company_theme_mix($__rgb, $__white, 0.78);
$__c4 = company_theme_mix($__rgb, $__white, 0.88);

$__rad1 = company_theme_rgba($__c1, 0.18);
$__rad2 = company_theme_rgba($__c2, 0.12);
$__rad3 = company_theme_rgba($__c3, 0.14);

$__lin1 = company_theme_rgba($__c4, 1.0);
$__lin2 = company_theme_rgba(company_theme_mix($__rgb, $__white, 0.92), 1.0);
$__lin3 = company_theme_rgba(company_theme_mix($__rgb, $__white, 0.84), 1.0);
$__lin4 = company_theme_rgba(company_theme_mix($__rgb, $__white, 0.92), 1.0);
?>
<style>
/* Company theme override: generated from companies.ui_color */
body{
    background:
        radial-gradient(ellipse 125% 92% at 88% -8%, <?= htmlspecialchars($__rad1, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 52%),
        radial-gradient(ellipse 100% 88% at -5% 48%, <?= htmlspecialchars($__rad2, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 48%),
        radial-gradient(ellipse 70% 58% at 50% 100%, <?= htmlspecialchars($__rad3, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 50%),
        linear-gradient(162deg,
            <?= htmlspecialchars($__lin1, ENT_QUOTES, 'UTF-8') ?> 0%,
            <?= htmlspecialchars($__lin2, ENT_QUOTES, 'UTF-8') ?> 28%,
            <?= htmlspecialchars($__lin3, ENT_QUOTES, 'UTF-8') ?> 52%,
            <?= htmlspecialchars($__lin4, ENT_QUOTES, 'UTF-8') ?> 100%
        );
    background-attachment: fixed;
}
</style>

