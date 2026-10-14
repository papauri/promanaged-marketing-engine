<?php
/**
 * The personal touch on every first message, at the smallest cost.
 *
 *  - The market pulse: once a week per business, ONE short web call reads the news where it sells (its markets and the kinds of business it targets) and keeps up to
 *    three issues its customers feel now (a forex shortage, fuel, power, the tourism season, a new rule), each with how we honestly help. Every writer and the day's study
 *    get it as one line, so a message can touch on what is happening around the lead (Travel Malawi: free, so a host pays nothing at all, in forex or kwacha).
 *  - The personal line: the day's study (one web call per lead, already paid for) also writes the line a first email opens with, built on one confirmed detail about them.
 *    The default message's {opening} uses it, and Travel Malawi's first email is drafted from it with no Writer call. No detail found: the default opening, as before.
 *  - Drafts already waiting are brought up to date when the study or a name lands (no AI): the default opening becomes the personal line, and "Hello," gets the owner's first name.
 */

/** Listing sites where an owner or host is named on the business's own listing page: a name there may be used in the greeting when the role is owner, host or manager. */
function pm_owner_listing_source(string $src, string $role): bool
{
    $h = strtolower((string)parse_url(preg_match('#^https?://#i', trim($src)) ? trim($src) : 'http://' . trim($src), PHP_URL_HOST));
    return $h !== '' && (bool)preg_match('/(^|\.)(tripadvisor\.[a-z.]+|airbnb\.[a-z.]+|booking\.com)$/', $h)
        && (bool)preg_match('/owner|founder|proprietor|host|director|manager/i', $role) && !preg_match('/restaurant|kitchen|front desk|reception|assistant/i', $role);
}

/* ---------------- the market pulse ---------------- */

/** The issues kept for a business: ['at' => 'Y-m-d', 'items' => [['issue', 'helps', 'source']]]. With $refresh and older than 7 days, asks the web once (never on a page load). */
function pm_market_pulse(string $brand = '', bool $refresh = false): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $have = (array)(pm_load('market_pulse', fn() => [])[$brand] ?? []);
    $fresh = !empty($have['tried']) && strtotime((string)$have['tried']) > strtotime('-7 days');
    if (!$refresh || $fresh || !(pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        return $have + ['items' => []];
    }
    $items = $have['items'] ?? [];
    try {
        $items = pm_dm_as($brand, fn() => pm_market_pulse_ask($brand));
        $at = date('Y-m-d');
    } catch (Throwable) {
        $at = (string)($have['at'] ?? '');
    }
    $row = ['at' => $at, 'tried' => date('Y-m-d'), 'items' => $items];
    pm_update('market_pulse', function (array $all) use ($brand, $row) {
        $all[$brand] = $row;
        return $all;
    }, fn() => []);
    return $row;
}

/** One short web call: what the business's customers feel now where it sells, and how it honestly helps. */
function pm_market_pulse_ask(string $brand): array
{
    $mk = pm_markets($brand);
    $segs = array_slice(array_map(fn($s) => (string)$s['name'], pm_brand_targeting($brand)['segments']), 0, 4);
    $who = $brand === 'travel' ? 'independent lodges, guest houses, B&Bs and camps' : ($segs ? implode(', ', $segs) : 'small and medium businesses');
    $system = pm_agents_company_brief('tiny') . "\nYou are a market analyst. Search news from the last 3 months in " . implode(', ', $mk['countries'])
        . ". Give up to 3 issues that $who there feel now (for example a shortage of foreign currency, fuel or power, the tourism season, a new tax or rule, prices rising). "
        . 'For each, how we genuinely help, using only the facts above (paying in the local currency instead of forex counts when our facts or money rule allow it); leave out any issue we cannot honestly help with. '
        . 'Issue under 14 words, helps under 18 words, each with its source URL. ' . PM_AGENT_RULES;
    $out = pm_agent_json(pm_claude($system, 'JSON: {"items":[{"issue":"","helps":"","source":"URL"}]}', true, 700));
    $items = [];
    foreach ((array)($out['items'] ?? []) as $x) {
        $i = is_array($x) ? trim(strip_tags((string)($x['issue'] ?? ''))) : '';
        $h = is_array($x) ? trim(strip_tags((string)($x['helps'] ?? ''))) : '';
        if ($i !== '' && $h !== '' && preg_match('#^https?://#i', (string)($x['source'] ?? '')) && !pm_has_price($i . ' ' . $h)) {
            $items[] = ['issue' => mb_substr($i, 0, 120), 'helps' => mb_substr($h, 0, 140), 'source' => (string)$x['source']];
        }
    }
    return array_slice($items, 0, 3);
}

