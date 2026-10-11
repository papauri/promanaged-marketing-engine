<?php
/**
 * Optimise: one checklist per connected channel (Facebook Page, Instagram, WhatsApp Business, Google Business Profile, X, LinkedIn, TikTok, YouTube) plus the business details they all share.
 * Everything is worked out in code, with no AI: the Page, Instagram and WhatsApp profiles are READ, each gap gets a ready suggestion built from the business's own details and facts, and where a
 * channel lets an app change it (Facebook Page fields, hours, picture and cover; the WhatsApp Business profile; the business details; the post calendar; the Google review link) the suggestion
 * is applied with one press, after the owner has seen it and can edit it. Where no API exists (Instagram bio, Google profile, X, LinkedIn, TikTok, YouTube) the suggestion is a text to copy, a
 * link to the exact place, and an "I did it" tick. One optional AI call can polish the wording of the text suggestions; its words are kept only if they pass the same facts-only checks.
 * Data: data/page_opt.json {brand: {hours, hours_confirmed, ai: {key: text}, log: [...], history: {date: score}, last: {...}}}. Nothing is posted or sent from here.
 */

const PM_OPT_DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
const PM_OPT_CHANNELS = ['business' => 'Business details', 'facebook' => 'Facebook Page', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp Business', 'google' => 'Google Business Profile',
    'x' => 'X', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'];
const PM_OPT_VERTICALS = ['PROF_SERVICES' => 'Professional services', 'TRAVEL' => 'Travel and transport', 'HOTEL' => 'Hotel and lodging', 'RETAIL' => 'Retail', 'RESTAURANT' => 'Restaurant', 'HEALTH' => 'Health',
    'EDU' => 'Education', 'FINANCE' => 'Finance', 'AUTO' => 'Automotive', 'BEAUTY' => 'Beauty and personal care', 'GROCERY' => 'Grocery', 'EVENT_PLAN' => 'Event planning', 'NONPROFIT' => 'Non-profit', 'OTHER' => 'Other'];

/* ---------------- storage ---------------- */

function pm_opt_store(string $brand): array
{
    $d = (array)(pm_load('page_opt', fn() => [])[$brand] ?? []);
    return $d + ['hours' => [], 'hours_confirmed' => false, 'ai' => [], 'log' => [], 'history' => [], 'last' => []];
}

function pm_opt_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('page_opt', function (array $all) use ($brand, $fn, &$out) {
        $cur = (array)($all[$brand] ?? []) + ['hours' => [], 'hours_confirmed' => false, 'ai' => [], 'log' => [], 'history' => [], 'last' => []];
        $out = $fn($cur);
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

function pm_opt_log(string $brand, string $channel, string $key, bool $ok, string $msg): void
{
    pm_opt_update($brand, function (array $d) use ($channel, $key, $ok, $msg) {
        $d['log'] = array_slice(array_merge([['at' => date('Y-m-d H:i'), 'channel' => $channel, 'key' => $key, 'ok' => $ok, 'msg' => mb_substr($msg, 0, 200)]], (array)$d['log']), 0, 60);
        return $d;
    });
}

/* ---------------- opening hours ---------------- */

/** A starting point, not a fact: Monday to Friday, 08:00 to 17:00 (what ProManaged's own proposals already say about support). The owner changes it to the real hours. */
function pm_opt_hours_default(): array
{
    $h = [];
    foreach (array_keys(PM_OPT_DAYS) as $d) {
        $h[$d] = in_array($d, ['sat', 'sun'], true) ? null : ['08:00', '17:00'];
    }
    return $h;
}

/** The saved hours, or the starting point. Each day: ['HH:MM','HH:MM'] or null (closed). */
function pm_opt_hours(string $brand): array
{
    $st = pm_opt_store($brand);
    $h = [];
    foreach (array_keys(PM_OPT_DAYS) as $d) {
        $v = $st['hours'][$d] ?? null;
        $h[$d] = is_array($v) && count($v) === 2 && preg_match('/^\d{2}:\d{2}$/', (string)$v[0]) && preg_match('/^\d{2}:\d{2}$/', (string)$v[1]) ? [(string)$v[0], (string)$v[1]] : null;
    }
    return $st['hours'] ? $h : pm_opt_hours_default();
}

/** "Mon-Fri 08:00-17:00; Sat 08:00-12:00" (days with the same hours are joined when they follow each other). */
function pm_opt_hours_text(array $h): string
{
    $short = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
    $groups = [];
    foreach ($short as $d => $lab) {
        $v = $h[$d] ?? null;
        if (!$v) {
            continue;
        }
        $key = $v[0] . '-' . $v[1];
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last]['key'] === $key && $groups[$last]['end'] === array_search($d, array_keys($short), true) - 1) {
            $groups[$last]['to'] = $lab;
            $groups[$last]['end']++;
        } else {
            $groups[] = ['from' => $lab, 'to' => $lab, 'key' => $key, 'end' => array_search($d, array_keys($short), true)];
        }
    }
    return implode('; ', array_map(fn($g) => ($g['from'] === $g['to'] ? $g['from'] : $g['from'] . '-' . $g['to']) . ' ' . $g['key'], $groups));
}

/** The hours form (hr[day][on|from|to]) checked: [true, hours] or [false, message]. */
function pm_opt_hours_parse(array $hr): array
{
    $h = [];
    $open = 0;
    foreach (PM_OPT_DAYS as $d => $label) {
        $r = (array)($hr[$d] ?? []);
        if (empty($r['on'])) {
            $h[$d] = null;
            continue;
        }
        $from = trim((string)($r['from'] ?? ''));
        $to = trim((string)($r['to'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) {
            return [false, "$label needs an opening and a closing time like 08:00 and 17:00."];
        }
        if ($to <= $from) {
            return [false, "$label closes before it opens. Closing must be later than opening."];
        }
        $h[$d] = [$from, $to];
        $open++;
    }
    return $open > 0 ? [true, $h] : [false, 'Tick at least one day you are open.'];
}

/** Hours in Facebook's shape: mon_1_open, mon_1_close ... */
function pm_opt_hours_fb(array $h): array
{
    $out = [];
    foreach ($h as $d => $v) {
        if ($v) {
            $out[$d . '_1_open'] = $v[0];
            $out[$d . '_1_close'] = $v[1];
        }
    }
    return $out;
}

/** Facebook's hours back into our shape (null = closed). [] when the Page has none. */
function pm_opt_hours_from_fb(array $fb): array
{
    if (!$fb) {
        return [];
    }
    $h = [];
    foreach (array_keys(PM_OPT_DAYS) as $d) {
        $o = (string)($fb[$d . '_1_open'] ?? '');
        $c = (string)($fb[$d . '_1_close'] ?? '');
        $h[$d] = preg_match('/^\d{2}:\d{2}$/', $o) && preg_match('/^\d{2}:\d{2}$/', $c) ? [$o, $c] : null;
    }
    return array_filter($h) ? $h : [];
}

/* ---------------- facts the suggestions are built from ---------------- */

function pm_opt_facts(string $brand): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $br = pm_brain($brand);
        $site = pm_clean_url((string)($s['website'] ?? ''));
        $link = pm_link_cfg($brand);
        $addr = trim((string)($s['address'] ?? ''));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($br['facts'] ?? '')) ?: [])));
        return ['name' => trim((string)($s['company_name'] ?? '')) ?: pm_brand_name($brand), 'tagline' => trim((string)($s['tagline'] ?? '')), 'phone' => trim((string)($s['phone'] ?? '')),
            'email' => trim((string)($s['email'] ?? '')), 'website' => $site, 'site' => $link['url'] !== '' ? $link['url'] : $site, 'address' => $addr, 'address_real' => $addr !== '' && !preg_match('/^malawi$/i', $addr),
            'about' => trim((string)($br['about'] ?? '')), 'audience' => trim((string)($br['audience'] ?? '')), 'facts' => $lines];
    } finally {
        pm_brand_set($prev);
    }
}

/** Whole sentences from the business's own description, up to $limit characters. */
function pm_opt_sentences(string $text, int $limit): string
{
    $out = '';
    foreach (pm_ch_sentences($text) as $s) {
        $try = trim($out . ' ' . $s);
        if (mb_strlen($try) > $limit) {
            break;
        }
        $out = $try;
    }
    return $out !== '' ? $out : pm_ch_cut(trim($text), $limit, '');
}

/** The long "about" text for a channel, from the business's own words only. */
function pm_opt_long(array $f, int $limit): string
{
    $t = pm_opt_sentences($f['about'] !== '' ? $f['about'] : ($f['tagline'] !== '' ? $f['tagline'] . '.' : ''), $limit);
    if ($t !== '' && $f['audience'] !== '') {
        $aud = 'For ' . lcfirst(rtrim(pm_ch_sentences($f['audience'])[0] ?? '', '.!?')) . '.';
        if (mb_strlen($t . ' ' . $aud) <= $limit) {
            $t .= ' ' . $aud;
        }
    }
    return $t;
}

/** One suggested username: letters and digits from the name, 5 to 50 characters. */
function pm_opt_username(string $name): string
{
    $u = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));
    return strlen($u) >= 5 ? substr($u, 0, 50) : '';
}

/* ---------------- AI polish (optional, one call, facts only) ---------------- */

