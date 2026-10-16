<?php
/**
 * Social scouting: the head of Social and her desk scout the social web for anything we can turn into sales, and a person decides what we act on.
 * Besides the people asking on public posts (the morning listening, lib/sx_listen.php: each one a lead under Leads > People looking), the scouting desk looks for
 * the places where our buyers gather and where a move from us would pay: active groups and communities, pages and creators with an engaged audience, busy threads
 * and upcoming events. Each find comes with why it matters, the move (join and post, reply, partner with the admin, advertise to that audience, a content angle, get
 * listed), the department that would do it, and the words to use. Nothing is posted by itself: the owner decides each one
 *   Hand to the team   the department named gets it on the team board and does its part (the Writers write, the Scouts look through the group for businesses...)
 *   We did it         acted on, with what happened
 *   More like this    the next scouting looks for more of the same
 *   Not for us        dropped, with the reason, and the next scouting avoids it
 * The desk has a goal (finds acted on each week) that counts towards Social's goal for the month, reports on the Overview and asks for a decision when finds wait.
 * Cost: two calls when it scouts (one short web search, one cheap call to sort the finds), at most every other working day by the scheduler, or on "Scout now".
 * Data: data/social_finds.json {brand: {items: {id: find}, at (last scouting), goal (finds acted on a week), likes [..], skips [..], error}}.
 */

const PM_SF_KINDS = ['group' => 'Group or community', 'page' => 'Page or creator', 'thread' => 'Busy conversation', 'event' => 'Event', 'listing' => 'Directory or listing'];
const PM_SF_MOVES = ['join_post' => 'Join and post something useful', 'reply' => 'Reply in the conversation', 'partner' => 'Partner with the admin or owner', 'advertise' => 'Advertise to this audience',
    'content' => 'Use it as a content angle', 'prospect' => 'Find businesses to approach in it', 'list' => 'Get listed there'];
const PM_SF_STATUS = ['new' => 'To decide', 'handed' => 'With the team', 'acted' => 'Done', 'skipped' => 'Not for us'];
const PM_SF_DEPTS = ['social', 'writers', 'scouts', 'replies', 'director'];

function pm_sf_state(string $brand): array
{
    return (array)(pm_load('social_finds', fn() => [])[$brand] ?? []) + ['items' => [], 'at' => '', 'goal' => 3, 'likes' => [], 'skips' => [], 'error' => ''];
}

/** Changes one business's scouting state and saves it. */
function pm_sf_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('social_finds', function (array $all) use ($brand, $fn, &$out) {
        $s = (array)($all[$brand] ?? []) + ['items' => [], 'at' => '', 'goal' => 3, 'likes' => [], 'skips' => [], 'error' => ''];
        $all[$brand] = $out = $fn($s);
        return $all;
    }, fn() => []);
    return $out;
}

/** Who buys from this business, as the people the scouting looks for. */
function pm_sf_buyers(string $brand): string
{
    if ($brand === 'travel') {
        return 'owners and managers of lodges, guest houses, B&Bs, cottages and camps (they list their stay with us, free), and travellers planning a stay (they book those stays)';
    }
    if (pm_brand_is_custom($brand)) {
        return 'people and businesses who need: ' . pm_listen_wants($brand);
    }
    return 'business owners and managers who need ' . pm_listen_wants($brand) . ', and gamers and students who buy computers and gaming gear';
}

/** The searches for places where those people gather: a few towns in turn, and the country. */
function pm_sf_queries(string $brand): array
{
    $towns = array_values(array_filter(array_map('strval', (array)(pm_agents_config($brand)['cities'] ?? []))));
    $home = pm_markets($brand)['home'];
    $t = $towns ? $towns[((int)date('z')) % count($towns)] : $home;
    if ($brand === 'travel') {
        return ["$home lodge owners Facebook group", "$home tourism operators association", "$home travel group Facebook", "Lake $home travellers group", "$t accommodation group", "$home tourism events " . date('Y')];
    }
    if (pm_brand_is_custom($brand)) {
        $w = mb_substr(pm_listen_wants($brand), 0, 60);
        return ["$w $home Facebook group", "$home business owners group", "$t community group", "$home $w forum", "$home events " . date('Y')];
    }
    return ["$home business owners Facebook group", "$t entrepreneurs group", "$home tech community", "$home gamers group", "$home SME association", "$home business expo " . date('Y')];
}

/**
 * The scouting: one short web search (plain text back, so the model really searches), then one cheap call that sorts what was found into finds with a move each.
 * New finds are added with status "new". Returns ['found' => n, 'added' => n, 'error' => ''].
 */
