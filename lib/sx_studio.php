<?php
/**
 * Cover studio: profile pictures and covers DESIGNED BY CODE, not generated as images. Eleven cover styles (aurora, dusk over the lake, waves, rings, blueprint, facets, diamonds, spotlight,
 * ribbons, minimal, photo) and five profile styles (logo on a plate, monogram, glow ring, bold tile, aurora) are drawn with GD from a small design spec: a style, four colours, a texture, a seed
 * and the words (a quiet eyebrow, a headline, a line, and a whisper strip of contact details). The same spec draws every size every platform wants, with the words kept inside each platform's
 * safe area. "Surprise me" re-rolls style, colours and seed in code; "New idea from AI" asks the AI for a spec (style, colours, words) in ONE small call, and code draws it; the AI's words are kept
 * only if they pass the facts-only guard (no new numbers, links, boasts). Nothing is uploaded anywhere until the owner presses "Use" or "Set on Facebook".
 * Data: data/cover_studio.json {brand: {design: spec|null, drafts: {id: spec}, history: [{id, at, how}]}}; pictures: data/brandkit/<brand>/studio/ (cache) and the usual kit files.
 */

const PM_SD_VERSION = 4; // bump when the drawing changes: cached pictures are drawn again
/** The first eight look made by hand (paper, cut shapes, flecks, weave, a flat landscape poster); the "digital" ones below them are glowing or technical and are used on purpose, not by default. */
const PM_SD_STYLES = ['paper' => 'Paper and ink', 'cutout' => 'Cut paper', 'terrazzo' => 'Terrazzo', 'weave' => 'Weave', 'dusk' => 'Dusk over the lake', 'waves' => 'Lake waves', 'minimal' => 'Minimal', 'photo' => 'Your photo',
    'aurora' => 'Soft glow', 'geometry' => 'Rings', 'blueprint' => 'Blueprint', 'lowpoly' => 'Facets', 'diamonds' => 'Diamonds', 'spotlight' => 'Spotlight', 'ribbons' => 'Ribbons'];
const PM_SD_DIGITAL = ['aurora', 'geometry', 'blueprint', 'lowpoly', 'diamonds', 'spotlight', 'ribbons'];
const PM_SD_PSTYLES = ['plate' => 'Logo on a plate', 'monogram' => 'Monogram', 'ring' => 'Fine ring', 'tile' => 'Bold tile', 'shapes' => 'Cut shapes'];
const PM_SD_TEXTURES = ['paper' => 'Paper grain', 'grain' => 'Fine grain', 'none' => 'Clean', 'dots' => 'Dots', 'lines' => 'Diagonal lines'];

/* ---------------- colour ---------------- */

function pm_sd_hex(string $h, array $fallback = [23, 55, 94]): array
{
    $h = ltrim(trim($h), '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    return preg_match('/^[0-9a-f]{6}$/i', $h) ? [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))] : $fallback;
}

function pm_sd_tohex(array $c): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, (int)round($c[0]))), max(0, min(255, (int)round($c[1]))), max(0, min(255, (int)round($c[2]))));
}

function pm_sd_mix(array $a, array $b, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
}

function pm_sd_lum(array $c): float
{
    $f = fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
}

function pm_sd_contrast(array $a, array $b): float
{
    $x = pm_sd_lum($a) + 0.05;
    $y = pm_sd_lum($b) + 0.05;
    return max($x, $y) / min($x, $y);
}

/** [hue 0..360, saturation 0..1, lightness 0..1] */
function pm_sd_hsl(array $c): array
{
    [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    $mx = max($r, $g, $b);
    $mn = min($r, $g, $b);
    $l = ($mx + $mn) / 2;
    if ($mx === $mn) {
        return [0.0, 0.0, $l];
    }
    $d = $mx - $mn;
    $s = $l > 0.5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
    $h = match (true) {
        $mx === $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
        $mx === $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    return [$h * 60, $s, $l];
}

function pm_sd_from_hsl(float $h, float $s, float $l): array
{
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    $s = max(0.0, min(1.0, $s));
    $l = max(0.0, min(1.0, $l));
    if ($s == 0) {
        return [$l * 255, $l * 255, $l * 255];
    }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function ($t) use ($p, $q) {
        $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
        return 255 * ($t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p)));
    };
    return [$f($h + 1 / 3), $f($h), $f($h - 1 / 3)];
}

function pm_sd_shade(array $c, float $dl): array
{
    [$h, $s, $l] = pm_sd_hsl($c);
    return pm_sd_from_hsl($h, $s, $l + $dl);
}

function pm_sd_rot(array $c, float $deg, float $ds = 0.0, float $dl = 0.0): array
{
    [$h, $s, $l] = pm_sd_hsl($c);
    return pm_sd_from_hsl($h + $deg, $s + $ds, $l + $dl);
}

/**
 * Makes a palette look natural: indigo and violet drift to a muted ink blue, magenta to clay, anything neon is calmed, backgrounds lose most of their saturation.
 * Used on what the AI chooses (never on colours the owner picked by hand). Returns [spec, changed].
 */
function pm_sd_humanize(array $spec): array
{
    $changed = false;
    foreach (['bg1' => 0.4, 'bg2' => 0.5, 'accent' => 0.62] as $k => $maxS) {
        $c = pm_sd_hex((string)$spec[$k]);
        [$h, $s, $l] = pm_sd_hsl($c);
        $n = $s;
        if ($h >= 235 && $h < 285 && $s > 0.3) {
            $h = 212; // indigo and violet read as generated: a muted ink blue instead
            $n = min($n, 0.34);
        } elseif ($h >= 285 && $h <= 335 && $s > 0.3) {
            $h = 14; // purple and magenta become clay
            $n = min($n, 0.4);
        }
        $n = min($n, $k === 'accent' && $h >= 8 && $h <= 62 ? 0.8 : $maxS); // ochre and terracotta are allowed to be rich
        if (abs($n - $s) > 0.01 || abs($h - pm_sd_hsl($c)[0]) > 1) {
            $spec[$k] = pm_sd_tohex(pm_sd_from_hsl($h, $n, $l));
            $changed = true;
        }
    }
    return [$spec, $changed];
}

/** Ink (the writing colour) that reads on the average of the two background colours. */
function pm_sd_ink_for(array $bg1, array $bg2, array $prefer): array
{
    $avg = pm_sd_mix($bg1, $bg2, 0.5);
    if (pm_sd_contrast($prefer, $avg) >= 4.5 && pm_sd_contrast($prefer, $bg1) >= 3.5) {
        return $prefer;
    }
    $w = [255, 255, 255];
    $k = [20, 24, 30];
    return pm_sd_contrast($w, $avg) >= pm_sd_contrast($k, $avg) ? $w : $k;
}

/** Named palettes: key => [label, bg1, bg2, accent, ink]. Three come from the business's own colour, the rest are curated. */
function pm_sd_palettes(string $accentHex): array
{
    $a = pm_sd_hex($accentHex);
    [$h, $s, $l] = pm_sd_hsl($a);
    $s = max(0.16, min($s, 0.46)); // the business's colour, softened: a loud brand colour on a cover looks like a template
    $warm = $h < 70 || $h > 330;
    $ochre = $warm ? '#e6d6b0' : '#d9a566'; // one warm accent that sits well with any brand colour
    $hx = fn(array $c) => pm_sd_tohex($c);
    return [
        'brand' => ['Your colour', $hx(pm_sd_from_hsl($h, $s * 0.75, 0.12)), $hx(pm_sd_from_hsl($h, $s, max(0.27, min(0.38, $l)))), $ochre, '#f4efe6'],
        'brand-light' => ['Your colour on bone', '#f3ede2', '#e6dcc8', $hx(pm_sd_from_hsl($h, min(0.5, $s + 0.05), 0.34)), $hx(pm_sd_from_hsl($h, 0.4, 0.12))],
        'brand-clay' => ['Your colour and clay', $hx(pm_sd_from_hsl($h, $s * 0.7, 0.11)), $hx(pm_sd_mix(pm_sd_from_hsl($h, $s, 0.3), [138, 75, 50], 0.5)), '#e9b872', '#f4efe6'],
        'clay' => ['Clay and bone', '#2b1d17', '#8a4b32', '#e9b872', '#f4eadb'],
        'sage' => ['Sage and cream', '#1f2a22', '#5d7360', '#e6d8b8', '#f3efe3'],
        'ink' => ['Ink and sand', '#16202e', '#2f4258', '#d9a566', '#f2ece0'],
        'olive' => ['Olive grove', '#26261a', '#6b6a3a', '#d8c47f', '#f2eee0'],
        'rosewood' => ['Rosewood', '#2a1618', '#7a3b3f', '#e3b9a0', '#f5ece4'],
        'lake' => ['Lake shore', '#17272b', '#4b6f73', '#e8d8b0', '#f3efe4'],
        'mustard' => ['Mustard and charcoal', '#2a2a28', '#4a4a45', '#d9a521', '#f4efe4'],
        'bone' => ['Bone and terracotta', '#f3ede2', '#e6dcc8', '#b4532a', '#2a211b'],
        'oat' => ['Oat and forest', '#f1ebdd', '#e2d8c0', '#3f5b46', '#1f2a22'],
        'blush' => ['Blush and brown', '#f4e6df', '#e8cfc3', '#8a4b3d', '#2d1c18'],
    ];
}

/* ---------------- drawing helpers ---------------- */

/** A repeatable random stream from a seed: each call gives 0..1. */
function pm_sd_rng(int $seed): Closure
{
    $s = ($seed & 0x7FFFFFFF) ?: 1;
    return function () use (&$s) {
        $s ^= ($s << 13) & 0xFFFFFFFF;
        $s ^= $s >> 17;
        $s ^= ($s << 5) & 0xFFFFFFFF;
        $s &= 0xFFFFFFFF;
        return $s / 4294967296;
    };
}

/** A smooth colour field: $f(nx, ny) -> [r, g, b] for nx, ny in 0..1, worked out on a small grid and stretched (so gradients cost almost nothing at any size). */
function pm_sd_field(int $w, int $h, callable $f): GdImage
{
    $lw = max(2, min(240, $w));
    $lh = max(2, (int)round($lw * $h / $w));
    $s = imagecreatetruecolor($lw, $lh);
    for ($y = 0; $y < $lh; $y++) {
        for ($x = 0; $x < $lw; $x++) {
            $c = $f(($x + 0.5) / $lw, ($y + 0.5) / $lh);
            imagesetpixel($s, $x, $y, ((int)max(0, min(255, $c[0])) << 16) | ((int)max(0, min(255, $c[1])) << 8) | (int)max(0, min(255, $c[2])));
        }
    }
    $big = imagecreatetruecolor($w, $h);
    imagecopyresampled($big, $s, 0, 0, 0, 0, $w, $h, $lw, $lh);
    return $big;
}

/** A colour with transparency (a: 1 = solid, 0 = invisible) for drawing on a truecolor canvas. */
function pm_sd_col($im, array $c, float $a = 1.0): int
{
    return imagecolorallocatealpha($im, (int)max(0, min(255, $c[0])), (int)max(0, min(255, $c[1])), (int)max(0, min(255, $c[2])), (int)round(127 * (1 - max(0.0, min(1.0, $a)))));
}

function pm_sd_poly($im, array $pts, int $col): void
{
    if (count($pts) >= 6) {
        imagefilledpolygon($im, array_map('intval', array_map('round', $pts)), $col);
    }
}