/** Does a polished text keep to the facts? No new numbers, links, emails, phone numbers, exclamation marks, shouting or boasts; within its limit. */
function pm_opt_ai_ok(string $text, int $limit, string $source): bool
{
    $t = trim($text);
    if ($t === '' || mb_strlen($t) > $limit || str_contains($t, '!') || preg_match('~https?://|www\.|@|#|\p{So}~u', $t)) {
        return false;
    }
    if (pm_brand_unsourced_number($t, $source) || preg_match('/\b\p{Lu}{5,}\b/u', $t)) {
        return false;
    }
    return !preg_match('/\b(best|number one|no\.? ?1|#1|leading|guarantee[sd]?|cheapest|top[- ]rated|award[- ]winning|world[- ]class|unbeatable)\b/i', $t) || preg_match('/\b(best|leading|guarantee)/i', $source) === 1;
}

/** The text to suggest: the polished version if there is one and it still passes, else the plain one built from the facts. */
function pm_opt_pick(string $brand, string $key, string $plain, int $limit, array $store, array $f): array
{
    $ai = trim((string)($store['ai'][$key] ?? ''));
    if ($ai !== '' && pm_opt_ai_ok($ai, $limit, implode(' ', array_filter(array_map('strval', [$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), $f['address'], $f['phone']]))))) {
        return [$ai, true];
    }
    return [$plain, false];
}

/* ---------------- reading the channels ---------------- */

/** The Facebook Page's own fields: [ok, fields|message]. Reads are cached by the Graph layer. */
function pm_opt_fb_read(string $brand): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return [false, 'The Facebook Page is not connected.'];
    }
    [$ok, $pg] = pm_graph('GET', $c['page_id'], ['fields' => 'name,about,description,category,website,phone,emails,username,whatsapp_number,fan_count,followers_count,overall_star_rating,rating_count,picture{url,is_silhouette},cover{source},hours,location,link'], $c['token']);
    return $ok && is_array($pg) ? [true, $pg] : [false, is_string($pg) ? $pg : 'Facebook did not answer.'];
}

/** The linked Instagram account: [ok, fields|message]. */
function pm_opt_ig_read(string $brand): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '') {
        return [false, 'Instagram is not linked.'];
    }
    [$ok, $d] = pm_graph('GET', $c['ig_id'], ['fields' => 'biography,website,name,username,profile_picture_url,followers_count,media_count'], $c['token']);
    return $ok && is_array($d) ? [true, $d] : [false, is_string($d) ? $d : 'Instagram did not answer.'];
}

