<?php
/**
 * Businesses ("brands"). ProManaged IT and Travel Malawi are built in; any other business is added on the Business screen:
 * a short questionnaire (name, what it sells, who buys, where, voice) creates it, and from then on every part of the app
 * (agents, replies, WhatsApp, social, reports) treats it as its own business, with its own voice, mailbox, numbering and limits.
 *
 * Storage. The built-ins keep their original places. A custom business uses:
 *   data/brands.json                  id => {id, name, created, daily_run, archived}
 *   data/settings.json ["brands"][id] its details, mailbox, "brain" (what the AI may say) and social settings (same shape as ["travel"])
 *   data/agents_config.json [id]      its targets, limits and switches
 * Everything else (leads, posts, experiments ...) is already keyed by the brand id, so it just works with a new id.
 *
 * Rule for callers: never write `$brand === 'travel' ? ... : ...` to mean "the other business". Ask this file.
 */

require_once __DIR__ . '/brand_study.php'; // reading a business's website and learning who to target
require_once __DIR__ . '/onepager.php'; // the one-page offer that replaces a proposal for an added business
require_once __DIR__ . '/suggest.php'; // suggestions while typing in the "find a business" boxes
require_once __DIR__ . '/brand_delete.php'; // bringing a hidden business back, or deleting an added one for good

const PM_BUILTIN_BRANDS = ['promanaged' => 'ProManaged IT', 'travel' => 'Travel Malawi'];
const PM_BRAND_RESERVED = ['promanaged', 'travel', 'default', 'all', 'brands', 'new', 'settings', 'tm', 'pm', 'shared', 'none', 'other', 'rows', 'posts', 'summary', 'items', 'models', 'catalog', 'auth', 'state']; // (the last ones are keys inside the data files)
const PM_BRAND_COLORS = ['#1f6feb', '#0f766e', '#b45309', '#7c3aed', '#be123c', '#0369a1', '#4d7c0f', '#c2410c'];

/** Custom businesses from data/brands.json (id => row). Read straight from disk: no file is created by asking. */
function pm_brands_custom(bool $withArchived = false): array
{
    $f = PM_DATA . '/brands.json';
    $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $r = is_array($r) ? $r : [];
    foreach ($r as $id => $row) {
        if (!is_array($row) || !preg_match('/^[a-z][a-z0-9]{1,15}$/', (string)$id) || in_array($id, PM_BRAND_RESERVED, true)) {
            unset($r[$id]);
        } elseif (!$withArchived && !empty($row['archived'])) {
            unset($r[$id]);
        }
    }
    return $r;
}

/** Every active business id: the two built-ins first, then the ones added on the Business screen. */
function pm_brand_ids(): array
{
    return array_merge(array_keys(PM_BUILTIN_BRANDS), array_keys(pm_brands_custom()));
}

function pm_brand_is_custom(string $b): bool
{
    return !isset(PM_BUILTIN_BRANDS[$b]) && isset(pm_brands_custom()[$b]);
}

function pm_brand_valid(string $b): bool
{
    return isset(PM_BUILTIN_BRANDS[$b]) || isset(pm_brands_custom()[$b]);
}

/** A known business id, or 'promanaged' (what the app has always done with an unknown value). */
function pm_brand_norm(mixed $b): string
{
    $b = is_string($b) ? $b : '';
    return pm_brand_valid($b) ? $b : 'promanaged';
}

/** The name shown for a business (labels, alerts, log lines). */
function pm_brand_name(string $b): string
{
    if (isset(PM_BUILTIN_BRANDS[$b])) {
        return PM_BUILTIN_BRANDS[$b];
    }
    $row = pm_brands_custom(true)[$b] ?? null;
    if (!$row) {
        return PM_BUILTIN_BRANDS['promanaged'];
    }
    $raw = pm_load('settings', 'pm_default_settings');
    return trim((string)($raw['brands'][$b]['company_name'] ?? '')) ?: (string)($row['name'] ?? $b);
}

