<?php
/**
 * Thandi, head of Social: her morning huddle and the listening desk. Once a day per business: one short web search, then one cheap call for the rest:
 *   huddle     Thandi sits down with the department heads over the Director's orders, the team's notes and the social numbers, and they agree the day's tasks.
 *              Each task goes on the team board to the department that does it; her own tasks and the day's focus steer the social posts.
 *   listening  public posts, group posts, comments and stories of the last weeks where someone in (or coming to) the towns we cover asks for what we offer.
 *              Each one becomes a lead ("People looking") with the post's link, what they asked for in their words and a helpful public reply to paste under it.
 * Only what the post itself says is kept: nobody is looked up. The reply speaks as the business, never as a customer, traveller or friend, and nothing is posted
 * by itself (no app may comment under other people's posts): the owner opens the post, pastes the reply and presses "I replied".
 * People-looking leads are reached on their post, so the outreach pipeline (research, contact finders, writers, default emails, follow-ups) leaves them alone.
 * Data: data/listen.json {brand: {seen: {md5(url): [date, url]}, day: {date, at, huddle[[who,says]], tasks[[to,do]], focus, found, kept, error}}}.
 */

require_once __DIR__ . '/sx_socleads.php'; // the social leads she owns: drafted in her morning call

const PM_LISTEN_WHO = ['social', 'director', 'scouts', 'research', 'writers', 'replies', 'followup'];

/** A lead found on a public post: reached under that post, not by the outreach pipeline. */
function pm_listen_is(array $l): bool
{
    return !empty($l['listen']);
}

function pm_listen_state(string $brand): array
{
    return (array)(pm_load('listen', fn() => [])[$brand] ?? []);
}

/** Thandi has not met the team and listened today yet. */
function pm_listen_due(string $brand): bool
{
    return (string)(pm_listen_state($brand)['day']['date'] ?? '') !== date('Y-m-d');
}

/**
 * The things people ask for that this business answers, as short search words, from the offerings (Sales > Targeting and messages: the one list every prompt reads).
 * "Source: finding, buying and delivering laptops, desktops, gaming PCs and gaming gear (controllers, headsets and accessories)" gives laptops, desktops, gaming PCs, controllers...
 */
function pm_listen_items(string $brand): array
{
    if ($brand === 'travel') { // travellers looking for a stay, and hosts looking for bookings (they list with us)
        return ['a place to stay', 'a lodge', 'a guesthouse', 'a campsite', 'accommodation', 'things to do and tours', 'more bookings for my lodge', 'where to list my guesthouse'];
    }
    $offers = array_values(array_filter(array_map('strval', (array)(pm_agents_config($brand)['offerings'] ?? []))));
    $hash = md5(json_encode($offers));
    $kept = (array)(pm_load('listen_items', fn() => [])[$brand] ?? []);
    if (($kept['hash'] ?? '') === $hash && !empty($kept['items'])) {
        return (array)$kept['items'];
    }
    $out = [];
    if ($offers && function_exists('pm_agents_ready') && pm_agents_ready()) { // once per change of the offerings: the words people really write when they need it
        try {
            $r = pm_agent_json(pm_claude('You know how people in ' . (function_exists('pm_markets') ? pm_markets($brand)['home'] : 'Malawi') . ' ask for things on social media. Reply JSON only.',
                "What we sell:\n- " . implode("\n- ", $offers) . "\n\nList 12 to 20 short things (1 to 4 words each) a person or a small business would write in a public post when they need what we sell, "
                . 'in their own words (for example "gaming laptop", "website designer", "IT support", "PS5 controller"). Concrete things, no job titles of ours, no slogans. JSON {"items":[]}', false, 400));
            foreach ((array)($r['items'] ?? []) as $it) {
                $it = strtolower(trim(strip_tags((string)$it), " .\"'"));
                if (mb_strlen($it) >= 3 && mb_strlen($it) <= 40) {
                    $out[] = $it;
                }
            }
        } catch (Throwable) {
        }
    }
    if (!$out) { // no AI: the offerings' own words, the details in brackets and after "with" left out
        foreach ($offers as $o) {
            $body = str_contains($o, ':') ? substr($o, strpos($o, ':') + 1) : $o;
            $body = (string)preg_replace('/\([^)]*\)|\b(with|including)\b[^,]*/i', '', $body);
            foreach (preg_split('/\s*(?:,|;|\band\b|\bor\b)\s*/i', $body) ?: [] as $p) {
                $p = trim((string)preg_replace('/^(finding|buying|delivering|an?|the|everyday)\s+/i', '', trim($p, " .\t\n")));
                if (mb_strlen($p) >= 4 && str_word_count($p) <= 3) {
                    $out[] = strtolower($p);
                }
            }
        }
        $fallback = true; // not saved: the AI words are tried again next time
    }
    $out = array_slice(array_values(array_unique($out)), 0, 20) ?: ['what we offer'];
    if (empty($fallback)) {
        pm_update('listen_items', function (array $s) use ($brand, $hash, $out) {
            $s[$brand] = ['hash' => $hash, 'items' => $out, 'at' => date('Y-m-d H:i')];
            return $s;
        }, fn() => []);
    }
    return $out;
}

/** What people ask for that this business answers, in a few words. */
function pm_listen_wants(string $brand): string
{
    if ($brand === 'travel') {
        return 'a place to stay (lodge, guesthouse, campsite, chalet, Airbnb), a recommendation of where to stay, or things to do and tours; or, from lodge and guesthouse owners, how to get more bookings or where to list their place';
    }
    return mb_substr(implode(', ', pm_listen_items($brand)), 0, 400);
}

/**
 * The searches for people asking: a few towns in turn and a few of the things we offer in turn, in the words people really use when they ask, so each search
 * covers new ground. $shift moves the turn on (the scouting desk searches posts more than once a day).
 */
