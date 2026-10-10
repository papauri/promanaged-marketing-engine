<?php
/**
 * Outbound engine: approve-and-drip sending, dedupe keys, email verification, warm-up cap, bounce breaker,
 * sender-domain checks, public web-form helpers (token, rate limit, SSRF guard, web leads).
 * Safe to include from index.php, run_agents.php, send_due.php and enquire.php.
 */
require_once __DIR__ . '/store.php';
if (!function_exists('pm_leads')) {
    require_once __DIR__ . '/agents.php';
}

/* ---------------- small helpers ---------------- */

/** Now, or PM_TEST_NOW (tests only) so the working-hours logic can be exercised. */
function pm_now(): int
{
    $t = getenv('PM_TEST_NOW');
    return ($t !== false && $t !== '' && ($x = strtotime($t)) !== false) ? $x : time();
}

/** Mon-Fri 08:00-16:30 Africa/Blantyre. */
function pm_send_window(?int $ts = null): bool
{
    $ts ??= pm_now();
    $m = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    return (int)date('N', $ts) <= 5 && $m >= 480 && $m <= 990;
}

function pm_ob_read(string $name): array
{
    $f = PM_DATA . "/$name.json";
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

/** Read-modify-write one of our small state files under a lock. */
function pm_ob_update(string $name, callable $fn): array
{
    $lock = @fopen(PM_DATA . '/outbound.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    $d = $fn(pm_ob_read($name));
    pm_save($name, $d);
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $d;
}

/* ---------------- Dedupe keys ---------------- */

const PM_SHARED_HOSTS = ['facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'tiktok.com', 'twitter.com', 'x.com', 'youtube.com', 'wa.me', 'linktr.ee', 'google.com', 'goo.gl', 'g.page'];

/** Website -> comparable key: host without www; for social/shared hosts the page path too (so two Facebook pages are not "the same site"). */
function pm_host_key(string $url): string
{
    $u = strtolower(trim($url));
    if ($u === '') {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#', $u)) {
        $u = 'http://' . $u;
    }
    $p = parse_url($u);
    $h = preg_replace('/^(www|m|web|mobile)\./', '', (string)($p['host'] ?? ''));
    if ($h === '' || !str_contains($h, '.')) {
        return '';
    }
    if (in_array($h, PM_SHARED_HOSTS, true)) {
        $seg = array_values(array_filter(explode('/', (string)($p['path'] ?? ''))));
        $path = in_array($seg[0] ?? '', ['pages', 'people', 'groups', 'p'], true) ? implode('/', array_slice($seg, 0, 3)) : ($seg[0] ?? '');
        parse_str((string)($p['query'] ?? ''), $q);
        $path .= isset($q['id']) ? '?id=' . $q['id'] : '';
        return $path === '' ? '' : "$h/$path";
    }
    return $h;
}

/** Everything that identifies the same business: website host, last 9 digits of every phone/WhatsApp, lower-case email. */
function pm_lead_keys(array $l): array
{
    $k = [];
    if (($h = pm_host_key((string)($l['website'] ?? ''))) !== '') {
        $k[] = "w:$h";
    }
    foreach (['phone', 'whatsapp'] as $f) {
        $raw = $l[$f] ?? '';
        foreach (preg_split('/[\/,;|\n]| or | and /i', is_scalar($raw) ? (string)$raw : '') as $part) {
            $n = preg_replace('/\D+/', '', $part);
            if (strlen($n) >= 9 && !preg_match('/^(\d)\1+$/', $n)) {
                $k[] = 'p:' . substr($n, -9);
            }
        }
    }
    $e = strtolower(trim((string)($l['email'] ?? '')));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
        $k[] = "e:$e";
    }
    return array_values(array_unique($k));
}

/** Id of an existing lead of the same brand sharing any key with $cand, else null. Names alone never match. */
function pm_lead_find_dupe(array $leads, array $cand, string $brand): ?string
{
    $brand = pm_brand_norm($brand);
    $ck = pm_lead_keys($cand);
    if (!$ck) {
        return null;
    }
    foreach ($leads as $k => $o) {
        if (($o['brand'] ?? 'promanaged') === $brand && array_intersect($ck, pm_lead_keys($o))) {
            return (string)($o['id'] ?? $k);
        }
    }
    return null;
}

/**
 * Name of another lead (any brand; leads of both brands share one file) at the same company that was emailed or WhatsApped
 * within $days, else null. Same company = any shared key, or the same non-free email domain.
 */
function pm_company_contacted_with(array $all, array $lead, int $days = 7): ?string
{
    $keys = pm_lead_keys($lead);
    $dom = pm_email_domain((string)($lead['email'] ?? ''));
    $dom = in_array($dom, PM_FREE_MAIL, true) ? '' : $dom;
    $cut = time() - $days * 86400;
    $myId = (string)($lead['id'] ?? '');
    foreach ($all as $k => $o) {
        if ($myId !== '' && (string)($o['id'] ?? $k) === $myId && ($o['brand'] ?? 'promanaged') === ($lead['brand'] ?? 'promanaged')) {
            continue;
        }
        $same = ($keys && array_intersect($keys, pm_lead_keys($o))) || ($dom !== '' && pm_email_domain((string)($o['email'] ?? '')) === $dom);
        if (!$same) {
            continue;
        }
        foreach (array_merge((array)($o['sent'] ?? []), (array)($o['wa_sent'] ?? [])) as $at) {
            if (strtotime((string)$at) > $cut) {
                return (string)($o['name'] ?? 'another lead');
            }
        }
    }
    return null;
}

function pm_company_contacted_recently(array $leadsAllBrands, array $lead, int $days = 7): bool
{
    return pm_company_contacted_with($leadsAllBrands, $lead, $days) !== null;
}

/* ---------------- Email verification ---------------- */

const PM_MAIL_TYPOS = ['gmial.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmal.com' => 'gmail.com',
    'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmail.comm' => 'gmail.com', 'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com',
    'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com'];
const PM_MAIL_DISPOSABLE = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com',
    'getnada.com', 'dispostable.com', 'throwawaymail.com', 'maildrop.cc', 'fakeinbox.com', 'example.com', 'example.org', 'test.com', 'invalid.com'];

function pm_dns_up(): bool
{
    static $up = null;
    return $up ??= (bool)@checkdnsrr('google.com.', 'A');
}

/** Does the domain accept mail (MX, else A)? Cached 7 days (1 day when no); null when our own DNS looks down. */
function pm_mail_domain_ok(string $domain): ?bool
{
    $c = pm_ob_read('email_verify');
    $hit = $c['dns'][$domain] ?? null;
    if ($hit && time() - (int)$hit[1] < ($hit[0] ? 7 : 1) * 86400) {
        return (bool)$hit[0];
    }
    $ok = @checkdnsrr($domain . '.', 'MX') || @checkdnsrr($domain . '.', 'A') || @checkdnsrr($domain . '.', 'AAAA');
    if (!$ok && !pm_dns_up()) {
        return null;
    }
    pm_ob_update('email_verify', function ($d) use ($domain, $ok) {
        $d['dns'][$domain] = [$ok, time()];
        return $d;
    });
    return $ok;
}

/** Emails on the business's own site (cached 7 days). Needs P3's pm_site_check()['emails']; missing means none. */
function pm_site_emails(string $website): array
{
    $h = pm_host_key($website);
    if ($h === '' || !function_exists('pm_site_check')) {
        return [];
    }
    $c = pm_ob_read('email_verify');
    if (isset($c['site'][$h]) && time() - (int)$c['site'][$h][1] < 7 * 86400) {
        return (array)$c['site'][$h][0];
    }
    try {
        $r = pm_site_check(preg_match('#^https?://#i', $website) ? $website : 'http://' . $website);
    } catch (Throwable) {
        return [];
    }
    $em = array_values(array_unique(array_map('strtolower', array_filter((array)($r['emails'] ?? []), 'is_string'))));
    pm_ob_update('email_verify', function ($d) use ($h, $em) {
        $d['site'][$h] = [$em, time()];
        return $d;
    });
    return $em;
}

/** ['ok','src'=>'on_site|domain_match|unverified','why']. ok=false means do not send (typo, throwaway, no mail server). $fetch=false never opens the website. */
function pm_verify_email(string $email, string $website = '', bool $fetch = true): array
{
    $email = strtolower(trim($email));
    $no = fn(string $why) => ['ok' => false, 'src' => 'unverified', 'why' => $why];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen(strstr($email, '@', true)) > 64) {
        return $no('Not a valid email address.');
    }
    $dom = pm_email_domain($email);
    if (isset(PM_MAIL_TYPOS[$dom])) {
        return $no("The domain $dom looks like a typo of " . PM_MAIL_TYPOS[$dom] . '.');
    }
    if (in_array($dom, PM_MAIL_DISPOSABLE, true)) {
        return $no("$dom is a throwaway or example domain.");
    }
    $mx = pm_mail_domain_ok($dom);
    if ($mx === false) {
        return $no("$dom has no mail server, so mail would bounce.");
    }
    $wh = pm_host_key($website);
    if ($wh !== '' && ($wh === $dom || str_ends_with($wh, ".$dom") || str_ends_with($dom, ".$wh"))) {
        return ['ok' => true, 'src' => 'domain_match', 'why' => 'The address is on the business\'s own domain.'];
    }
    if ($fetch && $wh !== '' && in_array($email, pm_site_emails($website), true)) {
        return ['ok' => true, 'src' => 'on_site', 'why' => 'The address is published on the business\'s website.'];
    }
    return ['ok' => true, 'src' => 'unverified', 'why' => $mx === null ? 'Could not check DNS from here.' : 'The domain accepts mail, but the address is not confirmed by the business\'s own site.'];
}

