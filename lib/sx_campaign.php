<?php
/**
 * sx_campaign — mass communication: ONE message or offer becomes a coordinated set for every channel, with one approval and one schedule.
 *   - a feed post (Facebook, Instagram, LinkedIn; the hand-post channels X, Google, TikTok... get their own wording from sx_variants),
 *   - a story set of three 9:16 frames (hook, offer, call to action) for Facebook and Instagram stories and WhatsApp Status,
 *   - a carousel the next day (cover + slides),
 *   - a WhatsApp broadcast to opted-in leads with the flyer picture attached (sx_wacampaign rules: approval, opt-in, STOP, daily limit),
 *   - a flyer: a shareable picture (1080x1350), a print picture and A4 / A5 PDFs with a QR code to the WhatsApp chat or the landing page.
 * Every item is an ordinary social post (posts carry campaign + camp_role), so the cards engine, the lint, the approval, the scheduler, the hand-post tasks and
 * the downloads all work as for any post. The words come from one AI call that may use only the business's own facts (no invented numbers or prices);
 * without the AI they are built from the owner's own words. Pictures: the drawn card, the owner's photo, or an AI picture (sx_aiimage, labelled "AI picture").
 * Data: data/campaigns.json {rows:[{id,brand,name,headline,message,keyword,offer,pic,bg,bg_ai,when,aud,posts:{role:id},wac,flyer,status,created,by,ai,note}]},
 * files in data/campaigns/<id>/.
 */

const PM_CAMP_PICS = ['card' => 'Drawn card in the brand colours', 'photo' => 'My photo', 'ai' => 'AI picture'];
const PM_CAMP_ROLES = ['feed' => 'Feed post', 'story1' => 'Story 1 · the hook', 'story2' => 'Story 2 · the offer', 'story3' => 'Story 3 · what to do', 'carousel' => 'Carousel (next day)'];

/* ---------------- storage ---------------- */

function pm_camp_all(): array
{
    return array_values((array)(pm_load('campaigns', fn() => ['rows' => []])['rows'] ?? []));
}

function pm_camp_update(callable $fn): void
{
    pm_update('campaigns', fn(array $d) => ['rows' => array_values($fn((array)($d['rows'] ?? [])))], fn() => ['rows' => []]);
}

function pm_camp_get(string $id): ?array
{
    foreach (pm_camp_all() as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
}

/** A business's campaigns, newest first. */
function pm_camp_for(string $brand): array
{
    $rows = array_values(array_filter(pm_camp_all(), fn($r) => ($r['brand'] ?? '') === $brand));
    usort($rows, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
    return $rows;
}

function pm_camp_patch(string $id, callable $fn): ?array
{
    $out = null;
    pm_camp_update(function (array $rows) use ($id, $fn, &$out) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $rows[$i] = $out = (array)$fn($r);
            }
        }
        return $rows;
    });
    return $out;
}

/** The campaign's own folder (created on demand). */
function pm_camp_dir(string $id): string
{
    $d = PM_DATA . '/campaigns/' . preg_replace('/[^a-z0-9]/', '', $id);
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** The posts of a campaign, by role. */
function pm_camp_posts(array $camp): array
{
    $ids = array_flip(array_map('strval', (array)($camp['posts'] ?? [])));
    $out = [];
    foreach (pm_social_posts() as $p) {
        if (isset($ids[(string)($p['id'] ?? '')])) {
            $out[$ids[(string)$p['id']]] = $p;
        }
    }
    return array_replace(array_intersect_key(array_flip(array_keys(PM_CAMP_ROLES)), $out), $out);
}

/* ---------------- the words ---------------- */

/** The call to action of a campaign: the keyword, the button words, and where a tap or a scan goes (landing page, else a WhatsApp chat). */
function pm_camp_cta(string $brand, array $camp): array
{
    $kw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($camp['keyword'] ?? '')));
    $o = ($camp['offer'] ?? '') !== '' && function_exists('pm_lp_get') ? pm_lp_get((string)$camp['offer']) : null;
    $page = $o && function_exists('pm_lp_url') ? pm_lp_url($brand, (string)$o['id'], 'flyer') : '';
    $wa = function_exists('pm_inb_wa_link') ? pm_inb_wa_link($brand, $kw !== '' ? $kw : 'Hi') : '';
    $was = pm_brand();
    pm_brand_set($brand);
    $phone = trim((string)(pm_settings()['phone'] ?? ''));
    $site = trim((string)(pm_settings()['website'] ?? ''));
    pm_brand_set($was);
    $site = $site !== '' ? (preg_match('#^https?://#i', $site) ? $site : 'https://' . $site) : '';
    return ['site' => $site, 'keyword' => $kw, 'line' => $kw !== '' ? 'Send ' . $kw . ' on WhatsApp' : 'Message us on WhatsApp', 'button' => $kw !== '' ? 'WhatsApp ' . $kw : 'Send WhatsApp message',
        'link' => $page !== '' ? $page : ($wa !== '' ? $wa : $site), 'wa' => $wa, 'phone' => $phone];
}

/** The owner's own words for a campaign (always safe to use), and the text any AI wording is checked against. */
function pm_camp_own(string $brand, array $in): array
{
    $brain = pm_brain($brand);
    $facts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($brain['facts'] ?? '')) ?: [])));
    $o = ($in['offer'] ?? '') !== '' && function_exists('pm_lp_get') ? pm_lp_get((string)$in['offer']) : null;
    $points = [];
    foreach ($o ? array_filter([(string)($o['fix'] ?? ''), (string)($o['problem'] ?? '')]) : [] as $x) {
        $points[] = $x;
    }
    foreach ($facts as $f) {
        if (count($points) < 3 && mb_strlen($f) <= 90) {
            $points[] = $f;
        }
    }
    $head = trim((string)($in['headline'] ?? '')) ?: (string)($o['title'] ?? '');
    $msg = trim((string)($in['message'] ?? '')) ?: trim((string)($o['fix'] ?? ''));
    $source = mb_strtolower(implode("\n", array_merge([$head, $msg, (string)($brain['about'] ?? ''), (string)pm_brand_profile($brand)['magnet']], $facts, $o ? [(string)$o['title'], (string)$o['problem'], (string)$o['fix'], (string)($o['proof'] ?? '')] : [])));
    return ['headline' => $head, 'message' => $msg, 'points' => array_slice($points, 0, 3), 'facts' => $facts, 'source' => $source, 'about' => (string)($brain['about'] ?? ''),
        'voice' => (string)($brain['voice'] ?? ''), 'never' => (string)($brain['never'] ?? '')];
}

