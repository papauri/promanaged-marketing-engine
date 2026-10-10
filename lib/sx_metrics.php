<?php
/**
 * Measure, part 1: what happened. Per post: snapshots at about 24 h, 3 d and 7 d, taken from the shared cached Page feed (no extra Graph call);
 * per day: follower counts and lead counts. Best-effort insights at the 7-day snapshot only. Zero AI.
 * Data: post['metrics'] {h24,d3,d7:{at,age_h,late?,reactions,comments,shares,reach,clicks,saves,eng}}, post['metrics_src'] api|manual,
 *       data/social_metrics.json {brand:{Y-m-d:{...}}, _unplanned:{brand:{postKey:{...}}}}.
 * Note for core: pm_graph_ttl() has no rule for '/insights'; add `str_ends_with($path, '/insights') => 3600` (the 7-day snapshot already calls it once per post).
 */

const PM_SX_SNAPS = ['h24' => 24, 'd3' => 72, 'd7' => 168]; // snapshot => target age in hours
const PM_SX_SNAP_TOL = 6;                                    // +/- hours around the target
const PM_SX_SNAP_LATE = ['h24' => 0.5, 'd3' => 24, 'd7' => 48]; // how long past the ideal age a snapshot may still be caught up (stored with late=true)
// Facebook post insight metric names live HERE only. Meta is replacing impressions with views: verify against the live API once granted.
const PM_SX_FB_INSIGHTS = ['reach' => 'post_impressions_unique', 'views' => 'post_media_view', 'clicks' => 'post_clicks'];
const PM_SX_IG_INSIGHTS = 'reach,saved,shares,likes,comments,total_interactions';

/** One number for "how much did people do with this post": reactions + 2 comments + 3 shares + 3 saves + 2 clicks (+5 per known enquiry). */
function pm_social_eng(array $m): float
{
    return (float)((int)($m['reactions'] ?? 0) + 2 * (int)($m['comments'] ?? 0) + 3 * (int)($m['shares'] ?? 0) + 3 * (int)($m['saves'] ?? 0)
        + 2 * (int)($m['clicks'] ?? 0) + 5 * (int)($m['enquiries'] ?? 0));
}

/** The part of a Facebook post id after the Page id ("123_456" and "456" are the same post). */
function pm_sx_idkey(string $id): string
{
    $i = strrpos($id, '_');
    return $i === false ? $id : substr($id, $i + 1);
}

/** When a post went out: the time recorded at publishing, else its slot. */
function pm_sx_pub_ts(array $p): int
{
    return (int)(strtotime((string)($p['published'] ?? '')) ?: strtotime((string)($p['when'] ?? '')) ?: 0);
}

/** This brand's posts (all statuses). */
function pm_sx_posts(string $brand): array
{
    return array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand));
}

/** Which snapshots are due at this age (hours): name => late?. Pure. */
function pm_sx_snaps_due(float $ageH, array $have): array
{
    $due = [];
    foreach (PM_SX_SNAPS as $k => $t) {
        if (isset($have[$k])) {
            continue;
        }
        if ($ageH >= $t - PM_SX_SNAP_TOL && $ageH < $t + PM_SX_SNAP_TOL + (PM_SX_SNAP_LATE[$k] ?? 0)) {
            $due[$k] = $ageH > $t + PM_SX_SNAP_TOL;
        }
    }
    return $due;
}

/** Pulls the first number of each named insight out of a Graph insights answer: name => value. */
function pm_sx_insight_values(array $d): array
{
    $o = [];
    foreach ((array)($d['data'] ?? []) as $r) {
        $v = $r['values'][0]['value'] ?? ($r['total_value']['value'] ?? null);
        if (isset($r['name']) && is_numeric($v)) {
            $o[(string)$r['name']] = (int)$v;
        }
    }
    return $o;
}

