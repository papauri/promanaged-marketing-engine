<?php
/**
 * sx_amplify — MARKETING.md cycle 2, the BROADCAST and GROWTH swarms: C2-A05 segment-tagged posts and a per-segment scoreboard,
 * C2-A06 X numbers typed in per post (they score the post when nothing else does), C2-A10 the weekly partnership radar (draft-only DM suggestions).
 * Auto-loaded via lib/social_modules.php (glob). Rule-based where possible; the radar is one search-grounded AI call a week per brand and sends nothing.
 */

/* ---------------- C2-A05 · which audience a post speaks to ---------------- */

/** Words that tie a ProManaged IT post to the same business groups the pipeline uses (pm_lead_group). Strict whole words: no stray matches. */
const PM_SX_SEGMENT_WORDS = [
    'Hotels & lodges' => '/\b(hotels?|lodges?|guest ?houses?|resorts?|b&b|hostels?|front desk|housekeeping|room bookings?)\b/i',
    'Restaurants & bars' => '/\b(restaurants?|caf[eé]s?|bars?|bakery|bakeries|kitchen|menus?|takeaways?|catering)\b/i',
    'Schools & colleges' => '/\b(schools?|colleges?|students?|teachers?|classrooms?|school fees|nurser(?:y|ies))\b/i',
    'Health & pharmacies' => '/\b(clinics?|pharmac(?:y|ies)|patients?|hospitals?|dental|medicines?)\b/i',
    'Fitness & beauty' => '/\b(gyms?|fitness|salons?|spas?|barbers?|beauty|memberships?)\b/i',
    'Shops & supermarkets' => '/\b(shops?|supermarkets?|stock ?takes?|stock|tills?|retail(?:ers)?|stores?|shelves|cashiers?)\b/i',
    'Offices & professional' => '/\b(accountants?|law firms?|lawyers?|consultants?|insurance|offices?|invoices?|clients? files?)\b/i',
    'NGOs & churches' => '/\b(ngos?|charit(?:y|ies)|churches|church|donors?|projects? reports?)\b/i',
    'Transport & logistics' => '/\b(transport|logistics|fleet|couriers?|taxis?|deliver(?:y|ies)|drivers?|trucks?)\b/i',
    'Farming & manufacturing' => '/\b(farms?|farmers?|agri(?:business)?|factory|factories|harvests?|production line)\b/i',
];

/**
 * The segment a post speaks to. Travel Malawi: Hosts or Travellers (from the post's audience or pillar), else General.
 * ProManaged IT: the business group its own words point to the most (hotels, shops, schools...), else "All businesses". Pure and free.
 */
function pm_sx_segment(array $p, string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : (string)($p['brand'] ?? 'promanaged');
    if ($brand === 'travel') {
        $a = function_exists('pm_sx_post_audience') ? pm_sx_post_audience($p) : (string)($p['audience'] ?? '');
        return $a === 'host' ? 'Hosts' : ($a === 'guest' ? 'Travellers' : 'General');
    }
    $text = implode(' ', array_filter([(string)($p['headline'] ?? ''), (string)($p['sub'] ?? ''), (string)($p['caption'] ?? ''), implode(' ', array_map('strval', (array)($p['facts'] ?? [])))]));
    if (pm_brand_is_custom($brand)) { // an added business: its own target types are the segments
        $top = 0;
        $best = 'All customers';
        foreach (pm_agents_config($brand)['sectors'] as $sec) {
            $n = 0;
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string)$sec)) as $w) {
                $n += mb_strlen($w) >= 4 ? preg_match_all('/' . preg_quote(rtrim($w, 's'), '/') . '/iu', $text) : 0;
            }
            if ($n > $top) {
                $top = $n;
                $best = mb_convert_case((string)$sec, MB_CASE_TITLE);
            }
        }
        return $best;
    }
    $best = 'All businesses';
    $top = 0;
    foreach (PM_SX_SEGMENT_WORDS as $seg => $re) {
        $n = preg_match_all($re, $text);
        if ($n > $top) {
            $top = $n;
            $best = $seg;
        }
    }
    return $best;
}

/* ---------------- C3-A08 · plan by the best segment ---------------- */

const PM_SX_SEGMENT_MIN_POSTS = 3;     // scored posts a segment needs before it counts
const PM_SX_SEGMENT_MIN_RATIO = 1.3;   // ... and how far above the average it must be
const PM_SX_SEGMENT_MAX_SHARE = 0.4;   // the most of one plan it may take