function pm_sf_scout(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $st = pm_sf_state($brand);
        $known = array_values(array_filter(array_map(fn($f) => (string)($f['url'] ?? ''), $st['items'])));
        $areas = implode(', ', array_slice(array_values(array_filter((array)(pm_agents_config($brand)['cities'] ?? []))), 0, 8)) ?: pm_markets($brand)['home'];
        $user = 'Use Google Search for each of these: ' . implode('; ', array_map(fn($q) => '"' . $q . '"', pm_sf_queries($brand))) . '. '
            . 'Then list the places on social media and the web where ' . pm_sf_buyers($brand) . " gather, in or around $areas: Facebook groups and pages, Reddit communities, forums, "
            . 'Instagram, TikTok or YouTube accounts with an engaged audience, WhatsApp or Telegram communities with a public page, associations, and events in the next 3 months. '
            . 'For each: the exact link, the platform, the name, its size (members or followers, only if shown), how active it is (recent posts and comments, only if you saw it), '
            . 'what people there talk about, and whether it allows posts from businesses (only if its rules say so). Only places the searches found; today is ' . date('j F Y') . '.'
            . ($known ? ' Leave out these, already known: ' . implode(' ', array_slice($known, -20)) . '.' : '');
        $found = trim(pm_claude('You search the web and report only what you found, each with its exact link.', $user, true, 1500, 'write'));
        if (!preg_match('#https?://#', $found)) {
            pm_sf_update($brand, fn($s) => ['at' => date('Y-m-d H:i'), 'error' => ''] + $s);
            return ['found' => 0, 'added' => 0, 'error' => ''];
        }
        $company = pm_brand_name($brand);
        $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
        $system = pm_agents_company_brief('tiny') . "\nYou are " . pm_agent_first('social') . ", head of Social for $company, with your scouting desk. From search_found ONLY (never invent a place, a link or a number), "
            . 'pick the places where a move from us can bring sales: where our buyers gather and are active. For each: kind (' . implode('|', array_keys(PM_SF_KINDS)) . '), name, platform, url (exactly as given), '
            . 'audience (size, only if given), activity (how active, only if given), why (one sentence: why it matters for sales, specific to what people there do or ask), '
            . 'move (' . implode('|', array_keys(PM_SF_MOVES)) . '): the best one, respecting the place\'s rules (a group that bans business posts gets join_post with genuinely useful help, never an advert), '
            . 'plan (one sentence: exactly what we do there and what we hope it brings), dept (who does it: ' . implode('|', PM_SF_DEPTS) . '; scouts when the place is full of businesses we could approach, writers for content), '
            . 'url must point at that exact place, never a site\'s home page. '
            . 'text (the words to use for that move, at most 70 words, from ' . $company . ' as a business; for join_post and reply it must be genuinely useful on its own (a tip, an answer, a short guide) and mention us only in one closing line, never as a customer, traveller or friend; helpful first, no pressure, no prices'
            . ($site !== '' ? ", at most one link: $site" : ', no links') . '), fit (0-100: how much it can bring us). '
            . 'Skip anything in skip_like, closed or dead places, and our own pages. ' . (defined('PM_HUMAN_RULES') ? PM_HUMAN_RULES . ' ' : '') . (defined('PM_TRUST_RULES') ? PM_TRUST_RULES . ' ' : '') . PM_AGENT_RULES;
        $payload = json_encode(['today' => date('Y-m-d'), 'our_buyers' => pm_sf_buyers($brand), 'areas' => $areas, 'search_found' => mb_substr($found, 0, 4500),
            'more_like' => array_slice($st['likes'], -6), 'skip_like' => array_slice($st['skips'], -8), 'team_notes' => function_exists('pm_tb_line') ? pm_tb_line($brand, 'social', 4) : ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = pm_agent_json(pm_claude($system, $payload . "\nJSON {\"finds\":[{\"kind\",\"name\",\"platform\",\"url\",\"audience\",\"activity\",\"why\",\"move\",\"plan\",\"dept\",\"text\",\"fit\"}]} at most 8, best first (none is fine).", false, 2200));
        $rows = is_array($out) ? (array)($out['finds'] ?? (array_is_list($out) ? $out : [])) : [];
        $added = 0;
        $names = [];
        $now = date('Y-m-d H:i');
        $host = $site !== '' ? strtolower((string)parse_url($site, PHP_URL_HOST)) : '';
        pm_sf_update($brand, function (array $s) use ($rows, &$added, &$names, $now, $host) {
            foreach (array_slice($rows, 0, 8) as $f) {
                $url = is_array($f) ? pm_listen_real_url(trim((string)($f['url'] ?? ''))) : '';
                $id = substr(md5(strtolower(rtrim($url, '/'))), 0, 10);
                $txt = fn(string $k, int $max) => mb_substr(trim(strip_tags((string)($f[$k] ?? ''))), 0, $max);
                if (!preg_match('#^https?://[^\s/]+\.[^\s]+#i', $url) || trim((string)parse_url($url, PHP_URL_PATH), '/') === '' || isset($s['items'][$id]) || $txt('name', 120) === '' || $txt('why', 300) === ''
                    || ($host !== '' && str_contains(strtolower((string)parse_url($url, PHP_URL_HOST)), $host))) {
                    continue; // no real link, already known, nothing said about it, or our own site
                }
                $kind = isset(PM_SF_KINDS[$f['kind'] ?? '']) ? (string)$f['kind'] : 'group';
                $move = isset(PM_SF_MOVES[$f['move'] ?? '']) ? (string)$f['move'] : 'join_post';
                $dept = in_array($f['dept'] ?? '', PM_SF_DEPTS, true) ? (string)$f['dept'] : 'social';
                $s['items'][$id] = ['id' => $id, 'kind' => $kind, 'name' => $txt('name', 120), 'platform' => $txt('platform', 40) ?: (string)preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST)),
                    'url' => $url, 'audience' => $txt('audience', 80), 'activity' => $txt('activity', 160), 'why' => $txt('why', 300), 'move' => $move, 'plan' => $txt('plan', 300), 'dept' => $dept,
                    'text' => mb_substr(function_exists('pm_human_polish') ? pm_human_polish(trim(strip_tags((string)($f['text'] ?? ''))), 'email') : trim(strip_tags((string)($f['text'] ?? ''))), 0, 700), 'fit' => max(0, min(100, (int)($f['fit'] ?? 50))), 'found' => $now, 'status' => 'new'];
                $added++;
                $names[] = $s['items'][$id]['name'];
            }
            if (count($s['items']) > 300) { // the oldest decided ones go first
                uasort($s['items'], fn($a, $b) => [($a['status'] ?? '') === 'new' ? 1 : 0, (string)($a['found'] ?? '')] <=> [($b['status'] ?? '') === 'new' ? 1 : 0, (string)($b['found'] ?? '')]);
                $s['items'] = array_slice($s['items'], -250, null, true);
            }
            return ['at' => $now, 'error' => ''] + $s;
        });
        $nm = pm_agent_first('social');
        pm_agent_log('Social', $added ? "I scouted social media and found {$added} place" . ($added === 1 ? '' : 's') . ' where we can win business: ' . implode(', ', array_slice($names, 0, 4)) . '. They wait for your decision under Social > Scouting.'
            : 'I scouted social media and found no new place worth our time today.');
        if ($added && function_exists('pm_tb_post')) {
            pm_tb_post($brand, 'social', 'director', "Scouting: {$added} new place" . ($added === 1 ? '' : 's') . ' where our buyers gather (' . implode(', ', array_slice($names, 0, 3)) . '). The owner decides which we act on.', 'sf:found');
        }
        return ['found' => count($rows), 'added' => $added, 'error' => ''];
    } catch (Throwable $e) {
        pm_sf_update($brand, fn($s) => ['error' => mb_substr($e->getMessage(), 0, 160)] + $s);
        return ['found' => 0, 'added' => 0, 'error' => $e->getMessage()];
    } finally {
        pm_brand_set($was);
    }
}

