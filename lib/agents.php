<?php
/**
 * Prospecting swarm: agents that find Malawian businesses needing ProManaged IT's systems,
 * qualify them, find contacts, draft outreach and plan each day's work.
 * Agents only ever DRAFT. Nothing is sent to a business until you press Send.
 */
require_once __DIR__ . '/store.php';

use Anthropic\Client;

const PM_LEAD_STATUSES = [
    'new' => 'New', 'qualified' => 'Qualified', 'drafted' => 'Draft ready', 'contacted' => 'Contacted',
    'replied' => 'Replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost', 'optout' => 'Do not contact',
];

/* ---------------- Configuration ---------------- */

function pm_agents_default_config(): array
{
    return [
        // What we sell. One per line: "Name: description". Every agent reads this, so edit it on the Agents tab.
        'offerings'       => [
            'Build: custom web apps, internal tools and dashboards, multi-tenant products with user accounts, business websites, and an industry management system (hotels and lodges, restaurants and bars, gyms, conference venues, retail)',
            'Source: finding, buying and delivering laptops, desktops, gaming PCs, monitors and peripherals, including items out of stock locally, with local-friendly payment on international orders',
            'Support: IT support, device setup and migration, email, backups and everyday security, remote support sessions, post-launch care',
        ],
        // Who to look for. Free text, one per search rotation.
        'sectors'         => ['hotels and lodges', 'restaurants and bars', 'gyms and fitness studios', 'conference venues', 'shops and supermarkets', 'schools and colleges',
                              'clinics and pharmacies', 'NGOs and associations', 'accounting and law firms', 'logistics and transport companies', 'farms and agribusiness', 'startups and small teams'],
        'reply_mode'      => 'draft',  // draft = you approve every reply; auto = simple, safe replies go out by themselves
        'wa_reply_mode'   => 'auto',   // WhatsApp Business answers: auto = simple, safe answers by themselves (ones that need a person stop); draft = you approve every answer
        'gemini_model'    => 'economy', // economy = cheapest model that works; auto = spread across the newest 3; or a model id
        'cities'          => ['Lilongwe', 'Blantyre', 'Mzuzu', 'Mangochi', 'Zomba', 'Salima', 'Nkhata Bay', 'Kasungu'],
        'new_per_day'     => 10,   // new leads the Scouts try to add each day
        'scouts_per_day'  => 4,    // sector x city searches per day (rotates through all combinations)
        'send_cap'        => 10,   // most emails you can send in one day
        'wa_cap'          => 40,   // most WhatsApp first messages per day (keeps the number from being flagged)
        'followup_days'   => 4,    // days of silence before a follow-up is drafted
        'max_followups'   => 3,    // then the plan switches to calls, WhatsApp and Facebook
        'parallel'        => 3,    // agents running at the same moment
        // Businesses we already serve (named on our website): never pitched as new ProManaged IT leads.
        'existing_clients' => ["Rosalyn's Beach Hotel", 'Liwonde Sun Hotel'],
        'proposals_per_day' => 2, // best leads get a tailored proposal prepared ahead of time (each costs an AI call)
        'research_per_day' => 5,  // best leads get their website, socials and news studied before the first message
        'daily_token_budget' => 400000, // hard stop for all AI use in a day, all brands together (0 = no limit)
        'quality' => 'economy',   // economy = cheapest models everywhere; balanced = a stronger model for web research and proposals
        'enabled'         => ['scout' => true, 'contact' => true, 'qualifier' => true, 'research' => true, 'writer' => true, 'proposals' => true, 'followup' => true],
    ] + (function_exists('pm_agents_extra_defaults') ? pm_agents_extra_defaults() : []);
}

/** Travel Malawi searches for stays only. */
function pm_travel_agent_defaults(): array
{
    return [
        'sectors' => ['lodges and guest houses', 'bed and breakfasts and cottages', 'safari camps and eco-lodges', 'lakeshore resorts and beach lodges', 'boutique and city hotels', 'backpackers and hostels'],
        'cities' => ['Cape Maclear', 'Nkhata Bay', 'Mangochi', 'Salima', 'Nkhotakota', 'Livingstonia', 'Karonga', 'Liwonde', 'Zomba', 'Mulanje', 'Blantyre', 'Lilongwe', 'Mzuzu', 'Chintheche'],
        'new_per_day' => 10, 'scouts_per_day' => 4, 'send_cap' => 10, 'wa_cap' => 40, 'followup_days' => 4, 'max_followups' => 2, 'proposals_per_day' => 2,
        'enabled' => ['scout' => true, 'contact' => true, 'qualifier' => true, 'research' => true, 'writer' => true, 'proposals' => true, 'followup' => true],
    ];
}

/** Index of the Travel Malawi onboarding package in the template. */
function pm_onboarding_index(): int
{
    foreach (pm_template()['packages'] as $i => $p) {
        if (($p['type'] ?? '') === 'onboarding') {
            return $i;
        }
    }
    return 0;
}

function pm_agents_config(?string $brand = null): array
{
    $brand ??= pm_brand();
    if ($brand === 'travel') {
        $base = pm_agents_config('promanaged');
        $stored = (array)(pm_load('agents_config', 'pm_agents_default_config')['travel'] ?? []);
        $t = array_replace_recursive(pm_travel_agent_defaults(), $stored);
        foreach (['sectors', 'cities'] as $k) {
            if (isset($stored[$k]) && is_array($stored[$k])) {
                $t[$k] = array_values($stored[$k]);
            }
        }
        return array_replace($base, $t); // shared: parallel, reply mode, Gemini model
    }
    $c = array_replace_recursive(pm_agents_default_config(), pm_load('agents_config', 'pm_agents_default_config'));
    foreach (['sectors', 'cities', 'offerings', 'existing_clients'] as $k) {
        $stored = pm_load('agents_config', 'pm_agents_default_config')[$k] ?? null;
        if (is_array($stored)) {
            $c[$k] = array_values($stored);
        }
    }
    $c['sectors'] = array_values(array_unique(array_map('pm_sector_label', $c['sectors'])));
    return $c;
}

/** The model sometimes echoes the option list; accept only one clean value. */
function pm_clean_offering(mixed $v): string
{
    $v = strtolower(trim((string)$v));
    return in_array($v, ['build', 'source', 'support'], true) ? $v : '';
}

/** Older settings stored sector keys (hotel, gym...); show them as plain-language targets. */
function pm_sector_label(string $x): string
{
    return ['hotel' => 'hotels and lodges', 'restaurant' => 'restaurants and bars', 'gym' => 'gyms and fitness studios', 'venue' => 'conference venues', 'retail' => 'shops and supermarkets'][$x] ?? $x;
}

function pm_key(string $name): string
{
    return trim((string)(pm_env()[$name] ?? getenv($name) ?: ''));
}

/**
 * Which AI does a kind of work: "web" (search tasks: Scout, Contact Finder) or "text" (Qualifier, Writer, Follow-up).
 * .env: AGENT_PROVIDER=anthropic|gemini (default for both), AGENT_WEB_PROVIDER, AGENT_TEXT_PROVIDER to mix them.
 * With no setting, uses whichever API key exists (Claude first).
 */
function pm_provider(bool $web): string
{
    $e = pm_env();
    $p = strtolower($e[$web ? 'AGENT_WEB_PROVIDER' : 'AGENT_TEXT_PROVIDER'] ?? $e['AGENT_PROVIDER'] ?? '');
    if (!in_array($p, ['anthropic', 'gemini'], true)) {
        $p = pm_key('ANTHROPIC_API_KEY') !== '' ? 'anthropic' : 'gemini';
    }
    return $p;
}

