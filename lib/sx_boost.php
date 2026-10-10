<?php
/**
 * sx_boost — MARKETING.md cycle-1 social & growth layer (S01 strategy, S02 trends, S03 repurposing,
 * S05 proactive engagement drafts, S07 X native posting, S08 audience segments).
 * Auto-loaded via lib/social_modules.php (glob) — every function here is registered by name where the registry looks.
 */

/* ---------------- S02 · trend/event radar ---------------- */

/**
 * A brand's stored trend list: {day, items[]}, fresh for 6 days, else []. C2-A11: each brand has its own (data/social_trends.json {brands:{promanaged:{...}, travel:{...}}});
 * the top-level day/items stay a copy of ProManaged IT's list, so an older file and a call with no brand still work.
 */
function pm_social_trends(string $brand = 'promanaged'): array
{
    $d = pm_load('social_trends', fn() => []);
    $e = (array)($d['brands'][$brand] ?? ($brand === 'promanaged' ? $d : []));
    if (($e['day'] ?? '') !== '' && !empty($e['items']) && strtotime((string)$e['day']) > time() - 6 * 86400) {
        return ['day' => (string)$e['day'], 'items' => array_values((array)$e['items'])];
    }
    return [];
}

/** [system, user] for one brand's weekly trend call: business trends for ProManaged IT, travel and season trends for Travel Malawi. */
function pm_trend_prompt(string $brand): array
{
    $rules = ' One short line each, factual, no speculation, nothing political or sensitive. Reply with JSON only.';
    if (pm_brand_is_custom($brand)) {
        $br = pm_brain($brand);
        return ["You are the Trend radar for " . pm_brand_name($brand) . ', ' . rtrim(trim((string)($br['about'] ?? 'a small business')), '.') . ". From current Malawian news and seasons, list up to 6 things its customers (" . (rtrim(trim((string)($br['audience'] ?? '')), '.') ?: 'people and businesses') . ') are talking about THIS week: events, weather, fuel or currency moves, school terms, seasons.' . $rules, 'Return JSON: {"items":["...", "..."]}'];
    }
    $system = $brand === 'travel'
        ? "You are the Trend radar for Travel Malawi, a direct-booking site for independent stays in Malawi. From current Malawian tourism news and seasons, list up to 6 things travellers and stay owners are talking about THIS week: festivals and events, school holidays, the rainy or dry season, lake and weather conditions, flights, roads and borders, fuel prices, wildlife and birding seasons." . $rules
        : "You are the Trend radar for a small IT and marketing business in Malawi. From current Malawian news and seasons, list up to 6 things small businesses (and their customers) are talking about THIS week — events, weather, fuel or currency moves, school terms, football, farming or tourism seasons." . $rules;
    return [$system, 'Return JSON: {"items":["...", "..."]}'];
}

/** Weekly job: one AI call per brand listing what its audience cares about this week (Monday, or when the list has gone stale). */
function pm_jobg_trends(): string
{
    $d = pm_load('social_trends', fn() => []);
    $today = date('Y-m-d');
    $due = [];
    $fresh = 0;
    $doneToday = 0;
    foreach (pm_brand_ids() as $b) {
        $day = (string)($d['brands'][$b]['day'] ?? ($b === 'promanaged' ? ($d['day'] ?? '') : ''));
        if ($day === $today) {
            $doneToday++;
        } elseif ((int)date('N') !== 1 && $day !== '' && strtotime($day) > time() - 6 * 86400) {
            $fresh++;
        } else {
            $due[] = $b;
        }
    }
    if (!$due) {
        return $doneToday === 2 ? 'trends already refreshed today' : 'trends are still fresh';
    }
    $stored = [];
    $errors = [];
    foreach ($due as $b) {
        [$system, $user] = pm_trend_prompt($b);
        try {
            $out = pm_agent_json(pm_claude($system, $user, false, 700));
        } catch (Throwable $e) {
            $errors[] = $b . ': ' . $e->getMessage();
            continue;
        }
        $items = array_values(array_filter(array_map('strval', (array)($out['items'] ?? [])), fn($i) => $i !== ''));
        $items = array_slice(array_map(fn($i) => mb_substr($i, 0, 120), $items), 0, 6);
        if ($items) {
            $stored[$b] = $items;
        }
    }
    if ($stored) {
        pm_update('social_trends', function (array $d) use ($stored, $today) {
            foreach ($stored as $b => $items) {
                $d['brands'][$b] = ['day' => $today, 'items' => $items];
            }
            $pro = (array)($d['brands']['promanaged'] ?? []);
            if ($pro) {
                $d['day'] = $pro['day'];
                $d['items'] = $pro['items'];
            }
            return $d;
        }, fn() => []);
    }
    $msg = $stored ? implode(', ', array_map(fn($b, $i) => count($i) . ' ' . (pm_brand_name($b)), array_keys($stored), $stored)) . ' trend(s) stored for the planners' : 'trends: nothing usable returned';
    return $errors ? $msg . ' (' . implode('; ', $errors) . ')' : $msg;
}

