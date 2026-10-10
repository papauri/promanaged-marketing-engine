<?php
/**
 * Picture cards, drawn with GD only. Ten layouts x five sizes, brand colours, a WhatsApp / website strip on every card.
 * pm_card_render(post, size): ?string  - png path, '' when it could not be drawn, null when GD or the fonts are missing (the core then uses its old card).
 * Files: data/social/<id>_<size>.png, slides data/social/<id>_<size>_<n>.png, special cards data/social/special/.
 * Sizes in this file are PIXELS; GD wants points, so they are converted (px x 0.75) when text is drawn or measured.
 */

const PM_CARD_LAYOUTS = ['headline', 'checklist', 'mythfact', 'beforeafter', 'quote', 'faq', 'offer', 'cover', 'slide', 'photo'];

function pm_card_sizes(): array
{
    return ['sq' => [1080, 1080], '4x5' => [1080, 1350], 'story' => [1080, 1920], 'link' => [1200, 627], 'gbp' => [1200, 900]];
}

/* ---------------- colour ---------------- */

function pm_card_rgb(string $hex, array $fallback = [23, 55, 94]): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))] : $fallback;
}

/** WCAG relative luminance, 0 (black) to 1 (white). */
function pm_card_lum(array $rgb): float
{
    $f = fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $f($rgb[0]) + 0.7152 * $f($rgb[1]) + 0.0722 * $f($rgb[2]);
}

function pm_card_contrast(array $a, array $b): float
{
    $x = pm_card_lum($a);
    $y = pm_card_lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** Text colour for use ON a background: white, or the dark ink when white would be hard to read (light accents). */
function pm_card_on(array $bg): array
{
    $ink = [22, 24, 29];
    return pm_card_contrast([255, 255, 255], $bg) >= pm_card_contrast($ink, $bg) ? [255, 255, 255] : $ink;
}

/** The accent made dark enough to read as text or a line on the light card background. */
function pm_card_accent_text(array $accent): array
{
    $bg = [250, 249, 247];
    for ($i = 0; $i < 20 && pm_card_contrast($accent, $bg) < 4.5; $i++) {
        $accent = [(int)round($accent[0] * 0.85), (int)round($accent[1] * 0.85), (int)round($accent[2] * 0.85)];
    }
    return $accent;
}

/** Text colour (rgb) that would be used on this accent hex. For tests and callers that want to know. */
function pm_card_text_color_for(string $accentHex): array { return pm_card_on(pm_card_rgb($accentHex)); }

/* ---------------- text ---------------- */

/** Plain text for a picture: no emoji or hidden joiners, single spaces. */
function pm_card_clean(string $t): string
{
    $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{FE00}-\x{FE0F}\x{200B}-\x{200F}\x{20E3}\x{E0000}-\x{E007F}]/u', '', $t);
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

function pm_card_fonts(): array
{
    $f = [];
    foreach (['serif' => [true, false], 'serifb' => [true, true], 'sans' => [false, false], 'sansb' => [false, true]] as $k => [$serif, $bold]) {
        $p = function_exists('pm_font') ? pm_font($serif, $bold) : '';
        if ($p === '' || !is_file($p)) {
            $p = PM_ROOT . '/assets/fonts/' . ($serif ? 'serif' : 'sans') . ($bold ? '-bold' : '') . '.ttf';
        }
        if (!is_file($p)) {
            return [];
        }
        $f[$k] = $p;
    }
    return $f;
}

function pm_card_gd_ok(): bool
{
    return (function_exists('pm_social_gd_ok') ? pm_social_gd_ok() : (function_exists('imagettftext') && function_exists('imagecreatetruecolor'))) && pm_card_fonts() !== [];
}

function pm_card_tw(string $font, float $px, string $text): int
{
    $b = imagettfbbox($px * 0.75, 0, $font, $text);
    return (int)($b[2] - $b[0]);
}

/** Wraps to lines no wider than $w. A word wider than the line is broken so nothing runs off the card. Sets $broke when that happened. */
function pm_card_wrap(string $text, string $font, float $px, int $w, bool &$broke = false): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        while (pm_card_tw($font, $px, $word) > $w && mb_strlen($word) > 1) { // clamp a wide word
            $broke = true;
            $cut = mb_strlen($word) - 1;
            while ($cut > 1 && pm_card_tw($font, $px, mb_substr($word, 0, $cut) . '-') > $w) {
                $cut--;
            }
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            $lines[] = mb_substr($word, 0, $cut) . '-';
            $word = mb_substr($word, $cut);
        }
        $try = $line === '' ? $word : "$line $word";
        if ($line !== '' && pm_card_tw($font, $px, $try) > $w) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

/** Largest size (px) between $min and $max at which $text fits the box. Returns [px, lines]; at $min the text is cut with an ellipsis if still too long. */
function pm_card_fit(string $text, string $font, int $max, int $min, int $w, int $h, float $lh = 1.22): array
{
    $text = pm_card_clean($text);
    $px = $max;
    for (; $px >= $min; $px -= 2) {
        $broke = false;
        $lines = pm_card_wrap($text, $font, $px, $w, $broke);
        if (!$broke && count($lines) * $px * $lh <= $h) {
            return [$px, $lines];
        }
    }
    $px = $min;
    $lines = pm_card_wrap($text, $font, $px, $w);
    $maxLines = max(1, (int)floor($h / ($px * $lh)));
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = rtrim($lines[$maxLines - 1], " .,;:-");
        while ($last !== '' && pm_card_tw($font, $px, $last . '...') > $w) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[$maxLines - 1] = $last . '...';
    }
    return [$px, $lines];
}

/** Draws lines starting with the top of the first line at $y. Returns the y below the last line. */
function pm_card_draw(array $c, array $lines, string $font, float $px, array $rgb, int $x, int $y, float $lh = 1.22, string $align = 'left', int $boxW = 0): int
{
    $col = imagecolorallocate($c['im'], $rgb[0], $rgb[1], $rgb[2]);
    foreach ($lines as $l) {
        $dx = 0;
        if ($align !== 'left' && $boxW > 0) {
            $dx = $align === 'center' ? (int)(($boxW - pm_card_tw($font, $px, $l)) / 2) : $boxW - pm_card_tw($font, $px, $l);
        }
        imagettftext($c['im'], $px * 0.75, 0, $x + $dx, (int)($y + $px * 0.92), $col, $font, $l);
        $y += (int)round($px * $lh);
    }
    return $y;
}

function pm_card_fill(array $c, array $rgb): int { return imagecolorallocate($c['im'], $rgb[0], $rgb[1], $rgb[2]); }

function pm_card_rrect(array $c, int $x1, int $y1, int $x2, int $y2, int $r, array $rgb): void
{
    $im = $c['im'];
    $col = pm_card_fill($c, $rgb);
    $r = max(0, min($r, intdiv(min($x2 - $x1, $y2 - $y1), 2)));
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
    }
}