/**
 * The audience segment that clearly outperforms: at least 3 scored posts and 1.3 times the average engagement. ['segment','ratio','n','share'] or null.
 * The planner may give its topics up to 40% of a plan (never more), and the "Why this week's mix" card says so.
 */
function pm_sx_segment_boost(string $brand): ?array
{
    if (!function_exists('pm_social_scoreboard')) {
        return null;
    }
    $best = null;
    foreach ((array)(pm_social_scoreboard($brand)['groups']['segment'] ?? []) as $name => $b) {
        if (in_array($name, ['(none)', 'unplanned', 'All businesses', 'General', 'All customers', 'Other'], true) || (int)$b['n'] < PM_SX_SEGMENT_MIN_POSTS
            || $b['vs_avg'] === null || (float)$b['vs_avg'] < PM_SX_SEGMENT_MIN_RATIO) {
            continue;
        }
        if ($best === null || (float)$b['vs_avg'] > $best['ratio']) {
            $best = ['segment' => (string)$name, 'ratio' => (float)$b['vs_avg'], 'n' => (int)$b['n'], 'share' => PM_SX_SEGMENT_MAX_SHARE];
        }
    }
    return $best;
}

/** The segment a content idea (seed) speaks to, by the same words test as a finished post. */
function pm_sx_seed_segment(array $seed, string $brand): string
{
    return pm_sx_segment(['headline' => (string)($seed['topic'] ?? ''), 'caption' => implode(' ', array_map('strval', (array)($seed['facts'] ?? []))), 'brand' => $brand,
        'audience' => (string)($seed['audience'] ?? '')], $brand);
}

/** Job: stamps a missing 'segment' on this brand's older posts (rule-based, no AI). New posts get it when they are planned. */
function pm_job_segment_stamp(string $brand): string
{
    if (!array_filter(pm_sx_posts($brand), fn($p) => empty($p['segment']))) {
        return ''; // nothing to tag: do not rewrite the posts file on every scheduler pass
    }
    $n = 0;
    pm_social_update(function (array $posts) use ($brand, &$n) {
        foreach ($posts as $i => $p) {
            if (($p['brand'] ?? 'promanaged') === $brand && empty($p['segment'])) {
                $posts[$i]['segment'] = pm_sx_segment($p, $brand);
                $n++;
            }
        }
        return $posts;
    });
    return $n ? "$n post(s) tagged with the audience segment they speak to" : '';
}

/* ---------------- C2-A06 · X numbers typed in ---------------- */

/** The numbers typed in for X on a post, as the scoreboard reads them: reactions (likes), comments (replies), shares (reposts), clicks and reach (views). null when none. */
function pm_x_numbers(array $p): ?array
{
    $m = $p['manual']['x'] ?? null;
    if (!is_array($m) || !array_filter(['reactions', 'comments', 'shares', 'clicks', 'views'], fn($k) => isset($m[$k]) && $m[$k] !== '')) {
        return null; // nothing typed in (a typed 0 still counts: it is a real result)
    }
    return ['reactions' => (int)($m['reactions'] ?? 0), 'comments' => (int)($m['comments'] ?? 0), 'shares' => (int)($m['shares'] ?? 0), 'clicks' => (int)($m['clicks'] ?? 0),
        'reach' => isset($m['views']) && $m['views'] !== '' ? (int)$m['views'] : null];
}

/** Owner types X numbers for one published post: likes, reposts, replies, views, link clicks. Stored in post['manual']['x'] with who and when, like every other channel. */
function pm_do_x_results_save(string $vb): array
{
    $to = 'social&view=results#xnumbers';
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $row = [];
    foreach (['likes' => 'reactions', 'replies' => 'comments', 'reposts' => 'shares', 'clicks' => 'clicks', 'views' => 'views'] as $in => $k) {
        $v = trim((string)($_POST[$in] ?? ''));
        if ($v !== '' && ctype_digit($v)) {
            $row[$k] = min(100000000, (int)$v);
            if ($in === 'likes' || $in === 'replies' || $in === 'reposts') {
                $row[$in] = $row[$k]; // kept under X's own names too, for display
            }
        }
    }
    if (!$row) {
        return ['msg' => 'Type at least one number.', 'kind' => 'err', 'to' => $to];
    }
    $row['by'] = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
    $row['at'] = date('Y-m-d H:i');
    $done = pm_social_patch($id, function (array $p) use ($vb, $row) {
        if (($p['brand'] ?? 'promanaged') !== $vb || ($p['status'] ?? '') !== 'published') {
            return $p;
        }
        $p['manual']['x'] = $row;
        if (empty($p['metrics']['d7']) && empty($p['manual']['facebook'])) {
            $p['metrics_src'] = 'manual';
        }
        return $p;
    });
    if (!$done || ($done['manual']['x'] ?? null) !== $row) {
        return ['msg' => 'That post was not found, or it is not published yet.', 'kind' => 'err', 'to' => $to];
    }
    if (function_exists('pm_social_log')) {
        pm_social_log($id, 'results', "x numbers entered by {$row['by']}");
    }
    return ['msg' => 'Saved as entered by ' . $row['by'] . ". It counts toward this post's score and is marked as yours.", 'kind' => 'ok', 'to' => $to];
}