function pm_agents_ready(): bool
{
    foreach ([true, false] as $web) {
        if (pm_key(pm_provider($web) === 'gemini' ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY') === '') {
            return false;
        }
    }
    return true;
}

function pm_agents_missing_key(): string
{
    foreach ([true, false] as $web) {
        $k = pm_provider($web) === 'gemini' ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY';
        if (pm_key($k) === '') {
            return $k;
        }
    }
    return '';
}

/* ---------------- Storage ---------------- */

/**
 * Leads are shared by the web screens and by the agent run in the background. To stop one overwriting the other,
 * loading remembers what each lead looked like, and saving (under a lock) writes back only the leads this process
 * changed, added or removed, on top of whatever is on disk now.
 */
function pm_leads(): array
{
    $l = pm_load('leads', fn() => []);
    $GLOBALS['PM_LEADS_SNAP'] = array_map(fn($x) => md5(json_encode($x)), $l);
    return $l;
}

function pm_leads_save(array $leads): void
{
    $lock = @fopen(PM_DATA . '/leads.lock', 'c');
    if ($lock) {
        $t0 = time();
        while (!flock($lock, LOCK_EX | LOCK_NB)) { // never wait for ever (a crashed save must not freeze the next one)
            if (time() - $t0 >= 15) {
                fclose($lock);
                throw new RuntimeException('The leads file is busy; nothing was saved.');
            }
            usleep(100000);
        }
    }
    $snap = $GLOBALS['PM_LEADS_SNAP'] ?? null;
    if ($snap === null) {
        $out = $leads;
    } else {
        $out = pm_load('leads', fn() => []);
        foreach ($leads as $k => $v) {
            if (($snap[$k] ?? null) !== md5(json_encode($v))) {
                $out[$k] = $v; // changed or new in this process
            }
        }
        foreach ($snap as $k => $h) {
            if (!isset($leads[$k])) {
                unset($out[$k]); // removed in this process
            }
        }
    }
    pm_save('leads', $out);
    $GLOBALS['PM_LEADS_SNAP'] = array_map(fn($x) => md5(json_encode($x)), $leads);
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function pm_lead_id(string $name, string $city, string $brand = 'promanaged'): string
{
    $n = strtolower(preg_replace('/\b(hotel|lodge|ltd|limited|the|restaurant|bar|gym|&|and)\b|[^a-z0-9]+/i', '', $name));
    return substr(md5(($brand === 'travel' ? 'travel|' : '') . $n . '|' . strtolower(trim($city))), 0, 12);
}

function pm_lead_note(array &$lead, string $text): void
{
    $lead['notes'][] = ['at' => date('Y-m-d H:i'), 'text' => $text, 'by' => (string)($GLOBALS['PM_WHO'] ?? '')];
    $lead['updated'] = date('Y-m-d H:i');
}

/** Locked read-modify-write of a small JSON list (agent logs). */
function pm_log_push(string $name, array $row, int $keep, ?int $days = null): void
{
    $lock = @fopen(PM_DATA . "/$name.lock", 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    $log = pm_load($name, fn() => []);
    array_unshift($log, $row);
    if ($days !== null) {
        $min = date('Y-m-d H:i:s', time() - $days * 86400);
        $log = array_values(array_filter($log, fn($r) => ($r['at'] ?? '') >= $min));
    }
    pm_save($name, array_slice($log, 0, $keep));
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Routine lines go to agent_log (last 300). Failures also go to agent_errors (60 days) so they stay visible. $error null = detect from the text. */
function pm_agent_log(string $agent, string $msg, ?bool $error = null): void
{
    $row = ['at' => date('Y-m-d H:i:s'), 'agent' => $agent, 'msg' => (pm_brand() === 'travel' ? '[Travel Malawi] ' : '') . $msg];
    $error ??= (bool)preg_match('/\b(fail(ed|ure)?|could not|error|time[sd]? ?out|crash(ed)?|stalled|unparseable|exception|stopped)\b/i', $msg) && !preg_match('/\b0 errors\b/i', $msg);
    pm_log_push('agent_log', $row, 300);
    if ($error) {
        pm_log_push('agent_errors', $row + ['brand' => pm_brand()], 500, 60);
    }
}

/** Recent failures for the UI: newest first, [{at,agent,msg,brand}]. */
function pm_agent_errors_recent(int $days = 3): array
{
    $min = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    return array_values(array_filter(pm_load('agent_errors', fn() => []), fn($r) => ($r['at'] ?? '') >= $min));
}

function pm_run_state(?array $set = null): array
{
    $f = PM_DATA . (pm_brand() === 'travel' ? '/agent_run_travel.json' : '/agent_run.json');
    if ($set !== null) {
        file_put_contents($f, json_encode($set, JSON_PRETTY_PRINT), LOCK_EX);
        return $set;
    }
    $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (is_array($r) && ($r['state'] ?? '') === 'running' && time() - (int)($r['beat'] ?? 0) > 900) {
        $r['state'] = 'stalled';
    }
    return is_array($r) ? $r : ['state' => 'idle'];
}

/**
 * Dead-man check for the UI: '' when every brand's run looks healthy, else a one-line warning.
 * Stale = last finish (or heartbeat) older than run_stale_hours (36h), or 'running' with a heartbeat older than 15 min.
 */
function pm_run_stale_check(string $dir = ''): string
{
    $dir = $dir !== '' ? $dir : PM_DATA;
    $hours = max(1, (int)(pm_agents_config('promanaged')['run_stale_hours'] ?? 36));
    $msgs = [];
    foreach (['promanaged' => ['agent_run.json', 'ProManaged IT'], 'travel' => ['agent_run_travel.json', 'Travel Malawi']] as [$file, $name]) {
        $f = "$dir/$file";
        $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (!is_array($r)) {
            continue;
        }
        $state = (string)($r['state'] ?? '');
        $beat = (int)($r['beat'] ?? 0);
        $fin = strtotime((string)($r['finished'] ?? '')) ?: 0;
        if ($state === 'running' && $beat && time() - $beat > 900) {
            $msgs[] = "$name agents look stuck (no sign of life for " . (int)((time() - $beat) / 60) . ' minutes)';
        } elseif ($state !== 'running' && ($t = max($fin, $beat)) && time() - $t > $hours * 3600) {
            $msgs[] = "$name agents have not run for " . (int)((time() - $t) / 3600) . ' hours: check the scheduled task';
        }
    }
    return implode('. ', $msgs);
}

/* ---------------- Claude ---------------- */

/** Claude model: the smallest that does the job. Web research needs a model that supports the search tool. */
function pm_agent_model(bool $web = false, string $tier = 'cheap'): string
{
    return pm_env()['AGENT_MODEL'] ?? ($web || $tier !== 'cheap' ? 'claude-sonnet-5-5' : 'claude-haiku-5-5');
}

/**
 * One agent call. $web lets the agent search the web (scouts and contact finders).
 * Returns the agent's final text. Throws on API failure.
 */
function pm_claude(string $system, string $user, bool $web = false, int $maxTokens = 3000, string $tier = ''): string
{
    pm_budget_check();
    $tier = $tier ?: ($web ? 'std' : 'cheap');
    if (isset($GLOBALS['PM_AI_STUB']) && is_callable($GLOBALS['PM_AI_STUB'])) { // tests: no network; tokens are estimated so the cost logic still runs
        $out = (string)($GLOBALS['PM_AI_STUB'])($system, $user, $tier, $maxTokens);
        pm_usage_add('stub', 'stub-' . $tier, (int)ceil((strlen($system) + strlen($user)) / 4), (int)ceil(strlen($out) / 4));
        return $out;
    }
    if (getenv('PM_TEST')) {
        throw new RuntimeException('AI not stubbed');
    }
    return pm_ai_call($web, $system, $user, $maxTokens, $tier);
}
require_once __DIR__ . '/sx_learn.php';
require_once __DIR__ . '/sx_harvest.php';

/* ---------------- Spend control: every call is counted against a daily token budget ---------------- */

function pm_usage_file(): string { return PM_DATA . '/ai_usage.log'; }

function pm_usage_add(string $provider, string $model, int $in, int $out, int $think = 0, string $ref = ''): void
{
    $who = 'other';
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
        $fn = (string)($f['function'] ?? '');
        if ($fn !== '' && !in_array($fn, ['pm_usage_add', 'pm_claude', 'pm_gemini', 'pm_anthropic', 'pm_gemini_call', '{closure}'], true) && !str_starts_with($fn, 'pm_gemini') && !str_starts_with($fn, 'pm_anthropic')) {
            $who = preg_replace('/^pm_(agent_)?/', '', $fn);
            break;
        }
    }
    $brand = pm_brand();
    $ref = (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', $ref !== '' ? $ref : (string)($GLOBALS['PM_AI_REF'] ?? '')); // what the call was for, e.g. a post id
    @file_put_contents(pm_usage_file(), date('Y-m-d') . "\t$brand\t$provider\t$model\t$in\t$out\t$think\t$who\t$ref\n", FILE_APPEND | LOCK_EX);
    try { // daily totals by brand and agent survive the log being rotated
        pm_update('ai_usage_daily', function (array $d) use ($brand, $who, $in, $out, $think) {
            $day = date('Y-m-d');
            $r = $d[$day][$brand][$who] ?? ['in' => 0, 'out' => 0, 'calls' => 0];
            $d[$day][$brand][$who] = ['in' => (int)$r['in'] + $in, 'out' => (int)$r['out'] + $out + $think, 'calls' => (int)$r['calls'] + 1];
            if (count($d) > 400) {
                ksort($d);
                $d = array_slice($d, -400, null, true);
            }
            return $d;
        }, fn() => []);
    } catch (Throwable) {
    }
}

/** Today's totals: ['calls','in','out','think','total','models'=>[model=>tokens]]. */
function pm_usage_today(): array
{
    $u = ['calls' => 0, 'in' => 0, 'out' => 0, 'think' => 0, 'total' => 0, 'models' => [], 'agents' => []];
    $f = pm_usage_file();
    if (!is_file($f)) {
        return $u;
    }
    $today = date('Y-m-d');
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        $c = explode("\t", $line);
        if (($c[0] ?? '') !== $today || count($c) < 7) {
            continue;
        }
        $u['calls']++;
        $u['in'] += (int)$c[4];
        $u['out'] += (int)$c[5];
        $u['think'] += (int)$c[6];
        $u['models'][$c[3]] = ($u['models'][$c[3]] ?? 0) + (int)$c[4] + (int)$c[5] + (int)$c[6];
        $u['agents'][$c[7] ?? 'other'] = ($u['agents'][$c[7] ?? 'other'] ?? 0) + (int)$c[4] + (int)$c[5] + (int)$c[6];
    }
    $u['total'] = $u['in'] + $u['out'] + $u['think'];
    if (filesize($f) > 100000) {
        pm_usage_rotate($f, $today);
    }
    return $u;
}

/** Keeps the log small without losing writes made meanwhile: the old file is renamed (never rewritten) and today's lines are appended to the new one. */
function pm_usage_rotate(string $f, string $today): void
{
    $lock = @fopen(PM_DATA . '/ai_usage.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    try {
        clearstatcache(true, $f);
        if (!is_file($f) || filesize($f) <= 100000) {
            return; // another process just did it
        }
        $old = $f . '.' . date('Ymd-His');
        if (!@rename($f, $old)) {
            return; // a writer holds it right now (Windows): next call
        }
        $keep = '';
        foreach (file($old, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $keep .= str_starts_with($l, $today) ? $l . "\n" : '';
        }
        if ($keep !== '') {
            @file_put_contents($f, $keep, FILE_APPEND | LOCK_EX);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Tokens used so far today (all brands). The planner reads it before and after a call to learn what a batch cost. */
function pm_usage_mark(): int
{
    return (int)pm_usage_today()['total'];
}

/** Tokens (in + out) one brand used today, from the daily totals. */
function pm_usage_brand_today(string $brand): int
{
    $f = PM_DATA . '/ai_usage_daily.json';
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $n = 0;
    foreach ((array)($d[date('Y-m-d')][$brand] ?? []) as $r) {
        $n += (int)($r['in'] ?? 0) + (int)($r['out'] ?? 0);
    }
    return $n;
}

/** The brand's own soft cap (Social > Results > goals); 0 = none. */
function pm_brand_token_cap(string $brand): int
{
    $f = PM_DATA . '/social_goals.json';
    $g = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return max(0, (int)($g[$brand]['daily_token_budget'] ?? 0));
}

function pm_budget(): int
{
    return max(0, (int)(pm_agents_config('promanaged')['daily_token_budget'] ?? 400000));
}

function pm_budget_check(): void
{
    $b = pm_budget();
    if ($b > 0 && pm_usage_today()['total'] >= $b) {
        throw new RuntimeException('Daily AI budget of ' . number_format($b) . ' tokens reached. Raise it in Agent settings, or wait until tomorrow.');
    }
    $cap = pm_brand_token_cap(pm_brand());
    if ($cap > 0 && pm_usage_brand_today(pm_brand()) >= $cap) {
        throw new RuntimeException('This business reached its own daily AI limit of ' . number_format($cap) . ' tokens (Social > Results > goals). It continues tomorrow.');
    }
}

/* ---------------- Gemini: pick the cheapest model that works ---------------- */

/** Fallback if Google's model list can't be fetched. */
const PM_GEMINI_FALLBACK = ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3.6-flash'];

/**
 * Gemini text models from Google's list (refreshed daily), newest first: [['id','v','tier'(1 lite,2 flash,3 pro),'stable']].
 * Image, speech, live, embedding and tool-specific models are left out.
 */
function pm_gemini_catalog(bool $refresh = false): array
{
    $cache = pm_load('gemini_models', fn() => []);
    if (!$refresh && ($cache['date'] ?? '') === date('Y-m-d') && !empty($cache['catalog'])) {
        return $cache['catalog'];
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . pm_key('GEMINI_API_KEY')]]);
    pm_curl_native_ca($ch);
    $raw = curl_exec($ch);
    $list = is_string($raw) ? (json_decode($raw, true)['models'] ?? []) : [];
    curl_close($ch);
    $cat = [];
    foreach ($list as $m) {
        $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)
            || !preg_match('/^gemini-(\d+(?:\.\d+)?)-(pro|flash-lite|flash)(?:-(.+))?$/', $id, $x)
            || preg_match('/image|tts|live|audio|embed|vision|computer|robot|native|customtools|transcribe|latest/', $id)) {
            continue;
        }
        $cat[] = ['id' => $id, 'v' => (float)$x[1], 'tier' => ['flash-lite' => 1, 'flash' => 2, 'pro' => 3][$x[2]], 'stable' => !str_contains($id, 'preview') && !str_contains($id, 'exp')];
    }
    if (!$cat) {
        return $cache['catalog'] ?? array_map(fn($id) => ['id' => $id, 'v' => 0, 'tier' => str_contains($id, 'lite') ? 1 : 2, 'stable' => true], PM_GEMINI_FALLBACK);
    }
    usort($cat, fn($a, $b) => [$b['v'], $b['stable'], $b['tier']] <=> [$a['v'], $a['stable'], $a['tier']]);
    $top = [];
    $seen = [];
    foreach ($cat as $m) { // newest three distinct families, for the "spread" mode and the settings list
        $fam = $m['v'] . '-' . $m['tier'];
        if (!isset($seen[$fam]) && count($top) < 3) {
            $seen[$fam] = true;
            $top[] = $m['id'];
        }
    }
    pm_save('gemini_models', ['date' => date('Y-m-d'), 'models' => $top, 'catalog' => $cat]);
    return $cat;
}

/** The three newest Gemini models today, shown in settings. */
function pm_gemini_top_models(bool $refresh = false): array
{
    $cat = pm_gemini_catalog($refresh);
    $cache = pm_load('gemini_models', fn() => []);
    return $cache['models'] ?? array_slice(array_column($cat, 'id'), 0, 3);
}

function pm_model_bad(string $m): bool { return (pm_load('gemini_health', fn() => [])[$m] ?? 0) > time(); }

/** A model that hung or failed is skipped for a while, so one overloaded model cannot slow every run. */
function pm_model_mark_bad(string $m, int $secs = 7200): void
{
    $h = array_filter(pm_load('gemini_health', fn() => []), fn($t) => $t > time());
    $h[$m] = time() + $secs;
    pm_save('gemini_health', $h);
}

/**
 * Models to try for one request, cheapest suitable first.
 * economy (default): newest Flash-Lite models, then Flash with thinking off. auto: spread across the newest three. Or a model id.
 * $tier 'cheap' = routine text work; 'std' = web research and proposal writing (Flash first when quality is "balanced").
 */
function pm_gemini_order(string $seed, string $tier = 'cheap'): array
{
    $cat = pm_gemini_catalog();
    $cfg = pm_agents_config('promanaged');
    $want = pm_env()['GEMINI_MODEL'] ?? ($cfg['gemini_model'] ?? 'economy');
    $ids = array_column($cat, 'id');
    $lites = array_column(array_filter($cat, fn($m) => $m['tier'] === 1), 'id');
    $flash = array_column(array_filter($cat, fn($m) => $m['tier'] === 2), 'id');
    if ($want === 'auto') {
        $top = pm_gemini_top_models();
        $k = crc32($seed) % max(1, count($top));
        $order = array_merge(array_slice($top, $k), array_slice($top, 0, $k), $lites, $flash);
    } elseif ($want !== '' && $want !== 'economy') {
        $order = array_merge([$want], $lites, $flash);
    } else {
        // Research and scoring run on the cheapest model. Customer-facing writing ('write') is short, so a stronger model there costs almost nothing.
        $better = $tier === 'write' || ($tier === 'std' && ($cfg['quality'] ?? 'economy') === 'balanced');
        $order = $better ? array_merge($flash, $lites) : array_merge($lites, $flash);
    }
    $order = array_values(array_unique($order));
    $ok = array_values(array_filter($order, fn($m) => !pm_model_bad($m)));
    return array_merge($ok, array_values(array_diff($order, $ok))); // known-bad models only as a last resort
}

function pm_curl_native_ca($ch): void
{
    if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA); // PHP on Windows ships without a CA bundle
    }
}

/**
 * Google Gemini over REST. With $web, answers are grounded in Google Search. Thinking is switched off where the model allows it:
 * on a Flash model the hidden thinking can cost 10 to 20 times the visible answer.
 */
function pm_gemini(string $system, string $user, bool $web, int $maxTokens, string $tier = 'cheap'): string
{
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['maxOutputTokens' => $maxTokens],
    ];
    if ($web) {
        $body['tools'] = [['google_search' => new stdClass()]]; // grounding; cannot be combined with JSON-only mode
    } else {
        $body['generationConfig']['responseMimeType'] = 'application/json';
    }
    $last = 'Gemini failed.';
    foreach (array_slice(pm_gemini_order($system . $user, $tier), 0, 4) as $model) {
        $think0 = !str_contains($model, 'lite') && !str_contains($model, 'pro'); // Lite models do not think; Pro cannot stop
        foreach ($think0 ? [true, false] : [false] as $off) {
            $b = $body;
            if ($off) {
                $b['generationConfig']['thinkingConfig'] = ['thinkingBudget' => 0];
            }
            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $web ? 100 : 45, // a stuck model falls through to the next
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . pm_key('GEMINI_API_KEY')],
                CURLOPT_POSTFIELDS => json_encode($b, JSON_UNESCAPED_UNICODE),
            ]);
            pm_curl_native_ca($ch);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $r = is_string($raw) ? json_decode($raw, true) : null;
            if ($off && $code === 400) {
                continue; // this model rejects the thinking setting: retry it without
            }
            if ($raw === false || $code >= 400 || !is_array($r)) {
                $last = "$model: " . ($raw === false ? "no answer ($err)" : "error $code " . ($r['error']['message'] ?? substr((string)$raw, 0, 160)));
                if ($raw === false || in_array($code, [404, 429, 500, 502, 503, 504], true)) {
                    pm_model_mark_bad($model, $code === 404 ? 86400 : 7200);
                    continue 2; // try the next model
                }
                throw new RuntimeException("Gemini $last");
            }
            $text = '';
            foreach ($r['candidates'][0]['content']['parts'] ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }
            $u = $r['usageMetadata'] ?? [];
            pm_usage_add('gemini', $model, (int)($u['promptTokenCount'] ?? 0), (int)($u['candidatesTokenCount'] ?? 0), (int)($u['thoughtsTokenCount'] ?? 0));
            if ($text !== '') {
                return $text;
            }
            $last = "$model returned nothing (" . ($r['candidates'][0]['finishReason'] ?? $r['promptFeedback']['blockReason'] ?? 'unknown') . ')';
            continue 2;
        }
    }
    throw new RuntimeException("Gemini $last");
}

/**
 * Claude over REST with curl: a 90 s timeout per request and one retry on 429/529 (overloaded) or a server error.
 * (The SDK needs a PSR-18 client that is not installed here, and cannot time out a stuck request.) ANTHROPIC_BASE_URL in .env overrides the host.
 */