/** The WhatsApp Business profile ($set = null reads, an array writes): [ok, data|message]. $GLOBALS['PM_WA_PROFILE_STUB'] (tests) is fn(method, cfg, payload|null). */
function pm_opt_wa_profile(string $brand, ?array $set = null): array
{
    if (!function_exists('pm_wa_biz_cfg') && is_file(__DIR__ . '/wa_biz.php')) {
        require_once __DIR__ . '/wa_biz.php';
    }
    $c = function_exists('pm_wa_biz_cfg') ? pm_wa_biz_cfg($brand) : ['ready' => false];
    if (empty($c['ready'])) {
        return [false, 'WhatsApp Business is not connected for this business.'];
    }
    if (isset($GLOBALS['PM_WA_PROFILE_STUB']) && is_callable($GLOBALS['PM_WA_PROFILE_STUB'])) {
        return $GLOBALS['PM_WA_PROFILE_STUB']($set === null ? 'GET' : 'POST', $c, $set);
    }
    if (getenv('PM_TEST')) {
        return [false, 'Offline in tests.'];
    }
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($c['phone_id']) . '/whatsapp_business_profile';
    $ch = curl_init($set === null ? $url . '?fields=about,address,description,email,profile_picture_url,websites,vertical' : $url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json']];
    if ($set !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode(['messaging_product' => 'whatsapp'] + $set, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opt);
    pm_curl_native_ca($ch);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($raw, true);
    if ($code >= 200 && $code < 300 && is_array($j)) {
        return [true, $set === null ? (array)($j['data'][0] ?? []) : $j];
    }
    return [false, 'WhatsApp said: ' . ($code ?: 'no answer') . ' ' . mb_substr((string)($j['error']['message'] ?? $raw), 0, 160)];
}

/* ---------------- one finding ---------------- */

/**
 * state: ok | weak | missing. how: api (applied to the channel), app (applied inside this app), manual (copy, open, tick), go (a link to the place that fixes it).
 * input: text | textarea | hours | select | none. bulk: may be included in "apply everything that can be applied".
 */
function pm_opt_it(string $ch, string $key, string $title, string $state, int $weight, string $why, array $o = []): array
{
    return array_replace(['channel' => $ch, 'key' => $key, 'title' => $title, 'state' => $state, 'weight' => $weight, 'why' => $why, 'current' => '', 'proposal' => '', 'how' => 'manual', 'input' => 'none',
        'limit' => 0, 'link' => '', 'link_label' => '', 'button' => '', 'bulk' => false, 'tick' => '', 'ai' => false, 'note' => '', 'options' => [], 'do' => 'opt_apply'], $o);
}

/** Done ticks for manual items live with the other ticks (data/social_channels.json). */
function pm_opt_ticked(string $brand, string $tick): bool
{
    return $tick !== '' && !empty(pm_channels_cfg($brand)['ticks'][$tick]);
}

/** Where the business stands on its own details, which every channel suggestion is built from. */
function pm_opt_check_business(string $brand, array $f, array $st): array
{
    $it = [];
    $set = fn($v) => trim((string)$v) !== '';
    $it[] = pm_opt_it('business', 'biz_phone', 'Phone / WhatsApp number', $set($f['phone']) ? 'ok' : 'missing', 3, 'Every profile shows it, and customers on WhatsApp need it.', ['current' => $f['phone'], 'how' => 'app', 'input' => 'text', 'limit' => 30, 'button' => 'Save the number']);
    $it[] = pm_opt_it('business', 'biz_email', 'Business email', $set($f['email']) ? 'ok' : 'missing', 2, 'Profiles and the Google listing ask for it.', ['current' => $f['email'], 'how' => 'app', 'input' => 'text', 'limit' => 120, 'button' => 'Save the email']);
    $it[] = pm_opt_it('business', 'biz_website', 'Website', $set($f['website']) ? 'ok' : 'missing', 2, 'The link every profile points to.', ['current' => $f['website'], 'how' => 'app', 'input' => 'text', 'limit' => 200, 'button' => 'Save the website']);
    $it[] = pm_opt_it('business', 'biz_address', 'Town and street', $f['address_real'] ? 'ok' : ($set($f['address']) ? 'weak' : 'missing'), 2, 'Maps, Google and Facebook need a real place, not just the country.',
        ['current' => $f['address'], 'proposal' => '', 'how' => 'app', 'input' => 'text', 'limit' => 200, 'button' => 'Save the address', 'note' => 'Type the street, area and town, for example "Area 3, Lilongwe".']);
    $it[] = pm_opt_it('business', 'biz_tagline', 'One-line description', mb_strlen($f['tagline']) >= 15 ? 'ok' : ($set($f['tagline']) ? 'weak' : 'missing'), 2, 'It becomes the intro on most profiles.', ['current' => $f['tagline'], 'how' => 'app', 'input' => 'text', 'limit' => 120, 'button' => 'Save the line']);
    $hrs = pm_opt_hours($brand);
    $it[] = pm_opt_it('business', 'biz_hours', 'Opening hours', !empty($st['hours_confirmed']) ? 'ok' : 'missing', 3, 'Visitors check them first; Facebook, Google and WhatsApp all ask for the same hours.',
        ['current' => !empty($st['hours_confirmed']) ? pm_opt_hours_text($hrs) : '', 'proposal' => pm_opt_hours_text($hrs), 'how' => 'app', 'input' => 'hours', 'button' => 'Save these hours', 'note' => 'This is a starting point, not a fact: set your real hours, then save.']);
    $it[] = pm_opt_it('business', 'biz_about', 'What the business does and for whom', mb_strlen($f['about']) >= 60 ? 'ok' : 'missing', 3, 'The suggestions below are written from it; without it they stay thin.',
        ['current' => $f['about'], 'how' => 'go', 'link' => '?tab=social&view=voice&brand=' . $brand, 'link_label' => 'Open Brand > Voice', 'note' => 'Brand > Voice (or add the website and press "Study the business again").']);
    return $it;
}

function pm_opt_check_facebook(string $brand, array $f, array $st): array
{
    $c = pm_social_cfg($brand);
    $base = 'https://www.facebook.com/' . $c['page_id'];
    if (!$c['ready']) {
        return ['connected' => false, 'note' => 'The Facebook Page is not connected.', 'items' => [pm_opt_it('facebook', 'fb_connect', 'Connect the Facebook Page', 'missing', 3, 'Posting, comments and this checklist all start from the connection.',
            ['how' => 'go', 'link' => '?tab=settings&section=connections', 'link_label' => 'Open Connections'])]];
    }
    [$ok, $pg] = pm_opt_fb_read($brand);
    if (!$ok) {
        return ['connected' => true, 'note' => 'The Page could not be read: ' . $pg, 'items' => []];
    }
    $it = [];
    $has = fn($k) => trim((string)($pg[$k] ?? '')) !== '';
    $logo = is_file(PM_ROOT . '/' . pm_brand_asset($brand, 'logo'));
    $pic = !empty($pg['picture']['data']['url']) && empty($pg['picture']['data']['is_silhouette']);
    $design = function_exists('pm_studio_saved') ? pm_studio_saved($brand) : null;
    $designNote = $design ? 'Drawn from your Studio design (' . (PM_SD_STYLES[$design['style']] ?? 'custom') . ').' : 'No design chosen yet, so the classic one is used. Choose one in the Studio first for something much better.';
    $it[] = pm_opt_it('facebook', 'fb_design', 'A designed profile picture and cover', $design ? 'ok' : 'weak', 2, 'A styled cover with your words says "established business" before anyone reads a post.',
        ['how' => 'go', 'link' => '?tab=social&view=studio', 'link_label' => 'Open the Studio', 'note' => $design ? 'Saved design: ' . (PM_SD_STYLES[$design['style']] ?? '') . '.' : 'Eleven styles, your colours and words, drawn by code. "Surprise me" or "New idea from the AI" make new ones.']);
    $it[] = pm_opt_it('facebook', 'fb_picture', 'Profile picture', $pic ? 'ok' : 'missing', 3, 'The logo is how people recognise the Page in every search and comment.',
        $logo || $design ? ['how' => 'api', 'bulk' => true, 'button' => 'Set it on Facebook', 'note' => $designNote] : ['how' => 'go', 'link' => '?tab=social&view=studio', 'link_label' => 'Open the Studio', 'note' => 'Add a logo in Operations > Business details, or use a monogram from the Studio.']);
    $it[] = pm_opt_it('facebook', 'fb_cover', 'Cover picture', !empty($pg['cover']['source']) ? 'ok' : 'missing', 2, 'The big picture on top: say what you do in one look.',
        ['how' => 'api', 'bulk' => true, 'button' => 'Set it on Facebook', 'note' => $designNote]);
    $bios = pm_bio_pack($brand);
    [$aboutP, $aboutAi] = pm_opt_pick($brand, 'fb_about', (string)($bios['facebook']['text'] ?? ''), 255, $st, $f);
    $it[] = pm_opt_it('facebook', 'fb_about', 'Short intro', mb_strlen((string)($pg['about'] ?? '')) >= 25 ? 'ok' : ($has('about') ? 'weak' : 'missing'), 3, 'The line under the Page name in search results.',
        ['current' => (string)($pg['about'] ?? ''), 'proposal' => $aboutP, 'ai' => $aboutAi, 'how' => 'api', 'input' => 'text', 'limit' => 255, 'bulk' => $aboutP !== '', 'button' => 'Apply to Facebook']);
    [$descP, $descAi] = pm_opt_pick($brand, 'fb_description', pm_opt_long($f, 250), 255, $st, $f);
    $it[] = pm_opt_it('facebook', 'fb_description', 'Page description', mb_strlen((string)($pg['description'] ?? '')) >= 80 ? 'ok' : ($has('description') ? 'weak' : 'missing'), 2, 'What visitors read on the About tab.',
        ['current' => (string)($pg['description'] ?? ''), 'proposal' => $descP, 'ai' => $descAi, 'how' => 'api', 'input' => 'textarea', 'limit' => 255, 'bulk' => $descP !== '', 'button' => 'Apply to Facebook']);
    $fbHours = pm_opt_hours_from_fb((array)($pg['hours'] ?? []));
    $it[] = pm_opt_it('facebook', 'fb_hours', 'Opening hours', $fbHours ? 'ok' : 'missing', 3, 'Visitors check them first. Customers message you at the wrong time when they are missing.',
        ['current' => $fbHours ? pm_opt_hours_text($fbHours) : '', 'proposal' => pm_opt_hours_text(pm_opt_hours($brand)), 'how' => 'api', 'input' => 'hours', 'bulk' => !empty($st['hours_confirmed']), 'button' => 'Apply to Facebook',
            'note' => !empty($st['hours_confirmed']) ? 'Your saved hours.' : 'A starting point, not a fact: set your real hours, then apply.']);
    $pagePhone = (string)($pg['phone'] ?? $pg['whatsapp_number'] ?? '');
    $digits = fn(string $v) => substr((string)preg_replace('/\D/', '', $v), -9);
    $phoneDiffers = $pagePhone !== '' && $f['phone'] !== '' && $digits($pagePhone) !== $digits($f['phone']);
    $it[] = pm_opt_it('facebook', 'fb_phone', 'Phone number', $phoneDiffers ? 'weak' : ($pagePhone !== '' ? 'ok' : 'missing'), 3, 'The call and message buttons depend on it, and it should be the same everywhere.',
        ['current' => $pagePhone, 'proposal' => $f['phone'], 'how' => 'api', 'input' => 'text', 'limit' => 30, 'bulk' => $f['phone'] !== '' && !$phoneDiffers, 'button' => 'Apply to Facebook',
            'note' => $phoneDiffers ? 'The Page shows a different number from the business details. If the Page is right, change the business details instead.' : '']);
    $hostOf = fn(string $u) => strtolower((string)preg_replace('/^www\./', '', (string)parse_url(preg_match('#^https?://#i', $u) ? $u : 'https://' . $u, PHP_URL_HOST)));
    $siteDiffers = $has('website') && $f['site'] !== '' && $hostOf((string)$pg['website']) !== $hostOf($f['site']) && $hostOf((string)$pg['website']) !== $hostOf($f['website']);
    $it[] = pm_opt_it('facebook', 'fb_website', 'Website link', $siteDiffers ? 'weak' : ($has('website') ? 'ok' : 'missing'), 2, 'Sends visitors where they can learn more or enquire.',
        ['current' => (string)($pg['website'] ?? ''), 'proposal' => $f['site'], 'how' => 'api', 'input' => 'text', 'limit' => 200, 'bulk' => $f['site'] !== '' && !$siteDiffers, 'button' => 'Apply to Facebook',
            'note' => $siteDiffers ? 'The Page links to a different site from the business details. If the Page is right, change the business details instead.' : '']);
    $it[] = pm_opt_it('facebook', 'fb_email', 'Email address', !empty($pg['emails']) ? 'ok' : 'missing', 1, 'Another way to reach you, shown on the About tab.',
        ['current' => implode(', ', (array)($pg['emails'] ?? [])), 'proposal' => $f['email'], 'how' => 'api', 'input' => 'text', 'limit' => 120, 'bulk' => $f['email'] !== '', 'button' => 'Apply to Facebook']);
    $it[] = pm_opt_it('facebook', 'fb_address', 'Location', !empty($pg['location']) ? 'ok' : 'missing', 2, 'Lets people find you and shows the town in search.',
        ['proposal' => $f['address_real'] ? $f['address'] : '', 'how' => 'manual', 'link' => $base . '/about', 'link_label' => 'Open the About tab', 'note' => 'Page > About > Location: add the street and town.']);
    $un = pm_opt_username($f['name']);
    $it[] = pm_opt_it('facebook', 'fb_username', 'Page username (@name)', $has('username') ? 'ok' : 'missing', 1, 'A short address people can say and type.',
        ['current' => (string)($pg['username'] ?? ''), 'proposal' => $un, 'how' => 'manual', 'link' => $base . '/settings/?tab=page_setup', 'link_label' => 'Open Page settings', 'note' => 'Facebook checks that the name is free when you save.']);
    $it[] = pm_opt_it('facebook', 'fb_action_button', 'Message button', pm_opt_ticked($brand, 'fb_action_button') ? 'ok' : 'missing', 2, 'One tap from a visitor to your WhatsApp.',
        ['how' => 'manual', 'tick' => 'fb_action_button', 'link' => $base, 'link_label' => 'Open the Page', 'note' => 'On the Page: Edit action button > Send WhatsApp message, with your number.']);
    // posting rhythm and calendar
    $posts = pm_fb_posts($brand, 25)['posts'];
    $recent = array_filter($posts, fn($p) => strtotime((string)$p['at']) > strtotime('-28 days'));
    $perWeek = round(count($recent) / 4, 1);
    $goal = max(2, min(5, (int)pm_social_goals($brand)['posts_per_week']));
    $planned = count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && in_array($p['status'] ?? '', ['approved', 'draft', 'review'], true) && strtotime((string)($p['when'] ?? '')) >= time()));
    $lastAge = $posts ? (int)floor((time() - strtotime((string)$posts[0]['at'])) / 86400) : 999;
    $state = ($lastAge > 14 && $planned === 0) ? 'missing' : (($perWeek < $goal * 0.6 || $planned < 3) ? 'weak' : 'ok');
    $it[] = pm_opt_it('facebook', 'fb_posting', 'Posting regularly', $state, 3, 'A Page that posts steadily is shown to more people and looks alive.',
        ['current' => count($recent) . ' post' . (count($recent) === 1 ? '' : 's') . ' in 4 weeks (' . $perWeek . ' a week); ' . $planned . ' planned' . ($lastAge < 999 ? '; last post ' . $lastAge . ' day' . ($lastAge === 1 ? '' : 's') . ' ago' : ''),
            'proposal' => 'Add 5 draft posts to the calendar from your facts (no AI). You read and approve each one; nothing posts until you do.', 'how' => 'app', 'bulk' => $planned < 3, 'button' => 'Fill the calendar with 5 drafts']);
    $withPic = count(array_filter($recent, fn($p) => trim((string)$p['picture']) !== ''));
    if (count($recent) >= 4) {
        $share = (int)round(100 * $withPic / count($recent));
        $it[] = pm_opt_it('facebook', 'fb_formats', 'Pictures in the posts', $share >= 50 ? 'ok' : ($share >= 25 ? 'weak' : 'missing'), 2, 'Posts with a picture or video are shown to more people than plain text.',
            ['current' => $withPic . ' of the last ' . count($recent) . ' posts have a picture (' . $share . '%)', 'how' => 'go', 'link' => '?tab=social', 'link_label' => 'Open the plan', 'note' => 'The planner makes a picture for every post it plans; plain-text posts mostly come from outside the app.']);
    }
    $mine = array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && ($p['status'] ?? '') === 'published' && strtotime((string)($p['when'] ?? '')) > strtotime('-28 days'));
    if (count($mine) >= 4) {
        $ask = count(array_filter($mine, fn($p) => in_array($p['cta'] ?? '', ['whatsapp', 'check', 'host'], true)));
        $it[] = pm_opt_it('facebook', 'fb_cta', 'Posts that invite an enquiry', $ask >= 1 ? 'ok' : 'weak', 3, 'Followers rarely become customers unless a post gives them an easy next step.',
            ['current' => $ask . ' of ' . count($mine) . ' posts in 4 weeks asked people to message you', 'how' => 'go', 'link' => '?tab=social&view=leadposts', 'link_label' => 'Make a lead post', 'note' => 'A lead post has a button and a keyword that brings in client details.']);
    }
    $rated = (int)($pg['rating_count'] ?? 0);
    $it[] = pm_opt_it('facebook', 'fb_reviews', 'Recommendations and reviews', $rated >= 3 ? 'ok' : (pm_opt_ticked($brand, 'opt_fb_reviews') ? 'ok' : ($rated > 0 ? 'weak' : 'missing')), 2, 'New visitors trust what other customers say more than what you say.',
        ['current' => $rated . ' review' . ($rated === 1 ? '' : 's') . ((float)($pg['overall_star_rating'] ?? 0) > 0 ? ', ' . $pg['overall_star_rating'] . ' stars' : ''), 'how' => 'manual', 'tick' => 'opt_fb_reviews',
            'proposal' => 'Hello, thank you for working with us. If you were happy, would you leave a short recommendation on our Facebook Page? It really helps a small business. ' . ($pg['link'] ?? $base), 'note' => 'Send this to three happy clients this week. Never offer anything in return.']);
    $judged = (array)(pm_load('fb_judged', fn() => [])[$brand] ?? []);
    $junk = count(array_filter($judged, fn($k) => is_array($k) && ($k['state'] ?? '') === '' && in_array($k['verdict'] ?? '', ['scam', 'abusive', 'off_topic'], true)));
    $it[] = pm_opt_it('facebook', 'fb_junk', 'Scam, abuse and off-topic comments', $junk === 0 ? 'ok' : 'weak', 2, 'Visitors judge the Page by what is left sitting on it.',
        ['current' => $junk . ' waiting for your decision', 'how' => 'go', 'link' => '?tab=social&view=page', 'link_label' => 'Review them']);
    $inbox = pm_fb_inbox($brand);
    $waiting = count(array_filter($inbox['threads'], fn($t) => !empty($t['waiting'])));
    if (!$inbox['error']) {
        $it[] = pm_opt_it('facebook', 'fb_messages', 'Messenger conversations answered', $waiting === 0 ? 'ok' : ($waiting <= 2 ? 'weak' : 'missing'), 3, 'A message left waiting is a customer lost. Facebook shows how fast you reply.',
            ['current' => $waiting . ' waiting for an answer', 'how' => 'go', 'link' => '?tab=social&view=inbox', 'link_label' => 'Open the inbox']);
    }
    $open = pm_social_recent($brand)['open_comments'];
    $it[] = pm_opt_it('facebook', 'fb_replies', 'Answering comments', count($open) === 0 ? 'ok' : (count($open) <= 3 ? 'weak' : 'missing'), 3, 'Unanswered comments tell new visitors that nobody is home.',
        ['current' => count($open) . ' waiting', 'how' => 'go', 'link' => '?tab=social&view=page', 'link_label' => 'Open the comments']);
    if ($pin = pm_pin_pick($brand)) {
        $it[] = pm_opt_it('facebook', 'fb_pin', 'Pinned post', pm_opt_ticked($brand, 'pin_' . date('Y-m')) ? 'ok' : 'missing', 1, 'The first thing a visitor sees after the cover.',
            ['proposal' => $pin['headline'], 'note' => $pin['why'] . '. On Facebook: the post\'s "..." menu > Pin post.', 'how' => 'manual', 'tick' => 'pin_' . date('Y-m'), 'link' => $pin['url'], 'link_label' => 'Open the post']);
    }
    return ['connected' => true, 'note' => '', 'items' => $it];
}

