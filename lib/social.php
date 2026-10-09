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
        str_ends_with($path, '/reactions') => 3600,
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
    if ($set !== null) {
        @file_put_contents($f, json_encode($set), LOCK_EX);
        return $set;
    }
    $g = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
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
    $ttl = $read && (($params['fields'] ?? '') !== 'status_code') ? pm_graph_ttl($path) : 0; // media status is polled live
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
    $all = pm_load('social_page', fn() => []);
    $all[$brand] = $out;
    pm_save('social_page', $all);
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

/** Replaces one brand's proof rows with the edited list (other brand untouched). Text is stored exactly as typed. */
function pm_social_proof_save(string $brand, array $rows): void
{
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
        $clean[] = ['id' => $id, 'proof_id' => $id, 'brand' => $brand, 'type' => in_array($r['type'] ?? '', ['quote', 'result', 'photo', 'offer'], true) ? $r['type'] : 'quote',
            'text' => mb_substr($text, 0, 600), 'client_name' => mb_substr($name, 0, 80), 'consent' => !empty($r['consent']), 'consent_note' => mb_substr(trim((string)($r['consent_note'] ?? '')), 0, 200),
            'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : '', 'lead_id' => (string)($r['lead_id'] ?? '')];
    }
    pm_update('social_proof', fn(array $all) => array_merge(array_values(array_filter($all, fn($r) => ($r['brand'] ?? 'promanaged') !== $brand)), $clean), fn() => []);
}

