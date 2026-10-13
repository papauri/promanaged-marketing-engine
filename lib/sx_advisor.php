<?php
/**
 * sx_advisor — what is selling, for one business, and what to do next.
 *
 * The model (plain PHP, no AI, cached per day in data/advisor.json): every lead of the business is counted under five things about it (the kind of buyer,
 * the town, where it came from, the offer that brought it, the channel of our first message) with how far it got: worked (we wrote to them, or they wrote
 * to us), answered, proposal, won. A rate from a handful of leads lies, so each rate is pulled towards a prior: the same kind of buyer or town in ALL the
 * other businesses (that is how one business's lessons reach another), or else this business's own average. Each value then gets a verdict:
 * double down, drop, keep testing, or untested (in the targeting study, never tried). Why deals were lost is counted too.
 *
 * It steers the agents by itself: the scouts' choice of town (pm_adv_town_weights), the Qualifier (pm_learn_qualifier_line), the Director's prompt
 * (pm_adv_director_lines) and the offer ideas (pm_adv_offer_context). Once a week one AI call turns it into 3 to 5 next moves (pm_adv_moves), with
 * rules instead when the AI is not set up. Shown on Sales > Targeting ("What is selling, and what to do next") and in one line on Home.
 * Handlers: POST action=social_ext&do=adv_refresh | adv_offer.
 */

const PM_ADV_DIMS = ['kind' => 'Kind of buyer', 'town' => 'Town', 'source' => 'Where they came from', 'offer' => 'Offer', 'channel' => 'First message by'];
const PM_ADV_MIN = 6;   // leads worked before a value can be told to double down or drop
const PM_ADV_K = 8;     // strength of the prior, in leads: a value needs about this many leads before its own rate outweighs the prior
const PM_ADV_VERDICTS = ['double_down' => ['Double down', 'ok'], 'drop' => ['Drop', 'bad'], 'testing' => ['Keep testing', ''], 'untested' => ['Not tried yet', 'info']];

/* ---------------- counting ---------------- */

/** Where a lead came from, in plain words. */
function pm_adv_source(array $l): string
{
    $s = trim((string)($l['source'] ?? ''));
    if ($s === '') {
        $s = is_array($l['src'] ?? null) ? (string)($l['src']['kind'] ?? 'scout') : 'scout';
    }
    if (str_starts_with((string)($l['src_tag'] ?? ''), 'offer-')) {
        return 'An offer post';
    }
    return ['facebook' => 'Facebook', 'web' => 'Website form', 'scout' => 'Found by the agents', 'directory' => 'Directory lists', 'social' => 'Found on social media', 'watch' => 'New in town (watcher)',
        'manual' => 'Added by hand', 'whatsapp' => 'WhatsApp', 'referral' => 'Referral', 'import' => 'Imported'][$s] ?? ucfirst($s);
}

/** The offer (lead post) that brought a lead, by title; '' when none. $offers: id => title. */
function pm_adv_offer_of(array $l, array $offers): string
{
    $t = (string)($l['src_tag'] ?? '');
    if (!str_starts_with($t, 'offer-')) {
        return '';
    }
    $id = substr($t, 6);
    foreach ($offers as $oid => $title) {
        if ($oid !== '' && (str_starts_with($id, (string)$oid) || str_starts_with((string)$oid, substr($id, 0, 10)))) {
            return $title;
        }
    }
    return 'An offer';
}

/** Did we write to them first, and by which channel? '' for leads that wrote to us or that we never wrote to. */
function pm_adv_channel(array $l): string
{
    if (in_array($l['source'] ?? '', ['facebook', 'web'], true) || str_starts_with((string)($l['src_tag'] ?? ''), 'offer-')) {
        return '';
    }
    $em = (string)(((array)($l['sent'] ?? []))[0] ?? '');
    $wa = (string)(((array)($l['wa_sent'] ?? []))[0] ?? '');
    if ($em === '' && $wa === '') {
        return '';
    }
    return $wa !== '' && ($em === '' || strcmp($wa, $em) < 0) ? 'WhatsApp' : 'Email';
}

/** How far a lead got: [worked, answered, proposal, won, wrote to us first]. A lead nobody wrote to and that never wrote is not worked yet. */
function pm_adv_outcome(array $l): array
{
    $st = (string)($l['status'] ?? '');
    $inbound = in_array($l['source'] ?? '', ['facebook', 'web'], true) || str_starts_with((string)($l['src_tag'] ?? ''), 'offer-');
    $replied = pm_lead_replied($l) || in_array($st, ['replied', 'proposal', 'won'], true);
    $won = $st === 'won';
    $proposal = $won || $st === 'proposal' || !empty($l['proposal_sent_at']);
    $worked = $inbound || $replied || !empty($l['sent']) || !empty($l['wa_sent']) || !empty($l['last_contacted']) || in_array($st, ['contacted', 'lost'], true);
    return [$worked, $replied, $proposal, $won, $inbound];
}