/** A flat ring between two radii, as one polygon (so it can be translucent). */
function pm_sd_donut($im, float $cx, float $cy, float $r1, float $r2, int $col): void
{
    $n = max(60, (int)($r1 / 6));
    $pts = [];
    for ($i = 0; $i <= $n; $i++) {
        $a = 2 * M_PI * $i / $n;
        $pts[] = $cx + $r1 * cos($a);
        $pts[] = $cy + $r1 * sin($a);
    }
    for ($i = $n; $i >= 0; $i--) {
        $a = 2 * M_PI * $i / $n;
        $pts[] = $cx + $r2 * cos($a);
        $pts[] = $cy + $r2 * sin($a);
    }
    pm_sd_poly($im, $pts, $col);
}

function pm_sd_disc($im, float $cx, float $cy, float $r, int $col): void
{
    imagefilledellipse($im, (int)round($cx), (int)round($cy), (int)round($r * 2), (int)round($r * 2), $col);
}

/** A ridge line (hills, waves) as a closed polygon down to the bottom edge. */
function pm_sd_ridge($im, int $w, int $h, float $base, float $amp, Closure $r, int $col, float $freq = 1.0): void
{
    $p1 = $r() * 6.28;
    $p2 = $r() * 6.28;
    $p3 = $r() * 6.28;
    $f1 = (1.2 + $r() * 1.4) * $freq;
    $f2 = (2.8 + $r() * 2.2) * $freq;
    $f3 = (6 + $r() * 4) * $freq;
    $pts = [];
    $step = max(6, (int)($w / 220));
    for ($x = 0; $x <= $w + $step; $x += $step) {
        $t = $x / $w * 6.28;
        $n = 0.55 * sin($t * $f1 + $p1) + 0.3 * sin($t * $f2 + $p2) + 0.15 * sin($t * $f3 + $p3);
        $pts[] = $x;
        $pts[] = $base * $h + $amp * $h * $n;
    }
    $pts[] = $w + $step;
    $pts[] = $h + 2;
    $pts[] = 0;
    $pts[] = $h + 2;
    pm_sd_poly($im, $pts, $col);
}

/** Width of text in pixels, with letter spacing. */
function pm_sd_tw(string $font, float $size, string $t, float $track = 0.0): float
{
    $b = imagettfbbox($size, 0, $font, $t);
    return ($b ? $b[2] - $b[0] : 0) + $track * max(0, mb_strlen($t) - 1);
}

/** One letter's advance (including its side spacing): measured between two bars, which gives the pen movement rather than the ink width. */
function pm_sd_adv(string $font, float $size, string $ch): float
{
    static $cache = [];
    $k = $font . '|' . round($size, 1) . '|' . $ch;
    if (!isset($cache[$k])) {
        $a = imagettfbbox($size, 0, $font, '|' . $ch . '|');
        $b = imagettfbbox($size, 0, $font, '||');
        $cache[$k] = $ch === ' ' ? $size * 0.32 : max($size * 0.2, ($a ? $a[2] - $a[0] : 0) - ($b ? $b[2] - $b[0] : 0));
    }
    return $cache[$k];
}

/** Spaced-out capitals, the quiet kind of writing. Returns the width used. $align: left | center | right around $x. */
function pm_sd_tracked($im, float $size, float $x, float $y, int $col, string $font, string $text, float $track, string $align = 'left'): float
{
    $chars = mb_str_split($text);
    $w = 0.0;
    foreach ($chars as $ch) {
        $w += pm_sd_adv($font, $size, $ch) + $track;
    }
    $w -= $track;
    $x0 = $align === 'center' ? $x - $w / 2 : ($align === 'right' ? $x - $w : $x);
    $cx = $x0;
    foreach ($chars as $ch) {
        if ($ch !== ' ') {
            imagettftext($im, $size, 0, (int)round($cx), (int)round($y), $col, $font, $ch);
        }
        $cx += pm_sd_adv($font, $size, $ch) + $track;
    }
    return $w;
}

/** A soft film grain: a faint noise tile laid over the picture. $amount 0.01 hides gradient banding; 0.07 is a visible grain. */
function pm_sd_grain($im, int $w, int $h, float $amount, int $seed): void
{
    $t = 192;
    $tile = imagecreatetruecolor($t, $t);
    imagealphablending($tile, false);
    imagesavealpha($tile, true);
    $r = pm_sd_rng($seed ^ 0x9E3779B);
    for ($y = 0; $y < $t; $y++) {
        for ($x = 0; $x < $t; $x++) {
            $light = $r() > 0.5;
            $a = 127 - (int)round(127 * $amount * (0.4 + 0.6 * $r()));
            imagesetpixel($tile, $x, $y, imagecolorallocatealpha($tile, $light ? 255 : 0, $light ? 255 : 0, $light ? 255 : 0, max(0, min(127, $a))));
        }
    }
    imagealphablending($im, true);
    for ($y = 0; $y < $h; $y += $t) {
        for ($x = 0; $x < $w; $x += $t) {
            imagecopy($im, $tile, $x, $y, 0, 0, min($t, $w - $x), min($t, $h - $y));
        }
    }
}

/** A wobbly circle, like something cut by hand: flat coordinates for a polygon. */
function pm_sd_blob(Closure $r, float $cx, float $cy, float $rad, int $n = 44, float $wob = 0.15): array
{
    $p1 = $r() * 6.28;
    $p2 = $r() * 6.28;
    $p3 = $r() * 6.28;
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $a = 2 * M_PI * $i / $n;
        $k = 1 + $wob * (0.6 * sin(2 * $a + $p1) + 0.3 * sin(3 * $a + $p2) + 0.1 * sin(5 * $a + $p3));
        $pts[] = $cx + $rad * $k * cos($a);
        $pts[] = $cy + $rad * $k * sin($a);
    }
    return $pts;
}

/** A shape with the soft shadow a cut piece of paper throws. */
function pm_sd_piece($im, array $pts, array $col, float $alpha, float $lift): void
{
    $sh = [];
    foreach ($pts as $i => $v) {
        $sh[] = $v + ($i % 2 ? $lift * 1.3 : $lift * 0.6);
    }
    pm_sd_poly($im, $sh, pm_sd_col($im, [0, 0, 0], 0.16));
    pm_sd_poly($im, $pts, pm_sd_col($im, $col, $alpha));
}

/** Where the art goes so the writing keeps its calm side: [cx, cy] as fractions of the width and height. */
function pm_sd_art_spot(float $ax, Closure $r): array
{
    return abs($ax - 0.5) < 0.1 ? [0.84 + $r() * 0.08, 0.14 + $r() * 0.12] : [min(0.97, $ax + 0.1 + $r() * 0.05), 0.5 + ($r() - 0.5) * 0.3];
}

/* ---------------- cover backgrounds: each returns a canvas of $w x $h; $ax = where the busy part of the art sits (0 left .. 1 right), the writing goes on the calmer side ---------------- */

/** Paper and ink: a flat card with a cut-paper circle, a smaller one, a stamped ring and a printed border. */
function pm_sd_bg_paper(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], pm_sd_mix($p['bg1'], $p['bg2'], 0.3), $ny * 0.7));
    [$fx, $fy] = pm_sd_art_spot($ax, $r);
    $cx = $fx * $w;
    $cy = $fy * $h;
    $R = min($h * 0.62, abs($ax - 0.5) < 0.1 ? $h * 0.3 : max($h * 0.34, $cx - $w * 0.64));
    pm_sd_piece($im, pm_sd_blob($r, $cx, $cy, $R, 60, 0.035), $p['bg2'], 1, $h * 0.012);
    pm_sd_piece($im, pm_sd_blob($r, $cx - $R * 0.18, $cy + $R * 0.1, $R * 0.5, 50, 0.05), $p['accent'], 1, $h * 0.01);
    pm_sd_donut($im, $cx, $cy, $R * 1.14, $R * 1.14 - max(2, $h * 0.0035), pm_sd_col($im, $p['ink'], 0.32));
    $m = (int)($h * 0.05);
    imagesetthickness($im, max(2, (int)($h * 0.004)));
    imagerectangle($im, $m, $m, $w - $m, $h - $m, pm_sd_col($im, $p['ink'], 0.2));
    imagesetthickness($im, 1);
    return $im;
}

/** Cut paper: four or five hand-cut layers overlapping, each with its own shadow, on a flat base. */
function pm_sd_bg_cutout(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], pm_sd_shade($p['bg1'], 0.03), $ny));
    [$fx, $fy] = pm_sd_art_spot($ax, $r);
    $tones = [pm_sd_mix($p['bg2'], $p['bg1'], 0.3), $p['bg2'], pm_sd_mix($p['bg2'], $p['accent'], 0.35), $p['accent'], pm_sd_mix($p['accent'], $p['ink'], 0.25)];
    foreach ($tones as $i => $c) {
        $rad = $h * (0.62 - $i * 0.09) * (0.85 + $r() * 0.3);
        pm_sd_piece($im, pm_sd_blob($r, ($fx + ($r() - 0.5) * 0.14) * $w, ($fy + ($r() - 0.5) * 0.4) * $h, $rad, 48, 0.2), $c, 1, $h * 0.014);
    }
    return $im;
}

/** Terrazzo: irregular flecks of stone scattered on a flat base, thicker on the art side. */
function pm_sd_bg_terrazzo(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg2'], pm_sd_shade($p['bg2'], -0.03), $ny));
    $tones = [$p['bg1'], $p['accent'], pm_sd_mix($p['ink'], $p['bg2'], 0.55), pm_sd_shade($p['bg2'], 0.13), pm_sd_mix($p['accent'], $p['bg1'], 0.5)];
    for ($i = 0; $i < 420; $i++) {
        $nx = $r();
        $ny = $r();
        if ($r() > exp(-((($nx - $ax) / 0.3) ** 2)) * 0.95 + 0.04) {
            continue;
        }
        $rad = $h * (0.008 + $r() * $r() * 0.05);
        $n = 5 + (int)($r() * 3);
        $a0 = $r() * 6.28;
        $pts = [];
        for ($k = 0; $k < $n; $k++) {
            $a = $a0 + 2 * M_PI * $k / $n + ($r() - 0.5) * 0.5;
            $rr = $rad * (0.6 + $r() * 0.7);
            $pts[] = $nx * $w + $rr * cos($a);
            $pts[] = $ny * $h + $rr * sin($a);
        }
        pm_sd_poly($im, $pts, pm_sd_col($im, $tones[(int)($r() * count($tones))], 0.9));
    }
    return $im;
}

/** Weave: hand-laid bands with slightly uneven edges and faint threads across them. */
function pm_sd_bg_weave(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], pm_sd_shade($p['bg1'], 0.04), $nx));
    $tones = [$p['bg2'], $p['accent'], pm_sd_mix($p['bg2'], $p['accent'], 0.5), pm_sd_shade($p['bg2'], 0.1), pm_sd_mix($p['bg1'], $p['ink'], 0.25)];
    $bands = 9;
    $bh = $h / $bands;
    for ($i = 0; $i < $bands; $i++) {
        $ph = $r() * 6.28;
        $amp = $bh * (0.05 + $r() * 0.07);
        $top = [];
        $bot = [];
        for ($x = 0; $x <= $w; $x += max(8, (int)($w / 160))) {
            $t = $x / $w;
            $weight = 0.25 + 0.75 * exp(-((($t - $ax) / 0.34) ** 2)); // the weave fades toward the writing
            $wob = sin($t * 9 + $ph) * $amp;
            $top[] = [$x, $i * $bh + $wob + $bh * 0.06];
            $bot[] = [$x, ($i + 1) * $bh + $wob - $bh * 0.06];
            if ($weight < 0.3) {
                $bot[count($bot) - 1][1] -= $bh * 0.5 * (0.3 - $weight) / 0.3; // thinner bands where it is calm
            }
        }
        $pts = [];
        foreach ($top as [$x, $y]) {
            array_push($pts, $x, $y);
        }
        foreach (array_reverse($bot) as [$x, $y]) {
            array_push($pts, $x, $y);
        }
        pm_sd_poly($im, $pts, pm_sd_col($im, $tones[$i % count($tones)], 0.55 + $r() * 0.4));
    }
    imagesetthickness($im, max(1, (int)($h / 700)));
    for ($x = (int)($h * 0.04); $x < $w; $x += (int)($h * 0.045)) {
        imageline($im, $x, 0, $x + (int)(($r() - 0.5) * $h * 0.02), $h, pm_sd_col($im, $p['ink'], 0.05));
    }
    imagesetthickness($im, 1);
    return $im;
}


