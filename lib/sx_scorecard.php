<?php
/**
 * Measure, part 2: the scoreboard. Free, deterministic, age-normalised (only the 7-day snapshot of a post is ever scored, so a young post can
 * never look better or worse than an old one) and honest about small samples (a group needs 3 posts, the whole board needs 8, or it says
 * "not enough data yet" and ranks nothing). Also: learnings text for the planner, suggested pillar mix, best posting slots, and the focus to plan.
 */

const PM_SX_MIN_BUCKET = 3;   // posts a group needs before it may be compared
const PM_SX_MIN_BOARD = 8;    // scored posts before anything is ranked
const PM_SX_DAYPARTS = ['early' => [6, 9, 'Early 06-09'], 'lunch' => [11, 14, 'Lunch 11-14'], 'evening' => [17, 20, 'Evening 17-20'], 'late' => [20, 22, 'Late 20-22']];
const PM_SX_DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

/** Daypart key for an hour of the day, or 'other' (09-11, 14-17, night). */
function pm_sx_daypart(int $h): string
{
    foreach (PM_SX_DAYPARTS as $k => [$a, $b]) {
        if ($h >= $a && $h < $b) {
            return $k;
        }
    }
    return 'other';
}

/** A cheap fingerprint of the files the board is built from, so one request computes it once. */
function pm_sx_sig(): string
{
    $s = '';
    foreach (['social_posts', 'leads', 'social_metrics', 'history', 'social_goals', 'social_experiments', 'settings', 'social_ads_results'] as $n) {
        $f = PM_DATA . "/$n.json";
        clearstatcache(true, $f);
        $s .= is_file($f) ? filesize($f) . '.' . filemtime($f) . '|' : '-|';
    }
    return $s;
}

