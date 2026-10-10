<?php
/**
 * sx_ops — MARKETING.md cycle 2, the GUARDIAN swarm: C2-G01 setup-health panel, C2-G02 lead archiving, C2-G03 housekeeping job,
 * C2-G04 weekly data archive. Auto-loaded via lib/social_modules.php (glob); the jobs are registered by name (pm_jobg_*) and run by the scheduler.
 * Nothing here changes what is sent. Archiving never touches a "do not contact" lead (suppression depends on it) or a client.
 */

/* ---------------- C2-G01 · setup health: what is connected and what to add ---------------- */

/**
 * One row per thing the engine can use: [key, label, need 'required'|'optional', ok bool, detail, add]. Both businesses are listed; nothing is called over the network.
 * 'add' says exactly which .env lines (or screen) would switch it on.
 */
function pm_setup_health(): array
{
    $was = pm_brand();
    $rows = [];
    $row = function (string $key, string $label, string $need, bool $ok, string $detail, string $add) use (&$rows): void {
        $rows[] = ['key' => $key, 'label' => $label, 'need' => $need, 'ok' => $ok, 'detail' => $detail, 'add' => $ok ? '' : $add];
    };
    $names = ['promanaged' => 'ProManaged IT', 'travel' => 'Travel Malawi'];
    $row('ai', 'AI agents', 'required', pm_agents_ready(), pm_agents_ready() ? 'An AI key is set.' : 'No AI key yet, so the agents and the planner cannot write.', pm_agents_missing_key() . '=... in .env');
    foreach ($names as $b => $n) {
        $p = $b === 'travel' ? 'TM_' : '';
        pm_brand_set($b);
        $sm = (array)(pm_settings()['smtp'] ?? []);
        $mailOk = trim((string)($sm['host'] ?? '')) !== '' && trim((string)($sm['username'] ?? '')) !== '';
        $row("smtp_$b", "Email sending · $n", 'required', $mailOk, $mailOk ? 'Sends as ' . (trim((string)($sm['from_email'] ?? '')) ?: (string)$sm['username']) . '.' : 'No mail login: emails cannot go out.',
            "{$p}SMTP_HOST, {$p}SMTP_USER, {$p}SMTP_PASS in .env (or Settings > Email sending)");
        $im = function_exists('pm_imap_settings') ? pm_imap_settings($b) : ['host' => '', 'user' => '', 'pass' => ''];
        $imOk = $im['host'] !== '' && $im['user'] !== '' && $im['pass'] !== '';
        $row("imap_$b", "Reading replies · $n", 'required', $imOk, $imOk ? 'Reads the inbox at ' . $im['host'] . '.' : 'Replies are not read, so nobody is answered automatically.',
            "{$p}IMAP_HOST, {$p}IMAP_USER, {$p}IMAP_PASS in .env (or the same as the mail login)");
        $fb = pm_social_cfg($b);
        $row("fb_$b", "Facebook Page · $n", 'required', $fb['ready'], $fb['ready'] ? 'Connected.' : 'Posts cannot be published and comments cannot be read.', 'Social > Accounts & branding > Connect Facebook in 3 steps');
        $row("ig_$b", "Instagram · $n", 'optional', $fb['ig_id'] !== '', $fb['ig_id'] !== '' ? 'Linked.' : 'Instagram posts stay hand-posted.', "Link the account to the Page, then reconnect Facebook ({$p}IG_USER_ID)");
        $li = pm_linkedin_cfg($b);
        $row("li_$b", "LinkedIn · $n", 'optional', $li['ready'], $li['ready'] ? 'Connected.' : 'LinkedIn posts stay hand-posted.', "{$p}LI_ORG_ID and {$p}LI_TOKEN in .env (needs LinkedIn approval)");
    }
    pm_brand_set($was);
    $x = pm_x_cfg();
    $row('x', 'X (Twitter)', 'optional', $x['ready'], $x['ready'] ? 'Connected.' : 'Nothing is posted to X.', 'X_API_KEY, X_API_SECRET, X_ACCESS_TOKEN, X_ACCESS_SECRET in .env');
    $wa = function_exists('pm_wa_biz_cfg') ? pm_wa_biz_cfg() : ['ready' => false];
    $row('wa', 'WhatsApp Business', 'optional', $wa['ready'], $wa['ready'] ? 'Connected' . (pm_env_val('WA_BIZ_TEMPLATE') !== '' ? ', campaign template set.' : '; add a template name to run campaigns outside the 24-hour window.') : 'No automatic WhatsApp answers or campaigns.',
        'WA_BIZ_TOKEN, WA_BIZ_PHONE_ID, WA_BIZ_VERIFY in .env (and WA_BIZ_TEMPLATE for campaigns)');
    $cron = pm_env_val('CRON_KEY') !== '';
    $age = function_exists('pm_social_heartbeat_age') ? pm_social_heartbeat_age() : null;
    $row('scheduler', 'Scheduler', 'required', $age !== null && $age < 7200, $age === null ? 'It has never run: scheduled posts and sending only happen while the app is open.' : ($age < 7200 ? 'Last ran ' . max(1, intdiv($age, 60)) . ' minutes ago.' : 'Last ran ' . intdiv($age, 3600) . ' hours ago: check the scheduled task.'),
        'Run schedule_agents.bat once (Windows), or add the cPanel cron line (README: SOCIAL)');
    $row('cron_key', 'Web scheduler key', 'optional', $cron, $cron ? 'CRON_KEY is set: cron.php can be called by the host.' : 'Only needed on a website host (cPanel cron).', 'CRON_KEY=a-long-random-text in .env');
    $url = pm_app_url() !== '';
    $row('app_url', 'Public address', 'required', $url, $url ? pm_app_url() : 'Instagram cannot fetch pictures and clients cannot open links until the app is online.', 'APP_URL=https://your-site in .env');
    return $rows;
}

