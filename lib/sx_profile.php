<?php
/**
 * Profile health and bios: Page completeness (read from Facebook, read-only) plus owner ticks for the places no API reaches,
 * one-line bios from the Marketing brain trimmed to each platform's limit, and the post to pin. No AI.
 */

/** Manual checks: key => [label, group, fix text, link]. Ticks live in data/social_channels.json. */
function pm_profile_ticks(string $brand): array
{
    $travel = $brand === 'travel';
    return [
        'fb_action_button' => ['Facebook action button set (Send WhatsApp message)', 'Facebook', 'On the Page: Edit action button > Send WhatsApp message, with your number.', ''],
        'ig_linked' => ['Instagram is a business account linked to the Page', 'Instagram', 'Instagram > Settings > Account type > Business, then link it to the Facebook Page.', 'https://www.instagram.com/accounts/edit/'],
        'ig_link_in_bio' => ['Link in bio set (the links page or the website)', 'Instagram', 'Instagram > Edit profile > Links. Use the links page so every tap is tagged.', 'https://www.instagram.com/accounts/edit/'],
        'tt_bio_link' => ['TikTok bio link set', 'TikTok', 'TikTok > Edit profile > Website (business accounts).', 'https://www.tiktok.com/'],
        'wa_greeting' => ['WhatsApp Business greeting message on', 'WhatsApp Business', 'WhatsApp Business > Settings > Business tools > Greeting message.', ''],
        'wa_away' => ['WhatsApp Business away message on', 'WhatsApp Business', 'WhatsApp Business > Business tools > Away message (outside working hours).', ''],
        'wa_catalogue' => ['WhatsApp Business catalogue added', 'WhatsApp Business', 'WhatsApp Business > Business tools > Catalogue: add ' . ($travel ? 'your stay types or the host sign-up' : 'your services and packages') . ' (no prices needed).', ''],
        'google_verified' => ['Google Business Profile verified', 'Google', 'business.google.com: finish verification. Nothing shows publicly until it is verified.', 'https://business.google.com/'],
    ];
}

/** The Page's own profile fields. Same request as the audit uses, so a cached answer is reused. null = not connected or unreadable. */
function pm_profile_page_fields(string $brand): ?array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return null;
    }
    [$ok, $pg] = pm_graph('GET', $c['page_id'], ['fields' => 'name,about,description,category,website,phone,emails,username,whatsapp_number,fan_count,followers_count,overall_star_rating,rating_count,picture{url},cover{source},hours,location'], $c['token']);
    return $ok && is_array($pg) ? $pg : null;
}

/** Pass/fail on each profile item with a fix list. Page items are null (not counted) when the Page cannot be read. */
function pm_profile_score(string $brand): array
{
    $brand = pm_ch_brand($brand);
    $cfg = pm_channels_cfg($brand);
    $pg = pm_profile_page_fields($brand);
    $c = pm_social_cfg($brand);
    $pageUrl = $c['page_id'] !== '' ? 'https://www.facebook.com/' . $c['page_id'] : 'https://www.facebook.com/pages/create';
    $items = [];
    $add = function (string $key, string $label, ?bool $pass, string $fix, string $link, string $group) use (&$items) {
        $items[] = ['key' => $key, 'label' => $label, 'pass' => $pass, 'fix' => $fix, 'link' => $link, 'group' => $group];
    };
    $has = fn($v) => $pg !== null ? !empty($v) : null;
    $add('fb_hours', 'Opening hours on the Page', $has($pg['hours'] ?? null), 'Page > About > Hours: add your working hours.', $pageUrl . '/about', 'Facebook');
    $add('fb_location', 'Location or service area', $has($pg['location'] ?? null), 'Page > About > Location: add the town or service area.', $pageUrl . '/about', 'Facebook');
    $add('fb_whatsapp', 'WhatsApp number on the Page', $has(($pg['whatsapp_number'] ?? '') ?: ($pg['phone'] ?? '')), 'Page > About > Contact: add your WhatsApp number.', $pageUrl . '/about', 'Facebook');
    $add('fb_website', 'Website link on the Page', $has($pg['website'] ?? null), 'Page > About > Websites: add the website or links page.', $pageUrl . '/about', 'Facebook');
    $add('fb_cover', 'Cover picture', $has($pg['cover'] ?? null), 'Make the cover in Accounts & branding, then upload it.', '?tab=social&view=accounts', 'Facebook');
    foreach (pm_profile_ticks($brand) as $k => [$label, $group, $fix, $link]) {
        $ticked = !empty($cfg['ticks'][$k]);
        if ($k === 'ig_linked' && $c['ig_id'] !== '') {
            $ticked = true; // the connection itself proves it
        }
        $add($k, $label, $ticked, $fix, $link, $group);
    }
    $counted = array_filter($items, fn($i) => $i['pass'] !== null);
    $pass = count(array_filter($counted, fn($i) => $i['pass']));
    return ['score' => $counted ? (int)round(100 * $pass / count($counted)) : 0, 'pass' => $pass, 'total' => count($counted), 'items' => $items,
        'fixes' => array_values(array_filter($items, fn($i) => $i['pass'] === false)), 'page_read' => $pg !== null];
}

