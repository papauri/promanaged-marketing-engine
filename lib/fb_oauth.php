<?php
/**
 * "Continue with Facebook": connecting a business's Facebook Page (and the Instagram account linked to it) with one press, no token to copy.
 *   1. The owner presses Continue with Facebook: Facebook asks them to log in and allow the app for their Pages.
 *   2. Facebook sends them back to fb_connect.php with a code; the app swaps it for a long-lived token (with the App Secret, server-side) and lists their Pages.
 *   3. The owner presses Use this Page (the one named like the business is ticked already). The Page token from a long-lived login never expires.
 * The long-lived login is kept (FB_USER_TOKEN, about 60 days), so the next business's Page is connected with one press, from a list, without logging in again.
 * The redirect address is the one the owner is using (a local app is sent back to http://localhost, which Facebook allows while the app is in development);
 * on a website it is https://<site>/fb_connect.php and must be listed once in the Facebook app (Facebook Login > Settings > Valid OAuth Redirect URIs).
 * If the app uses Facebook Login for Business, FB_LOGIN_CONFIG_ID in .env (its configuration ID) is sent instead of the permission list.
 * Data: data/fb_oauth.json {state: {brand, at, origin, redirect, pages: [{id, name, token}], error}} (each kept 30 minutes).
 */

/** Where Facebook sends the owner back to, from the address they are using now. */
function pm_fbo_redirect_uri(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $https = function_exists('pm_https') && pm_https();
    if (preg_match('/^(127\.0\.0\.1|\[::1\]|localhost)(:\d+)?$/', $host, $m)) {
        return 'http://localhost' . ($m[2] ?? '') . pm_fbo_base_path() . '/fb_connect.php'; // Facebook knows "localhost", not the bare IP
    }
    return ($https ? 'https' : 'http') . '://' . $host . pm_fbo_base_path() . '/fb_connect.php';
}

/** The folder the app is served from ('' at the site root). */
function pm_fbo_base_path(): string
{
    $d = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    return rtrim($d === '/' || $d === '.' ? '' : $d, '/');
}

/** The address the owner is using, to come back to after Facebook. */
function pm_fbo_origin(): string
{
    $https = function_exists('pm_https') && pm_https();
    return ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . pm_fbo_base_path();
}

/** Locked change of the waiting logins (older than 30 minutes are dropped). */
function pm_fbo_update(callable $fn): mixed
{
    $res = null;
    pm_update('fb_oauth', function (array $all) use ($fn, &$res) {
        $all = array_filter($all, fn($s) => is_array($s) && (int)($s['at'] ?? 0) > time() - 1800);
        $res = $fn($all);
        return $all;
    }, fn() => []);
    return $res;
}

function pm_fbo_get(string $state): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $state)) {
        return null;
    }
    $s = (array)(pm_load('fb_oauth', fn() => [])[$state] ?? []);
    return $s && (int)($s['at'] ?? 0) > time() - 1800 ? $s : null;
}

/** The Facebook login address for a business, or [false, why] when the app's ID or secret is missing. */
function pm_fbo_start(string $brand): array
{
    $e = pm_env();
    $appId = preg_replace('/\D/', '', (string)($e['FB_APP_ID'] ?? ''));
    if ($appId === '' || trim((string)($e['FB_APP_SECRET'] ?? '')) === '') {
        return [false, 'First paste the App ID and App Secret of your Facebook app (step 1), once.'];
    }
    $state = bin2hex(random_bytes(16));
    $redirect = pm_fbo_redirect_uri();
    pm_fbo_update(function (array &$all) use ($state, $brand, $redirect) {
        $all[$state] = ['brand' => pm_brand_norm($brand), 'at' => time(), 'origin' => pm_fbo_origin(), 'redirect' => $redirect, 'pages' => [], 'error' => ''];
    });
    $q = ['client_id' => $appId, 'redirect_uri' => $redirect, 'state' => $state, 'response_type' => 'code'];
    $cfg = trim((string)($e['FB_LOGIN_CONFIG_ID'] ?? ''));
    if ($cfg !== '') {
        $q['config_id'] = $cfg; // Facebook Login for Business: the permissions live in that configuration
    } else {
        $q['scope'] = implode(',', array_merge(array_keys(pm_fb_scopes()), ['business_management']));
        $q['auth_type'] = 'rerequest'; // a permission declined before is asked again
    }
    return [true, 'https://www.facebook.com/' . pm_graph_version() . '/dialog/oauth?' . http_build_query($q)];
}

/**
 * Facebook sent the owner back (fb_connect.php): the code becomes a long-lived login and the list of their Pages, kept with the waiting login.
 * Returns the address to send the browser to (the Connections screen, with the Pages to choose from or what went wrong).
 */