/** One line for the writers and the study: what is happening where the lead is, to touch on in a clause when it fits. '' when nothing is known. */
function pm_market_pulse_line(string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $bits = array_map(fn($x) => $x['issue'] . ' (we help: ' . $x['helps'] . ')', (array)(pm_market_pulse($brand)['items'] ?? []));
    if ($brand === 'travel') { // the owner's own angle: free to join, so a host pays nothing at all, in forex or kwacha
        $bits[] = 'paying abroad in foreign currency is hard (we help: joining is free with no commission, so a host pays nothing at all, in forex or kwacha, and guests pay them directly in kwacha)';
    } elseif ($brand === 'promanaged') { // the owner's own angle: a local supplier paid locally, so no forex to find for IT
        $bits[] = 'foreign currency is scarce, so paying overseas suppliers for IT and software is hard (we help: you pay us locally in ' . pm_local_currency()['word'] . ', with no forex to find)';
    }
    return $bits ? 'What is happening where they are now; touch on ONE of these in a single natural clause when it fits, never as a lecture: ' . implode('; ', $bits) . '.' : '';
}

/* ---------------- the personal line ---------------- */

/** The study's line made safe for the start of a first email: no greeting, link, number or price, no self-introduction or traveller pose, two sentences and 30 words at most. '' when unusable. */
function pm_personal_clean(string $s, string $brand): string
{
    $keep = []; // a place with a number in it ("Area 44", "the M1") is an address, not a price: kept through the no-numbers rule
    $s = (string)preg_replace_callback('/\b(Area|Sector|Plot|Zone|M|S)\s?\d{1,3}\b/u', function ($m) use (&$keep) {
        $keep[] = $m[0];
        return '⟦' . str_repeat('·', count($keep)) . '⟧';
    }, $s);
    $out = pm_personal_clean_text($s, $brand);
    foreach (array_reverse($keep, true) as $i => $k) {
        $out = str_replace('⟦' . str_repeat('·', $i + 1) . '⟧', $k, $out);
    }
    return $out;
}

/** pm_personal_clean() once the numbered places are set aside. */
function pm_personal_clean_text(string $s, string $brand): string
{
    if ($brand === 'travel') { // too long: the first sentence alone (the detail) still opens the email well
        $one = (string)(preg_split('/(?<=[.?!])\s+/u', trim($s))[0] ?? '');
        return pm_tm_clean_opening($s) ?: (pm_tm_clean_opening($one) ?: pm_tm_clean_opening((string)preg_replace('/,\s+(and|so)\s+we\b.*$/iu', '.', $one)));
    }
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    $s = (string)preg_replace('/^(hello|hi|dear|good (morning|day|afternoon))\b[^,.!?]{0,40}[,.!?]\s*/iu', '', $s);
    if ($s === '' || preg_match('#https?://|www\.|\d|[$£€]#u', $s) || pm_has_price($s) || preg_match('/\b(I\'?m|I am|my name is)\s+[A-Z]/u', $s)) {
        return '';
    }
    $s = implode(' ', array_slice((array)preg_split('/(?<=[.?])\s+/u', str_replace('!', '.', $s)), 0, 2));
    return str_word_count($s) > 30 ? '' : $s;
}

/** The personal line the day's study wrote for this lead, or ''. */
function pm_lead_personal(array $lead): string
{
    $p = trim((string)($lead['research']['personal'] ?? ''));
    if ($p !== '' || empty($lead['research']['sure'])) {
        return $p;
    }
    foreach ((array)($lead['research']['hooks'] ?? []) as $h) { // no line written (or it broke a rule): the observation half of a hook the study already wrote, no AI
        $obs = trim((string)preg_split('/\s*(—|–|\?|;|, but\b| but\b|,? (and )?(how|do|does|what|when|where|why|who|is|are|can|could|would)\b)/u', (string)$h)[0]);
        if (str_word_count($obs) >= 7 && preg_match('/^(Your|From your)\b/u', $obs) && ($c = pm_personal_clean(rtrim($obs, ' ,.') . '.', (string)($lead['brand'] ?? pm_brand()))) !== '') {
            return $c;
        }
    }
    return '';
}

