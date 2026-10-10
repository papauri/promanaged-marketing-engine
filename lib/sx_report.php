<?php
/**
 * Measure, part 3: goals, the lead funnel by source and post, what the AI and the ads cost, the Monday report. Plain code, no AI.
 * Data: data/social_goals.json {brand:{...}}, data/social_ads_results.json {brand:{Y-m-d:{campaign:{spend,reach,clicks,conversations,...}}}},
 *       data/ai_usage_daily.json (written by pm_usage_add in agents.php).
 * Honest by design: every figure says where it came from (Facebook, or "entered by <name>"), and a gap is shown as a gap.
 */

require_once __DIR__ . '/director.php';

const PM_SX_AD_CONV_ACTION = 'onsite_conversion.messaging_conversation_started_7d'; // a person started a chat because of the ad
const PM_SX_SOCIAL_AGENTS = '/^(social|plan_posts|plan_offline|junk_judge|engage|page_|fb_|ads_|review_reply|catalogue|reply_|autopilot|recycle|brand_voice)/';

/* ---------------- goals ---------------- */

function pm_social_goals_default(): array
{
    return ['followers_target' => 0, 'followers_date' => '', 'enquiries_per_week' => 2, 'leads_per_month' => 6, 'posts_per_week' => 5,
        'max_cost_per_conv_mwk' => 0, 'daily_token_budget' => 0, 'mwk_per_1k_tokens' => 8];
}

/** The brand's goals with defaults (0 = no goal / no limit). */
function pm_social_goals(string $brand): array
{
    $g = (array)(pm_load('social_goals', fn() => [])[$brand] ?? []);
    $d = pm_social_goals_default();
    foreach ($d as $k => $v) {
        if ($k === 'followers_date') {
            $d[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g[$k] ?? '')) ? (string)$g[$k] : '';
        } elseif (isset($g[$k]) && is_numeric($g[$k])) {
            $d[$k] = $k === 'mwk_per_1k_tokens' ? max(0.0, (float)$g[$k]) : max(0, (int)$g[$k]);
        }
    }
    return $d;
}

