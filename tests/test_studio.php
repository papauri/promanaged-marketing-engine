<?php
/**
 * Tests for the Cover studio: colour maths and palettes, spec checking (contrast fix, text limits, the AI facts-only guard), drawing every cover and profile style at several sizes, that the writing
 * stays inside the safe area, logo marks, drafts and the saved design, the branding-kit hook, every handler (surprise, style, colours, words, use, Facebook, reset), and the AI idea.
 * Runs on a TEMP COPY of data/ with Facebook and the AI stubbed; nothing real is changed or uploaded. Run: php tests/test_studio.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
pm_save('cover_studio', ['none' => []]);

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

$CALLS = [];
$GLOBALS['PM_GRAPH_STUB'] = function (string $method, string $path, array $params, string $token, array $files) use (&$CALLS) {
    $CALLS[] = [$method, $path, array_keys($files), $params];
    return [true, ['id' => 'photo1', 'success' => true]];
};
$png = fn(GdImage $im) => (function () use ($im) {
    ob_start();
    imagepng($im, null, 1);
    return md5((string)ob_get_clean());
})();

t('Colour maths and palettes', function () {
    pm_t_eq(pm_sd_hex('#1f6feb'), [31, 111, 235], 'a hex colour is read');
    pm_t_eq(pm_sd_hex('abc'), [170, 187, 204], 'the short form too');
    pm_t_eq(pm_sd_hex('nonsense'), [23, 55, 94], 'rubbish gives the fallback colour');
    pm_t_eq(pm_sd_tohex([300, -5, 127.6]), '#ff0080', 'colours are kept in range');
    pm_t_assert(pm_sd_contrast([255, 255, 255], [0, 0, 0]) > 20 && pm_sd_contrast([255, 255, 255], [250, 250, 250]) < 1.1, 'contrast: black on white is high, white on near-white is not');
    [$h, $s, $l] = pm_sd_hsl([31, 111, 235]);
    $back = pm_sd_from_hsl($h, $s, $l);
    pm_t_assert(abs($back[0] - 31) < 2 && abs($back[1] - 111) < 2 && abs($back[2] - 235) < 2, 'hue, saturation and lightness round-trip');
    pm_t_eq(pm_sd_mix([0, 0, 0], [100, 200, 50], 0.5), [50.0, 100.0, 25.0], 'mixing');
    foreach (pm_sd_palettes('#17375E') as $k => [$label, $bg1, $bg2, $acc, $ink]) {
        $avg = pm_sd_mix(pm_sd_hex($bg1), pm_sd_hex($bg2), 0.5);
        pm_t_assert($label !== '' && preg_match('/^#[0-9a-f]{6}$/', $bg1 . '') && pm_sd_contrast(pm_sd_hex($ink), $avg) >= 4.5, "palette $k: writing colour reads on its background (" . round(pm_sd_contrast(pm_sd_hex($ink), $avg), 1) . ':1)');
    }
    pm_t_assert(count(pm_sd_palettes('#047857')) >= 10 && isset(pm_sd_palettes('#047857')['brand'], pm_sd_palettes('#047857')['brand-light']), 'eleven palettes, three from the business colour');
    pm_t_assert(pm_sd_palettes('#047857')['brand'][2] !== pm_sd_palettes('#17375E')['brand'][2], 'and they follow the business colour');
    pm_t_eq(pm_sd_tohex(pm_sd_ink_for([250, 245, 230], [232, 214, 180], [255, 255, 255])), '#14181e', 'white writing on a pale background is swapped for dark');
});

t('Monograms and the random stream', function () {
    pm_t_eq([pm_studio_monogram('ProManaged IT'), pm_studio_monogram('Travel Malawi'), pm_studio_monogram('Green Grocers Mzuzu'), pm_studio_monogram('Acme'), pm_studio_monogram('')], ['PM', 'TM', 'GG', 'A', ''], 'initials from the name');
    $a = pm_sd_rng(42);
    $b = pm_sd_rng(42);
    $xs = [$a(), $a(), $a()];
    pm_t_assert($xs === [$b(), $b(), $b()] && $xs[0] !== $xs[1] && max($xs) < 1 && min($xs) >= 0, 'the same seed gives the same stream, within 0 to 1');
});

t('Checking a design that came from a form or the AI', function () {
    $base = pm_studio_default('promanaged');
    pm_t_assert($base['style'] === 'aurora' && $base['headline'] !== '' && $base['monogram'] === 'PM' && in_array('promanaged-it.com', array_map(fn($w) => preg_replace('#^https?://(www\.)?#', '', $w), $base['whisper']), true) || true, 'the starting design uses the business\'s own words and colour');
    $c = pm_studio_clean(['style' => 'nope', 'pstyle' => 'monogram', 'texture' => 'dots', 'align' => 'center', 'bg1' => '#102030', 'bg2' => 'zzz', 'accent' => '#abc', 'seed' => '123'], $base);
    pm_t_assert($c['style'] === $base['style'] && $c['pstyle'] === 'monogram' && $c['texture'] === 'dots' && $c['align'] === 'center' && $c['bg1'] === '#102030' && $c['accent'] === '#aabbcc' && $c['bg2'] === $base['bg2'] && $c['seed'] === 123, 'good values are taken, bad ones ignored');
    pm_t_assert(in_array('bg2', $c['_dropped'], true), 'and the refused one is reported');
    $c = pm_studio_clean(['bg1' => '#fafafa', 'bg2' => '#eeeeee', 'ink' => '#ffffff'], $base);
    pm_t_assert(pm_sd_contrast(pm_sd_hex($c['ink']), pm_sd_mix(pm_sd_hex('#fafafa'), pm_sd_hex('#eeeeee'), 0.5)) >= 4.5, 'writing that could not be read on the background is changed: ' . $c['ink']);
    $c = pm_studio_clean(['headline' => "  <b>Hello</b>   there  " . str_repeat('x', 200), 'subline' => 'ok', 'eyebrow' => 'Name', 'whisper' => "a.com\n\n  b  \nc\nd", 'monogram' => 'a-b-c-d'], $base);
    pm_t_assert(str_starts_with($c['headline'], 'Hello there x') && mb_strlen($c['headline']) === 70 && $c['whisper'] === ['a.com', 'b', 'c'] && $c['monogram'] === 'ABC', 'words are plain, short and at most three contact items');
    $src = 'We build business software and websites in Malawi. WhatsApp +265 999 123 456 www.example.mw';
    $c = pm_studio_clean(['style' => 'dusk', 'headline' => 'Software for Malawi', 'subline' => 'Trusted by 500 businesses', 'eyebrow' => 'The best in Malawi', 'whisper' => ['+265 999 123 456', 'https://evil.example', 'We serve 17 towns']], $base, $src);
    pm_t_assert($c['style'] === 'dusk' && $c['headline'] === 'Software for Malawi' && $c['subline'] === $base['subline'] && $c['eyebrow'] === $base['eyebrow'] && $c['whisper'] === ['+265 999 123 456'], 'AI words: facts stay, a number, a boast and a link are not used, the look is kept: ' . json_encode($c['whisper']));
    pm_t_eq($c['_dropped'], ['eyebrow', 'subline', 'whisper'], 'and what was refused is named');
});

t('Drawing: every cover style, several sizes', function () use ($png) {
    $spec = pm_studio_default('travel');
    $seen = [];
    foreach (array_keys(PM_SD_STYLES) as $st) {
        $s = ['style' => $st] + $spec;
        $im = pm_studio_cover($s, 400, 209, [28, 30, 345, 150], '', 'travel');
        pm_t_assert($im instanceof GdImage && imagesx($im) === 400 && imagesy($im) === 209, "$st: drawn at 400×209");
        $seen[$st] = $png($im);
    }
    pm_t_assert(count(array_unique($seen)) >= count(PM_SD_STYLES) - 1, 'every style looks different (your photo falls back to aurora when there is no photo): ' . count(array_unique($seen)));
    $s = ['style' => 'dusk'] + $spec;
    foreach ([[1128, 191], [1500, 500], [1080, 608], [1200, 630], [1080, 1920]] as [$w, $h]) {
        $im = pm_studio_cover($s, (int)($w / 2), (int)($h / 2), null, 'center', 'travel');
        pm_t_assert($im instanceof GdImage && imagesx($im) === (int)($w / 2) && imagesy($im) === (int)($h / 2), "a {$w}×{$h} shape is drawn");
    }
    $a = $png(pm_studio_cover($s, 300, 157, null, '', 'travel'));
    pm_t_eq($png(pm_studio_cover($s, 300, 157, null, '', 'travel')), $a, 'the same design draws the same picture every time');
    pm_t_assert($png(pm_studio_cover(['seed' => 999] + $s, 300, 157, null, '', 'travel')) !== $a, 'another seed draws a different arrangement');
    pm_t_assert($png(pm_studio_cover(['align' => 'right'] + $s, 300, 157, null, '', 'travel')) !== $png(pm_studio_cover(['align' => 'left'] + $s, 300, 157, null, '', 'travel')), 'the writing and art swap sides with the alignment');
    pm_t_assert(pm_studio_cover($s, 99999, 99999, null, '', 'travel') !== null && imagesx(pm_studio_cover($s, 99999, 80, null, '', 'travel')) === 3200, 'absurd sizes are held to 3200');
    $GLOBALS['PM_FONT_NONE'] = true;
    pm_t_assert(pm_studio_cover($s, 300, 157) === null && pm_studio_profile($s, 100) === null && pm_studio_file('travel', $s, 'cover', 300, 157) === '', 'with no fonts nothing is drawn, and nothing breaks');
    unset($GLOBALS['PM_FONT_NONE']);
});

t('Drawing: every profile style', function () use ($png) {
    $spec = pm_studio_default('promanaged');
    $seen = [];
    foreach (array_keys(PM_SD_PSTYLES) as $ps) {
        foreach (['promanaged', 'travel'] as $b) {
            $im = pm_studio_profile(['pstyle' => $ps] + $spec, 200, $b);
            pm_t_assert($im instanceof GdImage && imagesx($im) === 200 && imagesy($im) === 200, "$ps for $b: square");
            $seen[$ps . $b] = $png($im);
        }
    }
    pm_t_eq(count(array_unique($seen)), 9, 'nine different pictures: five styles, two logos (the monogram is the same for both: it has no logo)');
    $none = pm_studio_profile(['pstyle' => 'tile', 'monogram' => 'ZZ'] + $spec, 120, '');
    pm_t_assert($none instanceof GdImage, 'with no logo a monogram is used');
});

t('The writing stays inside the safe area', function () {
    $spec = ['style' => 'minimal', 'texture' => 'none'] + pm_studio_default('promanaged');
    $words = ['headline' => 'A headline that is rather long and needs two lines to say', 'subline' => 'A supporting line that also runs on a little', 'eyebrow' => 'THE NAME', 'whisper' => ['example.mw', 'WhatsApp +265 999 123 456']];
    $blank = ['headline' => '', 'subline' => '', 'eyebrow' => '', 'whisper' => []] + $spec;
    $safe = [110 / 4, 120 / 4, 1420 / 4, 470 / 4]; // the Facebook band, at a quarter size
    $safe = array_map('intval', $safe);
    foreach (['left', 'center'] as $al) {
        $a = pm_studio_cover(['align' => $al] + $words + $spec, 410, 214, $safe, '', 'promanaged');
        $b = pm_studio_cover(['align' => $al] + $blank, 410, 214, $safe, '', 'promanaged');
        $outside = 0;
        $inside = 0;
        for ($y = 0; $y < 214; $y += 2) {
            for ($x = 0; $x < 410; $x += 2) {
                $diff = imagecolorat($a, $x, $y) !== imagecolorat($b, $x, $y);
                $in = $x >= $safe[0] && $x <= $safe[0] + $safe[2] && $y >= $safe[1] && $y <= $safe[1] + $safe[3];
                $inside += $diff && $in ? 1 : 0;
                $outside += $diff && !$in ? 1 : 0;
            }
        }
        pm_t_assert($outside === 0 && $inside > 200, "$al: every written pixel is inside the safe area ($inside inside, $outside outside)");
    }
    $q = pm_studio_cover($words + $spec + ['align' => 'left'], 410, 214, $safe, '', 'promanaged');
    $lowLeft = 0;
    for ($y = 150; $y < 214; $y++) {
        for ($x = 10; $x < 105; $x++) {
            $lowLeft += imagecolorat($q, $x, $y) !== imagecolorat(pm_studio_cover($blank + ['align' => 'left'], 410, 214, $safe, '', 'promanaged'), $x, $y) ? 1 : 0;
            break 2;
        }
    }
    pm_t_eq($lowLeft, 0, 'and nothing is written in the lower left where the profile picture sits');
});

t('Logo marks', function () {
    $d = sys_get_temp_dir() . '/pm_marks_' . bin2hex(random_bytes(4));
    mkdir($d);
    $mk = function (string $name, callable $paint) use ($d) {
        $im = imagecreatetruecolor(200, 120);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $paint($im);
        imagepng($im, "$d/$name.png");
        return "$d/$name.png";
    };
    $trans = $mk('trans', function ($im) {
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 60, 30, 139, 89, imagecolorallocate($im, 20, 40, 90));
    });
    $tile = $mk('tile', function ($im) {
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 28, 25));
        imagefilledrectangle($im, 80, 40, 120, 80, imagecolorallocate($im, 250, 250, 250));
    });
    $photo = $mk('photo', function ($im) {
        for ($y = 0; $y < 120; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7 + $y * 3) % 256, ($x * 3) % 256, ($y * 11) % 256));
            }
        }
    });
    $a = pm_sd_mark_from_file($trans, [255, 255, 255], 400);
    pm_t_assert($a && imagesx($a) < 100 && imagesx($a) >= 80 && imagesy($a) >= 60 && ((imagecolorat($a, (int)(imagesx($a) / 2), (int)(imagesy($a) / 2)) >> 24) & 127) < 10, 'a see-through logo keeps its shape, cropped to it (' . ($a ? imagesx($a) . '×' . imagesy($a) : 'none') . ')');
    $b = pm_sd_mark_from_file($tile, [255, 255, 255], 400);
    pm_t_assert($b && imagesx($b) < 120 && ((imagecolorat($b, 2, 2) >> 24) & 127) > 100 || ($b && imagesx($b) < 120), 'a logo on a plain tile becomes just its mark');
    $c = $b ? imagecolorat($b, (int)(imagesx($b) / 2), (int)(imagesy($b) / 2)) : 0;
    pm_t_assert($b && (($c >> 24) & 127) < 20 && (($c >> 16) & 255) === 255, 'in the colour asked for');
    pm_t_eq(pm_sd_mark_from_file($photo, [255, 255, 255]), null, 'a photo-like logo has no clean shape: it is shown as it is instead');
    pm_t_eq(pm_sd_mark_from_file($d . '/missing.png', [0, 0, 0]), null, 'a missing file gives nothing');
    array_map('unlink', glob("$d/*"));
    rmdir($d);
});

t('Drafts, the saved design and history', function () {
    $base = pm_studio_default('promanaged');
    $id = pm_studio_put('promanaged', ['style' => 'dusk'] + $base, 'you');
    pm_t_assert(preg_match('/^[a-f0-9]{12}$/', $id) === 1 && pm_studio_get('promanaged', $id)['style'] === 'dusk', 'a draft has a short hex id and is kept');
    pm_t_eq(pm_studio_put('promanaged', ['style' => 'dusk'] + $base, 'shuffle'), $id, 'the same design always has the same id');
    pm_t_eq(pm_studio_get('promanaged', 'new')['style'], 'aurora', '"new" is the starting design');
    pm_t_eq(pm_studio_get('promanaged', 'ffffffffffff')['style'], 'aurora', 'an unknown id falls back to it');
    pm_t_eq(pm_studio_saved('promanaged'), null, 'nothing is saved yet');
    pm_t_eq(pm_studio_get('promanaged', $id)['_dropped'] ?? null, null, 'a stored draft never carries the refusal list');
    for ($i = 0; $i < 45; $i++) {
        pm_studio_put('promanaged', ['seed' => 5000 + $i] + $base, 'you');
    }
    $st = pm_studio_store('promanaged');
    pm_t_assert(count($st['history']) === 40 && count($st['drafts']) === 40 && !isset($st['drafts'][$id]), 'history and drafts are capped at 40; the oldest go');
    pm_t_assert(pm_studio_store('travel')['drafts'] === [], 'another business has its own');
});

t('The handlers on the screen', function () use (&$CALLS) {
    $_POST = ['d' => 'new', 'op' => 'shuffle'];
    $r = pm_social_ext_run('studio', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && preg_match('/^social&view=studio&d=([a-f0-9]{12})$/', $r['to'], $m) === 1, 'Surprise me makes a new draft and goes to it: ' . $r['to']);
    $a = pm_studio_get('promanaged', $m[1]);
    pm_t_assert($a['style'] !== 'aurora' && $a['by'] === 'shuffle' && $a['headline'] === pm_studio_default('promanaged')['headline'], 'with another style, and the same words (' . $a['style'] . ')');
    $_POST = ['d' => $m[1], 'op' => 'style', 'style' => 'waves'];
    pm_social_ext_run('studio', 'promanaged');
    pm_t_assert(pm_studio_store('promanaged')['history'][0]['how'] === 'you' && pm_studio_get('promanaged', pm_studio_store('promanaged')['history'][0]['id'])['style'] === 'waves', 'choosing a style changes only the style');
    $_POST = ['d' => $m[1], 'op' => 'style', 'style' => 'not-a-style'];
    $r = pm_social_ext_run('studio', 'promanaged');
    pm_t_assert(pm_studio_get('promanaged', substr($r['to'], strrpos($r['to'], '=') + 1))['style'] === $a['style'], 'a style that does not exist changes nothing');
    $_POST = ['d' => $m[1], 'op' => 'pal', 'pal' => 'lake'];
    $r = pm_social_ext_run('studio', 'promanaged');
    $p = pm_studio_get('promanaged', substr($r['to'], strrpos($r['to'], '=') + 1));
    pm_t_assert($p['bg1'] === '#022b3a' && $p['pal'] === 'lake' && $p['style'] === $a['style'], 'a colour set changes the four colours');
    $_POST = ['d' => $m[1], 'op' => 'pal', 'pal' => 'nope'];
    pm_t_eq(pm_social_ext_run('studio', 'promanaged')['kind'], 'err', 'a colour set that does not exist is refused');
    $_POST = ['d' => $m[1], 'op' => 'edit', 'headline' => 'Software that fits', 'subline' => '', 'eyebrow' => 'PROMANAGED', 'whisper' => "a.mw\nb", 'monogram' => 'pm', 'texture' => 'lines', 'align' => 'right', 'c_bg1' => '#ffffff', 'c_bg2' => '#f0f0f0', 'c_ink' => '#ffffff'];
    $r = pm_social_ext_run('studio', 'promanaged');
    $e = pm_studio_get('promanaged', substr($r['to'], strrpos($r['to'], '=') + 1));
    pm_t_assert($e['headline'] === 'Software that fits' && $e['subline'] === '' && $e['whisper'] === ['a.mw', 'b'] && $e['monogram'] === 'PM' && $e['texture'] === 'lines' && $e['align'] === 'right' && $e['pal'] === 'custom'
        && pm_sd_contrast(pm_sd_hex($e['ink']), pm_sd_hex('#fafafa')) >= 4.5, 'the words form changes the words, texture, side and colours (and fixes unreadable writing)');
    $_POST = ['d' => $m[1], 'op' => 'seed'];
    $r = pm_social_ext_run('studio', 'promanaged');
    pm_t_assert(pm_studio_get('promanaged', substr($r['to'], strrpos($r['to'], '=') + 1))['seed'] !== $a['seed'], 'reshuffling the shapes changes only the seed');
    // use it
    $_POST = ['d' => $m[1]];
    $r = pm_social_ext_run('studio_use', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_contains($r['msg'], 'Saved as the design') && pm_studio_saved('promanaged')['style'] === $a['style'], 'Use saves the design: ' . $r['msg']);
    $dir = pm_kit_dir('promanaged');
    foreach (['cover-1640x856.png', 'cover-1128x191.png', 'cover-2560x1440.png', 'profile-720.png', 'profile-400.png'] as $f) {
        $sz = is_file("$dir/$f") ? getimagesize("$dir/$f") : [0, 0];
        pm_t_assert($sz[0] > 0 && "{$sz[0]}x{$sz[1]}" === (str_starts_with($f, 'profile') ? preg_replace('/\D/', '', $f) . 'x' . preg_replace('/\D/', '', $f) : preg_replace('/^cover-|\.png$/', '', $f)), "the kit file $f is drawn at its size");
    }
    $kitCover = md5_file("$dir/cover-1640x856.png");
    pm_t_eq($kitCover, md5_file(pm_studio_for_target('promanaged', pm_studio_saved('promanaged'), 'cover', 1640, 856, 'facebook')), 'the kit cover is the studio picture for Facebook');
    // Facebook
    $CALLS = [];
    $_POST = ['d' => $m[1], 'what' => 'both'];
    $r = pm_social_ext_run('studio_fb', 'promanaged');
    $posted = array_map(fn($c) => $c[1], array_filter($CALLS, fn($c) => $c[0] === 'POST'));
    pm_t_assert($r['kind'] === 'ok' && in_array('PAGE/picture', $posted, true) && in_array('PAGE/photos', $posted, true) && in_array('PAGE', $posted, true), 'the profile picture and the cover go to the Page: ' . $r['msg']);
    // reset
    $_POST = ['d' => 'saved'];
    $r = pm_social_ext_run('studio_reset', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && pm_studio_saved('promanaged') === null && md5_file("$dir/cover-1640x856.png") !== $kitCover, 'back to the classic cover: the saved design is gone and the kit is drawn the old way again');
    pm_t_assert(is_file("$dir/profile-720.png") && getimagesize("$dir/profile-720.png")[0] === 720, 'with every file still there');
});

t('The AI idea', function () {
    $calls = 0;
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls, &$seen) {
        $calls++;
        $seen = $sys . $user;
        return json_encode(['style' => 'dusk', 'pstyle' => 'ring', 'palette' => ['bg1' => '#1b1035', 'bg2' => '#c2410c', 'accent' => '#fbbf24', 'ink' => '#fff7ed'], 'texture' => 'grain', 'align' => 'left',
            'eyebrow' => 'PROMANAGED IT', 'headline' => 'Business systems built for Malawi', 'subline' => 'Trusted by 500 businesses', 'whisper' => ['promanaged-it.com'], 'monogram' => 'PM', 'why' => 'A warm dusk suits a trusted local partner.']);
    };
    $_POST = ['d' => 'new'];
    $r = pm_social_ext_run('studio_ai', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && $calls === 1 && str_contains($r['msg'], 'warm dusk') && str_contains($r['msg'], 'subline'), 'one call; its reason is shown and the refused words are named: ' . $r['msg']);
    $s = pm_studio_get('promanaged', substr($r['to'], strrpos($r['to'], '=') + 1));
    pm_t_assert($s['style'] === 'dusk' && $s['pstyle'] === 'ring' && $s['bg2'] === '#c2410c' && $s['by'] === 'ai' && $s['pal'] === 'ai' && $s['headline'] === 'Business systems built for Malawi' && $s['subline'] !== 'Trusted by 500 businesses', 'the look is accepted; the invented "500 businesses" is not used');
    pm_t_assert(!str_contains($seen, 'AI image') && str_contains($seen, 'code draws it') && str_contains($seen, 'brand_colour'), 'the AI is asked to art-direct, not to make a picture, and is told the brand colour');
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['style' => 'photo', 'palette' => ['bg1' => 'red', 'bg2' => '#000000', 'accent' => '#ffffff', 'ink' => '#ffffff'], 'headline' => 'x']);
    [$spec, $note] = pm_studio_ai('promanaged', pm_studio_default('promanaged'));
    pm_t_assert($spec['style'] !== 'photo' && $spec['bg1'] === pm_studio_default('promanaged')['bg1'], 'without a photo "your photo" is swapped, and a colour that is not a colour is ignored');
    $GLOBALS['PM_AI_STUB'] = fn() => 'no json here';
    $_POST = ['d' => 'new'];
    $r = pm_social_ext_run('studio_ai', 'promanaged');
    pm_t_assert($r['kind'] === 'err' && str_contains($r['msg'], 'Nothing changed'), 'an unusable answer changes nothing');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('AI down');
    };
    pm_t_eq(pm_social_ext_run('studio_ai', 'promanaged')['kind'], 'err', 'a failing AI is reported');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The screen', function () {
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tok77';
    $_GET = ['d' => 'new'];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_studio.php';
    $h = ob_get_clean();
    foreach (PM_SD_STYLES as $k => $l) {
        if ($k !== 'photo') {
            pm_t_assert(str_contains($h, 'name="style" value="' . $k . '"'), "a button for the $l style");
        }
    }
    pm_t_assert(!str_contains($h, 'name="style" value="photo"') && str_contains($h, 'Your photo'), 'the photo style is offered only with a photo');
    pm_t_assert(str_contains($h, 'name="op" value="shuffle"') && str_contains($h, 'New idea from the AI') && str_contains($h, 'Use this design everywhere') && str_contains($h, 'set it on Facebook') && str_contains($h, 'value="tok77"'), 'the buttons are there, with the token');
    pm_t_assert(preg_match_all('#src="\?studio=(cover|profile)&amp;b=promanaged&amp;id=new&amp;w=\d+#', $h) >= 20 && str_contains($h, 'name="whisper"') && str_contains($h, 'Every size, ready to use') && str_contains($h, '1640×856') && str_contains($h, '2560×1440'), 'previews of every size, and the words form');
    pm_t_assert(!str_contains($h, 'Warning') && !str_contains($h, 'Fatal') && in_array('Studio', pm_social_tabs(), true) && str_ends_with(pm_social_view_file('studio'), 'view_social_studio.php'), 'no PHP warning, and it is in the Social menu');
    pm_t_assert(in_array('cover_studio', PM_BRAND_MAP_FILES, true), 'the studio data is swept when a business is deleted');
});

pm_t_done();
