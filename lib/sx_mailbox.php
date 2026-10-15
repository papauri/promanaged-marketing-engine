<?php
/**
 * The mailbox desk: the head of Replies reads each business's mailbox (the Inbox and the Sent folder of the last 30 days, read-only: nothing is marked read,
 * moved or deleted), works out what sort of communication each email is and links it to the lead it belongs to, so we never pursue a business twice.
 *   - Code first, free: our own copies, bounces, automatic replies, newsletters and notifications; a lead matched by its address, a colleague's address we know,
 *     its company domain (the email's or the website's) or the thread.
 *   - The cheap AI only for the human emails that match nobody: an enquiry, a client, a supplier, a partner, admin, personal or spam, in one line, with the
 *     business it names (which suggests the lead).
 *   - The safety it exists for: a lead we have NOT written to yet that writes to us, or that someone emailed by hand outside the app (its address or its company's
 *     domain is in the Sent folder), is taken out of cold outreach at once: marked contacted (with the date) or waiting for our answer, its queued email unqueued,
 *     the Writers and the Director told. A business is never cold-emailed when we are already talking to it.
 * Replies from leads we wrote to are still handled by the inbox check (lib/engage.php): the desk only links them for the picture.
 * The owner sees it all on Sales > Mailbox and links, turns into a lead or puts aside what code could not place.
 * Data: data/mailbox.json {brand: {items: {key: item}, at, error, uids: {box: {in: [..], out: [..]}}}}.
 */

const PM_MB_KINDS = ['lead' => 'Lead', 'enquiry' => 'New enquiry', 'client' => 'Client', 'partner' => 'Partner', 'supplier' => 'Supplier', 'admin' => 'Admin and accounts',
    'personal' => 'Personal', 'own' => 'Our own copy', 'newsletter' => 'Newsletter', 'notification' => 'Notification', 'auto' => 'Automatic reply', 'bounce' => 'Bounce', 'spam' => 'Spam', 'sent' => 'Sent, not a lead', 'other' => 'Other'];
/** Shared email providers: a domain here says nothing about the company. */
const PM_MB_SHARED = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com',
    'proton.me', 'protonmail.com', 'mail.com', 'gmx.com', 'zoho.com', 'yandex.com', 'malawi.net', 'sdnp.org.mw', 'globemw.net', 'africa-online.net', 'iwayafrica.com', 'skyband.mw', 'mtl.mw',
    // platforms a lead's "website" is often a page on, and that send their own notifications: never a company's own domain
    'facebook.com', 'facebookmail.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com', 'youtube.com', 'google.com', 'goo.gl', 'g.page', 'business.site', 'wa.me', 'whatsapp.com',
    'booking.com', 'airbnb.com', 'tripadvisor.com', 'tripadvisor.co.uk', 'expedia.com', 'hotels.com', 'agoda.com', 'safaribookings.com', 'lonelyplanet.com', 'wix.com', 'wixsite.com', 'wordpress.com',
    'blogspot.com', 'weebly.com', 'squarespace.com', 'godaddysites.com', 'linktr.ee', 'canva.site', 'github.io', 'mailchimp.com', 'sendgrid.net', 'amazonses.com'];

function pm_mb_state(string $brand): array
{
    return (array)(pm_load('mailbox', fn() => [])[$brand] ?? []) + ['items' => [], 'at' => '', 'error' => '', 'uids' => []];
}

function pm_mb_update(string $brand, callable $fn): void
{
    pm_update('mailbox', function (array $all) use ($brand, $fn) {
        $all[$brand] = $fn((array)($all[$brand] ?? []) + ['items' => [], 'at' => '', 'error' => '', 'uids' => []]);
        return $all;
    }, fn() => []);
}

/** A company domain from an email or a website ('' for a shared provider or nothing). */
function pm_mb_domain(string $s): string
{
    $s = strtolower(trim($s));
    $d = str_contains($s, '@') ? substr((string)strrchr($s, '@'), 1) : (string)parse_url(preg_match('#^https?://#', $s) ? $s : 'http://' . $s, PHP_URL_HOST);
    $d = (string)preg_replace('/^www\./', '', $d);
    foreach (PM_MB_SHARED as $sh) { // a subdomain of a platform (mylodge.wixsite.com, business.facebook.com) is the platform too
        if (str_ends_with($d, '.' . $sh)) {
            return '';
        }
    }
    return $d === '' || in_array($d, PM_MB_SHARED, true) || in_array($d, defined('PM_FREE_MAIL') ? PM_FREE_MAIL : [], true) || !str_contains($d, '.') ? '' : $d;
}

