<?php
/**
 * The news desk: each morning, posts for the day built on what is in the news and talked about online right now, so the Page joins today's conversation
 * instead of posting the same evergreen lines.
 *   1. One web-searched call (plain text back, so the model really searches): the stories and talking points of the last few days in the business's country,
 *      for its audience, each with its link, outlet and date.
 *   2. One call picks the ones the audience cares about AND the business can speak to honestly, and writes a post for each (caption, picture words, best time),
 *      tying the story to what we offer without exploiting it. Tragedy, deaths, accidents, crime, politics and elections are left alone.
 * Code checks every post: its story must be one the search found (a real link), and the caption passes the usual posting rules. Nothing is posted by itself:
 * "Put in today's plan" makes a draft on Social > Plan for the owner to approve. The stories also feed the weekly planner's "this week" line (social_trends).
 * Cost: two calls a business a day (the scheduler, from 06:00), or "Look again". Data: data/news_desk.json {brand: {day, at, news: [{title, outlet, url, date, line}],
 * posts: [{id, news, angle, format, caption, hashtags, headline, sub, time, status new|planned|skipped, post_id}], error}}.
 */

const PM_ND_MAX = 4;

function pm_nd_state(string $brand): array
{
    return (array)(pm_load('news_desk', fn() => [])[pm_brand_norm($brand)] ?? []) + ['day' => '', 'at' => '', 'news' => [], 'posts' => [], 'error' => ''];
}

function pm_nd_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('news_desk', function (array $all) use ($brand, $fn, &$out) {
        $k = pm_brand_norm($brand);
        $s = (array)($all[$k] ?? []) + ['day' => '', 'at' => '', 'news' => [], 'posts' => [], 'error' => ''];
        $all[$k] = $out = $fn($s);
        return $all;
    }, fn() => []);
    return $out;
}

/** What this business's audience follows in the news, in a few words. */
function pm_nd_topics(string $brand): string
{
    if ($brand === 'travel') {
        return 'tourism and travel, festivals and events, the lake and the national parks, the weather and the season, flights, roads and border crossings, fuel, public holidays, and what travellers and lodge owners are discussing online';
    }
    if (pm_brand_is_custom($brand)) {
        return 'what its customers follow: ' . mb_substr(pm_listen_wants($brand), 0, 200) . '; plus business news, the economy, events and the season';
    }
    return 'business and the economy (the kwacha, fuel, power cuts, mobile money, taxes), technology and the internet, gaming and football, schools and exams, events, and what small business owners and young people are discussing online';
}

/** Step 1: the news of the last few days, as plain text with links (a demand for JSON makes the model answer without searching). '' when nothing came back. */
function pm_nd_search(string $brand): string
{
    $home = function_exists('pm_markets') ? pm_markets($brand)['home'] : 'Malawi';
    $user = 'Use Google Search for the news and the most talked-about topics online in ' . $home . ' in the last 3 days (today is ' . date('l j F Y') . '), about: ' . pm_nd_topics($brand) . '. '
        . 'List 8 to 10 current stories or talking points, newest first. For each: the headline in plain words, the outlet or platform, the date, the exact link, and one line on what people are saying about it. '
        . 'Only stories the searches found, from the last 7 days. Leave out deaths, accidents, crime, disasters, politics and elections.';
    $txt = trim(pm_claude('You search the web and report only what you found, each with its exact link and date.', $user, true, 1800, 'write'));
    return preg_match('#https?://#', $txt) ? mb_substr($txt, 0, 6000) : '';
}

