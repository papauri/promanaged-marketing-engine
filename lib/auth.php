<?php
/**
 * Who may use the app. Accounts live in .env, one line each (no database):
 *   USER_<username>=<role admin|user>|<on|off>|<businesses: * or id,id>|<name shown>|<password hash>
 * As soon as one account exists, everyone signs in, on this computer too; with none, the old rule stands (open on this computer, APP_PASSWORD from elsewhere).
 * Admins see every business and manage the accounts (Operations > Team and currencies > Team access): add, turn on or off, delete, set a password,
 * and choose the businesses each person can open. A person turned off, deleted or given a new password is signed out on their next click.
 *   pm_auth_gate()           index.php calls it first: signs in, signs out or shows the sign-in page
 *   pm_user()                the signed-in account, or null (no accounts, or open on this computer)
 *   pm_user_brands()         the businesses the person may open; pm_user_can_brand($b)
 *   pm_auth_action($post)    the admin's changes to the accounts
 */

const PM_USER_PREFIX = 'USER_';

/** The .env file the accounts are kept in (tests point PM_ENV_FILE at a copy). */
function pm_auth_env_file(): string
{
    return (string)getenv('PM_ENV_FILE') !== '' ? (string)getenv('PM_ENV_FILE') : PM_ROOT . '/.env';
}

/** Every account, read fresh from .env: username => [role, on, brands (list, ['*'] = all, including businesses added later), name, hash]. */
function pm_users(): array
{
    $f = pm_auth_env_file();
    $out = [];
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (!preg_match('/^\s*' . PM_USER_PREFIX . '([a-z0-9._-]+)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $p = explode('|', trim($m[2], " \t\"'"), 5);
        if (count($p) < 5) {
            continue;
        }
        $out[$m[1]] = ['role' => $p[0] === 'admin' ? 'admin' : 'user', 'on' => $p[1] !== 'off', 'brands' => array_values(array_filter(explode(',', $p[2]))) ?: ['*'],
            'name' => trim($p[3]) !== '' ? trim($p[3]) : ucfirst($m[1]), 'hash' => $p[4]];
    }
    return $out;
}

/** Writes one account to .env (null removes it). The file is backed up to .env.bak first; other lines are left exactly as they were. */
function pm_user_write(string $username, ?array $u): void
{
    $f = pm_auth_env_file();
    $lines = is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
    @copy($f, $f . '.bak');
    $key = PM_USER_PREFIX . $username;
    $val = $u === null ? null : $key . '=' . $u['role'] . '|' . ($u['on'] ? 'on' : 'off') . '|' . implode(',', $u['brands'] ?: ['*']) . '|' . str_replace(['|', "\r", "\n"], ' ', $u['name']) . '|' . $u['hash'];
    $done = false;
    foreach ($lines as $i => $l) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $l)) {
            if ($val === null || $done) {
                unset($lines[$i]);
            } else {
                $lines[$i] = $val;
            }
            $done = true;
        }
    }
    if (!$done && $val !== null) {
        if (!array_filter($lines, fn($l) => str_starts_with(ltrim($l), PM_USER_PREFIX))) {
            $lines[] = '';
            $lines[] = '# Sign-in accounts (managed in the app: Operations > Team and currencies > Team access). role|on/off|businesses|name|password hash';
        }
        $lines[] = $val;
    }
    file_put_contents($f, implode("\n", $lines) . "\n", LOCK_EX);
}

/** A clean username: lower case letters, digits, dot, dash, underscore; 2 to 32 long. '' when not usable. */
function pm_user_clean(string $u): string
{
    $u = strtolower(trim($u));
    return preg_match('/^[a-z0-9._-]{2,32}$/', $u) ? $u : '';
}

/** The signed-in account with its username, or null. */
function pm_user(): ?array
{
    return $GLOBALS['PM_USER'] ?? null;
}

function pm_user_is_admin(): bool
{
    $u = pm_user();
    return $u === null || $u['role'] === 'admin'; // no accounts at all: whoever runs the app on this computer is the owner
}

/** The businesses the signed-in person may open, in the app's order. */
function pm_user_brands(): array
{
    $u = pm_user();
    $all = pm_brand_ids();
    if ($u === null || $u['role'] === 'admin' || in_array('*', $u['brands'], true)) {
        return $all;
    }
    return array_values(array_intersect($all, $u['brands']));
}

function pm_user_can_brand(string $b): bool
{
    return in_array($b, pm_user_brands(), true);
}

