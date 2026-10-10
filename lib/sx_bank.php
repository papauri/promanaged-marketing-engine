<?php
/**
 * Content engine: the seed bank (what a post can be ABOUT), the pillar/seed picker, hook patterns and hashtags. No AI in here.
 * Owner input lives in data/social_bank.json: {brand: {ideas, tags, dates, places, chichewa, banned, ratio, giveaway, builtin_ideas},
 *   ledger: {brand: {seeds_used:{id:Y-m-d}, tags_used:{tag:Y-m-d}, hooks_used:{id:Y-m-d}}}}.
 */

const PM_SX_SEED_GAP_DAYS = 45;   // a seed is not reused inside this window
const PM_SX_HOOK_GAP_DAYS = 14;   // nor a hook pattern
const PM_SX_WINDOW_DAYS = 28;     // rolling window for the pillar deficit

function pm_sx_brand(string $b): string { return pm_brand_norm($b); }

/* ---------------- storage ---------------- */

function pm_bank_raw(): array { return pm_load('social_bank', fn() => []); }

/** One brand's bank with defaults filled in (tags fall back to the built-in hashtag bank when the owner has none). Includes 'ledger'. */
function pm_bank_get(string $brand): array
{
    $brand = pm_sx_brand($brand);
    $all = pm_bank_raw();
    $b = array_replace_recursive(pm_default_bank($brand), (array)($all[$brand] ?? []));
    $d = pm_default_bank_tags($brand);
    foreach (['local', 'niche'] as $k) {
        $b['tags'][$k] = array_values(array_filter(array_map('pm_sx_tag', (array)($b['tags'][$k] ?? [])))) ?: $d[$k];
    }
    $b['tags']['pillar'] = array_map(fn($l) => array_values(array_filter(array_map('pm_sx_tag', (array)$l))), (array)($b['tags']['pillar'] ?? [])) + $d['pillar'];
    $b['ledger'] = pm_bank_ledger($brand);
    return $b;
}