function pm_anthropic(string $system, string $user, bool $web, int $maxTokens, string $tier = 'cheap'): string
{
    $key = pm_key('ANTHROPIC_API_KEY');
    $base = rtrim(pm_key('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com', '/');
    $model = pm_agent_model($web, $tier);
    $timeout = max(10, (int)(pm_agents_config('promanaged')['anthropic_timeout'] ?? 90));
    $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'output_config' => ['effort' => 'low']];
    if ($web) {
        $body['tools'] = [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3, 'user_location' => ['type' => 'approximate', 'country' => 'MW']]];
    }
    $messages = [['role' => 'user', 'content' => $user]];
    $text = '';
    $t0 = time();
    for ($turn = 0; $turn < 4; $turn++) {
        $r = null;
        for ($try = 0; $try < 2; $try++) {
            $ch = curl_init($base . '/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
                CURLOPT_POSTFIELDS => json_encode($body + ['messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            pm_curl_native_ca($ch);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hl = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new RuntimeException("Claude did not answer ($err)");
            }
            $head = substr((string)$raw, 0, $hl);
            $r = json_decode(substr((string)$raw, $hl), true);
            if (($code === 429 || $code === 529 || $code >= 500) && $try === 0 && time() - $t0 < 150) {
                $wait = preg_match('/^retry-after:\s*(\d+)/mi', $head, $m) ? (int)$m[1] : 3;
                sleep(max(1, min(15, $wait))); // one retry, then give up
                continue;
            }
            if ($code >= 400 || !is_array($r)) {
                throw new RuntimeException("Claude error $code: " . (is_array($r) ? ($r['error']['message'] ?? 'unknown') : substr(trim((string)substr((string)$raw, $hl)), 0, 120)));
            }
            break;
        }
        pm_usage_add('anthropic', $model, (int)($r['usage']['input_tokens'] ?? 0), (int)($r['usage']['output_tokens'] ?? 0), 0);
        foreach ((array)($r['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        if (($r['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('The model declined this request.');
        }
        if (($r['stop_reason'] ?? '') !== 'pause_turn' || time() - $t0 > 200) {
            break;
        }
        $messages[] = ['role' => 'assistant', 'content' => $r['content']];
    }
    return $text;
}

/** Pull a JSON value out of an agent's reply (it may wrap it in a code fence or prose). */
function pm_agent_json(string $text): mixed
{
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
        $text = $m[1];
    }
    $a = strpos($text, '[');
    $o = strpos($text, '{');
    $start = ($a !== false && ($o === false || $a < $o)) ? $a : $o;
    if ($start === false) {
        return null;
    }
    $end = max(strrpos($text, ']'), strrpos($text, '}'));
    return json_decode(substr($text, $start, $end - $start + 1), true);
}

/** An agent's JSON list, or an exception the caller can retry on. [] is a valid answer ("found nothing"); no JSON at all is not. */
function pm_agent_list(mixed $out): array
{
    if (!is_array($out)) {
        throw new RuntimeException('unparseable output');
    }
    if ($out && !array_is_list($out)) {
        foreach ($out as $v) { // {"leads":[...]} style wrapper
            if (is_array($v) && $v && array_is_list($v) && is_array($v[0])) {
                return $v;
            }
        }
        return isset($out['id']) || isset($out['name']) ? [$out] : [];
    }
    return $out;
}

/* ---------------- Context shared by every agent ---------------- */

/**
 * What every agent is told about us. Kept as short as the job allows, because every word is paid for on every call.
 * Levels: tiny (writers, follow-up) < short (scouts, lookups) < packages < full (qualifier: packages and pain points).
 */
/** The default "marketing brain" for a business: what the AI knows and may say. Editable in Settings, so the app can market any business. */
function pm_brain_defaults(string $brand): array
{
    if ($brand === 'travel') {
        return [
            'about' => 'A direct booking platform connecting travellers with independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi (stays only).',
            'facts' => "Listing and onboarding are free for now: no commission, no listing fee, no monthly charge\nGuests pay the property directly, in kwacha or dollars\nBooking requests reach the host's dashboard and confirmations go out over WhatsApp\nHosts control their own rooms, rates and blocked dates\nEvery listing is reviewed before it goes live\nThere is a host starter guide",
            'audience' => 'Owners and managers of independent places to stay in Malawi; travellers looking for them.',
            'voice' => 'Warm, local, proud of Malawi, plain words.',
            'never' => 'Never promise bookings, traffic or income.',
        ];
    }
    return [
        'about' => 'Builds software and websites (including a management system for hotels, restaurants, gyms, venues and shops), sources computer equipment, and provides IT support, working from Malawi.',
        'facts' => "The management system runs in a web browser, with Kwacha pricing, VAT and tourism levy built in\nIt already runs at hotels in Malawi\nWe source laptops, desktops and equipment, including items not sold locally, with local-friendly payment\nIT support by WhatsApp, email, phone and remote session during working hours",
        'audience' => 'Owners and managers of small and medium businesses in Malawi.',
        'voice' => 'Friendly, practical, honest, plain words.',
        'never' => 'Never promise results, deadlines or out-of-hours support.',
    ];
}

function pm_brain(string $brand = ''): array
{
    $brand = $brand ?: pm_brand();
    $s = pm_load('settings', 'pm_default_settings');
    $saved = (array)($brand === 'travel' ? ($s['travel']['brain'] ?? []) : ($s['brain'] ?? []));
    return array_replace(pm_brain_defaults($brand), array_filter($saved, fn($v) => trim((string)$v) !== ''));
}

/**
 * What every agent is told about us. Kept as short as the job allows, because every word is paid for on every call.
 * Levels: tiny (writers, follow-up) < short (scouts, lookups) < packages < full (qualifier: packages and pain points).
 */
function pm_agents_company_brief(string $level = 'short'): string
{
    $s = pm_settings();
    $br = pm_brain();
    $facts = implode('; ', array_filter(array_map('trim', preg_split('/\R/', (string)$br['facts']))));
    $core = "{$s['company_name']}: {$br['about']} Facts you may use, nothing beyond them: $facts. {$br['never']} Voice: {$br['voice']} Audience: {$br['audience']}";
    if (pm_brand() === 'travel') {
        return $core . ' Package for every lead: #' . pm_onboarding_index() . ' Stay Onboarding.';
    }
    if ($level === 'tiny') {
        return $core;
    }
$cfg = pm_agents_config();
    $off = implode("\n- ", $cfg['offerings']);
    $out = $core . "\nWhat we sell:\n- $off";
    if ($level === 'short') {
        return $out;
    }
    $tpl = pm_template();
    $pk = [];
    foreach ($tpl['packages'] as $i => $p) {
        $pk[] = "#$i {$p['name']} ({$p['type']}): {$p['suited']}";
    }
    $out .= "\nPackages:\n" . implode("\n", $pk);
    if ($level === 'packages') {
        return $out;
    }
    $pains = [];
    foreach ($tpl['pain_points'] as $p) {
        $pains[] = "[{$p['type']}] {$p['pain']} -> {$p['fix']}";
    }
    return $out . "\nManagement-system pain points (use only where they truly apply):\n" . implode("\n", $pains);
}

const PM_AGENT_RULES = "We are a small, owner-run business: never promise 24/7 or out-of-hours support, response times, deadlines, results or anything not in the facts given. Use only facts found in sources; never invent emails, phones or names; leave unknown fields empty. Malawi only; skip international chains. Reply with JSON only.";

/** The "how it stops" line for a pain point, so writers can state the gain without being sent the whole list. */
function pm_fix_for(string $pain): string
{
    $pain = strtolower(trim($pain));
    foreach (pm_template()['pain_points'] as $p) {
        $n = strtolower($p['pain']);
        if ($pain !== '' && ($n === $pain || str_contains($n, $pain) || str_contains($pain, $n))) {
            return (string)$p['fix'];
        }
    }
    return '';
}

/* ---------------- The agents ---------------- */

/** SCOUT: finds businesses of one sector in one city. */
function pm_agent_scout(string $sector, string $city, int $want, array $knownNames): array
{
    $label = pm_sector_label($sector);
    if (pm_brand() === 'travel') {
        $system = pm_agents_company_brief('short') . "\nYou are the Scout. Web-search for real, trading independent stays in {$city}, Malawi: {$label}. Prefer ones that gain from direct bookings "
            . "(mostly on third-party sites, phone/WhatsApp-only, no or outdated website). Exclude chains and non-stays. Only include stays that publish enquiry contacts. " . PM_AGENT_RULES;
    } else {
        $system = pm_agents_company_brief('short') . "\nYou are the Scout. Web-search for real, trading organisations in {$city}, Malawi: {$label}, that show a genuine need for something we do "
            . "(paper/WhatsApp/spreadsheet operations, no or outdated website, agent-only bookings, no booking/POS/stock system, ageing IT, hiring IT/admin, expanding). "
            . "Note the best-fit offering. Only include those that publish business contacts. " . PM_AGENT_RULES;
    }
    $user = "Find up to {$want}. Skip these (already known): " . implode('; ', array_slice(array_map(fn($n) => preg_replace('/ \(.*$/', '', $n), $knownNames), -80)) . "\n"
        . 'JSON array of {"name","type":"kind of business in 2-3 words","city","address","website","phone","email","contact":"owner, founder, managing director or manager if a page of the business itself names one, else empty","contact_title":"their role","contact_source":"URL of the page that names them","facebook":"Facebook page URL or empty","instagram":"Instagram URL or empty","social_gaps":["what is missing or weak online: no website, no Facebook page, page inactive since a year, few reviews, no way to book or enquire online"],"evidence":["one factual observation + source URL"],"need_signals":["why they need us"],"offering":"build, source or support"}';
    $out = pm_agent_list(pm_agent_json(pm_claude($system, $user, true, 2500)));
    return array_values(array_filter($out, fn($x) => is_array($x) && !empty($x['name'])));
}

/** CONTACT FINDER: only for leads that still lack a way to reach them. */
function pm_agent_contact(array $lead): array
{
    $system = "You are the Contact Finder. Your main job is the NAME of the owner, founder, managing director or general manager of this business, then the business's own published enquiry contacts. "
        . "Check its website (about, team, contact pages), Facebook page, Google listing, LinkedIn company page and local directories. Only report a name that a public source ties to this business, and give the source. "
        . "Never guess a name or an address; never return a private person's personal email or number. " . PM_AGENT_RULES;
    $user = "{$lead['name']} ({$lead['type']}), {$lead['city']}. Website: " . ($lead['website'] ?? '') . ". Have: " . trim(($lead['phone'] ?? '') . ' ' . ($lead['email'] ?? '') . ' ' . ($lead['contact'] ?? '')) . "\n"
        . 'JSON: {"contact":"full name","contact_title":"owner, manager...","email","phone","whatsapp","website","source":"URL where the name appears"}';
    $out = pm_agent_json(pm_claude($system, $user, true, 800, 'write'));
    return is_array($out) ? $out : [];
}

/** A named person is only used in a greeting when the page that names them belongs to the business itself (its website, Facebook or LinkedIn page). */
function pm_trusted_source(string $src, string $website): bool
{
    $host = function (string $u): string {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        return strtolower(preg_replace('/^www\./', '', (string)parse_url(preg_match('#^https?://#i', $u) ? $u : 'http://' . $u, PHP_URL_HOST)));
    };
    $s = $host($src);
    $w = $host($website);
    return $s !== '' && (($w !== '' && ($s === $w || str_ends_with($s, '.' . $w))) || (bool)preg_match('/(^|\.)(facebook|linkedin|instagram)\.com$/', $s));
}

/** Put a found name on a lead: as its contact if the source is trusted, otherwise as a hint for a person to confirm. */
function pm_apply_contact(array &$lead, array $d): void
{
    $name = trim((string)($d['contact'] ?? ''));
    $title = trim((string)($d['contact_title'] ?? ''));
    if ($name === '' || !empty($lead['contact'])) {
        return;
    }
    // A person's name: letters only (no digits or phone numbers), at least two letters, and not a department.
    if (preg_match('/\d|@|https?:/', $name) || mb_strlen(preg_replace('/[^\p{L}]/u', '', $name)) < 2 || preg_match('/\b(reservations?|reception|info|sales|bookings?|management|admin|team|office)\b/i', $name)) {
        return;
    }
    // Greet the decision maker: a restaurant, kitchen, front-desk or similar staff role is not who we pitch.
    if (preg_match('/restaurant|kitchen|chef|waiter|housekeep|front desk|receptionist|barman|bartender|driver|guard/i', $title)) {
        $lead['contact_hint'] = $name . ($title !== '' ? " ($title)" : '') . ', not the owner';
        return;
    }
    $src = trim((string)($d['source'] ?? $d['contact_source'] ?? ''));
    if (pm_trusted_source($src, (string)($lead['website'] ?? ''))) {
        $lead['contact'] = $name;
        if ($title !== '' && empty($lead['contact_title'])) {
            $lead['contact_title'] = $title;
        }
        pm_lead_note($lead, 'Contact name from ' . $src);
    } else {
        $lead['contact_hint'] = $name . ($title !== '' ? " ($title)" : '') . ($src !== '' ? ', from ' . $src : '');
    }
}

/** QUALIFIER: scores fit and picks the package and the lead pain point. No web access. */
function pm_agent_qualify(array $leads): array
{
    if (pm_brand() === 'travel') {
        $system = pm_agents_company_brief('tiny') . "\nYou are the Qualifier. Score each stay 0-100 on how likely it wants a free direct-booking listing: independent, genuine, reachable, reliant on agents/phone/WhatsApp or little online presence. "
            . "Above 70 needs concrete evidence. When an item has research, re-score it from what was found (previous_score is the old number): confirmed facts may raise or lower it. " . PM_AGENT_RULES;
    } else {
        $system = pm_agents_company_brief('full') . "\nYou are the Qualifier. Score each lead 0-100 on need and ability to pay (size, manual processes, reachable decision maker, package fit). Be sceptical: above 70 needs concrete evidence. "
            . "Pick the package index and the best pain point/need; the package must match what they need. When an item has research, re-score it from what was found (previous_score is the old number): confirmed facts may raise or lower it. "
            . pm_learn_qualifier_line(pm_brand()) . PM_AGENT_RULES;
    }
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'type' => $l['type'], 'evidence' => array_slice($l['evidence'] ?? [], 0, 2),
        'signals' => array_slice($l['need_signals'] ?? [], 0, 2), 'reachable' => !empty($l['email']) || !empty($l['phone'])]
        + (!empty($l['research']) ? ['research' => pm_research_brief($l), 'previous_score' => (int)($l['score'] ?? 0)] : []), $leads);
    return pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE)
        . "\nJSON array of {\"id\",\"score\",\"package\":index,\"offering\":\"build, source or support\",\"pain\":\"pain point/need to lead with\",\"reason\":\"one short sentence\",\"skip\":false}", false, 2500)));
}