function pm_sd_bg_aurora(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $cols = [$p['accent'], pm_sd_mix($p['accent'], $p['bg2'], 0.5), pm_sd_shade($p['bg2'], 0.14), pm_sd_shade($p['accent'], -0.1), $p['bg2'], pm_sd_mix($p['bg2'], $p['ink'], 0.2)]; // only the palette's own colours: a hue-shifted blob is what makes glows look generated
    $blobs = [];
    for ($i = 0; $i < 6; $i++) {
        $blobs[] = ['x' => $ax + ($r() - 0.5) * 0.6, 'y' => 0.1 + $r() * 0.8, 'r' => 0.2 + $r() * 0.4, 'c' => $cols[$i % count($cols)], 'k' => 0.45 + $r() * 0.45];
    }
    $asp = $w / $h;
    return pm_sd_field($w, $h, function ($nx, $ny) use ($p, $blobs, $asp) {
        $c = pm_sd_mix($p['bg1'], $p['bg2'], $nx * 0.5 + $ny * 0.5);
        foreach ($blobs as $b) {
            $dx = ($nx - $b['x']) * $asp;
            $dy = $ny - $b['y'];
            $c = pm_sd_mix($c, $b['c'], $b['k'] * exp(-($dx * $dx + $dy * $dy) / ($b['r'] * $b['r']) * 1.6));
        }
        return $c;
    });
}

function pm_sd_bg_dusk(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $hz = 0.66;
    $sx = abs($ax - 0.5) < 0.1 ? 0.82 : min(0.9, $ax + 0.04);
    $sy = abs($ax - 0.5) < 0.1 ? 0.3 : 0.47;
    $sun = pm_sd_mix($p['accent'], [255, 245, 225], 0.2);
    $horizon = pm_sd_mix($p['bg2'], $p['accent'], 0.45);
    $bands = [pm_sd_mix($p['bg1'], $p['bg2'], 0.0), pm_sd_mix($p['bg1'], $p['bg2'], 0.4), pm_sd_mix($p['bg2'], $horizon, 0.4), $horizon]; // a printed poster: the sky steps down in flat bands
    $im = pm_sd_field($w, $h, function ($nx, $ny) use ($p, $hz, $bands, $horizon) {
        if ($ny >= $hz) {
            return pm_sd_mix(pm_sd_mix($horizon, $p['bg1'], 0.45), pm_sd_shade($p['bg1'], -0.03), (($ny - $hz) / (1 - $hz)));
        }
        return $bands[min(3, (int)floor($ny / $hz * 4))];
    });
    $rad = $h * 0.13;
    pm_sd_disc($im, $sx * $w, $sy * $h, $rad, pm_sd_col($im, $sun, 1));
    for ($i = 0; $i < 7; $i++) { // the sun on the water, in hand-cut strokes
        $y = ($hz + 0.03 + $i * 0.034) * $h;
        $half = $rad * (1.0 - $i * 0.1) * (0.9 + $r() * 0.2);
        imagefilledrectangle($im, (int)($sx * $w - $half), (int)$y, (int)($sx * $w + $half), (int)($y + max(3, $h * 0.009)), pm_sd_col($im, $sun, 0.7 - $i * 0.07));
    }
    foreach ([[0.64, 0.05, 0.28], [0.69, 0.06, 0.14], [0.75, 0.07, 0.04]] as $i => [$base, $amp, $d]) {
        pm_sd_ridge($im, $w, $h, $base, $amp, $r, pm_sd_col($im, pm_sd_mix(pm_sd_shade($p['bg1'], -0.02), $horizon, $d), 1), 0.9 + $i * 0.3);
    }
    return $im;
}

function pm_sd_bg_waves(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix(pm_sd_mix($p['bg1'], $p['bg2'], $ny * 0.9 + 0.1), $p['accent'], 0.12 * exp(-((($nx - $ax) * 1.6) ** 2 + ($ny - 0.3) ** 2) / 0.2)));
    $cols = [$p['accent'], pm_sd_mix($p['accent'], $p['bg2'], 0.5), pm_sd_shade($p['bg2'], 0.12), $p['accent'], pm_sd_mix($p['bg2'], $p['bg1'], 0.5), pm_sd_shade($p['accent'], 0.1)];
    for ($i = 0; $i < 6; $i++) {
        pm_sd_ridge($im, $w, $h, 0.66 + $i * 0.062, 0.04 + $r() * 0.025, $r, pm_sd_col($im, $cols[$i], 0.13 + $i * 0.06), 0.7 + $r() * 0.5);
    }
    return $im;
}

function pm_sd_bg_geometry(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], $p['bg2'], ($nx * 0.7 + $ny * 0.3)));
    $cx = $ax * $w;
    $cy = $h * (0.45 + ($r() - 0.5) * 0.2);
    for ($i = 7; $i >= 1; $i--) {
        $rad = $h * (0.18 + $i * 0.15);
        pm_sd_disc($im, $cx, $cy, $rad, pm_sd_col($im, $i % 2 ? $p['accent'] : pm_sd_mix($p['accent'], $p['bg2'], 0.5), 0.045 + (7 - $i) * 0.004));
        pm_sd_donut($im, $cx, $cy, $rad, $rad - max(2, $h * 0.006), pm_sd_col($im, $p['accent'], 0.22));
    }
    pm_sd_disc($im, $cx + $h * 0.35, $cy - $h * 0.28, $h * 0.07, pm_sd_col($im, $p['accent'], 0.9));
    pm_sd_disc($im, $cx - $h * 0.55, $cy + $h * 0.34, $h * 0.035, pm_sd_col($im, [255, 255, 255], 0.55));
    return $im;
}

function pm_sd_bg_blueprint(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $dark = pm_sd_mix($p['bg1'], [0, 0, 0], 0.25);
    $asp = $w / $h;
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix(pm_sd_mix($dark, $p['bg2'], 0.5 * exp(-((($nx - $ax) * $asp) ** 2 + ($ny - 0.5) ** 2) / 0.3)), $p['bg1'], 0.2));
    $ink = pm_sd_mix($p['accent'], [255, 255, 255], 0.35);
    $minor = max(8, (int)($h / 22));
    for ($x = 0; $x < $w; $x += $minor) {
        imageline($im, $x, 0, $x, $h, pm_sd_col($im, $ink, ($x / $minor) % 4 === 0 ? 0.13 : 0.055));
    }
    for ($y = 0; $y < $h; $y += $minor) {
        imageline($im, 0, $y, $w, $y, pm_sd_col($im, $ink, ($y / $minor) % 4 === 0 ? 0.13 : 0.055));
    }
    $nodes = [];
    for ($i = 0; $i < 16; $i++) {
        $nodes[] = [(int)round((($ax + ($r() - 0.5) * 0.5) * $w) / $minor) * $minor, (int)round(($r() * $h) / $minor) * $minor];
    }
    foreach ($nodes as $i => [$x, $y]) {
        $best = [];
        foreach ($nodes as $j => [$x2, $y2]) {
            if ($i !== $j) {
                $best[$j] = ($x - $x2) ** 2 + ($y - $y2) ** 2;
            }
        }
        asort($best);
        imagesetthickness($im, max(1, (int)($h / 380)));
        foreach (array_slice(array_keys($best), 0, 2) as $j) {
            [$x2, $y2] = $nodes[$j];
            imageline($im, $x, $y, $x2, $y, pm_sd_col($im, $p['accent'], 0.4)); // right-angled, like a circuit
            imageline($im, $x2, $y, $x2, $y2, pm_sd_col($im, $p['accent'], 0.4));
        }
    }
    imagesetthickness($im, 1);
    foreach ($nodes as [$x, $y]) {
        pm_sd_disc($im, $x, $y, $h * 0.018, pm_sd_col($im, $p['bg1'], 1));
        pm_sd_donut($im, $x, $y, $h * 0.018, $h * 0.011, pm_sd_col($im, $p['accent'], 0.95));
    }
    pm_sd_donut($im, $ax * $w, $h * 0.5, $h * 0.62, $h * 0.612, pm_sd_col($im, $p['accent'], 0.35));
    pm_sd_donut($im, $ax * $w, $h * 0.5, $h * 0.42, $h * 0.40, pm_sd_col($im, $p['accent'], 0.22));
    return $im;
}

function pm_sd_bg_lowpoly(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    $cols = 18;
    $rows = max(6, (int)round($cols * $h / $w * 1.3));
    $cw = $w / $cols;
    $ch = $h / $rows;
    $pt = [];
    for ($j = 0; $j <= $rows; $j++) {
        for ($i = 0; $i <= $cols; $i++) {
            $jx = ($i === 0 || $i === $cols) ? 0 : ($r() - 0.5) * 0.7 * $cw;
            $jy = ($j === 0 || $j === $rows) ? 0 : ($r() - 0.5) * 0.7 * $ch;
            $pt[$j][$i] = [$i * $cw + $jx, $j * $ch + $jy];
        }
    }
    $base = function ($nx, $ny) use ($p, $ax) {
        $c = pm_sd_mix($p['bg1'], $p['bg2'], $nx * 0.55 + $ny * 0.45);
        return pm_sd_mix($c, $p['accent'], 0.5 * exp(-((($nx - $ax) * 1.5) ** 2 + ($ny - 0.5) ** 2) / 0.14));
    };
    imagefill($im, 0, 0, imagecolorallocate($im, $p['bg1'][0], $p['bg1'][1], $p['bg1'][2]));
    for ($j = 0; $j < $rows; $j++) {
        for ($i = 0; $i < $cols; $i++) {
            $a = $pt[$j][$i];
            $b = $pt[$j][$i + 1];
            $c = $pt[$j + 1][$i];
            $d = $pt[$j + 1][$i + 1];
            foreach (($i + $j) % 2 ? [[$a, $b, $c], [$b, $d, $c]] : [[$a, $b, $d], [$a, $d, $c]] as $tri) {
                $mx = ($tri[0][0] + $tri[1][0] + $tri[2][0]) / 3;
                $my = ($tri[0][1] + $tri[1][1] + $tri[2][1]) / 3;
                $nx = $mx / $w;
                $near = max(0.15, min(1.0, 0.25 + abs($nx - (1 - $ax)) * 1.4)); // calmer where the words go
                $col = pm_sd_shade($base($nx, $my / $h), ($r() - 0.5) * 0.12 * $near);
                $cx = $mx;
                $cy = $my;
                $pts = [];
                foreach ($tri as [$x, $y]) { // grown by a hair so no seams show between facets
                    $k = 1 + 1.2 / max(8.0, hypot($x - $cx, $y - $cy));
                    $pts[] = $cx + ($x - $cx) * $k;
                    $pts[] = $cy + ($y - $cy) * $k;
                }
                pm_sd_poly($im, $pts, imagecolorallocate($im, (int)$col[0], (int)$col[1], (int)$col[2]));
            }
        }
    }
    return $im;
}

