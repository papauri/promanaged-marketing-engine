<?php
/**
 * Building an added business up from nothing, right after it is created (or again from its welcome page): its social pages are saved, its profile pictures
 * and covers are drawn, free-offer ideas and the first posts are drafted, the one-page offer is checked and the agents look for its first leads.
 * Each step is safe to run again. Nothing is sent or published: posts wait for approval and first messages wait in Leads.
 * It runs in the background (lib/run_launch.php, or after the page is answered on a host that blocks background work); progress is in data/launch.json.
 */

/** The steps, in order: key => what it gives the owner. */
function pm_launch_steps(): array
{
    return [
        'links' => 'Social pages saved',
        'kit' => 'Profile pictures and covers drawn',
        'offers' => 'Free-offer ideas for posts that bring enquiries',
        'posts' => 'First social posts drafted, waiting for your approval',
        'onepager' => 'One-page offer to send to people who ask',
        'leads' => 'First leads found and first messages drafted (nothing is sent until you approve)',
    ];
}

/** Where the owner sees what a step made. */
function pm_launch_link(string $b, string $step): string
{
    $q = '&brand=' . rawurlencode($b);
    return ['links' => '?tab=settings&section=connections' . $q, 'kit' => '?tab=social&view=kit' . $q, 'offers' => '?tab=social&view=leadposts' . $q . '#ideas',
        'posts' => '?tab=social' . $q, 'onepager' => '?onepager=' . rawurlencode($b), 'leads' => '?tab=agents' . $q][$step] ?? '';
}

/** The launch record of a business: ['state' => ''|running|done, 'started', 'beat', 'steps' => [key => ['ok', 'msg', 'at']]]. */
function pm_launch_state(string $b, ?array $set = null): array
{
    if ($set !== null) {
        pm_update('launch', function (array $all) use ($b, $set) {
            $all[$b] = $set;
            return $all;
        }, fn() => []);
        return $set;
    }
    return (array)(pm_load('launch', fn() => [])[$b] ?? []) + ['state' => '', 'steps' => []];
}

/** The lock a build-up holds for as long as it runs, so two never run at once. */
function pm_launch_lock_file(string $b): string
{
    return PM_DATA . '/launch_' . preg_replace('/[^A-Za-z0-9_-]/', '', $b) . '.lock';
}

/**
 * Is a build-up busy right now? Yes while one holds the lock (however long a step takes), or for the first minute after it was started (the background
 * process has not taken the lock yet). Where the lock cannot be checked, a heartbeat in the last 15 minutes counts. A build-up that died stops counting.
 */
function pm_launch_busy(string $b): bool
{
    $st = pm_launch_state($b);
    $age = time() - (int)($st['beat'] ?? 0);
    $f = @fopen(pm_launch_lock_file($b), 'c');
    if (!$f) {
        return $st['state'] === 'running' && $age < 900;
    }
    $free = flock($f, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($f, LOCK_UN);
    }
    fclose($f);
    return !$free || ($st['state'] === 'running' && $age < 60);
}

/** One step. Returns [ok, what happened in words, optional link to fix it]. */
function pm_launch_step(string $b, string $step): array
{
    if ($step === 'links') {
        $prof = (array)pm_brand_setting($b, 'profile', []);
        $cfg = pm_social_settings($b);
        $n = 0;
        foreach ((array)($prof['presence'] ?? []) as $p) {
            $pk = (string)($p['platform'] ?? '');
            if ($pk !== '' && isset(pm_platforms()[$pk]) && trim((string)($cfg['urls'][$pk] ?? '')) === '') {
                $cfg['urls'][$pk] = (string)$p['url'];
                $n++;
            }
        }
        $n && pm_social_settings_save($b, $cfg);
        $other = count(array_filter((array)($prof['presence'] ?? []), fn($p) => (string)($p['platform'] ?? '') === ''));
        return $n || $other ? [true, trim(($n ? "$n social page" . ($n === 1 ? '' : 's') . ' saved.' : '') . ($other ? " $other listing" . ($other === 1 ? '' : 's') . ' or review page' . ($other === 1 ? '' : 's') . ' found.' : ''))]
            : [false, 'No social pages found yet. Add their links in Operations > Connections.'];
    }
    if ($step === 'kit') {
        $cfg = pm_social_settings($b);
        if (trim((string)($cfg['cover_line'] ?? '')) === '') { // the cover's second line: what it offers, from its own words
            $names = array_map(fn($o) => trim(explode(':', (string)$o, 2)[0]), (array)pm_agents_config($b)['offerings']);
            $line = mb_substr(implode(' · ', array_filter($names, fn($x) => $x !== '' && mb_strlen($x) <= 30)), 0, 90);
            if ($line !== '') {
                $cfg['cover_line'] = $line;
                pm_social_settings_save($b, $cfg);
            }
        }
        if (!function_exists('imagecreatetruecolor')) {
            return [false, 'This server cannot draw pictures (the PHP GD extension is off).'];
        }
        $n = count(array_filter(array_merge(...array_values(array_map('array_values', pm_kit_build($b))))));
        return [true, "$n pictures ready to download."];
    }
    if ($step === 'offers') {
        if (pm_lp_ideas($b)['rows'] || pm_lp_for_brand($b)) {
            return [true, 'Offer ideas are already there.'];
        }
        $rows = pm_lp_suggest($b);
        if (!$rows) {
            return [false, 'No ideas yet: add a free first step or more true facts in the business settings, then build again.'];
        }
        pm_update('offer_ideas', function (array $all) use ($b, $rows) {
            $all[$b] = ['at' => date('Y-m-d H:i'), 'rows' => $rows];
            return $all;
        }, fn() => []);
        return [true, count($rows) . ' offer idea' . (count($rows) === 1 ? '' : 's') . ': pick one to make its posts.'];
    }
    if ($step === 'posts') {
        $r = pm_social_replenish($b);
        $n = count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $b && in_array($p['status'] ?? '', ['draft', 'review', 'approved'], true)));
        return [$n > 0, $n > 0 ? "$n post" . ($n === 1 ? '' : 's') . ' lined up for you to approve.' : 'No posts yet (' . $r . ').'];
    }
    if ($step === 'onepager') {
        $bad = pm_onepager_status($b)['problems'];
        return $bad ? [false, 'Still needs: ' . implode('; ', array_slice($bad, 0, 3))] : [true, 'Ready to download and send.'];
    }
    if ($step === 'leads') {
        if (!pm_agents_ready()) {
            return [false, 'Add an AI key to .env first (Operations > Health and backups).'];
        }
        $on = (array)(pm_agents_config($b)['enabled'] ?? []);
        $fix = '?tab=targeting&brand=' . rawurlencode($b);
        if (!array_filter($on)) {
            return [false, 'Every agent is switched off for this business, so nothing was started. Tick the agents you want on its Targeting page.', $fix];
        }
        if (empty($on['scout'])) {
            return [false, pm_brand_profile($b)['sell_to'] === 'public'
                ? 'It sells only to the public, so the agents do not look for leads: enquiries come in from its posts and offers. To also find the organisations that book or buy in bulk, set "Who do you mainly sell to?" to Both on its Targeting page and tick the Scout agent there.'
                : 'The Scout agent is switched off for this business, so no new leads are looked for. Tick it on its Targeting page.', $fix];
        }
        $was = pm_brand();
        pm_brand_set($b);
        try {
            $st = pm_run_state();
            if (($st['state'] ?? '') === 'running') {
                return [true, 'The agents are already looking.'];
            }
            return pm_agents_launch() ? [true, 'The agents are looking now: leads appear in Leads as they are found.'] : [false, 'The agents could not start here; they run by themselves each weekday morning.'];
        } finally {
            pm_brand_set($was);
        }
    }
    return [false, 'Unknown step.'];
}

