<?php
/**
 * The website's look, read by code (no AI): the colours it really uses, light or dark, its fonts, its own headline and description, its photos and its logo.
 * The brand team designs the profile picture and cover from it, so the social profiles look like they belong to the website; the best website photo becomes
 * the hero photo for photo covers (until the owner uploads one), and the owner can pick another on Brand > Brand kit.
 *   pm_site_look($brand, $refresh)   the stored reading (read again weekly, or now with $refresh)
 * Colours: the theme colour, the brand-named CSS variables (primary, brand, accent...) and then how often each colour appears in the CSS, neutrals kept apart.
 * Photos: the share picture, large pictures on the page, CSS backgrounds and, for an app-style site, picture paths in its script; logos, icons and flags are left out,
 * each is downloaded, checked (at least 900 x 450, wider than tall) and kept as a JPEG in data/brandkit/<brand>/site/.
 * Data: data/site_look.json {brand: {at, url, palette: [{hex, w}], base: light|dark, base_hex, fonts, photos: [{file, src, w, h, alt}], logo, title, description, h1, error}}.
 * $GLOBALS['PM_SITE_FETCH_STUB'] (tests) replaces the network: fn(url) => [body, contentType].
 */

const PM_SL_MAX_PHOTOS = 6;

function pm_sl_dir(string $brand): string
{
    $d = PM_DATA . '/brandkit/' . pm_brand_norm($brand) . '/site';
    is_dir($d) || @mkdir($d, 0775, true);
    return $d;
}

/** The business's website address ('' when none). */
function pm_sl_url(string $brand): string
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $u = trim((string)(pm_link_cfg($brand)['url'] ?: (pm_settings()['website'] ?? '')));
    } finally {
        pm_brand_set($prev);
    }
    if ($u === '') {
        return '';
    }
    return preg_match('#^https?://#i', $u) ? $u : 'https://' . ltrim($u, '/');
}

/** One fetch with a browser's headers (some hosts refuse plain clients): [body, content type, http code]. */
function pm_sl_fetch(string $url, int $max = 3000000, int $timeout = 15): array
{
    if (isset($GLOBALS['PM_SITE_FETCH_STUB']) && is_callable($GLOBALS['PM_SITE_FETCH_STUB'])) {
        [$b, $t] = $GLOBALS['PM_SITE_FETCH_STUB']($url) + ['', ''];
        return [(string)$b, (string)$t, $b === '' ? 404 : 200];
    }
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
        return ['', '', 0];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', 'Accept-Language: en-GB,en;q=0.9'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($c, $dlt, $dl) => $dl > $max ? 1 : 0]);
    function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
    $body = (string)curl_exec($ch);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code >= 200 && $code < 300 ? $body : '', $type, $code];
}

