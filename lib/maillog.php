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
    pm_ml_write(function (array $rows) use ($row) {
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