function pm_social_goals_save(string $brand, array $in): array
{
    $d = pm_social_goals_default();
    $out = [];
    foreach ($d as $k => $v) {
        $raw = trim((string)($in[$k] ?? ''));
        $out[$k] = $k === 'followers_date' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '')
            : ($k === 'mwk_per_1k_tokens' ? ($raw === '' ? $v : max(0.0, min(1000.0, (float)$raw))) : max(0, min(100000000, (int)$raw)));
    }
    pm_update('social_goals', function (array $all) use ($brand, $out) {
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

function pm_do_goals_save(string $vb): array
{
    $g = pm_social_goals_save($vb, $_POST);
    return ['msg' => 'Goals saved.' . ($g['daily_token_budget'] > 0 ? ' AI use for this business stops at ' . number_format($g['daily_token_budget']) . ' tokens a day.' : ''), 'kind' => 'ok', 'to' => 'social&view=results'];
}

/* ---------------- leads from social, funnel ---------------- */

/** A lead as a flat row: the attribution fields plus where it stands. Works with or without the inbound module. */
function pm_sx_lead_row(array $l): array
{
    $tag = function_exists('pm_lead_src_tag') ? pm_lead_src_tag($l) : (is_string($l['src'] ?? null) ? trim($l['src']) : '');
    $ch = (string)($l['channel'] ?? '');
    $src = (string)($l['source'] ?? '');
    $ch = $ch !== '' ? $ch : ($src === 'facebook' ? 'facebook' : ($src === 'web' ? 'web' : ''));
    return ['id' => (string)($l['id'] ?? ''), 'created' => substr((string)($l['created'] ?? ''), 0, 10), 'status' => (string)($l['status'] ?? ''), 'src_tag' => $tag, 'channel' => $ch,
        'post_id' => (string)($l['source_post'] ?? ''), 'ref' => (string)($l['source_ref'] ?? ''), 'audience' => (string)($l['audience'] ?? ''),
        'first_touch_at' => (string)($l['first_touch_at'] ?? ''), 'first_reply_at' => (string)($l['first_reply_at'] ?? '')];
}

/** Did this enquiry come from our social posts or links (not from cold outreach)? */
function pm_sx_is_social(array $r, string $source): bool
{
    return $r['src_tag'] !== '' || $r['post_id'] !== '' || $r['ref'] !== '' || in_array($r['channel'], ['facebook', 'instagram', 'whatsapp', 'messenger'], true) || in_array($source, ['facebook', 'social'], true);
}

/**
 * Social enquiries of the last $days days, one row per lead: attribution (id, created, status, src_tag, channel, post_id, ref, audience, first_touch_at, first_reply_at)
 * plus source, pillar, format, name, replied, proposal, won, value (Kwacha, signed). Uses pm_attrib_leads when the inbound module provides it.
 */
function pm_sx_social_rows(string $brand, int $days): array
{
    static $cache = [];
    $key = $brand . '|' . $days . '|' . pm_sx_sig();
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $since = date('Y-m-d', strtotime("-$days days"));
    $raw = pm_leads();
    $base = [];
    if (function_exists('pm_attrib_leads')) {
        try {
            $base = (array)pm_attrib_leads($brand, $days);
        } catch (Throwable) {
            $base = [];
        }
    }
    if (!$base) {
        foreach ($raw as $l) {
            if (($l['brand'] ?? 'promanaged') === $brand && substr((string)($l['created'] ?? ''), 0, 10) >= $since) {
                $base[] = pm_sx_lead_row($l);
            }
        }
    }
    $posts = pm_sx_posts($brand);
    $byId = array_column($posts, null, 'id');
    $byFb = [];
    foreach ($posts as $p) {
        if (($p['fb_id'] ?? '') !== '') {
            $byFb[pm_sx_idkey($p['fb_id'])] = $p['id'];
        }
    }
    $deals = pm_signed_deals($brand);
    $norm = fn($n) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$n));
    $rows = [];
    foreach ($base as $r) {
        $l = $raw[$r['id'] ?? ''] ?? null;
        if (!$l || ($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $r += pm_sx_lead_row($l);
        $src = (string)($l['source'] ?? '');
        $ref = (string)$r['ref'];
        if ($ref === '' && preg_match('/^(?:fb|ig|li|wa|tt|x|gbp|google)[-_]([A-Z0-9]{4})$/', (string)$r['src_tag'], $m)) {
            $ref = $m[1];
        }
        $pid = (string)$r['post_id'];
        if ($pid !== '' && !isset($byId[$pid])) {
            $pid = $byFb[pm_sx_idkey($pid)] ?? $pid;
        }
        if ($pid === '' && $ref !== '' && function_exists('pm_post_by_ref')) {
            $pid = (string)(pm_post_by_ref($ref, $brand)['id'] ?? '');
        }
        $r['ref'] = $ref;
        $r['post_id'] = isset($byId[$pid]) ? $pid : '';
        if (!pm_sx_is_social($r, $src)) {
            continue;
        }
        $p = $byId[$r['post_id']] ?? null;
        $isWon = ($l['status'] ?? '') === 'won' || isset($deals['ids'][(string)$l['id']]) || ($norm($l['name'] ?? '') !== '' && isset($deals['names'][$norm($l['name'])]));
        $r += ['source' => $src !== '' ? $src : 'other', 'name' => (string)($l['name'] ?? ''),
            'pillar' => (string)(($l['source_pillar'] ?? '') ?: ($p['pillar'] ?? '')), 'format' => (string)(($l['source_format'] ?? '') ?: ($p['format'] ?? ''))];
        $r['replied'] = pm_lead_replied($l) || in_array($l['status'] ?? '', ['replied', 'proposal', 'won'], true) || $isWon;
        $r['proposal'] = in_array($l['status'] ?? '', ['proposal', 'won'], true) || $isWon;
        $r['won'] = $isWon;
        $r['value'] = $isWon ? pm_lead_signed_value($l, $deals) : 0.0;
        $rows[] = $r;
    }
    return $cache[$key] = $rows;
}

/** How many social enquiries arrived in the last $days days. */
function pm_sx_social_leads_count(string $brand, int $days): int
{
    return count(pm_sx_social_rows($brand, $days));
}

/** post id => ['leads' => n, 'won' => n] over the last year (the scoreboard adds these to each post). */
function pm_sx_leads_by_post(string $brand): array
{
    $o = [];
    foreach (pm_sx_social_rows($brand, 365) as $r) {
        if ($r['post_id'] !== '') {
            $o[$r['post_id']]['leads'] = ($o[$r['post_id']]['leads'] ?? 0) + 1;
            $o[$r['post_id']]['won'] = ($o[$r['post_id']]['won'] ?? 0) + ($r['won'] ? 1 : 0);
        }
    }
    return $o;
}

/** Minutes between two timestamps (strings), or null. */
function pm_sx_minutes(string $a, string $b): ?int
{
    $x = strtotime($a);
    $y = strtotime($b);
    return $x && $y && $y >= $x ? (int)round(($y - $x) / 60) : null;
}

function pm_sx_median(array $v): ?int
{
    sort($v);
    $n = count($v);
    return $n ? (int)round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2) : null;
}

/**
 * The social funnel for the last $days days. 'by' holds buckets per source, tag, post, pillar, format, channel:
 * each {leads, replied, proposal, won, value (signed, Kwacha)}. Plus the median time from first touch to first reply.
 */
function pm_social_funnel(string $brand, int $days = 90): array
{
    $rows = pm_sx_social_rows($brand, $days);
    $dims = ['source' => 'source', 'tag' => 'src_tag', 'post' => 'post_id', 'pillar' => 'pillar', 'format' => 'format', 'channel' => 'channel'];
    $by = array_fill_keys(array_keys($dims), []);
    $tot = ['leads' => 0, 'replied' => 0, 'proposal' => 0, 'won' => 0, 'value' => 0.0];
    $mins = [];
    foreach ($rows as $r) {
        $inc = fn(array &$b) => [$b['leads']++, $b['replied'] += $r['replied'] ? 1 : 0, $b['proposal'] += $r['proposal'] ? 1 : 0, $b['won'] += $r['won'] ? 1 : 0, $b['value'] += $r['value']];
        $inc($tot);
        foreach ($dims as $d => $f) {
            $k = (string)($r[$f] ?? '');
            $k = $k === '' ? '(none)' : $k;
            $by[$d][$k] ??= ['leads' => 0, 'replied' => 0, 'proposal' => 0, 'won' => 0, 'value' => 0.0];
            $inc($by[$d][$k]);
        }
        $m = pm_sx_minutes($r['first_touch_at'], $r['first_reply_at']);
        if ($m !== null) {
            $mins[] = $m;
        }
    }
    foreach ($by as $d => $_) {
        uasort($by[$d], fn($a, $b) => [$b['won'], $b['leads']] <=> [$a['won'], $a['leads']]);
    }
    $labels = [];
    foreach (pm_sx_posts($brand) as $p) {
        $labels[$p['id']] = (string)(($p['headline'] ?? '') ?: mb_substr((string)$p['caption'], 0, 40));
    }
    $resp = [];
    if (function_exists('pm_response_stats')) {
        try {
            $resp = (array)pm_response_stats($brand);
        } catch (Throwable) {
        }
    }
    return ['brand' => $brand, 'days' => $days, 'total' => $tot, 'by' => $by, 'post_labels' => $labels, 'rows' => count($rows),
        'reply_median_min' => pm_sx_median($mins), 'reply_n' => count($mins), 'response' => $resp];
}

/* ---------------- cost: AI tokens and ads ---------------- */

/** Tokens the AI used for a brand over the last $days days: total and social-only, with calls. From the daily totals. */
function pm_sx_usage(string $brand, int $days): array
{
    $f = PM_DATA . '/ai_usage_daily.json';
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $o = ['tokens' => 0, 'calls' => 0, 'social_tokens' => 0, 'social_calls' => 0];
    $since = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
    foreach ($d as $day => $brands) {
        if ($day < $since) {
            continue;
        }
        foreach ((array)($brands[$brand] ?? []) as $agent => $r) {
            $t = (int)($r['in'] ?? 0) + (int)($r['out'] ?? 0);
            $o['tokens'] += $t;
            $o['calls'] += (int)($r['calls'] ?? 0);
            if (preg_match(PM_SX_SOCIAL_AGENTS, (string)$agent)) {
                $o['social_tokens'] += $t;
                $o['social_calls'] += (int)($r['calls'] ?? 0);
            }
        }
    }
    return $o;
}

/** Ad spend (Kwacha), conversations, clicks and reach over a window, split into figures read from Facebook and figures typed in. $skipDays shifts the window back (prev period). */
function pm_sx_ads_sum(string $brand, int $days, int $skipDays = 0): array
{
    $d = (array)(pm_load('social_ads_results', fn() => [])[$brand] ?? []);
    $o = ['spend' => 0.0, 'conversations' => 0, 'clicks' => 0, 'reach' => 0, 'api_spend' => 0.0, 'api_conv' => 0, 'manual_spend' => 0.0, 'manual_conv' => 0, 'manual_by' => '', 'campaigns' => []];
    $since = date('Y-m-d', strtotime('-' . max(0, $days + $skipDays - 1) . ' days'));
    $until = $skipDays > 0 ? date('Y-m-d', strtotime('-' . $skipDays . ' days')) : '9999-12-31';
    foreach ($d as $day => $camps) {
        if ($day < $since || $day >= $until) {
            continue;
        }
        foreach ((array)$camps as $name => $r) {
            $man = ($r['src'] ?? 'api') === 'manual';
            $sp = (float)($r['spend_mwk'] ?? 0);
            $cv = (int)($r['conversations'] ?? 0);
            $o['spend'] += $sp;
            $o['conversations'] += $cv;
            $o['clicks'] += (int)($r['clicks'] ?? 0);
            $o['reach'] += (int)($r['reach'] ?? 0);
            $o[$man ? 'manual_spend' : 'api_spend'] += $sp;
            $o[$man ? 'manual_conv' : 'api_conv'] += $cv;
            $o['manual_by'] = $man ? (string)($r['by'] ?? '') : $o['manual_by'];
            $c = &$o['campaigns'][$man ? 'manual' : $name];
            $c ??= ['spend' => 0.0, 'conversations' => 0, 'clicks' => 0, 'src' => $man ? 'manual' : 'api'];
            $c['spend'] += $sp;
            $c['conversations'] += $cv;
            $c['clicks'] += (int)($r['clicks'] ?? 0);
            unset($c);
        }
    }
    return $o;
}

/**
 * What social costs and returns over the last $days days: AI tokens (social agents only) at the configured rate, ad spend, social enquiries,
 * cost per enquiry = (ads + tokens cost) / enquiries, tokens per published post.
 */
function pm_social_cost(string $brand, int $days = 7): array
{
    $g = pm_social_goals($brand);
    $u = pm_sx_usage($brand, $days);
    $ads = pm_sx_ads_sum($brand, $days);
    $tokCost = $u['social_tokens'] / 1000 * (float)$g['mwk_per_1k_tokens'];
    $leads = pm_sx_social_leads_count($brand, $days);
    $pub = array_filter(pm_sx_posts($brand), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - $days * 86400);
    $perPost = array_filter(array_map(fn($p) => isset($p['tokens']) ? (int)$p['tokens'] : null, $pub), fn($x) => $x !== null);
    return ['days' => $days, 'tokens' => $u['tokens'], 'social_tokens' => $u['social_tokens'], 'token_cost_mwk' => round($tokCost, 1), 'rate' => (float)$g['mwk_per_1k_tokens'],
        'ads_spend_mwk' => (int)round($ads['spend']), 'leads' => $leads, 'cost_per_lead' => $leads ? round(($ads['spend'] + $tokCost) / $leads) : null,
        'posts' => count($pub), 'tokens_per_post' => $perPost ? (int)round(array_sum($perPost) / count($perPost)) : (count($pub) ? (int)round($u['social_tokens'] / count($pub)) : null),
        'tokens_per_post_src' => $perPost ? 'planner' : 'estimate'];
}

/* ---------------- ads results ---------------- */

/** Rule-based hints only. Spend is never changed from here. */
function pm_sx_ads_hints(array $s, array $g): array
{
    $h = [];
    $cpc = $s['conversations'] > 0 ? $s['spend'] / $s['conversations'] : null;
    if ($s['spend'] > 0 && $s['conversations'] === 0) {
        $h[] = 'MWK ' . number_format($s['spend']) . ' spent in 7 days and no chats started: check the ad (picture, wording, audience) or pause it in Ads Manager.';
    }
    if ($cpc !== null && (int)$g['max_cost_per_conv_mwk'] > 0 && $cpc > $g['max_cost_per_conv_mwk']) {
        $h[] = 'Cost per conversation is MWK ' . number_format($cpc) . ', above your limit of MWK ' . number_format($g['max_cost_per_conv_mwk']) . ': consider pausing the campaign.';
    }
    return $h;
}

/** MG-S06: freshness and pacing hints from this week vs the week before. Pure. */
function pm_sx_ads_trend_hints(array $cur, array $prev, array $g): array
{
    $h = [];
    $conv = (int)($cur['conversations'] ?? 0);
    $prevConv = (int)($prev['conversations'] ?? 0);
    if ($prevConv >= 6 && $conv < $prevConv * 0.7 && (float)$cur['spend'] >= (float)$prev['spend'] * 0.8) {
        $h[] = 'Conversations fell from ' . $prevConv . ' to ' . $conv . ' while spend stayed up: refresh the creative (new picture or headline) before changing the budget.';
    }
    $cpc = $conv > 0 ? (float)$cur['spend'] / $conv : null;
    $limit = (int)($g['max_cost_per_conv_mwk'] ?? 0);
    if ($cpc !== null && $limit > 0 && $cpc <= 0.5 * $limit && $conv >= 3) {
        $h[] = 'Cost per conversation is well under your limit (MWK ' . number_format($cpc) . ' vs ' . number_format($limit) . '): the ads can safely take more budget.';
    }
    return $h;
}

/**
 * Ad results for the last 7 days. Read from Facebook (insights by campaign and day, stored per day) at most every 6 hours when ads are connected;
 * else whatever the owner typed in. Returns spend (Kwacha), conversations, clicks, reach, cost per conversation and click, source labels and hints.
 */
function pm_ads_results(string $brand, bool $refresh = false): array
{
    $g = pm_social_goals($brand);
    $a = function_exists('pm_ads_cfg') ? pm_ads_cfg($brand) : ['ready' => false];
    $err = '';
    $last = (int)(pm_social_state()['ads_at'][$brand] ?? 0);
    if (!empty($a['ready']) && ($refresh || time() - $last > 6 * 3600)) {
        pm_social_state_set(function (array $s) use ($brand) {
            $s['ads_at'][$brand] = time();
            return $s;
        });
        $cur = 'MWK';
        if (function_exists('pm_ads_account_info')) {
            $info = (array)pm_ads_account_info($brand);
            $cur = strtoupper((string)($info['currency'] ?? 'MWK')) ?: 'MWK';
        }
        [$ok, $d] = pm_graph('GET', 'act_' . $a['account'] . '/insights', ['level' => 'campaign', 'fields' => 'campaign_name,spend,impressions,reach,clicks,actions,cost_per_action_type',
            'date_preset' => 'last_7d', 'time_increment' => 1, 'limit' => 200], $a['token']);
        if ($ok && is_array($d)) {
            $days = [];
            foreach ((array)($d['data'] ?? []) as $r) {
                $day = substr((string)($r['date_start'] ?? ''), 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                    continue;
                }
                $conv = 0;
                foreach ((array)($r['actions'] ?? []) as $x) {
                    $conv += ($x['action_type'] ?? '') === PM_SX_AD_CONV_ACTION ? (int)$x['value'] : 0;
                }
                $sp = (float)($r['spend'] ?? 0);
                $days[$day][mb_substr((string)($r['campaign_name'] ?? 'campaign'), 0, 80)] = ['spend' => $sp, 'spend_mwk' => round(pm_to_mwk($sp, $cur)), 'cur' => $cur, 'reach' => (int)($r['reach'] ?? 0),
                    'clicks' => (int)($r['clicks'] ?? 0), 'conversations' => $conv, 'src' => 'api'];
            }
            if ($days) {
                pm_update('social_ads_results', function (array $all) use ($brand, $days) {
                    foreach ($days as $day => $camps) {
                        foreach ((array)($all[$brand][$day] ?? []) as $k => $r) { // replace Facebook rows for that day, keep typed-in rows
                            if (($r['src'] ?? 'api') === 'api') {
                                unset($all[$brand][$day][$k]);
                            }
                        }
                        $all[$brand][$day] = array_merge((array)($all[$brand][$day] ?? []), $camps);
                    }
                    foreach (array_keys((array)($all[$brand] ?? [])) as $k) {
                        if ($k < date('Y-m-d', strtotime('-400 days'))) {
                            unset($all[$brand][$k]);
                        }
                    }
                    ksort($all[$brand]);
                    return $all;
                }, fn() => []);
            }
        } else {
            $err = (string)$d;
        }
    }
    $s = pm_sx_ads_sum($brand, 7);
    $src = $s['api_spend'] > 0 || $s['api_conv'] > 0 ? ($s['manual_spend'] > 0 || $s['manual_conv'] > 0 ? 'both' : 'api') : ($s['manual_spend'] > 0 || $s['manual_conv'] > 0 ? 'manual' : 'none');
    return ['source' => $src, 'connected' => !empty($a['ready']), 'error' => $err, 'spend_mwk' => (int)round($s['spend']), 'conversations' => (int)$s['conversations'], 'clicks' => (int)$s['clicks'], 'reach' => (int)$s['reach'],
        'cost_per_conversation' => $s['conversations'] > 0 ? (int)round($s['spend'] / $s['conversations']) : null, 'cost_per_click' => $s['clicks'] > 0 ? round($s['spend'] / $s['clicks'], 1) : null,
        'manual_by' => $s['manual_by'], 'campaigns' => $s['campaigns'],
        'hints' => array_merge(pm_sx_ads_hints($s, $g), function_exists('pm_sx_ads_trend_hints') ? pm_sx_ads_trend_hints($s, pm_sx_ads_sum($brand, 7, 7), $g) : [])];
}

/** Every six hours at most: refresh the ad numbers when ads are connected. */
function pm_job_ads_results(string $brand): string
{
    if (!function_exists('pm_ads_cfg') || empty(pm_ads_cfg($brand)['ready'])) {
        return '';
    }
    $r = pm_ads_results($brand);
    return $r['error'] !== '' ? 'failed: ' . $r['error'] : 'spend MWK ' . number_format($r['spend_mwk']) . ', ' . $r['conversations'] . ' conversation(s)';
}

/** Typed-in ad results for when Facebook will not give them (spend in Kwacha, chats, qualified, signed). Stored as the owner's figures, never as Facebook's. */
function pm_do_ads_manual(string $vb): array
{
    $to = 'social&view=results';
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['day'] ?? '')) ? (string)$_POST['day'] : date('Y-m-d');
    $n = fn($k) => max(0, (int)preg_replace('/[^\d]/', '', (string)($_POST[$k] ?? '')));
    $spend = $n('spend');
    $chats = $n('chats');
    if ($spend === 0 && $chats === 0) {
        return ['msg' => 'Type the amount spent or the number of chats.', 'kind' => 'err', 'to' => $to];
    }
    $by = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
    pm_update('social_ads_results', function (array $all) use ($vb, $day, $spend, $chats, $n, $by) {
        $all[$vb][$day]['manual'] = ['spend' => $spend, 'spend_mwk' => $spend, 'cur' => 'MWK', 'conversations' => $chats, 'qualified' => $n('qualified'), 'signed' => $n('signed'),
            'reach' => 0, 'clicks' => 0, 'src' => 'manual', 'by' => $by, 'at' => date('Y-m-d H:i')];
        ksort($all[$vb]);
        return $all;
    }, fn() => []);
    return ['msg' => 'Saved as entered by ' . $by . ' (shown as your figures, not Facebook\'s).', 'kind' => 'ok', 'to' => $to];
}