/** A link on the page as a full address. */
function pm_sl_abs(string $u, string $base): string
{
    $u = trim(html_entity_decode($u, ENT_QUOTES), " \t\n\"'");
    if ($u === '' || str_starts_with($u, 'data:') || str_starts_with($u, '#')) {
        return '';
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    $p = parse_url($base);
    $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (str_starts_with($u, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $u;
    }
    if (str_starts_with($u, '/')) {
        return $root . $u;
    }
    $dir = preg_replace('#/[^/]*$#', '/', (string)($p['path'] ?? '/')) ?: '/';
    return $root . $dir . $u;
}

/** "#abc" / "#aabbcc" as lower-case "#aabbcc"; '' when it is not a colour. */
function pm_sl_hex(string $h): string
{
    $h = strtolower(ltrim(trim($h), '#'));
    if (strlen($h) === 3 && ctype_xdigit($h)) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    return strlen($h) === 6 && ctype_xdigit($h) ? '#' . $h : '';
}

/**
 * The colours of a page and its CSS, best first: [palette => [{hex, w}] (up to 5 colours with character), base => light|dark, base_hex].
 * Pure, so it can be tested on any text.
 */
function pm_sl_colours(string $html, string $css): array
{
    $w = [];
    $add = function (string $hex, float $n) use (&$w) {
        if (($hex = pm_sl_hex($hex)) !== '') {
            $w[$hex] = ($w[$hex] ?? 0) + $n;
        }
    };
    if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]*content=["\']([^"\']+)/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*name=["\']theme-color/i', $html, $m)) {
        $add($m[1], 30);
    }
    if (preg_match_all('/--[\w-]*(primary|brand|accent|main|secondary|cta|highlight)[\w-]*\s*:\s*(#[0-9a-f]{3,6})\b/i', $css . $html, $mm)) {
        foreach ($mm[2] as $h) {
            $add($h, 12);
        }
    }
    if (preg_match_all('/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/i', $css . ' ' . $html, $mm)) {
        foreach ($mm[0] as $h) {
            $add($h, 1);
        }
    }
    // modern stylesheets (Tailwind 4 and others) name their colours as variables in oklch(): each counts as often as the page uses it
    if (preg_match_all('/--(color-[\w-]+|[\w-]*(?:primary|brand|accent)[\w-]*)\s*:\s*(oklch\([^)]*\)|#[0-9a-f]{3,6}\b)/i', $css, $vm, PREG_SET_ORDER)) {
        foreach ($vm as [, $name, $val]) {
            $hex = str_starts_with(strtolower($val), 'oklch') ? pm_sl_oklch_hex($val) : pm_sl_hex($val);
            $uses = substr_count($css . $html, 'var(--' . $name . ')') + substr_count($html, $name);
            $hex !== '' && $uses > 0 && $add($hex, 2 * $uses + (preg_match('/primary|brand|accent/i', $name) ? 10 : 0));
        }
    }
    $chroma = [];
    $neutral = [];
    foreach ($w as $hex => $n) {
        [$h, $s, $l] = pm_sd_hsl(pm_sd_hex($hex));
        if ($s < 0.15 || $l < 0.07 || $l > 0.96) {
            $neutral[$hex] = $n;
        } else {
            $chroma[$hex] = $n;
        }
    }
    arsort($chroma);
    $pal = [];
    foreach ($chroma as $hex => $n) { // near-identical shades count as one colour
        [$h, , $l] = pm_sd_hsl(pm_sd_hex($hex));
        foreach ($pal as $i => $p) {
            [$ph, , $pl] = pm_sd_hsl(pm_sd_hex($p['hex']));
            if (min(abs($h - $ph), 360 - abs($h - $ph)) < 14 && abs($l - $pl) < 0.14) {
                $pal[$i]['w'] += $n;
                continue 2;
            }
        }
        $pal[] = ['hex' => $hex, 'w' => $n];
        if (count($pal) >= 8) {
            break;
        }
    }
    usort($pal, fn($a, $b) => $b['w'] <=> $a['w']);
    arsort($neutral);
    $lightW = $darkW = 0;
    foreach ($neutral as $hex => $n) {
        pm_sd_hsl(pm_sd_hex($hex))[2] > 0.5 ? $lightW += $n : $darkW += $n;
    }
    $light = $lightW >= $darkW;
    if (preg_match('/(?:^|[}\s,])body\s*\{[^}]*?background(?:-color)?\s*:\s*([^;}]+)/i', $css, $bm)) { // the page's own background settles it
        $bg = trim($bm[1]);
        if (preg_match('/var\(\s*(--[\w-]+)/', $bg, $vn) && preg_match('/' . preg_quote($vn[1], '/') . '\s*:\s*(#[0-9a-f]{3,6}\b|oklch\([^)]*\))/i', $css, $vv)) {
            $bg = $vv[1];
        }
        $bgHex = str_starts_with(strtolower($bg), 'oklch') ? pm_sl_oklch_hex($bg) : pm_sl_hex((string)preg_replace('/\s.*$/', '', $bg));
        if ($bgHex !== '') {
            return ['palette' => array_slice($pal, 0, 5), 'base' => pm_sd_hsl(pm_sd_hex($bgHex))[2] > 0.5 ? 'light' : 'dark', 'base_hex' => $bgHex];
        }
    }
    $baseHex = $light ? '#ffffff' : '#111111';
    foreach ($neutral as $hex => $n) { // the most used neutral on the winning side
        if ((pm_sd_hsl(pm_sd_hex($hex))[2] > 0.5) === $light) {
            $baseHex = $hex;
            break;
        }
    }
    return ['palette' => array_slice($pal, 0, 5), 'base' => $light ? 'light' : 'dark', 'base_hex' => $baseHex];
}

/** "oklch(59.6% .145 163.2)" as "#rrggbb" ('' when it cannot be read). */
function pm_sl_oklch_hex(string $v): string
{
    if (!preg_match('/oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)/i', $v, $m)) {
        return '';
    }
    $L = (float)$m[1] / ($m[2] === '%' ? 100 : 1);
    $C = (float)$m[3];
    $h = deg2rad((float)$m[4]);
    [$a, $b] = [$C * cos($h), $C * sin($h)];
    $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $mm = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
    $lin = [4.0767416621 * $l - 3.3077115913 * $mm + 0.2309699292 * $s, -1.2684380046 * $l + 2.6097574011 * $mm - 0.3413193965 * $s, -0.0041960863 * $l - 0.7034186147 * $mm + 1.7076147010 * $s];
    $rgb = array_map(fn($c) => (int)round(255 * max(0, min(1, $c <= 0.0031308 ? 12.92 * $c : 1.055 * (max(0, $c) ** (1 / 2.4)) - 0.055))), $lin);
    return sprintf('#%02x%02x%02x', ...$rgb);
}

