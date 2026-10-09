<?php
/**
 * Social media: AI-planned posts with branded images, an approval queue, scheduled publishing to the Facebook Page
 * (and Instagram when the app is online), page numbers, and comment replies. Each business uses its own Page.
 * Credentials live in .env: FB_PAGE_ID, FB_PAGE_TOKEN, IG_USER_ID (ProManaged IT) and TM_FB_PAGE_ID, TM_FB_PAGE_TOKEN, TM_IG_USER_ID (Travel Malawi).
 */
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/social_calendar.php';

/* ---------------- connection ---------------- */

function pm_social_cfg(string $brand = ''): array
{
    $brand = $brand ?: pm_brand();
    $e = pm_env();
    $p = $brand === 'travel' ? 'TM_' : '';
    $c = ['page_id' => trim((string)($e[$p . 'FB_PAGE_ID'] ?? '')), 'token' => trim((string)($e[$p . 'FB_PAGE_TOKEN'] ?? '')), 'ig_id' => trim((string)($e[$p . 'IG_USER_ID'] ?? ''))];
    $c['ready'] = $c['page_id'] !== '' && $c['token'] !== '';
    return $c;
}

function pm_graph_version(): string
{
    $v = trim((string)(pm_env()['FB_GRAPH_VERSION'] ?? 'v23.0'));
    return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v23.0';
}

/**
 * Facebook allows each app and Page only so many calls an hour, so every call goes through here:
 *  - reads are cached (how long depends on how fast the data changes) and any change we make clears the cache,
 *  - Facebook's own usage headers are read on every answer; above 75% we serve the last known data instead of calling,
 *  - if Facebook says "slow down", everything waits until it allows calls again (reads come from the cache meanwhile).
 */
function pm_graph_ttl(string $path): int
{
    return match (true) {
        $path === 'debug_token' => 21600,
        $path === 'search' => 604800,
        str_starts_with($path, 'me/adaccounts'), str_starts_with($path, 'act_') => 3600,
        str_ends_with($path, '/reactions'), str_ends_with($path, '/insights') => 3600,
        str_ends_with($path, '/ratings') => 21600, // reviews change rarely
        str_ends_with($path, '/conversations') => 300,
        (bool)preg_match('#/(feed|published_posts|posts|comments|ratings|tagged)$#', $path) => 600,
        (bool)preg_match('#^\d+$#', $path) => 3600,
        str_starts_with($path, 'me/accounts') => 0,
        default => 300,
    };
}

function pm_graph_guard(?array $set = null): array
{
    $f = PM_DATA . '/graph_guard.json';
    if ($set !== null) { // flock: concurrent calls never read a half-written file
        $h = @fopen($f, 'c');
        if ($h && flock($h, LOCK_EX)) {
            ftruncate($h, 0);
            fwrite($h, json_encode($set));
            fflush($h);
            flock($h, LOCK_UN);
        }
        if ($h) {
            fclose($h);
        }
        return $set;
    }
    $g = [];
    if (is_file($f) && ($h = @fopen($f, 'r'))) {
        if (flock($h, LOCK_SH)) {
            $g = json_decode((string)stream_get_contents($h), true) ?: [];
            flock($h, LOCK_UN);
        }
        fclose($h);
    }
    if (($g['day'] ?? '') !== date('Y-m-d')) {
        $g['day'] = date('Y-m-d');
        $g['calls'] = 0;
        $g['cached'] = 0;
    }
    return $g + ['pct' => 0, 'until' => 0, 'calls' => 0, 'cached' => 0];
}

function pm_graph_cache_dir(): string
{
    $d = PM_DATA . '/graph_cache';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** One Graph API call. $files maps a field name to a local file path (upload). Returns [ok, data or error message]. Error details: $GLOBALS['PM_GRAPH_ERR']. */
if (!function_exists('pm_graph')) {
function pm_graph(string $method, string $path, array $params, string $token, array $files = [], bool $video = false): array
{
    $path = ltrim($path, '/');
    $read = $method === 'GET';
    $GLOBALS['PM_GRAPH_ERR'] = null;
    if (isset($GLOBALS['PM_GRAPH_STUB']) && is_callable($GLOBALS['PM_GRAPH_STUB'])) { // tests: never touch the network
        $r = ($GLOBALS['PM_GRAPH_STUB'])($method, $path, $params, $token, $files);
        return is_array($r) && array_key_exists(0, $r) ? $r : [false, 'The Graph stub returned nothing.'];
    }
    if (getenv('PM_TEST')) {
        $GLOBALS['PM_GRAPH_ERR'] = ['code' => 0, 'http' => 0, 'errno' => 6];
        return [false, 'Could not reach Facebook: the network is switched off in tests (no PM_GRAPH_STUB).'];
    }
    // media status is polled live; PM_GRAPH_FRESH (set by pm_social_find_on_page) skips the read cache for one call
    $ttl = $read && (($params['fields'] ?? '') !== 'status_code') && empty($GLOBALS['PM_GRAPH_FRESH']) ? pm_graph_ttl($path) : 0;
    $cf = pm_graph_cache_dir() . '/' . md5($path . '|' . json_encode($params) . '|' . md5($token)) . '.json';
    $age = is_file($cf) ? time() - filemtime($cf) : PHP_INT_MAX;
    $g = pm_graph_guard();
    $fromCache = function () use ($cf, &$g) {
        $g['cached']++;
        pm_graph_guard($g);
        return [true, json_decode((string)file_get_contents($cf), true)];
    };
    if ($read && $ttl > 0 && $age < $ttl) {
        return $fromCache();
    }
    if ((int)$g['until'] > time()) { // Facebook asked us to wait
        $GLOBALS['PM_GRAPH_ERR'] = ['code' => 4, 'http' => 429, 'errno' => 0];
        return $read && $age < 86400 ? $fromCache() : [false, 'Facebook asked the app to slow down. It continues automatically at ' . date('H:i', (int)$g['until']) . '.'];
    }
    if ($read && (int)$g['pct'] >= 75 && $age < 86400 && time() - (int)($g['at'] ?? 0) < 3600) {
        return $fromCache(); // close to the limit: last known data is good enough
    }
    $host = $video ? 'https://graph-video.facebook.com/' : 'https://graph.facebook.com/';
    $url = $host . pm_graph_version() . '/' . $path;
    $params['access_token'] = $token;
    $ch = curl_init();
    if ($method === 'GET' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        if ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
    } else {
        foreach ($files as $k => $f) {
            $params[$k] = new CURLFile($f);
        }
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $files ? $params : http_build_query($params)]);
    }
    $usage = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $video ? 300 : 60, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$usage) {
            if (preg_match('/^x-(app-usage|page-usage|business-use-case-usage|ad-account-usage):\s*(.+)$/i', trim($h), $m)) {
                $usage[] = json_decode($m[2], true);
            }
            return strlen($h);
        }]);
    pm_curl_native_ca($ch);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // how close are we to Facebook's limits?
    $pct = 0;
    $wait = 0;
    array_walk_recursive($usage, function ($v, $k) use (&$pct, &$wait) {
        if (in_array($k, ['call_count', 'total_cputime', 'total_time', 'acc_id_util_pct'], true)) {
            $pct = max($pct, (int)$v);
        }
        if ($k === 'estimated_time_to_regain_access') {
            $wait = max($wait, (int)$v * 60);
        }
    });
    $g['calls']++;
    $g['pct'] = $pct;
    $g['at'] = time();
    $j = $raw === false ? null : json_decode((string)$raw, true);
    $code = (int)($j['error']['code'] ?? 0);
    if ($pct >= 95 || $wait > 0 || in_array($code, [4, 17, 32, 613, 80001, 80002, 80004, 80005, 80006], true)) {
        $g['until'] = time() + max($wait, 900);
    }
    pm_graph_guard($g);
    $GLOBALS['PM_GRAPH_ERR'] = ['code' => $code, 'http' => $http, 'errno' => $raw === false ? $errno : 0];
    if ($raw === false) {
        return $read && $age < 86400 ? $fromCache() : [false, 'Could not reach Facebook: ' . $err];
    }
    if (!is_array($j)) {
        return [false, 'Unexpected answer from Facebook.'];
    }
    if (isset($j['error'])) {
        $m = (string)($j['error']['message'] ?? 'Facebook error');
        if ($code == 190 && !str_contains($m, 'must be called with a Page Access Token')) {
            $m = 'The Page access token has expired or was revoked. Create a new one and put it in .env. (' . $m . ')';
        }
        if ((int)$g['until'] > time()) {
            $m .= ' (Facebook limit reached: the app waits until ' . date('H:i', (int)$g['until']) . '.)';
        }
        return [false, $m];
    }
    if ($read && $ttl > 0) {
        @file_put_contents($cf, json_encode($j), LOCK_EX);
    } elseif (!$read) {
        array_map('unlink', glob(pm_graph_cache_dir() . '/*.json') ?: []); // something changed: read fresh next time
    }
    return [true, $j];
}
}