/** Is insights access switched off for a while for this brand (a permission error was seen)? */
function pm_sx_insights_off(string $brand): bool
{
    return (int)(pm_social_state()['insights_off'][$brand] ?? 0) > time();
}

/**
 * Best-effort insights for one published post. Returns ['ok'=>bool, 'm'=>[reach,views,clicks,saves,shares_ig], 'ig'=>[...], 'li'=>[...], 'why'=>string].
 * Any permission error or empty answer is "not ok": the post is then marked metrics_src=manual and the owner can type the numbers.
 */
function pm_sx_insights(string $brand, array $p, array $c): array
{
    $out = ['ok' => false, 'm' => [], 'ig' => [], 'li' => [], 'why' => ''];
    if ($c['token'] === '') {
        $out['why'] = 'no token';
        return $out;
    }
    if (!pm_sx_insights_off($brand) && ($p['fb_id'] ?? '') !== '') {
        [$ok, $d] = pm_graph('GET', $p['fb_id'] . '/insights', ['metric' => implode(',', PM_SX_FB_INSIGHTS)], $c['token']);
        $v = $ok && is_array($d) ? pm_sx_insight_values($d) : [];
        if ($v) {
            foreach (PM_SX_FB_INSIGHTS as $k => $name) {
                if (isset($v[$name])) {
                    $out['m'][$k] = $v[$name];
                }
            }
            $out['ok'] = true;
        } else {
            $out['why'] = $ok ? 'Facebook returned no insight numbers' : (string)$d;
            if (!$ok && in_array((int)($GLOBALS['PM_GRAPH_ERR']['code'] ?? 0), [10, 200, 190, 100, 3], true)) { // permission / unsupported metric: stop asking for a week
                pm_social_state_set(function (array $s) use ($brand) {
                    $s['insights_off'][$brand] = time() + 7 * 86400;
                    return $s;
                });
            }
        }
    }
    if (($p['ig_post'] ?? '') !== '' && $c['ig_id'] !== '') {
        [$ok, $d] = pm_graph('GET', $p['ig_post'] . '/insights', ['metric' => PM_SX_IG_INSIGHTS], $c['token']);
        $v = $ok && is_array($d) ? pm_sx_insight_values($d) : [];
        if ($v) {
            $out['ig'] = $v;
            $out['m']['saves'] = (int)($v['saved'] ?? 0);
            $out['m']['ig_shares'] = (int)($v['shares'] ?? 0);
            $out['ok'] = true;
        }
    }
    if (($p['li_urn'] ?? '') !== '' && function_exists('pm_sx_li_stats')) {
        $li = pm_sx_li_stats($brand, (string)$p['li_urn']);
        if ($li) {
            $out['li'] = $li;
            $out['ok'] = true;
        }
    }
    return $out;
}

/** LinkedIn share statistics, only when the owner says r_organization_social is granted (LI_ORG_STATS=1 in .env). Never in tests unless stubbed. */
function pm_sx_li_stats(string $brand, string $urn): ?array
{
    if (!preg_match('/^urn:li:(share|ugcPost):\d+$/', $urn)) {
        return null;
    }
    if (isset($GLOBALS['PM_LI_STUB']) && is_callable($GLOBALS['PM_LI_STUB'])) {
        $r = ($GLOBALS['PM_LI_STUB'])($brand, $urn);
        return is_array($r) ? $r : null;
    }
    $e = pm_env();
    $pre = pm_brand_env_prefix($brand);
    if (getenv('PM_TEST') || ($e[$pre . 'LI_ORG_STATS'] ?? $e['LI_ORG_STATS'] ?? '') !== '1' || !function_exists('pm_linkedin_cfg')) {
        return null;
    }
    $c = pm_linkedin_cfg($brand);
    if (!$c['ready']) {
        return null;
    }
    $key = str_starts_with($urn, 'urn:li:share:') ? 'shares' : 'ugcPosts';
    $url = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode('urn:li:organization:' . $c['org'])
        . '&' . $key . '=List(' . rawurlencode($urn) . ')';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'X-Restli-Protocol-Version: 2.0.0',
        'LinkedIn-Version: ' . (defined('LI_API_VERSION') ? LI_API_VERSION : ($e['LI_API_VERSION'] ?? date('Ym', strtotime('-3 months'))))]]);
    if (function_exists('pm_curl_native_ca')) {
        pm_curl_native_ca($ch);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $s = json_decode((string)$raw, true)['elements'][0]['totalShareStatistics'] ?? null;
    return $code === 200 && is_array($s) ? ['impressions' => (int)($s['impressionCount'] ?? 0), 'reach' => (int)($s['uniqueImpressionsCount'] ?? 0), 'clicks' => (int)($s['clickCount'] ?? 0),
        'reactions' => (int)($s['likeCount'] ?? 0), 'comments' => (int)($s['commentCount'] ?? 0), 'shares' => (int)($s['shareCount'] ?? 0)] : null;
}

