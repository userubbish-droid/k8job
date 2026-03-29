<?php
/**
 * 侧栏预设头像：本地 assets/avatars/preset-1.png ~ preset-3.png（共 3 张，无男/女分栏）
 */
function avatar_presets_map(): array
{
    $base = 'assets/avatars';
    return [
        'p1' => $base . '/preset-1.png',
        'p2' => $base . '/preset-2.png',
        'p3' => $base . '/preset-3.png',
    ];
}

function avatar_preset_url(string $id): ?string
{
    $id = strtolower(trim($id));
    $map = avatar_presets_map();
    return $map[$id] ?? null;
}

function avatar_preset_is_valid(string $id): bool
{
    return avatar_preset_url($id) !== null;
}

/** @return list<array{id:string,url:string}> */
function avatar_presets_list(): array
{
    $out = [];
    foreach (avatar_presets_map() as $id => $url) {
        $out[] = ['id' => $id, 'url' => $url];
    }
    return $out;
}

/**
 * @deprecated 已无男/女分组，保留函数名以免旧代码报错
 * @return array{male: list<array{id:string,url:string}>, female: list<array{id:string,url:string}>}
 */
function avatar_presets_grouped(): array
{
    $list = avatar_presets_list();
    return ['male' => $list, 'female' => []];
}

/** @deprecated 不再使用性别切换 */
function avatar_preset_default_gender(string $avatar_url): string
{
    return 'male';
}

/** 当前 avatar_url 对应的预设 id */
function avatar_preset_id_from_url(string $avatar_url): ?string
{
    $avatar_url = trim($avatar_url);
    if ($avatar_url === '') {
        return null;
    }
    foreach (avatar_presets_map() as $id => $url) {
        if ($url === $avatar_url) {
            return $id;
        }
    }
    return null;
}