/** What X did, from the numbers typed in: posts with numbers, average engagement, the best three. */
function pm_x_summary(string $brand): array
{
    $rows = [];
    foreach (pm_sx_posts($brand) as $p) {
        if (($p['status'] ?? '') !== 'published' || !($n = pm_x_numbers($p))) {
            continue;
        }
        $rows[] = ['id' => (string)$p['id'], 'headline' => (string)(($p['headline'] ?? '') ?: mb_substr((string)($p['caption'] ?? ''), 0, 50)), 'when' => date('j M', pm_sx_pub_ts($p)),
            'eng' => pm_social_eng($n), 'n' => $n, 'by' => (string)($p['manual']['x']['by'] ?? ''), 'sent' => ($p['x'] ?? '') === 'published'];
    }
    usort($rows, fn($a, $b) => $b['eng'] <=> $a['eng']);
    $avg = $rows ? array_sum(array_column($rows, 'eng')) / count($rows) : 0.0;
    return ['n' => count($rows), 'avg' => round($avg, 1), 'best' => array_slice($rows, 0, 3), 'rows' => $rows];
}

/* ---------------- C2-A10 · partnership radar ---------------- */

/** The stored suggestions for a brand: {day, rows[{name,kind,url,why,dm}]}. Fresh for 8 days. */
function pm_partners(string $brand): array
{
    $d = (array)(pm_load('partners', fn() => [])[$brand] ?? []);
    if (($d['day'] ?? '') === '' || strtotime((string)$d['day']) <= time() - 8 * 86400) {
        return [];
    }
    return array_map(fn($r) => (array)$r + ['id' => substr(sha1(strtolower((string)($r['url'] ?? ''))), 0, 10), 'status' => '', 'status_at' => ''], (array)($d['rows'] ?? [])); // rows made before C3 get an id from their address
}

/** Cleans the model's rows: real https links only, linted DMs (no prices, links, spam wording), bounded text. A row that fails any rule is dropped. */
function pm_partners_clean(array $items): array
{
    $rows = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = trim((string)($it['name'] ?? ''));
        $url = trim((string)($it['url'] ?? ''));
        $dm = trim(preg_replace('/\s+/', ' ', (string)($it['dm'] ?? '')));
        if ($name === '' || $dm === '' || !preg_match('#^https://[^\s<>"\']{6,200}$#i', $url)) {
            continue; // no name, no message, or no link to a real page it found: not usable
        }
        $bad = array_values(array_filter(pm_outreach_lint('Partnership idea', $dm, true), fn($x) => !str_starts_with($x, 'The message is')));
        if ($bad || str_word_count($dm) > 90 || preg_match('#https?://|www\.#i', $dm)) {
            continue;
        }
        $rows[] = ['id' => substr(sha1(strtolower($url)), 0, 10), 'name' => mb_substr($name, 0, 80), 'kind' => mb_substr(trim((string)($it['kind'] ?? '')), 0, 50), 'url' => $url,
            'why' => mb_substr(trim((string)($it['why'] ?? '')), 0, 160), 'dm' => $dm, 'status' => '', 'status_at' => ''];
    }
    return array_slice($rows, 0, 5);
}

/* ---------------- C3-A06 · track partnership outreach, and let the radar learn from it ---------------- */

const PM_PARTNER_STATUSES = ['sent' => 'Sent', 'replied' => 'They replied', 'no' => 'No answer / no'];

/** Past suggestions whose outcome the owner recorded: [{id,name,kind,url,status,status_at}], newest last, 100 at most. */
function pm_partners_history(string $brand): array
{
    return array_values((array)((pm_load('partners', fn() => [])[$brand] ?? [])['history'] ?? []));
}