function pm_sd_bg_diamonds(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], $p['bg2'], $nx * 0.5 + $ny * 0.5));
    $s = $h / 5.0;
    $tones = [$p['accent'], pm_sd_mix($p['accent'], [255, 255, 255], 0.4), pm_sd_shade($p['bg2'], 0.12), pm_sd_shade($p['bg1'], 0.1)];
    for ($row = -1; $row * $s / 2 < $h + $s; $row++) {
        for ($col = -1; $col * $s < $w + $s; $col++) {
            $cx = $col * $s + ($row % 2 ? $s / 2 : 0);
            $cy = $row * $s / 2;
            $prox = max(0.0, 1 - abs($cx / $w - $ax) * 1.7);
            $a = 0.03 + 0.34 * $prox * $r();
            if ($a < 0.045) {
                continue;
            }
            $g = $s * 0.46;
            pm_sd_poly($im, [$cx, $cy - $g, $cx + $g, $cy, $cx, $cy + $g, $cx - $g, $cy], pm_sd_col($im, $tones[(int)($r() * 4)], $a));
        }
    }
    return $im;
}

function pm_sd_bg_spotlight(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $asp = $w / $h;
    $glow = pm_sd_mix($p['bg2'], $p['accent'], 0.35);
    $im = pm_sd_field($w, $h, function ($nx, $ny) use ($p, $glow, $ax, $asp) {
        $d = (($nx - $ax) * $asp) ** 2 + ($ny - 0.52) ** 2;
        $c = pm_sd_mix(pm_sd_shade($p['bg1'], -0.04), $glow, 0.9 * exp(-$d / 0.16));
        return pm_sd_mix($c, [0, 0, 0], min(0.55, 0.5 * $d));
    });
    $cx = $ax * $w;
    pm_sd_poly($im, [$cx - $h * 0.08, 0, $cx + $h * 0.08, 0, $cx + $h * 0.75, $h, $cx - $h * 0.75, $h], pm_sd_col($im, [255, 255, 255], 0.045));
    foreach ([0.34, 0.5, 0.68, 0.9] as $i => $k) {
        pm_sd_donut($im, $cx, $h * 0.52, $h * $k, $h * $k - max(1, $h * 0.0035), pm_sd_col($im, $p['accent'], 0.30 - $i * 0.05));
    }
    return $im;
}

function pm_sd_bg_ribbons(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], $p['bg2'], (1 - $ny) * 0.4 + $nx * 0.6));
    $cols = [$p['accent'], pm_sd_mix($p['accent'], [255, 255, 255], 0.4), pm_sd_shade($p['bg2'], 0.16), pm_sd_shade($p['accent'], -0.12), pm_sd_mix($p['accent'], $p['bg2'], 0.5), pm_sd_mix($p['bg2'], $p['ink'], 0.25)];
    for ($k = 0; $k < 6; $k++) {
        $ph = $r() * 6.28;
        $f = 0.8 + $r() * 1.3;
        $amp = 0.12 + $r() * 0.14;
        $base = 0.25 + $r() * 0.5;
        $th = 0.05 + $r() * 0.13;
        $up = [];
        $dn = [];
        for ($x = 0; $x <= $w; $x += max(6, (int)($w / 200))) {
            $t = $x / $w;
            $weight = 0.35 + 0.65 * exp(-(($t - $ax) ** 2) / 0.12); // ribbons gather on the art side
            $y = ($base + $amp * sin($t * 6.28 * $f + $ph) * $weight) * $h;
            $half = $th * $h * (0.4 + 0.6 * $weight) * (0.7 + 0.3 * sin($t * 9 + $ph));
            $up[] = [$x, $y - $half];
            $dn[] = [$x, $y + $half];
        }
        $alpha = 0.18 + $r() * 0.34;
        for ($i = 0; $i + 1 < count($up); $i++) { // slices, so the ribbon fades away over the writing
            $mid = ($up[$i][0] + $up[$i + 1][0]) / 2 / $w;
            $fade = max(0.0, min(1.0, 1.2 * exp(-(($mid - $ax) ** 2) / 0.09)));
            if ($fade > 0.04) {
                pm_sd_poly($im, [$up[$i][0], $up[$i][1], $up[$i + 1][0] + 1, $up[$i + 1][1], $dn[$i + 1][0] + 1, $dn[$i + 1][1], $dn[$i][0], $dn[$i][1]], pm_sd_col($im, $cols[$k], $alpha * $fade));
            }
        }
    }
    return $im;
}

function pm_sd_bg_minimal(int $w, int $h, array $p, Closure $r, float $ax): GdImage
{
    $im = pm_sd_field($w, $h, fn($nx, $ny) => pm_sd_mix($p['bg1'], $p['bg2'], 0.15 + 0.7 * ($nx * 0.6 + $ny * 0.4)));
    $cx = $ax * $w;
    foreach ([[0.95, 0.0125], [0.72, 0.005], [0.47, 0.0035]] as $i => [$k, $t]) {
        pm_sd_donut($im, $cx, $h * 0.5, $h * $k, $h * ($k - $t), pm_sd_col($im, $p['accent'], 0.55 - $i * 0.12));
    }
    pm_sd_disc($im, $cx + $h * 0.7 * cos(0.9), $h * 0.5 - $h * 0.7 * sin(0.9), $h * 0.026, pm_sd_col($im, $p['accent'], 1));
    return $im;
}

/** Your own photo, fitted to the picture and washed with the palette so the words stay readable on the calm side. */
function pm_sd_bg_photo(int $w, int $h, array $p, Closure $r, float $ax, string $hero = ''): GdImage
{
    $ph = $hero !== '' && is_file($hero) ? @imagecreatefromstring((string)file_get_contents($hero)) : false;
    if (!$ph) {
        return pm_sd_bg_aurora($w, $h, $p, $r, $ax);
    }
    $im = imagecreatetruecolor($w, $h);
    $pw = imagesx($ph);
    $pH = imagesy($ph);
    $sc = max($w / $pw, $h / $pH);
    $cw = (int)round($w / $sc);
    $ch = (int)round($h / $sc);
    imagecopyresampled($im, $ph, 0, 0, (int)(($pw - $cw) / 2), (int)(($pH - $ch) / 2), $w, $h, $cw, $ch);
    imagefilter($im, IMG_FILTER_GRAYSCALE);
    imagealphablending($im, true);
    $wash = pm_sd_field($w, $h, function ($nx, $ny) use ($p, $ax) {
        return pm_sd_mix($p['bg1'], $p['bg2'], 0.4 + 0.5 * abs($nx - (1 - $ax)));
    });
    imagecopymerge($im, $wash, 0, 0, 0, 0, $w, $h, 62);
    for ($x = 0; $x < $w; $x++) { // the writing side is darker
        $t = abs($x / $w - $ax);
        imageline($im, $x, 0, $x, $h, pm_sd_col($im, $p['bg1'], max(0.12, min(0.9, 0.12 + $t * 1.25))));
    }
    return $im;
}

/** A veil of the background colour over the side where the words go, so the art never fights the writing. $strength 0..1. */
function pm_sd_veil($im, int $w, int $h, float $ax, array $bg, float $strength): void
{
    if ($strength <= 0.01) {
        return;
    }
    imagealphablending($im, true);
    $step = max(1, (int)($w / 900));
    for ($x = 0; $x < $w; $x += $step) {
        $t = abs($x / $w - $ax); // distance from the busy part
        $a = $strength * max(0.0, min(1.0, ($t - 0.12) / 0.38));
        if ($a > 0.01) {
            imagefilledrectangle($im, $x, 0, $x + $step, $h, pm_sd_col($im, $bg, $a));
        }
    }
}

/** Faint texture over the whole picture. */
function pm_sd_texture($im, int $w, int $h, string $texture, array $p, int $seed): void
{
    pm_sd_grain($im, $w, $h, in_array($texture, ['grain', 'paper'], true) ? ($texture === 'paper' ? 0.06 : 0.075) : 0.014, $seed); // a hair of grain always hides banding in the gradients
    imagealphablending($im, true);
    if ($texture === 'paper') { // short fibres, like pressed paper
        $r = pm_sd_rng($seed ^ 0x51ED);
        $n = (int)($w * $h / 5500);
        $light = pm_sd_col($im, [255, 250, 240], 0.10);
        $dark = pm_sd_col($im, [20, 14, 8], 0.09);
        for ($i = 0; $i < $n; $i++) {
            $x = $r() * $w;
            $y = $r() * $h;
            $a = $r() * 6.28;
            $len = 5 + $r() * 14;
            $bend = ($r() - 0.5) * 0.6;
            imageline($im, (int)$x, (int)$y, (int)($x + $len * cos($a + $bend)), (int)($y + $len * sin($a + $bend)), $r() > 0.5 ? $light : $dark);
        }
    } elseif ($texture === 'dots') {
        $s = max(14, (int)($h / 34));
        $col = pm_sd_col($im, [255, 255, 255], 0.13);
        for ($y = (int)($s / 2); $y < $h; $y += $s) {
            for ($x = (int)($s / 2) + (((int)($y / $s)) % 2 ? (int)($s / 2) : 0); $x < $w; $x += $s) {
                pm_sd_disc($im, $x, $y, max(1.2, $s * 0.07), $col);
            }
        }
    } elseif ($texture === 'lines') {
        $s = max(10, (int)($h / 40));
        imagesetthickness($im, max(1, (int)($h / 500)));
        $col = pm_sd_col($im, [255, 255, 255], 0.05);
        for ($x = -$h; $x < $w; $x += $s) {
            imageline($im, $x, $h, $x + $h, 0, $col);
        }
        imagesetthickness($im, 1);
    }
}

/* ---------------- the logo as a quiet mark ---------------- */

/** The business's logo file, or ''. */
function pm_sd_logo_file(string $brand): string
{
    $f = PM_ROOT . '/' . pm_brand_asset($brand, 'logo');
    return is_file($f) ? $f : '';
}

/**
 * The mark of the logo as a shape: a logo with a see-through background keeps its own transparency; a logo on a plain coloured tile (all four corners alike) becomes the difference from that
 * tile; anything else (a photo-like logo) has no clean shape and gives null. Returns a GD image whose alpha is the shape and whose colour is $tint.
 */
function pm_sd_logo_mark(string $brand, array $tint, int $box = 420): ?GdImage
{
    return pm_sd_mark_from_file(pm_sd_logo_file($brand), $tint, $box);
}