/** Spaced capital label, e.g. an eyebrow. Returns its width. */
function pm_card_spaced(array $c, string $text, float $px, array $rgb, int $x, int $y, int $gap = 3): int
{
    $col = pm_card_fill($c, $rgb);
    $x0 = $x;
    foreach (mb_str_split($text) as $ch) {
        imagettftext($c['im'], $px * 0.75, 0, $x, (int)($y + $px * 0.92), $col, $c['f']['sansb'], $ch);
        $x += pm_card_tw($c['f']['sansb'], $px, $ch . '.') - pm_card_tw($c['f']['sansb'], $px, '.') + $gap;
    }
    return $x - $x0;
}

function pm_card_spaced_w(array $c, string $text, float $px, int $gap = 3): int
{
    $w = 0;
    foreach (mb_str_split($text) as $ch) {
        $w += pm_card_tw($c['f']['sansb'], $px, $ch . '.') - pm_card_tw($c['f']['sansb'], $px, '.') + $gap;
    }
    return $w;
}

/* ---------------- frame: background, eyebrow, CTA strip ---------------- */

/** Everything a layout needs. Switches the brand while the card is drawn; pm_card_end() puts it back. */
function pm_card_begin(array $p, string $size): ?array
{
    $sizes = pm_card_sizes();
    [$W, $H] = $sizes[$size] ?? $sizes['sq'];
    $f = pm_card_fonts();
    if (!$f || !function_exists('imagecreatetruecolor')) {
        return null;
    }
    $prev = pm_brand();
    pm_brand_set((string)($p['brand'] ?? 'promanaged'));
    $s = pm_settings();
    $brand = pm_brand();
    $im = imagecreatetruecolor($W, $H);
    $s1 = min(1.0, sqrt($W * $H) / 1080);
    $story = $size === 'story';
    $accent = pm_card_rgb((string)($s['accent_color'] ?? ''));
    $pillar = (string)($p['pillar'] ?? '');
    $class = function_exists('pm_sx_pillar_class') ? pm_sx_pillar_class($pillar) : 'tip';
    $cls = function_exists('pm_sx_pillar_class') ? PM_SX_CLASSES[$class] : ['color' => '#17375E', 'label' => strtoupper($pillar)];
    $website = function_exists('pm_link_cfg') ? (string)(pm_link_cfg($brand)['url'] ?: ($s['website'] ?? '')) : (string)($s['website'] ?? '');
    $c = ['im' => $im, 'W' => $W, 'H' => $H, 's' => $s1, 'size' => $size, 'f' => $f, 'prev' => $prev, 'brand' => $brand, 'p' => $p, 'story' => $story,
        'pad' => (int)round(max(44, 80 * $s1)), 'accent' => $accent, 'on' => pm_card_on($accent), 'accentT' => pm_card_accent_text($accent),
        'ink' => [22, 24, 29], 'muted' => [88, 93, 102], 'bg' => [250, 249, 247], 'name' => (string)($s['company_name'] ?? ''), 'phone' => trim((string)($s['phone'] ?? '')),
        'site' => preg_replace('#^https?://(www\.)?#i', '', rtrim($website, '/')), 'pcolor' => pm_card_rgb($cls['color']), 'label' => $cls['label'],
        'top' => $story ? 250 : 0, 'bottom' => $H - ($story ? 340 : 0)];
    imagefill($im, 0, 0, pm_card_fill($c, $c['bg']));
    return $c;
}

function pm_card_end(array $c): void
{
    imagedestroy($c['im']);
    pm_brand_set($c['prev']);
}

/** Bottom strip in the brand colour: logo, name, and the way to reach us (WhatsApp number if set, else the website). Returns its top y. */
function pm_card_strip(array &$c): int
{
    $h = (int)round(max(96, 140 * $c['s']));
    $y = $c['bottom'] - $h;
    imagefilledrectangle($c['im'], 0, $y, $c['W'], $c['bottom'], pm_card_fill($c, $c['accent']));
    $x = $c['pad'];
    $logo = function_exists('pm_logo_path') ? pm_logo_path() : '';
    if ($logo !== '' && ($li = @imagecreatefrompng($logo))) {
        $lh = $h - 36;
        $lw = (int)round(imagesx($li) * $lh / max(1, imagesy($li)));
        $lw = min($lw, 220);
        $lh2 = (int)round(imagesy($li) * $lw / max(1, imagesx($li)));
        pm_card_rrect($c, $x, $y + (int)(($h - $lh2) / 2) - 8, $x + $lw + 16, $y + (int)(($h + $lh2) / 2) + 8, 10, [255, 255, 255]);
        imagecopyresampled($c['im'], $li, $x + 8, $y + (int)(($h - $lh2) / 2), 0, 0, $lw, $lh2, imagesx($li), imagesy($li));
        imagedestroy($li);
        $x += $lw + 16 + 28;
    }
    $on = $c['on'];
    $right = $c['phone'] !== '' ? 'WhatsApp ' . $c['phone'] : $c['site'];
    $rpx = 34 * $c['s'];
    $maxR = (int)($c['W'] - $c['pad'] - $x) * 0.62;
    while ($right !== '' && pm_card_tw($c['f']['sansb'], $rpx, $right) > $maxR && $rpx > 18) {
        $rpx -= 2;
    }
    $rw = $right !== '' ? pm_card_tw($c['f']['sansb'], $rpx, $right) : 0;
    $npx = 32 * $c['s'];
    while (pm_card_tw($c['f']['sansb'], $npx, $c['name']) > $c['W'] - $c['pad'] - $x - $rw - 30 && $npx > 16) {
        $npx -= 2;
    }
    pm_card_draw($c, [$c['name']], $c['f']['sansb'], $npx, $on, $x, (int)($y + ($h - $npx * 1.2) / 2), 1.2);
    if ($right !== '') {
        pm_card_draw($c, [$right], $c['f']['sansb'], $rpx, $on, $c['W'] - $c['pad'] - $rw, (int)($y + ($h - $rpx * 1.2) / 2), 1.2);
    }
    return $y;
}

