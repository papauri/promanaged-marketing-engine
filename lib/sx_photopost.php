<?php
/**
 * Social > Plan > "Post from a photo". The owner uploads one picture (and, if they like, a few words about it and a price); the app then:
 *   1. edits the picture to a feed standard, in code: colour balance, levels, exposure, a little more colour and a light sharpen, and finds the subject
 *      so every size (square, 4:5, story) is cropped around it rather than around the middle;
 *   2. looks at the picture (an AI model that can see) and writes the headline, the line under it, the caption, hashtags and the alt text, from what is
 *      visible and what the owner wrote only; numbers nobody gave are taken out;
 *   3. puts the owner's price in a price box on the picture and one plain line in the caption. The price is only ever the owner's own figure: the AI never
 *      writes one, and the post checks still refuse any other amount;
 *   4. adds it to the plan as a draft at the next free slot, to approve like any post.
 * The original picture stays in the photo library beside the edited one. Without an AI key, or when seeing the picture fails, the words are written from
 * the owner's note alone (still no invented facts).
 */

const PM_PP_UNITS = ['per night' => 'per night', 'per person' => 'per person', 'per room' => 'per room', 'each' => 'each', 'per day' => 'per day', 'per month' => 'per month', 'once' => 'one-off', '' => 'no unit'];

/* ---------------- the picture: edited in code ---------------- */

/**
 * Edits a JPEG to a feed standard and writes it to $dest. Returns ['focus' => [x, y] (0..1, where the subject is), 'notes' => [what was done]].
 * Every step is measured first and kept gentle, so a picture that is already good is barely touched.
 */
