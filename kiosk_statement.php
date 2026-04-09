<?php
/**
 * Kiosk Statement：与 statement 页 Game Platform 同源数据，并按 DEPOSIT / REBATE / FREE / FREE WITHDRAW / bonus 字段拆分展示。
 */
require 'config.php';
require 'auth.php';
require_login();
require_permission('kiosk_statement');

$sidebar_current = 'kiosk_statement';
$is_admin = in_array(($_SESSION['user_role'] ?? ''), ['admin', 'boss'], true);

$day_from = isset($_GET['day_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_from']) ? $_GET['day_from'] : null;
$day_to   = isset($_GET['day_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day_to']) ? $_GET['day_to'] : null;
$day      = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day']) ? $_GET['day'] : null;
if ($day_from !== null && $day_to !== null) {
    if ($day_from > $day_to) {
        $t = $day_from;
        $day_from = $day_to;
        $day_to = $t;
    }
} elseif ($day !== null) {
    $day_from = $day_to = $day;
} else {
    $day_from = $day_to = date('Y-m-d');
}
$is_range = ($day_from !== $day_to);

$company_id = current_company_id();
require_once __DIR__ . '/inc/game_platform_statement_compute.php';

function kiosk_stmt_fmt_in(float $v): string {
    return $v != 0 ? '−' . number_format($v, 2) : '—';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kiosk Statement - <?= defined('SITE_TITLE') ? SITE_TITLE : 'K8' ?></title>
    <?php include __DIR__ . '/inc/sidebar_critical_css.php'; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
    <style>
    .stmt-drill {
        background: none; border: none; padding: 0; margin: 0; font: inherit;
        cursor: pointer; text-decoration: none; color: inherit; text-align: right; width: 100%;
    }
    .stmt-drill:hover { opacity: 0.88; }
    .stmt-drill:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }
    .stmt-drill-mask {
        display: none; position: fixed; inset: 0; z-index: 1200;
        background: rgba(15, 23, 42, 0.45); align-items: center; justify-content: center; padding: 16px;
    }
    .stmt-drill-mask.show { display: flex; }
    .stmt-drill-dialog {
        background: #fff; border-radius: 14px; max-width: min(960px, 100%);
        max-height: min(85vh, 900px); display: flex; flex-direction: column;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2); width: 100%;
    }
    .stmt-drill-head {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 14px 18px; border-bottom: 1px solid #e2e8f0;
    }
    .stmt-drill-head h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text); }
    .stmt-drill-x {
        border: none; background: #f1f5f9; width: 36px; height: 36px; border-radius: 10px;
        font-size: 22px; line-height: 1; cursor: pointer; color: #475569;
    }
    .stmt-drill-x:hover { background: #e2e8f0; }
    .stmt-drill-body { padding: 12px 16px 18px; overflow: auto; flex: 1; }
    .stmt-drill-body h4 { margin: 16px 0 8px; font-size: 13px; font-weight: 700; color: var(--muted); text-transform: none; }
    .stmt-drill-body h4:first-child { margin-top: 0; }
    .stmt-drill-table { width: 100%; font-size: 13px; margin-bottom: 8px; }
    .stmt-drill-table th, .stmt-drill-table td { padding: 6px 8px; white-space: nowrap; }
    .stmt-drill-table td.stmt-drill-wrap { white-space: normal; max-width: 220px; }
    .stmt-drill-loading, .stmt-drill-err { padding: 20px; text-align: center; color: var(--muted); }
    .stmt-drill-err { color: var(--danger); }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/inc/sidebar.php'; ?>
        <main class="dashboard-main">
            <div class="page-wrap" style="max-width: 100%;">
                <div class="page-header">
                    <h2>Kiosk Statement</h2>
                    <p class="breadcrumb">
                        <a href="dashboard.php">首页</a><span>·</span>
                        <span>Kiosk Statement</span>
                    </p>
                </div>

                <div class="card">
                    <div class="statement-filter-wrap" style="margin-bottom:16px;">
                        <button type="button" class="btn btn-outline" id="stmt-date-toggle">筛选日期</button>
                        <form method="get" class="stmt-date-form" id="stmt-date-form" style="display:none; margin-top:10px; align-items:center; gap:10px; flex-wrap:wrap;">
                            <label style="font-size:13px;">从</label>
                            <input type="date" name="day_from" id="stmt-day-from" value="<?= htmlspecialchars($day_from) ?>">
                            <label style="font-size:13px;">至</label>
                            <input type="date" name="day_to" id="stmt-day-to" value="<?= htmlspecialchars($day_to) ?>">
                            <button type="submit" class="btn btn-primary">查询</button>
                            <div style="flex-basis:100%; height:0;"></div>
                            <span style="font-size:13px; color:var(--muted);">快捷：</span>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="7">一个星期</button>
                            <button type="button" class="btn btn-sm btn-outline stmt-quick-range" data-days="30">一个月</button>
                        </form>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="total-table">
                            <thead>
                                <tr>
                                    <th>Game Platform</th>
                                    <?php if ($is_admin): ?>
                                        <th class="num">Starting</th>
                                        <th class="num">Deposit</th>
                                        <th class="num">Rebate</th>
                                        <th class="num">Free</th>
                                        <th class="num">Free WD</th>
                                        <th class="num">Bonus</th>
                                        <th class="num">Topup</th>
                                        <th class="num">Out</th>
                                        <th class="num">In 合计</th>
                                    <?php endif; ?>
                                    <th class="num">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_products as $name):
                                    $name = trim((string)$name);
                                    if ($name === '') {
                                        continue;
                                    }
                                    $key = strtolower($name);
                                    $bd = $range_breakdown_product[$key] ?? ['dep' => 0.0, 'reb' => 0.0, 'fr' => 0.0, 'fwd' => 0.0, 'bns' => 0.0];
                                    $dep = (float)$bd['dep'];
                                    $reb = (float)$bd['reb'];
                                    $fr = (float)$bd['fr'];
                                    $fwd = (float)$bd['fwd'];
                                    $bns = (float)$bd['bns'];
                                    $in = (float)($range_in_product[$key] ?? 0);
                                    $topup = (float)($range_topup_product[$key] ?? 0);
                                    $out = (float)($range_out_product[$key] ?? 0);
                                    $init = $initial_product[$name] ?? 0;
                                    $balance = $init - $in + $topup + $out;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <?php if ($is_admin): ?>
                                            <td class="num"><?= number_format($init, 2) ?></td>
                                            <td class="num stmt-in"><?php if ($dep != 0): ?><button type="button" class="stmt-drill" data-bucket="dep" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= kiosk_stmt_fmt_in($dep) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-in"><?php if ($reb != 0): ?><button type="button" class="stmt-drill" data-bucket="reb" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= kiosk_stmt_fmt_in($reb) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-in"><?php if ($fr != 0): ?><button type="button" class="stmt-drill" data-bucket="fr" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= kiosk_stmt_fmt_in($fr) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-in"><?php if ($fwd != 0): ?><button type="button" class="stmt-drill" data-bucket="fwd" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= kiosk_stmt_fmt_in($fwd) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num"><?php if ($bns != 0): ?><button type="button" class="stmt-drill profit" style="color:inherit" data-bucket="bns" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($bns, 2) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-topup"><?php if ($topup != 0): ?><button type="button" class="stmt-drill" data-bucket="topup" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($topup, 2) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-out"><?php if ($out != 0): ?><button type="button" class="stmt-drill" data-bucket="out" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($out, 2) ?></button><?php else: ?>—<?php endif; ?></td>
                                            <td class="num stmt-in"><?php if ($in != 0): ?><button type="button" class="stmt-drill" data-bucket="in" data-product="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= kiosk_stmt_fmt_in($in) ?></button><?php else: ?>—<?php endif; ?></td>
                                        <?php endif; ?>
                                        <td class="num <?= $balance < 0 ? 'stmt-negative' : 'profit' ?>"><?= number_format($balance, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($all_products)): ?>
                                    <tr><td colspan="<?= $is_admin ? 11 : 2 ?>">暂无</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php if ($is_admin): ?>
    <div class="stmt-drill-mask" id="stmt-drill-mask" aria-hidden="true">
        <div class="stmt-drill-dialog" role="dialog" aria-modal="true" aria-labelledby="stmt-drill-title">
            <div class="stmt-drill-head">
                <h3 id="stmt-drill-title">明细</h3>
                <button type="button" class="stmt-drill-x" id="stmt-drill-close" aria-label="关闭">×</button>
            </div>
            <div class="stmt-drill-body" id="stmt-drill-body"></div>
        </div>
    </div>
    <?php endif; ?>
