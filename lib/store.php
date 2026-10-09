<?php
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/lines.php';
require_once __DIR__ . '/links.php';
date_default_timezone_set('Africa/Blantyre');

define('PM_ROOT', dirname(__DIR__));
define('PM_DATA', PM_ROOT . '/data');
define('PM_OUT', PM_ROOT . '/output');


function pm_load(string $name, callable $default): array
{
    $file = PM_DATA . "/$name.json";
    if (!is_file($file)) {
        $data = $default();
        pm_save($name, $data);
        return $data;
    }
    $raw = (string)file_get_contents($file);
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    if (strlen($raw) <= 2) {
        return $default(); // empty file: nothing to lose
    }
    // Non-empty but unreadable: never pretend it is empty (the next save would wipe it). Restore the newest good backup, else stop.
    foreach (pm_backups($name) as $b) {
        $bd = json_decode((string)file_get_contents($b), true);
        if (is_array($bd)) {
            @mkdir(PM_DATA . "/backup", 0775, true);
            @copy($file, PM_DATA . "/backup/$name.corrupt." . date('YmdHis') . '.bad');
            if (@copy($b, $file)) {
                error_log("pm_load: $name.json was corrupt, restored from " . basename($b));
                return $bd;
            }
        }
    }
    throw new RuntimeException("data/$name.json is damaged and no valid backup exists. Fix or restore the file before continuing.");
}

/** Daily backups of a data file, newest first. */
function pm_backups(string $name): array
{
    $l = glob(PM_DATA . '/backup/' . $name . '.[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].json') ?: [];
    rsort($l);
    return $l;
}

function pm_save(string $name, array $data): void
{
    if (!is_dir(PM_DATA)) {
        mkdir(PM_DATA, 0775, true);
    }
    if ($name === 'settings' && function_exists('pm_smtp_from_env') && pm_smtp_from_env()) {
        // Mail server details live in .env only; never copy them into settings.json.
        $data['smtp'] = array_merge($data['smtp'] ?? [], ['host' => '', 'username' => '', 'password' => '']);
    }
    // A bad string must never wipe a file: substitute invalid UTF-8, and refuse to write anything that did not encode.
    $j = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($j === false || $j === '' || ($data && in_array($j, ['[]', '{}', 'null'], true)) || !is_array(json_decode($j, true))) {
        throw new RuntimeException("pm_save($name): data could not be encoded (" . json_last_error_msg() . '); nothing was written.');
    }
    $file = PM_DATA . "/$name.json";
    $tmp = PM_DATA . "/$name.json." . getmypid() . uniqid() . '.tmp';
    if (file_put_contents($tmp, $j, LOCK_EX) !== strlen($j)) {
        @unlink($tmp);
        throw new RuntimeException("pm_save($name): short write; nothing was replaced.");
    }
    if (is_file($file) && filesize($file) > 2) { // one backup per day, newest 7 kept
        $dir = PM_DATA . '/backup';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $b = "$dir/$name." . date('Ymd') . '.json';
        if (is_dir($dir) && !is_file($b)) {
            @copy($file, $b);
            foreach (array_slice(pm_backups($name), 7) as $old) {
                @unlink($old);
            }
        }
    }
    for ($i = 0; $i < 3; $i++) { // Windows / OneDrive can briefly hold the file
        if (@rename($tmp, $file)) {
            return;
        }
        usleep(150000);
    }
    @unlink($tmp);
    throw new RuntimeException("pm_save($name): could not replace the file.");
}

/** Read-modify-write under an exclusive lock, so two processes never overwrite each other. $fn(array $data): array returns the new data. */
function pm_update(string $name, callable $fn, ?callable $default = null): array
{
    if (!is_dir(PM_DATA)) {
        mkdir(PM_DATA, 0775, true);
    }
    $lock = fopen(PM_DATA . "/$name.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException("pm_update($name): could not lock.");
    }
    try {
        $new = $fn(pm_load($name, $default ?? fn() => []));
        if (!is_array($new)) {
            throw new RuntimeException("pm_update($name): the callback must return an array.");
        }
        pm_save($name, $new);
        return $new;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Read KEY=value lines from .env (no quotes needed; surrounding quotes are stripped). */
function pm_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $env = [];
    $file = PM_ROOT . '/.env';
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $env[trim($k)] = $v;
        }
    }
    return $env;
}

