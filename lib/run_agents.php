<?php
/**
 * The daily swarm. Run by the "Run agents now" button, or on a schedule:
 *   php lib/run_agents.php [travel] [names] [--dry]
 * Phases: Scouts (parallel) -> Contact Finders (parallel) -> Qualifiers -> Researchers -> Writers -> Proposals -> Follow-up.
 * Only this script writes leads.json for a run, so parallel workers never collide. One run per brand at a time (data/run_<brand>.lock).
 * --dry: take the lock, then stop before any AI call (used to test the lock).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/agents.php';
require __DIR__ . '/engage.php';
require_once __DIR__ . '/swarm.php';
set_time_limit(0);

$pos = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$dry = in_array('--dry', $argv, true);
$brand = pm_brand_norm($pos[0] ?? '');
pm_brand_set($brand);
$only = (string)($pos[1] ?? ''); // "names": only look for owner and manager names on the leads already in the list

// ---- Only one run per brand: a second start exits here, before it can touch the run state or any AI ----
@mkdir(PM_DATA, 0775, true);
$lockFile = fopen(PM_DATA . '/run_' . $brand . '.lock', 'c');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another $brand run is already in progress.\n";
    exit(0);
}
$prev = pm_run_state();
if (($prev['state'] ?? '') === 'running' && time() - (int)($prev['beat'] ?? 0) < 60) {
    echo "Another $brand run is already in progress (heartbeat is fresh).\n";
    exit(0);
}
if ($dry) {
    echo "Dry run: lock acquired for $brand, nothing started.\n";
    exit(0);
}

if (!pm_agents_ready()) {
    $m = pm_agents_missing_key() . ' is missing from .env';
    pm_run_state(['state' => 'error', 'msg' => $m, 'beat' => time()]);
    pm_agent_log('Swarm', 'Run failed: ' . $m, true);
    exit(1);
}
$cfg = pm_agents_config();
if ($only === 'names') {
    foreach (array_keys($cfg['enabled']) as $k) {
        $cfg['enabled'][$k] = $k === 'contact';
    }
}
$tmp = PM_DATA . '/agent_jobs';
@mkdir($tmp, 0775, true);
$run = ['state' => 'running', 'started' => date('Y-m-d H:i:s'), 'phase' => 'starting', 'beat' => time()];
$progress = function (string $phase) use (&$run) {
    foreach (['Reading' => 1, 'Scouts' => 2, 'Contact' => 3, 'Qualifiers' => 4, 'Researchers' => 5, 'Writers' => 6, 'Proposal' => 7, 'Follow' => 8] as $k => $n) {
        if (str_starts_with($phase, $k)) {
            $run['step'] = $n;
        }
    }
    $run['steps'] = 8;
    $run['phase'] = $phase;
    $run['beat'] = time();
    pm_run_state($run);
};
$beat = function () use (&$run) { // heartbeat while workers are busy, so the dead-man check can tell "busy" from "dead"
    $run['beat'] = time();
    pm_run_state($run);
};
pm_run_state($run);

/** Stop any worker processes still running (on failure or shutdown). */
function pm_swarm_kill(): void
{
    foreach ((array)($GLOBALS['PM_SWARM'] ?? []) as $r) {
        @proc_terminate($r[0]);
    }
    $GLOBALS['PM_SWARM'] = [];
}

/** A run that cannot continue: keep the leads, record the error where the UI and the owner will see it. */
function pm_run_fail(string $msg, array $run, array $stats, ?callable $save): void
{
    pm_swarm_kill();
    if ($save) {
        try {
            $save();
        } catch (Throwable $e) {
        }
    }
    $msg = mb_substr($msg, 0, 300);
    pm_run_state(['state' => 'error', 'msg' => $msg, 'started' => $run['started'] ?? date('Y-m-d H:i:s'), 'phase' => $run['phase'] ?? '', 'stats' => $stats, 'beat' => time()]);
    pm_agent_log('Swarm', 'Run failed during "' . ($run['phase'] ?? '?') . '": ' . $msg, true);
    if (function_exists('pm_notify_owner')) {
        try {
            pm_notify_owner('Agent run failed (' . (pm_brand_name(pm_brand())) . ')', 'The daily agent run stopped during "' . ($run['phase'] ?? '?') . '": ' . $msg . "\nLeads found so far were saved.");
        } catch (Throwable $e) {
        }
    }
}

/**
 * pm_swarm, but a job whose agent returned unparseable output is tried once more as two half-size jobs ($key is the list to halve,
 * or 'want' for scouts). A retried job's halves are merged: ok if any half worked, 'error' = the first failure that is left ('' if none).
 */
