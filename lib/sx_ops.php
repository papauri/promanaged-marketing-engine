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
    $names = [];
    foreach (pm_brand_ids() as $bid) {
        $names[$bid] = pm_brand_name($bid);
    }
    $row('ai', 'AI agents', 'required', pm_agents_ready(), pm_agents_ready() ? 'An AI key is set.' : 'No AI key yet, so the agents and the planner cannot write.', pm_agents_missing_key() . '=... in .env');
    foreach ($names as $b => $n) {
        $p = pm_brand_env_prefix($b);
        pm_brand_set($b);
        $sm = (array)(pm_settings()['smtp'] ?? []);
        $mailOk = trim((string)($sm['host'] ?? '')) !== '' && trim((string)($sm['username'] ?? '')) !== '';
        $row("smtp_$b", "Email sending · $n", 'required', $mailOk, $mailOk ? 'Sends as ' . (trim((string)($sm['from_email'] ?? '')) ?: (string)$sm['username']) . ' through ' . $sm['host'] . '.'
            . (function_exists('pm_mail_source') && pm_mail_source($b) === 'shared' ? " This business has no mail login of its own, so it uses ProManaged IT's: add {$p}SMTP_HOST, {$p}SMTP_USER and {$p}SMTP_PASS to .env." : '') : 'No mail login: emails cannot go out.',
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
        $x = pm_x_cfg($b); // each business has its own X account and its own WhatsApp Business number
        $row("x_$b", "X (Twitter) · $n", 'optional', $x['ready'], $x['ready'] ? 'Connected.' : 'Nothing is posted to X for this business.', "{$p}X_API_KEY, {$p}X_API_SECRET, {$p}X_ACCESS_TOKEN, {$p}X_ACCESS_SECRET in .env");
        $wa = function_exists('pm_wa_biz_cfg') ? pm_wa_biz_cfg($b) : ['ready' => false, 'template' => ''];
        $row("wa_$b", "WhatsApp Business · $n", 'optional', $wa['ready'], $wa['ready'] ? 'Connected' . ($wa['template'] !== '' ? ', campaign template set.' : '; add a template name to run campaigns outside the 24-hour window.')
            . (pm_brand_env($b, 'WA_BIZ_APP_SECRET') === '' ? ' Messages are not checked as coming from WhatsApp: add ' . $p . 'WA_BIZ_APP_SECRET (the Meta app secret).' : ' Incoming messages are checked.') : 'No automatic WhatsApp answers or campaigns for this business.',
            "{$p}WA_BIZ_TOKEN, {$p}WA_BIZ_PHONE_ID, {$p}WA_BIZ_VERIFY in .env (and {$p}WA_BIZ_TEMPLATE for campaigns)");
    }
    pm_brand_set($was);
    $cron = pm_env_val('CRON_KEY') !== '';
    $age = function_exists('pm_social_heartbeat_age') ? pm_social_heartbeat_age() : null;
    $last = function_exists('pm_health_last') ? pm_health_last() : [];
    $row('scheduler', 'Scheduler', 'required', $age !== null && $age < 7200, $age === null ? 'It has never run: scheduled posts and sending only happen while the app is open.' : ($age < 7200 ? 'Last ran ' . max(1, intdiv($age, 60)) . ' minutes ago.' : 'Last ran ' . intdiv($age, 3600) . ' hours ago: check the scheduled task.'),
        'Run schedule_agents.bat once (Windows), or add the cPanel cron line (README: SOCIAL)');
    $row('cron_key', 'Web scheduler key', 'optional', $cron, $cron ? 'CRON_KEY is set: cron.php can be called by the host.' : 'Only needed on a website host (cPanel cron).', 'CRON_KEY=a-long-random-text in .env');
    $url = pm_app_url() !== '';
    $row('app_url', 'Public address', 'required', $url, $url ? pm_app_url() : 'Instagram cannot fetch pictures and clients cannot open links until the app is online.', 'APP_URL=https://your-site in .env');
    foreach ($rows as $i => $r) { // C3-G02: rows with a check behind them get a Test button and show the last result
        $rows[$i]['testable'] = pm_health_testable($r);
        $rows[$i]['last'] = $last[$r['key']] ?? null;
    }
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
            . '<td>' . ($r['add'] !== '' ? '<span class="hint" style="margin:0">Add: <code>' . pm_h($r['add']) . '</code></span>' : '')
            . (!empty($r['testable']) ? '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . pm_h((string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''))) . '"><input type="hidden" name="action" value="health_check"><input type="hidden" name="key" value="' . pm_h($r['key']) . '"><button class="btn small">Test now</button></form>' : '')
            . (!empty($r['last']) ? ' <span class="hint" style="margin:0">Last test ' . pm_h(date('j M H:i', strtotime((string)$r['last']['at']))) . ': ' . (!empty($r['last']['ok']) ? '<b>ok</b>' : '<b style="color:var(--danger)">failed</b>') . ' · ' . pm_h((string)$r['last']['msg']) . '</span>' : '') . '</td></tr>';
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

/* ================================================================== cycle 3 ================================================================== */

/* ---------------- C3-G02 · test buttons on the setup-health rows ---------------- */

/** Logs in to the business's mail server without sending anything: [ok, message]. $GLOBALS['PM_SMTP_CHECK_STUB'] (tests) replaces the network. */
function pm_smtp_login_check(array $sm): array
{
    if (trim((string)($sm['host'] ?? '')) === '' || trim((string)($sm['username'] ?? '')) === '') {
        return [false, 'no mail server is set up yet'];
    }
    if (isset($GLOBALS['PM_SMTP_CHECK_STUB']) && is_callable($GLOBALS['PM_SMTP_CHECK_STUB'])) {
        return $GLOBALS['PM_SMTP_CHECK_STUB']($sm);
    }
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return [false, 'the mail library is not loaded'];
    }
    try {
        $mm = new PHPMailer\PHPMailer\PHPMailer(true);
        $mm->isSMTP();
        $mm->Host = (string)$sm['host'];
        $mm->Port = (int)$sm['port'];
        $mm->SMTPAuth = true;
        $mm->Username = (string)$sm['username'];
        $mm->Password = (string)$sm['password'];
        $mm->SMTPSecure = ($sm['encryption'] ?? '') === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : (($sm['encryption'] ?? '') === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        $mm->Timeout = 20;
        $ok = $mm->smtpConnect();
        $mm->smtpClose();
        return $ok ? [true, 'logged in to ' . $sm['host'] . ':' . (int)$sm['port'] . ' as ' . $sm['username'] . '; nothing was sent'] : [false, function_exists('pm_smtp_explain') ? pm_smtp_explain($sm, 'The server did not accept the login.') : 'the server did not accept the login'];
    } catch (Throwable $e) {
        return [false, function_exists('pm_smtp_explain') ? pm_smtp_explain($sm, $e->getMessage()) : mb_substr($e->getMessage(), 0, 200)];
    }
}

/**
 * Runs the check behind one setup-health row: smtp_<business> (mail login), imap_<business> (reply inbox login), fb_<business> (Facebook Page) or wa (WhatsApp Business).
 * Returns [ok, message] and remembers the answer for the row. Each check only reads or logs in; nothing is posted or sent.
 */
function pm_health_check(string $key): array
{
    $res = [false, 'There is no check for that.'];
    $what = (string)strtok($key, '_');
    $b = (string)substr($key, strlen($what) + 1);
    $was = pm_brand();
    try {
        if (in_array($what, ['smtp', 'imap', 'fb', 'wa'], true) && !pm_brand_valid($b)) {
            $res = [false, 'Unknown business.'];
        } elseif ($what === 'smtp') {
            pm_brand_set($b);
            $res = pm_smtp_login_check((array)(pm_settings()['smtp'] ?? []));
        } elseif ($what === 'imap') {
            $res = function_exists('pm_imap_login_check') ? pm_imap_login_check(pm_imap_settings($b)) : [false, 'Not available.'];
        } elseif ($what === 'fb') {
            $pg = pm_social_page($b, true);
            $res = !empty($pg['ok']) ? [true, 'connected to the Page "' . $pg['name'] . '" (' . number_format((int)$pg['followers']) . ' followers)'] : [false, (string)($pg['error'] ?? 'not connected')];
        } elseif ($what === 'wa') {
            $res = function_exists('pm_wa_biz_check') ? pm_wa_biz_check($b) : [false, 'Not available.'];
        }
    } catch (Throwable $e) {
        $res = [false, mb_substr($e->getMessage(), 0, 200)];
    } finally {
        pm_brand_set($was);
    }
    $res = [(bool)$res[0], trim((string)$res[1])];
    pm_update('health_checks', function (array $all) use ($key, $res) {
        $all[$key] = ['at' => date('Y-m-d H:i'), 'ok' => $res[0], 'msg' => mb_substr($res[1], 0, 200)];
        return array_slice($all, -60, null, true);
    }, fn() => []);
    return $res;
}

/** The last test result per row key: key => {at, ok, msg}. */
function pm_health_last(): array
{
    return pm_load('health_checks', fn() => []);
}

/** Does this setup-health row have a check behind it, and is it set up enough to try? */
function pm_health_testable(array $row): bool
{
    return !empty($row['ok']) && preg_match('/^(smtp|imap|fb|wa)_/', (string)$row['key']) === 1;
}

/* ---------------- C3-G01 · restore one file from a weekly archive ---------------- */

/** The files inside one archive: name => decoded array. '' / unreadable archives give []. */
function pm_archive_read(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    if (str_ends_with($path, '.zip')) {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $z = new ZipArchive();
        if ($z->open($path) !== true) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string)$z->getNameIndex($i);
            if (preg_match('/^[a-z0-9_]+\.json$/', $n) && ($d = json_decode((string)$z->getFromIndex($i), true)) !== null && is_array($d)) {
                $out[$n] = $d;
            }
        }
        $z->close();
        return $out;
    }
    $j = json_decode((string)@gzdecode((string)file_get_contents($path)), true);
    $out = [];
    foreach ((array)($j['files'] ?? []) as $n => $d) {
        if (preg_match('/^[a-z0-9_]+\.json$/', (string)$n) && is_array($d)) {
            $out[(string)$n] = $d;
        }
    }
    return $out;
}