/** The prefix of this business's own .env keys: '' (ProManaged), 'TM_' (Travel Malawi), 'ACME_' (a business with id "acme"). */
function pm_brand_env_prefix(string $b): string
{
    return $b === 'promanaged' ? '' : ($b === 'travel' ? 'TM_' : (pm_brand_is_custom($b) ? strtoupper($b) . '_' : ''));
}

/** This business's own settings block out of a settings array: the whole array for ProManaged, ["travel"], or ["brands"][id]. */
function pm_brand_block(array $s, string $b): array
{
    if ($b === 'travel') {
        return (array)($s['travel'] ?? []);
    }
    return pm_brand_is_custom($b) ? (array)($s['brands'][$b] ?? []) : $s;
}

/** Put a business's settings block back into a settings array (ProManaged's values live at the top level). */
function pm_brand_block_put(array &$s, string $b, array $block): void
{
    if ($b === 'travel') {
        $s['travel'] = $block;
    } elseif (pm_brand_is_custom($b)) {
        $s['brands'][$b] = $block;
    } else {
        $s = array_replace($s, $block);
    }
}

/** The stored (raw) settings value for one business, e.g. pm_brand_setting('acme', 'google_review_url'). */
function pm_brand_setting(string $b, string $key, mixed $default = ''): mixed
{
    $raw = pm_load('settings', 'pm_default_settings');
    $blk = $b === 'promanaged' ? $raw : (array)($b === 'travel' ? ($raw['travel'] ?? []) : ($raw['brands'][$b] ?? []));
    return $blk[$key] ?? $default;
}

/**
 * One of a business's OWN .env values: its prefix plus the key (ProManaged: WA_BIZ_TOKEN, Travel Malawi: TM_WA_BIZ_TOKEN, a business with id "acme": ACME_WA_BIZ_TOKEN).
 * It never falls back to another business's value, so a business without its own WhatsApp number or X account never speaks through someone else's.
 * A hidden or unknown business has no values at all.
 */
function pm_brand_env(string $brand, string $key): string
{
    return pm_brand_valid($brand) ? pm_env_val(pm_brand_env_prefix($brand) . $key) : '';
}

/** Where this business's own pictures live. $what: logo | signature | favicon | hero. */
function pm_brand_asset(string $b, string $what): string
{
    if ($b === 'promanaged') {
        return ['logo' => 'assets/logo.png', 'signature' => 'assets/signature.png', 'favicon' => 'assets/favicon.png', 'hero' => 'assets/promanaged_hero.jpg'][$what] ?? '';
    }
    if ($b === 'travel') {
        return ['logo' => 'assets/travel_logo.png', 'signature' => 'assets/travel_signature.png', 'favicon' => 'assets/travel_favicon.png', 'hero' => 'assets/travel_hero.jpg'][$what] ?? '';
    }
    return 'assets/brand_' . preg_replace('/[^a-z0-9]/', '', $b) . '_' . preg_replace('/[^a-z]/', '', $what) . '.png';
}

/** A short unique id from a business name: "Acme Solar Ltd" -> "acmesolarltd" (letters and digits only, never a reserved word). */
function pm_brand_slug(string $name): string
{
    $t = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $s = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '', $t !== '' ? $t : $name)), 0, 12);
    $s = $s === '' ? 'biz' : ($s[0] >= '0' && $s[0] <= '9' ? 'b' . $s : $s);
    $all = pm_brands_custom(true);
    $base = $s;
    $own = function_exists('pm_agents_default_config') ? pm_agents_default_config() : []; // ProManaged IT's own settings sit at the top of agents_config: "Cities" or "Sectors" must not become a business id there
    for ($i = 2; in_array($s, PM_BRAND_RESERVED, true) || isset($all[$s]) || array_key_exists($s, $own); $i++) {
        $s = substr($base, 0, 12) . $i;
    }
    return $s;
}