function pm_listen_queries(string $brand, array $towns, int $shift = 0): array
{
    $turn = (int)date('z') * 3 + $shift * 2;
    $home = function_exists('pm_markets') ? pm_markets($brand)['home'] : 'Malawi';
    $pick = $towns ? array_values(array_unique(array_map(fn($i) => $towns[($turn + $i) % count($towns)], [0, 1, 2]))) : [$home];
    $items = pm_listen_items($brand);
    $item = fn(int $i) => $items[($turn + $i) % count($items)];
    // individual people's public posts on social media: Facebook posts and public groups first, then Instagram, TikTok and X
    $ask = $brand === 'travel'
        ? ['site:facebook.com "where to stay" %2$s', 'site:facebook.com "recommend" %1$s %2$s', 'site:facebook.com/groups "looking for" %1$s ' . $home]
        : ['site:facebook.com "looking for" %1$s %2$s', 'site:facebook.com "anyone" %1$s %2$s', 'site:facebook.com/groups "where can I" %1$s ' . $home];
    $q = [];
    foreach ($ask as $i => $a) {
        $q[] = sprintf($a, $item($i), $pick[$i % count($pick)]);
    }
    $q[] = 'site:instagram.com ' . $home . ' "looking for" ' . $item(3);
    $q[] = ($turn % 2 ? 'site:tiktok.com ' : 'site:x.com ') . $home . ' ' . $item(4) . ' "anyone"';
    return $q;
}

/**
 * The listening: ONE short web-searched call, plain text back (a long prompt or a demand for JSON makes the model answer without searching).
 * What the posts said, with their links; '' when nothing was found.
 */
function pm_listen_search(string $brand, array $towns, array $skip, int $shift = 0): string
{
    $areas = implode(', ', $towns ?: ['Malawi']);
    $user = 'Use Google Search for each of these searches: ' . implode('; ', array_map(fn($q) => '"' . $q . '"', pm_listen_queries($brand, $towns, $shift))) . '. '
        . 'Then list the public social media posts, group posts and comments (Facebook, Instagram, TikTok, X) from the last 90 days (today is ' . date('j F Y') . ") in which a person in or coming to $areas asks for "
        . pm_listen_wants($brand) . '. For each: the exact link, the date posted, the platform, the town, the name shown on the post (or none), what they asked in their words, '
        . 'and an email or phone only if it is written in the post. Only posts the searches found; no business advertising itself; nothing about the person beyond the post. '
        . ($skip ? 'Leave out these links, already known: ' . implode(' ', $skip) . '. ' : '') . 'Newest first.';
    $txt = trim(pm_claude('You search the web and report only what you found, each with its exact link.', $user, true, 1500, 'write')); // the Flash model searches properly; the Lite one tends to answer without searching
    return preg_match('#https?://#', $txt) ? mb_substr($txt, 0, 4000) : ''; // no link: nothing found
}