function pm_fbo_callback(array $get): string
{
    $state = (string)($get['state'] ?? '');
    $s = pm_fbo_get($state);
    if (!$s) {
        return '';
    }
    $back = rtrim((string)$s['origin'], '/') . '/index.php?tab=settings&section=connections&brand=' . rawurlencode((string)$s['brand']) . '&fbpick=' . $state;
    $fail = function (string $why) use ($state, $back) {
        pm_fbo_update(function (array &$all) use ($state, $why) {
            if (isset($all[$state])) {
                $all[$state]['error'] = $why;
            }
        });
        return $back;
    };
    if (!empty($get['error'])) {
        return $fail(((string)($get['error_reason'] ?? '')) === 'user_denied' ? 'Facebook was not allowed to connect (Cancel was pressed). Press Continue with Facebook again and allow it.' : 'Facebook said: ' . mb_substr((string)($get['error_description'] ?? $get['error']), 0, 200));
    }
    $code = (string)($get['code'] ?? '');
    $e = pm_env();
    $appId = preg_replace('/\D/', '', (string)($e['FB_APP_ID'] ?? ''));
    $secret = trim((string)($e['FB_APP_SECRET'] ?? ''));
    if ($code === '' || $appId === '' || $secret === '') {
        return $fail('Facebook did not send the login back. Try again.');
    }
    if (isset($GLOBALS['PM_FBO_STUB']) && is_callable($GLOBALS['PM_FBO_STUB'])) { // tests: no network
        [$ok, $pages, $long] = $GLOBALS['PM_FBO_STUB']($code);
    } else {
        $ch = curl_init('https://graph.facebook.com/' . pm_graph_version() . '/oauth/access_token?' . http_build_query(['client_id' => $appId, 'redirect_uri' => (string)$s['redirect'], 'client_secret' => $secret, 'code' => $code]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
        $j = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($j['access_token'])) {
            return $fail('Facebook did not give a login: ' . mb_substr((string)($j['error']['message'] ?? 'unknown'), 0, 200) . (str_contains((string)($j['error']['message'] ?? ''), 'redirect_uri') ? ' Add ' . $s['redirect'] . ' under Facebook Login > Settings > Valid OAuth Redirect URIs in your Facebook app.' : ''));
        }
        [$ok, $pages, $long] = pm_fb_connect_pages($appId, $secret, (string)$j['access_token']) + [2 => ''];
    }
    if (!$ok) {
        return $fail(is_string($pages) ? $pages : 'No Pages came back.');
    }
    if ($long !== '') {
        pm_env_set(['FB_USER_TOKEN' => $long]); // the next business's Page connects with one press; ads use it too (about 60 days)
    }
    pm_fbo_update(function (array &$all) use ($state, $pages) {
        if (isset($all[$state])) {
            $all[$state]['pages'] = array_values(array_map(fn($p) => ['id' => (string)$p['id'], 'name' => (string)$p['name'], 'token' => (string)$p['access_token']], (array)$pages));
        }
    });
    return $back;
}

/** The Pages the saved long-lived login can manage, with their Page tokens ([] when there is none or it has expired). Cached by pm_graph. */
function pm_fbo_saved_pages(): array
{
    $t = trim((string)(pm_env()['FB_USER_TOKEN'] ?? ''));
    if ($t === '') {
        return [];
    }
    [$ok, $d] = pm_graph('GET', 'me/accounts', ['fields' => 'id,name,access_token', 'limit' => 50], $t);
    return $ok ? array_values(array_map(fn($p) => ['id' => (string)$p['id'], 'name' => (string)$p['name'], 'token' => (string)($p['access_token'] ?? '')], array_filter((array)($d['data'] ?? []), fn($p) => !empty($p['access_token'])))) : [];
}

/** How alike a Page's name is to the business's name, 0 to 100 (the likely Page is ticked for the owner). */
function pm_fbo_name_match(string $page, string $brand): int
{
    $n = fn(string $s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
    [$a, $b] = [$n($page), $n(pm_brand_name($brand))];
    if ($a === '' || $b === '') {
        return 0;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return 100;
    }
    similar_text($a, $b, $pct);
    return (int)$pct;
}

/** Saves the chosen Page for a business (and the Instagram account linked to it). Returns [ok, message]. */
function pm_fbo_connect(string $brand, array $page): array
{
    if (($page['id'] ?? '') === '' || ($page['token'] ?? '') === '') {
        return [false, 'Choose a Page.'];
    }
    $pre = pm_brand_env_prefix($brand);
    pm_env_set([$pre . 'FB_PAGE_ID' => $page['id'], $pre . 'FB_PAGE_TOKEN' => $page['token']]);
    [$okI, $dI] = pm_graph('GET', $page['id'], ['fields' => 'instagram_business_account'], $page['token']);
    $ig = $okI && !empty($dI['instagram_business_account']['id']) ? (string)$dI['instagram_business_account']['id'] : '';
    if ($ig !== '') {
        pm_env_set([$pre . 'IG_USER_ID' => $ig]);
    }
    pm_update('social_page', function (array $all) use ($brand) {
        unset($all[$brand]);
        return $all;
    }, fn() => []);
    $info = pm_fb_token_info($page['token']);
    return [!$info['missing'], 'Connected "' . $page['name'] . '" to ' . pm_brand_name($brand) . ' with a Page token that does not expire' . ($ig !== '' ? ', and its linked Instagram account' : ' (no Instagram account is linked to this Page yet: link it in the Page\'s settings, then connect again)') . '.'
        . ($info['missing'] ? ' Facebook did not give: ' . implode(', ', $info['missing']) . '. Press Continue with Facebook again and leave those ticked.' : '')];
}