/* ---------------- Warm-up cap, bounce breaker, sender-domain check ---------------- */

/** Daily cap that ramps with the age of the mailbox's sending: 3 on day 0, +2 a week, never above the configured send_cap. Then nudged by the trailing bounce rate (adaptive). */
function pm_effective_send_cap(array $cfg): int
{
    $cap = (int)($cfg['send_cap'] ?? 10);
    $st = pm_ob_read('send_stats');
    $fs = (string)($st['brands'][pm_brand()]['first_send'] ?? '');
    $days = $fs === '' ? 0 : max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($fs)) / 86400));
    $base = max(0, min($cap, 3 + 2 * intdiv($days, 7)));
    if (function_exists('pm_adaptive_send_cap')) { // MARKETING.md MG-G02: bounce-aware drift within the ceiling
        return pm_adaptive_send_cap($base, pm_brand());
    }
    return $base;
}

function pm_send_stats_mark(): void
{
    pm_ob_update('send_stats', function ($d) {
        $b = pm_brand();
        $d['brands'][$b]['first_send'] ??= date('Y-m-d');
        $d['first_send'] ??= date('Y-m-d');
        return $d;
    });
}

/** Owner has fixed the bad addresses: forget bounces before now. */
function pm_send_breaker_reset(): void
{
    pm_ob_update('send_stats', function ($d) {
        $d['breaker_reset'] = time();
        return $d;
    });
}