/** Stops the page: the person may not do this. */
function pm_auth_deny(string $why = 'You do not have access to that.'): never
{
    http_response_code(403);
    if (function_exists('pm_ta_log')) {
        pm_ta_log('denied', 'Refused: ' . mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120), $why, ['ok' => false]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => $why]));
    }
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>No access</title><body style="font:15px system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#16181d">'
        . '<div style="text-align:center"><p>' . pm_h($why) . '</p><p><a href="index.php?tab=home">Back to the overview</a> · <a href="index.php?logout=1">Sign out</a></p></div></body>');
}

/** The sign-in page. */
function pm_auth_page(string $err = '', string $user = '', bool $withName = true): never
{
    $logo = is_file(PM_ROOT . '/assets/logo.png') ? '<img src="assets/logo.png" alt="">' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Sign in</title>'
        . '<style>@font-face{font-family:Inter;src:url(assets/fonts/sans.ttf) format("truetype")}@font-face{font-family:Inter;src:url(assets/fonts/sans-bold.ttf) format("truetype");font-weight:600 800}'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f3ef;font:15px Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#16181d}'
        . 'form{background:#fff;border:1px solid #e5e2da;border-radius:16px;padding:36px 32px;width:min(380px,calc(100vw - 32px));box-sizing:border-box;box-shadow:0 20px 50px -30px rgba(0,0,0,.35)}'
        . 'img{height:38px;margin-bottom:18px}h1{font-size:22px;margin:0 0 4px;letter-spacing:-.01em}p.s{margin:0 0 22px;color:#6b6a65;font-size:14px}label{font-size:13px;font-weight:600}'
        . 'input{width:100%;padding:11px 12px;border:1px solid #d9d6cc;border-radius:10px;font:inherit;margin:6px 0 14px;box-sizing:border-box}input:focus{outline:2px solid #17375E33;border-color:#17375E}'
        . 'button{width:100%;padding:12px;border:0;border-radius:10px;background:#16181d;color:#fff;font:inherit;font-weight:600;cursor:pointer}button:hover{background:#2b2f36}.e{color:#a33;font-size:13px;margin:-6px 0 12px}</style></head><body>'
        . '<form method="post">' . $logo . '<h1>Sign in</h1><p class="s">The marketing firm, for the team only.</p>'
        . ($withName ? '<label for="u">Username</label><input id="u" name="login_user" value="' . pm_h($user) . '" autocomplete="username" autocapitalize="none" required' . ($user === '' ? ' autofocus' : '') . '>' : '')
        . '<label for="p">Password</label><input id="p" type="password" name="login_password" autocomplete="current-password" required' . ($user !== '' || !$withName ? ' autofocus' : '') . '>'
        . ($err !== '' ? '<p class="e">' . pm_h($err) . '</p>' : '') . '<button>Sign in</button></form></body></html>';
    exit;
}