/** Thandi's instructions and her brief for today (no web: the search is done): [system, user]. */
function pm_listen_prompt(string $brand, string $found, array $towns, array $drop = [], array $page = [], array $due = [], array $slCounts = []): array
{
    $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
    $company = pm_brand_name($brand);
    $focusKeys = defined('PM_SOCIAL_FOCUS') ? implode('|', array_keys(PM_SOCIAL_FOCUS)) : 'mix';
    $st = pm_listen_state($brand);
    $system = pm_agents_company_brief('tiny') . "\nYou are " . pm_agent_first('social') . ", head of Social: you know what people on social media want, on our own Page and everywhere else.\n"
        . '"people": from search_found only (this morning\'s search of public posts), each person who asks for what we offer, with the exact link given there; never invent one or a detail; '
        . 'skip a business advertising itself, anything older than 90 days or outside our areas, and every link in drop_links (too old or already answered: never talk about those either). Keep only what the post says. For each: who (the name shown, or empty), platform, url, posted (YYYY-MM-DD), town, '
        . 'wants (what they asked for, briefly, in their words), email and phone only if written in the post, '
        . "reply: our public answer under their post, at most 50 words, helpful first and specific to what they asked, from $company (we are a business: never pretend to be a customer, traveller or friend)"
        . ($site !== '' ? ", no link except $site" : ', no links') . ', no pressure, ending with an easy question. '
        . '"our_page" is what people said on our own Facebook Page, Instagram and Messenger and who still waits for an answer: in the huddle say who answers them today (the replies desk), a complaint first, and turn what they keep asking into tasks. '
        . '"huddle": your morning sit-down with the department heads (director, scouts, research, writers, replies, followup). They talk to EACH OTHER about today: '
        . 'the director\'s orders and team notes, the social numbers and the people you found; every line names something concrete (a town, a person found, a number, an order) and nobody repeats the company pitch. '
        . '4 to 6 lines {"who","says"}, each one short first-person sentence, from the facts only; you speak first and last. '
        . '"tasks": up to 4 for today {"to":"social|scouts|research|writers|replies|followup|director","do":"one specific sentence"}; yours (to social) say what today\'s posts and stories must do. '
        . '"social_leads" is where the people who came to us on social media stand, by channel; "social_leads_due" are the ones to answer or follow up today: in the huddle say who takes them (replies answers, followup follows up). '
        . '"followups": for each item of social_leads_due, {"id","text"}: what we send on that channel, at most 45 words, from ' . $company . ' (never as a customer or friend), warm and specific to what they asked and what was said, '
        . 'greeting them by name when given; an answer helps first; a follow-up checks in once without pressure and never repeats our last message; end with an easy question; no prices, no links. '
        . '"scouting" is your scouting desk: places on social media where our buyers gather, those waiting for the owner\'s decision and those the team is acting on: in the huddle the head named for each one says how it is going, and you ask the owner to decide on the best waiting one. '
        . "\"focus\": what today's posts lean on, one of $focusKeys. "
        . (defined('PM_TRUST_RULES') ? PM_TRUST_RULES . ' ' : '') . PM_AGENT_RULES;
    $user = json_encode(['today' => date('Y-m-d'), 'areas' => $towns, 'we_answer' => pm_listen_wants($brand), 'search_found' => $found !== '' ? $found : 'nobody asking today', 'drop_links' => $drop,
            'our_page' => !empty($page['ready']) ? ['waiting_for_an_answer' => (int)$page['waiting_comments'] + (int)$page['waiting_messages'], 'comments_waiting' => (int)$page['waiting_comments'],
                'messages_waiting' => (int)$page['waiting_messages'], 'complaints' => (int)$page['complaints'], 'buyers_added_to_leads' => (int)$page['buyers_added'], 'what_they_said' => $page['lines']] : 'not connected',
            'social_leads' => $slCounts, 'social_leads_due' => $due, 'scouting' => pm_listen_scouting($brand), 'team_notes' => function_exists('pm_tb_line') ? pm_tb_line($brand, 'social', 6) : '', 'social_numbers' => function_exists('pm_director_social') ? pm_director_social($brand) : [],
            'firm' => function_exists('pm_firm_line') ? pm_firm_line($brand) : '', 'yesterday' => ['people_found' => (int)($st['day']['kept'] ?? 0)]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nJSON {\"people\":[{\"who\",\"platform\",\"url\",\"posted\",\"town\",\"wants\",\"email\",\"phone\",\"reply\"}],\"huddle\":[{\"who\",\"says\"}],\"tasks\":[{\"to\",\"do\"}],\"followups\":[{\"id\",\"text\"}],\"focus\":\"\"} with at most 6 people (none is fine).";
    return [$system, $user];
}

/**
 * Thandi's day: the listening (one short web search), then the huddle, the tasks and the people found (one cheap call). New leads go into $all (the whole leads file, not saved here).
 * Returns ['found' => n, 'kept' => n, 'names' => [...], 'tasks' => n, 'error' => ''].
 */
function pm_listen_run(string $brand, array &$all): array
{
    $towns = array_values(array_filter(array_map('strval', (array)(pm_agents_config($brand)['cities'] ?? []))));
    $seen = (array)(pm_listen_state($brand)['seen'] ?? []);
    $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
    try {
        $found = pm_listen_search($brand, $towns, array_slice(array_values(array_filter(array_map(fn($x) => (string)($x[1] ?? ''), $seen))), -15));
    } catch (Throwable $e) { // no search today: the huddle still sets the day's tasks
        $found = '';
        pm_agent_log('Social', 'The search of public posts failed: ' . mb_substr($e->getMessage(), 0, 120), true);
    }
    [$found, $drop, $live] = pm_listen_check_links($found, $seen); // the real address of each link; too old or already known is dropped
    [$fromAcc] = pm_listen_connected($brand, $seen);
    $found = trim($found . "\n\n" . $fromAcc); // our connected Facebook and Instagram: posts that tag us, posts under our hashtags (free)
    // our own Page first: comments and messages read, buyers into the leads, who is waiting for an answer
    $page = pm_listen_page($brand);
    if ($page['buyers_added']) {
        foreach (pm_leads() as $k => $v) { // the buyers were saved to the leads file: keep them when the caller saves $all
            $all[$k] ??= $v;
        }
    }
    $due = pm_sl_due($brand, $all); // the social leads to answer or follow up today: she drafts them in the same call
    $slc = pm_sl_counts($brand, $all);
    $slCounts = array_map(fn($by) => array_filter($by), array_combine(array_map(fn($k) => PM_SL_CHANNELS[$k][0], array_keys($slc['by_channel'])), $slc['by_channel']));
    [$system, $user] = pm_listen_prompt($brand, $found, $towns, array_slice($drop, 0, 12), $page, $due, $slCounts);
    $out = pm_agent_json($GLOBALS['PM_LISTEN_RAW'] = pm_claude($system, $user, false, 2200));
    if (!is_array($out)) {
        throw new RuntimeException('The head of Social\'s answer could not be read');
    }

    // the huddle, as the team said it (into the agents' conversation)
    $huddle = [];
    foreach (array_values(array_slice((array)($out['huddle'] ?? []), 0, 6)) as $i => $ln) {
        $ln = is_string($ln) ? ['who' => $i === 0 ? 'social' : 'director', 'says' => $ln] : (array)$ln;
        $who = pm_talk_dept((string)($ln['who'] ?? ''));
        $says = mb_substr(trim(strip_tags((string)($ln['says'] ?? ''))), 0, 240);
        if ($says !== '' && in_array($who, PM_LISTEN_WHO, true)) {
            $huddle[] = [$who, $says];
            function_exists('pm_talk') && pm_talk($brand, $who, $says);
        }
    }
    // the day's tasks: each one on the team board to the department that does it
    $tasks = [];
    foreach (array_slice((array)($out['tasks'] ?? []), 0, 4) as $t) {
        $to = pm_talk_dept((string)($t['to'] ?? ''));
        $do = mb_substr(trim(strip_tags((string)($t['do'] ?? ''))), 0, 220);
        if ($do !== '' && in_array($to, PM_LISTEN_WHO, true)) {
            $tasks[] = [$to, $do];
            function_exists('pm_tb_post') && pm_tb_post($brand, 'social', $to, 'Today: ' . $do, 'thandi:' . $to . ':' . count($tasks));
        }
    }
    $drafted = $due ? pm_sl_keep_drafts($brand, $all, (array)($out['followups'] ?? []), $due) : 0;
    $focus = (string)($out['focus'] ?? '');
    $focus = defined('PM_SOCIAL_FOCUS') && isset(PM_SOCIAL_FOCUS[$focus]) ? $focus : '';

    $now = date('Y-m-d H:i');
    [$nFound, $names, $wantsSeen] = pm_listen_keep_people($brand, (array)($out['people'] ?? []), $all, $seen, $towns, $site); // checked by code, then each one a lead
    $kept = count($names);
    if (count($seen) > 600) {
        uasort($seen, fn($a, $b) => strcmp((string)($a[0] ?? ''), (string)($b[0] ?? '')));
        $seen = array_slice($seen, -500, null, true);
    }
    $day = ['date' => date('Y-m-d'), 'at' => $now, 'huddle' => $huddle, 'tasks' => $tasks, 'focus' => $focus, 'found' => $nFound, 'kept' => $kept, 'names' => $names, 'error' => '',
        'page' => ['ready' => $page['ready'], 'comments' => $page['waiting_comments'], 'messages' => $page['waiting_messages'], 'complaints' => $page['complaints'], 'buyers' => $page['buyers_added']]];
    pm_update('listen', function (array $s) use ($brand, $seen, $day) {
        $s[$brand] = ['seen' => $seen + (array)($s[$brand]['seen'] ?? []), 'day' => $day] + (array)($s[$brand] ?? []); // the posts sweep's own record stays
        return $s;
    }, fn() => []);

    // what Thandi tells the others: the Director hears it at tomorrow's meeting, the Writers what people ask for
    pm_agent_log('Social', $kept ? 'I searched the public posts in ' . count($towns) . ' towns and found ' . $kept . ' ' . ($kept === 1 ? 'person' : 'people') . ' asking for what we offer: ' . implode(', ', $names) . '. They are under Leads > People looking, each with a reply ready to paste under their post.'
        : 'I searched the public posts in ' . count($towns) . ' towns and found nobody new asking for what we offer today.');
    $waiting = $page['waiting_comments'] + $page['waiting_messages'];
    if ($page['ready']) {
        pm_agent_log('Social', 'I read our Page: ' . $page['waiting_comments'] . ' comment' . ($page['waiting_comments'] === 1 ? '' : 's') . ' and ' . $page['waiting_messages'] . ' message' . ($page['waiting_messages'] === 1 ? '' : 's')
            . ' waiting for an answer' . ($page['complaints'] ? ' (' . $page['complaints'] . ' unhappy)' : '') . ($page['buyers_added'] ? ', and ' . $page['buyers_added'] . ' buyer' . ($page['buyers_added'] === 1 ? '' : 's') . ' added to Leads' : '') . '.');
        if ($waiting && function_exists('pm_tb_post')) {
            pm_tb_post($brand, 'social', 'replies', 'On our Page ' . $waiting . ' ' . ($waiting === 1 ? 'person is' : 'people are') . ' waiting for an answer' . ($page['complaints'] ? ', ' . $page['complaints'] . ' of them unhappy: answer those first' : '') . ': ' . implode(' | ', array_slice($page['lines'], 0, 2)), 'thandi:page');
        }
    }
    if (function_exists('pm_tb_post')) { // the social leads: Replies answers, Follow-up follows up, each told what waits and where
        $slc['answer'] ? pm_tb_post($brand, 'social', 'replies', $slc['answer'] . ' ' . ($slc['answer'] === 1 ? 'person' : 'people') . ' from social media wait for an answer; the drafts are on Social > Social leads.', 'thandi:slanswer') : pm_tb_take($brand, 'thandi:slanswer');
        $slc['followup'] ? pm_tb_post($brand, 'social', 'followup', $slc['followup'] . ' social follow-up' . ($slc['followup'] === 1 ? ' is' : 's are') . ' due on ' . implode(', ', array_map(fn($k) => PM_SL_CHANNELS[$k][0], array_keys(array_filter($slc['by_channel'], fn($b) => !empty($b['followup']))))) . '; the drafts are on Social > Social leads.', 'thandi:slfu') : pm_tb_take($brand, 'thandi:slfu');
    }
    ($slc['answer'] || $slc['followup']) && pm_agent_log('Social', 'Social leads: ' . $slc['answer'] . ' to answer and ' . $slc['followup'] . ' to follow up' . ($drafted ? '; I drafted ' . $drafted . ' of them' : '') . '.');
    if ($kept && function_exists('pm_tb_post')) {
        pm_tb_post($brand, 'social', 'director', 'I found ' . $kept . ' ' . ($kept === 1 ? 'person' : 'people') . ' asking on social media: ' . implode('; ', array_slice($wantsSeen, 0, 3)), 'thandi:found');
        pm_tb_post($brand, 'social', 'writers', 'People are asking on social media for: ' . implode('; ', array_slice($wantsSeen, 0, 3)) . '. Use what they want as the angle.', 'thandi:asks');
    }
    return ['found' => $nFound, 'kept' => $kept, 'names' => $names, 'tasks' => count($tasks), 'drafted' => $drafted, 'links' => $live, 'dropped' => count($drop), 'page' => $page, 'error' => ''];
}

/** The hashtags the desk reads on Instagram: the country, the main towns and the business's own words (at most 8, so the 30-a-week limit of Instagram is never reached). */
function pm_listen_hashtags(string $brand): array
{
    $home = function_exists('pm_markets') ? pm_markets($brand)['home'] : 'Malawi';
    $towns = array_slice(array_values(array_filter(array_map('strval', (array)(pm_agents_config($brand)['cities'] ?? [])))), 0, 3);
    $own = $brand === 'travel' ? [$home . 'travel', 'visit' . $home] : (pm_brand_is_custom($brand) ? [$home . 'business'] : [$home . 'gaming', $home . 'business']);
    $tags = array_map(fn($t) => strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $t)), array_merge([$home], $towns, $own));
    return array_slice(array_values(array_unique(array_filter($tags))), 0, 8);
}

/**
 * The connected social accounts, read for individual posts: on Facebook the posts in which people tag or mention our Page (comments and messages on our own
 * posts are judged by the Inbox already), on Instagram the newest public posts under our hashtags (two a time, in turn) and the posts that tag us. Free, no AI,
 * each post with its real date and link. Returns [lines for the team to read ('' when none), what each account gave: ['facebook' => text, 'instagram' => text]].
 * $GLOBALS['PM_GRAPH_STUB'] (tests) replaces the network: fn(path, params) => [ok, data].
 */
function pm_listen_connected(string $brand, array $seen, int $shift = 0): array
{
    $c = function_exists('pm_social_cfg') ? pm_social_cfg($brand) : ['ready' => false, 'ig_id' => '', 'page_id' => '', 'token' => ''];
    $get = fn(string $path, array $params) => isset($GLOBALS['PM_GRAPH_STUB']) && is_callable($GLOBALS['PM_GRAPH_STUB']) ? $GLOBALS['PM_GRAPH_STUB']($path, $params) : pm_graph('GET', $path, $params, (string)$c['token']);
    $lines = [];
    $src = ['facebook' => 'not connected', 'instagram' => 'not linked'];
    $have = [];
    $add = function (string $where, string $at, string $text, string $link) use (&$lines, &$have, $seen) {
        $t = strtotime($at) ?: 0;
        if (isset($have[$link])) {
            return false; // the same post under two of our hashtags
        }
        $have[$link] = true;
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($link === '' || $text === '' || ($t && $t < strtotime('-45 days')) || isset($seen[md5(strtolower(rtrim($link, '/')))])) {
            return false;
        }
        $lines[] = $where . ($t ? ', posted ' . date('Y-m-d', $t) : '') . ': "' . mb_substr($text, 0, 500) . '" ' . $link;
        return true;
    };
    if (!empty($c['ready'])) {
        [$ok, $d] = $get((string)$c['page_id'] . '/tagged', ['limit' => 25, 'fields' => 'message,created_time,permalink_url,from']);
        $n = 0;
        foreach ($ok ? (array)($d['data'] ?? []) : [] as $p) {
            $n += $add('Facebook, a post that tags our Page' . (!empty($p['from']['name']) ? ' (by ' . $p['from']['name'] . ')' : ''), (string)($p['created_time'] ?? ''), (string)($p['message'] ?? ''), (string)($p['permalink_url'] ?? '')) ? 1 : 0;
        }
        $src['facebook'] = $ok ? 'read: ' . $n . ' new post' . ($n === 1 ? '' : 's') . ' tagging the Page' : 'Facebook refused: ' . mb_substr((string)($d['error']['message'] ?? 'no access'), 0, 100);
    }
    if (!empty($c['ready']) && (string)$c['ig_id'] !== '') {
        $tags = pm_listen_hashtags($brand);
        $pick = $tags ? array_values(array_unique([$tags[($shift * 2) % count($tags)], $tags[($shift * 2 + 1) % count($tags)]])) : [];
        $n = 0;
        $err = '';
        foreach ($pick as $tag) {
            [$ok, $d] = $get('ig_hashtag_search', ['user_id' => $c['ig_id'], 'q' => $tag]);
            $hid = $ok ? (string)($d['data'][0]['id'] ?? '') : '';
            if ($hid === '') {
                $err = $err ?: (string)($d['error']['message'] ?? 'hashtag search is not allowed for this account');
                continue;
            }
            [$ok, $d] = $get($hid . '/recent_media', ['user_id' => $c['ig_id'], 'fields' => 'caption,permalink,timestamp', 'limit' => 40]);
            foreach ($ok ? (array)($d['data'] ?? []) : [] as $p) {
                $n += $add('Instagram #' . $tag, (string)($p['timestamp'] ?? ''), (string)($p['caption'] ?? ''), (string)($p['permalink'] ?? '')) ? 1 : 0;
            }
        }
        [$ok, $d] = $get((string)$c['ig_id'] . '/tags', ['fields' => 'caption,permalink,timestamp,username', 'limit' => 25]);
        foreach ($ok ? (array)($d['data'] ?? []) : [] as $p) {
            $n += $add('Instagram, a post that tags us' . (!empty($p['username']) ? ' (@' . $p['username'] . ')' : ''), (string)($p['timestamp'] ?? ''), (string)($p['caption'] ?? ''), (string)($p['permalink'] ?? '')) ? 1 : 0;
        }
        $src['instagram'] = $pick ? 'read #' . implode(', #', $pick) . ': ' . $n . ' new post' . ($n === 1 ? '' : 's') . ($err !== '' ? ' (hashtags: ' . mb_substr($err, 0, 80) . ')' : '') : 'no hashtags';
    }
    return [$lines ? "Posts from our connected social accounts (real dates):\n" . implode("\n", array_slice($lines, 0, 60)) : '', $src];
}

/** The links a search found, checked by code before anyone talks about them: [found text with real addresses, dropped links (too old or known), live links]. */
function pm_listen_check_links(string $found, array $seen): array
{
    $drop = [];
    $live = 0;
    if ($found !== '' && preg_match_all('#https?://[^\s)\]>"]+#', $found, $lm)) {
        foreach (array_unique($lm[0]) as $u0) {
            $u = pm_listen_real_url($u0);
            $u !== $u0 && $found = str_replace($u0, $u, $found);
            $pd = pm_listen_post_date($u);
            if (($pd && $pd < strtotime('-100 days')) || isset($seen[md5(strtolower(rtrim($u, '/')))])) {
                $drop[] = $u;
            } else {
                $live++;
            }
        }
        $live || $found = ''; // every link was old or known: nobody new asking
    }
    return [$found, $drop, $live];
}

/**
 * The people the team found asking on posts, checked by code, each one a lead ("People looking") in $all; their links go into $seen.
 * Returns [how many were offered, the names kept, what they asked for].
 */
function pm_listen_keep_people(string $brand, array $people, array &$all, array &$seen, array $towns, string $site): array
{
    $nFound = 0;
    $names = [];
    $wantsSeen = [];
    $now = date('Y-m-d H:i');
    foreach (array_slice($people, 0, 8) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $nFound++;
        $url = pm_listen_real_url(trim((string)($p['url'] ?? '')));
        $h = md5(strtolower(rtrim($url, '/')));
        $what = mb_substr(trim(strip_tags((string)($p['wants'] ?? ''))), 0, 220);
        $reply = trim(strip_tags((string)($p['reply'] ?? '')));
        $posted = pm_listen_post_date($url) ?: (strtotime((string)($p['posted'] ?? '')) ?: 0); // a Reddit link carries its own date: the model's date is often a year out
        $townIn = trim((string)($p['town'] ?? ''));
        $town = '';
        foreach ($towns as $t) {
            if ($townIn !== '' && (stripos($townIn, $t) !== false || stripos($t, $townIn) !== false)) {
                $town = $t;
                break;
            }
        }
        $home = function_exists('pm_markets') ? pm_markets($brand)['home'] : 'Malawi';
        if (!preg_match('#^https?://[^\s/]+\.[^\s]+#i', $url) || isset($seen[$h]) || $what === '' || $reply === ''
            || ($site !== '' && stripos($url, (string)parse_url($site, PHP_URL_HOST) ?: '#none#') !== false)
            || ($posted && $posted < strtotime('-100 days')) || ($town === '' && stripos($townIn . ' ' . $what, $home) === false && stripos($townIn . ' ' . $what, 'malawi') === false)) {
            continue; // no real link, already seen, nothing asked, our own page, too old, or outside where we work
        }
        $seen[$h] = [date('Y-m-d'), $url];
        $who = mb_substr(trim(strip_tags((string)($p['who'] ?? ''))), 0, 60);
        $plat = mb_substr(trim(strip_tags((string)($p['platform'] ?? ''))), 0, 30) ?: (string)preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
        $name = $who !== '' ? $who : 'Someone ' . ($town !== '' ? 'in ' . $town : 'on ' . $plat);
        $id = pm_lead_id($name . substr($h, 0, 6), $town, $brand);
        if (isset($all[$id])) {
            continue;
        }
        $email = filter_var(trim((string)($p['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
        $all[$id] = [
            'id' => $id, 'brand' => $brand, 'name' => $name, 'type' => 'Person looking', 'city' => $town ?: $home, 'address' => '', 'website' => '',
            'phone' => mb_substr(trim((string)($p['phone'] ?? '')), 0, 30), 'email' => $email, 'whatsapp' => '', 'contact' => $who, 'contact_title' => '',
            'evidence' => [$what . ' ' . $url], 'facebook' => '', 'instagram' => '', 'social_gaps' => [], 'need_signals' => [$what], 'offering' => '',
            'reason' => 'Asked on ' . $plat . ($posted ? ' on ' . date('j M', $posted) : '') . ': ' . $what, 'score' => 80, 'status' => 'new',
            'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0, 'created' => $now, 'updated' => $now, 'src' => ['kind' => 'listen'], 'folders' => ['People looking'],
            'listen' => ['url' => $url, 'platform' => $plat, 'posted' => $posted ? date('Y-m-d', $posted) : '', 'wants' => $what, 'reply' => mb_substr($reply, 0, 600), 'found' => $now],
        ];
        $names[] = $name . ($town !== '' && $who !== '' ? ' (' . $town . ')' : '');
        $wantsSeen[] = $what;
    }
    if (count($seen) > 600) {
        uasort($seen, fn($a, $b) => strcmp((string)($a[0] ?? ''), (string)($b[0] ?? '')));
        $seen = array_slice($seen, -500, null, true);
    }
    return [$nFound, $names, $wantsSeen];
}

/**
 * The scouting desk's sweep of individual posts: people asking, on public posts, in groups and forums, for something we offer. One short web search (a new turn of
 * towns and of the things we offer each time), then one cheap call that keeps only real people asking, each with a reply to paste under the post. No huddle.
 * New leads go into $all (not saved here). Returns ['found', 'kept', 'names', 'error'].
 */
function pm_listen_posts(string $brand, array &$all): array
{
    $towns = array_values(array_filter(array_map('strval', (array)(pm_agents_config($brand)['cities'] ?? []))));
    $st = pm_listen_state($brand);
    $seen = (array)($st['seen'] ?? []);
    $shift = (int)($st['posts']['n'] ?? 0) + 1; // each sweep searches the next towns and things
    $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
    $company = pm_brand_name($brand);
    $res = ['found' => 0, 'kept' => 0, 'names' => [], 'error' => ''];
    try {
        try {
            $found = pm_listen_search($brand, $towns, array_slice(array_values(array_filter(array_map(fn($x) => (string)($x[1] ?? ''), $seen))), -15), $shift);
        } catch (Throwable $e) { // the community feed below can still find people
            $found = '';
            pm_agent_log('Social', 'The web search of public posts failed: ' . mb_substr($e->getMessage(), 0, 120), true);
        }
        [$found] = pm_listen_check_links($found, $seen);
        [$fromAcc, $res['sources']] = pm_listen_connected($brand, $seen, $shift);
        $found = trim($found . "\n\n" . $fromAcc); // our connected Facebook and Instagram: posts that tag us, posts under our hashtags (free)
        if ($found !== '') {
            $system = pm_agents_company_brief('tiny') . "\nYou are " . pm_agent_first('social') . ", head of Social for $company. From search_found ONLY (never invent a person, a link or a detail), "
                . 'go through EVERY post in search_found one by one and keep each one where a real person or small business asks for, wants to buy, or is trying to find something we offer (we_answer; a close match counts: "a laptop for gaming" is a gaming laptop, "somewhere to sleep near the lake" is a place to stay), with the exact link given there. Skip a business advertising itself, a post that only mentions the topic without asking, '
                . 'anything older than 90 days or outside our areas. Keep only what the post says. For each: who (the name shown, or empty), platform, url, posted (YYYY-MM-DD), town, '
                . 'wants (what they asked for, briefly, in their words), email and phone only if written in the post, '
                . "reply: our public answer under their post, at most 50 words, helpful first and specific to what they asked, from $company (we are a business: never pretend to be a customer, traveller or friend)"
                . ($site !== '' ? ", no link except $site" : ', no links') . ', no pressure, ending with an easy question. ' . (defined('PM_HUMAN_RULES') ? PM_HUMAN_RULES . ' ' : '') . PM_AGENT_RULES;
            $user = json_encode(['today' => date('Y-m-d'), 'areas' => $towns, 'we_answer' => pm_listen_wants($brand), 'search_found' => $found], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nJSON {\"people\":[{\"who\",\"platform\",\"url\",\"posted\",\"town\",\"wants\",\"email\",\"phone\",\"reply\"}]} at most 8 (none is fine).";
            $out = pm_agent_json($GLOBALS['PM_LISTEN_RAW'] = pm_claude($system, $user, false, 1800, 'write')); // a missed person is a missed sale: the middle model reads the posts
            [$res['found'], $res['names']] = pm_listen_keep_people($brand, is_array($out) ? (array)($out['people'] ?? (array_is_list($out) ? $out : [])) : [], $all, $seen, $towns, $site); // models often answer with the list alone
            $res['kept'] = count($res['names']);
        }
    } catch (Throwable $e) {
        $res['error'] = mb_substr($e->getMessage(), 0, 160);
    }
    $sweep = ['at' => date('Y-m-d H:i'), 'n' => $shift, 'found' => $res['found'], 'kept' => $res['kept'], 'names' => $res['names'], 'error' => $res['error'], 'sources' => (array)($res['sources'] ?? [])];
    pm_update('listen', function (array $s) use ($brand, $seen, $sweep) {
        $s[$brand] = ['seen' => $seen + (array)($s[$brand]['seen'] ?? []), 'posts' => $sweep] + (array)($s[$brand] ?? []);
        return $s;
    }, fn() => []);
    if ($res['error'] === '') {
        pm_agent_log('Social', $res['kept'] ? 'I scouted public posts and found ' . $res['kept'] . ' ' . ($res['kept'] === 1 ? 'person' : 'people') . ' asking for what we offer: ' . implode(', ', $res['names']) . '. They are under Leads > People looking, each with a reply to paste under their post.'
            : 'I scouted public posts for people asking for what we offer and found nobody new this time.');
        $res['kept'] && function_exists('pm_tb_post') && pm_tb_post($brand, 'social', 'replies', $res['kept'] . ' ' . ($res['kept'] === 1 ? 'person asks' : 'people ask') . ' on public posts for what we offer; the replies to paste are on each lead under People looking.', 'sf:posts');
    } else {
        pm_agent_log('Social', 'The scouting of public posts failed: ' . $res['error'], true);
    }
    return $res;
}

/**
 * Our own Page, read each morning by the head of Social: new comments are judged as the Inbox judges them (code first, the cheap model only for what code cannot tell),
 * buyers become leads, and who still waits for an answer (comments and Messenger) goes into her huddle. Free when nothing is new.
 * ['ready', 'waiting_comments', 'waiting_messages', 'complaints', 'buyers_added', 'lines' => ['Comment from Anna (buyer): ...'], 'error'].
 */
function pm_listen_page(string $brand): array
{
    $o = ['ready' => false, 'waiting_comments' => 0, 'waiting_messages' => 0, 'complaints' => 0, 'buyers_added' => 0, 'lines' => [], 'error' => ''];
    if (!function_exists('pm_fb_capture_buyers') && is_file(__DIR__ . '/social_modules.php')) {
        require_once __DIR__ . '/social_modules.php'; // the daily run does not load the social screens
    }
    if (!function_exists('pm_social_cfg') || !pm_social_cfg($brand)['ready']) {
        return $o;
    }
    $o['ready'] = true;
    $first = fn($n) => (string)(strtok(trim((string)$n), ' ') ?: 'Someone');
    try {
        function_exists('pm_agent_judge_safe') && pm_agent_judge_safe($brand);
        $o['buyers_added'] = function_exists('pm_fb_capture_buyers') ? (int)pm_fb_capture_buyers($brand) : 0;
    } catch (Throwable $e) {
        $o['error'] = mb_substr($e->getMessage(), 0, 120);
    }
    $cut = time() - 7 * 86400;
    foreach ((array)(pm_load('fb_judged', fn() => [])[$brand] ?? []) as $k) {
        if (!in_array($k['verdict'] ?? '', ['buyer', 'question', 'complaint'], true) || ($k['state'] ?? '') !== '' || !empty($k['answered']) || !empty($k['private_reply_at'])
            || (strtotime((string)($k['at'] ?? '')) ?: 0) < $cut) {
            continue;
        }
        $o['waiting_comments']++;
        $o['complaints'] += $k['verdict'] === 'complaint' ? 1 : 0;
        if (count($o['lines']) < 4) {
            $o['lines'][] = 'Comment from ' . $first($k['from'] ?? '') . ' (' . $k['verdict'] . '): ' . mb_substr(trim((string)($k['text'] ?? '')), 0, 90);
        }
    }
    try {
        foreach (function_exists('pm_fb_inbox') ? (array)pm_fb_inbox($brand)['threads'] : [] as $t) {
            if (empty($t['waiting'])) {
                continue;
            }
            $o['waiting_messages']++;
            $lastIn = '';
            foreach ((array)$t['messages'] as $m) {
                $lastIn = empty($m['ours']) ? (string)$m['text'] : $lastIn;
            }
            if (count($o['lines']) < 6) {
                $o['lines'][] = 'Message from ' . $first($t['who'] ?? '') . ': ' . mb_substr(trim($lastIn), 0, 90);
            }
        }
    } catch (Throwable $e) {
        $o['error'] = $o['error'] ?: mb_substr($e->getMessage(), 0, 120);
    }
    return $o;
}

/** A search result's redirect link (vertexaisearch...) as the real address of the post, free; the link itself when it is not one or cannot be followed. */
function pm_listen_real_url(string $url): string
{
    if (!preg_match('#^https://vertexaisearch\.cloud\.google\.com/grounding-api-redirect/#', $url) || !function_exists('curl_init')) {
        return $url;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
    curl_exec($ch);
    $to = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return preg_match('#^https?://#', $to) ? $to : $url;
}

/** When a post was made, from its link, where the link tells (a Reddit post id grows about 1.4 million a day from 1hy5q7m on 11 January 2025); 0 when it does not. */
function pm_listen_post_date(string $url): int
{
    if (!preg_match('#reddit\.com/r/[^/]+/comments/([a-z0-9]{6,8})#i', $url, $m)) {
        return 0;
    }
    $days = ((int)base_convert(strtolower($m[1]), 36, 10) - 3262081522) / 1438000;
    return (int)(strtotime('2025-01-11') + $days * 86400);
}

/** "Pemba (Sender)", "Researcher", "follow-up" ... as a department key. */
function pm_talk_dept(string $who): string
{
    $w = strtolower(trim($who));
    $w = (string)preg_replace('/^.*\((\w[\w -]*)\)$/', '$1', $w);
    $names = ['researcher' => 'research', 'researchers' => 'research', 'writer' => 'writers', 'follow-up' => 'followup', 'scout' => 'scouts', 'reply' => 'replies'];
    foreach (array_keys(PM_AGENT_ROLES) as $d) { // "Kwame", "Nia": the agents' own names, as the owner named them and as they were
        $names[strtolower(pm_agent_first($d))] ??= $d;
        $names[strtolower(PM_AGENT_FIRST[$d])] ??= $d;
    }
    return $names[$w] ?? $w;
}

/** The scouting desk for the huddle: the places waiting for the owner and those with the team (names, moves and who does them), and this week's count against the goal. */
function pm_listen_scouting(string $brand): array
{
    if (!function_exists('pm_sf_state')) {
        return [];
    }
    $c = pm_sf_counts($brand);
    $row = fn(array $f) => $f['name'] . ' (' . $f['platform'] . '): ' . (PM_SF_MOVES[$f['move']] ?? $f['move']) . ', by ' . $f['dept'];
    $items = pm_sf_state($brand)['items'];
    return ['to_decide' => array_values(array_map($row, array_slice(array_filter($items, fn($f) => $f['status'] === 'new'), 0, 3))),
        'with_the_team' => array_values(array_map($row, array_slice(array_filter($items, fn($f) => $f['status'] === 'handed'), 0, 4))),
        'acted_this_week' => $c['acted7'] . ' of ' . $c['goal']];
}

/** Today's focus for the social posts, when Thandi set one this morning; '' otherwise. */
function pm_listen_focus(string $brand): string
{
    $d = (array)(pm_listen_state($brand)['day'] ?? []);
    return ($d['date'] ?? '') === date('Y-m-d') ? (string)($d['focus'] ?? '') : '';
}

/* ---------------- the screens ---------------- */

/** Social > Plan, at the top: Thandi's day (the huddle, the tasks, the people found) and "Listen now". */
function pm_panel_plan_top_listen(string $vb, array $ctx = []): string
{
    $d = (array)(pm_listen_state($vb)['day'] ?? []);
    $open = count(array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $vb && pm_listen_is($l) && ($l['status'] ?? '') === 'new'));
    $nm = pm_agent_first('social');
    $pg = (array)($d['page'] ?? []);
    $h = '<div class="card" id="thandi"><h2>' . pm_h($nm) . '\'s day <span class="muted t-sm">· head of Social' . (!empty($d['at']) ? ' · ' . pm_h(date('j M, H:i', strtotime((string)$d['at']))) : '') . '</span></h2>'
        . '<p class="hint mt-0">Every morning ' . pm_h($nm) . ' reads our Page\'s comments and messages, searches public posts in the towns we cover for people asking for what we offer, meets the department heads and sets the day\'s tasks. Buyers on our Page and each person found elsewhere become leads.</p>';
    if (!empty($d['date'])) {
        $h .= '<p class="hint">' . (!empty($pg['ready']) ? 'On our Page: <a href="?tab=social&amp;view=inbox">' . (int)$pg['comments'] . ' comment' . ((int)$pg['comments'] === 1 ? '' : 's') . ' and ' . (int)$pg['messages'] . ' message' . ((int)$pg['messages'] === 1 ? '' : 's') . ' waiting for an answer</a>'
            . (!empty($pg['complaints']) ? ', ' . (int)$pg['complaints'] . ' unhappy' : '') . (!empty($pg['buyers']) ? '; ' . (int)$pg['buyers'] . ' buyer' . ((int)$pg['buyers'] === 1 ? '' : 's') . ' added to Leads' : '') . '.'
            : '<a href="?tab=settings&amp;section=connections">Connect the Facebook Page</a> so ' . pm_h($nm) . ' can read its comments and messages too.') . '</p>';
    }
    if (!empty($d['huddle'])) {
        $h .= '<ul class="talklist">' . implode('', array_map(fn($x) => '<li><time></time><b>' . pm_h(pm_talk_name((string)$x[0])) . ':</b><span>' . pm_h((string)$x[1]) . '</span></li>', (array)$d['huddle'])) . '</ul>';
    }
    if (!empty($d['tasks'])) {
        $h .= '<p class="hint"><b>Today\'s tasks:</b></p><ul class="t-sm">' . implode('', array_map(fn($x) => '<li><b>' . pm_h(pm_talk_name((string)$x[0])) . '</b>: ' . pm_h((string)$x[1]) . '</li>', (array)$d['tasks'])) . '</ul>';
    }
    if (!empty($d['focus']) && defined('PM_SOCIAL_FOCUS')) {
        $h .= '<p class="hint">Today\'s posts lean on: <b>' . pm_h(PM_SOCIAL_FOCUS[$d['focus']] ?? $d['focus']) . '</b></p>';
    }
    if (!empty($d['date'])) {
        $h .= '<p class="hint">' . ((int)($d['kept'] ?? 0) ? 'Found today: ' . pm_h(implode(', ', (array)($d['names'] ?? []))) . '.' : 'Nobody new asking today.') . '</p>';
    }
    $csrf = (string)($_SESSION['csrf'] ?? '');
    $h .= '<div class="btns">' . ($open ? '<a class="btn primary" href="?tab=agents&amp;status=people">' . $open . ' ' . ($open === 1 ? 'person' : 'people') . ' looking, waiting for a reply</a>' : '')
        . '<form method="post" data-confirm="' . pm_h($nm) . ' reads our Page, searches the public posts and meets the team now (one short AI web search)?"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="listen_now">'
        . '<button class="btn"' . (pm_agents_ready() ? '' : ' disabled title="Add an AI key to .env first"') . '>' . (empty($d['date']) || $d['date'] !== date('Y-m-d') ? 'Listen now' : 'Listen again') . '</button></form></div></div>';
    return $h;
}

/** "Listen now": Thandi's day, now (one short web search and one cheap call). */
function pm_do_listen_now(string $vb): array
{
    if (!pm_agents_ready()) {
        return ['msg' => 'The AI needs a key in .env first.', 'kind' => 'err', 'to' => 'social'];
    }
    @set_time_limit(180);
    pm_brand_set($vb);
    $all = pm_leads();
    $r = pm_listen_run($vb, $all);
    ($r['kept'] || $r['page']['buyers_added']) && pm_leads_save($all);
    $pg = $r['page'];
    return ['msg' => pm_agent_first('social') . ' met the team and set ' . $r['tasks'] . ' task' . ($r['tasks'] === 1 ? '' : 's') . '. '
        . ($pg['ready'] ? 'On our Page ' . ($pg['waiting_comments'] + $pg['waiting_messages']) . ' waiting for an answer' . ($pg['buyers_added'] ? ', ' . $pg['buyers_added'] . ' buyer(s) added to Leads' : '') . '. ' : '')
        . ($r['kept'] ? 'Found elsewhere: ' . $r['kept'] . ' ' . ($r['kept'] === 1 ? 'person' : 'people') . ' asking for what we offer: ' . implode(', ', $r['names']) . '.' : 'Nobody new asking in public posts today.'),
        'kind' => 'ok', 'to' => 'social'];
}