/** Among the last 20 sends (last 14 days, after any reset) > 5% bounced (needs 10+ sends): sending is blocked. */
function pm_send_breaker(?array $leads = null): array
{
    $leads ??= pm_load('leads', fn() => []);
    $from = max(time() - 14 * 86400, (int)(pm_ob_read('send_stats')['breaker_reset'] ?? 0));
    $ev = [];
    foreach ($leads as $k => $l) {
        $ts = array_values(array_filter(array_map('strtotime', array_map('strval', (array)($l['sent'] ?? [])))));
        if (!$ts) {
            continue;
        }
        $bi = -1;
        if (!empty($l['bounced_at']) && ($bt = strtotime((string)$l['bounced_at']))) {
            foreach ($ts as $i => $t) { // the bounce belongs to the last send before it
                if ($t <= $bt + 60) {
                    $bi = $i;
                }
            }
            $bi = $bi < 0 ? count($ts) - 1 : $bi;
        }
        foreach ($ts as $i => $t) {
            if ($t >= $from) {
                $ev[] = [$t, $i === $bi];
            }
        }
    }
    usort($ev, fn($a, $b) => $b[0] <=> $a[0]);
    $ev = array_slice($ev, 0, 20);
    $n = count($ev);
    $bad = count(array_filter($ev, fn($e) => $e[1]));
    if ($n >= 10 && $bad / $n > 0.05) {
        return ['blocked' => true, 'why' => "Sending is paused: $bad of the last $n emails bounced (" . round(100 * $bad / $n) . '%). Fix or remove the bad addresses; the pause lifts when bounces drop under 5% or after 14 days.'];
    }
    return ['blocked' => false, 'why' => $n < 10 ? "Watching bounces ($n sends so far)." : "$bad of the last $n emails bounced."];
}

/** SPF, DMARC and DKIM for the From domain, with plain-language advice. Cached 6 hours. */
function pm_email_auth_check(string $domain, bool $fresh = false): array
{
    $domain = strtolower(trim($domain));
    $out = ['domain' => $domain, 'spf' => ['status' => 'missing', 'detail' => ''], 'dmarc' => ['status' => 'missing', 'detail' => ''], 'dkim' => ['status' => 'missing', 'detail' => ''], 'advice' => [], 'checked' => false];
    if ($domain === '' || !str_contains($domain, '.')) {
        $out['advice'][] = 'Set a From address on your own domain (Settings > Email sending).';
        return $out;
    }
    if (in_array($domain, PM_FREE_MAIL, true)) {
        $out['advice'][] = "$domain is a free mailbox: you cannot set SPF/DKIM there. Send from an address on your own domain.";
        return $out;
    }
    $c = pm_ob_read('email_verify');
    if (!$fresh && isset($c['auth'][$domain]) && time() - (int)$c['auth'][$domain]['at'] < 6 * 3600) {
        return $c['auth'][$domain]['r'];
    }
    if (!pm_dns_up()) {
        $out['advice'][] = 'Could not look up DNS from this computer just now. Try again later.';
        return $out;
    }
    $out['checked'] = true;
    $txt = function (string $name, int $type = DNS_TXT) {
        $r = @dns_get_record($name, $type);
        $o = [];
        foreach (is_array($r) ? $r : [] as $x) {
            $o[] = (string)($x['txt'] ?? $x['target'] ?? (isset($x['entries']) ? implode('', $x['entries']) : ''));
        }
        return $o;
    };
    $spf = array_values(array_filter($txt($domain), fn($t) => stripos($t, 'v=spf1') === 0));
    if (count($spf) > 1) {
        $out['spf'] = ['status' => 'weak', 'detail' => 'More than one SPF record'];
        $out['advice'][] = 'Your domain has more than one SPF record, which makes SPF fail. Merge them into one.';
    } elseif ($spf) {
        $weak = preg_match('/\+all|\?all/i', $spf[0]) || !preg_match('/[~-]all/i', $spf[0]);
        $out['spf'] = ['status' => $weak ? 'weak' : 'ok', 'detail' => $spf[0]];
        $weak && $out['advice'][] = 'Your SPF record should end in ~all or -all so forged mail is rejected.';
    } else {
        $out['advice'][] = 'No SPF record: receiving servers cannot tell your mail is genuine and may send it to spam. Ask your email host for the SPF record to add.';
    }
    $dm = array_values(array_filter($txt('_dmarc.' . $domain), fn($t) => stripos($t, 'v=DMARC1') === 0));
    if ($dm) {
        $none = preg_match('/p\s*=\s*none/i', $dm[0]);
        $out['dmarc'] = ['status' => $none ? 'weak' : 'ok', 'detail' => $dm[0]];
        $none && $out['advice'][] = 'DMARC is in monitor-only mode (p=none). That is fine to start; move to quarantine once SPF and DKIM pass.';
    } else {
        $out['advice'][] = 'No DMARC record. Add one (start with v=DMARC1; p=none; rua=mailto:you@yourdomain) so Gmail and Yahoo accept your mail reliably.';
    }
    foreach (['default', 'google', 'selector1', 'selector2', 'k1'] as $sel) {
        $r = array_merge($txt("$sel._domainkey.$domain"), $txt("$sel._domainkey.$domain", DNS_CNAME));
        if (array_filter($r, fn($t) => $t !== '')) {
            $out['dkim'] = ['status' => 'ok', 'detail' => "selector $sel"];
            break;
        }
    }
    if ($out['dkim']['status'] === 'missing') {
        $out['advice'][] = 'No DKIM key found under the common selector names. If your host uses another selector this may be a false alarm; otherwise ask your email host to switch DKIM signing on.';
    }
    pm_ob_update('email_verify', function ($d) use ($domain, $out) {
        $d['auth'][$domain] = ['at' => time(), 'r' => $out];
        return $d;
    });
    return $out;
}