/** The scored rows for a brand: one per published post that has its 7-day numbers (API, or typed in by the owner). Also returns how many are still young. */
function pm_sx_rows(string $brand): array
{
    $posts = pm_sx_posts($brand);
    $series = function_exists('pm_sx_follower_series') ? pm_sx_follower_series($brand) : [];
    $nowF = $series ? end($series) : null;
    $byPost = function_exists('pm_sx_leads_by_post') ? pm_sx_leads_by_post($brand) : [];
    $rows = [];
    $pending = 0;
    foreach ($posts as $p) {
        if (($p['status'] ?? '') !== 'published') {
            continue;
        }
        $m = $p['metrics']['d7'] ?? null;
        $man = (array)($p['manual'] ?? []);
        $xn = function_exists('pm_x_numbers') ? pm_x_numbers($p) : null; // C2-A06: numbers the owner typed in for X
        $src = 'api';
        if (is_array($m)) {
            if (($p['metrics_src'] ?? '') === 'manual' && !empty($man['facebook'])) { // owner fills what the API would not give
                foreach (['saves', 'clicks', 'reach'] as $k) {
                    if (($m[$k] ?? null) === null && isset($man['facebook'][$k]) && $man['facebook'][$k] !== '') {
                        $m[$k] = (int)$man['facebook'][$k];
                        $src = 'api+manual';
                    }
                }
            }
        } elseif (!empty($man['facebook'])) {
            $m = array_map('intval', array_intersect_key($man['facebook'], array_flip(['reactions', 'comments', 'shares', 'saves', 'clicks'])));
            $m['reach'] = isset($man['facebook']['views']) ? (int)$man['facebook']['views'] : null;
            $src = 'manual';
        } elseif ($xn) { // nothing else scores this post: the X numbers do (marked as entered by the owner, from X)
            $m = $xn;
            $src = 'x';
        } else {
            $pending++;
            continue;
        }
        $manEnq = 0;
        foreach ($man as $mm) {
            $manEnq += (int)($mm['enquiries'] ?? 0);
        }
        $lead = $byPost[$p['id']] ?? ['leads' => 0, 'won' => 0];
        $m['enquiries'] = max($manEnq, (int)$lead['leads']); // an enquiry is counted once, from whichever source knows it
        $ts = pm_sx_pub_ts($p);
        $fol = null;
        foreach ($series as $day => $v) { // followers on the day it went out (the latest reading up to then, else the first one after)
            if (strtotime($day) <= $ts + 86400) {
                $fol = $v;
            } elseif ($fol === null) {
                $fol = $v;
                break;
            } else {
                break;
            }
        }
        $fol ??= $nowF;
        $eng = pm_social_eng($m);
        $rows[] = ['id' => $p['id'], 'headline' => (string)(($p['headline'] ?? '') ?: mb_substr((string)$p['caption'], 0, 50)), 'pillar' => (string)($p['pillar'] ?? '') ?: '(none)',
            'cta' => (string)($p['cta'] ?? ''), 'format' => (string)($p['format'] ?? ''), 'layout' => (string)($p['layout'] ?? ''), 'hook_pattern' => (string)($p['hook_pattern'] ?? ''),
            'hour' => $ts ? date('H', $ts) : '', 'time' => $ts ? date('H:i', $ts) : '', 'dow' => $ts ? (int)date('N', $ts) : 0, 'daypart' => $ts ? pm_sx_daypart((int)date('G', $ts)) : 'other',
            'when' => $ts ? date('Y-m-d', $ts) : '', 'eng' => $eng, 'followers' => $fol, 'per1000' => $fol ? round($eng / $fol * 1000, 1) : null, 'eng_rate' => $fol ? $eng / $fol * 100 : null,
            'leads' => (int)$lead['leads'], 'won' => (int)$lead['won'], 'src' => $src, 'reach' => $m['reach'] ?? null, 'unplanned' => false, 'exp' => (string)($p['exp'] ?? ''), 'arm' => (string)($p['arm'] ?? ''),
            'segment' => (string)(($p['segment'] ?? '') ?: (function_exists('pm_sx_segment') ? pm_sx_segment($p, $brand) : '')), 'x_eng' => $xn ? pm_social_eng($xn) : null];
    }
    foreach ((array)(pm_load('social_metrics', fn() => [])['_unplanned'][$brand] ?? []) as $u) { // native Page posts: pillar 'unplanned'
        $ts = (int)strtotime((string)($u['created'] ?? ''));
        $fol = $nowF;
        $rows[] = ['id' => 'u' . substr(md5((string)($u['created'] ?? '') . ($u['text'] ?? '')), 0, 8), 'headline' => (string)($u['text'] ?? ''), 'pillar' => 'unplanned', 'cta' => '', 'format' => '', 'layout' => '', 'hook_pattern' => '',
            'hour' => $ts ? date('H', $ts) : '', 'time' => $ts ? date('H:i', $ts) : '', 'dow' => $ts ? (int)date('N', $ts) : 0, 'daypart' => $ts ? pm_sx_daypart((int)date('G', $ts)) : 'other',
            'when' => $ts ? date('Y-m-d', $ts) : '', 'eng' => (float)($u['eng'] ?? 0), 'followers' => $fol, 'per1000' => $fol ? round(($u['eng'] ?? 0) / $fol * 1000, 1) : null,
            'eng_rate' => $fol ? ($u['eng'] ?? 0) / $fol * 100 : null, 'leads' => 0, 'won' => 0, 'src' => 'api', 'reach' => null, 'unplanned' => true, 'exp' => '', 'arm' => '', 'segment' => '', 'x_eng' => null];
    }
    return ['rows' => $rows, 'pending' => $pending];
}

/** Groups rows by one field: key => [n, avg_eng, per_1000_followers, leads, won, enough, vs_avg]. Best average first. */
function pm_sx_group(array $rows, string $field, float $overallAvg): array
{
    $g = [];
    foreach ($rows as $r) {
        $k = (string)($r[$field] ?? '');
        $k = $k === '' ? '(none)' : $k;
        $b = &$g[$k];
        $b ??= ['n' => 0, 'sum' => 0.0, 'p1000' => 0.0, 'np' => 0, 'leads' => 0, 'won' => 0];
        $b['n']++;
        $b['sum'] += $r['eng'];
        if ($r['per1000'] !== null) {
            $b['p1000'] += $r['per1000'];
            $b['np']++;
        }
        $b['leads'] += $r['leads'];
        $b['won'] += $r['won'];
        unset($b);
    }
    $out = [];
    foreach ($g as $k => $b) {
        $avg = $b['sum'] / $b['n'];
        $out[$k] = ['n' => $b['n'], 'avg_eng' => round($avg, 1), 'per_1000_followers' => $b['np'] ? round($b['p1000'] / $b['np'], 1) : null, 'leads' => $b['leads'], 'won' => $b['won'],
            'enough' => $b['n'] >= PM_SX_MIN_BUCKET, 'vs_avg' => $overallAvg > 0 ? round($avg / $overallAvg, 2) : null];
    }
    uasort($out, fn($a, $b) => [(int)$b['enough'], $b['avg_eng']] <=> [(int)$a['enough'], $a['avg_eng']]);
    return $out;
}