function pm_opt_check_instagram(string $brand, array $f, array $st): array
{
    $c = pm_social_cfg($brand);
    if ($c['ig_id'] === '') {
        return ['connected' => false, 'note' => 'Instagram is not linked.', 'items' => [pm_opt_it('instagram', 'ig_link', 'Link Instagram', 'missing', 2, 'Posts, comments and reach for Instagram all need the link.',
            ['how' => 'go', 'link' => '?tab=settings&section=connections', 'link_label' => 'See the steps', 'note' => pm_ig_next_step($brand)])]];
    }
    [$ok, $ig] = pm_opt_ig_read($brand);
    if (!$ok) {
        return ['connected' => true, 'note' => 'Instagram could not be read: ' . $ig . ' (it needs the instagram_basic permission).', 'items' => []];
    }
    $user = (string)($ig['username'] ?? '');
    $edit = 'https://www.instagram.com/accounts/edit/';
    $bios = pm_bio_pack($brand);
    [$bioP, $bioAi] = pm_opt_pick($brand, 'ig_bio', (string)($bios['instagram']['text'] ?? ''), 150, $st, $f);
    $bio = trim((string)($ig['biography'] ?? ''));
    $it = [];
    $it[] = pm_opt_it('instagram', 'ig_picture', 'Profile picture', !empty($ig['profile_picture_url']) ? 'ok' : 'missing', 2, 'Recognition in every comment and search.', ['how' => 'manual', 'link' => '?tab=settings&section=connections', 'link_label' => 'Download the picture']);
    $it[] = pm_opt_it('instagram', 'ig_bio', 'Bio', mb_strlen($bio) >= 40 ? 'ok' : ($bio !== '' ? 'weak' : 'missing'), 3, 'Says what you do and how to reach you, in 150 characters.',
        ['current' => $bio, 'proposal' => $bioP, 'ai' => $bioAi, 'how' => 'manual', 'input' => 'text', 'limit' => 150, 'link' => $edit, 'link_label' => 'Open Instagram', 'note' => 'Instagram does not let apps change the bio: copy it, then paste it under Edit profile.']);
    $it[] = pm_opt_it('instagram', 'ig_website', 'Link in the bio', trim((string)($ig['website'] ?? '')) !== '' ? 'ok' : 'missing', 3, 'The only clickable link on the profile.',
        ['current' => (string)($ig['website'] ?? ''), 'proposal' => $f['site'], 'how' => 'manual', 'tick' => 'ig_link_in_bio', 'link' => $edit, 'link_label' => 'Open Instagram', 'note' => 'Edit profile > Links. Use the links page so every tap is counted.']);
    $media = (int)($ig['media_count'] ?? 0);
    $it[] = pm_opt_it('instagram', 'ig_posts', 'Enough posts to look alive', $media >= 9 ? 'ok' : ($media >= 3 ? 'weak' : 'missing'), 2, 'A profile with fewer than nine posts looks unused.',
        ['current' => $media . ' post' . ($media === 1 ? '' : 's') . ($user !== '' ? ' on @' . $user : ''), 'proposal' => 'Add 5 draft posts to the calendar from your facts (no AI). You read and approve each one.', 'how' => 'app', 'bulk' => $media < 9, 'button' => 'Fill the calendar with 5 drafts']);
    return ['connected' => true, 'note' => '', 'items' => $it];
}