function pm_pp_enhance(string $src, string $dest): array
{
    @ini_set('memory_limit', '512M');
    $im = @imagecreatefromjpeg($src);
    if (!$im) {
        throw new RuntimeException('The picture could not be opened.');
    }
    $w = imagesx($im);
    $h = imagesy($im);
    // measure on a small copy
    $tw = 160;
    $th = max(1, (int)round($h * $tw / $w));
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $sum = [0.0, 0.0, 0.0];
    $hist = array_fill(0, 256, 0);
    $satSum = 0.0;
    $lum = [];
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $c = imagecolorat($t, $x, $y);
            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;
            $sum[0] += $r;
            $sum[1] += $g;
            $sum[2] += $b;
            $l = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
            $hist[$l]++;
            $lum[$y][$x] = $l;
            $mx = max($r, $g, $b);
            $satSum += $mx > 0 ? ($mx - min($r, $g, $b)) / $mx : 0;
        }
    }
    $n = $tw * $th;
    $mean = array_map(fn($v) => $v / $n, $sum);
    $notes = [];
    // white balance: only a clear colour cast is corrected (sky, sea and sand are meant to be blue and gold), then a touch of warmth, which sells rooms and food
    $grey = array_sum($mean) / 3;
    $dev = max(array_map(fn($m) => abs($m / max(1, $grey) - 1), $mean));
    $gain = $dev > 0.12 ? array_map(fn($m) => max(0.94, min(1.07, 1 + 0.35 * ($grey / max(1, $m) - 1))), $mean) : [1.0, 1.0, 1.0];
    $gain[0] *= 1.025; // warmer
    $gain[2] *= 0.975;
    $notes[] = $dev > 0.12 ? 'colour cast corrected' : 'warmed';
    // levels: the darkest and lightest 1% become near black and near white
    $lo = 0;
    $hi = 255;
    for ($acc = 0, $i = 0; $i < 256; $i++) {
        $acc += $hist[$i];
        if ($acc >= $n * 0.01) {
            $lo = $i;
            break;
        }
    }
    for ($acc = 0, $i = 255; $i >= 0; $i--) {
        $acc += $hist[$i];
        if ($acc >= $n * 0.01) {
            $hi = $i;
            break;
        }
    }
    $stretch = $hi - $lo < 230 && $hi > $lo + 40;
    $lo2 = $stretch ? (int)round($lo * 0.8) : 0;
    $hi2 = $stretch ? (int)round(255 - (255 - $hi) * 0.8) : 255;
    if ($stretch) {
        $notes[] = 'contrast lifted';
    }
    // exposure: the average brightness moves towards a well-lit picture
    $meanL = 0.0;
    foreach ($hist as $v => $cnt) {
        $meanL += $v * $cnt;
    }
    $meanL = max(1.0, $meanL / $n);
    $after = max(0.02, min(0.98, ($meanL - $lo2) / max(1, $hi2 - $lo2)));
    // feed pictures sell bright: a dark picture is lifted towards a well-lit average; only a clearly over-exposed one is brought down, and only a little
    $gamma = $after < 0.5 ? max(0.72, log(0.52) / log($after)) : ($after > 0.66 ? min(1.15, log(0.6) / log($after)) : 1.0);
    if (abs($gamma - 1) > 0.04) {
        $notes[] = $gamma < 1 ? 'brightened' : 'highlights calmed';
    }
    $sat = $satSum / $n < 0.22 ? 1.16 : 1.07;
    $lut = [];
    for ($ch = 0; $ch < 3; $ch++) {
        for ($v = 0; $v < 256; $v++) {
            $x = min(255, $v * $gain[$ch]);
            $x = ($x - $lo2) / max(1, $hi2 - $lo2);
            $x = pow(max(0.0, min(1.0, $x)), $gamma);
            $lut[$ch][$v] = $x * 255;
        }
    }
    // apply: the curves, then a little more colour (more on dull colours than on bright ones)
    imagealphablending($im, false);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = $lut[0][($c >> 16) & 255];
            $g = $lut[1][($c >> 8) & 255];
            $b = $lut[2][$c & 255];
            $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $mx = max($r, $g, $b);
            $k = 1 + ($sat - 1) * (1 - ($mx > 0 ? ($mx - min($r, $g, $b)) / $mx : 0) * 0.6);
            $r = (int)max(0, min(255, $l + ($r - $l) * $k));
            $g = (int)max(0, min(255, $l + ($g - $l) * $k));
            $b = (int)max(0, min(255, $l + ($b - $l) * $k));
            imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
        }
    }
    $notes[] = 'colour lifted';
    imageconvolution($im, [[-1, -1, -1], [-1, 24, -1], [-1, -1, -1]], 16, 0); // a light sharpen
    $notes[] = 'sharpened';
    // where the subject is: the busiest part of the picture (edges), pulled a little towards the middle
    $ex = 0.0;
    $ey = 0.0;
    $es = 0.0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $e = abs($lum[$y][$x + 1] - $lum[$y][$x - 1]) + abs($lum[$y + 1][$x] - $lum[$y - 1][$x]);
            $e = $e * $e;
            $ex += $e * $x;
            $ey += $e * $y;
            $es += $e;
        }
    }
    $fx = $es > 0 ? $ex / $es / $tw : 0.5;
    $fy = $es > 0 ? $ey / $es / $th : 0.5;
    $focus = [round(0.5 + ($fx - 0.5) * 0.7, 3), round(0.5 + ($fy - 0.5) * 0.7, 3)];
    imagedestroy($t);
    $ok = imagejpeg($im, $dest, 88);
    imagedestroy($im);
    if (!$ok) {
        throw new RuntimeException('The edited picture could not be saved.');
    }
    return ['focus' => $focus, 'notes' => $notes];
}

/* ---------------- looking at the picture ---------------- */