/**
 * Reads the shared cached Page feed (no extra Graph call) and writes the 24 h / 3 d / 7 d snapshots that are due. Feed items we did not plan
 * are counted as pillar 'unplanned' (7-day snapshot kept in social_metrics._unplanned). Returns counts.
 */
function pm_social_metrics_tick(string $brand): array
{
    $r = ['written' => 0, 'matched' => 0, 'unplanned' => 0, 'error' => ''];
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        $r['error'] = 'not connected';
        return $r;
    }
    $f = pm_fb_feed($brand);
    if (($f['error'] ?? '') !== '') {
        $r['error'] = (string)$f['error'];
        return $r;
    }
    $idx = [];
    foreach ((array)$f['items'] as $it) {
        if (!empty($it['ours'])) {
            $idx[pm_sx_idkey((string)$it['id'])] = $it;
        }
    }
    $now = time();
    $posts = pm_sx_posts($brand);
    $known = [];
    $patch = []; // post id => [snap => row, ...]
    $resolve = 0;
    foreach ($posts as $p) {
        $fid = (string)($p['fb_id'] ?? '');
        if ($fid === '') {
            continue;
        }
        $known[pm_sx_idkey($fid)] = 1;
        if (($p['status'] ?? '') !== 'published') {
            continue;
        }
        if (ctype_digit($fid) && $resolve < 3 && in_array(($p['format'] ?? ''), ['reel'], true)) { // a video id: find the post id it became
            $resolve++;
            $np = pm_hook_after_publish_metrics($p, $c);
            if ($np) {
                $patch[$p['id']]['fb_id'] = $np['fb_id'];
                $fid = $np['fb_id'];
                $known[pm_sx_idkey($fid)] = 1;
            }
        }
        $it = $idx[pm_sx_idkey($fid)] ?? null;
        if (!$it) {
            continue;
        }
        $r['matched']++;
        $ts = (int)(strtotime((string)$it['at']) ?: pm_sx_pub_ts($p));
        $age = ($now - $ts) / 3600;
        foreach (pm_sx_snaps_due($age, (array)($p['metrics'] ?? [])) as $snap => $late) {
            $row = ['at' => date('Y-m-d H:i'), 'age_h' => round($age, 1), 'reactions' => (int)$it['reactions'], 'comments' => (int)$it['comments'], 'shares' => (int)$it['shares'],
                'reach' => null, 'clicks' => null, 'saves' => null];
            if ($late) {
                $row['late'] = true;
            }
            $src = 'api';
            if ($snap === 'd7') { // the one that is scored: ask for reach, clicks, saves once
                $ins = pm_sx_insights($brand, $p, $c);
                foreach (['reach', 'clicks', 'saves'] as $k) {
                    $row[$k] = isset($ins['m'][$k]) ? (int)$ins['m'][$k] : null;
                }
                if (isset($ins['m']['views'])) {
                    $row['views'] = (int)$ins['m']['views'];
                }
                if (isset($ins['m']['ig_shares'])) {
                    $row['shares'] += (int)$ins['m']['ig_shares'];
                }
                if ($ins['ig']) {
                    $row['ig'] = $ins['ig'];
                }
                if ($ins['li']) {
                    $row['li'] = $ins['li'];
                }
                if (!$ins['ok']) {
                    $src = 'manual'; // no insights: reactions, comments and shares are still real, the rest is typed in by the owner
                    $row['why_manual'] = mb_substr($ins['why'], 0, 120);
                }
            }
            $row['eng'] = pm_social_eng($row);
            $patch[$p['id']][$snap] = $row;
            if ($snap === 'd7' || !isset($p['metrics_src'])) {
                $patch[$p['id']]['_src'] = $snap === 'd7' ? $src : 'api';
            }
            $r['written']++;
        }
    }
    if ($patch) {
        pm_social_update(function (array $all) use ($patch) {
            foreach ($all as $i => $p) {
                $pt = $patch[$p['id'] ?? ''] ?? null;
                if (!$pt) {
                    continue;
                }
                foreach ($pt as $k => $v) {
                    if ($k === '_src') {
                        $all[$i]['metrics_src'] = $v;
                    } elseif ($k === 'fb_id') {
                        $all[$i]['fb_id'] = $v;
                    } else {
                        $all[$i]['metrics'][$k] = $v;
                    }
                }
            }
            return $all;
        });
    }
    // native Page posts we never planned: one 7-day record each
    $un = [];
    foreach ($idx as $k => $it) {
        if (isset($known[$k])) {
            continue;
        }
        $age = ($now - (int)strtotime((string)$it['at'])) / 3600;
        if ($age >= 168 - PM_SX_SNAP_TOL && $age <= 168 + PM_SX_SNAP_TOL + 48) {
            $un[$k] = ['at' => date('Y-m-d H:i'), 'created' => substr((string)$it['at'], 0, 16), 'text' => mb_substr((string)$it['message'], 0, 80), 'reactions' => (int)$it['reactions'],
                'comments' => (int)$it['comments'], 'shares' => (int)$it['shares'], 'pillar' => 'unplanned', 'eng' => pm_social_eng($it)];
        }
    }
    if ($un) {
        pm_update('social_metrics', function (array $d) use ($brand, $un, $now) {
            foreach ($un as $k => $row) {
                $d['_unplanned'][$brand][$k] ??= $row;
            }
            foreach ((array)($d['_unplanned'][$brand] ?? []) as $k => $row) { // keep four months
                if (strtotime((string)($row['created'] ?? '')) < $now - 120 * 86400) {
                    unset($d['_unplanned'][$brand][$k]);
                }
            }
            return $d;
        }, fn() => []);
        $r['unplanned'] = count($un);
    }
    return $r;
}