/** Sign in, sign out, or the sign-in page. Sets $GLOBALS['PM_USER'] for a signed-in account. */
function pm_auth_gate(): void
{
    $users = pm_users();
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (isset($_GET['logout'])) {
        $outName = (string)($_SESSION['pm_user'] ?? '');
        if ($outName !== '' && isset($users[$outName])) {
            pm_auth_audit('signout', 'Signed out', '', $outName, $users[$outName]);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
    $signedOut = function () {
        if (isset($_GET['names']) || isset($_GET['suggest'])) { // the type-ahead asks in the background: say "signed out" in a way it can show, not with a login page
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            exit('{"error":"signin","rows":[]}');
        }
    };
    if (!$users) { // no accounts yet: open on this computer, one shared password from elsewhere
        if ($isLocal) {
            return;
        }
        $appPassword = pm_env_val('APP_PASSWORD');
        if ($appPassword === '') {
            http_response_code(403);
            exit('This app is only open on the computer it runs on. Add a sign-in account (or APP_PASSWORD) in .env to use it from other devices.');
        }
        if (!empty($_SESSION['pm_auth'])) {
            return;
        }
        $signedOut();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
            usleep(400000); // slow down guessing
            if (hash_equals($appPassword, (string)$_POST['login_password'])) {
                session_regenerate_id(true);
                $_SESSION['pm_auth'] = true;
                header('Location: index.php');
                exit;
            }
            pm_auth_page('That password is not right.', '', false);
        }
        pm_auth_page('', '', false);
    }

    $name = (string)($_SESSION['pm_user'] ?? '');
    $u = $users[$name] ?? null;
    if ($u && $u['on'] && hash_equals((string)($_SESSION['pm_user_ver'] ?? ''), substr(hash('sha256', $u['hash']), 0, 16))) {
        $GLOBALS['PM_USER'] = ['username' => $name] + $u;
        return;
    }
    if ($name !== '') { // turned off, deleted or given a new password since signing in
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $signedOut();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
        usleep(400000); // slow down guessing
        $try = pm_user_clean((string)($_POST['login_user'] ?? ''));
        $acc = $users[$try] ?? null;
        if ($acc && password_verify((string)$_POST['login_password'], $acc['hash'])) {
            if (!$acc['on']) {
                pm_auth_audit('signin_fail', 'Sign-in refused', 'The account is turned off', $try, $acc);
                pm_auth_page('This account is turned off. Ask the admin to turn it back on.', $try);
            }
            pm_auth_audit('signin', 'Signed in', '', $try, $acc);
            session_regenerate_id(true);
            $_SESSION['pm_user'] = $try;
            $_SESSION['pm_user_ver'] = substr(hash('sha256', $acc['hash']), 0, 16);
            $_SESSION['who'] = $acc['name'];
            $back = (string)($_POST['back'] ?? '');
            header('Location: index.php' . (preg_match('/^\?tab=[a-z]+[A-Za-z0-9_=&%.\-]*$/', $back) ? $back : ''));
            exit;
        }
        pm_auth_audit('signin_fail', 'Wrong ' . ($acc ? 'password' : 'username'), 'Tried the username "' . mb_substr((string)($_POST['login_user'] ?? ''), 0, 40) . '"', $acc ? $try : '', $acc);
        pm_auth_page('That username or password is not right.', (string)($_POST['login_user'] ?? ''));
    }
    pm_auth_page();
}

/** A sign-in, sign-out or failed sign-in in the activity log (Audit department), with the browser it came from. */
function pm_auth_audit(string $kind, string $what, string $detail, string $username, ?array $acc): void
{
    if (function_exists('pm_ta_log')) {
        pm_ta_log($kind, $what, $detail, ['user' => $username, 'name' => $acc['name'] ?? ($username !== '' ? $username : 'Unknown'), 'role' => $acc['role'] ?? '', 'brand' => '',
            'ok' => $kind !== 'signin_fail', 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
    }
}

/** After the business is chosen: a business the person may not open is refused, and the screens fall back to one they may. Returns the business to show. */
function pm_auth_brand_gate(string $vb): string
{
    if (pm_user() === null) {
        return $vb;
    }
    $mine = pm_user_brands();
    if (!$mine) {
        pm_auth_deny('Your account has no business to work on yet. Ask the admin to give you one.');
    }
    foreach ([$_GET['brand'] ?? null, $_POST['brand'] ?? null] as $rb) {
        if (is_string($rb) && $rb !== '' && !pm_user_can_brand(pm_brand_norm($rb))) {
            if ($_SERVER['REQUEST_METHOD'] === 'GET') { // an old link or the menu of another account: show their own business instead
                $_SESSION['abrand'] = $mine[0];
                header('Location: index.php?tab=home');
                exit;
            }
            pm_auth_deny('You do not have access to ' . pm_brand_name(pm_brand_norm($rb)) . '.');
        }
    }
    $leads = null;
    foreach (['id', 'lead', 'lead_id'] as $k) { // a lead of a business they may not open
        foreach ([$_GET[$k] ?? null, $_POST[$k] ?? null] as $lid) {
            if (is_string($lid) && $lid !== '') {
                $leads ??= pm_leads_read();
                if (isset($leads[$lid]) && !pm_user_can_brand(pm_brand_norm($leads[$lid]['brand'] ?? 'promanaged'))) {
                    pm_auth_deny('That lead belongs to a business you do not have access to.');
                }
            }
        }
    }
    if (!in_array($vb, $mine, true)) {
        $vb = $mine[0];
        $_SESSION['abrand'] = $vb;
    }
    return $vb;
}

/** The admin's changes to the accounts (action=access, do=add|toggle|delete|password|brands). Returns [message, ok]. */
function pm_auth_action(array $in): array
{
    if (!pm_user_is_admin()) {
        return ['Only an admin can change the accounts.', false];
    }
    $users = pm_users();
    $me = (string)(pm_user()['username'] ?? '');
    $do = (string)($in['do'] ?? '');
    $name = pm_user_clean((string)($in['username'] ?? ''));
    $pass = (string)($in['password'] ?? '');
    $admins = array_keys(array_filter($users, fn($u) => $u['role'] === 'admin' && $u['on']));
    $brands = array_values(array_intersect(pm_brand_ids(), array_map('strval', (array)($in['brands'] ?? []))));
    $brands = !$brands || count($brands) === count(pm_brand_ids()) ? ['*'] : $brands; // every business ticked (or none chosen): every business, including ones added later
    if ($do === 'add') {
        if ($name === '') {
            return ['A username is 2 to 32 letters, digits, dots or dashes, with no spaces.', false];
        }
        if (isset($users[$name])) {
            return ["There is already an account called $name.", false];
        }
        if (strlen($pass) < 8) {
            return ['The password needs at least 8 characters.', false];
        }
        $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'user';
        pm_user_write($name, ['role' => $role, 'on' => true, 'brands' => $role === 'admin' ? ['*'] : $brands, 'name' => trim((string)($in['name'] ?? '')) ?: ucfirst($name), 'hash' => password_hash($pass, PASSWORD_DEFAULT)]);
        return ["Account $name added. They sign in with that username and password.", true];
    }
    if (!isset($users[$name])) {
        return ['That account no longer exists.', false];
    }
    $u = $users[$name];
    $lastAdmin = $u['role'] === 'admin' && $u['on'] && count($admins) <= 1;
    if ($do === 'toggle') {
        if ($name === $me) {
            return ['You cannot turn off your own account.', false];
        }
        if ($lastAdmin) {
            return ['This is the only admin left: add another admin first.', false];
        }
        $u['on'] = !$u['on'];
        pm_user_write($name, $u);
        return [$u['on'] ? "$name can sign in again." : "$name is turned off and signed out.", true];
    }
    if ($do === 'delete') {
        if ($name === $me) {
            return ['You cannot delete your own account.', false];
        }
        if ($lastAdmin) {
            return ['This is the only admin left: add another admin first.', false];
        }
        pm_user_write($name, null);
        return ["Account $name deleted. Their notes and leads stay as they are.", true];
    }
    if ($do === 'password') {
        if (strlen($pass) < 8) {
            return ['The password needs at least 8 characters.', false];
        }
        $u['hash'] = password_hash($pass, PASSWORD_DEFAULT);
        pm_user_write($name, $u);
        if ($name === $me) { // the admin's own password: stay signed in here
            $_SESSION['pm_user_ver'] = substr(hash('sha256', $u['hash']), 0, 16);
        }
        return ["New password set for $name." . ($name === $me ? '' : ' They are signed out and sign in with the new one.'), true];
    }
    if ($do === 'brands') {
        if ($u['role'] === 'admin') {
            return ['An admin always sees every business.', false];
        }
        $u['brands'] = $brands;
        if (trim((string)($in['name'] ?? '')) !== '') {
            $u['name'] = trim((string)$in['name']);
        }
        pm_user_write($name, $u);
        return ["$name can now open: " . ($brands === ['*'] ? 'every business' : implode(', ', array_map('pm_brand_name', $brands))) . '.', true];
    }
    return ['Nothing to change.', false];
}

/** A person's initials in a circle of their own colour. $u needs 'username' and 'name'. */
function pm_auth_av(array $u, string $cls = ''): string
{
    $parts = preg_split('/\s+/', trim((string)$u['name'])) ?: [(string)$u['username']];
    $ini = strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) : ''));
    $hue = (int)(hexdec(substr(md5((string)$u['username']), 0, 2)) * 360 / 256);
    return '<span class="av' . ($cls !== '' ? ' ' . $cls : '') . '" style="--av:hsl(' . $hue . ' 45% 42%)" aria-hidden="true">' . pm_h($ini) . '</span>';
}

