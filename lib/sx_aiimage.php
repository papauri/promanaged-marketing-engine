<?php
/**
 * sx_aiimage — AI-made background pictures for stories, flyers and posts (Gemini image models, GEMINI_API_KEY; IMAGE_MODEL in .env picks one).
 *
 * Honesty rules, written into every prompt:
 *   - The picture is a BACKGROUND or illustration only: a mood, a generic setting or scene (a lake at sunset, hands on a laptop, a meeting table).
 *     It never claims to show this business's own rooms, premises, products, staff, customers, logo or results; the owner's real photos do that.
 *   - No text, letters, numbers, logos or watermarks: the app draws every word itself, so nothing misspelt or invented appears.
 *   - No identifiable real person; people only from behind, at a distance, as hands or as silhouettes.
 * Every result carries 'ai' => true and 'label' => 'AI picture', so a caller can say so next to it.
 *
 * pm_aiimg_make() costs one image call (about 1,300 output tokens) and is cached by business, brief, size and style, so the same picture is never paid twice.
 * Tests: $GLOBALS['PM_AIIMG_STUB'] = fn(string $prompt, int $w, int $h): ?string (PNG bytes) replaces the network; with PM_TEST set the network is never used.
 */

const PM_AIIMG_STYLES = [
    'photo' => 'a natural, realistic photograph with soft light and shallow depth of field',
    'illustration' => 'a clean modern flat illustration with simple shapes',
    'abstract' => 'an abstract composition of soft gradients, light and simple geometric shapes',
    'flatlay' => 'a tidy top-down flat-lay photograph of generic objects on a plain surface',
];

/** Gemini's aspect ratios for images: the nearest one is asked for, then the picture is cropped to the exact size. */
const PM_AIIMG_RATIOS = ['1:1' => 1.0, '2:3' => 2 / 3, '3:2' => 1.5, '3:4' => 0.75, '4:3' => 4 / 3, '4:5' => 0.8, '5:4' => 1.25, '9:16' => 9 / 16, '16:9' => 16 / 9, '21:9' => 21 / 9];

function pm_aiimg_key(string $brand = ''): string
{
    $b = $brand !== '' ? $brand : (function_exists('pm_brand') ? pm_brand() : '');
    $own = $b !== '' && function_exists('pm_brand_env') ? trim(pm_brand_env($b, 'GEMINI_API_KEY')) : '';
    return $own !== '' ? $own : (function_exists('pm_key') ? pm_key('GEMINI_API_KEY') : '');
}

/** Can the app make AI pictures here? (a Gemini key, GD to crop, and not a test run without a stub) */
function pm_aiimg_ready(string $brand = ''): bool
{
    if (!function_exists('imagecreatefromstring')) {
        return false;
    }
    if (isset($GLOBALS['PM_AIIMG_STUB']) && is_callable($GLOBALS['PM_AIIMG_STUB'])) {
        return true;
    }
    return !getenv('PM_TEST') && pm_aiimg_key($brand) !== '';
}

function pm_aiimg_dir(string $brand): string
{
    $d = PM_DATA . '/aiimg/' . (function_exists('pm_brand_norm') ? pm_brand_norm($brand) : preg_replace('/[^a-z0-9_]/', '', $brand));
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** The image model: IMAGE_MODEL in .env, else the newest Gemini model whose name says "image" and that answers generateContent (checked once a day). */
function pm_aiimg_model(string $brand = ''): string
{
    $set = function_exists('pm_key') ? pm_key('IMAGE_MODEL') : '';
    if ($set !== '') {
        return $set;
    }
    $cache = pm_load('aiimg_model', fn() => []);
    if (($cache['date'] ?? '') === date('Y-m-d') && !empty($cache['model'])) {
        return (string)$cache['model'];
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . pm_aiimg_key($brand)]]);
    function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
    $raw = curl_exec($ch);
    curl_close($ch);
    $best = '';
    $bestKey = [-1, -1, -1];
    foreach ((array)(is_string($raw) ? (json_decode($raw, true)['models'] ?? []) : []) as $m) {
        $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if (!str_starts_with($id, 'gemini-') || !str_contains($id, 'image') || !in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) {
            continue;
        }
        preg_match('/^gemini-(\d+(?:\.\d+)?)/', $id, $v);
        $key = [(float)($v[1] ?? 0), str_contains($id, 'preview') || str_contains($id, 'exp') ? 0 : 1, str_contains($id, 'pro') ? 0 : 1]; // newest, stable, the cheaper flash
        if ($key > $bestKey) {
            [$best, $bestKey] = [$id, $key];
        }
    }
    if ($best === '') {
        return (string)($cache['model'] ?? 'gemini-2.5-flash-image');
    }
    pm_save('aiimg_model', ['date' => date('Y-m-d'), 'model' => $best]);
    return $best;
}