/** The five values a lead is counted under (empty ones are skipped). */
function pm_adv_values(array $l, array $offers): array
{
    $town = trim((string)($l['city'] ?? ''));
    return array_filter(['kind' => function_exists('pm_lead_group') ? pm_lead_group($l) : trim((string)($l['type'] ?? '')), 'town' => $town !== '' ? mb_convert_case($town, MB_CASE_TITLE) : '',
        'source' => pm_adv_source($l), 'offer' => pm_adv_offer_of($l, $offers), 'channel' => pm_adv_channel($l)], fn($v) => trim((string)$v) !== '');
}

/** Raw counts per business: brand => ['dims' => [dim => [value => [n, replied, proposal, won, inbound]]], 'all' => [...], 'lost' => [reason => n]]. One pass over all leads. */
function pm_adv_counts(?array $leads = null): array
{
    $offers = [];
    foreach (function_exists('pm_lp_all') ? pm_lp_all() : [] as $o) {
        $offers[(string)($o['id'] ?? '')] = (string)($o['title'] ?? 'An offer');
    }
    $out = [];
    foreach ($leads ?? pm_leads_read() as $l) {
        $b = (string)($l['brand'] ?? 'promanaged');
        if (($l['status'] ?? '') === 'optout') {
            continue;
        }
        $out[$b] ??= ['dims' => [], 'all' => [0, 0, 0, 0, 0], 'lost' => [], 'leads' => 0];
        $out[$b]['leads']++;
        if (($l['status'] ?? '') === 'lost') {
            $r = (string)($l['lost_reason'] ?? '') ?: 'other';
            $out[$b]['lost'][$r] = ($out[$b]['lost'][$r] ?? 0) + 1;
        }
        [$worked, $replied, $proposal, $won, $inbound] = pm_adv_outcome($l);
        if (!$worked) {
            continue;
        }
        $add = function (array &$row) use ($replied, $proposal, $won, $inbound) {
            $row[0]++;
            $row[1] += $replied ? 1 : 0;
            $row[2] += $proposal ? 1 : 0;
            $row[3] += $won ? 1 : 0;
            $row[4] += $inbound ? 1 : 0;
        };
        $add($out[$b]['all']);
        foreach (pm_adv_values($l, $offers) as $d => $v) {
            $out[$b]['dims'][$d][$v] ??= [0, 0, 0, 0, 0];
            $add($out[$b]['dims'][$d][$v]);
        }
    }
    return $out;
}

/* ---------------- the model ---------------- */

/** "Answered" for one value: replies, with a win counting as a strong answer (a won deal is worth two replies). */
function pm_adv_hits(array $c): float
{
    return $c[1] + $c[3];
}

/**
 * The model for one business: ['at', 'leads', 'overall' => [n, replied, proposal, won, rate], 'dims' => [dim => [value => row]], 'lost' => [label => n]].
 * row: n, replied, proposal, won, inbound, rate (own), smooth (pulled towards the prior), prior, prior_from (others|business), lift (smooth / own average), verdict.
 * $counts (pm_adv_counts) can be passed in to judge many businesses from one pass.
 */