/** Plain words for every item, from the owner's own text only. */
function pm_camp_copy_plain(array $own, array $cta): array
{
    $h = $own['headline'];
    $m = $own['message'];
    $pts = array_values(array_filter($own['points'], fn($x) => pm_study_norm((string)$x) !== pm_study_norm($m))) ?: array_values(array_filter([$m])); // not the line it already says
    $caption = trim($h . "\n\n" . ($m !== '' ? $m . "\n\n" : '') . ($pts ? implode("\n", array_map(fn($x) => '• ' . $x, $pts)) . "\n\n" : '') . $cta['line'] . '.');
    return [
        'feed' => ['headline' => $h, 'sub' => $m, 'caption' => $caption, 'bullets' => array_slice(array_values($pts), 0, 3)],
        'stories' => [['headline' => $h, 'sub' => ''], ['headline' => $m !== '' ? $m : $h, 'sub' => $pts[0] ?? ''], ['headline' => $cta['line'], 'sub' => '']],
        'carousel' => ['headline' => $h, 'caption' => $caption, 'slides' => array_map(fn($x) => ['h' => $x, 't' => ''], array_slice(array_values(array_merge($pts, [$cta['line']])), 0, 4))],
        'status' => trim($h . ($m !== '' ? ' ' . $m : '') . ' ' . $cta['line'] . '.'),
        'broadcast' => trim('Hi {first_name}, ' . lcfirst($h) . ($m !== '' ? '. ' . $m : '') . '. ' . $cta['line'] . '. Reply STOP to stop these messages.'),
        'flyer' => ['headline' => $h, 'sub' => $m, 'points' => array_slice(array_values($pts), 0, 3)],
    ];
}

/** One AI call for every item's words. Any string that carries a number the owner never wrote, or that is empty, keeps the plain wording. */
function pm_camp_copy(string $brand, array $own, array $cta): array
{
    $plain = pm_camp_copy_plain($own, $cta);
    if (!function_exists('pm_agents_ready') || !(pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        return $plain + ['ai' => false];
    }
    try {
        $system = 'You write one coordinated marketing campaign for a small business in ' . (pm_brand_profile($brand)['market'] ?? 'Malawi') . ': a feed post, three story frames, a carousel, a WhatsApp Status line, '
            . 'a WhatsApp broadcast and a flyer, all saying the same thing. Use ONLY the facts and the owner\'s words you are given. Never invent numbers, prices, discounts, deadlines, '
            . 'awards, customers or guarantees, and never use the words best, cheapest, fastest, guaranteed or 24/7. Say "we", never "our team". Short plain words, written for a phone screen. '
            . 'Voice: ' . ($own['voice'] !== '' ? $own['voice'] : 'friendly and plain') . '.' . ($own['never'] !== '' ? ' Never: ' . $own['never'] : '') . ' Reply with JSON only.';
        $user = json_encode(['business' => pm_brand_name($brand), 'about' => $own['about'], 'headline' => $own['headline'], 'message' => $own['message'], 'true_facts' => $own['facts'],
                'call_to_action' => $cta['line']], JSON_UNESCAPED_UNICODE)
            . "\nJSON: {\"feed\":{\"headline\":\"under 9 words\",\"sub\":\"one short line\",\"caption\":\"the post text, 2 to 5 short lines, ending with the call to action\",\"bullets\":[\"3 short points\"]},"
            . "\"stories\":[{\"headline\":\"a hook that stops the thumb, under 8 words\",\"sub\":\"\"},{\"headline\":\"the offer in under 9 words\",\"sub\":\"one line\"},{\"headline\":\"what to do now, under 7 words\",\"sub\":\"\"}],"
            . "\"carousel\":{\"headline\":\"cover, under 8 words\",\"caption\":\"2 to 4 lines\",\"slides\":[{\"h\":\"slide title\",\"t\":\"one line\"}] (3 or 4 slides, the last one is the call to action)},"
            . "\"status\":\"one WhatsApp Status line\",\"broadcast\":\"a WhatsApp message to past customers, under 60 words, starting Hi {first_name}, ending with: Reply STOP to stop these messages.\","
            . "\"flyer\":{\"headline\":\"under 8 words\",\"sub\":\"one line\",\"points\":[\"3 short points\"]}}";
        $r = pm_agent_json(pm_claude($system, $user, false, 1800, 'write'));
    } catch (Throwable) {
        $r = null;
    }
    if (!is_array($r)) {
        return $plain + ['ai' => false];
    }
    $src = $own['source'] . ' ' . mb_strtolower($cta['line']);
    $ok = function (mixed $v, int $max) use ($src): string {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : '')));
        return $t === '' || pm_brand_unsourced_number($t, $src) || preg_match('/\b(best|cheapest|fastest|guarantee[sd]?|24\s?\/\s?7)\b/i', $t) ? '' : mb_substr($t, 0, $max);
    };
    $okList = function (mixed $v, int $n, int $max) use ($ok): array {
        return array_slice(array_values(array_filter(array_map(fn($x) => $ok($x, $max), is_array($v) ? $v : []))), 0, $n);
    };
    $out = $plain;
    foreach (['headline' => 80, 'sub' => 140] as $k => $max) {
        $out['feed'][$k] = $ok($r['feed'][$k] ?? '', $max) ?: $plain['feed'][$k];
    }
    $cap = trim((string)preg_replace("/[ \t]+/", ' ', strip_tags(is_scalar($r['feed']['caption'] ?? null) ? (string)$r['feed']['caption'] : '')));
    $out['feed']['caption'] = $cap !== '' && !pm_brand_unsourced_number($cap, $src) ? mb_substr($cap, 0, 900) : $plain['feed']['caption'];
    $out['feed']['bullets'] = $okList($r['feed']['bullets'] ?? [], 3, 70) ?: $plain['feed']['bullets'];
    foreach ([0, 1, 2] as $i) {
        $s = (array)(((array)($r['stories'] ?? []))[$i] ?? []);
        $out['stories'][$i] = ['headline' => $ok($s['headline'] ?? '', 70) ?: $plain['stories'][$i]['headline'], 'sub' => $ok($s['sub'] ?? '', 120)];
    }
    $out['carousel']['headline'] = $ok($r['carousel']['headline'] ?? '', 70) ?: $plain['carousel']['headline'];
    $cc = trim((string)preg_replace("/[ \t]+/", ' ', strip_tags(is_scalar($r['carousel']['caption'] ?? null) ? (string)$r['carousel']['caption'] : '')));
    $out['carousel']['caption'] = $cc !== '' && !pm_brand_unsourced_number($cc, $src) ? mb_substr($cc, 0, 900) : $plain['carousel']['caption'];
    $sl = [];
    foreach (array_slice((array)($r['carousel']['slides'] ?? []), 0, 4) as $s) {
        $h = $ok(is_array($s) ? ($s['h'] ?? '') : '', 60);
        if ($h !== '') {
            $sl[] = ['h' => $h, 't' => $ok($s['t'] ?? '', 120)];
        }
    }
    $out['carousel']['slides'] = count($sl) >= 2 ? $sl : $plain['carousel']['slides'];
    $out['status'] = $ok($r['status'] ?? '', 250) ?: $plain['status'];
    $bc = $ok($r['broadcast'] ?? '', 600);
    $out['broadcast'] = $bc !== '' && str_contains(strtolower($bc), 'stop') ? $bc : $plain['broadcast'];
    $out['flyer'] = ['headline' => $ok($r['flyer']['headline'] ?? '', 70) ?: $plain['flyer']['headline'], 'sub' => $ok($r['flyer']['sub'] ?? '', 140) ?: $plain['flyer']['sub'],
        'points' => $okList($r['flyer']['points'] ?? [], 3, 70) ?: $plain['flyer']['points']];
    return $out + ['ai' => true];
}