/** Top accent bar and the pillar label (eyebrow). Returns the y just under the label. */
function pm_card_eyebrow(array &$c, ?string $label = null, string $right = ''): int
{
    $im = $c['im'];
    $y = $c['top'];
    if (!$c['story']) {
        imagefilledrectangle($im, 0, 0, $c['W'], (int)round(14 * $c['s']), pm_card_fill($c, $c['accent']));
        $y = (int)round(14 * $c['s']);
    }
    $y += (int)round(40 * $c['s']);
    $label = pm_card_clean((string)($label ?? $c['label']));
    $px = 22 * $c['s'];
    $x = $c['pad'];
    if ($label !== '') {
        $label = mb_strtoupper($label);
        $w = pm_card_spaced_w($c, $label, $px);
        $hh = (int)round($px * 1.2 + 22 * $c['s']);
        pm_card_rrect($c, $x, $y, $x + $w + (int)(36 * $c['s']), $y + $hh, (int)($hh / 2), $c['pcolor']);
        pm_card_spaced($c, $label, $px, pm_card_on($c['pcolor']), $x + (int)(18 * $c['s']), $y + (int)(11 * $c['s']));
        $y += $hh;
    }
    if ($right !== '') {
        $rp = 26 * $c['s'];
        $rw = pm_card_tw($c['f']['sansb'], $rp, $right);
        pm_card_draw($c, [$right], $c['f']['sansb'], $rp, $c['muted'], $c['W'] - $c['pad'] - $rw, $c['top'] + (int)(($c['story'] ? 40 : 54) * $c['s']), 1.2);
    }
    return $y + (int)round(30 * $c['s']);
}

/** Vertical centring: returns the y at which a block of height $h starts inside [$top,$bottom]. */
function pm_card_center(int $top, int $bottom, int $h): int { return $top + max(0, (int)(($bottom - $top - $h) / 2)); }

/* ---------------- content helpers ---------------- */

function pm_card_headline(array $p): string
{
    $h = pm_card_clean((string)($p['headline'] ?? ''));
    if ($h === '') {
        $first = preg_split('/(?<=[.!?])\s/u', pm_card_clean((string)($p['caption'] ?? '')))[0] ?? '';
        $h = mb_substr($first, 0, 70);
    }
    return $h;
}

/** [['h','t'], ...] for checklist-style cards: slides, else numbered/bulleted caption lines, else the sub line split up. */
function pm_card_items(array $p, int $max = 5): array
{
    $items = [];
    foreach ((array)($p['slides'] ?? []) as $s) {
        if (trim((string)($s['h'] ?? '')) !== '' || trim((string)($s['t'] ?? '')) !== '') {
            $items[] = ['h' => pm_card_clean((string)($s['h'] ?? '')), 't' => pm_card_clean((string)($s['t'] ?? ''))];
        }
    }
    if (count($items) < 2) {
        $items = [];
        foreach (preg_split('/\R/', (string)($p['caption'] ?? '')) ?: [] as $l) {
            if (preg_match('/^\s*(?:\d+[.)]|[-*•])\s+(.+)$/u', $l, $m)) {
                $items[] = ['h' => pm_card_clean($m[1]), 't' => ''];
            }
        }
    }
    if (count($items) < 2) {
        $items = [];
        foreach (preg_split('/[;]\s*/u', (string)($p['sub'] ?? '')) ?: [] as $part) {
            if (pm_card_clean($part) !== '') {
                $items[] = ['h' => pm_card_clean($part), 't' => ''];
            }
        }
    }
    return array_slice(array_filter($items, fn($i) => $i['h'] !== '' || $i['t'] !== ''), 0, $max);
}

/** Which layout a post gets. Deterministic: by format, then hook pattern, then pillar. */
function pm_card_layout_for(array $p): string
{
    if (($p['format'] ?? '') === 'carousel') {
        return 'cover';
    }
    $hook = (string)($p['hook_pattern'] ?? '');
    $photo = !empty($p['asset_id']);
    if (!empty($p['giveaway'])) {
        return 'offer';
    }
    if ($hook === 'myth') {
        return 'mythfact';
    }
    if ($hook === 'beforeafter') {
        return 'beforeafter';
    }
    if ($hook === 'faq') {
        return 'faq';
    }
    $class = function_exists('pm_sx_pillar_class') ? pm_sx_pillar_class((string)($p['pillar'] ?? '')) : 'tip';
    return match ($class) {
        'proof' => 'quote', 'offer', 'freecheck', 'hostsignup', 'giveaway' => 'offer',
        'spotlight', 'destination', 'behind' => $photo ? 'photo' : 'headline',
        'tip', 'hosttips', 'guesttips', 'practical' => $hook === 'signs' || $hook === 'howto' ? 'checklist' : 'headline',
        'story' => 'beforeafter',
        default => 'headline',
    };
}

/* ---------------- layouts: each draws into the body area [$top, $bottom] ---------------- */

function pm_card_l_headline(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $w = $c['W'] - 2 * $c['pad'];
    $sub = pm_card_clean((string)($p['sub'] ?? ''));
    $subBox = $sub !== '' ? (int)(($bottom - $top) * 0.28) : 0;
    [$spx, $slines] = $sub !== '' ? pm_card_fit($sub, $c['f']['sans'], (int)(40 * $c['s']), (int)(24 * $c['s']), $w, $subBox, 1.35) : [0, []];
    $subH = $slines ? (int)(count($slines) * $spx * 1.35) + (int)(34 * $c['s']) : 0;
    [$hpx, $hlines] = pm_card_fit(pm_card_headline($p), $c['f']['serifb'], (int)(124 * $c['s']), (int)(40 * $c['s']), $w, $bottom - $top - $subH - (int)(40 * $c['s']), 1.14);
    $blockH = (int)(count($hlines) * $hpx * 1.14) + $subH + (int)(30 * $c['s']);
    $y = pm_card_center($top, $bottom, $blockH);
    imagefilledrectangle($c['im'], $c['pad'], $y, $c['pad'] + (int)(110 * $c['s']), $y + (int)(8 * $c['s']), pm_card_fill($c, $c['accent']));
    $y += (int)(30 * $c['s']);
    $y = pm_card_draw($c, $hlines, $c['f']['serifb'], $hpx, $c['ink'], $c['pad'], $y, 1.14);
    if ($slines) {
        pm_card_draw($c, $slines, $c['f']['sans'], $spx, $c['muted'], $c['pad'], $y + (int)(34 * $c['s']), 1.35);
    }
}