/** The things the code needs to know about a business that are not in its brain: market, dial code, who it sells to, its free first step. */
function pm_brand_profile(string $b): array
{
    $d = ['market' => 'Malawi', 'dial' => '+265', 'sell_to' => 'business', 'magnet' => '', 'cta_keyword' => '', 'cta_text' => '', 'customers' => '', 'proposals' => false, 'kind' => 'custom'];
    if ($b === 'promanaged') {
        return ['sell_to' => 'business', 'proposals' => true, 'kind' => 'builtin', 'magnet' => 'a free website check', 'cta_keyword' => 'CHECK', 'cta_text' => 'Send CHECK on WhatsApp for a free website check'] + $d;
    }
    if ($b === 'travel') {
        return ['sell_to' => 'both', 'proposals' => true, 'kind' => 'builtin', 'magnet' => 'a free listing', 'cta_keyword' => 'HOST', 'cta_text' => 'Send HOST on WhatsApp to list your stay'] + $d;
    }
    $blk = pm_brand_setting($b, 'profile', []);
    $p = array_replace($d, array_filter((array)$blk, fn($v) => $v !== null && $v !== ''));
    $p['dial'] = preg_match('/^\+\d{1,4}$/', (string)$p['dial']) ? $p['dial'] : '+265';
    $p['sell_to'] = in_array($p['sell_to'], ['business', 'public', 'both'], true) ? $p['sell_to'] : 'business';
    if ($p['cta_keyword'] !== '' && $p['cta_text'] === '') {
        $p['cta_text'] = 'Send ' . $p['cta_keyword'] . ' on WhatsApp' . ($p['magnet'] !== '' ? ' for ' . $p['magnet'] : '');
    }
    return $p;
}

/** A business's own page address for the enquiry form: ?b=<id>. */
function pm_brand_enquiry_query(string $b): string
{
    return '?b=' . rawurlencode($b);
}

/* ---------------- Defaults for a new business ---------------- */

function pm_brand_default_block(string $id, string $name): array
{
    return [
        'company_name' => $name, 'tagline' => '', 'address' => '', 'phone' => '', 'email' => '', 'website' => '',
        'signatory_name' => '', 'signatory_title' => 'Owner',
        'accent_color' => PM_BRAND_COLORS[abs(crc32($id)) % count(PM_BRAND_COLORS)], 'from_email' => '',
        'link_url' => '', 'link_on' => false,
        'ref_prefix' => strtoupper(substr($id, 0, 3)), 'next_ref' => 1, 'online_signing' => false, 'esign_tags' => false,
        'smtp' => ['host' => '', 'port' => 465, 'encryption' => 'ssl', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => $name, 'bcc_self' => true],
        'imap' => ['host' => '', 'port' => 993, 'secure' => 'ssl'],
        'brain' => [], 'profile' => [], 'social' => [], 'social_auto' => false,
    ];
}

/** Targets and limits for a new business's agents. Conservative: the shared warm-up caps still apply on top. */
function pm_brand_agent_defaults(string $b): array
{
    $p = pm_brand_profile($b);
    $stored = (array)(pm_brand_setting($b, 'profile', []));
    return [
        'offerings' => (array)($stored['offerings'] ?? []), 'sectors' => (array)($stored['sectors'] ?? []), 'cities' => (array)($stored['cities'] ?? []),
        'existing_clients' => (array)($stored['existing_clients'] ?? []),
        'new_per_day' => 6, 'scouts_per_day' => 3, 'send_cap' => 6, 'wa_cap' => 20, 'followup_days' => 4, 'max_followups' => 2, 'proposals_per_day' => 0,
        // Proposals use ProManaged's own wording and terms, so they stay off for other businesses. A business that sells mainly to the public
        // is reached by social media, enquiries and partners, not by cold email.
        'enabled' => ['scout' => $p['sell_to'] !== 'public', 'contact' => true, 'qualifier' => true, 'research' => true, 'writer' => true, 'proposals' => false, 'followup' => true],
    ];
}

/** Voices the questionnaire offers. */
const PM_BRAND_VOICES = [
    'friendly' => 'Friendly, practical, honest, plain words.',
    'professional' => 'Professional, clear and courteous, plain words.',
    'warm' => 'Warm, local, proud of the place, plain words.',
    'bold' => 'Confident, energetic and direct, never hyped.',
];