/** The scoreboard. Cached per request (fingerprint of the data files). */
function pm_social_scoreboard(string $brand): array
{
    static $cache = [];
    $sig = $brand . '|' . pm_sx_sig();
    if (isset($cache[$sig])) {
        return $cache[$sig];
    }
    $R = pm_sx_rows($brand);
    $rows = $R['rows'];
    $n = count($rows);
    $avg = $n ? array_sum(array_column($rows, 'eng')) / $n : 0.0;
    $fa = function_exists('pm_social_followers_all') ? pm_social_followers_all($brand) : ['fb' => null];
    $series = function_exists('pm_sx_follower_series') ? pm_sx_follower_series($brand) : [];
    $rates = array_filter(array_column($rows, 'eng_rate'), fn($x) => $x !== null);
    $leads28 = function_exists('pm_sx_social_leads_count') ? pm_sx_social_leads_count($brand, 28) : 0;
    $sb = [
        'brand' => $brand, 'n' => $n, 'pending' => $R['pending'], 'enough' => $n >= PM_SX_MIN_BOARD, 'avg_eng' => round($avg, 1),
        'followers' => ['now' => $fa['fb'], 'ig' => $fa['ig'], 'li' => $fa['li'], 'tt' => $fa['tt'], 'day' => $fa['day'],
            'd7' => function_exists('pm_social_follower_delta') ? pm_social_follower_delta($brand, 7) : null, 'd30' => function_exists('pm_social_follower_delta') ? pm_social_follower_delta($brand, 30) : null,
            'series' => array_slice($series, -30, null, true)],
        'eng_rate' => $rates ? round(array_sum($rates) / count($rates), 2) : null, // average engagement per post as % of followers
        'enq_per_100' => !empty($fa['fb']) ? round($leads28 / $fa['fb'] * 100, 2) : null, // enquiries from social in 28 days per 100 followers
        'groups' => [], 'top5' => [], 'bottom5' => [], 'heat' => [],
    ];
    foreach (['pillar', 'cta', 'format', 'layout', 'hook_pattern', 'hour', 'daypart', 'segment'] as $f) { // segment: the audience a post speaks to (C2-A05)
        $sb['groups'][$f] = pm_sx_group(array_values(array_filter($rows, fn($r) => $f !== 'segment' || !$r['unplanned'])), $f, $avg);
    }
    $sb['x'] = function_exists('pm_x_summary') ? ['n' => count(array_filter($rows, fn($r) => $r['x_eng'] !== null)), 'avg' => ($xs = array_filter(array_column($rows, 'x_eng'), fn($v) => $v !== null)) ? round(array_sum($xs) / count($xs), 1) : null] : ['n' => 0, 'avg' => null];
    $wk = [];
    foreach ($rows as $r) {
        $r['weekday'] = $r['dow'] ? PM_SX_DOW[$r['dow']] : '';
        $wk[] = $r;
    }
    $sb['groups']['weekday'] = pm_sx_group($wk, 'weekday', $avg);
    $sb['groups']['daypart'] = array_combine(array_map(fn($k) => PM_SX_DAYPARTS[$k][2] ?? 'Other hours', array_keys($sb['groups']['daypart'])), array_values($sb['groups']['daypart']));
    // weekday x daypart: n, mean, and the times used (for pm_social_slots)
    $heat = [];
    foreach ($rows as $r) {
        if (!$r['dow'] || $r['daypart'] === 'other') {
            continue;
        }
        $c = &$heat[$r['dow']][$r['daypart']];
        $c ??= ['n' => 0, 'sum' => 0.0, 'times' => []];
        $c['n']++;
        $c['sum'] += $r['eng'];
        $c['times'][$r['time']] = ($c['times'][$r['time']] ?? 0) + $r['eng'];
        unset($c);
    }
    foreach ($heat as $d => $parts) {
        foreach ($parts as $k => $c) {
            arsort($c['times']);
            $sb['heat'][$d][$k] = ['n' => $c['n'], 'avg' => round($c['sum'] / $c['n'], 1), 'sum' => $c['sum'], 'time' => (string)array_key_first($c['times']), 'enough' => $c['n'] >= PM_SX_MIN_BUCKET];
        }
    }
    if ($sb['enough']) { // never rank lucky posts: only with enough data
        $planned = array_values(array_filter($rows, fn($r) => !$r['unplanned']));
        usort($planned, fn($a, $b) => $b['eng'] <=> $a['eng']);
        $why = function (array $r) use ($avg) {
            $x = [$avg > 0 ? round($r['eng'] / $avg, 1) . 'x the average' : '', $r['pillar'], $r['format'], $r['cta'] !== '' ? $r['cta'] . ' call to action' : '', PM_SX_DAYPARTS[$r['daypart']][2] ?? ''];
            return implode(' · ', array_filter($x));
        };
        $pick = fn(array $list) => array_map(fn($r) => $r + ['reason' => $why($r)], $list);
        $sb['top5'] = $pick(array_slice($planned, 0, 5));
        $sb['bottom5'] = $pick(array_slice(array_reverse($planned), 0, 5));
    }
    $sb['note'] = $sb['enough'] ? '' : 'Not enough data yet: ' . $n . ' post' . ($n === 1 ? '' : 's') . ' with 7-day numbers (' . PM_SX_MIN_BOARD . ' are needed before anything is ranked).'
        . ($R['pending'] ? ' ' . $R['pending'] . ' more are still younger than 7 days or need their numbers typed in.' : '');
    return $cache[$sig] = $sb;
}

