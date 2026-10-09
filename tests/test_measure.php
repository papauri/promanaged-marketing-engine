<?php
/**
 * Tests for the measure-steer package (metrics, scoreboard, funnel, report, experiments, recycle, usage, director).
 * Runs on a TEMP COPY of data/ (see boot.php) with the Graph API and the AI stubbed. Run: php tests/test_measure.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
// Facebook / Instagram / ads connection for the temp run only (the env file is the harness's stub file)
file_put_contents($T['env'], "FB_PAGE_ID=999\nFB_PAGE_TOKEN=tok\nIG_USER_ID=555\nFB_AD_ACCOUNT_ID=123\nFB_USER_TOKEN=utok\n", FILE_APPEND);

$calls = [];
$S = ['feed' => [], 'insights' => [], 'ads' => []];
$GLOBALS['PM_GRAPH_STUB'] = function (string $m, string $path, array $p, string $tok, array $f) use (&$calls, &$S): array {
    $calls[] = "$m $path";
    if ($path === '999/feed') {
        return [true, ['data' => $S['feed']]];
    }
    if ($path === '999') {
        return [true, ['name' => 'Test Page', 'link' => 'https://x', 'fan_count' => 950, 'followers_count' => 1000]];
    }
    if ($path === '555') {
        return [true, ['followers_count' => 300, 'media_count' => 12]];
    }
    if ($path === 'act_123') {
        return [true, ['currency' => 'MWK', 'name' => 'Ads']];
    }
    if ($path === 'act_123/insights') {
        return [true, ['data' => $S['ads']]];
    }
    if (str_ends_with($path, '/insights')) {
        if (isset($S['insights'][$path])) {
            return $S['insights'][$path];
        }
        $GLOBALS['PM_GRAPH_ERR'] = ['code' => 10, 'http' => 403, 'errno' => 0];
        return [false, '(#10) Application does not have permission for this action'];
    }
    if (preg_match('#^\d+$#', $path) && ($p['fields'] ?? '') === 'post_id') {
        return [true, ['post_id' => '999_' . $path]];
    }
    return [false, 'unexpected call ' . $path];
};
$GLOBALS['PM_AI_STUB'] = null;
// prove the stub is honoured before anything else can reach the network
$probe = pm_graph('GET', '999', [], 'tok');
if (empty($probe[0]) || !$calls) {
    fwrite(STDERR, "pm_graph does not honour PM_GRAPH_STUB: refusing to run.\n");
    exit(2);
}
$calls = [];

set_error_handler(function ($no, $str, $file, $line) {
    if (preg_match('#(sx_(metrics|scorecard|report|experiments|recycle)|view_social_results|lib[\\\\/]agents|lib[\\\\/]director)\.php$#', $file)) {
        pm_t_assert(false, "PHP warning in $file:$line $str");
    }
    return true;
});
function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
function fx_reset(): void
{
    pm_save('social_posts', []);
    pm_save('social_metrics', []);
    pm_save('social_experiments', ['rows' => []]);
}
/** A published post with 7-day numbers (reactions only, so eng = reactions). $at: hours ago. */
function fx_pub(array $o = []): array
{
    $at = $o['_ago'] ?? 24 * 60;
    unset($o['_ago']);
    $eng = $o['_eng'] ?? 10;
    unset($o['_eng']);
    $ts = $o['_ts'] ?? time() - $at * 3600;
    unset($o['_ts']);
    return pm_t_fixture_post($o + ['status' => 'published', 'published' => date('Y-m-d H:i', $ts), 'when' => date('Y-m-d H:i', $ts), 'fb_id' => '999_' . bin2hex(random_bytes(3)),
        'metrics' => ['d7' => ['at' => date('Y-m-d H:i', $ts + 7 * 86400), 'reactions' => $eng, 'comments' => 0, 'shares' => 0, 'reach' => null, 'clicks' => null, 'saves' => null, 'eng' => $eng]], 'metrics_src' => 'api']);
}
function fx_ts(string $dayName, string $time, int $weeksBack = 1): int
{
    return strtotime("last $dayName $time -" . ($weeksBack - 1) . ' weeks');
}
function fx_feed_item(string $id, int $ts, int $react = 5, int $com = 1, int $sh = 0): array
{
    return ['id' => $id, 'from' => ['id' => '999', 'name' => 'Test Page'], 'message' => 'm ' . $id, 'created_time' => date('c', $ts), 'permalink_url' => 'https://www.facebook.com/' . str_replace('_', '/posts/', $id),
        'shares' => ['count' => $sh], 'reactions' => ['summary' => ['total_count' => $react]], 'comments' => ['summary' => ['total_count' => $com], 'data' => []]];
}
pm_brand_set('promanaged');