/** A small JPEG of the picture for the AI (sees enough, costs little). */
function pm_pp_small_b64(string $file, int $max = 768): string
{
    $im = @imagecreatefromjpeg($file);
    if (!$im) {
        return '';
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $k = min(1, $max / max($w, $h));
    $s = imagecreatetruecolor(max(1, (int)($w * $k)), max(1, (int)($h * $k)));
    imagecopyresampled($s, $im, 0, 0, 0, 0, imagesx($s), imagesy($s), $w, $h);
    ob_start();
    imagejpeg($s, null, 80);
    $bin = (string)ob_get_clean();
    imagedestroy($im);
    imagedestroy($s);
    return base64_encode($bin);
}

/**
 * One AI call that sees a picture: Gemini or Claude, whichever writes for this app (AGENT_TEXT_PROVIDER). Counted against the daily budget like every call.
 * Returns the model's text. $GLOBALS['PM_AI_STUB'] (tests) answers instead, with "[image]" added to what it is given.
 */
function pm_ai_vision(string $system, string $user, string $jpeg, int $maxTokens = 1500): string
{
    pm_budget_check();
    if (isset($GLOBALS['PM_AI_STUB']) && is_callable($GLOBALS['PM_AI_STUB'])) {
        return (string)($GLOBALS['PM_AI_STUB'])($system, $user . "\n[image]", 'vision', $maxTokens);
    }
    if (getenv('PM_TEST')) {
        throw new RuntimeException('AI not stubbed');
    }
    $b64 = pm_pp_small_b64($jpeg);
    if ($b64 === '') {
        throw new RuntimeException('The picture could not be read for the AI.');
    }
    if (pm_provider(false) === 'gemini') {
        $body = ['systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]], ['text' => $user]]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json']];
        $last = 'no model answered';
        foreach (array_slice(pm_gemini_order($system . $user, 'cheap'), 0, 3) as $model) {
            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . pm_key('GEMINI_API_KEY')], CURLOPT_POSTFIELDS => json_encode($body)]);
            pm_curl_native_ca($ch);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $r = is_string($raw) ? json_decode($raw, true) : null;
            if ($code >= 400 || !is_array($r)) {
                $last = "$model: error $code " . mb_substr((string)($r['error']['message'] ?? ''), 0, 120);
                continue;
            }
            $text = '';
            foreach ($r['candidates'][0]['content']['parts'] ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }
            $u = $r['usageMetadata'] ?? [];
            pm_usage_add('gemini', $model, (int)($u['promptTokenCount'] ?? 0), (int)($u['candidatesTokenCount'] ?? 0), (int)($u['thoughtsTokenCount'] ?? 0));
            if ($text !== '') {
                return $text;
            }
        }
        throw new RuntimeException('Gemini could not look at the picture (' . $last . ')');
    }
    $base = rtrim(pm_key('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com', '/');
    $model = pm_agent_model(false, 'cheap');
    $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
        'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]], ['type' => 'text', 'text' => $user]]]]];
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . pm_key('ANTHROPIC_API_KEY'), 'anthropic-version: 2023-06-01'], CURLOPT_POSTFIELDS => json_encode($body)]);
    pm_curl_native_ca($ch);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = is_string($raw) ? json_decode($raw, true) : null;
    if ($code >= 400 || !is_array($r)) {
        throw new RuntimeException('Claude could not look at the picture (error ' . $code . ')');
    }
    $text = '';
    foreach ((array)($r['content'] ?? []) as $b) {
        $text .= ($b['type'] ?? '') === 'text' ? (string)$b['text'] : '';
    }
    pm_usage_add('anthropic', $model, (int)($r['usage']['input_tokens'] ?? 0), (int)($r['usage']['output_tokens'] ?? 0));
    return $text;
}

/* ---------------- the words ---------------- */

/** "From MWK 85,000 per night" from what the owner typed; '' when there is no price. */
function pm_pp_price_text(array $pr): string
{
    $amt = (float)($pr['amount'] ?? 0);
    if ($amt <= 0) {
        return '';
    }
    $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($pr['currency'] ?? 'MWK'))) ?: 'MWK';
    $num = number_format($amt, abs($amt - round($amt)) < 0.005 ? 0 : 2);
    $unit = trim((string)($pr['unit'] ?? ''));
    return trim((!empty($pr['from']) ? 'From ' : '') . $cur . ' ' . $num . ($unit !== '' ? ' ' . $unit : ''));
}

/** Text with every sentence that holds a number nobody gave taken out (the owner's note and the business's facts are the only sources). */
function pm_pp_drop_unsourced(string $text, string $sources): string
{
    preg_match_all('/\d+(?:[.,]\d+)?/', $sources, $m);
    $ok = array_map(fn($x) => str_replace(',', '', $x), $m[0]);
    $keep = [];
    foreach (preg_split('/(?<=[.!?])\s+|\R/u', $text) ?: [] as $s) {
        preg_match_all('/\d+(?:[.,]\d+)?/', $s, $mm);
        $bad = array_filter($mm[0], fn($x) => !in_array(str_replace(',', '', $x), $ok, true));
        if (!$bad) {
            $keep[] = $s;
        }
    }
    return trim(implode(' ', $keep));
}