/* ---------------- Approve and send ---------------- */

function pm_ob_res(bool $ok, string $msg, string $kind): array
{
    return ['ok' => $ok, 'msg' => $msg, 'kind' => $kind]; // kind: ok | cap | breaker | lead (this lead cannot be sent) | smtp
}

/** Send one lead's first email (or follow-up) after every safety check. $mailer(settings, message) mimics pm_mail; null = pm_mail. */
function pm_send_first(array &$leads, string $id, string $which, ?callable $mailer = null): array
{
    if (!isset($leads[$id])) {
        return pm_ob_res(false, 'That lead was not found.', 'lead');
    }
    $prev = pm_brand();
    pm_brand_set((string)($leads[$id]['brand'] ?? 'promanaged')); // act as the brand this lead was approached by
    try {
        $key = in_array($which, ['followup', 'followup_draft'], true) ? 'followup_draft' : (in_array($which, ['winback', 'winback_draft'], true) ? 'winback_draft' : 'drafts');
        return pm_send_first_run($leads, $id, $key, $mailer);
    } finally {
        pm_brand_set($prev);
    }
}

function pm_send_first_run(array &$leads, string $id, string $which, ?callable $mailer): array
{
    $lead = $leads[$id];
    $settings = pm_settings();
    $cfg = pm_agents_config();
    $d = (array)($lead[$which] ?? []);
    $no = fn(string $m, string $kind = 'lead') => pm_ob_res(false, $m, $kind);
    if (($lead['status'] ?? '') === 'optout') {
        return $no(($lead['name'] ?? 'This business') . ' asked not to be contacted.');
    }
    if (($b = pm_send_breaker())['blocked']) {
        return $no($b['why'], 'breaker');
    }
    $cap = pm_effective_send_cap($cfg);
    if (pm_sent_today($leads) >= $cap) {
        return $no("You have reached today's limit of $cap emails (it rises each week as the mailbox warms up; the most you allowed is {$cfg['send_cap']}).", 'cap');
    }
    $email = trim((string)($lead['email'] ?? ''));
    if (empty($d['email_body']) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $no('This lead needs a valid email address and a draft first.');
    }
    if (!empty($lead['email_bad']) && ($lead['email_bad'] === true || strcasecmp((string)$lead['email_bad'], $email) === 0)) {
        return $no('That address bounced before. Find a different email address first.');
    }
    if (pm_suppressed($leads, $email, $id)) {
        return $no('That address or company asked not to be contacted.');
    }
    if ($which === 'drafts' && !empty($lead['sent'])) {
        return $no('You have already sent this business a first email. Use a follow-up instead.');
    }
    if (!($v = pm_verify_email($email, (string)($lead['website'] ?? ''), false))['ok']) {
        return $no('Not sent: ' . $v['why']);
    }
    if (($who = pm_company_contacted_with($leads, $lead, 7)) !== null) {
        return $no("This company was already contacted this week ($who). Wait a week so one company is not contacted twice.");
    }
    if ($problems = pm_outreach_lint((string)($d['email_subject'] ?? ''), (string)$d['email_body'], $which === 'drafts')) {
        return $no('Not sent. ' . implode(' ', $problems) . ' Edit the draft and try again.');
    }
    if ($mailer === null) {
        if (!function_exists('pm_mail') && is_file(__DIR__ . '/mail.php')) {
            require_once __DIR__ . '/mail.php';
        }
        $mailer = 'pm_mail';
    }
    $fromAddr = ($settings['smtp']['from_email'] ?? '') ?: ($settings['email'] ?? '');
    $res = $mailer($settings, ['to' => $email, 'subject' => $d['email_subject'], 'body' => $d['email_body'] . pm_outreach_footer($settings),
        'headers' => ['List-Unsubscribe' => '<mailto:' . $fromAddr . '?subject=STOP>', 'Auto-Submitted' => 'no'], 'link' => function_exists('pm_link_card') ? pm_link_card() : null]);
    [$ok, $why, $mid] = array_pad((array)$res, 3, '');
    if (!$ok) {
        return $no('Could not send: ' . $why, 'smtp');
    }
    $L = &$leads[$id];
    if (empty($L['owner']) && ($GLOBALS['PM_WHO'] ?? '') !== '') {
        $L['owner'] = $GLOBALS['PM_WHO']; // whoever sends it owns the follow-up
    }
    $now = date('Y-m-d H:i');
    $L['sent'][] = $now;
    $L['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => $d['email_body']] + ($mid ? ['mid' => $mid] : []);
    if ($mid) {
        $L['msg_ids'] = array_slice(array_merge((array)($L['msg_ids'] ?? []), [(string)$mid]), -20);
    }
    $L['last_contacted'] = $L['last_out'] = $now;
    $L['status'] = 'contacted';
    unset($L['approved_at'], $L['send_fail_at']);
    if ($which === 'followup_draft') {
        if (function_exists('pm_followup_log_sent')) { // C2-A03: remember the subject arm so the follow-up experiment can score it
            pm_followup_log_sent($L, $d, $now);
        }
        $L['followups'] = (int)($L['followups'] ?? 0) + 1;
        unset($L['followup_draft'], $L['followup_approved']);
    }
    if ($which === 'winback_draft') { // C2-A01: one fresh try after a no; no automatic follow-ups after it
        $L['winback_sent_at'] = $now;
        $L['followups'] = (int)$cfg['max_followups'];
        unset($L['winback_draft'], $L['snooze_until']);
    }
    pm_lead_note($L, ($which === 'drafts' ? 'First email' : ($which === 'winback_draft' ? 'Win-back email' : 'Follow-up')) . ' sent to ' . $email);
    unset($L);
    pm_send_stats_mark();
    pm_leads_save($leads);
    return pm_ob_res(true, 'Sent to ' . ($lead['name'] ?? 'the lead') . '.', 'ok');
}

/** Owner approves drafts for the slow drip: only drafted/qualified leads with a valid, verified address and a clean draft. Returns how many were approved. */
function pm_approve_for_send(array &$leads, array $ids): int
{
    $n = 0;
    foreach (array_unique($ids) as $id) {
        $l = $leads[$id] ?? null;
        $d = (array)($l['drafts'] ?? []);
        if (!$l || !in_array($l['status'] ?? '', ['drafted', 'qualified'], true) || !empty($l['approved_at']) || !empty($l['sent']) || empty($d['email_body'])) {
            continue;
        }
        $email = trim((string)($l['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || pm_suppressed($leads, $email, (string)$id)
            || (!empty($l['email_bad']) && ($l['email_bad'] === true || strcasecmp((string)$l['email_bad'], $email) === 0))
            || !pm_verify_email($email, (string)($l['website'] ?? ''), false)['ok']
            || pm_outreach_lint((string)($d['email_subject'] ?? ''), (string)$d['email_body'], true)) {
            continue;
        }
        $leads[$id]['approved_at'] = date('Y-m-d H:i:s');
        pm_lead_note($leads[$id], 'Approved for sending (goes out slowly during working hours)');
        $n++;
    }
    if ($n) {
        pm_leads_save($leads);
    }
    return $n;
}

/** Take leads back out of the send queue. Returns how many. */
function pm_unapprove(array &$leads, array $ids): int
{
    $n = 0;
    foreach ($ids as $id) {
        if (!empty($leads[$id]['approved_at'])) {
            unset($leads[$id]['approved_at']);
            pm_lead_note($leads[$id], 'Taken out of the send queue');
            $n++;
        }
    }
    $n && pm_leads_save($leads);
    return $n;
}

/** Approved leads, best score first (optionally one brand). */
function pm_send_queue(array $leads, ?string $brand = null): array
{
    $q = array_filter($leads, fn($l) => !empty($l['approved_at']) && !in_array($l['status'] ?? '', ['optout', 'won', 'lost'], true)
        && ($brand === null || ($l['brand'] ?? 'promanaged') === $brand));
    uasort($q, fn($a, $b) => [(int)($b['score'] ?? 0), (string)($a['approved_at'])] <=> [(int)($a['score'] ?? 0), (string)($b['approved_at'])]);
    return $q;
}

/** 'sends ~HH:MM' or 'sends next working morning'. $pos = place in the queue (0 = next); $left = sends left under today's cap. */
function pm_approved_eta(array $lead, int $pos = 0, ?int $left = null): string
{
    if (empty($lead['approved_at'])) {
        return '';
    }
    $now = pm_now();
    $morning = 'sends next working morning';
    if (($left !== null && $pos >= $left) || !pm_send_window($now)) {
        return $morning;
    }
    $next = max($now, (int)(pm_ob_read('send_state')['next_send_at'] ?? 0)) + $pos * 900; // the task runs every 15 minutes, one email each
    $t = (int)(ceil($next / 900) * 900);
    return pm_send_window($t) && date('Y-m-d', $t) === date('Y-m-d', $now) ? 'sends ~' . date('H:i', $t) : $morning;
}

/* ---------------- Public web forms: token, rate limit, SSRF guard, web leads ---------------- */

function pm_form_secret(): string
{
    $f = PM_DATA . '/enquire_secret.txt';
    if (!is_file($f) || strlen(trim((string)@file_get_contents($f))) < 32) {
        if (!is_dir(PM_DATA)) {
            mkdir(PM_DATA, 0775, true);
        }
        if (($h = @fopen($f, 'x')) !== false) { // only ever created, never overwritten
            fwrite($h, bin2hex(random_bytes(32)));
            fclose($h);
        }
    }
    return trim((string)@file_get_contents($f));
}

function pm_form_token(string $ctx, ?int $ts = null): string
{
    $ts ??= time();
    return $ts . '.' . hash_hmac('sha256', "$ts|$ctx", pm_form_secret());
}

/** Valid when signed for this context, at least $min seconds old (bots post instantly) and under $max seconds old. */
function pm_form_token_ok(string $token, string $ctx, int $min = 3, int $max = 7200): bool
{
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
        return false;
    }
    $age = time() - (int)$m[1];
    return $age >= $min && $age <= $max && hash_equals(hash_hmac('sha256', "{$m[1]}|$ctx", pm_form_secret()), $m[2]);
}

/** Visitor IP for rate limits. REMOTE_ADDR, since a forwarded header can be forged unless TRUST_CF_IP=1 is set in .env (site behind Cloudflare). */
function pm_visitor_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ((pm_env()['TRUST_CF_IP'] ?? '') === '1' && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', FILTER_VALIDATE_IP)) {
        $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $ip;
}

/** Sliding-window limit: true and records a hit when under $max in the last $window seconds. Files under data/ratelimit/ are created by this code only. */
function pm_rate_hit(string $bucket, string $key, int $max, int $window = 3600): bool
{
    $dir = PM_DATA . '/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $h = @fopen($dir . '/' . preg_replace('/[^a-z]/', '', $bucket) . '_' . substr(hash('sha256', $key), 0, 24) . '.json', 'c+');
    if (!$h) {
        return false; // cannot count: refuse rather than allow unlimited
    }
    flock($h, LOCK_EX);
    $now = time();
    $hits = array_values(array_filter((array)json_decode((string)stream_get_contents($h), true), fn($t) => is_int($t) && $t > $now - $window));
    $ok = count($hits) < $max;
    if ($ok) {
        $hits[] = $now;
    }
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($hits));
    flock($h, LOCK_UN);
    fclose($h);
    return $ok;
}

/** Resolve a host to its public IPs; [] when it does not resolve or any address is private/reserved. */
function pm_public_ips(string $host): array
{
    $host = trim($host, '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = (array)(@gethostbynamel($host) ?: []);
        foreach ((array)(@dns_get_record($host, DNS_AAAA) ?: []) as $r) {
            $ips[] = (string)($r['ipv6'] ?? '');
        }
        $ips = array_values(array_filter($ips));
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) // also covers ::1, fc00::/7, fe80::/10, ::ffff:x
            || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (ip2long($ip) >> 22) === (ip2long("100.64.0.0") >> 22))) { // carrier-grade NAT
            return [];
        }
        $h = bin2hex((string)@inet_pton($ip));
        if (strlen($h) === 32) { // IPv6: refuse mapped IPv4, loopback/unspecified (::/8), unique-local, link-local, 6to4 and NAT64
            $o = hexdec(substr($h, 0, 2));
            if (str_starts_with($h, '00000000000000000000ffff') || $o === 0 || ($o & 0xfe) === 0xfc || ($o === 0xfe && (hexdec(substr($h, 2, 2)) & 0xc0) === 0x80)
                || str_starts_with($h, '2002') || str_starts_with($h, '0064ff9b')) {
                return [];
            }
        }
    }
    return $ips;
}