/** Step 2's instructions: [system, user]. Pure, so it can be tested. */
function pm_nd_prompt(string $brand, string $found, array $recent): array
{
    $s = pm_settings();
    $hours = function_exists('pm_social_hours') ? pm_social_hours($brand) : ['18:30'];
    $system = pm_agents_company_brief('tiny') . "\nYou run the news desk for {$s['company_name']}'s Facebook Page. From news_found ONLY, pick up to " . PM_ND_MAX . ' stories our audience cares about today '
        . 'and that we can speak to honestly from our facts, and write one post for each that joins the conversation: helpful or interesting first, then the link to what we offer, said naturally. '
        . 'The link between the story and us must be natural and true: if it is a stretch, leave that story out (fewer, better posts). Each post speaks to one story, in full sentences, with one call to action. '
        . 'Never exploit bad news, never take sides, nothing about deaths, accidents, crime, disasters, politics or elections, and never claim the news says more than it does. '
        . 'Use our facts only for what we offer: no prices, numbers, guarantees or promises that are not in the facts. '
        . 'For each: news (the index of the story in news_list), angle (one sentence: why this story, for our audience, today), format (image or text), '
        . 'caption (40 to 80 words, plain and warm, one clear idea, mentions the story in plain words so a reader knows what it is about, no links, no phone numbers, ends with an easy question or call to comment), '
        . 'hashtags (2 to 4, at least one local like #Malawi), headline (at most 7 words, printed on the picture), sub (at most 12 words), time (the best of: ' . implode(', ', $hours) . '). '
        . (function_exists('pm_firm_line') ? pm_firm_line($brand) . ' ' : '') . (defined('PM_HUMAN_RULES') ? PM_HUMAN_RULES . ' ' : '') . 'Reply JSON only.';
    $user = json_encode(['today' => date('Y-m-d l'), 'news_found' => $found, 'our_last_posts' => array_map(fn($c) => mb_substr(preg_replace('/\s+/', ' ', (string)$c), 0, 90), array_slice($recent, 0, 8))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nJSON {\"news_list\":[{\"title\":\"\",\"outlet\":\"\",\"date\":\"YYYY-MM-DD\",\"url\":\"\",\"line\":\"\"}],\"posts\":[{\"news\":0,\"angle\":\"\",\"format\":\"image\",\"caption\":\"\",\"hashtags\":[],\"headline\":\"\",\"sub\":\"\",\"time\":\"\"}]}";
    return [$system, $user];
}

/** The day's run: the news, then the posts. Saved; returns the state. Never throws: an error is recorded on the desk. */
function pm_nd_run(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $found = pm_nd_search($brand);
        if ($found === '') {
            return pm_nd_update($brand, fn($s) => ['day' => date('Y-m-d'), 'at' => date('Y-m-d H:i'), 'error' => 'The search found no news this time.'] + $s);
        }
        $links = [];
        if (preg_match_all('#https?://[^\s)\]>"]+#', $found, $lm)) {
            foreach (array_unique($lm[0]) as $u0) {
                $u = function_exists('pm_listen_real_url') ? pm_listen_real_url($u0) : $u0;
                $u !== $u0 && $found = str_replace($u0, $u, $found);
                $links[] = strtolower(rtrim($u, '/.,'));
            }
        }
        $recent = array_column(array_slice(array_reverse(array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand))), 0, 8), 'caption');
        [$sys, $usr] = pm_nd_prompt($brand, $found, $recent);
        $out = pm_agent_json(pm_claude($sys, $usr, false, 2600, 'write'));
        $news = [];
        foreach (array_slice((array)($out['news_list'] ?? []), 0, 12) as $i => $n) {
            $url = trim((string)($n['url'] ?? ''));
            $url = function_exists('pm_listen_real_url') ? pm_listen_real_url($url) : $url;
            $real = $url !== '' && (in_array(strtolower(rtrim($url, '/.,')), $links, true) || str_contains(strtolower($found), strtolower(rtrim($url, '/'))));
            $date = strtotime((string)($n['date'] ?? '')) ?: 0;
            $news[$i] = ['title' => mb_substr(trim(strip_tags((string)($n['title'] ?? ''))), 0, 160), 'outlet' => mb_substr(trim(strip_tags((string)($n['outlet'] ?? ''))), 0, 60), 'url' => $real ? $url : '',
                'date' => $date ? date('Y-m-d', $date) : '', 'line' => mb_substr(trim(strip_tags((string)($n['line'] ?? ''))), 0, 220), 'real' => $real && (!$date || $date > strtotime('-10 days'))];
        }
        $posts = [];
        foreach (array_slice((array)($out['posts'] ?? []), 0, PM_ND_MAX) as $p) {
            $ni = (int)($p['news'] ?? -1);
            $cap = trim((string)($p['caption'] ?? ''));
            if (!isset($news[$ni]) || !$news[$ni]['real'] || $news[$ni]['title'] === '' || $cap === '') {
                continue; // a post must stand on a story the search really found, from the last days
            }
            $cap = function_exists('pm_human_polish') ? pm_human_polish($cap, 'post') : $cap;
            $time = preg_match('/^\d{1,2}:\d{2}$/', (string)($p['time'] ?? '')) ? (string)$p['time'] : (pm_social_hours($brand)[0] ?? '18:30');
            $posts[] = ['id' => substr(md5($brand . $news[$ni]['url'] . $cap), 0, 10), 'news' => $ni, 'angle' => mb_substr(trim(strip_tags((string)($p['angle'] ?? ''))), 0, 220),
                'format' => ($p['format'] ?? '') === 'text' ? 'text' : 'image', 'caption' => mb_substr($cap, 0, 1200),
                'hashtags' => array_slice(array_values(array_filter(array_map(fn($h) => '#' . ltrim((string)preg_replace('/[^\p{L}\p{N}_]/u', '', (string)$h), '#'), (array)($p['hashtags'] ?? [])), fn($h) => mb_strlen($h) > 2)), 0, 4),
                'headline' => mb_substr(trim(strip_tags((string)($p['headline'] ?? ''))), 0, 60), 'sub' => mb_substr(trim(strip_tags((string)($p['sub'] ?? ''))), 0, 90), 'time' => $time,
                'lint' => pm_social_lint($cap, $brand, 'facebook', $recent), 'status' => 'new'];
        }
        $state = pm_nd_update($brand, fn($s) => ['day' => date('Y-m-d'), 'at' => date('Y-m-d H:i'), 'news' => array_values($news), 'posts' => $posts, 'error' => $posts ? '' : 'No story today that we can speak to honestly.'] + $s);
        $heads = array_values(array_filter(array_map(fn($n) => $n['real'] ? $n['title'] : '', $news)));
        if ($heads) { // the weekly planner's "this week" line, from real news instead of a guess
            pm_update('social_trends', function (array $d) use ($brand, $heads) {
                $d['brands'][$brand] = ['day' => date('Y-m-d'), 'items' => array_slice($heads, 0, 6)];
                if ($brand === 'promanaged') {
                    $d['day'] = date('Y-m-d');
                    $d['items'] = array_slice($heads, 0, 6);
                }
                return $d;
            }, fn() => []);
        }
        pm_agent_log('Social', $posts ? 'News desk: ' . count($posts) . ' post' . (count($posts) === 1 ? '' : 's') . ' for today from the news (' . implode('; ', array_map(fn($p) => $news[$p['news']]['title'], array_slice($posts, 0, 2))) . '). They wait on Social > Plan.'
            : 'News desk: read today\'s news; nothing we can speak to honestly today.');
        return $state;
    } catch (Throwable $e) {
        return pm_nd_update($brand, fn($s) => ['at' => date('Y-m-d H:i'), 'error' => mb_substr($e->getMessage(), 0, 160)] + $s);
    } finally {
        pm_brand_set($was);
    }
}

