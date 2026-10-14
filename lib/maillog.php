<?php
/**
 * The email ledger: every email the app sends, in one place, sent once.
 *
 *  - pm_mail() writes a row before it sends ("sending") and finishes it after ("sent" or "failed", with the reason and the fix), so the Operations > Email screen shows
 *    what went out, to whom, from which business, through which server, and what went wrong.
 *  - Never twice: each email has a key (a lead's first email, a follow-up number, or the exact words to the same address). While one copy of the app sends it, a lock
 *    stops a second process; once it is sent the ledger refuses it again. A send that stopped half-way ("unknown") also blocks, until the owner says it did not go.
 *  - The Sent folder: SMTP does not keep a copy, so after a send the message is saved into the mailbox's own Sent folder over IMAP (the same login as the replies check).
 *    Before a first email, the Sent folder is searched for that address, so the server copy and the local copy of the app never write to the same person twice; and the
 *    replies check reads the Sent folder so a lead emailed from the other copy (or by hand) shows as contacted here too.
 * Data: data/mail_log.json {rows: [...newest last]}, data/mail_state.json {sent_box: {account: name}, sent_uid: {account: uid}}.
 */

const PM_ML_KEEP = 3000;

/** The ledger rows, newest first. */
function pm_ml_rows(): array
{
    return array_reverse((array)(pm_load('mail_log', fn() => ['rows' => []])['rows'] ?? []));
}

function pm_ml_write(callable $fn): void
{
    try {
        pm_update('mail_log', function (array $d) use ($fn) {
            $rows = (array)($d['rows'] ?? []);
            $rows = $fn($rows);
            $d['rows'] = array_slice(array_values($rows), -PM_ML_KEEP);
            return $d;
        }, fn() => ['rows' => []]);
    } catch (Throwable) {
        // the ledger must never stop an email: a failure to write it is shown by its absence
    }
}

/** The key that makes an email unique: the caller's own (a lead's first email), else the same words to the same address. */
function pm_ml_key(array $m): string
{
    if (!empty($m['dedupe'])) {
        return (string)$m['dedupe'];
    }
    return 'msg:' . sha1(strtolower(trim((string)($m['to'] ?? ''))) . "\n" . trim((string)($m['subject'] ?? '')) . "\n" . trim((string)($m['body'] ?? '')));
}

/**
 * May this email go? '' when yes, else why not. Explicit keys (a lead's first email, a follow-up) block for good once sent; the same words to the same address block
 * for 10 minutes (a double click, a retry); tests and owner alerts are never blocked.
 */
function pm_ml_blocked(array $m, string $key): string
{
    if (in_array((string)($m['kind'] ?? ''), ['test', 'notify'], true)) {
        return '';
    }
    $window = !empty($m['dedupe']) ? (int)($m['dedupe_days'] ?? 3650) * 86400 : 600;
    foreach (pm_ml_rows() as $r) {
        if (($r['key'] ?? '') !== $key || time() - (int)strtotime((string)$r['at']) > $window) {
            continue;
        }
        $st = (string)($r['status'] ?? '');
        if ($st === 'sent') {
            return 'This email was already sent to ' . $r['to'] . ' on ' . $r['at'] . '. It is not sent twice.';
        }
        if ($st === 'sending' && time() - (int)strtotime((string)$r['at']) < 900) {
            return 'This email is being sent right now (started ' . $r['at'] . '). It is not sent twice.';
        }
        if ($st === 'sending' || $st === 'unknown') {
            return 'An earlier try at ' . $r['at'] . ' stopped half-way, so it may have gone out. Look in the Sent folder; if it is not there, press "It did not go" on Operations > Email, then send again.';
        }
    }
    return '';
}

/** Starts a ledger row: its id. */
function pm_ml_begin(array $s, array $m, string $key): string
{
    $id = substr(sha1(uniqid('', true)), 0, 12);
    $row = ['id' => $id, 'at' => date('Y-m-d H:i:s'), 'brand' => function_exists('pm_brand') ? pm_brand() : '', 'kind' => (string)($m['kind'] ?? 'email'), 'lead' => (string)($m['lead'] ?? ''),
        'to' => strtolower(trim((string)($m['to'] ?? ''))), 'cc' => (string)($m['cc'] ?? ''), 'subject' => mb_substr((string)($m['subject'] ?? ''), 0, 200),
        'from' => (string)(($s['smtp']['from_email'] ?? '') ?: ($s['smtp']['username'] ?? '')), 'key' => $key, 'status' => 'sending',
        'by' => (string)($GLOBALS['PM_WHO'] ?? (PHP_SAPI === 'cli' ? 'scheduler' : ''))];
    pm_ml_write(function (array $rows) use (&$row) {
        foreach ($rows as $i => $r) { // an earlier failed try of the same email leaves the Outbox: this try replaces it
            if (($r['key'] ?? '') === $row['key'] && ($r['status'] ?? '') === 'failed' && empty($r['resolved'])) {
                $rows[$i]['resolved'] = 'tried again ' . date('Y-m-d H:i');
                $row['tries'] = max((int)($row['tries'] ?? 0), (int)($r['tries'] ?? 0));
            }
        }
        $rows[] = $row;
        return $rows;
    });
    return $id;
}

