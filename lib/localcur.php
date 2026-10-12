<?php
/**
 * The currency a message speaks in: the one where the business we write to is, never US dollars. A lead's address, town or country decides it;
 * when they say nothing, the country of our own business does (Malawi: kwacha).
 *   pm_local_currency($lead)   ['code' => 'MWK', 'word' => 'kwacha', 'country' => 'Malawi']
 *   pm_no_usd($text, $lead)    the text with every mention of dollars or USD taken out (or turned into the local currency)
 * Loaded by lib/agents.php, so the writers, the senders and the worker processes all have it.
 */

/** Countries we may write to: a pattern of the country and its towns => [code, word, country]. The first match wins; Malawi is the default. */
const PM_LOCAL_CURRENCIES = [
    'zambia|lusaka|ndola|kitwe|livingstone|chipata' => ['ZMW', 'Zambian kwacha', 'Zambia'],
    'tanzania|dar es salaam|dodoma|arusha|mwanza|zanzibar|mbeya' => ['TZS', 'Tanzanian shillings', 'Tanzania'],
    'mozambique|maputo|beira|nampula|tete|quelimane' => ['MZN', 'meticais', 'Mozambique'],
    'south africa|johannesburg|cape town|durban|pretoria' => ['ZAR', 'rand', 'South Africa'],
    'zimbabwe|harare|bulawayo|mutare' => ['ZWG', 'ZiG', 'Zimbabwe'],
    'kenya|nairobi|mombasa|kisumu' => ['KES', 'Kenyan shillings', 'Kenya'],
    'uganda|kampala|entebbe' => ['UGX', 'Ugandan shillings', 'Uganda'],
    'botswana|gaborone|francistown' => ['BWP', 'pula', 'Botswana'],
    'malawi|lilongwe|blantyre|zomba|mzuzu|mangochi|salima|karonga|nkhata bay|nkhotakota|liwonde|mulanje|kasungu|dedza|balaka|chikwawa|cape maclear|monkey bay|likoma|chintheche|livingstonia' => ['MWK', 'kwacha', 'Malawi'],
];

/** Telephone country codes, for numbers written the local way (a leading 0). */
const PM_COUNTRY_DIAL = ['Malawi' => '265', 'Zambia' => '260', 'Tanzania' => '255', 'Mozambique' => '258', 'South Africa' => '27', 'Zimbabwe' => '263', 'Kenya' => '254',
    'Uganda' => '256', 'Botswana' => '267', 'Rwanda' => '250', 'Ghana' => '233', 'Nigeria' => '234', 'United Kingdom' => '44', 'United States' => '1', 'Canada' => '1',
    'Australia' => '61', 'Ireland' => '353', 'Germany' => '49', 'Netherlands' => '31', 'United Arab Emirates' => '971', 'India' => '91'];

/* ---------------- where a business works ---------------- */

/** The country a place names ("Lusaka", "Cape Town, South Africa", "Accra, Ghana"), or '' when it does not say. */
function pm_country_of(string $text): string
{
    $t = mb_strtolower(trim($text));
    if ($t === '') {
        return '';
    }
    foreach (PM_LOCAL_CURRENCIES as $re => [, , $country]) {
        if (preg_match('/\b(' . $re . ')\b/u', $t)) {
            return $country;
        }
    }
    foreach (array_keys(PM_COUNTRY_DIAL) as $country) {
        if (preg_match('/\b' . preg_quote(mb_strtolower($country), '/') . '\b/u', $t)) {
            return $country;
        }
    }
    if (preg_match('/,\s*([\p{L} .\'-]{3,40})$/u', trim($text), $m)) { // "Town, Country" with a country we do not list
        return trim($m[1]);
    }
    return '';
}

/**
 * Where a business sells, from Sales > Targeting ("Where we sell"): ['home' => the first country, 'countries' => [...], 'worldwide' => bool].
 * ProManaged IT: Malawi first, and worldwide. Travel Malawi: Malawi. An added business: the market it gave when it was added.
 */
function pm_markets(string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $m = array_values(array_filter(array_map('trim', (array)(pm_agents_config($brand)['markets'] ?? ['Malawi'])), fn($x) => $x !== ''));
    $isWorld = fn($x) => (bool)preg_match('/world|global|international|anywhere|everywhere/i', $x);
    $countries = array_values(array_filter($m, fn($x) => !$isWorld($x)));
    return ['home' => $countries[0] ?? 'Malawi', 'countries' => $countries ?: ['Malawi'], 'worldwide' => (bool)array_filter($m, $isWorld)];
}