function pm_adv_build(string $brand, ?array $counts = null): array
{
    $counts ??= pm_adv_counts();
    $me = $counts[$brand] ?? ['dims' => [], 'all' => [0, 0, 0, 0, 0], 'lost' => [], 'leads' => 0];
    $all = $me['all'];
    $avg = $all[0] > 0 ? min(0.95, max(0.02, pm_adv_hits($all) / $all[0])) : 0.15; // with nothing yet, a modest starting guess
    $model = ['at' => date('Y-m-d H:i'), 'day' => date('Y-m-d'), 'leads' => (int)$me['leads'], 'overall' => ['n' => $all[0], 'replied' => $all[1], 'proposal' => $all[2], 'won' => $all[3], 'rate' => round($avg, 3)], 'dims' => [], 'lost' => []];
    $amp = fn(string $x) => str_replace('&', ' and ', $x);
    $same = fn(string $dim, string $a, string $b) => in_array($dim, ['kind', 'town'], true) ? (function_exists('pm_study_same') ? pm_study_same($amp($a), $amp($b)) : strcasecmp($a, $b) === 0) : strcasecmp($a, $b) === 0;
    // what the other businesses saw is itself pulled towards this business's average, so a few of their leads cannot set the prior on their own
    $pooled = fn(float $ph, int $pn) => $pn >= PM_ADV_MIN ? min(0.95, max(0.02, ($ph + PM_ADV_K * $avg) / ($pn + PM_ADV_K))) : $avg;
    foreach (PM_ADV_DIMS as $dim => $label) {
        foreach ((array)($me['dims'][$dim] ?? []) as $v => $c) {
            $v = (string)$v;
            $pn = 0;
            $ph = 0.0;
            if ($dim !== 'offer') { // offers belong to one business; everything else can borrow from the others
                foreach ($counts as $ob => $oc) {
                    if ($ob === $brand) {
                        continue;
                    }
                    foreach ((array)($oc['dims'][$dim] ?? []) as $ov => $occ) {
                        if ($same($dim, $v, (string)$ov)) {
                            $pn += $occ[0];
                            $ph += pm_adv_hits($occ);
                        }
                    }
                }
            }
            $prior = $pooled($ph, $pn);
            $smooth = (pm_adv_hits($c) + PM_ADV_K * $prior) / ($c[0] + PM_ADV_K);
            $lift = $avg > 0 ? $smooth / $avg : 1.0;
            $verdict = 'testing';
            if (($c[0] >= PM_ADV_MIN && $lift >= 1.35 && $c[1] >= 2) || ($c[3] >= 2 && $lift >= 1.2)) { // a value that is nearly every lead cannot beat the average, so it is never told to double down
                $verdict = 'double_down';
            } elseif ($c[0] >= PM_ADV_MIN && $lift <= 0.65 && $c[3] === 0) {
                $verdict = 'drop';
            }
            $model['dims'][$dim][$v] = ['n' => $c[0], 'replied' => $c[1], 'proposal' => $c[2], 'won' => $c[3], 'inbound' => $c[4], 'rate' => round(pm_adv_hits($c) / max(1, $c[0]), 3),
                'smooth' => round($smooth, 3), 'prior' => round($prior, 3), 'prior_from' => $pn >= PM_ADV_MIN ? 'others' : 'business', 'prior_n' => $pn, 'lift' => round($lift, 2), 'verdict' => $verdict];
        }
    }
    // never tried: kinds and towns the business targets that no worked lead has yet
    $cfg = pm_agents_config($brand);
    $kinds = array_merge((array)($cfg['sectors'] ?? []), function_exists('pm_brand_targeting') ? array_column(pm_brand_targeting($brand)['segments'], 'name') : []);
    foreach (['kind' => $kinds, 'town' => (array)($cfg['cities'] ?? [])] as $dim => $want) {
        foreach ($want as $w) {
            $w = trim((string)$w);
            if ($w === '' || array_filter(array_keys((array)($model['dims'][$dim] ?? [])), fn($k) => $same($dim, (string)$k, $w))) {
                continue;
            }
            $pn = 0;
            $ph = 0.0;
            foreach ($counts as $ob => $oc) {
                foreach ($ob === $brand ? [] : (array)($oc['dims'][$dim] ?? []) as $ov => $occ) {
                    if ($same($dim, $w, (string)$ov)) {
                        $pn += $occ[0];
                        $ph += pm_adv_hits($occ);
                    }
                }
            }
            $prior = $pooled($ph, $pn);
            $model['dims'][$dim][mb_convert_case($w, MB_CASE_TITLE)] = ['n' => 0, 'replied' => 0, 'proposal' => 0, 'won' => 0, 'inbound' => 0, 'rate' => 0, 'smooth' => round($prior, 3), 'prior' => round($prior, 3),
                'prior_from' => $pn >= PM_ADV_MIN ? 'others' : 'business', 'prior_n' => $pn, 'lift' => round($avg > 0 ? $prior / $avg : 1, 2), 'verdict' => 'untested'];
        }
    }
    $order = ['double_down' => 0, 'testing' => 1, 'untested' => 2, 'drop' => 3];
    foreach ($model['dims'] as $dim => $rows) {
        uasort($rows, fn($a, $b) => [$order[$a['verdict']], -$a['smooth'], -$a['n']] <=> [$order[$b['verdict']], -$b['smooth'], -$b['n']]);
        $model['dims'][$dim] = $rows;
    }
    $labels = function_exists('pm_lost_reasons') ? pm_lost_reasons() : [];
    arsort($me['lost']);
    foreach ($me['lost'] as $k => $n) {
        $model['lost'][(string)($labels[$k] ?? ucfirst(str_replace('_', ' ', (string)$k)))] = (int)$n;
    }
    return $model;
}

/** The model, built at most once a day per business (data/advisor.json). $fresh rebuilds now. */
function pm_adv_model(string $brand, bool $fresh = false): array
{
    static $mem = [];
    if (!$fresh && isset($mem[$brand])) {
        return $mem[$brand];
    }
    $d = pm_load('advisor', fn() => []);
    if (!$fresh && ($d[$brand]['day'] ?? '') === date('Y-m-d')) {
        return $mem[$brand] = (array)$d[$brand];
    }
    $m = pm_adv_build($brand);
    pm_update('advisor', function (array $all) use ($brand, $m) {
        $all[$brand] = $m;
        return $all;
    }, fn() => []);
    return $mem[$brand] = $m;
}