/** A suggestion as a draft post in the plan (today at its time, or the next hour if that time has passed). Returns [ok, message]. */
function pm_nd_plan(string $brand, string $id): array
{
    $st = pm_nd_state($brand);
    $p = null;
    foreach ($st['posts'] as $x) {
        if (($x['id'] ?? '') === $id) {
            $p = $x;
        }
    }
    if (!$p || ($p['status'] ?? '') === 'planned') {
        return [false, $p ? 'It is in the plan already.' : 'That suggestion is not there any more.'];
    }
    $when = date('Y-m-d') . ' ' . $p['time'];
    if (strtotime($when) < time() + 600) {
        $when = date('Y-m-d H:00', time() + 3600);
    }
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $post = ['id' => bin2hex(random_bytes(10)), 'brand' => $brand, 'status' => 'draft', 'when' => $when, 'format' => $p['format'], 'pillar' => 'News', 'cta' => 'comment', 'proof_id' => '',
            'caption' => $p['caption'], 'hashtags' => $p['hashtags'], 'headline' => $p['headline'], 'sub' => $p['sub'], 'script' => '', 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0,
            'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''), 'source' => 'news', 'news' => $st['news'][$p['news']] ?? []];
        $post = pm_social_resolve($post, pm_social_lint($post['caption'], $brand, 'facebook', pm_social_recent_from(pm_social_posts(), $brand), $post));
        pm_social_add_planned([$post], false);
    } finally {
        pm_brand_set($was);
    }
    pm_nd_update($brand, function ($s) use ($id, $post) {
        foreach ($s['posts'] as $i => $x) {
            if (($x['id'] ?? '') === $id) {
                $s['posts'][$i]['status'] = 'planned';
                $s['posts'][$i]['post_id'] = $post['id'];
            }
        }
        return $s;
    });
    return [true, 'In the plan as a draft for ' . date('D j M, H:i', strtotime($when)) . ': read it and approve it on Social > Plan.'];
}

/* ---------------- the scheduler and the forms ---------------- */

/** Scheduler job: the day's news desk, once a day from 06:00 (with an AI key). */
function pm_job_news_desk(string $brand): string
{
    $now = function_exists('pm_now') ? pm_now() : time();
    if (date('H:i', $now) < '06:00' || !pm_agents_ready() || pm_nd_state($brand)['day'] === date('Y-m-d', $now)) {
        return '';
    }
    $s = pm_nd_run($brand);
    return $s['error'] !== '' ? 'news desk: ' . $s['error'] : 'news desk: ' . count($s['posts']) . ' post(s) for today';
}

function pm_do_nd_run(string $vb): array
{
    if (!pm_agents_ready()) {
        return ['msg' => 'The AI needs a key in .env first.', 'kind' => 'err', 'to' => 'social#newsdesk'];
    }
    @set_time_limit(180);
    $s = pm_nd_run($vb);
    return ['msg' => $s['error'] !== '' && !$s['posts'] ? $s['error'] : count($s['posts']) . ' post' . (count($s['posts']) === 1 ? '' : 's') . ' for today from the news.', 'kind' => $s['posts'] ? 'ok' : 'err', 'to' => 'social#newsdesk'];
}