/* ---------------- the posts ---------------- */

/** One campaign post (not saved). */
function pm_camp_post(string $brand, array $camp, string $role, array $f): array
{
    $story = str_starts_with($role, 'story');
    $p = [
        'id' => bin2hex(random_bytes(10)), 'brand' => $brand, 'status' => 'draft', 'when' => (string)$f['when'], 'format' => $story ? 'story' : ($role === 'carousel' ? 'carousel' : 'image'),
        'pillar' => defined('PM_LP_PILLAR') ? PM_LP_PILLAR : 'Lead offer', 'cta' => 'offer', 'proof_id' => '', 'caption' => trim((string)($f['caption'] ?? '')), 'hashtags' => [], 'headline' => (string)($f['headline'] ?? ''), 'sub' => (string)($f['sub'] ?? ''),
        'script' => '', 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''),
        'hook_pattern' => 'plain', 'offline' => true, 'tokens' => 0, 'layout' => (string)($f['layout'] ?? 'headline'), 'campaign' => (string)$camp['id'], 'camp_role' => $role,
    ];
    $p['facts'] = array_values((array)($camp['facts'] ?? [])); // what the lint checks claims against: the owner's campaign words, the brain and the offer
    foreach (['button', 'bullets', 'slides'] as $k) {
        if (isset($f[$k])) {
            $p[$k] = $f[$k];
        }
    }
    if (($camp['bg'] ?? '') !== '') {
        $p['bg'] = (string)$camp['bg'];
        $p['bg_ai'] = !empty($camp['bg_ai']);
    }
    if (($camp['offer'] ?? '') !== '') {
        $o = function_exists('pm_lp_get') ? pm_lp_get((string)$camp['offer']) : null;
        if ($o) {
            $p['lead_offer'] = (string)$o['id'];
            $p['lead_keyword'] = (string)($o['keyword'] ?? '');
        }
    }
    $p['alt'] = trim($p['headline'] . ' ' . $p['sub']);
    if (!$story && function_exists('pm_social_hashtags')) {
        $p['hashtags'] = pm_social_hashtags($p, 'facebook');
    }
    $p['segment'] = function_exists('pm_sx_segment') ? pm_sx_segment($p, $brand) : '';
    return $p;
}

/** The post fields of every role, from the words and the call to action. $t = the feed post's time. */
function pm_camp_fields(array $copy, array $cta, string $t, bool $hasBg): array
{
    $ts = strtotime($t) ?: time();
    $at = fn(int $plus) => date('Y-m-d H:i', $ts + $plus);
    $st = $copy['status'];
    return [
        'feed' => ['when' => $at(0), 'caption' => $copy['feed']['caption'], 'headline' => $copy['feed']['headline'], 'sub' => $copy['feed']['sub'], 'layout' => $hasBg ? 'photo' : 'leadad',
            'button' => $cta['button'], 'bullets' => $copy['feed']['bullets']],
        'story1' => ['when' => $at(60), 'caption' => $st, 'headline' => $copy['stories'][0]['headline'], 'sub' => $copy['stories'][0]['sub'], 'layout' => $hasBg ? 'photo' : 'headline'],
        'story2' => ['when' => $at(120), 'caption' => $st, 'headline' => $copy['stories'][1]['headline'], 'sub' => $copy['stories'][1]['sub'], 'layout' => 'offer'],
        'story3' => ['when' => $at(180), 'caption' => $st, 'headline' => $copy['stories'][2]['headline'], 'sub' => $copy['stories'][2]['sub'], 'layout' => 'leadad', 'button' => $cta['button'],
            'bullets' => array_slice($copy['feed']['bullets'], 0, 2)],
        'carousel' => ['when' => $at(86400), 'caption' => $copy['carousel']['caption'], 'headline' => $copy['carousel']['headline'], 'sub' => '', 'layout' => 'cover', 'slides' => $copy['carousel']['slides']],
    ];
}

/** Lints a post like any other and gives it its draft-stage status. */
function pm_camp_lint(array $p, array $pool): array
{
    $pool = array_values(array_filter($pool, fn($q) => ($q['campaign'] ?? '') !== $p['campaign'])); // its sister items say the same thing on purpose
    $why = pm_social_lint((string)$p['caption'], (string)$p['brand'], 'facebook', pm_social_recent_from($pool, (string)$p['brand'], (string)$p['id']), $p);
    $warn = [];
    if (function_exists('pm_lint_ext')) {
        foreach (pm_lint_ext($p, 'facebook', $pool) as $r) {
            if ($r['sev'] === 'block') {
                $why[] = $r['msg'];
            } else {
                $warn[] = $r['msg'];
            }
        }
    }
    $p = pm_social_resolve($p, array_values(array_unique($why)));
    $p['warn'] = $warn;
    return $p;
}

/* ---------------- the picture ---------------- */

/** Saves an uploaded photo as the campaign's picture (JPEG, longest side at most 2400 px). Returns the path relative to the data folder, or ''. */
function pm_camp_save_photo(string $cid, array $file): string
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) && !getenv('PM_TEST') && !defined('PM_CAMP_CLI')) {
        return '';
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || !function_exists('imagecreatetruecolor')) {
        return '';
    }
    $src = match ($info[2]) { IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']), IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false, default => @imagecreatefromjpeg($file['tmp_name']) };
    if (!$src) {
        return '';
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    $k = min(1, 2400 / max($w, $h));
    $dst = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k)));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $out = pm_camp_dir($cid) . '/photo.jpg';
    imagejpeg($dst, $out, 88);
    imagedestroy($src);
    imagedestroy($dst);
    return is_file($out) ? 'campaigns/' . basename(dirname($out)) . '/photo.jpg' : '';
}

/** An AI picture for the campaign (sx_aiimage). [relative path or '', why]. */
function pm_camp_ai_picture(string $brand, string $cid, string $topic): array
{
    if (!function_exists('pm_aiimg_ready') || !function_exists('pm_aiimg_make') || !pm_aiimg_ready($brand)) {
        return ['', 'AI pictures are not set up, so the drawn card is used.'];
    }
    try {
        $brief = function_exists('pm_aiimg_brief') ? (string)pm_aiimg_brief($brand, $topic) : $topic;
        $r = (array)pm_aiimg_make($brand, $brief, 1080, 1920, 'photo');
    } catch (Throwable $e) {
        return ['', 'The AI picture did not work (' . mb_substr($e->getMessage(), 0, 80) . '), so the drawn card is used.'];
    }
    $path = (string)($r['path'] ?? '');
    if (empty($r['ok']) || $path === '' || !is_file($path)) {
        return ['', 'The AI picture did not work' . (($r['why'] ?? '') !== '' ? ' (' . mb_substr((string)$r['why'], 0, 80) . ')' : '') . ', so the drawn card is used.'];
    }
    $ext = preg_match('/\.png$/i', $path) ? 'png' : 'jpg';
    $dst = pm_camp_dir($cid) . '/ai.' . $ext;
    @copy($path, $dst);
    return is_file($dst) ? ['campaigns/' . basename(dirname($dst)) . '/ai.' . $ext, ''] : ['', 'The AI picture could not be saved.'];
}

