<?php
/**
 * 双库分片：catalog（主库）存 companies/users/权限等；PG 公司业务流水在可选的第二库。
 * 未配置 shard_config.php 或 dbname 为空时，pdo_business() 恒等于 catalog。
 */

function shard_catalog(): PDO
{
    if (!empty($GLOBALS['pdo_catalog']) && $GLOBALS['pdo_catalog'] instanceof PDO) {
        return $GLOBALS['pdo_catalog'];
    }
    global $pdo;
    return $pdo;
}

function shard_pg(): ?PDO
{
    $pg = $GLOBALS['pdo_pg_shard'] ?? null;
    return ($pg instanceof PDO) ? $pg : null;
}

/** 当前会话对应的业务库（流水/客户/银行产品等业务表） */
function pdo_business(): PDO
{
    if (!empty($GLOBALS['pdo_business']) && $GLOBALS['pdo_business'] instanceof PDO) {
        return $GLOBALS['pdo_business'];
    }
    return shard_catalog();
}

function shard_register_catalog(PDO $pdo): void
{
    $GLOBALS['pdo_catalog'] = $pdo;
    if (empty($GLOBALS['pdo_business']) || !($GLOBALS['pdo_business'] instanceof PDO)) {
        $GLOBALS['pdo_business'] = $pdo;
    }
}

function shard_try_connect_pg_from_config(): void
{
    $GLOBALS['pdo_pg_shard'] = null;
    $path = __DIR__ . '/../shard_config.php';
    if (!is_file($path)) {
        return;
    }
    $cfg = include $path;
    if (!is_array($cfg)) {
        return;
    }
    $dbname = trim((string)($cfg['dbname'] ?? ''));
    if ($dbname === '') {
        return;
    }
    $host = trim((string)($cfg['host'] ?? 'localhost'));
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');
    $charset = trim((string)($cfg['charset'] ?? 'utf8mb4'));
    if ($charset === '') {
        $charset = 'utf8mb4';
    }
    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $GLOBALS['pdo_pg_shard'] = new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        $GLOBALS['pdo_pg_shard'] = null;
    }
}

/**
 * 登录后按当前分公司 business_kind 切换业务 PDO（HQ 汇总 cid=0 时仍用 catalog）。
 */
function shard_refresh_business_pdo(): void
{
    $cat = shard_catalog();
    $pg = shard_pg();
    if (!$pg) {
        $GLOBALS['pdo_business'] = $cat;
        return;
    }
    $role = (string)($_SESSION['user_role'] ?? '');
    $cid = (int)($_SESSION['company_id'] ?? 0);
    if ($role === 'superadmin' && $cid === 0) {
        $GLOBALS['pdo_business'] = $cat;
        return;
    }
    $eff = $cid;
    if ($eff <= 0) {
        try {
            $eff = (int) $cat->query('SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        } catch (Throwable $e) {
            $eff = 1;
        }
    }
    if ($eff <= 0) {
        $GLOBALS['pdo_business'] = $cat;
        return;
    }
    try {
        $st = $cat->prepare('SELECT business_kind FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$eff]);
        $bk = strtolower(trim((string) $st->fetchColumn()));
        $GLOBALS['pdo_business'] = ($bk === 'pg') ? $pg : $cat;
    } catch (Throwable $e) {
        $GLOBALS['pdo_business'] = $cat;
    }
}

/** Webhook 等无 session 场景：按 company_id 选业务库（catalog 上读 business_kind） */
function pdo_data_for_company_id(PDO $catalog, int $companyId): PDO
{
    $pg = shard_pg();
    if (!$pg || $companyId <= 0) {
        return $catalog;
    }
    try {
        $st = $catalog->prepare('SELECT business_kind FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$companyId]);
        $bk = strtolower(trim((string) $st->fetchColumn()));
        if ($bk === 'pg') {
            return $pg;
        }
    } catch (Throwable $e) {
    }
    return $catalog;
}

/** 将 catalog 中该公司行同步到 PG 分库（id 与主库一致） */
function companies_shard_upsert_from_catalog(PDO $catalog, int $companyId): void
{
    $pg = shard_pg();
    if (!$pg || $companyId <= 0) {
        return;
    }
    try {
        $st = $catalog->prepare('SELECT id, code, name, currency, business_kind, is_active FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return;
        }
        $bk = strtolower(trim((string)($r['business_kind'] ?? 'gaming')));
        if ($bk !== 'pg') {
            companies_shard_delete_row($companyId);
            return;
        }
        $pg->prepare('INSERT INTO companies (id, code, name, currency, business_kind, is_active) VALUES (?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE code=VALUES(code), name=VALUES(name), currency=VALUES(currency), business_kind=VALUES(business_kind), is_active=VALUES(is_active)')
            ->execute([
                (int)$r['id'],
                (string)$r['code'],
                (string)$r['name'],
                (string)($r['currency'] ?? 'MYR'),
                $bk,
                (int)($r['is_active'] ?? 1),
            ]);
    } catch (Throwable $e) {
        // 分库未建表或结构不一致时忽略，避免阻断主库保存
    }
}

function companies_shard_delete_row(int $companyId): void
{
    $pg = shard_pg();
    if (!$pg || $companyId <= 0) {
        return;
    }
    try {
        $pg->prepare('DELETE FROM companies WHERE id = ? LIMIT 1')->execute([$companyId]);
    } catch (Throwable $e) {
    }
}

/** 统计某表在某库中 company_id 行数（用于删除前检查） */
function shard_table_company_count(PDO $db, string $table, int $companyId): int
{
    if ($companyId <= 0) {
        return 0;
    }
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE company_id = ?");
        $stmt->execute([$companyId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