/** Split free text into clean lines or comma items (max $max, each at most $len characters). */
function pm_brand_list(mixed $v, int $max = 10, int $len = 120): array
{
    $parts = is_array($v) ? $v : preg_split('/[\r\n;,]+/', (string)$v);
    $out = [];
    foreach ($parts as $x) {
        $x = trim(preg_replace('/\s+/', ' ', is_scalar($x) ? (string)$x : ''));
        if ($x !== '' && !in_array(mb_strtolower($x), array_map('mb_strtolower', $out), true)) {
            $out[] = mb_substr($x, 0, $len);
        }
    }
    return array_slice($out, 0, $max);
}

/* ---------------- The questionnaire ---------------- */

/** Checks the owner's answers. Returns a list of problems (empty = fine). Three answers are required; the rest only make the start better. */
function pm_brand_check_answers(array $a): array
{
    $bad = [];
    $name = trim((string)($a['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) {
        $bad[] = 'Give the business name (up to 60 characters).';
    } elseif (in_array(strtolower(preg_replace('/[^a-z0-9]+/i', '', $name)), ['promanaged', 'promanagedit', 'travelmalawi', 'travel'], true)) {
        $bad[] = 'That business already exists. Switch to it from the business menu.';
    }
    if (mb_strlen(trim((string)($a['sells'] ?? ''))) < 12) {
        $bad[] = 'Say in a sentence what the business does or sells.';
    }
    if (mb_strlen(trim((string)($a['customers'] ?? ''))) < 4) {
        $bad[] = 'Say who buys from you.';
    }
    foreach (['email' => FILTER_VALIDATE_EMAIL] as $k => $f) {
        if (trim((string)($a[$k] ?? '')) !== '' && !filter_var($a[$k], $f)) {
            $bad[] = 'That email address does not look right.';
        }
    }
    if (trim((string)($a['website'] ?? '')) !== '' && pm_study_url((string)$a['website']) === '') {
        $bad[] = 'That website address does not look right (for example www.example.mw).';
    }
    return $bad;
}

/** Numbers and money amounts in $text that the owner never wrote: those are inventions, so any fact carrying one is dropped. */
function pm_brand_unsourced_number(string $text, string $source): bool
{
    preg_match_all('/\d[\d,.]*/', $text, $m);
    foreach ($m[0] as $n) {
        $n = rtrim($n, ',.');
        if ($n !== '' && !str_contains($source, $n)) {
            return true;
        }
    }
    return false;
}

/**
 * A first draft of the business's profile from the answers, the business's own website and anything the owner pasted. One AI call (when the AI is set up)
 * learns what the business is, its facts (each one carried by a quote that is checked in code), who to target and what is still worth asking the owner;
 * otherwise, or if the call fails, it is a plain draft built straight from the owner's own words. Nothing is saved here.
 * $bundle is a website already read (pm_study_pages), so asking again does not fetch the pages again.
 * Returns ['draft' => [...], 'ai' => bool, 'note' => string, 'pages' => bundle].
 */
function pm_brand_draft(array $a, ?array $bundle = null): array
{
    $name = trim((string)($a['name'] ?? ''));
    $sells = trim((string)($a['sells'] ?? ''));
    $customers = trim((string)($a['customers'] ?? ''));
    $sellTo = in_array($a['sell_to'] ?? '', ['business', 'public', 'both'], true) ? $a['sell_to'] : 'business';
    $voiceKey = isset(PM_BRAND_VOICES[$a['voice'] ?? '']) ? $a['voice'] : 'friendly';
    $notes = mb_substr(trim((string)($a['notes'] ?? '')), 0, 6000);
    $qa = pm_study_qa($a['qa'] ?? []);
    $ownerText = implode("\n", array_filter([$name, $sells, $customers, (string)($a['facts'] ?? ''), (string)($a['magnet'] ?? ''), (string)($a['cities'] ?? ''), (string)($a['targets'] ?? ''), $notes, implode("\n", array_column($qa, 'a'))]));
    $source = mb_strtolower($ownerText);
    $own = [
        'about' => mb_substr($sells, 0, 300),
        'offerings' => pm_brand_list($sells, 4, 160),
        'sectors' => pm_brand_list($a['targets'] ?? '', 10, 60),
        'cities' => pm_brand_list($a['cities'] ?? '', 12, 40),
        'facts' => pm_brand_list($a['facts'] ?? '', 8, 160),
        'audience' => mb_substr($customers, 0, 200),
        'voice' => PM_BRAND_VOICES[$voiceKey],
        'never' => trim((string)($a['never'] ?? '')) !== '' ? mb_substr(trim((string)$a['never']), 0, 200) : 'Never promise results, prices, deadlines or anything not in the facts.',
        'magnet' => mb_substr(trim((string)($a['magnet'] ?? '')), 0, 80),
        'cta_keyword' => '',
        'pillars' => [],
    ];
    if (count($own['offerings']) === 1 && mb_strlen($own['offerings'][0]) > 160) {
        $own['offerings'] = [mb_substr($own['offerings'][0], 0, 160)];
    }
    $bundle ??= pm_study_pages((string)($a['website'] ?? ''));
    $pages = (array)($bundle['pages'] ?? []);
    $out = $own + ['targeting' => ['segments' => [], 'skip' => [], 'angles' => [], 'cities' => []], 'questions' => [], 'sources' => array_fill_keys($own['facts'], 'you'), 'found' => []];
    $found = [];
    if (trim((string)($a['email'] ?? '')) === '' && !empty($bundle['emails'])) {
        $found['email'] = (string)$bundle['emails'][0];
    }
    if (trim((string)($a['phone'] ?? '')) === '' && !empty($bundle['phones'])) {
        $found['phone'] = (string)$bundle['phones'][0];
    }
    if ($found) {
        $found['source'] = (string)($pages[0]['url'] ?? $bundle['url'] ?? '');
    }
    $out['found'] = $found;
    $ai = false;
    $note = (string)($bundle['note'] ?? '');
    if (function_exists('pm_agents_ready') && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        try {
            [$system, $user] = pm_study_prompt($a, $own, $pages, $qa);
            $r = pm_agent_json(pm_claude($system, $user, false, 3500, 'write'));
            if (is_array($r)) {
                $ai = true;
                $out = pm_study_merge($out, $r, ['own' => $own, 'source' => $source, 'owner_text' => $ownerText, 'pages' => $pages, 'pagetext' => mb_strtolower(implode("\n", array_column($pages, 'text')))]);
            } else {
                $note = trim($note . ' The AI answered, but not in a form we could use, so this is a plain draft from your answers. Go back and try again, or edit everything here.');
            }
        } catch (Throwable $e) {
            $note = trim($note . ' The AI draft did not work (' . mb_substr($e->getMessage(), 0, 80) . '), so this is a plain draft from your answers. You can edit everything.');
        }
    } else {
        $note = trim($note . ' The AI is not set up yet, so this is a plain draft from your answers. You can edit everything.');
    }
    if ($out['cta_keyword'] === '') {
        $out['cta_keyword'] = 'QUOTE';
    }
    if (!$out['pillars']) {
        $out['pillars'] = [['name' => 'Tip/How-to', 'weight' => 30], ['name' => 'Proof', 'weight' => 20], ['name' => 'Behind the scenes', 'weight' => 15], ['name' => 'Offer', 'weight' => 20], ['name' => 'Question', 'weight' => 15]];
    }
    if (!$out['sectors'] && $sellTo !== 'public') {
        $note = trim($note . ' Add the kinds of business to look for, or the scouts have nothing to search for.');
    }
    return ['draft' => $out, 'ai' => $ai, 'note' => $note, 'pages' => $bundle];
}

/**
 * Creates the business from the owner's answers and the (possibly edited) draft. Returns [id, errors]. Idempotent per name: a second call with the
 * same name makes a second business with a different id, so callers check pm_brand_check_answers first. Nothing is sent or switched on.
 */
function pm_brand_create(array $a, array $draft): array
{
    $bad = pm_brand_check_answers($a);
    if ($bad) {
        return ['', $bad];
    }
    $name = trim((string)$a['name']);
    $id = pm_brand_slug($name);
    $sellTo = in_array($a['sell_to'] ?? '', ['business', 'public', 'both'], true) ? $a['sell_to'] : 'business';
    $market = trim((string)($a['market'] ?? '')) ?: 'Malawi';
    $dial = preg_match('/^\+\d{1,4}$/', trim((string)($a['dial'] ?? ''))) ? trim((string)$a['dial']) : '+265';

    $blk = pm_brand_default_block($id, $name);
    foreach (['tagline', 'address', 'phone', 'email', 'website'] as $k) {
        $blk[$k] = trim((string)($a[$k] ?? ''));
    }
    foreach (['email', 'phone'] as $k) { // the draft screen shows what the owner typed or what the website showed, and the owner confirms it
        if (trim((string)($draft[$k] ?? '')) !== '' && ($k !== 'email' || filter_var(trim((string)$draft[$k]), FILTER_VALIDATE_EMAIL))) {
            $blk[$k] = mb_substr(trim((string)$draft[$k]), 0, 80);
        }
    }
    $blk['website'] = $blk['website'] !== '' ? preg_replace('#^https?://#i', '', rtrim($blk['website'], '/')) : '';
    $blk['smtp']['from_email'] = $blk['email'];
    $blk['brain'] = [
        'about' => trim((string)($draft['about'] ?? '')), 'facts' => implode("\n", pm_brand_list($draft['facts'] ?? [], 8, 160)),
        'audience' => trim((string)($draft['audience'] ?? '')), 'voice' => trim((string)($draft['voice'] ?? '')), 'never' => trim((string)($draft['never'] ?? '')),
    ];
    $blk['profile'] = [
        'market' => $market, 'dial' => $dial, 'sell_to' => $sellTo, 'customers' => trim((string)($a['customers'] ?? '')),
        'magnet' => trim((string)($draft['magnet'] ?? '')), 'cta_keyword' => strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($draft['cta_keyword'] ?? ''))),
        'offerings' => pm_brand_list($draft['offerings'] ?? [], 4, 200), 'sectors' => pm_brand_list($draft['sectors'] ?? [], 10, 60),
        'cities' => pm_brand_list($draft['cities'] ?? [], 12, 40), 'existing_clients' => [], 'pillars' => array_values(array_filter((array)($draft['pillars'] ?? []), fn($r) => trim((string)($r['name'] ?? '')) !== '')),
    ];
    $blk['profile']['targeting'] = pm_study_targeting_store((array)($draft['targeting'] ?? []), (array)$blk['profile']['sectors']); // who to target, with why: steers the scouts and the qualifier
    $blk['profile']['learned'] = ['at' => date('Y-m-d H:i'), 'from' => array_slice(array_values(array_filter(array_map('strval', (array)($draft['pages_read'] ?? [])))), 0, 6), 'ai' => !empty($draft['ai']),
        'qa' => pm_study_qa($a['qa'] ?? [])];
    if ($blk['profile']['magnet'] !== '' && $blk['profile']['cta_keyword'] !== '' && !array_filter($blk['profile']['pillars'], fn($r) => preg_match('/^free\b/i', (string)$r['name']))) {
        $blk['profile']['pillars'][] = ['name' => 'Free first step', 'weight' => 10]; // the invitation topic: posts that offer the free first step
    }

    pm_update('brands', function (array $all) use ($id, $name) {
        $all[$id] = ['id' => $id, 'name' => $name, 'created' => date('Y-m-d H:i:s'), 'daily_run' => true];
        return $all;
    }, fn() => []);
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['brands'][$id] = $blk;
    pm_save('settings', $raw);
    $cfg = pm_load('agents_config', 'pm_agents_default_config');
    $cfg[$id] = array_intersect_key(pm_brand_agent_defaults($id), array_flip(['sectors', 'cities', 'offerings', 'existing_clients', 'new_per_day', 'scouts_per_day', 'send_cap', 'wa_cap', 'followup_days', 'max_followups', 'proposals_per_day', 'enabled']));
    pm_save('agents_config', $cfg);
    return [$id, []];
}