/** The same, from any PNG file. */
function pm_sd_mark_from_file(string $f, array $tint, int $box = 420): ?GdImage
{
    $src = $f !== '' && is_file($f) ? @imagecreatefrompng($f) : false;
    if (!$src) {
        return null;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $sc = min(1.0, $box / max($sw, $sh));
    $w = max(8, (int)round($sw * $sc));
    $h = max(8, (int)round($sh * $sc));
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagecopyresampled($im, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    $transparent = 0;
    $n = 0;
    for ($y = 0; $y < $h; $y += max(1, intdiv($h, 10))) {
        for ($x = 0; $x < $w; $x += max(1, intdiv($w, 10))) {
            $n++;
            $transparent += ((imagecolorat($im, $x, $y) >> 24) & 127) > 40 ? 1 : 0;
        }
    }
    $corner = function (int $x, int $y) use ($im) {
        $c = imagecolorat($im, $x, $y);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    };
    $cs = [$corner(1, 1), $corner($w - 2, 1), $corner(1, $h - 2), $corner($w - 2, $h - 2)];
    $uniform = max(array_map(fn($c) => abs($c[0] - $cs[0][0]) + abs($c[1] - $cs[0][1]) + abs($c[2] - $cs[0][2]), $cs)) < 36;
    $byAlpha = $transparent / max(1, $n) > 0.08;
    if (!$byAlpha && !$uniform) {
        return null;
    }
    [$x1, $y1, $x2, $y2] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if ($byAlpha) {
                $a = 1 - (($c >> 24) & 127) / 127;
            } else {
                $d = abs((($c >> 16) & 255) - $cs[0][0]) + abs((($c >> 8) & 255) - $cs[0][1]) + abs(($c & 255) - $cs[0][2]);
                $a = max(0.0, min(1.0, ($d - 30) / 90));
            }
            if ($a > 0.25) {
                [$x1, $y1, $x2, $y2] = [min($x1, $x), min($y1, $y), max($x2, $x), max($y2, $y)];
            }
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, (int)$tint[0], (int)$tint[1], (int)$tint[2], (int)round(127 * (1 - $a))));
        }
    }
    if ($x2 > $x1 && $y2 > $y1) {
        $pad = (int)round(max($x2 - $x1, $y2 - $y1) * 0.03);
        $cx = max(0, $x1 - $pad);
        $cy = max(0, $y1 - $pad);
        $cw = min($w - $cx, $x2 - $x1 + 1 + 2 * $pad);
        $ch = min($h - $cy, $y2 - $y1 + 1 + 2 * $pad);
        $crop = imagecreatetruecolor($cw, $ch);
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        imagefill($crop, 0, 0, imagecolorallocatealpha($crop, 0, 0, 0, 127));
        imagecopy($crop, $im, 0, 0, $cx, $cy, $cw, $ch);
        return $crop;
    }
    return $im;
}

/** The logo as it is (for a plate), fitted into $box pixels. */
function pm_sd_logo_plain(string $brand): ?GdImage
{
    $f = pm_sd_logo_file($brand);
    $im = $f !== '' ? @imagecreatefrompng($f) : false;
    return $im ?: null;
}

/** Puts $mark centred at ($cx, $cy), no wider than $bw and no taller than $bh. */
function pm_sd_put($im, GdImage $mark, float $cx, float $cy, float $bw, float $bh): void
{
    $sw = imagesx($mark);
    $sh = imagesy($mark);
    $sc = min($bw / $sw, $bh / $sh);
    $w = max(1, (int)round($sw * $sc));
    $h = max(1, (int)round($sh * $sc));
    imagealphablending($im, true);
    imagecopyresampled($im, $mark, (int)round($cx - $w / 2), (int)round($cy - $h / 2), 0, 0, $w, $h, $sw, $sh);
}

/** Initials for a monogram: "ProManaged IT" -> PM, "Travel Malawi" -> TM, "Green Grocers Mzuzu" -> GG. */
function pm_studio_monogram(string $name): string
{
    $name = trim($name);
    $words = preg_split('/[\s&\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) {
        return '';
    }
    if (preg_match_all('/\p{Lu}/u', $words[0], $m) && count($m[0]) >= 2 && mb_strlen($words[0]) > 4) {
        return mb_strtoupper($m[0][0] . $m[0][1]);
    }
    return mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
}

/* ---------------- targets: what sizes each place wants, and the part of the picture that is always seen ---------------- */

/** key => [label, kind, w, h, safe [x, y, w, h] in pixels at that size (or null = the whole picture with a margin), align override or '']. */
function pm_studio_targets(): array
{
    return [
        'facebook' => ['Facebook cover', 'cover', 1640, 856, [110, 120, 1420, 470], ''], // the middle band every device shows, minus the lower left where the profile picture sits
        'linkedin' => ['LinkedIn banner', 'cover', 1128, 191, [300, 14, 770, 163], ''],
        'x' => ['X header', 'cover', 1500, 500, [260, 70, 1040, 270], ''],
        'youtube' => ['YouTube banner', 'cover', 2560, 1440, [507, 508, 1546, 423], 'center'],
        'google' => ['Google Business cover', 'cover', 1080, 608, [70, 70, 940, 468], ''],
        'og' => ['Link preview (website, WhatsApp, Facebook shares)', 'cover', 1200, 630, [90, 80, 1020, 470], ''],
        'story' => ['Story / Status background', 'cover', 1080, 1920, [90, 420, 900, 1080], 'center'],
    ];
}

/** The target that matches a picture size, or null. */
function pm_studio_target_for(int $w, int $h): ?array
{
    foreach (pm_studio_targets() as $k => $t) {
        if ($t[2] === $w && $t[3] === $h) {
            return ['key' => $k] + array_combine(['label', 'kind', 'w', 'h', 'safe', 'align'], $t);
        }
    }
    return null;
}

/** The profile picture sizes the platforms want: size => the places that use it. */
function pm_studio_profile_sizes(): array
{
    $out = [];
    foreach (pm_platforms() as $k => $p) {
        $out[$p['profile'][0]][] = $p['name'];
    }
    krsort($out);
    return $out;
}

/* ---------------- the design spec ---------------- */

/** The design a business starts from: its own colour, its cover words, a calm style. */
function pm_studio_default(string $brand): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $cfg = pm_social_settings($brand);
        $pal = pm_sd_palettes((string)($s['accent_color'] ?? '#17375E'))['brand'];
        $name = trim((string)($s['company_name'] ?? '')) ?: pm_brand_name($brand);
        $head = trim((string)($cfg['cover_head'] ?? ''));
        $site = preg_replace('#^https?://#i', '', rtrim((string)(pm_link_cfg($brand)['url'] ?: ($s['website'] ?? '')), '/'));
        $phone = trim((string)($s['phone'] ?? ''));
        return ['v' => 2, 'style' => 'paper', 'pstyle' => 'plate', 'pal' => 'brand', 'bg1' => $pal[1], 'bg2' => $pal[2], 'accent' => $pal[3], 'ink' => $pal[4], 'texture' => 'paper', 'align' => 'left', 'seed' => abs(crc32($brand)) % 900000 + 1000,
            'eyebrow' => mb_strtoupper(mb_substr($name, 0, 40)), 'headline' => $head !== '' ? mb_substr($head, 0, 70) : $name, 'subline' => mb_substr(trim((string)($cfg['cover_line'] ?? '')), 0, 110),
            'whisper' => array_values(array_filter([$site, $phone !== '' ? 'WhatsApp ' . $phone : ''])), 'monogram' => pm_studio_monogram($name), 'cover_logo' => 'none', 'by' => 'start', 'at' => ''];
    } finally {
        pm_brand_set($prev);
    }
}

/**
 * Checks a spec that came from a form or from the AI and fills the gaps from $base. Colours are hex, the writing colour is changed if it cannot be read on the background, texts are
 * short plain text. $facts (AI only): the words must pass the facts-only guard (no new numbers, links, emails, boasts); the design choices are kept even when the words are not.
 * Returns the clean spec plus ['_dropped' => [...]] naming what was refused.
 */
function pm_studio_clean(array $in, array $base, ?string $factsSource = null): array
{
    $out = $base;
    $drop = [];
    $pick = fn(string $k, array $allowed) => isset($in[$k]) && is_scalar($in[$k]) && array_key_exists((string)$in[$k], $allowed) ? (string)$in[$k] : null;
    foreach (['style' => PM_SD_STYLES, 'pstyle' => PM_SD_PSTYLES, 'texture' => PM_SD_TEXTURES, 'align' => ['left' => 1, 'center' => 1, 'right' => 1], 'cover_logo' => ['none' => 1, 'white' => 1]] as $k => $allowed) {
        if (($v = $pick($k, $allowed)) !== null) {
            $out[$k] = $v;
        }
    }
    $pal = (array)($in['palette'] ?? []);
    foreach (['bg1', 'bg2', 'accent', 'ink'] as $k) {
        $v = $in[$k] ?? $pal[$k] ?? null;
        if (is_scalar($v) && preg_match('/^#?([0-9a-f]{6}|[0-9a-f]{3})$/i', trim((string)$v))) {
            $out[$k] = pm_sd_tohex(pm_sd_hex((string)$v));
        } elseif ($v !== null && isset($in[$k])) {
            $drop[] = $k;
        }
    }
    if (isset($in['pal']) && is_scalar($in['pal']) && preg_match('/^[a-z\-]{2,20}$/', (string)$in['pal'])) {
        $out['pal'] = (string)$in['pal'];
    }
    if (isset($in['seed']) && is_numeric($in['seed'])) {
        $out['seed'] = max(1, min(2000000000, (int)$in['seed']));
    }
    $ink = pm_sd_ink_for(pm_sd_hex($out['bg1']), pm_sd_hex($out['bg2']), pm_sd_hex($out['ink']));
    if (pm_sd_tohex($ink) !== pm_sd_tohex(pm_sd_hex($out['ink']))) {
        $out['ink'] = pm_sd_tohex($ink); // the writing must be readable on the colours chosen
    }
    $txt = function (string $k, int $max) use ($in, &$out, &$drop, $factsSource) {
        if (!isset($in[$k]) || !is_scalar($in[$k])) {
            return;
        }
        $t = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$in[$k])));
        if ($factsSource !== null) {
            $t = pm_human_polish($t, 'profile');
        }
        if ($factsSource !== null && $t !== '' && !pm_opt_ai_ok($t, $max, $factsSource)) {
            $drop[] = $k;
            return;
        }
        $out[$k] = mb_substr($t, 0, $max);
    };
    $txt('eyebrow', 40);
    $txt('headline', 70);
    $txt('subline', 110);
    if (isset($in['monogram']) && is_scalar($in['monogram'])) {
        $m = mb_strtoupper(preg_replace('/[^\p{L}\p{N}]/u', '', (string)$in['monogram']));
        if ($m !== '') {
            $out['monogram'] = mb_substr($m, 0, 3);
        }
    }
    if (isset($in['whisper'])) {
        $list = is_array($in['whisper']) ? $in['whisper'] : preg_split('/\R/', (string)$in['whisper']);
        $w = [];
        foreach (array_slice($list, 0, 6) as $item) {
            $t = trim((string)preg_replace('/\s+/u', ' ', strip_tags(is_scalar($item) ? (string)$item : '')));
            if ($t === '') {
                continue;
            }
            if ($factsSource !== null && !pm_opt_ai_ok($t, 40, $factsSource)) {
                $drop[] = 'whisper';
                continue;
            }
            $w[] = mb_substr($t, 0, 40);
        }
        $out['whisper'] = array_slice($w, 0, 3);
    }
    if (!isset(PM_SD_STYLES[$out['style']])) {
        $out['style'] = 'aurora';
    }
    $out['_dropped'] = array_values(array_unique($drop));
    return $out;
}

/** The text of every fact the AI may restate: the guard compares its words against this. */
function pm_studio_facts_source(string $brand, array $spec = []): string
{
    $f = pm_opt_facts($brand);
    return implode(' ', array_filter(array_map('strval', [$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), implode(' ', $f['offers'] ?? []), $f['address'], $f['phone'], $f['website'], $f['site'],
        $spec['headline'] ?? '', $spec['subline'] ?? '', $spec['eyebrow'] ?? '', implode(' ', (array)($spec['whisper'] ?? []))])));
}

/* ---------------- drawing a whole picture ---------------- */

function pm_studio_can_draw(): bool
{
    return function_exists('imagettftext') && pm_font(true) !== '' && pm_font(false) !== '' && (pm_font(false, true) !== '' || pm_font(false) !== '');
}