/** SSRF guard. ['ok'=>true,'url'=>clean url] or ['ok'=>false,'why'=>...]. http/https only, no credentials, ports 80/443, public IPs only. */
function pm_url_safe(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 300 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return ['ok' => false, 'why' => 'Please enter a website address such as www.example.com.'];
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'http://' . $url;
    }
    $p = parse_url($url);
    if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
        return ['ok' => false, 'why' => 'Only http and https website addresses can be checked.'];
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return ['ok' => false, 'why' => 'Addresses with a username or password cannot be checked.'];
    }
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) {
        return ['ok' => false, 'why' => 'Only normal website addresses (ports 80 and 443) can be checked.'];
    }
    $host = strtolower(rtrim($p['host'], '.'));
    if ($host === 'localhost' || (!str_contains($host, '.') && !str_contains($host, ':'))) {
        return ['ok' => false, 'why' => 'That address is not a public website.'];
    }
    if (!pm_public_ips($host)) {
        return ['ok' => false, 'why' => 'We could not find a public website at that address.'];
    }
    return ['ok' => true, 'url' => $url];
}

/** Follow redirects by hand (max 5), re-checking every hop with pm_url_safe and pinning the checked IP; returns the final safe URL or a refusal. */
function pm_url_safe_final(string $url): array
{
    for ($i = 0; $i < 6; $i++) {
        $r = pm_url_safe($url);
        if (!$r['ok']) {
            return $r;
        }
        $url = $r['url'];
        $p = parse_url($url);
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HEADER => true,
            CURLOPT_RESOLVE => [strtolower($p['host']) . ":$port:" . (pm_public_ips($p['host'])[0] ?? '0.0.0.0')]]);
        $h = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($h) || $code < 300 || $code >= 400 || !preg_match('/^location:\s*(\S+)/im', $h, $m)) {
            return ['ok' => true, 'url' => $url];
        }
        $loc = trim($m[1]);
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $loc)) { // relative redirect
            $loc = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($loc[0] === '/' ? '' : '/') . $loc;
        }
        $url = $loc;
    }
    return ['ok' => false, 'why' => 'That website redirects too many times to check.'];
}

