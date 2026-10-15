<?php
/**
 * Google Ads planner (Social > Google Ads): a complete Search campaign for one business, written once and checked in code, ready to paste into Google Ads or to import
 * into Google Ads Editor as a CSV. There is no Google Ads connection (that needs an approved developer token), so nothing is created or spent from here.
 *   inputs     what we sell, the towns we cover, who buys, the facts, and the words real people used (public posts, social leads, enquiries, replies): code, no AI
 *   the plan   ONE AI call writes the ad groups (keywords, negatives, 15 headlines and 4 descriptions each), sitelinks, callouts and structured snippets;
 *              code keeps Google's limits (30/90/15/25/35 characters), its editorial rules (no "!" in headlines, no shouting, no phone numbers in ad text) and our
 *              facts-only rule (no number, price or boast the facts do not carry). Budget, bidding, locations, schedule and the final URL are set by code.
 *   editing    every line can be changed here, checked the same way
 *   tracking   clicks go to our enquiry page tagged src=gads (each enquiry becomes a lead marked Google Ads), and with the Google tag ID and conversion label saved
 *              here the enquiry page reports each enquiry to Google Ads as a conversion
 *   results    the owner types each week's spend, clicks, impressions and conversions; the leads are counted from the app; cost per lead goes to the Director
 * Data: data/gads.json {brand: {plan, at, by, sig, cap_mwk, land, tag, label, results: {monday: {spend, clicks, impr, conv}}}}.
 */

const PM_GADS_LIMITS = ['headline' => 30, 'description' => 90, 'path' => 15, 'sitelink' => 25, 'sitelink_line' => 35, 'callout' => 25, 'snippet' => 25, 'keyword' => 80];
const PM_GADS_CAP_DEFAULT_MWK = 3000;
const PM_GADS_ACRONYMS = ['IT', 'CCTV', 'PC', 'PCS', 'WIFI', 'ICT', 'POS', 'VPN', 'SEO', 'CRM', 'ERP', 'UPS', 'MWK', 'USD', 'LTD', 'NGO', 'SME', 'TV', 'SUV', 'B&B'];

function pm_gads_get(string $brand): array
{
    return (array)(pm_load('gads', fn() => [])[$brand] ?? []);
}

function pm_gads_set(string $brand, callable $fn): array
{
    $out = [];
    pm_update('gads', function (array $all) use ($brand, $fn, &$out) {
        $all[$brand] = $out = $fn((array)($all[$brand] ?? []));
        return $all;
    }, fn() => []);
    return $out;
}

/** The daily cap in Kwacha the owner set for Google Ads (the plan's budget never goes above it). */
function pm_gads_cap(string $brand): int
{
    $c = (int)(pm_gads_get($brand)['cap_mwk'] ?? 0);
    return $c > 0 ? $c : PM_GADS_CAP_DEFAULT_MWK;
}

/** Where the ad clicks land: our enquiry page (counts each enquiry as a Google Ads lead) when the app is online, else the website. */
function pm_gads_final_url(string $brand): string
{
    $land = (string)(pm_gads_get($brand)['land'] ?? 'enquiry');
    $site = function_exists('pm_brand_site') ? pm_brand_site($brand) : '';
    if ($land === 'enquiry' && pm_app_url() !== '') {
        return pm_app_url() . '/enquire.php?' . http_build_query(array_filter(['b' => $brand !== 'promanaged' ? $brand : '', 'src' => 'gads']));
    }
    if ($site === '') {
        return '';
    }
    return $site . (str_contains($site, '?') ? '&' : '?') . 'utm_source=google&utm_medium=cpc&utm_campaign=' . rawurlencode(pm_brand_name($brand));
}