/** One compact line for a brand's planner prompt; '' when nothing is fresh. */
function pm_trend_line(string $brand = 'promanaged'): string
{
    $t = pm_social_trends($brand);
    return $t ? ($brand === 'travel' ? 'This week for travel in Malawi: ' : 'This week in Malawi: ') . implode(' | ', array_slice($t['items'], 0, 3)) : '';
}

/* ---------------- S03 · auto-repurposing ---------------- */

/** A top performer of the last 28 days (image/text/carousel, with a caption) that has not been repurposed yet. */
function pm_repurpose_candidates(string $brand): array
{
    $rows = pm_sx_rows($brand)['rows'];
    if (!$rows) {
        return [];
    }
    $cut = time() - 28 * 86400;
    $out = [];
    foreach ($rows as $r) {
        if ($r['unplanned'] || ($r['eng'] ?? 0) <= 0) {
            continue;
        }
        $p = null;
        foreach (pm_sx_posts($brand) as $x) {
            if (($x['id'] ?? '') === $r['id']) {
                $p = $x;
            }
        }
        if (!$p || ($p['status'] ?? '') !== 'published' || pm_sx_pub_ts($p) < $cut || trim((string)($p['caption'] ?? '')) === '') {
            continue;
        }
        $again = false;
        foreach (pm_sx_posts($brand) as $x) {
            if (($x['repurposed_from'] ?? '') === $p['id'] || ($x['recycled_from'] ?? '') === $p['id']) {
                $again = true;
            }
        }
        if ($again) {
            continue;
        }
        $out[] = $p;
    }
    usort($out, fn($a, $b) => ($b['metrics']['d7']['eng'] ?? 0) <=> ($a['metrics']['d7']['eng'] ?? 0));
    return array_slice($out, 0, 1);
}

/**
 * C2-A12: the picture style a re-cut of a winner should use. A carousel always opens with the cover card. A story re-draws the winner's own
 * headline on the owner's photo when there is one (and on the plain headline card when the winner was the photo card), so the same idea reads as a new picture.
 */
function pm_recut_layout(array $orig, string $kind): string
{
    if ($kind === 'carousel') {
        return 'cover';
    }
    $cur = (string)($orig['layout'] ?? '');
    if ($cur === '' && function_exists('pm_card_layout_for')) {
        $cur = pm_card_layout_for($orig);
    }
    $photo = !empty($orig['asset_id']) && function_exists('pm_social_asset_find') && pm_social_asset_find((string)$orig['asset_id']);
    return $cur === 'photo' || !$photo ? 'headline' : 'photo';
}

/** Deterministic re-cuts of a winner: a story and a 3-slide carousel carrying the winner's own picture words and photo, and a trimmed text post. No AI. */
function pm_repurpose_make(array $orig, int $i): ?array
{
    $cap = trim((string)($orig['caption'] ?? ''));
    $sent = preg_split('/(?<=[.!?])\s+/', $cap, -1, PREG_SPLIT_NO_EMPTY) ?: [$cap];
    $kind = ['story', 'carousel', 'text'][$i % 3];
    $base = ['id' => bin2hex(random_bytes(10)), 'brand' => $orig['brand'] ?? 'promanaged', 'status' => 'draft', 'pillar' => $orig['pillar'] ?? 'Tip/How-to',
        'cta' => $orig['cta'] ?? '', 'proof_id' => $orig['proof_id'] ?? '', 'hashtags' => [], 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0,
        'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => 'repurpose', 'repurposed_from' => $orig['id']];
    foreach (['segment', 'audience', 'hook_pattern'] as $k) { // who the post speaks to travels with it
        if (!empty($orig[$k])) {
            $base[$k] = $orig[$k];
        }
    }
    if ($kind !== 'text' && trim((string)($orig['headline'] ?? '')) !== '') { // the winner's picture: same words and photo, re-cut in a new layout
        foreach (['headline', 'sub', 'alt', 'asset_id'] as $k) {
            if (!empty($orig[$k])) {
                $base[$k] = $orig[$k];
            }
        }
        $base['layout'] = pm_recut_layout($orig, $kind);
        $base['repurposed_image'] = (string)(($orig['layout'] ?? '') ?: (function_exists('pm_card_layout_for') ? pm_card_layout_for($orig) : ''));
    }
    if ($kind === 'story') {
        $words = preg_split('/\s+/', $cap) ?: [];
        $text = implode(' ', array_slice($words, 0, 40));
        $base['format'] = 'story';
        $base['caption'] = rtrim($text, ' ,') . ' (link in bio)';
    } elseif ($kind === 'carousel') {
        $slides = [];
        foreach (array_slice($sent, 0, 3) as $s) {
            $slides[] = ['h' => mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $s), 0, 30), 't' => mb_substr(trim($s), 0, 80)];
        }
        $base['format'] = 'carousel';
        $base['caption'] = mb_substr($cap, 0, 160);
        $base['slides'] = $slides;
    } else {
        $base['format'] = 'text';
        $base['caption'] = mb_substr($cap, 0, 160);
    }
    $base['when'] = date('Y-m-d', time() + 2 * 86400) . ' 12:30';
    return $base;
}