/** The archive file for a name typed or posted: only a file that is really in data/archive (never a path). '' when unknown. */
function pm_archive_path(string $name): string
{
    foreach (array_keys(pm_data_archives()) as $f) {
        if (basename($f) === $name) {
            return $f;
        }
    }
    return '';
}

/** Archives were written without mail logins, secrets and tokens. A restored file keeps the CURRENT value of every such key, so a restore never wipes a login. */
function pm_restore_keep_secrets(array $current, array $archived): array
{
    $secret = fn($k) => is_string($k) && (preg_match('/^(smtp|imap|csrf)$/i', $k) || preg_match('/pass(word|wd)?$|secret|api_?key|access_?token|page_?token|token_secret|^authorization$/i', $k));
    foreach ($current as $k => $v) {
        if ($secret($k)) {
            $archived[$k] = $v;
        } elseif (is_array($v) && isset($archived[$k]) && is_array($archived[$k])) {
            $archived[$k] = pm_restore_keep_secrets($v, $archived[$k]);
        }
    }
    return $archived;
}

/**
 * Puts ONE file back from a weekly archive. The archive must be one of ours, the file must be inside it. The file being replaced is copied to
 * data/backup/<name>.before-restore-<time>.json first, so the restore itself can be undone. Returns [ok, message].
 */