/** The desk's numbers: new (to decide), handed, acted this week, skipped, the goal, the people asking on public posts waiting for a reply, when it last scouted. */
function pm_sf_counts(string $brand): array
{
    $st = pm_sf_state($brand);
    $week = strtotime('-7 days');
    $c = ['new' => 0, 'handed' => 0, 'acted' => 0, 'acted7' => 0, 'skipped' => 0, 'goal' => max(1, (int)$st['goal']), 'at' => (string)$st['at'], 'error' => (string)$st['error']];
    foreach ($st['items'] as $f) {
        $s = (string)($f['status'] ?? 'new');
        $c[$s] = ($c[$s] ?? 0) + 1;
        $c['acted7'] += $s === 'acted' && strtotime((string)($f['decided_at'] ?? '')) > $week ? 1 : 0;
    }
    $c['people'] = count(array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand && !empty($l['listen']) && ($l['status'] ?? '') === 'new'));
    return $c;
}

/** The owner's decision on one find. $do: hand | acted | more | skip | reopen. Returns [ok, message]. */
function pm_sf_decide(string $brand, string $id, string $do, string $note = ''): array
{
    $note = mb_substr(trim(strip_tags($note)), 0, 300);
    $f = pm_sf_state($brand)['items'][$id] ?? null;
    if (!$f) {
        return [false, 'That find is no longer here.'];
    }
    $now = date('Y-m-d H:i');
    $who = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'You';
    $dept = (string)($f['dept'] ?? 'social');
    $short = fn(array $x) => $x['name'] . ' (' . PM_SF_KINDS[$x['kind']] . ', ' . $x['platform'] . ')';
    pm_sf_update($brand, function (array $s) use ($id, $do, $note, $now, $who, $short) {
        $x = &$s['items'][$id];
        if ($do === 'more') {
            $s['likes'][] = $short($x) . ': ' . mb_substr((string)$x['why'], 0, 120);
            $s['likes'] = array_slice(array_values(array_unique($s['likes'])), -12);
            $x['liked'] = $now;
            return $s;
        }
        if ($do === 'skip') {
            $s['skips'][] = $short($x) . ($note !== '' ? ': ' . $note : '');
            $s['skips'] = array_slice(array_values(array_unique($s['skips'])), -20);
        }
        $x['status'] = ['hand' => 'handed', 'acted' => 'acted', 'skip' => 'skipped', 'reopen' => 'new'][$do] ?? $x['status'];
        $x['decided_at'] = $now;
        $x['by'] = $who;
        $note !== '' && $x['note'] = $note;
        unset($x);
        return $s;
    });
    $deptName = function_exists('pm_talk_name') ? pm_talk_name($dept) : $dept;
    if ($do === 'hand' && function_exists('pm_tb_post')) {
        $what = $dept === 'scouts' ? 'look through ' . $f['name'] . ' (' . $f['url'] . ') for businesses we could approach, and add the best as leads'
            : ($dept === 'writers' ? 'write for this angle: ' . $f['plan'] : $f['plan'] . ' (' . $f['url'] . ')');
        pm_tb_post($brand, 'social', $dept, 'The owner says go: ' . $what . ($note !== '' ? ' Note: ' . $note : ''), 'sf:' . $id);
        function_exists('pm_talk') && pm_talk($brand, 'social', 'The owner chose ' . $f['name'] . '; ' . $deptName . ' takes it: ' . $f['plan'], $dept);
    }
    if (in_array($do, ['acted', 'skip'], true) && function_exists('pm_tb_take')) {
        pm_tb_take($brand, 'sf:' . $id);
    }
    if ($do === 'acted' && function_exists('pm_tb_post')) {
        pm_tb_post($brand, 'social', 'director', 'We acted on ' . $f['name'] . ' (' . PM_SF_MOVES[$f['move']] . ')' . ($note !== '' ? ': ' . $note : '') . '.', 'sf:done:' . $id);
    }
    return [true, match ($do) {
        'hand' => $f['name'] . ' is with ' . $deptName . ' now: it is on the team board, and the head reports back on the Overview.',
        'acted' => 'Marked as done' . ($note !== '' ? ', with what happened' : '') . '. It counts towards this week\'s goal.',
        'more' => 'The next scouting looks for more places like ' . $f['name'] . '.',
        'skip' => 'Dropped. The next scouting avoids places like it.',
        default => 'Back on the list to decide.',
    }];
}