/** The account menu in the top bar: initials in a circle, the name, the businesses they can open, Team access for an admin, Sign out. */
function pm_auth_avatar(): string
{
    $u = pm_user();
    if ($u === null) {
        return '';
    }
    $mine = pm_user_brands();
    $h = '<div class="dd acct"><button type="button" class="ddbtn avbtn" aria-haspopup="true" aria-expanded="false" title="' . pm_h('Signed in as ' . $u['name']) . '">'
        . pm_auth_av($u) . '<span class="avname">' . pm_h($u['name']) . '</span><span class="caret"></span></button>'
        . '<div class="menu" role="menu"><div class="acct-hd">' . pm_auth_av($u, 'lg') . '<div><b>' . pm_h($u['name']) . '</b><small>'
        . pm_h('@' . $u['username'] . ' · ' . ($u['role'] === 'admin' ? 'Admin' : 'Team member')) . '</small></div></div>'
        . '<div class="menuhd">Can open</div><div class="acct-biz">' . pm_h($u['role'] === 'admin' || in_array('*', $u['brands'], true) ? 'Every business' : implode(', ', array_map('pm_brand_name', $mine))) . '</div><hr>';
    if ($u['role'] === 'admin') {
        $h .= '<a role="menuitem" class="item" href="?tab=settings&amp;section=team#access"><span class="plus">⚿</span><span>Team access</span></a>';
    }
    return $h . '<a role="menuitem" class="item" href="?logout=1"><span class="plus">↩</span><span>Sign out</span></a></div></div>';
}
