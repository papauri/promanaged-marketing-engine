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

const PM_CAMP_PICS = ['card' => 'Drawn card in the brand colours (free)', 'photo' => 'My photo (free)', 'ailib' => 'An AI picture made before (free)', 'ai' => 'A new AI picture (about 5 US cents)'];

/** The business's AI story pictures already paid for, least used first: [path, ...]. Reusing one costs nothing. */
function pm_camp_ai_library(string $brand): array
{
    if (!function_exists('pm_aiimg_dir')) {
        return [];
    }
    $uses = (array)(pm_load('aiimg_uses', fn() => [])[$brand] ?? []);
    $files = array_values(array_filter(glob(pm_aiimg_dir($brand) . '/*.png') ?: [], function ($f) {
        $sz = @getimagesize($f);
        return $sz && $sz[0] === 1080 && $sz[1] === 1920;
    }));
    usort($files, fn($a, $b) => [(int)($uses[basename($a)] ?? 0), filemtime($a)] <=> [(int)($uses[basename($b)] ?? 0), filemtime($b)]);
    return $files;
}

/** Puts the least-used AI picture of the business into the campaign: [relative path, why it could not]. */
function pm_camp_ai_reuse(string $brand, string $cid): array
{
    $lib = pm_camp_ai_library($brand);
    if (!$lib) {
        return ['', 'No AI picture has been made for this business yet, so the drawn card is used.'];
    }
    $dst = pm_camp_dir($cid) . '/ai.png';
    if (!@copy($lib[0], $dst)) {
        return ['', 'The AI picture could not be copied.'];
    }
    pm_update('aiimg_uses', function (array $all) use ($brand, $lib) {
        $all[$brand][basename($lib[0])] = (int)($all[$brand][basename($lib[0])] ?? 0) + 1;
        return $all;
    }, fn() => []);
    return ['campaigns/' . $cid . '/ai.png', ''];
}
/** The parts a campaign can have; the owner ticks the ones to make (old campaigns without a list had every part). */
const PM_CAMP_PARTS = ['feed' => 'Feed post (Facebook, Instagram, LinkedIn)', 'stories' => '3 story frames (Facebook and Instagram stories)', 'carousel' => 'Carousel the next day',
    'status' => 'WhatsApp Status (the story frames, posted by hand)', 'broadcast' => 'WhatsApp broadcast to people who agreed', 'flyer' => 'Flyer (print and share)'];

/** Where the posts go. 'others' = the hand-post channels beyond the three (X, Google profile, TikTok, YouTube Shorts, your own LinkedIn). */
const PM_CAMP_NETS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'others' => 'Other channels posted by hand (X, Google profile, TikTok…)'];
const PM_CAMP_OTHERS = ['x', 'google', 'tiktok', 'youtube_short', 'linkedin_personal'];

/** The parts of a campaign. */
function pm_camp_parts(array $camp): array
{
    return isset($camp['parts']) ? array_values(array_intersect(array_keys(PM_CAMP_PARTS), (array)$camp['parts'])) : array_keys(PM_CAMP_PARTS);
}

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
    // what the person gets for messaging: the offer, else the business's free first step
    $what = trim((string)($o['title'] ?? '')) ?: trim((string)pm_brand_profile($brand)['magnet']);
    $what = $what !== '' ? mb_strtoupper(mb_substr($what, 0, 1)) . mb_substr($what, 1) : '';
    $to = $phone !== '' ? ' to ' . $phone : '';
    $send = $kw !== '' ? 'WhatsApp the word ' . $kw . $to : 'Send us a WhatsApp message' . $to;
    $whatLc = preg_match('/^\p{Lu}\p{Ll}/u', $what) ? mb_strtolower(mb_substr($what, 0, 1)) . mb_substr($what, 1) : $what; // "Free check" -> "free check", "IT check" stays
    $line = ($what !== '' ? $what . ': ' . $send : $send) . ' and a person from ' . pm_brand_name($brand) . ' will reply';
    return ['site' => $site, 'keyword' => $kw, 'what' => $what, 'what_lc' => $whatLc, 'line' => $line,
        'short' => $kw !== '' ? 'WhatsApp ' . $kw . $to : 'WhatsApp us' . $to, // a story frame, a slide, the flyer
        'reply' => $kw !== '' ? 'Reply ' . $kw . ' to this message' . ($what !== '' ? ' for ' . $whatLc : '') : 'Reply to this message' . ($what !== '' ? ' for ' . $whatLc : ''), // the broadcast is already a chat
        'button' => $kw !== '' ? 'WhatsApp ' . $kw : 'Send WhatsApp message', 'link' => $page !== '' ? $page : ($wa !== '' ? $wa : $site), 'wa' => $wa, 'phone' => $phone];
}