function pm_web_clean(mixed $v, int $max): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string)$v)), 0, $max);
}

/**
 * A lead created by a public web form (enquiry, free site check, Travel Malawi host sign-up). Merges into an existing lead
 * of the same brand when any key matches, else adds one. Returns ['id','merged','lead'].
 * $d: brand, kind (enquiry|check|host), name, business, phone, email, website, message, src, type, city, evidence[].
 * Optional: src_tag (where it came from; src is read as the legacy name), ref (4-character post code), referred_by (lead id), channel (web|whatsapp|facebook|...),
 * audience (host|traveller|unknown), source ('web' by default), status ('replied' by default), label, note, first_touch_at.
 * A traveller is not a business: type "traveller", score 40, no proposal. A Travel Malawi enquiry of unknown kind scores 70, no proposal.
 */
function pm_web_lead(array $d): array
{
    $brand = pm_brand_norm($d['brand'] ?? '');
    $now = date('Y-m-d H:i');
    $leads = pm_leads();
    $biz = pm_web_clean(($d['business'] ?? '') ?: ($d['name'] ?? '') ?: 'Website enquiry', 100);
    $cand = ['name' => $biz, 'website' => (string)($d['website'] ?? ''), 'phone' => (string)($d['phone'] ?? ''), 'whatsapp' => (string)($d['phone'] ?? ''), 'email' => (string)($d['email'] ?? '')];
    $id = pm_lead_find_dupe($leads, $cand, $brand);
    $merged = $id !== null;
    $label = pm_web_clean($d['label'] ?? '', 100) ?: (['enquiry' => 'Asked via website form', 'check' => 'Asked via website form (free website check)', 'host' => 'Host sign-up via website form'][$d['kind'] ?? 'enquiry'] ?? 'Asked via website form');
    $aud = in_array($d['audience'] ?? '', ['host', 'traveller', 'unknown'], true) ? $d['audience'] : ($brand === 'travel' ? (($d['kind'] ?? '') === 'host' ? 'host' : 'unknown') : '');
    $trav = $aud === 'traveller';
    $soft = $trav || $aud === 'unknown'; // not a business to pitch: no proposal, lower score
    $srcTag = pm_web_clean(($d['src_tag'] ?? '') ?: ($d['src'] ?? ''), 40);
    $status = in_array($d['status'] ?? '', ['qualified', 'replied', 'proposal'], true) ? $d['status'] : 'replied';
    $attr = ['source_post' => '', 'source_ref' => pm_web_clean($d['ref'] ?? '', 8), 'source_pillar' => '', 'source_format' => ''];
    if (function_exists('pm_lead_attrib')) {
        $attr = pm_lead_attrib(['brand' => $brand, 'source_ref' => $attr['source_ref']], ($d['message'] ?? '') . ' ' . ($d['business'] ?? '')) + $attr;
    }
    $ev = array_values(array_unique(array_merge([$label], array_map('strval', array_slice((array)($d['evidence'] ?? []), 0, 8)))));
    $msg = pm_web_clean($d['message'] ?? '', 1500);
    $thread = ['dir' => 'in', 'at' => $now, 'text' => ($msg !== '' ? $msg : $label . (!empty($d['name']) ? ' by ' . $d['name'] : ''))];
    if (!$merged) {
        $id = pm_lead_id($biz, (string)($d['city'] ?? ''), $brand);
        if (isset($leads[$id])) { // same normalised name, different contact details: a different business
            $id = substr(md5($id . '|' . $now . '|' . random_int(1, PHP_INT_MAX)), 0, 12);
        }
        $leads[$id] = ['id' => $id, 'brand' => $brand, 'name' => $biz, 'type' => pm_web_clean($d['type'] ?? '', 60) ?: 'business', 'city' => pm_web_clean($d['city'] ?? '', 60), 'address' => '',
            'website' => pm_web_clean($d['website'] ?? '', 200), 'phone' => pm_web_clean($d['phone'] ?? '', 60), 'email' => pm_web_clean($d['email'] ?? '', 120), 'whatsapp' => pm_web_clean($d['phone'] ?? '', 60),
            'contact' => pm_web_clean($d['name'] ?? '', 80), 'contact_title' => '', 'evidence' => $ev, 'need_signals' => [], 'score' => $trav ? 40 : ($soft ? 70 : 85), 'status' => $status, 'notes' => [], 'drafts' => [],
            'sent' => [], 'followups' => 0, 'source' => pm_web_clean($d['source'] ?? '', 20) ?: 'web', 'src' => pm_web_clean($d['src'] ?? '', 40) ?: $srcTag, 'src_tag' => $srcTag, 'want_proposal' => !$soft, 'reason' => $label, 'thread' => [$thread],
            'channel' => pm_web_clean($d['channel'] ?? '', 20) ?: 'web', 'audience' => $aud, 'first_touch_at' => pm_web_clean($d['first_touch_at'] ?? '', 40) ?: date('c'), 'first_reply_at' => '',
            'referred_by' => preg_match('/^[a-f0-9]{12}$/', (string)($d['referred_by'] ?? '')) ? $d['referred_by'] : '',
            'last_reply' => $now, 'created' => $now, 'updated' => $now] + $attr;
        pm_lead_note($leads[$id], $label . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . (($d['note'] ?? '') !== '' ? ' ' . pm_web_clean($d['note'], 300) : ''));
    } else {
        $L = &$leads[$id];
        foreach (['phone' => 60, 'email' => 120, 'website' => 200, 'contact' => 80] as $f => $max) {
            if (trim((string)($L[$f] ?? '')) === '' && ($d[$f === 'contact' ? 'name' : $f] ?? '') !== '') {
                $L[$f] = pm_web_clean($d[$f === 'contact' ? 'name' : $f], $max);
            }
        }
        $L['evidence'] = array_values(array_unique(array_merge((array)($L['evidence'] ?? []), $ev)));
        $L['thread'][] = $thread;
        $L['last_reply'] = $now;
        if (!$soft) {
            $L['score'] = max(85, (int)($L['score'] ?? 0));
            $L['want_proposal'] = true;
        }
        $L['source'] = $L['source'] ?? 'web';
        foreach (['src_tag' => $srcTag, 'source_post' => $attr['source_post'], 'source_ref' => $attr['source_ref'], 'source_pillar' => $attr['source_pillar'], 'source_format' => $attr['source_format']] as $f => $v) {
            if ($v !== '' && trim((string)($L[$f] ?? '')) === '') {
                $L[$f] = $v; // the first touch wins: later enquiries never rewrite where a lead came from
            }
        }
        $L['channel'] = $L['channel'] ?? (pm_web_clean($d['channel'] ?? '', 20) ?: 'web');
        $L['first_touch_at'] = ($L['first_touch_at'] ?? '') ?: date('c');
        if (empty($L['referred_by']) && preg_match('/^[a-f0-9]{12}$/', (string)($d['referred_by'] ?? '')) && $d['referred_by'] !== $id) {
            $L['referred_by'] = $d['referred_by'];
        }
        if ($aud !== '' && empty($L['audience'])) {
            $L['audience'] = $aud;
        }
        if (($L['status'] ?? '') !== 'optout' && ($L['status'] ?? '') !== 'won') {
            $L['status'] = $status; // they asked us: that beats any cold-outreach state
        }
        unset($L['approved_at']); // never send a cold email to someone who just wrote to us
        pm_lead_note($L, $label . ' (matched an existing lead)' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . (($d['note'] ?? '') !== '' ? ' ' . pm_web_clean($d['note'], 300) : ''));
        unset($L);
    }
    pm_leads_save($leads);
    return ['id' => $id, 'merged' => $merged, 'lead' => $leads[$id]];
}