/** What the plan is written from, all by code: [inputs for the AI, the facts as one text for the checks]. */
function pm_gads_inputs(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $f = function_exists('pm_opt_facts') ? pm_opt_facts($brand) : ['name' => pm_brand_name($brand), 'tagline' => '', 'about' => '', 'audience' => '', 'facts' => [], 'phone' => '', 'address' => '', 'site' => ''];
        $cfg = pm_agents_config($brand);
        $heard = []; // the words real people used when they asked for what we sell
        foreach (pm_leads_read() as $l) {
            if (($l['brand'] ?? 'promanaged') !== $brand || strtotime((string)($l['created'] ?? '')) < strtotime('-120 days')) {
                continue;
            }
            $w = (string)($l['listen']['wants'] ?? '');
            if ($w === '' && in_array($l['source'] ?? '', ['facebook', 'instagram', 'whatsapp', 'web', 'social'], true)) {
                foreach ((array)($l['thread'] ?? []) as $m) {
                    if (($m['dir'] ?? '') === 'in') {
                        $w = (string)$m['text'];
                        break;
                    }
                }
            }
            $w = trim(mb_substr((string)preg_replace('/\s+/u', ' ', strip_tags($w)), 0, 120));
            $w !== '' && $heard[] = $w;
        }
        $offers = function_exists('pm_lp_for_brand') ? array_map(fn($o) => trim($o['title'] . ': ' . $o['problem'] . ' ' . $o['fix']), pm_lp_for_brand($brand, true)) : [];
        $t = function_exists('pm_brand_targeting') ? pm_brand_targeting($brand) : ['segments' => []];
        $terms = [];
        foreach ((array)$t['segments'] as $s) {
            foreach ((array)($s['search_terms'] ?? []) as $x) {
                $terms[] = (string)$x;
            }
        }
        $in = ['business' => $f['name'], 'tagline' => $f['tagline'], 'about' => mb_substr((string)$f['about'], 0, 500), 'audience' => mb_substr((string)$f['audience'], 0, 240),
            'facts' => array_slice((array)$f['facts'], 0, 10), 'what_we_sell' => array_slice(array_values((array)($cfg['offerings'] ?? [])), 0, 10), 'who_buys' => array_slice(array_values((array)($cfg['sectors'] ?? [])), 0, 10),
            'towns' => array_slice(array_values((array)($cfg['cities'] ?? [])), 0, 10), 'offers' => array_slice($offers, 0, 4), 'search_terms_we_know' => array_slice(array_values(array_unique($terms)), 0, 12),
            'words_real_people_used' => array_slice(array_values(array_unique($heard)), 0, 15), 'focus_today' => function_exists('pm_director_today') ? (array)(pm_director_today()['focus'] ?? []) : []];
        $source = implode(' ', array_filter(array_map('strval', [$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', (array)$f['facts']), implode(' ', $in['what_we_sell']), implode(' ', $in['who_buys']),
            implode(' ', $in['towns']), implode(' ', $offers), $f['address']])));
        return [$in, $source];
    } finally {
        pm_brand_set($was);
    }
}

/** Why an ad text breaks Google's editorial rules or our facts-only rule ('' when it is fine). */
function pm_gads_bad(string $t, string $kind, string $source): string
{
    $lim = PM_GADS_LIMITS[$kind] ?? 90;
    if ($t === '') {
        return 'empty';
    }
    if (mb_strlen($t) > $lim) {
        return 'over ' . $lim . ' characters';
    }
    if ($kind !== 'keyword') {
        if (($kind !== 'description' && str_contains($t, '!')) || substr_count($t, '!') > 1) {
            return 'exclamation mark';
        }
        if (preg_match('/([!?.,])\1|\p{So}|\p{Cs}/u', $t)) {
            return 'repeated punctuation or emoji';
        }
        foreach (preg_split('/[^\p{L}&]+/u', $t) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && $w === mb_strtoupper($w) && preg_match('/\p{L}{3}/u', $w) && !in_array(strtoupper($w), PM_GADS_ACRONYMS, true)) {
                return 'a word in capitals';
            }
        }
        if (preg_match('/\+?\d[\d\s-]{7,}\d/', $t)) {
            return 'a phone number in the text';
        }
        if (preg_match('/\b(click here|best|number one|no\.? ?1|#1|cheapest|guarantee[sd]?|top[- ]rated|award[- ]winning|unbeatable|lowest price)\b/i', $t) && !preg_match('/\b(best|guarantee|award|cheapest|lowest)\b/i', $source)) {
            return 'a claim the facts do not carry';
        }
        if (function_exists('pm_brand_unsourced_number') && pm_brand_unsourced_number($t, $source)) {
            return 'a number nobody gave';
        }
        if (preg_match('~https?://|www\.|@~i', $t)) {
            return 'a link or address';
        }
    }
    return '';
}