/**
 * Runs every step (or only the ones that did not work last time), saving progress after each. Holds the business's launch lock throughout:
 * when another build-up already holds it, nothing runs and the current state is returned.
 */
function pm_launch_run(string $b, bool $onlyMissing = false): array
{
    $lock = @fopen(pm_launch_lock_file($b), 'c'); // a folder that cannot be written still gets its build-up; only the guard is lost
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return pm_launch_state($b);
    }
    try {
        $st = pm_launch_state($b);
        $st = ['state' => 'running', 'started' => date('Y-m-d H:i:s'), 'beat' => time(), 'steps' => $onlyMissing ? (array)$st['steps'] : []];
        pm_launch_state($b, $st);
        $was = pm_brand();
        foreach (array_keys(pm_launch_steps()) as $k) {
            if ($onlyMissing && !empty($st['steps'][$k]['ok'])) {
                continue;
            }
            $st['current'] = $k;
            $st['beat'] = time();
            pm_launch_state($b, $st);
            pm_brand_set($b);
            try {
                $r = pm_launch_step($b, $k);
            } catch (Throwable $e) {
                $r = [false, 'Did not work: ' . mb_substr($e->getMessage(), 0, 160)];
            }
            $st['steps'][$k] = ['ok' => (bool)$r[0], 'msg' => (string)$r[1], 'at' => date('Y-m-d H:i')] + (!empty($r[2]) ? ['fix' => (string)$r[2]] : []);
            $st['beat'] = time(); // a slow step (drawing pictures, an AI call) shows life again as soon as it ends
            pm_launch_state($b, $st);
        }
        pm_brand_set($was);
        unset($st['current']);
        $st['state'] = 'done';
        $st['beat'] = time();
        pm_launch_state($b, $st);
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    if (function_exists('pm_agent_log')) {
        pm_agent_log('Launch', pm_brand_name($b) . ' built: ' . count(array_filter($st['steps'], fn($s) => $s['ok'])) . ' of ' . count(pm_launch_steps()) . ' steps done.');
    }
    return $st;
}

/** Starts the launch in the background. False when one is already busy. */
function pm_launch_start(string $b, bool $onlyMissing = false): bool
{
    if (!pm_brand_is_custom($b) || pm_launch_busy($b)) {
        return false;
    }
    $st = pm_launch_state($b);
    pm_launch_state($b, ['state' => 'running', 'started' => date('Y-m-d H:i:s'), 'beat' => time(), 'steps' => $onlyMissing ? (array)$st['steps'] : []]);
    if (PHP_SAPI === 'cli') {
        pm_launch_run($b, $onlyMissing);
        return true;
    }
    if (!pm_can_spawn()) {
        register_shutdown_function(function () use ($b, $onlyMissing) {
            pm_finish_response();
            pm_launch_run($b, $onlyMissing);
        });
        return true;
    }
    $script = __DIR__ . '/run_launch.php';
    $php = pm_php_binary();
    $args = $b . ($onlyMissing ? ' missing' : '');
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" "' . $php . '" "' . $script . '" ' . $args . ' > NUL 2>&1', 'r'));
    } else {
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($b) . ($onlyMissing ? ' missing' : '') . ' > /dev/null 2>&1 &');
    }
    return true;
}