function pm_card_l_checklist(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $items = pm_card_items($p);
    if (count($items) < 2) {
        pm_card_l_headline($c, $top, $bottom);
        return;
    }
    $w = $c['W'] - 2 * $c['pad'];
    [$tpx, $tlines] = pm_card_fit(pm_card_headline($p), $c['f']['serifb'], (int)(66 * $c['s']), (int)(34 * $c['s']), $w, (int)(($bottom - $top) * 0.3), 1.15);
    $titleH = (int)(count($tlines) * $tpx * 1.15) + (int)(26 * $c['s']);
    $avail = $bottom - $top - $titleH;
    $n = count($items);
    $badge = (int)(64 * $c['s']);
    $tw = $w - $badge - (int)(26 * $c['s']);
    $fit = null;
    for ($px = (int)(42 * $c['s']); $px >= (int)(22 * $c['s']); $px -= 2) {
        $tot = 0;
        $rows = [];
        foreach ($items as $it) {
            $main = pm_card_wrap($it['h'] !== '' ? $it['h'] : $it['t'], $c['f']['sansb'], $px, $tw);
            $det = $it['h'] !== '' && $it['t'] !== '' ? array_slice(pm_card_wrap($it['t'], $c['f']['sans'], $px * 0.8, $tw), 0, 2) : [];
            $hh = max($badge, (int)(count($main) * $px * 1.25 + count($det) * $px * 0.8 * 1.3)) + (int)(22 * $c['s']);
            $rows[] = [$main, $det, $hh];
            $tot += $hh;
        }
        if ($tot <= $avail) {
            $fit = [$px, $rows, $tot];
            break;
        }
    }
    if (!$fit) { // too much text: keep the headings only
        $px = (int)(22 * $c['s']);
        $rows = [];
        $tot = 0;
        foreach ($items as $it) {
            $main = array_slice(pm_card_wrap($it['h'] !== '' ? $it['h'] : $it['t'], $c['f']['sansb'], $px, $tw), 0, 2);
            $hh = max($badge, (int)(count($main) * $px * 1.25)) + (int)(22 * $c['s']);
            $rows[] = [$main, [], $hh];
            $tot += $hh;
        }
        $fit = [$px, $rows, $tot];
    }
    [$px, $rows, $tot] = $fit;
    $y = pm_card_center($top, $bottom, $titleH + $tot);
    $y = pm_card_draw($c, $tlines, $c['f']['serifb'], $tpx, $c['ink'], $c['pad'], $y, 1.15) + (int)(26 * $c['s']);
    foreach ($rows as $i => [$main, $det, $hh]) {
        $cx = $c['pad'] + (int)($badge / 2);
        imagefilledellipse($c['im'], $cx, $y + (int)($badge / 2), $badge, $badge, pm_card_fill($c, $c['accent']));
        $nb = (string)($i + 1);
        $nw = pm_card_tw($c['f']['sansb'], $badge * 0.5, $nb);
        pm_card_draw($c, [$nb], $c['f']['sansb'], $badge * 0.5, $c['on'], $cx - (int)($nw / 2), $y + (int)($badge * 0.22), 1.0);
        $bh = (int)(count($main) * $px * 1.25 + count($det) * $px * 0.8 * 1.3);
        $ty = pm_card_draw($c, $main, $c['f']['sansb'], $px, $c['ink'], $c['pad'] + $badge + (int)(26 * $c['s']), $y + max(0, (int)(($hh - (int)(22 * $c['s']) - $bh) / 2)), 1.25);
        if ($det) {
            pm_card_draw($c, $det, $c['f']['sans'], $px * 0.8, $c['muted'], $c['pad'] + $badge + (int)(26 * $c['s']), $ty, 1.3);
        }
        $y += $hh;
    }
}

/** A labelled panel: label chip on the top-left, then text. $dark = filled with $fill, text in $textRgb. */
function pm_card_panel(array &$c, int $x1, int $y1, int $x2, int $y2, string $label, string $text, array $fill, array $textRgb, string $font, int $maxPx, array $chip): void
{
    pm_card_rrect($c, $x1, $y1, $x2, $y2, (int)(28 * $c['s']), $fill);
    $in = (int)(34 * $c['s']);
    $px = 22 * $c['s'];
    $lab = mb_strtoupper($label);
    $cw = pm_card_spaced_w($c, $lab, $px);
    $ch = (int)($px * 1.2 + 20 * $c['s']);
    pm_card_rrect($c, $x1 + $in, $y1 + $in, $x1 + $in + $cw + (int)(32 * $c['s']), $y1 + $in + $ch, (int)($ch / 2), $chip);
    pm_card_spaced($c, $lab, $px, pm_card_on($chip), $x1 + $in + (int)(16 * $c['s']), $y1 + $in + (int)(10 * $c['s']));
    $ty = $y1 + $in + $ch + (int)(22 * $c['s']);
    $bw = $x2 - $x1 - 2 * $in;
    $bh = $y2 - $in - $ty;
    [$tpx, $lines] = pm_card_fit($text, $font, $maxPx, (int)(24 * $c['s']), $bw, $bh, 1.22);
    $ty += max(0, (int)(($bh - count($lines) * $tpx * 1.22) / 2));
    pm_card_draw($c, $lines, $font, $tpx, $textRgb, $x1 + $in, $ty, 1.22);
}

function pm_card_l_mythfact(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $sl = (array)($p['slides'] ?? []);
    $myth = preg_replace('/^\s*myth\s*[:\-]\s*/i', '', pm_card_headline($p));
    $fact = preg_replace('/^\s*fact\s*[:\-]\s*/i', '', pm_card_clean((string)($p['sub'] ?? '')) ?: pm_card_clean((string)($sl[1]['t'] ?? $sl[1]['h'] ?? '')));
    if ($fact === '') {
        pm_card_l_headline($c, $top, $bottom);
        return;
    }
    $gap = (int)(24 * $c['s']);
    $land = $c['W'] > $c['H'];
    if ($land) {
        $mid = (int)(($c['W'] - 2 * $c['pad'] - $gap) / 2);
        pm_card_panel($c, $c['pad'], $top, $c['pad'] + $mid, $bottom, 'Myth', $myth, [236, 233, 228], $c['ink'], $c['f']['serifb'], (int)(50 * $c['s']), [96, 101, 110]);
        pm_card_panel($c, $c['pad'] + $mid + $gap, $top, $c['W'] - $c['pad'], $bottom, 'Fact', $fact, $c['accent'], $c['on'], $c['f']['sansb'], (int)(44 * $c['s']), $c['on']);
        return;
    }
    $mid = (int)(($bottom - $top - $gap) * 0.42);
    pm_card_panel($c, $c['pad'], $top, $c['W'] - $c['pad'], $top + $mid, 'Myth', $myth, [236, 233, 228], $c['ink'], $c['f']['serifb'], (int)(58 * $c['s']), [96, 101, 110]);
    pm_card_panel($c, $c['pad'], $top + $mid + $gap, $c['W'] - $c['pad'], $bottom, 'Fact', $fact, $c['accent'], $c['on'], $c['f']['sansb'], (int)(50 * $c['s']), $c['on']);
}