/** The words for the post: ['headline','sub','caption','hashtags','alt','pillar','seen' (true when the AI saw the picture)]. */
function pm_pp_words(string $brand, string $jpeg, string $note, string $priceText): array
{
    $s = pm_settings();
    $pillars = array_values(array_filter(array_column(pm_social_pillars($brand), 'name'), fn($n) => !in_array(strtolower((string)$n), ['proof', 'offer'], true)));
    $system = pm_agents_company_brief('tiny') . "\nYou write one social media post for {$s['company_name']} around a photo the owner took. Write like a top hospitality and small-business marketer: "
        . 'three short paragraphs separated by a blank line: first a hook that stops the scroll with what the reader gets or feels; then two or three sentences that sell the feeling and the benefit '
        . '(do not list every object in the photo, pick the one or two details that make someone want it); last, one easy invitation to send a WhatsApp message. '
        . 'Describe only what is visible in the photo and what the owner wrote. Never invent features, amenities, distances, awards, numbers, dates, discounts or prices: the app adds the owner\'s price itself, so write no amount of money at all. '
        . 'Never start with "Hello" or the business name. Plain words, no exclamation marks in a row, no hashtags inside the caption. ' . (defined('PM_HUMAN_RULES') ? PM_HUMAN_RULES : '')
        . "\nReply JSON only: {\"headline\":\"at most 6 words for the picture\",\"sub\":\"at most 10 words under it\",\"caption\":\"45 to 90 words in three paragraphs\",\"hashtags\":[\"exactly 3 relevant tags\"],"
        . "\"alt\":\"one sentence describing the photo for screen readers\",\"pillar\":\"one of: " . implode(' | ', $pillars ?: ['Tip/How-to']) . '"}';
    $user = json_encode(['owner_note' => $note, 'business' => $s['company_name'], 'there_is_a_price' => $priceText !== ''], JSON_UNESCAPED_UNICODE);
    $seen = false;
    $out = null;
    try {
        $out = pm_agent_json(pm_ai_vision($system, $user, $jpeg));
        $seen = is_array($out);
    } catch (Throwable) {
        $out = null;
    }
    if (!is_array($out) && pm_agents_ready()) {
        try { // could not see the picture: write from the owner's words alone
            $out = pm_agent_json(pm_claude($system . "\nYou cannot see the photo: work only from owner_note.", $user, false, 1200, 'write'));
        } catch (Throwable) {
            $out = null;
        }
    }
    $out = is_array($out) ? $out : [];
    $src = $note . "\n" . (string)(pm_brain($brand)['facts'] ?? '');
    $clean = fn($v, int $max) => mb_substr(trim(strip_tags(is_scalar($v) ? (string)$v : '')), 0, $max);
    $headline = pm_pp_drop_unsourced($clean($out['headline'] ?? '', 70), $src);
    $sub = pm_pp_drop_unsourced($clean($out['sub'] ?? '', 110), $src);
    $caption = $clean($out['caption'] ?? '', 1200);
    $caption = implode("\n\n", array_filter(array_map(fn($para) => pm_pp_drop_unsourced($para, $src), preg_split('/\R{2,}/u', $caption) ?: []), fn($x) => $x !== ''));
    if ($caption === '') {
        $caption = trim(($note !== '' ? rtrim($note, '. ') . ".\n\n" : '') . 'Send us a WhatsApp message to ask or to book.');
    }
    if ($headline === '') {
        $headline = $note !== '' ? mb_substr(rtrim(explode('.', $note)[0]), 0, 60) : (string)$s['company_name'];
    }
    if (function_exists('pm_human_polish')) {
        $caption = pm_human_polish($caption, 'post');
    }
    $tags = array_values(array_slice(array_filter(array_map(fn($t) => '#' . preg_replace('/[^\p{L}\p{N}]/u', '', ltrim((string)$t, '#')), (array)($out['hashtags'] ?? [])), fn($t) => mb_strlen($t) > 2), 0, 3)); // Facebook does best with 3 or fewer
    $pillar = in_array((string)($out['pillar'] ?? ''), $pillars, true) ? (string)$out['pillar'] : ($pillars[0] ?? '');
    return ['headline' => $headline, 'sub' => $sub, 'caption' => $caption, 'hashtags' => $tags, 'alt' => $clean($out['alt'] ?? '', 300), 'pillar' => $pillar, 'seen' => $seen];
}