/* ---------------- the weekly report ---------------- */

/** Latest numbers of a post: its newest snapshot (h24, d3 or d7), else null. */
function pm_sx_latest_snap(array $p): ?array
{
    foreach (['d7', 'd3', 'h24'] as $k) {
        if (!empty($p['metrics'][$k])) {
            return $p['metrics'][$k];
        }
    }
    return null;
}

/** The week in numbers, from stored data only (no AI, no invented figures): followers vs pace, posts, best/worst, reach/engagement, enquiries, comments, ads, cost, three next actions. */
function pm_social_week_report(string $brand): array
{
    $now = time();
    $g = pm_social_goals($brand);
    $sb = pm_social_scoreboard($brand);
    $posts = pm_sx_posts($brand);
    $fol = $sb['followers'];
    $pace = null;
    if ($g['followers_target'] > 0 && $g['followers_date'] !== '' && $fol['now'] !== null) {
        $daysLeft = max(1, (int)ceil((strtotime($g['followers_date'] . ' 23:59') - $now) / 86400));
        $need = max(0, $g['followers_target'] - $fol['now']);
        $pace = ['target' => $g['followers_target'], 'date' => $g['followers_date'], 'need' => $need, 'need_per_week' => (int)ceil($need / $daysLeft * 7), 'days_left' => $daysLeft,
            'on_track' => $fol['d7'] === null ? null : $fol['d7'] >= (int)ceil($need / $daysLeft * 7)];
    }
    $win = array_filter($posts, fn($p) => ($ts = strtotime((string)($p['when'] ?? ''))) && $ts > $now - 7 * 86400 && $ts <= $now && ($p['status'] ?? '') !== 'expired');
    $shipped = array_filter($posts, fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > $now - 7 * 86400);
    $reach = 0;
    $reachN = 0;
    $eng = ['reactions' => 0, 'comments' => 0, 'shares' => 0];
    foreach ($shipped as $p) {
        $m = pm_sx_latest_snap($p);
        if ($m) {
            foreach ($eng as $k => $_) {
                $eng[$k] += (int)($m[$k] ?? 0);
            }
            if (($m['reach'] ?? null) !== null) {
                $reach += (int)$m['reach'];
                $reachN++;
            }
        }
    }
    $leads = pm_social_funnel($brand, 7);
    $bySrc = array_slice(array_map(fn($b) => $b['leads'], $leads['by']['tag']), 0, 3, true);
    $byPost = [];
    foreach (array_slice($leads['by']['post'], 0, 3, true) as $pid => $b) {
        if ($pid !== '(none)') {
            $byPost[] = ['id' => $pid, 'label' => $leads['post_labels'][$pid] ?? $pid, 'leads' => $b['leads']];
        }
    }
    $resp = (array)$leads['response'];
    $ads = pm_ads_results($brand);
    $cost = pm_social_cost($brand, 7);
    $best = $sb['top5'][0] ?? null;
    $worst = $sb['bottom5'][0] ?? null;
    $pending = 0;
    foreach ($posts as $p) {
        if (($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) < $now - 7 * 86400 && pm_sx_pub_ts($p) > $now - 21 * 86400 && empty($p['metrics']['d7']) && empty($p['manual'])) {
            $pending++;
        }
    }
    $r = ['brand' => $brand, 'at' => date('Y-m-d H:i'), 'week' => date('o-\WW'),
        'followers' => ['now' => $fol['now'], 'd7' => $fol['d7'], 'd30' => $fol['d30'], 'ig' => $fol['ig'], 'pace' => $pace],
        'posts' => ['shipped' => count($shipped), 'calendar' => count($win), 'goal' => $g['posts_per_week']],
        'best' => $best, 'worst' => $worst, 'board_note' => $sb['note'],
        'reach' => ['reach' => $reachN ? $reach : null, 'reach_posts' => $reachN, 'src' => 'Facebook'] + $eng,
        'enquiries' => ['week' => $leads['total']['leads'], 'goal' => $g['enquiries_per_week'], 'month' => pm_sx_social_leads_count($brand, 30), 'month_goal' => $g['leads_per_month'],
            'by_source' => $bySrc, 'by_post' => $byPost, 'won' => $leads['total']['won']],
        'comments' => ['unanswered' => $resp['unanswered_over_2h'] ?? null, 'median_min' => $resp['median_min'] ?? null, 'pct_under_2h' => $resp['pct_under_2h'] ?? null],
        'ads' => $ads, 'cost' => $cost, 'numbers_missing' => $pending];
    $r['actions'] = pm_sx_next_actions($brand, $r, $sb, $g);
    return $r;
}