function pm_card_l_beforeafter(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $sl = array_values((array)($p['slides'] ?? []));
    $before = pm_card_clean((string)($sl[0]['t'] ?? '')) ?: pm_card_headline($p);
    $after = pm_card_clean((string)($sl[1]['t'] ?? '')) ?: pm_card_clean((string)($p['sub'] ?? ''));
    $before = preg_replace('/^\s*before\s*[:\-]\s*/i', '', $before);
    $after = preg_replace('/^\s*after\s*[:\-]\s*/i', '', $after);
    if ($after === '') {
        pm_card_l_headline($c, $top, $bottom);
        return;
    }
    $gap = (int)(24 * $c['s']);
    if ($c['W'] > $c['H']) {
        $mid = (int)(($c['W'] - 2 * $c['pad'] - $gap) / 2);
        pm_card_panel($c, $c['pad'], $top, $c['pad'] + $mid, $bottom, 'Before', $before, [236, 233, 228], $c['ink'], $c['f']['serifb'], (int)(46 * $c['s']), [96, 101, 110]);
        pm_card_panel($c, $c['pad'] + $mid + $gap, $top, $c['W'] - $c['pad'], $bottom, 'After', $after, $c['accent'], $c['on'], $c['f']['sansb'], (int)(44 * $c['s']), $c['on']);
        return;
    }
    $mid = (int)(($bottom - $top - $gap) / 2);
    pm_card_panel($c, $c['pad'], $top, $c['W'] - $c['pad'], $top + $mid, 'Before', $before, [236, 233, 228], $c['ink'], $c['f']['serifb'], (int)(54 * $c['s']), [96, 101, 110]);
    pm_card_panel($c, $c['pad'], $top + $mid + $gap, $c['W'] - $c['pad'], $bottom, 'After', $after, $c['accent'], $c['on'], $c['f']['sansb'], (int)(50 * $c['s']), $c['on']);
}

function pm_card_l_quote(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $text = '';
    $who = '';
    if (!empty($p['proof_id']) && function_exists('pm_social_proof_find') && ($r = pm_social_proof_find((string)$c['brand'], (string)$p['proof_id']))) {
        $text = (string)$r['text'];
        $who = !empty($r['consent']) ? trim((string)($r['client_name'] ?? '')) : '';
    }
    $text = pm_card_clean($text !== '' ? $text : pm_card_headline($p));
    $w = $c['W'] - 2 * $c['pad'];
    $whoH = $who !== '' ? (int)(60 * $c['s']) : 0;
    $qh = (int)(150 * $c['s']);
    [$px, $lines] = pm_card_fit($text, $c['f']['serif'], (int)(64 * $c['s']), (int)(26 * $c['s']), $w, $bottom - $top - $qh - $whoH - (int)(30 * $c['s']), 1.28);
    $blockH = $qh + (int)(count($lines) * $px * 1.28) + $whoH + (int)(30 * $c['s']);
    $y = pm_card_center($top, $bottom, $blockH);
    pm_card_draw($c, ['“'], $c['f']['serifb'], 190 * $c['s'], $c['accentT'], $c['pad'], $y - (int)(20 * $c['s']), 1.0);
    $y = pm_card_draw($c, $lines, $c['f']['serif'], $px, $c['ink'], $c['pad'], $y + $qh, 1.28);
    if ($who !== '') {
        imagefilledrectangle($c['im'], $c['pad'], $y + (int)(14 * $c['s']), $c['pad'] + (int)(70 * $c['s']), $y + (int)(18 * $c['s']), pm_card_fill($c, $c['accent']));
        pm_card_draw($c, [$who], $c['f']['sansb'], 32 * $c['s'], $c['ink'], $c['pad'], $y + (int)(30 * $c['s']), 1.2);
    }
}

function pm_card_l_faq(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $sl = array_values((array)($p['slides'] ?? []));
    $q = pm_card_headline($p);
    $a = pm_card_clean((string)($p['sub'] ?? '')) ?: pm_card_clean((string)($sl[0]['t'] ?? $sl[1]['t'] ?? ''));
    if ($a === '') {
        pm_card_l_headline($c, $top, $bottom);
        return;
    }
    $badge = (int)(70 * $c['s']);
    $x = $c['pad'] + $badge + (int)(28 * $c['s']);
    $w = $c['W'] - $c['pad'] - $x;
    $half = (int)(($bottom - $top) * 0.46);
    [$qpx, $qlines] = pm_card_fit($q, $c['f']['serifb'], (int)(70 * $c['s']), (int)(30 * $c['s']), $w, $half, 1.18);
    [$apx, $alines] = pm_card_fit($a, $c['f']['sans'], (int)(44 * $c['s']), (int)(24 * $c['s']), $w, $bottom - $top - (int)(count($qlines) * $qpx * 1.18) - (int)(80 * $c['s']), 1.33);
    $qh = (int)(count($qlines) * $qpx * 1.18);
    $ah = (int)(count($alines) * $apx * 1.33);
    $y = pm_card_center($top, $bottom, $qh + $ah + (int)(60 * $c['s']));
    foreach ([['Q', $y, $c['accent'], $c['on']], ['A', $y + $qh + (int)(60 * $c['s']), [236, 233, 228], $c['ink']]] as [$ch, $by, $fill, $tc]) {
        imagefilledellipse($c['im'], $c['pad'] + (int)($badge / 2), $by + (int)($badge / 2), $badge, $badge, pm_card_fill($c, $fill));
        $cw = pm_card_tw($c['f']['sansb'], $badge * 0.5, $ch);
        pm_card_draw($c, [$ch], $c['f']['sansb'], $badge * 0.5, $tc, $c['pad'] + (int)(($badge - $cw) / 2), $by + (int)($badge * 0.22), 1.0);
    }
    pm_card_draw($c, $qlines, $c['f']['serifb'], $qpx, $c['ink'], $x, $y, 1.18);
    pm_card_draw($c, $alines, $c['f']['sans'], $apx, $c['muted'], $x, $y + $qh + (int)(60 * $c['s']), 1.33);
}

function pm_card_l_offer(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $pad = $c['pad'];
    pm_card_rrect($c, $pad, $top, $c['W'] - $pad, $bottom, (int)(34 * $c['s']), $c['accent']);
    $in = (int)(54 * $c['s']);
    $w = $c['W'] - 2 * $pad - 2 * $in;
    $until = !empty($p['expires']) ? 'Until ' . date('j M Y', strtotime((string)$p['expires'])) : '';
    $subT = pm_card_clean((string)($p['sub'] ?? ''));
    $subBox = $subT !== '' ? (int)(($bottom - $top) * 0.3) : 0;
    [$spx, $slines] = $subT !== '' ? pm_card_fit($subT, $c['f']['sans'], (int)(38 * $c['s']), (int)(22 * $c['s']), $w, $subBox, 1.3) : [0, []];
    $subH = $slines ? (int)(count($slines) * $spx * 1.3) + (int)(28 * $c['s']) : 0;
    $untilH = $until !== '' ? (int)(80 * $c['s']) : 0;
    [$hpx, $hlines] = pm_card_fit(pm_card_headline($p), $c['f']['serifb'], (int)(100 * $c['s']), (int)(34 * $c['s']), $w, $bottom - $top - 2 * $in - $subH - $untilH, 1.14);
    $blockH = (int)(count($hlines) * $hpx * 1.14) + $subH + $untilH;
    $y = pm_card_center($top + $in, $bottom - $in, $blockH);
    $y = pm_card_draw($c, $hlines, $c['f']['serifb'], $hpx, $c['on'], $pad + $in, $y, 1.14);
    if ($slines) {
        $y = pm_card_draw($c, $slines, $c['f']['sans'], $spx, $c['on'], $pad + $in, $y + (int)(28 * $c['s']), 1.3);
    }
    if ($until !== '') {
        $upx = 28 * $c['s'];
        $uw = pm_card_tw($c['f']['sansb'], $upx, $until);
        $y += (int)(26 * $c['s']);
        pm_card_rrect($c, $pad + $in, $y, $pad + $in + $uw + (int)(40 * $c['s']), $y + (int)($upx * 1.2 + 24 * $c['s']), (int)(($upx * 1.2 + 24 * $c['s']) / 2), $c['on']);
        pm_card_draw($c, [$until], $c['f']['sansb'], $upx, $c['on'] === [255, 255, 255] ? $c['accentT'] : $c['ink'], $pad + $in + (int)(20 * $c['s']), $y + (int)(12 * $c['s']), 1.2);
    }
}