/** Cleans a list of texts of one kind: trimmed, checked, without repeats, at most $max. */
function pm_gads_list(array $raw, string $kind, string $source, int $max, array &$dropped = []): array
{
    $out = [];
    $seen = [];
    foreach ($raw as $x) {
        if (!is_scalar($x)) {
            continue;
        }
        $t = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$x)));
        if ($kind === 'keyword') {
            $t = trim(mb_strtolower((string)preg_replace('/[\[\]"+]/', '', $t)));
            if (str_word_count($t) > 10) {
                $t = '';
            }
        }
        $k = mb_strtolower($t);
        $why = pm_gads_bad($t, $kind, $source);
        if ($why !== '' || isset($seen[$k])) {
            $t !== '' && $why !== '' && $dropped[] = $t . ' (' . $why . ')';
            continue;
        }
        $seen[$k] = 1;
        $out[] = $t;
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/** The AI's answer made safe: every ad group with enough good lines, the assets, and what was dropped and why. */
function pm_gads_clean(array $out, string $source): array
{
    $drop = [];
    $groups = [];
    foreach (array_slice((array)($out['groups'] ?? []), 0, 6) as $g) {
        if (!is_array($g)) {
            continue;
        }
        $name = mb_substr(trim(strip_tags((string)($g['name'] ?? ''))), 0, 60);
        $row = ['name' => $name, 'keywords' => pm_gads_list((array)($g['keywords'] ?? []), 'keyword', $source, 20, $drop), 'negatives' => pm_gads_list((array)($g['negatives'] ?? []), 'keyword', $source, 15, $drop),
            'headlines' => pm_gads_list((array)($g['headlines'] ?? []), 'headline', $source, 15, $drop), 'descriptions' => pm_gads_list((array)($g['descriptions'] ?? []), 'description', $source, 4, $drop),
            'path1' => (string)(pm_gads_list([(string)preg_replace('/[^\p{L}\p{N}-]+/u', '-', (string)($g['path1'] ?? ''))], 'path', $source, 1)[0] ?? ''),
            'path2' => (string)(pm_gads_list([(string)preg_replace('/[^\p{L}\p{N}-]+/u', '-', (string)($g['path2'] ?? ''))], 'path', $source, 1)[0] ?? '')];
        if ($name !== '' && count($row['keywords']) >= 3 && count($row['headlines']) >= 3 && count($row['descriptions']) >= 2) {
            $groups[] = $row;
        } elseif ($name !== '') {
            $drop[] = 'Ad group "' . $name . '": too few good keywords, headlines or descriptions left after the checks';
        }
    }
    $sitelinks = [];
    foreach (array_slice((array)($out['sitelinks'] ?? []), 0, 6) as $s) {
        $txt = pm_gads_list([(string)($s['text'] ?? '')], 'sitelink', $source, 1, $drop)[0] ?? '';
        if ($txt !== '') {
            $sitelinks[] = ['text' => $txt, 'd1' => (string)(pm_gads_list([(string)($s['line1'] ?? '')], 'sitelink_line', $source, 1, $drop)[0] ?? ''), 'd2' => (string)(pm_gads_list([(string)($s['line2'] ?? '')], 'sitelink_line', $source, 1, $drop)[0] ?? '')];
        }
    }
    return ['groups' => $groups, 'negatives' => pm_gads_list((array)($out['negatives'] ?? []), 'keyword', $source, 30, $drop), 'sitelinks' => $sitelinks,
        'callouts' => pm_gads_list((array)($out['callouts'] ?? []), 'callout', $source, 8, $drop), 'snippet_header' => in_array($out['snippet_header'] ?? '', ['Services', 'Types', 'Destinations', 'Amenities', 'Brands', 'Courses', 'Neighborhoods'], true) ? (string)$out['snippet_header'] : 'Services',
        'snippets' => pm_gads_list((array)($out['snippets'] ?? []), 'snippet', $source, 8, $drop), 'dropped' => array_slice($drop, 0, 30)];
}

