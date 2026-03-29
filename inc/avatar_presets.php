<?php
/**
 * 侧栏预设头像：男/女各 9 个，DiceBear 7.x SVG（需可访问 api.dicebear.com）
 * 男：personas（偏立体、清爽）；女：avataaars（偏精致、常见应用风）
 */
function avatar_presets_dicebear_base(): string {
    return 'https://api.dicebear.com/7.x';
}

/** @return array<string, string> id => url */
function avatar_presets_map(): array {
    $base = avatar_presets_dicebear_base();
    $out = [];
    for ($i = 0; $i < 9; $i++) {
        $out['m' . $i] = $base . '/personas/svg?seed=' . rawurlencode('k8m' . $i . 'x7')
            . '&backgroundColor=dbeafe&radius=50';
        $out['f' . $i] = $base . '/avataaars/svg?seed=' . rawurlencode('k8f' . $i . 'x7')
            . '&backgroundColor=fce7f3&radius=50';
    }
    return $out;
}

function avatar_preset_url(string $id): ?string {
    $id = strtolower(trim($id));
    $map = avatar_presets_map();
    return $map[$id] ?? null;
}

function avatar_preset_is_valid(string $id): bool {
    return avatar_preset_url($id) !== null;
}

/** @return array{male: list<array{id:string,url:string}>, female: list<array{id:string,url:string}>} */
function avatar_presets_grouped(): array {
    $male = [];
    $female = [];
    for ($i = 0; $i < 9; $i++) {
        $mid = 'm' . $i;
        $fid = 'f' . $i;
        $map = avatar_presets_map();
        $male[] = ['id' => $mid, 'url' => $map[$mid]];
        $female[] = ['id' => $fid, 'url' => $map[$fid]];
    }
    return ['male' => $male, 'female' => $female];
}

/** 根据当前 avatar_url 推断默认展示的性别页 */
function avatar_preset_default_gender(string $avatar_url): string {
    $avatar_url = trim($avatar_url);
    if ($avatar_url === '') {
        return 'male';
    }
    $map = avatar_presets_map();
    foreach ($map as $id => $url) {
        if ($url === $avatar_url && strlen($id) > 0 && $id[0] === 'f') {
            return 'female';
        }
    }
    return 'male';
}

/** 当前 URL 对应的预设 id，无则 null */
function avatar_preset_id_from_url(string $avatar_url): ?string {
    $avatar_url = trim($avatar_url);
    foreach (avatar_presets_map() as $id => $url) {
        if ($url === $avatar_url) {
            return $id;
        }
    }
    return null;
}