/** Counts for the panel header: [ready, total required, ready optional, total optional]. */
function pm_setup_health_summary(array $rows): array
{
    $r = [0, 0, 0, 0];
    foreach ($rows as $x) {
        $i = $x['need'] === 'required' ? 0 : 2;
        $r[$i + 1]++;
        $r[$i] += $x['ok'] ? 1 : 0;
    }
    return $r;
}

/** The Settings panel: every channel's ready / not-ready state and what to add. */
function pm_view_setup_health(): void
{
    $rows = pm_setup_health();
    [$ok, $need, $okO, $needO] = pm_setup_health_summary($rows);
    echo '<details class="card"' . ($ok < $need ? ' open' : '') . '><summary><b>Setup health</b> <span class="pill ' . ($ok < $need ? 'warn' : 'hot') . '">' . $ok . ' of ' . $need . ' essentials ready</span> <span class="pill">' . $okO . ' of ' . $needO . ' optional channels on</span></summary>'
        . '<p class="hint">One glance at what is connected. Nothing here is checked over the network, so it is instant. Optional channels stay off until their keys exist.</p>'
        . '<table class="grid compact" style="width:100%"><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td style="white-space:nowrap"><span class="pill ' . ($r['ok'] ? 'hot' : ($r['need'] === 'required' ? 'warn' : '')) . '">' . ($r['ok'] ? 'ready' : ($r['need'] === 'required' ? 'to do' : 'off')) . '</span></td>'
            . '<td><b>' . pm_h($r['label']) . '</b><br><span class="hint" style="margin:0">' . pm_h($r['detail']) . '</span></td>'
            . '<td>' . ($r['add'] !== '' ? '<span class="hint" style="margin:0">Add: <code>' . pm_h($r['add']) . '</code></span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></details>';
}

/* ---------------- C2-G02 · lead archiving ---------------- */

const PM_ARCHIVE_DAYS = 365;

/** The latest moment anything happened to a lead (a note, a message, a call, a status change, a view). */
function pm_lead_last_touch(array $l): int
{
    $t = 0;
    foreach (['created', 'updated', 'last_contacted', 'last_out', 'last_reply', 'lost_at', 'won_at', 'proposal_sent_at', 'viewed_at', 'last_viewed_at', 'winback_sent_at', 'requalified_at', 'contact_checked_at'] as $k) {
        $t = max($t, (int)(strtotime((string)($l[$k] ?? '')) ?: 0));
    }
    foreach (['notes', 'thread', 'calls'] as $k) {
        foreach ((array)($l[$k] ?? []) as $e) {
            $t = max($t, (int)(strtotime((string)($e['at'] ?? '')) ?: 0));
        }
    }
    foreach ((array)($l['sent'] ?? []) as $at) {
        $t = max($t, (int)(strtotime((string)$at) ?: 0));
    }
    return $t;
}

/** Old and quiet enough to move out of the lists? Never a client (won) or a "do not contact" lead (suppression needs it), nothing queued to send, nothing snoozed ahead. */
function pm_lead_archivable(array $l, ?int $now = null): bool
{
    $now ??= time();
    if (in_array((string)($l['status'] ?? ''), ['won', 'optout'], true) || !empty($l['approved_at']) || (string)($l['snooze_until'] ?? '') > date('Y-m-d', $now)) {
        return false;
    }
    $t = pm_lead_last_touch($l);
    return $t > 0 && $t < $now - PM_ARCHIVE_DAYS * 86400;
}

/** Daily job: leads untouched for 12 months move to data/leads_archive.json (kept whole and restorable). */
function pm_jobg_archiving(): string
{
    $st = pm_load('housekeeping', fn() => []);
    if (($st['archiving_day'] ?? '') === date('Y-m-d')) {
        return '';
    }
    $leads = pm_leads();
    $move = [];
    foreach ($leads as $id => $l) {
        if (is_array($l) && pm_lead_archivable($l)) {
            $move[$id] = $l + ['archived_at' => date('Y-m-d H:i')];
        }
    }
    if ($move) {
        pm_update('leads_archive', fn(array $a) => $move + $a, fn() => []); // safely on disk first ...
        foreach (array_keys($move) as $id) {
            unset($leads[$id]);
        }
        pm_leads_save($leads); // ... then out of the main list
    }
    pm_update('housekeeping', function (array $s) { $s['archiving_day'] = date('Y-m-d'); return $s; }, fn() => []);
    return $move ? count($move) . ' lead(s) untouched for ' . PM_ARCHIVE_DAYS . ' days moved to the archive' : '';
}

/** Puts one archived lead back into the main list. [ok, message]. */
function pm_lead_unarchive(string $id): array
{
    $a = pm_leads_archive();
    if (!isset($a[$id])) {
        return [false, 'That lead is not in the archive.'];
    }
    $l = $a[$id];
    unset($l['archived_at']);
    $leads = pm_leads();
    if (isset($leads[$id])) {
        return [false, 'A lead with that name is already in the main list.'];
    }
    pm_lead_note($l, 'Restored from the archive'); // also refreshes "updated", so it is not archived again at once
    $leads[$id] = $l;
    pm_leads_save($leads);
    pm_update('leads_archive', function (array $all) use ($id) { unset($all[$id]); return $all; }, fn() => []);
    return [true, ($l['name'] ?? 'The lead') . ' is back in your leads.'];
}

/* ---------------- C2-G03 · housekeeping ---------------- */

/** Deletes files in $dir (not folders) matching $glob that are older than $maxAge seconds. Returns how many. Never leaves $dir. */
function pm_hk_clear(string $dir, string $glob, int $maxAge, int $now): int
{
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) {
        return 0;
    }
    $n = 0;
    foreach (glob($root . DIRECTORY_SEPARATOR . $glob) ?: [] as $f) {
        $real = realpath($f);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || dirname($real) !== $root) {
            continue;
        }
        if ($now - (int)filemtime($real) > $maxAge && @unlink($real)) {
            $n++;
        }
    }
    return $n;
}

