<?php
/**
 * The Marketing Director: reads the whole pipeline once a day per brand, works out what is (and is not) bringing leads and money,
 * and tells the other agents where to focus. One small AI call (about 600 tokens); the numbers themselves are plain code.
 */
require_once __DIR__ . '/agents.php';

/** Did this lead really answer us? A reply with a known kind, or (older leads) a recorded reply that was not a STOP. Auto-replies, bounces and STOPs do not count. */
function pm_lead_replied(array $l): bool
{
    $i = (string)($l['reply_intent'] ?? '');
    if (in_array($i, ['interested', 'question', 'meeting_request', 'not_now', 'not_interested'], true)) {
        return true;
    }
    return $i === '' && ($l['status'] ?? '') !== 'optout' && (!empty($l['last_reply']) || in_array($l['status'] ?? '', ['replied', 'proposal', 'won'], true));
}

/** An amount in another currency as Kwacha, using the settings rate table (rate = units of that currency per 1 Kwacha). Unknown currency = 0. */
function pm_to_mwk(float $amount, string $cur): float
{
    if (strtoupper($cur) === 'MWK') {
        return $amount;
    }
    foreach ((array)(pm_settings()['currencies'] ?? []) as $c) {
        if (strtoupper((string)($c['code'] ?? '')) === strtoupper($cur) && (float)($c['rate'] ?? 0) > 0) {
            return $amount / (float)$c['rate'];
        }
    }
    return 0.0;
}

/**
 * Proposals sent and signed deals from History for one brand ('' = both). A proposal belongs to Travel Malawi when its package is the onboarding one.
 * 'value' is per currency; 'mwk_by_id' / 'mwk_by_name' are the same deals in Kwacha per lead id / business name (a deal counts once its signed file exists).
 */
function pm_signed_deals(string $brand = ''): array
{
    $tpl = pm_template();
    $norm = fn($n) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$n));
    $r = ['sent' => 0, 'signed' => 0, 'value' => [], 'ids' => [], 'names' => [], 'mwk_by_id' => [], 'mwk_by_name' => []];
    foreach (pm_history() as $h) {
        $type = (string)($tpl['packages'][(int)($h['proposal']['package'] ?? 0)]['type'] ?? '');
        if ($brand !== '' && pm_brand_of_type($type) !== $brand) {
            continue;
        }
        $r['sent'] += str_starts_with((string)$h['status'], 'Emailed') || !empty($h['signed_file']) ? 1 : 0;
        if (!empty($h['signed_file'])) {
            $r['signed']++;
            $cur = (string)($h['currency'] ?? 'USD');
            $amt = (float)$h['setup'] + 12 * (float)$h['monthly'];
            $r['value'][$cur] = ($r['value'][$cur] ?? 0) + $amt;
            $mwk = pm_to_mwk($amt, $cur);
            if (!empty($h['proposal']['lead_id'])) {
                $lid = (string)$h['proposal']['lead_id'];
                $r['ids'][$lid] = 1;
                $r['mwk_by_id'][$lid] = ($r['mwk_by_id'][$lid] ?? 0) + $mwk;
            }
            if ($norm($h['business'] ?? '') !== '') {
                $r['names'][$norm($h['business'])] = 1;
                $r['mwk_by_name'][$norm($h['business'])] = ($r['mwk_by_name'][$norm($h['business'])] ?? 0) + $mwk;
            }
        }
    }
    return $r;
}

/** What a signed lead is worth in Kwacha (setup + 12 months, from its signed proposal); 0 when not signed. $deals: pm_signed_deals() result, pass it when valuing many leads. */
function pm_lead_signed_value(array $lead, ?array $deals = null): float
{
    $deals ??= pm_signed_deals((string)($lead['brand'] ?? 'promanaged'));
    $id = (string)($lead['id'] ?? '');
    if ($id !== '' && isset($deals['mwk_by_id'][$id])) {
        return (float)$deals['mwk_by_id'][$id];
    }
    $n = preg_replace('/[^a-z0-9]+/', '', strtolower((string)($lead['name'] ?? '')));
    return $n !== '' ? (float)($deals['mwk_by_name'][$n] ?? 0) : 0.0;
}

/** The source label of a lead: src_tag (string) first, else a string src, else its source, else 'scout'. Scout leads (src kept as a list) group as 'scout'. */
function pm_pipeline_src_label(array $l): string
{
    $t = function_exists('pm_lead_src_tag') ? pm_lead_src_tag($l) : '';
    if ($t === '') {
        $t = is_string($l['src'] ?? null) ? trim($l['src']) : '';
    }
    $t = $t !== '' ? $t : (string)($l['source'] ?? '');
    return $t !== '' ? mb_substr($t, 0, 40) : 'scout';
}