/** Page dots + "Swipe" cue for carousel cards. */
function pm_card_swipe(array &$c, int $y, int $n, int $total): void
{
    $r = (int)(8 * $c['s']);
    $x = $c['pad'];
    for ($i = 1; $i <= $total; $i++) {
        $on = $i === $n;
        imagefilledellipse($c['im'], $x + $r, $y + $r, $r * 2, $r * 2, pm_card_fill($c, $on ? $c['accentT'] : [205, 202, 196]));
        $x += $r * 2 + (int)(10 * $c['s']);
    }
    if ($n < $total) {
        $t = 'Swipe  →';
        $px = 28 * $c['s'];
        $w = pm_card_tw($c['f']['sansb'], $px, $t);
        pm_card_draw($c, [$t], $c['f']['sansb'], $px, $c['accentT'], $c['W'] - $c['pad'] - $w, $y - (int)(4 * $c['s']), 1.2);
    }
}

function pm_card_l_cover(array &$c, int $top, int $bottom): void
{
    $total = max(1, count((array)($c['p']['slides'] ?? [])) + 1);
    $cue = (int)(40 * $c['s']);
    pm_card_l_headline($c, $top, $bottom - $cue - (int)(20 * $c['s']));
    pm_card_swipe($c, $bottom - $cue, 1, $total);
}

function pm_card_l_slide(array &$c, int $top, int $bottom): void
{
    $n = (int)($c['slide'] ?? 1);
    $sl = array_values((array)($c['p']['slides'] ?? []));
    $s = $sl[$n - 1] ?? [];
    $total = count($sl) + 1;
    $w = $c['W'] - 2 * $c['pad'];
    $cue = (int)(40 * $c['s']);
    $bottom -= $cue + (int)(20 * $c['s']);
    $h = pm_card_clean((string)($s['h'] ?? ''));
    $t = pm_card_clean((string)($s['t'] ?? ''));
    $tBox = $t !== '' ? (int)(($bottom - $top) * 0.55) : 0;
    [$tpx, $tlines] = $t !== '' ? pm_card_fit($t, $c['f']['sans'], (int)(52 * $c['s']), (int)(24 * $c['s']), $w, $tBox, 1.33) : [0, []];
    $tH = $tlines ? (int)(count($tlines) * $tpx * 1.33) + (int)(34 * $c['s']) : 0;
    [$hpx, $hlines] = pm_card_fit($h !== '' ? $h : $t, $c['f']['serifb'], (int)(96 * $c['s']), (int)(34 * $c['s']), $w, $bottom - $top - $tH, 1.14);
    if ($h === '') {
        $tlines = [];
        $tH = 0;
    }
    $blockH = (int)(count($hlines) * $hpx * 1.14) + $tH;
    $y = pm_card_center($top, $bottom, $blockH);
    $y = pm_card_draw($c, $hlines, $c['f']['serifb'], $hpx, $c['ink'], $c['pad'], $y, 1.14);
    if ($tlines) {
        pm_card_draw($c, $tlines, $c['f']['sans'], $tpx, $c['muted'], $c['pad'], $y + (int)(34 * $c['s']), 1.33);
    }
    pm_card_swipe($c, $bottom + (int)(20 * $c['s']), $n + 1, $total);
}

/** Photo card: the owner's picture fills the card, a dark gradient carries the words. Returns false when the picture is missing. */
function pm_card_l_photo_bg(array &$c): bool
{
    $a = !empty($c['p']['asset_id']) && function_exists('pm_social_asset_find') ? pm_social_asset_find((string)$c['p']['asset_id']) : null;
    $path = $a ? pm_social_asset_path($a) : '';
    $src = $path !== '' ? @imagecreatefromjpeg($path) : false;
    if (!$src) {
        return false;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $k = max($c['W'] / $sw, $c['H'] / $sh);
    $cw = (int)ceil($c['W'] / $k);
    $ch = (int)ceil($c['H'] / $k);
    imagecopyresampled($c['im'], $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $c['W'], $c['H'], $cw, $ch);
    imagedestroy($src);
    $c['credit'] = trim((string)($a['credit'] ?? ''));
    return true;
}

function pm_card_l_photo(array &$c, int $top, int $bottom): void
{
    // the picture was drawn first (see pm_card_draw_layout); here: gradient, words, credit
    $im = $c['im'];
    $gTop = $top + (int)(($bottom - $top) * 0.28);
    for ($y = $gTop; $y <= $bottom; $y++) {
        $t = ($y - $gTop) / max(1, $bottom - $gTop);
        $alpha = (int)round(127 - 127 * 0.82 * min(1, $t * 1.15));
        imagefilledrectangle($im, 0, $y, $c['W'], $y, imagecolorallocatealpha($im, 0, 0, 0, max(0, min(127, $alpha))));
    }
    $w = $c['W'] - 2 * $c['pad'];
    $credit = !empty($c['credit']) ? 'Photo: ' . pm_card_clean($c['credit']) : '';
    $cH = $credit !== '' ? (int)(40 * $c['s']) : 0;
    $sub = pm_card_clean((string)($c['p']['sub'] ?? ''));
    [$spx, $slines] = $sub !== '' ? pm_card_fit($sub, $c['f']['sans'], (int)(36 * $c['s']), (int)(22 * $c['s']), $w, (int)(($bottom - $top) * 0.16), 1.3) : [0, []];
    $subH = $slines ? (int)(count($slines) * $spx * 1.3) + (int)(20 * $c['s']) : 0;
    [$hpx, $hlines] = pm_card_fit(pm_card_headline($c['p']), $c['f']['serifb'], (int)(96 * $c['s']), (int)(34 * $c['s']), $w, (int)(($bottom - $top) * 0.42) - $subH, 1.14);
    $y = $bottom - $cH - $subH - (int)(count($hlines) * $hpx * 1.14) - (int)(16 * $c['s']);
    $y = pm_card_draw($c, $hlines, $c['f']['serifb'], $hpx, [255, 255, 255], $c['pad'], $y, 1.14);
    if ($slines) {
        $y = pm_card_draw($c, $slines, $c['f']['sans'], $spx, [240, 240, 240], $c['pad'], $y + (int)(20 * $c['s']), 1.3);
    }
    if ($credit !== '') {
        pm_card_draw($c, [$credit], $c['f']['sans'], 22 * $c['s'], [225, 225, 225], $c['pad'], $bottom - $cH + (int)(8 * $c['s']), 1.2);
    }
}

/** Draws the whole card for a layout. */
function pm_card_draw_layout(array &$c, string $layout): void
{
    if ($layout === 'photo' && !pm_card_l_photo_bg($c)) {
        $layout = 'headline';
    }
    $strip = $c['bottom'] - (int)round(max(96, 140 * $c['s']));
    if ($layout === 'photo') {
        $top = $c['top'];
        // small label on the picture, then the gradient and words
        $y = pm_card_eyebrow($c);
        pm_card_l_photo($c, $top, $strip);
    } else {
        $y = pm_card_eyebrow($c, null, $layout === 'slide' ? ((int)($c['slide'] ?? 1) + 1) . '/' . (count((array)($c['p']['slides'] ?? [])) + 1) : '');
        $gap = (int)round(34 * $c['s']);
        $fn = 'pm_card_l_' . $layout;
        $fn($c, $y, $strip - $gap);
    }
    pm_card_strip($c);
}

/* ---------------- public ---------------- */

function pm_card_safe_id(array $p): string
{
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($p['id'] ?? ''));
    return $id !== '' ? $id : substr(sha1(json_encode([$p['headline'] ?? '', $p['caption'] ?? ''])), 0, 16);
}