/** True when the mail server details come from .env (the Settings screen then shows them read-only). */
function pm_smtp_from_env(): bool
{
    return (pm_env()['SMTP_HOST'] ?? '') !== '';
}

/* ---------------- Brands: ProManaged IT (default) and Travel Malawi ---------------- */

function pm_brand_set(string $b): void { $GLOBALS['PM_BRAND'] = $b === 'travel' ? 'travel' : 'promanaged'; }
function pm_brand(): string { return $GLOBALS['PM_BRAND'] ?? 'promanaged'; }
function pm_brand_of_type(string $type): string { return $type === 'onboarding' ? 'travel' : 'promanaged'; }
function pm_set_free(bool $f): void { $GLOBALS['PM_FREE'] = $f; }
function pm_set_from(bool $f): void { $GLOBALS['PM_FROM'] = $f; } // quotations show "From" prices instead of exact ones

/** Logo file for the current brand, or '' if there is none (the brand name is then printed as text). */
/** Each business signs its own agreements: Travel Malawi never uses ProManaged's signature. */
function pm_signature_path(): string
{
    return PM_ROOT . (pm_brand() === 'travel' ? '/assets/travel_signature.png' : '/assets/signature.png');
}

function pm_logo_path(): string
{
    $f = PM_ROOT . (pm_brand() === 'travel' ? '/assets/travel_logo.png' : '/assets/logo.png');
    return is_file($f) ? $f : '';
}

/**
 * One-time: an install whose price list was kept in dollars is moved to Kwacha. Every price becomes the Kwacha amount it was already
 * quoted at (dollars x the Kwacha rate, to the nearest 1,000), and the exchange rates are re-expressed per Kwacha.
 */
function pm_migrate_to_mwk(): void
{
    $raw = pm_load('settings', 'pm_default_settings');
    if (($raw['currency'] ?? 'USD') === 'MWK' || !empty($raw['_mwk_base'])) {
        return;
    }
    $mwk = 0.0;
    foreach ($raw['currencies'] ?? [] as $c) {
        if (strtoupper((string)($c['code'] ?? '')) === 'MWK') {
            $mwk = (float)$c['rate'];
        }
    }
    $mwk = $mwk > 0 ? $mwk : 4200.0; // Kwacha per 1 old base unit
    $tplFile = PM_DATA . '/template.json';
    if (is_file($tplFile)) {
        $t = json_decode((string)file_get_contents($tplFile), true);
        if (is_array($t)) {
            $k = fn($v) => ((float)$v) > 0 ? round((float)$v * $mwk / 1000) * 1000 : 0;
            foreach ($t['packages'] ?? [] as $i => $p) {
                $t['packages'][$i]['setup'] = $k($p['setup'] ?? 0);
                $t['packages'][$i]['monthly'] = $k($p['monthly'] ?? 0);
            }
            foreach ($t['extras'] ?? [] as $i => $e) {
                $t['extras'][$i]['price'] = $k($e['price'] ?? 0);
            }
            pm_save('template', $t);
        }
    }
    $cur = [];
    foreach ($raw['currencies'] ?? pm_default_settings()['currencies'] as $c) {
        $code = strtoupper((string)($c['code'] ?? ''));
        $cur[] = ['code' => $code, 'name' => (string)($c['name'] ?? $code), 'rate' => $code === 'MWK' ? 1 : (float)$c['rate'] / $mwk, 'round' => $code === 'MWK' ? max(1000, (float)($c['round'] ?? 1000)) : (float)($c['round'] ?? 1)];
    }
    if (!array_filter($cur, fn($c) => $c['code'] === 'MWK')) {
        array_unshift($cur, ['code' => 'MWK', 'name' => 'Malawi Kwacha', 'rate' => 1, 'round' => 1000]);
    }
    $raw['currencies'] = $cur;
    $raw['currency'] = 'MWK';
    $raw['default_quote_currency'] = 'MWK';
    $raw['_mwk_base'] = true;
    pm_save('settings', $raw);
}