/** Funnel and money numbers for one brand. Leads that came to us (Facebook, website) are kept out of the outreach reply rate. */
function pm_pipeline_stats(string $brand): array
{
    $leads = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    // Proposals and signed deals, from History.
    $norm = fn($n) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$n));
    $deals = pm_signed_deals($brand);
    ['sent' => $sent, 'signed' => $signed, 'value' => $value, 'ids' => $signedIds, 'names' => $signedNames] = $deals;
    $bySrc = [];
    $wonValueBySrc = [];
    $st = array_fill_keys(array_keys(PM_LEAD_STATUSES), 0);
    $seg = [];
    $src = [];
    $inbound = ['leads' => 0, 'won' => 0, 'sources' => []];
    $week = 0;
    $won = 0;
    foreach ($leads as $l) {
        $st[$l['status']] = ($st[$l['status']] ?? 0) + 1;
        $isWon = ($l['status'] ?? '') === 'won' || isset($signedIds[(string)($l['id'] ?? '')]) || isset($signedNames[$norm($l['name'] ?? '')]);
        $won += $isWon ? 1 : 0;
        $sl = pm_pipeline_src_label($l); // every lead by where it came from (inbound and outreach)
        $bySrc[$sl] ??= ['leads' => 0, 'replied' => 0, 'won' => 0];
        $bySrc[$sl]['leads']++;
        $bySrc[$sl]['replied'] += pm_lead_replied($l) || in_array($l['status'] ?? '', ['replied', 'proposal', 'won'], true) ? 1 : 0;
        $bySrc[$sl]['won'] += $isWon ? 1 : 0;
        if ($isWon) {
            $wonValueBySrc[$sl] = ($wonValueBySrc[$sl] ?? 0) + pm_lead_signed_value($l, $deals);
        }
        foreach ((array)($l['sent'] ?? []) as $at) {
            $week += strtotime((string)$at) > time() - 7 * 86400 ? 1 : 0;
        }
        $s = (string)($l['source'] ?? '');
        if (in_array($s, ['facebook', 'web'], true)) {
            $inbound['leads']++;
            $inbound['won'] += $isWon ? 1 : 0;
            $inbound['sources'][$s] = ($inbound['sources'][$s] ?? 0) + 1;
            continue; // they wrote to us: not part of the outreach funnel
        }
        $replied = pm_lead_replied($l);
        $contacted = !empty($l['sent']) || !empty($l['wa_sent']) || !empty($l['last_contacted']) || $replied || in_array($l['status'], ['contacted', 'replied', 'proposal', 'won'], true);
        $k = $s !== '' ? $s : (string)($l['src']['kind'] ?? 'scout');
        $src[$k] ??= ['leads' => 0, 'contacted' => 0, 'replied' => 0, 'won' => 0];
        $t = pm_lead_group($l);
        $seg[$t] ??= ['leads' => 0, 'contacted' => 0, 'replied' => 0, 'won' => 0];
        $bump = function (array &$row) use ($contacted, $replied, $isWon) {
            $row['leads']++;
            $row['contacted'] += $contacted ? 1 : 0;
            $row['replied'] += $replied ? 1 : 0;
            $row['won'] += $isWon ? 1 : 0;
        };
        $bump($seg[$t]);
        $bump($src[$k]);
    }
    $contacted = array_sum(array_column($seg, 'contacted'));
    $replied = array_sum(array_column($seg, 'replied'));
    uasort($seg, fn($a, $b) => [$b['won'], $b['replied'], $b['contacted']] <=> [$a['won'], $a['replied'], $a['contacted']]);
    $bs = pm_backlog_state($leads, pm_agents_config($brand), $brand);
    return [
        'leads' => count($leads), 'status' => array_filter($st), 'segments' => array_slice($seg, 0, 8, true),
        'contacted' => $contacted, 'replied' => $replied, 'reply_rate' => $contacted ? round(100 * $replied / $contacted) : 0,
        'inbound' => $inbound, 'sources' => $src, 'backlog' => $bs['backlog'], 'scouting_paused' => $bs['pause'],
        'sent_7d' => $week, 'proposals_sent' => $sent, 'signed' => $signed, 'won' => $won, 'signed_value' => $value,
        'by_src' => $bySrc, 'won_value_by_src' => array_map(fn($v) => (int)round($v), $wonValueBySrc), // Kwacha
        'hot' => count(array_filter($leads, fn($l) => ($l['score'] ?? 0) >= 75 && in_array($l['status'], ['drafted', 'qualified'], true))),
    ];
}

/**
 * What the Director sees of the social side, in about 150 tokens: followers and 7-day change, best and weakest pillar of the scoreboard (28 days
 * of posts, only when there is enough data), social enquiries and leads against the goals, ad cost per conversation, unanswered comments, drafts waiting.
 * [] when the social modules are not installed.
 */
