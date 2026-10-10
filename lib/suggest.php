<?php
/**
 * Suggestions while typing in the "find a business" boxes (Leads, and Proposals > find a business).
 *
 * First, instantly and for free: businesses we already know by name, across every business in the app (leads, archived leads, businesses we sent a proposal to,
 * existing clients), best match first. A lead of the business on screen opens that lead; anything else fills the box so a normal search can run.
 * Then, after a short pause and only when the typed text is long enough: a web search (one AI call, cached for a week, limited per hour) for real businesses in Malawi
 * whose name matches. Those are labelled as coming from the web, are never saved by themselves, and only fill the box: the usual Find step still checks them.
 * Served by index.php as JSON: ?suggest=<text>&mode=local|web&brand=<id>.
 */

/** Lower-case letters and digits separated by single spaces, so "SUNBIRD cap." and "Sunbird Capital" compare. */
function pm_suggest_norm(string $s): string
{
    return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
}

/** How well a typed text matches a name: 0 starts with it, 1 every word typed starts a word of the name, 2 it appears inside, null no match (lower is better). */
function pm_suggest_rank(string $q, string $name): ?int
{
    $qn = pm_suggest_norm($q);
    $nn = pm_suggest_norm($name);
    if ($qn === '' || $nn === '') {
        return null;
    }
    if (str_starts_with($nn, $qn)) {
        return 0;
    }
    $words = explode(' ', $nn);
    $all = true;
    foreach (explode(' ', $qn) as $t) {
        $hit = false;
        foreach ($words as $w) {
            if (str_starts_with($w, $t)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $all = false;
            break;
        }
    }
    if ($all) {
        return 1;
    }
    return str_contains($nn, $qn) ? 2 : null;
}

/** Every business we already know by name: [['name','city','type','status','brand','src' lead|archive|proposal|client,'id','score']]. */
function pm_suggest_pool(): array
{
    $rows = [];
    $add = function (array $l, string $id, string $src) use (&$rows): void {
        $name = trim((string)($l['name'] ?? ''));
        if ($name !== '') {
            $rows[] = ['name' => mb_substr($name, 0, 100), 'city' => trim((string)($l['city'] ?? '')), 'type' => trim((string)($l['type'] ?? '')), 'status' => (string)($l['status'] ?? ''),
                'brand' => pm_brand_norm($l['brand'] ?? ''), 'src' => $src, 'id' => $id, 'score' => (int)($l['score'] ?? 0)];
        }
    };
    foreach (pm_leads() as $id => $l) {
        if (is_array($l)) {
            $add($l, (string)($l['id'] ?? $id), 'lead');
        }
    }
    foreach (function_exists('pm_leads_archive') ? pm_leads_archive() : [] as $id => $l) {
        if (is_array($l)) {
            $add($l, (string)($l['id'] ?? $id), 'archive');
        }
    }
    foreach ((array)pm_load('history', fn() => []) as $h) {
        if (is_array($h) && trim((string)($h['business'] ?? '')) !== '') {
            $add(['name' => $h['business'], 'city' => '', 'brand' => 'promanaged'], '', 'proposal');
        }
    }
    foreach (pm_brand_ids() as $b) {
        foreach ((array)(pm_agents_config($b)['existing_clients'] ?? []) as $c) {
            if (is_string($c) && trim($c) !== '') {
                $add(['name' => $c, 'city' => '', 'brand' => $b], '', 'client');
            }
        }
    }
    return $rows;
}

/** The words under a suggestion: what it is and where it comes from. */
function pm_suggest_meta(array $r, string $brand): string
{
    $bits = [];
    if ($r['src'] === 'lead') {
        $bits[] = $r['brand'] === $brand ? 'Your lead' : 'Lead of ' . pm_brand_name($r['brand']);
    } elseif ($r['src'] === 'archive') {
        $bits[] = 'Archived lead' . ($r['brand'] === $brand ? '' : ' of ' . pm_brand_name($r['brand']));
    } elseif ($r['src'] === 'proposal') {
        $bits[] = 'You sent a proposal';
    } else {
        $bits[] = 'Existing client' . ($r['brand'] === $brand ? '' : ' of ' . pm_brand_name($r['brand']));
    }
    foreach ([$r['city'], $r['type'], in_array($r['src'], ['lead'], true) ? str_replace('_', ' ', $r['status']) : ''] as $x) {
        if ($x !== '') {
            $bits[] = $x;
        }
    }
    return implode(' · ', $bits);
}

/**
 * The businesses we already know that match what was typed, best first (at most $limit). [['name','city','meta','href']]; href is set for a lead of $brand
 * (opens it), empty for the rest (they fill the box).
 */
function pm_suggest_local(string $q, string $brand, int $limit = 8): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2 || mb_strlen($q) > 80) {
        return [];
    }
    $order = ['lead' => 0, 'archive' => 2, 'proposal' => 3, 'client' => 3];
    $best = [];
    foreach (pm_suggest_pool() as $r) {
        $rank = pm_suggest_rank($q, $r['name']);
        if ($rank === null) {
            continue;
        }
        $key = pm_suggest_norm($r['name']) . '|' . pm_suggest_norm($r['city']);
        $r['_k'] = sprintf('%d.%d.%03d.%s', $rank, ($r['brand'] === $brand ? 0 : 1) + $order[$r['src']], 100 - min(100, $r['score']), pm_suggest_norm($r['name']));
        // one row per business: the same name in a lead and in an old proposal is one suggestion (the lead wins); a proposal has no town, so it joins its lead by name
        $nk = pm_suggest_norm($r['name']);
        foreach ($best as $bk => $b) {
            if (pm_suggest_norm($b['name']) === $nk && ($b['city'] === '' || $r['city'] === '' || pm_suggest_norm($b['city']) === pm_suggest_norm($r['city']))) {
                if ($r['_k'] < $b['_k']) {
                    unset($best[$bk]);
                } else {
                    continue 2;
                }
            }
        }
        $best[$key] = $r;
    }
    uasort($best, fn($a, $b) => strcmp($a['_k'], $b['_k']));
    $out = [];
    foreach (array_slice($best, 0, $limit) as $r) {
        $own = $r['src'] === 'lead' && $r['brand'] === $brand && $r['id'] !== '';
        $out[] = ['name' => $r['name'], 'city' => $r['city'], 'meta' => pm_suggest_meta($r, $brand), 'href' => $own ? '?tab=agents&brand=' . rawurlencode($brand) . '&lead=' . rawurlencode($r['id']) : ''];
    }
    return $out;
}