/** Job: bring back the best performer of the month as up to 2 fresh drafts (2 per week). */
function pm_job_repurpose(string $brand): string
{
    $recent = count(array_filter(pm_sx_posts($brand), fn($p) => !empty($p['repurposed_from']) && strtotime((string)($p['created'] ?? '')) > time() - 7 * 86400));
    if ($recent >= 2) {
        return 'repurposing weekly limit reached';
    }
    $made = 0;
    foreach (pm_repurpose_candidates($brand) as $cand) {
        foreach ([0, 1] as $i) {
            if ($made >= min(2, 2 - $recent)) {
                break;
            }
            $new = pm_repurpose_make($cand, $i);
            if (!$new) {
                continue;
            }
            $why = function_exists('pm_social_lint_post') ? pm_social_lint_post($new) : [];
            $new = function_exists('pm_social_resolve') ? pm_social_resolve($new, $why) : $new;
            pm_social_add_planned([$new], pm_social_auto($brand));
            if (!empty($new['repurposed_image'])) { // draw the re-cut now so the owner sees the new picture in the draft at once
                try {
                    $new['format'] === 'carousel' && function_exists('pm_card_slides') ? pm_card_slides($new, '4x5') : (function_exists('pm_card_render') ? pm_card_render($new, 'story') : null);
                } catch (Throwable) {
                }
            }
            $made++;
        }
    }
    return $made ? "$made repurposed draft(s) made from a top performer" : 'no repurposing candidate found';
}

/* ---------------- S05 · proactive engagement drafts ---------------- */

/** Stored engagement suggestions for a brand: {day, rows[]}. Drafts only — the owner pastes them manually. */
function pm_proactive(string $brand): array
{
    $d = pm_load('proactive', fn() => []);
    if (($d[$brand]['day'] ?? '') !== '' && strtotime((string)$d[$brand]['day']) > time() - 6 * 86400) {
        return (array)$d[$brand]['rows'];
    }
    return [];
}

/** Weekly job: 3 genuine, helpful comment drafts for peer pages and local groups. Linted; nothing is posted. */
function pm_jobg_proactive(): string
{
    $made = 0;
    foreach (pm_brand_ids() as $b) {
        $d = pm_load('proactive', fn() => []);
        if (($d[$b]['day'] ?? '') === date('Y-m-d')) {
            continue;
        }
        $s = pm_settings();
        pm_brand_set($b);
        $system = pm_agents_company_brief('tiny') . "\nYou help {$s['company_name']} join local conversations. Write 3 engagement suggestions: each is a kind of Malawian Facebook page or group to comment on, and a one-to-two sentence comment that is genuinely helpful, specific and warm. No links, no prices, no selling, no flattery of our own business, no controversy. Reply with JSON only.";
        try {
            $out = pm_agent_json(pm_claude($system, 'Return JSON: {"items":[{"where":"kind of page or group","comment":"short comment"}]}', false, 900));
        } catch (Throwable) {
            $out = null;
        }
        $rows = [];
        foreach ((array)($out['items'] ?? []) as $it) {
            $c = trim((string)($it['comment'] ?? ''));
            $w = trim((string)($it['where'] ?? ''));
            if ($c === '' || $w === '') {
                continue;
            }
            $bad = function_exists('pm_outreach_lint') ? pm_outreach_lint('Engagement suggestion', $c, true) : [];
            if (!$bad) {
                $rows[] = ['where' => mb_substr($w, 0, 80), 'comment' => mb_substr($c, 0, 300)];
            }
        }
        if ($rows) {
            pm_update('proactive', function (array $d) use ($b, $rows) {
                $d[$b] = ['day' => date('Y-m-d'), 'rows' => array_slice($rows, 0, 3)];
                return $d;
            }, fn() => []);
            $made += count($rows);
        }
    }
    return $made ? "$made engagement suggestion(s) ready in Social > Plan" : 'no engagement suggestions this week';
}