/** Locked change of one brand's bank. $fn(array $stored): array. The callback sees what is stored (no hashtag defaults mixed in). */
function pm_bank_set(string $brand, callable $fn): array
{
    $brand = pm_sx_brand($brand);
    $out = [];
    pm_update('social_bank', function (array $all) use ($brand, $fn, &$out) {
        $cur = array_replace_recursive(pm_default_bank($brand), (array)($all[$brand] ?? []));
        $out = (array)$fn($cur);
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

function pm_bank_ledger(string $brand): array
{
    $l = (array)((pm_bank_raw()['ledger'] ?? [])[pm_sx_brand($brand)] ?? []);
    return ['seeds_used' => (array)($l['seeds_used'] ?? []), 'tags_used' => (array)($l['tags_used'] ?? []), 'hooks_used' => (array)($l['hooks_used'] ?? [])];
}

/** Records what a batch used (dates Y-m-d), and forgets entries older than 120 days. */
function pm_bank_mark(string $brand, array $seedDates, array $tagDates, array $hookDates): void
{
    $brand = pm_sx_brand($brand);
    $old = date('Y-m-d', strtotime('-120 days'));
    pm_update('social_bank', function (array $all) use ($brand, $seedDates, $tagDates, $hookDates, $old) {
        $l = (array)($all['ledger'][$brand] ?? []);
        foreach (['seeds_used' => $seedDates, 'tags_used' => $tagDates, 'hooks_used' => $hookDates] as $k => $new) {
            $cur = (array)($l[$k] ?? []);
            foreach ($new as $id => $d) {
                if ($id !== '' && (!isset($cur[$id]) || $cur[$id] < $d)) {
                    $cur[$id] = $d;
                }
            }
            $l[$k] = array_filter($cur, fn($d) => (string)$d >= $old);
        }
        $all['ledger'][$brand] = $l;
        return $all;
    }, fn() => []);
}

function pm_sx_tag(mixed $t): string
{
    $t = preg_replace('/[^\p{L}\p{N}_]/u', '', (string)$t);
    return $t === '' ? '' : '#' . $t;
}

function pm_sx_days_between(string $a, string $b): int
{
    return (int)floor(abs(strtotime($b) - strtotime($a)) / 86400);
}

/* ---------------- pillars ---------------- */

/** What a pillar is for, from its name (owners can rename pillars freely). */
function pm_sx_pillar_class(string $name): string
{
    $n = strtolower($name);
    foreach ([
        'giveaway' => '/giveaway|competition/', 'proof' => '/proof|testimonial|review|client stor/', 'offer' => '/offer|special|promo|discount/',
        'spotlight' => '/spotlight|featured stay|stay of the/', 'freecheck' => '/free (website )?check|free audit|free look|free (quote|estimate|trial|consult|assessment|sample|demo|first)/', 'hostsignup' => '/host sign|sign-?up|list your|become a host/',
        'guesttips' => '/guest tip|traveller tip|traveler tip/', 'hosttips' => '/host tip|owner tip|hosting/', 'destination' => '/destination|inspiration|place|where to go/',
        'practical' => '/practical|travel info|getting there|how to get/', 'behind' => '/behind|our team|how we work|about us/', 'story' => '/story|problem|case study/',
    ] as $class => $re) {
        if (preg_match($re, $n)) {
            return $class;
        }
    }
    return 'tip';
}

/** Per-class settings: seed kinds it can use, audience, colour, card layout, CTAs, hook patterns. */
const PM_SX_CLASSES = [
    'tip'         => ['kinds' => ['pain', 'benefit', 'module', 'support', 'fact', 'idea', 'occasion'], 'aud' => '', 'color' => '#1D6FB8', 'cta' => ['save', 'comment', 'share', 'whatsapp'], 'label' => 'TIP'],
    'story'       => ['kinds' => ['pain'], 'aud' => '', 'color' => '#B45309', 'cta' => ['comment', 'whatsapp', 'share'], 'label' => 'REAL LIFE'],
    'proof'       => ['kinds' => ['fact'], 'aud' => 'host', 'color' => '#047857', 'cta' => ['whatsapp', 'save'], 'label' => 'CLIENT STORY'],
    'offer'       => ['kinds' => ['fact'], 'aud' => 'guest', 'color' => '#B91C1C', 'cta' => ['whatsapp'], 'label' => 'OFFER'],
    'behind'      => ['kinds' => ['idea', 'fact', 'support'], 'aud' => '', 'color' => '#6D28D9', 'cta' => ['comment', 'share', 'save'], 'label' => 'BEHIND THE SCENES'],
    'freecheck'   => ['kinds' => ['magnet'], 'aud' => '', 'color' => '#0E7490', 'cta' => ['check'], 'label' => 'FREE CHECK'],
    'hostsignup'  => ['kinds' => ['magnet'], 'aud' => 'host', 'color' => '#047857', 'cta' => ['host'], 'label' => 'FOR HOSTS'],
    'hosttips'    => ['kinds' => ['fact', 'idea', 'season', 'occasion'], 'aud' => 'host', 'color' => '#1D6FB8', 'cta' => ['save', 'comment', 'share'], 'label' => 'HOST TIP'],
    'guesttips'   => ['kinds' => ['idea', 'place', 'season', 'occasion'], 'aud' => 'guest', 'color' => '#1D4ED8', 'cta' => ['save', 'share', 'tag', 'comment'], 'label' => 'TRAVEL TIP'],
    'practical'   => ['kinds' => ['place', 'season', 'idea', 'occasion'], 'aud' => 'guest', 'color' => '#475569', 'cta' => ['save', 'share', 'tag'], 'label' => 'GOOD TO KNOW'],
    'destination' => ['kinds' => ['place', 'season'], 'aud' => 'guest', 'color' => '#0F766E', 'cta' => ['tag', 'save', 'share', 'comment'], 'label' => 'DISCOVER MALAWI'],
    'spotlight'   => ['kinds' => ['stay'], 'aud' => 'guest', 'color' => '#9D174D', 'cta' => ['whatsapp', 'tag', 'share'], 'label' => 'STAY SPOTLIGHT'],
    'giveaway'    => ['kinds' => ['giveaway'], 'aud' => '', 'color' => '#C2410C', 'cta' => ['comment'], 'label' => 'GIVEAWAY'],
];

/** Classes only their own seeds may feed. */
const PM_SX_EXCLUSIVE = ['proof', 'offer', 'spotlight', 'freecheck', 'hostsignup', 'giveaway'];

/** The brand's pillars [['name','weight','class']]: the owner's list from Accounts, else the built-in one (which includes Free check / Host sign-up / Guest tips). */
function pm_sx_pillars(string $brand): array
{
    $saved = function_exists('pm_social_settings') ? (array)(pm_social_settings($brand)['pillars'] ?? []) : [];
    $rows = [];
    foreach ($saved as $r) {
        $n = trim((string)($r['name'] ?? ''));
        if ($n !== '' && (int)($r['weight'] ?? 0) > 0) {
            $rows[] = ['name' => mb_substr($n, 0, 40), 'weight' => min(100, (int)$r['weight'])];
        }
    }
    $rows = $rows ?: pm_default_pillars($brand);
    foreach ($rows as $i => $r) {
        $rows[$i]['class'] = pm_sx_pillar_class($r['name']);
    }
    return $rows;
}

/** Colour (#rrggbb) of a pillar's eyebrow label. */
function pm_sx_pillar_color(string $pillar): string { return PM_SX_CLASSES[pm_sx_pillar_class($pillar)]['color']; }

/* ---------------- hook patterns ---------------- */

/** id => [label for the writer, classes, seed kinds, min items]. Order is the round-robin order. */
function pm_sx_hook_patterns(): array
{
    $all = ['tip', 'story', 'hosttips', 'guesttips', 'behind', 'practical', 'destination'];
    return [
        'myth'        => ['Myth: <what many people believe>. Fact: <what is true>.', ['tip', 'story', 'hosttips', 'guesttips'], ['pain', 'benefit', 'fact', 'idea', 'module'], 0],
        'signs'       => ['<n> signs your <thing> is costing you (use the listed items)', ['tip', 'story', 'hosttips'], ['pain', 'idea'], 3],
        'beforeafter' => ['Before: <the problem>. After: <how it stops>.', ['story', 'tip'], ['pain'], 0],
        'question'    => ['Open with a question the reader can answer in one line', $all, ['pain', 'benefit', 'fact', 'idea', 'module', 'support', 'place', 'season'], 0],
        'stop'        => ['Stop doing <the habit>. Do <the better habit> instead.', ['tip', 'hosttips', 'guesttips'], ['idea', 'pain', 'benefit'], 0],
        'howto'       => ['How to <do one thing> in simple steps', ['tip', 'hosttips', 'guesttips', 'practical'], ['idea', 'module', 'support', 'benefit', 'place'], 0],
        'faq'         => ['A question we get asked, answered plainly', ['tip', 'behind', 'hosttips'], ['fact', 'module', 'support', 'benefit'], 0],
        'place'       => ['Why <the place> is worth the trip', ['destination', 'practical', 'guesttips'], ['place'], 0],
        'season'      => ['A seasonal idea for the trip (no dates or numbers)', ['destination', 'practical', 'guesttips', 'hosttips'], ['season'], 0],
        'quote'       => ['The client\'s words, exactly as given', ['proof'], ['fact'], 0],
        'offer'       => ['The offer exactly as given, with its end date', ['offer'], ['fact'], 0],
        'spot'        => ['A friendly introduction to the stay, using only the facts', ['spotlight'], ['stay'], 0],
        'magnet'      => ['Invite the reader to send one word on WhatsApp', ['freecheck', 'hostsignup'], ['magnet'], 0],
        'occasion'    => ['Tie the post to the occasion in a useful, low-key way', $all, ['occasion'], 0],
        'giveaway'    => ['fixed giveaway text', ['giveaway'], ['giveaway'], 0],
        'plain'       => ['One clear idea in plain words', array_keys(PM_SX_CLASSES), ['pain', 'benefit', 'module', 'support', 'fact', 'idea', 'place', 'stay', 'season', 'occasion', 'magnet', 'giveaway'], 0],
    ];
}

/** Round robin: the pattern not used for the longest time, never one used in the last 14 days while another is free. */
function pm_sx_hook_pick(array $seed, string $class, array $hooksUsed, array $inBatch, string $ref): string
{
    $items = count((array)($seed['items'] ?? []));
    $fit = [];
    foreach (pm_sx_hook_patterns() as $id => [$label, $classes, $kinds, $minItems]) {
        if ($id === 'plain' || !in_array($class, $classes, true) || !in_array($seed['kind'], $kinds, true) || $items < $minItems) {
            continue;
        }
        $fit[] = $id;
    }
    if (!$fit) {
        return 'plain';
    }
    if (count($fit) === 1) {
        return $fit[0];
    }
    $score = function (string $id) use ($hooksUsed, $inBatch, $ref) {
        $d = $hooksUsed[$id] ?? '';
        $recent = $d !== '' && pm_sx_days_between($d, $ref) < PM_SX_HOOK_GAP_DAYS ? 1 : 0;
        return [in_array($id, $inBatch, true) ? 1 : 0, $recent, $d === '' ? '0000-00-00' : $d];
    };
    $best = $fit[0];
    foreach ($fit as $id) {
        if ($score($id) < $score($best)) {
            $best = $id;
        }
    }
    return $best;
}

/* ---------------- seeds ---------------- */

function pm_sx_charging(string $brand): bool
{
    return $brand === 'travel' && !empty(pm_load('settings', 'pm_default_settings')['travel']['charging']);
}

/** Facts that talk about "free / no commission" are dropped while Travel Malawi is charging. */
function pm_sx_clean_facts(string $brand, array $facts): array
{
    $out = [];
    foreach ($facts as $f) {
        $f = trim(preg_replace('/\s+/', ' ', (string)$f));
        if ($f === '' || (pm_sx_charging($brand) && preg_match('/\bfree\b|commission|no (listing )?fee/i', $f))) {
            continue;
        }
        $out[] = $f;
    }
    return array_values($out);
}

function pm_sx_seed(string $brand, string $kind, string $key, string $topic, array $facts, string $hint = 'any', string $aud = '', string $needs = '', array $extra = []): ?array
{
    $facts = pm_sx_clean_facts($brand, $facts);
    if (!$facts) {
        return null;
    }
    return $extra + ['id' => substr(sha1("$brand|$kind|$key"), 0, 10), 'kind' => $kind, 'pillar_hint' => $hint, 'topic' => mb_substr(trim($topic), 0, 120), 'facts' => $facts,
        'audience' => $aud, 'needs' => $needs, 'brand' => $brand];
}

/** The default text for the free-check / host-sign-up invitation (inbound's pm_magnet_keyword_line wins when it exists). */
function pm_sx_magnet_line(string $brand): string
{
    if (function_exists('pm_magnet_keyword_line')) {
        $l = trim((string)pm_magnet_keyword_line($brand));
        if ($l !== '') {
            return $l;
        }
    }
    if ($brand === 'travel') {
        return pm_sx_charging('travel') ? 'Send HOST on WhatsApp and we will help you list your stay.' : 'Send HOST on WhatsApp to list your stay free.';
    }
    if (pm_brand_is_custom($brand)) {
        return '';
    }
    return 'Send CHECK on WhatsApp and we will look at your website for free.';
}

/** A giveaway is live only with a complete, current config and the owner's rules tick. */
function pm_giveaway_active(string $brand, ?string $today = null): bool
{
    $g = pm_bank_get($brand)['giveaway'];
    $today ??= date('Y-m-d');
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
    return trim((string)$g['prize']) !== '' && $ok($g['start']) && $ok($g['end']) && $g['start'] <= $g['end'] && $g['end'] >= $today && !empty($g['rules_ok']);
}

/** The fixed, compliant giveaway wording. Deterministic: same config, same text. $g (prize, start, end) overrides the saved config, so a posted giveaway can be re-checked. */
function pm_giveaway_text(string $brand, ?array $g = null): string
{
    $b = pm_bank_get($brand)['giveaway'];
    $g = array_replace($b, array_intersect_key((array)$g, array_flip(['prize', 'start', 'end'])));
    $d = fn($x) => date('j F Y', strtotime((string)$x));
    $prize = trim((string)$g['prize']);
    $donor = !empty($b['donor_consent']) && trim((string)$b['donor']) !== '' ? ', kindly donated by ' . trim((string)$b['donor']) : '';
    return "GIVEAWAY: win $prize$donor.

How to enter: comment on this post between " . $d($g['start']) . ' and ' . $d($g['end']) . ". That is all. There is nothing to share or tag.

"
        . "Who can enter: people living in Malawi who are 18 or older.

How we choose: after " . $d($g['end']) . ", one winner is picked by a random draw from the people who commented. "
        . "We will message the winner and announce their first name here.

This giveaway is not sponsored, endorsed or administered by, or associated with, Meta.";
}

/** Bank files that change what seeds exist (used as a cache key). */
function pm_sx_bank_stamp(): string
{
    $s = '';
    foreach (['social_bank', 'social_proof', 'social_assets', 'settings', 'template'] as $n) {
        $f = PM_DATA . "/$n.json";
        clearstatcache(true, $f);
        $s .= is_file($f) ? filemtime($f) . ':' . filesize($f) . ';' : '-;';
    }
    return $s;
}

/** Group of items (>= 3) as a checklist seed. */
function pm_sx_group_seed(string $brand, string $key, string $topic, array $items, string $kind = 'pain'): ?array
{
    $items = array_values(array_filter($items, fn($i) => trim((string)($i['h'] ?? '')) !== ''));
    if (count($items) < 3) {
        return null;
    }
    $facts = [];
    foreach ($items as $i) {
        $facts[] = trim($i['h'] . (trim((string)($i['t'] ?? '')) !== '' ? ': ' . $i['t'] : ''));
    }
    return pm_sx_seed($brand, $kind, $key, $topic, $facts, 'any', '', '', ['items' => array_slice($items, 0, 6), 'n' => count($items)]);
}

/** Every seed the brand could write about today. Cached per request and data stamp. $start (Y-m-d) bounds the occasions looked at. */
function pm_social_bank(string $brand, ?string $start = null): array
{
    static $cache = [];
    $brand = pm_sx_brand($brand);
    $start ??= date('Y-m-d');
    $ck = $brand . '|' . $start . '|' . pm_sx_bank_stamp();
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $saved = pm_brand();
    pm_brand_set($brand);
    try {
        $seeds = pm_sx_bank_build($brand, $start);
    } finally {
        pm_brand_set($saved);
    }
    $cache = [$ck => $seeds];
    return $seeds;
}

function pm_sx_bank_build(string $brand, string $start): array
{
    $bank = pm_bank_get($brand);
    $seeds = [];
    $add = function (?array $s) use (&$seeds) {
        if ($s) {
            $seeds[$s['id']] = $s;
        }
    };
    // ProManaged: the proposal template's pain points, benefits, modules and support
    if ($brand === 'promanaged') {
        $tpl = pm_template();
        $labels = ['hotel' => ['hotels and lodges', 'hotel'], 'restaurant' => ['restaurants and bars', 'restaurant'], 'gym' => ['gyms', 'gym'], 'venue' => ['venues', 'venue'], 'retail' => ['shops', 'shop'], 'all' => ['businesses', 'business']];
        $groups = [];
        foreach ((array)($tpl['pain_points'] ?? []) as $p) {
            $pain = trim((string)($p['pain'] ?? ''));
            if ($pain === '') {
                continue;
            }
            $add(pm_sx_seed($brand, 'pain', $pain, $pain, [(string)($p['cost'] ?? ''), (string)($p['fix'] ?? '')], 'any', '', '', ['pain' => $pain, 'cost' => trim((string)($p['cost'] ?? '')), 'fix' => trim((string)($p['fix'] ?? '')), 'type' => (string)($p['type'] ?? 'all')]));
            $groups[(string)($p['type'] ?? 'all')][] = ['h' => $pain, 't' => trim((string)($p['fix'] ?? ''))];
        }
        foreach ($groups as $type => $items) {
            [$lab, $thing] = $labels[$type] ?? $labels['all'];
            $g = pm_sx_group_seed($brand, 'group-' . $type, 'Where ' . $lab . ' lose money', $items);
            if ($g) {
                $add($g + ['thing' => $thing]);
            }
        }
        foreach ((array)($tpl['benefits'] ?? []) as $b) {
            $add(pm_sx_seed($brand, 'benefit', (string)($b[0] ?? ''), (string)($b[0] ?? ''), [(string)($b[1] ?? '')]));
        }
        $mods = [];
        foreach ((array)($tpl['modules'] ?? []) as $m) {
            $area = trim((string)($m[0] ?? ''));
            if ($area !== '') {
                $add(pm_sx_seed($brand, 'module', $area, $area, [(string)($m[1] ?? '')]));
                $mods[] = ['h' => $area, 't' => trim((string)($m[1] ?? ''))];
            }
        }
        $g = pm_sx_group_seed($brand, 'group-modules', 'What one system can cover', array_slice($mods, 0, 6), 'module');
        $add($g ? $g + ['thing' => 'system'] : null);
        $sup = trim((string)($tpl['support_intro'] ?? ''));
        $add(pm_sx_seed($brand, 'support', 'support-intro', 'How support works', [$sup]));
    }
    // Marketing brain facts (both brands)
    if (function_exists('pm_brain')) {
        $br = pm_brain($brand);
        foreach (preg_split('/\R/', (string)($br['facts'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $aud = $brand === 'travel' && preg_match('/\bhosts?\b/i', $line) ? 'host' : '';
                $add(pm_sx_seed($brand, 'fact', $line, implode(' ', array_slice(explode(' ', $line), 0, 8)), [$line], 'any', $aud));
            }
        }
    }
    // starter and owner ideas
    $ideas = [];
    if (!empty($bank['builtin_ideas'])) {
        foreach (pm_default_ideas($brand) as $pillar => $texts) {
            foreach ($texts as $t) {
                $ideas[] = ['id' => 'b' . substr(sha1($brand . $t), 0, 8), 'text' => $t, 'pillar' => $pillar, 'active' => true, 'builtin' => true];
            }
        }
    }
    foreach ((array)$bank['ideas'] as $i) {
        if (trim((string)($i['text'] ?? '')) !== '' && ($i['active'] ?? true) !== false) {
            $ideas[] = $i;
        }
    }
    foreach ($ideas as $i) {
        $pn = trim((string)($i['pillar'] ?? ''));
        $cls = $pn === '' ? 'any' : pm_sx_pillar_class($pn);
        $aud = $brand === 'travel' ? (PM_SX_CLASSES[$cls]['aud'] ?? '') : '';
        $photo = !empty($i['photo']);
        $add(pm_sx_seed($brand, 'idea', (string)$i['id'], $i['text'], [(string)$i['text']], in_array($cls, PM_SX_EXCLUSIVE, true) ? 'any' : $cls, $aud, $photo ? 'asset' : '', ['owner' => empty($i['builtin']), 'idea_id' => (string)$i['id']]));
    }
    if ($brand === 'travel') {
        $add(pm_sx_seed($brand, 'season', 'dry', 'Trips in the dry season', ['The dry season, roughly May to October, is a popular time for lake, safari and hiking trips.'], 'any', 'guest', '', ['approx' => true]));
        $add(pm_sx_seed($brand, 'season', 'rainy', 'Trips in the rainy season', ['The rainy season, roughly November to April, brings green landscapes and often quieter stays.'], 'any', 'guest', '', ['approx' => true]));
        $add(pm_sx_seed($brand, 'season', 'host-dry', 'Get ready for the dry season', ['Before the busy dry season, refresh your photos and check your rates and your calendar.'], 'any', 'host', '', ['approx' => true]));
        $add(pm_sx_seed($brand, 'season', 'school', 'School holidays', ['School holidays are a good time for families to plan a short stay.'], 'any', 'guest', '', ['approx' => true]));
        // verified places the owner typed in
        foreach ((array)$bank['places'] as $pl) {
            $name = trim((string)($pl['place'] ?? ''));
            if ($name === '' || empty($pl['verified'])) {
                continue;
            }
            $facts = array_filter([
                trim((string)($pl['how_to_get'] ?? '')) !== '' ? "$name, getting there: " . trim($pl['how_to_get']) : '',
                trim((string)($pl['things_to_do'] ?? '')) !== '' ? "$name, things to do: " . trim($pl['things_to_do']) : '',
                trim((string)($pl['season'] ?? '')) !== '' ? "$name, best season: " . trim($pl['season']) : '',
            ]);
            $add(pm_sx_seed($brand, 'place', (string)($pl['id'] ?? $name), $name, $facts, 'any', 'guest', 'place', ['place' => $name, 'place_id' => (string)($pl['id'] ?? '')]));
        }
        // stays: consented proof rows of type stay, and consented photos with a note
        foreach (function_exists('pm_social_proof_usable') ? pm_social_proof_usable($brand) : [] as $r) {
            if (($r['type'] ?? '') === 'stay') {
                $add(pm_sx_seed($brand, 'stay', 'proof' . $r['id'], (string)($r['client_name'] ?: 'A stay'), [(string)$r['text']], 'spotlight', 'guest', 'proof', ['proof_id' => (string)$r['id'], 'host' => (string)$r['client_name']]));
            }
        }
        foreach (function_exists('pm_social_assets_usable') ? pm_social_assets_usable($brand) : [] as $a) {
            $has = array_filter((array)($a['tags']['pillars'] ?? []), fn($p) => pm_sx_pillar_class((string)$p) === 'spotlight');
            if ($has && trim((string)($a['note'] ?? '')) !== '') {
                $add(pm_sx_seed($brand, 'stay', 'asset' . $a['id'], (string)(($a['owner'] ?? '') ?: 'A stay'), [(string)$a['note']], 'spotlight', 'guest', 'asset', ['asset_id' => (string)$a['id'], 'host' => (string)($a['owner'] ?? '')]));
            }
        }
    }
    // proof and offers from the proof bank (consent and expiry already checked by the core)
    foreach (function_exists('pm_social_proof_usable') ? pm_social_proof_usable($brand) : [] as $r) {
        $t = (string)($r['type'] ?? 'quote');
        if ($t === 'stay') {
            continue;
        }
        $hint = $t === 'offer' ? 'offer' : 'proof';
        $add(pm_sx_seed($brand, 'fact', 'proof' . $r['id'], (string)(($r['client_name'] ?? '') ?: ($t === 'offer' ? 'Offer' : 'Client')), [(string)$r['text']], $hint, $brand === 'travel' && $t === 'offer' ? 'guest' : '', 'proof',
            ['proof_id' => (string)$r['id'], 'host' => (string)($r['client_name'] ?? ''), 'ends' => (string)($r['expires'] ?? '')]));
    }
    // the invitation (free check / host sign-up): three angles, one fact
    $line = pm_sx_magnet_line($brand);
    $hint = $brand === 'travel' ? 'hostsignup' : 'freecheck';
    $angles = $brand === 'travel'
        ? ['List your stay with Travel Malawi', 'Direct bookings for independent stays', 'Put your lodge in front of travellers']
        : ['A closer look at your website', 'Do customers find you online?', 'Not sure what your website is missing?'];
    if (pm_brand_is_custom($brand)) { // the business's own free first step; with none set up there is nothing to invite people to
        $mg = trim((string)pm_brand_profile($brand)['magnet']);
        $angles = $mg !== '' && $line !== '' ? ['Get ' . $mg, 'Not sure where to start?', 'Ask us a question'] : [];
    }
    $extraFacts = $brand === 'travel' ? array_filter(preg_split('/\R/', (string)(pm_brain($brand)['facts'] ?? '')) ?: [], fn($l) => preg_match('/free|commission|pay the property|dashboard|control their own/i', $l)) : [];
    foreach ($angles as $k => $a) {
        $add(pm_sx_seed($brand, 'magnet', "magnet$k", $a, array_merge([$line], array_slice(array_values($extraFacts), 0, 3)), $hint, $brand === 'travel' ? 'host' : ''));
    }
    // occasions the owner can trust (confirmed only): the next 45 days
    $to = date('Y-m-d', strtotime("$start +45 days"));
    foreach (pm_malawi_occasions($start, $to, $brand) as $o) {
        if (!$o['confirmed']) {
            continue;
        }
        $when = str_starts_with($o['key'], 'payday') ? '' : ' on ' . date('j F', strtotime($o['date']));
        $add(pm_sx_seed($brand, 'occasion', $o['key'], $o['label'], [$o['hook'] . $when], 'any', $o['audience'], '', ['occasion' => ['key' => $o['key'], 'date' => $o['date']], 'date' => $o['date'], 'end' => $o['end'], 'lead_days' => $o['lead_days']]));
    }
    // giveaway
    if (pm_giveaway_active($brand)) {
        $g = $bank['giveaway'];
        $key = sha1($g['prize'] . $g['start'] . $g['end']);
        $add(pm_sx_seed($brand, 'giveaway', $key, 'Giveaway', [pm_giveaway_text($brand)], 'giveaway', '', '', ['giveaway' => ['start' => $g['start'], 'end' => $g['end'], 'prize' => $g['prize']], 'date' => $g['start'], 'end' => $g['end']]));
    }
    return array_values($seeds);
}

function pm_sx_seed_find(string $brand, string $id, ?string $start = null): ?array
{
    foreach (pm_social_bank($brand, $start) as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    return null;
}

/* ---------------- the picker ---------------- */

const PM_SX_FOCUS_BOOST = [
    'leads' => ['freecheck' => 1.6, 'hostsignup' => 1.6, 'offer' => 1.4, 'proof' => 1.3], 'tips' => ['tip' => 1.5, 'hosttips' => 1.4, 'guesttips' => 1.4, 'practical' => 1.3],
    'awareness' => ['behind' => 1.5, 'destination' => 1.4, 'spotlight' => 1.4], 'story' => ['story' => 1.5, 'proof' => 1.4],
];

function pm_sx_post_audience(array $p): string
{
    $a = (string)($p['audience'] ?? '');
    return in_array($a, ['host', 'guest'], true) ? $a : (PM_SX_CLASSES[pm_sx_pillar_class((string)($p['pillar'] ?? ''))]['aud'] ?? '');
}

/**
 * Picks $n slots for the brand. Deterministic: same posts, bank and ledger give the same slots. No AI.
 * Slot: pillar, seed (array), format, hook_pattern, cta, audience, layout, asset_id?, occasion?, expires?, occasion_date?, needs_asset?
 */
function pm_social_seed_pick(string $brand, int $n, array $posts, string $focus = 'mix', ?string $start = null): array
{
    $brand = pm_sx_brand($brand);
    $start ??= date('Y-m-d', strtotime('+1 day'));
    $ledger = pm_bank_ledger($brand);
    $bank = pm_bank_get($brand);
    $mine = array_values(array_filter($posts, fn($p) => ($p['brand'] ?? 'promanaged') === $brand));
    $live = array_filter($mine, fn($p) => !in_array($p['status'] ?? '', ['failed', 'expired'], true));
    $since = date('Y-m-d', strtotime("$start -" . PM_SX_WINDOW_DAYS . ' days'));
    $until = date('Y-m-d', strtotime("$start +" . PM_SX_WINDOW_DAYS . ' days'));
    $recent = array_values(array_filter($live, fn($p) => substr((string)($p['when'] ?? ''), 0, 10) >= $since && substr((string)($p['when'] ?? ''), 0, 10) <= $until));

    // seeds not used inside the gap
    $seeds = array_values(array_filter(pm_social_bank($brand, $start), function ($s) use ($ledger, $start) {
        $d = $ledger['seeds_used'][$s['id']] ?? '';
        return $d === '' || pm_sx_days_between($d, $start) >= PM_SX_SEED_GAP_DAYS;
    }));
    // pillars with weights: owner weight x measured performance x focus
    $perf = function_exists('pm_social_perf_weights') ? array_change_key_case((array)pm_social_perf_weights($brand)) : [];
    $pillars = [];
    foreach (pm_sx_pillars($brand) as $p) {
        $w = $p['weight'] * (float)($perf[strtolower($p['name'])] ?? 1) * (float)(PM_SX_FOCUS_BOOST[$focus][$p['class']] ?? 1);
        $p['w'] = max(0.1, $w);
        $pillars[strtolower($p['name'])] = $p;
    }
    $eligible = function (array $s, string $class): bool {
        if (!in_array($s['kind'], PM_SX_CLASSES[$class]['kinds'], true)) {
            return false;
        }
        $h = $s['pillar_hint'];
        if (in_array($class, PM_SX_EXCLUSIVE, true)) {
            return $h === $class;
        }
        return !in_array($h, PM_SX_EXCLUSIVE, true) && ($h === 'any' || $h === $class);
    };
    $taken = [];      // seed ids in this batch
    $slots = [];
    $assetsUsed = [];
    $needsAsset = count(array_filter($mine, fn($p) => ($p['status'] ?? '') === 'needs_asset'));
    $counts = [];
    foreach ($recent as $p) {
        $k = strtolower((string)($p['pillar'] ?? ''));
        $counts[$k] = ($counts[$k] ?? 0) + 1;
    }
    $audCount = ['host' => 0, 'guest' => 0];
    foreach ($recent as $p) {
        $a = pm_sx_post_audience($p);
        if ($a !== '') {
            $audCount[$a]++;
        }
    }
    $ratio = $bank['ratio'];
    $hostShare = max(0, min(100, (int)$ratio['host'])) / max(1, (int)$ratio['host'] + (int)$ratio['guest']);
    $prev = '';
    $lastBefore = $mine;
    usort($lastBefore, fn($a, $b) => strcmp((string)($b['when'] ?? ''), (string)($a['when'] ?? '')));
    foreach ($lastBefore as $p) {
        if (substr((string)($p['when'] ?? ''), 0, 10) <= $start && !in_array($p['status'] ?? '', ['failed', 'expired'], true)) {
            $prev = strtolower((string)($p['pillar'] ?? ''));
            break;
        }
    }
    $recentCtas = array_slice(array_map(fn($p) => (string)($p['cta'] ?? ''), $lastBefore), 0, 2);
    $hooksBatch = [];

    $candidates = function (string $pk) use ($pillars, $seeds, &$taken, $eligible, $ledger, $start) {
        $class = $pillars[$pk]['class'];
        $list = array_values(array_filter($seeds, fn($s) => !isset($taken[$s['id']]) && $eligible($s, $class)));
        usort($list, function ($a, $b) use ($ledger, $start) {
            $key = fn($s) => [!empty($s['owner']) ? 0 : 1, ($ledger['seeds_used'][$s['id']] ?? '0000-00-00'), crc32($s['id'] . '|' . $start)];
            return $key($a) <=> $key($b);
        });
        return $list;
    };
    $pairAsset = function (array $seed, string $pillarName) use ($brand, &$assetsUsed): ?array {
        if (!function_exists('pm_social_assets_usable')) {
            return null;
        }
        if (!empty($seed['asset_id']) && ($a = pm_social_asset_find((string)$seed['asset_id']))) {
            return $a;
        }
        $filter = ['pillar' => $pillarName, 'place' => (string)($seed['place'] ?? ''), 'owner' => (string)($seed['host'] ?? '')];
        foreach (pm_social_assets_usable($brand, $filter) as $a) {
            if (!in_array($a['id'], $assetsUsed, true)) {
                return $a;
            }
        }
        return null;
    };
    $makeSlot = function (array $seed, array $pillar) use (&$taken, &$assetsUsed, &$hooksBatch, &$recentCtas, &$needsAsset, $pairAsset, $ledger, $start, $brand) {
        $class = $pillar['class'];
        $asset = null;
        $wantsAsset = in_array($class, ['spotlight', 'destination', 'behind', 'proof'], true) || $seed['needs'] === 'asset';
        if ($wantsAsset) {
            $asset = $pairAsset($seed, $pillar['name']);
            if ($asset) {
                $assetsUsed[] = $asset['id'];
            }
        }
        $missing = !$asset && ($seed['needs'] === 'asset' || ($class === 'destination' && $seed['kind'] === 'place'));
        if ($missing && $needsAsset >= 2) {
            return null; // do not pile up posts waiting for a photo
        }
        if ($missing) {
            $needsAsset++;
        }
        $taken[$seed['id']] = true;
        $hook = pm_sx_hook_pick($seed, $class, $ledger['hooks_used'], $hooksBatch, $start);
        $hooksBatch[] = $hook;
        $ctas = PM_SX_CLASSES[$class]['cta'];
        $cta = $ctas[crc32($seed['id']) % count($ctas)];
        $bad = fn($c) => ($c === 'whatsapp' && in_array('whatsapp', array_slice($recentCtas, 0, 2), true)) || (count($ctas) > 1 && $c === ($recentCtas[0] ?? ''));
        for ($k = 0; $k < count($ctas) && $bad($cta); $k++) {
            $cta = $ctas[(array_search($cta, $ctas, true) + 1) % count($ctas)];
        }
        array_unshift($recentCtas, $cta);
        $slot = ['pillar' => $pillar['name'], 'seed' => $seed, 'format' => 'image', 'hook_pattern' => $hook, 'cta' => $cta, 'audience' => $seed['audience'] ?: (PM_SX_CLASSES[$class]['aud'] ?? ''), 'layout' => ''];
        if ($asset) {
            $slot['asset_id'] = $asset['id'];
        }
        if ($missing) {
            $slot['needs_asset'] = true;
        }
        if (!empty($seed['occasion'])) {
            $slot['occasion'] = $seed['occasion'];
            $slot['occasion_date'] = $seed['date'];
            $slot['expires'] = $seed['end'] ?? $seed['date'];
        }
        if (!empty($seed['giveaway'])) {
            $slot['giveaway'] = $seed['giveaway'];
            $slot['expires'] = $seed['end'];
            if ($seed['date'] > date('Y-m-d')) {
                $slot['occasion_date'] = $seed['date'];
            }
        }
        if ($class === 'offer' && !empty($seed['ends'])) {
            $slot['expires'] = $seed['ends'];
        }
        if (in_array($class, ['proof', 'offer'], true) && !empty($seed['proof_id'])) {
            $slot['proof_id'] = $seed['proof_id'];
        }
        return $slot;
    };
    $bump = function (array $pillar) use (&$counts, &$audCount, &$prev) {
        $k = strtolower($pillar['name']);
        $counts[$k] = ($counts[$k] ?? 0) + 1;
        $a = PM_SX_CLASSES[$pillar['class']]['aud'];
        if ($a !== '') {
            $audCount[$a]++;
        }
        $prev = $k;
    };

    // 1) forced slots: a live giveaway, then occasions whose lead time has begun
    foreach ($seeds as $s) {
        if ($s['kind'] === 'giveaway' && count($slots) < $n) {
            $slots[] = ['pillar' => 'Giveaway', 'seed' => $s, 'format' => 'image', 'hook_pattern' => 'giveaway', 'cta' => 'comment', 'audience' => '', 'layout' => '', 'giveaway' => $s['giveaway'], 'expires' => $s['end']]
                + ($s['date'] > date('Y-m-d') ? ['occasion_date' => $s['date']] : []);
            $taken[$s['id']] = true;
            break;
        }
    }
    $occ = array_values(array_filter($seeds, fn($s) => $s['kind'] === 'occasion' && $s['date'] >= $start && strtotime($s['date']) - strtotime($start) <= (14 + $n) * 86400 && strtotime($s['date']) - $s['lead_days'] * 86400 <= strtotime($start) + $n * 86400));
    usort($occ, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $occMax = $n >= 7 ? 2 : ($n >= 3 ? 1 : 0);
    foreach ($occ as $s) {
        if ($occMax <= 0 || count($slots) >= $n) {
            break;
        }
        $cls = array_values(array_filter($pillars, fn($p) => !in_array($p['class'], PM_SX_EXCLUSIVE, true) && in_array('occasion', PM_SX_CLASSES[$p['class']]['kinds'], true)
            && ($s['audience'] === '' || PM_SX_CLASSES[$p['class']]['aud'] === '' || PM_SX_CLASSES[$p['class']]['aud'] === $s['audience'])));
        usort($cls, fn($a, $b) => [($counts[strtolower($a['name'])] ?? 0) / $a['w'], $a['name']] <=> [($counts[strtolower($b['name'])] ?? 0) / $b['w'], $b['name']]);
        if (!$cls) {
            continue;
        }
        $slot = $makeSlot($s, $cls[0]);
        if ($slot) {
            $slots[] = $slot;
            $bump($cls[0]);
            $occMax--;
        }
    }
    // 2) the rest by rolling deficit
    $boost = function_exists('pm_sx_segment_boost') ? pm_sx_segment_boost($brand) : null;
    $boostCap = $boost ? max(1, (int)floor($n * $boost['share'])) : 0;
    $boostUsed = 0;
    $guard = 0;
    while (count($slots) < $n && $guard++ < $n * 6) {
        $avail = [];
        foreach ($pillars as $pk => $p) {
            if ($candidates($pk)) {
                $avail[$pk] = $p;
            }
        }
        if (!$avail) {
            break;
        }
        $pool = $avail;
        if ($brand === 'travel') { // alternate host / guest by the owner's ratio
            $totA = $audCount['host'] + $audCount['guest'];
            $want = $totA === 0 ? ($hostShare >= 0.5 ? 'host' : 'guest') : (($audCount['host'] / $totA) < $hostShare ? 'host' : 'guest');
            $f = array_filter($avail, fn($p) => in_array(PM_SX_CLASSES[$p['class']]['aud'], [$want, ''], true));
            $pool = $f ?: $avail;
        }
        $sumW = array_sum(array_column($avail, 'w')) ?: 1;
        $total = 0;
        foreach ($avail as $pk => $p) {
            $total += $counts[$pk] ?? 0;
        }
        $rank = [];
        foreach ($pool as $pk => $p) {
            $rank[] = ['pk' => $pk, 'deficit' => $p['w'] / $sumW * ($total + 1) - ($counts[$pk] ?? 0), 'w' => $p['w']];
        }
        usort($rank, fn($a, $b) => [round($b['deficit'], 6), $b['w'], $a['pk']] <=> [round($a['deficit'], 6), $a['w'], $b['pk']]);
        $pickPk = '';
        if ($boost && $boostUsed < $boostCap) { // C3-A08: of the three pillars most due, take the first that has a topic for the winning segment
            foreach (array_slice($rank, 0, 3) as $r) {
                foreach ($r['pk'] === $prev ? [] : $candidates($r['pk']) as $sd) {
                    if (pm_sx_seed_segment($sd, $brand) === $boost['segment']) {
                        $pickPk = $r['pk'];
                        break 2;
                    }
                }
            }
        }
        foreach ($pickPk === '' ? $rank : [] as $r) {
            if ($r['pk'] !== $prev) {
                $pickPk = $r['pk'];
                break;
            }
        }
        $pickPk = $pickPk ?: $rank[0]['pk'];
        $slot = null;
        $cands = $candidates($pickPk);
        if ($boost && $boostUsed < $boostCap) { // C3-A08: the best segment's topics come first, up to the cap
            usort($cands, fn($a, $b) => (int)(pm_sx_seed_segment($b, $brand) === $boost['segment']) <=> (int)(pm_sx_seed_segment($a, $brand) === $boost['segment']));
        }
        foreach ($cands as $seed) {
            $slot = $makeSlot($seed, $pillars[$pickPk]);
            if ($slot) {
                if ($boost && pm_sx_seed_segment($seed, $brand) === $boost['segment']) {
                    $boostUsed++;
                    $slot['segment_boost'] = $boost['segment'];
                }
                break;
            }
        }
        if (!$slot) { // that pillar only had seeds needing a photo we cannot queue: leave it out for this batch
            unset($pillars[$pickPk]);
            continue;
        }
        $slots[] = $slot;
        $bump($pillars[$pickPk]);
    }
    $slots = array_slice($slots, 0, $n);
    // 3) formats: carousel for checklist/FAQ/myth, at most one reel, at most one text status
    $reelBlocked = false;
    foreach ($lastBefore as $p) {
        if (($p['format'] ?? '') === 'reel') {
            $reelBlocked = ($p['status'] ?? '') === 'needs_video';
            break;
        }
    }
    $textDone = false;
    $reelIdx = -1;
    if ($n >= 3 && !$reelBlocked) {
        $cand = [];
        foreach ($slots as $i => $s) {
            $cls = pm_sx_pillar_class($s['pillar']);
            if (in_array($cls, ['tip', 'behind', 'story', 'hosttips', 'guesttips'], true) && empty($s['occasion']) && empty($s['needs_asset']) && !in_array($s['hook_pattern'], ['myth', 'signs'], true) && !preg_match('/checklist|faq|myth/i', $s['pillar'])) {
                $cand[] = $i;
            }
        }
        $reelIdx = $cand ? $cand[intdiv(count($cand), 2)] : -1;
    }
    foreach ($slots as $i => $s) {
        $cls = pm_sx_pillar_class($s['pillar']);
        $fmt = 'image';
        if (preg_match('/checklist|faq|myth/i', $s['pillar']) || in_array($s['hook_pattern'], ['myth', 'signs'], true)) {
            $fmt = 'carousel';
        } elseif ($i === $reelIdx) {
            $fmt = 'reel';
        } elseif ($s['hook_pattern'] === 'question' && !$textDone && $n >= 4 && empty($s['asset_id']) && empty($s['needs_asset'])) {
            $fmt = 'text';
            $textDone = true;
        }
        if (!empty($s['giveaway']) || in_array($cls, ['proof', 'offer', 'spotlight'], true)) {
            $fmt = 'image';
        }
        $slots[$i]['format'] = $fmt;
        $slots[$i]['layout'] = function_exists('pm_card_layout_for') ? pm_card_layout_for(['pillar' => $s['pillar'], 'hook_pattern' => $s['hook_pattern'], 'format' => $fmt, 'asset_id' => $s['asset_id'] ?? '', 'giveaway' => $s['giveaway'] ?? null]) : 'headline';
    }
    return $slots;
}

/* ---------------- hashtags ---------------- */

/** How many tags per channel, and from which buckets (local, niche, pillar), in order. */
function pm_sx_tag_plan(string $channel): array
{
    return match ($channel) {
        'facebook' => ['local', 'niche', 'pillar'],
        'instagram' => ['local', 'niche', 'pillar', 'niche', 'local'],
        'linkedin' => ['niche', 'pillar', 'local'],
        'tiktok' => ['local', 'niche', 'pillar', 'niche'],
        'youtube_short' => ['niche', 'pillar', 'local'],
        'x' => ['local', 'niche'],
        default => in_array($channel, ['google', 'whatsapp', 'whatsapp_status', 'whatsapp_channel'], true) ? [] : ['local', 'niche', 'pillar'],
    };
}

/**
 * Hashtags for a post on a channel. Keeps the tags the post already has (up to the channel's cap) and fills from the bank:
 * a new post rotates by the ledger (least recently used first); an existing post fills in a fixed per-post order, so a preview and the real post agree.
 */
function pm_social_hashtags(array $p, string $channel = 'facebook'): array
{
    $plan = pm_sx_tag_plan($channel);
    if (!$plan) {
        return [];
    }
    $brand = pm_sx_brand((string)($p['brand'] ?? pm_brand()));
    $bank = pm_bank_get($brand);
    $have = [];
    foreach ((array)($p['hashtags'] ?? []) as $t) {
        $t = pm_sx_tag($t);
        if ($t !== '' && !in_array(strtolower($t), array_map('strtolower', $have), true)) {
            $have[] = $t;
        }
    }
    $cap = count($plan);
    $out = array_slice($have, 0, $cap);
    $pillarTags = $bank['tags']['pillar'][(string)($p['pillar'] ?? '')] ?? ($bank['tags']['pillar']['_default'] ?? []);
    if (!$pillarTags && !empty($p['pillar'])) {
        foreach ($bank['tags']['pillar'] as $name => $l) {
            if (strcasecmp($name, (string)$p['pillar']) === 0) {
                $pillarTags = $l;
            }
        }
    }
    $buckets = ['local' => $bank['tags']['local'], 'niche' => $bank['tags']['niche'], 'pillar' => $pillarTags];
    $fixed = !empty($p['id']) && $have;
    $used = $bank['ledger']['tags_used'];
    foreach ($plan as $slotNo => $bucket) {
        if (count($out) >= $cap) {
            break;
        }
        if ($slotNo < count($have) && $slotNo < $cap) {
            continue; // an existing tag already fills this place
        }
        $list = array_values(array_filter($buckets[$bucket], fn($t) => !in_array(strtolower($t), array_map('strtolower', $out), true)));
        if (!$list) {
            continue;
        }
        usort($list, function ($a, $b) use ($fixed, $used, $p) {
            $k = fn($t) => $fixed ? [0, crc32(($p['id'] ?? '') . $t)] : [$used[$t] ?? '0000-00-00', crc32($t . '|' . ($p['pillar'] ?? ''))];
            return $k($a) <=> $k($b);
        });
        $out[] = $list[0];
    }
    return array_slice($out, 0, $cap);
}