/** True when Travel Malawi has its own mail server details in .env (TM_SMTP_HOST ...). */
function pm_tm_smtp_from_env(): bool
{
    return (pm_env()['TM_SMTP_HOST'] ?? '') !== '';
}

function pm_settings(): array
{
    pm_migrate_to_mwk();
    $stored = pm_load('settings', 'pm_default_settings');
    $s = array_replace_recursive(pm_default_settings(), $stored);
    if (isset($stored['currencies']) && is_array($stored['currencies'])) {
        $s['currencies'] = array_values($stored['currencies']); // a saved list replaces the defaults, never merges
    }
    $e = pm_env();
    if (pm_smtp_from_env()) {
        $s['smtp']['host'] = $e['SMTP_HOST'];
        $s['smtp']['port'] = (int)($e['SMTP_PORT'] ?? 587);
        $sec = strtolower($e['SMTP_SECURE'] ?? 'tls');
        $s['smtp']['encryption'] = in_array($sec, ['ssl', 'tls'], true) ? $sec : 'none';
        $s['smtp']['username'] = $e['SMTP_USER'] ?? '';
        $s['smtp']['password'] = $e['SMTP_PASS'] ?? '';
        $s['smtp']['from_email'] = $e['SMTP_FROM'] ?? ($e['SMTP_USER'] ?? $s['smtp']['from_email']);
        $s['smtp']['from_name'] = $e['SMTP_FROM_NAME'] ?? $s['smtp']['from_name'];
    }
    $s['travel'] = array_replace(pm_default_settings()['travel'], (array)($stored['travel'] ?? []));
    if (pm_brand() === 'travel') { // speak as Travel Malawi: name, contact details, colour, sender name
        $t = $s['travel'];
        foreach (['company_name', 'tagline', 'accent_color', 'ref_prefix'] as $k) {
            if (trim((string)($t[$k] ?? '')) !== '') {
                $s[$k] = $t[$k];
            }
        }
        // Contact details and signatory are Travel Malawi's own: never fall back to ProManaged's.
        foreach (['address', 'phone', 'email', 'website', 'signatory_name', 'signatory_title'] as $k) {
            $s[$k] = trim((string)($t[$k] ?? ''));
        }
        if ($s['website'] === '' && trim((string)($t['link_url'] ?? '')) !== '') {
            $s['website'] = preg_replace('#^https?://#i', '', rtrim((string)$t['link_url'], '/'));
        }
        $s['next_ref'] = (int)($t['next_ref'] ?? 1);
        $s['online_signing'] = !empty($t['online_signing']);
        $s['esign_tags'] = !empty($t['esign_tags']);
        $ts = (array)($t['smtp'] ?? []);
        $s['smtp']['from_name'] = trim((string)($ts['from_name'] ?? '')) ?: $t['company_name'];
        $s['smtp']['bcc_self'] = !empty($ts['bcc_self']);
        if (trim((string)($t['from_email'] ?? '')) !== '') {
            $s['smtp']['from_email'] = $t['from_email']; // older setting
        }
        if (!pm_tm_smtp_from_env() && trim((string)($ts['host'] ?? '')) !== '') { // Travel Malawi's own mailbox, entered in Settings
            foreach (['host', 'username', 'from_email'] as $k) {
                $s['smtp'][$k] = (string)$ts[$k];
            }
            $s['smtp']['port'] = (int)($ts['port'] ?? 465);
            $s['smtp']['encryption'] = (string)($ts['encryption'] ?? 'ssl');
            $s['smtp']['password'] = (string)($ts['password'] ?? '');
        }
        if (pm_tm_smtp_from_env()) { // Travel Malawi's own mail account, separate from ProManaged's
            $s['smtp']['host'] = $e['TM_SMTP_HOST'];
            $s['smtp']['port'] = (int)($e['TM_SMTP_PORT'] ?? 587);
            $sec = strtolower($e['TM_SMTP_SECURE'] ?? 'tls');
            $s['smtp']['encryption'] = in_array($sec, ['ssl', 'tls'], true) ? $sec : 'none';
            $s['smtp']['username'] = $e['TM_SMTP_USER'] ?? '';
            $s['smtp']['password'] = $e['TM_SMTP_PASS'] ?? '';
            $s['smtp']['from_email'] = $e['TM_SMTP_FROM'] ?? ($e['TM_SMTP_USER'] ?? $s['smtp']['from_email']);
            $s['smtp']['from_name'] = $e['TM_SMTP_FROM_NAME'] ?? $s['smtp']['from_name'];
        }
    }
    return $s;
}
function pm_template(): array
{
    // Saved template wins; sections added in later versions come from the defaults.
    $t = array_replace(pm_default_template(), pm_load('template', 'pm_default_template'));
    // One-time: add the packages for our other business lines (software, web, hardware, support) to a saved template.
    if (empty($t['_lines_added'])) {
        $have = array_map(fn($p) => strtolower((string)($p['name'] ?? '')), $t['packages']);
        foreach (pm_default_template()['packages'] as $dp) {
            if (in_array($dp['type'] ?? '', ['software', 'web', 'hardware', 'support'], true) && !in_array(strtolower($dp['name']), $have, true)) {
                $t['packages'][] = $dp;
            }
        }
        $t['_lines_added'] = true;
        pm_save('template', $t);
    }
    if (empty($t['_honest_v1'])) { // one-time: replace over-promising wording, but only where it was never changed by hand
        $legacy = json_decode((string)@file_get_contents(__DIR__ . '/legacy_template.json'), true) ?: [];
        $def = pm_default_template();
        foreach ($legacy as $k => $old) {
            if (($t[$k] ?? null) == $old || ($k === 'fees_note' && str_contains((string)($t[$k] ?? ''), 'US dollars'))) {
                $t[$k] = $def[$k];
            }
        }
        $swap = ['Keeps trading through outages' => 'Made for local conditions', 'Already in daily use' => 'Already in use'];
        foreach ($t['benefits'] as $i => $b) {
            if (isset($swap[$b[0] ?? ''])) {
                foreach ($def['benefits'] as $db) {
                    if ($db[0] === $swap[$b[0]]) {
                        $t['benefits'][$i][0] = $db[0];
                        $t['benefits'][$i][1] = $db[1];
                    }
                }
            }
        }
        $t['_honest_v1'] = true;
        pm_save('template', $t);
    }
    if (empty($t['_travel_added'])) { // one-time: the Travel Malawi onboarding package
        $names = array_map(fn($p) => strtolower((string)($p['name'] ?? '')), $t['packages']);
        foreach (pm_default_template()['packages'] as $dp) {
            if (($dp['type'] ?? '') === 'onboarding' && !in_array(strtolower($dp['name']), $names, true)) {
                $t['packages'][] = $dp;
            }
        }
        $t['_travel_added'] = true;
        pm_save('template', $t);
    }
    foreach ($t['packages'] as &$pk) {
        if (empty($pk['type'])) {
            $pk['type'] = pm_guess_type($pk['name'] ?? '');
        }
    }
    unset($pk);
    foreach ($t['benefits'] as &$bn) {
        if (!isset($bn[2]) && stripos((string)($bn[0] ?? ''), 'commission-free') !== false) {
            $bn[2] = 'hotel'; // only relevant to accommodation
        }
    }
    unset($bn);
    foreach (['pitch', 'contract', 'both'] as $k) {
        $t['emails'][$k] = array_replace(pm_default_template()['emails'][$k], $t['emails'][$k] ?? []);
    }
    foreach (['pitch', 'contract', 'both'] as $k) { // emails never carry prices: an older saved email that does is replaced by the current default
        if (pm_has_price((string)($t['emails'][$k]['body'] ?? ''))) {
            $t['emails'][$k] = pm_default_template()['emails'][$k];
        }
    }
    foreach (pm_default_lines() as $ln => $def) {
        $t['lines'][$ln] = array_replace($def, (array)($t['lines'][$ln] ?? []));
        foreach (['pitch', 'contract', 'both'] as $k) {
            if (pm_has_price((string)($t['lines'][$ln]['emails'][$k]['body'] ?? ''))) {
                $t['lines'][$ln]['emails'][$k] = $def['emails'][$k];
            }
        }
    }
    return $t;
}