/** ONE AI call writes the plan; code checks it and sets the rest. Returns [ok, message]. */
function pm_gads_write(string $brand, string $note = ''): array
{
    [$in, $source] = pm_gads_inputs($brand);
    $note = mb_substr(trim($note), 0, 300);
    $system = (function_exists('pm_agents_company_brief') ? pm_agents_company_brief('tiny') . "\n" : '') . 'You are a Google Ads search specialist for small businesses in Malawi, writing one Search campaign for ' . $in['business'] . '. '
        . 'Group what people search for into 2 to 5 tight ad groups (one service or need each). Keywords: the words a person types when they are ready to buy or ask, 8 to 20 per group, 2 to 5 words each, '
        . 'with the town where people would type it (e.g. "it support lilongwe"), taken from what_we_sell, who_buys, search_terms_we_know and especially words_real_people_used; no brand names of other companies. '
        . 'Negatives: words that bring the wrong people (jobs, free, course, salary, download, pdf, what is, and anything we do not sell). '
        . 'For each group 15 headlines of at most 30 characters (include the service, the town, a benefit, a reason to trust from the facts, and an easy action like "Message Us Today"; no exclamation marks) '
        . 'and 4 descriptions of at most 90 characters (outcome first, one concrete fact, an easy next step). path1 and path2: one word each, at most 15 characters. '
        . (defined('PM_MARKETING_PSYCH') ? PM_MARKETING_PSYCH . ' ' : '') . 'Use ONLY the facts: no number, price, discount, ranking, award, guarantee or claim that is not in them, never "best", no phone numbers or links in the text, no words in capitals. '
        . 'Also 4 to 6 sitelinks (text at most 25 characters, two lines of at most 35), 4 to 8 callouts (at most 25 characters) and one structured snippet (a header and 3 to 8 values of at most 25 characters). '
        . ($note !== '' ? 'The owner adds: ' . $note . ' ' : '') . 'Reply JSON only.';
    $user = json_encode($in, JSON_UNESCAPED_UNICODE) . "\nJSON: {\"groups\":[{\"name\",\"keywords\":[],\"negatives\":[],\"headlines\":[],\"descriptions\":[],\"path1\",\"path2\"}],\"negatives\":[],\"sitelinks\":[{\"text\",\"line1\",\"line2\"}],\"callouts\":[],\"snippet_header\":\"Services\",\"snippets\":[]}";
    $GLOBALS['PM_AI_REF'] = 'google_ads';
    $out = pm_agent_json(pm_claude($system, $user, false, 3200, 'write'));
    if (!is_array($out)) {
        return [false, 'The AI did not return a plan. Nothing changed; try again.'];
    }
    $plan = pm_gads_clean($out, $source);
    if (!$plan['groups']) {
        return [false, 'No ad group passed the checks (Google\'s limits and our facts-only rule). Add more facts or what you sell under Brand > Voice, then try again.'];
    }
    pm_gads_set($brand, fn(array $d) => array_replace($d, ['plan' => $plan, 'at' => date('Y-m-d H:i'), 'by' => pm_social_who() ?: 'owner', 'sig' => md5($source)]));
    pm_agent_log('Social', 'Google Ads plan written: ' . count($plan['groups']) . ' ad groups, ' . array_sum(array_map(fn($g) => count($g['keywords']), $plan['groups'])) . ' keywords' . ($plan['dropped'] ? ', ' . count($plan['dropped']) . ' lines dropped by the checks' : ''));
    return [true, 'The plan is ready: ' . count($plan['groups']) . ' ad groups' . ($plan['dropped'] ? '. ' . count($plan['dropped']) . ' lines were dropped by the checks (listed at the bottom).' : '.')];
}

/** The campaign settings, worked out in code from the cap, the towns, the hours and the plan. */
function pm_gads_settings(string $brand): array
{
    $cap = pm_gads_cap($brand);
    $towns = array_values((array)(pm_agents_config($brand)['cities'] ?? []));
    $hours = function_exists('pm_opt_hours') && function_exists('pm_opt_hours_text') ? pm_opt_hours_text(pm_opt_hours($brand)) : '';
    $usd = function_exists('pm_ads_rate') ? pm_ads_rate('USD') : 0;
    return [
        'name' => pm_brand_name($brand) . ' Search', 'type' => 'Search', 'networks' => 'Google Search only: switch off Search partners and the Display Network',
        'budget_mwk' => $cap, 'budget' => 'MWK ' . number_format($cap) . ' a day' . ($usd > 0 ? ' (about USD ' . number_format($cap * $usd, 2) . '; set the account currency when you open it)' : '') . ', about MWK ' . number_format($cap * 30.4) . ' a month at most',
        'bidding' => 'Maximize clicks with a maximum cost per click of about MWK ' . number_format(max(50, (int)round($cap / 12 / 10) * 10)) . ' for the first two weeks (so the budget buys about a dozen clicks a day), then Maximize conversions once 15 or more enquiries are recorded',
        'locations' => $towns ? 'People in or regularly in: ' . implode(', ', $towns) . ' (Malawi). Location option: Presence, not interest.' : 'Malawi',
        'languages' => 'English', 'schedule' => $hours !== '' ? 'Your opening hours: ' . $hours : 'All day', 'status' => 'Create it paused, check it, then enable it',
        'final_url' => pm_gads_final_url($brand), 'phone' => function_exists('pm_opt_facts') ? (string)pm_opt_facts($brand)['phone'] : '',
    ];
}