/* ---------------- the flyer ---------------- */

/** The QR code of $text drawn into $im at (x, y), $size pixels square, on a white quiet zone. False when TCPDF's barcode class is missing. */
function pm_camp_qr($im, string $text, int $x, int $y, int $size): bool
{
    if ($text === '' || !class_exists('TCPDF2DBarcode')) {
        return false;
    }
    $a = (new TCPDF2DBarcode($text, 'QRCODE,M'))->getBarcodeArray();
    $n = (int)($a['num_cols'] ?? 0);
    if ($n < 1) {
        return false;
    }
    $quiet = 2;
    $cell = $size / ($n + 2 * $quiet);
    imagefilledrectangle($im, $x, $y, $x + $size, $y + $size, imagecolorallocate($im, 255, 255, 255));
    $black = imagecolorallocate($im, 0, 0, 0);
    foreach ((array)$a['bcode'] as $r => $row) {
        foreach ((array)$row as $col => $on) {
            if ($on) {
                $x1 = (int)round($x + ($col + $quiet) * $cell);
                $y1 = (int)round($y + ($r + $quiet) * $cell);
                imagefilledrectangle($im, $x1, $y1, (int)round($x + ($col + $quiet + 1) * $cell) - 1, (int)round($y + ($r + $quiet + 1) * $cell) - 1, $black);
            }
        }
    }
    return true;
}

/**
 * Draws the flyer at $W x $H: the picture band (the campaign's picture, else the brand colour with the business name), the logo, a label, the headline, the
 * offer line, three points, and a call-to-action panel with the WhatsApp number, the keyword and a QR code; the website and email at the foot. Returns the PNG path or ''.
 */