/* ---------------- forms ---------------- */

/** Social > Scouting: a decision on a find. */
function pm_do_sf_decide(string $vb): array
{
    [$ok, $msg] = pm_sf_decide($vb, (string)($_POST['id'] ?? ''), (string)($_POST['sf'] ?? ''), (string)($_POST['note'] ?? ''));
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=scout' . (preg_match('/^[a-z]+$/', (string)($_POST['st'] ?? '')) ? '&st=' . $_POST['st'] : '')];
}

/** Social > Scouting: "Scout now". */
function pm_do_sf_scout(string $vb): array
{
    if (!pm_agents_ready()) {
        return ['msg' => 'The AI needs a key in .env first.', 'kind' => 'err', 'to' => 'social&view=scout'];
    }
    @set_time_limit(300);
    $r = pm_sf_scout($vb);
    $pp = pm_sf_posts_now($vb); // and the individual posts: people asking for something we offer
    $places = $r['error'] !== '' ? 'The scouting of places failed: ' . $r['error'] . '.' : ($r['added'] ? $r['added'] . ' new place' . ($r['added'] === 1 ? '' : 's') . ' to decide on.' : 'No new place worth our time.');
    $posts = $pp['error'] !== '' ? ' The scouting of posts failed: ' . $pp['error'] . '.' : ($pp['kept'] ? ' ' . $pp['kept'] . ' ' . ($pp['kept'] === 1 ? 'person asks' : 'people ask') . ' for what we offer on public posts: they are under People looking, each with a reply to paste.' : ' Nobody new asking on public posts.');
    return ['msg' => $places . $posts, 'kind' => $r['error'] !== '' && $pp['error'] !== '' ? 'err' : 'ok', 'to' => 'social&view=scout'];
}

