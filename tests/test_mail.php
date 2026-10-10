<?php
/**
 * Tests for the mail checks in Settings: why a login or a send fails and what to do (blocked ports, wrong security, wrong password, unknown host), where a
 * business's mail login comes from, and the test email (right business's mailbox, optional recipient, honest result). The network is stubbed.
 * Run: php tests/test_mail.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/mail.php';
pm_brand_set('promanaged');

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

$sm = ['host' => 'smtp.example.test', 'port' => 465, 'encryption' => 'ssl', 'username' => 'info@example.test', 'password' => 'x'];
$setProbe = function (array $open, bool $dns = true) {
    $GLOBALS['PM_SMTP_PROBE_STUB'] = fn($h, $p) => in_array($p, $open, true);
    $GLOBALS['PM_SMTP_DNS_STUB'] = fn($h) => $dns;
};

t('A failed connection says what is wrong and what to change', function () use ($sm, $setProbe) {
    $err = 'SMTP Error: Could not connect to SMTP host. Failed to connect to server';
    $setProbe([]);
    $m = pm_smtp_explain($sm, $err);
    pm_t_assert(str_contains($m, $err) && str_contains($m, 'cannot reach smtp.example.test on ports 465, 587 or 25') && str_contains($m, 'block outgoing mail connections') && str_contains($m, 'ask your host'), 'when no usual port opens, the host is the likely cause and the fix is said: ' . $m);
    $setProbe([587]);
    $m = pm_smtp_explain($sm, $err);
    pm_t_assert(str_contains($m, 'cannot reach smtp.example.test on port 465, but it can on port 587') && str_contains($m, 'SMTP_PORT=587') && str_contains($m, 'SMTP_SECURE=tls'), 'when another port opens, it names it and the security that goes with it: ' . $m);
    pm_brand_set('travel');
    pm_t_assert(str_contains(pm_smtp_explain($sm, $err), 'TM_SMTP_PORT=587'), 'Travel Malawi is told its own TM_ keys');
    pm_brand_set('promanaged');
    $setProbe([465]);
    $m = pm_smtp_explain(['port' => 465, 'encryption' => 'tls'] + $sm, $err);
    pm_t_assert(str_contains($m, 'can reach smtp.example.test on port 465, but the secure start did not complete') && str_contains($m, 'yours is tls on port 465'), 'a port that opens but a security that does not fit is said as that: ' . $m);
    $setProbe([465], false);
    pm_t_assert(str_contains(pm_smtp_explain($sm, 'php_network_getaddresses: getaddrinfo failed'), 'could not be found from this server'), 'a name that does not exist says so, before anything about ports');
    $setProbe([465, 587, 25]);
    pm_t_assert(str_contains(pm_smtp_explain($sm, 'Connection timed out'), 'secure start did not complete'), 'a timeout is treated as a connection failure');
});

t('Other failures: login, secure connection, sender', function () use ($sm, $setProbe) {
    $setProbe([465]);
    pm_t_assert(str_contains(pm_smtp_explain($sm, 'SMTP Error: Could not authenticate.'), 'smtp.example.test refused the login for info@example.test: check the username (usually the full email address) and the password'), 'a wrong password says so');
    pm_t_assert(str_contains(pm_smtp_explain($sm, '535 5.7.8 Username and Password not accepted'), 'refused the login'), 'including the server\'s own wording');
    pm_t_assert(str_contains(pm_smtp_explain($sm, 'SMTP Error: SSL certificate verify failed'), 'Port 465 goes with security ssl'), 'a certificate or secure-connection problem names the port and security pairing');
    pm_t_assert(str_contains(pm_smtp_explain($sm, 'SMTP Error: MAIL FROM command failed. Sender address rejected: not owned by user'), 'send from the address you log in with'), 'a refused sender address says what to use');
    pm_t_eq(pm_smtp_explain($sm, 'Something unexpected'), 'Something unexpected', 'anything else is passed through unchanged');
    pm_t_assert(str_contains(pm_smtp_explain(['host' => ''] + $sm, 'Could not connect'), 'No mail server is set'), 'no server at all is said');
    $GLOBALS['PM_SMTP_PROBE_STUB'] = $GLOBALS['PM_SMTP_DNS_STUB'] = null;
});

t('Where a business\'s mail login comes from', function () use ($T) {
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['smtp']['host'] = '';
    $raw['travel']['smtp']['host'] = '';
    pm_save('settings', $raw);
    pm_t_eq([pm_mail_source('promanaged'), pm_mail_source('travel')], ['none', 'none'], 'nothing set: none');
    $raw['smtp']['host'] = 'mail.pm.example';
    $raw['smtp']['username'] = 'info@pm.example';
    pm_save('settings', $raw);
    pm_t_eq([pm_mail_source('promanaged'), pm_mail_source('travel')], ['settings', 'shared'], 'ProManaged IT has one in Settings; Travel Malawi has none, so it would use ProManaged IT\'s');
    $raw['travel']['smtp'] = ['host' => 'mail.tm.example', 'port' => 465, 'encryption' => 'ssl', 'username' => 'info@tm.example', 'password' => 'p', 'from_email' => 'info@tm.example', 'from_name' => 'Travel Malawi', 'bcc_self' => false];
    pm_save('settings', $raw);
    pm_t_eq(pm_mail_source('travel'), 'settings', 'once Travel Malawi has its own, that is the source');
    $rows = array_column(pm_setup_health(), null, 'key');
    pm_t_assert($rows['smtp_travel']['ok'] && str_contains($rows['smtp_travel']['detail'], 'info@tm.example through mail.tm.example') && !str_contains($rows['smtp_travel']['detail'], 'no mail login of its own'), 'Setup health names the mailbox and server it really uses: ' . $rows['smtp_travel']['detail']);
    $raw['travel']['smtp']['host'] = '';
    pm_save('settings', $raw);
    $rows = array_column(pm_setup_health(), null, 'key');
    pm_t_assert(str_contains($rows['smtp_travel']['detail'], 'no mail login of its own') && str_contains($rows['smtp_travel']['detail'], 'TM_SMTP_HOST'), 'and says plainly when a business is borrowing another\'s mailbox, and what to add: ' . $rows['smtp_travel']['detail']);
});

t('The test email uses the right business\'s mailbox and tells the truth', function () {
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['smtp'] = ['host' => 'mail.pm.example', 'port' => 465, 'encryption' => 'ssl', 'username' => 'info@pm.example', 'password' => 'pw', 'from_email' => 'info@pm.example', 'from_name' => 'ProManaged IT', 'bcc_self' => false];
    $raw['travel']['smtp'] = ['host' => 'mail.tm.example', 'port' => 587, 'encryption' => 'tls', 'username' => 'hello@tm.example', 'password' => 'pw2', 'from_email' => 'hello@tm.example', 'from_name' => 'Travel Malawi', 'bcc_self' => false];
    pm_save('settings', $raw);
    $sent = [];
    $GLOBALS['PM_MAIL_STUB'] = function ($s, $m) use (&$sent) {
        $sent[] = [$s['smtp']['host'], $s['smtp']['from_email'], $s['company_name'], $m['to'], $m['subject'], $m['body']];
        return [true, '', 'id1'];
    };
    pm_brand_set('promanaged');
    [$ok, $msg] = pm_mail_test('travel');
    pm_t_assert($ok && $sent[0][0] === 'mail.tm.example' && $sent[0][1] === 'hello@tm.example' && $sent[0][2] === 'Travel Malawi' && $sent[0][3] === 'hello@tm.example' && $sent[0][4] === 'Test email from Travel Malawi',
        'the Travel Malawi test goes through Travel Malawi\'s own server and address, to its own mailbox, even when ProManaged IT is on screen: ' . json_encode($sent[0]));
    pm_t_assert(str_contains($msg, 'A test email was sent to hello@tm.example as hello@tm.example through mail.tm.example:587') && str_contains($msg, 'login from Settings') && str_contains($msg, 'spam folder'), 'and says where it went and where the login comes from: ' . $msg);
    pm_t_assert(str_contains($sent[0][5], 'mail.tm.example:587 (tls security)'), 'the email itself says how it was sent');
    pm_t_eq(pm_brand(), 'promanaged', 'the app is back on the business it was on');
    [$ok] = pm_mail_test('promanaged', ' you@gmail.example ');
    pm_t_assert($ok && $sent[1][0] === 'mail.pm.example' && $sent[1][3] === 'you@gmail.example', 'a different recipient (tidied) is honoured: that is how a real outside inbox is tested');
    $n = count($sent);
    [$ok, $msg] = pm_mail_test('promanaged', 'not-an-address');
    pm_t_assert(!$ok && str_contains($msg, 'no valid address') && count($sent) === $n, 'a recipient that is not an address is refused before anything is sent');
    [$ok, $msg] = pm_mail_test('nobody');
    pm_t_assert($ok && $sent[$n][2] === 'ProManaged IT', 'an unknown business is treated as ProManaged IT, as everywhere else');
    // a failure carries the reason and what to do
    $GLOBALS['PM_MAIL_STUB'] = fn() => [false, 'SMTP Error: Could not authenticate.', ''];
    [$ok, $msg] = pm_mail_test('travel');
    pm_t_assert(!$ok && str_starts_with($msg, 'The test email failed.') && str_contains($msg, 'mail.tm.example refused the login for hello@tm.example'), 'a failed test says why: ' . $msg);
    // no login at all
    $GLOBALS['PM_MAIL_STUB'] = fn() => [true, '', ''];
    $raw['travel']['smtp']['host'] = '';
    $raw['smtp']['host'] = '';
    pm_save('settings', $raw);
    [$ok, $msg] = pm_mail_test('travel');
    pm_t_assert(!$ok && str_contains($msg, 'has no mail login yet') && str_contains($msg, 'TM_SMTP_HOST') && str_contains($msg, 'TM_SMTP_PASS'), 'no mail login says which .env lines to add: ' . $msg);
    $GLOBALS['PM_MAIL_STUB'] = null;
});

pm_t_done();