function pm_types(): array
{
    return ['hotel' => 'Hotel / lodge', 'restaurant' => 'Restaurant / bar', 'gym' => 'Gym', 'venue' => 'Conference venue', 'retail' => 'Shop / supermarket',
        'software' => 'Custom software', 'web' => 'Website', 'hardware' => 'Hardware sourcing', 'support' => 'IT support', 'onboarding' => 'Stay onboarding (Travel Malawi)'];
}

function pm_type_plural(string $type): string
{
    return ['hotel' => 'hotels and lodges', 'restaurant' => 'restaurants and bars', 'gym' => 'gyms', 'venue' => 'venues', 'retail' => 'shops and supermarkets'][$type] ?? 'businesses';
}

function pm_guess_type(string $name): string
{
    $n = strtolower($name);
    foreach (['hotel' => 'hotel|lodge', 'restaurant' => 'restaurant|bar|cafe', 'gym' => 'gym|fitness', 'venue' => 'conference|venue|event', 'retail' => 'shop|supermarket|retail', 'software' => 'software|web app', 'web' => 'website', 'hardware' => 'hardware|sourcing|laptop', 'support' => 'support', 'onboarding' => 'onboarding|travel malawi'] as $t => $re) {
        if (preg_match("/($re)/", $n)) {
            return $t;
        }
    }
    return 'all';
}

