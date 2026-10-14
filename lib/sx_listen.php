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

/** What people ask for that this business answers, in a few words. */
function pm_listen_wants(string $brand): string
{
    if ($brand === 'travel') {
        return 'a place to stay (lodge, guesthouse, campsite, chalet, Airbnb), a recommendation of where to stay, or things to do and tours';
    }
    $off = array_map(fn($o) => trim((string)preg_replace('/:.*$/', '', (string)$o)), (array)(pm_agents_config($brand)['offerings'] ?? []));
    if (pm_brand_is_custom($brand)) {
        return mb_substr(implode(', ', array_filter($off)) ?: 'what we offer', 0, 200);
    }
    return 'IT help: a website or web app, a business or booking system, a laptop, computer or gaming PC, IT support, networking, email or backups';
}

/** The searches Thandi runs today: a few towns a day in turn, in the words people really use when they ask. */
function pm_listen_queries(string $brand, array $towns): array
{
    $day = (int)date('z');
    $pick = $towns ? array_map(fn($i) => $towns[($day * 3 + $i) % count($towns)], [0, 1, 2]) : ['Malawi'];
    $pick = array_values(array_unique($pick));
    $ask = $brand === 'travel' ? ['where to stay in %s', 'recommend a lodge %s Malawi', 'accommodation %s Malawi help'] : (pm_brand_is_custom($brand)
        ? ['looking for %s Malawi', 'recommend %s Malawi', 'anyone know %s Malawi']
        : ['need a website developer %s Malawi', 'recommend IT support %s', 'where to buy a laptop %s Malawi']);
    $q = [];
    foreach ($pick as $i => $t) {
        $q[] = sprintf($ask[$i % count($ask)], $t);
    }
    $q[] = $brand === 'travel' ? 'Malawi travel forum where to stay ' . date('Y') : 'Malawi ' . ($brand === 'promanaged' ? 'IT services' : 'recommendation') . ' reddit ' . date('Y');
    return $q;
}

/**
 * The listening: ONE short web-searched call, plain text back (a long prompt or a demand for JSON makes the model answer without searching).
 * What the posts said, with their links; '' when nothing was found.
 */
function pm_listen_search(string $brand, array $towns, array $skip): string
{
    $areas = implode(', ', $towns ?: ['Malawi']);
    $user = 'Use Google Search for each of these searches: ' . implode('; ', array_map(fn($q) => '"' . $q . '"', pm_listen_queries($brand, $towns))) . '. '
        . 'Then list the public posts, group posts, comments and forum threads from the last 60 days (today is ' . date('j F Y') . ") in which a person in or coming to $areas asks for "
        . pm_listen_wants($brand) . '. For each: the exact link, the date posted, the platform, the town, the name shown on the post (or none), what they asked in their words, '
        . 'and an email or phone only if it is written in the post. Only posts the searches found; no business advertising itself; nothing about the person beyond the post. '
        . ($skip ? 'Leave out these links, already known: ' . implode(' ', $skip) . '. ' : '') . 'Newest first.';
    $txt = trim(pm_claude('You search the web and report only what you found, each with its exact link.', $user, true, 1500, 'write')); // the Flash model searches properly; the Lite one tends to answer without searching
    return preg_match('#https?://#', $txt) ? mb_substr($txt, 0, 4000) : ''; // no link: nothing found
}