/** Hides a business from the menus and stops its scheduled work. Nothing is deleted: bring it back with pm_brand_unarchive() (Settings > Hidden businesses), or delete it with pm_brand_delete(). */
function pm_brand_archive(string $id): bool
{
    if (!pm_brand_is_custom($id)) {
        return false;
    }
    pm_update('brands', function (array $all) use ($id) {
        $all[$id]['archived'] = date('Y-m-d H:i:s');
        return $all;
    }, fn() => []);
    return true;
}

/* ---------------- Is this business ready? ---------------- */

/** What is still missing for a business to work end to end: [['label','ok','hint'], ...]. Honest: only things that can be checked here. */
function pm_brand_checklist(string $b): array
{
    $was = pm_brand();
    pm_brand_set($b);
    $s = pm_settings();
    $sm = (array)($s['smtp'] ?? []);
    $rows = [];
    $rows[] = ['label' => 'Business details', 'ok' => trim((string)($s['email'] ?? '')) !== '' && trim((string)($s['company_name'] ?? '')) !== '', 'hint' => 'Add the business email address (and phone, website) in its settings.'];
    $rows[] = ['label' => 'Email sending', 'ok' => trim((string)($sm['host'] ?? '')) !== '' && trim((string)($sm['username'] ?? '')) !== '', 'hint' => 'Add the mail server login for this business in its settings, so outreach goes from its own address.'];
    $cfg = pm_agents_config($b);
    $rows[] = ['label' => 'Who to look for', 'ok' => !empty($cfg['sectors']) && !empty($cfg['cities']), 'hint' => 'Add the kinds of business and the cities to search in (Agents > Settings).'];
    $rows[] = ['label' => 'Facts the AI may use', 'ok' => trim((string)(pm_brain($b)['facts'] ?? '')) !== '', 'hint' => 'Add a few true statements about the business, one per line. The AI says nothing beyond them.'];
    pm_brand_set($was);
    return $rows;
}