/** Cover art at any size. $safe = [x, y, w, h] the part every device shows (null = the whole picture with margins); $align overrides the spec's. Returns a GD image or null when fonts are missing. */
function pm_studio_cover(array $spec, int $W, int $H, ?array $safe = null, string $align = '', string $brand = ''): ?GdImage
{
    if (!pm_studio_can_draw()) {
        return null;
    }
    $W = max(64, min(3200, $W));
    $H = max(64, min(3200, $H));
    $align = $align !== '' ? $align : (string)($spec['align'] ?? 'left');
    $pal = ['bg1' => pm_sd_hex($spec['bg1']), 'bg2' => pm_sd_hex($spec['bg2']), 'accent' => pm_sd_hex($spec['accent']), 'ink' => pm_sd_hex($spec['ink'])];
    $ss = $W * $H <= 2300000 ? 2 : 1; // drawn twice as large and brought down, so every edge is smooth
    $w = $W * $ss;
    $h = $H * $ss;
    $r = pm_sd_rng((int)$spec['seed']);
    $ax = $align === 'left' ? 0.76 : ($align === 'right' ? 0.24 : 0.5);
    $style = isset(PM_SD_STYLES[$spec['style']]) ? (string)$spec['style'] : 'aurora';
    $hero = $brand !== '' && function_exists('pm_hero_path') ? pm_hero_path($brand) : '';
    $big = match ($style) {
        'paper' => pm_sd_bg_paper($w, $h, $pal, $r, $ax),
        'cutout' => pm_sd_bg_cutout($w, $h, $pal, $r, $ax),
        'terrazzo' => pm_sd_bg_terrazzo($w, $h, $pal, $r, $ax),
        'weave' => pm_sd_bg_weave($w, $h, $pal, $r, $ax),
        'dusk' => pm_sd_bg_dusk($w, $h, $pal, $r, $ax),
        'waves' => pm_sd_bg_waves($w, $h, $pal, $r, $ax),
        'geometry' => pm_sd_bg_geometry($w, $h, $pal, $r, $ax),
        'blueprint' => pm_sd_bg_blueprint($w, $h, $pal, $r, $ax),
        'lowpoly' => pm_sd_bg_lowpoly($w, $h, $pal, $r, $ax),
        'diamonds' => pm_sd_bg_diamonds($w, $h, $pal, $r, $ax),
        'spotlight' => pm_sd_bg_spotlight($w, $h, $pal, $r, $ax),
        'ribbons' => pm_sd_bg_ribbons($w, $h, $pal, $r, $ax),
        'minimal' => pm_sd_bg_minimal($w, $h, $pal, $r, $ax),
        'photo' => pm_sd_bg_photo($w, $h, $pal, $r, $ax, $hero),
        default => pm_sd_bg_aurora($w, $h, $pal, $r, $ax),
    };
    if ($style !== 'aurora' && $style !== 'lowpoly' && $style !== 'spotlight' && $style !== 'paper' && !($style === 'photo' && $hero !== '')) {
        $veil = ['dusk' => 0.2, 'waves' => 0.45, 'geometry' => 0.8, 'blueprint' => 0.4, 'diamonds' => 0.55, 'ribbons' => 0.4, 'minimal' => 0.3, 'cutout' => 0.35, 'terrazzo' => 0.6, 'weave' => 0.68][$style] ?? 0.3;
        pm_sd_veil($big, $w, $h, $ax, pm_sd_mix($pal['bg1'], $pal['bg2'], 0.25), $veil);
    }
    if ($ss > 1) {
        $im = imagecreatetruecolor($W, $H);
        imagecopyresampled($im, $big, 0, 0, 0, 0, $W, $H, $w, $h);
    } else {
        $im = $big;
    }
    pm_sd_texture($im, $W, $H, (string)$spec['texture'], $pal, (int)$spec['seed']);
    pm_sd_cover_words($im, $spec, $pal, $W, $H, $safe, $align, $brand);
    return $im;
}

/** The writing: a quiet eyebrow, a rule, the headline, a line, and a whisper strip, all inside the safe area. */
function pm_sd_cover_words($im, array $spec, array $pal, int $W, int $H, ?array $safe, string $align, string $brand): void
{
    $serifB = pm_font(true, true) ?: pm_font(true);
    $sans = pm_font(false);
    $sansB = pm_font(false, true) ?: $sans;
    [$sx, $sy, $sw, $sh] = $safe ?: [(int)($W * 0.06), (int)($H * 0.08), (int)($W * 0.88), (int)($H * 0.84)];
    $compact = $W / max(1, $H) > 4.2; // a banner much wider than tall (LinkedIn): the headline alone
    $tall = $H > $W; // story
    $ink = $pal['ink'];
    $textW = (int)($sw * ($compact ? 0.94 : ($align === 'center' ? 0.84 : ($tall ? 0.9 : ($sw / $sh > 3 ? 0.66 : 0.62)))));
    $x0 = $align === 'center' ? $sx + $sw / 2 : ($align === 'right' ? $sx + $sw - 0.02 * $sw : $sx + 0.02 * $sw);
    $anchor = $align === 'center' ? 'center' : ($align === 'right' ? 'right' : 'left');
    $head = trim((string)$spec['headline']);
    $line = trim((string)$spec['subline']);
    $eyebrow = trim((string)$spec['eyebrow']);
    $whisper = array_values(array_filter(array_map('trim', (array)$spec['whisper'])));
    if (mb_strtoupper($eyebrow) === mb_strtoupper($head)) {
        $eyebrow = '';
    }
    $fit = function (float $hs) use ($head, $serifB, $textW) { // the headline: as large as fits in two lines
        for ($i = 0; $i < 60; $i++) {
            $lines = pm_wrap_px($head, $serifB, $hs, $textW);
            $widest = max(array_map(fn($l) => pm_sd_tw($serifB, $hs, $l), $lines ?: ['']));
            if ((count($lines) <= 2 && $widest <= $textW) || $hs <= 10) {
                return [$hs, $lines];
            }
            $hs *= 0.95;
        }
        return [$hs, pm_wrap_px($head, $serifB, $hs, $textW)];
    };
    [$hs, $lines] = $fit(max(12.0, $compact ? $sh * 0.36 : $sh * ($tall ? 0.12 : 0.21)));
    $es = max(9.0, $sh * 0.036 * ($tall ? 1.1 : 1.0));
    $ws = max(9.0, $sh * 0.032);
    $showEyebrow = $eyebrow !== '' && !$compact;
    $showLine = $line !== '' && !$compact;
    $showWhisper = (bool)$whisper && !$compact;
    // everything must fit the safe area: drop the line, then the eyebrow, then shrink the headline
    for ($try = 0; $try < 14; $try++) {
        $ls = max(10.0, min($hs * 0.40, $sh * 0.06));
        $lh = $hs * 1.14;
        $sublines = $showLine ? array_slice(pm_wrap_px($line, $sans, $ls, $textW) ?: [$line], 0, 2) : [];
        $blockH = count($lines) * $lh + ($showEyebrow ? $es * 2.9 : 0) + ($sublines ? count($sublines) * $ls * 1.5 + $ls * 0.6 : 0);
        $bandH = $showWhisper ? $ws * 3.2 : 0;
        if ($blockH + $bandH <= $sh * 0.96) {
            break;
        }
        if ($showLine) {
            $showLine = false;
        } elseif ($showEyebrow) {
            $showEyebrow = false;
        } elseif ($showWhisper && $try > 6) {
            $showWhisper = false;
        } else {
            [$hs, $lines] = $fit($hs * 0.9);
        }
    }
    $y = max($sy, $sy + ($sh - $bandH - $blockH) / 2);
    $c = fn(array $col, float $a) => pm_sd_col($im, $col, $a);
    $shadow = $c([0, 0, 0], 0.18);
    if ($showEyebrow) {
        $y += $es;
        $txt = mb_strtoupper($eyebrow);
        pm_sd_tracked($im, $es, $x0, (int)$y, $c($pal['accent'], 0.95), $sansB, $txt, $es * 0.34, $anchor);
        $y += $es * 0.9;
        $rl = $es * 3.2;
        $rx = $anchor === 'center' ? $x0 - $rl / 2 : ($anchor === 'right' ? $x0 - $rl : $x0);
        imagefilledrectangle($im, (int)$rx, (int)$y, (int)($rx + $rl), (int)($y + max(1, $es * 0.12)), $c($pal['accent'], 0.8));
        $y += $es * 0.9;
    }
    foreach ($lines as $l) {
        $y += $lh * 0.84;
        $lx = $anchor === 'center' ? $x0 - pm_sd_tw($serifB, $hs, $l) / 2 : ($anchor === 'right' ? $x0 - pm_sd_tw($serifB, $hs, $l) : $x0);
        imagettftext($im, $hs, 0, (int)round($lx + $hs * 0.025), (int)round($y + $hs * 0.03), $shadow, $serifB, $l);
        imagettftext($im, $hs, 0, (int)round($lx), (int)round($y), $c($ink, 1), $serifB, $l);
        $y += $lh * 0.16;
    }
    if ($sublines) {
        $y += $ls * 0.45;
        foreach ($sublines as $l) {
            $y += $ls * 1.5;
            $lx = $anchor === 'center' ? $x0 - pm_sd_tw($sans, $ls, $l) / 2 : ($anchor === 'right' ? $x0 - pm_sd_tw($sans, $ls, $l) : $x0);
            imagettftext($im, $ls, 0, (int)round($lx), (int)round($y), $c($ink, 0.82), $sans, $l);
        }
    }
    if ($showWhisper) {
        $wy = $sy + $sh - $ws * 0.9;
        $txt = mb_strtoupper(implode('   ·   ', $whisper));
        $wt = pm_sd_tw($sansB, $ws, $txt, $ws * 0.26);
        while ($wt > $sw * 0.94 && $ws > 8) {
            $ws *= 0.92;
            $wt = pm_sd_tw($sansB, $ws, $txt, $ws * 0.26);
        }
        $ruleY = $wy - $ws * 1.9;
        $rl = min($sw * 0.5, $wt);
        $rx = $anchor === 'center' ? $x0 - $rl / 2 : ($anchor === 'right' ? $x0 - $rl : $x0);
        imagefilledrectangle($im, (int)$rx, (int)$ruleY, (int)($rx + $rl), (int)($ruleY + max(1, $ws * 0.07)), $c($ink, 0.22));
        pm_sd_tracked($im, $ws, $x0, (int)$wy, $c($ink, 0.7), $sansB, $txt, $ws * 0.26, $anchor);
    }
    if (($spec['cover_logo'] ?? 'none') === 'white' && $brand !== '' && !$compact && ($mk = pm_sd_logo_mark($brand, $ink, 300))) {
        $lh2 = $sh * 0.12;
        pm_sd_put($im, $mk, $anchor === 'center' ? $x0 : ($anchor === 'right' ? $x0 - $lh2 : $x0 + $lh2), $sy + $lh2 * 0.4, $lh2 * 2.4, $lh2);
    }
}