/** Three rule-based next steps, most important first. Each is a plain sentence built from the numbers above. */
function pm_sx_next_actions(string $brand, array $r, array $sb, array $g): array
{
    $a = [];
    $leadPillar = '';
    foreach (pm_social_pillars($brand) as $p) {
        if (preg_match('/lead|enquir|offer|free check|host sign|sign.?up/i', $p['name'])) {
            $leadPillar = $p['name'];
            break;
        }
    }
    $e = $r['enquiries'];
    if ($e['goal'] > 0 && $e['week'] < $e['goal']) {
        $a[] = 'Enquiries from social were ' . $e['week'] . ' this week against a goal of ' . $e['goal'] . ': next week make 2 of 5 posts ' . ($leadPillar !== '' ? 'the "' . $leadPillar . '" pillar' : 'enquiry posts') . ' with one clear WhatsApp or free-check call to action.';
    }
    $c = $r['comments'];
    if (($c['unanswered'] ?? 0) > 0 || ($c['median_min'] ?? 0) > 120) {
        $a[] = (($c['unanswered'] ?? 0) > 0 ? $c['unanswered'] . ' comment(s) have waited over 2 hours' : 'The median first reply took ' . $c['median_min'] . ' minutes')
            . ': answer comments and messages first thing each day (aim for under 2 hours, they are your warmest leads).';
    }
    $p = $r['posts'];
    if ($p['goal'] > 0 && $p['shipped'] < $p['goal']) {
        $a[] = 'Only ' . $p['shipped'] . ' of ' . $p['goal'] . ' planned posts went out: approve the drafts early in the week' . (!empty($r['posts']['calendar']) && $p['calendar'] > $p['shipped'] ? ' (some scheduled posts did not publish: check Plan)' : '') . '.';
    }
    $f = $r['followers']['pace'];
    if ($f && $f['on_track'] === false) {
        $a[] = 'Followers grew ' . ($r['followers']['d7'] >= 0 ? '+' : '') . $r['followers']['d7'] . ' against about +' . $f['need_per_week'] . ' a week needed for ' . number_format($f['target']) . ' by ' . $f['date'] . ': post more useful tips people save or share, and invite customers to follow the Page.';
    }
    if ($r['numbers_missing'] > 0) {
        $a[] = 'Type the reach and saves for ' . $r['numbers_missing'] . ' post(s) in Results > Manual results so they count in the scoreboard.';
    }
    if ($sb['enough'] && ($b = array_key_first($sb['groups']['pillar'])) && $b !== 'unplanned' && ($sb['groups']['pillar'][$b]['vs_avg'] ?? 0) >= 1.15) {
        $a[] = 'Your best pillar is "' . $b . '" (' . $sb['groups']['pillar'][$b]['vs_avg'] . 'x the average): plan one more of it next week.';
    }
    $ad = $r['ads'];
    if ($ad['hints']) {
        $a[] = $ad['hints'][0];
    }
    foreach (['Keep going: one useful post a day, and a question in each so people reply.', 'Ask every happy customer for a short quote you may use (with their permission): proof is your best post.', 'Check Results next Monday: with more weeks of numbers the scoreboard starts to rank what works.'] as $fill) {
        $a[] = $fill;
    }
    return array_slice($a, 0, 3);
}