function pm_opt_check_whatsapp(string $brand, array $f, array $st): array
{
    if (!function_exists('pm_wa_biz_cfg') && is_file(__DIR__ . '/wa_biz.php')) {
        require_once __DIR__ . '/wa_biz.php';
    }
    $c = function_exists('pm_wa_biz_cfg') ? pm_wa_biz_cfg($brand) : ['ready' => false];
    if (empty($c['ready'])) {
        return ['connected' => false, 'note' => 'WhatsApp Business is not connected for this business.', 'items' => []];
    }
    [$ok, $p] = pm_opt_wa_profile($brand);
    if (!$ok) {
        return ['connected' => true, 'note' => 'The WhatsApp Business profile could not be read: ' . $p, 'items' => []];
    }
    $has = fn($k) => trim((string)($p[$k] ?? '')) !== '';
    $hrs = !empty($st['hours_confirmed']) ? pm_opt_hours_text(pm_opt_hours($brand)) : '';
    [$aboutP, $aboutAi] = pm_opt_pick($brand, 'wa_about', pm_bio_fit([$f['tagline'], 'Malawi'], 139), 139, $st, $f);
    [$descP, $descAi] = pm_opt_pick($brand, 'wa_description', pm_opt_long($f, 512), 512, $st, $f);
    $vert = $brand === 'travel' ? 'TRAVEL' : ($brand === 'promanaged' ? 'PROF_SERVICES' : 'OTHER');
    $it = [];
    $it[] = pm_opt_it('whatsapp', 'wa_about', 'About line', mb_strlen((string)($p['about'] ?? '')) >= 15 ? 'ok' : ($has('about') ? 'weak' : 'missing'), 2, 'Shown under your name in the chat.', ['current' => (string)($p['about'] ?? ''), 'proposal' => $aboutP, 'ai' => $aboutAi,
        'how' => 'api', 'input' => 'text', 'limit' => 139, 'bulk' => $aboutP !== '', 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_description', 'Business description', mb_strlen((string)($p['description'] ?? '')) >= 60 ? 'ok' : ($has('description') ? 'weak' : 'missing'), 2, 'What people read before they message you.',
        ['current' => (string)($p['description'] ?? ''), 'proposal' => $descP, 'ai' => $descAi, 'how' => 'api', 'input' => 'textarea', 'limit' => 512, 'bulk' => $descP !== '', 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_address', 'Address', $has('address') ? 'ok' : 'missing', 1, 'Shown on the business profile.', ['current' => (string)($p['address'] ?? ''), 'proposal' => $f['address_real'] ? $f['address'] : '',
        'how' => 'api', 'input' => 'text', 'limit' => 256, 'bulk' => $f['address_real'], 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_email', 'Email', $has('email') ? 'ok' : 'missing', 1, 'Another way to reach you.', ['current' => (string)($p['email'] ?? ''), 'proposal' => $f['email'], 'how' => 'api', 'input' => 'text', 'limit' => 128,
        'bulk' => $f['email'] !== '', 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_websites', 'Website', !empty($p['websites']) ? 'ok' : 'missing', 2, 'A tap from the chat to your site.', ['current' => implode(', ', (array)($p['websites'] ?? [])), 'proposal' => $f['site'],
        'how' => 'api', 'input' => 'text', 'limit' => 256, 'bulk' => $f['site'] !== '', 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_vertical', 'Business category', $has('vertical') && ($p['vertical'] ?? '') !== 'UNDEFINED' ? 'ok' : 'missing', 1, 'How WhatsApp lists the business.', ['current' => (string)($p['vertical'] ?? ''), 'proposal' => $vert,
        'how' => 'api', 'input' => 'select', 'options' => PM_OPT_VERTICALS, 'bulk' => true, 'button' => 'Apply to WhatsApp']);
    $it[] = pm_opt_it('whatsapp', 'wa_greeting', 'Greeting message', pm_opt_ticked($brand, 'wa_greeting') ? 'ok' : 'missing', 2, 'Answers a new chat at once, so nobody waits wondering.',
        ['proposal' => 'Hello, and thank you for contacting ' . $f['name'] . '. ' . ($f['tagline'] !== '' ? rtrim($f['tagline'], '.') . '. ' : '') . 'How can we help you today?', 'how' => 'manual', 'tick' => 'wa_greeting',
            'note' => 'WhatsApp Business app > Settings > Business tools > Greeting message. The Cloud API cannot set it.']);
    $it[] = pm_opt_it('whatsapp', 'wa_away', 'Away message', pm_opt_ticked($brand, 'wa_away') ? 'ok' : 'missing', 2, 'Tells people when to expect an answer outside working hours.',
        ['proposal' => 'Thank you for your message. We are away at the moment' . ($hrs !== '' ? ' (we answer ' . $hrs . ')' : '') . ' and will reply on the next working day.', 'how' => 'manual', 'tick' => 'wa_away',
            'note' => 'WhatsApp Business app > Business tools > Away message.' . ($hrs === '' ? ' Save your opening hours above and this line fills in.' : '')]);
    $it[] = pm_opt_it('whatsapp', 'wa_catalogue', 'Catalogue', pm_opt_ticked($brand, 'wa_catalogue') ? 'ok' : 'missing', 1, 'Shows what you offer inside the chat (no prices needed).',
        ['how' => 'go', 'link' => '?tab=social&view=channels#catalogue', 'link_label' => 'Write the entries', 'tick' => 'wa_catalogue']);
    return ['connected' => true, 'note' => '', 'items' => $it];
}

function pm_opt_check_google(string $brand, array $f, array $st): array
{
    $cfg = pm_channels_cfg($brand);
    $hrs = pm_opt_hours_text(pm_opt_hours($brand));
    [$descP, $descAi] = pm_opt_pick($brand, 'g_description', pm_opt_long($f, 750), 750, $st, $f);
    $facts = array_slice($f['facts'], 0, 3);
    $it = [];
    $it[] = pm_opt_it('google', 'g_verified', 'Profile verified', pm_opt_ticked($brand, 'google_verified') ? 'ok' : 'missing', 3, 'Nothing shows publicly on Google until it is verified.',
        ['how' => 'manual', 'tick' => 'google_verified', 'link' => 'https://business.google.com/', 'link_label' => 'Open Google Business', 'note' => 'Finish verification (a postcard, phone call or video).']);
    $it[] = pm_opt_it('google', 'g_hours', 'Opening hours', !empty($st['hours_confirmed']) && pm_opt_ticked($brand, 'opt_g_hours') ? 'ok' : 'missing', 3, 'Google shows "open now" from these.',
        ['proposal' => $hrs, 'how' => 'manual', 'tick' => 'opt_g_hours', 'link' => 'https://business.google.com/', 'link_label' => 'Open Google Business', 'note' => !empty($st['hours_confirmed']) ? 'Your saved hours.' : 'Set your real hours in Business details first.']);
    $it[] = pm_opt_it('google', 'g_description', 'Business description', pm_opt_ticked($brand, 'opt_g_description') ? 'ok' : 'missing', 3, 'Up to 750 characters on your Maps listing, with no phone numbers or links.',
        ['proposal' => $descP, 'ai' => $descAi, 'how' => 'manual', 'input' => 'textarea', 'limit' => 750, 'tick' => 'opt_g_description', 'link' => 'https://business.google.com/', 'link_label' => 'Open Google Business']);
    $it[] = pm_opt_it('google', 'g_services', 'Services listed', pm_opt_ticked($brand, 'opt_g_services') ? 'ok' : 'missing', 2, 'Each service with a one-line description helps you appear for what you do.',
        ['proposal' => implode("\n", $facts), 'how' => 'manual', 'tick' => 'opt_g_services', 'link' => 'https://business.google.com/', 'link_label' => 'Open Google Business', 'note' => 'Your own facts, one per line, as a starting list.']);
    $it[] = pm_opt_it('google', 'g_photos', 'At least five real photos', pm_opt_ticked($brand, 'opt_g_photos') ? 'ok' : 'missing', 2, 'Listings with real photos get more taps. Use your own, not stock pictures.',
        ['how' => 'manual', 'tick' => 'opt_g_photos', 'link' => 'https://business.google.com/', 'link_label' => 'Open Google Business']);
    $rev = trim((string)($cfg['google_review_url'] ?? ''));
    $it[] = pm_opt_it('google', 'g_review_link', 'Review link saved', $rev !== '' ? 'ok' : 'missing', 2, 'So every happy client can leave a review in two taps.',
        ['current' => $rev, 'how' => 'app', 'input' => 'text', 'limit' => 300, 'button' => 'Save the review link', 'link' => 'https://business.google.com/', 'link_label' => 'Find it in Google Business', 'note' => 'In Google Business: Ask for reviews > copy the link (https://g.page/r/...).']);
    $it[] = pm_opt_it('google', 'g_reviews', 'Asked happy clients for a review', pm_opt_ticked($brand, 'opt_g_reviews') ? 'ok' : 'missing', 2, 'Reviews are the biggest local ranking signal. Never offer anything in return.',
        ['how' => 'manual', 'tick' => 'opt_g_reviews', 'note' => 'Ask three clients this week, with the review link.']);
    $gp = pm_gbp_pack($brand, 1);
    $it[] = pm_opt_it('google', 'g_posts', 'A weekly update posted', pm_opt_ticked($brand, 'opt_g_posts_' . date('oW')) ? 'ok' : 'missing', 1, 'Fresh posts keep the listing active.',
        ['how' => 'go', 'link' => '?tab=social&view=channels#google', 'link_label' => $gp ? 'Open this week\'s update' : 'Open the Google pack', 'tick' => 'opt_g_posts_' . date('oW')]);
    return ['connected' => true, 'note' => 'Google has no connection to this app: copy each suggestion and tick it when you have done it.', 'items' => $it];
}

/** X, LinkedIn, TikTok, YouTube: a bio to copy and the places to put it. These platforms give small businesses no way for an app to edit a profile. */
function pm_opt_check_social_bio(string $brand, string $ch, array $f, array $st): array
{
    $bios = pm_bio_pack($brand);
    $map = ['x' => ['x', 'X bio', 160, 'https://x.com/settings/profile', 'x_bio'], 'linkedin' => ['linkedin', 'LinkedIn tagline', 120, 'https://www.linkedin.com/company/', 'li_tagline'],
        'tiktok' => ['tiktok', 'TikTok bio', 80, 'https://www.tiktok.com/', 'tt_bio'], 'youtube' => ['linkedin', 'YouTube channel description', 300, 'https://studio.youtube.com/', 'yt_bio']];
    [$bk, $label, $limit, $url, $key] = $map[$ch];
    $plain = $ch === 'youtube' ? pm_opt_long($f, 300) : (string)($bios[$bk]['text'] ?? '');
    [$p, $ai] = pm_opt_pick($brand, $key, $plain, $limit, $st, $f);
    $it = [];
    $it[] = pm_opt_it($ch, $key, $label, pm_opt_ticked($brand, 'opt_' . $key) ? 'ok' : 'missing', 2, 'The first words a visitor reads on the profile.', ['proposal' => $p, 'ai' => $ai, 'how' => 'manual', 'input' => 'text', 'limit' => $limit,
        'tick' => 'opt_' . $key, 'link' => $url, 'link_label' => 'Open ' . PM_OPT_CHANNELS[$ch], 'note' => 'Copy it, paste it into the profile, then tick it.']);
    $it[] = pm_opt_it($ch, $ch . '_link', 'Link to the website', pm_opt_ticked($brand, 'opt_' . $ch . '_link') ? 'ok' : 'missing', 2, 'Where a curious visitor goes next.', ['proposal' => $f['site'], 'how' => 'manual', 'tick' => 'opt_' . $ch . '_link', 'link' => $url, 'link_label' => 'Open ' . PM_OPT_CHANNELS[$ch]]);
    $it[] = pm_opt_it($ch, $ch . '_pictures', 'Profile and cover pictures', pm_opt_ticked($brand, 'opt_' . $ch . '_pictures') ? 'ok' : 'missing', 1, 'Made at the right size for this platform.', ['how' => 'go', 'link' => '?tab=settings&section=connections', 'link_label' => 'Download the pictures', 'tick' => 'opt_' . $ch . '_pictures']);
    return ['connected' => true, 'note' => 'This platform gives apps no way to edit a profile: copy, paste, then tick.', 'items' => $it];
}

/** Which channels this business uses: Facebook and Instagram when linked or switched on, WhatsApp when connected, Google always, the others when switched on or connected. */
function pm_opt_channels(string $brand): array
{
    if (!function_exists('pm_wa_biz_cfg') && is_file(__DIR__ . '/wa_biz.php')) {
        require_once __DIR__ . '/wa_biz.php';
    }
    $set = pm_social_settings($brand);
    $cfg = pm_channels_cfg($brand);
    $use = fn($k) => !empty($cfg['ticks']['use_' . $k]); // the owner says "we are on this one"
    $list = ['business', 'facebook', 'instagram'];
    if (function_exists('pm_wa_biz_cfg') && pm_wa_biz_cfg($brand)['ready']) {
        $list[] = 'whatsapp';
    }
    $list[] = 'google';
    if (pm_x_cfg($brand)['ready'] || $use('x')) {
        $list[] = 'x';
    }
    if (!empty($set['channels']['linkedin']) || $use('linkedin')) {
        $list[] = 'linkedin';
    }
    foreach (['tiktok', 'youtube'] as $ch) {
        if ($use($ch)) {
            $list[] = $ch;
        }
    }
    return $list;
}

/**
 * Runs every check for the business and stores the score. Returns ['at','score','fixable','channels' => [key => ['label','connected','note','score','items']]].
 * Reading uses the Graph layer's own cache, so this is cheap to repeat; no AI.
 */
function pm_opt_run(string $brand, bool $save = true): array
{
    $brand = pm_brand_norm($brand);
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $f = pm_opt_facts($brand);
        $st = pm_opt_store($brand);
        if (empty($st['hours_confirmed']) && pm_social_cfg($brand)['ready']) { // hours already on the Page are the owner's real hours
            [$okp, $pgp] = pm_opt_fb_read($brand);
            $fbh = $okp ? pm_opt_hours_from_fb((array)($pgp['hours'] ?? [])) : [];
            if ($fbh) {
                $st = pm_opt_update($brand, function (array $d) use ($fbh) {
                    $d['hours'] = $fbh;
                    $d['hours_confirmed'] = true;
                    return $d;
                });
            }
        }
        $out = [];
        $tw = 0.0;
        $tp = 0.0;
        $fixable = 0;
        foreach (pm_opt_channels($brand) as $ch) {
            try {
                $r = match ($ch) {
                    'business' => ['connected' => true, 'note' => '', 'items' => pm_opt_check_business($brand, $f, $st)],
                    'facebook' => pm_opt_check_facebook($brand, $f, $st),
                    'instagram' => pm_opt_check_instagram($brand, $f, $st),
                    'whatsapp' => pm_opt_check_whatsapp($brand, $f, $st),
                    'google' => pm_opt_check_google($brand, $f, $st),
                    default => pm_opt_check_social_bio($brand, $ch, $f, $st),
                };
            } catch (Throwable $e) {
                $r = ['connected' => true, 'note' => 'This check could not run: ' . mb_substr($e->getMessage(), 0, 120), 'items' => []];
            }
            $w = 0;
            $p = 0.0;
            foreach ($r['items'] as $i) {
                $w += $i['weight'];
                $p += $i['weight'] * ($i['state'] === 'ok' ? 1 : ($i['state'] === 'weak' ? 0.4 : 0));
                $fixable += $i['state'] !== 'ok' && $i['bulk'] ? 1 : 0;
            }
            $r['label'] = PM_OPT_CHANNELS[$ch];
            $counted = !empty($r['connected']) && $w > 0; // a channel that is not connected yet is not scored: its only item is "connect it"
            $r['score'] = $counted ? (int)round(100 * $p / $w) : null;
            $r['weight'] = $counted ? $w : 0;
            if ($counted) {
                $tw += $w * ($ch === 'business' ? 0.7 : 1);
                $tp += $p * ($ch === 'business' ? 0.7 : 1);
            }
            $out[$ch] = $r;
        }
        $res = ['at' => date('Y-m-d H:i'), 'score' => $tw > 0 ? (int)round(100 * $tp / $tw) : 0, 'fixable' => $fixable, 'channels' => $out];
        if ($save) {
            $todo = [];
            foreach ($res['channels'] as $c) {
                foreach ($c['items'] as $i) {
                    if ($i['state'] !== 'ok') {
                        $todo[] = $i['title'];
                    }
                }
            }
            pm_opt_update($brand, function (array $d) use ($res, $todo) {
                $d['last'] = ['at' => $res['at'], 'score' => $res['score'], 'fixable' => $res['fixable'], 'channels' => array_map(fn($c) => $c['score'], $res['channels']), 'todo' => array_slice($todo, 0, 6)];
                $d['history'][date('Y-m-d')] = $res['score'];
                if (count($d['history']) > 60) {
                    ksort($d['history']);
                    $d['history'] = array_slice($d['history'], -60, null, true);
                }
                return $d;
            });
        }
        return $res;
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- applying ---------------- */

/** The business's own settings block gets one value (phone, email, website, address, tagline). */
function pm_opt_set_detail(string $brand, string $field, string $value): void
{
    pm_update('settings', function (array $s) use ($brand, $field, $value) {
        $blk = pm_brand_block($s, $brand);
        $blk[$field] = $value;
        pm_brand_block_put($s, $brand, $blk);
        return $s;
    }, 'pm_default_settings');
}

/** Checks a typed value for one suggestion: [true, cleaned] or [false, message]. */
function pm_opt_clean(string $key, string $v, array $it = []): array
{
    $v = trim((string)preg_replace('/\s+/u', ' ', strip_tags($v)));
    $limit = (int)($it['limit'] ?? 0);
    if ($v === '') {
        return [false, 'There is nothing to apply: the box is empty.'];
    }
    if ($limit > 0 && mb_strlen($v) > $limit) {
        return [false, 'That is ' . mb_strlen($v) . ' characters; the limit is ' . $limit . '.'];
    }
    if (str_ends_with($key, '_phone')) {
        return preg_match_all('/\d/', $v) >= 7 && preg_match('/^[+\d][\d\s().\-]{5,29}$/', $v) ? [true, $v] : [false, '"' . mb_substr($v, 0, 30) . '" does not look like a phone number.'];
    }
    if (str_ends_with($key, '_email')) {
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? [true, strtolower($v)] : [false, '"' . mb_substr($v, 0, 40) . '" is not an email address.'];
    }
    if (str_ends_with($key, '_website') || str_ends_with($key, '_websites')) {
        $u = pm_clean_url($v);
        return $u !== '' ? [true, $u] : [false, '"' . mb_substr($v, 0, 40) . '" is not a web address.'];
    }
    if ($key === 'g_review_link') {
        return preg_match('#^https://\S+$#i', $v) ? [true, $v] : [false, 'The review link must start with https://'];
    }
    if ($key === 'wa_vertical') {
        return isset(PM_OPT_VERTICALS[$v]) ? [true, $v] : [false, 'Choose one of the categories in the list.'];
    }
    if (in_array($key, ['biz_address', 'biz_tagline', 'fb_about', 'fb_description', 'wa_about', 'wa_description', 'wa_address'], true) && mb_strlen($v) < 5) {
        return [false, 'That is too short to be useful.'];
    }
    return [true, $v];
}

function pm_opt_fb_set(string $brand, array $params): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return [false, 'Connect the Facebook Page first.'];
    }
    [$ok, $d] = pm_graph('POST', $c['page_id'], $params, $c['token']);
    return $ok ? [true, ''] : [false, 'Facebook would not take it (' . (is_string($d) ? mb_substr($d, 0, 200) : 'no reason given') . '). Copy the text and paste it at Page > About > Edit details.'];
}