function pm_camp_flyer_draw(array $camp, int $W, int $H, string $file): string
{
    if (!function_exists('pm_card_gd_ok') || !pm_card_gd_ok()) {
        return '';
    }
    $brand = (string)$camp['brand'];
    $was = pm_brand();
    pm_brand_set($brand);
    $im = null;
    try {
        $s = pm_settings();
        $f = pm_card_fonts();
        $k = $W / 1080;
        $im = imagecreatetruecolor($W, $H);
        $accent = pm_card_rgb((string)($s['accent_color'] ?? ''));
        $on = pm_card_on($accent);
        $c = ['im' => $im, 'W' => $W, 'H' => $H, 's' => $k, 'f' => $f];
        $col = fn(array $rgb) => imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($im, 0, 0, $col([250, 249, 247]));
        $pad = (int)round(70 * $k);
        $fl = (array)($camp['copy']['flyer'] ?? []);
        $cta = pm_camp_cta($brand, $camp);
        // the picture band
        $bg = function_exists('pm_card_bg_path') ? pm_card_bg_path(['bg' => (string)($camp['bg'] ?? '')]) : '';
        $src = $bg !== '' ? (preg_match('/\.png$/i', $bg) ? @imagecreatefrompng($bg) : @imagecreatefromjpeg($bg)) : false;
        $bandH = (int)round($H * ($src ? 0.36 : 0.25)); // a picture gets room; the name alone needs less
        if ($src) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $sc = max($W / $sw, $bandH / $sh);
            $cw = (int)ceil($W / $sc);
            $ch = (int)ceil($bandH / $sc);
            imagecopyresampled($im, $src, 0, 0, (int)max(0, ($sw - $cw) / 2), (int)max(0, ($sh - $ch) / 2), $W, $bandH, $cw, $ch);
            imagedestroy($src);
            if (!empty($camp['bg_ai'])) {
                $lbl = 'AI picture';
                $px = 20 * $k;
                $lw = pm_card_tw($f['sans'], $px, $lbl);
                pm_card_rrect($c, $W - $pad - $lw - (int)(24 * $k), $bandH - (int)(56 * $k), $W - $pad, $bandH - (int)(16 * $k), (int)(18 * $k), [20, 20, 20]);
                pm_card_draw($c, [$lbl], $f['sans'], $px, [255, 255, 255], $W - $pad - $lw - (int)(12 * $k), $bandH - (int)(50 * $k), 1.2);
            }
        } else {
            imagefilledrectangle($im, 0, 0, $W, $bandH, $col($accent));
            $lg = function_exists('pm_logo_path') ? pm_logo_path() : '';
            $lsz = $lg !== '' ? @getimagesize($lg) : false;
            $nameTop = $lsz ? $pad + (int)round(min(90 * $k, $lsz[1] * min(300 * $k, $lsz[0] * 90 * $k / max(1, $lsz[1])) / max(1, $lsz[0]))) + (int)(40 * $k) : $pad; // under the logo tile
            [$npx, $nl] = pm_card_fit((string)($s['company_name'] ?? pm_brand_name($brand)), $f['serifb'], (int)(110 * $k), (int)(36 * $k), $W - 2 * $pad, max((int)(50 * $k), $bandH - $nameTop - (int)(30 * $k)), 1.1);
            pm_card_draw($c, $nl, $f['serifb'], $npx, $on, $pad, max($nameTop, (int)($bandH - count($nl) * $npx * 1.1 - 30 * $k)), 1.1);
        }
        // the logo on a white tile
        $logo = function_exists('pm_logo_path') ? pm_logo_path() : '';
        if ($logo !== '' && ($li = @imagecreatefrompng($logo))) {
            $lh = (int)(90 * $k);
            $lw = min((int)(300 * $k), (int)round(imagesx($li) * $lh / max(1, imagesy($li))));
            $lh = (int)round(imagesy($li) * $lw / max(1, imagesx($li)));
            pm_card_rrect($c, $pad - (int)(16 * $k), $pad - (int)(16 * $k), $pad + $lw + (int)(16 * $k), $pad + $lh + (int)(16 * $k), (int)(16 * $k), [255, 255, 255]);
            imagecopyresampled($im, $li, $pad, $pad, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
            imagedestroy($li);
        }
        // label, headline, offer line, points
        $y = $bandH + (int)(56 * $k);
        $label = mb_strtoupper(trim((string)($camp['label'] ?? '')) ?: ($src ? (string)($s['company_name'] ?? pm_brand_name($brand)) : (($camp['offer'] ?? '') !== '' ? 'Free offer' : ''))); // the name is already in the band when there is no picture
        if ($label !== '') {
            $lpx = 24 * $k;
            $lw = pm_card_spaced_w($c, $label, $lpx);
            $lhh = (int)round($lpx * 1.2 + 24 * $k);
            pm_card_rrect($c, $pad, $y, $pad + $lw + (int)(40 * $k), $y + $lhh, (int)($lhh / 2), $accent);
            pm_card_spaced($c, $label, $lpx, $on, $pad + (int)(20 * $k), $y + (int)(12 * $k));
            $y += $lhh + (int)(34 * $k);
        }
        $ctaH = (int)round($H * 0.17);
        $footH = (int)round(70 * $k);
        $ctaTop = $H - $footH - $ctaH - (int)(30 * $k);
        $room = $ctaTop - $y - (int)(30 * $k);
        $pts = array_slice(array_values(array_filter(array_map('strval', (array)($fl['points'] ?? [])))), 0, 3);
        $sub = trim((string)($fl['sub'] ?? ''));
        $ptsH = (int)(count($pts) * (34 * 1.25 + 18) * $k) + ($pts ? (int)(30 * $k) : 0); // the points keep their room: the headline shrinks first
        $subRoom = $sub !== '' ? (int)min($room * 0.2, 130 * $k) : 0;
        [$hpx, $hl] = pm_card_fit((string)($fl['headline'] ?? $camp['headline'] ?? ''), $f['serifb'], (int)(118 * $k), (int)(40 * $k), $W - 2 * $pad, max((int)(90 * $k), $room - $ptsH - $subRoom - (int)(22 * $k)), 1.1);
        $y = pm_card_draw($c, $hl, $f['serifb'], $hpx, [22, 24, 29], $pad, $y, 1.1);
        if ($sub !== '') {
            [$spx, $sl] = pm_card_fit($sub, $f['sans'], (int)(42 * $k), (int)(24 * $k), $W - 2 * $pad, $subRoom, 1.3);
            $y = pm_card_draw($c, $sl, $f['sans'], $spx, [70, 74, 82], $pad, $y + (int)(22 * $k), 1.3);
        }
        $y += (int)(30 * $k);
        foreach ($pts as $pt) {
            [$ppx, $pl] = pm_card_fit($pt, $f['sans'], (int)(34 * $k), (int)(22 * $k), $W - 2 * $pad - (int)(60 * $k), (int)(90 * $k), 1.25);
            if ($y + (int)(count($pl) * $ppx * 1.25) > $ctaTop - (int)(20 * $k)) {
                break;
            }
            $d = (int)(26 * $k);
            imagefilledellipse($im, $pad + (int)($d / 2), $y + (int)($ppx * 0.65), $d, $d, $col($accent));
            pm_card_draw($c, ['✓'], $f['sansb'], 18 * $k, $on, $pad + (int)(6 * $k), $y + (int)($ppx * 0.65 - 12 * $k), 1.0);
            $y = pm_card_draw($c, $pl, $f['sans'], $ppx, [40, 42, 48], $pad + (int)(52 * $k), $y, 1.25) + (int)(18 * $k);
        }
        // the call-to-action panel
        pm_card_rrect($c, $pad, $ctaTop, $W - $pad, $ctaTop + $ctaH, (int)(34 * $k), $accent);
        $qr = (int)round($ctaH - 44 * $k);
        $hasQr = pm_camp_qr($im, (string)$cta['link'], $W - $pad - (int)(22 * $k) - $qr, $ctaTop + (int)(22 * $k), $qr);
        $tw = $W - 2 * $pad - (int)(80 * $k) - ($hasQr ? $qr + (int)(30 * $k) : 0);
        $l1 = $cta['line'];
        $l2 = $cta['phone'] !== '' ? 'WhatsApp ' . $cta['phone'] : preg_replace('#^https?://(www\.)?#i', '', (string)($s['website'] ?? ''));
        $room = $ctaH - (int)(48 * $k) - ($l2 !== '' ? (int)(68 * $k) : 0) - ($hasQr ? (int)(46 * $k) : 0); // the headline gets what the other lines leave, so nothing spills out of the panel
        [$apx, $al] = pm_card_fit($l1, $f['serifb'], (int)(64 * $k), (int)(26 * $k), $tw, max((int)(40 * $k), min((int)($ctaH * 0.5), $room)), 1.1);
        $blockH = (int)(count($al) * $apx * 1.1) + ($l2 !== '' ? (int)(54 * $k) : 0) + ($hasQr ? (int)(40 * $k) : 0);
        $ty = $ctaTop + max((int)(24 * $k), (int)(($ctaH - $blockH) / 2));
        $ty = pm_card_draw($c, $al, $f['serifb'], $apx, $on, $pad + (int)(40 * $k), $ty, 1.1);
        if ($l2 !== '') {
            [$bpx, $bl] = pm_card_fit($l2, $f['sansb'], (int)(40 * $k), (int)(22 * $k), $tw, (int)(60 * $k), 1.2);
            $ty = pm_card_draw($c, $bl, $f['sansb'], $bpx, $on, $pad + (int)(40 * $k), $ty + (int)(14 * $k), 1.2);
        }
        if ($hasQr && $ty + (int)(50 * $k) <= $ctaTop + $ctaH - (int)(8 * $k)) {
            pm_card_draw($c, [str_contains((string)$cta['link'], 'wa.me') ? 'Scan to chat →' : 'Scan to find out more →'], $f['sans'], 26 * $k, $on, $pad + (int)(40 * $k), $ty + (int)(12 * $k), 1.2);
        }
        // the foot: how else to reach the business
        $foot = implode('   ·   ', array_filter([preg_replace('#^https?://(www\.)?#i', '', rtrim((string)($s['website'] ?? ''), '/')), (string)($s['email'] ?? ''), mb_substr((string)($s['address'] ?? ''), 0, 60)]));
        if ($foot !== '') {
            [$fpx, $fl2] = pm_card_fit($foot, $f['sans'], (int)(26 * $k), (int)(16 * $k), $W - 2 * $pad, (int)(40 * $k), 1.2);
            pm_card_draw($c, array_slice($fl2, 0, 1), $f['sans'], $fpx, [88, 93, 102], $pad, $H - $footH + (int)(10 * $k), 1.2);
        }
        imagepng($im, $file, 6);
        return is_file($file) && filesize($file) > 0 ? $file : '';
    } catch (Throwable) {
        return '';
    } finally {
        if ($im) {
            imagedestroy($im);
        }
        pm_brand_set($was);
    }
}

/** Makes the flyer files of a campaign: share.png (1080x1350), print.png (A4 shape, 1654x2339) and flyer-a4.pdf / flyer-a5.pdf. Returns [name => path]. */
function pm_camp_flyer(array $camp): array
{
    $dir = pm_camp_dir((string)$camp['id']);
    $out = [];
    if (($f = pm_camp_flyer_draw($camp, 1080, 1350, $dir . '/flyer-share.png')) !== '') {
        $out['share'] = $f;
    }
    if (($p = pm_camp_flyer_draw($camp, 1654, 2339, $dir . '/flyer-print.png')) !== '') {
        $out['print'] = $p;
        if (class_exists('TCPDF')) {
            foreach (['a4' => [210, 297], 'a5' => [148, 210]] as $fmt => [$w, $h]) {
                try {
                    $pdf = new TCPDF('P', 'mm', strtoupper($fmt), true, 'UTF-8', false);
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                    $pdf->SetMargins(0, 0, 0);
                    $pdf->SetAutoPageBreak(false, 0);
                    $pdf->SetTitle(pm_brand_name((string)$camp['brand']) . ': ' . (string)($camp['headline'] ?? 'flyer'));
                    $pdf->AddPage();
                    $pdf->Image($p, 0, 0, $w, $h, 'PNG');
                    $pdf->Output($dir . '/flyer-' . $fmt . '.pdf', 'F');
                    if (is_file($dir . '/flyer-' . $fmt . '.pdf')) {
                        $out[$fmt] = $dir . '/flyer-' . $fmt . '.pdf';
                    }
                } catch (Throwable) {
                }
            }
        }
    }
    return $out;
}