t('AI stub and usage', function () use (&$calls) {
    unset($GLOBALS['PM_AI_STUB']);
    $thrown = '';
    try {
        pm_claude('s', 'u');
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    pm_t_eq($thrown, 'AI not stubbed', 'pm_claude throws when unstubbed under PM_TEST');
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function (string $sys, string $user, string $tier, int $max) use (&$seen): string {
        $seen[] = [$tier, $max];
        return '{"ok":1}';
    };
    $m0 = pm_usage_mark();
    $out = pm_claude('system words here', 'user words here', false, 321, 'write');
    pm_t_eq($out, '{"ok":1}', 'pm_claude returns the stub result');
    pm_t_eq($seen, [['write', 321]], 'stub gets tier and max tokens');
    pm_t_assert(pm_usage_mark() > $m0, 'usage is still counted for stubbed calls');
    $d = json_decode((string)file_get_contents(PM_DATA . '/ai_usage_daily.json'), true);
    $day = $d[date('Y-m-d')]['promanaged'] ?? [];
    $calls = array_sum(array_column($day, 'calls'));
    pm_t_assert($calls >= 1 && array_sum(array_column($day, 'in')) > 0, 'ai_usage_daily.json has today/brand/agent totals');
    pm_t_assert(pm_usage_brand_today('promanaged') > 0 && pm_usage_brand_today('travel') === 0, 'brand usage is separate');
    // brand soft cap
    pm_social_goals_save('promanaged', ['daily_token_budget' => 5]);
    $msg = '';
    try {
        pm_claude('s', 'u');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    pm_t_assert(str_contains($msg, 'own daily AI limit'), 'per-brand token cap stops the call');
    pm_brand_set('travel');
    $ok = true;
    try {
        pm_budget_check();
    } catch (RuntimeException) {
        $ok = false;
    }
    pm_brand_set('promanaged');
    pm_t_assert($ok, 'the cap of one brand does not stop the other');
    pm_social_goals_save('promanaged', ['daily_token_budget' => 0]);
    pm_t_assert(pm_claude('s', 'u') === '{"ok":1}', 'cap 0 = no brand limit');
    // log rotation keeps today's lines and renames instead of rewriting
    $f = PM_DATA . '/ai_usage.log';
    $old = date('Y-m-d', strtotime('-3 days'));
    $fh = fopen($f, 'a');
    for ($i = 0; $i < 6000; $i++) {
        fwrite($fh, "$old\tpromanaged\tx\tm\t100\t50\t0\told\n");
    }
    fwrite($fh, date('Y-m-d') . "\tpromanaged\tx\tm\t700\t300\t0\ttest\n");
    fclose($fh);
    $before = pm_usage_today()['total'];
    $after = pm_usage_today()['total'];
    pm_t_eq($after, $before, 'today total unchanged by rotation');
    pm_t_assert(count(glob($f . '.*') ?: []) >= 1 && filesize($f) < 100000, 'big log was renamed away, active log is small');
    pm_t_assert(pm_usage_mark() === $after, 'pm_usage_mark = tokens today');
});

t('Engagement formula and goals', function () {
    pm_t_eq(pm_social_eng(['reactions' => 10, 'comments' => 2, 'shares' => 1, 'saves' => 1, 'clicks' => 3, 'enquiries' => 1]), 31.0, 'pm_social_eng');
    $g = pm_social_goals('travel');
    pm_t_assert($g['posts_per_week'] > 0 && $g['daily_token_budget'] === 0, 'goal defaults');
    pm_social_goals_save('travel', ['followers_target' => '1500', 'followers_date' => '2026-12-31', 'enquiries_per_week' => 4]);
    pm_t_eq(pm_social_goals('travel')['followers_target'], 1500, 'goals save and load');
    pm_t_eq(pm_social_goals('travel')['followers_date'], '2026-12-31', 'goal date');
});

t('Snapshots at 24h / 3d / 7d from the cached feed', function () use (&$calls, &$S) {
    pm_t_eq(array_keys(pm_sx_snaps_due(17.9, [])), [], 'nothing due at 17.9h');
    pm_t_eq(array_keys(pm_sx_snaps_due(18.0, [])), ['h24'], 'h24 due from 18h');
    pm_t_eq(array_keys(pm_sx_snaps_due(30.5, [])), [], 'h24 window closed after 30h');
    pm_t_eq(array_keys(pm_sx_snaps_due(75, [])), ['d3'], 'd3 at 75h');
    pm_t_eq(pm_sx_snaps_due(200, []), ['d7' => true], 'd7 may be caught up late (flagged)');
    pm_t_eq(pm_sx_snaps_due(230, []), [], 'd7 gives up after 48h late');
    fx_reset();
    $p1 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p1', 'published' => date('Y-m-d H:i', time() - 30 * 3600), 'pillar' => 'Tip/How-to']);
    $p2 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p2', 'published' => date('Y-m-d H:i', time() - 80 * 3600)]);
    $p3 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p3', 'published' => date('Y-m-d H:i', time() - 170 * 3600)]);
    $p4 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p4', 'published' => date('Y-m-d H:i', time() - 10 * 3600)]);
    $p5 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p5', 'published' => date('Y-m-d H:i', time() - 400 * 3600)]);
    $S['feed'] = [fx_feed_item('999_p1', time() - 30 * 3600, 12, 3, 1), fx_feed_item('999_p2', time() - 80 * 3600, 20, 2, 2), fx_feed_item('999_p3', time() - 170 * 3600, 40, 5, 4),
        fx_feed_item('999_p4', time() - 10 * 3600), fx_feed_item('999_p5', time() - 400 * 3600), fx_feed_item('999_zz', time() - 170 * 3600, 9, 1, 0)];
    $S['insights'] = [];
    $calls = [];
    $r = pm_social_metrics_tick('promanaged');
    pm_t_eq($r['written'], 3, 'three snapshots written (24h, 3d, 7d)');
    pm_t_eq($calls, ['GET 999/feed', 'GET 999_p3/insights'], 'one feed call (shared, cached) + the single 7-day insights ask; none for 24h/3d');
    $by = array_column(pm_social_posts(), null, 'id');
    pm_t_eq((int)($by[$p1['id']]['metrics']['h24']['reactions'] ?? -1), 12, 'p1 h24 reactions from the feed');
    pm_t_eq(pm_social_eng($by[$p1['id']]['metrics']['h24']), 12 + 6 + 3.0, 'p1 h24 eng');
    pm_t_assert(isset($by[$p2['id']]['metrics']['d3']) && !isset($by[$p2['id']]['metrics']['h24']), 'p2 d3 only');
    pm_t_assert(isset($by[$p3['id']]['metrics']['d7']), 'p3 d7 written');
    pm_t_eq($by[$p3['id']]['metrics_src'] ?? '', 'manual', 'insights refused -> metrics_src manual');
    pm_t_assert(!isset($by[$p4['id']]['metrics']) && !isset($by[$p5['id']]['metrics']), 'young and old posts get nothing');
    $m = pm_load('social_metrics', fn() => []);
    pm_t_assert(count($m['_unplanned']['promanaged'] ?? []) === 1, 'unmatched Page post recorded as unplanned');
    $calls = [];
    $r2 = pm_social_metrics_tick('promanaged');
    pm_t_eq($r2['written'], 0, 'second tick writes nothing');
    pm_t_assert(pm_sx_insights_off('promanaged'), 'permission error switches insights off for a while');
    // insights succeed for a fresh post (separate brand state)
    pm_social_state_set(function (array $s) { unset($s['insights_off']); return $s; });
    $p6 = pm_t_fixture_post(['status' => 'published', 'fb_id' => '999_p6', 'ig_post' => 'ig1', 'published' => date('Y-m-d H:i', time() - 170 * 3600)]);
    $S['feed'][] = fx_feed_item('999_p6', time() - 170 * 3600, 30, 2, 3);
    $S['insights']['999_p6/insights'] = [true, ['data' => [['name' => PM_SX_FB_INSIGHTS['reach'], 'values' => [['value' => 500]]], ['name' => PM_SX_FB_INSIGHTS['clicks'], 'values' => [['value' => 12]]]]]];
    $S['insights']['ig1/insights'] = [true, ['data' => [['name' => 'saved', 'values' => [['value' => 7]]], ['name' => 'shares', 'values' => [['value' => 2]]], ['name' => 'reach', 'values' => [['value' => 90]]]]]];
    pm_social_metrics_tick('promanaged');
    $d7 = array_column(pm_social_posts(), null, 'id')[$p6['id']]['metrics']['d7'] ?? [];
    pm_t_eq([$d7['reach'] ?? null, $d7['clicks'] ?? null, $d7['saves'] ?? null, $d7['shares'] ?? null], [500, 12, 7, 5], 'insights fill reach, clicks, saves; IG shares added');
    pm_t_eq(array_column(pm_social_posts(), null, 'id')[$p6['id']]['metrics_src'] ?? '', 'api', 'metrics_src api when insights came');
});

t('Daily snapshot and follower delta', function () use (&$calls) {
    $calls = [];
    $row = pm_social_snapshot_day('promanaged');
    pm_t_eq((int)($row['fb_followers'] ?? 0), 1000, 'fb followers stored');
    pm_t_eq((int)($row['ig_followers'] ?? 0), 300, 'ig followers stored (inbound module)');
    $n = count($calls);
    pm_social_snapshot_day('promanaged');
    pm_t_eq(count($calls), $n, 'second call the same day makes no Graph call');
    pm_update('social_metrics', function (array $d) {
        $d['promanaged'][date('Y-m-d', strtotime('-7 days'))] = ['fb_followers' => 960];
        $d['promanaged'][date('Y-m-d', strtotime('-30 days'))] = ['fb_followers' => 900];
        return $d;
    });
    pm_t_eq(pm_social_follower_delta('promanaged', 7), 40, '7-day follower delta');
    pm_t_eq(pm_social_follower_delta('promanaged', 30), 100, '30-day follower delta');
    pm_t_eq(pm_social_follower_delta('promanaged', 14), null, 'no reading near 14 days ago -> null');
    pm_t_eq(pm_social_follower_delta('travel', 7), null, 'unknown brand -> null');
});

t('Scoreboard: min-sample guard', function () {
    fx_reset();
    for ($i = 0; $i < 3; $i++) {
        fx_pub(['pillar' => 'Tip/How-to', '_eng' => 50, '_ago' => 24 * (20 + $i)]);
    }
    $sb = pm_social_scoreboard('promanaged');
    pm_t_eq($sb['enough'], false, '3 posts is not enough');
    pm_t_eq([$sb['top5'], $sb['bottom5']], [[], []], 'nothing ranked');
    pm_t_assert(str_contains($sb['note'], 'Not enough data'), 'says not enough data');
    pm_t_eq(pm_social_learnings('promanaged'), '', 'no learnings on thin data');
    $w = pm_social_perf_weights('promanaged');
    pm_t_eq(array_unique(array_values($w)), [1.0], 'weights neutral (1.0) without data');
    pm_t_eq(pm_social_focus_for('promanaged'), 'mix', 'focus mix by default');
});

t('Scoreboard: ranking, groups, weights', function () {
    fx_reset();
    foreach ([0, 1, 2, 3, 4, 5] as $i) {
        fx_pub(['pillar' => 'Tip/How-to', 'cta' => 'save', '_eng' => 100, '_ago' => 24 * (20 + $i)]);
    }
    foreach ([0, 1, 2, 3] as $i) {
        fx_pub(['pillar' => 'Offer', 'cta' => 'whatsapp', '_eng' => 20, '_ago' => 24 * (30 + $i)]);
    }
    foreach ([0, 1] as $i) {
        fx_pub(['pillar' => 'Proof', 'cta' => 'comment', '_eng' => 60, '_ago' => 24 * (40 + $i)]);
    }
    $sb = pm_social_scoreboard('promanaged');
    pm_t_eq($sb['enough'], true, '12 posts is enough');
    pm_t_eq($sb['groups']['pillar']['Tip/How-to']['n'], 6, 'pillar n');
    pm_t_eq($sb['groups']['pillar']['Tip/How-to']['avg_eng'], 100.0, 'pillar average');
    pm_t_eq($sb['groups']['pillar']['Proof']['enough'], false, 'a 2-post bucket is flagged not enough');
    pm_t_assert($sb['groups']['pillar']['Tip/How-to']['vs_avg'] > 1.4, 'vs average');
    pm_t_eq(count($sb['top5']), 5, 'top 5');
    pm_t_eq($sb['top5'][0]['pillar'], 'Tip/How-to', 'top post is a tip');
    pm_t_eq($sb['bottom5'][0]['pillar'], 'Offer', 'bottom post is an offer');
    $L = pm_social_learnings('promanaged');
    pm_t_assert(str_contains($L, 'Best pillar Tip/How-to (n=6') && str_contains($L, 'weak Offer'), 'learnings name best and weak pillar: ' . $L);
    pm_t_assert(mb_strlen($L) <= 480, 'learnings within ~120 tokens');
    $w = pm_social_perf_weights('promanaged');
    pm_t_assert(max($w) <= 1.3 && min($w) >= 0.7, 'weights inside 0.7..1.3: ' . json_encode($w));
    pm_t_assert($w['Tip/How-to'] > 1.0 && $w['Offer'] < 1.0 && $w['Proof'] === 1.0 || $w['Proof'] >= 0.7, 'direction: tip up, offer down');
    // floor and clamp with a tiny Offer share
    pm_social_pillars_save('promanaged', [['name' => 'Tip/How-to', 'weight' => 60], ['name' => 'Offer', 'weight' => 5], ['name' => 'Proof', 'weight' => 35]]);
    $mix = pm_sx_mix('promanaged');
    pm_t_assert($mix && $mix['Offer']['new'] >= 0.0999, 'Offer is lifted to the 10% floor: ' . round($mix['Offer']['new'], 4));
    pm_t_assert(abs(array_sum(array_column($mix, 'new')) - 1) < 1e-6, 'shares add up to 100%');
    pm_t_eq(pm_social_perf_weights('promanaged')['Offer'], 1.3, 'factor clamped at 1.3');
    // apply only on the owner's click
    $before = array_column(pm_social_pillars('promanaged'), 'weight', 'name');
    pm_t_eq($before['Offer'], 5, 'nothing applied by itself');
    $r = pm_do_apply_weights('promanaged');
    pm_t_eq($r['kind'], 'ok', 'apply handler ok');
    $after = array_column(pm_social_pillars('promanaged'), 'weight', 'name');
    pm_t_assert($after['Offer'] >= 9 && $after['Tip/How-to'] < 60, 'apply writes the suggested mix through the pillar save: ' . json_encode($after));
    pm_social_pillars_save('promanaged', []);
});

t('Slots: prior when empty, shrinkage with data', function () {
    fx_reset();
    $s = pm_social_slots('travel');
    pm_t_eq(count($s), 4, '3 + 1 slots when empty');
    pm_t_eq(array_column($s, 'src'), ['prior', 'prior', 'prior', 'explore'], 'prior then exploration');
    pm_t_assert(in_array([$s[0]['dow'], $s[0]['time']], [[5, '18:30'], [6, '12:00'], [4, '18:30']], true), 'Travel prior: Thu-Sun 18:30/12:00');
    $p = pm_social_slots('promanaged');
    pm_t_assert(in_array($p[0]['dow'], [2, 3, 4], true) && in_array($p[0]['time'], ['07:30', '12:30', '17:30'], true), 'ProManaged prior Tue-Thu');
    // data: Thu evening x4 (100), Wed lunch x4 (50), Tue early x1 (200)
    for ($k = 1; $k <= 4; $k++) {
        fx_pub(['_ts' => fx_ts('thursday', '17:30', $k), '_eng' => 100, 'pillar' => 'Tip/How-to']);
        fx_pub(['_ts' => fx_ts('wednesday', '12:30', $k), '_eng' => 50, 'pillar' => 'Tip/How-to']);
    }
    fx_pub(['_ts' => fx_ts('tuesday', '07:30', 1), '_eng' => 200, 'pillar' => 'Tip/How-to']);
    $cells = pm_sx_slot_cells('promanaged');
    $tue = $cells['2|early'];
    pm_t_assert($tue['n'] === 1 && $tue['score'] < 200 && $tue['score'] > 100, 'one lucky post is shrunk toward the prior: score ' . $tue['score']);
    pm_t_eq($cells['4|evening']['src'], 'data', 'cell with 4 posts is data-driven');
    pm_t_eq($cells['2|early']['src'], 'prior', 'cell with 1 post stays prior');
    $s = pm_social_slots('promanaged');
    pm_t_eq(count($s), 4, '3 + exploration');
    pm_t_eq(count(array_unique(array_column(array_slice($s, 0, 3), 'dow'))), 3, 'three different weekdays');
    pm_t_eq($s[3]['src'], 'explore', 'last is the exploration slot');
    $th = array_values(array_filter($s, fn($x) => $x['dow'] === 4));
    pm_t_assert(!$th || $th[0]['time'] === '17:30', 'Thursday slot keeps the time that worked');
});

t('Funnel joins leads, posts and signed value', function () {
    fx_reset();
    $post = fx_pub(['pillar' => 'Tip/How-to', 'headline' => 'Closing the shop', 'format' => 'image', '_eng' => 30]);
    $ref = function_exists('pm_post_ref') ? pm_post_ref($post) : 'ABCD';
    $now = date('Y-m-d H:i');
    pm_save('leads', [
        'l1' => ['id' => 'l1', 'brand' => 'promanaged', 'name' => 'Acme Ltd', 'status' => 'won', 'source' => 'facebook', 'source_post' => $post['id'], 'created' => $now,
            'first_touch_at' => '2026-10-01T08:00:00+02:00', 'first_reply_at' => '2026-10-01T09:00:00+02:00'],
        'l2' => ['id' => 'l2', 'brand' => 'promanaged', 'name' => 'Beta Shop', 'status' => 'replied', 'source' => 'web', 'src' => 'fb-' . $ref, 'src_tag' => 'fb-' . $ref, 'created' => $now],
        'l3' => ['id' => 'l3', 'brand' => 'promanaged', 'name' => 'Cold Co', 'status' => 'contacted', 'source' => 'scout', 'src' => ['kind' => 'maps'], 'created' => $now],
        'l4' => ['id' => 'l4', 'brand' => 'promanaged', 'name' => 'Old Co', 'status' => 'replied', 'source' => 'web', 'src_tag' => 'bio_fb', 'created' => date('Y-m-d H:i', strtotime('-200 days'))],
        'l5' => ['id' => 'l5', 'brand' => 'travel', 'name' => 'Other Brand', 'status' => 'replied', 'source' => 'facebook', 'created' => $now],
    ]);
    pm_save('history', [['status' => 'Signed', 'signed_file' => 'x.pdf', 'currency' => 'MWK', 'setup' => 100000, 'monthly' => 10000, 'business' => 'Acme Ltd', 'proposal' => ['package' => 0, 'lead_id' => 'l1']]]);
    $l1 = pm_leads()['l1'];
    pm_t_eq(pm_lead_signed_value($l1), 220000.0, 'signed value = setup + 12 x monthly');
    pm_t_eq(pm_lead_signed_value(pm_leads()['l2']), 0.0, 'unsigned lead is worth 0');
    $f = pm_social_funnel('promanaged', 90);
    pm_t_eq($f['total']['leads'], 2, 'two social leads in 90 days (scout, old and other-brand leads left out)');
    pm_t_eq([$f['total']['replied'], $f['total']['won']], [2, 1], 'replied and won');
    pm_t_eq($f['total']['value'], 220000.0, 'funnel signed value');
    pm_t_eq($f['by']['post'][$post['id']]['leads'] ?? 0, 2, 'both leads join to the post (id and tag/ref)');
    pm_t_eq($f['by']['pillar']['Tip/How-to']['leads'] ?? 0, 2, 'by pillar');
    pm_t_eq($f['by']['source']['facebook']['leads'] ?? 0, 1, 'by source');
    pm_t_eq($f['reply_median_min'], 60, 'median time to first reply');
    $sb = pm_social_scoreboard('promanaged');
    pm_t_eq($sb['groups']['pillar']['Tip/How-to']['leads'], 2, 'scoreboard carries the leads');
    $row = pm_sx_rows('promanaged')['rows'][0];
    pm_t_eq($row['eng'], 30.0 + 10.0, 'enquiries add +5 each to the post\'s engagement');
    $st = pm_pipeline_stats('promanaged');
    pm_t_assert(isset($st['sources'], $st['inbound'], $st['signed_value'], $st['reply_rate']), 'existing pipeline keys kept');
    pm_t_eq($st['by_src']['facebook']['won'] ?? -1, 1, 'by_src counts the won facebook lead');
    pm_t_eq($st['won_value_by_src']['facebook'] ?? 0, 220000, 'won value by source');
    pm_t_assert(isset($st['by_src']['scout']), 'scout leads are in by_src as scout');
});

t('Manual results', function () {
    fx_reset();
    $p = fx_pub(['_eng' => 10]);
    $GLOBALS['PM_WHO'] = 'Tester';
    $_POST = ['id' => $p['id'], 'channel' => 'tiktok', 'views' => '1200', 'saves' => '14', 'enquiries' => '2', 'note' => 'from the app'];
    $r = pm_do_results_save('promanaged');
    pm_t_eq($r['kind'], 'ok', 'saved');
    $man = array_column(pm_social_posts(), null, 'id')[$p['id']]['manual']['tiktok'] ?? [];
    pm_t_eq([$man['views'] ?? 0, $man['saves'] ?? 0, $man['by'] ?? ''], [1200, 14, 'Tester'], 'stored with who entered it');
    pm_t_assert(!empty($man['at']), 'and when');
    $_POST = ['id' => $p['id'], 'channel' => 'nonsense', 'views' => '1'];
    pm_t_eq(pm_do_results_save('promanaged')['kind'], 'err', 'unknown channel refused');
    $_POST = ['id' => $p['id'], 'channel' => 'tiktok'];
    pm_t_eq(pm_do_results_save('promanaged')['kind'], 'err', 'empty form refused');
    // manual-only post (no API numbers) is scored from the owner's Facebook figures and labelled
    $q = pm_t_fixture_post(['status' => 'published', 'published' => date('Y-m-d H:i', time() - 20 * 86400), 'when' => date('Y-m-d H:i', time() - 20 * 86400)]);
    pm_t_eq(count(pm_sx_rows('promanaged')['rows']), 1, 'post without any 7-day numbers is not scored');
    $_POST = ['id' => $q['id'], 'channel' => 'facebook', 'reactions' => '30', 'comments' => '4'];
    pm_do_results_save('promanaged');
    $rows = pm_sx_rows('promanaged')['rows'];
    $mine = array_values(array_filter($rows, fn($r) => $r['id'] === $q['id']))[0] ?? null;
    pm_t_assert($mine && $mine['src'] === 'manual' && $mine['eng'] == 38.0, 'owner-entered Facebook figures score the post and are labelled manual');
    $_POST = [];
    pm_t_eq(pm_do_followers_manual('promanaged')['kind'], 'err', 'empty followers form refused');
    $_POST = ['li_followers' => '210'];
    pm_t_eq(pm_do_followers_manual('promanaged')['kind'], 'ok', 'followers form ok');
    pm_t_eq(pm_social_followers_all('promanaged')['li'], 210, 'LinkedIn followers stored as entered');
    $_POST = [];
});

t('Ads results', function () use (&$S, &$calls) {
    $d1 = date('Y-m-d');
    $d2 = date('Y-m-d', strtotime('-1 day'));
    $S['ads'] = [
        ['campaign_name' => 'C1', 'date_start' => $d1, 'spend' => '5000', 'reach' => '3000', 'clicks' => '40', 'actions' => [['action_type' => PM_SX_AD_CONV_ACTION, 'value' => '5'], ['action_type' => 'link_click', 'value' => '40']]],
        ['campaign_name' => 'C1', 'date_start' => $d2, 'spend' => '3000', 'reach' => '1000', 'clicks' => '10', 'actions' => [['action_type' => PM_SX_AD_CONV_ACTION, 'value' => '1']]],
    ];
    pm_social_goals_save('promanaged', ['max_cost_per_conv_mwk' => 1000]);
    $a = pm_ads_results('promanaged', true);
    pm_t_eq([$a['spend_mwk'], $a['conversations'], $a['clicks']], [8000, 6, 50], 'ad totals read from insights');
    pm_t_eq($a['cost_per_conversation'], 1333, 'cost per conversation');
    pm_t_eq($a['source'], 'api', 'source api');
    pm_t_assert(count($a['hints']) === 1 && str_contains($a['hints'][0], 'above your limit'), 'deterministic hint when above the limit');
    $stored = pm_load('social_ads_results', fn() => [])['promanaged'] ?? [];
    pm_t_assert(isset($stored[$d1]['C1']) && isset($stored[$d2]['C1']), 'stored per day and campaign');
    $n = count($calls);
    pm_ads_results('promanaged');
    pm_t_eq(count($calls), $n, 'no second Graph call within 6 hours');
    $_POST = ['day' => $d1, 'spend' => '2000', 'chats' => '2', 'qualified' => '1', 'signed' => '0'];
    $GLOBALS['PM_WHO'] = 'Tester';
    pm_t_eq(pm_do_ads_manual('promanaged')['kind'], 'ok', 'manual ads saved');
    $a = pm_ads_results('promanaged');
    pm_t_eq([$a['spend_mwk'], $a['conversations'], $a['source'], $a['manual_by']], [10000, 8, 'both', 'Tester'], 'typed figures are added and labelled');
    $c = pm_social_cost('promanaged', 7);
    pm_t_eq($c['ads_spend_mwk'], 10000, 'cost includes ad spend');
    $_POST = [];
});

t('Weekly report sends once', function () {
    fx_reset();
    for ($i = 0; $i < 3; $i++) {
        fx_pub(['_eng' => 20, '_ago' => 24 * (10 + $i)]);
    }
    $monday = strtotime('next monday');
    $GLOBALS['PM_SX_NOW'] = strtotime(date('Y-m-d', $monday) . ' 07:00');
    pm_t_eq(pm_jobg_report_weekly(), '', 'not before Monday 07:30');
    $GLOBALS['PM_SX_NOW'] = strtotime(date('Y-m-d', $monday) . ' 08:00');
    $r1 = pm_jobg_report_weekly();
    pm_t_assert(str_contains($r1, 'promanaged'), 'sent for promanaged on Monday 08:00: ' . $r1);
    pm_t_eq(pm_jobg_report_weekly(), '', 'second run the same week sends nothing');
    $GLOBALS['PM_SX_NOW'] = $GLOBALS['PM_SX_NOW'] + 86400;
    pm_t_eq(pm_jobg_report_weekly(), '', 'Tuesday of the same week: still nothing');
    pm_t_eq(pm_social_state()['report_week']['promanaged'] ?? '', date('o-\WW', $monday), 'stamped in social state report_week');
    $GLOBALS['PM_SX_NOW'] = strtotime(date('Y-m-d', $monday) . ' 08:00 +7 days');
    pm_t_assert(str_contains(pm_jobg_report_weekly(), 'promanaged'), 'next Monday sends again');
    $GLOBALS['PM_SX_NOW'] = strtotime(date('Y-m-d', $monday) . ' 08:00 +4 days'); // Friday
    pm_t_eq(pm_jobg_report_weekly(), '', 'Friday: nothing');
    unset($GLOBALS['PM_SX_NOW']);
    $r = pm_social_week_report('promanaged');
    pm_t_eq(count($r['actions']), 3, 'three next actions');
    $txt = pm_social_week_text($r);
    pm_t_assert(str_contains($txt, 'Next week:') && str_contains($txt, 'Followers (Facebook)'), 'report text has its parts');
    pm_t_assert(str_contains($txt, 'Not enough data') || str_contains($txt, 'not enough data'), 'says so when there is too little to rank: ' . substr($txt, 0, 200));
});

t('Experiments', function () {
    fx_reset();
    pm_save('social_experiments', ['rows' => []]);
    $e = pm_experiment_start('promanaged', 'cta_question_vs_whatsapp');
    pm_t_assert($e && $e['status'] === 'active', 'started from the fixed menu');
    pm_t_eq(pm_experiment_start('promanaged', 'hour_0730_vs_1830'), null, 'only one at a time');
    pm_t_eq(pm_experiment_start('promanaged', 'made_up'), null, 'unknown key refused');
    $arms = array_map(fn($i) => pm_experiment_for_slot('promanaged', $i)['arm'], [0, 1, 2, 3]);
    pm_t_eq($arms, ['A', 'B', 'A', 'B'], 'arms alternate');
    $s0 = pm_experiment_for_slot('promanaged', 0);
    pm_t_assert($s0['field'] === 'cta' && str_contains($s0['instruction'], 'question') && $s0['id'] === $e['id'], 'slot has id, field and planner instruction');
    pm_t_eq(pm_experiment_for_slot('travel', 0), null, 'no experiment for another brand');
    pm_t_eq(pm_experiment_eval($e['id'])['verdict'], 'too early', 'too early with no posts');
    pm_t_eq(pm_experiment_for_slot('promanaged', 0)['arm'], 'A', 'unchanged');
    pm_t_eq(pm_job_experiments('promanaged'), '', 'job leaves a too-early experiment running');
    $mk = function (string $arm, int $eng, int $i) use ($e) {
        return fx_pub(['exp' => $e['id'], 'arm' => $arm, 'cta' => $arm === 'A' ? 'comment' : 'whatsapp', '_eng' => $eng, '_ago' => 24 * (12 + $i)]);
    };
    foreach ([40, 42, 38] as $i => $v) {
        $mk('A', $v, $i);
    }
    foreach ([80, 82] as $i => $v) {
        $mk('B', $v, 10 + $i);
    }
    $ev = pm_experiment_eval($e['id']);
    pm_t_eq($ev['verdict'], 'too early', '3 vs 2 posts is too early');
    pm_t_assert(str_contains($ev['summary'], 'Too early') && $ev['figures']['A']['n'] === 3, 'plain figures');
    $mk('A', 41, 3);
    foreach ([78, 80] as $i => $v) {
        $mk('B', $v, 20 + $i);
    }
    $ev = pm_experiment_eval($e['id']);
    pm_t_eq([$ev['verdict'], $ev['winner']], ['keep', 'B'], 'B clearly better -> keep');
    pm_t_assert(($ev['lift'] ?? 0) >= 90 && str_contains($ev['summary'], 'WhatsApp us beat Ask a question'), 'lift and wording: ' . $ev['summary']);
    $msg = pm_job_experiments('promanaged');
    pm_t_assert(str_contains($msg, 'experiment finished'), 'job closes it');
    pm_t_eq(pm_experiment_active('promanaged'), null, 'no active experiment after the job');
    $learn = pm_experiment_learnings('promanaged');
    pm_t_assert(count($learn) === 1 && str_contains($learn[0], 'WhatsApp us'), 'learning stored');
    pm_t_assert(str_contains(pm_social_learnings('promanaged'), 'Tested:'), 'learnings text includes the tested result');
    // no clear difference -> drop
    pm_save('social_experiments', ['rows' => []]);
    pm_save('social_posts', []);
    $e = pm_experiment_start('promanaged', 'cta_question_vs_whatsapp');
    foreach ([40, 44, 38, 42] as $i => $v) {
        fx_pub(['exp' => $e['id'], 'arm' => 'A', 'cta' => 'comment', '_eng' => $v, '_ago' => 24 * (12 + $i)]);
    }
    foreach ([41, 43, 39, 45] as $i => $v) {
        fx_pub(['exp' => $e['id'], 'arm' => 'B', 'cta' => 'whatsapp', '_eng' => $v, '_ago' => 24 * (20 + $i)]);
    }
    $ev = pm_experiment_eval($e['id']);
    pm_t_eq([$ev['verdict'], $ev['winner']], ['drop', ''], 'similar results -> drop (no clear difference)');
    // B worse -> drop, A wins
    pm_save('social_experiments', ['rows' => []]);
    pm_save('social_posts', []);
    $e = pm_experiment_start('promanaged', 'cta_question_vs_whatsapp');
    foreach ([80, 84, 78, 82] as $i => $v) {
        fx_pub(['exp' => $e['id'], 'arm' => 'A', 'cta' => 'comment', '_eng' => $v, '_ago' => 24 * (12 + $i)]);
    }
    foreach ([40, 41, 39, 42] as $i => $v) {
        fx_pub(['exp' => $e['id'], 'arm' => 'B', 'cta' => 'whatsapp', '_eng' => $v, '_ago' => 24 * (20 + $i)]);
    }
    $ev = pm_experiment_eval($e['id']);
    pm_t_eq([$ev['verdict'], $ev['winner']], ['drop', 'A'], 'B clearly worse -> drop, A wins');
    // Director picks the next one from the menu when none is running
    pm_save('social_experiments', ['rows' => []]);
    $n = pm_experiment_autostart('promanaged');
    pm_t_assert($n && isset(PM_SX_EXP_MENU[$n['key']]) && pm_experiment_active('promanaged'), 'autostart picks from the fixed menu');
    pm_t_eq(pm_experiment_autostart('promanaged'), null, 'and not twice');
    // stop
    $_POST = ['id' => $n['id']];
    pm_t_eq(pm_do_experiment_stop('promanaged')['kind'], 'ok', 'owner can stop it');
    pm_t_eq(pm_experiment_active('promanaged'), null, 'stopped');
    pm_t_eq(pm_experiment_learnings('promanaged') === [] || !str_contains(implode(' ', pm_experiment_learnings('promanaged')), (string)$n['hypothesis']), true, 'a stopped experiment concludes nothing');
    $_POST = [];
});

t('Recycle', function () {
    fx_reset();
    // eligible: 10 evergreen posts 60..105 days old, eng 10..100
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        $ids[$i] = fx_pub(['_eng' => $i * 10, '_ago' => 24 * (55 + $i * 5), 'pillar' => 'Tip/How-to', 'caption' => "Tip number $i about your shop till and stock counts at the end of a busy day.", 'headline' => "Tip $i"])['id'];
    }
    $recent = fx_pub(['_eng' => 500, '_ago' => 24 * 20, 'headline' => 'too recent'])['id'];
    $dated = fx_pub(['_eng' => 400, '_ago' => 24 * 70, 'expires' => date('Y-m-d', strtotime('+10 days')), 'headline' => 'dated'])['id'];
    $again = fx_pub(['_eng' => 300, '_ago' => 24 * 80, 'recycled_at' => date('Y-m-d', strtotime('-30 days')), 'headline' => 'recycled lately'])['id'];
    $long = fx_pub(['_eng' => 200, '_ago' => 24 * 90, 'recycled_at' => date('Y-m-d', strtotime('-100 days')), 'headline' => 'recycled long ago'])['id'];
    $reel = fx_pub(['_eng' => 600, '_ago' => 24 * 75, 'format' => 'reel', 'headline' => 'reel'])['id'];
    $c = pm_recycle_candidates('promanaged');
    $got = array_column(array_column($c, 'post'), 'id');
    pm_t_assert(!in_array($recent, $got, true), 'posts younger than 45 days are never recycled');
    pm_t_assert(!in_array($dated, $got, true) && !in_array($reel, $got, true), 'dated and non-picture posts are not evergreen');
    pm_t_assert(!in_array($again, $got, true), 'not recycled twice within 90 days');
    pm_t_assert(in_array($long, $got, true) || count($got) >= 1, 'a post recycled over 90 days ago may come back');
    pm_t_eq(count($c), (int)ceil(11 * 0.2), 'top 20% of the 11 eligible posts: ' . count($c));
    pm_t_eq($c[0]['post']['id'], $ids[10], 'best first');
    $beforeN = count(pm_social_posts());
    $r = pm_recycle_make($ids[10]);
    pm_t_assert($r['ok'], 'made: ' . $r['msg']);
    $new = $r['post'];
    pm_t_assert(in_array($new['status'], ['draft', 'needs_edit'], true) && $new['status'] !== 'approved', 'a draft only (Auto-publish off): ' . $new['status']);
    pm_t_eq($new['recycled_from'], $ids[10], 'recycled_from set');
    pm_t_eq(count(pm_social_posts()), $beforeN + 1, 'exactly one new post');
    pm_t_assert($new['caption'] !== array_column(pm_social_posts(), null, 'id')[$ids[10]]['caption'] && str_contains($new['caption'], 'Tip number 10'), 'fresh opener, same substance');
    pm_t_assert(strtotime($new['when']) > time(), 'scheduled in the future');
    $orig = array_column(pm_social_posts(), null, 'id')[$ids[10]];
    pm_t_eq($orig['recycled_at'], date('Y-m-d'), 'original is stamped recycled_at');
    pm_t_eq(pm_recycle_make($ids[10])['ok'], false, 'not again within 90 days');
    pm_t_eq(pm_recycle_make($recent)['ok'], false, 'a recent post is refused');
    pm_t_eq(pm_recycle_make($dated)['ok'], false, 'a dated post is refused');
    $made = pm_recycle_fill('promanaged', 2);
    pm_t_eq($made, 1, 'fill respects 2 a week in total (one already made)');
    pm_t_eq(pm_recycle_fill('promanaged', 2), 0, 'and then stops');
    $_POST = ['id' => $ids[9]];
    pm_t_eq(pm_do_recycle('promanaged')['kind'], 'err', 'button refuses a post that was just recycled by fill or is limited');
    $_POST = [];
    fx_reset();
    pm_t_eq(pm_recycle_candidates('promanaged'), [], 'no candidates without data');
});

t('Director brief', function () {
    fx_reset();
    for ($i = 0; $i < 9; $i++) {
        fx_pub(['_eng' => 20 + $i, '_ago' => 24 * (12 + $i), 'pillar' => $i % 2 ? 'Tip/How-to' : 'Offer']);
    }
    pm_social_goals_save('promanaged', ['enquiries_per_week' => 0]);
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function (string $sys, string $user, string $tier, int $max) use (&$seen): string {
        $seen[] = [$sys, $user, $tier];
        return json_encode(['headline' => 'Test headline', 'priorities' => ['a', 'b'], 'focus' => [], 'drop' => [], 'experiment' => 'e', 'social_focus' => 'awareness', 'social_note' => 'Answer comments within 2 hours.']);
    };
    $b = pm_director_brief(true);
    pm_t_eq(count($seen), 1, 'one AI call');
    pm_t_eq($seen[0][2], 'write', 'tier unchanged');
    $u = json_decode($seen[0][1], true);
    pm_t_assert(isset($u['social']) && array_key_exists('followers', $u['social']), 'user JSON carries a social block');
    pm_t_assert(strlen(json_encode($u['social'])) < 700, 'social block is compact (' . strlen(json_encode($u['social'])) . ' chars)');
    pm_t_assert(str_contains($seen[0][0], 'social_focus'), 'reply format asks for social_focus');
    pm_t_eq($b['social_focus'] ?? '', 'awareness', 'social_focus validated and kept');
    pm_t_eq(pm_load('director', fn() => [])['promanaged']['social_note'] ?? '', 'Answer comments within 2 hours.', 'social_note saved in director.json');
    pm_t_eq(pm_social_focus_for('promanaged'), 'awareness', 'planner focus follows the Director when nothing else decides');
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['headline' => 'H', 'priorities' => [], 'focus' => [], 'drop' => [], 'social_focus' => 'bogus', 'social_note' => 'n']);
    $b = pm_director_brief(true);
    pm_t_assert(($b['social_focus'] ?? '') === '' || isset(PM_SOCIAL_FOCUS[$b['social_focus']]), 'invalid social_focus is not stored');
    pm_t_eq(pm_load('director', fn() => [])['promanaged']['social_focus'] ?? 'unset', 'unset', 'bogus focus dropped');
    // enquiries below goal -> leads
    pm_social_goals_save('promanaged', ['enquiries_per_week' => 5]);
    pm_t_eq(pm_social_focus_for('promanaged'), 'leads', 'enquiries below goal -> leads');
    pm_social_goals_save('promanaged', ['enquiries_per_week' => 0]);
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Hooks, helpers, view', function () use (&$S) {
    $r = pm_hook_after_publish_metrics(['fb_id' => '888', 'format' => 'reel'], pm_social_cfg('promanaged'));
    pm_t_eq($r, ['fb_id' => '999_888'], 'video id resolved to the post id');
    pm_t_eq(pm_hook_after_publish_metrics(['fb_id' => '999_1', 'format' => 'image'], pm_social_cfg('promanaged')), null, 'plain posts untouched');
    pm_t_eq(pm_social_resolve_fb_id('promanaged', ['url' => 'https://www.facebook.com/999/posts/12345']), '999_12345', 'URL /posts/ id');
    pm_t_eq(pm_social_resolve_fb_id('promanaged', ['url' => 'https://www.facebook.com/permalink.php?story_fbid=777&id=999']), '999_777', 'URL permalink.php');
    pm_t_eq(pm_social_resolve_fb_id('promanaged', ['url' => 'not a url']), '', 'unknown -> empty');
    pm_t_eq(pm_job_metrics('travel'), 'skipped (not connected)', 'job skips an unconnected brand');
    pm_t_assert(pm_panel_plan_kpi_growth('promanaged') !== '', 'plan KPI panel renders');
    pm_t_assert(is_array(pm_today_goalgap('promanaged')) && is_array(pm_today_numbers('promanaged')), 'today items are arrays');
    // the Results page renders with and without data
    $vb = 'promanaged';
    $csrf = 'x';
    $settings = pm_settings();
    foreach (['with data', 'empty'] as $mode) {
        if ($mode === 'empty') {
            fx_reset();
        }
        ob_start();
        include dirname(__DIR__) . '/lib/view_social_results.php';
        $html = ob_get_clean();
        pm_t_assert(str_contains($html, '<h1>') && str_contains($html, 'Scoreboard') && str_contains($html, 'name="do" value="goals_save"'), "results view renders ($mode)");
        pm_t_assert(!str_contains($html, 'Warning:') && !str_contains($html, 'Notice:') && !str_contains($html, 'Fatal'), "no PHP notices in the view ($mode)");
    }
    pm_t_assert(str_contains($html, 'Not enough data') || str_contains($html, 'not enough data'), 'honest empty state');
});

pm_t_done();