<script>
window.KIOSK_STMT = <?= json_encode(['day_from' => $day_from, 'day_to' => $day_to], JSON_UNESCAPED_UNICODE) ?>;
(function(){
    var btn = document.getElementById('stmt-date-toggle');
    var form = document.getElementById('stmt-date-form');
    if (btn && form) {
        btn.addEventListener('click', function(){
            form.style.display = form.style.display === 'none' ? 'flex' : 'none';
        });
    }
    var fromEl = document.getElementById('stmt-day-from');
    var toEl = document.getElementById('stmt-day-to');
    document.querySelectorAll('.stmt-quick-range').forEach(function(b){
        b.addEventListener('click', function(){
            var days = parseInt(b.getAttribute('data-days'), 10) || 7;
            var end = new Date();
            var start = new Date(end);
            start.setDate(start.getDate() - (days - 1));
            var fmt = function(d) {
                var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            };
            if (fromEl) fromEl.value = fmt(start);
            if (toEl) toEl.value = fmt(end);
            if (form) form.submit();
        });
    });
})();
<?php if ($is_admin): ?>
(function(){
    var mask = document.getElementById('stmt-drill-mask');
    var bodyEl = document.getElementById('stmt-drill-body');
    var titleEl = document.getElementById('stmt-drill-title');
    var closeBtn = document.getElementById('stmt-drill-close');
    if (!mask || !bodyEl || !titleEl) return;
    function closeDrill() {
        mask.classList.remove('show');
        mask.setAttribute('aria-hidden', 'true');
        bodyEl.innerHTML = '';
    }
    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function renderRows(sec, canEdit) {
        var tbl = document.createElement('table');
        tbl.className = 'data-table stmt-drill-table';
        var thead = document.createElement('thead');
        var hr = document.createElement('tr');
        sec.columns.forEach(function(c) {
            var th = document.createElement('th');
            th.textContent = c;
            hr.appendChild(th);
        });
        if (canEdit) {
            var tha = document.createElement('th');
            tha.textContent = '';
            hr.appendChild(tha);
        }
        thead.appendChild(hr);
        tbl.appendChild(thead);
        var tb = document.createElement('tbody');
        (sec.rows || []).forEach(function(row) {
            var tr = document.createElement('tr');
            var cells = row.cells || [];
            cells.forEach(function(cell, idx) {
                var td = document.createElement('td');
                if (idx === cells.length - 1 && String(cell).length > 40) td.className = 'stmt-drill-wrap';
                td.textContent = cell;
                tr.appendChild(td);
            });
            if (canEdit && row.txn_id) {
                var tda = document.createElement('td');
                var a = document.createElement('a');
                a.href = 'transaction_edit.php?id=' + encodeURIComponent(row.txn_id);
                a.textContent = '编辑';
                a.className = 'btn btn-sm btn-outline';
                tda.appendChild(a);
                tr.appendChild(tda);
            } else if (canEdit) {
                tr.appendChild(document.createElement('td'));
            }
            tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        return tbl;
    }
    function openDrill(bucket, product) {
        var q = window.KIOSK_STMT || {};
        var url = 'kiosk_statement_detail.php?bucket=' + encodeURIComponent(bucket)
            + '&product=' + encodeURIComponent(product)
            + '&day_from=' + encodeURIComponent(q.day_from || '')
            + '&day_to=' + encodeURIComponent(q.day_to || '');
        mask.classList.add('show');
        mask.setAttribute('aria-hidden', 'false');
        titleEl.textContent = '加载中…';
        bodyEl.innerHTML = '<div class="stmt-drill-loading">加载中…</div>';
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) {
                    bodyEl.innerHTML = '<div class="stmt-drill-err">' + esc(data.error || '加载失败') + '</div>';
                    titleEl.textContent = '明细';
                    return;
                }
                titleEl.textContent = data.title || '明细';
                bodyEl.innerHTML = '';
                var canEdit = !!data.can_edit_txn;
                (data.sections || []).forEach(function(sec) {
                    if (!sec.rows || sec.rows.length === 0) return;
                    var h = document.createElement('h4');
                    h.textContent = sec.label || '';
                    bodyEl.appendChild(h);
                    bodyEl.appendChild(renderRows(sec, canEdit));
                });
                if (!bodyEl.querySelector('.stmt-drill-table')) {
                    bodyEl.innerHTML = '<div class="stmt-drill-loading">暂无明细</div>';
                }
                if (data.truncated) {
                    var p = document.createElement('p');
                    p.className = 'form-hint';
                    p.style.marginTop = '10px';
                    p.textContent = '仅显示前 400 条流水，如需更多请缩短日期范围或在流水列表筛选。';
                    bodyEl.appendChild(p);
                }
            })
            .catch(function() {
                bodyEl.innerHTML = '<div class="stmt-drill-err">网络错误</div>';
                titleEl.textContent = '明细';
            });
    }
    document.querySelectorAll('.stmt-drill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openDrill(btn.getAttribute('data-bucket'), btn.getAttribute('data-product'));
        });
    });
    if (closeBtn) closeBtn.addEventListener('click', closeDrill);
    mask.addEventListener('click', function(e) { if (e.target === mask) closeDrill(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mask.classList.contains('show')) closeDrill();
    });
})();
<?php endif; ?>
</script>
</body>
</html>