/** Every way we know a lead by: address => id, company domain => id (one business's leads; the most recently touched wins a shared domain). */
function pm_mb_index(array $leads, string $brand): array
{
    $byMail = $byDom = [];
    uasort($leads, fn($a, $b) => strcmp((string)($a['updated'] ?? ''), (string)($b['updated'] ?? '')));
    foreach ($leads as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || !empty($l['listen'])) {
            continue;
        }
        foreach (array_merge([(string)($l['email'] ?? '')], array_map('strval', (array)($l['alt_emails'] ?? []))) as $em) {
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $byMail[strtolower($em)] = (string)$id;
                ($d = pm_mb_domain($em)) !== '' && $byDom[$d] = (string)$id;
            }
        }
        ($d = pm_mb_domain((string)($l['website'] ?? ''))) !== '' && $byDom[$d] = (string)$id;
    }
    foreach (function_exists('pm_ml_rows') ? pm_ml_rows() : [] as $r) { // the app's own send log: an address we emailed for a lead, even if the lead's address changed since
        $to = strtolower(trim((string)($r['to'] ?? '')));
        $lid = (string)($r['lead'] ?? '');
        if ($lid !== '' && isset($leads[$lid]) && ($r['brand'] ?? '') === $brand && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $byMail[$to] ??= $lid;
        }
    }
    return [$byMail, $byDom];
}

/** The lead an address belongs to: [id, how] ('address' | 'company domain') or [null, '']. */
function pm_mb_match(string $addr, array $byMail, array $byDom): array
{
    $addr = strtolower(trim($addr));
    if (isset($byMail[$addr])) {
        return [$byMail[$addr], 'address'];
    }
    $d = pm_mb_domain($addr);
    return $d !== '' && isset($byDom[$d]) ? [$byDom[$d], 'company domain'] : [null, ''];
}

/** An open, logged-in, read-only session on a business's mailbox: [stream, '', account] or [null, why, '']. */
function pm_mb_open(string $brand): array
{
    $c = pm_imap_settings($brand);
    if ($c['host'] === '' || $c['user'] === '' || $c['pass'] === '') {
        return [null, 'no mailbox login for this business (Operations > Email)', ''];
    }
    $c['hosts'] = function_exists('pm_imap_hosts_for') ? pm_imap_hosts_for((string)$c['host']) : [$c['host']];
    [$fp, , $es] = pm_imap_open($c, 15);
    if (!$fp) {
        return [null, 'could not reach the mail server: ' . $es, ''];
    }
    stream_set_timeout($fp, 30);
    fgets($fp, 4096);
    $q = fn($v) => '"' . addcslashes($v, '"\\') . '"';
    if (!str_contains(pm_imap_cmd($fp, 'M1', 'LOGIN ' . $q($c['user']) . ' ' . $q($c['pass'])), 'M1 OK')) {
        fclose($fp);
        return [null, 'the mail server refused the login', ''];
    }
    return [$fp, '', strtolower($c['host'] . '|' . $c['user'])];
}

/** The UIDs of a folder since $days days ago, opened read-only: [uidvalidity, [uid...]]. */
function pm_mb_uids($fp, string $folder, int $days): array
{
    $sel = pm_imap_cmd($fp, 'X1', 'EXAMINE "' . addcslashes($folder, '"\\') . '"');
    if (!str_contains($sel, 'X1 OK')) {
        return ['', []];
    }
    $v = preg_match('/UIDVALIDITY (\d+)/i', $sel, $vm) ? $vm[1] : '0';
    preg_match('/\* SEARCH([\d ]*)/', pm_imap_cmd($fp, 'X2', 'UID SEARCH SINCE ' . date('d-M-Y', strtotime("-$days days"))), $m);
    return [$v, array_values(array_filter(array_map('intval', explode(' ', trim($m[1] ?? '')))))];
}

/**
 * Reads the mailbox of one business: new Inbox and Sent emails of the last 30 days (at most $max of each a time), classified and linked, the safety applied.
 * Returns ['in' => n, 'out' => n, 'linked' => n, 'outside' => [lead names taken out of cold outreach], 'error' => ''].
 */