function pm_archive_restore(string $archiveName, string $file): array
{
    $path = pm_archive_path($archiveName);
    if ($path === '') {
        return [false, 'That archive is not in the list.'];
    }
    if (!preg_match('/^[a-z0-9_]+\.json$/', $file)) {
        return [false, 'That is not a data file name.'];
    }
    $inside = pm_archive_read($path);
    if (!isset($inside[$file])) {
        return [false, $file . ' is not in that archive' . (str_ends_with($path, '.zip') && !class_exists('ZipArchive') ? ' (this server cannot open zip files)' : '') . '.'];
    }
    $name = substr($file, 0, -5);
    $cur = PM_DATA . '/' . $file;
    $kept = '';
    if (is_file($cur) && filesize($cur) > 0) {
        $dir = PM_DATA . '/backup';
        @mkdir($dir, 0775, true);
        $kept = $dir . '/' . $name . '.before-restore-' . date('YmdHis') . '.json';
        if (!@copy($cur, $kept)) {
            return [false, 'Could not back up the current ' . $file . ' first, so nothing was changed.'];
        }
    }
    $curData = is_file($cur) ? (array)(json_decode((string)file_get_contents($cur), true) ?: []) : [];
    $data = pm_restore_keep_secrets($curData, $inside[$file]);
    if (!$data && $curData) {
        return [false, 'The archived copy of ' . $file . ' is empty, so nothing was changed.'];
    }
    try {
        pm_save($name, $data);
    } catch (Throwable $e) {
        return [false, 'Could not write ' . $file . ': ' . $e->getMessage()];
    }
    return [true, 'Restored ' . $file . ' from the ' . $archiveName . ' archive.' . ($kept !== '' ? ' The file it replaced was kept as ' . basename($kept) . ' in the backup folder.' : '')];
}