/** Our posts if the social module is loaded, else read from the file (the enquiry page does not load the social code). */
function pm_social_posts_safe(): array
{
    try {
        return function_exists('pm_social_posts') ? pm_social_posts() : pm_load('social_posts', fn() => []);
    } catch (Throwable) {
        return [];
    }
}

/** Tell the owner about a web lead: pm_notify_owner() when P2 provides it, else a plain email to the business address. Never throws. */
function pm_web_notify(array $lead, bool $merged): bool
{
    try {
        pm_brand_set((string)($lead['brand'] ?? 'promanaged'));
        $s = pm_settings();
        $subject = ($merged ? 'Website enquiry (existing lead): ' : 'New website enquiry: ') . preg_replace('/\s+/', ' ', (string)$lead['name']);
        $last = (array)end($lead['thread']);
        $link = pm_app_url() !== '' ? "\n\nOpen it: " . pm_app_url() . '/index.php?tab=agents&brand=' . ($lead['brand'] ?? 'promanaged') . '&lead=' . $lead['id'] : '';
        $from = function_exists('pm_lead_src_tag') ? pm_lead_src_tag($lead) : (is_string($lead['src'] ?? null) ? $lead['src'] : '');
        $by = '';
        if (!empty($lead['referred_by'])) {
            $rl = pm_leads()[$lead['referred_by']] ?? null;
            $by = "\nReferred by: " . ($rl['name'] ?? 'an existing client') . ($rl && !empty($rl['contact']) ? ' (' . $rl['contact'] . ')' : '');
        }
        $post = '';
        if (!empty($lead['source_post'])) {
            $sp = array_values(array_filter(pm_social_posts_safe(), fn($x) => ($x['id'] ?? '') === $lead['source_post']))[0] ?? null;
            $post = $sp ? "\nCame from our post: " . ($sp['headline'] ?? '') . ' (' . ($sp['pillar'] ?? '') . ')' : '';
        }
        $body = "Someone asked for you through the website.\n\nBusiness: {$lead['name']}\nName: " . ($lead['contact'] ?? '') . "\nPhone/WhatsApp: " . ($lead['phone'] ?? '') . "\nEmail: " . ($lead['email'] ?? '')
            . "\nWebsite: " . ($lead['website'] ?? '') . "\nCame from: " . ($from ?: 'direct') . $post . $by . "\n\nWhat they wrote:\n" . ($last['text'] ?? '')
            . ($lead['evidence'] ? "\n\nNotes:\n- " . implode("\n- ", array_map('strval', (array)$lead['evidence'])) : '') . $link;
        if (function_exists('pm_notify_owner')) {
            try {
                pm_notify_owner($subject, $body);
                return true;
            } catch (Throwable) { // different signature: fall back to plain email
            }
        }
        if (!function_exists('pm_mail') && is_file(__DIR__ . '/mail.php')) {
            require_once __DIR__ . '/mail.php';
        }
        $to = trim((string)($s['email'] ?? ''));
        return $to !== '' && pm_mail($s, ['to' => $to, 'subject' => $subject, 'body' => $body, 'bcc_self' => false])[0];
    } catch (Throwable) {
        return false;
    }
}