/** The flyer files that exist: name => path. */
function pm_camp_flyer_files(array $camp): array
{
    $dir = PM_DATA . '/campaigns/' . preg_replace('/[^a-z0-9]/', '', (string)$camp['id']);
    $out = [];
    foreach (['share' => 'flyer-share.png', 'print' => 'flyer-print.png', 'a4' => 'flyer-a4.pdf', 'a5' => 'flyer-a5.pdf'] as $k => $n) {
        if (is_file($dir . '/' . $n)) {
            $out[$k] = $dir . '/' . $n;
        }
    }
    return $out;
}

/* ---------------- making a campaign ---------------- */

/**
 * Builds a campaign: the words, the picture, five posts (feed, three story frames, carousel), the flyer and the WhatsApp broadcast draft. Nothing is approved.
 * $in: headline, message, keyword, offer, pic (card|photo|ai), photo ($_FILES entry), when ('Y-m-d H:i'), statuses (lead statuses for the broadcast), name.
 * Returns [campaign or null, message].
 */
function pm_camp_create(string $brand, array $in): array
{
    $own = pm_camp_own($brand, $in);
    if (mb_strlen($own['headline']) < 3) {
        return [null, 'Write what the campaign says (a headline), or pick an offer.'];
    }
    $id = 'cp' . substr(bin2hex(random_bytes(6)), 0, 10);
    $o = ($in['offer'] ?? '') !== '' && function_exists('pm_lp_get') ? pm_lp_get((string)$in['offer']) : null;
    $kw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['keyword'] ?? ''))) ?: strtoupper((string)($o['keyword'] ?? '')) ?: (string)pm_brand_profile($brand)['cta_keyword'];
    $when = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string)($in['when'] ?? '')) ? (string)$in['when'] : date('Y-m-d H:00', time() + 3600);
    $camp = ['id' => $id, 'brand' => $brand, 'name' => mb_substr(trim((string)($in['name'] ?? '')) ?: $own['headline'], 0, 60), 'headline' => $own['headline'], 'message' => $own['message'],
        'keyword' => mb_substr($kw, 0, 12), 'offer' => $o ? (string)$o['id'] : '', 'pic' => isset(PM_CAMP_PICS[$in['pic'] ?? '']) ? (string)$in['pic'] : 'card', 'bg' => '', 'bg_ai' => false,
        'when' => $when, 'status' => 'draft', 'created' => date('Y-m-d H:i'), 'by' => trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner', 'posts' => [], 'wac' => '', 'note' => ''];
    $notes = [];
    if ($camp['pic'] === 'photo') {
        $rel = is_array($in['photo'] ?? null) ? pm_camp_save_photo($id, (array)$in['photo']) : '';
        if ($rel !== '') {
            $camp['bg'] = $rel;
        } else {
            $notes[] = 'No usable photo was uploaded (JPEG, PNG or WebP), so the drawn card is used.';
            $camp['pic'] = 'card';
        }
    } elseif ($camp['pic'] === 'ai') {
        [$rel, $why] = pm_camp_ai_picture($brand, $id, $own['headline'] . ($own['message'] !== '' ? '. ' . $own['message'] : ''));
        if ($rel !== '') {
            $camp['bg'] = $rel;
            $camp['bg_ai'] = true;
        } else {
            $notes[] = $why;
            $camp['pic'] = 'card';
        }
    }
    $camp['facts'] = array_values(array_unique(array_filter(array_map('trim', array_merge([$own['headline'], $own['message'], $own['about']], $own['facts'],
        $o && function_exists('pm_lp_facts') ? (array)pm_lp_facts($o) : [])))));
    $cta = pm_camp_cta($brand, $camp);
    $copy = pm_camp_copy($brand, $own, $cta);
    $camp['ai'] = (bool)$copy['ai'];
    unset($copy['ai']);
    $camp['copy'] = $copy;
    $pool = pm_social_posts();
    $rows = [];
    foreach (pm_camp_fields($copy, $cta, $when, $camp['bg'] !== '') as $role => $f) {
        $p = pm_camp_lint(pm_camp_post($brand, $camp, $role, $f), $pool);
        $rows[] = $p;
        $pool[] = $p;
        $camp['posts'][$role] = $p['id'];
    }
    pm_social_update(function (array $posts) use ($rows) {
        foreach ($rows as $p) {
            $posts[] = $p;
        }
        return $posts;
    });
    foreach ($rows as $p) { // the pictures, drawn now so the campaign screen shows them at once (approval and publishing draw the same thing again)
        if (function_exists('pm_card_render')) {
            pm_card_render($p, $p['format'] === 'story' ? 'story' : ($p['format'] === 'carousel' ? '4x5' : 'sq'));
        }
    }
    $files = pm_camp_flyer($camp);
    if (!isset($files['share'])) {
        $notes[] = 'The flyer could not be drawn (the server needs GD and the fonts).';
    }
    $camp['wac'] = pm_camp_broadcast_draft($camp, (array)($in['statuses'] ?? ['contacted', 'replied', 'proposal', 'won']), $files['share'] ?? '');
    $camp['note'] = implode(' ', $notes);
    pm_camp_update(function (array $all) use ($camp) {
        $all[] = $camp;
        return $all;
    });
    $held = count(array_filter($rows, fn($p) => !empty($p['lint'])));
    return [$camp, 'Campaign ready to check: ' . count($rows) . ' posts, a flyer' . ($camp['wac'] !== '' ? ' and a WhatsApp broadcast' : '') . ($camp['ai'] ? ', written by the AI from your facts' : ', from your own words')
        . '. Nothing goes out until you approve it.' . ($held ? " $held item(s) need an edit first." : '') . ($notes ? ' ' . implode(' ', $notes) : '')];
}

/** The campaign's WhatsApp broadcast as a draft of sx_wacampaign (opted-in leads only), with the flyer picture. Returns its id, or '' when the module is missing. */
function pm_camp_broadcast_draft(array $camp, array $statuses, string $image): string
{
    if (!function_exists('pm_wac_update') || !defined('PM_WAC_AUDIENCE_STATUSES')) {
        return '';
    }
    $st = array_values(array_intersect(array_map('strval', $statuses), array_keys(PM_WAC_AUDIENCE_STATUSES)));
    $row = ['id' => 'wc' . substr(md5($camp['id'] . microtime(true)), 0, 10), 'brand' => $camp['brand'], 'name' => 'Campaign: ' . mb_substr((string)$camp['name'], 0, 48),
        'text' => mb_substr(str_replace('{first_name}', '{first_name}', (string)($camp['copy']['broadcast'] ?? '')), 0, 700),
        'aud' => ['statuses' => $st ?: ['replied', 'won'], 'group' => '', 'min_score' => 0, 'optin_only' => true], 'template' => '', 'send_from' => (string)$camp['when'],
        'status' => 'draft', 'recipients' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)$camp['by'], 'campaign' => (string)$camp['id'],
        'image' => $image !== '' ? 'campaigns/' . basename(dirname($image)) . '/' . basename($image) : ''];
    pm_wac_update(function (array $rows) use ($row) {
        $rows[] = $row;
        return $rows;
    });
    return $row['id'];
}

