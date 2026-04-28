<?php
/**
 * PG statement 计算（balance_summary 用）：
 * - 左表：Channel（银行/渠道）
 * - 右表：Customer（member_code）
 *
 * 依赖：$pdo, $day_from, $day_to（Y-m-d）；$company_id 可选，缺省用 current_company_id()
 * 依赖（分库）：pdo_data_for_company_id($pdoCat, $company_id)；$pdoCat 使用 shard_catalog() 或 $pdo
 *
 * 产出：
 * - $pg_all_channels, $pg_initial_channel, $pg_range_in_channel, $pg_range_out_channel
 * - $pg_all_customers, $pg_initial_customer, $pg_range_in_customer, $pg_range_out_customer
 */
if (!isset($pdo, $day_from, $day_to)) {
    return;
}

$pg_cid = isset($company_id) ? (int)$company_id : (function_exists('current_company_id') ? (int)current_company_id() : -1);
if ($pg_cid <= 0) {
    $pg_cid = -1;
}

// PG 业务数据可能在分库
$pdoCat = null;
try {
    $pdoCat = (function_exists('shard_catalog') && shard_catalog()) ? shard_catalog() : $pdo;
} catch (Throwable $e) {
    $pdoCat = $pdo;
}

$pdoData = null;
try {
    if (function_exists('pdo_data_for_company_id') && $pg_cid > 0) {
        $pdoData = pdo_data_for_company_id($pdoCat, $pg_cid);
    }
} catch (Throwable $e) {
    $pdoData = null;
}

if (!$pdoData) {
    $pg_all_channels = [];
    $pg_initial_channel = [];
    $pg_range_in_channel = [];
    $pg_range_out_channel = [];
    $pg_all_customers = [];
    $pg_initial_customer = [];
    $pg_range_in_customer = [];
    $pg_range_out_customer = [];
    return;
}

// Helpers
$pg_all_channels = [];
$pg_all_customers = [];
$pg_initial_channel = [];
$pg_range_in_channel = [];
$pg_range_out_channel = [];
$pg_initial_customer = [];
$pg_range_in_customer = [];
$pg_range_out_customer = [];

try {
    $st = $pdoData->prepare("SELECT DISTINCT TRIM(channel) AS ch
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND channel IS NOT NULL AND TRIM(channel) <> ''
        ORDER BY ch ASC");
    $st->execute([$pg_cid]);
    $pg_all_channels = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $pg_all_channels = [];
}

try {
    $st = $pdoData->prepare("SELECT DISTINCT TRIM(member_code) AS cd
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND member_code IS NOT NULL AND TRIM(member_code) <> ''
        ORDER BY cd ASC");
    $st->execute([$pg_cid]);
    $pg_all_customers = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $pg_all_customers = [];
}

// 累计（day < day_from）
try {
    $st = $pdoData->prepare("SELECT TRIM(channel) AS k,
        COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) AS tout
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND txn_day < ? AND channel IS NOT NULL AND TRIM(channel) <> ''
        GROUP BY k");
    $st->execute([$pg_cid, $day_from]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtolower(trim((string)($r['k'] ?? '')));
        if ($k === '') continue;
        $pg_initial_channel[$k] = (float)($r['ti'] ?? 0) - (float)($r['tout'] ?? 0);
    }
} catch (Throwable $e) {
}

try {
    $st = $pdoData->prepare("SELECT TRIM(member_code) AS k,
        COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) AS tout
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND txn_day < ? AND member_code IS NOT NULL AND TRIM(member_code) <> ''
        GROUP BY k");
    $st->execute([$pg_cid, $day_from]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtolower(trim((string)($r['k'] ?? '')));
        if ($k === '') continue;
        $pg_initial_customer[$k] = (float)($r['ti'] ?? 0) - (float)($r['tout'] ?? 0);
    }
} catch (Throwable $e) {
}

// 区间（day_from ~ day_to）
try {
    $st = $pdoData->prepare("SELECT TRIM(channel) AS k,
        COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) AS tout
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND txn_day >= ? AND txn_day <= ?
          AND channel IS NOT NULL AND TRIM(channel) <> ''
        GROUP BY k");
    $st->execute([$pg_cid, $day_from, $day_to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtolower(trim((string)($r['k'] ?? '')));
        if ($k === '') continue;
        $pg_range_in_channel[$k] = (float)($r['ti'] ?? 0);
        $pg_range_out_channel[$k] = (float)($r['tout'] ?? 0);
    }
} catch (Throwable $e) {
}

try {
    $st = $pdoData->prepare("SELECT TRIM(member_code) AS k,
        COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) AS ti,
        COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) AS tout
        FROM pg_transactions
        WHERE company_id = ? AND status = 'approved' AND txn_day >= ? AND txn_day <= ?
          AND member_code IS NOT NULL AND TRIM(member_code) <> ''
        GROUP BY k");
    $st->execute([$pg_cid, $day_from, $day_to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtolower(trim((string)($r['k'] ?? '')));
        if ($k === '') continue;
        $pg_range_in_customer[$k] = (float)($r['ti'] ?? 0);
        $pg_range_out_customer[$k] = (float)($r['tout'] ?? 0);
    }
} catch (Throwable $e) {
}

// 补齐：存在于列表但没出现在聚合结果的键
foreach ($pg_all_channels as $nm) {
    $k = strtolower(trim((string)$nm));
    if ($k === '') continue;
    if (!isset($pg_initial_channel[$k])) $pg_initial_channel[$k] = 0.0;
    if (!isset($pg_range_in_channel[$k])) $pg_range_in_channel[$k] = 0.0;
    if (!isset($pg_range_out_channel[$k])) $pg_range_out_channel[$k] = 0.0;
}
foreach ($pg_all_customers as $nm) {
    $k = strtolower(trim((string)$nm));
    if ($k === '') continue;
    if (!isset($pg_initial_customer[$k])) $pg_initial_customer[$k] = 0.0;
    if (!isset($pg_range_in_customer[$k])) $pg_range_in_customer[$k] = 0.0;
    if (!isset($pg_range_out_customer[$k])) $pg_range_out_customer[$k] = 0.0;
}