/** Facebook post id for a video upload (the upload id is not the post id). Used right after publishing and by the tick. */
function pm_hook_after_publish_metrics(array $p, array $c): ?array
{
    $id = (string)($p['fb_id'] ?? '');
    $video = ($p['format'] ?? '') === 'reel' || (function_exists('pm_social_is_video') && pm_social_is_video((string)($p['media'] ?? '')));
    if ($id === '' || !$video || !ctype_digit($id) || empty($c['token'])) {
        return null;
    }
    [$ok, $d] = pm_graph('GET', $id, ['fields' => 'post_id'], $c['token']);
    return $ok && !empty($d['post_id']) ? ['fb_id' => (string)$d['post_id']] : null;
}

/**
 * Works out a post id from what the owner pasted when marking a post as posted. $p['url'] may be a Facebook post URL
 * (/posts/ID, permalink.php?story_fbid=ID&id=PAGE, /videos/ID, /reel/ID, photo?fbid=ID or a pfbid link looked up in the cached feed). Returns "page_post" or '' when unknown.
 */
function pm_social_resolve_fb_id(string $brand, array $p): string
{
    $c = pm_social_cfg($brand);
    $url = trim((string)($p['url'] ?? $p['post_url'] ?? ''));
    if ($url === '') {
        return (string)($p['fb_id'] ?? '');
    }
    $page = $c['page_id'];
    $q = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('/^\d+_\d+$/', $url)) {
        return $url;
    }
    if (!empty($q['story_fbid']) && ctype_digit((string)$q['story_fbid'])) {
        return (string)($q['id'] ?? $page) . '_' . $q['story_fbid'];
    }
    if (!empty($q['fbid']) && ctype_digit((string)$q['fbid'])) {
        return $page . '_' . $q['fbid'];
    }
    if (preg_match('#^(?:/(\d+))?/(?:posts|videos|reel|reels|video|photos?)/(\d+)#', $path, $m)) {
        return ($page !== '' ? $page : (string)($m[1] ?? '')) . '_' . $m[2];
    }
    if ($c['ready']) { // pfbid or share links: only the cached feed can tell
        $want = strtolower(rtrim(preg_replace('#^https?://(www\.|m\.|web\.)?facebook\.com#i', '', $url), '/'));
        foreach ((array)(pm_fb_feed($brand)['items'] ?? []) as $it) {
            $u = strtolower(rtrim(preg_replace('#^https?://(www\.|m\.|web\.)?facebook\.com#i', '', (string)$it['url']), '/'));
            if ($u !== '' && strtok($u, '?') === strtok($want, '?')) {
                return (string)$it['id'];
            }
        }
    }
    return '';
}

