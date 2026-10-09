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

/** Funnel and money numbers for one brand. Leads that came to us (Facebook, website) are kept out of the outreach reply rate. */
function pm_pipeline_stats(string $brand): array
{
    $leads = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    // Proposals and signed deals, from History (a proposal belongs to Travel Malawi when its package is the onboarding one).
    $tpl = pm_template();
    $norm = fn($n) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$n));
    $sent = 0;
    $signed = 0;
    $value = [];
    $signedIds = [];
    $signedNames = [];
    foreach (pm_history() as $h) {
        $type = (string)($tpl['packages'][(int)($h['proposal']['package'] ?? 0)]['type'] ?? '');
        if (pm_brand_of_type($type) !== $brand) {
            continue;
        }
        $sent += str_starts_with((string)$h['status'], 'Emailed') || !empty($h['signed_file']) ? 1 : 0;
        if (!empty($h['signed_file'])) {
            $signed++;
            $cur = (string)($h['currency'] ?? 'USD');
            $value[$cur] = ($value[$cur] ?? 0) + (float)$h['setup'] + 12 * (float)$h['monthly'];
            if (!empty($h['proposal']['lead_id'])) {
                $signedIds[(string)$h['proposal']['lead_id']] = 1;
            }
            if ($norm($h['business'] ?? '') !== '') {
                $signedNames[$norm($h['business'])] = 1;
            }
        }
    }
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
        'hot' => count(array_filter($leads, fn($l) => ($l['score'] ?? 0) >= 75 && in_array($l['status'], ['drafted', 'qualified'], true))),
    ];
}

/**
 * Today's strategy for a brand: cached for the day, one cheap AI call. $force re-asks.
 * If the AI call fails, today's date and headline are NOT saved: the previous brief is kept and returned with an 'error'
 * (and the call is not repeated for 30 minutes).
 */
function pm_director_brief(bool $force = false): array
{
    $brand = pm_brand();
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
        . "Reply JSON only: {\"headline\":\"one sentence\",\"priorities\":[\"3 to 5 specific actions for today\"],\"focus\":[\"up to 3 business types from segments or target_types to search more of\"],\"drop\":[\"types to search less of, or empty\"],\"experiment\":\"one small thing to test this week\"}";
    $user = json_encode(['brand' => $brand, 'today' => date('Y-m-d'), 'pipeline' => $stats, 'target_types' => $cfg['sectors'], 'daily_send_cap' => $cfg['send_cap']], JSON_UNESCAPED_UNICODE);
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
    $brief = [
        'date' => date('Y-m-d'), 'stats' => $stats, 'headline' => (string)$out['headline'],
        'priorities' => $list('priorities'), 'focus' => $list('focus'), 'drop' => $list('drop'), 'experiment' => (string)($out['experiment'] ?? ''),
    ];
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