/** The report as plain text (also the email body). Manual figures are labelled; gaps are stated. */
function pm_social_week_text(array $r): string
{
    $name = pm_brand_name($r['brand']);
    $n = fn($v) => $v === null ? 'no data' : number_format((float)$v);
    $sg = fn($v) => $v === null ? 'n/a' : ($v >= 0 ? '+' : '') . $v;
    $f = $r['followers'];
    $L = ["$name: social week report ({$r['week']})", ''];
    $L[] = 'Followers (Facebook): ' . $n($f['now']) . ', ' . $sg($f['d7']) . ' this week' . ($f['d7'] === null ? ' (no reading from 7 days ago yet)' : '')
        . ($f['pace'] ? '; about +' . $f['pace']['need_per_week'] . ' a week needed for ' . number_format($f['pace']['target']) . ' by ' . $f['pace']['date'] : '') . '.';
    $L[] = 'Posts: ' . $r['posts']['shipped'] . ' published (calendar had ' . $r['posts']['calendar'] . ($r['posts']['goal'] ? ', goal ' . $r['posts']['goal'] . ' a week' : '') . ').';
    if ($r['best']) {
        $L[] = 'Best post: "' . $r['best']['headline'] . '" (' . $r['best']['reason'] . ').';
    }
    if ($r['worst'] && (!$r['best'] || $r['worst']['id'] !== $r['best']['id'])) {
        $L[] = 'Weakest post: "' . $r['worst']['headline'] . '" (' . $r['worst']['reason'] . ').';
    }
    if (!$r['best']) {
        $L[] = ucfirst(trim($r['board_note'] ?: 'Not enough data yet to name a best or worst post.'));
    }
    $x = $r['reach'];
    $L[] = 'This week\'s posts so far: ' . $x['reactions'] . ' reactions, ' . $x['comments'] . ' comments, ' . $x['shares'] . ' shares' . ($x['reach'] !== null ? ', reach ' . number_format($x['reach']) . ' (Facebook, ' . $x['reach_posts'] . ' post(s))' : '; reach not available') . '.';
    $e = $r['enquiries'];
    $L[] = 'Enquiries from social: ' . $e['week'] . ' this week' . ($e['goal'] ? ' (goal ' . $e['goal'] . ')' : '') . ', ' . $e['month'] . ' in 30 days' . ($e['won'] ? ', ' . $e['won'] . ' signed' : '')
        . ($e['by_source'] ? '. By source: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($e['by_source']), $e['by_source'])) : '')
        . ($e['by_post'] ? '. Top post: "' . $e['by_post'][0]['label'] . '" (' . $e['by_post'][0]['leads'] . ')' : '') . '.';
    $c = $r['comments'];
    $L[] = $c['unanswered'] === null && $c['median_min'] === null ? 'Replies: no reply-time data yet.'
        : 'Replies: ' . ($c['unanswered'] ?? '?') . ' comment(s) unanswered over 2 hours; median first reply ' . ($c['median_min'] !== null ? $c['median_min'] . ' min' : 'n/a') . '.';
    $ad = $r['ads'];
    $lab = ['api' => 'from Facebook', 'manual' => 'entered by ' . ($ad['manual_by'] ?: 'owner'), 'both' => 'Facebook plus figures entered by ' . ($ad['manual_by'] ?: 'owner')][$ad['source']] ?? '';
    $L[] = $ad['source'] === 'none' ? 'Ads: no spend recorded.' : 'Ads (' . $lab . '): MWK ' . number_format($ad['spend_mwk']) . ' spent, ' . $ad['conversations'] . ' chat(s) started'
        . ($ad['cost_per_conversation'] !== null ? ', MWK ' . number_format($ad['cost_per_conversation']) . ' each' : '') . '.';
    $k = $r['cost'];
    $L[] = 'AI: ' . number_format($k['social_tokens']) . ' tokens for social (about MWK ' . number_format($k['token_cost_mwk']) . ', an estimate at MWK ' . $k['rate'] . ' per 1,000)'
        . ($k['cost_per_lead'] !== null ? '; cost per social enquiry about MWK ' . number_format($k['cost_per_lead']) . ' (ads + AI)' : '; no social enquiries to divide by') . '.';
    $L[] = '';
    $L[] = 'Next week:';
    foreach ($r['actions'] as $i => $a) {
        $L[] = ($i + 1) . '. ' . $a;
    }
    $L[] = '';
    $L[] = 'Full tables: Social > Results.';
    return implode("\n", $L);
}

/**
 * Mondays from 07:30 (and until Wednesday, if the app was off on Monday): one report per brand per week through pm_social_notify.
 * Stamped in the social state under report_week before sending, so a brand is never reported twice in one week.
 */
function pm_jobg_report_weekly(): string
{
    $t = (int)($GLOBALS['PM_SX_NOW'] ?? time()); // tests may set the clock
    $dow = (int)date('N', $t);
    if ($dow > 3 || ($dow === 1 && date('H:i', $t) < '07:30')) {
        return '';
    }
    $wk = date('o-\WW', $t);
    $sent = [];
    foreach (pm_brand_ids() as $b) {
        $was = pm_brand();
        pm_brand_set($b);
        try {
            $have = pm_sx_posts($b);
            if (!$have && !pm_social_cfg($b)['ready']) {
                continue; // nothing to report for this business
            }
            $claimed = false;
            pm_social_state_set(function (array $s) use ($b, $wk, &$claimed) {
                if (($s['report_week'][$b] ?? '') !== $wk) {
                    $s['report_week'][$b] = $wk;
                    $claimed = true;
                }
                return $s;
            });
            if ($claimed) {
                $r = pm_social_week_report($b);
                pm_social_notify((pm_brand_name($b)) . ': social report for the week', pm_social_week_text($r));
                $sent[] = $b;
            }
        } finally {
            pm_brand_set($was);
        }
    }
    return $sent ? 'report sent for ' . implode(', ', $sent) : '';
}

/* ---------------- today items, small panels ---------------- */

/** How far this week's social enquiries are from the goal, from Thursday on. */
function pm_today_goalgap(string $brand): array
{
    $g = pm_social_goals($brand);
    if ($g['enquiries_per_week'] <= 0 || (int)date('N') < 4 || !count(array_filter(pm_sx_posts($brand), fn($p) => ($p['status'] ?? '') === 'published'))) {
        return [];
    }
    $n = pm_sx_social_leads_count($brand, 7);
    return $n >= $g['enquiries_per_week'] ? [] : [['text' => "Social enquiries this week: $n of the goal of {$g['enquiries_per_week']}. Reply to open comments and add a clear call to action to the next post.",
        'href' => '?tab=social&view=results', 'urgency' => 1]];
}

/** Weekly nudge: posts whose reach/saves the API could not give and the owner has not typed in. */
function pm_today_numbers(string $brand): array
{
    $now = time();
    $n = count(array_filter(pm_sx_posts($brand), fn($p) => ($p['status'] ?? '') === 'published' && ($p['metrics_src'] ?? '') === 'manual' && empty($p['manual'])
        && pm_sx_pub_ts($p) < $now - 6 * 86400 && pm_sx_pub_ts($p) > $now - 28 * 86400));
    return $n ? [['text' => "Enter last week's numbers for $n post" . ($n === 1 ? '' : 's') . ' (Facebook did not give reach and saves).', 'href' => '?tab=social&view=results#manual', 'urgency' => (int)date('N') <= 2 ? 1 : 0]] : [];
}

/** A tiny inline line chart (no scripts, no libraries). */
function pm_sx_spark(array $vals, int $w = 96, int $h = 24): string
{
    $vals = array_values($vals);
    if (count($vals) < 2) {
        return '';
    }
    $min = min($vals);
    $max = max($vals);
    $rng = max(1, $max - $min);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i / (count($vals) - 1) * ($w - 2) + 1, 1) . ',' . round($h - 2 - ($v - $min) / $rng * ($h - 4), 1);
    }
    return '<svg class="spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Followers over time"><polyline fill="none" stroke="currentColor" stroke-width="1.6" points="' . implode(' ', $pts) . '"/></svg>';
}