/** Records Sent / They replied / No on a suggestion (current or already archived). Returns true when found. */
function pm_partner_set_status(string $brand, string $id, string $status): bool
{
    if (!isset(PM_PARTNER_STATUSES[$status]) || !preg_match('/^[a-f0-9]{10}$/', $id)) {
        return false;
    }
    $found = false;
    pm_update('partners', function (array $all) use ($brand, $id, $status, &$found) {
        foreach (['rows', 'history'] as $k) {
            foreach ((array)($all[$brand][$k] ?? []) as $i => $r) {
                if (((($r['id'] ?? '') ?: substr(sha1(strtolower((string)($r['url'] ?? ''))), 0, 10))) === $id) { // rows made before ids existed use their address
                    $all[$brand][$k][$i]['status'] = $status;
                    $all[$brand][$k][$i]['status_at'] = date('Y-m-d H:i');
                    $found = true;
                }
            }
        }
        return $all;
    }, fn() => []);
    return $found;
}

/** The old list's tracked rows join the history; rows nobody acted on are simply dropped. */
function pm_partners_archive(array $entry): array
{
    $hist = (array)($entry['history'] ?? []);
    $have = array_column($hist, 'id');
    foreach ((array)($entry['rows'] ?? []) as $r) {
        if (($r['status'] ?? '') !== '' && !in_array($r['id'] ?? '', $have, true)) {
            $hist[] = array_intersect_key($r, array_flip(['id', 'name', 'kind', 'url', 'status', 'status_at']));
        }
    }
    return array_slice(array_values($hist), -100);
}

/**
 * One line for the radar: which kinds of page answered before and which did not, from what the owner recorded. '' until at least one outcome is known.
 * "Answered" = They replied. "Did not" = sent or marked No, with no reply. Only kinds seen at least once; the counts are shown, nothing is extrapolated.
 */
function pm_partners_learn_line(array $history): string
{
    $kinds = [];
    foreach ($history as $r) {
        $k = mb_strtolower(trim((string)($r['kind'] ?? '')));
        if ($k === '' || !in_array($r['status'] ?? '', ['sent', 'replied', 'no'], true)) {
            continue;
        }
        $kinds[$k][0] = ($kinds[$k][0] ?? 0) + 1;
        $kinds[$k][1] = ($kinds[$k][1] ?? 0) + (($r['status'] ?? '') === 'replied' ? 1 : 0);
    }
    $good = [];
    $bad = [];
    foreach ($kinds as $k => [$n, $rep]) {
        if ($rep > 0) {
            $good[] = "$k ($rep of $n answered)";
        } elseif ($n >= 2) {
            $bad[] = "$k (0 of $n answered)";
        }
    }
    if (!$good && !$bad) {
        return '';
    }
    return 'What happened with earlier suggestions: ' . ($good ? 'these kinds of page answered: ' . implode('; ', $good) . '. ' : '') . ($bad ? 'these did not: ' . implode('; ', $bad) . '. ' : '')
        . 'Prefer the kinds that answered, and do not suggest a kind that did not unless you find a clearly better fit.';
}

function pm_do_partner_status(string $vb): array
{
    $ok = pm_partner_set_status($vb, (string)($_POST['id'] ?? ''), (string)($_POST['status'] ?? ''));
    return ['msg' => $ok ? 'Saved. The radar will learn from it.' : 'That suggestion was not found.', 'kind' => $ok ? 'ok' : 'err', 'to' => 'social'];
}

/**
 * Weekly job (Mondays, or when the list is stale): ONE search-grounded call per brand lists local pages and creators worth collaborating with, each with a
 * link to the page it actually found and a short, honest DM the owner may send by hand. Nothing is sent from here.
 */