/** The pictures a page offers, best guess first: the share picture, then large pictures on the page, CSS backgrounds, then paths in an app's script. */
function pm_sl_photo_candidates(string $html, string $css, string $js, string $base): array
{
    $out = [];
    $put = function (string $u, string $alt = '') use (&$out, $base) {
        $u = pm_sl_abs($u, $base);
        if ($u === '' || !preg_match('#\.(jpe?g|png|webp)(\?|$)#i', $u) && !preg_match('#/(images?|photos?|uploads?|media|hotels?|rooms?)/#i', $u)) {
            return;
        }
        if (preg_match('#(logo|icon|favicon|sprite|avatar|flag|badge|emoji|placeholder|loader|spinner|payment|visa|mastercard|qr)[^/]*$#i', $u)) {
            return; // not a photo of the business
        }
        $out[$u] ??= $alt;
    };
    foreach (['og:image', 'twitter:image', 'og:image:url'] as $p) {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($p, '/') . '["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
            $alt = preg_match('/<meta[^>]+property=["\']og:image:alt["\'][^>]*content=["\']([^"\']+)/i', $html, $am) ? html_entity_decode($am[1]) : '';
            $put($m[1], $alt);
        }
    }
    if (preg_match_all('/<img\b[^>]*>/i', $html, $im)) {
        foreach ($im[0] as $tag) {
            $alt = preg_match('/\balt=(["\'])(.*?)\1/i', $tag, $a) ? html_entity_decode($a[2], ENT_QUOTES) : ''; // an apostrophe inside double quotes stays
            if (preg_match('/\bclass=["\'][^"\']*logo/i', $tag)) {
                continue;
            }
            if (preg_match('/\bsrcset=["\']([^"\']+)/i', $tag, $ss)) { // the largest of the set
                $best = '';
                $bw = 0;
                foreach (explode(',', $ss[1]) as $part) {
                    [$u, $d] = array_pad(preg_split('/\s+/', trim($part)), 2, '');
                    $wv = (int)$d;
                    if ($wv >= $bw) {
                        [$best, $bw] = [$u, $wv];
                    }
                }
                $best !== '' && $put($best, $alt);
            }
            foreach (['data-src', 'data-lazy-src', 'src'] as $att) {
                if (preg_match('/\b' . $att . '=["\']([^"\']+)/i', $tag, $s)) {
                    $put($s[1], $alt);
                    break;
                }
            }
        }
    }
    if (preg_match_all('/url\(\s*["\']?([^"\')]+\.(?:jpe?g|png|webp))["\']?\s*\)/i', $html . ' ' . $css, $bm)) {
        foreach ($bm[1] as $u) {
            $put($u);
        }
    }
    if ($js !== '' && preg_match_all('#["\'`](/?(?:[\w-]+/)*[\w.-]+\.(?:jpe?g|webp))["\'`]#i', $js, $jm)) { // an app-style site keeps its pictures in its script
        foreach (array_slice(array_unique($jm[1]), 0, 40) as $u) {
            $put($u);
        }
    }
    return array_slice($out, 0, 30, true);
}

/** The logo the website shows: an image named or classed as a logo, else the touch icon, else a PNG icon; '' when none. */
function pm_sl_logo_candidate(string $html, string $base): string
{
    if (preg_match_all('/<img\b[^>]*>/i', $html, $im)) {
        foreach ($im[0] as $tag) {
            if (preg_match('/logo/i', $tag) && preg_match('/\bsrc=["\']([^"\']+\.(?:png|jpe?g|webp))["\']/i', $tag, $s)) {
                return pm_sl_abs($s[1], $base);
            }
        }
    }
    foreach (['apple-touch-icon', 'icon'] as $rel) {
        if (preg_match_all('/<link[^>]+rel=["\'][^"\']*' . $rel . '[^"\']*["\'][^>]*>/i', $html, $lm)) {
            foreach ($lm[0] as $tag) {
                if (preg_match('/href=["\']([^"\']+\.png)["\']/i', $tag, $h)) {
                    return pm_sl_abs($h[1], $base);
                }
            }
        }
    }
    return '';
}