/** Finishes a ledger row with what happened: status sent|failed|bounced|unknown, and any of mid, err, fix, via, sent_folder, bounce. */
function pm_ml_end(string $id, array $set): void
{
    pm_ml_write(function (array $rows) use ($id, $set) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $rows[$i] = array_replace($r, $set, ['done' => date('Y-m-d H:i:s')]);
            }
        }
        return $rows;
    });
}

/** Rows left at "sending" for more than 15 minutes stopped half-way: they become "unknown" (and keep blocking a second send until the owner clears them). */
function pm_ml_settle(): void
{
    $old = array_filter(pm_ml_rows(), fn($r) => ($r['status'] ?? '') === 'sending' && time() - (int)strtotime((string)$r['at']) > 900);
    if ($old) {
        pm_ml_write(function (array $rows) {
            foreach ($rows as $i => $r) {
                if (($r['status'] ?? '') === 'sending' && time() - (int)strtotime((string)$r['at']) > 900) {
                    $rows[$i]['status'] = 'unknown';
                    $rows[$i]['err'] = 'The app stopped while sending, so it is not known whether this email went out.';
                    $rows[$i]['fix'] = 'Look in the Sent folder of ' . ($r['from'] ?? 'the mailbox') . '. If it is not there, press "It did not go" and send it again.';
                }
            }
            return $rows;
        });
    }
}

/** The owner checked: this email did not go. The row becomes "failed", so it may be sent again. */
function pm_ml_clear(string $id): bool
{
    $done = false;
    pm_ml_write(function (array $rows) use ($id, &$done) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id && in_array($r['status'] ?? '', ['unknown', 'sending'], true)) {
                $rows[$i]['status'] = 'failed';
                $rows[$i]['err'] = 'Marked as not sent by ' . ((string)($GLOBALS['PM_WHO'] ?? '') ?: 'the owner') . ' on ' . date('Y-m-d H:i') . '.';
                $done = true;
            }
        }
        return $rows;
    });
    return $done;
}

/** A bounce came back for an address: the newest sent row to it (last 14 days) shows it. $kind: hard, soft or auth. */
function pm_ml_mark_bounce(string $addr, string $kind, string $why = ''): void
{
    $addr = strtolower(trim($addr));
    if ($addr === '') {
        return;
    }
    pm_ml_write(function (array $rows) use ($addr, $kind, $why) {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $r = $rows[$i];
            if (($r['to'] ?? '') === $addr && ($r['status'] ?? '') === 'sent' && time() - (int)strtotime((string)$r['at']) < 14 * 86400) {
                $rows[$i]['status'] = $kind === 'soft' ? 'sent' : 'bounced';
                $rows[$i]['bounce'] = $kind;
                $rows[$i]['err'] = $why !== '' ? $why : ($kind === 'auth' ? 'The receiving server refused it: our domain failed SPF/DKIM.' : ($kind === 'soft' ? 'A temporary delivery problem was reported.' : 'The address does not accept mail.'));
                $rows[$i]['fix'] = $kind === 'auth' ? 'Fix SPF/DKIM for the sending domain (Operations > Email shows the record), then send it again.' : ($kind === 'soft' ? '' : 'Find another address for this business.');
                break;
            }
        }
        return $rows;
    });
}

/**
 * Can the copy of a sent email go into the Sent folder (so no extra copy to the inbox is needed)? Yes when an inbox login is set up and the last try to save worked.
 */
function pm_ml_can_save_sent(string $brand): bool
{
    if (strtolower((string)(pm_env()['MAIL_SAVE_SENT'] ?? 'on')) === 'off') {
        return false;
    }
    if (!function_exists('pm_imap_settings')) {
        require_once __DIR__ . '/engage.php';
    }
    $c = pm_imap_settings($brand);
    if ($c['host'] === '' || $c['user'] === '' || $c['pass'] === '') {
        return false;
    }
    $ok = pm_load('mail_state', fn() => [])['save_ok'][strtolower($c['host'] . '|' . $c['user'])] ?? true; // until a save fails, trust it
    return (bool)$ok;
}

/** Remembers whether saving to the Sent folder worked for a mailbox (when it does not, the inbox copy is used again). */
function pm_ml_save_result(string $brand, bool $ok): void
{
    if (!function_exists('pm_imap_settings')) {
        return;
    }
    $c = pm_imap_settings($brand);
    $acct = strtolower($c['host'] . '|' . $c['user']);
    try {
        pm_update('mail_state', function (array $d) use ($acct, $ok) {
            $d['save_ok'][$acct] = $ok;
            return $d;
        }, fn() => []);
    } catch (Throwable) {
    }
}