/** The rules for every first email: short, specific, exciting, honest, no prices. */
const PM_EMAIL_CRAFT = "Write like one real person writing to one owner, never like a marketing department. Email layout, each part a short paragraph separated by a blank line: "
    . "(1) The greeting alone on the first line: 'Hello <first name>,' when a contact name is given ('Dear Dr <surname>,' for a doctor or professor), otherwise 'Hello,' or 'Good day,'. "
    . "(2) Introduce yourself in one natural sentence with your first name (if given) and the company, and acknowledge this is out of the blue, e.g. 'My name is <sender>, and I run <company>. I don't believe we've met, so I'll keep this short.' or 'I'm <sender> from <company>; we haven't met yet.' Vary the wording every time. "
    . "(3) One specific, true observation about their business from the evidence (how we came across them is fine if true, e.g. 'I came across Blend Lodge while looking at places to stay in Zomba'), then the gain for them in plain words; describe a problem only conditionally ('when..., ...'). "
    . "When research is given, the observation in (3) MUST be built on one item from research.discoveries, research.news or research.hooks (the most recent and specific one), mentioned naturally, so they can see we really looked. "
    . "Engage, don't lecture: say there are one or two simple things that could help with it, but do NOT hand over the full answer in the email; leave them curious enough to reply. "
    . "(4) One easy, low-pressure question: a 10-minute call, or a one-page plan for their business. "
    . "Warm, plain, contractions welcome; no buzzwords (streamline, leverage, solution, seamless, cutting-edge, elevate). Subject: under 45 characters, specific to them, truthful, no capitals for emphasis, no exclamation marks. "
    . "NEVER mention prices, fees or numbers you cannot source. No urgency, scarcity, guarantees, links or attachments; never ask for passwords or payment; never claim we know them or follow their work; never name a town as our base. "
    . "No sign-off (the app adds it). Email: 70 to 110 words. WhatsApp: 30 to 45 words, one paragraph: greeting with first name, who you are, the gain, the easy question.";

/** The email rules in a nutshell, for the proposal cover email. */
const PM_EMAIL_CRAFT_SHORT = "under 60 words, opens with something specific and true about THEM, states the gain in plain words, ends with one easy yes (the attached plan, or a 10-minute chat). NEVER any price, fee or amount of money; no urgency or hype";

/** The person the message comes from: whoever is working (team menu), else the business's signatory. '' = nobody named. */
function pm_sender_name(): string
{
    $who = trim((string)($GLOBALS['PM_WHO'] ?? ''));
    return $who !== '' ? $who : trim((string)(pm_settings()['signatory_name'] ?? ''));
}

/** WRITER: first email and WhatsApp in our voice. */
function pm_agent_write(array $leads): array
{
    $s = pm_settings();
    $tpl = pm_template();
    $ask = pm_brand() === 'travel'
        ? "Invite the stay to list on {$s['company_name']} free; the gain is direct bookings with no commission. "
        : "";
    $sender = pm_sender_name();
    $subjectLine = function_exists('pm_email_exp_example') ? pm_email_exp_example(pm_brand()) : '';
    $system = pm_agents_company_brief('tiny') . "\nYou are writing outreach for {$s['company_name']} as " . ($sender !== '' ? "$sender (use this first name: " . explode(' ', $sender)[0] . ")" : "a member of the team (no personal name: introduce the company instead)") . ". $ask" . PM_EMAIL_CRAFT . ' ' . ($subjectLine !== '' ? $subjectLine . ' ' : '')
        . "Each item has subject_style: write that item's email subject in that style. " . PM_AGENT_RULES;
    $brand = pm_brand();
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'type' => $l['type'], 'city' => $l['city'], 'contact' => $l['contact'] ?? '',
        'evidence' => array_slice($l['evidence'] ?? [], 0, 2), 'online_gaps' => array_slice($l['social_gaps'] ?? [], 0, 2), 'research' => pm_research_brief($l), 'lead_with' => $l['pain'] ?? '', 'how_it_stops' => pm_fix_for((string)($l['pain'] ?? '')),
        'subject_style' => pm_subject_style(pm_email_exp_arm((string)$l['id'], $brand))], $leads);
    return pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE)
        . "\nJSON array of {\"id\",\"email_subject\",\"email_body\",\"whatsapp\"}. Never greet with the business name.", false, 2500, 'write')));
}

/** FOLLOW-UP: a short nudge for leads that have gone quiet. Engagement-aware: opened-but-silent, never-opened and replied-then-quiet get different angles. */
function pm_agent_followup(array $leads): array
{
    $s = pm_settings();
    $system = pm_agents_company_brief('tiny') . "\nYou are the Follow-up agent for {$s['company_name']}. Write a 40-word follow-up email and 25-word WhatsApp that add ONE new angle (a different gain, or a one-page plan offer). "
        . "Match the state: for \"opened\" (they opened our proposal or email but stayed quiet) gently reference what they saw and add one new, true reason; for \"silent\" (never engaged) be shorter and lead with one specific fact about their business; for \"replied\" pick up their last words, never repeat a whole earlier message. "
        . "No prices, no guilt, no pressure, no urgency. Plain text, no sign-off. Each item has subject_style: write that item's email subject in that style. "
        . pm_email_exp_followup_example(pm_brand()) . ' ' . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'contact' => $l['contact'] ?? '', 'used' => mb_substr($l['drafts']['email_body'] ?? '', 0, 220),
        'state' => pm_lead_engagement((array)$l), 'views' => (int)($l['view_count'] ?? 0), 'last_seen' => (string)($l['last_viewed_at'] ?? ''),
        'subject_style' => pm_subject_style(pm_followup_arm((array)$l))], $leads);
    return pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"email_subject\",\"email_body\",\"whatsapp\"}", false, 1800)));
}

/** One word for the follow-up writer: 'replied', 'opened' or 'silent'. */
function pm_lead_engagement(array $l): string
{
    if (!empty($l['last_reply']) || pm_lead_replied($l)) {
        return 'replied';
    }
    return !empty($l['view_count']) || !empty($l['last_viewed_at']) || !empty($l['opened_at']) ? 'opened' : 'silent';
}

/**
 * LOOKUP: research one named business and qualify it in the same call (one search-grounded request, no separate steps).
 * Returns a lead-shaped array, or null if the business could not be found.
 */
function pm_agent_lookup(string $name, string $city = ''): ?array
{
    if (pm_brand() === 'travel') {
        $system = pm_agents_company_brief('tiny') . "\nYou are the Researcher. Web-search for one specific place to stay in Malawi and report what is publicly known. "
            . "If it is not a place to stay, or you cannot find it, return {\"found\":false}. Score 0-100 how likely it wants a free direct-booking listing. Package index is given above. " . PM_AGENT_RULES;
        $pk = pm_onboarding_index();
    } else {
        $system = pm_agents_company_brief('full') . "\nYou are the Researcher. Web-search for one specific organisation in Malawi and report what is publicly known. "
            . "If you cannot find it, return {\"found\":false}. Choose the package that fits what they do, the pain point to lead with, and score 0-100 how likely they need and can afford us. " . PM_AGENT_RULES;
        $pk = '';
    }
    $user = "Business: $name" . ($city !== '' ? ", $city" : '') . "\nJSON: {\"found\":true,\"name\",\"type\":\"kind in 2-3 words\",\"city\",\"address\",\"website\",\"phone\",\"email\",\"contact\":\"owner or manager name if a public source gives one\",\"contact_title\","
        . "\"facebook\",\"instagram\",\"social_gaps\":[\"what is missing or weak online\"],\"evidence\":[\"fact + source URL\"],\"need_signals\":[\"why they may need us\"],\"offering\":\"build, source or support\",\"package\":" . ($pk !== '' ? $pk : 'index') . ",\"pain\":\"\",\"reason\":\"one sentence\",\"score\":0}";
    $out = pm_agent_json(pm_claude($system, $user, true, 2000));
    return is_array($out) && !empty($out['found']) && !empty($out['name']) ? $out : null;
}

/* ---------------- Outreach helpers ---------------- */

function pm_outreach_footer(array $s): string
{
    $sig = array_filter([$s['signatory_name'] ?? '', ($s['signatory_name'] ?? '') !== '' ? ($s['signatory_title'] ?? '') : '', $s['company_name'], $s['address'] ?? '', $s['phone'] ?? '', $s['website'] ?? '']);
    return "\n\nKind regards,\n" . implode("\n", $sig)
        . "\n\nYou are receiving this one-off message because your business publishes this address for enquiries. "
        . "If you would rather not hear from us, reply STOP and we will not contact you again.";
}

/** Domains where many unrelated people share an address; the per-company domain limit does not apply to them. */
const PM_FREE_MAIL = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'proton.me', 'protonmail.com'];

function pm_email_domain(string $email): string
{
    return strtolower(substr(strrchr($email, '@') ?: '', 1));
}

/** True if this address (or its company domain) belongs to anyone who said STOP. */
function pm_suppressed(array $leads, string $email, string $exceptId = ''): bool
{
    $d = pm_email_domain($email);
    foreach ($leads as $l) {
        if (($l['status'] ?? '') !== 'optout' || ($l['id'] ?? '') === $exceptId) {
            continue;
        }
        $le = strtolower((string)($l['email'] ?? ''));
        if ($le === strtolower($email) || ($d !== '' && !in_array($d, PM_FREE_MAIL, true) && pm_email_domain($le) === $d)) {
            return true;
        }
    }
    return false;
}

/**
 * Checks a message against the rules that separate honest outreach from spam or phishing.
 * Returns a list of problems; an empty list means it may be sent.
 */
function pm_outreach_lint(string $subject, string $body, bool $first): array
{
    $bad = [];
    $subject = trim($subject);
    $words = str_word_count($body);
    if ($subject === '' || mb_strlen($subject) > 70) {
        $bad[] = 'The subject must be present and under 70 characters.';
    }
    if ($first && preg_match('/^\s*(re|fwd?):/i', $subject)) {
        $bad[] = 'The subject pretends to be a reply or forward.';
    }
    if (preg_match('/!{2,}|\$\$|\b[A-Z]{5,}\b.*\b[A-Z]{5,}\b/', $subject . ' ' . $body) || substr_count($subject, '!') > 0) {
        $bad[] = 'Remove shouting: exclamation marks and words in capitals.';
    }
    if (preg_match('/\b(free gift|guarantee[d]?|act now|urgent|limited time|last chance|winner|you have won|click here|risk[- ]free|100%|no obligation|congratulations|cash prize|offer expires)\b/i', $subject . ' ' . $body, $m)) {
        $bad[] = 'Spam wording: "' . $m[1] . '".';
    }
    if (preg_match('/\b(password|passcode|pin\b|otp\b|verify your|confirm your (account|identity)|account (suspended|locked)|bank details|card number|login to|log in to|send (us )?payment|wire)\b/i', $body, $m)) {
        $bad[] = 'Looks like phishing: it mentions "' . $m[1] . '". Outreach must never ask for credentials, payment or personal details.';
    }
    if (preg_match_all('#https?://|www\.#i', $body) > 1 || preg_match('#\b(bit\.ly|tinyurl|t\.co|goo\.gl|wa\.me)\b#i', $body)) {
        $bad[] = 'Too many links or a link shortener. Use at most one plain link to your own website.';
    }
    // £ and € are matched as real characters (/u): as bytes they would also hit the em dash in a sign-off "— Company"
    if (preg_match('/(?<![A-Za-z])(MWK|USD|ZAR|GBP|EUR)(?![A-Za-z])|\$|(?<![A-Za-z])K\s?\d|\d[\d,. ]{3,}\s*(a month|per month|\/\s?month|once|setup)/i', $subject . ' ' . $body) || preg_match('/[£€]/u', $subject . ' ' . $body)) {
        $bad[] = 'Remove prices and amounts from the email: prices belong in the proposal.';
    }
    if ($words < 20 || $words > ($first ? 190 : 120)) {
        $bad[] = "The message is $words words; keep it " . ($first ? 'between 20 and 190' : 'between 20 and 120') . '.';
    }
    return $bad;
}

function pm_sent_today(array $leads): int
{
    $n = 0;
    foreach ($leads as $l) {
        if (($l['brand'] ?? 'promanaged') !== pm_brand()) {
            continue;
        }
        foreach ((array)($l['sent'] ?? []) as $at) {
            if (str_starts_with((string)$at, date('Y-m-d'))) {
                $n++;
            }
        }
    }
    return $n;
}

/** Malawi numbers in any common spelling -> ['265881234567' (digits), 'mobile'|'landline'|'unknown'], from the first usable number in the text. */
function pm_wa_number(string $raw): array
{
    foreach (preg_split('/[\/,;]| or | and /i', $raw) as $part) {
        $n = preg_replace('/\D+/', '', $part);
        if ($n === '') {
            continue;
        }
        if (str_starts_with($n, '00')) {
            $n = substr($n, 2);
        }
        if (str_starts_with($n, '0')) {
            $n = '265' . substr($n, 1);
        } elseif (strlen($n) === 9 && !str_starts_with($n, '265')) {
            $n = '265' . $n;
        }
        if (str_starts_with($n, '265') && strlen($n) === 12) {
            return [$n, in_array($n[3], ['8', '9'], true) ? 'mobile' : 'landline']; // Malawi mobiles start 8 or 9 (088, 099...)
        }
        if (str_starts_with($n, '265')) {
            return [$n, 'landline']; // a Malawi number with too few digits is a landline or incomplete
        }
        if (strlen($n) >= 10 && strlen($n) <= 15) {
            return [$n, 'unknown']; // another country: assume it can have WhatsApp
        }
    }
    return ['', 'unknown'];
}

/** The best number for WhatsApp: the WhatsApp field first, then the phone. Returns [digits, kind]. */
function pm_wa_best(array $lead): array
{
    foreach ([(string)($lead['whatsapp'] ?? ''), (string)($lead['phone'] ?? '')] as $raw) {
        [$n, $k] = pm_wa_number($raw);
        if ($n !== '' && $k !== 'landline') {
            return [$n, $k];
        }
    }
    return ['', 'landline'];
}

function pm_wa_link(array $lead, string $text): string
{
    [$n] = pm_wa_best($lead);
    return $n === '' ? '' : 'https://wa.me/' . $n . '?text=' . rawurlencode($text);
}

/** WhatsApp text as sent: greeting by name, then sign-off with who we are and an easy opt-out. */
function pm_wa_text(array $lead, string $text): string
{
    $s = pm_settings();
    $nm = trim(preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))));
    $first = trim(explode(' ', $nm)[0] ?? '');
    $text = trim($text);
    if ($first !== '' && preg_match('/^(hello|hi|dear)[ ,.!]*/i', $text) && !str_contains(mb_substr($text, 0, 30), $first)) {
        $text = preg_replace('/^(hello|hi|dear)[ ,.!]*/i', "Hello $first, ", $text, 1);
    }
    if ($first !== '' && !preg_match('/^(hello|hi|dear)/i', $text)) {
        $text = "Hello $first, " . lcfirst($text);
    }
    $who = trim(($GLOBALS['PM_WHO'] ?? '') !== '' ? $GLOBALS['PM_WHO'] . ', ' . $s['company_name'] : ($s['signatory_name'] ? $s['signatory_name'] . ', ' . $s['company_name'] : $s['company_name']));
    if (($lk = pm_link_wa_line()) !== '' && !str_contains($text, $lk)) {
        $text .= "\n\n" . $lk;
    }
    if (!str_contains($text, $s['company_name'])) {
        $text .= "\n\n— $who";
    }
    return $text . "\n(Reply STOP and we won't message again.)";
}