/** Daily job: every business's model, from one pass over the leads. */
function pm_jobg_advisor(): string
{
    $have = pm_load('advisor', fn() => []);
    if (!array_filter(pm_brand_ids(), fn($b) => ($have[$b]['day'] ?? '') !== date('Y-m-d'))) {
        return ''; // once a day is enough
    }
    $counts = pm_adv_counts();
    $out = [];
    $models = [];
    foreach (pm_brand_ids() as $b) {
        $models[$b] = pm_adv_build($b, $counts);
        $dd = 0;
        foreach ($models[$b]['dims'] as $rows) {
            $dd += count(array_filter($rows, fn($r) => $r['verdict'] === 'double_down'));
        }
        $out[] = $b . ': ' . $models[$b]['overall']['n'] . ' worked, ' . $dd . ' to double down';
    }
    pm_update('advisor', fn(array $all) => array_replace($all, $models), fn() => []);
    return implode('; ', $out);
}

/* ---------------- lines for the agents ---------------- */

/** One value with its numbers, e.g. "Schools (4 of 9 answered, 1 won)". */
function pm_adv_fmt(string $v, array $r): string
{
    return $v . ' (' . ($r['n'] ? $r['replied'] . ' of ' . $r['n'] . ' answered' . ($r['won'] ? ', ' . $r['won'] . ' won' : '') : 'not tried; ' . ($r['prior_from'] === 'others' ? 'does well for our other businesses' : 'no results yet')) . ')';
}

/** Up to $max values of a dimension with a verdict. */
function pm_adv_pick(array $model, string $dim, string $verdict, int $max = 3): array
{
    return array_slice(array_filter((array)($model['dims'][$dim] ?? []), fn($r) => $r['verdict'] === $verdict), 0, $max, true);
}

/** Lines for the Director: what to double down on, what to drop, and why deals were lost. [] while there is nothing to say. */
function pm_adv_director_lines(string $brand): array
{
    $m = pm_adv_model($brand);
    $lines = [];
    foreach (PM_ADV_DIMS as $dim => $label) {
        foreach (['double_down' => 'Double down on', 'drop' => 'Drop'] as $vd => $say) {
            $p = pm_adv_pick($m, $dim, $vd);
            if ($p) {
                $lines[] = $say . ' (' . strtolower($label) . '): ' . implode('; ', array_map(fn($v, $r) => pm_adv_fmt((string)$v, $r), array_keys($p), $p));
            }
        }
    }
    if ($m['lost']) {
        $lines[] = 'Why deals were lost: ' . implode(', ', array_map(fn($k, $n) => "$k ($n)", array_keys(array_slice($m['lost'], 0, 4, true)), array_slice($m['lost'], 0, 4, true)));
    }
    return $lines;
}

/** Best and worst kinds of buyer and towns, as one sentence for the Qualifier; '' while nothing is proven. */
function pm_adv_qualifier_line(string $brand): string
{
    $m = pm_adv_model($brand);
    $parts = [];
    foreach (['kind' => 'kinds of business', 'town' => 'towns'] as $dim => $label) {
        $best = array_keys(pm_adv_pick($m, $dim, 'double_down'));
        $worst = array_keys(pm_adv_pick($m, $dim, 'drop'));
        if ($best) {
            $parts[] = 'the ' . $label . ' that answer and buy most are ' . implode(', ', $best);
        }
        if ($worst) {
            $parts[] = 'the ' . $label . ' that rarely answer are ' . implode(', ', $worst);
        }
    }
    return $parts ? ' Past results: ' . implode('; ', $parts) . '. Weigh them slightly when the evidence about a lead is otherwise equal.' : '';
}

/**
 * Weights for the scouts' towns: a town that answers gets more searches, one that does not gets fewer (never none: it is still tried now and then),
 * an untried town keeps a full share so new ground is explored. [] while no town has results (the plain rotation then decides).
 */
function pm_adv_town_weights(string $brand, array $cities): array
{
    $rows = (array)(pm_adv_model($brand)['dims']['town'] ?? []);
    if (!array_filter($rows, fn($r) => $r['n'] >= 3)) {
        return [];
    }
    $w = [];
    foreach ($cities as $c) {
        $x = 1.0;
        foreach ($rows as $v => $r) {
            if (function_exists('pm_study_same') ? pm_study_same((string)$v, (string)$c) : strcasecmp((string)$v, (string)$c) === 0) {
                $x = match ($r['verdict']) { 'double_down' => 2.0, 'drop' => 0.35, 'untested' => 1.0, default => max(0.6, min(1.6, (float)$r['lift'])) };
                break;
            }
        }
        $w[(string)$c] = $x;
    }
    return $w;
}