/* ---------------- the mailbox's own Sent folder (IMAP) ---------------- */

/** An open, logged-in IMAP session for a business's mailbox, reused within one page or run: [stream, account key] or [null, why]. */
function pm_ml_imap(string $brand): array
{
    static $open = [];
    if (strtolower((string)(pm_env()['MAIL_SAVE_SENT'] ?? 'on')) === 'off') {
        return [null, 'turned off (MAIL_SAVE_SENT=off)'];
    }
    if (!function_exists('pm_imap_settings')) {
        require_once __DIR__ . '/engage.php';
    }
    $c = pm_imap_settings($brand);
    if ($c['host'] === '' || $c['user'] === '' || $c['pass'] === '') {
        return [null, 'no inbox login for this mailbox'];
    }
    $acct = strtolower($c['host'] . '|' . $c['user']);
    if (isset($open[$acct])) {
        return $open[$acct][0] ? [$open[$acct][0], $acct] : [null, $open[$acct][1]];
    }
    [$fp, , $es] = pm_imap_open($c, 12);
    if (!$fp) {
        $open[$acct] = [null, 'could not reach the inbox server: ' . $es];
        return [null, $open[$acct][1]];
    }
    stream_set_timeout($fp, 20);
    fgets($fp, 4096);
    $q = fn($v) => '"' . addcslashes($v, '"\\') . '"';
    if (!str_contains(pm_imap_cmd($fp, 'L1', 'LOGIN ' . $q($c['user']) . ' ' . $q($c['pass'])), 'L1 OK')) {
        fclose($fp);
        $open[$acct] = [null, 'the inbox login was refused'];
        return [null, $open[$acct][1]];
    }
    $open[$acct] = [$fp, ''];
    register_shutdown_function(function () use ($fp) {
        if (is_resource($fp)) {
            @fwrite($fp, "LZ LOGOUT\r\n");
            @fclose($fp);
        }
    });
    return [$fp, $acct];
}

/** The name of the mailbox's Sent folder (from the server's own \Sent flag, else the usual names), remembered for 30 days. '' when none is found. */
function pm_ml_sent_box($fp, string $acct): string
{
    $st = pm_load('mail_state', fn() => []);
    $known = (array)($st['sent_box'][$acct] ?? []);
    if (!empty($known['name']) && time() - (int)($known['at'] ?? 0) < 30 * 86400) {
        return (string)$known['name'];
    }
    $list = pm_imap_cmd($fp, 'S1', 'LIST "" "*"');
    $names = [];
    $flagged = '';
    foreach (preg_split('/\r?\n/', $list) as $ln) {
        if (!preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))\s*$/i', trim($ln), $m)) {
            continue;
        }
        $name = ($m[2] ?? '') !== '' ? stripcslashes($m[2]) : (string)($m[3] ?? '');
        $names[] = $name;
        if (stripos($m[1], '\\Sent') !== false) {
            $flagged = $name;
        }
    }
    $name = $flagged;
    if ($name === '') {
        foreach (['Sent', 'INBOX.Sent', 'INBOX/Sent', 'Sent Items', 'Sent Messages', 'INBOX.Sent Items', 'Sent Mail', '[Gmail]/Sent Mail'] as $try) {
            foreach ($names as $n) {
                if (strcasecmp($n, $try) === 0) {
                    $name = $n;
                    break 2;
                }
            }
        }
    }
    if ($name !== '') {
        try {
            pm_update('mail_state', function (array $d) use ($acct, $name) {
                $d['sent_box'][$acct] = ['name' => $name, 'at' => time()];
                return $d;
            }, fn() => []);
        } catch (Throwable) {
        }
    }
    return $name;
}

/** Saves a sent message into the Sent folder: 'saved', or why not. */
function pm_ml_save_sent(string $brand, string $mime): string
{
    if ($mime === '') {
        return 'not saved: no copy of the message';
    }
    try {
        [$fp, $acct] = pm_ml_imap($brand);
        if (!$fp) {
            return 'not saved: ' . $acct;
        }
        $box = pm_ml_sent_box($fp, $acct);
        if ($box === '') {
            return 'not saved: no Sent folder found in the mailbox';
        }
        $mime = preg_replace("/\r?\n/", "\r\n", $mime);
        fwrite($fp, 'A1 APPEND "' . addcslashes($box, '"\\') . '" (\\Seen) {' . strlen($mime) . "}\r\n");
        $ln = (string)fgets($fp, 4096);
        if (!str_starts_with($ln, '+')) {
            return 'not saved: the server would not take it (' . trim($ln) . ')';
        }
        fwrite($fp, $mime . "\r\n");
        $resp = '';
        while (($l = fgets($fp, 4096)) !== false) {
            $resp .= $l;
            if (str_starts_with($l, 'A1 ')) {
                break;
            }
        }
        return str_contains($resp, 'A1 OK') ? 'saved' : 'not saved: ' . trim(mb_substr($resp, 0, 160));
    } catch (Throwable $e) {
        return 'not saved: ' . mb_substr($e->getMessage(), 0, 160);
    }
}