/** One line for every prompt: where we are and whom we serve, so no agent writes as if we only served one country. */
function pm_markets_line(string $brand = ''): string
{
    $mk = pm_markets($brand);
    $list = implode(', ', $mk['countries']);
    return "Where we work: based in {$mk['home']}; we serve businesses in $list" . ($mk['worldwide'] ? ', and anywhere in the world' : '') . '. Never say we only serve one country unless that is all we serve.';
}

/** A search place for the scout: "Lusaka, Zambia" as written, a known town with its country, any other town in the home country. */
function pm_city_place(string $city, string $brand = ''): string
{
    $city = trim($city);
    if (str_contains($city, ',')) {
        return $city;
    }
    $c = pm_country_of($city);
    if ($c !== '' && stripos($city, $c) !== false) { // "Lusaka Zambia": the country is already in it
        return $city;
    }
    return $city . ', ' . ($c !== '' ? $c : pm_markets($brand)['home']);
}

/** The country of a place to search (see pm_city_place). */
function pm_city_country(string $city, string $brand = ''): string
{
    return pm_country_of(pm_city_place($city, $brand)) ?: pm_markets($brand)['home'];
}

/** The country a lead is in: saved on it, else read from its address or town, else the home country of the business that found it. */
function pm_lead_country(array $l): string
{
    if (trim((string)($l['country'] ?? '')) !== '') {
        return trim((string)$l['country']);
    }
    return pm_country_of((string)($l['address'] ?? '')) ?: (pm_country_of((string)($l['city'] ?? '')) ?: pm_markets((string)($l['brand'] ?? ''))['home']);
}

/** "Lilongwe, Malawi": the lead's town and country, for the researchers. */
function pm_lead_place(array $l): string
{
    $city = trim((string)($l['city'] ?? ''));
    $country = pm_lead_country($l);
    return $city === '' ? $country : (stripos($city, $country) !== false ? $city : "$city, $country");
}

/** The country code for a lead's phone numbers written the local way. */
function pm_lead_dial(array $l): string
{
    return PM_COUNTRY_DIAL[pm_lead_country($l)] ?? '265';
}

/** The currency of the business we write to (its country), else of our own business. A country we do not list: "their local currency". */
function pm_local_currency(array $lead = []): array
{
    $country = $lead ? pm_lead_country($lead) : pm_markets()['home'];
    foreach (PM_LOCAL_CURRENCIES as [$code, $word, $c]) {
        if (strcasecmp($c, $country) === 0) {
            return ['code' => $code, 'word' => $word, 'country' => $c];
        }
    }
    return ['code' => '', 'word' => 'their local currency', 'country' => $country !== '' ? $country : 'their country'];
}

/** One line for the writers: which currency to name, if any, and never dollars. */
function pm_local_currency_rule(array $lead = []): string
{
    $c = pm_local_currency($lead);
    return "Money: never mention US dollars, USD or the \$ sign. If a currency must be named, it is {$c['word']}" . ($c['code'] !== '' ? " ({$c['code']})" : '') . ", the currency of {$c['country']}; a lead given with its own 'currency' uses that one.";
}

/**
 * The text without US dollars: "in kwacha or dollars" becomes "in kwacha", "dollars or kwacha" becomes "kwacha", and a lone "dollars" or "USD" becomes the local currency.
 * Amounts written with a $ sign are left as they are (a price is never converted by guesswork; the wording rules keep prices out of first messages anyway).
 */
function pm_no_usd(string $text, array $lead = []): string
{
    if (!preg_match('/dollar|\bUSD\b|US\$/i', $text)) {
        return $text;
    }
    $c = pm_local_currency($lead);
    $t = (string)preg_replace('/\s*,?\s*(?:\bor\b|\band\b|\/)\s*(?:in\s+)?(?:US\s*)?(?:dollars?|USD)\b/i', '', $text); // "kwacha or dollars" -> "kwacha"
    $t = (string)preg_replace('/\b(?:US\s*)?(?:dollars?|USD)\s*(?:or|and|\/)\s+/i', '', $t); // "dollars or kwacha" -> "kwacha"
    $t = (string)preg_replace('/\bUS\s+dollars?\b|\bdollars?\b|\bUSD\b(?!\s?\d)/i', $c['word'], $t); // a lone mention: the local currency
    return $t;
}

/**
 * One-off: takes "or dollars" out of what is already saved and will be sent or shown: the business facts and the Travel standard email, the default first messages,
 * the drafts on leads (not the conversation history), and posts and profile wording that have not gone out. Each file is backed up first. Returns what changed.
 */