/** Fills the calendar with five template drafts from the facts, no AI. They wait for the owner's approval. Returns [ok, message]. */
function pm_opt_fill_calendar(string $brand): array
{
    $start = pm_social_next_free_date(pm_social_posts(), $brand, date('Y-m-d', strtotime('+1 day')));
    $new = pm_plan_offline($brand, 5, $start);
    $n = pm_social_add_planned($new, false);
    return $n > 0 ? [true, "$n draft post" . ($n === 1 ? '' : 's') . ' added to the calendar from your facts (no AI). Read and approve them under Plan; nothing posts until you do.'] : [false, 'No drafts could be made. Add more facts about the business first.'];
}

/**
 * Applies one suggestion. $val is the text the owner saw (and may have edited); $hr is the hours form. Returns [ok, message].
 * Only keys that exist in a fresh check can be applied, and the value is validated before anything is sent.
 */
function pm_opt_apply(string $brand, string $key, string $val = '', array $hr = []): array
{
    $brand = pm_brand_norm($brand);
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $f = pm_opt_facts($brand);
        $st = pm_opt_store($brand);
        $found = null;
        foreach (pm_opt_run($brand, false)['channels'] as $c) {
            foreach ($c['items'] as $i) {
                if ($i['key'] === $key) {
                    $found = $i;
                }
            }
        }
        if (!$found || !in_array($found['how'], ['api', 'app'], true)) {
            return [false, 'That suggestion cannot be applied from here (it may already be done).'];
        }
        $ch = $found['channel'];
        $done = function (bool $ok, string $msg) use ($brand, $ch, $key) {
            pm_opt_log($brand, $ch, $key, $ok, $msg);
            return [$ok, $msg];
        };
        // pictures, hours and the calendar carry no typed text
        if ($key === 'fb_picture' || $key === 'fb_cover') {
            [$ok, $m] = pm_fb_apply($brand, $key === 'fb_cover' ? 'cover' : 'profile');
            return $done($ok, $m);
        }
        if ($key === 'fb_posting' || $key === 'ig_posts') {
            [$ok, $m] = pm_opt_fill_calendar($brand);
            return $done($ok, $m);
        }
        if ($key === 'biz_hours' || $key === 'fb_hours') {
            [$ok, $h] = pm_opt_hours_parse($hr ?: []);
            if (!$ok) {
                return [false, (string)$h];
            }
            pm_opt_update($brand, function (array $d) use ($h) {
                $d['hours'] = $h;
                $d['hours_confirmed'] = true;
                return $d;
            });
            if ($key === 'biz_hours') {
                return $done(true, 'Opening hours saved: ' . pm_opt_hours_text($h) . '. Every channel suggestion now uses them.');
            }
            [$ok, $m] = pm_opt_fb_set($brand, ['hours' => pm_opt_hours_fb($h)]);
            return $done($ok, $ok ? 'Opening hours applied to the Facebook Page: ' . pm_opt_hours_text($h) . '.' : $m);
        }
        [$ok, $v] = pm_opt_clean($key, $val, $found);
        if (!$ok) {
            return [false, (string)$v];
        }
        $v = (string)$v;
        switch ($key) {
            case 'biz_phone':
            case 'biz_email':
            case 'biz_website':
            case 'biz_address':
            case 'biz_tagline':
                pm_opt_set_detail($brand, substr($key, 4), $key === 'biz_website' ? preg_replace('#^https?://#i', '', rtrim($v, '/')) : $v);
                return $done(true, $found['title'] . ' saved. The suggestions on every channel now use it.');
            case 'g_review_link':
                pm_channels_update($brand, function (array $c) use ($v) {
                    $c['google_review_url'] = mb_substr($v, 0, 300);
                    return $c;
                });
                return $done(true, 'Google review link saved.');
            case 'fb_about':
            case 'fb_description':
            case 'fb_phone':
            case 'fb_website':
                [$ok, $m] = pm_opt_fb_set($brand, [substr($key, 3) => $v]);
                return $done($ok, $ok ? $found['title'] . ' applied to the Facebook Page.' : $m);
            case 'fb_email':
                [$ok, $m] = pm_opt_fb_set($brand, ['emails' => [$v]]);
                return $done($ok, $ok ? 'Email applied to the Facebook Page.' : $m);
            case 'wa_about':
            case 'wa_description':
            case 'wa_address':
            case 'wa_email':
            case 'wa_vertical':
                [$ok, $m] = pm_opt_wa_profile($brand, [substr($key, 3) => $v]);
                return $done($ok, $ok ? $found['title'] . ' applied to the WhatsApp Business profile.' : (string)$m);
            case 'wa_websites':
                [$ok, $m] = pm_opt_wa_profile($brand, ['websites' => [$v]]);
                return $done($ok, $ok ? 'Website applied to the WhatsApp Business profile.' : (string)$m);
        }
        return [false, 'That suggestion cannot be applied from here.'];
    } finally {
        pm_brand_set($prev);
    }
}