/**
 * Was this address already written to from this mailbox (by either copy of the app, or by hand)? Looks in the Sent folder for the last $days days.
 * Returns ['found' => bool, 'at' => date, 'subject' => '', 'checked' => bool, 'why' => ''] (checked false: the folder could not be read; the send is not blocked by that).
 */
function pm_ml_sent_to(string $brand, string $to, int $days = 365): array
{
    $out = ['found' => false, 'at' => '', 'subject' => '', 'checked' => false, 'why' => ''];
    $to = strtolower(trim($to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return $out;
    }
    try {
        [$fp, $acct] = pm_ml_imap($brand);
        if (!$fp) {
            return ['why' => $acct] + $out;
        }
        $box = pm_ml_sent_box($fp, $acct);
        if ($box === '') {
            return ['why' => 'no Sent folder found'] + $out;
        }
        if (!str_contains(pm_imap_cmd($fp, 'E1', 'EXAMINE "' . addcslashes($box, '"\\') . '"'), 'E1 OK')) {
            return ['why' => 'the Sent folder would not open'] + $out;
        }
        $res = pm_imap_cmd($fp, 'Q1', 'UID SEARCH SINCE ' . date('d-M-Y', strtotime("-$days days")) . ' TO "' . addcslashes($to, '"\\') . '"');
        $out['checked'] = true;
        preg_match('/\* SEARCH([\d ]*)/', $res, $m);
        $uids = array_filter(explode(' ', trim($m[1] ?? '')));
        if ($uids) {
            $last = (int)end($uids);
            $h = pm_imap_cmd($fp, 'Q2', "UID FETCH $last (BODY.PEEK[HEADER.FIELDS (DATE SUBJECT)])");
            $out['found'] = true;
            $out['at'] = preg_match('/^Date:\s*(.+)$/mi', $h, $dm) && ($t = strtotime(trim($dm[1]))) ? date('Y-m-d H:i', $t) : '';
            $out['subject'] = preg_match('/^Subject:\s*(.+)$/mi', $h, $sm) ? mb_substr(trim(mb_decode_mimeheader($sm[1])), 0, 120) : '';
        }
    } catch (Throwable $e) {
        $out['why'] = mb_substr($e->getMessage(), 0, 120);
    }
    return $out;
}

/**
 * Reads the Sent folder of every business's mailbox (new messages only) and brings the leads up to date: a lead written to from the other copy of the app, or by hand,
 * is marked contacted here with the date and subject, so nobody writes to it again. Called by the replies check. Returns how many leads were updated.
 */
function pm_ml_sync_sent(array &$leads): int
{
    if (!function_exists('pm_imap_boxes')) {
        return 0;
    }
    $n = 0;
    $st = pm_load('mail_state', fn() => []);
    foreach (pm_imap_boxes() as $box) {
        $brand = (string)($box['brands'][0] ?? 'promanaged');
        try {
            [$fp, $acct] = pm_ml_imap($brand);
            if (!$fp) {
                continue;
            }
            $name = pm_ml_sent_box($fp, $acct);
            if ($name === '' || !str_contains(pm_imap_cmd($fp, 'E2', 'EXAMINE "' . addcslashes($name, '"\\') . '"'), 'E2 OK')) {
                continue;
            }
            $from = (int)($st['sent_uid'][$acct] ?? 0);
            $res = pm_imap_cmd($fp, 'Q3', $from > 0 ? 'UID SEARCH UID ' . ($from + 1) . ':*' : 'UID SEARCH SINCE ' . date('d-M-Y', strtotime('-30 days')));
            preg_match('/\* SEARCH([\d ]*)/', $res, $m);
            $uids = array_values(array_filter(array_map('intval', explode(' ', trim($m[1] ?? ''))), fn($u) => $u > $from));
            $byMail = [];
            foreach ($leads as $id => $l) {
                if (in_array((string)($l['brand'] ?? 'promanaged'), (array)$box['brands'], true) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $byMail[strtolower((string)$l['email'])] = $id;
                }
            }
            $max = $from;
            foreach (array_slice($uids, -200) as $k => $uid) {
                $max = max($max, $uid);
                $h = pm_imap_cmd($fp, 'H' . $k, "UID FETCH $uid (BODY.PEEK[HEADER.FIELDS (TO DATE SUBJECT)])");
                preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', preg_match('/^To:\s*(.+(?:\r?\n[ \t].+)*)/mi', $h, $tm) ? $tm[1] : '', $am);
                $at = preg_match('/^Date:\s*(.+)$/mi', $h, $dm) && ($t = strtotime(trim($dm[1]))) ? date('Y-m-d H:i', $t) : date('Y-m-d H:i');
                $subj = preg_match('/^Subject:\s*(.+)$/mi', $h, $sm) ? mb_substr(trim(mb_decode_mimeheader($sm[1])), 0, 120) : '';
                foreach (array_unique(array_map('strtolower', $am[0])) as $addr) {
                    $id = $byMail[$addr] ?? null;
                    if ($id === null || !empty($leads[$id]['sent']) || !in_array($leads[$id]['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
                        continue; // not a lead, this copy already knows it was written to, or past the first email (a reply to their enquiry is not a cold email)
                    }
                    $leads[$id]['sent'][] = $at;
                    $leads[$id]['thread'][] = ['dir' => 'out', 'at' => $at, 'text' => '(Found in the Sent folder) ' . $subj];
                    $leads[$id]['last_contacted'] = $leads[$id]['last_out'] = $at;
                    $leads[$id]['status'] = 'contacted';
                    unset($leads[$id]['approved_at'], $leads[$id]['send_after']);
                    pm_lead_note($leads[$id], 'Marked contacted: an email to ' . $addr . ' on ' . $at . ' ("' . $subj . '") is in the Sent folder, sent from another copy of the app or by hand');
                    $n++;
                }
            }
            if ($max > $from) {
                pm_update('mail_state', function (array $d) use ($acct, $max) {
                    $d['sent_uid'][$acct] = $max;
                    return $d;
                }, fn() => []);
            }
        } catch (Throwable $e) {
            if (function_exists('pm_agent_log')) {
                pm_agent_log('Email', 'Sent folder check failed for ' . $box['user'] . ': ' . $e->getMessage());
            }
        }
    }
    return $n;
}

/** The plain summary for the Email screen: counts for the last 7 days and the open problems. */
function pm_ml_summary(string $brand = ''): array
{
    pm_ml_settle();
    $rows = array_filter(pm_ml_rows(), fn($r) => ($brand === '' || ($r['brand'] ?? '') === $brand) && time() - (int)strtotime((string)$r['at']) < 7 * 86400);
    $c = ['sent' => 0, 'failed' => 0, 'bounced' => 0, 'unknown' => 0, 'saved' => 0, 'not_saved' => 0];
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? '');
        $c[$st] = ($c[$st] ?? 0) + 1;
        if (in_array($st, ['sent', 'bounced'], true)) {
            $sf = (string)($r['sent_folder'] ?? '');
            $sf === 'saved' ? $c['saved']++ : ($sf !== '' ? $c['not_saved']++ : null);
        }
    }
    return $c;
}

/* ---------------- advice instead of limits ---------------- */

/** The numbers behind the sending advice, from what really happened (the email log, the leads and the domain check). */
function pm_send_facts(string $brand): array
{
    $rows = array_filter(pm_ml_rows(), fn($r) => ($r['brand'] ?? '') === $brand && !in_array($r['kind'] ?? '', ['test', 'notify'], true));
    $age = fn($r) => time() - (int)strtotime((string)$r['at']);
    $sent = array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['sent', 'bounced'], true));
    $first = $sent ? min(array_map(fn($r) => (int)strtotime((string)$r['at']), $sent)) : 0;
    $days = $first ? max(1, (int)ceil((time() - $first) / 86400)) : 0;
    $today = count(array_filter($sent, fn($r) => substr((string)$r['at'], 0, 10) === date('Y-m-d')));
    $d14 = array_filter($sent, fn($r) => $age($r) < 14 * 86400);
    $bounced = count(array_filter($d14, fn($r) => ($r['status'] ?? '') === 'bounced' && ($r['bounce'] ?? '') !== 'auth'));
    $replied = 0;
    $sent30 = 0;
    foreach (pm_leads_read() as $l) {
        $last = (array)($l['sent'] ?? []);
        if (($l['brand'] ?? 'promanaged') !== $brand || !$last || strtotime((string)end($last)) < strtotime('-30 days')) {
            continue;
        }
        $sent30++;
        $replied += function_exists('pm_lead_replied') && pm_lead_replied($l) ? 1 : 0;
    }
    $prev = $days > 1 ? (count($sent) - $today) / max(1, $days - 1) : 0;
    $s = pm_settings();
    $auth = function_exists('pm_email_auth_check') ? pm_email_auth_check(pm_email_domain((string)(($s['smtp']['from_email'] ?? '') ?: ($s['email'] ?? '')))) : ['checked' => false];
    return ['sent_today' => $today, 'average_per_day_before' => round($prev, 1), 'days_sending' => $days, 'sent_14d' => count($d14), 'bounced_14d' => $bounced,
        'bounce_rate_14d' => count($d14) ? round(100 * $bounced / count($d14), 1) : 0, 'emailed_30d' => $sent30, 'replied_30d' => $replied,
        'outbox' => count(array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['failed', 'unknown'], true) && empty($r['resolved']))),
        'not_in_sent_folder_7d' => count(array_filter($sent, fn($r) => $age($r) < 7 * 86400 && str_starts_with((string)($r['sent_folder'] ?? ''), 'not'))),
        'spf' => !empty($auth['checked']) ? $auth['spf']['status'] : 'unknown', 'dkim' => !empty($auth['checked']) ? $auth['dkim']['status'] : 'unknown',
        'provider_per_hour' => (int)(pm_ml_provider_rate($brand)['per_hour'] ?? 0)];
}