/** A 'today' item so the owner sees fresh suggestions (rendered by the plan screen's today list). */
function pm_today_proactive(string $brand): array
{
    $rows = pm_proactive($brand);
    return $rows ? [['text' => count($rows) . ' community comment suggestion(s) waiting — review and paste them by hand', 'href' => '?tab=social&view=plan', 'urgency' => 0]] : [];
}

/* ---------------- S07 · X (Twitter) native posting ---------------- */

function pm_x_cfg(): array
{
    $e = pm_env();
    $get = fn($k) => trim((string)(function_exists('pm_env_val') ? pm_env_val($k) : ($e[$k] ?? '')));
    $c = ['key' => $get('X_API_KEY'), 'secret' => $get('X_API_SECRET'),
        'token' => $get('X_ACCESS_TOKEN'), 'token_secret' => $get('X_ACCESS_SECRET')];
    $c['ready'] = $c['key'] !== '' && $c['secret'] !== '' && $c['token'] !== '' && $c['token_secret'] !== '';
    return $c;
}

/** OAuth 1.0a header for the X API v2. */
function pm_x_sign(string $method, string $url, array $params, array $cfg): string
{
    $oauth = ['oauth_consumer_key' => $cfg['key'], 'oauth_nonce' => bin2hex(random_bytes(16)), 'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => (string)time(), 'oauth_token' => $cfg['token'], 'oauth_version' => '1.0'];
    $base = array_merge($params, $oauth);
    ksort($base);
    $qs = [];
    foreach ($base as $k => $v) {
        $qs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    $baseStr = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $qs));
    $key = rawurlencode($cfg['secret']) . '&' . rawurlencode($cfg['token_secret']);
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseStr, $key, true));
    $h = [];
    foreach ($oauth as $k => $v) {
        $h[] = rawurlencode($k) . '="' . rawurlencode((string)$v) . '"';
    }
    return 'OAuth ' . implode(', ', $h);
}

/** Posts one text to X. [ok, id|message]. PM_X_STUB lets tests stub the network. */
function pm_x_post(string $text): array
{
    $cfg = pm_x_cfg();
    if (!$cfg['ready']) {
        return [false, 'X is not connected (X_API_KEY, X_API_SECRET, X_ACCESS_TOKEN, X_ACCESS_SECRET in .env).'];
    }
    if (isset($GLOBALS['PM_X_STUB']) && is_callable($GLOBALS['PM_X_STUB'])) {
        return $GLOBALS['PM_X_STUB']($text);
    }
    $url = 'https://api.x.com/2/tweets';
    $auth = pm_x_sign('POST', $url, [], $cfg);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text])]);
    pm_curl_native_ca($ch);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $j = json_decode($raw, true);
        return [true, (string)($j['data']['id'] ?? 'posted')];
    }
    return [false, 'X said: ' . ($code ?: 'no answer') . ' ' . mb_substr($raw, 0, 120)];
}

/** Job: publish due text/image posts to X when the channel is on. */
function pm_job_channels_x(string $brand): string
{
    if (!pm_x_cfg()['ready']) {
        return 'X not connected';
    }
    $ch = function_exists('pm_social_settings') ? (array)pm_social_settings($brand)['channels'] : [];
    if (empty($ch['x'])) {
        return 'X switched off';
    }
    $n = 0;
    foreach (pm_sx_posts($brand) as $p) {
        if (($p['status'] ?? '') !== 'approved' || ($p['x'] ?? '') !== '' || !in_array($p['format'] ?? '', ['text', 'image'], true)) {
            continue;
        }
        $when = strtotime((string)($p['when'] ?? ''));
        if (!$when || $when > time() + 60 || $when < time() - 3600) {
            continue;
        }
        $text = function_exists('pm_social_caption') ? pm_social_caption($p, 'x') : (string)($p['caption'] ?? '');
        if (trim($text) === '') {
            continue;
        }
        [$ok, $m] = pm_x_post(mb_substr($text, 0, 280));
        pm_social_patch($p['id'], fn(array $cur) => array_merge($cur, ['x' => $ok ? 'published' : 'failed', 'x_id' => $ok ? (string)$m : '', 'x_err' => $ok ? '' : mb_substr($m, 0, 200)]));
        $n += $ok ? 1 : 0;
        if ($n >= 3) {
            break;
        }
    }
    return $n ? "$n X post(s) published" : 'no X posts due';
}