/** Google Ads Editor CSV (one entity per row): the campaign, each ad group, its keywords and negatives, its responsive search ad, the campaign negatives, sitelinks, callouts. */
function pm_gads_csv(string $brand): string
{
    $plan = (array)(pm_gads_get($brand)['plan'] ?? []);
    $s = pm_gads_settings($brand);
    $cols = array_merge(['Campaign', 'Campaign Type', 'Networks', 'Budget', 'Bid Strategy Type', 'Campaign Status', 'Ad Group', 'Ad Group Status', 'Keyword', 'Criterion Type', 'Ad type'],
        array_map(fn($i) => 'Headline ' . $i, range(1, 15)), array_map(fn($i) => 'Description ' . $i, range(1, 4)), ['Path 1', 'Path 2', 'Final URL', 'Status', 'Sitelink text', 'Description line 1', 'Description line 2', 'Callout text']);
    $rows = [];
    $row = fn(array $v) => array_map(fn($c) => (string)($v[$c] ?? ''), $cols);
    $c = $s['name'];
    $rows[] = $row(['Campaign' => $c, 'Campaign Type' => 'Search', 'Networks' => 'Google search', 'Budget' => (string)$s['budget_mwk'], 'Bid Strategy Type' => 'Maximize clicks', 'Campaign Status' => 'Paused']);
    foreach ((array)($plan['groups'] ?? []) as $g) {
        $rows[] = $row(['Campaign' => $c, 'Ad Group' => $g['name'], 'Ad Group Status' => 'Enabled']);
        foreach ($g['keywords'] as $k) {
            $rows[] = $row(['Campaign' => $c, 'Ad Group' => $g['name'], 'Keyword' => $k, 'Criterion Type' => 'Phrase', 'Status' => 'Enabled']);
        }
        foreach ($g['negatives'] as $k) {
            $rows[] = $row(['Campaign' => $c, 'Ad Group' => $g['name'], 'Keyword' => $k, 'Criterion Type' => 'Negative Phrase']);
        }
        $ad = ['Campaign' => $c, 'Ad Group' => $g['name'], 'Ad type' => 'Responsive search ad', 'Path 1' => $g['path1'], 'Path 2' => $g['path2'], 'Final URL' => $s['final_url'], 'Status' => 'Enabled'];
        foreach ($g['headlines'] as $i => $h) {
            $ad['Headline ' . ($i + 1)] = $h;
        }
        foreach ($g['descriptions'] as $i => $d) {
            $ad['Description ' . ($i + 1)] = $d;
        }
        $rows[] = $row($ad);
    }
    foreach ((array)($plan['negatives'] ?? []) as $k) {
        $rows[] = $row(['Campaign' => $c, 'Keyword' => $k, 'Criterion Type' => 'Campaign Negative Phrase']);
    }
    foreach ((array)($plan['sitelinks'] ?? []) as $sl) {
        $rows[] = $row(['Campaign' => $c, 'Sitelink text' => $sl['text'], 'Description line 1' => $sl['d1'], 'Description line 2' => $sl['d2'], 'Final URL' => $s['final_url']]);
    }
    foreach ((array)($plan['callouts'] ?? []) as $co) {
        $rows[] = $row(['Campaign' => $c, 'Callout text' => $co]);
    }
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $cols, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fp, $r, ',', '"', '');
    }
    rewind($fp);
    return "\xEF\xBB\xBF" . stream_get_contents($fp);
}

/** Leads that came from the ads (src=gads on the enquiry page) since a date. */
function pm_gads_leads(string $brand, int $days): int
{
    $since = strtotime("-$days days");
    return count(array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand && strtotime((string)($l['created'] ?? '')) >= $since
        && in_array('gads', [(string)(is_string($l['src'] ?? null) ? $l['src'] : ''), (string)($l['src_tag'] ?? '')], true)));
}