/** A square profile picture. Everything that matters sits inside the middle 72%, so the circle crop every platform uses never cuts it. */
function pm_studio_profile(array $spec, int $size, string $brand = ''): ?GdImage
{
    if (!pm_studio_can_draw()) {
        return null;
    }
    $size = max(64, min(2400, $size));
    $pal = ['bg1' => pm_sd_hex($spec['bg1']), 'bg2' => pm_sd_hex($spec['bg2']), 'accent' => pm_sd_hex($spec['accent']), 'ink' => pm_sd_hex($spec['ink'])];
    $ss = $size <= 1100 ? 2 : 1;
    $S = $size * $ss;
    $r = pm_sd_rng((int)$spec['seed'] + 77);
    $style = isset(PM_SD_PSTYLES[$spec['pstyle'] ?? '']) ? (string)$spec['pstyle'] : 'plate';
    $mono = trim((string)($spec['monogram'] ?? ''));
    $serifB = pm_font(true, true) ?: pm_font(true);
    $mark = $brand !== '' ? pm_sd_logo_mark($brand, $pal['ink'], 360) : null;
    $c = $S / 2;
    $bone = [244, 239, 230];
    $drawMono = function ($im, float $frac, array $col, float $a = 1.0) use ($mono, $serifB, $S, $c) {
        if ($mono === '') {
            return;
        }
        $fs = $S * $frac;
        $tries = 0;
        while (pm_sd_tw($serifB, $fs, $mono) > $S * 0.5 && $tries++ < 30) {
            $fs *= 0.94;
        }
        $b = imagettfbbox($fs, 0, $serifB, $mono);
        $tw = $b[2] - $b[0];
        $th = $b[1] - $b[7];
        imagettftext($im, $fs, 0, (int)round($c - $tw / 2 - $b[0]), (int)round($c + $th / 2 - $b[1]), pm_sd_col($im, $col, $a), $serifB, $mono);
    };
    $markOrMono = function ($im, float $box) use ($mark, $drawMono, $pal, $c) {
        if ($mark) {
            pm_sd_put($im, $mark, $c, $c, $box, $box * 0.92);
        } else {
            $drawMono($im, 0.30, $pal['ink']);
        }
    };
    $flat = function () use ($S, $pal, $r) { // a flat base with two cut-paper pieces in the corners
        $im = pm_sd_field($S, $S, fn($nx, $ny) => pm_sd_mix($pal['bg2'], pm_sd_shade($pal['bg2'], -0.04), $ny));
        pm_sd_poly($im, pm_sd_blob($r, $S * 0.9, $S * 0.1, $S * 0.42, 44, 0.12), pm_sd_col($im, $pal['bg1'], 0.6));
        pm_sd_poly($im, pm_sd_blob($r, $S * 0.08, $S * 0.95, $S * 0.34, 44, 0.12), pm_sd_col($im, $pal['accent'], 0.28));
        return $im;
    };
    if ($style === 'tile') {
        $im = $flat();
        pm_sd_poly($im, [0, $S * 0.64, $S, $S * 0.32, $S, $S, 0, $S], pm_sd_col($im, $pal['accent'], 0.3));
        pm_sd_donut($im, $c, $c, $S * 0.43, $S * 0.425, pm_sd_col($im, $pal['ink'], 0.3));
        $markOrMono($im, $S * 0.46);
    } elseif ($style === 'ring') {
        $im = pm_sd_field($S, $S, fn($nx, $ny) => pm_sd_mix(pm_sd_shade($pal['bg1'], -0.02), $pal['bg1'], $ny));
        pm_sd_donut($im, $c, $c, $S * 0.42, $S * 0.404, pm_sd_col($im, $pal['accent'], 1));
        pm_sd_donut($im, $c, $c, $S * 0.375, $S * 0.371, pm_sd_col($im, $pal['ink'], 0.4));
        $markOrMono($im, $S * 0.4);
    } elseif ($style === 'monogram') {
        $im = $flat();
        pm_sd_donut($im, $c, $c, $S * 0.405, $S * 0.397, pm_sd_col($im, $pal['accent'], 0.95));
        pm_sd_donut($im, $c, $c, $S * 0.37, $S * 0.368, pm_sd_col($im, $pal['ink'], 0.35));
        if ($mono !== '') {
            $drawMono($im, 0.34, $pal['ink']);
        } elseif ($mark) {
            pm_sd_put($im, $mark, $c, $c, $S * 0.44, $S * 0.4);
        }
    } elseif ($style === 'shapes') {
        $im = pm_sd_field($S, $S, fn($nx, $ny) => $pal['bg1']);
        foreach ([[$pal['bg2'], 0.5, 0.52, 0.5], [pm_sd_mix($pal['bg2'], $pal['accent'], 0.5), 0.62, 0.4, 0.3], [$pal['accent'], 0.4, 0.62, 0.22]] as [$col, $fx, $fy, $rr]) {
            pm_sd_piece($im, pm_sd_blob($r, $S * $fx, $S * $fy, $S * $rr, 40, 0.16), $col, 1, $S * 0.012);
        }
        $markOrMono($im, $S * 0.44);
    } else { // plate: the logo on a bone-coloured circle over a flat base
        $im = $flat();
        $plain = $brand !== '' ? pm_sd_logo_plain($brand) : null;
        $transparentLogo = $plain && $mark && (((imagecolorat($plain, 1, 1) >> 24) & 127) > 100);
        if ($plain && !$transparentLogo) { // a logo that is its own tile: shown as that tile, with a soft shadow
            $t = $S * 0.56;
            imagefilledrectangle($im, (int)($c - $t / 2 + $S * 0.012), (int)($c - $t / 2 + $S * 0.02), (int)($c + $t / 2 + $S * 0.012), (int)($c + $t / 2 + $S * 0.02), pm_sd_col($im, [0, 0, 0], 0.22));
            pm_sd_put($im, $plain, $c, $c, $t, $t);
        } else {
            pm_sd_disc($im, $c + $S * 0.012, $c + $S * 0.02, $S * 0.372, pm_sd_col($im, [0, 0, 0], 0.18));
            pm_sd_disc($im, $c, $c, $S * 0.37, pm_sd_col($im, $bone, 1));
            pm_sd_donut($im, $c, $c, $S * 0.405, $S * 0.395, pm_sd_col($im, $pal['accent'], 0.85));
            if ($plain) {
                pm_sd_put($im, $plain, $c, $c, $S * 0.5, $S * 0.46);
            } elseif ($mono !== '') {
                $drawMono($im, 0.30, $pal['bg1']);
            }
        }
    }
    if ($ss > 1) {
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
        $im = $out;
    }
    pm_sd_grain($im, $size, $size, 0.05, (int)$spec['seed']);
    return $im;
}

/** A picture drawn to a file (PNG). Returns the path, or '' when the server cannot draw. Cached by what it shows. */
function pm_studio_file(string $brand, array $spec, string $kind, int $W, int $H, string $align = '', ?array $safe = null, string $to = ''): string
{
    $key = md5(json_encode([PM_SD_VERSION, $kind, $W, $H, $align, $safe, array_diff_key($spec, ['at' => 1, 'by' => 1, '_dropped' => 1]), $kind === 'cover' && ($spec['style'] ?? '') === 'photo' ? @filemtime(pm_hero_path($brand) ?: __FILE__) : 0]));
    $dir = pm_kit_dir($brand) . '/studio';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $f = $dir . '/' . $kind . '-' . substr($key, 0, 16) . '-' . $W . 'x' . $H . '.png';
    if (!is_file($f) || filesize($f) < 200) {
        $im = $kind === 'profile' ? pm_studio_profile($spec, $W, $brand) : pm_studio_cover($spec, $W, $H, $safe, $align, $brand);
        if (!$im) {
            return '';
        }
        imagepng($im, $f, 6);
        if (random_int(1, 25) === 1) { // housekeeping: previews older than a week are drawn again if wanted
            foreach (glob($dir . '/*.png') ?: [] as $old) {
                if (filemtime($old) < time() - 7 * 86400) {
                    @unlink($old);
                }
            }
        }
    }
    if ($to !== '') { // asked for at a fixed place (the branding kit): copy the cached picture there
        return @copy($f, $to) ? $to : '';
    }
    return $f;
}

/** The picture for one of the places in pm_studio_targets() (or any size when $targetKey is ''). */
function pm_studio_for_target(string $brand, array $spec, string $kind, int $W, int $H, string $targetKey = ''): string
{
    if ($kind === 'profile') {
        return pm_studio_file($brand, $spec, 'profile', $W, $W);
    }
    $t = $targetKey !== '' ? (pm_studio_targets()[$targetKey] ?? null) : null;
    $tt = $t ? ['key' => $targetKey, 'safe' => $t[4], 'align' => $t[5]] : pm_studio_target_for($W, $H);
    if ($t && ($t[2] !== $W || $t[3] !== $H)) { // previews are smaller than the real size: the safe area scales with it
        $k = $W / $t[2];
        $tt['safe'] = array_map(fn($v) => (int)round($v * $k), $t[4]);
    }
    return pm_studio_file($brand, $spec, 'cover', $W, $H, (string)($tt['align'] ?? ''), $tt['safe'] ?? null);
}

/* ---------------- drafts, the saved design, history ---------------- */

function pm_studio_store(string $brand): array
{
    return (array)(pm_load('cover_studio', fn() => [])[$brand] ?? []) + ['design' => null, 'drafts' => [], 'history' => []];
}