function pm_mb_read(string $brand, int $max = 120): array
{
    $res = ['in' => 0, 'out' => 0, 'linked' => 0, 'outside' => [], 'error' => ''];
    if (!function_exists('pm_imap_settings')) {
        require_once __DIR__ . '/engage.php';
    }
    [$fp, $why, $acct] = pm_mb_open($brand);
    if (!$fp) {
        pm_mb_update($brand, fn($s) => ['error' => $why, 'at' => date('Y-m-d H:i')] + $s);
        return ['error' => $why] + $res;
    }
    $st = pm_mb_state($brand);
    $own = function_exists('pm_own_addresses') ? pm_own_addresses() : [];
    $new = [];
    $uidsDone = (array)($st['uids'][$acct] ?? []);
    try {
        // the Inbox: what people sent us
        [$v, $uids] = pm_mb_uids($fp, 'INBOX', 30);
        $doneIn = array_map('intval', (array)($uidsDone['in'][$v] ?? []));
        foreach (array_slice(array_values(array_diff($uids, $doneIn)), -$max) as $n => $uid) {
            $raw = pm_imap_cmd($fp, 'I' . $n, "UID FETCH $uid (BODY.PEEK[]<0.40000>)");
            $a = strpos($raw, "\n");
            $msg = (string)preg_replace('/\r?\n\)\r?\nI' . $n . ' OK.*$/s', '', substr($raw, $a === false ? 0 : $a + 1));
            $p = pm_parse_mail($msg);
            $head = (string)(preg_split('/\r?\n\r?\n/', $msg, 2)[0] ?? '');
            $at = preg_match('/^Date:\s*(.+)$/mi', $head, $dm) && ($t = strtotime(trim($dm[1]))) ? date('Y-m-d H:i', $t) : date('Y-m-d H:i');
            $replyTo = preg_match('/^Reply-To:\s*(.+)$/mi', $head, $rm) && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $rm[1], $ra) ? strtolower($ra[0]) : '';
            $new[] = ['key' => "in:$v:$uid", 'dir' => 'in', 'at' => $at, 'addr' => (string)$p['from_email'], 'reply_to' => $replyTo, 'name' => mb_substr((string)$p['from_name'], 0, 80),
                'subject' => mb_substr((string)$p['subject'], 0, 200), 'snippet' => mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)$p['text'])), 0, 400), 'text' => mb_substr((string)$p['text'], 0, 3000),
                'msgid' => (string)$p['message_id'], 'auto' => !empty($p['auto']), 'bulk' => !empty($p['bulk']), 'dsn' => !empty($p['dsn']),
                'noreply' => (bool)preg_match('/^(no-?reply|do-?not-?reply|notifications?|alerts?|mailer|info@.*(facebook|google|linkedin))/i', (string)$p['from_email'])];
            $res['in']++;
            $uidsDone['in'][$v][] = $uid;
        }
        // the Sent folder: what we sent, from this app or by hand
        $sentBox = function_exists('pm_ml_sent_box') ? pm_ml_sent_box($fp, $acct) : 'Sent';
        if ($sentBox !== '') {
            [$v2, $uids2] = pm_mb_uids($fp, $sentBox, 30);
            $doneOut = array_map('intval', (array)($uidsDone['out'][$v2] ?? []));
            $todo = array_slice(array_values(array_diff($uids2, $doneOut)), -$max);
            foreach (array_chunk($todo, 100) as $ci => $chunk) {
                $raw = pm_imap_cmd($fp, 'O' . $ci, 'UID FETCH ' . implode(',', $chunk) . ' (UID BODY.PEEK[HEADER.FIELDS (TO CC DATE SUBJECT MESSAGE-ID)])');
                foreach (preg_split('/\r?\n(?=\* \d+ FETCH )/', $raw) as $m) {
                    if (!preg_match('/UID (\d+)/', $m, $um)) {
                        continue;
                    }
                    $tl = (preg_match('/^To:\s*(.+(?:\r?\n[ \t].+)*)/mi', $m, $tm) ? $tm[1] : '') . ' ' . (preg_match('/^Cc:\s*(.+(?:\r?\n[ \t].+)*)/mi', $m, $cm) ? $cm[1] : '');
                    preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $tl, $am);
                    $to = array_values(array_diff(array_unique(array_map('strtolower', $am[0])), $own));
                    $at = preg_match('/^Date:\s*(.+)$/mi', $m, $dm) && ($t = strtotime(trim($dm[1]))) ? date('Y-m-d H:i', $t) : '';
                    $new[] = ['key' => "out:$v2:{$um[1]}", 'dir' => 'out', 'at' => $at, 'addr' => (string)($to[0] ?? ''), 'to_all' => array_slice($to, 0, 6), 'name' => '',
                        'subject' => preg_match('/^Subject:\s*(.+)$/mi', $m, $sm) ? mb_substr(trim(mb_decode_mimeheader($sm[1])), 0, 200) : '', 'snippet' => '', 'text' => '',
                        'msgid' => preg_match('/^Message-ID:\s*(<[^>\s]+>)/mi', $m, $mm) ? $mm[1] : ''];
                    $res['out']++;
                    $uidsDone['out'][$v2][] = (int)$um[1];
                }
            }
        }
    } catch (Throwable $e) {
        $res['error'] = mb_substr($e->getMessage(), 0, 160);
    } finally {
        @pm_imap_cmd($fp, 'MZ', 'LOGOUT');
        @fclose($fp);
    }
    if ($new) {
        $res = pm_mb_place($brand, $new, $own) + $res;
    }
    foreach ($uidsDone as $dir => $byV) { // only the newest few thousand are kept: older ones are past the 30 days anyway
        foreach ((array)$byV as $vv => $l) {
            $uidsDone[$dir][$vv] = array_slice(array_values(array_unique(array_map('intval', (array)$l))), -3000);
        }
    }
    pm_mb_update($brand, function (array $s) use ($new, $acct, $uidsDone, $res) {
        foreach ($new as $it) {
            $s['items'][$it['key']] = $it;
        }
        uasort($s['items'], fn($a, $b) => strcmp((string)($a['at'] ?? ''), (string)($b['at'] ?? '')));
        $s['items'] = array_slice($s['items'], -400, null, true);
        $s['uids'][$acct] = $uidsDone;
        $s['at'] = date('Y-m-d H:i');
        $s['error'] = $res['error'];
        return $s;
    });
    return $res;
}