/** The Backups panel of Settings: the archives, a download link and a restore form for each. */
function pm_view_archives(): void
{
    $csrf = (string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''));
    $arch = pm_data_archives();
    echo '<details class="card"><summary><b>Backups</b> <span class="pill">' . count($arch) . ' weekly archive' . (count($arch) === 1 ? '' : 's') . '</span></summary>'
        . '<p class="hint">Every week the app saves a copy of your data (mail logins and keys removed) and keeps the last eight. Download one to keep it somewhere safe, or put a single file back. '
        . 'Restoring keeps the file it replaces in the backup folder, and never touches your mail logins.</p>';
    if (!$arch) {
        echo '<p class="hint">No archive yet: the first one is made by the scheduler within a week.</p></details>';
        return;
    }
    foreach (array_slice($arch, 0, 8, true) as $f => $day) {
        $names = array_keys(pm_archive_read($f));
        sort($names);
        echo '<form method="post" class="task"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="archive_restore"><input type="hidden" name="archive" value="' . pm_h(basename($f)) . '">'
            . '<span><b>' . pm_h(date('j M Y', strtotime($day))) . '</b> <span class="muted">· ' . pm_h(strtoupper(substr(basename($f), strrpos(basename($f), '.') + 1))) . ' · ' . number_format(((int)filesize($f)) / 1024, 0) . ' KB</span></span>'
            . '<a class="btn small" href="?archive=' . urlencode(basename($f)) . '">Download</a>'
            . ($names ? '<select name="file" aria-label="File to put back" required><option value="">Put back…</option>' . implode('', array_map(fn($n) => '<option>' . pm_h($n) . '</option>', $names)) . '</select>'
                . '<label class="check" style="margin:0"><input type="checkbox" name="confirm" value="1" required> I understand</label><button class="btn small danger">Restore that file</button>'
                : '<span class="hint">Cannot be opened on this server.</span>') . '</form>';
    }
    echo '</details>';
}

/* ---------------- C3-G03 · archive search and bulk restore ---------------- */

/** Archived leads of one business matching a search: by words in the name, the city or the type. Newest archived first, up to $max. */
function pm_archive_search(string $brand, string $q = '', string $city = '', string $type = '', int $max = 50): array
{
    $out = [];
    $q = mb_strtolower(trim($q));
    $city = mb_strtolower(trim($city));
    $type = mb_strtolower(trim($type));
    foreach (pm_leads_archive() as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $hay = mb_strtolower((string)($l['name'] ?? '') . ' ' . (string)($l['contact'] ?? '') . ' ' . (string)($l['email'] ?? ''));
        if (($q !== '' && !str_contains($hay, $q)) || ($city !== '' && !str_contains(mb_strtolower((string)($l['city'] ?? '')), $city)) || ($type !== '' && !str_contains(mb_strtolower((string)($l['type'] ?? '')), $type))) {
            continue;
        }
        $out[(string)$id] = $l;
    }
    uasort($out, fn($a, $b) => strcmp((string)($b['archived_at'] ?? ''), (string)($a['archived_at'] ?? '')));
    return array_slice($out, 0, max(1, $max), true);
}