/* ---------------- daily numbers ---------------- */

/** Date => Facebook followers for a brand, oldest first. */
function pm_sx_follower_series(string $brand): array
{
    $d = pm_load('social_metrics', fn() => [])[$brand] ?? [];
    $o = [];
    foreach ($d as $day => $r) {
        if (is_array($r) && isset($r['fb_followers']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day)) {
            $o[$day] = (int)$r['fb_followers'];
        }
    }
    ksort($o);
    return $o;
}

/** Facebook followers now minus followers $days days ago, or null when there is no reading near enough to that date. */
function pm_social_follower_delta(string $brand, int $days): ?int
{
    $s = pm_sx_follower_series($brand);
    if (!$s) {
        return null;
    }
    $lastDay = array_key_last($s);
    if (strtotime($lastDay) < time() - 3 * 86400) {
        return null; // the latest reading is stale
    }
    $target = strtotime($lastDay) - $days * 86400;
    $tol = max(2, min(7, intdiv($days, 4))) * 86400;
    $best = null;
    foreach ($s as $day => $v) {
        $gap = abs(strtotime($day) - $target);
        if ($day !== $lastDay && $gap <= $tol && ($best === null || $gap < $best[0])) {
            $best = [$gap, $v];
        }
    }
    return $best === null ? null : $s[$lastDay] - $best[1];
}

/** The newest follower numbers per channel, with the day they were read: ['fb','ig','li','tt','day'] (null = never read). */
function pm_social_followers_all(string $brand): array
{
    $d = pm_load('social_metrics', fn() => [])[$brand] ?? [];
    $o = ['fb' => null, 'ig' => null, 'li' => null, 'tt' => null, 'day' => ''];
    foreach (array_reverse($d, true) as $day => $r) {
        if (!is_array($r) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day)) {
            continue;
        }
        foreach (['fb' => 'fb_followers', 'ig' => 'ig_followers', 'li' => 'li_followers', 'tt' => 'tt_followers'] as $k => $f) {
            if ($o[$k] === null && isset($r[$f])) {
                $o[$k] = (int)$r[$f];
            }
        }
        $o['day'] = $o['day'] ?: $day;
    }
    if ($o['fb'] === null && function_exists('pm_social_page')) { // no daily row yet: one cached Page read gives the number
        try {
            $pg = pm_social_page($brand);
            if (!empty($pg['ok'])) {
                $o['fb'] = (int)$pg['followers'];
            }
        } catch (Throwable) {
        }
    }
    return $o;
}