/** What the offer-idea writer should build on: how each offer did, and why deals were lost. */
function pm_adv_offer_context(string $brand): array
{
    $rows = [];
    foreach (function_exists('pm_lp_for_brand') ? pm_lp_for_brand($brand) : [] as $o) {
        $s = function_exists('pm_lp_stats') ? pm_lp_stats($o) : [];
        $rows[] = ['offer' => (string)($o['title'] ?? ''), 'posts_published' => (int)($s['published'] ?? 0), 'page_views' => (int)($s['views'] ?? 0), 'enquiries' => (int)($s['leads'] ?? 0) + (int)($s['keyword_comments'] ?? 0)];
    }
    usort($rows, fn($a, $b) => $b['enquiries'] <=> $a['enquiries']);
    $m = pm_adv_model($brand);
    return ['offers_so_far' => array_slice($rows, 0, 6), 'why_deals_were_lost' => array_slice($m['lost'], 0, 4, true),
        'buyers_that_answer' => array_keys(pm_adv_pick($m, 'kind', 'double_down'))];
}

/* ---------------- next moves ---------------- */

const PM_ADV_MOVES = ['double_down' => 'Double down', 'drop' => 'Drop', 'try_next' => 'Try next', 'new_offer' => 'New offer', 'fix' => 'Fix'];

/** Is there enough to learn from? Fewer than 15 leads worked is thin: the moves then lean on the other businesses and the targeting study. */
function pm_adv_thin(array $m): bool
{
    return (int)$m['overall']['n'] < 15;
}

/** Moves from the verdicts alone (no AI): used when the AI is not set up or its answer was not usable. */
function pm_adv_rule_moves(string $brand, array $m): array
{
    $moves = [];
    foreach (['kind', 'town', 'offer', 'channel', 'source'] as $dim) {
        foreach (pm_adv_pick($m, $dim, 'double_down', 1) as $v => $r) {
            $moves[] = ['type' => 'double_down', 'title' => 'More ' . strtolower(PM_ADV_DIMS[$dim]) . ': ' . $v, 'reason' => pm_adv_fmt((string)$v, $r) . ', against ' . round(100 * $m['overall']['rate']) . '% overall.',
                'action' => $dim === 'kind' || $dim === 'town' ? 'Give it more of the daily searches in the targeting below.' : 'Use it more this week.'];
        }
        foreach (pm_adv_pick($m, $dim, 'drop', 1) as $v => $r) {
            $moves[] = ['type' => 'drop', 'title' => 'Less ' . strtolower(PM_ADV_DIMS[$dim]) . ': ' . $v, 'reason' => pm_adv_fmt((string)$v, $r) . ', against ' . round(100 * $m['overall']['rate']) . '% overall.',
                'action' => $dim === 'kind' ? 'Take it out of the types to look for, or change the message to it.' : 'Spend less effort on it for now.'];
        }
    }
    foreach (array_slice(array_filter((array)($m['dims']['kind'] ?? []), fn($r) => $r['verdict'] === 'untested'), 0, 2, true) as $v => $r) {
        $moves[] = ['type' => 'try_next', 'title' => 'Try: ' . $v, 'reason' => pm_adv_fmt((string)$v, $r) . '.', 'action' => 'It is in the targeting already: the scouts reach it as searches come round.'];
    }
    if ($m['lost']) {
        $k = (string)array_key_first($m['lost']);
        $moves[] = ['type' => 'fix', 'title' => 'Answer the main reason deals are lost: ' . $k, 'reason' => $m['lost'][$k] . ' lost deal' . ($m['lost'][$k] === 1 ? '' : 's') . ' gave this reason.',
            'action' => 'Address it in the first message and the follow-ups, for example with the free first step.'];
    }
    return array_slice($moves, 0, 5);
}

/** The table the AI reads: the biggest values of each dimension with their numbers. */
function pm_adv_ai_table(array $m): array
{
    $t = [];
    foreach (PM_ADV_DIMS as $dim => $label) {
        foreach (array_slice((array)($m['dims'][$dim] ?? []), 0, 6, true) as $v => $r) {
            $t[$label][] = ['value' => (string)$v, 'worked' => $r['n'], 'answered' => $r['replied'], 'proposals' => $r['proposal'], 'won' => $r['won'], 'verdict' => PM_ADV_VERDICTS[$r['verdict']][0],
                'others' => $r['prior_from'] === 'others' ? round(100 * $r['prior']) . '% answer in our other businesses' : ''];
        }
    }
    return $t;
}