/**
 * Brings a waiting first draft up to date with what was learned, without AI: the default opening becomes the study's personal line, and a greeting with no name gets the
 * owner's first name. Only drafts nobody sent, approved or edited by hand. Returns true when it changed something.
 */
function pm_dm_personalise(array &$lead): bool
{
    $d = (array)($lead['drafts'] ?? []);
    $body = (string)($d['email_body'] ?? '');
    if ($body === '' || !empty($lead['sent']) || !empty($lead['approved_at']) || !empty($d['edited_at']) || !in_array($lead['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
        return false;
    }
    $brand = (string)($lead['brand'] ?? pm_brand());
    $was = [$body, (string)($d['whatsapp'] ?? '')];
    $p = pm_lead_personal($lead);
    if ($p !== '' && empty($d['plain']) && !str_contains($body, $p)) {
        $bare = array_diff_key($lead, ['research' => 1]);
        $generic = pm_dm_as($brand, fn() => [pm_dm_vars($bare)['opening'], $brand === 'travel' ? pm_tm_default_opening($bare) : '']);
        foreach (array_filter($generic) as $g) {
            if (str_contains($body, $g)) {
                $body = str_replace($g, $p, $body);
                break;
            }
        }
        if (!str_contains($body, $p) && $brand === 'travel') { // an earlier writer's opening ("We came across your lodge..."): the paragraph between the greeting and the standard part
            $paras = explode("\n\n", str_replace("\r", '', $body));
            $intro = trim((string)(pm_dm_as($brand, fn() => pm_tm_email_cfg())['intro'] ?? ''));
            if (count($paras) > 3 && $intro !== '' && trim($paras[2]) === $intro && preg_match('/^(Hello|Hi|Good day|Dear)\b[^\n]{0,40},$/u', trim($paras[0]))) {
                $paras[1] = $p;
                $body = implode("\n\n", $paras);
            }
        }
    }
    $first = pm_dm_first($lead);
    if ($first !== '') {
        $body = (string)preg_replace('/^(Hello|Hi|Good day|Dear)\s*,/u', "Hello $first,", $body);
        $d['whatsapp'] = (string)preg_replace('/^(Hello|Hi|Good day)\s*,/u', "Hello $first,", $was[1]);
    }
    if ([$body, (string)($d['whatsapp'] ?? '')] === $was) {
        return false;
    }
    $d['email_body'] = $body;
    $lead['drafts'] = $d;
    pm_lead_note($lead, 'Draft made personal with what we learned' . ($first !== '' ? " (greets $first)" : ''));
    return true;
}

/**
 * The owner chose a name (pressed Use this name, or typed it in the To box): it goes into the greeting of every unsent message on the lead straight away, the first email,
 * the follow-up and their WhatsApp versions, even a draft edited by hand (only the greeting line changes). Returns true when a greeting changed.
 */
function pm_dm_greet(array &$lead): bool
{
    $first = pm_dm_first($lead);
    if ($first === '' || !empty($lead['sent']) && empty($lead['followup_draft'])) {
        return false;
    }
    $did = false;
    foreach (['drafts', 'followup_draft'] as $k) {
        if (empty($lead[$k]) || ($k === 'drafts' && !empty($lead['sent']))) {
            continue;
        }
        foreach (['email_body', 'whatsapp'] as $f) {
            $t = (string)($lead[$k][$f] ?? '');
            $n = (string)preg_replace('/^(Hello|Hi|Dear|Good day|Moni|Muli bwanji)(\s+(Mr|Mrs|Ms|Dr)\.?)?(\s+[\p{L}\'’-]+){0,2}\s*,/u', "Hello $first,", $t, 1);
            if ($n !== $t) {
                $lead[$k][$f] = $n;
                $did = true;
            }
        }
    }
    return $did;
}

/**
 * Is WhatsApp set up for this business: a WhatsApp number (or phone) of ours in Business details, or a connected WhatsApp Business number. When it is not, the business works
 * by email only: no WhatsApp boxes or buttons on the leads, and the writers do not spend tokens writing WhatsApp messages nobody will send.
 */
function pm_wa_on(string $brand = ''): bool
{
    $brand = $brand !== '' ? $brand : pm_brand();
    static $memo = [];
    return $memo[$brand] ??= pm_dm_as($brand, fn() => pm_our_wa_number(pm_settings()) !== '') || (function_exists('pm_wa_biz_cfg') && pm_wa_biz_cfg($brand)['ready']);
}
