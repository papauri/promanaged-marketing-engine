<?php
/**
 * Measure, part 5: re-use what worked. A top performer (7-day engagement, top 20% of its pool) that is evergreen, older than 45 days and not
 * recycled in the last 90 days comes back as a NEW DRAFT with a fresh opener and fresh hashtags. It goes through the normal checks and
 * approval (a draft unless Auto-publish is on). Nothing is posted from here.
 */

const PM_SX_RECYCLE_MIN_AGE = 45;   // days since it was published
const PM_SX_RECYCLE_COOLDOWN = 90;  // days before the same post may come back again
const PM_SX_RECYCLE_FRAMES = ['In case you missed it: ', 'Worth another look: ', 'A reminder for this week: '];

/** Is the post free of anything that expires or depends on a date? */
function pm_sx_evergreen(array $p): bool
{
    return empty($p['occasion']) && empty($p['occasion_date']) && empty($p['expires']) && empty($p['giveaway'])
        && !preg_match('/^(offer|giveaway)/i', (string)($p['pillar'] ?? '')) && in_array($p['format'] ?? '', ['image', 'text', 'carousel'], true);
}

/** Recycle candidates, best first: [['post'=>row, 'eng'=>float, 'age_days'=>int]]. Needs at least 5 aged, scored posts, else none (no ranking on thin data). */
function pm_recycle_candidates(string $brand): array
{
    $now = time();
    $byId = array_column(pm_sx_posts($brand), null, 'id');
    $recentFrom = [];
    foreach ($byId as $p) {
        if (!empty($p['recycled_from']) && strtotime((string)($p['created'] ?? '')) > $now - PM_SX_RECYCLE_COOLDOWN * 86400) {
            $recentFrom[$p['recycled_from']] = true;
        }
    }
    $pool = [];
    foreach (pm_sx_rows($brand)['rows'] as $r) {
        $p = $byId[$r['id']] ?? null;
        if (!$p || $r['unplanned'] || ($p['status'] ?? '') !== 'published' || trim((string)($p['caption'] ?? '')) === '') {
            continue;
        }
        $age = (int)floor(($now - pm_sx_pub_ts($p)) / 86400);
        $rec = strtotime((string)($p['recycled_at'] ?? ''));
        if ($age < PM_SX_RECYCLE_MIN_AGE || ($rec && $rec > $now - PM_SX_RECYCLE_COOLDOWN * 86400) || isset($recentFrom[$p['id']]) || !pm_sx_evergreen($p)) {
            continue;
        }
        $pool[] = ['post' => $p, 'eng' => $r['eng'], 'age_days' => $age];
    }
    if (count($pool) < 5) {
        return [];
    }
    usort($pool, fn($a, $b) => [empty($a['post']['recycled_at']) ? 0 : 1, $b['eng']] <=> [empty($b['post']['recycled_at']) ? 0 : 1, $a['eng']]);
    return array_slice($pool, 0, max(1, (int)ceil(count($pool) * 0.2)));
}