/** Writes today's row once a day: followers (one cached Page call + Instagram when connected), counts of posts and leads, reply speed. Zero AI. */
function pm_social_snapshot_day(string $brand, bool $force = false): ?array
{
    $day = date('Y-m-d');
    $have = (pm_load('social_metrics', fn() => [])[$brand][$day] ?? null);
    if (!$force && is_array($have) && isset($have['fb_followers'])) {
        return $have;
    }
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return null;
    }
    $pg = pm_social_page($brand);
    if (empty($pg['ok'])) {
        return null;
    }
    $row = ['at' => time(), 'fb_followers' => (int)$pg['followers'], 'fb_fans' => (int)$pg['fans']];
    if (function_exists('pm_ig_followers')) {
        try {
            $ig = pm_ig_followers($brand);
            if (is_array($ig)) {
                $row['ig_followers'] = (int)($ig['followers'] ?? 0);
                $row['ig_media'] = (int)($ig['media_count'] ?? 0);
            }
        } catch (Throwable) {
        }
    }
    $posts = pm_sx_posts($brand);
    $row['posts'] = count(array_filter($posts, fn($p) => ($p['status'] ?? '') === 'published' && str_starts_with((string)($p['published'] ?? ''), $day)));
    $tot = ['reactions' => 0, 'comments' => 0, 'shares' => 0];
    try {
        foreach ((array)(pm_fb_feed($brand)['items'] ?? []) as $it) {
            if (!empty($it['ours']) && str_starts_with((string)date('Y-m-d', (int)strtotime((string)$it['at'])), $day)) {
                foreach ($tot as $k => $_) {
                    $tot[$k] += (int)$it[$k];
                }
            }
        }
    } catch (Throwable) {
    }
    $row += $tot;
    $row += ['buyers' => 0, 'web_leads' => 0, 'fb_leads' => 0];
    foreach (pm_leads() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || !str_starts_with((string)($l['created'] ?? ''), $day)) {
            continue;
        }
        $row['buyers'] += ($l['source'] ?? '') === 'facebook' ? 1 : 0;      // buyers caught from Page comments / messages
        $row['web_leads'] += ($l['source'] ?? '') === 'web' ? 1 : 0;        // the website form
        $row['fb_leads'] += !empty($l['source_post']) || !empty($l['src_tag']) || in_array($l['channel'] ?? '', ['facebook', 'instagram', 'whatsapp'], true) || ($l['source'] ?? '') === 'facebook' ? 1 : 0;
    }
    if (function_exists('pm_response_stats')) {
        try {
            $rs = pm_response_stats($brand);
            if (isset($rs['median_min'])) {
                $row['resp_median_min'] = (int)$rs['median_min'];
            }
        } catch (Throwable) {
        }
    }
    pm_update('social_metrics', function (array $d) use ($brand, $day, $row) {
        $old = is_array($d[$brand][$day] ?? null) ? $d[$brand][$day] : [];
        $new = array_merge($old, $row); // numbers typed in by the owner earlier today (LinkedIn, TikTok, Status) stay
        $d[$brand][$day] = $new;
        foreach (array_keys((array)$d[$brand]) as $k) { // keep 400 days
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$k) && strtotime($k) < time() - 400 * 86400) {
                unset($d[$brand][$k]);
            }
        }
        ksort($d[$brand]);
        return $d;
    }, fn() => []);
    $d7 = pm_social_follower_delta($brand, 7);
    if ($d7 !== null) {
        pm_update('social_metrics', function (array $d) use ($brand, $day, $d7) {
            $d[$brand][$day]['fb_d7'] = $d7; // the weekly change, kept with the day
            return $d;
        }, fn() => []);
    }
    return pm_load('social_metrics', fn() => [])[$brand][$day] ?? $row;
}