/** What the numbers say, in plain words (code, no AI): the fallback, and what the AI is given. */
function pm_send_advice_plain(array $f): array
{
    $out = [];
    if ($f['spf'] !== 'ok' && $f['dkim'] !== 'ok' && $f['spf'] !== 'unknown') {
        $out[] = 'Gmail refuses mail from a domain whose SPF and DKIM both fail: fix that first (Operations > Email), or most of what you send is lost.';
    }
    if ($f['sent_14d'] >= 10 && $f['bounce_rate_14d'] >= 3) {
        $out[] = "{$f['bounced_14d']} of the last {$f['sent_14d']} emails bounced ({$f['bounce_rate_14d']}%). Mail providers start filtering a sender whose bounces climb: check the addresses before sending more.";
    }
    if ($f['days_sending'] > 0 && $f['days_sending'] < 21 && $f['sent_today'] > max(20, 3 * $f['average_per_day_before'])) {
        $out[] = "{$f['sent_today']} emails today from a mailbox that has sent for {$f['days_sending']} days (about {$f['average_per_day_before']} a day before). A sudden jump can look like bulk mail to Gmail; it is your call, and spreading them over a few days is safer.";
    }
    if ($f['emailed_30d'] >= 15 && $f['replied_30d'] === 0) {
        $out[] = "No replies yet from {$f['emailed_30d']} businesses emailed in 30 days: the opening or subject may need a different angle before sending more of the same.";
    }
    if ($f['provider_per_hour'] > 0) {
        $out[] = "Your mail provider refused more after about {$f['provider_per_hour']} emails in an hour: Send now sends that many and queues the rest to go out by themselves after the hour (the provider's limit, not the app's).";
    }
    if ($f['outbox'] > 0) {
        $out[] = "{$f['outbox']} email" . ($f['outbox'] === 1 ? ' is' : 's are') . ' in the Outbox because they did not go: see the Outbox on the Leads page.';
    }
    if ($f['not_in_sent_folder_7d'] > 0) {
        $out[] = "{$f['not_in_sent_folder_7d']} sent email" . ($f['not_in_sent_folder_7d'] === 1 ? ' is' : 's are') . ' not in the Sent folder: Operations > Email shows why.';
    }
    return $out;
}