/** Makes sure a text ends with the specific call to action (the AI sometimes writes a vague "message us"): adds $line when the number or keyword is missing. */
function pm_camp_with_cta(string $t, array $cta, string $line): string
{
    $need = $cta['phone'] !== '' ? preg_replace('/\D/', '', $cta['phone']) : $cta['keyword'];
    $have = $cta['phone'] !== '' ? str_contains(preg_replace('/\D/', '', $t), $need) : ($need === '' || str_contains($t, $need));
    if ($have) {
        return $t;
    }
    $t = rtrim($t);
    return $t . ($t === '' || preg_match('/[.!?]$/u', $t) ? '' : '.') . "\n\n" . $line . '.';
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
        'stories' => [['headline' => $h, 'sub' => ''], ['headline' => $m !== '' ? $m : $h, 'sub' => $pts[0] ?? ''], ['headline' => $cta['short'], 'sub' => $cta['what']]],
        'carousel' => ['headline' => $h, 'caption' => $caption, 'slides' => array_merge(array_map(fn($x) => ['h' => $x, 't' => ''], array_slice(array_values($pts), 0, 3)), [['h' => $cta['short'], 't' => $cta['what']]])],
        'status' => trim($h . ($m !== '' ? ' ' . $m : '') . ' ' . $cta['line'] . '.'),
        'broadcast' => trim('Hi {first_name}, ' . lcfirst($h) . ($m !== '' ? '. ' . $m : '') . '. ' . $cta['reply'] . '. Reply STOP to stop these messages.'),
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
            . 'awards, customers or guarantees, and never use the words best, cheapest, fastest, guaranteed or 24/7. Say "we", never "our team". Short plain words, written for a phone screen. Always tell people exactly what to do, using the call to action you are given: never a vague "contact us" or a keyword without the number. '
            . 'Voice: ' . ($own['voice'] !== '' ? $own['voice'] : 'friendly and plain') . '.' . ($own['never'] !== '' ? ' Never: ' . $own['never'] : '') . ' Reply with JSON only.';
        $user = json_encode(['business' => pm_brand_name($brand), 'about' => $own['about'], 'headline' => $own['headline'], 'message' => $own['message'], 'true_facts' => $own['facts'],
                'call_to_action' => $cta['line'], 'reply_line' => $cta['reply']], JSON_UNESCAPED_UNICODE)
            . "\nJSON: {\"feed\":{\"headline\":\"under 9 words\",\"sub\":\"one short line\",\"caption\":\"the post text, 2 to 5 short lines, ending with call_to_action word for word\",\"bullets\":[\"3 short points\"]},"
            . "\"stories\":[{\"headline\":\"a hook that stops the thumb, under 8 words\",\"sub\":\"\"},{\"headline\":\"the offer in under 9 words\",\"sub\":\"one line\"},{\"headline\":\"why it is worth it, under 8 words\",\"sub\":\"\"}],"
            . "\"carousel\":{\"headline\":\"cover, under 8 words\",\"caption\":\"2 to 4 lines\",\"slides\":[{\"h\":\"slide title\",\"t\":\"one line\"}] (2 or 3 slides; the call to action slide is added for you)},"
            . "\"status\":\"one WhatsApp Status line ending with call_to_action word for word\",\"broadcast\":\"a WhatsApp message to past customers, under 60 words, starting Hi {first_name}, then reply_line word for word, ending with: Reply STOP to stop these messages.\","
            . "\"flyer\":{\"headline\":\"under 8 words\",\"sub\":\"one line\",\"points\":[\"3 short points\"]}}";
        $r = pm_agent_json(pm_claude($system, $user, false, 1800, 'write'));
    } catch (Throwable) {
        $r = null;
    }
    if (!is_array($r)) {
        return $plain + ['ai' => false];
    }
    $src = $own['source'] . ' ' . mb_strtolower($cta['line'] . ' ' . $cta['reply'] . ' ' . $cta['short']);
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
    $out['stories'][2] = $plain['stories'][2]; // the last frame always says exactly what to do: the keyword, the number, what they get
    $out['carousel']['headline'] = $ok($r['carousel']['headline'] ?? '', 70) ?: $plain['carousel']['headline'];
    $cc = trim((string)preg_replace("/[ \t]+/", ' ', strip_tags(is_scalar($r['carousel']['caption'] ?? null) ? (string)$r['carousel']['caption'] : '')));
    $out['carousel']['caption'] = $cc !== '' && !pm_brand_unsourced_number($cc, $src) ? mb_substr($cc, 0, 900) : $plain['carousel']['caption'];
    $sl = [];
    foreach (array_slice((array)($r['carousel']['slides'] ?? []), 0, 3) as $s) {
        $h = $ok(is_array($s) ? ($s['h'] ?? '') : '', 60);
        if ($h !== '') {
            $sl[] = ['h' => $h, 't' => $ok($s['t'] ?? '', 120)];
        }
    }
    $out['carousel']['slides'] = $sl ? array_merge($sl, [end($plain['carousel']['slides'])]) : $plain['carousel']['slides'];
    $out['status'] = $ok($r['status'] ?? '', 250) ?: $plain['status'];
    $bc = $ok($r['broadcast'] ?? '', 600);
    $out['broadcast'] = $bc !== '' && str_contains(strtolower($bc), 'stop') && ($cta['keyword'] === '' || str_contains($bc, $cta['keyword'])) ? $bc : $plain['broadcast'];
    foreach (['feed', 'carousel'] as $k) { // a caption without the number or keyword gets the full call to action
        $out[$k]['caption'] = pm_camp_with_cta($out[$k]['caption'], $cta, $cta['line']);
    }
    $out['status'] = pm_camp_with_cta($out['status'], $cta, $cta['line']);
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
    pm_update('aiimg_uses', function (array $all) use ($brand, $path) { // used once already: a library pick prefers pictures used less
        $all[$brand][basename($path)] = (int)($all[$brand][basename($path)] ?? 0) + 1;
        return $all;
    }, fn() => []);
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
        $l1 = $cta['keyword'] !== '' ? 'WhatsApp the word ' . $cta['keyword'] : 'Send us a WhatsApp message';
        $l2 = $cta['phone'] !== '' ? 'to ' . $cta['phone'] . ($cta['what'] !== '' ? ' for ' . $cta['what_lc'] : '') : preg_replace('#^https?://(www\.)?#i', '', (string)($s['website'] ?? ''));
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
    $parts = array_values(array_intersect(array_keys(PM_CAMP_PARTS), (array)($in['parts'] ?? array_keys(PM_CAMP_PARTS))));
    $notes = [];
    if (in_array('status', $parts, true) && !in_array('stories', $parts, true)) {
        $parts[] = 'stories'; // WhatsApp Status uses the story frames
        $notes[] = 'WhatsApp Status uses the story frames, so they were made too.';
    }
    if (!array_diff($parts, ['status'])) {
        return [null, 'Tick at least one part: the feed post, the stories, the carousel, the WhatsApp broadcast or the flyer.'];
    }
    $nets = array_values(array_intersect(array_keys(PM_CAMP_NETS), (array)($in['nets'] ?? array_keys(PM_CAMP_NETS))));
    if (array_intersect(['feed', 'stories', 'carousel'], $parts) && !array_intersect(['facebook', 'instagram', 'linkedin'], $nets)) {
        return [null, 'Tick Facebook, Instagram or LinkedIn for the posts, or untick the feed post, the stories and the carousel.'];
    }
    $camp = ['id' => $id, 'brand' => $brand, 'name' => mb_substr(trim((string)($in['name'] ?? '')) ?: $own['headline'], 0, 60), 'headline' => $own['headline'], 'message' => $own['message'],
        'keyword' => mb_substr($kw, 0, 12), 'offer' => $o ? (string)$o['id'] : '', 'pic' => isset(PM_CAMP_PICS[$in['pic'] ?? '']) ? (string)$in['pic'] : 'card', 'bg' => '', 'bg_ai' => false,
        'when' => $when, 'status' => 'draft', 'created' => date('Y-m-d H:i'), 'by' => trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner', 'posts' => [], 'wac' => '', 'note' => '', 'parts' => $parts, 'nets' => $nets];
    if ($camp['pic'] === 'photo') {
        $rel = is_array($in['photo'] ?? null) ? pm_camp_save_photo($id, (array)$in['photo']) : '';
        if ($rel !== '') {
            $camp['bg'] = $rel;
        } else {
            $notes[] = 'No usable photo was uploaded (JPEG, PNG or WebP), so the drawn card is used.';
            $camp['pic'] = 'card';
        }
    } elseif ($camp['pic'] === 'ai' || $camp['pic'] === 'ailib') {
        [$rel, $why] = $camp['pic'] === 'ailib' ? pm_camp_ai_reuse($brand, $id) : pm_camp_ai_picture($brand, $id, $own['headline'] . ($own['message'] !== '' ? '. ' . $own['message'] : ''));
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
    $camp['facts'] = array_values(array_unique(array_merge($camp['facts'], [$cta['line'], $cta['short'], $cta['reply']]))); // the number in the call to action is a fact
    $copy = pm_camp_copy($brand, $own, $cta);
    $camp['ai'] = (bool)$copy['ai'];
    unset($copy['ai']);
    $camp['copy'] = $copy;
    $pool = pm_social_posts();
    $rows = [];
    foreach (pm_camp_fields($copy, $cta, $when, $camp['bg'] !== '') as $role => $f) {
        if (!in_array(str_starts_with($role, 'story') ? 'stories' : $role, $parts, true)) {
            continue;
        }
        $p = pm_camp_post($brand, $camp, $role, $f);
        $wa = array_intersect(['status', 'broadcast'], $parts);
        $skip = array_merge(in_array('status', $parts, true) ? [] : ['whatsapp_status'], $wa ? [] : ['whatsapp_channel'], array_values(array_diff(['facebook', 'instagram', 'linkedin'], $nets)),
            in_array('others', $nets, true) ? [] : PM_CAMP_OTHERS);
        if ($skip) {
            $p['ch_skip'] = $skip; // the channels the owner did not tick: no publishing there, no hand-post task
        }
        $p = pm_camp_lint($p, $pool);
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
    $files = array_intersect(['flyer', 'broadcast'], $parts) ? pm_camp_flyer($camp) : []; // the broadcast sends the flyer's share picture
    if (array_intersect(['flyer', 'broadcast'], $parts) && !isset($files['share'])) {
        $notes[] = 'The flyer could not be drawn (the server needs GD and the fonts).';
    }
    $camp['wac'] = in_array('broadcast', $parts, true) ? pm_camp_broadcast_draft($camp, (array)($in['statuses'] ?? ['contacted', 'replied', 'proposal', 'won']), $files['share'] ?? '') : '';
    $camp['note'] = implode(' ', $notes);
    pm_camp_update(function (array $all) use ($camp) {
        $all[] = $camp;
        return $all;
    });
    $held = count(array_filter($rows, fn($p) => !empty($p['lint'])));
    $made = array_filter([$rows ? count($rows) . ' post' . (count($rows) === 1 ? '' : 's') : '', in_array('flyer', $parts, true) ? 'a flyer' : '', $camp['wac'] !== '' ? 'a WhatsApp broadcast' : '']);
    return [$camp, 'Campaign ready to check: ' . implode(', ', $made) . ($camp['ai'] ? ', written by the AI from your facts' : ', from your own words')
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

/* ---------------- results and where a campaign stands ---------------- */

/** What a campaign has brought so far: enquiries from its posts (or its offer since it started), reactions, comments and shares, WhatsApp messages sent. */
function pm_camp_results(array $camp): array
{
    $ids = array_values(array_map('strval', (array)($camp['posts'] ?? [])));
    $since = strtotime((string)($camp['when'] ?? '')) ?: 0;
    $tag = ($camp['offer'] ?? '') !== '' ? 'offer-' . substr((string)$camp['offer'], 0, 10) : '';
    $leads = 0;
    foreach ((array)pm_load('leads', fn() => []) as $l) {
        if (!is_array($l) || ($l['brand'] ?? '') !== ($camp['brand'] ?? '')) {
            continue;
        }
        if (in_array((string)($l['source_post'] ?? ''), $ids, true) || ($tag !== '' && str_starts_with((string)($l['src_tag'] ?? ''), $tag) && (strtotime((string)($l['created'] ?? '')) ?: 0) >= $since)) {
            $leads++;
        }
    }
    $out = ['leads' => $leads, 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'measured' => 0, 'sent' => 0];
    foreach (pm_camp_posts($camp) as $p) {
        $m = (array)($p['metrics']['d7'] ?? $p['metrics']['d3'] ?? $p['metrics']['h24'] ?? []);
        if ($m) {
            $out['measured']++;
            foreach (['reactions', 'comments', 'shares'] as $k) {
                $out[$k] += (int)($m[$k] ?? 0);
            }
        }
    }
    if (($camp['wac'] ?? '') !== '' && function_exists('pm_wac_get') && ($w = pm_wac_get((string)$camp['wac']))) {
        $out['sent'] = (int)pm_wac_counts($w)['sent'];
    }
    return $out;
}

/** "in 3 hours", "in 2 days", "now". */
function pm_camp_in(int $ts): string
{
    $s = $ts - time();
    return $s <= 60 ? 'now' : ($s < 5400 ? 'in ' . max(1, (int)round($s / 60)) . ' minutes' : ($s < 129600 ? 'in ' . (int)round($s / 3600) . ' hours' : 'in ' . (int)round($s / 86400) . ' days'));
}

/**
 * Where a campaign stands, in plain words (no AI): 'next' = the one thing to do now, 'steps' = [[label, done|now|todo, detail]],
 * 'times' = [[label, 'Y-m-d H:i', state]] when each item goes out, 'results' = pm_camp_results().
 */
function pm_camp_guide(array $camp, array $items): array
{
    $by = fn(string $s) => array_values(array_filter($items, fn($i) => $i['state'] === $s));
    [$draft, $held, $failed, $waiting, $appr, $pub] = [$by('draft'), $by('held'), $by('failed'), $by('waiting'), $by('approved'), $by('published')];
    $posts = array_values(array_filter($items, fn($i) => $i['post'] !== null));
    $postsOut = count(array_filter($posts, fn($i) => $i['state'] === 'published'));
    $start = strtotime((string)$camp['when']) ?: time();
    $r = pm_camp_results($camp);
    $plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
    $resLine = $postsOut || $r['sent'] ? trim(($r['leads'] ? $plural($r['leads'], 'enquiry', 'enquiries') . ' so far. ' : 'No enquiries yet. ')
        . ($r['measured'] ? $r['reactions'] . ' reactions, ' . $r['comments'] . ' comments, ' . $r['shares'] . ' shares on the posts measured. ' : 'Reactions and comments are counted about a day after a post goes out. ')
        . ($r['sent'] ? $plural($r['sent'], 'WhatsApp message', 'WhatsApp messages') . ' sent.' : '')) : 'Shows here once the first item is out.';
    $toCheck = count($draft) + count($held);
    $steps = [
        ['Made', 'done', 'On ' . date('j M, H:i', strtotime((string)$camp['created']) ?: time()) . ($camp['ai'] ? ', words by the AI from your facts.' : ', from your own words.')],
        ['Checked and approved', $toCheck ? 'now' : 'done', $held ? $plural(count($held), 'item needs', 'items need') . ' an edit (the reason is under each one).' . (count($draft) ? ' ' . count($draft) . ' more wait for Approve all.' : '')
            : ($draft ? $plural(count($draft), 'item waits', 'items wait') . ' for your check and Approve all.' : 'Approved' . (($camp['approved_at'] ?? '') !== '' ? ' ' . date('j M, H:i', strtotime((string)$camp['approved_at'])) . ' by ' . $camp['approved_by'] : '') . '.' . ($by('off') ? ' The WhatsApp broadcast waits until WhatsApp Business is connected (Operations > Connections).' : ''))],
        ['Going out', $posts && $postsOut === count($posts) ? 'done' : ($appr ? 'now' : 'todo'), $postsOut . ' of ' . count($posts) . ' posts out.'
            . ($appr && $start > time() ? ' The first goes out ' . pm_camp_in($start) . ' (' . date('D j M, H:i', $start) . ').' : ($appr ? ' The rest follow on their times below.' : ''))],
    ];
    $hand = array_values(array_filter($items, fn($i) => str_contains($i['role'], ':')));
    if ($hand) {
        $steps[] = ['Posted by hand', $waiting ? 'now' : 'done', $waiting ? $plural(count($waiting), 'post waits', 'posts wait') . ' for you to post by hand (' . implode(', ', array_unique(array_map(fn($i) => preg_replace('/^↳\s*/u', '', $i['label']), $waiting))) . ').' : 'All hand-posts done.'];
    }
    $steps[] = ['Results', $postsOut || $r['sent'] ? 'now' : 'todo', $resLine];
    $next = match (true) {
        (bool)$failed => 'Fix the failed item: ' . $failed[0]['label'] . ($failed[0]['note'] !== '' ? ' (' . $failed[0]['note'] . ')' : '') . '. Change its words and save, or open it in Channels.',
        (bool)$held => 'Change the words of the ' . $plural(count($held), 'item', 'items') . ' marked "needs an edit" below, save, then press Approve all.',
        (bool)$draft => pm_social_can_approve() ? 'Check the pictures and words below (change any you like), then press Approve all. Nothing goes out before that.' : 'Waiting for an approver to press Approve all.',
        (bool)$waiting => 'Post the hand-post items: download the stories (zip), post them to your WhatsApp Status, then mark them done in Channels.',
        $appr && $start > time() => 'Nothing to do: it starts by itself ' . pm_camp_in($start) . '. Meanwhile, print the flyer or share it in your groups.',
        (bool)$appr => 'Going out now; the rest follows on the times below by itself.',
        $r['leads'] > 0 => 'Answer the ' . $plural($r['leads'], 'enquiry', 'enquiries') . ' quickly: fast replies win most deals.',
        default => 'Everything is out. Share the flyer in person and in groups; when it has run a week, try one of the next campaign ideas.',
    };
    $times = [];
    foreach ($posts as $i) {
        $times[] = [$i['label'], (string)($i['post']['when'] ?? ''), $i['state']];
    }
    foreach ($items as $i) {
        if ($i['role'] === 'wac' && ($w = function_exists('pm_wac_get') ? pm_wac_get($i['id']) : null)) {
            $times[] = ['WhatsApp broadcast (a few at a time, working hours)', (string)($w['send_from'] ?? $camp['when']), $i['state']];
        }
    }
    return ['next' => $next, 'steps' => $steps, 'times' => $times, 'results' => $r];
}

/* ---------------- ideas: what to run next ---------------- */

const PM_CAMP_IDEA_SRC = ['offer' => 'Your offer', 'advisor' => 'What is selling', 'magnet' => 'Free first step', 'ai' => 'AI idea'];

/** The headlines of the business's campaigns of the last $days days, lower-cased, so an idea is not a campaign that just ran. */
function pm_camp_recent_heads(string $brand, int $days = 30): array
{
    $cut = date('Y-m-d H:i', time() - $days * 86400);
    return array_map(fn($c) => mb_strtolower(trim((string)($c['headline'] ?? ''))), array_filter(pm_camp_for($brand), fn($c) => (string)($c['created'] ?? '') >= $cut));
}

/** Campaign ideas from code alone (free): the offers by enquiries, this week's advisor offer, the free first step; minus what ran lately. */
function pm_camp_ideas_plain(string $brand): array
{
    $recent = pm_camp_recent_heads($brand);
    $p = pm_brand_profile($brand);
    $kw = (string)$p['cta_keyword'];
    $out = [];
    $rows = [];
    foreach (function_exists('pm_lp_for_brand') ? pm_lp_for_brand($brand, true) : [] as $o) {
        $s = function_exists('pm_lp_stats') ? pm_lp_stats($o) : [];
        $rows[] = [$o, (int)($s['leads'] ?? 0) + (int)($s['keyword_comments'] ?? 0), (int)($s['published'] ?? 0)];
    }
    usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
    foreach (array_slice($rows, 0, 2) as [$o, $enq, $pub]) {
        $out[] = ['headline' => (string)$o['title'], 'message' => (string)($o['fix'] ?? ''), 'keyword' => (string)($o['keyword'] ?? '') ?: $kw, 'offer' => (string)$o['id'], 'statuses' => ['contacted', 'replied', 'proposal', 'won'], 'src' => 'offer',
            'why' => $enq ? 'This offer brought ' . $enq . ' enquir' . ($enq === 1 ? 'y' : 'ies') . ' from ' . $pub . ' post' . ($pub === 1 ? '' : 's') . ': give it every channel at once.'
                : ($pub ? 'Posted ' . $pub . ' time' . ($pub === 1 ? '' : 's') . ' with no enquiry yet: stories, WhatsApp and a flyer reach people the feed missed.' : 'Not posted yet: a campaign launches it on every channel at once.')];
    }
    foreach (function_exists('pm_adv_moves_saved') ? pm_adv_moves_saved($brand)['moves'] : [] as $mv) {
        if (!empty($mv['offer']['title'])) {
            $out[] = ['headline' => (string)$mv['offer']['title'], 'message' => (string)($mv['offer']['fix'] ?? ''), 'keyword' => (string)($mv['offer']['keyword'] ?? '') ?: $kw, 'offer' => '', 'statuses' => ['replied', 'won'], 'src' => 'advisor',
                'why' => (string)($mv['reason'] ?: $mv['title'])];
        }
    }
    if ($p['magnet'] !== '') {
        $out[] = ['headline' => mb_strtoupper(mb_substr($p['magnet'], 0, 1)) . mb_substr($p['magnet'], 1), 'message' => '', 'keyword' => $kw, 'offer' => '', 'statuses' => ['qualified', 'contacted'], 'src' => 'magnet',
            'why' => 'Your free first step: no risk for the buyer, so it starts conversations with people who have not bought yet.'];
    }
    $seen = [];
    return array_slice(array_values(array_filter($out, function ($i) use ($recent, &$seen) {
        $k = mb_strtolower(trim($i['headline']));
        if ($k === '' || in_array($k, $recent, true) || isset($seen[$k])) {
            return false;
        }
        return $seen[$k] = true;
    })), 0, 4);
}

/**
 * Asks the AI (cheap tier, one call) for 3 or 4 campaign ideas from the business's facts, offers and their enquiries, past campaigns and their results,
 * the advisor's moves and the month; the free ideas fill up the rest. Skipped when nothing changed since the last ideas (unless $force).
 * Every idea is checked like campaign words: no number the business never gave, none of the banned words, a real offer id. Saved in data/camp_ideas.json.
 */
function pm_camp_ideas(string $brand, bool $force = false): array
{
    $prev = (array)(pm_load('camp_ideas', fn() => [])[$brand] ?? []);
    $plain = pm_camp_ideas_plain($brand);
    $res = ['at' => date('Y-m-d H:i'), 'ai' => false, 'note' => '', 'sig' => '', 'rows' => $plain];
    $ready = function_exists('pm_agents_ready') && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']));
    if ($ready) {
        $brain = pm_brain($brand);
        $p = pm_brand_profile($brand);
        $offers = [];
        foreach (function_exists('pm_lp_for_brand') ? pm_lp_for_brand($brand, true) : [] as $o) {
            $s = function_exists('pm_lp_stats') ? pm_lp_stats($o) : [];
            $offers[] = ['id' => (string)$o['id'], 'title' => (string)$o['title'], 'problem' => (string)($o['problem'] ?? ''), 'fix' => (string)($o['fix'] ?? ''), 'keyword' => (string)($o['keyword'] ?? ''),
                'posts_out' => (int)($s['published'] ?? 0), 'enquiries' => (int)($s['leads'] ?? 0) + (int)($s['keyword_comments'] ?? 0)];
        }
        $past = array_map(fn($c) => ['said' => (string)$c['headline'], 'started' => substr((string)$c['when'], 0, 10), 'enquiries' => pm_camp_results($c)['leads']], array_slice(pm_camp_for($brand), 0, 6));
        $moves = array_map(fn($m) => $m['title'] . ': ' . $m['reason'], function_exists('pm_adv_moves_saved') ? pm_adv_moves_saved($brand)['moves'] : []);
        $input = ['business' => pm_brand_name($brand), 'about' => (string)$brain['about'], 'market' => (string)$p['market'], 'sells_to' => (string)$p['sell_to'],
            'true_facts' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$brain['facts']) ?: []))), 'offerings' => array_values((array)(pm_agents_config($brand)['offerings'] ?? [])),
            'free_first_step' => (string)$p['magnet'], 'whatsapp_keyword' => (string)$p['cta_keyword'], 'month' => date('F'), 'offers' => $offers, 'past_campaigns' => $past, 'advisor_moves' => $moves,
            'audiences' => (defined('PM_WAC_AUDIENCE_STATUSES') ? PM_WAC_AUDIENCE_STATUSES : [])];
        $res['sig'] = sha1(json_encode($input, JSON_UNESCAPED_UNICODE));
        if (!$force && ($prev['sig'] ?? '') === $res['sig'] && !empty($prev['ai'])) {
            return $prev; // nothing new to think about: the same ideas, no AI call
        }
        try {
            $system = 'You suggest the next marketing campaigns for a small business in ' . ($p['market'] ?: 'Malawi') . '. A campaign is one message sent to every channel at once: feed post, stories, carousel, '
                . 'a WhatsApp broadcast to people who already know the business, and a printed flyer. Suggest 3 or 4, best first, each different: build on the offers that brought enquiries, the advisor moves, '
                . 'the free first step and what people need in ' . date('F') . '. Use ONLY the facts given. Never invent numbers, prices, discounts, deadlines, awards, customers or guarantees, never use the words '
                . 'best, cheapest, fastest, guaranteed or 24/7, and do not repeat a past campaign. Say "we". Short plain words for a phone screen. Reply with JSON only.';
            $user = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nJSON: {\"note\":\"one sentence: what these ideas build on\",\"ideas\":[{\"headline\":\"what it says, under 9 words\",\"message\":\"one more plain line\",\"keyword\":\"ONE capital word\","
                . "\"offer_id\":\"an id from offers, or empty\",\"audience\":[\"keys from audiences\"],\"why\":\"one sentence: why this, why now, quoting only numbers given\"}]}";
            $r = pm_agent_json(pm_claude($system, $user, false, 1200, 'cheap'));
            if (is_array($r) && array_is_list($r) && is_array($r[0] ?? null)) {
                $r = isset($r[0]['ideas']) ? $r[0] : ['ideas' => $r]; // some models wrap the answer in a list
            }
        } catch (Throwable $e) {
            $r = null;
            $res['note'] = 'The AI ideas did not work this time (' . mb_substr($e->getMessage(), 0, 80) . '); these come from your offers and results.';
        }
        $src = mb_strtolower(json_encode($input, JSON_UNESCAPED_UNICODE));
        $clean = fn($v, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : ''))), 0, $n);
        $bad = fn(string $t) => pm_brand_unsourced_number($t, $src) || preg_match('/\b(best|cheapest|fastest|guarantee[sd]?|24\s?\/\s?7)\b/i', $t);
        $ids = array_column($offers, 'id');
        $ai = [];
        foreach (is_array($r) ? (array)($r['ideas'] ?? []) : [] as $x) {
            if (!is_array($x) || count($ai) >= 4) {
                continue;
            }
            $row = ['headline' => $clean($x['headline'] ?? '', 90), 'message' => $clean($x['message'] ?? '', 240), 'keyword' => mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($x['keyword'] ?? ''))), 0, 12) ?: (string)$p['cta_keyword'],
                'offer' => in_array((string)($x['offer_id'] ?? ''), $ids, true) ? (string)$x['offer_id'] : '', 'src' => 'ai', 'why' => $clean($x['why'] ?? '', 260),
                'statuses' => array_values(array_intersect(array_map('strval', (array)($x['audience'] ?? [])), array_keys((defined('PM_WAC_AUDIENCE_STATUSES') ? PM_WAC_AUDIENCE_STATUSES : []))))];
            if (mb_strlen($row['headline']) < 3 || $bad($row['headline'] . ' ' . $row['message'] . ' ' . $row['why'])) {
                continue; // a number nobody gave it, or a banned claim
            }
            $ai[] = $row;
        }
        if ($ai) {
            $heads = array_map(fn($i) => mb_strtolower($i['headline']), $ai);
            $same = function (array $i) use ($heads): bool { // a free idea the AI already said in its own words
                $k = preg_replace('/^(a|an|the)\s+/u', '', mb_strtolower(trim($i['headline'])));
                return (bool)array_filter($heads, fn($h) => str_contains($h, $k));
            };
            $res['rows'] = array_slice(array_merge($ai, array_values(array_filter($plain, fn($i) => !$same($i)))), 0, 6);
            $res['ai'] = true;
            $n = $clean(is_array($r) ? ($r['note'] ?? '') : '', 200);
            $res['note'] = $bad($n) ? '' : $n;
        } elseif ($res['note'] === '') {
            $res['note'] = 'The AI gave no usable idea this time; these come from your offers and results.';
        }
    } else {
        $res['note'] = 'The AI is not set up, so these ideas come from your offers and results alone.';
    }
    pm_update('camp_ideas', function (array $all) use ($brand, $res) {
        $all[$brand] = $res;
        return $all;
    }, fn() => []);
    return $res;
}