function pm_studio_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('cover_studio', function (array $all) use ($brand, $fn, &$out) {
        $cur = (array)($all[$brand] ?? []) + ['design' => null, 'drafts' => [], 'history' => []];
        $out = $fn($cur);
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

/** The saved design the business uses everywhere, or null (the classic cover is used). */
function pm_studio_saved(string $brand): ?array
{
    $d = pm_studio_store($brand)['design'];
    return is_array($d) && !empty($d['style']) ? $d : null;
}

/** Keeps a draft and returns its id (the same design always has the same id). */
function pm_studio_put(string $brand, array $spec, string $how = 'you'): string
{
    $spec = array_diff_key($spec, ['_dropped' => 1]);
    $spec['by'] = $how;
    $spec['at'] = date('Y-m-d H:i');
    $id = substr(md5(json_encode(array_diff_key($spec, ['at' => 1, 'by' => 1]))), 0, 12);
    pm_studio_update($brand, function (array $d) use ($id, $spec, $how) {
        $d['drafts'][$id] = $spec;
        $d['history'] = array_slice(array_merge([['id' => $id, 'at' => $spec['at'], 'how' => $how]], array_values(array_filter((array)$d['history'], fn($h) => ($h['id'] ?? '') !== $id))), 0, 40);
        $keep = array_flip(array_column($d['history'], 'id'));
        $d['drafts'] = array_intersect_key($d['drafts'], $keep);
        return $d;
    });
    return $id;
}

/** A draft by id ('saved' = the saved design, 'new' or an unknown id = the starting design). Always a complete spec. */
function pm_studio_get(string $brand, string $id): array
{
    $base = pm_studio_default($brand);
    $st = pm_studio_store($brand);
    $spec = $id === 'saved' ? $st['design'] : ($st['drafts'][$id] ?? null);
    if (!$spec && $id !== 'new') {
        $spec = $st['design'];
    }
    return is_array($spec) ? array_replace($base, array_diff_key($spec, ['_dropped' => 1])) : $base;
}

/** Re-rolls the look in code: another style, other colours, a new seed. The words stay. */
function pm_studio_shuffle(string $brand, array $spec): array
{
    $acc = (string)(pm_settings()['accent_color'] ?? '#17375E');
    $pals = pm_sd_palettes($acc);
    $styles = array_values(array_diff(array_keys(PM_SD_STYLES), [$spec['style']]));
    if (pm_hero_path($brand) === '') {
        $styles = array_values(array_diff($styles, ['photo']));
    }
    $pool = [];
    foreach ($styles as $s) { // the handmade looks come up three times as often as the digital ones
        for ($i = 0, $n = in_array($s, PM_SD_DIGITAL, true) ? 1 : 3; $i < $n; $i++) {
            $pool[] = $s;
        }
    }
    $keys = array_values(array_diff(array_keys($pals), [$spec['pal'] ?? '']));
    $style = $pool[random_int(0, count($pool) - 1)];
    $pk = $keys[random_int(0, count($keys) - 1)];
    $tex = ['paper', 'paper', 'grain', 'none'][random_int(0, 3)];
    $p = $pals[$pk];
    return pm_studio_clean(['style' => $style, 'pal' => $pk, 'bg1' => $p[1], 'bg2' => $p[2], 'accent' => $p[3], 'ink' => $p[4], 'texture' => $tex, 'seed' => random_int(1000, 999999),
        'pstyle' => array_keys(PM_SD_PSTYLES)[random_int(0, 4)]], $spec);
}

/* ---------------- the AI idea: one small call, code draws it ---------------- */

/**
 * Asks the AI for a design: style, four colours, texture, alignment, and the words. Returns [spec, note]. The look is always accepted (after the colour and style checks); the words are kept
 * only if they pass the facts-only guard. Throws when the AI cannot be reached.
 */
/** The art director's rules: what makes a design look made by a person. Used by the one-design idea here and by the Brand team's several-kit round. */
function pm_studio_art_rules(): string
{
    return 'The look must feel made by a person: neo-naturalism, editorial, paper and print. Choose a muted, earthy palette (clay, sage, olive, ochre, ink, bone, sand, rosewood) with a calm base and ONE stronger accent; stay close to the brand colour. '
        . 'Never use purple, indigo, violet, neon, glowing or blue-to-purple gradients, glassmorphism or generic tech blue: they look machine-made. Prefer the styles paper, cutout, terrazzo, weave, dusk, waves or minimal; use a digital style only for a technology business and say why. '
        . 'Give four colours (hex: bg1 base, bg2 second tone, accent one highlight, ink the writing colour with strong contrast on the backgrounds), a texture (paper or grain), and the words. '
        . 'Words: eyebrow (the business name or a 2-3 word label, up to 40 characters), headline (up to 60 characters, what the business does or stands for), subline (up to 100 characters, one plain supporting line), whisper (up to 3 short items such as the website or a town the business serves; contact details only from the facts). '
        . 'Use ONLY what the facts say: no number, price, award, ranking or claim that is not in them, no link, hashtag, emoji or exclamation mark, never "best" or "leading". Reply JSON only.';
}

/**
 * Turns what the AI chose into a design the code can draw: a photo style needs a photo, the look is accepted after the colour and style checks, the words are kept only if they pass the
 * facts-only guard, and colours that look generated are softened. Returns [spec, softened].
 */
function pm_studio_accept(string $brand, array $out, array $base): array
{
    if (isset($out['style']) && $out['style'] === 'photo' && pm_hero_path($brand) === '') {
        $out['style'] = $base['style'] === 'aurora' ? 'dusk' : 'aurora'; // no photo to use
    }
    $out['pal'] = 'ai';
    $spec = pm_studio_clean($out, $base, pm_studio_facts_source($brand, $base));
    $softened = false;
    if (!empty($out['palette']) || !empty($out['bg1'])) { // generated colour choices are made natural: no violet, indigo or neon
        [$spec, $softened] = pm_sd_humanize($spec);
        $spec['ink'] = pm_sd_tohex(pm_sd_ink_for(pm_sd_hex($spec['bg1']), pm_sd_hex($spec['bg2']), pm_sd_hex($spec['ink'])));
    }
    return [$spec, $softened];
}

/**
 * Asks the AI for a design: style, four colours, texture, alignment, and the words. Returns [spec, note]. The look is always accepted (after the colour and style checks); the words are kept
 * only if they pass the facts-only guard. Throws when the AI cannot be reached.
 */
function pm_studio_ai(string $brand, array $base): array
{
    $f = pm_opt_facts($brand);
    $acc = (string)(pm_settings()['accent_color'] ?? '#17375E');
    $system = pm_agents_company_brief('tiny') . "\nYou are the art director for " . $f['name'] . ' (Malawi). You choose the design of its social media profile picture and cover; code draws it, so you only choose. ' . pm_studio_art_rules();
    $user = json_encode(['business' => $f['name'], 'tagline' => $f['tagline'], 'about' => mb_substr($f['about'], 0, 400), 'audience' => mb_substr($f['audience'], 0, 200), 'facts' => array_slice($f['facts'], 0, 6),
            'website' => $f['site'], 'phone' => $f['phone'], 'brand_colour' => $acc, 'styles' => PM_SD_STYLES, 'profile_styles' => PM_SD_PSTYLES, 'textures' => array_keys(PM_SD_TEXTURES),
            'current_style' => $base['style'], 'avoid' => 'repeating the current style'], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"style\":\"\",\"pstyle\":\"\",\"palette\":{\"bg1\":\"#\",\"bg2\":\"#\",\"accent\":\"#\",\"ink\":\"#\"},\"texture\":\"\",\"align\":\"left|center|right\",\"eyebrow\":\"\",\"headline\":\"\",\"subline\":\"\",\"whisper\":[],\"monogram\":\"\",\"why\":\"one sentence\"}";
    $out = pm_agent_json(pm_claude($system, $user, false, 700, 'cheap')); // picks from fixed menus (style, colours, texture) and a few words that the facts guard checks: no need for a stronger model
    if (!is_array($out) || empty($out['style'])) {
        throw new RuntimeException('The AI did not return a design. Try again.');
    }
    [$spec, $softened] = pm_studio_accept($brand, $out, $base);
    $note = trim((string)preg_replace('/\s+/u', ' ', strip_tags(is_scalar($out['why'] ?? null) ? (string)$out['why'] : '')));
    $dropped = $spec['_dropped'];
    $msg = ($note !== '' ? mb_substr($note, 0, 160) . ' ' : '') . ($softened ? '(The colours were softened so they look natural.) ' : '') . ($dropped ? '(Some of its words were not used because they added a claim or a number: ' . implode(', ', $dropped) . '.)' : '');
    return [$spec, trim($msg)];
}

/* ---------------- making it the design everywhere ---------------- */

/** Throws away the kit pictures and draws them again (after the design, the wording or the photo changed). */
function pm_studio_redraw_kit(string $brand): void
{
    foreach (glob(pm_kit_dir($brand) . '/{profile,cover}-*.png', GLOB_BRACE) ?: [] as $old) {
        @unlink($old);
    }
    pm_kit_build($brand);
}

/** Saves a draft as the business's design and draws the kit pictures again from it. Returns [ok, message]. */
function pm_studio_use(string $brand, string $id): array
{
    if (!pm_studio_can_draw()) {
        return [false, 'This server cannot draw pictures (GD with FreeType and the fonts are needed: see Operations > Connections).'];
    }
    $spec = pm_studio_get($brand, $id);
    pm_studio_update($brand, function (array $d) use ($spec) {
        $d['design'] = array_diff_key($spec, ['_dropped' => 1]);
        return $d;
    });
    pm_studio_redraw_kit($brand);
    return [true, 'Saved as the design for every profile picture and cover (' . (PM_SD_STYLES[$spec['style']] ?? 'custom') . '). Download them, or set them on Facebook.'];
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_studio_to(string $id, string $extra = ''): string
{
    return 'social&view=studio&d=' . preg_replace('/[^a-z0-9]/', '', $id) . $extra;
}

function pm_studio_base_id(): string
{
    $d = preg_replace('/[^a-z0-9]/', '', (string)($_POST['d'] ?? ''));
    return $d !== '' ? $d : 'new';
}

/** One handler for every change on the screen: op = shuffle | style | pal | edit | texture. Makes a new draft from the one on screen. */
function pm_do_studio(string $vb): array
{
    $base = pm_studio_get($vb, pm_studio_base_id());
    $op = (string)($_POST['op'] ?? 'edit');
    $in = [];
    if ($op === 'shuffle') {
        $spec = pm_studio_shuffle($vb, $base);
        $id = pm_studio_put($vb, $spec, 'shuffle');
        return ['msg' => 'A new look: ' . PM_SD_STYLES[$spec['style']] . '. Press "Surprise me" again for another, or "Use this design" to keep it.', 'kind' => 'ok', 'to' => pm_studio_to($id)];
    }
    if ($op === 'style') {
        $in = ['style' => (string)($_POST['style'] ?? '')];
    } elseif ($op === 'pstyle') {
        $in = ['pstyle' => (string)($_POST['pstyle'] ?? '')];
    } elseif ($op === 'pal') {
        $pals = pm_sd_palettes((string)(pm_settings()['accent_color'] ?? '#17375E'));
        $k = (string)($_POST['pal'] ?? '');
        if (!isset($pals[$k])) {
            return ['msg' => 'That colour set does not exist.', 'kind' => 'err', 'to' => pm_studio_to(pm_studio_base_id())];
        }
        [, $a, $b, $c, $d] = $pals[$k];
        $in = ['pal' => $k, 'bg1' => $a, 'bg2' => $b, 'accent' => $c, 'ink' => $d];
    } elseif ($op === 'seed') {
        $in = ['seed' => random_int(1000, 999999)];
    } else {
        foreach (['headline', 'subline', 'eyebrow', 'whisper', 'monogram', 'texture', 'align', 'cover_logo'] as $k) {
            if (isset($_POST[$k])) {
                $in[$k] = $_POST[$k];
            }
        }
        foreach (['bg1', 'bg2', 'accent', 'ink'] as $k) {
            if (!empty($_POST['c_' . $k])) {
                $in[$k] = $_POST['c_' . $k];
                $in['pal'] = 'custom';
            }
        }
    }
    $spec = pm_studio_clean($in, $base);
    $id = pm_studio_put($vb, $spec, 'you');
    return ['msg' => 'Updated. The pictures below show it.', 'kind' => 'ok', 'to' => pm_studio_to($id)];
}

function pm_do_studio_ai(string $vb): array
{
    $baseId = pm_studio_base_id();
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) { // (a test stub stands in for the AI key)
        return ['msg' => 'The AI needs a key in .env first. "Surprise me" works without it.', 'kind' => 'err', 'to' => pm_studio_to($baseId)];
    }
    try {
        [$spec, $note] = pm_studio_ai($vb, pm_studio_get($vb, $baseId));
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not make a design: ' . mb_substr($e->getMessage(), 0, 120) . '. Nothing changed.', 'kind' => 'err', 'to' => pm_studio_to($baseId)];
    }
    $id = pm_studio_put($vb, $spec, 'ai');
    return ['msg' => 'New idea from the AI: ' . (PM_SD_STYLES[$spec['style']] ?? '') . '. ' . $note, 'kind' => 'ok', 'to' => pm_studio_to($id)];
}

function pm_do_studio_use(string $vb): array
{
    [$ok, $m] = pm_studio_use($vb, pm_studio_base_id());
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => pm_studio_to(pm_studio_base_id())];
}

/** Uses the draft, then puts the profile picture and cover on the Facebook Page. */
function pm_do_studio_fb(string $vb): array
{
    [$ok, $m] = pm_studio_use($vb, pm_studio_base_id());
    if (!$ok) {
        return ['msg' => $m, 'kind' => 'err', 'to' => pm_studio_to(pm_studio_base_id())];
    }
    $what = (string)($_POST['what'] ?? 'both');
    $msgs = [];
    $bad = false;
    foreach (['profile', 'cover'] as $w) {
        if ($what === 'both' || $what === $w) {
            [$ok2, $m2] = pm_fb_apply($vb, $w);
            $msgs[] = $m2;
            $bad = $bad || !$ok2;
        }
    }
    return ['msg' => implode(' ', $msgs), 'kind' => $bad ? 'err' : 'ok', 'to' => pm_studio_to(pm_studio_base_id())];
}

function pm_do_studio_reset(string $vb): array
{
    pm_studio_update($vb, function (array $d) {
        $d['design'] = null;
        return $d;
    });
    foreach (glob(pm_kit_dir($vb) . '/{profile,cover}-*.png', GLOB_BRACE) ?: [] as $old) {
        @unlink($old);
    }
    pm_kit_build($vb);
    return ['msg' => 'Back to the classic cover. The pictures were made again.', 'kind' => 'ok', 'to' => pm_studio_to('saved')];
}