/**
 * What each new email is and whose it is (fills $new in place), and the safety: a lead we have not written to that is already in conversation with us leaves cold outreach.
 * Returns ['linked' => n, 'outside' => [names]].
 */
function pm_mb_place(string $brand, array &$new, array $own): array
{
    $leads = pm_leads_read();
    [$byMail, $byDom] = pm_mb_index($leads, $brand);
    $linked = 0;
    $ask = [];
    foreach ($new as $i => &$it) {
        $addr = (string)$it['addr'];
        if ($it['dir'] === 'out') {
            if (!(array)($it['to_all'] ?? []) || preg_match('/^\s*\[test\]/i', (string)$it['subject'])) {
                [$it['lead'], $it['how'], $it['kind']] = [null, '', 'own']; // to ourselves, or a test: says nothing about a lead
                continue;
            }
            $hit = [null, ''];
            foreach ((array)($it['to_all'] ?? []) as $to) {
                $hit = pm_mb_match($to, $byMail, $byDom);
                if ($hit[0] !== null) {
                    $it['addr'] = $to;
                    break;
                }
            }
            if ($hit[0] === null && ($byName = pm_mb_named($leads, $brand, (string)$it['subject'])) !== '') {
                $hit = [$byName, 'name in our subject'];
            }
            [$it['lead'], $it['how']] = $hit;
            $it['kind'] = $hit[0] !== null ? 'lead' : 'sent';
            $linked += $hit[0] !== null ? 1 : 0;
            continue;
        }
        if (in_array($addr, $own, true)) {
            if (($it['reply_to'] ?? '') === '' || in_array($it['reply_to'], $own, true)) {
                [$it['lead'], $it['how'], $it['kind']] = [null, '', 'own']; // our own copy or a test
                continue;
            }
            $it['addr'] = $addr = (string)$it['reply_to']; // a website form mailing for the person who filled it in: they are the sender
            $it['form'] = true;
        }
        [$it['lead'], $it['how']] = pm_mb_match($addr, $byMail, $byDom);
        $it['kind'] = !empty($it['dsn']) ? 'bounce' : (!empty($it['auto']) ? 'auto' : (!empty($it['bulk']) ? 'newsletter' : (!empty($it['noreply']) ? 'notification' : ($it['lead'] !== null ? 'lead' : '')))); // a newsletter from a lead's company stays a newsletter (still linked)
        $linked += $it['lead'] !== null ? 1 : 0;
        if ($it['kind'] === '') {
            $ask[$i] = ['id' => (string)$i, 'from' => $addr, 'name' => $it['name'], 'subject' => $it['subject'], 'text' => mb_substr((string)$it['snippet'], 0, 300)];
        }
    }
    unset($it);
    // the cheap AI for the human emails code could not place, at most 25 a read
    if ($ask && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        try {
            $was = pm_brand();
            pm_brand_set($brand);
            $sys = pm_agents_company_brief('tiny') . "\nYou are " . pm_agent_first('replies') . ', head of Replies, reading our mailbox. For each email say what sort of communication it is: '
                . 'enquiry (someone asking about what we offer, or wanting to buy, book or list), client (a customer we already serve), partner, supplier (someone selling to us or our supplier), '
                . 'admin (banks, tax, government, the platforms we use, accounts), personal, spam (unsolicited sales or scams), other. '
                . 'summary: what it is about in one plain line of at most 16 words. business: the company or place the sender writes for, only if the email names it, else empty. ' . PM_AGENT_RULES;
            $out = pm_agent_json(pm_claude($sys, json_encode(array_values(array_slice($ask, 0, 25)), JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"kind\",\"summary\",\"business\"}", false, 1500));
            pm_brand_set($was);
            foreach (is_array($out) ? $out : [] as $o) {
                $i = is_array($o) ? (int)($o['id'] ?? -1) : -1;
                if (isset($new[$i]) && $new[$i]['kind'] === '') {
                    $k = (string)($o['kind'] ?? 'other');
                    $new[$i]['kind'] = isset(PM_MB_KINDS[$k]) && !in_array($k, ['lead', 'sent', 'bounce', 'auto'], true) ? $k : 'other';
                    $new[$i]['summary'] = mb_substr(trim(strip_tags((string)($o['summary'] ?? ''))), 0, 160);
                    $new[$i]['business'] = mb_substr(trim(strip_tags((string)($o['business'] ?? ''))), 0, 100);
                }
            }
        } catch (Throwable) {
            // no classification this time: they show as "other" with their subject, and the owner places them
        }
    }
    foreach ($new as &$it) {
        $it['kind'] = $it['kind'] !== '' ? $it['kind'] : 'other';
        if ($it['lead'] === null && in_array($it['kind'], ['enquiry', 'client', 'partner', 'other'], true)) {
            $it['suggest'] = pm_mb_suggest($leads, $brand, (string)($it['business'] ?? '') . ' ' . $it['name'] . ' ' . $it['subject']);
        }
    }
    unset($it);
    return ['linked' => $linked, 'outside' => pm_mb_outside($brand, $new)];
}

/** The one lead whose whole name (its distinctive words: at least two, or one long one) is in the words given, else ''. Strong enough to link an email we sent. */
function pm_mb_named(array $leads, string $brand, string $words): string
{
    $w = ' ' . mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $words)) . ' ';
    $hits = [];
    foreach ($leads as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || !empty($l['listen'])) {
            continue;
        }
        $tok = array_values(array_filter(explode(' ', mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)($l['name'] ?? '')))), fn($t) => mb_strlen($t) >= 3
            && !in_array($t, ['the', 'and', 'lodge', 'lodges', 'hotel', 'guest', 'house', 'camp', 'resort', 'malawi', 'beach', 'inn', 'limited', 'ltd', 'company', 'cottage', 'cottages', 'apartments', 'village'], true)));
        if ((count($tok) >= 2 || (count($tok) === 1 && mb_strlen($tok[0]) >= 7)) && !array_filter($tok, fn($t) => !str_contains($w, ' ' . $t . ' '))) {
            $hits[] = (string)$id;
        }
    }
    return count($hits) === 1 ? $hits[0] : '';
}