function pm_no_usd_migrate(): array
{
    $done = [];
    $fix = function ($v) use (&$fix) {
        if (is_array($v)) {
            return array_map($fix, $v);
        }
        return is_string($v) ? pm_no_usd($v) : $v;
    };
    $backup = function (string $name): void {
        $f = PM_DATA . "/$name.json";
        if (is_file($f)) {
            @mkdir(PM_DATA . '/backup', 0775, true);
            @copy($f, PM_DATA . "/backup/$name.before-no-usd-" . date('YmdHis') . '.json');
        }
    };
    $count = fn($a) => substr_count(mb_strtolower(json_encode($a, JSON_UNESCAPED_UNICODE)), 'dollar');
    // settings: every business's facts and Travel's standard email
    $backup('settings');
    pm_update('settings', function (array $s) use ($fix, $count, &$done) {
        $before = $count($s);
        if (isset($s['brain'])) {
            $s['brain'] = $fix($s['brain']);
        }
        foreach (['brain', 'host_email'] as $k) {
            if (isset($s['travel'][$k])) {
                $s['travel'][$k] = $fix($s['travel'][$k]);
            }
        }
        foreach (array_keys((array)($s['brands'] ?? [])) as $b) {
            if (isset($s['brands'][$b]['brain'])) {
                $s['brands'][$b]['brain'] = $fix($s['brands'][$b]['brain']);
            }
        }
        $done['settings'] = $before - $count($s);
        return $s;
    }, 'pm_default_settings');
    // the default first messages: the currency follows the lead
    $backup('message_defaults');
    pm_update('message_defaults', function (array $m) use ($count, &$done) {
        $before = $count($m);
        array_walk_recursive($m, function (&$v) {
            if (is_string($v)) {
                $v = (string)preg_replace('/\bkwacha\s+(?:or|and|\/)\s+(?:US\s*)?dollars?\b/i', '{currency}', $v);
                $v = pm_no_usd($v);
            }
        });
        $done['message_defaults'] = $before - $count($m);
        return $m;
    }, fn() => []);
    // drafts on leads (never the conversation that already happened)
    $backup('leads');
    $L = pm_leads();
    $before = $count(array_map(fn($l) => [$l['drafts'] ?? [], $l['followup_draft'] ?? [], $l['reply_draft'] ?? []], $L));
    $deep = function ($v, array $l) use (&$deep) {
        return is_array($v) ? array_map(fn($x) => $deep($x, $l), $v) : (is_string($v) ? pm_no_usd($v, $l) : $v);
    };
    foreach ($L as $id => $l) {
        foreach (['drafts', 'followup_draft', 'reply_draft', 'proposal_ai', 'reason', 'pain'] as $k) { // what can still go out: drafts, the proposal email and plan, the ready-made openings
            if (isset($l[$k])) {
                $L[$id][$k] = $deep($l[$k], $l);
            }
        }
        foreach (['hooks', 'starters'] as $k) {
            if (isset($l['research'][$k])) {
                $L[$id]['research'][$k] = $deep($l['research'][$k], $l);
            }
        }
    }
    pm_leads_save($L);
    $done['lead drafts'] = $before - $count(array_map(fn($l) => [$l['drafts'] ?? [], $l['followup_draft'] ?? [], $l['reply_draft'] ?? []], pm_leads_read()));
    // posts that have not gone out, and profile wording not yet used
    if (is_file(PM_DATA . '/social_posts.json')) {
        $backup('social_posts');
        pm_update('social_posts', function (array $posts) use (&$done) {
            $n = 0;
            foreach ($posts as $i => $p) {
                if (in_array($p['status'] ?? '', ['draft', 'review', 'approved', 'needs_edit', 'needs_video', 'needs_asset'], true)) {
                    foreach (['caption', 'headline', 'sub', 'first_comment', 'script'] as $k) {
                        if (is_string($p[$k] ?? null) && ($nv = pm_no_usd($p[$k])) !== $p[$k]) {
                            $posts[$i][$k] = $nv;
                            $posts[$i]['approved_hash'] = '';
                            $n++;
                        }
                    }
                }
            }
            $done['unsent posts'] = $n;
            return $posts;
        }, fn() => []);
    }
    if (is_file(PM_DATA . '/page_opt.json')) {
        $backup('page_opt');
        pm_update('page_opt', function (array $o) use ($fix, $count, &$done) {
            $before = $count($o);
            foreach ($o as $b => $row) {
                foreach (['ai', 'copy'] as $k) {
                    if (isset($row[$k])) {
                        $o[$b][$k] = $fix($row[$k]);
                    }
                }
            }
            $done['profile wording'] = $before - $count($o);
            return $o;
        }, fn() => []);
    }
    return $done;
}