/** The last 30 days: spend, clicks, conversions typed in, leads counted, cost per lead. [] before anything was typed. */
function pm_gads_summary(string $brand): array
{
    $res = (array)(pm_gads_get($brand)['results'] ?? []);
    $since = date('Y-m-d', strtotime('-30 days'));
    $sum = ['spend' => 0, 'clicks' => 0, 'impr' => 0, 'conv' => 0, 'weeks' => 0];
    foreach ($res as $wk => $r) {
        if ($wk >= $since) {
            foreach (['spend', 'clicks', 'impr', 'conv'] as $k) {
                $sum[$k] += (float)($r[$k] ?? 0);
            }
            $sum['weeks']++;
        }
    }
    if (!$sum['weeks']) {
        return [];
    }
    $sum['leads'] = pm_gads_leads($brand, 30);
    $sum['cost_per_lead'] = $sum['leads'] ? (int)round($sum['spend'] / $sum['leads']) : null;
    $sum['cost_per_click'] = $sum['clicks'] ? (int)round($sum['spend'] / $sum['clicks']) : null;
    return $sum;
}

/** The Google tag for the enquiry page: loads on every page of a business that saved its tag, and reports a conversion on the thank-you page after a real enquiry. */
function pm_gads_tag_html(string $brand, bool $conversion): string
{
    $g = pm_gads_get($brand);
    $tag = (string)($g['tag'] ?? '');
    if (!preg_match('/^AW-\d{6,12}$/', $tag)) {
        return '';
    }
    $label = (string)($g['label'] ?? '');
    $conv = $conversion && preg_match('/^[A-Za-z0-9_-]{4,40}$/', $label) ? "gtag('event','conversion',{'send_to':'" . $tag . '/' . $label . "'});" : '';
    return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $tag . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'' . $tag . '\');' . $conv . '</script>';
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_do_gads_write(string $vb): array
{
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return ['msg' => 'The AI needs a key in .env first.', 'kind' => 'err', 'to' => 'social&view=gads'];
    }
    try {
        [$ok, $m] = pm_gads_write($vb, (string)($_POST['note'] ?? ''));
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not write the plan: ' . mb_substr($e->getMessage(), 0, 120) . '. Nothing changed.', 'kind' => 'err', 'to' => 'social&view=gads'];
    }
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=gads'];
}

/** The owner's edits to one ad group (one line each), checked like the AI's. */
function pm_do_gads_group(string $vb): array
{
    $i = (int)($_POST['g'] ?? -1);
    $lines = fn(string $k) => preg_split('/\R/', (string)($_POST[$k] ?? '')) ?: [];
    [, $source] = pm_gads_inputs($vb);
    $drop = [];
    $msg = '';
    pm_gads_set($vb, function (array $d) use ($i, $lines, $source, &$drop, &$msg) {
        if (!isset($d['plan']['groups'][$i])) {
            $msg = 'That ad group is not there any more.';
            return $d;
        }
        if (!empty($_POST['remove'])) {
            array_splice($d['plan']['groups'], $i, 1);
            $msg = 'Ad group removed.';
            return $d;
        }
        $g = &$d['plan']['groups'][$i];
        $g['name'] = mb_substr(trim(strip_tags((string)($_POST['name'] ?? $g['name']))), 0, 60) ?: $g['name'];
        $g['keywords'] = pm_gads_list($lines('keywords'), 'keyword', $source, 20, $drop);
        $g['negatives'] = pm_gads_list($lines('negatives'), 'keyword', $source, 15, $drop);
        $g['headlines'] = pm_gads_list($lines('headlines'), 'headline', $source, 15, $drop);
        $g['descriptions'] = pm_gads_list($lines('descriptions'), 'description', $source, 4, $drop);
        $g['path1'] = (string)(pm_gads_list([(string)($_POST['path1'] ?? '')], 'path', $source, 1, $drop)[0] ?? '');
        $g['path2'] = (string)(pm_gads_list([(string)($_POST['path2'] ?? '')], 'path', $source, 1, $drop)[0] ?? '');
        $msg = 'Saved.' . ($drop ? ' Left out: ' . implode('; ', array_slice($drop, 0, 4)) . '.' : '') . (count($g['headlines']) < 3 || count($g['descriptions']) < 2 ? ' Google needs at least 3 headlines and 2 descriptions.' : '');
        return $d;
    });
    return ['msg' => $msg, 'kind' => $drop ? 'err' : 'ok', 'to' => 'social&view=gads#g' . $i];
}