/* ---------------- approving and the state of every item ---------------- */

/**
 * Approves every item of a campaign at once (approvers only): the posts through the normal approval (lint, picture), the broadcast through the WhatsApp
 * campaign rules. Items that fail a check stay as drafts with the reason. Returns [approved count, held count, message].
 */
function pm_camp_approve(string $brand, string $id): array
{
    $camp = pm_camp_get($id);
    if (!$camp || ($camp['brand'] ?? '') !== $brand) {
        return [0, 0, 'That campaign is gone.'];
    }
    if (!pm_social_can_approve()) {
        return [0, 0, 'You are an editor: an approver has to approve campaigns.'];
    }
    $ids = array_values(array_map('strval', (array)$camp['posts']));
    $cards = [];
    foreach (pm_social_posts() as $p) {
        if (in_array((string)$p['id'], $ids, true) && in_array($p['status'] ?? '', ['draft', 'review'], true)) {
            $cards[$p['id']] = pm_social_prepare_card($p); // drawn before the lock: drawing is slow
        }
    }
    $ok = 0;
    $held = 0;
    $why = [];
    pm_social_update(function (array $posts) use ($ids, $cards, &$ok, &$held, &$why) {
        foreach ($posts as $i => $p) {
            if (in_array((string)$p['id'], $ids, true) && in_array($p['status'] ?? '', ['draft', 'review', 'needs_edit'], true)) {
                [$np, $m, $good] = pm_social_approve($p, $cards[$p['id']] ?? null);
                $posts[$i] = $np;
                if ($good) {
                    $ok++;
                } else {
                    $held++;
                    $why[] = (PM_CAMP_ROLES[$p['camp_role'] ?? ''] ?? 'A post') . ': ' . $m;
                }
            }
        }
        return $posts;
    });
    $bmsg = '';
    if (($camp['wac'] ?? '') !== '' && function_exists('pm_wac_approve')) {
        [$bok, $bm] = pm_wac_approve($brand, (string)$camp['wac']);
        $bmsg = ' WhatsApp broadcast: ' . $bm;
        if ($bok) {
            $ok++;
        } else {
            $held++;
            $bmsg = '';
            $why[] = 'WhatsApp broadcast: ' . $bm;
        }
    }
    pm_camp_patch($id, function (array $c) use ($held) {
        $c['status'] = $held ? 'partly approved' : 'approved';
        $c['approved_at'] = date('Y-m-d H:i');
        $c['approved_by'] = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
        return $c;
    });
    return [$ok, $held, rtrim("$ok item(s) approved" . ($held ? ", $held held: " . implode(' ', array_slice($why, 0, 3)) : ''), '.') . '.' . $bmsg];
}

/** Every item of a campaign with its state in words: [['label','state' (draft|approved|published|waiting|failed|off),'note','id','role']]. */
function pm_camp_items(array $camp): array
{
    $posts = pm_camp_posts($camp);
    $out = [];
    foreach (PM_CAMP_ROLES as $role => $label) {
        $p = $posts[$role] ?? null;
        if (!$p) {
            continue;
        }
        $st = (string)($p['status'] ?? 'draft');
        $state = match (true) { $st === 'published' => 'published', $st === 'approved' => 'approved', in_array($st, ['failed', 'needs_check'], true) => 'failed', in_array($st, ['needs_edit', 'needs_video'], true) => 'held', default => 'draft' };
        $note = $state === 'failed' ? (string)($p['error'] ?? '') : ($state === 'held' ? implode(' ', (array)($p['lint'] ?? [])) : ($state === 'approved' ? 'goes out ' . date('D j M, H:i', strtotime((string)$p['when'])) : ''));
        $out[] = ['label' => $label, 'state' => $state, 'note' => $note, 'id' => (string)$p['id'], 'role' => $role, 'post' => $p];
        foreach ((array)($p['channels'] ?? []) as $ch => $e) { // hand-post tasks this post made (WhatsApp Status, X, Google...)
            if (($e['mode'] ?? '') === 'manual' && in_array($e['state'] ?? '', ['todo', 'done', 'skipped'], true)) {
                $out[] = ['label' => '↳ ' . (defined('PM_CH_LABEL') ? (PM_CH_LABEL[$ch] ?? $ch) : $ch), 'state' => ($e['state'] ?? '') === 'todo' ? 'waiting' : 'published', 'note' => ($e['state'] ?? '') === 'todo' ? 'hand-post task in Channels' : (string)($e['state'] ?? ''),
                    'id' => (string)$p['id'], 'role' => $role . ':' . $ch, 'post' => null];
            }
        }
    }
    if (($camp['wac'] ?? '') !== '' && function_exists('pm_wac_get') && ($w = pm_wac_get((string)$camp['wac']))) {
        $n = pm_wac_counts($w);
        $ready = function_exists('pm_wa_biz_cfg') && pm_wa_biz_cfg((string)$camp['brand'])['ready'];
        $state = !$ready ? 'off' : match ((string)($w['status'] ?? 'draft')) { 'done' => 'published', 'approved', 'sending' => 'approved', 'paused', 'cancelled' => 'failed', default => 'draft' };
        $out[] = ['label' => 'WhatsApp broadcast', 'state' => $state, 'note' => !$ready ? 'WhatsApp Business is not connected for this business, so it stays a draft.'
            : ($n['sent'] || $n['pending'] ? $n['sent'] . ' sent, ' . $n['pending'] . ' waiting, ' . $n['skipped'] . ' skipped' : (string)($w['error'] ?? '')), 'id' => (string)$w['id'], 'role' => 'wac', 'post' => null];
    }
    return $out;
}

/* ---------------- forms (POST action=social_ext&do=camp_*) ---------------- */

function pm_camp_back(string $msg, string $kind = 'ok', string $id = ''): array
{
    return ['msg' => $msg, 'kind' => $kind, 'to' => 'social&view=campaign' . ($id !== '' ? '&c=' . $id : '')];
}

function pm_do_camp_create(string $vb): array
{
    @set_time_limit(300);
    $when = trim((string)($_POST['when'] ?? ''));
    $ts = $when !== '' ? strtotime(str_replace('T', ' ', $when)) : false;
    $in = ['name' => (string)($_POST['name'] ?? ''), 'headline' => mb_substr(trim((string)($_POST['headline'] ?? '')), 0, 90), 'message' => mb_substr(trim((string)($_POST['message'] ?? '')), 0, 240),
        'keyword' => (string)($_POST['keyword'] ?? ''), 'offer' => preg_replace('/[^a-f0-9]/', '', (string)($_POST['offer'] ?? '')), 'pic' => (string)($_POST['pic'] ?? 'card'),
        'photo' => $_FILES['photo'] ?? null, 'when' => $ts && $ts > time() - 3600 ? date('Y-m-d H:i', $ts) : '', 'statuses' => (array)($_POST['statuses'] ?? [])];
    [$camp, $msg] = pm_camp_create($vb, $in);
    return pm_camp_back($msg, $camp ? 'ok' : 'err', $camp ? (string)$camp['id'] : '');
}