/** Leads whose name appears in the words given (the business an email names, the sender, the subject): up to 3 ids, best first. */
function pm_mb_suggest(array $leads, string $brand, string $words): array
{
    $w = ' ' . mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $words)) . ' ';
    $stop = ['lodge', 'lodges', 'hotel', 'guest', 'house', 'camp', 'the', 'and', 'resort', 'limited', 'ltd', 'malawi', 'company', 'cottage', 'cottages', 'beach', 'inn', 'bnb', 'apartments'];
    $score = [];
    foreach ($leads as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $tok = array_filter(explode(' ', mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)($l['name'] ?? '')))), fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true));
        $hit = count(array_filter($tok, fn($t) => str_contains($w, ' ' . $t . ' ')));
        if ($tok && $hit) {
            $score[(string)$id] = $hit / count($tok);
        }
    }
    arsort($score);
    return array_slice(array_keys(array_filter($score, fn($s) => $s >= 0.5)), 0, 3);
}

/**
 * The safety: a lead not written to yet that wrote to us, or that we emailed by hand (its address or its company's domain in the Sent folder), leaves cold outreach now.
 * Wrote to us (and nothing sent after): waiting for our answer. Emailed by us: contacted, with the date. Its queued email is taken out of the queue. Returns the lead names.
 */