/** Makes the recycled draft. Returns ['ok'=>bool, 'msg'=>string, 'post'=>?array]. */
function pm_recycle_make(string $postId): array
{
    $orig = null;
    foreach (pm_social_posts() as $p) {
        if (($p['id'] ?? '') === $postId) {
            $orig = $p;
        }
    }
    if (!$orig || ($orig['status'] ?? '') !== 'published') {
        return ['ok' => false, 'msg' => 'Only a published post can be recycled.', 'post' => null];
    }
    $brand = $orig['brand'] ?? 'promanaged';
    $now = time();
    if (!pm_sx_evergreen($orig)) {
        return ['ok' => false, 'msg' => 'That post is tied to a date, offer or giveaway, so it cannot come back.', 'post' => null];
    }
    if (pm_sx_pub_ts($orig) > $now - PM_SX_RECYCLE_MIN_AGE * 86400) {
        return ['ok' => false, 'msg' => 'It is too recent: posts come back after ' . PM_SX_RECYCLE_MIN_AGE . ' days.', 'post' => null];
    }
    $rec = strtotime((string)($orig['recycled_at'] ?? ''));
    if ($rec && $rec > $now - PM_SX_RECYCLE_COOLDOWN * 86400) {
        return ['ok' => false, 'msg' => 'It was already recycled in the last ' . PM_SX_RECYCLE_COOLDOWN . ' days.', 'post' => null];
    }
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $n = count(array_filter(pm_sx_posts($brand), fn($p) => ($p['recycled_from'] ?? '') === $postId));
        $frame = PM_SX_RECYCLE_FRAMES[(abs(crc32($postId)) + $n) % count(PM_SX_RECYCLE_FRAMES)];
        $cap = trim((string)$orig['caption']);
        $new = array_intersect_key($orig, array_flip(['brand', 'format', 'pillar', 'cta', 'proof_id', 'headline', 'sub', 'script', 'layout', 'audience', 'hook_pattern', 'slides', 'alt', 'asset_id', 'first_comment', 'video']));
        $new += ['id' => bin2hex(random_bytes(10)), 'status' => 'draft', 'caption' => $frame . $cap,
            'hashtags' => (array)($orig['hashtags'] ?? []), 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [],
            'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? '') ?: 'recycle', 'recycled_from' => $postId, 'tokens' => 0];
        if (function_exists('pm_social_hashtags')) { // fresh tags from the bank
            try {
                $tags = (array)pm_social_hashtags($new, 'facebook');
                if ($tags) {
                    $new['hashtags'] = array_values($tags);
                }
            } catch (Throwable) {
            }
        }
        $all = pm_social_posts();
        $time = preg_match('/(\d{1,2}:\d{2})$/', (string)($orig['when'] ?? ''), $m) ? $m[1] : '18:30';
        $new['when'] = date('Y-m-d', $now + 86400) . ' ' . str_pad($time, 5, '0', STR_PAD_LEFT);
        $start = pm_social_next_free_date($all, $brand, date('Y-m-d', $now + 86400));
        $new = pm_social_cadence([$new], $all, $brand, $start)[0];
        $why = function_exists('pm_social_lint_post') ? pm_social_lint_post($new) : [];
        $new = pm_social_resolve($new, $why);
        pm_social_add_planned([$new], pm_social_auto($brand));
        pm_social_patch($postId, fn(array $cur) => array_merge($cur, ['recycled_at' => date('Y-m-d')]));
        if (function_exists('pm_social_log')) {
            pm_social_log($new['id'], 'recycle', 'from ' . $postId);
        }
        return ['ok' => true, 'msg' => 'A new draft was made for ' . date('D j M', strtotime($new['when'])) . ($new['status'] === 'needs_edit' ? ' but it needs an edit first: ' . implode(' ', (array)$new['lint']) : ''), 'post' => $new];
    } finally {
        pm_brand_set($was);
    }
}

/** Used by the weekly top-up before it asks the AI for new posts: up to $max recycled drafts (at most 2 a week in total). Returns how many were made. */
function pm_recycle_fill(string $brand, int $max = 2): int
{
    $recent = count(array_filter(pm_sx_posts($brand), fn($p) => !empty($p['recycled_from']) && strtotime((string)($p['created'] ?? '')) > time() - 7 * 86400));
    $allow = min($max, 2 - $recent);
    $made = 0;
    foreach (pm_recycle_candidates($brand) as $c) {
        if ($made >= $allow) {
            break;
        }
        try {
            $r = pm_recycle_make($c['post']['id']);
        } catch (Throwable) {
            break;
        }
        $made += $r['ok'] ? 1 : 0;
    }
    return $made;
}

function pm_do_recycle(string $vb): array
{
    $to = 'social&view=results';
    $recent = count(array_filter(pm_sx_posts($vb), fn($p) => !empty($p['recycled_from']) && strtotime((string)($p['created'] ?? '')) > time() - 7 * 86400));
    if ($recent >= 2) { // the weekly top-up limit: at most two recycled drafts in seven days
        return ['msg' => 'This week already has its two recycled posts. Next week you can bring back two more.', 'kind' => 'err', 'to' => $to];
    }
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $r = pm_recycle_make($id);
    return ['msg' => $r['msg'] . ($r['ok'] ? ' Open Plan to check and approve it.' : ''), 'kind' => $r['ok'] ? 'ok' : 'err', 'to' => $to];
}