function pm_wa_sent_today(array $leads): int
{
    $n = 0;
    foreach ($leads as $l) {
        if (($l['brand'] ?? 'promanaged') !== pm_brand()) {
            continue;
        }
        foreach ((array)($l['wa_sent'] ?? []) as $at) {
            $n += str_starts_with((string)$at, date('Y-m-d')) ? 1 : 0;
        }
    }
    return $n;
}

/** True while a lead is snoozed ("contact me later"): nothing is drafted or planned for it until the date passes. */
function pm_lead_snoozed(array $l): bool
{
    $s = substr(trim((string)($l['snooze_until'] ?? '')), 0, 10);
    return $s !== '' && $s > date('Y-m-d');
}

/** True when the lead's email is known bad and there is no WhatsApp-capable number to use instead. */
function pm_lead_email_dead(array $l): bool
{
    return !empty($l['email_bad']) && pm_wa_best($l)[0] === '';
}

/** Another brand (or anyone) already approached this company recently? Uses P4's check when it exists; never blocks otherwise. */
function pm_agents_recent_company(array $leads, array $l): bool
{
    if (!function_exists('pm_company_contacted_recently')) {
        return false;
    }
    try {
        $p = (new ReflectionFunction('pm_company_contacted_recently'))->getParameters();
        if (count($p) < 2 || !preg_match('/leads/i', $p[0]->getName()) || ($p[2] ?? null)?->isOptional() === false) {
            return false; // unknown signature: do not guess
        }
        return (bool)pm_company_contacted_recently(array_diff_key($leads, [(string)($l['id'] ?? '') => 1]), $l);
    } catch (Throwable) {
        return false;
    }
}

/** Leads ready for a WhatsApp message today, best first: [{lead,url,text,kind,display}]. */
function pm_wa_queue(array $leads): array
{
    $cfg = pm_agents_config();
    $out = [];
    foreach ($leads as $l) {
        if (($l['brand'] ?? 'promanaged') !== pm_brand() || in_array($l['status'] ?? '', ['optout', 'lost', 'won', 'replied', 'proposal'], true) || pm_lead_snoozed($l)) {
            continue;
        }
        if (pm_brand() === 'promanaged' && array_filter((array)($cfg['existing_clients'] ?? []), fn($x) => $x !== '' && stripos((string)$l['name'], (string)$x) !== false)) {
            continue; // already our client
        }
        [$num] = pm_wa_best($l);
        if ($num === '') {
            continue;
        }
        $kind = 'first';
        $draft = $l['drafts']['whatsapp'] ?? '';
        $after = ''; // date of our first email, when WhatsApp follows it
        if (!empty($l['wa_sent'])) {
            $lastWa = strtotime((string)end($l['wa_sent'])) ?: 0;
            if (empty($l['followup_draft']['whatsapp']) || time() - $lastWa < $cfg['followup_days'] * 86400 || count($l['wa_sent']) > (int)$cfg['max_followups']) {
                continue; // already messaged; wait for the follow-up window
            }
            $kind = 'followup';
            $draft = $l['followup_draft']['whatsapp'];
        } elseif (!empty($l['sent'])) {
            $t = strtotime((string)reset($l['sent'])) ?: 0;
            if ($t && time() - $t < 2 * 86400) {
                continue; // email goes first; WhatsApp waits 2 days so they are not hit twice on one day
            }
            $after = $t ? date('j M', $t) : '';
        } elseif (pm_agents_recent_company($leads, $l)) {
            continue; // same company approached recently (e.g. by the other brand)
        }
        if (trim((string)$draft) === '') {
            continue;
        }
        if ($kind === 'first' && $after !== '') {
            $rest = preg_replace('/^(hello|hi|dear)[^,.!\n]{0,40}[,.!]\s*/i', '', trim((string)$draft), 1);
            $draft = "Hello, following my email on $after: " . lcfirst($rest);
        }
        if (pm_outreach_lint('WhatsApp message', (string)$draft, $kind === 'first')) {
            continue; // fails the honesty/spam checks: edit the draft on the lead first
        }
        $text = pm_wa_text($l, (string)$draft);
        $out[] = ['lead' => $l, 'url' => 'https://wa.me/' . $num, 'text' => $text, 'kind' => $kind, 'display' => '+' . $num, 'score' => (int)($l['score'] ?? 0)];
    }
    usort($out, fn($a, $b) => [$a['kind'] === 'followup' ? 0 : 1, -$a['score']] <=> [$b['kind'] === 'followup' ? 0 : 1, -$b['score']]);
    return array_slice($out, 0, 40);
}

/** Short replies the team can paste into a chat. No prices, no promises. */
function pm_wa_quick_replies(): array
{
    $b = pm_brand() === 'travel' ? 'Travel Malawi' : 'ProManaged IT';
    $what = pm_brand() === 'travel'
        ? 'Travel Malawi is a direct booking site for independent stays in Malawi. Guests book you directly, you keep your rate, and requests reach you on WhatsApp. Listing is free for now.'
        : 'We build software and websites, source computers and equipment, and provide IT support for Malawian businesses. I can send a one-page plan for your business.';
    return [
        'What is this?' => "Thanks for asking. $what",
        'How much?' => 'Fair question. It depends on what you need, so I would rather give you an exact figure than a guess. Can I send you a short plan for your business? ' . (pm_brand() === 'travel' ? 'Listing is free for now.' : ''),
        'Can we talk?' => 'Yes please. Would a short call today or tomorrow suit you? Tell me a time and I will call you.',
        'Send details' => 'Of course. I will send a one-page plan for your business here and by email. Which email should I use?',
        'Not now' => 'No problem at all. May I check back in a few weeks? If you would rather not, just say so and I will not message again.',
        'Not interested' => "Understood, thank you for replying. I won't message again. All the best with the business.",
        'Who are you?' => "I'm part of the team at $b, based in Malawi. I messaged because I thought we could help your business. Happy to share more or leave you be.",
    ];
}

/** tel: link for a Malawi number (a leading 0 becomes +265). */
function pm_tel_link(array $lead): string
{
    $raw = (string)(($lead['phone'] ?? '') ?: ($lead['whatsapp'] ?? ''));
    $n = preg_replace('/[^\d+]/', '', $raw);
    if ($n === '') {
        return '';
    }
    if (str_starts_with($n, '0')) {
        $n = '+265' . substr($n, 1);
    } elseif (!str_starts_with($n, '+')) {
        $n = '+' . $n;
    }
    return 'tel:' . $n;
}

/** Turn a lead into a pre-filled proposal form. */
function pm_lead_to_draft(array $lead): array
{
    return ['lead_id' => $lead['id'], 'business' => $lead['name'], 'contact' => $lead['contact'] ?? '', 'contact_title' => $lead['contact_title'] ?? '',
        'email' => $lead['email'] ?? '', 'phone' => $lead['phone'] ?? '', 'address' => trim(($lead['address'] ?? '') . ' ' . $lead['city']),
        'package' => (int)($lead['package'] ?? 0), 'extras' => [], 'date' => date('Y-m-d'), 'doc_type' => 'pitch']
        + (!empty($lead['proposal_ai']) ? ['ai' => $lead['proposal_ai']['ai'], 'personal_note' => $lead['proposal_ai']['personal_note'],
            'subject' => $lead['proposal_ai']['subject'], 'body' => $lead['proposal_ai']['body']] : []);
}

/* ---------------- Daily plan ---------------- */

/** Today's task list, rebuilt from the state of every lead. Task completion is remembered per day. */
/** A lead's business group for filtering: free-text types such as "beach hotel resort" or "hotel and restaurant" fall into one group. */
function pm_lead_group(array $l): string
{
    $t = strtolower((string)($l['type'] ?? '') . ' ' . (($l['type'] ?? '') === '' ? $l['name'] ?? '' : ''));
    if (($l['source'] ?? '') === 'facebook') {
        return 'Facebook enquiries';
    }
    $travel = ($l['brand'] ?? '') === 'travel';
    $map = $travel ? [
        'Safari & eco camps' => 'safari|eco|camp|bush|wildlife',
        'Lakeshore & beach' => 'lake|beach|island',
        'Backpackers & hostels' => 'backpack|hostel',
        'B&Bs & cottages' => 'b&b|bed and breakfast|cottage|chalet|self-catering|farm stay|airbnb',
        'Hotels' => 'hotel|suites|resort|inn',
        'Lodges & guest houses' => 'lodge|guest|motel|rest house',
    ] : [
        'Hotels & lodges' => 'hotel|lodge|resort|guest|motel|suites|camp|b&b|cottage|hostel|backpack|inn\b|accommodation',
        'Restaurants & bars' => 'restaurant|bar\b|bars|cafe|café|coffee|grill|pub\b|bakery|takeaway|food|catering|night ?club',
        'Schools & colleges' => 'school|college|academy|universit|institute|training|nursery|kindergarten|education',
        'Health & pharmacies' => 'clinic|hospital|health|medical|pharmac|dental|optic|lab',
        'Fitness & beauty' => 'gym|fitness|wellness|spa\b|salon|beauty|barber|yoga',
        'Shops & supermarkets' => 'shop|supermarket|store|retail|boutique|hardware|mart\b|wholesale|distribut|fashion|electronics|furniture',
        'Offices & professional' => 'law|legal|account|audit|consult|insurance|bank|microfinance|finance|agency|real estate|architect|engineer|office|firm',
        'NGOs & churches' => 'ngo|charity|foundation|church|mission|trust\b|non-profit|nonprofit',
        'Transport & logistics' => 'transport|logistic|courier|freight|travel agen|tour operator|car hire|bus\b|taxi',
        'Farming & manufacturing' => 'farm|agri|estate|tea|tobacco|manufactur|factory|mill|industr',
    ];
    foreach ($map as $g => $re) {
        if (preg_match('/' . $re . '/u', $t)) {
            return $g;
        }
    }
    return 'Other';
}

/** Task kinds that stay ticked for a week (the task does not recur daily); the others are ticked for today only. */
const PM_PLAN_STICKY = ['call', 'nudge', 'social', 'referral', 'upsell', 'research', 'followup', 'winback', 'postsign'];

/**
 * Today's task list, rebuilt from the state of every lead. Ids are stable (kind + key + lead + the lead's touch count),
 * so a ticked task stays ticked until something about that lead changes (a reply, a message, a logged call).
 */