function pm_do_ticks_save(string $vb): array
{
    $keys = array_keys(pm_profile_ticks($vb));
    $on = (array)($_POST['tick'] ?? []);
    $rev = trim((string)($_POST['google_review_url'] ?? ''));
    $bad = false;
    if ($rev !== '' && !preg_match('#^https://\S+$#i', $rev)) {
        $bad = true;
    }
    pm_channels_update($vb, function (array $c) use ($keys, $on, $rev, $bad) {
        if (isset($_POST['ticks_form'])) { // only the ticks form carries the checkboxes: another form must not clear them
            foreach ($keys as $k) {
                if (!empty($on[$k])) {
                    $c['ticks'][$k] = true;
                } else {
                    unset($c['ticks'][$k]);
                }
            }
        }
        if (isset($_POST['google_review_url']) && !$bad) {
            $c['google_review_url'] = mb_substr($rev, 0, 300);
        }
        return $c;
    });
    return $bad ? ['msg' => 'Saved the ticks. The review link must start with https://', 'kind' => 'err', 'to' => 'social&view=channels'] : ['msg' => 'Saved.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

/* ---------------- bios ---------------- */

/** Joins parts (highest priority first) with " | ", dropping the lowest priorities until it fits; if even the first does not fit it is cut. */
function pm_bio_fit(array $parts, int $limit, string $sep = ' | '): string
{
    $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    while ($parts && mb_strlen(implode($sep, $parts)) > $limit) {
        if (count($parts) === 1) {
            return pm_ch_cut($parts[0], $limit, '');
        }
        array_pop($parts);
    }
    return implode($sep, $parts);
}

/** One-line bios from the Marketing brain, each within its platform's limit. [key => ['label','limit','text','chars']]. */
function pm_bio_pack(string $brand): array
{
    $brand = pm_ch_brand($brand);
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $br = pm_brain($brand);
        $what = trim((string)($s['tagline'] ?? ''));
        if ($what === '') {
            $what = (pm_ch_sentences((string)$br['about'])[0] ?? '');
        }
        $what = rtrim($what, '. ');
        $who = trim(rtrim((string)(pm_ch_sentences((string)$br['audience'])[0] ?? ''), '. '));
        $who = $who !== '' ? 'For ' . lcfirst($who) : '';
        $wa = trim((string)($s['phone'] ?? ''));
        $cta = function_exists('pm_magnet_keyword_line') ? trim((string)pm_magnet_keyword_line($brand)) : '';
        if ($cta === '') {
            $cta = $brand === 'travel' ? 'Send HOST on WhatsApp to list your stay' : 'Send CHECK on WhatsApp for a free website check';
        }
        $cta = rtrim($cta, '. ');
        $lk = pm_link_cfg($brand);
        $link = $lk['url'] !== '' ? preg_replace('#^https?://#i', '', rtrim($lk['url'], '/')) : '';
        $waPart = $wa !== '' ? 'WhatsApp ' . $wa : '';
        $place = 'Malawi';
        $defs = ['instagram' => ['Instagram bio', 150], 'x' => ['X bio', 160], 'tiktok' => ['TikTok bio', 80], 'linkedin' => ['LinkedIn tagline', 120], 'facebook' => ['Facebook intro', 101], 'whatsapp_business' => ['WhatsApp Business description', 256]];
        $out = [];
        foreach ($defs as $k => [$label, $limit]) {
            $parts = match ($k) {
                'instagram' => [$what, $waPart, $place, $cta, $link],
                'x' => [$what, $who, $place, $waPart, $link],
                'tiktok' => [$what, $waPart],
                'linkedin' => [$what, $who, $place],
                'facebook' => [$what, $place, $waPart],
                default => [$what, $who, $place, $cta, $waPart, $link],
            };
            $t = pm_bio_fit($parts, $limit);
            $out[$k] = ['label' => $label, 'limit' => $limit, 'text' => $t, 'chars' => mb_strlen($t)];
        }
        return $out;
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- pin pick ---------------- */

/** The published evergreen post to pin: best engagement from the scoreboard when measure-steer has one, else the best from stored metrics. null = nothing to pin yet. */
function pm_pin_pick(string $brand): ?array
{
    $brand = pm_ch_brand($brand);
    $posts = [];
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? '') === $brand && ($p['status'] ?? '') === 'published' && ($p['fb_id'] ?? '') !== '' && !pm_pin_seasonal($p)) {
            $posts[$p['id']] = $p;
        }
    }
    if (!$posts) {
        return null;
    }
    if (function_exists('pm_social_scoreboard')) {
        try {
            $sb = (array)pm_social_scoreboard($brand);
            foreach ((array)($sb['top5'] ?? []) as $row) {
                $id = (string)(is_array($row) ? ($row['id'] ?? $row['post_id'] ?? '') : $row);
                if (isset($posts[$id])) {
                    return pm_pin_row($posts[$id], 'Top performer on the scoreboard');
                }
            }
        } catch (Throwable) {
        }
    }
    $best = null;
    $score = -1.0;
    foreach ($posts as $p) {
        $m = (array)($p['metrics']['d7'] ?? $p['metrics']['d3'] ?? $p['metrics']['h24'] ?? []);
        $e = function_exists('pm_social_eng') && $m ? (float)pm_social_eng($m) : (float)($m['eng'] ?? 0);
        $magnet = in_array($p['cta'] ?? '', ['check', 'host', 'whatsapp'], true) ? 1.0 : 0.0;
        $e += $magnet; // a tie goes to the post that asks for an enquiry
        if ($e > $score) {
            $score = $e;
            $best = $p;
        }
    }
    return $best && $score > 0 ? pm_pin_row($best, 'Best engagement so far') : null;
}