/** The caption with the owner's price as its own line, just before the closing invitation. */
function pm_pp_caption_with_price(string $caption, string $priceText): string
{
    if ($priceText === '') {
        return $caption;
    }
    $paras = preg_split('/\R{2,}/u', trim($caption)) ?: [];
    if (count($paras) === 1) { // one block of text: the closing invitation becomes its own paragraph, so the price can sit just before it
        $sent = preg_split('/(?<=[.!?])\s+/u', $paras[0]) ?: [];
        $last = count($sent) > 1 ? array_pop($sent) : null;
        if ($last !== null && preg_match('/whatsapp|message|book|call|contact|ask|dm\b/i', $last)) {
            $paras = [implode(' ', $sent), $last];
        }
    }
    $line = $priceText . '.';
    if (count($paras) >= 2) {
        array_splice($paras, count($paras) - 1, 0, [$line]);
    } else {
        $paras[] = $line;
    }
    return implode("\n\n", $paras);
}

/* ---------------- the form and its handler ---------------- */

/** Social > Plan: the card with the form. */
function pm_panel_plan_top_photopost(string $vb, array $ctx = []): string
{
    $csrf = pm_h((string)($_SESSION['csrf'] ?? ''));
    $s = pm_settings();
    $curs = array_values(array_unique(array_map(fn($c) => (string)$c['code'], (array)($s['currencies'] ?? []))));
    $def = (string)($s['default_quote_currency'] ?? ($s['currency'] ?? 'MWK'));
    $cur = '';
    foreach ($curs ?: ['MWK', 'USD'] as $c) {
        $cur .= '<option value="' . pm_h($c) . '"' . ($c === $def ? ' selected' : '') . '>' . pm_h($c) . '</option>';
    }
    $units = '';
    foreach (PM_PP_UNITS as $k => $l) {
        $units .= '<option value="' . pm_h($k) . '"' . ($k === ($vb === 'travel' ? 'per night' : '') ? ' selected' : '') . '>' . pm_h($l) . '</option>';
    }
    $ai = pm_agents_ready() ? 'The AI looks at the picture and writes the words.' : 'Without an AI key the words come from your note only.';
    return '<form method="post" enctype="multipart/form-data" class="card" id="photopost"><h2>Post from a photo</h2>'
        . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="pp_make">'
        . '<p class="hint mt-0">Upload a photo: it is edited to a feed standard (colour, light, contrast, sharpness, cropped around the subject for every size), the words are written for it, '
        . 'and your price goes in a price box on the picture. It lands in the plan as a draft for you to approve. ' . pm_h($ai) . '</p>'
        . '<div class="row"><div><label>Photo (JPG or PNG)</label><input type="file" name="photo" accept="image/jpeg,image/png" required></div>'
        . '<div><label>What is in it <span class="muted">(optional, helps the words)</span></label><input type="text" name="note" maxlength="300" placeholder="' . ($vb === 'travel' ? 'Lakeside chalet, sleeps 4, own braai area' : 'New laptops in stock, 16 GB, 1 year warranty') . '"></div></div>'
        . '<div class="row"><div><label>Price <span class="muted">(optional; only your figure is ever shown)</span></label><input type="number" name="price" min="0" step="any" placeholder="85000"></div>'
        . '<div><label>Currency</label><select name="currency">' . $cur . '</select></div><div><label>Per</label><select name="unit">' . $units . '</select></div></div>'
        . '<label class="check"><input type="checkbox" name="from" value="1"> Show it as a "From" price</label>'
        . '<label class="check"><input type="checkbox" name="consent" value="1" required> I took this photo, or I have permission to use it</label>'
        . '<div class="btns"><button class="btn primary" data-busy="Editing the photo and writing the post…">Edit the photo and write the post</button></div></form>';
}