function pm_jobg_partners(): string
{
    $made = 0;
    $was = pm_brand();
    foreach (pm_brand_ids() as $b) {
        $d = (array)(pm_load('partners', fn() => [])[$b] ?? []);
        $day = (string)($d['day'] ?? '');
        if ($day === date('Y-m-d') || ((int)date('N') !== 1 && $day !== '' && strtotime($day) > time() - 6 * 86400)) {
            continue;
        }
        pm_brand_set($b);
        $s = pm_settings();
        $who = pm_brand_is_custom($b)
            ? 'Malawian pages, groups, associations and communities whose members or followers include ' . (rtrim(trim((string)(pm_brain($b)['audience'] ?? '')), '.') ?: 'our customers')
            : ($b === 'travel'
            ? 'Malawian travel bloggers, tourism and lake pages, guesthouse and lodge associations, local photographers and community groups that travellers or stay owners follow'
            : 'Malawian business groups, small-business and tech communities, local entrepreneurs and business pages, associations and chambers whose members could use IT help');
        $system = pm_agents_company_brief('tiny') . "\nYou are the Partnership radar for {$s['company_name']}. Web-search for up to 5 real $who that could be good to collaborate with (cross-posts, a guest tip, a shout-out swap). "
            . 'Report ONLY pages you actually found while searching: the exact page name as it appears, and its address (https) from the search. Never invent a page, a name or a follower number. '
            . 'For each, write one short, warm, honest direct message (30 to 70 words) the owner could send by hand: say who we are, why we like their page, and one specific idea. '
            . 'No prices, no links, no flattery, no pressure, no promises. ' . ($learn = pm_partners_learn_line($hist = pm_partners_archive($d))) . ' ' . PM_AGENT_RULES;
        $user = 'Return JSON: {"items":[{"name":"","kind":"kind of page","url":"https://...","why":"one short reason","dm":""}]}';
        try {
            $out = pm_agent_json(pm_claude($system, $user, true, 2200));
        } catch (Throwable) {
            continue;
        }
        $seenIds = array_column($hist, 'id');
        $rows = array_values(array_filter(pm_partners_clean((array)($out['items'] ?? [])), fn($r) => !in_array($r['id'], $seenIds, true))); // a page we already acted on is not suggested again
        if ($rows) {
            pm_update('partners', function (array $all) use ($b, $rows) {
                $all[$b] = ['day' => date('Y-m-d'), 'rows' => $rows, 'history' => pm_partners_archive((array)($all[$b] ?? []))];
                return $all;
            }, fn() => []);
            $made += count($rows);
        }
    }
    pm_brand_set($was);
    return $made ? "$made partnership suggestion(s) ready in Social > Plan (draft messages, nothing sent)" : '';
}

/** A 'today' item so the owner sees fresh suggestions. */
function pm_today_partners(string $brand): array
{
    $rows = pm_partners($brand);
    return $rows ? [['text' => count($rows) . ' partnership idea(s) waiting: review the drafted messages and send the ones you like by hand', 'href' => '?tab=social', 'urgency' => 0]] : [];
}

/** Sent / They replied / No: the buttons under a suggestion, and the status it already has. */
function pm_partner_buttons(string $vb, array $r): string
{
    $csrf = (string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''));
    $cur = (string)($r['status'] ?? '');
    $h = $cur !== '' ? '<span class="pill ' . ($cur === 'replied' ? 'hot' : '') . '">' . pm_h(PM_PARTNER_STATUSES[$cur] ?? $cur) . '</span>' : '';
    foreach (PM_PARTNER_STATUSES as $k => $lab) {
        if ($k === $cur) {
            continue;
        }
        $h .= '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="partner_status">'
            . '<input type="hidden" name="id" value="' . pm_h((string)($r['id'] ?? '')) . '"><input type="hidden" name="status" value="' . pm_h($k) . '"><button class="btn small">' . pm_h($lab) . '</button></form>';
    }
    return $h;
}

/** Plan-screen card: the radar's suggestions with a Copy button on each drafted message. */
function pm_panel_plan_top_partners(string $vb, array $ctx = []): string
{
    $rows = pm_partners($vb);
    if (!$rows) {
        return '';
    }
    $h = '<div class="card sx-card" style="padding:12px 16px"><b style="font-size:13px">Partnership radar</b> <span class="muted" style="font-size:12px">· pages worth working with; drafts only, you send them by hand</span>';
    foreach ($rows as $r) {
        $h .= '<div style="margin-top:10px;font-size:13px"><b>' . pm_h((string)$r['name']) . '</b>' . ($r['kind'] !== '' ? ' <span class="muted">· ' . pm_h((string)$r['kind']) . '</span>' : '')
            . ' <a href="' . pm_h((string)$r['url']) . '" target="_blank" rel="noopener noreferrer">open page</a>'
            . ($r['why'] !== '' ? '<br><span class="muted">' . pm_h((string)$r['why']) . '</span>' : '')
            . '<div class="msgbox">' . pm_h((string)$r['dm']) . '</div><div class="btns"><button type="button" class="btn small" data-copy="' . pm_h((string)$r['dm']) . '">Copy message</button>'
            . pm_partner_buttons($vb, $r) . '</div></div>';
    }
    return $h . '</div>';
}