/** Pain points for a business type first, then the general ones. */
function pm_pains_for(array $tpl, string $type): array
{
    $mine = array_values(array_filter($tpl['pain_points'], fn($x) => ($x['type'] ?? 'all') === $type));
    $all = array_values(array_filter($tpl['pain_points'], fn($x) => ($x['type'] ?? 'all') === 'all'));
    return array_merge($mine, $all);
}

/** Placeholder values for the emails. */
/** "a, b and c" */
function pm_list_and(array $items): string
{
    $items = array_values(array_filter(array_map('strval', $items)));
    return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : (string)($items[0] ?? 'a simpler way to run things');
}

/** True if the text mentions money amounts: used to keep prices out of email bodies. */
function pm_has_price(string $text): bool
{
    return (bool)preg_match('/(?<![A-Za-z])(MWK|USD|ZAR|ZMW|GBP|EUR)\s?[\d,]+|\d[\d,. ]{2,}\s?(MWK|USD|kwacha)(?![A-Za-z])|[$£€]\s?\d|\{(setup|monthly|first_year|daily)\}/i', $text);
}

function pm_email_vars(array $s, array $tpl, array $p, array $q, string $ref): array
{
    $type = $q['package']['type'] ?? 'all';
    $pain = pm_pains_for($tpl, $type)[0] ?? ['pain' => '', 'cost' => ''];
    $sig = array_filter([$s['signatory_name'] ?? '', ($s['signatory_name'] ?? '') !== '' ? ($s['signatory_title'] ?? '') : '', $s['company_name'], $s['phone'] ?? '', $s['website'] ?? '']);
    $curRow = pm_currency($s, (string)($p['currency'] ?? $s['currency']));
    $cur = $curRow['code'];
    return [
        'business' => $p['business'], 'contact' => $p['contact'] ?: 'Sir/Madam', 'reference' => $ref,
        'package' => $q['package']['name'], 'company' => $s['company_name'],
        'sender' => $s['signatory_name'] ?: $s['company_name'], 'phone' => $s['phone'] ?? '',
        'valid_until' => date('j F Y', strtotime(($p['date'] ?: date('Y-m-d')) . ' +' . (int)$tpl['valid_days'] . ' days')),
        'setup' => pm_money($q['setup'], $cur), 'monthly' => pm_money($q['monthly'], $cur),
        'daily' => pm_money(pm_daily($q['monthly'], $curRow), $cur), 'first_year' => pm_money($q['first_year'], $cur),
        'type_plural' => pm_type_plural($type),
        'fee_line' => !empty($tpl['free'])
            ? 'Onboarding is free: no listing fee, no commission and no monthly charge.'
            : 'The attached proposal explains how onboarding works.', // emails never carry prices; they are in the proposal
        'gains' => pm_list_and(!empty($tpl['gains']) ? $tpl['gains'] : array_slice(array_map(fn($b) => lcfirst($b[0]), array_values(array_filter($tpl['benefits'] ?? [], fn($x) => in_array($x[2] ?? 'all', ['all', '', $type], true)))), 0, 3)),
        'pain_title' => lcfirst((string)$pain['pain']), 'pain_cost' => (string)$pain['cost'],
        'or_call' => ($s['phone'] ?? '') !== '' ? ', or call me on ' . $s['phone'] : '',
        'signature' => implode("\n", $sig),
        'sign_link' => (string)($p['sign_url'] ?? ''),
        'sign_step' => !empty($p['sign_url'])
            ? 'Accept and sign online, it takes two minutes: ' . $p['sign_url']
            : 'Complete and sign the Acceptance page (you can type straight into the PDF) and send it back by replying to this email.',
    ];
}
function pm_history(): array  { return pm_load('history', fn() => []); }