function pm_pin_seasonal(array $p): bool
{
    return !empty($p['occasion']) || !empty($p['occasion_date']) || !empty($p['expires']) || !empty($p['giveaway']);
}

function pm_pin_row(array $p, string $why): array
{
    return ['id' => (string)$p['id'], 'headline' => (string)($p['headline'] ?: mb_substr((string)$p['caption'], 0, 60)), 'fb_id' => (string)$p['fb_id'], 'url' => pm_ch_fb_url((string)$p['fb_id']), 'why' => $why];
}

/** Monthly reminder to re-pin the best post. */
function pm_today_pin(string $brand): array
{
    $pick = pm_pin_pick($brand);
    $m = date('Y-m');
    if (!$pick || !empty(pm_channels_cfg($brand)['ticks']['pin_' . $m])) {
        return [];
    }
    return [['text' => 'Pin "' . mb_substr($pick['headline'], 0, 50) . '" to the top of the Page (monthly)', 'href' => '?tab=social&view=channels#profile', 'urgency' => 0]];
}

function pm_do_pin_done(string $vb): array
{
    pm_channels_update($vb, function (array $c) {
        $c['ticks']['pin_' . date('Y-m')] = true;
        return $c;
    });
    return ['msg' => 'Pinned for this month. We will remind you next month.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

/** One honest line about Instagram: what is missing, or that it is automatic. */
function pm_ig_next_step(string $brand): string
{
    $c = pm_social_cfg($brand);
    $on = function_exists('pm_social_settings') ? !empty(pm_social_settings($brand)['channels']['instagram']) : true;
    return match (true) {
        $c['ig_id'] === '' => 'Instagram is not linked yet. Switch the account to Business, link it to the Facebook Page, then add IG_USER_ID to .env (steps in Accounts & branding). Until then Instagram posts are hand-posted from the tasks below.',
        pm_app_url() === '' => 'Instagram is linked, but its posts stay hand-posted until the app is online: Instagram fetches each picture or video from APP_URL/media.php, so set APP_URL in .env to the public address.',
        !$on => 'Instagram is linked but switched off: tick Instagram under "Post automatically to" in Accounts & branding.',
        default => 'Instagram posts automatically (pictures, carousels, stories). Reels go out only while media.php can serve the video from your public address; otherwise they appear as a hand-post task.',
    };
}