/** Plan screen KPI cells: followers with 7-day change and a sparkline, and median first-reply time. Flat markup (the KPI grid styles every nested div). */
function pm_panel_plan_kpi_growth(string $vb, array $ctx = []): string
{
    $sb = pm_social_scoreboard($vb);
    $f = $sb['followers'];
    $h = '';
    if ($f['now'] !== null) {
        $d = $f['d7'];
        $h .= '<div><b>' . number_format($f['now']) . ($d !== null ? ' <small style="font-weight:500;color:' . ($d >= 0 ? 'var(--ok,#1a7f37)' : 'var(--danger,#b42318)') . '">' . ($d >= 0 ? '+' : '') . $d . ' in 7 days</small>' : '') . '</b>'
            . '<span>Facebook followers ' . pm_sx_spark(array_values($f['series'])) . ($d === null ? ' · 7-day change appears after a week of readings' : '') . '</span></div>';
    }
    if (function_exists('pm_response_stats')) {
        try {
            $rs = (array)pm_response_stats($vb);
            if (!empty($rs['n'])) {
                $h .= '<div><b>' . (int)$rs['median_min'] . ' min</b><span>median first reply' . (isset($rs['unanswered_over_2h']) ? ' · ' . (int)$rs['unanswered_over_2h'] . ' waiting over 2 h' : '') . '</span></div>';
            }
        } catch (Throwable) {
        }
    }
    return $h;
}