function pm_send_advice_sig(array $f): string
{
    return sha1(json_encode([$f['sent_today'] >= 20 ? intdiv($f['sent_today'], 10) : 0, $f['bounced_14d'], $f['outbox'], $f['replied_30d'], intdiv($f['emailed_30d'], 5), $f['spf'], $f['dkim'], date('Y-m-d')]));
}

/** The advice to show: the AI's reading when it was made for these numbers, else the plain one. Never asks the AI (no AI on a page load). */
function pm_send_advice(string $brand): array
{
    $f = pm_send_facts($brand);
    $saved = (array)(pm_load('mail_state', fn() => [])['advice'][$brand] ?? []);
    if (($saved['sig'] ?? '') === pm_send_advice_sig($f) && !empty($saved['lines'])) {
        return ['lines' => (array)$saved['lines'], 'ai' => true, 'facts' => $f];
    }
    return ['lines' => pm_send_advice_plain($f), 'ai' => false, 'facts' => $f];
}

/**
 * The AI reads the numbers and advises: one small cheap call, only when the numbers changed, after a send or in the morning run (never on a page load).
 * The advice never stops a send: the owner decides.
 */
function pm_send_advice_refresh(string $brand): void
{
    if (!function_exists('pm_agents_ready') || !(pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        return;
    }
    $f = pm_send_facts($brand);
    $sig = pm_send_advice_sig($f);
    if ((pm_load('mail_state', fn() => [])['advice'][$brand]['sig'] ?? '') === $sig) {
        return; // the same numbers: the same advice, no AI call
    }
    try {
        $sys = 'You are an email deliverability adviser for a small business that sends cold first emails one at a time from its own mailbox. The owner decides how many to send; you never set a limit. '
            . 'From the numbers, give 1 to 3 short, specific pieces of advice (each one sentence, plain words, quoting the numbers that matter). If nothing is worrying, say so in one line. Never invent a number. JSON only.';
        $out = pm_agent_json(pm_claude($sys, json_encode(['numbers' => $f, 'what_the_rules_see' => pm_send_advice_plain($f)], JSON_UNESCAPED_UNICODE) . "\nJSON: {\"advice\":[\"\"]}", false, 300, 'cheap'));
        $lines = array_values(array_filter(array_map(fn($x) => mb_substr(trim(strip_tags((string)$x)), 0, 300), (array)($out['advice'] ?? []))));
        if ($lines) {
            pm_update('mail_state', function (array $d) use ($brand, $sig, $lines) {
                $d['advice'][$brand] = ['sig' => $sig, 'lines' => array_slice($lines, 0, 3), 'at' => date('Y-m-d H:i')];
                return $d;
            }, fn() => []);
        }
    } catch (Throwable) {
        // the plain advice is shown instead
    }
}

/* ---------------- the Outbox: emails that did not go ---------------- */

/** Emails that did not go (failed, or stopped half-way) and were not dealt with: newest first. */
function pm_ml_outbox(string $brand = ''): array
{
    pm_ml_settle();
    return array_values(array_filter(pm_ml_rows(), fn($r) => ($brand === '' || ($r['brand'] ?? '') === $brand) && in_array($r['status'] ?? '', ['failed', 'unknown'], true)
        && empty($r['resolved']) && !in_array($r['kind'] ?? '', ['test', 'notify'], true)));
}

/** Is a failure worth trying again by itself (a server or connection hiccup), or does it need a person (a refused address, a login, a domain problem)? */
function pm_ml_transient(string $err): bool
{
    if (function_exists('pm_smtp_rate_limited') && pm_smtp_rate_limited($err)) {
        return true; // the provider's hourly allowance: it goes once the hour has passed
    }
    return (bool)preg_match('/connect|timed? ?out|temporar|try again|\b4\d\d\b|busy|reset by peer|could not reach|unavailable|greylist/i', $err)
        && !preg_match('/authenticat|password|5\.7\.|not sent twice|already sent|stopped half-way/i', $err);
}

/** Marks an Outbox row as dealt with (sent again, or discarded). */
function pm_ml_resolve(string $id, string $how): void
{
    pm_ml_write(function (array $rows) use ($id, $how) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $rows[$i]['resolved'] = $how . ' ' . date('Y-m-d H:i');
                if (($r['status'] ?? '') === 'unknown') {
                    $rows[$i]['status'] = 'failed';
                }
            }
        }
        return $rows;
    });
}