function pm_mb_outside(string $brand, array $new): array
{
    $hits = [];
    foreach ($new as $it) {
        if (($it['lead'] ?? null) === null || $it['at'] === '' || in_array($it['kind'], ['bounce', 'auto', 'newsletter', 'notification'], true) || !empty($it['auto']) || !empty($it['bulk']) || !empty($it['noreply']) || !empty($it['dsn'])) {
            continue;
        }
        $id = (string)$it['lead'];
        $hits[$id][] = $it;
    }
    if (!$hits) {
        return [];
    }
    $names = [];
    pm_update('leads', function (array $cur) use ($hits, &$names) {
        foreach ($hits as $id => $its) {
            $l = $cur[$id] ?? null;
            if (!$l || !empty($l['sent']) || !empty($l['wa_sent']) || !in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
                continue; // already known as contacted (the Sent check and the inbox check keep those), or past cold outreach
            }
            usort($its, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));
            $lastIn = $lastOut = null;
            foreach ($its as $it) {
                $cur[$id]['thread'][] = ['dir' => $it['dir'], 'at' => $it['at'], 'text' => '(Found in the mailbox, outside the app) ' . ($it['subject'] !== '' ? $it['subject'] : '') . ($it['dir'] === 'in' && $it['snippet'] !== '' ? ': ' . mb_substr($it['snippet'], 0, 300) : ''), 'ch' => 'email'];
                $it['dir'] === 'in' ? $lastIn = $it : $lastOut = $it;
                if ($it['dir'] === 'in' && strtolower((string)$it['addr']) !== strtolower((string)($l['email'] ?? '')) && filter_var($it['addr'], FILTER_VALIDATE_EMAIL)) {
                    $cur[$id]['alt_emails'] = array_slice(array_values(array_unique(array_merge((array)($cur[$id]['alt_emails'] ?? []), [strtolower((string)$it['addr'])]))), -5);
                }
            }
            unset($cur[$id]['approved_at'], $cur[$id]['send_after']);
            if ($lastOut) {
                $cur[$id]['sent'][] = $lastOut['at'];
                $cur[$id]['last_contacted'] = $cur[$id]['last_out'] = $lastOut['at'];
            }
            if ($lastIn && (!$lastOut || $lastIn['at'] > $lastOut['at'])) {
                $cur[$id]['status'] = 'replied'; // they wrote last: it waits for our answer
                $cur[$id]['awaiting_reply_since'] = $lastIn['at'];
                $cur[$id]['last_reply'] = $lastIn['at'];
                !empty($lastIn['msgid']) && $cur[$id]['last_in_id'] = (string)$lastIn['msgid'];
            } else {
                $cur[$id]['status'] = 'contacted';
            }
            $cur[$id]['outside'] = ['at' => date('Y-m-d H:i'), 'last' => ($lastIn ?? $lastOut)['at'], 'how' => (string)($its[0]['how'] ?? '')];
            pm_lead_note($cur[$id], 'Found in the mailbox: we are already in touch outside the app (' . ($lastIn ? 'they wrote on ' . $lastIn['at'] : 'emailed by hand on ' . $lastOut['at']) . ', matched by ' . ($its[0]['how'] ?? 'address') . '). Taken out of cold outreach.');
            $names[] = (string)$l['name'];
            foreach ($its as $it) { // the inbox check kept it as an unmatched reply: it is placed now
                !empty($it['msgid']) && function_exists('pm_inbox_unmatched_take') && pm_inbox_unmatched_take(md5((string)$it['msgid']));
            }
        }
        return $cur;
    }, fn() => []);
    if ($names && function_exists('pm_tb_post')) {
        pm_tb_post($brand, 'replies', 'writers', 'Already in touch outside the app, so no cold email: ' . implode(', ', array_slice($names, 0, 4)) . '.', 'mb:outside');
        pm_tb_post($brand, 'replies', 'director', count($names) . ' ' . (count($names) === 1 ? 'lead was' : 'leads were') . ' found already talking to us in the mailbox and taken out of cold outreach: ' . implode(', ', array_slice($names, 0, 4)) . '.', 'mb:outside:d');
    }
    $names && pm_agent_log('Reply', 'Mailbox: already in touch outside the app with ' . implode(', ', $names) . '; taken out of cold outreach.');
    return $names;
}