/** Page name and follower numbers, cached for 30 minutes. */
function pm_social_page(string $brand, bool $refresh = false): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return ['ok' => false, 'error' => 'Not connected'];
    }
    $cache = pm_load('social_page', fn() => [])[$brand] ?? [];
    if (!$refresh && ($cache['at'] ?? 0) > time() - 1800) {
        return $cache;
    }
    [$ok, $d] = pm_graph('GET', $c['page_id'], ['fields' => 'name,link,fan_count,followers_count'], $c['token']);
    $out = $ok ? ['ok' => true, 'name' => $d['name'] ?? '', 'link' => $d['link'] ?? '', 'fans' => (int)($d['fan_count'] ?? 0), 'followers' => (int)($d['followers_count'] ?? 0), 'at' => time()]
        : ['ok' => false, 'error' => $d, 'at' => time()];
    pm_update('social_page', function (array $all) use ($brand, $out) {
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

/** Recent Page posts with likes, comments and shares, plus comments that have no reply from the Page yet. */
function pm_social_recent(string $brand): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return ['posts' => [], 'open_comments' => [], 'error' => 'Not connected'];
    }
    if (function_exists('pm_fb_feed')) { // reuse the shared, cached feed call
        $f = pm_fb_feed($brand);
        $posts = [];
        $open = [];
        foreach (array_slice(array_values(array_filter($f['items'], fn($p) => $p['ours'])), 0, 10) as $p) {
            $posts[] = array_intersect_key($p, array_flip(['id', 'message', 'at', 'url', 'reactions', 'comments', 'shares']));
            foreach ($p['comment_list'] as $cm) {
                if (!$cm['ours'] && !$cm['answered']) {
                    $open[] = ['id' => $cm['id'], 'post_id' => $p['id'], 'post' => mb_substr($p['message'], 0, 160), 'from' => $cm['from'], 'message' => $cm['message'], 'at' => $cm['at']];
                }
            }
        }
        return ['posts' => $posts, 'open_comments' => $open, 'error' => $f['error']];
    }
    [$ok, $d] = pm_graph('GET', $c['page_id'] . '/posts', ['limit' => 10,
        'fields' => 'id,message,created_time,permalink_url,shares,reactions.summary(true).limit(0),comments.summary(true).limit(15){id,message,created_time,from,comments.limit(5){from}}'], $c['token']);
    if (!$ok) {
        return ['posts' => [], 'open_comments' => [], 'error' => $d];
    }
    $posts = [];
    $open = [];
    foreach ($d['data'] ?? [] as $p) {
        $posts[] = ['id' => $p['id'], 'message' => (string)($p['message'] ?? ''), 'at' => $p['created_time'] ?? '', 'url' => $p['permalink_url'] ?? '',
            'reactions' => (int)($p['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($p['comments']['summary']['total_count'] ?? 0), 'shares' => (int)($p['shares']['count'] ?? 0)];
        foreach ($p['comments']['data'] ?? [] as $cm) {
            if (($cm['from']['id'] ?? '') === $c['page_id']) {
                continue; // our own comment
            }
            $answered = false;
            foreach ($cm['comments']['data'] ?? [] as $r) {
                $answered = $answered || (($r['from']['id'] ?? '') === $c['page_id']);
            }
            if (!$answered) {
                $open[] = ['id' => $cm['id'], 'post_id' => $p['id'], 'post' => mb_substr((string)($p['message'] ?? ''), 0, 160), 'from' => $cm['from']['name'] ?? 'Someone', 'message' => (string)($cm['message'] ?? ''), 'at' => $cm['created_time'] ?? ''];
            }
        }
    }
    return ['posts' => $posts, 'open_comments' => $open, 'error' => ''];
}

/* ---------------- posts store ---------------- */

function pm_social_posts(): array { return pm_load('social_posts', fn() => []); }
function pm_social_save_posts(array $p): void { pm_save('social_posts', $p); } // legacy whole-file save: use pm_social_update instead
function pm_social_dir(): string { $d = PM_DATA . '/social'; if (!is_dir($d)) { mkdir($d, 0775, true); } return $d; }

/** Read-modify-write the posts under a lock. $fn(array $posts): array. */
function pm_social_update(callable $fn): array
{
    return pm_update('social_posts', fn(array $p) => array_values($fn($p)), fn() => []);
}

/** Change one post under the lock. $fn(array $post): array. Returns the stored post, or null if it is gone. */
function pm_social_patch(string $id, callable $fn): ?array
{
    $out = null;
    pm_social_update(function (array $posts) use ($id, $fn, &$out) {
        foreach ($posts as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                $posts[$i] = $out = $fn($p);
            }
        }
        return $posts;
    });
    return $out;
}

function pm_social_notify(string $subject, string $text): void
{
    if (function_exists('pm_notify_owner')) {
        try {
            pm_notify_owner($subject, $text);
        } catch (Throwable) {
        }
    }
}

function pm_social_state(): array { return pm_load('social_state', fn() => []); }
function pm_social_state_set(callable $fn): void { pm_update('social_state', fn(array $s) => $fn($s), fn() => []); }

/** Brand switch for "approve planned posts automatically". */
function pm_social_auto(string $brand): bool
{
    $s = pm_load('settings', 'pm_default_settings');
    return !empty(($brand === 'travel' ? ($s['travel'] ?? []) : $s)['social_auto']);
}

/* ---------------- who may do what, post log, media paths, alerts ---------------- */

/** Name part of the working member ("Name | role" -> "Name"). */
function pm_social_who(): string { return trim(explode('|', (string)($GLOBALS['PM_WHO'] ?? ''))[0]); }

/**
 * 'approver' (default) or 'editor'. Settings > Marketing team lines: "Name | editor" or "Name | approver".
 * $who may be the whole line or just the name. Nobody chosen = the owner = approver.
 */
function pm_social_role(?string $who = null): string
{
    $who ??= (string)($GLOBALS['PM_WHO'] ?? '');
    $parts = array_map('trim', explode('|', $who));
    $name = $parts[0] ?? '';
    if ($name === '') {
        return 'approver';
    }
    $role = strtolower($parts[1] ?? '');
    if ($role === '') {
        foreach ((array)(pm_settings()['team'] ?? []) as $line) {
            $q = array_map('trim', explode('|', (string)$line));
            if (strcasecmp($q[0], $name) === 0) {
                $role = strtolower($q[1] ?? '');
                break;
            }
        }
    }
    return $role === 'editor' ? 'editor' : 'approver';
}

function pm_social_can_approve(): bool { return pm_social_role() === 'approver'; }

/** Adds one entry to a post's log (kept to the last 30). Pure: use inside a lock callback. */
function pm_social_log_add(array $p, string $act, string $note = '', string $by = ''): array
{
    $log = (array)($p['log'] ?? []);
    $log[] = ['at' => date('Y-m-d H:i'), 'by' => $by !== '' ? $by : (pm_social_who() ?: (PHP_SAPI === 'cli' ? 'system' : 'owner')), 'act' => $act, 'note' => mb_substr($note, 0, 160)];
    $p['log'] = array_slice($log, -30);
    return $p;
}

/** Appends to a post's log under the lock. Never call it from inside pm_social_update/patch (use pm_social_log_add there). */
function pm_social_log(string $id, string $act, string $note = ''): void
{
    pm_social_patch($id, fn(array $p) => pm_social_log_add($p, $act, $note));
}

/** Absolute path of a stored media path: new posts store it relative to data/social, older ones hold an absolute path. */
function pm_social_media_path(string $m): string
{
    if ($m === '' || str_contains($m, '..')) {
        return '';
    }
    return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $m) ? $m : pm_social_dir() . '/' . ltrim($m, '/');
}

/** The form to store: relative to data/social when the file lives there. */
function pm_social_media_rel(string $abs): string
{
    $d = rtrim(str_replace('\\', '/', pm_social_dir()), '/') . '/';
    $a = str_replace('\\', '/', $abs);
    return str_starts_with($a, $d) ? substr($a, strlen($d)) : $abs;
}

function pm_social_media(array $p): string { return pm_social_media_path((string)($p['media'] ?? '')); }

/** "2M" / "512K" / "1G" from php.ini in bytes (0 = no limit). */
function pm_social_ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Largest upload (MB) this server takes: the smaller of upload_max_filesize and post_max_size. */
function pm_social_upload_max_mb(): int
{
    $l = array_filter([pm_social_ini_bytes((string)ini_get('upload_max_filesize')), pm_social_ini_bytes((string)ini_get('post_max_size'))], fn($b) => $b > 0);
    return $l ? max(1, (int)floor(min($l) / 1048576)) : 200;
}

/** One owner alert per $key per $ttl seconds (remembered in social_state). Returns true when sent. */
function pm_social_alert_once(string $key, string $subject, string $text, int $ttl = 86400): bool
{
    $send = false;
    pm_social_state_set(function (array $s) use ($key, $ttl, &$send) {
        $a = (array)($s['alerts'] ?? []);
        $now = time();
        foreach ($a as $k => $t) {
            if ($now - (int)$t > 14 * 86400) {
                unset($a[$k]);
            }
        }
        if ($now - (int)($a[$key] ?? 0) >= $ttl) {
            $a[$key] = $now;
            $send = true;
        }
        $s['alerts'] = $a;
        return $s;
    });
    if ($send) {
        pm_social_notify($subject, $text);
    }
    return $send;
}

/** The brand's WhatsApp / phone number from Settings ('' if none). */
function pm_social_phone(string $brand): string
{
    $was = pm_brand();
    pm_brand_set($brand);
    $ph = trim((string)(pm_settings()['phone'] ?? ''));
    pm_brand_set($was);
    return $ph;
}

/** Short code of a post (4 chars A-Z0-9, from its id): put in WhatsApp pre-fills and links so enquiries trace back to the post. No storage. */
function pm_post_ref(array|string $postOrId): string
{
    $id = is_array($postOrId) ? (string)($postOrId['id'] ?? '') : $postOrId;
    return strtoupper(substr(str_pad(base_convert(sprintf('%u', crc32($id)), 10, 36), 4, '0', STR_PAD_LEFT), -4));
}

/** The post a ref belongs to (newest wins on the rare collision). */
function pm_post_by_ref(string $ref, string $brand = ''): ?array
{
    $ref = strtoupper(trim($ref));
    if (!preg_match('/^[A-Z0-9]{4}$/', $ref)) {
        return null;
    }
    $best = null;
    foreach (pm_social_posts() as $p) {
        if (($brand === '' || ($p['brand'] ?? 'promanaged') === $brand) && pm_post_ref($p) === $ref && ($best === null || strcmp((string)($p['when'] ?? ''), (string)$best['when']) > 0)) {
            $best = $p;
        }
    }
    return $best;
}

/** Facebook post id / video id out of a pasted Facebook URL. Returns ['url','fb_id','video_id'] (empty parts when unknown). */
function pm_social_parse_post_url(string $url, string $pageId = ''): array
{
    $out = ['url' => '', 'fb_id' => '', 'video_id' => ''];
    $url = trim($url);
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (!preg_match('#^https?://#i', $url) || !preg_match('#(^|\.)(facebook\.com|fb\.com|fb\.watch)$#', $host)) {
        return $out;
    }
    $out['url'] = mb_substr($url, 0, 300);
    $q = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('#/(\d{5,})/posts/(\d{5,})#', $path, $m)) {
        $out['fb_id'] = $m[1] . '_' . $m[2];
    } elseif (preg_match('#/posts/(\d{5,})#', $path, $m) && $pageId !== '') {
        $out['fb_id'] = $pageId . '_' . $m[1];
    } elseif (!empty($q['story_fbid']) && preg_match('/^\d{5,}$/', (string)$q['story_fbid']) && preg_match('/^\d{5,}$/', (string)($q['id'] ?? ''))) {
        $out['fb_id'] = $q['id'] . '_' . $q['story_fbid'];
    }
    if (preg_match('#/(?:videos|reel|reels)/(\d{5,})#', $path, $m) || (!empty($q['v']) && preg_match('/^\d{5,}$/', (string)$q['v']) && ($m = [1 => $q['v']]))) {
        $out['video_id'] = $m[1];
    }
    return $out;
}

/** Is the app ready to post well? List of ['ok','label','fix'] for the Plan screen. */
function pm_social_readiness(string $brand): array
{
    $c = pm_social_cfg($brand);
    $set = $brand === 'travel' ? '?tab=settings&brand=travel' : '?tab=settings';
    $acc = '?tab=social&view=accounts';
    $link = pm_link_cfg($brand);
    $gd = pm_social_gd_info();
    $rows = [];
    $rows[] = ['ok' => pm_social_phone($brand) !== '', 'label' => 'WhatsApp number set (posts that ask people to WhatsApp need it)', 'fix' => $set];
    $rows[] = ['ok' => !empty($link['on']) && trim((string)($link['url'] ?? '')) !== '', 'label' => 'Website link switched on', 'fix' => $set];
    $rows[] = ['ok' => pm_app_url() !== '', 'label' => 'APP_URL set in .env (Instagram needs a public address for pictures)', 'fix' => '?tab=settings'];
    $btn = false;
    if (function_exists('pm_channels_cfg')) {
        try {
            foreach ((array)(pm_channels_cfg($brand)['ticks'] ?? []) as $k => $v) {
                $btn = $btn || (!empty($v) && preg_match('/action|button/i', (string)$k));
            }
        } catch (Throwable) {
        }
    }
    $rows[] = ['ok' => $btn, 'label' => 'Facebook Page action button set to "Send WhatsApp message" (tick it in Channels once done on Facebook)', 'fix' => '?tab=social&view=channels'];
    $rows[] = ['ok' => $c['ig_id'] !== '', 'label' => 'Instagram linked', 'fix' => $acc];
    $rows[] = ['ok' => pm_social_gd_ok(), 'label' => 'Picture drawing works (GD + FreeType + fonts)' . (!$gd['gd'] ? ': GD is missing' : (!$gd['freetype'] ? ': FreeType is missing' : ($gd['serif'] === '' || $gd['sans'] === '' ? ': no font file found' : ''))), 'fix' => $acc];
    if ($c['ig_id'] !== '' && pm_app_url() !== '') {
        $t = (array)(pm_social_state()['ig_url_test'][$brand] ?? []);
        $rows[] = ['ok' => !empty($t['ok']), 'label' => 'Instagram picture address reachable' . (empty($t) ? ' (not tested yet)' : (!empty($t['ok']) ? '' : ': ' . ($t['msg'] ?? 'failed'))), 'fix' => $acc];
    }
    return $rows;
}

/** HEAD request to APP_URL/media.php?ping=1: can Instagram fetch our pictures? Stores and returns [ok, message]. */
function pm_social_ig_url_test(string $brand): array
{
    $u = pm_app_url();
    if ($u === '') {
        $r = [false, 'APP_URL is empty in .env'];
    } elseif (getenv('PM_TEST')) {
        $r = [false, 'network switched off in tests'];
    } else {
        $ch = curl_init($u . '/media.php?ping=1');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
        pm_curl_native_ca($ch);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $r = $code === 200 ? [true, 'Reachable (HTTP 200)'] : [false, $code ? "The address answered HTTP $code" : 'Could not connect: ' . $err];
    }
    pm_social_state_set(function (array $s) use ($brand, $r) {
        $s['ig_url_test'][$brand] = ['at' => time(), 'ok' => $r[0], 'msg' => $r[1]];
        return $s;
    });
    return $r;
}

/* ---------------- proof bank: real quotes, results, photos and offers (data/social_proof.json) ---------------- */

function pm_social_proof_all(string $brand = ''): array
{
    $rows = array_values(pm_load('social_proof', fn() => []));
    return $brand === '' ? $rows : array_values(array_filter($rows, fn($r) => ($r['brand'] ?? 'promanaged') === $brand));
}

/** Rows the planner may use: consent given, not expired, some text. Quotes are used word for word. */
function pm_social_proof_usable(string $brand, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    return array_values(array_filter(pm_social_proof_all($brand), fn($r) => (!empty($r['consent']) || ($r['type'] ?? '') === 'offer') && trim((string)($r['text'] ?? '')) !== ''
        && (trim((string)($r['expires'] ?? '')) === '' || $r['expires'] >= $today)));
}