/** Restores several archived leads at once (each exactly like a single restore). Returns [restored count, message]. */
function pm_lead_unarchive_many(array $ids): array
{
    $ok = 0;
    $bad = [];
    foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 200) as $id) {
        [$r, $m] = pm_lead_unarchive($id);
        if ($r) {
            $ok++;
        } else {
            $bad[] = $m;
        }
    }
    return [$ok, $ok ? "$ok lead" . ($ok === 1 ? ' is' : 's are') . ' back in your leads.' . ($bad ? ' ' . count($bad) . ' could not be restored (' . $bad[0] . ')' : '') : ($bad ? $bad[0] : 'Tick the leads to restore first.')];
}

/* ---------------- C3-G04 · archive old published posts, keeping their numbers ---------------- */

const PM_POST_ARCHIVE_MONTHS = 18;

/** The 7-day engagement of a published post (reactions + comments + shares, or what the scoreboard counts), 0 when unmeasured. */
function pm_post_archive_eng(array $p): int
{
    $m = (array)($p['metrics']['d7'] ?? []);
    if (!$m) {
        return 0;
    }
    return function_exists('pm_social_eng') ? (int)pm_social_eng($m) : (int)(($m['reactions'] ?? 0) + ($m['comments'] ?? 0) + ($m['shares'] ?? 0));
}

/** The summary rows kept for archived posts: [brand => [YYYY-MM => ['posts','eng','measured']]] and the archived posts themselves. */
function pm_posts_archive_data(): array
{
    $d = pm_load('social_posts_archive', fn() => ['posts' => [], 'summary' => []]);
    return ['posts' => (array)($d['posts'] ?? []), 'summary' => (array)($d['summary'] ?? [])];
}

/** Totals for one business: ['posts','eng','measured','avg','from','to'] over its archived posts, or posts 0. */
function pm_posts_archive_summary(string $brand): array
{
    $n = 0;
    $eng = 0;
    $meas = 0;
    $months = [];
    foreach ((array)(pm_posts_archive_data()['summary'][$brand] ?? []) as $month => $r) {
        $n += (int)$r['posts'];
        $eng += (int)$r['eng'];
        $meas += (int)$r['measured'];
        $months[] = (string)$month;
    }
    sort($months);
    return ['posts' => $n, 'eng' => $eng, 'measured' => $meas, 'avg' => $meas ? round($eng / $meas, 1) : null, 'from' => $months[0] ?? '', 'to' => $months ? end($months) : ''];
}

/** Moves published posts older than 18 months out of social_posts.json. Their numbers stay in a summary row per business and month. Returns the number moved. */
function pm_posts_archive_run(?int $now = null): int
{
    $now ??= time();
    $cut = date('Y-m-d', strtotime('-' . PM_POST_ARCHIVE_MONTHS . ' months', $now));
    $move = [];
    foreach (pm_social_posts() as $p) {
        $d = substr((string)(($p['published'] ?? '') ?: ($p['when'] ?? '')), 0, 10);
        if (($p['status'] ?? '') === 'published' && $d !== '' && $d < $cut) {
            $move[(string)$p['id']] = $p;
        }
    }
    if (!$move) {
        return 0;
    }
    pm_update('social_posts_archive', function (array $a) use ($move) { // safely on disk first ...
        $a['posts'] = (array)($a['posts'] ?? []);
        $a['summary'] = (array)($a['summary'] ?? []);
        foreach ($move as $id => $p) {
            if (isset($a['posts'][$id])) {
                continue;
            }
            $b = pm_brand_norm($p['brand'] ?? 'promanaged');
            $m = substr((string)(($p['published'] ?? '') ?: ($p['when'] ?? '')), 0, 7);
            $r = (array)($a['summary'][$b][$m] ?? ['posts' => 0, 'eng' => 0, 'measured' => 0]);
            $r['posts']++;
            $r['eng'] += pm_post_archive_eng($p);
            $r['measured'] += !empty($p['metrics']['d7']) ? 1 : 0;
            $a['summary'][$b][$m] = $r;
            $a['posts'][$id] = $p + ['archived_at' => date('Y-m-d H:i')];
        }
        return $a;
    }, fn() => ['posts' => [], 'summary' => []]);
    pm_social_update(fn(array $posts) => array_values(array_filter($posts, fn($p) => !isset($move[(string)($p['id'] ?? '')])))); // ... then out of the live file
    return count($move);
}