/**
 * This week's 3 to 5 next moves for a business: ['week', 'at', 'ai', 'thin', 'note', 'moves' => [['type','title','reason','action','offer'?]]].
 * One AI call a week (or when $force), with rules instead when the AI is not set up or fails. A new-offer move carries an offer that passed the same checks
 * as any offer typed by hand; numbers in a reason must be numbers from the table the AI was given.
 */
function pm_adv_moves(string $brand, bool $force = false): array
{
    $store = pm_load('advisor_moves', fn() => []);
    $week = date('o-\WW');
    if (!$force && ($store[$brand]['week'] ?? '') === $week && !empty($store[$brand]['moves'])) {
        return (array)$store[$brand];
    }
    $m = pm_adv_model($brand, $force);
    $thin = pm_adv_thin($m);
    $res = ['week' => $week, 'at' => date('Y-m-d H:i'), 'ai' => false, 'thin' => $thin, 'note' => '', 'moves' => []];
    $ready = function_exists('pm_agents_ready') && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']));
    if ($ready) {
        try {
            $brain = pm_brain($brand);
            $p = pm_brand_profile($brand);
            $cfg = pm_agents_config($brand);
            $tg = function_exists('pm_brand_targeting') ? pm_brand_targeting($brand) : ['segments' => [], 'skip' => [], 'angles' => []];
            $input = ['business' => pm_brand_name($brand), 'about' => (string)$brain['about'], 'offerings' => array_values((array)($cfg['offerings'] ?? [])),
                'true_facts' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$brain['facts']) ?: []))), 'free_first_step' => (string)$p['magnet'], 'sells_to' => (string)$p['sell_to'],
                'leads_worked' => $m['overall']['n'], 'answered' => $m['overall']['replied'], 'won' => $m['overall']['won'], 'results' => pm_adv_ai_table($m), 'why_deals_were_lost' => $m['lost'],
                'kinds_of_buyer_studied' => array_map(fn($s) => ['kind' => $s['name'], 'why' => $s['why'] ?? ''], array_slice($tg['segments'], 0, 6)), 'offers_so_far' => pm_adv_offer_context($brand)['offers_so_far']];
            $system = 'You are the growth advisor of a small business. From its real results, say what to do next to sell more: 3 to 5 moves, best first. '
                . 'Types: double_down (more of what answers or buys), drop (less of what does not), try_next (a kind of buyer, town or channel not tried yet), new_offer (a small free or low-risk offer that starts conversations), fix (something that loses deals). '
                . 'Every reason quotes the numbers you were given; never invent a number, a result, a price or a claim. With few leads worked (under 15), say so in "note" and lean on try_next and new_offer, using the studied kinds of buyer and what does well for our other businesses. '
                . 'A new_offer may only use the true facts, offerings and free first step; never the words best, cheapest, fastest, guaranteed or 24/7. Plain words. Reply with JSON only.';
            $user = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nJSON: {\"note\":\"one sentence on how much there is to learn from\",\"moves\":[{\"type\":\"double_down|drop|try_next|new_offer|fix\",\"title\":\"under 10 words\",\"reason\":\"one sentence with the numbers\",\"action\":\"one concrete thing to do this week\","
                . "\"offer\":{\"title\":\"only for new_offer: what is offered, under 8 words\",\"problem\":\"the problem it fixes\",\"fix\":\"what the business does about it\",\"keyword\":\"ONE capital word\"}}]}";
            $r = pm_agent_json(pm_claude($system, $user, false, 1600, 'write'));
            $numbers = mb_strtolower(json_encode($input, JSON_UNESCAPED_UNICODE)) . ' ' . mb_strtolower((string)$brain['facts']);
            $own = mb_strtolower(implode("\n", array_merge([pm_brand_name($brand), (string)$brain['about'], (string)$p['magnet']], (array)($cfg['offerings'] ?? []), [(string)$brain['facts']])));
            $clean = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : ''))), 0, $n);
            foreach ((array)($r['moves'] ?? []) as $mv) {
                if (!is_array($mv) || !isset(PM_ADV_MOVES[$mv['type'] ?? '']) || count($res['moves']) >= 5) {
                    continue;
                }
                $row = ['type' => (string)$mv['type'], 'title' => $clean($mv['title'] ?? '', 90), 'reason' => $clean($mv['reason'] ?? '', 260), 'action' => $clean($mv['action'] ?? '', 200)];
                if ($row['title'] === '' || pm_brand_unsourced_number($row['title'] . ' ' . $row['reason'] . ' ' . $row['action'], $numbers)) {
                    continue; // a number nobody gave it is an invention
                }
                if ($row['type'] === 'new_offer') { // a new offer must come with an offer that passes the same checks as one typed by hand
                    $o = is_array($mv['offer'] ?? null) && function_exists('pm_lp_clean') ? pm_lp_clean((array)$mv['offer'] + ['button' => 'whatsapp'], $brand) : [];
                    if (!$o || pm_lp_check($o) || pm_brand_unsourced_number(implode("\n", [$o['title'], $o['problem'], $o['fix']]), $own)) {
                        continue;
                    }
                    $row['offer'] = $o;
                }
                $res['moves'][] = $row;
            }
            $res['note'] = mb_substr(trim(strip_tags(is_scalar($r['note'] ?? null) ? (string)$r['note'] : '')), 0, 200);
            if (pm_brand_unsourced_number($res['note'], $numbers)) {
                $res['note'] = '';
            }
            $res['ai'] = (bool)$res['moves'];
        } catch (Throwable $e) {
            $res['note'] = 'The AI advice did not work this time (' . mb_substr($e->getMessage(), 0, 80) . '); these moves come from the numbers alone.';
        }
    }
    if (!$res['moves']) {
        $res['moves'] = pm_adv_rule_moves($brand, $m);
        $res['ai'] = false;
        if ($res['note'] === '') {
            $res['note'] = $ready ? 'These moves come from the numbers alone.' : 'The AI is not set up, so these moves come from the numbers alone.';
        }
    }
    if ($thin && !str_contains(strtolower($res['note']), 'few')) {
        $res['note'] = trim('Only ' . $m['overall']['n'] . ' lead' . ($m['overall']['n'] === 1 ? '' : 's') . ' worked so far, so treat this as a starting point. ' . $res['note']);
    }
    pm_update('advisor_moves', function (array $all) use ($brand, $res) {
        $all[$brand] = $res;
        return $all;
    }, fn() => []);
    return $res;
}