/** Thandi's instructions and her brief for today (no web: the search is done): [system, user]. */
function pm_listen_prompt(string $brand, string $found, array $towns, array $drop = [], array $page = []): array
{
    $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
    $company = pm_brand_name($brand);
    $focusKeys = defined('PM_SOCIAL_FOCUS') ? implode('|', array_keys(PM_SOCIAL_FOCUS)) : 'mix';
    $st = pm_listen_state($brand);
    $system = pm_agents_company_brief('tiny') . "\nYou are " . pm_agent_first('social') . ", head of Social: you know what people on social media want, on our own Page and everywhere else.\n"
        . '"people": from search_found only (this morning\'s search of public posts), each person who asks for what we offer, with the exact link given there; never invent one or a detail; '
        . 'skip a business advertising itself, anything older than 60 days or outside our areas, and every link in drop_links (too old or already answered: never talk about those either). Keep only what the post says. For each: who (the name shown, or empty), platform, url, posted (YYYY-MM-DD), town, '
        . 'wants (what they asked for, briefly, in their words), email and phone only if written in the post, '
        . "reply: our public answer under their post, at most 50 words, helpful first and specific to what they asked, from $company (we are a business: never pretend to be a customer, traveller or friend)"
        . ($site !== '' ? ", no link except $site" : ', no links') . ', no pressure, ending with an easy question. '
        . '"our_page" is what people said on our own Facebook Page, Instagram and Messenger and who still waits for an answer: in the huddle say who answers them today (the replies desk), a complaint first, and turn what they keep asking into tasks. '
        . '"huddle": your morning sit-down with the department heads (director, scouts, research, writers, replies, followup). They talk to EACH OTHER about today: '
        . 'the director\'s orders and team notes, the social numbers and the people you found; every line names something concrete (a town, a person found, a number, an order) and nobody repeats the company pitch. '
        . '4 to 6 lines {"who","says"}, each one short first-person sentence, from the facts only; you speak first and last. '
        . '"tasks": up to 4 for today {"to":"social|scouts|research|writers|replies|followup|director","do":"one specific sentence"}; yours (to social) say what today\'s posts and stories must do. '
        . "\"focus\": what today's posts lean on, one of $focusKeys. "
        . (defined('PM_TRUST_RULES') ? PM_TRUST_RULES . ' ' : '') . PM_AGENT_RULES;
    $user = json_encode(['today' => date('Y-m-d'), 'areas' => $towns, 'we_answer' => pm_listen_wants($brand), 'search_found' => $found !== '' ? $found : 'nobody asking today', 'drop_links' => $drop,
            'our_page' => !empty($page['ready']) ? ['waiting_for_an_answer' => (int)$page['waiting_comments'] + (int)$page['waiting_messages'], 'comments_waiting' => (int)$page['waiting_comments'],
                'messages_waiting' => (int)$page['waiting_messages'], 'complaints' => (int)$page['complaints'], 'buyers_added_to_leads' => (int)$page['buyers_added'], 'what_they_said' => $page['lines']] : 'not connected',
            'team_notes' => function_exists('pm_tb_line') ? pm_tb_line($brand, 'social', 6) : '', 'social_numbers' => function_exists('pm_director_social') ? pm_director_social($brand) : [],
            'firm' => function_exists('pm_firm_line') ? pm_firm_line($brand) : '', 'yesterday' => ['people_found' => (int)($st['day']['kept'] ?? 0)]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nJSON {\"people\":[{\"who\",\"platform\",\"url\",\"posted\",\"town\",\"wants\",\"email\",\"phone\",\"reply\"}],\"huddle\":[{\"who\",\"says\"}],\"tasks\":[{\"to\",\"do\"}],\"focus\":\"\"} with at most 6 people (none is fine).";
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
    // the links the search found, checked by code before the team talks about them: the real address, and too old or already known is dropped
    $drop = [];
    $live = 0;
    if ($found !== '' && preg_match_all('#https?://[^\s)\]>"]+#', $found, $lm)) {
        foreach (array_unique($lm[0]) as $u0) {
            $u = pm_listen_real_url($u0);
            $u !== $u0 && $found = str_replace($u0, $u, $found);
            $pd = pm_listen_post_date($u);
            if (($pd && $pd < strtotime('-75 days')) || isset($seen[md5(strtolower(rtrim($u, '/')))])) {
                $drop[] = $u;
            } else {
                $live++;
            }
        }
        $live || $found = ''; // every link was old or known: nobody new asking
    }
    // our own Page first: comments and messages read, buyers into the leads, who is waiting for an answer
    $page = pm_listen_page($brand);
    if ($page['buyers_added']) {
        foreach (pm_leads() as $k => $v) { // the buyers were saved to the leads file: keep them when the caller saves $all
            $all[$k] ??= $v;
        }
    }
    [$system, $user] = pm_listen_prompt($brand, $found, $towns, array_slice($drop, 0, 12), $page);
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
    $focus = (string)($out['focus'] ?? '');
    $focus = defined('PM_SOCIAL_FOCUS') && isset(PM_SOCIAL_FOCUS[$focus]) ? $focus : '';

    // the people: checked by code, then each one a lead
    $nFound = 0;
    $names = [];
    $wantsSeen = [];
    $now = date('Y-m-d H:i');
    foreach (array_slice((array)($out['people'] ?? []), 0, 6) as $p) {
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
        if (!preg_match('#^https?://[^\s/]+\.[^\s]+#i', $url) || isset($seen[$h]) || $what === '' || $reply === ''
            || ($site !== '' && stripos($url, (string)parse_url($site, PHP_URL_HOST) ?: '#none#') !== false)
            || ($posted && $posted < strtotime('-75 days')) || ($town === '' && stripos($townIn . ' ' . $what, 'malawi') === false)) {
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
            'id' => $id, 'brand' => $brand, 'name' => $name, 'type' => 'Person looking', 'city' => $town ?: 'Malawi', 'address' => '', 'website' => '',
            'phone' => mb_substr(trim((string)($p['phone'] ?? '')), 0, 30), 'email' => $email, 'whatsapp' => '', 'contact' => $who, 'contact_title' => '',
            'evidence' => [$what . ' ' . $url], 'facebook' => '', 'instagram' => '', 'social_gaps' => [], 'need_signals' => [$what], 'offering' => '',
            'reason' => 'Asked on ' . $plat . ($posted ? ' on ' . date('j M', $posted) : '') . ': ' . $what, 'score' => 80, 'status' => 'new',
            'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0, 'created' => $now, 'updated' => $now, 'src' => ['kind' => 'listen'], 'folders' => ['People looking'],
            'listen' => ['url' => $url, 'platform' => $plat, 'posted' => $posted ? date('Y-m-d', $posted) : '', 'wants' => $what, 'reply' => mb_substr($reply, 0, 600), 'found' => $now],
        ];
        $names[] = $name . ($town !== '' && $who !== '' ? ' (' . $town . ')' : '');
        $wantsSeen[] = $what;
    }
    $kept = count($names);
    if (count($seen) > 600) {
        uasort($seen, fn($a, $b) => strcmp((string)($a[0] ?? ''), (string)($b[0] ?? '')));
        $seen = array_slice($seen, -500, null, true);
    }
    $day = ['date' => date('Y-m-d'), 'at' => $now, 'huddle' => $huddle, 'tasks' => $tasks, 'focus' => $focus, 'found' => $nFound, 'kept' => $kept, 'names' => $names, 'error' => '',
        'page' => ['ready' => $page['ready'], 'comments' => $page['waiting_comments'], 'messages' => $page['waiting_messages'], 'complaints' => $page['complaints'], 'buyers' => $page['buyers_added']]];
    pm_update('listen', function (array $s) use ($brand, $seen, $day) {
        $s[$brand] = ['seen' => $seen, 'day' => $day];
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
    if ($kept && function_exists('pm_tb_post')) {
        pm_tb_post($brand, 'social', 'director', 'I found ' . $kept . ' ' . ($kept === 1 ? 'person' : 'people') . ' asking on social media: ' . implode('; ', array_slice($wantsSeen, 0, 3)), 'thandi:found');
        pm_tb_post($brand, 'social', 'writers', 'People are asking on social media for: ' . implode('; ', array_slice($wantsSeen, 0, 3)) . '. Use what they want as the angle.', 'thandi:asks');
    }
    return ['found' => $nFound, 'kept' => $kept, 'names' => $names, 'tasks' => count($tasks), 'links' => $live, 'dropped' => count($drop), 'page' => $page, 'error' => ''];
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