function pm_do_nd_plan(string $vb): array
{
    [$ok, $m] = pm_nd_plan($vb, (string)($_POST['id'] ?? ''));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social#newsdesk'];
}

function pm_do_nd_skip(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    pm_nd_update($vb, function ($s) use ($id) {
        foreach ($s['posts'] as $i => $x) {
            if (($x['id'] ?? '') === $id) {
                $s['posts'][$i]['status'] = 'skipped';
            }
        }
        return $s;
    });
    return ['msg' => 'Put aside.', 'kind' => 'ok', 'to' => 'social#newsdesk'];
}

/** Social > Plan, at the top: today's posts from the news, one line each that opens for the caption. */
function pm_panel_plan_top_newsdesk(string $vb, array $ctx = []): string
{
    $st = pm_nd_state($vb);
    $csrf = (string)($_SESSION['csrf'] ?? '');
    $f = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
    $today = $st['day'] === date('Y-m-d');
    $open = array_values(array_filter($st['posts'], fn($p) => ($p['status'] ?? 'new') !== 'skipped'));
    $h = '<div class="card nd" id="newsdesk"><div class="nd-head"><div><h2 class="mt-0 mb-0">Today\'s posts from the news</h2><p class="hint mt-0 mb-0">Posts built on what is in the news and talked about online'
        . ($today && !empty($st['at']) ? ', read ' . pm_h(date('H:i', strtotime((string)$st['at']))) : '') . '. Nothing goes out until you put it in the plan and approve it.</p></div>'
        . '<form method="post" data-confirm="Read today\'s news and write posts from it (one web search and one call)?">' . $f('nd_run') . '<button class="btn small"' . (pm_agents_ready() ? '' : ' disabled title="Add an AI key to .env first"') . '>' . ($today ? 'Look again' : 'Read today\'s news') . '</button></form></div>';
    if (!$today) {
        $h .= '<p class="muted mb-0">Not read yet today: the desk reads the news each morning, or press the button.</p></div>';
        return $h;
    }
    if ($st['error'] !== '' && !$open) {
        $h .= '<p class="muted mb-0">' . pm_h((string)$st['error']) . '</p>';
    }
    foreach ($open as $p) {
        $n = (array)($st['news'][$p['news']] ?? []);
        $box = 'nd-' . pm_h((string)$p['id']);
        $h .= '<details class="sc-row"><summary><span class="pill">' . pm_h($p['format'] === 'text' ? 'Text' : 'Picture') . '</span><span class="sc-main"><b>' . pm_h((string)($p['headline'] ?: ($n['title'] ?? ''))) . '</b><span class="sc-sub">'
            . pm_h((string)($n['title'] ?? '')) . (!empty($n['outlet']) ? ' · ' . pm_h((string)$n['outlet']) : '') . '</span></span><span class="sc-meta">' . (($p['status'] ?? '') === 'planned' ? 'in the plan' : pm_h((string)$p['time'])) . '</span></summary><div class="sc-body">'
            . ($p['angle'] !== '' ? '<p class="hint">' . pm_h((string)$p['angle']) . '</p>' : '')
            . (!empty($n['url']) ? '<p class="t-sm">The story: <a href="' . pm_h((string)$n['url']) . '" target="_blank" rel="noopener noreferrer">' . pm_h((string)$n['title']) . ' ↗</a>' . (!empty($n['date']) ? ' <span class="muted">· ' . pm_h(date('j M', strtotime((string)$n['date']))) . '</span>' : '') . '</p>' : '')
            . '<textarea id="' . $box . '" rows="4" readonly>' . pm_h($p['caption'] . ($p['hashtags'] ? "\n\n" . implode(' ', $p['hashtags']) : '')) . '</textarea>'
            . (!empty($p['lint']) ? '<p class="hint warnt">To fix before it can go out: ' . pm_h(implode(' ', array_slice((array)$p['lint'], 0, 2))) . '</p>' : '')
            . '<div class="btns">' . (($p['status'] ?? '') === 'planned' ? '<a class="btn small" href="?tab=social">Open the plan</a>'
                : '<form method="post" class="inl">' . $f('nd_plan', '<input type="hidden" name="id" value="' . pm_h((string)$p['id']) . '">') . '<button class="btn small primary">Put in today\'s plan</button></form>'
                . '<form method="post" class="inl">' . $f('nd_skip', '<input type="hidden" name="id" value="' . pm_h((string)$p['id']) . '">') . '<button class="btn small ghost">Not for us</button></form>')
            . '<button type="button" class="btn small" data-copy-target="#' . $box . '">Copy</button></div></div></details>';
    }
    return $h . '</div>';
}