/** What the screens show: this week's saved moves, else last week's, else moves from the numbers alone. Never asks the AI (pages stay instant). */
function pm_adv_moves_saved(string $brand): array
{
    $mv = (array)(pm_load('advisor_moves', fn() => [])[$brand] ?? []);
    if (!empty($mv['moves'])) {
        return $mv + ['ai' => false, 'note' => '', 'at' => ''];
    }
    $m = pm_adv_model($brand);
    return ['week' => '', 'at' => '', 'ai' => false, 'thin' => pm_adv_thin($m), 'note' => 'The advisor has not written this week’s moves yet; these come from the numbers alone.', 'moves' => pm_adv_rule_moves($brand, $m)];
}

/** Scheduler job (per business): this week's moves, asked once a week on a weekday morning, so no page ever waits for the AI. */
function pm_job_advisor_moves(string $brand): string
{
    $h = (int)date('G');
    if ((int)date('N') > 5 || $h < 7 || $h > 17 || (pm_load('advisor_moves', fn() => [])[$brand]['week'] ?? '') === date('o-\WW')) {
        return '';
    }
    if (!function_exists('pm_agents_ready') || !pm_agents_ready()) {
        return '';
    }
    $r = pm_adv_moves($brand);
    return count($r['moves']) . ' next moves written' . ($r['ai'] ? '' : ' (from the numbers)');
}

/* ---------------- handlers ---------------- */

/** Ask the advisor again now (rebuilds the numbers too). */
function pm_do_adv_refresh(string $vb): array
{
    @set_time_limit(120);
    $r = pm_adv_moves($vb, true);
    return ['msg' => count($r['moves']) . ' next move' . (count($r['moves']) === 1 ? '' : 's') . ' ready.', 'kind' => 'ok', 'to' => 'targeting#advisor'];
}

/** "Make it an offer": a new-offer move goes to the offer ideas on the Lead posts screen, ready to pick and post. */
function pm_do_adv_offer(string $vb): array
{
    $i = (int)($_POST['i'] ?? -1);
    $mv = (array)((pm_load('advisor_moves', fn() => [])[$vb]['moves'] ?? [])[$i] ?? []);
    if (empty($mv['offer']['title'])) {
        return ['msg' => 'That move has no offer to make. Ask the advisor again.', 'kind' => 'err', 'to' => 'targeting#advisor'];
    }
    $o = (array)$mv['offer'];
    pm_update('offer_ideas', function (array $all) use ($vb, $o) {
        $rows = array_values(array_filter((array)($all[$vb]['rows'] ?? []), fn($r) => mb_strtolower((string)($r['title'] ?? '')) !== mb_strtolower((string)$o['title'])));
        array_unshift($rows, $o);
        $all[$vb] = ['at' => date('Y-m-d H:i'), 'rows' => array_slice($rows, 0, 6)];
        return $all;
    }, fn() => []);
    return ['msg' => '"' . $o['title'] . '" is in the offer ideas: pick it, change anything, and make its posts.', 'kind' => 'ok', 'to' => 'social&view=leadposts#ideas'];
}