/** Pillars the brand really plans (the board also holds 'unplanned'): name => weight. */
function pm_sx_pillar_weights(string $brand): array
{
    $o = [];
    foreach (pm_social_pillars($brand) as $p) {
        $o[$p['name']] = (int)$p['weight'];
    }
    return $o;
}

/**
 * Suggested pillar mix: 70% of today's share + 30% of what performed, a 10% floor per pillar. Returns name => [old, new, factor, n, avg] (shares 0..1) or [] when there is not enough data.
 * Only pillars with 3+ scored posts count as evidence; the rest keep their share.
 */
function pm_sx_mix(string $brand): array
{
    $w = pm_sx_pillar_weights($brand);
    $sb = pm_social_scoreboard($brand);
    if (!$w || !$sb['enough']) {
        return [];
    }
    $tot = array_sum($w) ?: 1;
    $g = [];
    foreach ($sb['groups']['pillar'] as $k => $b) {
        $g[mb_strtolower($k)] = $b;
    }
    $c = $s = $data = [];
    foreach ($w as $name => $wt) {
        $c[$name] = $wt / $tot;
        $b = $g[mb_strtolower($name)] ?? null;
        if ($b && $b['enough'] && $b['avg_eng'] > 0) {
            $data[$name] = $b['avg_eng'];
        }
    }
    if (count($data) < 2) {
        return [];
    }
    $C = array_sum(array_intersect_key($c, $data));
    $sumAvg = array_sum($data);
    foreach ($c as $name => $share) {
        $s[$name] = isset($data[$name]) ? $data[$name] / $sumAvg * $C : $share;
    }
    $floor = min(0.10, 1 / count($c));
    $raw = [];
    foreach ($c as $name => $share) {
        $raw[$name] = 0.7 * $share + 0.3 * $s[$name];
    }
    $t = $raw;
    $pinned = [];
    for ($k = 0; $k < count($c); $k++) { // pillars under the floor sit exactly at it; the rest share what is left
        $low = array_filter($t, fn($v, $n) => !isset($pinned[$n]) && $v < $floor - 1e-9, ARRAY_FILTER_USE_BOTH);
        if (!$low) {
            break;
        }
        foreach ($low as $n => $_) {
            $pinned[$n] = $floor;
        }
        $free = array_diff_key($raw, $pinned);
        $left = 1 - $floor * count($pinned);
        $fs = array_sum($free) ?: 1;
        $t = $pinned;
        foreach ($free as $n => $v) {
            $t[$n] = $v / $fs * $left;
        }
    }
    $out = [];
    foreach ($c as $name => $_) {
        $v = $t[$name];
        $b = $g[mb_strtolower($name)] ?? null;
        $out[$name] = ['old' => $c[$name], 'new' => $v, 'factor' => max(0.7, min(1.3, $v / $c[$name])), 'n' => $b['n'] ?? 0, 'avg' => $b['avg_eng'] ?? null];
    }
    return $out;
}