/** Short honest receipt to the visitor: no promises, no prices. Once a day per lead. */
function pm_web_ack(array $lead): bool
{
    try {
        $email = trim((string)($lead['email'] ?? ''));
        if ($email === '' || ($lead['status'] ?? '') === 'optout' || !pm_verify_email($email, '', false)['ok']) {
            return false;
        }
        if (!empty($lead['ack_at']) && strtotime((string)$lead['ack_at']) > time() - 86400) {
            return false;
        }
        pm_brand_set((string)($lead['brand'] ?? 'promanaged'));
        $s = pm_settings();
        if (!function_exists('pm_mail') && is_file(__DIR__ . '/mail.php')) {
            require_once __DIR__ . '/mail.php';
        }
        $first = trim(explode(' ', trim((string)($lead['contact'] ?? '')))[0] ?? '');
        $first = preg_replace('/[^\p{L}\' -]/u', '', $first);
        $r = pm_mail($s, ['to' => $email, 'subject' => 'We received your message - ' . $s['company_name'], 'bcc_self' => false,
            'body' => ($first !== '' ? "Hello $first,\n\n" : "Hello,\n\n") . "Thank you for getting in touch with {$s['company_name']}. We have your message and a member of our team will read it and reply to you personally.\n\n"
                . "If you did not send this message, please ignore this email; we will not contact you again.\n\n{$s['company_name']}\n" . implode('  ·  ', array_filter([$s['phone'] ?? '', $s['email'] ?? ''])),
            'headers' => ['Auto-Submitted' => 'auto-replied']]);
        if ($r[0]) {
            $leads = pm_leads();
            if (isset($leads[$lead['id']])) {
                $leads[$lead['id']]['ack_at'] = date('Y-m-d H:i');
                pm_leads_save($leads);
            }
        }
        return (bool)$r[0];
    } catch (Throwable) {
        return false;
    }
}

foreach (['sx_replies', 'sx_inbound', 'sx_attrib', 'sx_links', 'sx_magnet'] as $m) { // inbound helpers the public enquiry page needs
    if (is_file(__DIR__ . "/$m.php")) {
        require_once __DIR__ . "/$m.php";
    }
}
if (is_file(__DIR__ . '/view_outbound.php')) {
    require_once __DIR__ . '/view_outbound.php'; // echo-functions for the Agents and Settings screens
}