function pm_director_social(string $brand): array
{
    if (!function_exists('pm_social_scoreboard') || !function_exists('pm_social_goals')) {
        return [];
    }
    try {
        $sb = pm_social_scoreboard($brand);
        $g = pm_social_goals($brand);
        $o = ['followers' => $sb['followers']['now'], 'followers_7d' => $sb['followers']['d7']];
        if ($sb['enough']) {
            $pil = array_filter($sb['groups']['pillar'], fn($b, $k) => $b['enough'] && $k !== 'unplanned' && $k !== '(none)', ARRAY_FILTER_USE_BOTH);
            if (count($pil) >= 2) {
                $o['top_pillar'] = array_key_first($pil);
                $o['weak_pillar'] = array_key_last($pil);
            }
        } else {
            $o['scoreboard'] = 'not enough data yet (' . $sb['n'] . ' scored posts)';
        }
        $o['enquiries_7d'] = pm_sx_social_leads_count($brand, 7);
        $o['enquiries_goal_week'] = $g['enquiries_per_week'];
        $o['leads_30d'] = pm_sx_social_leads_count($brand, 30);
        $o['leads_goal_month'] = $g['leads_per_month'];
        $ad = pm_ads_results($brand);
        if ($ad['cost_per_conversation'] !== null) {
            $o['ad_cost_per_conversation_mwk'] = $ad['cost_per_conversation'];
        }
        if (function_exists('pm_response_stats')) {
            $rs = (array)pm_response_stats($brand);
            $o['comments_unanswered_2h'] = $rs['unanswered_over_2h'] ?? null;
        }
        $o['drafts_waiting'] = count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand && ($p['status'] ?? '') === 'draft'));
        return array_filter($o, fn($v) => $v !== null);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Today's strategy for a brand: cached for the day, one cheap AI call. $force re-asks.
 * If the AI call fails, today's date and headline are NOT saved: the previous brief is kept and returned with an 'error'
 * (and the call is not repeated for 30 minutes).
 */