function pm_daily_plan(array $leads, array $cfg): array
{
    $byId = [];
    foreach ($leads as $k => $l) {
        if (($l['brand'] ?? 'promanaged') === pm_brand()) {
            $byId[(string)($l['id'] ?? $k)] = $l + ['id' => (string)$k];
        }
    }
    $leads = $byId;
    $tasks = [];
    $touch = fn($l) => count((array)($l['thread'] ?? [])) + count((array)($l['calls'] ?? []));
    $add = function (string $kind, string $title, string $lead = '', int $prio = 5, string $key = '') use (&$tasks, $leads, $touch) {
        $n = $lead !== '' && isset($leads[$lead]) ? $touch($leads[$lead]) : 0;
        $tasks[] = ['id' => substr(md5($kind . '|' . ($key !== '' ? $key : $title) . '|' . $lead . '|' . $n), 0, 10), 'kind' => $kind, 'title' => $title, 'lead' => $lead, 'prio' => $prio];
    };
    $ts = fn($v) => strtotime((string)$v) ?: 0;
    $days = fn(string $at) => ($t = strtotime($at)) ? (int)((time() - $t) / 86400) : 0;
    $lastOut = function ($l) use ($ts) { // our latest message to them, from last_out or the thread
        $t = $ts($l['last_out'] ?? '');
        foreach ((array)($l['thread'] ?? []) as $m) {
            if (($m['dir'] ?? '') === 'out') {
                $t = max($t, $ts($m['at'] ?? ''));
            }
        }
        return $t;
    };
    $called = function ($l, int $ref) use ($ts) { // a call logged since $ref (a no-answer counts for 2 days, then it is worth another try)
        foreach ((array)($l['calls'] ?? []) as $c) {
            $t = $ts($c['at'] ?? '');
            if ($t > $ref && (($c['outcome'] ?? '') !== 'no_answer' || time() - $t < 2 * 86400)) {
                return true;
            }
        }
        return false;
    };
    $cap = max(0, $cfg['send_cap'] - pm_sent_today($leads));
    // existing ProManaged clients are never cold-pitched: they get a check-in that looks for the next job instead
    $isClient = fn($l) => (bool)array_filter((array)(pm_agents_config('promanaged')['existing_clients'] ?? []), fn($x) => $x !== '' && stripos((string)$l['name'], (string)$x) !== false);
    foreach ($leads as $l) {
        if ($isClient($l) && !in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true)) {
            $add('upsell', pm_brand() === 'travel'
                ? "{$l['name']} already works with ProManaged IT: message the person you know there personally about listing on Travel Malawi"
                : "{$l['name']} is already a client: call your contact, ask how things are going and what else is slowing them down", $l['id'], 2, 'client-checkin');
        }
    }
    $ready = array_filter($leads, fn($l) => ($l['status'] ?? '') === 'drafted' && !$isClient($l) && !pm_lead_snoozed($l) && !pm_lead_email_dead($l));
    usort($ready, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $n = count($ready);
    if ($n) {
        $add('review', "Review and send the top " . min($n, $cap) . " of $n drafted first emails (send limit left today: $cap)", '', 1, 'review');
    }
    $top = [];
    foreach (array_slice($ready, 0, $cap) as $l) {
        $top[$l['id']] = 1;
        $add('send', "Send first message to {$l['name']} ({$l['city']}), score " . (int)($l['score'] ?? 0), $l['id'], 2, 'send');
    }
    // No lead is ever dropped quietly: every stage has a next step, and when one channel goes quiet the next one takes over.
    foreach ($leads as $l) {
        $st = $l['status'] ?? '';
        if ($st === 'optout') {
            continue;
        }
        $id = $l['id'];
        $nm = $l['name'];
        $ph = ($l['whatsapp'] ?? '') ?: ($l['phone'] ?? '');
        $out = $lastOut($l);
        $replyAt = $ts($l['last_reply'] ?? '');
        $await = $ts($l['awaiting_reply_since'] ?? '');
        $sn = substr(trim((string)($l['snooze_until'] ?? '')), 0, 10);
        if (pm_lead_snoozed($l)) {
            continue; // asked to be left alone until $sn
        }
        if ($sn !== '' && !in_array($st, ['won', 'lost'], true) && $days($sn) <= 14 && $out < $ts($sn)) {
            $add('followup', "Resume: $nm asked to be contacted around " . date('j M', $ts($sn)), $id, 2, 'resume');
        }
        if (!empty($l['email_bad']) && !in_array($st, ['won', 'lost'], true)) {
            $add('research', "Find another contact for $nm (email bounced)", $id, $ph !== '' ? 4 : 3, 'email-bad');
        }
        // Someone wrote to us and we have not answered since.
        $ref = $st === 'replied' ? max($replyAt, $await) : (in_array($st, ['proposal', 'won', 'lost'], true) ? $await : 0);
        if ($st === 'replied' && !$ref) {
            $ref = $ts($l['updated'] ?? ''); // older replies without a timestamp
        }
        if ($ref > 0 && $ref > $out) {
            $d = (int)((time() - $ref) / 86400);
            $fbq = ($l['source'] ?? '') === 'facebook';
            $what = $st === 'replied' ? ($fbq ? "Answer $nm on Facebook, then move to WhatsApp or a call" : "Answer {$nm}'s reply and book a call or demo") : "Answer {$nm}'s new message (status: " . (PM_LEAD_STATUSES[$st] ?? $st) . ')';
            $add('reply', ($d >= 1 ? "OVERDUE ($d day" . ($d > 1 ? 's' : '') . "): " : '') . $what, $id, $d >= 1 ? 0 : 1, 'reply');
        } elseif ($st === 'replied' && $out > $replyAt && $replyAt > 0 && ($q = (int)((time() - $out) / 86400)) >= 5 && !$called($l, $out)) {
            $add('call', "Call or WhatsApp $nm" . ($ph ? " on $ph" : '') . ": no answer to our reply for $q days", $id, 3, 'reply-silent');
        }
        if ($st === 'replied' && !empty($l['want_proposal']) && empty($l['proposal_sent_at'])) {
            $add('send', "$nm asked for a proposal: check it and send it", $id, 1, 'want-proposal');
        }
        if ($st === 'proposal') {
            $sent = $ts($l['proposal_sent_at'] ?? '') ?: $ts($l['updated'] ?? '');
            $d = $sent ? (int)((time() - $sent) / 86400) : 0;
            $views = (int)($l['view_count'] ?? 0);
            $opened = !empty($l['viewed_at']) || $views > 0;
            if ($opened && !$called($l, $ts($l['viewed_at'] ?? ''))) {
                $add('call', "$nm opened the proposal" . ($views > 1 ? " $views times" : ($views === 1 ? ' once' : '')) . ' and has not signed: call now' . ($ph ? " on $ph" : ''), $id, 0, 'proposal-viewed');
            }
            $valid = substr(trim((string)($l['proposal_valid_until'] ?? '')), 0, 10);
            if ($valid !== '' && ($vt = strtotime($valid))) {
                $left = (int)floor(($vt - strtotime(date('Y-m-d'))) / 86400);
                if ($left >= 0 && $left <= 3) {
                    $add('nudge', "{$nm}'s proposal offer expires " . ($left === 0 ? 'today' : "in $left day" . ($left > 1 ? 's' : '')) . ': ask for a decision', $id, 1, 'proposal-expiry');
                }
            }
            if ($d >= 21) {
                $add('nudge', "Last try with $nm: ask if the timing is wrong and offer to check back next month", $id, 3, 'proposal-last');
            } elseif ($d >= 7) {
                if (!$opened && !$called($l, $sent)) {
                    $add('call', "Call $nm about the proposal" . ($ph ? " on $ph" : '') . ': answer questions, agree a decision date', $id, 2, 'proposal-call');
                }
            } elseif ($d >= 3 && !$opened) {
                $add('nudge', "$nm has not opened the proposal after $d days: " . ($ph ? 'resend it on WhatsApp' : 'resend it by email and offer a 10-minute walk-through'), $id, 2, 'proposal-unopened');
            }
        }
        if ($st === 'drafted' && !isset($top[$id]) && !pm_lead_email_dead($l) && $days((string)($l['updated'] ?? '')) >= 2) {
            $add('send', "$nm's first message has waited " . $days((string)$l['updated']) . " days: send it or improve it", $id, 2, 'send-waited');
        }
        if ($st === 'contacted') {
            $last = strtotime((string)($l['last_contacted'] ?? '')) ?: 0;
            $quiet = $last ? (int)((time() - $last) / 86400) : 0;
            $fu = (int)($l['followups'] ?? 0);
            $emailOk = !empty($l['email']) && empty($l['email_bad']);
            if ($last && $quiet >= $cfg['followup_days'] && $fu < $cfg['max_followups']) {
                if ($emailOk) {
                    $add('followup', "Follow up with $nm (no reply for $quiet days)", $id, 3, 'followup');
                }
            } elseif ($last && $fu >= $cfg['max_followups'] && $quiet >= $cfg['followup_days']) {
                // email went quiet: switch channel instead of giving up
                if ($ph !== '' && $quiet < 30) {
                    if (!$called($l, $last)) {
                        $add('call', "Email is quiet: call or WhatsApp $nm on $ph (ask for the right person)", $id, 3, 'quiet-call');
                    }
                } elseif (!empty($l['facebook']) && $quiet < 30) {
                    $add('social', "Email is quiet: like or comment on {$nm}'s latest Facebook post, then message their Page", $id, 4, 'quiet-social');
                } elseif ($quiet >= 30) {
                    $add('nudge', "Send $nm a short last note (\"shall I close your file?\"), then leave them for 2 months", $id, 5, 'quiet-last');
                }
            }
            if (!$emailOk && $ph !== '' && $fu < $cfg['max_followups'] && !$called($l, $last)) {
                $add('call', "Call or WhatsApp $nm on $ph", $id, 4, 'call-no-email');
            }
        }
        if ($st === 'won') {
            $w = $ts($l['won_at'] ?? '') ?: $ts($l['updated'] ?? ''); // keyed to the win date, not the last edit
            $d = $w ? (int)((time() - $w) / 86400) : 0;
            if ($d >= 14 && $d < 21) {
                $add('referral', "Ask $nm for a referral and a Facebook review while they are happy", $id, 4, 'referral-14');
            } elseif ($d >= 60 && $d < 67) {
                $add('referral', "Ask $nm for a referral again: two months in, how is it going?", $id, 4, 'referral-60');
                $add('upsell', "Check in with $nm: how is it going, and what else could we take off their hands?", $id, 4, 'upsell-60');
            }
        }
        if ($st === 'lost' && !in_array(strtolower(str_replace(' ', '_', trim((string)($l['lost_reason'] ?? '')))), ['not_interested'], true)
            && (!empty($l['sent']) || !empty($l['wa_sent'])) && ($d = $days((string)(($l['lost_at'] ?? '') ?: ($l['updated'] ?? '')))) >= 90 && $d < 97) {
            $add('nudge', "Re-open $nm: 3 months on, things change. One friendly check-in", $id, 6, 'reopen');
        }
        if ($st === 'qualified' && empty($l['email']) && empty($l['phone'])) {
            $add('research', "Find a contact for $nm ({$l['city']}): no email or phone yet", $id, 6, 'find-contact');
        }
        // what the win-back, post-sign and re-verify agents prepared: the owner reviews and sends (nothing goes out by itself)
        foreach (pm_lead_open_drafts($l) as $od) {
            if ($od === 'winback') {
                $add('winback', "Review the win-back draft for $nm and send it if the time is right", $id, 4, 'winback');
            } elseif ($od === 'postsign') {
                $add('postsign', "Send $nm the thank-you asks: testimonial, Google review and referral (drafted)", $id, 3, 'postsign');
            } else {
                $add('research', "Check $nm: " . lcfirst((string)$l['reverify']['what']), $id, 4, 'reverify');
            }
        }
    }
    $seen = [];
    $tasks = array_values(array_filter($tasks, function ($t) use (&$seen) {
        return !isset($seen[$t['id']]) && ($seen[$t['id']] = true);
    }));
    usort($tasks, fn($a, $b) => $a['prio'] <=> $b['prio']);
    $all = pm_load('plan_done', fn() => []);
    $today = $all[date('Y-m-d')] ?? [];
    $week = [];
    foreach ($all as $day => $ids) {
        if ($day >= date('Y-m-d', strtotime('-6 days'))) {
            $week = array_merge($week, (array)$ids);
        }
    }
    foreach ($tasks as &$t) {
        $t['done'] = in_array($t['id'], in_array($t['kind'], PM_PLAN_STICKY, true) ? $week : $today, true);
    }
    return $tasks;
}

function pm_plan_mark(string $id, bool $done): void
{
    $all = pm_load('plan_done', fn() => []);
    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-7 days'));
    $all = array_filter($all, fn($ids, $day) => $day >= $from, ARRAY_FILTER_USE_BOTH); // older days are dropped
    foreach ($all as $day => $ids) {
        $all[$day] = array_values(array_diff((array)$ids, [$id]));
    }
    $all[$today] ??= [];
    if ($done) {
        $all[$today][] = $id;
    }
    pm_save('plan_done', $all);
}

/* ---------------- Pipeline stage marking ---------------- */

/** How far a lead has got. A stage never goes backwards (lost and optout are handled separately). */
const PM_LEAD_RANK = ['new' => 0, 'qualified' => 1, 'drafted' => 1, 'contacted' => 2, 'replied' => 3, 'proposal' => 4, 'won' => 5];

/** A lead's id by id, then email (any case), then name (same brand first, one clear match only). */
function pm_lead_resolve(array $leads, string $leadId, string $email, string $business, string $city = ''): ?string
{
    if ($leadId !== '' && isset($leads[$leadId])) {
        return $leadId;
    }
    $brand = pm_brand();
    $pick = function (array $ids) use ($leads, $brand): string {
        $mine = array_values(array_filter($ids, fn($i) => ($leads[$i]['brand'] ?? 'promanaged') === $brand));
        return (string)(($mine ?: $ids)[0]);
    };
    $email = strtolower(trim($email));
    if ($email !== '') {
        $hits = [];
        foreach ($leads as $id => $l) {
            if (strtolower(trim((string)($l['email'] ?? ''))) === $email) {
                $hits[] = (string)$id;
            }
        }
        if ($hits) {
            return $pick($hits);
        }
    }
    $business = trim($business);
    if ($business !== '') {
        foreach (array_unique([$brand, $brand === 'travel' ? 'promanaged' : 'travel']) as $b) {
            $id = pm_lead_id($business, $city, $b);
            if (isset($leads[$id])) {
                return $id;
            }
        }
        $norm = fn($n) => preg_replace('/\b(hotel|lodge|ltd|limited|the|restaurant|bar|gym|&|and)\b|[^a-z0-9]+/i', '', strtolower((string)$n));
        $want = $norm($business);
        $hits = [];
        foreach ($leads as $id => $l) {
            if ($want !== '' && $norm($l['name'] ?? '') === $want && ($city === '' || strcasecmp(trim((string)($l['city'] ?? '')), $city) === 0)) {
                $hits[] = (string)$id;
            }
        }
        if ($hits) {
            $mine = array_values(array_filter($hits, fn($i) => ($leads[$i]['brand'] ?? 'promanaged') === $brand));
            if (count($mine) === 1 || (!$mine && count($hits) === 1)) {
                return $mine[0] ?? $hits[0];
            }
        }
    }
    return null;
}

/**
 * Move a lead to a later pipeline stage (contacted, replied, proposal, won) and/or merge fields into it. Found by id, else email, else name.
 * Never downgrades. A lead that is optout is never touched; a lost lead keeps its status (extras are still merged).
 * 'optout' always applies; 'lost' applies unless the lead is won. Empty $status merges $extra only.
 * Won: sets won_at and asks the social engine for a testimonial (pm_social_proof_request, if present). Proposal: sets proposal_sent_at.
 * Returns true when the lead was found and left consistent, false when not found or optout. Saved through the leads lock.
 */
function pm_lead_mark(string $leadId, string $email, string $business, string $status, array $extra = []): bool
{
    $prevSnap = $GLOBALS['PM_LEADS_SNAP'] ?? null; // so the caller's own load/save cycle is not disturbed
    $leads = pm_leads();
    $id = pm_lead_resolve($leads, $leadId, $email, $business, (string)($extra['city'] ?? ''));
    $restore = function () use ($prevSnap) {
        if ($prevSnap === null) {
            unset($GLOBALS['PM_LEADS_SNAP']);
        } else {
            $GLOBALS['PM_LEADS_SNAP'] = $prevSnap;
        }
    };
    if ($id === null || ($leads[$id]['status'] ?? '') === 'optout') {
        $restore();
        return false;
    }
    $l = $leads[$id];
    $cur = (string)($l['status'] ?? 'new');
    $new = strtolower(trim($status));
    $apply = false;
    if ($new === 'optout') {
        $apply = true;
    } elseif ($new === 'lost') {
        $apply = $cur !== 'lost' && $cur !== 'won';
    } elseif (isset(PM_LEAD_RANK[$new]) && $cur !== 'lost') {
        $apply = PM_LEAD_RANK[$new] > (PM_LEAD_RANK[$cur] ?? 0);
    }
    foreach ($extra as $k => $v) {
        if (!in_array($k, ['id', 'brand', 'status', 'city'], true)) {
            $l[$k] = $v;
        }
    }
    if ($apply) {
        $l['status'] = $new;
        pm_lead_note($l, 'Marked ' . strtolower(PM_LEAD_STATUSES[$new] ?? $new));
        if ($new === 'won' && empty($l['won_at'])) {
            $l['won_at'] = date('Y-m-d H:i');
        }
        if ($new === 'proposal' && empty($l['proposal_sent_at'])) {
            $l['proposal_sent_at'] = date('Y-m-d H:i');
        }
        if ($new === 'won' && function_exists('pm_social_proof_request')) {
            try {
                pm_social_proof_request($l);
            } catch (Throwable $e) {
                pm_agent_log('Social', 'Testimonial request failed: ' . $e->getMessage());
            }
        }
    }
    $l['updated'] = date('Y-m-d H:i');
    $leads[$id] = $l;
    pm_leads_save($leads);
    $restore();
    return true;
}

/**
 * Backpressure, pure: how many drafts wait unsent (drafted, nothing sent by email or WhatsApp) and what that means for today's run.
 * pause = scouts skipped (backlog >= 2 x send_cap); hold_new = writers/researchers skip NEW cold leads (backlog >= send_cap);
 * room = how many leads may still be added today (new_per_day minus leads created today, counted from the leads themselves).
 */