/** Hourly job: metrics snapshots (every run is cheap: the feed is cached) and the daily numbers. */
function pm_job_metrics(string $brand): string
{
    $last = (int)(pm_social_state()['metrics_at'][$brand] ?? 0);
    if (time() - $last < 45 * 60) {
        return '';
    }
    pm_social_state_set(function (array $s) use ($brand) {
        $s['metrics_at'][$brand] = time();
        return $s;
    });
    $r = pm_social_metrics_tick($brand);
    $day = pm_social_snapshot_day($brand);
    $out = $r['error'] !== '' ? 'skipped (' . $r['error'] . ')' : $r['written'] . ' snapshot(s), ' . $r['matched'] . ' post(s) matched' . ($r['unplanned'] ? ', ' . $r['unplanned'] . ' unplanned' : '');
    return $out . ($day ? '; followers ' . (int)$day['fb_followers'] : '');
}

/** Typed-in numbers for channels no API reaches (LinkedIn, TikTok followers, Status views) into today's row. */
function pm_do_followers_manual(string $vb): array
{
    $to = 'social&view=results';
    $day = date('Y-m-d');
    $vals = [];
    foreach (['li_followers', 'tt_followers', 'wa_status_views'] as $k) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v !== '' && ctype_digit($v)) {
            $vals[$k] = (int)$v;
        }
    }
    if (!$vals) {
        return ['msg' => 'Type at least one number.', 'kind' => 'err', 'to' => $to];
    }
    $by = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
    pm_update('social_metrics', function (array $d) use ($vb, $day, $vals, $by) {
        $d[$vb][$day] = array_merge((array)($d[$vb][$day] ?? []), $vals, ['manual_by' => $by]);
        return $d;
    }, fn() => []);
    return ['msg' => 'Saved as entered by ' . $by . '.', 'kind' => 'ok', 'to' => $to];
}

/** Per-post numbers the owner types in for a channel (Facebook reach/saves, TikTok, Status, Google...). Stored in post['manual'][channel] with who and when. */
function pm_do_results_save(string $vb): array
{
    $to = 'social&view=results';
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $ch = (string)($_POST['channel'] ?? '');
    if (!in_array($ch, ['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube_short', 'whatsapp_status', 'google', 'x'], true)) {
        return ['msg' => 'Choose a channel.', 'kind' => 'err', 'to' => $to];
    }
    $row = [];
    foreach (['views', 'reactions', 'comments', 'saves', 'shares', 'clicks', 'enquiries'] as $k) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v !== '' && ctype_digit($v)) {
            $row[$k] = min(100000000, (int)$v);
        }
    }
    if (!$row) {
        return ['msg' => 'Type at least one number.', 'kind' => 'err', 'to' => $to];
    }
    $row['note'] = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160);
    $row['by'] = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
    $row['at'] = date('Y-m-d H:i');
    $done = pm_social_patch($id, function (array $p) use ($vb, $ch, $row) {
        if (($p['brand'] ?? 'promanaged') !== $vb || ($p['status'] ?? '') !== 'published') {
            return $p;
        }
        $p['manual'][$ch] = $row;
        if (empty($p['metrics']['d7'])) {
            $p['metrics_src'] = 'manual';
        }
        return $p;
    });
    if (!$done || empty($done['manual'][$ch]) || $done['manual'][$ch] !== $row) {
        return ['msg' => 'That post was not found, or it is not published yet.', 'kind' => 'err', 'to' => $to];
    }
    if (function_exists('pm_social_log')) {
        pm_social_log($id, 'results', "$ch numbers entered by {$row['by']}");
    }
    return ['msg' => 'Saved as entered by ' . $row['by'] . '. It is shown as your figure, not as Facebook\'s.', 'kind' => 'ok', 'to' => $to];
}
