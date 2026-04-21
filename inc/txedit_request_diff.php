<?php
/** @return string HTML */
function txedit_h(?string $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function txedit_time_disp(?string $t): string
{
    if ($t === null || $t === '') {
        return '';
    }
    return substr(trim((string)$t), 0, 8);
}

/** @param mixed $v */
function txedit_money_disp($v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float)$v, 2);
}

/** @param mixed $a @param mixed $b */
function txedit_money_eq($a, $b): bool
{
    $fa = ($a === null || $a === '') ? null : round((float)$a, 2);
    $fb = ($b === null || $b === '') ? null : round((float)$b, 2);
    if ($fa === null && $fb === null) {
        return true;
    }
    if ($fa === null || $fb === null) {
        return false;
    }
    return abs($fa - $fb) < 0.0001;
}

/** @return string HTML */
function txedit_diff_text(?string $old, ?string $new): string
{
    $o = trim((string)($old ?? ''));
    $n = trim((string)($new ?? ''));
    if ($o === $n) {
        $one = $n === '' ? '—' : txedit_h($n);
        return $one;
    }
    $os = $o === '' ? '—' : txedit_h($o);
    $ns = $n === '' ? '—' : txedit_h($n);
    return $os . ' <span class="muted">→</span> ' . $ns;
}

/** @return string HTML */
function txedit_diff_time(?string $old, ?string $new): string
{
    return txedit_diff_text(txedit_time_disp($old), txedit_time_disp($new));
}

/** @param mixed $old @param mixed $new @return string HTML */
function txedit_diff_money($old, $new): string
{
    if (txedit_money_eq($old, $new)) {
        return txedit_h(txedit_money_disp($new));
    }
    return txedit_h(txedit_money_disp($old)) . ' <span class="muted">→</span> ' . txedit_h(txedit_money_disp($new));
}