/**
 * Tries one Outbox email again: a lead's first email, follow-up or win-back through the normal send (every check, the current draft); any other email with the words it
 * had. Returns [ok, message].
 */
function pm_ml_retry(string $id): array
{
    $row = null;
    foreach (pm_ml_rows() as $r) {
        if (($r['id'] ?? '') === $id) {
            $row = $r;
            break;
        }
    }
    if (!$row || !empty($row['resolved'])) {
        return [false, 'That email is no longer in the Outbox.'];
    }
    if (($row['status'] ?? '') === 'unknown') {
        return [false, 'It is not known whether this one went out. Look in the Sent folder first; if it is not there, press "It did not go", then try again.'];
    }
    $was = pm_brand();
    pm_brand_set((string)($row['brand'] ?? $was));
    try {
        $lead = (string)($row['lead'] ?? '');
        if ($lead !== '' && in_array($row['kind'] ?? '', ['first', 'followup', 'winback'], true)) {
            $leads = pm_leads();
            if (!isset($leads[$lead])) {
                return [false, 'That lead no longer exists.'];
            }
            $res = pm_send_first($leads, $lead, ['first' => 'drafts', 'followup' => 'followup_draft', 'winback' => 'winback_draft'][$row['kind']]);
            if (!empty($res['ok'])) {
                pm_leads_save($leads);
                pm_ml_resolve($id, 'sent again');
            }
            return [!empty($res['ok']), (string)$res['msg']];
        }
        $m = (array)($row['payload'] ?? []);
        if (!$m || empty($m['to'])) {
            return [false, 'This email cannot be rebuilt here: send it again from where it was written.'];
        }
        [$ok, $err] = array_pad(pm_mail(pm_settings(), $m), 2, '');
        $ok && pm_ml_resolve($id, 'sent again');
        return [(bool)$ok, $ok ? 'Sent again to ' . $m['to'] . '.' : 'Still not sent: ' . (function_exists('pm_mail_fail') ? pm_mail_fail(pm_settings(), (string)$err) : $err)];
    } finally {
        pm_brand_set($was);
    }
}

/** The scheduler tries the Outbox again by itself, for hiccups only: up to 3 tries, waiting 15 minutes, then 1 hour, then 4 hours. Returns how many went. */
function pm_ml_retry_due(): int
{
    $n = 0;
    foreach (pm_ml_outbox() as $r) {
        $tries = (int)($r['tries'] ?? 0);
        $limited = function_exists('pm_smtp_rate_limited') && pm_smtp_rate_limited((string)($r['err'] ?? ''));
        $wait = $limited ? ([3900, 3900, 3900, 7200, 14400, 28800][$tries] ?? null) : ([900, 3600, 14400][$tries] ?? null); // a provider limit waits past the hour each time
        if ($limited && $wait !== null && ($room = pm_ml_room_now((string)($r['brand'] ?? 'promanaged'))) !== null && $room < 1) {
            continue; // the provider's hour is still full
        }
        if ($wait === null || ($r['status'] ?? '') !== 'failed' || !pm_ml_transient((string)($r['err'] ?? '')) || time() - (int)strtotime((string)($r['done'] ?? $r['at'])) < $wait) {
            continue;
        }
        pm_ml_write(function (array $rows) use ($r) {
            foreach ($rows as $i => $x) {
                if (($x['id'] ?? '') === $r['id']) {
                    $rows[$i]['tries'] = (int)($x['tries'] ?? 0) + 1;
                    $rows[$i]['done'] = date('Y-m-d H:i:s');
                }
            }
            return $rows;
        });
        [$ok] = pm_ml_retry((string)$r['id']);
        $n += $ok ? 1 : 0;
    }
    return $n;
}