function pm_swarm_retry(array $jobs, int $parallel, string $tmp, ?callable $beat, string $key): array
{
    $res = pm_swarm($jobs, $parallel, $tmp, $beat);
    $flat = [];
    $map = [];
    foreach ($res as $i => $r) {
        if ($r['ok'] || !str_contains((string)$r['error'], 'unparseable')) {
            continue;
        }
        $j = $jobs[$i];
        if ($key === 'want') {
            $parts = (int)($j['want'] ?? 0) >= 2 ? [['want' => max(1, (int)ceil($j['want'] / 2))] + $j] : [];
        } else {
            $list = array_values((array)($j[$key] ?? []));
            $h = intdiv(count($list) + 1, 2);
            $parts = count($list) >= 2 ? [[$key => array_slice($list, 0, $h)] + $j, [$key => array_slice($list, $h)] + $j] : [];
        }
        foreach ($parts as $p) {
            $map[count($flat)] = $i;
            $flat[] = $p;
        }
        if ($parts) {
            $res[$i] = ['ok' => false, 'data' => [], 'error' => ''];
        }
    }
    if (!$flat) {
        return $res;
    }
    foreach (pm_swarm($flat, $parallel, $tmp, $beat) as $k => $r) {
        $i = $map[$k];
        if ($r['ok']) {
            $res[$i]['ok'] = true;
            $res[$i]['data'] = array_merge((array)$res[$i]['data'], (array)$r['data']);
        } elseif ($res[$i]['error'] === '') {
            $res[$i]['error'] = (string)$r['error'];
        }
    }
    foreach ($map as $i) {
        if (!$res[$i]['ok'] && $res[$i]['error'] === '') {
            $res[$i]['error'] = 'unparseable output';
        }
    }
    return $res;
}

$all = [];
$leads = [];
$loaded = false; // nothing is ever written back before the leads were read successfully
$isMine = fn($l) => is_array($l) && ($l['brand'] ?? 'promanaged') === $brand;
$save = function () use (&$all, &$leads, &$loaded) { // only this brand's leads are changed; the rest are written back untouched
    if (!$loaded) {
        return;
    }
    foreach ($leads as $k => $v) {
        $all[$k] = $v;
    }
    pm_leads_save($all);
};
$stats = ['scouted' => 0, 'added' => 0, 'contacts' => 0, 'qualified' => 0, 'drafted' => 0, 'followups' => 0, 'replies' => 0, 'errors' => 0];
$finished = false;
register_shutdown_function(function () use (&$finished, &$run, &$stats, &$save) { // a fatal error or kill must not leave "running" behind
    if (!$finished) {
        $e = error_get_last();
        pm_run_fail('stopped unexpectedly' . ($e ? ': ' . $e['message'] : ''), $run, $stats, $save);
    }
});
/** Log a failed or partly failed job result. Returns true when its data can still be used. */
$jobFailed = function (array $r, string $agent, string $what) use (&$stats): bool {
    if (!$r['ok']) {
        $stats['errors']++;
        pm_agent_log($agent, ($what !== '' ? "$what " : '') . 'failed: ' . $r['error'], true);
        return false;
    }
    if (($r['error'] ?? '') !== '') { // half of a retried job failed
        $stats['errors']++;
        pm_agent_log($agent, ($what !== '' ? "$what " : '') . 'partly failed: ' . $r['error'], true);
    }
    return true;
};