function pm_social_proof_find(string $brand, string $id): ?array
{
    foreach (pm_social_proof_usable($brand) as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
}

/** Replaces one brand's proof rows with the edited list (other brand untouched). Text is stored exactly as typed. Types: quote, result, photo, offer, stay (a host/lodge we may feature). */
function pm_social_proof_save(string $brand, array $rows): void
{
    $old = [];
    foreach (pm_social_proof_all($brand) as $r) {
        $old[(string)($r['id'] ?? '')] = $r;
    }
    $clean = [];
    foreach ($rows as $r) {
        if (!empty($r['delete'])) {
            continue;
        }
        $text = trim((string)($r['text'] ?? ''));
        $name = trim((string)($r['client_name'] ?? ''));
        if ($text === '' && $name === '' && empty($r['lead_id'])) {
            continue;
        }
        $id = preg_match('/^[a-f0-9]{8,24}$/', (string)($r['id'] ?? '')) ? $r['id'] : bin2hex(random_bytes(6));
        $exp = (string)($r['expires'] ?? '');
        $fb = trim((string)($r['fb_url'] ?? ''));
        $consent = !empty($r['consent']);
        $was = $old[$id] ?? [];
        $clean[] = ['id' => $id, 'proof_id' => $id, 'brand' => $brand, 'type' => in_array($r['type'] ?? '', ['quote', 'result', 'photo', 'offer', 'stay'], true) ? $r['type'] : 'quote',
            'text' => mb_substr($text, 0, 600), 'client_name' => mb_substr($name, 0, 80), 'consent' => $consent, 'consent_note' => mb_substr(trim((string)($r['consent_note'] ?? '')), 0, 200),
            'consent_at' => $consent ? (string)(!empty($was['consent']) && !empty($was['consent_at']) ? $was['consent_at'] : date('Y-m-d')) : '',
            'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : '', 'lead_id' => (string)($r['lead_id'] ?? ''),
            'fb_url' => preg_match('#^https?://\S{4,200}$#i', $fb) ? $fb : '', 'ig_handle' => mb_substr(preg_replace('/[^A-Za-z0-9._]/', '', ltrim(trim((string)($r['ig_handle'] ?? '')), '@')), 0, 30),
            'tag_ok' => !empty($r['tag_ok']), 'host_id' => mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($r['host_id'] ?? '')), 0, 40)];
    }
    pm_update('social_proof', fn(array $all) => array_merge(array_values(array_filter($all, fn($r) => ($r['brand'] ?? 'promanaged') !== $brand)), $clean), fn() => []);
}

/**
 * A lead became a client: ask them for a one-line quote and permission to feature them (a blank row waits in the proof bank).
 * A Travel Malawi host also gets a blank 'stay' row (permission to feature the stay, tag their page).
 */