/** Puts archived posts back (all of a business, or one month) and takes them out of the summary. Returns the number restored. */
function pm_posts_archive_restore(string $brand, string $month = ''): int
{
    $back = [];
    pm_update('social_posts_archive', function (array $a) use ($brand, $month, &$back) {
        foreach ((array)($a['posts'] ?? []) as $id => $p) {
            $m = substr((string)(($p['published'] ?? '') ?: ($p['when'] ?? '')), 0, 7);
            if (pm_brand_norm($p['brand'] ?? 'promanaged') === $brand && ($month === '' || $m === $month)) {
                $back[$id] = $p;
                unset($a['posts'][$id]);
            }
        }
        if ($month === '') {
            unset($a['summary'][$brand]);
        } else {
            unset($a['summary'][$brand][$month]);
        }
        return $a;
    }, fn() => ['posts' => [], 'summary' => []]);
    if ($back) {
        pm_social_update(function (array $posts) use ($back) {
            $have = array_column($posts, 'id');
            foreach ($back as $id => $p) {
                if (!in_array($id, $have, true)) {
                    unset($p['archived_at']);
                    $posts[] = $p;
                }
            }
            return $posts;
        });
    }
    return count($back);
}

/** Monthly job: old published posts leave the live file. */
function pm_jobg_posts_archive(): string
{
    $st = pm_load('housekeeping', fn() => []);
    if (($st['posts_archive_month'] ?? '') === date('Y-m')) {
        return '';
    }
    $n = pm_posts_archive_run();
    pm_update('housekeeping', function (array $s) { $s['posts_archive_month'] = date('Y-m'); return $s; }, fn() => []);
    return $n ? "$n published post(s) older than " . PM_POST_ARCHIVE_MONTHS . ' months archived (their numbers are kept)' : '';
}

/** Results-screen note: published posts older than 18 months are archived, and their numbers stay here as a summary. */
function pm_panel_results_archived(string $vb, array $ctx = []): string
{
    $a = pm_posts_archive_summary($vb);
    if (!$a['posts']) {
        return '';
    }
    $csrf = (string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''));
    return '<div class="card"><h2>Older posts</h2><p>' . (int)$a['posts'] . ' published post' . ($a['posts'] === 1 ? '' : 's') . ' from ' . pm_h(date('M Y', strtotime($a['from'] . '-01'))) . ' to ' . pm_h(date('M Y', strtotime($a['to'] . '-01')))
        . ' were archived to keep this screen fast. Their numbers are kept: ' . number_format((int)$a['eng']) . ' engagements in all' . ($a['avg'] !== null ? ', ' . $a['avg'] . ' per measured post' : '') . '.</p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="posts_archive_restore"><button class="btn small" data-confirm="Put all archived posts back in the live list?">Restore them</button></form></div>';
}

function pm_do_posts_archive_restore(string $vb): array
{
    $n = pm_posts_archive_restore($vb);
    return ['msg' => $n ? "$n archived post(s) are back in the live list." : 'Nothing was archived.', 'kind' => 'ok', 'to' => 'social&view=results'];
}