/**
 * Real businesses in Malawi whose name matches what was typed, from one web-grounded AI call. Cached for a week per business and text, at most 60 asks an hour,
 * nothing for text shorter than 4 characters, and [] (never an error) when the AI is not set up, fails, or answers badly. Anything whose name does not match
 * what was typed, or that we already know, is dropped. [['name','city','meta','href' => '','site']].
 */
function pm_suggest_web(string $q, string $brand): array
{
    $q = trim((string)preg_replace('/\s+/', ' ', strip_tags($q)));
    if (mb_strlen($q) < 4 || mb_strlen($q) > 60 || !function_exists('pm_claude') || (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB']))) {
        return [];
    }
    $key = $brand . '|' . pm_suggest_norm($q);
    $cache = (array)pm_load('suggest_cache', fn() => []);
    $hit = $cache[$key] ?? null;
    if (is_array($hit) && (int)($hit['at'] ?? 0) > time() - 7 * 86400) {
        $raw = (array)$hit['rows'];
    } else {
        if (!pm_rate_hit('suggestweb', 'all', 60, 3600)) {
            return [];
        }
        $kind = $brand === 'travel' ? 'real, trading places to stay in Malawi (lodges, guest houses, B&Bs, cottages, camps, resorts, hostels)' : 'real, trading businesses and organisations in Malawi';
        $system = "You are a business directory for Malawi. Web-search for $kind whose name starts with or contains the text you are given. Only list a business that a web page you found confirms exists and trades in Malawi. "
            . 'Never invent a business, a town or a website: if you find none, return []. Reply with JSON only.';
        $user = "Name typed so far: \"$q\"\nJSON array of up to 6 {\"name\":\"its exact trading name\",\"city\":\"the town\",\"type\":\"kind of business in 2 or 3 words\",\"website\":\"its own website, or empty\"}";
        try {
            $r = pm_agent_json(pm_claude($system, $user, true, 900));
        } catch (Throwable) {
            return [];
        }
        $raw = [];
        foreach (is_array($r) && array_is_list($r) ? $r : [] as $x) {
            if (!is_array($x)) {
                continue;
            }
            $host = '';
            $w = trim(is_scalar($x['website'] ?? null) ? (string)$x['website'] : '');
            if ($w !== '') {
                $h = strtolower((string)parse_url(preg_match('#^https?://#i', $w) ? $w : 'https://' . $w, PHP_URL_HOST));
                $host = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $h) ? preg_replace('/^www\./', '', $h) : '';
            }
            $raw[] = ['name' => mb_substr(trim(strip_tags(is_scalar($x['name'] ?? null) ? (string)$x['name'] : '')), 0, 80), 'city' => mb_substr(trim(strip_tags(is_scalar($x['city'] ?? null) ? (string)$x['city'] : '')), 0, 40),
                'type' => mb_substr(trim(strip_tags(is_scalar($x['type'] ?? null) ? (string)$x['type'] : '')), 0, 40), 'site' => $host];
        }
        $cache[$key] = ['at' => time(), 'rows' => $raw];
        uasort($cache, fn($a, $b) => (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0));
        pm_save('suggest_cache', array_slice($cache, 0, 300, true));
    }
    $known = [];
    foreach (pm_suggest_pool() as $p) {
        $known[pm_suggest_norm($p['name'])] = true;
    }
    $out = [];
    foreach ($raw as $x) {
        $nn = pm_suggest_norm((string)($x['name'] ?? ''));
        if ($nn === '' || isset($known[$nn]) || isset($out[$nn . '|' . pm_suggest_norm((string)$x['city'])]) || pm_suggest_rank($q, (string)$x['name']) === null) {
            continue;
        }
        $out[$nn . '|' . pm_suggest_norm((string)$x['city'])] = ['name' => $x['name'], 'city' => $x['city'], 'meta' => implode(' · ', array_filter(['From a web search', $x['city'], $x['type'], $x['site']])), 'href' => '', 'site' => $x['site']];
    }
    return array_slice(array_values($out), 0, 6);
}