function pm_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pm_money(float $amount, string $currency): string
{
    if ($amount == 0 && !empty($GLOBALS['PM_FREE'])) {
        return 'Free'; // free onboarding: never print "MWK 0"
    }
    if ($amount > 0 && !empty($GLOBALS['PM_FROM'])) {
        $GLOBALS['PM_FROM'] = false; // avoid recursion while formatting
        $t = 'From ' . pm_money($amount, $currency);
        $GLOBALS['PM_FROM'] = true;
        return $t;
    }
    $dec = (abs($amount - round($amount)) < 0.005) ? 0 : 2;
    return $currency . ' ' . number_format($amount, $dec);
}

/**
 * Work out the client's quote from the proposal form.
 * Returns package, chosen extras with line totals, and setup / monthly totals.
 */
function pm_quote(array $tpl, array $p): array
{
    $pkg = $tpl['packages'][(int)($p['package'] ?? 0)] ?? ($tpl['packages'][0] ?? ['name' => '', 'setup' => 0, 'monthly' => 0, 'suited' => '']);
    // Prices typed on the proposal screen override the standard ones for this client.
    if (isset($p['price_setup']) && $p['price_setup'] !== '') {
        $pkg['setup'] = (float)$p['price_setup'];
    }
    if (isset($p['price_monthly']) && $p['price_monthly'] !== '') {
        $pkg['monthly'] = (float)$p['price_monthly'];
    }
    $lines = [];
    $setup = (float)$pkg['setup'];
    $monthly = (float)$pkg['monthly'];

    foreach (($p['extras'] ?? []) as $idx => $qty) {
        $qty = max(0, (int)$qty);
        $ex = $tpl['extras'][(int)$idx] ?? null;
        if (!$ex || $qty === 0 || !in_array($ex['period'], ['month', 'once'], true)) {
            continue;
        }
        $unit = (isset($p['extra_price'][$idx]) && $p['extra_price'][$idx] !== '') ? (float)$p['extra_price'][$idx] : (float)$ex['price'];
        $total = $qty * $unit;
        $lines[] = ['name' => $ex['name'], 'qty' => $qty, 'price' => $unit, 'period' => $ex['period'], 'total' => $total];
        if ($ex['period'] === 'month') {
            $monthly += $total;
        } else {
            $setup += $total;
        }
    }

    $discSetup = min(100, max(0, (float)($p['discount_setup'] ?? 0)));
    $discMonthly = min(100, max(0, (float)($p['discount_monthly'] ?? 0)));
    $setupAfter = round($setup * (1 - $discSetup / 100), 2);
    $monthlyAfter = round($monthly * (1 - $discMonthly / 100), 2);

    return [
        'package'          => $pkg,
        'lines'            => $lines,
        'setup_gross'      => $setup,
        'monthly_gross'    => $monthly,
        'discount_setup'   => $discSetup,
        'discount_monthly' => $discMonthly,
        'setup'            => $setupAfter,
        'monthly'          => $monthlyAfter,
        'first_year'       => $setupAfter + 12 * $monthlyAfter,
    ];
}