/** The sweep: old agent job files (1 day), old rate-limit counters (2 days), leftover half-written temp files (1 hour). Live data files are never matched. */
function pm_housekeeping_sweep(?int $now = null): array
{
    $now ??= time();
    $r = ['agent_jobs' => 0, 'ratelimit' => 0, 'temp' => 0];
    foreach (['job_*', '*.out.json'] as $g) {
        $r['agent_jobs'] += pm_hk_clear(PM_DATA . '/agent_jobs', $g, 86400, $now);
    }
    $r['ratelimit'] = pm_hk_clear(PM_DATA . '/ratelimit', '*.json', 2 * 86400, $now);
    foreach ([PM_DATA, PM_DATA . '/proposals', PM_DATA . '/social'] as $d) {
        $r['temp'] += pm_hk_clear($d, '*.tmp', 3600, $now);
    }
    return $r;
}

/** Daily job: runs the sweep once a day. */
function pm_jobg_housekeeping(): string
{
    $st = pm_load('housekeeping', fn() => []);
    if (($st['sweep_day'] ?? '') === date('Y-m-d')) {
        return '';
    }
    $r = pm_housekeeping_sweep();
    pm_update('housekeeping', function (array $s) use ($r) { $s['sweep_day'] = date('Y-m-d'); $s['last'] = $r; return $s; }, fn() => []);
    $total = array_sum($r);
    return $total ? "cleared $total old file(s): {$r['agent_jobs']} agent job, {$r['ratelimit']} rate-limit, {$r['temp']} temp" : '';
}