/** Applies every suggestion marked bulk-safe (complete, from the business's own details, nothing the owner still has to type). Returns [applied, failed[], messages[]]. */
function pm_opt_apply_all(string $brand): array
{
    $brand = pm_brand_norm($brand);
    $run = pm_opt_run($brand, false);
    $applied = 0;
    $fail = [];
    $msgs = [];
    foreach ($run['channels'] as $c) {
        foreach ($c['items'] as $i) {
            if ($i['state'] === 'ok' || !$i['bulk'] || !in_array($i['how'], ['api', 'app'], true)) {
                continue;
            }
            $hr = [];
            if ($i['input'] === 'hours') {
                foreach (pm_opt_hours($brand) as $d => $v) {
                    $hr[$d] = $v ? ['on' => 1, 'from' => $v[0], 'to' => $v[1]] : [];
                }
            }
            [$ok, $m] = pm_opt_apply($brand, $i['key'], (string)$i['proposal'], $hr);
            if ($ok) {
                $applied++;
                $msgs[] = $i['title'];
            } else {
                $fail[] = $i['title'] . ': ' . $m;
            }
        }
    }
    return [$applied, $fail, $msgs];
}

/**
 * One optional AI call: polishes the text suggestions (never adds a fact). Only texts that pass pm_opt_ai_ok are kept. Returns [kept, tried, message].
 */
function pm_opt_polish(string $brand): array
{
    $brand = pm_brand_norm($brand);
    $f = pm_opt_facts($brand);
    $run = pm_opt_run($brand, false);
    $items = [];
    foreach ($run['channels'] as $c) {
        foreach ($c['items'] as $i) {
            if ($i['state'] !== 'ok' && $i['proposal'] !== '' && in_array($i['input'], ['text', 'textarea'], true) && $i['limit'] >= 80 && !str_starts_with($i['key'], 'biz_') && !in_array($i['key'], ['fb_phone', 'fb_website', 'fb_email', 'wa_email', 'wa_websites', 'wa_address', 'g_review_link'], true)) {
                $items[$i['key']] = ['key' => $i['key'], 'channel' => PM_OPT_CHANNELS[$i['channel']] ?? $i['channel'], 'limit' => $i['limit'], 'text' => $i['proposal']];
            }
        }
    }
    if (!$items) {
        return [0, 0, 'There is no text suggestion to polish.'];
    }
    $source = implode(' ', array_filter(array_map('strval', [$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), $f['address'], $f['phone']])));
    $system = pm_agents_company_brief('tiny') . "\nYou polish short profile texts for " . $f['name'] . '. Rewrite each text so it reads naturally and clearly, in plain English, within its character limit. '
        . 'Use ONLY what the text and the facts already say: add no claim, number, price, award, place, name or promise, and no link, email, phone number, hashtag, emoji or exclamation mark. Do not say "best" or "leading". Reply JSON only.';
    $user = json_encode(['facts' => array_slice($f['facts'], 0, 8), 'items' => array_values($items)], JSON_UNESCAPED_UNICODE) . "\nJSON: {\"items\":[{\"key\":\"\",\"text\":\"\"}]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 300 + 220 * count($items), 'cheap'));
    $list = is_array($out) ? (array_is_list($out) ? $out : (array)($out['items'] ?? [])) : [];
    $keep = [];
    foreach ($list as $x) {
        if (!is_array($x) || !is_scalar($x['key'] ?? null) || !is_scalar($x['text'] ?? null) || !isset($items[(string)$x['key']])) {
            continue;
        }
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$x['text'])));
        $k = (string)$x['key'];
        if ($t !== '' && $t !== $items[$k]['text'] && pm_opt_ai_ok($t, (int)$items[$k]['limit'], $source)) {
            $keep[$k] = $t;
        }
    }
    if ($keep) {
        pm_opt_update($brand, function (array $d) use ($keep) {
            $d['ai'] = $keep + (array)$d['ai'];
            return $d;
        });
    }
    return [count($keep), count($items), count($keep) . ' of ' . count($items) . ' texts were reworded. The ones that added a claim or went over the limit were thrown away.'];
}

/* ---------------- one suggestion as a card (used by Optimise and by the Audit) ---------------- */

function pm_opt_hours_grid(array $h): string
{
    $o = '<div class="opt-hours">';
    foreach (PM_OPT_DAYS as $d => $label) {
        $v = $h[$d] ?? null;
        $o .= '<div><label class="check"><input type="checkbox" name="hr[' . $d . '][on]" value="1" ' . ($v ? 'checked' : '') . '> ' . pm_h($label) . '</label>'
            . '<input type="time" name="hr[' . $d . '][from]" value="' . pm_h($v[0] ?? '08:00') . '" aria-label="' . pm_h($label) . ' opens"> to '
            . '<input type="time" name="hr[' . $d . '][to]" value="' . pm_h($v[1] ?? '17:00') . '" aria-label="' . pm_h($label) . ' closes"></div>';
    }
    return $o . '</div>';
}