/** The desk's numbers for the screens: emails in 30 days by kind, those that need a look (human, not linked, not put aside), the leads found in touch outside the app. */
function pm_mb_counts(string $brand): array
{
    $st = pm_mb_state($brand);
    $c = ['in' => 0, 'out' => 0, 'linked' => 0, 'look' => 0, 'kinds' => [], 'outside' => 0, 'at' => (string)$st['at'], 'error' => (string)$st['error']];
    $cut = date('Y-m-d H:i', strtotime('-30 days'));
    foreach ($st['items'] as $it) {
        if ((string)($it['at'] ?? '') < $cut) {
            continue;
        }
        $c[$it['dir'] === 'in' ? 'in' : 'out']++;
        $c['linked'] += !empty($it['lead']) ? 1 : 0;
        $c['kinds'][$it['kind']] = ($c['kinds'][$it['kind']] ?? 0) + 1;
        $c['look'] += pm_mb_needs_look($it) ? 1 : 0;
    }
    $c['outside'] = count(array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand && !empty($l['outside']) && strtotime((string)$l['outside']['at']) > strtotime('-30 days')));
    return $c;
}

/** An email a person should look at: human, from someone we do not know as a lead, and not put aside. */
function pm_mb_needs_look(array $it): bool
{
    return $it['dir'] === 'in' && empty($it['lead']) && empty($it['done']) && in_array($it['kind'], ['enquiry', 'client', 'partner', 'other'], true);
}

/* ---------------- the owner's actions ---------------- */