/** The business name for headings: the name saved in its settings (so a renamed business reads right), else the built-in or onboarding name. */
function pm_brand_title(array $settings, string $b): string
{
    if ($b === 'promanaged') {
        return PM_BUILTIN_BRANDS['promanaged'];
    }
    $n = trim((string)(pm_brand_block($settings, $b)['company_name'] ?? ''));
    return $n !== '' ? $n : pm_brand_name($b);
}

/**
 * The WhatsApp invitation line for an added business, e.g. "Send QUOTE on WhatsApp for a free quote." Empty for the two original
 * businesses (they keep their own wording) and when the business has no free first step to offer: nothing is promised that was not set up.
 */
function pm_magnet_keyword_line(string $brand): string
{
    if (!pm_brand_is_custom($brand)) {
        return '';
    }
    $p = pm_brand_profile($brand);
    if (trim((string)$p['magnet']) === '' || trim((string)$p['cta_keyword']) === '') {
        return '';
    }
    return rtrim((string)$p['cta_text'], '.') . '.';
}

/**
 * Scheduler job (every 30 minutes, per business): the two original businesses have their own scheduled task; a business added in the app is started
 * from here, once a day after 07:30 on a weekday, so adding a business needs no change to the Windows task or the cron line.
 */
function pm_job_brand_agent_run(string $brand): string
{
    if (!pm_brand_is_custom($brand) || empty(pm_brands_custom()[$brand]['daily_run'])) {
        return '';
    }
    $now = function_exists('pm_now') ? pm_now() : time();
    if ((int)date('N', $now) > 5 || date('H:i', $now) < '07:30' || date('H:i', $now) > '17:00') {
        return '';
    }
    if (!function_exists('pm_agents_ready') || !pm_agents_ready() || (PHP_SAPI !== 'cli' && !function_exists('pm_agents_launch'))) {
        return '';
    }
    $st = pm_run_state();
    if (($st['state'] ?? '') === 'running' || str_starts_with((string)($st['started'] ?? ''), date('Y-m-d', $now))) {
        return '';
    }
    if (isset($GLOBALS['PM_BRAND_RUN_STUB']) && is_callable($GLOBALS['PM_BRAND_RUN_STUB'])) { // tests: record the launch, start nothing
        $GLOBALS['PM_BRAND_RUN_STUB']($brand);
        return 'daily agent run started';
    }
    return pm_agents_launch() ? 'daily agent run started' : '';
}