/* ---------------- S08 · ProManaged audience segments ---------------- */

/** A lead's audience segment for promanaged — the same grouping the pipeline uses (pm_lead_group), so the planner and the funnel agree. */
function pm_pro_audience(array $lead): string
{
    return ($lead['brand'] ?? 'promanaged') === 'travel' ? (function_exists('pm_classify_audience') ? (string)pm_classify_audience($lead) : '') : pm_lead_group($lead);
}

/** Job: stamp a missing 'audience' on every lead (rule-based, zero AI). */
function pm_job_audience_segments(string $brand): string
{
    $n = 0;
    pm_update('leads', function (array $leads) use ($brand, &$n) {
        foreach ($leads as $id => &$l) {
            if (($l['brand'] ?? 'promanaged') !== $brand || !empty($l['audience'])) {
                continue;
            }
            $a = pm_pro_audience($l);
            if ($a !== '') {
                $l['audience'] = $a;
                $n++;
            }
        }
        unset($l);
        return $leads;
    });
    return $n ? "$n lead(s) tagged with an audience segment" : 'no leads to tag';
}

/** One line for the planner: the audience mix of the last 90 days. */
function pm_audience_line(string $brand): string
{
    $mix = [];
    $cut = time() - 90 * 86400;
    foreach (pm_load('leads', fn() => []) as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || strtotime((string)($l['created'] ?? '')) < $cut) {
            continue;
        }
        $a = (string)($l['audience'] ?? pm_pro_audience($l));
        if ($a !== '') {
            $mix[$a] = ($mix[$a] ?? 0) + 1;
        }
    }
    if (!$mix) {
        return '';
    }
    arsort($mix);
    $top = array_slice(array_keys($mix), 0, 3);
    return 'Audience mix: ' . implode(', ', array_map(fn($k) => "$k (" . $mix[$k] . ')', $top));
}

/* ---------------- S01 · the planner learns from outcomes ---------------- */

/** Everything the planner should read before writing a week of posts: own learnings, outcome weights, trends, cross-brand lessons, audience. */
function pm_plan_learnings(string $brand): string
{
    $parts = [];
    $learn = function_exists('pm_social_learnings') ? trim((string)pm_social_learnings($brand)) : '';
    if ($learn !== '') {
        $parts[] = $learn;
    }
    if (function_exists('pm_social_perf_weights')) {
        $w = (array)pm_social_perf_weights($brand);
        arsort($w);
        $top = [];
        foreach ($w as $k => $v) {
            if ((float)$v > 1.0) {
                $top[] = "$k x" . round((float)$v, 2);
            }
        }
        if ($top) {
            $parts[] = 'Proven mix (do more of): ' . implode(', ', array_slice($top, 0, 3));
        }
    }
    $t = pm_trend_line($brand);
    if ($t !== '') {
        $parts[] = $t;
    }
    foreach (array_slice(pm_shared_learnings(), 0, 2) as $sh) {
        $parts[] = 'Cross-brand: ' . $sh;
    }
    $a = pm_audience_line($brand);
    if ($a !== '') {
        $parts[] = $a;
    }
    return implode(' ', $parts);
}

/** Plan-screen card, shown once at the top (it was a per-post slot, so it repeated under every post): why this week's mix is what it is. */
function pm_panel_plan_top_strategy(string $vb, array $ctx = []): string
{
    $line = pm_plan_learnings($vb);
    $h = '<div class="card sx-card" style="padding:12px 16px"><b style="font-size:13px">Why this week\'s mix</b><p style="margin:6px 0 0;font-size:13px;color:var(--muted,#667085)">'
        . ($line !== '' ? pm_h($line) : 'Not enough data yet — the planner follows the default mix until the scoreboard has measured posts.') . '</p>';
    foreach (array_slice(pm_proactive($vb), 0, 3) as $pr) {
        $h .= '<div style="margin-top:8px;font-size:12.5px"><b>' . pm_h($pr['where']) . '</b><br>' . pm_h($pr['comment']) . '</div>';
    }
    return $h . '</div>';
}