/** The business's accent colour, as a hint for the palette. */
function pm_aiimg_accent(string $brand): string
{
    $c = '';
    if (function_exists('pm_brand_set') && function_exists('pm_settings')) {
        $was = pm_brand();
        pm_brand_set($brand);
        try {
            $c = (string)(pm_settings()['accent_color'] ?? '');
        } finally {
            pm_brand_set($was);
        }
    }
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : '';
}

/**
 * A short, safe scene for a business and a topic, with no AI call: a generic setting that fits the kind of business, never its own premises or products.
 * E.g. a lodge + "weekend retreat" -> "A calm, generic lakeside scene at golden hour that suggests a weekend retreat, ..."
 */
function pm_aiimg_brief(string $brand, string $topic): string
{
    $about = function_exists('pm_brain') ? trim((string)(pm_brain($brand)['about'] ?? '')) : '';
    $offer = function_exists('pm_agents_config') ? implode(', ', array_map(fn($o) => trim(explode(':', (string)$o, 2)[0]), array_slice((array)(pm_agents_config($brand)['offerings'] ?? []), 0, 3))) : '';
    $kind = mb_strtolower($about . ' ' . $offer);
    $scenes = [
        '/lodge|hotel|resort|guest ?house|stay|accommodation|tour|travel|safari|lake/' => 'a calm, generic Malawian landscape (lake shore, hills or open sky) in warm natural light, with no buildings, huts, rooms or pools',
        '/restaurant|food|cater|cafe|bakery|kitchen|meal/' => 'a generic table with fresh food and warm ambient light, seen from a distance',
        '/software|it |computer|website|tech|digital|network|system/' => 'a generic modern workspace with a laptop and soft daylight, hands only, no screens with text',
        '/school|college|training|course|education|learn/' => 'a generic bright classroom or study desk with books and natural light, no faces',
        '/clinic|health|pharmacy|medical|care/' => 'a calm, clean generic care setting in soft light, no faces',
        '/solar|power|energy|electric/' => 'generic solar panels on a roof under a clear African sky',
        '/farm|agri|crop|seed|poultry/' => 'a generic green field or farm landscape in morning light',
        '/shop|store|retail|grocer|market|boutique/' => 'generic well-lit shelves or a market stall with colourful goods, no brand names',
        '/salon|beauty|spa|barber/' => 'a generic calm spa or salon setting with towels and plants, no faces',
        '/build|construct|hardware|plumb|engineer/' => 'generic building materials and tools in daylight',
    ];
    $scene = 'a generic, uncluttered scene that suits a small business in Malawi, in soft natural light';
    foreach ($scenes as $re => $s) {
        if (preg_match($re, $kind)) {
            $scene = $s;
            break;
        }
    }
    $topic = trim(preg_replace('/\s+/', ' ', strip_tags($topic)));
    return ucfirst($scene) . ($topic !== '' ? ', with a mood that suggests "' . mb_substr($topic, 0, 120) . '"' : '') . '. Leave calm, empty space where words can be placed.';
}

/** The full prompt: the brief, the style, the palette and the honesty rules. */
function pm_aiimg_prompt(string $brand, string $brief, string $style, string $ratio): string
{
    $accent = pm_aiimg_accent($brand);
    return 'Create a background picture: ' . trim($brief) . ' Style: ' . (PM_AIIMG_STYLES[$style] ?? PM_AIIMG_STYLES['photo']) . '. '
        . ($accent !== '' ? 'Let the colour ' . $accent . ' appear gently in the palette. ' : '')
        . 'Aspect ratio ' . $ratio . '. '
        . 'Rules: absolutely no text, letters, numbers, signs, logos, labels or watermarks anywhere in the picture. '
        . 'It is a generic mood picture, not a picture of any real business, building, product or brand: show no hotel rooms, chalets, huts, venues, shop fronts or products '
        . 'that a viewer could take for the business\'s own. '
        . 'No identifiable people: at most hands, silhouettes or people seen from behind or far away. '
        . 'Keep a calm area with little detail where words will be placed later.';
}

/** Cover-crops PNG/JPEG bytes to exactly $w x $h and saves a PNG. Returns the path or ''. */
function pm_aiimg_fit(string $bytes, int $w, int $h, string $file): string
{
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return '';
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh);
    $cw = (int)round($w / $scale);
    $chh = (int)round($h / $scale);
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $chh) / 2), $w, $h, $cw, $chh);
    $ok = imagepng($dst, $file, 6);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? $file : '';
}

/**
 * One AI background picture, exactly $w x $h, saved in data/aiimg/<business>/. Cached: the same business, brief, size and style is made once.
 * Returns ['ok', 'path', 'why', 'model', 'ai' => true, 'label' => 'AI picture', 'cached'].
 */