/** The card for one suggestion: what is wrong, what it is now, the text to apply (editable), and the button. $back = 'audit' sends the owner back to the Audit afterwards. */
function pm_opt_item_html(array $i, string $vb, string $csrf, string $back = ''): string
{
    $fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">'
        . ($back !== '' ? '<input type="hidden" name="back" value="' . pm_h($back) . '">' : '') . $extra;
    $hid = fn(string $n, string $v) => '<input type="hidden" name="' . pm_h($n) . '" value="' . pm_h($v) . '">';
    $cp = fn(string $text, string $label = 'Copy') => '<button type="button" class="btn small" data-copy="' . pm_h($text) . '">' . pm_h($label) . '</button>';
    [$lab, $cls] = ['missing' => ['Missing', 'bad'], 'weak' => ['Could be better', 'warn'], 'ok' => ['Good', 'ok']][$i['state']];
    $h = '<div class="sx-card opt-it" id="i-' . pm_h($i['key']) . '"><div class="sxh"><span class="sx-chip ' . $cls . '">' . $lab . '</span> <b>' . pm_h($i['title']) . '</b>'
        . ($i['ai'] ? ' <span class="sx-chip" title="Reworded by the AI from your facts; checked for new claims, numbers and links">AI</span>' : '') . ' <span class="muted">· ' . pm_h($i['why']) . '</span></div>';
    if ($i['current'] !== '') {
        $h .= '<p class="hint" style="margin:6px 0 0">Now: ' . pm_h(mb_substr($i['current'], 0, 300)) . '</p>';
    }
    if ($i['note'] !== '') {
        $h .= '<p class="hint" style="margin:4px 0 0">' . pm_h($i['note']) . '</p>';
    }
    $link = $i['link'] !== '' ? '<a class="btn small" href="' . pm_h($i['link']) . '" ' . (str_starts_with($i['link'], 'http') ? 'target="_blank" rel="noopener noreferrer"' : '') . '>' . pm_h($i['link_label'] ?: 'Open') . '</a>' : '';
    if (in_array($i['how'], ['api', 'app'], true)) {
        $h .= '<form method="post" class="opt-form">' . $fx((string)$i['do'], $hid('key', $i['key']));
        if ($i['input'] === 'hours') {
            $h .= pm_opt_hours_grid(pm_opt_hours($vb));
        } elseif ($i['input'] === 'textarea') {
            $h .= '<textarea name="val[' . pm_h($i['key']) . ']" rows="' . (int)($i['rows'] ?? 3) . '" maxlength="' . (int)$i['limit'] . '" aria-label="' . pm_h($i['title']) . '">' . pm_h($i['proposal']) . '</textarea>'
                . '<span class="sx-chip">' . mb_strlen($i['proposal']) . '/' . (int)$i['limit'] . '</span>';
        } elseif ($i['input'] === 'select') {
            $h .= '<select name="val[' . pm_h($i['key']) . ']" aria-label="' . pm_h($i['title']) . '">';
            foreach ($i['options'] as $v => $l) {
                $h .= '<option value="' . pm_h($v) . '" ' . ($v === $i['proposal'] ? 'selected' : '') . '>' . pm_h($l) . '</option>';
            }
            $h .= '</select>';
        } elseif ($i['input'] === 'text') {
            $h .= '<input type="text" name="val[' . pm_h($i['key']) . ']" value="' . pm_h($i['proposal']) . '" maxlength="' . (int)$i['limit'] . '" aria-label="' . pm_h($i['title']) . '" placeholder="Type it here" autocomplete="off">';
        } else {
            $h .= '<p class="hint" style="margin:6px 0 0">' . pm_h($i['proposal']) . '</p>';
        }
        return $h . '<div class="btns"><button class="btn small primary">' . pm_h($i['button'] ?: 'Apply') . '</button>' . (in_array($i['input'], ['text', 'textarea'], true) && $i['proposal'] !== '' ? $cp($i['proposal']) : '') . $link . '</div></form></div>';
    }
    if ($i['proposal'] !== '') {
        $h .= '<pre class="opt-pre">' . pm_h($i['proposal']) . '</pre>';
    }
    $h .= '<div class="sxb">' . ($i['proposal'] !== '' ? $cp($i['proposal']) : '') . $link;
    if ($i['tick'] !== '') {
        $h .= '<form method="post" class="inl">' . $fx('opt_done', $hid('tick', $i['tick'])) . '<button class="btn small primary">I did it</button></form>';
    }
    return $h . '</div></div>';
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_do_opt_apply(string $vb): array
{
    $key = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['key'] ?? ''));
    $val = $_POST['val'][$key] ?? '';
    [$ok, $m] = pm_opt_apply($vb, $key, is_scalar($val) ? (string)$val : '', (array)($_POST['hr'] ?? []));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => ($_POST['back'] ?? '') === 'audit' ? 'social&view=audit#i-' . $key : 'social&view=optimise#c-' . pm_opt_anchor($key)];
}

function pm_opt_anchor(string $key): string
{
    return ['biz' => 'business', 'fb' => 'facebook', 'ig' => 'instagram', 'wa' => 'whatsapp', 'g' => 'google', 'x' => 'x', 'li' => 'linkedin', 'linkedin' => 'linkedin', 'tt' => 'tiktok', 'tiktok' => 'tiktok',
        'yt' => 'youtube', 'youtube' => 'youtube'][explode('_', $key)[0]] ?? 'business';
}

function pm_do_opt_all(string $vb): array
{
    [$n, $fail, $msgs] = pm_opt_apply_all($vb);
    if ($n === 0 && !$fail) {
        return ['msg' => 'Nothing is waiting that can be applied in one go.', 'kind' => 'ok', 'to' => 'social&view=optimise'];
    }
    return ['msg' => $n . ' suggestion' . ($n === 1 ? '' : 's') . ' applied' . ($msgs ? ' (' . implode(', ', array_slice($msgs, 0, 8)) . ')' : '') . ($fail ? '. ' . count($fail) . ' could not be: ' . implode(' ', array_slice($fail, 0, 3)) : '') . '.',
        'kind' => $fail && !$n ? 'err' : 'ok', 'to' => 'social&view=optimise'];
}

function pm_do_opt_done(string $vb): array
{
    $tick = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['tick'] ?? ''));
    $allowed = [];
    foreach (pm_opt_run($vb, false)['channels'] as $c) {
        foreach ($c['items'] as $i) {
            if ($i['tick'] !== '') {
                $allowed[$i['tick']] = $i['title'];
            }
        }
    }
    if ($tick === '' || !isset($allowed[$tick])) {
        return ['msg' => 'That item is not on the list.', 'kind' => 'err', 'to' => 'social&view=optimise'];
    }
    $undo = ($_POST['how'] ?? '') === 'undo';
    pm_channels_update($vb, function (array $c) use ($tick, $undo) {
        if ($undo) {
            unset($c['ticks'][$tick]);
        } else {
            $c['ticks'][$tick] = true;
        }
        return $c;
    });
    return ['msg' => $undo ? '"' . $allowed[$tick] . '" is back on the list.' : '"' . $allowed[$tick] . '" ticked off.', 'kind' => 'ok', 'to' => ($_POST['back'] ?? '') === 'audit' ? 'social&view=audit' : 'social&view=optimise'];
}

function pm_do_opt_use(string $vb): array
{
    $on = (array)($_POST['use'] ?? []);
    pm_channels_update($vb, function (array $c) use ($on) {
        foreach (['x', 'linkedin', 'tiktok', 'youtube'] as $k) {
            if (!empty($on[$k])) {
                $c['ticks']['use_' . $k] = true;
            } else {
                unset($c['ticks']['use_' . $k]);
            }
        }
        return $c;
    });
    return ['msg' => 'Saved. The checklist now covers the channels you ticked.', 'kind' => 'ok', 'to' => 'social&view=optimise'];
}

function pm_do_opt_refresh(string $vb): array
{
    array_map('unlink', glob(pm_graph_cache_dir() . '/*.json') ?: []);
    $r = pm_opt_run($vb);
    return ['msg' => 'Checked again: ' . $r['score'] . '% (' . $r['fixable'] . ' suggestion' . ($r['fixable'] === 1 ? '' : 's') . ' ready to apply).', 'kind' => 'ok', 'to' => 'social&view=optimise'];
}

function pm_do_opt_polish(string $vb): array
{
    if (!pm_agents_ready()) {
        return ['msg' => 'The AI needs a key in .env first. The plain suggestions work without it.', 'kind' => 'err', 'to' => 'social&view=optimise'];
    }
    try {
        [, , $m] = pm_opt_polish($vb);
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not polish the texts: ' . mb_substr($e->getMessage(), 0, 120) . '. The plain suggestions are unchanged.', 'kind' => 'err', 'to' => 'social&view=optimise'];
    }
    return ['msg' => $m, 'kind' => 'ok', 'to' => 'social&view=optimise'];
}

function pm_do_opt_plain(string $vb): array
{
    pm_opt_update($vb, function (array $d) {
        $d['ai'] = [];
        return $d;
    });
    return ['msg' => 'Back to the plain suggestions.', 'kind' => 'ok', 'to' => 'social&view=optimise'];
}

/* ---------------- daily check and the Today list ---------------- */

/** Once a day per business: re-reads the channels, keeps the score history. Reads only; changes nothing on any channel. */
function pm_job_pageopt(string $brand): string
{
    $st = pm_opt_store($brand);
    if (substr((string)($st['last']['at'] ?? ''), 0, 10) === date('Y-m-d')) {
        return '';
    }
    $was = (int)($st['last']['score'] ?? -1);
    $r = pm_opt_run($brand);
    return 'Page health ' . $r['score'] . '%' . ($was >= 0 ? ' (' . ($r['score'] >= $was ? '+' : '') . ($r['score'] - $was) . ')' : '') . ', ' . $r['fixable'] . ' suggestion' . ($r['fixable'] === 1 ? '' : 's') . ' ready to apply';
}

/** From the stored check (no network): one Today line when there is something to improve. */
function pm_today_pageopt(string $brand): array
{
    $l = (array)(pm_opt_store($brand)['last'] ?? []);
    if (empty($l['at']) || (int)($l['score'] ?? 100) >= 90) {
        return [];
    }
    $n = (int)($l['fixable'] ?? 0);
    return [['text' => 'Page health ' . (int)$l['score'] . '%: ' . ($n > 0 ? $n . ' fix' . ($n === 1 ? '' : 'es') . ' ready to apply' : 'see what to improve') . (!empty($l['todo'][0]) ? ' (first: ' . $l['todo'][0] . ')' : ''),
        'href' => '?tab=social&view=optimise', 'urgency' => (int)$l['score'] < 60 ? 1 : 0]];
}
