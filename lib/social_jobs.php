<?php
/**
 * Runs every module job. pm_job_<name>(string $brand): string runs once per brand; pm_jobg_<name>(): string once per run.
 * Each is wrapped, so one broken job never stops the others. A heartbeat (data/cron_heartbeat.json) tells the app the scheduler is alive.
 * $budget (seconds, 0 = none): stop starting new jobs after that long (cron.php uses 25).
 */
function pm_social_heartbeat(string $by = ''): void
{
    try {
        pm_save('cron_heartbeat', ['at' => time(), 'by' => $by !== '' ? $by : (PHP_SAPI === 'cli' ? 'cli' : 'web')]);
    } catch (Throwable) {
    }
}

/** Seconds since the scheduler last ran, or null when it never did. */
function pm_social_heartbeat_age(): ?int
{
    $f = PM_DATA . '/cron_heartbeat.json';
    if (!is_file($f)) {
        return null;
    }
    $h = json_decode((string)file_get_contents($f), true);
    return is_array($h) && !empty($h['at']) ? max(0, time() - (int)$h['at']) : null;
}

function pm_social_run_jobs(int $budget = 0, string $by = ''): string
{
    $t0 = time();
    $out = [];
    $was = pm_brand();
    $over = fn() => $budget > 0 && time() - $t0 >= $budget;
    foreach (['promanaged', 'travel'] as $b) {
        pm_brand_set($b);
        foreach (pm_social_registered('pm_job_') as $fn) {
            if ($over()) {
                $out[] = 'time budget used: remaining jobs wait for the next run';
                break 2;
            }
            try {
                $r = trim((string)$fn($b));
                if ($r !== '') {
                    $out[] = "$b " . substr($fn, 7) . ': ' . $r;
                }
            } catch (Throwable $e) {
                $out[] = "$b " . substr($fn, 7) . ' failed: ' . $e->getMessage();
            }
        }
    }
    foreach (pm_social_registered('pm_jobg_') as $fn) {
        if ($over()) {
            break;
        }
        try {
            $r = trim((string)$fn());
            if ($r !== '') {
                $out[] = substr($fn, 8) . ': ' . $r;
            }
        } catch (Throwable $e) {
            $out[] = substr($fn, 8) . ' failed: ' . $e->getMessage();
        }
    }
    pm_brand_set($was);
    pm_social_heartbeat($by);
    return implode("\n", $out);
}

/** Does ?key= match CRON_KEY in .env? (Empty key in .env = cron.php is switched off.) */
function pm_cron_authorized(string $given): bool
{
    $key = (string)(pm_env()['CRON_KEY'] ?? '');
    return $key !== '' && $given !== '' && hash_equals($key, $given);
}

/**
 * One scheduler pass, shared by lib/social_run.php (command line) and cron.php (web cron): publish what is due, keep the calendar topped up,
 * remind about waiting drafts, run the Page autopilot, then every module job. $budget: seconds after which no new step is started (0 = none).
 */
function pm_social_cycle(int $budget = 0, string $by = ''): string
{
    $t0 = time();
    $over = fn() => $budget > 0 && time() - $t0 >= $budget;
    $out = [];
    try {
        $out[] = pm_social_due(true) . ' post(s) published';
    } catch (Throwable $e) {
        $out[] = 'publish: ' . $e->getMessage();
    }
    foreach (['promanaged', 'travel'] as $b) { // independent of the Facebook token: drafts can be made before a Page is connected
        if ($over()) {
            break;
        }
        try {
            $out[] = $b . ' calendar: ' . pm_social_replenish($b);
        } catch (Throwable $e) {
            $out[] = $b . ' calendar: ' . $e->getMessage();
        }
    }
    try {
        if ($w = pm_social_notify_waiting()) {
            $out[] = "$w draft(s) waiting for approval: owner alerted";
        }
    } catch (Throwable $e) {
        $out[] = 'alert: ' . $e->getMessage();
    }
    foreach (['promanaged', 'travel'] as $b) {
        if ($over()) {
            break;
        }
        try {
            $out[] = $b . ': ' . pm_social_autopilot($b);
        } catch (Throwable $e) {
            $out[] = $b . ': ' . $e->getMessage();
        }
    }
    $left = $budget > 0 ? max(1, $budget - (time() - $t0)) : 0;
    $out[] = pm_social_run_jobs($left, $by);
    return trim(implode("\n", array_filter($out, fn($l) => $l !== ''))) . "\n";
}