function pm_backlog_state(array $leads, array $cfg, string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $backlog = 0;
    $today = 0;
    $d = date('Y-m-d');
    foreach ($leads as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        if (($l['status'] ?? '') === 'drafted' && empty($l['sent']) && empty($l['wa_sent'])) {
            $backlog++;
        }
        $today += str_starts_with((string)($l['created'] ?? ''), $d) ? 1 : 0;
    }
    $cap = max(1, (int)($cfg['send_cap'] ?? 10));
    $on = (bool)($cfg['backlog_pause'] ?? true);
    return ['backlog' => $backlog, 'cap' => $cap, 'pause' => $on && $backlog >= 2 * $cap, 'hold_new' => $on && $backlog >= $cap,
        'added_today' => $today, 'room' => max(0, (int)($cfg['new_per_day'] ?? 10) - $today)];
}

/* ---------------- Orchestration helpers ---------------- */

/** Names of leads already known for the SAME city and kind of business: all a scout needs to skip (not a global list). */
function pm_known_names(array $leads, string $sector, string $city): array
{
    $grp = pm_lead_group(['type' => $sector, 'brand' => pm_brand()]);
    $out = [];
    foreach ($leads as $l) {
        if (strcasecmp(trim((string)($l['city'] ?? '')), trim($city)) !== 0) {
            continue;
        }
        $same = strcasecmp((string)($l['src']['sector'] ?? ''), $sector) === 0 || ($grp !== 'Other' && pm_lead_group($l) === $grp) || strcasecmp((string)($l['type'] ?? ''), $sector) === 0;
        if ($same) {
            $out[] = $l['name'] . ' (' . $l['city'] . ')';
        }
    }
    return array_slice($out, -80);
}

/** A scout hit found again: fill only the fields the existing lead lacks. Returns true if anything was added. */
function pm_lead_merge_missing(array &$into, array $b): bool
{
    $changed = false;
    foreach (['website', 'phone', 'email', 'address', 'facebook', 'instagram'] as $f) {
        $v = trim((string)($b[$f] ?? ''));
        if ($v !== '' && trim((string)($into[$f] ?? '')) === '') {
            $into[$f] = $v;
            $changed = true;
        }
    }
    foreach (['evidence', 'need_signals', 'social_gaps'] as $f) {
        $add = array_values(array_diff(array_map('strval', (array)($b[$f] ?? [])), array_map('strval', (array)($into[$f] ?? []))));
        if ($add) {
            $into[$f] = array_slice(array_merge((array)($into[$f] ?? []), $add), 0, 6);
            $changed = true;
        }
    }
    return $changed;
}

/** Today's sector x city searches. Rotates so every combination is visited over time. $weights: Director's numeric per-type weights (0..100) — searches are allocated proportionally. */
function pm_scout_targets(array $cfg, ?array $weights = null): array
{
    // Types the Director says to search less of are left out today (never below 2 types).
    $drop = function_exists('pm_director_drop') ? pm_director_drop() : [];
    if ($drop) {
        $keep = array_values(array_filter($cfg['sectors'], fn($s) => !array_filter($drop, fn($d) => $d !== '' && (strcasecmp($d, $s) === 0 || stripos($s, $d) !== false || stripos($d, $s) !== false))));
        if (count($keep) >= 2) {
            $cfg['sectors'] = $keep;
        }
    }
    $combos = [];
    foreach ($cfg['sectors'] as $s) {
        foreach ($cfg['cities'] as $c) {
            $combos[] = [$s, $c];
        }
    }
    if (!$combos) {
        return [];
    }
    $S = count($cfg['sectors']);
    $C = max(1, count($cfg['cities']));
    $n = min($cfg['scouts_per_day'], count($combos));
    $day = (int)floor(time() / 86400);
    if ($weights) { // the Director's numeric weights decide how many slots each type gets
        $sectors = pm_weighted_pick($cfg['sectors'], $weights, $n, $day * $n);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [$sectors[$i], $cfg['cities'][($day * 7 + $i * 3) % $C]];
        }
        return $out;
    }
    // Different business type and city on every search; the pairing shifts each day so all combinations come round.
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $k = $day * $n + $i;
        $out[] = [$cfg['sectors'][$k % $S], $cfg['cities'][($day * 7 + $i * 3 + intdiv($k, $S)) % $C]];
    }
    // The Director's picks get half of today's searches: more of what brings replies.
    $focus = function_exists('pm_director_focus') ? pm_director_focus() : [];
    if ($focus && $n >= 2) {
        for ($i = intdiv($n, 2); $i < $n; $i++) {
            $out[$i] = [$focus[$i % count($focus)], $cfg['cities'][($day * 5 + $i * 2) % $C]];
        }
    }
    return $out;
}

/** The PHP command line program: PHP_BINARY under `php -S`, plain `php` when running inside Apache/FPM. */
function pm_php_binary(): string
{
    return preg_match('/php(\d|-cgi)?(\.exe)?$/i', PHP_BINARY) && !str_contains(strtolower(PHP_BINARY), 'fpm') ? PHP_BINARY : 'php';
}

/** Start the daily run in the background without blocking the web page. */
function pm_agents_launch(string $mode = ''): bool
{
    $state = pm_run_state();
    if (($state['state'] ?? '') === 'running') {
        return false;
    }
    $script = __DIR__ . '/run_agents.php';
    $php = pm_php_binary();
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" "' . $php . '" "' . $script . '" ' . pm_brand() . ($mode !== '' ? ' ' . $mode : '') . ' > NUL 2>&1', 'r'));
    } else {
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(pm_brand()) . ($mode !== '' ? ' ' . escapeshellarg($mode) : '') . ' > /dev/null 2>&1 &');
    }
    return true;
}


/* ---------------- AI message composer: pick tone, length and angle, get a message for one business ---------------- */

const PM_COMPOSE_TONES = [
    'professional' => 'Professional', 'human' => 'Friendly and human', 'marketing' => 'Marketing (punchy)',
    'warm' => 'Warm and personal', 'direct' => 'Direct and brief',
];
const PM_COMPOSE_LENGTHS = ['short' => 'Short', 'standard' => 'Standard', 'detailed' => 'Longer, detailed'];
const PM_COMPOSE_ANGLES = [
    'need' => "This business's specific need", 'benefit' => 'Main benefit', 'question' => 'Curiosity question',
    'offer' => 'Offer a one-page plan or call', 'proof' => 'Why us (only true facts)', 'followup' => 'Follow-up nudge',
];

/** Writes one email or WhatsApp message for one lead. Returns ['subject','body']. One small AI call. */
function pm_agent_compose(array $lead, string $channel, string $tone, string $length, string $angle, string $lang): array
{
    pm_brand_set((string)($lead['brand'] ?? 'promanaged'));
    $s = pm_settings();
    $wa = $channel === 'whatsapp';
    $words = ['short' => $wa ? 40 : 75, 'standard' => $wa ? 65 : 110, 'detailed' => $wa ? 110 : 170][$length] ?? 75;
    $toneTxt = [
        'professional' => 'Professional and courteous, polished but not stiff.',
        'human' => 'Friendly and human: write like a thoughtful person typing to someone they respect, contractions allowed, no corporate phrases.',
        'marketing' => 'Marketing-sharp: lead with the outcome the owner wants, vivid and specific, confident but never hyped.',
        'warm' => 'Warm and personal: show real interest in their place and what they have built.',
        'direct' => 'Direct and brief: one idea, no filler, easy to answer.',
    ][$tone] ?? '';
    $angleTxt = [
        'need' => "Build the message around the one specific need this business's own evidence shows.",
        'benefit' => 'Build the message around the single biggest gain we offer them, in plain words.',
        'question' => 'Open with one curious, specific question about how they handle the thing we improve.',
        'offer' => 'Make the offer of a one-page plan for their business, or a 10-minute call, the heart of the message.',
        'proof' => 'Say why trust us, using only facts you were given (no names, numbers or customers you were not told).',
        'followup' => 'This is a follow-up to an earlier message: add one new angle, do not repeat the first message, no guilt.',
    ][$angle] ?? '';
    $langTxt = $lang === 'chichewa' ? 'Open with a short, correct Chichewa greeting (for example "Moni") then continue in clear English.' : 'English.';
    $sender = pm_sender_name();
    $system = pm_agents_company_brief('tiny') . "\nYou write one " . ($wa ? 'WhatsApp message' : 'email') . " for {$s['company_name']}, as " . ($sender !== '' ? $sender . ' (first name ' . explode(' ', $sender)[0] . ')' : 'a member of the team (no personal name: introduce the company)') . ".\n"
        . "Tone: $toneTxt\nAngle: $angleTxt\nLength: about $words words (never more than " . (int)($words * 1.25) . ").\nLanguage: $langTxt\n"
        . PM_EMAIL_CRAFT . ' ' . ($wa ? 'This is the WhatsApp version.' : 'This is the email version.') . ' Reply JSON only.';
    $user = json_encode([
        'business' => $lead['name'], 'type' => $lead['type'], 'city' => $lead['city'], 'contact' => $lead['contact'] ?? '',
        'evidence' => array_slice($lead['evidence'] ?? [], 0, 3), 'research' => pm_research_brief($lead), 'online_gaps' => array_slice($lead['social_gaps'] ?? [], 0, 2), 'lead_with' => $lead['pain'] ?? '', 'how_it_stops' => pm_fix_for((string)($lead['pain'] ?? '')),
        'earlier_message' => $angle === 'followup' ? mb_substr((string)(($lead['drafts']['email_body'] ?? '') ?: ($lead['drafts']['whatsapp'] ?? '')), 0, 250) : '',
    ], JSON_UNESCAPED_UNICODE) . "\nJSON: " . ($wa ? '{"body":"..."}' : '{"subject":"...","body":"..."}');
    $out = null;
    for ($try = 0; $try < 2 && (!is_array($out) || trim((string)($out['body'] ?? '')) === ''); $try++) { // one retry if the model slips on the JSON
        $out = pm_agent_json(pm_claude($system, $user, false, 1200, 'write'));
    }
    if (!is_array($out) || trim((string)($out['body'] ?? '')) === '') {
        throw new RuntimeException('The AI did not return a message. Try again.');
    }
    return ['subject' => trim((string)($out['subject'] ?? '')), 'body' => trim((string)$out['body'])];
}

/** The picker shown above a message box. $target tells the script which fields to fill. */
function pm_compose_controls(string $leadId, string $csrf, bool $both = true): string
{
    $opt = fn(array $a, string $sel) => implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . pm_h($v) . '</option>', array_keys($a), $a));
    return '<div class="composer" data-id="' . pm_h($leadId) . '" data-csrf="' . pm_h($csrf) . '"><b>Write with AI</b>'
        . '<div class="cgrid"><select data-k="tone" aria-label="Tone">' . $opt(PM_COMPOSE_TONES, 'human') . '</select>'
        . '<select data-k="length" aria-label="Length">' . $opt(PM_COMPOSE_LENGTHS, 'short') . '</select>'
        . '<select data-k="angle" aria-label="Angle">' . $opt(PM_COMPOSE_ANGLES, 'need') . '</select>'
        . '<select data-k="lang" aria-label="Language"><option value="english">English</option><option value="chichewa">Chichewa greeting</option></select></div>'
        . '<div class="btns">' . ($both ? '<button type="button" class="btn small aigen" data-channel="email">Write email</button>' : '')
        . '<button type="button" class="btn small aigen" data-channel="whatsapp">Write WhatsApp</button><span class="hint cmsg"></span></div></div>';
}


/* ---------------- RESEARCHER: what a business's website, social pages and the news say ---------------- */

/** [host, ip] when the URL is http(s) on a normal web port and every address its host resolves to is public; null otherwise. */
function pm_url_public(string $url): ?array
{
    $p = parse_url(trim($url));
    if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
        return null;
    }
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443, 8080, 8443], true)) {
        return null;
    }
    $host = trim(strtolower((string)$p['host']), '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            return null;
        }
        $ips = gethostbynamel($host) ?: [];
        foreach (function_exists('dns_get_record') ? (@dns_get_record($host, DNS_AAAA) ?: []) : [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || stripos($ip, '::ffff:') === 0) {
            return null;
        }
    }
    return [$host, $ips[0]];
}