function pm_social_proof_request(array $lead): void
{
    $lid = (string)($lead['id'] ?? '');
    if ($lid === '') {
        return;
    }
    $brand = ($lead['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged';
    pm_update('social_proof', function (array $rows) use ($lead, $lid, $brand) {
        $has = [];
        foreach ($rows as $r) {
            if (($r['lead_id'] ?? '') === $lid) {
                $has[(string)($r['type'] ?? 'quote') === 'stay' ? 'stay' : 'quote'] = true;
            }
        }
        $base = ['brand' => $brand, 'text' => '', 'client_name' => (string)($lead['name'] ?? ''), 'consent' => false, 'consent_at' => '', 'expires' => '', 'lead_id' => $lid,
            'fb_url' => '', 'ig_handle' => '', 'tag_ok' => false, 'host_id' => ''];
        if (empty($has['quote']) && empty($has['stay'])) {
            $id = bin2hex(random_bytes(6));
            $rows[] = ['id' => $id, 'proof_id' => $id, 'type' => 'quote', 'consent_note' => 'Ask for a one-line quote and permission to feature them'] + $base;
        }
        if ($brand === 'travel' && empty($has['stay'])) {
            $id = bin2hex(random_bytes(6));
            $rows[] = ['id' => $id, 'proof_id' => $id, 'type' => 'stay', 'host_id' => $lid, 'consent_note' => 'Ask permission to feature the stay (photos, name), their Facebook link and Instagram handle'] + $base;
        }
        return $rows;
    }, fn() => []);
}

/* ---------------- slots and cadence: max 1 feed post a day per brand, never the same pillar twice running ---------------- */

const PM_SOCIAL_LIVE = ['draft', 'approved', 'publishing', 'needs_edit', 'needs_video', 'needs_check', 'review', 'needs_asset'];
const PM_SOCIAL_UNSENT = ['draft', 'approved', 'needs_edit', 'needs_video', 'review', 'needs_asset']; // not on the Page yet and not posting right now

/** Hours (HH:MM) to post at: this Page's own best times when known, else the Malawi habits. */
function pm_social_hours(string $brand): array
{
    if (function_exists('pm_social_slots')) {
        try {
            $t = array_values(array_unique(array_column((array)pm_social_slots($brand), 'time')));
            if ($t) {
                return $t;
            }
        } catch (Throwable) {
        }
    }
    $h = function_exists('pm_fb_best_times') ? (array)(pm_fb_best_times($brand)['hours'] ?? []) : [];
    return $h ? array_map(fn($x) => sprintf('%02d:00', (int)$x), array_values($h)) : ['07:30', '12:30', '18:30'];
}

/** Habit times before any data: ProManaged Tue-Thu 07:30 / 12:30, Travel Malawi Thu-Sun 18:30 / 12:00. $dow is 1 (Mon) to 7 (Sun). */
function pm_social_default_time(string $brand, int $dow, int $i = 0): string
{
    if ($brand === 'travel') {
        return $dow >= 4 && $i % 2 === 0 ? '18:30' : '12:00';
    }
    if ($dow >= 2 && $dow <= 4) {
        return $i % 2 === 0 ? '07:30' : '12:30';
    }
    return $dow >= 6 ? '18:30' : '12:30';
}

/** Time (HH:MM) for the $i-th post on $date: from the measured slots when there are any (that weekday's first, else cycling through them), else the habits. */
function pm_social_time_for(string $brand, string $date, int $i = 0, ?array $slots = null): string
{
    if ($slots === null) {
        $slots = [];
        if (function_exists('pm_social_slots')) {
            try {
                $slots = array_values(array_filter((array)pm_social_slots($brand), fn($s) => preg_match('/^\d{1,2}:\d{2}$/', (string)($s['time'] ?? ''))));
            } catch (Throwable) {
            }
        }
    }
    $dow = (int)date('N', strtotime($date));
    if ($slots) {
        $same = array_values(array_filter($slots, fn($s) => (int)($s['dow'] ?? 0) === $dow));
        $s = $same ? $same[$i % count($same)] : $slots[$i % count($slots)];
        return str_pad((string)$s['time'], 5, '0', STR_PAD_LEFT);
    }
    return pm_social_default_time($brand, $dow, $i);
}

/** Days (Y-m-d => true) a brand already has a feed post on, live or published. Stories do not use up the day. */
function pm_social_busy_days(array $posts, string $brand, string $exceptId = ''): array
{
    $days = [];
    foreach ($posts as $p) {
        if (($p['brand'] ?? 'promanaged') === $brand && ($p['id'] ?? '') !== $exceptId && ($p['format'] ?? '') !== 'story'
            && (in_array($p['status'] ?? '', PM_SOCIAL_LIVE, true) || ($p['status'] ?? '') === 'published') && !empty($p['when'])) {
            $days[substr($p['when'], 0, 10)] = true;
        }
    }
    return $days;
}

/** First free day (Y-m-d) on or after $from. */
function pm_social_next_free_date(array $posts, string $brand, string $from): string
{
    $busy = pm_social_busy_days($posts, $brand);
    for ($i = 0; $i < 120; $i++) {
        $d = date('Y-m-d', strtotime("$from +$i days"));
        if (empty($busy[$d])) {
            return $d;
        }
    }
    return $from;
}

/** Next free slot ('Y-m-d H:i') at least 30 minutes after $fromTs. $hours (legacy) forces one time of day. */
function pm_social_free_slot(array $posts, string $brand, int $fromTs, string $exceptId = '', ?array $hours = null): string
{
    $busy = pm_social_busy_days($posts, $brand, $exceptId);
    $t = $hours ? ($hours === ['07:30', '12:30', '18:30'] ? '18:30' : ($hours[0] ?? '18:30')) : '';
    for ($i = 0; $i < 120; $i++) {
        $d = date('Y-m-d', $fromTs + $i * 86400);
        $ts = strtotime("$d " . ($t ?: pm_social_time_for($brand, $d, 0)));
        if ($ts >= $fromTs + 1800 && empty($busy[$d])) {
            return date('Y-m-d H:i', $ts);
        }
    }
    return date('Y-m-d H:i', $fromTs + 86400);
}

/**
 * Gives new posts one free day each from $start and shuffles them so no two neighbours share a pillar (also against the post just before).
 * A post with occasion_date goes ON that date, or the nearest free day up to 2 days BEFORE it, never after. Times come from the measured
 * slots (cycling by weekday) or the habit times. Posts may carry '_off' (the planner's day offset, for order).
 */
function pm_social_cadence(array $new, array $existing, string $brand, string $start): array
{
    usort($new, fn($a, $b) => (int)($a['_off'] ?? 0) <=> (int)($b['_off'] ?? 0));
    $busy = pm_social_busy_days($existing, $brand);
    $fixed = [];
    $rest = [];
    foreach ($new as $p) {
        $od = (string)($p['occasion_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) && $od >= $start) {
            $day = '';
            foreach ([0, 1, 2] as $back) {
                $d = date('Y-m-d', strtotime("$od -$back days"));
                if ($d >= $start && empty($busy[$d])) {
                    $day = $d;
                    break;
                }
            }
            $day = $day ?: $od; // every day busy: share the occasion day rather than miss it
            $busy[$day] = true;
            $fixed[] = [$p, $day];
        } else {
            $rest[] = $p; // no date, or already past (its expires date will retire it)
        }
    }
    $days = [];
    for ($i = 0; count($days) < count($rest) && $i < 200; $i++) {
        $d = date('Y-m-d', strtotime("$start +$i days"));
        if (empty($busy[$d])) {
            $days[] = $d;
        }
    }
    $before = array_filter($existing, fn($p) => ($p['brand'] ?? 'promanaged') === $brand && ($p['status'] ?? '') !== 'failed' && !empty($p['when']) && substr($p['when'], 0, 10) < ($days[0] ?? $start));
    usort($before, fn($a, $b) => strcmp($b['when'], $a['when']));
    $prev = strtolower((string)($before[0]['pillar'] ?? ''));
    $n = count($rest);
    for ($i = 0; $i < $n; $i++) {
        if (strtolower((string)($rest[$i]['pillar'] ?? '')) === $prev && $prev !== '') {
            for ($j = $i + 1; $j < $n; $j++) {
                if (strtolower((string)($rest[$j]['pillar'] ?? '')) !== $prev) {
                    [$rest[$i], $rest[$j]] = [$rest[$j], $rest[$i]];
                    break;
                }
            }
        }
        $prev = strtolower((string)($rest[$i]['pillar'] ?? ''));
    }
    $placed = $fixed;
    foreach ($rest as $i => $p) {
        $placed[] = [$p, $days[$i] ?? ($days ? $days[count($days) - 1] : $start)];
    }
    usort($placed, fn($a, $b) => strcmp($a[1], $b[1]));
    $slots = [];
    if (function_exists('pm_social_slots')) {
        try {
            $slots = array_values(array_filter((array)pm_social_slots($brand), fn($s) => preg_match('/^\d{1,2}:\d{2}$/', (string)($s['time'] ?? ''))));
        } catch (Throwable) {
        }
    }
    $out = [];
    $seen = [];
    foreach ($placed as $k => [$p, $day]) {
        $nth = $seen[$day] = ($seen[$day] ?? -1) + 1;
        $p['when'] = $day . ' ' . pm_social_time_for($brand, $day, $k + $nth, $slots);
        unset($p['_off']);
        $out[] = $p;
    }
    return $out;
}

/* ---------------- lint: what must never go out ---------------- */

/** Last captions of a brand from a posts list (for the no-repeat rule). Only posts at or before $before when given. */
function pm_social_recent_from(array $posts, string $brand, string $exceptId = '', string $before = '', int $n = 12): array
{
    $rows = array_filter($posts, fn($p) => ($p['brand'] ?? 'promanaged') === $brand && ($p['id'] ?? '') !== $exceptId && in_array($p['status'] ?? '', array_merge(PM_SOCIAL_LIVE, ['published']), true)
        && trim((string)($p['caption'] ?? '')) !== '' && ($before === '' || ($p['when'] ?? '') <= $before));
    usort($rows, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
    return array_map(fn($p) => (string)$p['caption'], array_slice($rows, 0, $n));
}

/** Reasons a caption may not be posted (empty list = fine). $post (optional) adds the pillar / proof checks. */
function pm_social_lint(string $caption, string $brand, string $channel = 'facebook', array $recent = [], array $post = []): array
{
    $why = [];
    $c = trim($caption);
    if (preg_match('/\bour team\b/i', $c)) {
        $why[] = 'Says "our team": we are a small owner-run business, say "we".';
    }
    if (preg_match('/\bpackages?\s*#?\s*\d+|(?<![\w#])#\d+\b/i', $c)) {
        $why[] = 'Mentions an internal package number.';
    }
    if (preg_match('/guarantee/i', $c)) {
        $why[] = 'Promises a guarantee.';
    }
    if (preg_match('/(?i:\b(MWK|USD|ZAR|ZMW|GBP|EUR|kwacha)\b)|[$£€]\s?\d|(?<![A-Za-z])K\s?\d|\d\s?[Kk]\b/', $c)) {
        $why[] = 'Contains a price or money amount.';
    }
    if ($channel === 'facebook' && preg_match('/link in (my |our )?bio/i', $c)) {
        $why[] = '"Link in bio" does not work on Facebook.';
    }
    if (preg_match_all('#https?://\S+|www\.\S+#i', $c) > 1) {
        $why[] = 'More than one link.';
    }
    $a = mb_strtolower(mb_substr($c, 0, 400));
    foreach ($recent as $r) {
        $b = mb_strtolower(mb_substr((string)$r, 0, 400));
        if ($a !== '' && $b !== '') {
            similar_text($a, $b, $pct);
            if ($pct > 70) {
                $why[] = 'Too similar to a recent post (' . round($pct) . '%).';
                break;
            }
        }
    }
    $pillar = strtolower(trim((string)($post['pillar'] ?? '')));
    if ($post && ($pillar === 'proof' || $pillar === 'offer')) {
        $row = pm_social_proof_find($brand, (string)($post['proof_id'] ?? ''));
        if (!$row || ($pillar === 'proof') === (($row['type'] ?? '') === 'offer')) {
            $why[] = $pillar === 'proof' ? 'A Proof post needs a proof item with the client\'s consent.' : 'An Offer post needs a current offer from the proof bank.';
        }
    }
    if ($post && ($post['cta'] ?? '') === 'whatsapp' && pm_social_phone($brand) === '') {
        $why[] = 'WhatsApp is the call to action but no WhatsApp number is set (Settings).';
    }
    return $why;
}

/** Extra checks from the content-engine module (pm_lint_ext): [block messages, warning messages]. Nothing when the module is absent. */
function pm_social_ext_lint(array $p, string $channel = 'facebook'): array
{
    $block = [];
    $warn = [];
    if (function_exists('pm_lint_ext')) {
        try {
            foreach ((array)pm_lint_ext($p, $channel) as $r) {
                $m = trim((string)($r['msg'] ?? ''));
                if ($m === '') {
                    continue;
                }
                if (($r['sev'] ?? '') === 'block') {
                    $block[] = $m;
                } else {
                    $warn[] = $m;
                }
            }
        } catch (Throwable) {
        }
    }
    return [$block, $warn];
}

/** Reasons a post may not go out (empty = fine). Warnings (not blocking) are kept for pm_social_resolve to put on the post. */
function pm_social_lint_post(array $p): array
{
    $b = $p['brand'] ?? 'promanaged';
    $why = pm_social_lint((string)($p['caption'] ?? ''), $b, 'facebook', pm_social_recent_from(pm_social_posts(), $b, (string)($p['id'] ?? ''), (string)($p['when'] ?? '')), $p);
    [$block, $warn] = pm_social_ext_lint($p);
    $GLOBALS['PM_LINT_WARN'][(string)($p['id'] ?? '')] = $warn;
    return array_values(array_unique(array_merge($why, $block)));
}

/** What the owner approved: caption, hashtags, picture text, the picture file's time and the slides. Time of day is not part of it. */
function pm_social_hash(array $p): string
{
    $m = pm_social_media($p);
    return sha1(implode('|', [(string)($p['caption'] ?? ''), implode(' ', (array)($p['hashtags'] ?? [])), (string)($p['headline'] ?? ''), (string)($p['sub'] ?? ''),
        $m !== '' && is_file($m) ? (string)filemtime($m) : '0', json_encode($p['slides'] ?? [])]));
}

/**
 * Sets the draft-stage status from the facts: lint fails -> needs_edit, reel without video -> needs_video, else draft.
 * An approved post whose approved content changed goes back to draft ("edited after approval"); a time change does not. Review stays review.
 * Published, posting, expired and needs_asset posts are left alone.
 */
function pm_social_resolve(array $p, array $lint): array
{
    $p['lint'] = $lint;
    $id = (string)($p['id'] ?? '');
    if (array_key_exists($id, $GLOBALS['PM_LINT_WARN'] ?? [])) {
        $p['warn'] = $GLOBALS['PM_LINT_WARN'][$id];
        unset($GLOBALS['PM_LINT_WARN'][$id]);
    } else {
        $p['warn'] = pm_social_ext_lint($p)[1];
    }
    if (($p['status'] ?? '') === 'approved' && ($p['approved_hash'] ?? '') !== '' && !hash_equals((string)$p['approved_hash'], pm_social_hash($p))) {
        $p['status'] = 'draft';
        $p['approved_hash'] = '';
        $p['note'] = 'edited after approval';
        $p = pm_social_log_add($p, 'demoted', 'edited after approval');
    }
    if (in_array($p['status'] ?? '', ['draft', 'needs_edit', 'needs_video', 'approved', 'review'], true)) {
        $video = pm_social_has_media($p);
        $keep = in_array($p['status'], ['approved', 'review'], true) ? $p['status'] : 'draft';
        $p['status'] = $lint ? 'needs_edit' : (($p['format'] ?? '') === 'reel' && !$video ? 'needs_video' : $keep);
    }
    return $p;
}

/** Draws the picture of an image post that has no photo of its own. null = nothing to draw, '' = could not be drawn, else the file. Call it BEFORE taking a lock. */
function pm_social_prepare_card(array $p): ?string
{
    if (($p['format'] ?? '') !== 'image' || pm_social_has_media($p)) {
        return null;
    }
    try {
        return pm_social_card($p);
    } catch (Throwable) {
        return '';
    }
}

/** Records who approved what (and a hash to notice later edits). */
function pm_social_stamp_approval(array $p, string $by = ''): array
{
    $by = $by !== '' ? $by : (pm_social_who() ?: 'owner');
    $p['approved_hash'] = pm_social_hash($p);
    $p['approved_by'] = $by;
    $p['approved_at'] = date('Y-m-d H:i');
    unset($p['note'], $p['review_note']);
    return pm_social_log_add($p, 'approved', '', $by);
}

/**
 * Approves one post if it passes every check. Returns [post, message, ok].
 * $card: the picture drawn beforehand by pm_social_prepare_card (null = work it out here; only draws when it must).
 */
function pm_social_approve(array $p, ?string $card = null, string $by = ''): array
{
    $p = pm_social_resolve($p, pm_social_lint_post($p));
    if ($p['status'] === 'needs_edit') {
        return [$p, 'Not approved: ' . implode(' ', $p['lint']), false];
    }
    if ($p['status'] === 'needs_video') {
        return [$p, 'Not approved: a reel needs its video. Record it from the script and attach it.', false];
    }
    if (!in_array($p['status'], ['draft', 'review', 'approved'], true)) {
        return [$p, 'Not approved: this post is ' . str_replace('_', ' ', (string)$p['status']) . '.', false];
    }
    $card ??= pm_social_prepare_card($p);
    if ($card === '') {
        $p['status'] = 'needs_edit';
        $p['error'] = 'Picture could not be drawn (check fonts)';
        return [$p, 'Not approved: Picture could not be drawn (check fonts). Fix it in Accounts, then redraw.', false];
    }
    $p['status'] = 'approved';
    $p['retry_at'] = '';
    $p['tries'] = 0;
    $p['error'] = '';
    $p = pm_social_stamp_approval($p, $by);
    return [$p, 'Approved for ' . date('D j M, H:i', strtotime($p['when'])) . '.', true];
}

/* ---------------- AI content planner ---------------- */

const PM_SOCIAL_FOCUS = [
    'mix' => 'A balanced mix', 'leads' => 'Get enquiries (clear call to action)', 'tips' => 'Useful tips that build trust',
    'awareness' => 'Show what we do', 'story' => 'Real-life problems and how they are solved', 'check' => 'Free website check / host sign-up',
];

function pm_social_default_pillars(string $brand): array
{
    $p = $brand === 'travel'
        ? ['Destination/inspiration' => 35, 'Host tips' => 20, 'Stay spotlight' => 20, 'Practical travel info' => 15, 'Offer' => 10]
        : ['Tip/How-to' => 30, 'Local problem story' => 20, 'Proof' => 15, 'Behind the scenes' => 15, 'Offer' => 20];
    return array_map(fn($n, $w) => ['name' => $n, 'weight' => $w], array_keys($p), array_values($p));
}

/** The brand's content pillars [['name','weight']]: its own list from Accounts, else the defaults. */
function pm_social_pillars(string $brand): array
{
    $saved = function_exists('pm_social_settings') ? (array)(pm_social_settings($brand)['pillars'] ?? []) : [];
    $out = [];
    foreach ($saved as $r) {
        $n = trim((string)($r['name'] ?? ''));
        if ($n !== '' && (int)($r['weight'] ?? 0) > 0) {
            $out[] = ['name' => mb_substr($n, 0, 40), 'weight' => min(100, (int)$r['weight'])];
        }
    }
    return $out ?: pm_social_default_pillars($brand);
}

/** Posts per pillar for $n posts, by weight (largest remainder). Returns name => count (zeros left out). */
function pm_social_pillar_counts(array $pillars, int $n): array
{
    $tot = array_sum(array_column($pillars, 'weight')) ?: 1;
    $cnt = [];
    $rem = [];
    foreach ($pillars as $i => $p) {
        $x = $n * $p['weight'] / $tot;
        $cnt[$i] = (int)floor($x);
        $rem[$i] = $x - $cnt[$i];
    }
    arsort($rem);
    foreach (array_keys($rem) as $i) {
        if (array_sum($cnt) >= $n) {
            break;
        }
        $cnt[$i]++;
    }
    $out = [];
    foreach ($pillars as $i => $p) {
        if ($cnt[$i] > 0) {
            $out[$p['name']] = $cnt[$i];
        }
    }
    return $out;
}

/** The planner's prompt. Pure: everything it needs comes in $o (brand, company, brief, n, angle, start, pillars, hooks, recent, hours, proof, learnings). Returns [system, user]. */
function pm_social_plan_prompt(array $o): array
{
    $n = (int)$o['n'];
    $mix = [];
    foreach (pm_social_pillar_counts($o['pillars'], $n) as $name => $c) {
        $mix[] = "$name x$c";
    }
    $travel = ($o['brand'] ?? '') === 'travel';
    $hours = $o['hours'] ?: ['07:30', '12:30', '18:30'];
    $proof = array_map(fn($r) => ['proof_id' => $r['id'], 'type' => $r['type'], 'text' => $r['text'], 'client' => $r['client_name'] ?? '', 'ends' => $r['expires'] ?? ''], (array)($o['proof'] ?? []));
    $system = ($o['brief'] ?? '') . "\nYou are the social media manager for {$o['company']}'s Facebook Page, writing for small business owners in Malawi"
        . ($travel ? ' who run lodges, guest houses, B&Bs, cottages and safari camps, and for travellers who want to book them directly' : '') . ".\n"
        . "Plan exactly $n posts. Plan exactly this pillar mix for $n posts: " . implode(', ', $mix) . ". Put the pillar name in \"pillar\" (exactly as written). Goal: {$o['angle']}. "
        . "Formats: mostly \"image\" (a branded picture with a headline), at most one \"reel\" (a 20 to 30 second script someone films on a phone) and a short \"text\" status. "
        . "Each caption: 25 to 70 words, plain and warm, one clear idea, 2 to 4 relevant hashtags, at least one local (e.g. #Malawi). No links, no phone numbers (added later). "
        . "Vary the call to action: ask a question to get a comment, ask to save the post, ask to share it, ask to tag a friend, or invite a WhatsApp message. At most 1 of every 3 posts may ask for WhatsApp. Put it in \"cta\" as comment|save|share|tag|whatsapp. "
        . "image_headline: at most 7 words, the hook printed on the picture. image_sub: at most 12 words. "
        . ($o['learnings'] ?? '') . ' '
        . "Never mention prices, money amounts or guarantees. Never promise results, response times, 24/7 support or anything not in the facts. Never name or show a client or business without their permission. No fake testimonials or numbers. We are a small owner-run business: say \"we\", never \"our team\". "
        . ($proof ? "PROOF BANK (the only real proof and offers you may use): " . json_encode($proof, JSON_UNESCAPED_UNICODE) . ". A Proof post uses ONE quote/result/photo item, quoting its text word for word, never reworded, and returns its proof_id. An Offer post uses ONE offer item and returns its proof_id; say 'last days' only if it ends within 5 days. " : 'No proof items exist: do not write testimonials or offers. ')
        . 'Reply JSON only.';
    $user = "Start date {$o['start']} (Malawi). Spread posts across different days, best posting times " . implode(', ', $hours) . ".\n"
        . ($o['hooks'] ? 'seasonal_hooks (use at most 2, only if relevant): ' . implode(' | ', $o['hooks']) . "\n" : '')
        . ($o['recent'] ? 'Do not repeat these angles (our last captions): ' . implode(' || ', array_map(fn($c) => mb_substr(preg_replace('/\s+/', ' ', (string)$c), 0, 100), $o['recent'])) . "\n" : '')
        . 'JSON: {"posts":[{"pillar":"","format":"image|text|reel","day_offset":0,"time":"18:30","cta":"comment","proof_id":"","caption":"","hashtags":["#Malawi"],"image_headline":"","image_sub":"","reel_script":"hook / shots / on-screen text, only for reel"}]}';
    return [$system, $user];
}

/**
 * Plans $n posts for the brand in use. One AI call. Returns posts (not yet saved), already linted and spread one per day.
 * Formats: image (a branded picture with a headline), text (a short status), reel (a script to film on a phone).
 */
function pm_agent_social_plan(int $n, string $focus, string $start): array
{
    if (function_exists('pm_plan_posts') && empty($GLOBALS['PM_LEGACY_PLAN'])) { // the content-engine planner (seed bank, one short AI call)
        return pm_plan_posts($n, $focus, $start);
    }
    $s = pm_settings();
    $brand = pm_brand();
    $existing = pm_social_posts();
    $proof = pm_social_proof_usable($brand);
    $hasProof = (bool)array_filter($proof, fn($r) => $r['type'] !== 'offer');
    $hasOffer = (bool)array_filter($proof, fn($r) => $r['type'] === 'offer');
    $pillars = array_values(array_filter(pm_social_pillars($brand), fn($p) => !(strtolower($p['name']) === 'proof' && !$hasProof) && !(strtolower($p['name']) === 'offer' && !$hasOffer)));
    $hours = function_exists('pm_fb_best_times') ? (array)(pm_fb_best_times($brand)['hours'] ?? []) : [];
    [$system, $user] = pm_social_plan_prompt(['brand' => $brand, 'company' => $s['company_name'], 'brief' => pm_agents_company_brief($brand === 'travel' ? 'tiny' : 'short'),
        'n' => $n, 'angle' => PM_SOCIAL_FOCUS[$focus] ?? PM_SOCIAL_FOCUS['mix'], 'start' => $start, 'pillars' => $pillars ?: pm_social_default_pillars($brand),
        'hooks' => pm_social_hooks($start, 21), 'recent' => pm_social_recent_from($existing, $brand), 'hours' => $hours ? pm_social_hours($brand) : [], 'proof' => $proof,
        'learnings' => function_exists('pm_audit_learnings') ? pm_audit_learnings($brand) : '']);
    $out = pm_agent_json(pm_claude($system, $user, false, min(4500, 500 + $n * 450), 'write'));
    $list = is_array($out) ? (array)($out['posts'] ?? []) : [];
    $names = array_column($pillars, 'name');
    $posts = [];
    foreach ($list as $p) {
        if (!is_array($p) || trim((string)($p['caption'] ?? '')) === '') {
            continue;
        }
        $time = preg_match('/^\d{1,2}:\d{2}$/', (string)($p['time'] ?? '')) ? $p['time'] : ($hours ? pm_social_hours($brand)[0] : '18:30');
        $pl = '';
        foreach ($names as $nm) {
            if (strcasecmp($nm, trim((string)($p['pillar'] ?? ''))) === 0) {
                $pl = $nm;
            }
        }
        $posts[] = [
            'id' => bin2hex(random_bytes(10)), 'brand' => $brand, 'status' => 'draft', 'when' => date('Y-m-d') . ' ' . $time, '_off' => (int)($p['day_offset'] ?? 0),
            'format' => in_array($p['format'] ?? '', ['image', 'text', 'reel'], true) ? $p['format'] : 'image', 'pillar' => $pl ?: mb_substr(trim((string)($p['pillar'] ?? '')), 0, 40),
            'cta' => in_array($p['cta'] ?? '', ['comment', 'save', 'share', 'tag', 'whatsapp', 'check', 'host'], true) ? $p['cta'] : '', 'proof_id' => preg_replace('/[^a-f0-9]/', '', (string)($p['proof_id'] ?? '')),
            'caption' => trim((string)$p['caption']), 'hashtags' => array_values(array_filter(array_map(fn($h) => '#' . ltrim(preg_replace('/\s+/', '', (string)$h), '#'), (array)($p['hashtags'] ?? [])))),
            'headline' => mb_substr(trim((string)($p['image_headline'] ?? '')), 0, 60), 'sub' => mb_substr(trim((string)($p['image_sub'] ?? '')), 0, 90),
            'script' => trim((string)($p['reel_script'] ?? '')), 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [],
            'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''),
        ];
    }
    $posts = pm_social_cadence($posts, $existing, $brand, $start);
    $pool = $existing;
    foreach ($posts as $i => $p) {
        $why = pm_social_lint($p['caption'], $brand, 'facebook', pm_social_recent_from($pool, $brand, $p['id']), $p);
        $posts[$i] = pm_social_resolve($p, $why);
        $pool[] = $posts[$i];
    }
    return $posts;
}

/**
 * Saves freshly planned posts. Pictures are drawn first (a picture post whose picture cannot be drawn becomes "needs an edit": it never goes out
 * as text). Approved only when auto-publish is on and every check passes (logged as approved by "auto"). Returns how many.
 */
function pm_social_add_planned(array $new, bool $auto): int
{
    foreach ($new as $i => $np) {
        if (($np['format'] ?? '') === 'image' && in_array($np['status'] ?? '', ['draft', 'approved', 'needs_edit'], true)) {
            if (pm_social_prepare_card($np) === '') {
                $np['status'] = 'needs_edit';
                $np['error'] = 'Picture could not be drawn (check fonts)';
                $np['lint'] = array_values(array_unique(array_merge((array)($np['lint'] ?? []), ['Picture could not be drawn (check fonts).'])));
            }
        }
        if ($auto && ($np['status'] ?? '') === 'draft') {
            $np['status'] = 'approved';
            $np = pm_social_stamp_approval($np, 'auto');
        }
        $new[$i] = pm_social_log_add($np, 'planned', !empty($np['offline']) ? 'offline draft' : '', 'system');
    }
    if ($new) {
        pm_social_update(fn(array $posts) => array_merge($posts, $new));
    }
    return count($new);
}

/** A short, kind reply to a comment on our Page. */
function pm_agent_social_reply(array $c): string
{
    $s = pm_settings();
    $system = pm_agents_company_brief('tiny') . "\nYou reply to comments on {$s['company_name']}'s Facebook Page. 1 to 3 short sentences, warm and human, in the commenter's language if it is clear. "
        . "Answer only from the facts. For prices or details, invite them to send a WhatsApp or private message. Never promise times or results. No hashtags. Reply JSON only: {\"reply\":\"\"}";
    $out = pm_agent_json(pm_claude($system, json_encode(['post' => $c['post'], 'commenter' => $c['from'], 'comment' => $c['message']], JSON_UNESCAPED_UNICODE), false, 400, 'write'));
    return trim((string)(is_array($out) ? ($out['reply'] ?? '') : ''));
}

/* ---------------- branded image ---------------- */

/**
 * A TrueType font file: the bundled assets/fonts/{serif,serif-bold,sans,sans-bold}.ttf first, then the operating system's.
 * Returns '' when there is none (then no picture can be drawn and posts wait as "needs an edit").
 */
function pm_font(bool $serif, bool $bold = false): string
{
    if (!empty($GLOBALS['PM_FONT_NONE'])) { // tests: pretend the server has no fonts
        return '';
    }
    $name = ($serif ? 'serif' : 'sans') . ($bold ? '-bold' : '');
    $cands = [PM_ROOT . '/assets/fonts/' . $name . '.ttf'];
    if ($serif) {
        array_push($cands, 'C:/Windows/Fonts/georgia' . ($bold ? 'b' : '') . '.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif' . ($bold ? '-Bold' : '') . '.ttf',
            '/usr/share/fonts/dejavu/DejaVuSerif' . ($bold ? '-Bold' : '') . '.ttf', '/usr/share/fonts/TTF/DejaVuSerif' . ($bold ? '-Bold' : '') . '.ttf');
    } else {
        array_push($cands, 'C:/Windows/Fonts/arial' . ($bold ? 'bd' : '') . '.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf', '/usr/share/fonts/TTF/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf');
    }
    foreach ($cands as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return $bold ? pm_font($serif, false) : '';
}

/** What the server can draw with: GD, FreeType, and the font files found. */
function pm_social_gd_info(): array
{
    $gd = function_exists('imagecreatetruecolor') && function_exists('imagettftext') && function_exists('imagettfbbox');
    $ft = false;
    if ($gd && function_exists('gd_info')) {
        $ft = !empty(gd_info()['FreeType Support']);
    }
    return ['gd' => $gd, 'freetype' => $ft, 'serif' => pm_font(true), 'sans' => pm_font(false),
        'bundled' => is_file(PM_ROOT . '/assets/fonts/serif.ttf') && is_file(PM_ROOT . '/assets/fonts/sans.ttf')];
}

/** Can pictures be drawn here? (GD with imagettftext, FreeType and at least one serif and one sans font) */
function pm_social_gd_ok(): bool
{
    $i = pm_social_gd_info();
    return $i['gd'] && $i['freetype'] && $i['serif'] !== '' && $i['sans'] !== '';
}

/** Wraps $text to lines that fit $w pixels. A single word wider than the box is cut into pieces (never overflows the picture). */
function pm_wrap_px(string $text, string $font, float $size, int $w): array
{
    $width = function (string $t) use ($font, $size): int {
        $b = imagettfbbox($size, 0, $font, $t);
        return $b ? (int)($b[2] - $b[0]) : 0;
    };
    $lines = [];
    $line = '';
    $words = [];
    foreach (preg_split('/\s+/u', trim($text)) as $word) {
        while ($word !== '' && $width($word) > $w && mb_strlen($word) > 1) { // clamp an over-wide word
            $cut = mb_strlen($word) - 1;
            while ($cut > 1 && $width(mb_substr($word, 0, $cut)) > $w) {
                $cut--;
            }
            $words[] = mb_substr($word, 0, $cut);
            $word = mb_substr($word, $cut);
        }
        if ($word !== '') {
            $words[] = $word;
        }
    }
    foreach ($words as $word) {
        $try = $line === '' ? $word : "$line $word";
        if ($width($try) > $w && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

/** Text for the picture: emoji and symbols the fonts cannot draw (they print as boxes) are removed. */
function pm_social_card_text(string $t): string
{
    $t = preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}\x{20E3}]/u', '', $t) ?? $t;
    return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
}

/**
 * The picture for a post at a size key ('sq' 1080x1080 by default). Delegates to the content-engine card renderer (pm_card_render) when it handles
 * the request, else draws the plain 1080 square. Returns the file path, or '' when it could not be drawn (no GD / no font).
 */
function pm_social_card(array $p, string $size = 'sq'): string
{
    if (isset($GLOBALS['PM_CARD_HOOK']) && is_callable($GLOBALS['PM_CARD_HOOK'])) { // tests
        return (string)($GLOBALS['PM_CARD_HOOK'])($p, $size);
    }
    $was = pm_brand();
    try {
        if (function_exists('pm_card_render')) {
            $r = pm_card_render($p, $size);
            if ($r !== null) {
                return (string)$r;
            }
        }
        return pm_social_card_legacy($p);
    } catch (Throwable) {
        return '';
    } finally {
        pm_brand_set($was);
    }
}

/** Draws the plain 1080x1080 branded picture. Returns the file path, or '' if no font / GD is available. */
function pm_social_card_legacy(array $p): string
{
    if (!pm_social_gd_ok()) {
        return '';
    }
    $serif = pm_font(true);
    $sans = pm_font(false);
    $sansB = pm_font(false, true);
    pm_brand_set($p['brand'] ?? 'promanaged');
    $s = pm_settings();
    $W = 1080;
    $im = imagecreatetruecolor($W, $W);
    [$r, $g, $b] = sscanf(ltrim($s['accent_color'] ?: '#17375E', '#'), '%02x%02x%02x');
    $accent = imagecolorallocate($im, (int)$r, (int)$g, (int)$b);
    $bg = imagecolorallocate($im, 250, 249, 247);
    $ink = imagecolorallocate($im, 22, 24, 29);
    $muted = imagecolorallocate($im, 96, 101, 110);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $bg);
    imagefilledrectangle($im, 0, 0, $W, 16, $accent);
    imagefilledrectangle($im, 80, 250, 200, 258, $accent);
    // headline
    $head = pm_social_card_text((string)(trim((string)($p['headline'] ?? '')) ?: mb_substr((string)($p['caption'] ?? ''), 0, 50)));
    $size = 74;
    do {
        $lines = pm_wrap_px($head, $serif, $size, $W - 160);
        $size -= 4;
    } while (count($lines) > 4 && $size > 40);
    $y = 300 + $size;
    foreach ($lines as $l) {
        imagettftext($im, $size + 4, 0, 80, $y, $ink, $serif, $l);
        $y += (int)(($size + 4) * 1.32);
    }
    $sub = pm_social_card_text((string)($p['sub'] ?? ''));
    if ($sub !== '') {
        $y += 20;
        foreach (array_slice(pm_wrap_px($sub, $sans, 30, $W - 160), 0, 3) as $l) {
            imagettftext($im, 30, 0, 80, $y, $muted, $sans, $l);
            $y += 46;
        }
    }
    // footer band: logo, name, link
    imagefilledrectangle($im, 0, $W - 150, $W, $W, $accent);
    $logo = pm_logo_path();
    $x = 80;
    if ($logo !== '' && ($li = @imagecreatefrompng($logo))) {
        $lh = 86;
        $lw = (int)round(imagesx($li) * $lh / imagesy($li));
        $box = imagecreatetruecolor($lw + 16, $lh + 16);
        imagefill($box, 0, 0, $white);
        imagecopyresampled($box, $li, 8, 8, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
        imagecopy($im, $box, $x, $W - 150 + 24, 0, 0, $lw + 16, $lh + 16);
        $x += $lw + 40;
    }
    imagettftext($im, 34, 0, $x, $W - 62, $white, $sansB ?: $sans, pm_social_card_text((string)$s['company_name']));
    $link = pm_link_cfg($p['brand'] ?? 'promanaged')['url'] ?: ($s['website'] ?? '');
    if ($link !== '') {
        $t = preg_replace('#^https?://#', '', rtrim($link, '/'));
        $bb = imagettfbbox(24, 0, $sans, $t);
        imagettftext($im, 24, 0, $W - 80 - ($bb[2] - $bb[0]), $W - 64, $white, $sans, $t);
    }
    $f = pm_social_dir() . '/' . $p['id'] . '.png';
    $ok = imagepng($im, $f, 6);
    imagedestroy($im);
    return $ok && is_file($f) ? $f : '';
}

/** Sizes the picture route accepts (keys of pm_card_sizes, else just 'sq'). */
function pm_social_size_keys(): array
{
    $k = function_exists('pm_card_sizes') ? array_keys((array)pm_card_sizes()) : [];
    return $k ?: ['sq'];
}

/**
 * The picture file for ?simg=ID&size=KEY&slide=N. Unknown sizes fall back to 'sq'. Own photos are served as they are (square size only).
 * Draws a missing size on demand. Returns '' when there is nothing to serve.
 */
function pm_social_img_file(string $id, string $size = 'sq', int $slide = 0): string
{
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) {
        return '';
    }
    $size = in_array($size, pm_social_size_keys(), true) ? $size : 'sq';
    $post = null;
    foreach (pm_social_posts() as $p) {
        if (($p['id'] ?? '') === $id) {
            $post = $p;
        }
    }
    $own = $post ? pm_social_media($post) : '';
    $dir = pm_social_dir();
    if ($slide > 0 && $post && function_exists('pm_card_slides')) {
        $paths = array_values((array)pm_card_slides($post, $size === 'sq' ? '4x5' : $size));
        $f = (string)($paths[$slide] ?? '');
        return $f !== '' && is_file($f) ? $f : '';
    }
    if ($size === 'sq') {
        if ($own !== '' && is_file($own) && !pm_social_is_video($own)) {
            return $own;
        }
        foreach ([$dir . '/' . $id . '.png', $dir . '/' . $id . '_sq.png'] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
    } elseif (is_file($dir . '/' . $id . '_' . $size . '.png')) {
        return $dir . '/' . $id . '_' . $size . '.png';
    }
    if ($post && in_array($post['format'] ?? '', ['image', 'carousel', 'story'], true)) { // not drawn yet at this size: draw it now
        $f = pm_social_card($post, $size);
        if ($f !== '' && is_file($f)) {
            return $f;
        }
    }
    return is_file($dir . '/' . $id . '.png') ? $dir . '/' . $id . '.png' : '';
}

/* ---------------- publishing ---------------- */

function pm_social_sleep(int $s): void
{
    if (empty($GLOBALS['PM_NOSLEEP'])) {
        sleep($s);
    }
}

function pm_social_has_media(array $p): bool { $m = pm_social_media($p); return $m !== '' && is_file($m); }
function pm_social_is_video(string $f): bool { return (bool)preg_match('/\.(mp4|mov|m4v)$/i', $f); }

/**
 * The caption as posted on a channel. The Channels module (pm_variant_caption) composes it; without it: text, a WhatsApp link that opens a chat about
 * this post, the website link (when on) tagged with the post id, hashtags. Instagram has no clickable links: it says "message us on WhatsApp".
 */
function pm_social_caption(array $p, string $channel = 'facebook'): string
{
    if (function_exists('pm_variant_caption')) {
        try {
            $v = pm_variant_caption($p, $channel);
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        } catch (Throwable) {
        }
    }
    pm_brand_set($p['brand'] ?? 'promanaged');
    $t = trim((string)($p['caption'] ?? ''));
    $s = pm_settings();
    $phone = trim((string)($s['phone'] ?? ''));
    $head = trim((string)($p['headline'] ?? '')) ?: mb_substr($t, 0, 40);
    if ($channel === 'instagram') {
        $t .= "\n\nMessage us on WhatsApp" . ($phone !== '' ? ': ' . $phone : '.');
    } else {
        $wa = function_exists('pm_wa_link') && !str_contains($t, 'wa.me') ? pm_wa_link(['whatsapp' => $phone, 'phone' => $phone], 'Hi, I saw your post: ' . $head . ' (ref ' . pm_post_ref($p) . ')') : '';
        if ($wa !== '') {
            $t .= "\n\nMessage us on WhatsApp: " . $wa;
        }
        $lk = pm_link_cfg($p['brand'] ?? 'promanaged');
        if ($lk['on'] && !str_contains($t, $lk['url'])) {
            $t .= "\n\n" . $lk['url'] . (str_contains($lk['url'], '?') ? '&' : '?') . 'utm_source=' . ($channel === 'linkedin' ? 'linkedin' : 'facebook') . '&utm_medium=social&utm_campaign=' . pm_post_ref($p);
        }
    }
    if (!empty($p['hashtags'])) {
        $t .= "\n\n" . implode(' ', (array)$p['hashtags']);
    }
    return $t;
}

/** 'auth' (token dead), 'check' (may have posted: never retry blindly), 'transient' (try again later) or 'perm'. Uses pm_graph's last error details when there are any. */
function pm_social_err_kind(string $m): string
{
    $e = (array)($GLOBALS['PM_GRAPH_ERR'] ?? []);
    $code = (int)($e['code'] ?? 0);
    $http = (int)($e['http'] ?? 0);
    if ($code === 190 || preg_match('/\(#?190\)|access token (has )?(expired|is invalid)|token has expired|session has expired/i', $m)) {
        return 'auth';
    }
    if (in_array((int)($e['errno'] ?? 0), [28, 52, 55, 56], true)) {
        return 'check'; // timed out or dropped after sending: Facebook may have posted it
    }
    if (in_array($code, [1, 2, 4, 17, 32, 613], true) || $http === 429 || $http >= 500
        || preg_match('/slow down|rate.?limit|limit reached|too many calls|temporarily|try again|Could not reach|\(#?(1|2|4|17|32|613)\)|code (1|2|4|17|32|613)\b|HTTP (429|5\d\d)/i', $m)) {
        return 'transient';
    }
    return 'perm';
}

/**
 * Records a failed attempt on the post per its kind. Rate limits: stays approved and retries in 30 minutes, 4 tries, then failed + owner alert.
 * A dead token keeps the post approved and pauses that brand's publishing (data/social_state.json auth_down) until the token works again.
 */
function pm_social_fail(array &$p, string $msg): string
{
    $k = pm_social_err_kind($msg);
    $p['error'] = $msg;
    $b = ($p['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged';
    $label = ($b === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ': ';
    $cap = mb_substr((string)($p['caption'] ?? ''), 0, 80);
    if ($k === 'transient') {
        $p['tries'] = (int)($p['tries'] ?? 0) + 1;
        if ($p['tries'] >= 4) {
            $p['status'] = 'failed';
            pm_social_notify('Social post failed after 4 tries', $label . $cap . "\n" . $msg);
        } else {
            $p['status'] = 'approved';
            $p['retry_at'] = date('Y-m-d H:i', time() + 1800);
        }
    } elseif ($k === 'check') {
        $p['status'] = 'needs_check';
        $p['error'] = 'No answer from Facebook while posting. Check the Page, then Mark posted or Retry. (' . $msg . ')';
        pm_social_alert_once('check:' . ($p['id'] ?? ''), 'Social post needs a check', $label . $cap . "\nFacebook did not answer while posting: open the Page, then press Mark posted or Retry in Social > Plan.");
    } elseif ($k === 'auth') {
        $p['status'] = 'approved'; // safe: waits for a working token
        $p['retry_at'] = '';
        pm_social_state_set(function (array $s) use ($b, $msg) {
            $s['auth_down'][$b] = ['at' => time(), 'msg' => mb_substr($msg, 0, 240), 'checked' => time()];
            return $s;
        });
        pm_social_alert_once('auth:' . $b, 'Facebook token needs renewing', $label . "Publishing is paused: the access token expired or was revoked. Approved posts wait safely.\n" . $msg);
    } else {
        $p['status'] = 'failed';
        pm_social_alert_once('perm:' . ($p['id'] ?? ''), 'Social post failed', $label . $cap . "\n" . $msg);
    }
    return $k;
}

/** Is the brand's token usable again? Clears auth_down when it is. Live call (not cached). Returns true when publishing may continue. */
function pm_social_auth_recheck(string $brand): bool
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return false;
    }
    $GLOBALS['PM_GRAPH_FRESH'] = true;
    try {
        [$ok] = pm_graph('GET', $c['page_id'], ['fields' => 'id'], $c['token']);
    } finally {
        unset($GLOBALS['PM_GRAPH_FRESH']);
    }
    pm_social_state_set(function (array $s) use ($brand, $ok) {
        if ($ok) {
            unset($s['auth_down'][$brand]);
        } elseif (isset($s['auth_down'][$brand])) {
            $s['auth_down'][$brand]['checked'] = time();
        }
        return $s;
    });
    return (bool)$ok;
}

/** Normalised start of a text for comparing a caption with a Page post (letters and digits only, lower case). */
function pm_social_norm_text(string $t, int $n = 60): string
{
    $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t);
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $t) ?? $t), 0, $n);
}

/**
 * Is this post already on the Page? (After a timeout or an error the post may have gone out.) Fresh, uncached read of the Page's published
 * posts since the last attempt; matches the first 60 normalised characters of the caption. Returns ['id','created'] or null.
 */
function pm_social_find_on_page(array $p): ?array
{
    $c = pm_social_cfg($p['brand'] ?? 'promanaged');
    $want = pm_social_norm_text((string)($p['caption'] ?? ''));
    if (!$c['ready'] || $want === '') {
        return null;
    }
    $since = strtotime((string)($p['prev_pub_at'] ?? '')) ?: (strtotime((string)($p['pub_at'] ?? '')) ?: time() - 86400);
    $GLOBALS['PM_GRAPH_FRESH'] = true;
    try {
        [$ok, $d] = pm_graph('GET', $c['page_id'] . '/published_posts', ['since' => $since - 300, 'fields' => 'id,message,created_time', 'limit' => 25], $c['token']);
    } finally {
        unset($GLOBALS['PM_GRAPH_FRESH']);
    }
    if (!$ok || !is_array($d)) {
        return null;
    }
    foreach ((array)($d['data'] ?? []) as $x) {
        $m = pm_social_norm_text((string)($x['message'] ?? ''), 400);
        if ($m !== '' && str_starts_with($m, $want)) {
            return ['id' => (string)($x['id'] ?? ''), 'created' => (string)($x['created_time'] ?? '')];
        }
    }
    return null;
}

/**
 * The picture made fit for Instagram: width at most 1440 px, shape between 4:5 and 1.91:1 (padded with white, never stretched or cut),
 * EXIF rotation applied, transparency flattened onto white, JPEG quality 85 (lower only if the file is 8 MB or more).
 */
function pm_social_ig_prepare(string $src, string $dest): bool
{
    if (!function_exists('imagecreatefromstring') || !is_file($src)) {
        return false;
    }
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) {
        return false;
    }
    if (function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $src)) {
        $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
        $rot = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($rot !== 0 && ($r = imagerotate($im, $rot, 0))) {
            $im = $r;
        }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1) {
        return false;
    }
    $tw = $w;
    $th = $h;
    if ($w / $h < 0.8) {
        $tw = (int)ceil($h * 0.8);
    } elseif ($w / $h > 1.91) {
        $th = (int)ceil($w / 1.91);
    }
    $k = min(1.0, 1440 / $tw);
    $ow = max(1, (int)round($tw * $k));
    $oh = max(1, (int)round($th * $k));
    $sw = max(1, (int)round($w * $k));
    $sh = max(1, (int)round($h * $k));
    $out = imagecreatetruecolor($ow, $oh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $im, intdiv($ow - $sw, 2), intdiv($oh - $sh, 2), 0, 0, $sw, $sh, $w, $h);
    foreach ([85, 70, 55] as $q) {
        imagejpeg($out, $dest, $q);
        clearstatcache(true, $dest);
        if (is_file($dest) && filesize($dest) < 8 * 1024 * 1024) {
            break;
        }
    }
    return is_file($dest) && filesize($dest) > 0;
}

/**
 * Instagram picture post. First time: make the file, create the container, wait a little (3 checks, 5 s apart), publish.
 * Still processing -> $p['ig'] = 'pending...' with ig_container kept: the next run finishes it (no long sleeping).
 * publish errors or timeouts become 'check: ...' (it may have posted: look first), never 'failed'. Sets $p['ig'], ig_post.
 */
function pm_social_ig(array &$p, array $c, string $media): bool
{
    if (($p['ig_post'] ?? '') !== '') {
        $p['ig'] = 'published';
        return true;
    }
    $cid = (string)($p['ig_container'] ?? '');
    $checks = 1;
    if ($cid !== '' && strtotime((string)($p['ig_container_at'] ?? '')) < time() - 3600) { // too old to still be processing
        $cid = '';
        $p['ig_container'] = '';
    }
    if ($cid === '') {
        $pub = 'ig_' . bin2hex(random_bytes(12)) . '.jpg';
        if (!pm_social_ig_prepare($media, pm_social_dir() . '/' . $pub)) {
            $p['ig'] = 'failed: the picture could not be prepared for Instagram';
            return false;
        }
        $p['ig_pub'] = $pub;
        [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['image_url' => pm_app_url() . '/media.php?f=' . $pub, 'caption' => pm_social_caption($p, 'instagram')]
            + (trim((string)($p['alt'] ?? '')) !== '' ? ['alt_text' => mb_substr(trim((string)$p['alt']), 0, 1000)] : []), $c['token']);
        if (!$ok || empty($d['id'])) {
            $p['ig'] = 'failed: ' . (is_string($d) ? $d : 'no media container');
            return false;
        }
        $cid = (string)$d['id'];
        $p['ig_container'] = $cid;
        $p['ig_container_at'] = date('Y-m-d H:i:s');
        $checks = 3;
    }
    $st = '';
    for ($i = 0; $i < $checks; $i++) {
        [$ok2, $s2] = pm_graph('GET', $cid, ['fields' => 'status_code'], $c['token']);
        $st = $ok2 && is_array($s2) ? (string)($s2['status_code'] ?? '') : '';
        if (in_array($st, ['FINISHED', 'ERROR', 'EXPIRED'], true)) {
            break;
        }
        if ($i < $checks - 1) {
            pm_social_sleep(5);
        }
    }
    if (in_array($st, ['ERROR', 'EXPIRED'], true)) {
        $p['ig'] = 'failed: Instagram could not process the picture (' . $st . ')';
        $p['ig_container'] = '';
        return false;
    }
    if ($st !== 'FINISHED') {
        $p['ig'] = 'pending: Instagram is still processing the picture; the app finishes it on the next run';
        return false;
    }
    [$ok3, $d3] = pm_graph('POST', $c['ig_id'] . '/media_publish', ['creation_id' => $cid], $c['token']);
    if (!$ok3) {
        $p['ig'] = 'check: ' . (is_string($d3) ? $d3 : 'no answer') . ' Look at Instagram before trying again: it may have posted.';
        return false;
    }
    $p['ig'] = 'published';
    $p['ig_post'] = (string)($d3['id'] ?? '');
    $p['ig_container'] = '';
    if (($p['ig_pub'] ?? '') !== '') {
        @unlink(pm_social_dir() . '/' . basename((string)$p['ig_pub']));
        $p['ig_pub'] = '';
    }
    return true;
}

/** Finishes Instagram posts that were still processing when they were published (called by the scheduler run). Returns how many moved on. */
function pm_social_ig_poll(): int
{
    $n = 0;
    foreach (pm_social_posts() as $p) {
        if (($p['status'] ?? '') !== 'published' || ($p['ig_container'] ?? '') === '' || ($p['ig_post'] ?? '') !== '' || !str_starts_with((string)($p['ig'] ?? ''), 'pending')) {
            continue;
        }
        $c = pm_social_cfg($p['brand'] ?? 'promanaged');
        if ($c['ig_id'] === '' || $c['token'] === '') {
            continue;
        }
        $was = pm_brand();
        pm_brand_set($p['brand'] ?? 'promanaged');
        try {
            $q = $p;
            $ok = pm_social_ig($q, $c, '');
            $keys = array_flip(['ig', 'ig_pub', 'ig_post', 'ig_container', 'ig_container_at']);
            pm_social_patch($p['id'], fn(array $cur) => pm_social_log_add(array_merge($cur, array_intersect_key($q, $keys)), $ok ? 'instagram' : 'instagram_pending', mb_substr((string)$q['ig'], 0, 100), 'system'));
            $n += $ok ? 1 : 0;
        } finally {
            pm_brand_set($was);
        }
    }
    return $n;
}

/**
 * Publishes one post to the Facebook Page, Instagram and LinkedIn as switched on. Mutates $p (status, ids, errors). Returns [ok, message].
 * Never posts twice: a post that was attempted before is first looked for on the Page; channels already done (fb_id, ig_post, li) are skipped.
 * Never posts text only for a picture post. Formats carousel / story / reel and anything else the Channels module handles go through pm_publish_ext.
 */
function pm_social_publish(array &$p): array
{
    $p += ['ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'format' => 'text'];
    $why = pm_social_lint_post($p); // last guard
    if ($why) {
        $p['status'] = 'needs_edit';
        $p['lint'] = $why;
        return [false, 'Not posted: ' . implode(' ', $why)];
    }
    if ($p['format'] === 'reel' && !pm_social_has_media($p)) {
        $p['status'] = 'needs_video';
        return [false, 'This reel needs its video: record it from the script and attach it.'];
    }
    $c = pm_social_cfg($p['brand']);
    $ch = function_exists('pm_social_settings') ? (array)pm_social_settings($p['brand'])['channels'] : ['facebook' => true, 'instagram' => true, 'linkedin' => false];
    $li = function_exists('pm_linkedin_cfg') && !empty($ch['linkedin']) && pm_linkedin_cfg($p['brand'])['ready'];
    $fbOn = $c['ready'] && !empty($ch['facebook']);
    $igOn = !empty($ch['instagram']) && $c['ig_id'] !== '' && $c['token'] !== '';
    if (!$fbOn && !$li && !$igOn) {
        $p['status'] = 'failed';
        $p['error'] = 'No social account is connected for ' . ($p['brand'] === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ' yet (Social > Accounts & branding).';
        return [false, $p['error']];
    }
    $media = pm_social_has_media($p) ? pm_social_media($p) : '';
    if ($media === '' && $p['format'] === 'image') {
        $media = pm_social_card($p);
        if ($media === '') { // never fall back to a text-only post
            $p['status'] = 'needs_edit';
            $p['error'] = 'Picture could not be drawn (check fonts)';
            return [false, 'Not posted: ' . $p['error'] . '.'];
        }
    }
    $video = $media !== '' && pm_social_is_video($media);
    $done = [];
    // an earlier attempt may have gone out (timeout, error after sending): look on the Page before posting again
    if ($fbOn && ($p['fb_id'] ?? '') === '' && !empty($p['prev_pub_at'])) {
        $hit = pm_social_find_on_page($p);
        if ($hit && $hit['id'] !== '') {
            $p['fb_id'] = $hit['id'];
            $p = pm_social_log_add($p, 'found_on_page', 'Already on the Page: not posted again', 'system');
        }
    }
    $fbDone = ($p['fb_id'] ?? '') !== '';
    $igDone = ($p['ig_post'] ?? '') !== '';
    if ($fbDone) {
        $done[] = 'Facebook';
    }
    $ext = null;
    if (function_exists('pm_publish_ext') && (($fbOn && !$fbDone) || ($igOn && !$igDone))) {
        try {
            $ext = pm_publish_ext($p, $c, $media); // null = not handled (plain image or text)
        } catch (Throwable $e) { // it may have gone out: never auto-republish
            $p['status'] = 'needs_check';
            $p['error'] = 'Stopped while posting: ' . $e->getMessage() . ' Check the Page, then Mark posted or Retry.';
            return [false, $p['error']];
        }
    }
    if ($ext === null && in_array($p['format'], ['carousel', 'story'], true)) {
        $p['status'] = 'needs_edit';
        $p['error'] = 'This ' . $p['format'] . ' post cannot be published here (the Channels module is missing).';
        return [false, $p['error']];
    }
    if ($ext !== null) {
        if (empty($ext['ok'])) {
            $m = (string)($ext['error'] ?? '') ?: 'Publishing failed.';
            pm_social_fail($p, $m);
            return [false, $m . ($p['status'] === 'approved' && !empty($p['retry_at']) ? ' Will retry at ' . substr($p['retry_at'], 11) . '.' : '')];
        }
        if (!empty($ext['fb_id']) && !$fbDone) {
            $p['fb_id'] = (string)$ext['fb_id'];
            $done[] = 'Facebook';
        }
        $ig = (string)($ext['ig'] ?? '');
        if ($ig !== '') {
            if (preg_match('/^\d+$/', $ig)) {
                $p['ig_post'] = $ig;
                $p['ig'] = 'published';
            } else {
                $p['ig'] = $ig;
            }
            if ($p['ig'] === 'published') {
                $done[] = 'Instagram';
            }
        }
        if (!$done) {
            $done[] = 'your other channels';
        }
    } elseif ($fbOn && !$fbDone) {
        $caption = pm_social_caption($p);
        $GLOBALS['PM_GRAPH_ERR'] = null;
        if ($video) {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/videos', ['description' => $caption], $c['token'], ['source' => $media], true);
        } elseif ($media !== '') {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/photos', ['caption' => $caption] + (trim((string)($p['alt'] ?? '')) !== '' ? ['alt_text_custom' => mb_substr(trim((string)$p['alt']), 0, 1000)] : []), $c['token'], ['source' => $media]);
        } else {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/feed', ['message' => $caption], $c['token']);
        }
        if (!$ok) {
            pm_social_fail($p, (string)$d);
            return [false, (string)$d . ($p['status'] === 'approved' && !empty($p['retry_at']) ? ' Will retry at ' . substr($p['retry_at'], 11) . '.' : '')];
        }
        $p['fb_id'] = (string)($d['post_id'] ?? $d['id'] ?? '');
        $done[] = 'Facebook';
    }
    if ($li && ($p['li'] ?? '') !== 'published') {
        $args = [$p['brand'], pm_social_caption($p, 'linkedin')];
        if ((new ReflectionFunction('pm_linkedin_post'))->getNumberOfParameters() >= 3) {
            $args[] = $p; // lets it add the picture
        }
        $r = pm_linkedin_post(...$args);
        $okL = !empty($r[0]);
        $p['li'] = $okL ? 'published' : (string)($r[1] ?? 'failed');
        if ($okL && !empty($r[2]) && is_string($r[2])) {
            $p['li_urn'] = $r[2];
        }
        if ($okL) {
            $done[] = 'LinkedIn';
        }
    } elseif ($li) {
        $done[] = 'LinkedIn';
    }
    if ($igOn && $ext === null && !$igDone) {
        if ($media !== '' && !$video && pm_app_url() !== '') {
            if (pm_social_ig($p, $c, $media)) {
                $done[] = 'Instagram';
            } elseif (str_starts_with((string)$p['ig'], 'pending') && !$done) {
                $done[] = 'Instagram (finishing)';
            }
        } else {
            $p['ig'] = 'skipped: ' . ($video ? 'videos are not posted to Instagram from here' : ($media === '' ? 'Instagram needs a picture' : 'the app is not online (APP_URL)'));
        }
    }
    if (!$done && $igOn && !$fbOn && !$li && str_starts_with((string)$p['ig'], 'check:')) { // Instagram may have posted: look first
        $p['status'] = 'needs_check';
        $p['error'] = (string)$p['ig'];
        return [false, $p['error']];
    }
    if (!$done) { // nothing went out (IG-only or LinkedIn-only failed)
        $m =$igOn && !$fbOn && !$li ? (string)preg_replace('/^(failed|check): /', '', $p['ig']) : (string)($p['li'] ?? 'Not posted');
        pm_social_fail($p, $m);
        return [false, $m];
    }
    $p['status'] = 'published';
    $p['published'] = date('Y-m-d H:i');
    $p['error'] = '';
    $p['retry_at'] = '';
    $p['tries'] = 0;
    foreach (pm_social_registered('pm_hook_after_publish_') as $fn) { // modules add their follow-ups (first comment, manual tasks, ids)
        try {
            $patch = $fn($p, $c);
            if (is_array($patch)) {
                $p = array_merge($p, $patch);
            }
        } catch (Throwable $e) {
            pm_agent_log('Social', 'After-publish step ' . substr($fn, 22) . ' failed: ' . mb_substr($e->getMessage(), 0, 120));
        }
    }
    return [true, 'Published to ' . implode(', ', $done) . '.'];
}

/** Claims a post ('publishing', under the lock), publishes it outside the lock, then writes the result at once. Returns [ok, message]. */
function pm_social_run_one(string $id, bool $manual = false): array
{
    $claimed = null;
    $allowed = $manual ? ['draft', 'approved', 'failed', 'needs_check'] : ['approved'];
    pm_social_update(function (array $posts) use ($id, $allowed, &$claimed) {
        foreach ($posts as $i => $p) {
            if (($p['id'] ?? '') === $id && in_array($p['status'] ?? '', $allowed, true)) {
                $posts[$i]['prev_pub_at'] = (string)($p['pub_at'] ?? ''); // an earlier attempt? publish looks on the Page first
                $posts[$i]['status'] = 'publishing';
                $posts[$i]['pub_at'] = date('Y-m-d H:i:s');
                $claimed = $posts[$i];
            }
        }
        return $posts;
    });
    if (!$claimed) {
        return [false, 'That post cannot be published now (it is already posting, held back, or needs an edit or a video first).'];
    }
    $was = pm_brand();
    pm_brand_set($claimed['brand']);
    $claimed['status'] = 'approved';
    try {
        [$ok, $msg] = pm_social_publish($claimed);
    } catch (Throwable $e) { // it may have gone out: never auto-republish
        $claimed['status'] = 'needs_check';
        $claimed['error'] = 'Stopped while posting: ' . $e->getMessage() . ' Check the Page, then Mark posted or Retry.';
        [$ok, $msg] = [false, $claimed['error']];
    }
    if (($claimed['status'] ?? '') === 'publishing') {
        $claimed['status'] = $ok ? 'published' : 'needs_check';
    }
    $merge = $claimed;
    unset($merge['log'], $merge['id'], $merge['brand'], $merge['caption'], $merge['hashtags'], $merge['headline'], $merge['sub'], $merge['when'], $merge['media'], $merge['script'], $merge['format']);
    $by = $manual ? '' : 'system';
    pm_social_patch($id, fn(array $cur) => pm_social_log_add(array_merge($cur, $merge), $ok ? 'published' : 'not_published', mb_substr($msg, 0, 140), $by));
    pm_agent_log('Social', ($claimed['brand'] === 'travel' ? '[Travel Malawi] ' : '') . ($ok ? 'Posted: ' : 'Post not posted: ') . mb_substr((string)$claimed['caption'], 0, 60) . ($ok ? '' : ' (' . mb_substr($msg, 0, 120) . ')'));
    pm_brand_set($was);
    return [$ok, $msg];
}

/** Retries only the Instagram part of a post that is already on Facebook. Returns [ok, message]. */
function pm_social_retry_ig(string $id): array
{
    $p = null;
    foreach (pm_social_posts() as $x) {
        if (($x['id'] ?? '') === $id) {
            $p = $x;
        }
    }
    if (!$p || ($p['status'] ?? '') !== 'published') {
        return [false, 'Only a post that is already published can retry Instagram.'];
    }
    if (($p['ig_post'] ?? '') !== '') {
        return [true, 'It is already on Instagram.'];
    }
    $c = pm_social_cfg($p['brand']);
    $media = pm_social_has_media($p) ? pm_social_media($p) : (is_file(pm_social_dir() . '/' . $p['id'] . '.png') ? pm_social_dir() . '/' . $p['id'] . '.png' : '');
    if ($c['ig_id'] === '' || $c['token'] === '' || $media === '' || pm_social_is_video($media) || pm_app_url() === '') {
        return [false, 'Instagram needs a connected account, a picture and the app online (APP_URL).'];
    }
    $was = pm_brand();
    pm_brand_set($p['brand']);
    $ok = pm_social_ig($p, $c, $media);
    pm_brand_set($was);
    $keys = array_flip(['ig', 'ig_pub', 'ig_post', 'ig_container', 'ig_container_at']);
    pm_social_patch($id, fn(array $cur) => pm_social_log_add(array_merge($cur, array_intersect_key($p, $keys)), $ok ? 'instagram' : 'instagram_retry', mb_substr((string)$p['ig'], 0, 100)));
    return [$ok, $ok ? 'Posted on Instagram.' : (string)$p['ig']];
}

/**
 * Housekeeping under the lock: stuck 'publishing' -> needs_check; an unsent post whose expires date has passed -> 'expired' (never moved);
 * missed slots and past-dated drafts move to the next free slot (not while that brand's token is down: approved posts wait as they are).
 * Old Instagram helper files (ig_*, over 7 days) are deleted.
 */
function pm_social_housekeeping(): void
{
    $down = (array)(pm_social_state()['auth_down'] ?? []);
    $today = date('Y-m-d');
    pm_social_update(function (array $posts) use ($down, $today) {
        $now = time();
        $slide = function (array $posts, int $i) use ($now, $today): array {
            $p = $posts[$i];
            $b = $p['brand'] ?? 'promanaged';
            $when = pm_social_free_slot($posts, $b, $now, $p['id']);
            $exp = (string)($p['expires'] ?? '');
            if ($exp !== '' && substr($when, 0, 10) > $exp) {
                $posts[$i]['status'] = 'expired';
                $posts[$i] = pm_social_log_add($posts[$i], 'expired', 'The date it was for has passed', 'system');
            } else {
                $posts[$i]['when'] = $when;
            }
            return $posts;
        };
        foreach ($posts as $i => $p) {
            $st = $p['status'] ?? '';
            $b = $p['brand'] ?? 'promanaged';
            $exp = (string)($p['expires'] ?? '');
            if ($st === 'publishing' && ($p['fb_id'] ?? '') === '' && strtotime((string)($p['pub_at'] ?? '')) < $now - 600) {
                $posts[$i]['status'] = 'needs_check';
                $posts[$i]['error'] = 'Posting was interrupted. Check the Page, then Mark posted or Retry.';
            } elseif ($exp !== '' && $exp < $today && in_array($st, PM_SOCIAL_UNSENT, true)) {
                $posts[$i]['status'] = 'expired'; // stale (an event or date that has passed): never posts, never moves
                $posts[$i] = pm_social_log_add($posts[$i], 'expired', 'The date it was for has passed', 'system');
            } elseif ($st === 'approved' && ($p['format'] ?? '') === 'reel' && !pm_social_has_media($p)) {
                $posts[$i]['status'] = 'needs_video'; // never posts as text
            } elseif ($st === 'approved' && strtotime((string)$p['when']) < $now - 6 * 3600 && empty($down[$b])) {
                $posts[$i]['status'] = 'draft';
                $posts[$i]['approved_hash'] = '';
                $posts[$i]['note'] = 'missed slot, reschedule';
                $posts = $slide($posts, $i);
            } elseif (in_array($st, ['draft', 'needs_edit', 'needs_video', 'review', 'needs_asset'], true) && strtotime((string)$p['when']) < $now - 3600) {
                $posts = $slide($posts, $i);
            }
        }
        return $posts;
    });
    foreach (glob(pm_social_dir() . '/ig_*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 7 * 86400) {
            @unlink($f);
        }
    }
}

/**
 * Publishes approved posts whose time has come: at most 1 per brand per run, 2 feed posts per brand per day, one at a time, each saved before the next.
 * A brand whose token is down is skipped (its posts stay approved). Then finishes Instagram pictures that were still processing. Returns how many succeeded.
 */
function pm_social_due(bool $force = false): int
{
    $stamp = PM_DATA . '/social_due.stamp';
    if (!$force && is_file($stamp) && filemtime($stamp) > time() - 240) {
        return 0;
    }
    @touch($stamp);
    $lock = @fopen(PM_DATA . '/social.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $n = 0;
    try {
        pm_social_housekeeping();
        $down = (array)(pm_social_state()['auth_down'] ?? []);
        foreach ($down as $b => $d) { // is the token fine again? (checked at most every 30 minutes)
            if (time() - (int)($d['checked'] ?? 0) > 1800) {
                try {
                    if (pm_social_auth_recheck((string)$b)) {
                        unset($down[$b]);
                    }
                } catch (Throwable) {
                }
            }
        }
        $posts = pm_social_posts();
        usort($posts, fn($a, $b) => strcmp((string)$a['when'], (string)$b['when']));
        $now = time();
        $today = date('Y-m-d');
        $per = [];
        foreach ($posts as $p) {
            if (($p['status'] ?? '') === 'published' && str_starts_with((string)($p['published'] ?? ''), $today) && ($p['format'] ?? '') !== 'story') {
                $per[$p['brand']] = ($per[$p['brand']] ?? 0) + 1;
            }
        }
        $pick = [];
        foreach ($posts as $p) {
            $b = $p['brand'] ?? 'promanaged';
            $exp = (string)($p['expires'] ?? '');
            if (($p['status'] ?? '') === 'approved' && strtotime((string)$p['when']) <= $now && (empty($p['retry_at']) || strtotime($p['retry_at']) <= $now)
                && empty($down[$b]) && ($exp === '' || $exp >= $today) && !isset($pick[$b]) && (($p['format'] ?? '') === 'story' || ($per[$b] ?? 0) < 2)) {
                $pick[$b] = $p['id'];
            }
        }
        foreach ($pick as $id) {
            [$ok] = pm_social_run_one($id);
            $n += $ok ? 1 : 0;
        }
        try {
            pm_social_ig_poll();
        } catch (Throwable) {
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $n;
}

/* ---------------- keeping the calendar full ---------------- */

/**
 * If fewer than 3 posts are lined up for the next 5 days: first brings back the best old posts (pm_recycle_fill), then plans 5 more with AI
 * (focus from pm_social_focus_for). If the AI budget is used up or the call fails, it writes 3 offline template drafts instead, so the calendar is never empty.
 * "Planned" is remembered for 5 days only when the AI worked; after a failure it tries again in 6 hours. Returns a short status.
 */
function pm_social_replenish(string $brand): string
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $now = time();
        $ahead = function () use ($brand, $now): int {
            return count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand && in_array($p['status'] ?? '', ['draft', 'approved', 'review'], true)
                && strtotime((string)$p['when']) >= $now - 3600 && strtotime((string)$p['when']) <= $now + 5 * 86400));
        };
        if (($a = $ahead()) >= 3) {
            return 'calendar full (' . $a . ' lined up)';
        }
        $note = '';
        if (function_exists('pm_recycle_fill')) {
            try {
                $r = (int)pm_recycle_fill($brand, 2);
                $note = $r > 0 ? "$r recycled; " : '';
            } catch (Throwable) {
            }
            if (($a = $ahead()) >= 3) {
                return $note . 'calendar full (' . $a . ' lined up)';
            }
        }
        $st = pm_social_state();
        if (max((int)($st['replenish'][$brand] ?? 0) + 5 * 86400, (int)($st['replenish_until'][$brand] ?? 0)) > $now) {
            return $note . 'planned recently (waiting for the next time)';
        }
        $posts = pm_social_posts();
        $start = pm_social_next_free_date($posts, $brand, date('Y-m-d', $now + 86400));
        $focus = function_exists('pm_social_focus_for') ? (string)pm_social_focus_for($brand) : 'mix';
        $focus = isset(PM_SOCIAL_FOCUS[$focus]) ? $focus : 'mix';
        $new = [];
        $failed = '';
        try {
            pm_budget_check();
            $new = function_exists('pm_plan_posts') ? pm_plan_posts(5, $focus, $start) : pm_agent_social_plan(5, $focus, $start);
        } catch (Throwable $e) {
            $failed = $e->getMessage();
        }
        if ($failed === '' && !$new) {
            $failed = 'the planner returned no posts';
        }
        $until = $failed === '' ? $now + 5 * 86400 : $now + 6 * 3600; // 5 days only when the AI worked
        pm_social_state_set(function (array $s) use ($brand, $until) {
            $s['replenish_until'][$brand] = $until;
            return $s;
        });
        if ($failed !== '') {
            if (!function_exists('pm_plan_offline')) {
                return $note . 'could not plan: ' . $failed;
            }
            $new = pm_plan_offline($brand, 3, $start);
        }
        $n = pm_social_add_planned($new, pm_social_auto($brand));
        pm_agent_log('Social', $failed === '' ? "Calendar topped up: $n post(s) planned" : "Calendar topped up with $n offline draft(s) (AI unavailable: " . mb_substr($failed, 0, 100) . ')');
        return $note . ($failed === '' ? "$n post(s) planned" : "$n offline draft(s) planned (AI unavailable: " . mb_substr($failed, 0, 80) . ')');
    } catch (Throwable $e) {
        return 'could not plan: ' . $e->getMessage();
    } finally {
        pm_brand_set($was);
    }
}

/** One owner alert a day when drafts have waited more than 24 hours for approval. Returns the number waiting. */
function pm_social_notify_waiting(): int
{
    $old = array_filter(pm_social_posts(), fn($p) => in_array($p['status'] ?? '', ['draft', 'review'], true) && strtotime((string)($p['created'] ?? '')) < time() - 86400);
    if (!$old || (pm_social_state()['stale_day'] ?? '') === date('Y-m-d')) {
        return 0;
    }
    pm_social_state_set(function (array $s) { $s['stale_day'] = date('Y-m-d'); return $s; });
    pm_social_notify(count($old) . ' social post(s) waiting for your approval', 'Open Social > Plan and approve them (or press "Approve all that pass checks"). Nothing posts until you do.');
    return count($old);
}