/**
 * Reads the website now: colours, fonts, words, photos and logo. Saved and returned. Never throws: a site that cannot be read is recorded with its error,
 * keeping what was read before.
 */
function pm_site_look_read(string $brand): array
{
    $url = pm_sl_url($brand);
    $old = (array)(pm_load('site_look', fn() => [])[pm_brand_norm($brand)] ?? []);
    $rec = ['at' => date('Y-m-d H:i'), 'url' => $url, 'error' => ''];
    if ($url === '') {
        $rec['error'] = 'No website address is set for this business (Operations > Business details).';
    } else {
        [$html, , $code] = pm_sl_fetch($url);
        if ($html === '') {
            $rec['error'] = 'The website did not open for us' . ($code ? ' (answer ' . $code . ')' : '') . '.';
        } else {
            $css = '';
            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $sm)) {
                $css .= implode("\n", $sm[1]);
            }
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>|<link[^>]+href=["\'][^"\']+\.css[^"\']*["\'][^>]*>/i', $html, $lm)) {
                $n = 0;
                foreach ($lm[0] as $tag) {
                    if ($n >= 5 || !preg_match('/href=["\']([^"\']+)/i', $tag, $h)) {
                        continue;
                    }
                    $cu = pm_sl_abs($h[1], $url);
                    $ch = strtolower((string)parse_url($cu, PHP_URL_HOST));
                    if ($cu === '' || ($ch !== $host && !str_ends_with($ch, '.' . $host) && !str_ends_with($host, '.' . $ch))) {
                        continue; // only the site's own styles: not font or icon libraries
                    }
                    $css .= "\n" . pm_sl_fetch($cu, 1500000, 10)[0];
                    $n++;
                }
            }
            $js = '';
            if (strlen(trim(strip_tags($html))) < 1500 && preg_match('/<script[^>]+type=["\']module["\'][^>]+src=["\']([^"\']+)/i', $html, $jm)) {
                $js = pm_sl_fetch(pm_sl_abs($jm[1], $url), 4000000, 15)[0]; // an app-style site: its words and pictures are in its script
            }
            $rec += pm_sl_colours($html, $css);
            $fonts = [];
            if (preg_match_all('/fonts\.googleapis\.com\/css2?\?([^"\']+)/i', $html, $fm)) {
                foreach ($fm[1] as $q) {
                    preg_match_all('/family=([^:&]+)/', html_entity_decode($q), $ff);
                    foreach ($ff[1] as $f) {
                        $fonts[] = str_replace('+', ' ', urldecode($f));
                    }
                }
            }
            $rec['fonts'] = array_values(array_unique(array_slice($fonts, 0, 4)));
            $txt = fn(string $re) => preg_match($re, $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : '';
            $rec['title'] = mb_substr($txt('/<title[^>]*>(.*?)<\/title>/is'), 0, 140);
            $rec['description'] = mb_substr($txt('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']+)/i') ?: $txt('/<meta[^>]+property=["\']og:description["\'][^>]*content=["\']([^"\']+)/i'), 0, 300);
            $rec['h1'] = mb_substr(trim((string)preg_replace('/\s+/', ' ', $txt('/<h1[^>]*>(.*?)<\/h1>/is'))), 0, 140);
            // the photos: downloaded, checked, kept as JPEG
            $dir = pm_sl_dir($brand);
            foreach (glob($dir . '/photo-*.jpg') ?: [] as $f) {
                @unlink($f);
            }
            $photos = [];
            $tries = 0;
            foreach (pm_sl_photo_candidates($html, $css, $js, $url) as $src => $alt) {
                if (count($photos) >= PM_SL_MAX_PHOTOS || $tries >= 14) {
                    break;
                }
                $tries++;
                [$img] = pm_sl_fetch($src, 8000000, 12);
                $info = $img !== '' ? @getimagesizefromstring($img) : false;
                if (!$info || $info[0] < 900 || $info[1] < 450 || $info[0] < $info[1] * 1.15) {
                    continue; // too small, or taller than wide: no cover
                }
                $gd = @imagecreatefromstring($img);
                if (!$gd) {
                    continue;
                }
                $file = $dir . '/photo-' . (count($photos) + 1) . '.jpg';
                imagejpeg($gd, $file, 88);
                imagedestroy($gd);
                $photos[] = ['file' => basename($file), 'src' => $src, 'w' => (int)$info[0], 'h' => (int)$info[1], 'alt' => mb_substr((string)$alt, 0, 120)];
            }
            $rec['photos'] = $photos;
            $rec['logo'] = '';
            if (($lu = pm_sl_logo_candidate($html, $url)) !== '') {
                [$li] = pm_sl_fetch($lu, 3000000, 10);
                $gd = $li !== '' ? @imagecreatefromstring($li) : false;
                if ($gd && imagesx($gd) >= 64) {
                    imagesavealpha($gd, true);
                    imagepng($gd, $dir . '/logo.png');
                    $rec['logo'] = 'logo.png';
                }
                $gd && imagedestroy($gd);
            }
        }
    }
    if ($rec['error'] !== '') {
        $rec = ['at' => $rec['at'], 'url' => $url, 'error' => $rec['error']] + $old; // keep what was read before
    }
    pm_update('site_look', function (array $all) use ($brand, $rec) {
        $all[pm_brand_norm($brand)] = $rec;
        return $all;
    }, fn() => []);
    return $rec;
}