/** What the screen shows: the saved ideas minus campaigns made since, else the free ones. Never asks the AI (pages stay instant). */
function pm_camp_ideas_saved(string $brand): array
{
    $s = (array)(pm_load('camp_ideas', fn() => [])[$brand] ?? []);
    $recent = pm_camp_recent_heads($brand);
    $rows = array_values(array_filter((array)($s['rows'] ?? []), fn($i) => !in_array(mb_strtolower(trim((string)($i['headline'] ?? ''))), $recent, true)));
    return $rows ? ['at' => (string)($s['at'] ?? ''), 'ai' => !empty($s['ai']), 'note' => (string)($s['note'] ?? ''), 'rows' => $rows]
        : ['at' => '', 'ai' => false, 'note' => '', 'rows' => pm_camp_ideas_plain($brand)];
}

/* ---------------- forms (POST action=social_ext&do=camp_*) ---------------- */

/** "Suggest campaigns with the AI": asks now, even when nothing changed. */
function pm_do_camp_ideas(string $vb): array
{
    @set_time_limit(120);
    $r = pm_camp_ideas($vb, true);
    return ['msg' => count($r['rows']) . ' campaign idea' . (count($r['rows']) === 1 ? '' : 's') . ' ready' . ($r['ai'] ? ', written by the AI from your facts and results.' : '. ' . $r['note']), 'kind' => 'ok', 'to' => 'social&view=campaign#ideas'];
}

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
    if (isset($_POST['parts_shown'])) { // the form had the ticks: nothing ticked means nothing, not everything
        $in['parts'] = array_map('strval', (array)($_POST['parts'] ?? []));
        $in['nets'] = array_map('strval', (array)($_POST['nets'] ?? []));
    }
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