/** A lead became a client: ask them for a one-line quote and permission to feature them (a blank row waits in the proof bank). */
function pm_social_proof_request(array $lead): void
{
    $lid = (string)($lead['id'] ?? '');
    if ($lid === '') {
        return;
    }
    pm_update('social_proof', function (array $rows) use ($lead, $lid) {
        foreach ($rows as $r) {
            if (($r['lead_id'] ?? '') === $lid) {
                return $rows;
            }
        }
        $id = bin2hex(random_bytes(6));
        $rows[] = ['id' => $id, 'proof_id' => $id, 'brand' => ($lead['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged', 'type' => 'quote', 'text' => '',
            'client_name' => (string)($lead['name'] ?? ''), 'consent' => false, 'consent_note' => 'Ask for a one-line quote and permission to feature them', 'expires' => '', 'lead_id' => $lid];
        return $rows;
    }, fn() => []);
}

/* ---------------- slots and cadence: max 1 post a day per brand, never the same pillar twice running ---------------- */

const PM_SOCIAL_LIVE = ['draft', 'approved', 'publishing', 'needs_edit', 'needs_video', 'needs_check'];

/** Hours (HH:MM) to post at: this Page's own best hours when known, else the Malawi habits. */
function pm_social_hours(string $brand): array
{
    $h = function_exists('pm_fb_best_times') ? (array)(pm_fb_best_times($brand)['hours'] ?? []) : [];
    return $h ? array_map(fn($x) => sprintf('%02d:00', (int)$x), array_values($h)) : ['07:30', '12:30', '18:30'];
}

/** Days (Y-m-d => true) a brand already has a post on, live or published. */
function pm_social_busy_days(array $posts, string $brand, string $exceptId = ''): array
{
    $days = [];
    foreach ($posts as $p) {
        if (($p['brand'] ?? 'promanaged') === $brand && ($p['id'] ?? '') !== $exceptId && (in_array($p['status'] ?? '', PM_SOCIAL_LIVE, true) || ($p['status'] ?? '') === 'published') && !empty($p['when'])) {
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

/** Next free slot ('Y-m-d H:i') at least 30 minutes after $fromTs. */
function pm_social_free_slot(array $posts, string $brand, int $fromTs, string $exceptId = '', ?array $hours = null): string
{
    $hours ??= pm_social_hours($brand);
    $t = $hours === ['07:30', '12:30', '18:30'] ? '18:30' : ($hours[0] ?? '18:30');
    $busy = pm_social_busy_days($posts, $brand, $exceptId);
    for ($i = 0; $i < 120; $i++) {
        $d = date('Y-m-d', $fromTs + $i * 86400);
        $ts = strtotime("$d $t");
        if ($ts >= $fromTs + 1800 && empty($busy[$d])) {
            return date('Y-m-d H:i', $ts);
        }
    }
    return date('Y-m-d H:i', $fromTs + 86400);
}

/**
 * Gives new posts one free day each from $start and shuffles them so no two neighbours share a pillar
 * (also against the post just before). Posts may carry '_off' (the AI's day offset, for order); time stays from 'when'.
 */
function pm_social_cadence(array $new, array $existing, string $brand, string $start): array
{
    usort($new, fn($a, $b) => (int)($a['_off'] ?? 0) <=> (int)($b['_off'] ?? 0));
    $busy = pm_social_busy_days($existing, $brand);
    $days = [];
    for ($i = 0; count($days) < count($new) && $i < 200; $i++) {
        $d = date('Y-m-d', strtotime("$start +$i days"));
        if (empty($busy[$d])) {
            $days[] = $d;
        }
    }
    $prev = '';
    $before = array_filter($existing, fn($p) => ($p['brand'] ?? 'promanaged') === $brand && ($p['status'] ?? '') !== 'failed' && !empty($p['when']) && substr($p['when'], 0, 10) < ($days[0] ?? $start));
    usort($before, fn($a, $b) => strcmp($b['when'], $a['when']));
    $prev = strtolower((string)($before[0]['pillar'] ?? ''));
    $n = count($new);
    for ($i = 0; $i < $n; $i++) {
        if (strtolower((string)($new[$i]['pillar'] ?? '')) === $prev && $prev !== '') {
            for ($j = $i + 1; $j < $n; $j++) {
                if (strtolower((string)($new[$j]['pillar'] ?? '')) !== $prev) {
                    [$new[$i], $new[$j]] = [$new[$j], $new[$i]];
                    break;
                }
            }
        }
        $prev = strtolower((string)($new[$i]['pillar'] ?? ''));
    }
    foreach ($new as $i => &$p) {
        $time = preg_match('/(\d{1,2}:\d{2})$/', (string)($p['when'] ?? ''), $m) ? $m[1] : '18:30';
        $p['when'] = ($days[$i] ?? $days[count($days) - 1] ?? $start) . ' ' . str_pad($time, 5, '0', STR_PAD_LEFT);
        unset($p['_off']);
    }
    return array_values($new);
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
    return $why;
}

function pm_social_lint_post(array $p): array
{
    $b = $p['brand'] ?? 'promanaged';
    return pm_social_lint((string)($p['caption'] ?? ''), $b, 'facebook', pm_social_recent_from(pm_social_posts(), $b, (string)($p['id'] ?? ''), (string)($p['when'] ?? '')), $p);
}

/** Sets the draft-stage status from the facts: lint fails -> needs_edit, reel without video -> needs_video, else draft. Published or posting posts are left alone. */
function pm_social_resolve(array $p, array $lint): array
{
    $p['lint'] = $lint;
    if (in_array($p['status'] ?? '', ['draft', 'needs_edit', 'needs_video', 'approved'], true)) {
        $video = ($p['media'] ?? '') !== '' && is_file($p['media']);
        $p['status'] = $lint ? 'needs_edit' : (($p['format'] ?? '') === 'reel' && !$video ? 'needs_video' : (($p['status'] ?? '') === 'approved' ? 'approved' : 'draft'));
    }
    return $p;
}

/** Approves one post if it passes every check. Returns [post, message, ok]. Draws the picture when needed. */
function pm_social_approve(array $p): array
{
    $p = pm_social_resolve($p, pm_social_lint_post($p));
    if ($p['status'] === 'needs_edit') {
        return [$p, 'Not approved: ' . implode(' ', $p['lint']), false];
    }
    if ($p['status'] === 'needs_video') {
        return [$p, 'Not approved: a reel needs its video. Record it from the script and attach it.', false];
    }
    if (($p['format'] ?? '') === 'image' && ($p['media'] ?? '') === '') {
        pm_social_card($p);
    }
    $p['status'] = 'approved';
    $p['retry_at'] = '';
    $p['tries'] = 0;
    return [$p, 'Approved for ' . date('D j M, H:i', strtotime($p['when'])) . '.', true];
}

/* ---------------- AI content planner ---------------- */

const PM_SOCIAL_FOCUS = [
    'mix' => 'A balanced mix', 'leads' => 'Get enquiries (clear call to action)', 'tips' => 'Useful tips that build trust',
    'awareness' => 'Show what we do', 'story' => 'Real-life problems and how they are solved',
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
            'cta' => in_array($p['cta'] ?? '', ['comment', 'save', 'share', 'tag', 'whatsapp'], true) ? $p['cta'] : '', 'proof_id' => preg_replace('/[^a-f0-9]/', '', (string)($p['proof_id'] ?? '')),
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

/** Saves freshly planned posts (pictures drawn, status set: approved only when auto-publish is on and every check passes). Returns how many. */
function pm_social_add_planned(array $new, bool $auto): int
{
    foreach ($new as $i => $np) {
        if ($np['format'] === 'image' && function_exists('imagecreatetruecolor')) {
            try {
                pm_social_card($np);
            } catch (Throwable) {
            }
        }
        if ($auto && $np['status'] === 'draft') {
            $np['status'] = 'approved';
        }
        $new[$i] = $np;
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

function pm_font(bool $serif, bool $bold = false): string
{
    $cands = $serif
        ? [PM_ROOT . '/assets/fonts/serif.ttf', 'C:/Windows/Fonts/georgia' . ($bold ? 'b' : '') . '.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif' . ($bold ? '-Bold' : '') . '.ttf']
        : [PM_ROOT . '/assets/fonts/sans' . ($bold ? '-bold' : '') . '.ttf', 'C:/Windows/Fonts/arial' . ($bold ? 'bd' : '') . '.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf'];
    foreach ($cands as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}

/** Wraps $text to lines that fit $w pixels. */
function pm_wrap_px(string $text, string $font, float $size, int $w): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $try = $line === '' ? $word : "$line $word";
        $b = imagettfbbox($size, 0, $font, $try);
        if ($b[2] - $b[0] > $w && $line !== '') {
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

/** Draws a 1080x1080 branded picture for a post. Returns the file path, or '' if no font is available. */
function pm_social_card(array $p): string
{
    $serif = pm_font(true);
    $sans = pm_font(false);
    $sansB = pm_font(false, true);
    if ($serif === '' || $sans === '') {
        return '';
    }
    pm_brand_set($p['brand']);
    $s = pm_settings();
    $W = 1080;
    $im = imagecreatetruecolor($W, $W);
    [$r, $g, $b] = sscanf(ltrim($s['accent_color'] ?: '#17375E', '#'), '%02x%02x%02x');
    $accent = imagecolorallocate($im, $r, $g, $b);
    $bg = imagecolorallocate($im, 250, 249, 247);
    $ink = imagecolorallocate($im, 22, 24, 29);
    $muted = imagecolorallocate($im, 96, 101, 110);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $bg);
    imagefilledrectangle($im, 0, 0, $W, 16, $accent);
    imagefilledrectangle($im, 80, 250, 200, 258, $accent);
    // headline
    $head = trim($p['headline'] ?: mb_substr($p['caption'], 0, 50));
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
    if (trim($p['sub']) !== '') {
        $y += 20;
        foreach (array_slice(pm_wrap_px($p['sub'], $sans, 30, $W - 160), 0, 3) as $l) {
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
    imagettftext($im, 34, 0, $x, $W - 62, $white, $sansB ?: $sans, $s['company_name']);
    $link = pm_link_cfg($p['brand'])['url'] ?: ($s['website'] ?? '');
    if ($link !== '') {
        $t = preg_replace('#^https?://#', '', rtrim($link, '/'));
        $bb = imagettfbbox(24, 0, $sans, $t);
        imagettftext($im, 24, 0, $W - 80 - ($bb[2] - $bb[0]), $W - 64, $white, $sans, $t);
    }
    $f = pm_social_dir() . '/' . $p['id'] . '.png';
    imagepng($im, $f, 6);
    imagedestroy($im);
    return $f;
}

/* ---------------- publishing ---------------- */

function pm_social_sleep(int $s): void
{
    if (empty($GLOBALS['PM_NOSLEEP'])) {
        sleep($s);
    }
}

function pm_social_has_media(array $p): bool { return ($p['media'] ?? '') !== '' && is_file($p['media']); }
function pm_social_is_video(string $f): bool { return (bool)preg_match('/\.(mp4|mov|m4v)$/i', $f); }

/**
 * The caption as posted. Facebook/LinkedIn: text, a WhatsApp link that opens a chat about this post, the website link (when on) tagged with the post id, hashtags.
 * Instagram has no clickable links: it says "message us on WhatsApp".
 */
function pm_social_caption(array $p, string $channel = 'facebook'): string
{
    pm_brand_set($p['brand']);
    $t = trim($p['caption']);
    $s = pm_settings();
    $phone = trim((string)($s['phone'] ?? ''));
    $head = trim((string)($p['headline'] ?? '')) ?: mb_substr($t, 0, 40);
    if ($channel === 'instagram') {
        $t .= "\n\nMessage us on WhatsApp" . ($phone !== '' ? ': ' . $phone : '.');
    } else {
        $wa = function_exists('pm_wa_link') && !str_contains($t, 'wa.me') ? pm_wa_link(['whatsapp' => $phone, 'phone' => $phone], 'Hi, I saw your post: ' . $head) : '';
        if ($wa !== '') {
            $t .= "\n\nMessage us on WhatsApp: " . $wa;
        }
        $lk = pm_link_cfg($p['brand']);
        if ($lk['on'] && !str_contains($t, $lk['url'])) {
            $t .= "\n\n" . $lk['url'] . (str_contains($lk['url'], '?') ? '&' : '?') . 'utm_source=' . ($channel === 'linkedin' ? 'linkedin' : 'facebook') . '&utm_medium=social&utm_campaign=' . $p['id'];
        }
    }
    if ($p['hashtags']) {
        $t .= "\n\n" . implode(' ', $p['hashtags']);
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

/** Records a failed attempt on the post per its kind. Rate limits: stays approved and retries in 30 minutes, 4 tries, then failed + owner alert. */
function pm_social_fail(array &$p, string $msg): string
{
    $k = pm_social_err_kind($msg);
    $p['error'] = $msg;
    $label = ($p['brand'] === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ': ';
    if ($k === 'transient') {
        $p['tries'] = (int)($p['tries'] ?? 0) + 1;
        if ($p['tries'] >= 4) {
            $p['status'] = 'failed';
            pm_social_notify('Social post failed after 4 tries', $label . mb_substr($p['caption'], 0, 80) . "\n" . $msg);
        } else {
            $p['status'] = 'approved';
            $p['retry_at'] = date('Y-m-d H:i', time() + 1800);
        }
    } elseif ($k === 'check') {
        $p['status'] = 'needs_check';
        $p['error'] = 'No answer from Facebook while posting. Check the Page, then Mark posted or Retry. (' . $msg . ')';
    } else {
        $p['status'] = 'failed';
        if ($k === 'auth') {
            pm_social_notify('Facebook token needs renewing', $label . "A social post could not go out because the access token expired or was revoked.\n" . $msg);
        }
    }
    return $k;
}

/** Instagram picture post: create the container, wait for it to be ready (up to 5 checks, 10 s apart), publish. Sets $p['ig']. */
function pm_social_ig(array &$p, array $c, string $media): bool
{
    $pub = (string)($p['ig_pub'] ?? '');
    if ($pub === '' || !is_file(pm_social_dir() . '/' . $pub)) {
        $pub = 'ig_' . bin2hex(random_bytes(12)) . '.jpg';
        $src = @imagecreatefromstring((string)file_get_contents($media));
        if (!$src) {
            $p['ig'] = 'failed: the picture could not be read';
            return false;
        }
        imagejpeg($src, pm_social_dir() . '/' . $pub, 90);
        $p['ig_pub'] = $pub;
    }
    [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['image_url' => pm_app_url() . '/media.php?f=' . $pub, 'caption' => pm_social_caption($p, 'instagram')], $c['token']);
    if (!$ok || empty($d['id'])) {
        $p['ig'] = 'failed: ' . (is_string($d) ? $d : 'no media container');
        return false;
    }
    $st = '';
    for ($i = 0; $i < 5; $i++) {
        [$ok2, $s2] = pm_graph('GET', (string)$d['id'], ['fields' => 'status_code'], $c['token']);
        $st = $ok2 && is_array($s2) ? (string)($s2['status_code'] ?? '') : '';
        if (in_array($st, ['FINISHED', 'ERROR', 'EXPIRED'], true)) {
            break;
        }
        if ($i < 4) {
            pm_social_sleep(10);
        }
    }
    if ($st !== 'FINISHED') {
        $p['ig'] = 'failed: Instagram did not finish processing the picture (' . ($st ?: 'no answer') . ')';
        return false;
    }
    [$ok3, $d3] = pm_graph('POST', $c['ig_id'] . '/media_publish', ['creation_id' => $d['id']], $c['token']);
    if (!$ok3) {
        $p['ig'] = 'failed: ' . (is_string($d3) ? $d3 : 'could not publish');
        return false;
    }
    $p['ig'] = 'published';
    $p['ig_post'] = (string)($d3['id'] ?? '');
    return true;
}

/** Publishes one post to the Facebook Page, Instagram and LinkedIn as switched on. Mutates $p (status, ids, errors). Returns [ok, message]. */
function pm_social_publish(array &$p): array
{
    $p += ['ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => []];
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
    $media = pm_social_has_media($p) ? $p['media'] : '';
    if ($media === '' && $p['format'] === 'image') {
        $media = pm_social_card($p);
    }
    $video = $media !== '' && pm_social_is_video($media);
    $done = [];
    if ($fbOn) {
        $caption = pm_social_caption($p);
        $GLOBALS['PM_GRAPH_ERR'] = null;
        if ($video) {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/videos', ['description' => $caption], $c['token'], ['source' => $media], true);
        } elseif ($media !== '') {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/photos', ['caption' => $caption], $c['token'], ['source' => $media]);
        } else {
            [$ok, $d] = pm_graph('POST', $c['page_id'] . '/feed', ['message' => $caption], $c['token']);
        }
        if (!$ok) {
            pm_social_fail($p, (string)$d);
            return [false, (string)$d . ($p['status'] === 'approved' ? ' Will retry at ' . substr($p['retry_at'], 11) . '.' : '')];
        }
        $p['fb_id'] = (string)($d['post_id'] ?? $d['id'] ?? '');
        $done[] = 'Facebook';
    }
    if ($li) {
        [$okL, $mL] = pm_linkedin_post($p['brand'], pm_social_caption($p, 'linkedin'));
        $p['li'] = $okL ? 'published' : $mL;
        if ($okL) {
            $done[] = 'LinkedIn';
        }
    }
    if ($igOn) {
        if ($media !== '' && !$video && pm_app_url() !== '') {
            if (pm_social_ig($p, $c, $media)) {
                $done[] = 'Instagram';
            }
        } else {
            $p['ig'] = 'skipped: ' . ($video ? 'videos are not posted to Instagram from here' : ($media === '' ? 'Instagram needs a picture' : 'the app is not online (APP_URL)'));
        }
    }
    if (!$done) { // nothing went out (IG-only or LinkedIn-only failed)
        $m = $igOn && !$fbOn && !$li ? (string)preg_replace('/^failed: /', '', $p['ig']) : (string)($p['li'] ?? 'Not posted');
        pm_social_fail($p, $m);
        return [false, $m];
    }
    $p['status'] = 'published';
    $p['published'] = date('Y-m-d H:i');
    $p['error'] = '';
    $p['retry_at'] = '';
    $p['tries'] = 0;
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
    $keep = array_flip(['status', 'fb_id', 'ig', 'ig_pub', 'ig_post', 'li', 'error', 'published', 'retry_at', 'tries', 'lint', 'pub_at']);
    pm_social_patch($id, fn(array $cur) => array_merge($cur, array_intersect_key($claimed, $keep)));
    pm_agent_log('Social', ($claimed['brand'] === 'travel' ? '[Travel Malawi] ' : '') . ($ok ? 'Posted: ' : 'Post not posted: ') . mb_substr($claimed['caption'], 0, 60) . ($ok ? '' : ' (' . mb_substr($msg, 0, 120) . ')'));
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
    $c = pm_social_cfg($p['brand']);
    $media = pm_social_has_media($p) ? $p['media'] : (is_file(pm_social_dir() . '/' . $p['id'] . '.png') ? pm_social_dir() . '/' . $p['id'] . '.png' : '');
    if ($c['ig_id'] === '' || $c['token'] === '' || $media === '' || pm_social_is_video($media) || pm_app_url() === '') {
        return [false, 'Instagram needs a connected account, a picture and the app online (APP_URL).'];
    }
    $was = pm_brand();
    pm_brand_set($p['brand']);
    $ok = pm_social_ig($p, $c, $media);
    pm_brand_set($was);
    pm_social_patch($id, fn(array $cur) => array_merge($cur, array_intersect_key($p, array_flip(['ig', 'ig_pub', 'ig_post']))));
    return [$ok, $ok ? 'Posted on Instagram.' : $p['ig']];
}

/** Housekeeping under the lock: stuck 'publishing' -> needs_check; missed slots and past-dated drafts move to the next free slot. */
function pm_social_housekeeping(): void
{
    pm_social_update(function (array $posts) {
        $now = time();
        foreach ($posts as $i => $p) {
            $st = $p['status'] ?? '';
            $b = $p['brand'] ?? 'promanaged';
            if ($st === 'publishing' && ($p['fb_id'] ?? '') === '' && strtotime((string)($p['pub_at'] ?? '')) < $now - 600) {
                $posts[$i]['status'] = 'needs_check';
                $posts[$i]['error'] = 'Posting was interrupted. Check the Page, then Mark posted or Retry.';
            } elseif ($st === 'approved' && ($p['format'] ?? '') === 'reel' && !pm_social_has_media($p)) {
                $posts[$i]['status'] = 'needs_video'; // never posts as text
            } elseif ($st === 'approved' && strtotime((string)$p['when']) < $now - 6 * 3600) {
                $posts[$i]['status'] = 'draft';
                $posts[$i]['note'] = 'missed slot, reschedule';
                $posts[$i]['when'] = pm_social_free_slot($posts, $b, $now, $p['id']);
            } elseif (in_array($st, ['draft', 'needs_edit', 'needs_video'], true) && strtotime((string)$p['when']) < $now - 3600) {
                $posts[$i]['when'] = pm_social_free_slot($posts, $b, $now, $p['id']);
            }
        }
        return $posts;
    });
}

/** Publishes approved posts whose time has come: at most 1 per brand per run, 2 per brand per day, one at a time, each saved before the next. Returns how many succeeded. */
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
        $posts = pm_social_posts();
        usort($posts, fn($a, $b) => strcmp((string)$a['when'], (string)$b['when']));
        $now = time();
        $today = date('Y-m-d');
        $per = [];
        foreach ($posts as $p) {
            if (($p['status'] ?? '') === 'published' && str_starts_with((string)($p['published'] ?? ''), $today)) {
                $per[$p['brand']] = ($per[$p['brand']] ?? 0) + 1;
            }
        }
        $pick = [];
        foreach ($posts as $p) {
            $b = $p['brand'] ?? 'promanaged';
            if (($p['status'] ?? '') === 'approved' && strtotime((string)$p['when']) <= $now && (empty($p['retry_at']) || strtotime($p['retry_at']) <= $now)
                && !isset($pick[$b]) && ($per[$b] ?? 0) < 2) {
                $pick[$b] = $p['id'];
            }
        }
        foreach ($pick as $id) {
            [$ok] = pm_social_run_one($id);
            $n += $ok ? 1 : 0;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $n;
}

/* ---------------- keeping the calendar full ---------------- */

/** If fewer than 3 posts are lined up for the next 5 days (and not tried in the last 5 days, and the AI budget allows), plans 5 more. Returns a short status. */
function pm_social_replenish(string $brand): string
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $now = time();
        $posts = pm_social_posts();
        $ahead = array_filter($posts, fn($p) => ($p['brand'] ?? 'promanaged') === $brand && in_array($p['status'] ?? '', ['draft', 'approved'], true)
            && strtotime((string)$p['when']) >= $now - 3600 && strtotime((string)$p['when']) <= $now + 5 * 86400);
        if (count($ahead) >= 3) {
            return 'calendar full (' . count($ahead) . ' lined up)';
        }
        if ($now - (int)(pm_social_state()['replenish'][$brand] ?? 0) < 5 * 86400) {
            return 'planned less than 5 days ago';
        }
        try {
            pm_budget_check();
        } catch (Throwable $e) {
            return 'skipped: ' . $e->getMessage();
        }
        pm_social_state_set(function (array $s) use ($brand, $now) {
            $s['replenish'][$brand] = $now; // stamped on the attempt, so a failing AI call is not repeated every run
            return $s;
        });
        $new = pm_agent_social_plan(5, 'mix', pm_social_next_free_date($posts, $brand, date('Y-m-d', $now + 86400)));
        $n = pm_social_add_planned($new, pm_social_auto($brand));
        pm_agent_log('Social', "Calendar topped up: $n post(s) planned");
        return "$n post(s) planned";
    } catch (Throwable $e) {
        return 'could not plan: ' . $e->getMessage();
    } finally {
        pm_brand_set($was);
    }
}

/** One owner alert a day when drafts have waited more than 24 hours for approval. Returns the number waiting. */
function pm_social_notify_waiting(): int
{
    $old = array_filter(pm_social_posts(), fn($p) => ($p['status'] ?? '') === 'draft' && strtotime((string)($p['created'] ?? '')) < time() - 86400);
    if (!$old || (pm_social_state()['stale_day'] ?? '') === date('Y-m-d')) {
        return 0;
    }
    pm_social_state_set(function (array $s) { $s['stale_day'] = date('Y-m-d'); return $s; });
    pm_social_notify(count($old) . ' social post(s) waiting for your approval', 'Open Social > Plan and approve them (or press "Approve all that pass checks"). Nothing posts until you do.');
    return count($old);
}