/* ---------------- the mail provider's own pace (learned, not set by the app) ---------------- */

/**
 * How many emails left this mailbox in the last 60 minutes, counted in its Sent folder (so every copy of the app, and mail sent by hand, is counted). null when the
 * folder cannot be read.
 */
function pm_ml_sent_last_hour(string $brand): ?int
{
    try {
        [$fp, $acct] = pm_ml_imap($brand);
        if (!$fp || ($box = pm_ml_sent_box($fp, $acct)) === '' || !str_contains(pm_imap_cmd($fp, 'E3', 'EXAMINE "' . addcslashes($box, '"\\') . '"'), 'E3 OK')) {
            return null;
        }
        $res = pm_imap_cmd($fp, 'Q4', 'UID SEARCH SINCE ' . date('d-M-Y', time() - 3600));
        preg_match('/\* SEARCH([\d ]*)/', $res, $m);
        $uids = array_slice(array_values(array_filter(explode(' ', trim($m[1] ?? '')))), -300);
        if (!$uids) {
            return 0;
        }
        $res = pm_imap_cmd($fp, 'Q5', 'UID FETCH ' . implode(',', $uids) . ' (INTERNALDATE)');
        preg_match_all('/INTERNALDATE "([^"]+)"/', $res, $dm);
        return count(array_filter($dm[1], fn($d) => ($t = strtotime($d)) && $t > time() - 3600));
    } catch (Throwable) {
        return null;
    }
}

/** The provider refused for sending too much: remember how many it had taken in the hour before (its real allowance, learned from what happened). */
function pm_ml_learn_rate(string $brand): void
{
    $n = pm_ml_sent_last_hour($brand);
    if ($n === null || !function_exists('pm_imap_settings')) {
        return;
    }
    $c = pm_imap_settings($brand);
    $acct = strtolower($c['host'] . '|' . $c['user']);
    try {
        pm_update('mail_state', function (array $d) use ($acct, $n) {
            $was = (array)($d['provider_rate'][$acct] ?? []);
            $d['provider_rate'][$acct] = ['per_hour' => max(1, $n), 'seen' => date('Y-m-d H:i'), 'times' => (int)($was['times'] ?? 0) + 1,
                'lowest' => min($n ?: 1, (int)($was['lowest'] ?? PHP_INT_MAX))];
            return $d;
        }, fn() => []);
    } catch (Throwable) {
    }
}

/** What the provider is known to allow per hour for this mailbox, learned from its refusals: ['per_hour' => n, 'seen' => when] or []. */
function pm_ml_provider_rate(string $brand): array
{
    if (!function_exists('pm_imap_settings')) {
        require_once __DIR__ . '/engage.php';
    }
    $c = pm_imap_settings($brand);
    $r = (array)(pm_load('mail_state', fn() => [])['provider_rate'][strtolower($c['host'] . '|' . $c['user'])] ?? []);
    return isset($r['per_hour']) ? ['per_hour' => (int)min($r['per_hour'], $r['lowest'] ?? $r['per_hour']), 'seen' => (string)$r['seen']] : [];
}

/** How many more the provider will take in this hour: null while its allowance is not known (send, and learn from a refusal). */
function pm_ml_room_now(string $brand): ?int
{
    $r = pm_ml_provider_rate($brand);
    if (!$r) {
        return null;
    }
    $sent = pm_ml_sent_last_hour($brand);
    return $sent === null ? null : max(0, $r['per_hour'] - $sent);
}

/** Reads the Sent folders at most every 5 minutes when the Leads list opens (no AI: a short mailbox read), so a lead emailed from the other copy of the app is not in Active. */
function pm_ml_autosync(): int
{
    $st = pm_load('mail_state', fn() => []);
    if (time() - (int)($st['sync_at'] ?? 0) < 300) {
        return 0;
    }
    try {
        pm_update('mail_state', function (array $d) {
            $d['sync_at'] = time();
            return $d;
        }, fn() => []);
        if (!function_exists('pm_imap_boxes')) {
            require_once __DIR__ . '/engage.php';
        }
        $leads = pm_leads();
        $n = pm_ml_sync_sent($leads);
        $n && pm_leads_save($leads);
        return $n;
    } catch (Throwable) {
        return 0;
    }
}
