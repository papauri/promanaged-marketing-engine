<?php
require_once PM_ROOT . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Send an email with PDF attachments.
 * $m = [to, cc?, subject, body (plain text), attachments? (paths), button? [label, url], bcc_self? (bool)]
 * Returns [true, '', messageId] on success or [false, 'reason', ''] (callers may destructure just [ok, why]).
 */
function pm_mail(array $s, array $m): array
{
    if (isset($GLOBALS['PM_MAIL_STUB']) && is_callable($GLOBALS['PM_MAIL_STUB'])) { // tests: no network
        return (array)$GLOBALS['PM_MAIL_STUB']($s, $m);
    }
    $smtp = $s['smtp'];
    if (trim($smtp['host']) === '' || trim($smtp['username']) === '') {
        return [false, 'Email is not set up yet. Add the mail server details to .env or Settings.', ''];
    }
    if (!filter_var($m['to'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return [false, 'The email address is not valid.', ''];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        if ($smtp['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtp['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        $from = $smtp['from_email'] ?: $smtp['username'];
        $fromName = $smtp['from_name'] ?: $s['company_name'];
        $mail->setFrom($from, $fromName);
        $mail->addReplyTo($from, $fromName);
        $mail->addAddress($m['to']);
        foreach (preg_split('/[,;\s]+/', (string)($m['cc'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($c);
            }
        }
        if (($m['bcc_self'] ?? !empty($smtp['bcc_self'])) && strcasecmp($m['to'], $from) !== 0) {
            $mail->addBCC($from);
        }

        $logo = pm_logo_path();
        $hasLogo = $logo !== '';
        if ($hasLogo) {
            $mail->addEmbeddedImage($logo, 'pmlogo', 'logo.png');
        }
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['accent_color'] ?? '')) ? $s['accent_color'] : '#17375E';

        $text = htmlspecialchars((string)$m['body'], ENT_QUOTES, 'UTF-8');
        $text = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:' . $accent . ';">$1</a>', $text);
        $button = '';
        if (!empty($m['button'][1])) {
            $button = '<tr><td style="padding:6px 0 26px;"><a href="' . htmlspecialchars($m['button'][1], ENT_QUOTES) . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:13px 26px;border-radius:6px;">'
                . htmlspecialchars($m['button'][0], ENT_QUOTES) . '</a></td></tr>';
        }
        foreach ((array)($m['headers'] ?? []) as $hn => $hv) {
            $mail->addCustomHeader((string)$hn, (string)$hv);
        }
        $linkHtml = '';
        if (!empty($m['link']['url'])) { // website link card with thumbnail, under the message
            $lk = $m['link'];
            $u = htmlspecialchars($lk['url'], ENT_QUOTES);
            if (!empty($lk['img']) && is_file($lk['img'])) {
                $mail->addEmbeddedImage($lk['img'], 'pmlink', 'preview.jpg', 'base64', 'image/jpeg');
            }
            $linkHtml = '<tr><td style="padding:0 0 22px;"><a href="' . $u . '" style="text-decoration:none;color:inherit;display:block;border:1px solid #e6e8eb;border-radius:8px;overflow:hidden;max-width:420px;">'
                . (!empty($lk['img']) ? '<img src="cid:pmlink" alt="" width="420" style="display:block;width:100%;max-width:420px;height:auto;">' : '')
                . '<span style="display:block;padding:10px 14px;font-family:Arial,Helvetica,sans-serif;"><b style="font-size:14px;color:#1f2328;">' . htmlspecialchars($lk['title'] ?: $lk['url'], ENT_QUOTES) . '</b>'
                . ($lk['desc'] ? '<br><span style="font-size:12px;color:#5a5f66;">' . htmlspecialchars(mb_substr($lk['desc'], 0, 140), ENT_QUOTES) . '</span>' : '')
                . '<br><span style="font-size:11px;color:#8a9099;">' . htmlspecialchars((string)parse_url($lk['url'], PHP_URL_HOST), ENT_QUOTES) . '</span></span></a></td></tr>';
        }
        $mail->Subject = (string)$m['subject'];
        $mail->isHTML(true);
        $mail->Body = '<!doctype html><html><body style="margin:0;padding:0;background:#f4f5f7;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e6e8eb;border-radius:8px;">'
            . '<tr><td style="padding:26px 34px 6px;border-bottom:1px solid #eef0f2;">' . ($hasLogo ? (function () use ($logo, $s) { $ls = @getimagesize($logo); $sq = $ls && $ls[1] > 0 && ($ls[0] / $ls[1]) < 1.4;
            return '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td><img src="cid:pmlogo" alt="' . htmlspecialchars($s['company_name'], ENT_QUOTES) . '" height="' . ($sq ? 34 : 38) . '" style="display:block;height:' . ($sq ? 34 : 38) . 'px;"></td>'
                . ($sq ? '<td style="padding-left:10px;font-family:Georgia,serif;font-size:20px;font-weight:bold;color:#1c1917;">' . htmlspecialchars($s['company_name']) . '</td>' : '') . '</tr></table>'; })() : '<b>' . htmlspecialchars($s['company_name']) . '</b>') . '<div style="height:16px"></div></td></tr>'
            . '<tr><td style="padding:26px 34px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2328;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding-bottom:18px;">' . nl2br($text) . '</td></tr>' . $linkHtml . $button . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 34px 24px;border-top:1px solid #eef0f2;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8a9099;">'
            . htmlspecialchars(implode('  ·  ', array_filter([$s['company_name'], $s['phone'] ?? '', $s['email'] ?? '', $s['website'] ?? ''])), ENT_QUOTES) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
        $mail->AltBody = (string)$m['body'] . (!empty($m['link']['url']) ? "\n\n" . $m['link']['url'] : '') . (!empty($m['button'][1]) ? "\n\n" . $m['button'][0] . ': ' . $m['button'][1] : '');
        foreach ((array)($m['attachments'] ?? []) as $path) {
            if (is_file($path)) {
                $mail->addAttachment($path, basename($path), PHPMailer::ENCODING_BASE64, 'application/pdf');
            }
        }

        $mail->send();
        return [true, '', (string)$mail->getLastMessageID()];
    } catch (MailException $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage(), ''];
    }
}

/**
 * Tell the owner something happened (reply, proposal opened, bounce). Sent to the Email in Settings from the configured SMTP.
 * Same subject is sent at most once per 6 hours (data/notify_state.json). Never throws.
 */
function pm_notify_owner(string $subject, string $text): bool
{
    try {
        $s = pm_settings();
        $to = trim((string)($s['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $key = md5($subject);
        $lock = @fopen(PM_DATA . '/notify_state.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $f = PM_DATA . '/notify_state.json';
            $st = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
            $now = time();
            $st = array_filter($st, fn($t) => is_int($t) && $t > $now - 6 * 3600);
            if (isset($st[$key])) {
                return false;
            }
            [$ok] = pm_mail($s, ['to' => $to, 'subject' => mb_substr($subject, 0, 150), 'body' => $text, 'bcc_self' => false]);
            if ($ok) {
                $st[$key] = $now;
                pm_save('notify_state', $st);
            }
            return (bool)$ok;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    } catch (Throwable $e) {
        return false;
    }
}

/** Older call style, kept for the Settings test email. */
function pm_send_mail(array $s, string $to, string $cc, string $subject, string $body, string $pdfPath): array
{
    return pm_mail($s, ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'body' => $body, 'attachments' => [$pdfPath]]);
}

/* ---------------- Why a mail login or a send failed, and a test email that tells the truth ---------------- */

/** Can this server open a plain connection to host:port? (tests replace it with $GLOBALS['PM_SMTP_PROBE_STUB']) */
function pm_smtp_probe(string $host, int $port): bool
{
    if (isset($GLOBALS['PM_SMTP_PROBE_STUB']) && is_callable($GLOBALS['PM_SMTP_PROBE_STUB'])) {
        return (bool)$GLOBALS['PM_SMTP_PROBE_STUB']($host, $port);
    }
    $f = @fsockopen($host, $port, $en, $es, 3);
    if ($f) {
        fclose($f);
        return true;
    }
    return false;
}

/** Does this mail server name exist, as seen from this server? */
function pm_smtp_dns_ok(string $host): bool
{
    if (isset($GLOBALS['PM_SMTP_DNS_STUB']) && is_callable($GLOBALS['PM_SMTP_DNS_STUB'])) {
        return (bool)$GLOBALS['PM_SMTP_DNS_STUB']($host);
    }
    return filter_var($host, FILTER_VALIDATE_IP) !== false || (bool)@gethostbynamel($host);
}

/**
 * What went wrong, in plain words, and what to do about it. $err is what the mail library said. When the connection itself failed, it also tries the other
 * usual ports (465, 587, 25) from THIS server, because the commonest cause on a website host is that outgoing mail connections to other servers are blocked.
 */
function pm_smtp_explain(array $sm, string $err): string
{
    $host = trim((string)($sm['host'] ?? ''));
    $port = (int)($sm['port'] ?? 0);
    $enc = (string)($sm['encryption'] ?? '') ?: 'none';
    $p = function_exists('pm_brand_env_prefix') ? pm_brand_env_prefix(pm_brand()) : '';
    $err = trim((string)preg_replace('/\s+/', ' ', $err));
    $e = strtolower($err);
    $out = [mb_substr($err, 0, 220)];
    $connect = (bool)preg_match('/could not connect|connect\(\) failed|connection (timed out|refused|reset)|network is unreachable|getaddrinfo|php_network_getaddresses|name or service not known|no route to host|timed out|failed to connect|unable to connect/', $e);
    if ($host === '') {
        $out[] = 'No mail server is set.';
    } elseif ($connect) {
        if (!pm_smtp_dns_ok($host)) {
            $out[] = "The name $host could not be found from this server: check how the mail server is spelled.";
        } elseif (pm_smtp_probe($host, $port)) {
            $out[] = "This server can reach $host on port $port, but the secure start did not complete. Port 465 goes with security ssl and port 587 with tls; yours is $enc on port $port.";
        } else {
            $open = [];
            foreach ([465 => 'ssl', 587 => 'tls', 25 => 'none'] as $pp => $ee) {
                if ($pp !== $port && pm_smtp_probe($host, $pp)) {
                    $open[$pp] = $ee;
                }
            }
            if ($open) {
                $first = (int)array_key_first($open);
                $out[] = "This server cannot reach $host on port $port, but it can on port $first: set {$p}SMTP_PORT=$first and {$p}SMTP_SECURE=" . $open[$first] . ' in .env (or the Port and Security boxes in Settings).';
            } else {
                $out[] = "This server cannot reach $host on ports 465, 587 or 25. Web hosts often block outgoing mail connections to other servers: ask your host to allow outgoing connections to $host, or use a mailbox on the same host, or an email-sending service.";
            }
        }
    } elseif (preg_match('/authenticat|\b53[45]\b|username and password|invalid login|not accepted|credentials|auth/', $e)) {
        $out[] = "$host refused the login for " . (string)($sm['username'] ?? '') . ': check the username (usually the full email address) and the password.';
    } elseif (preg_match('/ssl|tls|certificate|handshake|crypto/', $e)) {
        $out[] = "The secure connection to $host failed. Port 465 goes with security ssl and port 587 with tls; yours is $enc on port $port. If the certificate is for another name, use the mail server name your host gives you.";
    } elseif (preg_match('/sender|from address|not owned|relay|not allowed|rejected/', $e)) {
        $out[] = 'The server refused the sender address: send from the address you log in with, or one this mailbox is allowed to use.';
    }
    return implode(' ', $out);
}

/** A send failure for a screen or a log: what the mail library said, plus what it means and what to do (when it can tell). */
function pm_mail_fail(array $settings, string $why): string
{
    return pm_smtp_explain((array)($settings['smtp'] ?? []), $why);
}

/** Where a business's mail login comes from: 'env' (its own .env lines), 'settings' (typed in Settings), 'shared' (Travel Malawi using ProManaged IT's), 'none'. */
function pm_mail_source(string $brand): string
{
    $e = pm_env();
    $p = pm_brand_env_prefix($brand);
    if (($e[$p . 'SMTP_HOST'] ?? '') !== '') {
        return 'env';
    }
    $raw = pm_load('settings', 'pm_default_settings');
    $blk = $brand === 'promanaged' ? $raw : (array)($brand === 'travel' ? ($raw['travel'] ?? []) : ($raw['brands'][$brand] ?? []));
    if (trim((string)($blk['smtp']['host'] ?? '')) !== '') {
        return 'settings';
    }
    return $brand === 'travel' && (($e['SMTP_HOST'] ?? '') !== '' || trim((string)($raw['smtp']['host'] ?? '')) !== '') ? 'shared' : 'none';
}

/**
 * Sends one plain test email as a business, through its own mail login, to $to (default: the mailbox itself). Same function, settings and library as every
 * real email, so what it says is true. Returns [ok, message]; a failure carries the reason and what to do.
 */
function pm_mail_test(string $brand, string $to = ''): array
{
    $brand = pm_brand_valid($brand) ? $brand : 'promanaged';
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $sm = (array)$s['smtp'];
        $name = (string)($s['company_name'] ?? pm_brand_name($brand));
        $own = trim((string)($sm['from_email'] ?? '')) ?: trim((string)($sm['username'] ?? ''));
        $to = trim($to) !== '' ? trim($to) : $own;
        if (trim((string)($sm['host'] ?? '')) === '' || trim((string)($sm['username'] ?? '')) === '') {
            return [false, "$name has no mail login yet: add " . pm_brand_env_prefix($brand) . 'SMTP_HOST, ' . pm_brand_env_prefix($brand) . 'SMTP_USER and ' . pm_brand_env_prefix($brand) . 'SMTP_PASS to .env, or fill in Settings.'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'There is no valid address to send the test to. Type one in the box beside the button.'];
        }
        $src = ['env' => '.env', 'settings' => 'Settings', 'shared' => "ProManaged IT's mailbox (this business has none of its own)", 'none' => 'nowhere'][pm_mail_source($brand)] ?? '';
        [$ok, $err] = array_pad(pm_mail($s, ['to' => $to, 'subject' => 'Test email from ' . $name, 'bcc_self' => false, 'link' => function_exists('pm_link_card') ? pm_link_card() : null,
            'body' => "This is a test from the $name mailbox.\n\nIt was sent " . date('j M Y, H:i') . ' as ' . ($sm['from_email'] ?: $sm['username']) . ' through ' . $sm['host'] . ':' . $sm['port'] . ' (' . ($sm['encryption'] ?: 'no') . " security).\n\nIf you can read this, email is working."]), 2, '');
        return $ok ? [true, "A test email was sent to $to as " . ($sm['from_email'] ?: $sm['username']) . " through {$sm['host']}:{$sm['port']} (login from $src). Check that inbox, and the spam folder."]
            : [false, 'The test email failed. ' . pm_smtp_explain($sm, (string)$err)];
    } finally {
        pm_brand_set($was);
    }
}