/** The account-level lines: campaign negatives, callouts, snippets, sitelinks. */
function pm_do_gads_assets(string $vb): array
{
    [, $source] = pm_gads_inputs($vb);
    $lines = fn(string $k) => preg_split('/\R/', (string)($_POST[$k] ?? '')) ?: [];
    $drop = [];
    pm_gads_set($vb, function (array $d) use ($lines, $source, &$drop) {
        $d['plan']['negatives'] = pm_gads_list($lines('negatives'), 'keyword', $source, 30, $drop);
        $d['plan']['callouts'] = pm_gads_list($lines('callouts'), 'callout', $source, 8, $drop);
        $d['plan']['snippets'] = pm_gads_list($lines('snippets'), 'snippet', $source, 8, $drop);
        $sl = [];
        foreach ($lines('sitelinks') as $ln) { // "text | line 1 | line 2"
            $p = array_map('trim', explode('|', $ln) + ['', '', '']);
            $t = pm_gads_list([$p[0]], 'sitelink', $source, 1, $drop)[0] ?? '';
            $t !== '' && $sl[] = ['text' => $t, 'd1' => (string)(pm_gads_list([$p[1]], 'sitelink_line', $source, 1, $drop)[0] ?? ''), 'd2' => (string)(pm_gads_list([$p[2]], 'sitelink_line', $source, 1, $drop)[0] ?? '')];
        }
        $d['plan']['sitelinks'] = array_slice($sl, 0, 6);
        return $d;
    });
    return ['msg' => 'Saved.' . ($drop ? ' Left out: ' . implode('; ', array_slice($drop, 0, 4)) . '.' : ''), 'kind' => $drop ? 'err' : 'ok', 'to' => 'social&view=gads#assets'];
}

/** The budget cap, where clicks land, and the Google tag for conversions. */
function pm_do_gads_settings(string $vb): array
{
    $tag = strtoupper(trim((string)($_POST['tag'] ?? '')));
    $label = trim((string)($_POST['label'] ?? ''));
    if ($tag !== '' && !preg_match('/^AW-\d{6,12}$/', $tag)) {
        return ['msg' => 'The Google tag ID looks like AW-123456789.', 'kind' => 'err', 'to' => 'social&view=gads#settings'];
    }
    if ($label !== '' && !preg_match('/^[A-Za-z0-9_-]{4,40}$/', $label)) {
        return ['msg' => 'The conversion label is letters, numbers, - and _ only (as Google shows it after the slash).', 'kind' => 'err', 'to' => 'social&view=gads#settings'];
    }
    pm_gads_set($vb, fn(array $d) => array_replace($d, ['cap_mwk' => max(500, min(500000, (int)($_POST['cap'] ?? PM_GADS_CAP_DEFAULT_MWK))), 'land' => ($_POST['land'] ?? '') === 'site' ? 'site' : 'enquiry', 'tag' => $tag, 'label' => $label]));
    return ['msg' => 'Saved.', 'kind' => 'ok', 'to' => 'social&view=gads#settings'];
}

/** One week's numbers from Google Ads, typed by the owner. */
function pm_do_gads_results(string $vb): array
{
    $wk = date('Y-m-d', strtotime('monday this week', strtotime((string)($_POST['week'] ?? '')) ?: time()));
    $num = fn(string $k) => max(0, (float)str_replace(',', '', (string)($_POST[$k] ?? '0')));
    pm_gads_set($vb, function (array $d) use ($wk, $num) {
        $d['results'][$wk] = ['spend' => $num('spend'), 'clicks' => (int)$num('clicks'), 'impr' => (int)$num('impr'), 'conv' => (int)$num('conv'), 'by' => pm_social_who() ?: 'owner', 'at' => date('Y-m-d H:i')];
        krsort($d['results']);
        $d['results'] = array_slice($d['results'], 0, 52, true);
        return $d;
    });
    return ['msg' => 'Week of ' . date('j M', strtotime($wk)) . ' saved.', 'kind' => 'ok', 'to' => 'social&view=gads#results'];
}