function pm_do_camp_approve(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    [$ok, $held, $msg] = pm_camp_approve($vb, $id);
    return pm_camp_back($msg, $ok && !$held ? 'ok' : ($ok ? 'ok' : 'err'), $id);
}

/** Saves the words of one campaign post (before approval), then redraws it. */
function pm_do_camp_save(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $pid = preg_replace('/[^a-f0-9]/', '', (string)($_POST['post'] ?? ''));
    $camp = pm_camp_get($id);
    if (!$camp || ($camp['brand'] ?? '') !== $vb || !in_array($pid, array_map('strval', (array)$camp['posts']), true)) {
        return pm_camp_back('That campaign item is gone.', 'err', $id);
    }
    $msg = 'Saved.';
    pm_social_update(function (array $posts) use ($pid, &$msg) {
        foreach ($posts as $i => $p) {
            if (($p['id'] ?? '') !== $pid) {
                continue;
            }
            if (!in_array($p['status'] ?? '', ['draft', 'review', 'needs_edit', 'approved'], true)) {
                $msg = 'It has gone out already: it cannot be changed here.';
                continue;
            }
            foreach (['headline' => 90, 'sub' => 160, 'caption' => 1500] as $k => $max) {
                if (isset($_POST[$k])) {
                    $p[$k] = mb_substr(trim((string)$_POST[$k]), 0, $max);
                }
            }
            $p['alt'] = trim($p['headline'] . ' ' . $p['sub']);
            $posts[$i] = pm_social_resolve($p, pm_social_lint_post($p)); // an approved item that changed goes back to draft
            $msg = $posts[$i]['lint'] ? 'Saved, but it needs an edit: ' . implode(' ', $posts[$i]['lint']) : (($p['status'] ?? '') === 'approved' && $posts[$i]['status'] === 'draft' ? 'Saved. It changed after approval, so approve it again.' : 'Saved.');
        }
        return $posts;
    });
    foreach (pm_social_posts() as $p) { // redraw its pictures so the preview and the download show the new words
        if (($p['id'] ?? '') === $pid && function_exists('pm_card_render')) {
            if (($p['format'] ?? '') === 'story') {
                pm_card_render($p, 'story');
            } elseif (($p['format'] ?? '') === 'carousel') {
                pm_card_slides($p, '4x5');
            } else {
                pm_card_render($p, 'sq');
                pm_card_render($p, '4x5');
            }
        }
    }
    return pm_camp_back($msg, str_starts_with($msg, 'Saved.') ? 'ok' : 'err', $id);
}

/** Draws the flyer again (after the business details or the words changed). */
function pm_do_camp_flyer(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $camp = pm_camp_get($id);
    if (!$camp || ($camp['brand'] ?? '') !== $vb) {
        return pm_camp_back('That campaign is gone.', 'err');
    }
    foreach (['headline' => 90, 'sub' => 160] as $k => $max) {
        if (isset($_POST['f_' . $k])) {
            $camp['copy']['flyer'][$k] = mb_substr(trim((string)$_POST['f_' . $k]), 0, $max);
        }
    }
    if (isset($_POST['f_points'])) {
        $camp['copy']['flyer']['points'] = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$_POST['f_points']) ?: []))), 0, 3);
    }
    pm_camp_patch($id, fn(array $c) => array_replace($c, ['copy' => $camp['copy']]));
    $files = pm_camp_flyer($camp);
    return pm_camp_back($files ? 'Flyer drawn again.' : 'The flyer could not be drawn.', $files ? 'ok' : 'err', $id);
}

/** Deletes a campaign that has not gone out: its draft posts, its broadcast draft and its files. Published posts stay on the Page and in the plan. */
function pm_do_camp_delete(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $camp = pm_camp_get($id);
    if (!$camp || ($camp['brand'] ?? '') !== $vb) {
        return pm_camp_back('That campaign is gone.', 'err');
    }
    $ids = array_values(array_map('strval', (array)$camp['posts']));
    pm_social_update(fn(array $posts) => array_values(array_filter($posts, fn($p) => !(in_array((string)($p['id'] ?? ''), $ids, true) && in_array($p['status'] ?? '', ['draft', 'review', 'needs_edit', 'approved'], true)))));
    if (($camp['wac'] ?? '') !== '' && function_exists('pm_wac_update')) {
        pm_wac_update(fn(array $rows) => array_values(array_filter($rows, fn($r) => !(($r['id'] ?? '') === $camp['wac'] && in_array($r['status'] ?? '', ['draft', 'approved'], true)))));
    }
    pm_camp_update(fn(array $rows) => array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id)));
    foreach (glob(PM_DATA . '/campaigns/' . $id . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(PM_DATA . '/campaigns/' . $id);
    return pm_camp_back('Campaign deleted. Anything already published stays on the Page.');
}

/* ---------------- downloads (GET ?camp=<id>&f=<file>) ---------------- */

/** Serves a campaign file to someone who may open that business: the flyer files, or every story frame and the flyer as one zip (f=pack). */
function pm_camp_serve(): void
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_GET['camp'] ?? ''));
    $f = (string)($_GET['f'] ?? '');
    $camp = $id !== '' ? pm_camp_get($id) : null;
    if (!$camp || (function_exists('pm_user_can_brand') && !pm_user_can_brand((string)$camp['brand']))) {
        http_response_code(404);
        exit('Not found');
    }
    $files = pm_camp_flyer_files($camp);
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', pm_brand_name((string)$camp['brand']));
    if ($f === 'pack') {
        $paths = [];
        foreach (pm_camp_posts($camp) as $role => $p) {
            if (str_starts_with($role, 'story')) {
                $s = function_exists('pm_ch_story_pic') ? pm_ch_story_pic($p, '') : '';
                if ($s !== '' && is_file($s)) {
                    $paths['story-' . substr($role, 5) . '.png'] = $s;
                }
            }
        }
        foreach (['share' => 'flyer.png', 'a4' => 'flyer-A4.pdf'] as $k => $n) {
            if (isset($files[$k])) {
                $paths[$n] = $files[$k];
            }
        }
        if (!$paths || !class_exists('ZipArchive')) {
            http_response_code($paths ? 500 : 404);
            exit($paths ? 'The server has no zip support (php-zip): download the files one by one.' : 'Nothing to download yet.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cp');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($paths as $n => $p) {
            $zip->addFile($p, $n);
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '-campaign.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    if (!isset($files[$f])) {
        http_response_code(404);
        exit('Not found');
    }
    $pdf = str_ends_with($files[$f], '.pdf');
    header('Content-Type: ' . ($pdf ? 'application/pdf' : 'image/png'));
    header('Cache-Control: private, max-age=60');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '-flyer-' . $f . ($pdf ? '.pdf' : '.png') . '"');
    readfile($files[$f]);
    exit;
}