function pm_aiimg_make(string $brand, string $brief, int $w, int $h, string $style = 'photo'): array
{
    $style = isset(PM_AIIMG_STYLES[$style]) ? $style : 'photo';
    $w = max(64, min(4000, $w));
    $h = max(64, min(4000, $h));
    $out = ['ok' => false, 'path' => '', 'why' => '', 'model' => '', 'ai' => true, 'label' => 'AI picture', 'cached' => false];
    $brief = trim($brief) !== '' ? trim($brief) : pm_aiimg_brief($brand, '');
    $file = pm_aiimg_dir($brand) . '/' . substr(hash('sha256', $brand . "\n" . $brief . "\n" . $w . 'x' . $h . "\n" . $style), 0, 24) . '.png';
    if (is_file($file) && filesize($file) > 1000) {
        return ['ok' => true, 'path' => $file, 'why' => 'AI picture (saved earlier)', 'cached' => true] + $out;
    }
    if (!function_exists('imagecreatefromstring')) {
        return ['why' => 'This server cannot handle pictures (the PHP GD extension is off).'] + $out;
    }
    $want = $w / $h;
    $ratio = '1:1';
    foreach (PM_AIIMG_RATIOS as $r => $v) {
        if (abs(log($v / $want)) < abs(log(PM_AIIMG_RATIOS[$ratio] / $want))) {
            $ratio = $r;
        }
    }
    $prompt = pm_aiimg_prompt($brand, $brief, $style, $ratio);
    if (isset($GLOBALS['PM_AIIMG_STUB']) && is_callable($GLOBALS['PM_AIIMG_STUB'])) {
        $bytes = ($GLOBALS['PM_AIIMG_STUB'])($prompt, $w, $h);
        if (!is_string($bytes) || $bytes === '') {
            return ['why' => 'No picture came back.', 'model' => 'stub'] + $out;
        }
        $p = pm_aiimg_fit($bytes, $w, $h, $file);
        return $p !== '' ? ['ok' => true, 'path' => $p, 'why' => 'AI picture', 'model' => 'stub'] + $out : ['why' => 'The picture could not be read.', 'model' => 'stub'] + $out;
    }
    if (getenv('PM_TEST')) {
        return ['why' => 'offline in tests'] + $out;
    }
    if (pm_aiimg_key($brand) === '') {
        return ['why' => 'Add GEMINI_API_KEY to .env to make AI pictures.'] + $out;
    }
    try {
        if (function_exists('pm_budget_check')) {
            pm_budget_check();
        }
    } catch (Throwable $e) {
        return ['why' => $e->getMessage()] + $out;
    }
    $model = pm_aiimg_model($brand);
    $out['model'] = $model;
    $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => $ratio]]];
    $send = function (array $body) use ($model, $brand) {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . pm_aiimg_key($brand)], CURLOPT_POSTFIELDS => json_encode($body)]);
        function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$raw, $code, $err];
    };
    [$raw, $code, $err] = $send($body);
    if ($code === 400 && is_string($raw) && str_contains($raw, 'imageConfig')) { // an older model without imageConfig: ask again without it, then crop
        unset($body['generationConfig']['imageConfig']);
        [$raw, $code, $err] = $send($body);
    }
    if (!is_string($raw) || $raw === '') {
        return ['why' => 'The picture service did not answer' . ($err !== '' ? ' (' . $err . ')' : '') . '.'] + $out;
    }
    $j = json_decode($raw, true);
    if ($code >= 400 || !is_array($j)) {
        $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        return ['why' => 'The picture service said ' . $code . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . '.'] + $out;
    }
    $u = (array)($j['usageMetadata'] ?? []);
    if (function_exists('pm_usage_add')) { // counted against the business the picture is for
        $was = function_exists('pm_brand') ? pm_brand() : '';
        $was !== '' && pm_brand_set($brand);
        try {
            pm_usage_add('gemini', $model, (int)($u['promptTokenCount'] ?? 0), (int)($u['candidatesTokenCount'] ?? 0), (int)($u['thoughtsTokenCount'] ?? 0), 'aiimg');
        } finally {
            $was !== '' && pm_brand_set($was);
        }
    }
    $bytes = '';
    foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $part) {
        $d = $part['inlineData'] ?? $part['inline_data'] ?? null;
        if (is_array($d) && !empty($d['data'])) {
            $bytes = (string)base64_decode((string)$d['data']);
            break;
        }
    }
    if ($bytes === '') {
        $why = (string)($j['candidates'][0]['finishReason'] ?? $j['promptFeedback']['blockReason'] ?? 'no picture in the answer');
        return ['why' => 'No picture came back (' . $why . ').'] + $out;
    }
    $p = pm_aiimg_fit($bytes, $w, $h, $file);
    return $p !== '' ? ['ok' => true, 'path' => $p, 'why' => 'AI picture'] + $out : ['why' => 'The picture could not be read.'] + $out;
}