/** Resolves a Location header against the URL it came from. */
function pm_url_join(string $base, string $loc): string
{
    if (preg_match('#^https?://#i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) {
        return ($p['scheme'] ?? 'http') . ':' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return $root . $loc;
    }
    $dir = str_replace(chr(92), '/', dirname($p['path'] ?? '/'));
    return $root . rtrim($dir, '/') . '/' . $loc;
}

/** Email addresses a web page shows (also in mailto: links), the business's own domain first, at most $max. */
function pm_page_emails(string $html, string $host = '', int $max = 10): array
{
    $t = html_entity_decode(str_ireplace(['%40', '[at]'], '@', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/[a-z0-9._%+\-]{1,64}@[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,24}/i', $t, $m);
    $own = preg_replace('/^www\./', '', strtolower($host));
    $mine = [];
    $other = [];
    foreach ($m[0] as $e) {
        $e = strtolower(rtrim($e, '.'));
        $dom = substr(strrchr($e, '@'), 1);
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/\.(png|jpe?g|gif|svg|webp|css|js|woff2?|ico)$/', $e)
            || preg_match('/^(example|domain|email|yourdomain|sentry|wixpress|sentry-next)\./', $dom) || preg_match('/^(your|name|user|email|test|john\.?doe|example)@/', $e)) {
            continue;
        }
        if ($own !== '' && ($dom === $own || str_ends_with($own, '.' . $dom) || str_ends_with($dom, '.' . $own))) {
            $mine[$e] = 1;
        } else {
            $other[$e] = 1;
        }
    }
    return array_slice(array_keys($mine + $other), 0, $max);
}

/**
 * Facts about a business's website checked by code (no AI, no guessing): does it load, how fast, https, mobile-ready,
 * WhatsApp/booking/contact options, copyright year, links to its social pages, and the emails it shows (homepage + /contact).
 * pm_site_check($url, true) = for a URL typed in by a stranger: private and reserved addresses are never fetched (each redirect hop is re-checked).
 */
function pm_site_check(string $url, string|bool $type = '', bool $noPrivate = false): array
{
    if (is_bool($type)) {
        [$noPrivate, $type] = [$type, ''];
    }
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return [];
    }
    if ($noPrivate && !pm_url_public($url)) {
        return [];
    }
    // Browsers repair some certificate chains that our computer cannot, so a certificate complaint here is never reported as the site's fault:
    // we look again without the check. A site counts as down only if it fails twice for a real reason (no answer, server error, not found).
    $get = function (string $u, bool $strict) use ($noPrivate) {
        $t0 = microtime(true);
        for ($hop = 0; ; $hop++) {
            $loc = '';
            $ch = curl_init($u);
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$loc) {
                    if (stripos($line, 'location:') === 0) {
                        $loc = trim(substr($line, 9));
                    }
                    return strlen($line);
                }];
            if ($noPrivate) {
                $opt[CURLOPT_NOPROGRESS] = false;
                $opt[CURLOPT_PROGRESSFUNCTION] = fn($c, $dt, $dn) => $dn > 3000000 ? 1 : 0; // a stranger's page: stop at 3 MB
                $pub = pm_url_public($u);
                if (!$pub) {
                    curl_close($ch);
                    return [false, 0.0, 0, $u, -1, 'address not allowed', ''];
                }
                $opt[CURLOPT_FOLLOWLOCATION] = false;
                if (filter_var($pub[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { // pin the checked address so DNS cannot change between check and fetch
                    $port = parse_url($u, PHP_URL_PORT) ?: (stripos($u, 'https') === 0 ? 443 : 80);
                    $opt[CURLOPT_RESOLVE] = ["{$pub[0]}:$port:{$pub[1]}"];
                }
            } else {
                $opt[CURLOPT_FOLLOWLOCATION] = true;
                $opt[CURLOPT_MAXREDIRS] = 5;
            }
            curl_setopt_array($ch, $opt);
            pm_curl_native_ca($ch);
            if (!$strict) {
                curl_setopt_array($ch, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
            }
            $html = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $r = [$html, round(microtime(true) - $t0, 1), $code, $noPrivate ? $u : (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), curl_errno($ch), curl_error($ch), (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
            curl_close($ch);
            if ($noPrivate && $code >= 300 && $code < 400 && $loc !== '') {
                if ($hop >= 3) {
                    return [false, $r[1], 0, $u, -1, 'too many redirects', ''];
                }
                $u = pm_url_join($u, $loc);
                continue;
            }
            return $r;
        }
    };
    $strict = true;
    [$html, $secs, $code, $final, $errno, $err, $ctype] = $get($url, true);
    if (!is_string($html) && in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
        $strict = false;
        [$html, $secs, $code, $final, $errno, $err, $ctype] = $get($url, false);
    }
    if ($errno !== -1 && (!is_string($html) || $code === 0 || $code >= 500)) {
        sleep(2);
        $strict = false;
        [$html, $secs, $code, $final, $errno, $err, $ctype] = $get($url, false);
    }
    if (!is_string($html) || $code === 0 || $code >= 400) {
        $why = $code === 404 ? 'page not found' : ($code >= 500 ? 'server error ' . $code : (in_array($errno, [6, 7, 28], true) ? 'no answer' : ''));
        return $why === '' ? [] : ['url' => $url, 'loads' => false, 'facts' => ['The website ' . preg_replace('#^https?://#', '', $url) . ' did not open for us on ' . date('j M') . " (twice: $why)"]];
    }
    $html = pm_to_utf8($html, $ctype);
    $h = substr($html, 0, 2000000);
    $low = strtolower($h);
    preg_match('#<title[^>]*>(.*?)</title>#is', $h, $tm);
    preg_match_all('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(20\d\d)/i', $h, $cy);
    $year = $cy[1] ? max(array_map('intval', $cy[1])) : 0;
    preg_match_all('#https?://(?:www\.|m\.)?(facebook\.com|instagram\.com|tiktok\.com|linkedin\.com)/[A-Za-z0-9._/\-?=]+#i', $h, $sl);
    $social = [];
    foreach (array_unique($sl[0]) as $u) {
        if (!preg_match('#/(sharer|share|plugins|dialog|tr\?|intent)#i', $u)) {
            $social[strtolower(preg_replace('#^https?://(?:www\.|m\.)?([a-z]+)\..*$#i', '$1', $u))] ??= rtrim($u, '/');
        }
    }
    $r = ['url' => $final ?: $url, 'loads' => true, 'secs' => $secs, 'https' => str_starts_with(strtolower($final ?: $url), 'https://'),
        'mobile' => str_contains($low, 'name="viewport"') || str_contains($low, "name='viewport'"),
        'whatsapp' => (bool)preg_match('#wa\.me/|api\.whatsapp\.com|whatsapp://#i', $h),
        'booking' => (bool)preg_match('#book now|book online|book a (room|table|stay)|reserve (a|your|now)|make a reservation|reservations|check availability|booking\.com|nightsbridge|cloudbeds|beds24|checkfront#i', $h),
        'form' => str_contains($low, '<form'), 'email' => (bool)preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', strip_tags($h)),
        'shop' => (bool)preg_match('#add to cart|checkout|woocommerce|shopify#i', $h), 'year' => $year, 'title' => trim(html_entity_decode(strip_tags($tm[1] ?? ''))), 'social' => $social];
    // emails: homepage plus /contact (one extra request)
    $host = (string)parse_url($r['url'], PHP_URL_HOST);
    $emails = pm_page_emails($h, $host);
    if ($host !== '') {
        $cu = (string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $r['url']) . '/contact';
        [$ch2, , $cc2, , , , $ct2] = $get($cu, $strict);
        if (is_string($ch2) && $cc2 >= 200 && $cc2 < 300) {
            $emails = array_slice(array_values(array_unique(array_merge($emails, pm_page_emails(pm_to_utf8(substr($ch2, 0, 1000000), $ct2), $host)))), 0, 10);
        }
    }
    $r['emails'] = $emails;
    $site = preg_replace('#^https?://(www\.)?#', '', rtrim($r['url'], '/'));
    $f = [];
    $f[] = "$site opened in {$secs}s" . ($secs > 6 ? ' (slow)' : '') . ($r['https'] ? '' : ', without a secure https address');
    $f[] = $r['mobile'] ? 'It is set up for phones' : 'It is not set up for phones (no mobile viewport)';
    if (!$r['whatsapp']) {
        $f[] = 'There is no WhatsApp button or link on the home page';
    }
    if (preg_match('/hotel|lodge|resort|guest|camp|cottage|b&b|bed and breakfast|hostel|backpack|restaurant|spa|salon|gym|suites|inn|chalet|stay/i', $type)) { // only where booking matters
        $f[] = $r['booking'] ? 'Visitors can book or reserve from the site' : 'There is no way to book or reserve on the home page';
    }
    if (!$r['form'] && !$r['email']) {
        $f[] = 'No contact form or email address on the home page';
    }
    if ($year && $year < (int)date('Y') - 1) {
        $f[] = "The footer still says © $year";
    }
    if ($social) {
        $f[] = 'It links to: ' . implode(', ', array_keys($social));
    }
    $r['facts'] = $f;
    return $r;
}

/**
 * The Researcher. Precise by design: code checks the website first (certain facts, free), then ONE web-grounded AI call studies
 * the business's own pages, socials, reviews and news. It must confirm it found the right business (same name and town) and give a source for
 * every fact; anything unsure, unsourced or stale is dropped in code. It also writes ready conversation starters for email and WhatsApp.
 */
function pm_agent_research(array $lead): array
{
    $site = pm_site_check((string)($lead['website'] ?? ''), (string)($lead['type'] ?? ''));
    $first = trim(explode(' ', preg_replace('/^(mr|mrs|ms|dr|miss|madam|sir)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))))[0] ?? '');
    $first = preg_match('/^\p{Lu}\p{L}+$/u', $first) ? $first : '';
    $system = pm_agents_company_brief('short') . "\nYou are the Researcher, and precision is your job. Use web search to study ONE business. "
        . "Step 1, identity: make sure every source is about THIS business in THIS town (same name, same place). Similar names elsewhere, directories that only list the name, and other branches do not count. If you cannot confirm it, say so. "
        . "Step 2, facts: from its own website, its Facebook and Instagram pages, Google reviews, and Malawian or business/finance news from the last 18 months (expansion, new rooms or branch, awards, funding, tenders won, hiring, events). "
        . "Only facts you actually read, each with its exact source URL and the date shown on the source; mark confidence high (read it yourself on an official page or reputable news) or medium (a review site or directory). Never guess, infer or fill gaps. "
        . "The 'checked_by_code' list was verified by our own system: treat it as true, do not contradict it, and never claim a website, certificate or security problem that is not in it. "
        . "Step 3, through our eyes: what is weak or missing online and where we could genuinely help (for our team only). "
        . "Step 4, write 2 or 3 hooks: one real observation plus a curious question that makes the owner want to reply, WITHOUT handing over the solution or offering to fix it. Friendly, never critical or creepy, business information only. "
        . "Step 5, write 3 conversation starters, each built on a different hook, ready to send: an email (subject of max 7 words, then a body of 45 to 80 words: a greeting line" . ($first !== '' ? " to $first" : '') . ", one line saying who we are"
        . ", the observation, one easy question about how they handle it today; ONE question only, no offer to fix it, no prices, no feature list, no sign-off) and a WhatsApp version of 25 to 40 words. " . PM_AGENT_RULES;
    $user = "Business: {$lead['name']} ({$lead['type']}), {$lead['city']}, Malawi. Known website: " . ($lead['website'] ?? '') . '. Known Facebook: ' . ($lead['facebook'] ?? '') . '. Contact: ' . ($lead['contact'] ?? '') . ".\n"
        . 'checked_by_code: ' . json_encode($site['facts'] ?? ['no website known'], JSON_UNESCAPED_UNICODE) . ($site['social'] ?? [] ? ' social links on their site: ' . json_encode($site['social']) : '') . "\n"
        . 'JSON: {"identity":{"confirmed":true,"how":"what proves it is this business"},"discoveries":[{"fact":"","source":"URL","date":"","confidence":"high|medium"}],"website":{"url":"","verdict":"one short line","issues":[""]},'
        . '"social":{"facebook":"URL","instagram":"URL","verdict":"one short line: last post date, followers, reviews"},"news":[{"headline":"","source":"URL","date":""}],"opportunities":[""],"hooks":[""],'
        . '"starters":[{"angle":"2-4 words","subject":"","email":"","whatsapp":""}]}';
    $out = pm_agent_json(pm_claude($system, $user, true, 3000, 'write'));
    if (!is_array($out)) {
        throw new RuntimeException('The research did not return a usable result. Try again.');
    }
    $src = fn($u) => is_string($u) && preg_match('#^https?://#i', $u) && !preg_match('#google\.[a-z.]+/search|bing\.com/search|duckduckgo#i', $u);
    $fresh = fn($d) => trim((string)$d) === '' || !($t = strtotime((string)$d)) || $t > strtotime('-24 months');
    $sure = (bool)($out['identity']['confirmed'] ?? false);
    $disc = $sure ? array_values(array_filter((array)($out['discoveries'] ?? []), fn($d) => is_array($d) && trim((string)($d['fact'] ?? '')) !== '' && $src($d['source'] ?? '')
        && in_array(strtolower((string)($d['confidence'] ?? 'medium')), ['high', 'medium'], true) && $fresh($d['date'] ?? ''))) : [];
    // the code-checked website facts always count: they are certain
    $disc = array_merge(array_map(fn($f) => ['fact' => $f, 'source' => $site['url'], 'date' => date('Y-m-d'), 'confidence' => 'checked'], array_slice($site['facts'] ?? [], 0, 4)), $disc);
    $starters = [];
    foreach (array_slice((array)($out['starters'] ?? []), 0, 3) as $st) {
        $body = trim((string)($st['email'] ?? ''));
        $n = str_word_count($body);
        if ($body === '' || $n < 25 || $n > 120 || pm_has_price($body)) {
            continue; // too thin, too long or mentions money: not sent to the box
        }
        $starters[] = ['angle' => mb_substr((string)($st['angle'] ?? ''), 0, 40), 'subject' => mb_substr(trim((string)($st['subject'] ?? '')), 0, 80), 'email' => $body, 'whatsapp' => trim((string)($st['whatsapp'] ?? ''))];
    }
    $social = (array)($out['social'] ?? []);
    foreach (['facebook', 'instagram'] as $k) { // a link on their own website beats a search result
        if (!empty($site['social'][$k])) {
            $social[$k] = $site['social'][$k];
        }
    }
    return [
        'at' => date('Y-m-d H:i'),
        'sure' => $sure,
        'identity' => mb_substr((string)($out['identity']['how'] ?? ''), 0, 200),
        'site' => $site ? array_diff_key($site, ['facts' => 1]) : [],
        'discoveries' => $disc,
        'website' => (array)($out['website'] ?? []),
        'social' => $sure ? $social : array_intersect_key($social, array_flip(array_keys($site['social'] ?? []))),
        'news' => $sure ? array_values(array_filter((array)($out['news'] ?? []), fn($d) => is_array($d) && trim((string)($d['headline'] ?? '')) !== '' && $src($d['source'] ?? '') && $fresh($d['date'] ?? ''))) : [],
        'opportunities' => array_values(array_filter(array_map('strval', (array)($out['opportunities'] ?? [])))),
        'hooks' => $sure || $site ? array_values(array_filter(array_map('strval', (array)($out['hooks'] ?? [])))) : [],
        'starters' => $sure || $site ? $starters : [],
    ];
}

/** Puts research results on a lead: links and gaps it found are merged in, and the discoveries become evidence. */
function pm_apply_research(array &$lead, array $r): void
{
    $lead['research'] = $r;
    unset($lead['requalify_tries']); // new research: the re-score may try again
    foreach (['facebook', 'instagram'] as $k) {
        $u = (string)($r['social'][$k] ?? '');
        if (empty($lead[$k]) && preg_match('#^https?://#i', $u)) {
            $lead[$k] = $u;
        }
    }
    if (empty($lead['website']) && preg_match('#^https?://#i', (string)($r['website']['url'] ?? ''))) {
        $lead['website'] = $r['website']['url'];
    }
    $gaps = array_merge((array)($lead['social_gaps'] ?? []), array_map('strval', (array)($r['website']['issues'] ?? [])));
    $lead['social_gaps'] = array_values(array_unique(array_filter($gaps)));
    foreach (array_slice($r['discoveries'], 0, 3) as $d) {
        $e = trim($d['fact']) . ' ' . $d['source'];
        if (!in_array($e, (array)($lead['evidence'] ?? []), true)) {
            $lead['evidence'][] = $e;
        }
    }
}

/** What writers get from the research: the freshest discoveries and the hooks. */
function pm_research_brief(array $lead): array
{
    $r = (array)($lead['research'] ?? []);
    return [
        'discoveries' => array_slice(array_map(fn($d) => $d['fact'] . (!empty($d['date']) ? ' (' . $d['date'] . ')' : ''), (array)($r['discoveries'] ?? [])), 0, 3),
        'news' => array_slice(array_map(fn($d) => $d['headline'], (array)($r['news'] ?? [])), 0, 2),
        'hooks' => array_slice((array)($r['hooks'] ?? []), 0, 3),
    ];
}

// Outbound engine (approve-and-drip sending, dedupe keys, email checks) adds its functions here when present.
if (is_file(__DIR__ . '/outbound.php')) {
    require_once __DIR__ . '/outbound.php';
}