/** Pillar => factor 0.7..1.3 the planner multiplies its weights by (1.0 for everything while there is not enough data). */
function pm_social_perf_weights(string $brand): array
{
    $mix = pm_sx_mix($brand);
    $out = [];
    foreach (pm_sx_pillar_weights($brand) as $name => $_) {
        $out[$name] = $mix ? round($mix[$name]['factor'], 3) : 1.0;
    }
    return $out;
}

/** Owner clicked "Apply suggested mix": the new weights go through the normal pillar save, nothing else changes. */
function pm_do_apply_weights(string $vb): array
{
    $to = 'social&view=results';
    $mix = pm_sx_mix($vb);
    if (!$mix) {
        return ['msg' => 'Not enough results yet to suggest a mix.', 'kind' => 'err', 'to' => $to];
    }
    $rows = [];
    foreach ($mix as $name => $m) {
        $rows[] = ['name' => $name, 'weight' => max(1, (int)round($m['new'] * 100))];
    }
    pm_social_pillars_save($vb, $rows);
    return ['msg' => 'Content mix updated: ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . $r['weight'], $rows)) . '. You can change it any time under Accounts.', 'kind' => 'ok', 'to' => $to];
}

/** At most ~120 tokens of plain text the planner can use: what worked, what did not, what was tested. '' when there is nothing honest to say. */
function pm_social_learnings(string $brand): string
{
    $sb = pm_social_scoreboard($brand);
    $parts = [];
    if ($sb['enough']) {
        $pick = function (string $field, bool $best) use ($sb) {
            foreach ($best ? $sb['groups'][$field] : array_reverse($sb['groups'][$field], true) as $k => $b) {
                if ($b['enough'] && $k !== '(none)' && $k !== 'unplanned' && $b['vs_avg'] !== null && ($best ? $b['vs_avg'] >= 1.15 : $b['vs_avg'] <= 0.85)) {
                    return [$k, $b];
                }
            }
            return null;
        };
        $bp = $pick('pillar', true);
        $wp = $pick('pillar', false);
        if ($bp) {
            $parts[] = 'Best pillar ' . $bp[0] . ' (n=' . $bp[1]['n'] . ', ' . $bp[1]['vs_avg'] . 'x average)';
        }
        if ($wp && (!$bp || $wp[0] !== $bp[0])) {
            $parts[] = 'weak ' . $wp[0] . ' (n=' . $wp[1]['n'] . ', ' . $wp[1]['vs_avg'] . 'x)';
        }
        $line = implode('; ', $parts);
        $parts = $line !== '' ? [$line . '.'] : [];
        if ($c = $pick('cta', true)) {
            $parts[] = 'Best call to action: ' . $c[0] . '.';
        }
        if ($c = $pick('format', true)) {
            $parts[] = 'Best format: ' . $c[0] . '.';
        }
        if ($c = $pick('daypart', true)) {
            $parts[] = 'Best time: ' . $c[0] . '.';
        }
        if (($c = $pick('segment', true)) && !in_array($c[0], ['All businesses', 'General'], true)) {
            $parts[] = 'Best audience: ' . $c[0] . '.';
        }
        $avoid = [];
        foreach (['cta', 'format'] as $f) {
            foreach ($sb['groups'][$f] as $k => $b) {
                if ($b['enough'] && $k !== '(none)' && $b['vs_avg'] !== null && $b['vs_avg'] <= 0.7) {
                    $avoid[] = "$k $f (" . $b['vs_avg'] . 'x)';
                }
            }
        }
        if ($avoid) {
            $parts[] = 'Avoid: ' . implode(', ', array_slice($avoid, 0, 2)) . '.';
        }
    }
    if (function_exists('pm_experiment_learnings')) {
        foreach (array_slice(pm_experiment_learnings($brand), 0, 2) as $l) {
            $parts[] = 'Tested: ' . $l;
        }
    }
    $txt = trim(implode(' ', $parts));
    if (mb_strlen($txt) > 480) { // about 120 tokens
        $txt = mb_substr($txt, 0, 480);
        $txt = (string)preg_replace('/[^.]*$/u', '', $txt) ?: $txt;
    }
    return $txt;
}

/* ---------------- best times ---------------- */

/** The Malawi prior: brand => [[dow, 'HH:MM'], ...] in the order they are offered when there is no data. */
function pm_sx_prior_slots(string $brand): array
{
    return $brand === 'travel' ? [[5, '18:30'], [6, '12:00'], [4, '18:30'], [7, '12:00']] : [[2, '07:30'], [3, '12:30'], [4, '17:30']];
}

/** Default clock time for a daypart in a brand. */
function pm_sx_part_time(string $brand, string $part): string
{
    return ['early' => '07:30', 'lunch' => $brand === 'travel' ? '12:00' : '12:30', 'evening' => $brand === 'travel' ? '18:30' : '17:30', 'late' => '20:30'][$part] ?? '12:30';
}

/** The prior as a set of cells: "dow|daypart" => 'HH:MM'. */
function pm_sx_prior_cells(string $brand): array
{
    $o = [];
    foreach (pm_sx_prior_slots($brand) as [$d, $t]) {
        $o[$d . '|' . pm_sx_daypart((int)substr($t, 0, 2))] = $t;
    }
    return $o;
}

/**
 * Every weekday x daypart cell with its score, best first: key => [dow, time, score, n, src, key].
 * score = (sum + 3 x prior) / (n + 3): few posts means the Malawi prior decides, more posts means the Page's own results do.
 * When visitors' comment times are known (20+ comments in the cached feed) a cell where they comment gets up to +20%.
 */
function pm_sx_slot_cells(string $brand, ?array $sb = null): array
{
    $sb ??= pm_social_scoreboard($brand);
    $priorCells = pm_sx_prior_cells($brand);
    $hist = [];
    $tot = 0;
    try {
        foreach ((array)(pm_fb_feed($brand)['items'] ?? []) as $it) { // when visitors comment (cached feed: no extra call)
            foreach ((array)($it['comment_list'] ?? []) as $cm) {
                $ts = (int)strtotime((string)($cm['at'] ?? ''));
                if (!$ts || !empty($cm['ours'])) {
                    continue;
                }
                $part = pm_sx_daypart((int)date('G', $ts));
                if ($part !== 'other') {
                    $hist[(int)date('N', $ts) . '|' . $part] = ($hist[(int)date('N', $ts) . '|' . $part] ?? 0) + 1;
                    $tot++;
                }
            }
        }
    } catch (Throwable) {
    }
    $hmax = $hist ? max($hist) : 0;
    $avg = $sb['avg_eng'] > 0 ? $sb['avg_eng'] : 1.0;
    $cells = [];
    foreach (PM_SX_DOW as $d => $_) {
        foreach (array_keys(PM_SX_DAYPARTS) as $part) {
            $key = "$d|$part";
            $h = $sb['heat'][$d][$part] ?? null;
            $n = $h['n'] ?? 0;
            $sum = $h['sum'] ?? 0.0;
            $pv = $avg * (isset($priorCells[$key]) ? 1.2 : 1.0);
            $score = ($sum + 3 * $pv) / ($n + 3);
            if ($tot >= 20 && $hmax > 0) {
                $score *= 1 + 0.2 * (($hist[$key] ?? 0) / $hmax);
            }
            $cells[$key] = ['dow' => $d, 'time' => ($h['time'] ?? '') ?: ($priorCells[$key] ?? pm_sx_part_time($brand, $part)), 'score' => round($score, 2), 'n' => $n,
                'src' => $n >= PM_SX_MIN_BUCKET ? 'data' : 'prior', 'key' => $key];
        }
    }
    uasort($cells, fn($a, $b) => [$b['score'], $a['dow']] <=> [$a['score'], $b['dow']]);
    return $cells;
}

/**
 * Posting slots, best first: 3 of the best cells (a different weekday each while there are some) and one weekly exploration slot.
 * Rows: dow 1-7, time HH:MM, score, n (posts behind it), src prior|data|explore. With nothing measured yet: the Malawi habit
 * (ProManaged Tue-Thu 07:30 / 12:30 / 17:30, Travel Thu-Sun 18:30 / 12:00).
 */
function pm_social_slots(string $brand): array
{
    $sb = pm_social_scoreboard($brand);
    if (!$sb['n']) {
        $out = [];
        foreach (array_slice(pm_sx_prior_slots($brand), 0, 3) as [$d, $t]) {
            $out[] = ['dow' => $d, 'time' => $t, 'score' => 1.2, 'n' => 0, 'src' => 'prior'];
        }
        $ex = pm_sx_explore($brand, array_map(fn($s) => $s['dow'] . '|' . pm_sx_daypart((int)substr($s['time'], 0, 2)), $out), $sb);
        return $ex ? array_merge($out, [$ex]) : $out;
    }
    $out = [];
    $days = [];
    foreach (pm_sx_slot_cells($brand, $sb) as $c) {
        if (count($out) >= 3) {
            break;
        }
        if (!isset($days[$c['dow']])) {
            $days[$c['dow']] = 1;
            $out[] = $c;
        }
    }
    $used = array_column($out, 'key');
    foreach ($out as &$o) {
        unset($o['key']);
    }
    unset($o);
    $ex = pm_sx_explore($brand, $used, $sb);
    return $ex ? array_merge($out, [$ex]) : $out;
}

/** The weekly exploration slot: a cell with few or no posts, rotating by ISO week so every cell gets a turn. */
function pm_sx_explore(string $brand, array $usedKeys, array $sb): ?array
{
    $cand = [];
    foreach ([2, 3, 4, 5, 6, 7, 1] as $d) { // working days / weekend first, Monday last
        foreach (['evening', 'lunch', 'early', 'late'] as $part) {
            $key = "$d|$part";
            if (!in_array($key, $usedKeys, true) && ($sb['heat'][$d][$part]['n'] ?? 0) < 2) {
                $cand[] = [$d, $part];
            }
        }
    }
    if (!$cand) {
        return null;
    }
    [$d, $part] = $cand[((int)date('W') + ($brand === 'travel' ? 1 : 0)) % count($cand)];
    return ['dow' => $d, 'time' => pm_sx_part_time($brand, $part), 'score' => 0.0, 'n' => (int)($sb['heat'][$d][$part]['n'] ?? 0), 'src' => 'explore'];
}

/** The focus the planner should use (a PM_SOCIAL_FOCUS key): enquiries below goal -> leads, low engagement -> tips, the Director's pick, else mix. */
function pm_social_focus_for(string $brand): string
{
    $valid = fn($k) => is_string($k) && defined('PM_SOCIAL_FOCUS') && isset(PM_SOCIAL_FOCUS[$k]);
    try {
        $published28 = count(array_filter(pm_sx_posts($brand), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - 28 * 86400));
        $g = function_exists('pm_social_goals') ? pm_social_goals($brand) : ['enquiries_per_week' => 0];
        if ($published28 >= 4 && (int)$g['enquiries_per_week'] > 0 && function_exists('pm_sx_social_leads_count')
            && pm_sx_social_leads_count($brand, 7) < (int)$g['enquiries_per_week'] && $valid('leads')) {
            return 'leads';
        }
        $sb = pm_social_scoreboard($brand);
        if ($sb['enough'] && $sb['eng_rate'] !== null && $sb['eng_rate'] < 0.5 && ($sb['followers']['now'] ?? 0) >= 100 && $valid('tips')) {
            return 'tips';
        }
        $d = (array)(pm_load('director', fn() => [])[$brand] ?? []);
        if ($valid($d['social_focus'] ?? null) && ($d['social_at'] ?? '') >= date('Y-m-d', strtotime('-3 days'))) {
            return (string)$d['social_focus'];
        }
    } catch (Throwable) {
    }
    return 'mix';
}