function pm_card_dir(string $sub = ''): string
{
    $d = PM_DATA . '/social' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

function pm_card_save(array $c, string $file): string
{
    $ok = imagepng($c['im'], $file, 6);
    pm_card_end($c);
    return $ok && is_file($file) && filesize($file) > 0 ? $file : '';
}

/** One card. null = not handled (no GD or no fonts), '' = failed, else the PNG path. */
function pm_card_render(array $p, string $size = 'sq', ?string $layout = null, ?int $slide = null, ?string $file = null): ?string
{
    if (!pm_card_gd_ok()) {
        return null;
    }
    if (!isset(pm_card_sizes()[$size])) {
        $size = 'sq';
    }
    $layout = $layout ?? (string)($p['layout'] ?? '');
    if (!in_array($layout, PM_CARD_LAYOUTS, true)) {
        $layout = pm_card_layout_for($p);
    }
    if (($p['format'] ?? '') === 'carousel' && $slide === null && $layout !== 'cover') {
        $layout = 'cover'; // the one picture of a carousel is its cover
    }
    $c = null;
    try {
        $c = pm_card_begin($p, $size);
        if (!$c) {
            return null;
        }
        if ($slide !== null) {
            $c['slide'] = $slide;
        }
        pm_card_draw_layout($c, $layout);
        $file ??= pm_card_dir() . '/' . pm_card_safe_id($p) . '_' . $size . '.png';
        $out = pm_card_save($c, $file);
        if ($out !== '' && $size === 'sq' && $slide === null) { // older screens look for <id>.png
            @copy($out, pm_card_dir() . '/' . pm_card_safe_id($p) . '.png');
        }
        return $out;
    } catch (Throwable) {
        if (is_array($c) && isset($c['im'])) {
            pm_card_end($c);
        }
        return '';
    }
}

/** Cover + one card per slide (max 7 files). Falls back to the single card when the post has no slides. */
function pm_card_slides(array $p, string $size = '4x5'): array
{
    if (!pm_card_gd_ok()) {
        return [];
    }
    $slides = array_slice(array_values((array)($p['slides'] ?? [])), 0, 6);
    $id = pm_card_safe_id($p);
    $out = [];
    $cover = pm_card_render($p, $size, 'cover', null, pm_card_dir() . "/{$id}_{$size}_0.png");
    if ($cover) {
        $out[] = $cover;
    }
    foreach ($slides as $i => $_) {
        $f = pm_card_render($p, $size, 'slide', $i + 1, pm_card_dir() . "/{$id}_{$size}_" . ($i + 1) . '.png');
        if ($f) {
            $out[] = $f;
        }
    }
    return $out;
}

/* ---------------- special cards ---------------- */

/** featured | badge | shot | storyboard. Returns the PNG path, or '' when it could not be drawn. */
function pm_card_special(string $brand, string $kind, array $spec, string $size = 'sq'): string
{
    if (!pm_card_gd_ok() || !in_array($kind, ['featured', 'badge', 'shot', 'storyboard'], true)) {
        return '';
    }
    $brand = pm_brand_norm($brand);
    if ($kind === 'storyboard') {
        $size = 'story';
    } elseif (!isset(pm_card_sizes()[$size])) {
        $size = 'sq';
    }
    $file = pm_card_dir('special') . '/' . $kind . '_' . substr(sha1($brand . json_encode($spec) . $size), 0, 14) . '_' . $size . '.png';
    $c = null;
    try {
        $post = ['brand' => $brand, 'pillar' => (string)($spec['pillar'] ?? ($brand === 'travel' ? 'Stay spotlight' : 'Proof')), 'headline' => (string)($spec['headline'] ?? ''), 'sub' => '', 'id' => 'special'];
        if ($kind === 'badge') {
            return pm_card_badge($brand, $file);
        }
        $c = pm_card_begin($post, $size);
        if (!$c) {
            return '';
        }
        $c['label'] = $kind === 'featured' ? ($brand === 'travel' ? 'FEATURED STAY' : 'FEATURED CLIENT') : ($kind === 'shot' ? 'SHOT' : 'FILM PLAN');
        $y = pm_card_eyebrow($c, $c['label']);
        $strip = $c['bottom'] - (int)round(max(96, 140 * $c['s'])) - (int)(34 * $c['s']);
        $w = $c['W'] - 2 * $c['pad'];
        if ($kind === 'featured') {
            $host = pm_card_clean((string)($spec['host'] ?? ''));
            $head = pm_card_clean((string)($spec['headline'] ?? ''));
            $lead = 'Featured on ' . $c['name'];
            [$lpx, $ll] = pm_card_fit($lead, $c['f']['sansb'], (int)(40 * $c['s']), (int)(22 * $c['s']), $w, (int)(120 * $c['s']), 1.2);
            [$hpx, $hl] = pm_card_fit($host, $c['f']['serifb'], (int)(118 * $c['s']), (int)(36 * $c['s']), $w, (int)(($strip - $y) * 0.5), 1.12);
            [$tpx, $tl] = $head !== '' ? pm_card_fit($head, $c['f']['sans'], (int)(44 * $c['s']), (int)(22 * $c['s']), $w, (int)(($strip - $y) * 0.3), 1.3) : [0, []];
            $bh = (int)(count($ll) * $lpx * 1.2) + (int)(count($hl) * $hpx * 1.12) + (int)(count($tl) * $tpx * 1.3) + (int)(60 * $c['s']);
            $yy = pm_card_center($y, $strip, $bh);
            $yy = pm_card_draw($c, $ll, $c['f']['sansb'], $lpx, $c['accentT'], $c['pad'], $yy, 1.2) + (int)(24 * $c['s']);
            $yy = pm_card_draw($c, $hl, $c['f']['serifb'], $hpx, $c['ink'], $c['pad'], $yy, 1.12) + (int)(24 * $c['s']);
            pm_card_draw($c, $tl, $c['f']['sans'], $tpx, $c['muted'], $c['pad'], $yy, 1.3);
        } elseif ($kind === 'shot') {
            $n = (int)($spec['n'] ?? 1);
            $tot = (int)($spec['total'] ?? 1);
            $secs = (int)($spec['secs'] ?? 0);
            $rows = [['Show', (string)($spec['show'] ?? '')], ['Say', (string)($spec['say'] ?? '')], ['On screen', (string)($spec['onscreen'] ?? '')]];
            $rows = array_values(array_filter($rows, fn($r) => pm_card_clean($r[1]) !== ''));
            $title = "Shot $n of $tot" . ($secs > 0 ? " · $secs sec" : '');
            [$tpx, $tl] = pm_card_fit($title, $c['f']['serifb'], (int)(86 * $c['s']), (int)(36 * $c['s']), $w, (int)(130 * $c['s']), 1.1);
            $yy = pm_card_draw($c, $tl, $c['f']['serifb'], $tpx, $c['ink'], $c['pad'], $y, 1.1) + (int)(28 * $c['s']);
            $each = $rows ? (int)(($strip - $yy) / count($rows)) : 0;
            foreach ($rows as [$lab, $txt]) {
                pm_card_spaced($c, strtoupper($lab), 22 * $c['s'], $c['accentT'], $c['pad'], $yy);
                [$px, $ls] = pm_card_fit($txt, $c['f']['sans'], (int)(46 * $c['s']), (int)(22 * $c['s']), $w, $each - (int)(60 * $c['s']), 1.3);
                pm_card_draw($c, $ls, $c['f']['sans'], $px, $c['ink'], $c['pad'], $yy + (int)(38 * $c['s']), 1.3);
                $yy += $each;
            }
        } else { // storyboard: a 9:16 checklist of shots
            $shots = array_slice(array_values((array)($spec['shots'] ?? [])), 0, 8);
            $hook = pm_card_clean((string)($spec['hook_2s'] ?? ''));
            [$hpx, $hl] = $hook !== '' ? pm_card_fit('First 2 seconds: ' . $hook, $c['f']['serifb'], (int)(52 * $c['s']), (int)(26 * $c['s']), $w, (int)(260 * $c['s']), 1.2) : [0, []];
            $yy = pm_card_draw($c, $hl, $c['f']['serifb'], $hpx, $c['ink'], $c['pad'], $y, 1.2) + (int)(24 * $c['s']);
            $n = max(1, count($shots));
            $each = (int)(($strip - $yy) / $n);
            foreach ($shots as $i => $sh) {
                $badge = min((int)(56 * $c['s']), $each - 10);
                imagefilledellipse($c['im'], $c['pad'] + (int)($badge / 2), $yy + (int)($badge / 2), $badge, $badge, pm_card_fill($c, $c['accent']));
                $nw = pm_card_tw($c['f']['sansb'], $badge * 0.5, (string)($i + 1));
                pm_card_draw($c, [(string)($i + 1)], $c['f']['sansb'], $badge * 0.5, $c['on'], $c['pad'] + (int)(($badge - $nw) / 2), $yy + (int)($badge * 0.2), 1.0);
                $txt = trim(($sh['show'] ?? '') . (isset($sh['secs']) && (int)$sh['secs'] > 0 ? ' (' . (int)$sh['secs'] . ' sec)' : ''));
                [$px, $ls] = pm_card_fit($txt, $c['f']['sans'], (int)(36 * $c['s']), (int)(20 * $c['s']), $w - $badge - (int)(24 * $c['s']), $each - 8, 1.25);
                pm_card_draw($c, $ls, $c['f']['sans'], $px, $c['ink'], $c['pad'] + $badge + (int)(24 * $c['s']), $yy + max(0, (int)(($badge - count($ls) * $px * 1.25) / 2)), 1.25);
                $yy += $each;
            }
        }
        pm_card_strip($c);
        return pm_card_save($c, $file);
    } catch (Throwable) {
        if (is_array($c) && isset($c['im'])) {
            pm_card_end($c);
        }
        return '';
    }
}

/** "Listed with Travel Malawi" / "Powered by ProManaged IT": a square badge on a transparent background. */
function pm_card_badge(string $brand, string $file): string
{
    $f = pm_card_fonts();
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $W = 1080;
        $im = imagecreatetruecolor($W, $W);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        $accent = pm_card_rgb((string)($s['accent_color'] ?? ''));
        $on = pm_card_on($accent);
        $c = ['im' => $im, 'f' => $f, 's' => 1.0];
        pm_card_rrect($c, 40, 40, $W - 40, $W - 40, 120, $accent);
        $head = $brand === 'travel' ? 'Listed with' : 'Powered by';
        $name = pm_brand_name($brand);
        $hp = 60;
        $hw = pm_card_tw($f['sans'], $hp, $head);
        pm_card_draw($c, [$head], $f['sans'], $hp, $on, (int)(($W - $hw) / 2), 330, 1.2);
        [$npx, $nl] = pm_card_fit($name, $f['serifb'], 150, 60, $W - 240, 340, 1.1);
        $y = pm_card_draw(['im' => $im] + $c, $nl, $f['serifb'], $npx, $on, 120, 430, 1.1, 'center', $W - 240);
        $logo = function_exists('pm_logo_path') ? pm_logo_path() : '';
        if ($logo !== '' && ($li = @imagecreatefrompng($logo))) {
            $lh = 120;
            $lw = min(360, (int)round(imagesx($li) * $lh / max(1, imagesy($li))));
            $lh = (int)round(imagesy($li) * $lw / max(1, imagesx($li)));
            pm_card_rrect($c, (int)(($W - $lw) / 2) - 16, $W - 260 - 10, (int)(($W + $lw) / 2) + 16, $W - 260 + $lh + 10, 16, [255, 255, 255]);
            imagecopyresampled($im, $li, (int)(($W - $lw) / 2), $W - 260, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
            imagedestroy($li);
        }
        $ok = imagepng($im, $file, 6);
        imagedestroy($im);
        return $ok && is_file($file) ? $file : '';
    } finally {
        pm_brand_set($prev);
    }
}