/** The form's handler: picture in, edited, words written, draft post planned. */
function pm_do_pp_make(string $vb): array
{
    $to = 'social';
    if (empty($_POST['consent'])) {
        return ['msg' => 'Tick that you took the photo or have permission to use it.', 'kind' => 'err', 'to' => $to];
    }
    $f = $_FILES['photo'] ?? [];
    if (empty($f['tmp_name']) || (int)($f['error'] ?? 4) !== UPLOAD_ERR_OK || !pm_sx_upload_ok((string)$f['tmp_name'])) {
        $err = (int)($f['error'] ?? 4);
        return ['msg' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The photo is bigger than this server accepts (' . ini_get('upload_max_filesize') . ').' : 'Choose a photo first.', 'kind' => 'err', 'to' => $to];
    }
    $note = mb_substr(trim(strip_tags((string)($_POST['note'] ?? ''))), 0, 300);
    $unit = (string)($_POST['unit'] ?? '');
    $price = ['amount' => max(0, (float)($_POST['price'] ?? 0)), 'currency' => (string)($_POST['currency'] ?? 'MWK'), 'unit' => isset(PM_PP_UNITS[$unit]) ? ($unit === 'once' ? 'one-off' : $unit) : '', 'from' => !empty($_POST['from'])];
    $priceText = pm_pp_price_text($price);
    return pm_pp_make($vb, (string)$f['tmp_name'], (string)($f['name'] ?? 'photo.jpg'), $note, $price, $priceText) + ['to' => $to];
}

/** Does the work (also for tests): returns ['msg','kind','post_id'?]. */
function pm_pp_make(string $vb, string $tmp, string $origName, string $note, array $price, string $priceText): array
{
    @set_time_limit(300);
    [$orig, $why] = pm_social_asset_ingest($tmp, $origName, $vb, ['consent' => true, 'consent_note' => 'Uploaded for a post from a photo', 'note' => $note, 'owner' => pm_social_who()]);
    if (!$orig) {
        return ['msg' => $why, 'kind' => 'err'];
    }
    $id = bin2hex(random_bytes(6));
    $file = $id . '.jpg';
    try {
        $ed = pm_pp_enhance(pm_social_asset_path($orig), pm_sx_assets_dir() . '/' . $file);
    } catch (Throwable $e) {
        return ['msg' => 'The photo was saved to the library, but it could not be edited: ' . $e->getMessage(), 'kind' => 'err'];
    }
    $sz = @getimagesize(pm_sx_assets_dir() . '/' . $file) ?: [0, 0];
    $asset = pm_social_asset_save(['id' => $id, 'brand' => $vb, 'file' => $file, 'orig_name' => 'Edited: ' . $origName, 'w' => (int)$sz[0], 'h' => (int)$sz[1], 'created' => date('Y-m-d H:i'),
        'consent' => true, 'consent_note' => 'Edited copy of ' . $origName, 'note' => $note, 'edited_of' => $orig['id'], 'focus' => $ed['focus'], 'edits' => $ed['notes']]);
    if (!$asset) {
        return ['msg' => 'The edited photo could not be recorded.', 'kind' => 'err'];
    }
    $GLOBALS['PM_AI_REF'] = 'photo_post';
    $w = pm_pp_words($vb, pm_sx_assets_dir() . '/' . $file, $note, $priceText);
    $posts = pm_social_posts();
    $post = ['id' => bin2hex(random_bytes(10)), 'brand' => $vb, 'status' => 'draft', 'when' => pm_social_free_slot($posts, $vb, time() + 3600), 'format' => 'image', 'layout' => 'photo',
        'asset_id' => $id, 'caption' => pm_pp_caption_with_price($w['caption'], $priceText), 'hashtags' => $w['hashtags'], 'headline' => $w['headline'], 'sub' => $w['sub'], 'alt' => $w['alt'],
        'pillar' => $w['pillar'], 'cta' => pm_social_phone($vb) !== '' ? 'whatsapp' : '', 'price' => $price + ['text' => $priceText], 'src' => 'photo_post',
        'script' => '', 'media' => '', 'fb_id' => '', 'error' => '', 'created' => date('Y-m-d H:i'), 'by' => pm_social_who()];
    $post = pm_social_resolve($post, pm_social_lint_post($post));
    pm_social_add_planned([$post], false);
    pm_social_asset_use($id, (string)$post['id']);
    return ['msg' => 'Photo edited (' . implode(', ', $ed['notes']) . ') and the post written' . ($w['seen'] ? ' from what the AI saw' : ' from your note') . ($priceText !== '' ? ', with the price box "' . $priceText . '"' : '')
        . '. It is a draft for ' . date('D j M, H:i', strtotime((string)$post['when'])) . ': read it below and approve.' . ($post['status'] === 'needs_edit' ? ' It needs an edit first: see the note on it.' : ''),
        'kind' => 'ok', 'post_id' => (string)$post['id']];
}