/* ---------------- C2-G04 · weekly data archive ---------------- */

const PM_DATA_ARCHIVE_KEEP_DAYS = 56; // 8 weeks

/** Removes anything secret-looking from decoded JSON: passwords, secrets, API keys and access tokens, and whole mail-server blocks. */
function pm_scrub_secrets(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && (preg_match('/^(smtp|imap|csrf)$/i', $k) || preg_match('/pass(word|wd)?$|secret|api_?key|access_?token|page_?token|token_secret|^authorization$/i', $k))) {
            continue;
        }
        $out[$k] = pm_scrub_secrets($x);
    }
    return $out;
}

/** Top-level data/*.json as name => scrubbed JSON text (a file that cannot be read is skipped, never aborts the archive). */
function pm_data_archive_files(): array
{
    $out = [];
    foreach (glob(PM_DATA . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) {
            continue;
        }
        $out[basename($f)] = json_encode(pm_scrub_secrets($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $out;
}

function pm_data_archive_dir(): string
{
    $d = PM_DATA . '/archive';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Writes today's archive (a zip when php-zip exists, else one gzip of a JSON bundle). Returns [path, file count] or ['', 0] on failure. */
function pm_data_archive_make(?string $day = null): array
{
    $day ??= date('Y-m-d');
    $files = pm_data_archive_files();
    if (!$files) {
        return ['', 0];
    }
    $dir = pm_data_archive_dir();
    if (class_exists('ZipArchive')) {
        $path = "$dir/data-$day.zip";
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['', 0];
        }
        foreach ($files as $name => $json) {
            $z->addFromString($name, $json);
        }
        $z->addFromString('README.txt', "Backup of data/*.json on $day. Mail logins, secrets and API keys were removed. To restore a file, copy it back into the data folder.\n");
        $z->close();
    } else {
        $path = "$dir/data-$day.json.gz";
        $bundle = json_encode(['created' => date('c'), 'note' => 'Backup of data/*.json. Secrets removed. Each key under "files" is a file to restore into the data folder.',
            'files' => array_map(fn($j) => json_decode($j, true), $files)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $gz = @gzencode((string)$bundle, 6);
        if ($gz === false || @file_put_contents($path, $gz, LOCK_EX) === false) {
            return ['', 0];
        }
    }
    return is_file($path) ? [$path, count($files)] : ['', 0];
}

/** The archives on disk, newest first: [path => Y-m-d]. */
function pm_data_archives(): array
{
    $o = [];
    foreach (glob(pm_data_archive_dir() . '/data-*') ?: [] as $f) {
        if (preg_match('/data-(\d{4}-\d{2}-\d{2})\.(zip|json\.gz)$/', basename($f), $m)) {
            $o[$f] = $m[1];
        }
    }
    arsort($o);
    return $o;
}

/** Weekly job: one archive a week, 8 weeks kept. */
function pm_jobg_archive(): string
{
    $have = pm_data_archives();
    $latest = $have ? max($have) : '';
    if ($latest !== '' && $latest > date('Y-m-d', strtotime('-6 days'))) {
        return '';
    }
    [$path, $n] = pm_data_archive_make();
    if ($path === '') {
        return 'weekly data archive failed (could not write data/archive)';
    }
    $gone = 0;
    foreach (pm_data_archives() as $f => $d) {
        if ($d < date('Y-m-d', strtotime('-' . PM_DATA_ARCHIVE_KEEP_DAYS . ' days')) && @unlink($f)) {
            $gone++;
        }
    }
    return 'weekly archive saved (' . $n . ' files, ' . basename($path) . ')' . ($gone ? ", $gone old archive(s) removed" : '');
}