/** The scouting desk's sweep of individual posts, saved: ['kept', 'names', 'error']. */
function pm_sf_posts_now(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $all = pm_leads();
        $r = pm_listen_posts($brand, $all);
        $r['kept'] && pm_leads_save($all);
        return $r;
    } catch (Throwable $e) {
        return ['found' => 0, 'kept' => 0, 'names' => [], 'error' => mb_substr($e->getMessage(), 0, 160)];
    } finally {
        pm_brand_set($was);
    }
}

/** Social > Scouting: the weekly goal (finds acted on). */
function pm_do_sf_goal(string $vb): array
{
    $g = max(1, min(50, (int)($_POST['goal'] ?? 3)));
    pm_sf_update($vb, fn($s) => ['goal' => $g] + $s);
    return ['msg' => "Goal set: act on {$g} find" . ($g === 1 ? '' : 's') . ' a week.', 'kind' => 'ok', 'to' => 'social&view=scout'];
}

/* ---------------- the scheduler ---------------- */

/**
 * Scheduler job: the desk scouts on working days between 8 and 17, at most every other day, and the next day too when fewer than 2 finds wait for a decision.
 * Off without an AI key or with the agents' Scout switched off. Two calls each time.
 */
function pm_job_social_scout(string $brand): string
{
    $now = function_exists('pm_now') ? pm_now() : time();
    if ((int)date('N', $now) > 5 || date('H:i', $now) < '08:00' || date('H:i', $now) > '17:00' || !pm_agents_ready() || empty(pm_agents_config($brand)['enabled']['scout'])) {
        return '';
    }
    $c = pm_sf_counts($brand);
    $last = strtotime($c['at']) ?: 0;
    if ($last > $now - ($c['new'] < 2 ? 20 * 3600 : 44 * 3600)) {
        return '';
    }
    $r = pm_sf_scout($brand);
    return $r['error'] !== '' ? 'scouting failed: ' . $r['error'] : $r['added'] . ' new place' . ($r['added'] === 1 ? '' : 's') . ' found';
}

/**
 * Scheduler job: people asking on posts do not wait, so the desk sweeps the posts every day, more often than it looks for places: at most every 8 hours between 07:00 and 20:00,
 * not within 4 hours of the head of Social's morning listening. Off without an AI key or with the agents' Scout switched off. Two calls each time.
 */
function pm_job_social_scout_posts(string $brand): string
{
    $now = function_exists('pm_now') ? pm_now() : time();
    if (date('H:i', $now) < '07:00' || date('H:i', $now) > '20:00' || !pm_agents_ready() || empty(pm_agents_config($brand)['enabled']['scout'])) {
        return '';
    }
    $st = pm_listen_state($brand);
    $last = strtotime((string)($st['posts']['at'] ?? '')) ?: 0;
    $morning = strtotime((string)($st['day']['at'] ?? '')) ?: 0;
    if ($last > $now - 8 * 3600 || $morning > $now - 4 * 3600) {
        return '';
    }
    $r = pm_sf_posts_now($brand);
    return $r['error'] !== '' ? 'posts scouting failed: ' . $r['error'] : $r['kept'] . ' ' . ($r['kept'] === 1 ? 'person' : 'people') . ' asking on posts';
}

/* ---------------- on other screens ---------------- */

/** Social > Plan, at the top: the scouting desk in one line. */
function pm_panel_plan_top_sfscout(string $vb, array $ctx = []): string
{
    $c = pm_sf_counts($vb);
    if (!$c['new'] && !$c['handed'] && !$c['people'] && $c['at'] === '') {
        return '';
    }
    return '<div class="card"><p class="mt-0"><b>Scouting:</b> ' . ($c['new'] ? '<a href="?tab=social&amp;view=scout">' . $c['new'] . ' place' . ($c['new'] === 1 ? '' : 's') . ' where we can win business wait for your decision</a>. ' : 'Nothing waits for a decision. ')
        . ($c['people'] ? '<a href="?tab=agents&amp;status=people">' . $c['people'] . ' ' . ($c['people'] === 1 ? 'person asks' : 'people ask') . ' for what we offer</a>. ' : '')
        . 'Acted on ' . $c['acted7'] . ' of ' . $c['goal'] . ' this week.</p></div>';
}