/* ---------------- screens ---------------- */

/** The card on Sales > Targeting: the table of what sells, with verdicts, and this week's moves with their buttons. */
function pm_adv_card(string $brand, string $csrf): string
{
    $m = pm_adv_model($brand);
    $mv = pm_adv_moves_saved($brand);
    $hid = '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext">';
    $h = '<div class="card" id="advisor"><h2>What is selling, and what to do next</h2>';
    $o = $m['overall'];
    $h .= '<p class="hint mt-0">' . ($o['n'] ? $o['n'] . ' lead' . ($o['n'] === 1 ? '' : 's') . ' worked: ' . $o['replied'] . ' answered, ' . $o['proposal'] . ' got a proposal or offer, ' . $o['won'] . ' won. ' : 'No leads worked yet. ')
        . 'Each rate is pulled towards what our other businesses see until there is enough of its own, so a lucky week does not decide. Updated ' . pm_h(date('j M', strtotime((string)$m['at']))) . '.</p>';
    if ($mv['moves']) {
        $h .= '<h3 class="mt-2">This week' . ($mv['ai'] ? '' : ' <span class="muted t-sm">(from the numbers)</span>') . '</h3>' . ($mv['note'] !== '' ? '<p class="hint mt-0">' . pm_h($mv['note']) . '</p>' : '') . '<ol class="adv-moves">';
        foreach ($mv['moves'] as $i => $x) {
            $chip = ['double_down' => 'ok', 'drop' => 'bad', 'try_next' => 'info', 'new_offer' => 'info', 'fix' => 'warn'][$x['type']] ?? '';
            $h .= '<li><span class="chip ' . $chip . '">' . pm_h(PM_ADV_MOVES[$x['type']] ?? $x['type']) . '</span> <b>' . pm_h($x['title']) . '</b><br><span class="muted">' . pm_h($x['reason']) . '</span>'
                . ($x['action'] !== '' ? '<br>' . pm_h($x['action']) : '')
                . (!empty($x['offer']['title']) ? '<form method="post" class="inline">' . $hid . '<input type="hidden" name="do" value="adv_offer"><input type="hidden" name="i" value="' . (int)$i . '"><button class="btn small primary">Make it an offer: ' . pm_h((string)$x['offer']['title']) . '</button></form>' : '')
                . '</li>';
        }
        $h .= '</ol>';
    }
    $h .= '<form method="post" class="btns">' . $hid . '<input type="hidden" name="do" value="adv_refresh"><button class="btn small">Ask the advisor again</button><span class="hint">It writes them by itself once a week' . ($mv['at'] !== '' ? '; last on ' . pm_h((string)$mv['at']) : '') . '.</span></form>';
    $h .= '<details class="more mt-2"><summary>The numbers behind it</summary>';
    foreach (PM_ADV_DIMS as $dim => $label) {
        $rows = array_slice((array)($m['dims'][$dim] ?? []), 0, 8, true);
        if (!$rows) {
            continue;
        }
        $h .= '<h3>' . pm_h($label) . '</h3><table class="grid" data-nopage><thead><tr><th>' . pm_h($label) . '</th><th class="num">Worked</th><th class="num">Answered</th><th class="num">Won</th><th>Verdict</th><th>Others</th></tr></thead><tbody>';
        foreach ($rows as $v => $r) {
            [$vl, $vc] = PM_ADV_VERDICTS[$r['verdict']];
            $h .= '<tr><td>' . pm_h((string)$v) . '</td><td class="num">' . $r['n'] . '</td><td class="num">' . $r['replied'] . '</td><td class="num">' . $r['won'] . '</td><td><span class="chip ' . $vc . '">' . $vl . '</span></td>'
                . '<td class="t-sm muted">' . ($r['prior_from'] === 'others' ? round(100 * $r['prior']) . '% answer elsewhere (' . (int)$r['prior_n'] . ')' : '–') . '</td></tr>';
        }
        $h .= '</tbody></table>';
    }
    if ($m['lost']) {
        $h .= '<h3>Why deals were lost</h3><p>' . pm_h(implode(' · ', array_map(fn($k, $n) => "$k: $n", array_keys($m['lost']), $m['lost']))) . '</p>';
    }
    return $h . '</details></div>';
}

/** One line for Home: this week's first move, linking to the card. '' when there is no move. */
function pm_adv_home_line(string $brand): string
{
    $mv = pm_adv_moves_saved($brand); // never asks the AI from Home
    $x = $mv['moves'][0] ?? null;
    return $x ? '<p class="hint"><b>Next move:</b> ' . pm_h((string)$x['title']) . ' <a href="?tab=targeting&amp;brand=' . pm_h($brand) . '#advisor">See what is selling</a></p>' : '';
}