/** Fill {placeholders} in email subject/body. */
function pm_fill(string $text, array $vars): string
{
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0], $text);
}

function pm_slug(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'client';
}

/* ---------------- Currencies ---------------- */

function pm_currency(array $s, string $code): array
{
    foreach ($s['currencies'] as $c) {
        if (strcasecmp($c['code'], $code) === 0) {
            return $c;
        }
    }
    return ['code' => $s['currency'], 'name' => $s['currency'], 'rate' => 1, 'round' => 1];
}

function pm_round_to(float $v, float $step): float
{
    return $step >= 1 ? round($v / $step) * $step : round($v, 2);
}

/** The template with every price converted from the base currency into the quote currency. */
function pm_template_in(array $tpl, float $rate, float $step): array
{
    foreach ($tpl['packages'] as &$pk) {
        $pk['setup'] = pm_round_to((float)$pk['setup'] * $rate, $step);
        $pk['monthly'] = pm_round_to((float)$pk['monthly'] * $rate, $step);
    }
    unset($pk);
    foreach ($tpl['extras'] as &$ex) {
        $ex['price'] = pm_round_to((float)$ex['price'] * $rate, $step);
    }
    unset($ex);
    return $tpl;
}

/** Fees note with the quote currency filled in. */
function pm_fees_note(array $tpl, array $cur): string
{
    $note = (string)$tpl['fees_note'];
    if (str_contains($note, 'All fees are in US dollars')) {
        $note = pm_default_template()['fees_note']; // wording from before multi-currency
    }
    $fx = strtoupper($cur['code']) === 'MWK' ? '' : ' They may be paid in Malawi Kwacha at the Reserve Bank of Malawi rate on the invoice date.';
    return pm_fill($note, ['currency_name' => $cur['name'] . ' (' . $cur['code'] . ')', 'fx_line' => $fx]);
}

/* ---------------- Online signing ---------------- */

define('PM_REC', PM_DATA . '/proposals');

function pm_app_url(): string
{
    return rtrim((string)(pm_env()['APP_URL'] ?? ''), '/');
}

function pm_sign_url(string $token): string
{
    $base = pm_app_url();
    return $base === '' ? '' : $base . '/sign.php?t=' . $token;
}

function pm_record_load(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $f = PM_REC . "/$token.json";
    if (!is_file($f)) {
        return null;
    }
    $r = json_decode((string)file_get_contents($f), true);
    return is_array($r) ? $r : null;
}

function pm_record_save(array $r): void
{
    if (!is_dir(PM_REC)) {
        mkdir(PM_REC, 0775, true);
    }
    $tmp = PM_REC . '/' . $r['token'] . '.json.tmp';
    file_put_contents($tmp, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, PM_REC . '/' . $r['token'] . '.json');
}

/** Update the matching History row (status, signed file). */
function pm_history_update(string $token, array $changes): void
{
    $h = pm_history();
    foreach ($h as &$row) {
        if (($row['token'] ?? '') === $token) {
            $row = array_merge($row, $changes);
        }
    }
    unset($row);
    pm_save('history', $h);
}

function pm_client_ip(): string
{
    return (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}

/** Monthly fee as a daily figure: whole units for currencies that round to 10 or more (MWK, ZAR, ZMW). */
function pm_daily(float $monthly, array $curRow): float
{
    return round($monthly / 30, (float)($curRow['round'] ?? 1) >= 10 ? 0 : 2);
}