/** The website's look as stored, read again when it is over a week old (or now with $refresh). [] when there is no website. */
function pm_site_look(string $brand, bool $refresh = false): array
{
    $cur = (array)(pm_load('site_look', fn() => [])[pm_brand_norm($brand)] ?? []);
    $stale = empty($cur['at']) || strtotime((string)$cur['at']) < time() - 7 * 86400 || (string)($cur['url'] ?? '') !== pm_sl_url($brand);
    if ($refresh || ($stale && empty($GLOBALS['PM_SITE_LOOK_NO_FETCH']))) {
        $cur = pm_site_look_read($brand);
    }
    return $cur;
}

/** The stored reading only (never fetches): for pages that must stay instant. */
function pm_site_look_cached(string $brand): array
{
    return (array)(pm_load('site_look', fn() => [])[pm_brand_norm($brand)] ?? []);
}

/** The best website photo as a file path ('' when none): the hero for photo covers until the owner uploads one. */
function pm_site_photo(string $brand, int $i = 0): string
{
    $p = (array)(pm_site_look_cached($brand)['photos'] ?? []);
    $f = isset($p[$i]['file']) ? pm_sl_dir($brand) . '/' . basename((string)$p[$i]['file']) : '';
    return $f !== '' && is_file($f) ? $f : '';
}

/** The website's look in one line for a designer's brief. */
function pm_site_look_line(string $brand): array
{
    $l = pm_site_look_cached($brand);
    if (empty($l['palette']) && empty($l['photos'])) {
        return [];
    }
    return ['colours_best_first' => array_column((array)($l['palette'] ?? []), 'hex'), 'background' => ($l['base'] ?? 'light') . ' (' . ($l['base_hex'] ?? '') . ')', 'fonts' => (array)($l['fonts'] ?? []),
        'its_headline' => (string)($l['h1'] ?? ''), 'its_title' => (string)($l['title'] ?? ''), 'photos_from_the_website' => count((array)($l['photos'] ?? []))];
}

/* ---------------- Brand > Brand kit ---------------- */

/** "Read the website again". */
function pm_do_site_look(string $vb): array
{
    @set_time_limit(120);
    $r = pm_site_look($vb, true);
    $n = count((array)($r['photos'] ?? []));
    return ['msg' => $r['error'] !== '' ? 'The website could not be read: ' . $r['error'] : 'Read the website: ' . count((array)($r['palette'] ?? [])) . ' brand colours, ' . $n . ' photo' . ($n === 1 ? '' : 's') . ' good enough for a cover' . (!empty($r['fonts']) ? ', fonts ' . implode(' and ', $r['fonts']) : '') . '. The brand team uses them next time it makes a set.',
        'kind' => $r['error'] !== '' ? 'err' : 'ok', 'to' => 'social&view=kit#site'];
}

/** "Use as the hero photo": a website photo becomes the brand's hero photo (assets/<brand>_hero.jpg), used by photo covers. */
function pm_do_site_hero(string $vb): array
{
    $i = max(0, (int)($_POST['i'] ?? 0));
    $f = pm_site_photo($vb, $i);
    if ($f === '') {
        return ['msg' => 'That photo is not there any more: read the website again.', 'kind' => 'err', 'to' => 'social&view=kit#site'];
    }
    foreach (['jpg', 'png'] as $x) {
        @unlink(PM_ROOT . '/assets/' . pm_brand_norm($vb) . "_hero.$x");
    }
    copy($f, PM_ROOT . '/assets/' . pm_brand_norm($vb) . '_hero.jpg');
    return ['msg' => 'That website photo is now the hero photo: the "Your photo" covers use it. Press "Make the pictures and covers" to draw them again.', 'kind' => 'ok', 'to' => 'social&view=kit#site'];
}