/** Progress bars for the goals: followers, enquiries this week, leads this month, posts this week. Empty until a goal is set. */
function pm_sx_goal_strip(string $vb): string
{
    $g = pm_social_goals($vb);
    $sb = pm_social_scoreboard($vb);
    $posts7 = count(array_filter(pm_sx_posts($vb), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - 7 * 86400));
    $bars = [];
    if ($g['followers_target'] > 0 && $sb['followers']['now'] !== null) {
        $bars[] = ['Followers', (int)$sb['followers']['now'], $g['followers_target'], $g['followers_date'] !== '' ? 'by ' . $g['followers_date'] : ''];
    }
    if ($g['enquiries_per_week'] > 0) {
        $bars[] = ['Enquiries this week', pm_sx_social_leads_count($vb, 7), $g['enquiries_per_week'], 'from social'];
    }
    if ($g['leads_per_month'] > 0) {
        $bars[] = ['Leads in 30 days', pm_sx_social_leads_count($vb, 30), $g['leads_per_month'], 'from social'];
    }
    if ($g['posts_per_week'] > 0) {
        $bars[] = ['Posts this week', $posts7, $g['posts_per_week'], 'published'];
    }
    if (!$bars) {
        return '';
    }
    $h = '<div class="card sx-card" style="padding:12px 16px"><b style="font-size:13px">Goals</b>';
    foreach ($bars as [$l, $v, $t, $note]) {
        $pct = (int)min(100, round($v / max(1, $t) * 100));
        $h .= '<div style="margin:8px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span style="min-width:150px;font-size:13px">' . pm_h($l) . '</span>'
            . '<span style="flex:1 1 120px;height:8px;border-radius:99px;background:var(--line,#e5e7eb);overflow:hidden"><i style="display:block;height:100%;width:' . $pct . '%;background:' . ($pct >= 100 ? 'var(--ok,#1a7f37)' : 'var(--accent,#2563eb)') . '"></i></span>'
            . '<span style="font-size:12px;color:var(--muted,#667085);min-width:170px">' . number_format($v) . ' of ' . number_format($t) . ' · ' . pm_h($note) . '</span></div>';
    }
    return $h . '</div>';
}

function pm_panel_growth_top_goal(string $vb, array $ctx = []): string
{
    return pm_sx_goal_strip($vb);
}