function pm_director_brief(bool $force = false): array
{
    $brand = pm_brand();
    if (function_exists('pm_experiment_autostart')) { // next test from the fixed menu when none is running (no AI)
        try {
            pm_experiment_autostart($brand);
        } catch (Throwable) {
        }
    }
    $all = pm_load('director', fn() => []);
    $mine = $all[$brand] ?? [];
    $stats = pm_pipeline_stats($brand); // the numbers are always live; only the written advice is cached
    $changed = abs((int)$stats['leads'] - (int)($mine['stats']['leads'] ?? 0)) >= 3 || (int)$stats['signed'] !== (int)($mine['stats']['signed'] ?? 0);
    if (!$force && ($mine['date'] ?? '') === date('Y-m-d') && !empty($mine['headline']) && !$changed) {
        $mine['stats'] = $stats;
        return $mine;
    }
    $show = function (array $b, string $err) use ($stats) { // what callers see when there is no fresh advice
        $b['stats'] = $stats;
        $b['error'] = $err;
        $b['headline'] = ($b['headline'] ?? '') !== '' ? $b['headline'] : 'The Director could not run today (' . $err . '). The numbers below are live.';
        return $b;
    };
    if (!$force && !empty($mine['error']) && time() - (int)($mine['error_at'] ?? 0) < 1800) {
        return $show($mine, (string)$mine['error']);
    }
    $cfg = pm_agents_config();
    $system = pm_agents_company_brief('tiny') . "\nYou are the Marketing Director. From the pipeline numbers, decide what will bring the most qualified replies and signed deals at the least effort and AI spend. "
        . "Be concrete and honest: if there is too little data to judge, say so and say what to collect. Never suggest spam, pressure or fake urgency. Think like a sales lead: in Malawi, small businesses usually answer a WhatsApp message or a phone call faster than an email, so recommend the channel mix and a daily rhythm for the marketing team (who to call first, when to follow up). Prefer leads with a named decision maker. "
        . "Replies from people who wrote to us first (inbound) are not outreach results. If backlog is high, say to send or clear drafts before finding more leads. "
        . "Reply JSON only: {\"headline\":\"one sentence\",\"priorities\":[\"3 to 5 specific actions for today\"],\"focus\":[\"up to 3 business types from segments or target_types to search more of\"],\"drop\":[\"types to search less of, or empty\"],\"experiment\":\"one small thing to test this week\",\"focus_weights\":{\"type\":0 to 100, how many of today's searches each type deserves, only for types in target_types}\"";
    $ai = $stats; // by_src can be long: only the five biggest sources go to the AI
    $ai['by_src'] = array_slice($ai['by_src'] ?? [], 0, 5, true);
    $ai['won_value_by_src'] = array_slice($ai['won_value_by_src'] ?? [], 0, 5, true);
    $social = pm_director_social($brand);
    if ($social) {
        $keys = defined('PM_SOCIAL_FOCUS') ? implode('|', array_keys(PM_SOCIAL_FOCUS)) : 'mix';
        $system .= ",\"social_focus\":\"$keys: what the next social posts should lean on\",\"social_note\":\"one sentence on what social should do this week, only from the social numbers\"}. "
            . "The \"social\" object holds the Facebook Page numbers: if the scoreboard has too little data, say so and do not claim what works.";
    } else {
        $system .= '}';
    }
    $user = json_encode(['brand' => $brand, 'today' => date('Y-m-d'), 'pipeline' => $ai, 'target_types' => $cfg['sectors'], 'daily_send_cap' => $cfg['send_cap']] + ($social ? ['social' => $social] : []), JSON_UNESCAPED_UNICODE);
    $out = null;
    $err = '';
    try {
        $out = pm_agent_json(pm_claude($system, $user, false, 900, 'write'));
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    if (!is_array($out) || empty($out['headline'])) {
        $err = $err !== '' ? $err : 'no usable answer';
        $keep = $mine;
        $keep['error'] = mb_substr($err, 0, 200);
        $keep['error_at'] = time();
        $all[$brand] = $keep; // only the error is recorded; date and headline stay as they were
        pm_save('director', $all);
        return $show($keep, $keep['error']);
    }
    $list = fn($k) => array_values(array_filter(array_map('strval', (array)($out[$k] ?? []))));
    $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', 'and', $s)));
    $weights = [];
    foreach ((array)($out['focus_weights'] ?? []) as $k => $v) {
        if (!is_string($k) || $k === '' || !is_numeric($v)) {
            continue;
        }
        foreach ($cfg['sectors'] as $sec) { // only types we actually target; matched on normalised text (case, & / and, spaces)
            if ($norm((string)$k) === $norm((string)$sec)) {
                $weights[(string)$sec] = max(0, min(100, (int)$v));
                break;
            }
        }
    }
    $brief = [
        'date' => date('Y-m-d'), 'stats' => $stats, 'headline' => (string)$out['headline'],
        'priorities' => $list('priorities'), 'focus' => $list('focus'), 'drop' => $list('drop'), 'experiment' => (string)($out['experiment'] ?? ''),
    ] + ($weights ? ['focus_weights' => $weights] : []);
    if ($social) { // the social part of the reply: a valid focus key and one note
        $sf = (string)($out['social_focus'] ?? '');
        if (defined('PM_SOCIAL_FOCUS') && isset(PM_SOCIAL_FOCUS[$sf])) {
            $brief['social_focus'] = $sf;
            $brief['social_at'] = date('Y-m-d');
        }
        $brief['social_note'] = mb_substr(trim((string)($out['social_note'] ?? '')), 0, 240);
    }
    $all[$brand] = $brief;
    pm_save('director', $all);
    return $brief;
}

/** Today's brief for this brand, or [] when it is not from today. */
function pm_director_today(): array
{
    $mine = (pm_load('director', fn() => [])[pm_brand()] ?? []);
    return ($mine['date'] ?? '') === date('Y-m-d') ? $mine : [];
}

/** Today's focus weights from the Director (type => 0..100), or [] when the brief carries none. */
function pm_director_focus_weights(): array
{
    $b = pm_director_today();
    $w = (array)($b['focus_weights'] ?? []);
    $out = [];
    foreach ($w as $k => $v) {
        if (is_string($k) && $k !== '' && is_numeric($v)) {
            $out[$k] = max(0, min(100, (int)$v));
        }
    }
    return $out;
}

/** Business types the Director wants searched more today (only ones already in the brand's target list; a segment name maps to the targets in it). */
function pm_director_focus(): array
{
    $mine = pm_director_today();
    $targets = pm_agents_config()['sectors'];
    $out = [];
    foreach ((array)($mine['focus'] ?? []) as $f) {
        foreach ($targets as $t) {
            if (strcasecmp($f, $t) === 0 || (pm_lead_group(['type' => $t, 'brand' => pm_brand()]) === $f && $f !== 'Other')) {
                $out[$t] = 1;
            }
        }
    }
    return array_keys($out);
}

/** Business types the Director wants searched less today (names or segment names); pm_scout_targets leaves them out of today's rotation. */
function pm_director_drop(): array
{
    $mine = pm_director_today();
    $out = [];
    foreach ((array)($mine['drop'] ?? []) as $d) {
        $d = trim((string)$d);
        if ($d === '') {
            continue;
        }
        $out[] = $d;
        foreach (pm_agents_config()['sectors'] as $t) { // a segment name drops every target in that segment
            if (pm_lead_group(['type' => $t, 'brand' => pm_brand()]) === $d && $d !== 'Other') {
                $out[] = $t;
            }
        }
    }
    return array_values(array_unique($out));
}