try {
    $all = pm_leads();
    $leads = array_filter($all, $isMine);
    $loaded = true;

    // ---- Director: today's strategy (one small call) ----
    if ($only === '') try {
        $brief = pm_director_brief();
        pm_agent_log('Director', !empty($brief['error']) ? 'Could not run: ' . $brief['error'] : $brief['headline'], !empty($brief['error']));
    } catch (Throwable $e) {
        pm_agent_log('Director', 'Could not run: ' . $e->getMessage(), true);
    }

    // ---- 0. Replies from businesses we have already written to ----
    if ($only === '' && pm_imap_ready()) {
        $progress('Reading replies');
        try {
            [$stats['replies']] = pm_inbox_poll('pm_send_reply');
            pm_brand_set($brand);
            $all = pm_leads();
            $leads = array_filter($all, $isMine);
        } catch (Throwable $e) {
            pm_brand_set($brand); // the inbox switches business per reply: never carry on as another business
            $stats['errors']++;
            pm_agent_log('Reply', 'Inbox check failed: ' . $e->getMessage(), true);
        }
    }

    // Backpressure: unsent drafts pile up when the owner has not had time to send. Finding more leads then only wastes tokens.
    $bs = pm_backlog_state($leads, $cfg, $brand);
    $stats['backlog'] = $bs['backlog'];
    $pausedLogged = false;
    $pauseLog = function () use (&$pausedLogged, $bs) {
        if (!$pausedLogged) {
            $pausedLogged = true;
            pm_agent_log('Swarm', 'Paused: ' . $bs['backlog'] . ' drafts waiting for you');
        }
    };
    $today = date('Y-m-d');

    // ---- 1. Scouts ----
    if ($cfg['enabled']['scout']) {
        if ($bs['pause']) {
            $pauseLog();
            $stats['paused'] = true;
        } elseif ($bs['room'] <= 0) {
            pm_agent_log('Scout', "Skipped: {$bs['added_today']} leads already added today (limit {$cfg['new_per_day']})");
        } else {
            $targets = array_slice(pm_scout_targets($cfg, function_exists('pm_director_focus_weights') ? pm_director_focus_weights() : null), 0, max(1, min($bs['room'], (int)$cfg['scouts_per_day'])));
            $want = max(2, (int)ceil($bs['room'] * 1.5 / max(1, count($targets))));
            $progress('Scouts searching ' . count($targets) . ' areas');
            $jobs = [];
            foreach ($targets as [$sector, $city]) {
                $jobs[] = ['agent' => 'scout', 'sector' => $sector, 'city' => $city, 'want' => $want, 'known' => pm_known_names($leads, $sector, $city)];
            }
            foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'want') as $i => $r) {
                [$sector, $city] = $targets[$i];
                if (!$jobFailed($r, 'Scout', "$sector in $city")) {
                    continue;
                }
                foreach ($r['data'] as $b) {
                    $stats['scouted']++;
                    $c = trim((string)($b['city'] ?? $city)) ?: $city;
                    $id = pm_lead_id((string)$b['name'], $c, $brand);
                    $alsoIn = '';
                    $dupe = isset($leads[$id]) ? $id : null; // the old name-only check always applies
                    if ($dupe === null && function_exists('pm_lead_find_dupe')) { // same website, phone or email (outbound engine)
                        try {
                            $pool = array_replace(function_exists('pm_leads_archive') ? pm_leads_archive() : [], $all, $leads); // an archived business is not "new" again
                            $cand = ['name' => trim((string)$b['name']), 'city' => $c, 'brand' => $brand, 'type' => $sector,
                                'website' => (string)($b['website'] ?? ''), 'phone' => (string)($b['phone'] ?? ''), 'email' => (string)($b['email'] ?? '')];
                            $dupe = pm_lead_find_dupe($pool, $cand, $brand);
                            foreach (array_diff(pm_brand_ids(), [$brand]) as $ob) { // another business has it: still a lead here, flagged both ways
                                if ($dupe === null && ($o = pm_lead_find_dupe($pool, $cand, $ob)) !== null) {
                                    $alsoIn = $ob;
                                    if (isset($all[$o])) { // an archived lead is not in the file: never create a stub for it
                                        $all[$o]['also_in'] = $brand;
                                    }
                                    break;
                                }
                            }
                        } catch (Throwable $e) {
                            $dupe = null;
                        }
                    }
                    if ($dupe !== null) {
                        if (isset($leads[$dupe]) && pm_lead_merge_missing($leads[$dupe], $b)) { // same business again: fill gaps, do not add twice
                            pm_lead_note($leads[$dupe], 'Scout found it again: details merged');
                            $stats['merged'] = ($stats['merged'] ?? 0) + 1;
                        }
                        continue;
                    }
                    if ($stats['added'] >= $bs['room']) {
                        continue;
                    }
                    if ($brand !== 'travel' && array_filter((array)($cfg['existing_clients'] ?? []), fn($x) => $x !== '' && stripos((string)$b['name'], (string)$x) !== false)) {
                        continue; // already our client
                    }
                    $leads[$id] = [
                        'id' => $id, 'brand' => $brand, 'name' => trim((string)$b['name']), 'type' => $sector, 'city' => $c, 'country' => pm_country_of($c) ?: pm_city_country($city, $brand),
                        'address' => (string)($b['address'] ?? ''), 'website' => (string)($b['website'] ?? ''),
                        'phone' => (string)($b['phone'] ?? ''), 'email' => (string)($b['email'] ?? ''), 'whatsapp' => '',
                        'contact' => '', 'contact_title' => '', 'evidence' => array_values((array)($b['evidence'] ?? [])),
                        'facebook' => (string)($b['facebook'] ?? ''), 'instagram' => (string)($b['instagram'] ?? ''), 'social_gaps' => array_values(array_filter(array_map('strval', (array)($b['social_gaps'] ?? [])))),
                        'need_signals' => array_values((array)($b['need_signals'] ?? [])), 'offering' => pm_clean_offering($b['offering'] ?? ''), 'score' => 0, 'status' => 'new',
                        'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i'),
                        'src' => ['kind' => 'scout', 'sector' => $sector, 'city' => $city, 'run_date' => $today],
                    ] + ($alsoIn !== '' ? ['also_in' => $alsoIn] : []);
                    pm_apply_contact($leads[$id], $b); // a name found by the scout, used only if its source is the business's own page
                    $stats['added']++;
                }
                pm_agent_log('Scout', "$sector in $city: " . count($r['data']) . ' found');
            }
            $save();
        }
    }

    // ---- 1b. Web-change watcher: fresh signals a normal sweep may have missed (one call per brand per day) ----
    if ($cfg['enabled']['scout'] && !$bs['pause'] && $stats['added'] < $bs['room']) {
        $ws = pm_load('watch_state', fn() => []);
        if (($ws[$brand] ?? '') !== date('Y-m-d')) {
            $progress('Watcher looking for fresh signals');
            try {
                $known = array_slice(array_map(fn($l) => (string)$l['name'], $leads), -200);
                foreach (array_slice(pm_agent_watch($brand, $known), 0, 4) as $b) {
                    if ($stats['added'] >= $bs['room']) {
                        break;
                    }
                    $c = trim((string)($b['city'] ?? '')) ?: (string)($cfg['cities'][0] ?? '');
                    $pool = array_replace(function_exists('pm_leads_archive') ? pm_leads_archive() : [], $all, $leads);
                    $cand = ['name' => trim((string)$b['name']), 'brand' => $brand, 'type' => (string)($b['type'] ?? ''),
                        'website' => (string)($b['website'] ?? ''), 'phone' => (string)($b['phone'] ?? ''), 'email' => (string)($b['email'] ?? '')];
                    $dupe = pm_lead_find_dupe($pool, $cand, $brand);
                    if ($dupe !== null) {
                        if (isset($leads[$dupe]) && pm_lead_merge_missing($leads[$dupe], $b)) {
                            $stats['merged'] = ($stats['merged'] ?? 0) + 1;
                        }
                        continue;
                    }
                    if ($brand !== 'travel' && array_filter((array)($cfg['existing_clients'] ?? []), fn($x) => $x !== '' && stripos((string)$b['name'], (string)$x) !== false)) {
                        continue;
                    }
                    $id = pm_lead_id(trim((string)$b['name']), $c, $brand);
                    if (isset($leads[$id]) || isset($pool[$id])) { // known by name and town: fill gaps, never replace it (its history, sends and any STOP stay)
                        if (isset($leads[$id]) && pm_lead_merge_missing($leads[$id], $b)) {
                            $stats['merged'] = ($stats['merged'] ?? 0) + 1;
                        }
                        continue;
                    }
                    $leads[$id] = [
                        'id' => $id, 'brand' => $brand, 'name' => trim((string)$b['name']), 'type' => (string)($b['type'] ?? ''), 'city' => $c,
                        'address' => '', 'website' => (string)($b['website'] ?? ''), 'phone' => (string)($b['phone'] ?? ''), 'email' => (string)($b['email'] ?? ''), 'whatsapp' => '',
                        'contact' => '', 'contact_title' => '', 'evidence' => array_values((array)($b['evidence'] ?? [])),
                        'facebook' => (string)($b['facebook'] ?? ''), 'instagram' => '', 'social_gaps' => array_values(array_filter(array_map('strval', (array)($b['social_gaps'] ?? [])))),
                        'need_signals' => array_values((array)($b['need_signals'] ?? [])), 'offering' => pm_clean_offering($b['offering'] ?? ''), 'score' => 0, 'status' => 'new',
                        'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i'),
                        'src' => ['kind' => 'watch'],
                    ];
                    $stats['added']++;
                    $stats['watched'] = ($stats['watched'] ?? 0) + 1;
                }
                $save();
            } catch (Throwable $e) {
                pm_agent_log('Watcher', 'failed: ' . $e->getMessage(), true);
            }
            pm_update('watch_state', fn(array $w) => array_replace($w, [$brand => date('Y-m-d')]), fn() => []); // under the lock: another business's run may be saving too
        }
    }

    // ---- 1c. Directory scout: public member lists and directories, one town, once a week ----
    if ($cfg['enabled']['scout'] && !$bs['pause'] && $stats['added'] < $bs['room'] && function_exists('pm_directory_due') && pm_directory_due($brand)) {
        $progress('Directory scout reading member lists');
        try {
            $cities = array_values($cfg['cities']);
            $dcity = $cities ? $cities[(int)date('W') % count($cities)] : 'Lilongwe';
            $known = array_slice(array_map(fn($l) => (string)$l['name'], $leads), -200);
            $rows = pm_directory_clean(pm_agent_directory($brand, $dcity, (array)$cfg['sectors'], $known));
            $pool = array_replace(function_exists('pm_leads_archive') ? pm_leads_archive() : [], $all, $leads);
            foreach (pm_directory_leads($rows, $pool, $brand, $cfg, $dcity, $bs['room'] - $stats['added']) as $nid => $nl) {
                $leads[$nid] = $nl;
                $stats['added']++;
                $stats['directory'] = ($stats['directory'] ?? 0) + 1;
            }
            pm_agent_log('Directory', "$dcity: " . count($rows) . ' listed, ' . ($stats['directory'] ?? 0) . ' new');
            $save();
            pm_directory_mark($brand);
        } catch (Throwable $e) {
            pm_agent_log('Directory', 'failed: ' . $e->getMessage(), true);
        }
    }

    // ---- 2. Contact finders (new leads) ----
    // A search is only paid for when the lead still lacks an email or a named owner/manager (a name makes the message far more likely to be read). Capped for cost.
    // "names" mode skips leads already searched in the last 30 days.
    $newIds = $only === 'names'
        ? array_slice(array_keys(array_filter($leads, fn($l) => !in_array($l['status'], ['won', 'lost', 'optout'], true) && empty($l['contact']) && empty($l['contact_hint'])
            && (($c = strtotime((string)($l['contact_checked'] ?? ''))) === false || $c < strtotime('-30 days')))), 0, 12)
        : array_slice(array_keys(array_filter($leads, fn($l) => $l['status'] === 'new' && (empty($l['email']) || empty($l['contact']))
            && (($c = strtotime((string)($l['contact_checked'] ?? ''))) === false || $c < strtotime('-30 days')))), 0, 10); // a lead searched lately is not paid for again
    if ($cfg['enabled']['contact'] && $newIds) {
        $progress('Contact Finders on ' . count($newIds) . ' leads');
        $jobs = array_map(fn($id) => ['agent' => 'contact', 'lead' => $leads[$id]], $newIds);
        foreach (pm_swarm($jobs, $cfg['parallel'], $tmp, $beat) as $i => $r) {
            $id = $newIds[$i];
            $leads[$id]['contact_checked'] = $today; // looked once: not searched again for 30 days in "names" mode
            if (!$r['ok']) {
                $stats['errors']++;
                pm_agent_log('Contact Finder', $leads[$id]['name'] . ' failed: ' . $r['error'], true);
                continue;
            }
            $d = $r['data'];
            foreach (['email', 'phone', 'whatsapp', 'website'] as $f) {
                if (!empty($d[$f]) && empty($leads[$id][$f])) {
                    $leads[$id][$f] = trim((string)$d[$f]);
                }
            }
            pm_apply_contact($leads[$id], $d);
            if ($leads[$id]['email'] !== '' && !filter_var($leads[$id]['email'], FILTER_VALIDATE_EMAIL)) {
                $leads[$id]['email'] = '';
            }
            $stats['contacts'] += (!empty($leads[$id]['email']) || !empty($leads[$id]['phone'])) ? 1 : 0;
        }
        $save();
    }
    // Check the emails of new leads (outbound engine, when present): a bad address is dropped, the lead stays for WhatsApp.
    if ($only === '' && function_exists('pm_verify_email')) {
        $n = 0;
        foreach ($leads as $id => $l) {
            if ($n >= 15 || ($l['status'] ?? '') !== 'new' || empty($l['email']) || !empty($l['email_src'])) {
                continue;
            }
            $n++;
            $beat();
            try {
                $v = pm_verify_email((string)$l['email'], (string)($l['website'] ?? ''));
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($v)) {
                continue;
            }
            $leads[$id]['email_src'] = (string)($v['src'] ?? 'unverified');
            if (($v['ok'] ?? true) === false) {
                $leads[$id]['email_rejected'] = $l['email'];
                $leads[$id]['email'] = '';
                pm_lead_note($leads[$id], 'Email ' . $l['email'] . ' rejected: ' . (string)($v['why'] ?? 'failed the check'));
                $stats['rejected'] = ($stats['rejected'] ?? 0) + 1;
            }
        }
        $save();
    }

    // ---- 2b. Contact re-verification: old leads whose published details may have changed ----
    if ($cfg['enabled']['contact']) {
        $stale = pm_reverify_due($leads, 3);
        if ($stale) {
            $progress('Re-checking ' . count($stale) . ' old contact details');
            $jobs = array_map(fn($b) => ['agent' => 'reverify', 'leads' => $b], array_chunk($stale, 3));
            foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads') as $r) {
                if (!$jobFailed($r, 'Contact re-check', '') || empty($r['data'])) {
                    continue;
                }
                foreach ($r['data'] as $d) {
                    $id = (string)($d['id'] ?? '');
                    if (!isset($leads[$id])) {
                        continue;
                    }
                    $leads[$id]['contact_checked_at'] = date('Y-m-d H:i');
                    $before = [(string)($leads[$id]['email'] ?? ''), (string)($leads[$id]['phone'] ?? ''), (string)($leads[$id]['contact'] ?? '')];
                    $newEmail = strtolower(trim((string)($d['email'] ?? '')));
                    if ($newEmail !== '' && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                        $cur = strtolower((string)($leads[$id]['email'] ?? ''));
                        if ($cur === '' || (!empty($leads[$id]['email_bad']) && $cur !== $newEmail)) {
                            $leads[$id]['email'] = $newEmail;
                            unset($leads[$id]['email_bad']);
                            pm_lead_note($leads[$id], 'Contact re-verified: new address ' . $newEmail);
                            $stats['reverified'] = ($stats['reverified'] ?? 0) + 1;
                        }
                    }
                    pm_apply_contact($leads[$id], $d);
                    if (function_exists('pm_reverify_result')) { // C2-A01: the owner sees what the re-check found (or that nothing changed)
                        pm_reverify_result($leads[$id], $before);
                    }
                }
            }
            $save();
        }
    }

    // ---- 3. Qualifiers ----
    $todo = array_values(array_filter($leads, fn($l) => $l['status'] === 'new'));
    if ($cfg['enabled']['qualifier'] && $todo) {
        $progress('Qualifiers scoring ' . count($todo) . ' leads');
        $jobs = array_map(fn($b) => ['agent' => 'qualify', 'leads' => $b], array_chunk($todo, 15));
        foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads') as $r) {
            if (!$jobFailed($r, 'Qualifier', '')) {
                continue;
            }
            foreach ($r['data'] as $q) {
                $id = (string)($q['id'] ?? '');
                if (!isset($leads[$id])) {
                    continue;
                }
                $leads[$id]['score'] = max(0, min(100, (int)($q['score'] ?? 0)));
                if ($brand === 'travel') { // packages and proposals belong to the two businesses that have a price list
                    $leads[$id]['package'] = pm_onboarding_index();
                } elseif (!pm_brand_is_custom($brand)) {
                    $leads[$id]['package'] = max(0, min(count((array)(pm_template()['packages'] ?? [])) - 1, (int)($q['package'] ?? 0))); // a number outside the price list opens nothing
                }
                $leads[$id]['pain'] = (string)($q['pain'] ?? '');
                $leads[$id]['offering'] = pm_clean_offering($q['offering'] ?? '') ?: ($leads[$id]['offering'] ?? '');
                $leads[$id]['reason'] = (string)($q['reason'] ?? '');
                $leads[$id]['status'] = !empty($q['skip']) ? 'lost' : 'qualified';
                if (!empty($q['skip'])) {
                    pm_lead_note($leads[$id], 'Qualifier skipped: ' . ($q['reason'] ?? 'not a fit'));
                } else {
                    $stats['qualified']++;
                }
            }
        }
        $save();
    }

    // With a pile of unsent drafts, new cold leads wait: no research, drafting or proposals for them until the owner clears some.
    $hold = $bs['hold_new'];

    // ---- 3b. Researchers: website, socials and news for the best leads (web search, so capped) ----
    $rs = array_values(array_filter($leads, fn($l) => in_array($l['status'], ['qualified', 'new'], true) && ($l['score'] ?? 0) >= 65 && empty($l['research'])));
    usort($rs, fn($a, $b) => $b['score'] <=> $a['score']);
    $rsRoom = max(0, (int)($cfg['research_per_day'] ?? 5) - count(array_filter($leads, fn($l) => str_starts_with((string)($l['research']['at'] ?? ''), $today)))); // a day's allowance, not a run's
    $rs = array_slice($rs, 0, $rsRoom);
    if (function_exists('pm_research_queue') && $rsRoom > count($rs)) { // follow-up-bound and proposal-bound leads get a fresh research pass too, inside the same allowance
        $extra = array_slice(array_filter(pm_research_queue($leads, 2), fn($l) => !in_array((string)($l['id'] ?? ''), array_column($rs, 'id'), true)), 0, $rsRoom - count($rs));
        $rs = array_merge($rs, array_values($extra));
    }
    if (($cfg['enabled']['research'] ?? true) && $rs && !$hold) {
        $progress('Researchers studying ' . count($rs) . ' businesses');
        $jobs = array_map(fn($l) => ['agent' => 'research', 'lead' => $l], $rs);
        foreach (pm_swarm($jobs, $cfg['parallel'], $tmp, $beat) as $i => $r) {
            $id = $rs[$i]['id'];
            if (!$r['ok']) {
                $stats['errors']++;
                pm_agent_log('Researcher', $leads[$id]['name'] . ' failed: ' . $r['error'], true);
                continue;
            }
            pm_apply_research($leads[$id], $r['data']);
            $stats['researched'] = ($stats['researched'] ?? 0) + 1;
        }
        $save();
    }

    // ---- 3c. Re-qualify: a researched lead gets a fresh score and package from what was found (capped per run), before the Writers read the score ----
    $rq = $cfg['enabled']['qualifier'] && !$hold && function_exists('pm_requalify_due') ? pm_requalify_due($leads, 3) : [];
    if ($rq) {
        $progress('Researchers re-scoring ' . count($rq) . ' leads');
        $jobs = array_map(fn($b) => ['agent' => 'qualify', 'leads' => $b], array_chunk($rq, 3));
        foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads') as $r) {
            if (!$jobFailed($r, 'Qualifier', 're-score')) {
                continue;
            }
            foreach ($r['data'] as $q) {
                $id = (string)($q['id'] ?? '');
                if (isset($leads[$id]) && !empty($leads[$id]['research'])) {
                    pm_apply_requalify($leads[$id], $q, $brand);
                    $stats['requalified'] = ($stats['requalified'] ?? 0) + 1;
                }
            }
        }
        foreach ($rq as $l0) { // a failed or empty answer is retried once more, then left until new research lands
            $i0 = (string)$l0['id'];
            if (isset($leads[$i0]) && strcmp((string)($leads[$i0]['requalified_at'] ?? ''), (string)($leads[$i0]['research']['at'] ?? '')) < 0) {
                $leads[$i0]['requalify_tries'] = (int)($leads[$i0]['requalify_tries'] ?? 0) + 1;
            }
        }
        $save();
    }

    // ---- 3d. Engagement: a proposal opened twice, or a reply, raises the score (rule-based, capped, no AI) ----
    if (function_exists('pm_engagement_due')) {
        foreach (pm_engagement_due($leads, 20) as $l0) {
            $i0 = (string)$l0['id'];
            if (isset($leads[$i0]) && pm_apply_engagement($leads[$i0]) !== '') {
                $stats['engaged'] = ($stats['engaged'] ?? 0) + 1;
            }
        }
        $save();
    }

    // ---- 4. Writers ----
    $todo = array_values(array_filter($leads, fn($l) => $l['status'] === 'qualified' && ($l['score'] ?? 0) >= 60
        && (!empty($l['email']) || !empty($l['phone'])) && empty($l['drafts']) && !pm_lead_is_client($l))); // a client is messaged personally, never with a cold draft
    usort($todo, fn($a, $b) => $b['score'] <=> $a['score']);
    $todo = array_slice($todo, 0, 30);
    if ($hold && $todo && $cfg['enabled']['writer']) {
        $pauseLog();
        $stats['paused'] = true;
    } elseif ($cfg['enabled']['writer'] && $todo) {
        $progress('Writers drafting ' . count($todo) . ' messages');
        $jobs = array_map(fn($b) => ['agent' => 'write', 'leads' => $b], array_chunk($todo, 6));
        $byAi = !(function_exists('pm_dm_use_default') && pm_dm_use_default($brand)); // the default message has one subject for all: not part of the subject experiment
        $results = pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads');
        $fresh = pm_leads_read(); // what the owner did while the writers worked (a draft applied by hand, a reply) is not written over
        foreach ($results as $r) {
            if (!$jobFailed($r, 'Writer', '')) {
                continue;
            }
            foreach ($r['data'] as $w) {
                $id = (string)($w['id'] ?? '');
                if (isset($fresh[$id]) && (!empty($fresh[$id]['drafts']) || !in_array($fresh[$id]['status'] ?? '', ['new', 'qualified'], true))) {
                    continue;
                }
                if (isset($leads[$id]) && !empty($w['email_body'])) {
                    $L = $leads[$id];
                    $leads[$id]['drafts'] = ['email_subject' => (string)$w['email_subject'], 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? '')];
                    if ($byAi) {
                        $leads[$id]['email_arm'] = function_exists('pm_email_exp_arm') ? pm_email_exp_arm((string)$id, $brand) : 'A'; // subject experiment arm
                    }
                    $leads[$id]['variant'] = ['angle' => mb_substr((string)($L['pain'] ?? ''), 0, 60), 'research' => !empty($L['research']), 'named' => !empty($L['contact']),
                        'channel_first' => !empty($L['email']) ? 'email' : 'whatsapp']; // what was tried, so the Director can learn what gets replies
                    $leads[$id]['status'] = 'drafted';
                    $stats['drafted']++;
                }
            }
        }
        $save();
    }

    // ---- 4b. Tailored proposals ----
    // First the leads that replied and asked for one (any score), then the best cold leads. Both share proposals_per_day (counted from the leads, so a second run today does not double it).
    $slots = max(0, (int)$cfg['proposals_per_day'] - count(array_filter($leads, fn($l) => str_starts_with((string)($l['proposal_ai']['at'] ?? ''), $today))));
    $hand = array_values(array_filter($leads, fn($l) => $l['status'] === 'replied' && !empty($l['want_proposal']) && empty($l['proposal_ai'])));
    usort($hand, fn($a, $b) => strcmp((string)($b['last_reply'] ?? ''), (string)($a['last_reply'] ?? '')));
    $cold = $hold ? [] : array_values(array_filter($leads, fn($l) => in_array($l['status'], ['drafted', 'qualified'], true) && ($l['score'] ?? 0) >= 75 && empty($l['proposal_ai'])));
    usort($cold, fn($a, $b) => $b['score'] <=> $a['score']);
    $prep = array_slice(array_merge($hand, $cold), 0, $slots);
    if (($cfg['enabled']['proposals'] ?? true) && !empty(pm_brand_profile($brand)['proposals']) && $prep) {
        $progress('Proposal agents tailoring ' . count($prep) . ' proposals');
        $tpl = pm_template();
        $jobs = array_map(function ($l) use ($tpl, $brand) {
            if (!isset($l['package']) && $brand === 'travel') {
                $l['package'] = pm_onboarding_index();
            }
            $pkg = $tpl['packages'][(int)($l['package'] ?? 0)] ?? [];
            return ['agent' => 'polish', 'p' => pm_lead_to_draft($l), 'line' => pm_apply_line($tpl, (string)($pkg['type'] ?? '')), 'package' => $pkg, 'lead' => $l];
        }, $prep);
        foreach (pm_swarm($jobs, $cfg['parallel'], $tmp, $beat) as $i => $r) {
            $id = $prep[$i]['id'];
            if (!$r['ok'] || empty($r['data']['pain_points'])) {
                $stats['errors']++;
                pm_agent_log('Proposal', $leads[$id]['name'] . ' failed: ' . ($r['error'] ?: 'unusable result'), true);
                continue;
            }
            $d = $r['data'];
            $leads[$id]['proposal_ai'] = [
                'ai' => ['cover_hook' => (string)($d['cover_hook'] ?? ''), 'intro' => (string)($d['intro'] ?? ''), 'what_we_know' => (array)($d['what_we_know'] ?? []), 'pain_points' => (array)$d['pain_points'],
                    'gains' => array_values(array_filter(array_map('strval', (array)($d['gains'] ?? []))))], // the tailored gains were paid for: keep them, as the on-demand path does
                'personal_note' => (string)($d['personal_note'] ?? ''), 'subject' => (string)($d['email_subject'] ?? ''), 'body' => (string)($d['email_body'] ?? ''), 'at' => date('Y-m-d H:i'),
            ];
            $stats['proposals'] = ($stats['proposals'] ?? 0) + 1;
        }
        $save();
    }

    // ---- 5. Follow-up ----
    // Not for leads that are snoozed, replied, in proposal, won, lost or opted out, nor those whose only channel is a bad email.
    $due = array_values(pm_followup_due($leads, $cfg, false)); // the same rule the Leads screen and the scheduler use (lib/followups.php)
    if ($cfg['enabled']['followup'] && $due) {
        $progress('Follow-up agent on ' . count($due) . ' leads');
        $jobs = array_map(fn($b) => ['agent' => 'followup', 'leads' => $b], array_chunk($due, 6));
        $results = pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads');
        $fresh = pm_leads_read(); // a STOP, a reply or a follow-up sent while the agent wrote: no draft for that lead
        foreach ($results as $r) {
            if (!$jobFailed($r, 'Follow-up', '')) {
                continue;
            }
            foreach ($r['data'] as $w) {
                $id = (string)($w['id'] ?? '');
                if (isset($fresh[$id]) && (($fresh[$id]['status'] ?? '') !== 'contacted' || !empty($fresh[$id]['followup_draft']) || !empty($fresh[$id]['awaiting_reply_since']))) {
                    continue;
                }
                if (isset($leads[$id]) && !empty($w['email_body'])) {
                    $leads[$id]['followup_draft'] = ['email_subject' => (string)$w['email_subject'], 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? '')];
                    if (function_exists('pm_followup_arm')) {
                        $leads[$id]['followup_arm'] = pm_followup_arm($leads[$id], $brand); // C2-A03: which subject style this follow-up used
                    }
                    $stats['followups']++;
                }
            }
        }
        $save();
    }

    // ---- 6. Win-back: one fresh angle for lost leads, on a long cooldown ----
    $wbDue = $only === '' && ($cfg['enabled']['followup'] ?? true) && function_exists('pm_winback_due') ? pm_winback_due($leads, 2) : [];
    if ($wbDue) {
        $progress('Win-back agent on ' . count($wbDue) . ' lost leads');
        $jobs = array_map(fn($b) => ['agent' => 'winback', 'leads' => $b], array_chunk($wbDue, 2));
        foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads') as $r) {
            if (!$jobFailed($r, 'Win-back', '')) {
                continue;
            }
            foreach ($r['data'] as $w) {
                $id = (string)($w['id'] ?? '');
                if (isset($leads[$id]) && !empty($w['email_body'])) {
                    $leads[$id]['winback_draft'] = ['email_subject' => (string)$w['email_subject'], 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? '')];
                    $leads[$id]['winback_at'] = date('Y-m-d');
                    pm_lead_note($leads[$id], 'Win-back draft ready: review and send if the time is right');
                    $stats['winbacks'] = ($stats['winbacks'] ?? 0) + 1;
                }
            }
        }
        $save();
    }

    // ---- 7. Post-sign: testimonial, review and referral asks after a signed deal ----
    $psDue = $only === '' && function_exists('pm_postsign_due') ? pm_postsign_due($leads, 3) : [];
    if ($psDue) {
        $progress('Post-sign agent on ' . count($psDue) . ' new clients');
        $jobs = array_map(fn($b) => ['agent' => 'postsign', 'leads' => $b], array_chunk($psDue, 3));
        foreach (pm_swarm_retry($jobs, $cfg['parallel'], $tmp, $beat, 'leads') as $r) {
            if (!$jobFailed($r, 'Post-sign', '')) {
                continue;
            }
            foreach ($r['data'] as $w) {
                $id = (string)($w['id'] ?? '');
                if (isset($leads[$id]) && !empty($w['testimonial'])) {
                    $leads[$id]['postsign'] = function_exists('pm_postsign_finish') ? pm_postsign_finish($w, $brand)
                        : ['testimonial' => (string)$w['testimonial'], 'review' => (string)($w['review'] ?? ''), 'referral' => (string)($w['referral'] ?? '')]; // C2-A08: the review ask carries the Google review link
                    $leads[$id]['postsign_at'] = date('Y-m-d');
                    pm_lead_note($leads[$id], 'Post-sign asks ready: testimonial, Google review and referral');
                    $stats['postsigns'] = ($stats['postsigns'] ?? 0) + 1;
                }
            }
        }
        $save();
    }

    $named = count(array_filter($leads, fn($l) => !empty($l['contact'])));
    $finished = true;
    if ($only === 'names') {
        pm_agent_log('Contact Finder', "Name search finished: $named leads now have an owner or manager name");
        pm_run_state(['state' => 'done', 'mode' => 'names', 'started' => $run['started'], 'finished' => date('Y-m-d H:i:s'), 'stats' => $stats + ['named' => $named], 'beat' => time()]);
        exit;
    }
    pm_agent_log('Swarm', sprintf('Run finished: %d new leads, %d qualified, %d drafted, %d follow-ups, %d errors', $stats['added'], $stats['qualified'], $stats['drafted'], $stats['followups'], $stats['errors']), $stats['errors'] > 0);
    if ($only === '') {
        try { // today's engagement playbook, unless the morning scheduler already wrote it (one a day)
            pm_playbook_ensure($brand);
        } catch (Throwable) {
        }
    }
    pm_run_state(['state' => 'done', 'started' => $run['started'], 'finished' => date('Y-m-d H:i:s'), 'stats' => $stats, 'beat' => time()]);
} catch (Throwable $e) {
    $finished = true; // handled here, not by the shutdown function
    pm_run_fail($e->getMessage(), $run, $stats, $save);
    exit(1);
}