/** Links an email to a lead (and its address to the lead), or makes a lead of it, or puts it aside. Returns [ok, message]. */
function pm_mb_act(string $brand, string $key, string $do, string $leadId = ''): array
{
    $it = pm_mb_state($brand)['items'][$key] ?? null;
    if (!$it) {
        return [false, 'That email is no longer in the list.'];
    }
    if ($do === 'aside') {
        pm_mb_update($brand, function ($s) use ($key) {
            $s['items'][$key]['done'] = date('Y-m-d H:i');
            return $s;
        });
        return [true, 'Put aside.'];
    }
    $leads = pm_leads();
    if ($do === 'new') { // a new enquiry becomes a lead that waits for our answer
        $name = trim((string)($it['business'] ?? '')) ?: (trim((string)$it['name']) ?: (string)$it['addr']);
        $leadId = pm_lead_id($name, '', $brand);
        if (!isset($leads[$leadId])) {
            $now = date('Y-m-d H:i');
            $leads[$leadId] = ['id' => $leadId, 'brand' => $brand, 'name' => mb_substr($name, 0, 120), 'type' => 'Enquiry', 'city' => '', 'address' => '', 'website' => '', 'phone' => '',
                'email' => filter_var($it['addr'], FILTER_VALIDATE_EMAIL) ? (string)$it['addr'] : '', 'whatsapp' => '', 'contact' => (string)$it['name'], 'contact_title' => '', 'evidence' => [],
                'need_signals' => [(string)($it['summary'] ?? $it['subject'])], 'score' => 70, 'status' => 'replied', 'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0,
                'created' => $now, 'updated' => $now, 'source' => 'email', 'src' => ['kind' => 'mailbox'], 'awaiting_reply_since' => $it['at'], 'last_reply' => $it['at'],
                'thread' => [['dir' => 'in', 'at' => $it['at'], 'text' => trim((string)$it['subject'] . "\n\n" . (string)($it['text'] ?: $it['snippet'])), 'ch' => 'email']]]
                + (!empty($it['msgid']) ? ['last_in_id' => (string)$it['msgid']] : []);
            pm_lead_note($leads[$leadId], 'Made from an email in the mailbox (' . ($it['subject'] ?: 'no subject') . ')');
            pm_leads_save($leads);
        }
    } elseif (!isset($leads[$leadId])) {
        return [false, 'Pick the lead this email belongs to.'];
    } else { // linked: their address is remembered, and a lead we have not written to leaves cold outreach
        $addr = strtolower((string)$it['addr']);
        if (filter_var($addr, FILTER_VALIDATE_EMAIL) && $addr !== strtolower((string)($leads[$leadId]['email'] ?? ''))) {
            pm_update('leads', function ($cur) use ($leadId, $addr) {
                $cur[$leadId]['alt_emails'] = array_slice(array_values(array_unique(array_merge((array)($cur[$leadId]['alt_emails'] ?? []), [$addr]))), -5);
                return $cur;
            }, fn() => []);
        }
        pm_mb_outside($brand, [['lead' => $leadId, 'how' => 'linked by you'] + $it]);
        !empty($it['msgid']) && function_exists('pm_inbox_unmatched_take') && pm_inbox_unmatched_take(md5((string)$it['msgid']));
    }
    pm_mb_update($brand, function ($s) use ($key, $leadId) {
        $s['items'][$key]['lead'] = $leadId;
        $s['items'][$key]['how'] = 'linked by you';
        $s['items'][$key]['kind'] = in_array($s['items'][$key]['kind'], ['other', 'enquiry', 'client', 'partner'], true) ? 'lead' : $s['items'][$key]['kind'];
        return $s;
    });
    $nm = (string)(pm_leads_read()[$leadId]['name'] ?? 'the lead');
    return [true, ($do === 'new' ? 'Made a lead: ' : 'Linked to ') . $nm . '. It shows on the lead, and the next emails from that address link by themselves.'];
}

/* ---------------- forms, jobs, other screens ---------------- */

function pm_do_mb_read(string $vb): array
{
    @set_time_limit(180);
    $r = pm_mb_read($vb);
    return ['msg' => $r['error'] !== '' ? 'The mailbox could not be read: ' . $r['error'] : 'Read ' . $r['in'] . ' new email' . ($r['in'] === 1 ? '' : 's') . ' in the Inbox and ' . $r['out'] . ' in Sent.'
        . ($r['outside'] ? ' Already in touch outside the app, now out of cold outreach: ' . implode(', ', $r['outside']) . '.' : ''), 'kind' => $r['error'] !== '' ? 'err' : 'ok', 'to' => 'mailbox'];
}

function pm_do_mb_act(string $vb): array
{
    [$ok, $msg] = pm_mb_act($vb, (string)($_POST['key'] ?? ''), (string)($_POST['mb'] ?? ''), (string)($_POST['lead'] ?? ''));
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'mailbox' . (preg_match('/^[a-z]+$/', (string)($_POST['f'] ?? '')) ? '&f=' . $_POST['f'] : '')];
}

/** Scheduler job: each business's mailbox is read every 30 minutes (new emails only; the AI only for the human ones code cannot place). */
function pm_job_mailbox(string $brand): string
{
    if (!function_exists('pm_imap_settings')) {
        require_once __DIR__ . '/engage.php';
    }
    $c = pm_imap_settings($brand);
    if ($c['host'] === '' || $c['user'] === '' || $c['pass'] === '' || (strtotime(pm_mb_state($brand)['at']) ?: 0) > time() - 1800) {
        return '';
    }
    $r = pm_mb_read($brand);
    return $r['error'] !== '' ? 'mailbox: ' . $r['error'] : (($r['in'] + $r['out']) ? 'mailbox: ' . $r['in'] . ' in, ' . $r['out'] . ' sent' . ($r['outside'] ? ', in touch outside the app: ' . implode(', ', $r['outside']) : '') : '');
}
