<?php
/**
 * Tests for deleting a business that was added in the app: what is removed (and that nothing of any other business is), the backup, the refusals (the two core
 * businesses, an unknown id, a run in progress, a backup that cannot be written), bringing a hidden business back, and that a re-added business starts empty.
 * Runs on a TEMP COPY of data/ (see boot.php); the only thing written outside it is one picture in assets/ under a throw-away id, removed again at the end.
 * Run: php tests/test_branddelete.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/wa_biz.php';
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

$D = PM_DATA;
$ANS = ['name' => 'Sunrise Solar', 'sells' => 'We design, install and maintain solar power and battery systems for homes and small businesses.',
    'customers' => 'Homeowners and small business owners who want reliable power', 'sell_to' => 'business', 'cities' => 'Lilongwe, Blantyre',
    'targets' => 'schools, clinics, guest houses', 'facts' => "We install and maintain solar systems\nWe offer a free site visit", 'voice' => 'friendly', 'magnet' => 'a free site visit',
    'email' => 'info@sunrise.example', 'phone' => '0999111222', 'website' => 'https://www.sunrise.example'];
$mkBiz = function (array $over = []) use ($ANS): string {
    $a = $over + $ANS;
    $draft = pm_brand_draft($a)['draft'];
    $draft['sectors'] = ['schools', 'clinics'];
    [$id, $err] = pm_brand_create($a, $draft);
    pm_t_eq($err, [], 'created ' . $a['name']);
    return $id;
};
$json = fn(string $f) => (array)(is_file(PM_DATA . "/$f.json") ? json_decode((string)file_get_contents(PM_DATA . "/$f.json"), true) : []);
$ago = fn(int $d) => date('Y-m-d H:i', strtotime("-$d days"));
$lead = fn(string $id, string $brand, array $x = []) => $x + ['id' => $id, 'brand' => $brand, 'name' => "Lead $id", 'type' => 'school', 'city' => 'Zomba', 'status' => 'contacted', 'email' => "$id@x.mw", 'notes' => [], 'thread' => []];
$pid = fn(string $tag, int $n) => substr(md5($tag . $n), 0, 20); // a post id: 20 hex characters
$assetFile = dirname(__DIR__) . '/assets/brand_sunrisesolar_logo.png'; // the picture of the business deleted below (its id is made by the test, so the file is the test's own)
if (is_file($assetFile)) {
    fwrite(STDERR, "assets/brand_sunrisesolar_logo.png already exists: refusing to run (the test would delete it).\n");
    exit(2);
}

/** Gives $id some of everything the app keeps per business. */
$seed = function (string $id, string $tag) use ($D, $lead, $json, $pid, $ago): void {
    $leads = $json('leads');
    foreach (['a', 'b', 'c'] as $k) {
        $leads["$tag$k"] = $lead("$tag$k", $id);
    }
    pm_save('leads', $leads);
    $arch = $json('leads_archive');
    $arch["{$tag}old"] = $lead("{$tag}old", $id, ['status' => 'lost']);
    pm_save('leads_archive', $arch);
    $posts = $json('social_posts');
    $posts[] = ['id' => $pid($tag, 1), 'brand' => $id, 'status' => 'draft', 'text' => "Post of $id"];
    $posts[] = ['id' => $pid($tag, 2), 'brand' => $id, 'status' => 'posted', 'text' => "Second post of $id"];
    pm_save('social_posts', array_values($posts));
    $ar = $json('social_posts_archive');
    $ar['posts'][] = ['id' => $pid($tag, 3), 'brand' => $id, 'text' => 'old'];
    $ar['summary'] = $ar['summary'] ?? [];
    pm_save('social_posts_archive', $ar);
    foreach (['lead_offers' => 'offer', 'wa_campaigns' => 'camp', 'social_experiments' => 'exp'] as $f => $w) {
        $o = $json($f);
        $o['rows'][] = ['id' => "$w$tag", 'brand' => $id, 'name' => "$w of $id"];
        pm_save($f, $o);
    }
    foreach (['director' => ['headline' => "Strategy $id"], 'ads_plan' => ['x' => 1], 'autopilot' => ['on' => true], 'send_caps' => ['day' => 3], 'fb_judged' => ['k' => 'v'], 'followup_job' => $ago(0), 'offer_ideas' => ['rows' => [['t' => 'idea']]]] as $f => $v) {
        $m = $json($f);
        $m[$id] = $v;
        pm_save($f, $m);
    }
    $log = $json('agent_log');
    $log[] = ['at' => $ago(0), 'agent' => 'scout', 'brand' => $id, 'msg' => 'x'];
    pm_save('agent_log', $log);
    $hc = $json('health_checks');
    $hc["wa_$id"] = ['at' => $ago(0), 'ok' => true, 'msg' => 'ok'];
    $hc["smtp_$id"] = ['at' => $ago(0), 'ok' => false, 'msg' => 'blocked'];
    pm_save('health_checks', $hc);
    $sc = $json('suggest_cache');
    $sc["$id|zomba"] = ['at' => time(), 'rows' => []];
    pm_save('suggest_cache', $sc);
    $s = pm_load('settings', 'pm_default_settings');
    $s['wa_templates'][] = ['name' => "tpl_$id", 'brand' => $id, 'body' => 'Hello'];
    pm_save('settings', $s);
    file_put_contents("$D/link_$id.json", json_encode(['url' => 'https://x.example', 'ok' => true]));
    file_put_contents("$D/" . pm_run_state_file($id), json_encode(['state' => 'done', 'beat' => time() - 7200]));
    @mkdir("$D/brandkit/$id", 0775, true);
    file_put_contents("$D/brandkit/$id/logo.png", 'x');
    @mkdir("$D/thumbs", 0775, true);
    file_put_contents("$D/thumbs/$id.img", 'x');
    @mkdir("$D/social", 0775, true);
    foreach ([1, 2] as $n) {
        file_put_contents("$D/social/" . $pid($tag, $n) . '_1080.png', 'x');
    }
};

$SUN = $mkBiz();
$MOON = $mkBiz(['name' => 'Moonlight Cafe', 'email' => 'hi@moon.example']);
pm_t_eq([$SUN, $MOON], ['sunrisesolar', 'moonlightcaf'], 'two businesses were added (an id is the name, 12 letters at most)');
$leads = pm_leads();
$leads['pm1'] = $lead('pm1', 'promanaged');
$leads['pm2'] = $lead('pm2', 'promanaged', ['status' => 'replied']);
$leads['tm1'] = $lead('tm1', 'travel');
pm_save('leads', $leads);
$seed($SUN, 'sun');
$seed($MOON, 'moon');
file_put_contents($assetFile, 'x');
register_shutdown_function(function () use ($assetFile) {
    is_file($assetFile) && @unlink($assetFile);
});
@mkdir("$D/deleted", 0775, true);

t('Only a business that was added can be deleted', function () {
    foreach (['promanaged', 'travel'] as $b) {
        $r = pm_brand_delete($b);
        pm_t_assert(!$r['ok'] && str_contains($r['message'], 'core businesses'), "$b is refused: " . $r['message']);
    }
    pm_t_assert(!pm_brand_delete('nosuchbiz')['ok'], 'an unknown id is refused');
    pm_t_assert(!pm_brand_delete('../leads')['ok'] && !pm_brand_delete('')['ok'], 'something that is not an id is refused');
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel', 'sunrisesolar', 'moonlightcaf'], 'nothing changed');
    pm_t_eq(pm_brand_purge('promanaged', false)['counts']['leads'], 0, 'and a built-in is never even counted as a purge target');
});

t('What deleting would remove is counted first', function () use ($SUN, $assetFile) {
    $c = pm_brand_delete_plan($SUN);
    pm_t_eq([$c['leads'], $c['archived'], $c['posts'], $c['offers'], $c['campaigns']], [3, 1, 3, 1, 1], 'leads, archived leads, posts (live and archived), offers and campaigns');
    pm_t_assert($c['other'] > 8, 'and its settings, strategy, mailbox, logo and saved files are counted too: ' . $c['other']);
    $w = pm_brand_plan_words($c);
    pm_t_assert(str_contains($w, '3 leads') && str_contains($w, '1 archived lead,') && str_contains($w, '3 posts') && str_contains($w, '1 offer,') && str_contains($w, '1 WhatsApp campaign'), 'in words: ' . $w);
    pm_t_assert(is_file($assetFile) && count(array_filter(pm_leads(), fn($l) => $l['brand'] === $SUN)) === 3, 'asking changes nothing');
});

t('A run in progress blocks the delete; a stale one does not', function () use ($SUN, $D) {
    file_put_contents("$D/" . pm_run_state_file($SUN), json_encode(['state' => 'running', 'beat' => time() - 30]));
    $r = pm_brand_delete($SUN);
    pm_t_assert(!$r['ok'] && str_contains($r['message'], 'running'), 'refused while the agents run: ' . $r['message']);
    pm_t_assert(isset(pm_brands_custom()[$SUN]) && count(array_filter(pm_leads(), fn($l) => $l['brand'] === $SUN)) === 3, 'and nothing was touched');
    file_put_contents("$D/" . pm_run_state_file($SUN), json_encode(['state' => 'running', 'beat' => time() - 3600]));
    pm_t_assert(!pm_brand_run_busy($SUN), 'a run that stopped beating an hour ago is not a run in progress');
    file_put_contents("$D/" . pm_run_state_file($SUN), json_encode(['state' => 'done', 'beat' => time() - 7200]));
});

t('A backup that cannot be written stops the delete', function () use ($SUN, $D) {
    rmdir("$D/deleted");
    file_put_contents("$D/deleted", 'a file where the folder should be');
    $r = pm_brand_delete($SUN);
    pm_t_assert(!$r['ok'] && str_contains($r['message'], 'nothing was deleted'), 'refused: ' . $r['message']);
    pm_t_assert(isset(pm_brands_custom()[$SUN]) && count(array_filter(pm_leads(), fn($l) => $l['brand'] === $SUN)) === 3, 'everything is still there');
    unlink("$D/deleted");
});

$BACKUP = '';
t('Deleting removes the business, and only that business', function () use ($SUN, $MOON, $D, $json, $pid, $assetFile, &$BACKUP) {
    $cfgBefore = $json('agents_config');
    $nLeads = count(pm_leads());
    $realPosts = count(array_filter($json('social_posts'), fn($p) => !in_array($p['brand'] ?? '', [$SUN, $MOON], true)));
    $setBefore = pm_load('settings', 'pm_default_settings');
    $r = pm_brand_delete($SUN);
    $BACKUP = $r['backup'];
    pm_t_assert($r['ok'] && str_contains($r['message'], 'deleted for good') && str_contains($r['message'], 'data/deleted/sunrisesolar-'), 'deleted: ' . $r['message']);
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel', $MOON], 'it is out of the registry');
    pm_t_assert(!isset(pm_brands_custom(true)[$SUN]) && pm_brand_name($SUN) === 'ProManaged IT', 'even among the hidden, and it is no longer a known name');
    $L = pm_leads();
    pm_t_assert(!array_filter($L, fn($l) => ($l['brand'] ?? '') === $SUN) && isset($L['pm1'], $L['pm2'], $L['tm1'], $L['moona'], $L['moonb'], $L['moonc']) && count($L) === $nLeads - 3, 'no lead of it is left (3 gone of ' . $nLeads . '); every other lead is still there');
    pm_t_eq(array_keys($json('leads_archive')), ['moonold'], 'archived leads: only the other business\'s');
    $posts = $json('social_posts');
    pm_t_assert(array_is_list($posts) && count($posts) === $realPosts + 2 && !array_filter($posts, fn($p) => ($p['brand'] ?? '') === $SUN) && in_array($pid('moon', 1), array_column($posts, 'id'), true), 'posts stay a list; its 2 are gone and every other post is still there');
    pm_t_eq(array_column($json('social_posts_archive')['posts'], 'id'), [$pid('moon', 3)], 'archived posts too');
    pm_t_assert(!glob("$D/social/" . $pid('sun', 1) . '*') && !glob("$D/social/" . $pid('sun', 2) . '*') && glob("$D/social/" . $pid('moon', 1) . '*'), 'its post pictures are deleted, the other business\'s stay');
    foreach (['lead_offers' => 'offermoon', 'wa_campaigns' => 'campmoon', 'social_experiments' => 'expmoon'] as $f => $keep) {
        $ids = array_column($json($f)['rows'], 'id');
        pm_t_assert(in_array($keep, $ids, true) && !in_array(str_replace('moon', 'sun', $keep), $ids, true), "$f: its row is gone, the other business's stays");
    }
    foreach (['director', 'ads_plan', 'autopilot', 'send_caps', 'fb_judged', 'followup_job', 'offer_ideas', 'agents_config'] as $f) {
        $m = $json($f);
        pm_t_assert(!array_key_exists($SUN, $m) && array_key_exists($MOON, $m), "$f: its block is gone, the other business's stays");
    }
    $dir = $json('director');
    pm_t_assert(isset($dir['promanaged'], $dir['travel']), 'the strategies of ProManaged IT and Travel Malawi are untouched');
    $cfg = $json('agents_config');
    unset($cfgBefore[$SUN], $cfg[$MOON], $cfgBefore[$MOON]);
    pm_t_eq($cfg, $cfgBefore, 'agents_config is otherwise exactly as it was (ProManaged\'s own settings at its top, Travel Malawi\'s block)');
    pm_t_assert(!array_filter($json('agent_log'), fn($x) => ($x['brand'] ?? '') === $SUN) && array_filter($json('agent_log'), fn($x) => ($x['brand'] ?? '') === $MOON), 'the activity log lost only its lines');
    $hc = $json('health_checks');
    pm_t_assert(!isset($hc["wa_$SUN"], $hc["smtp_$SUN"]) && isset($hc["wa_$MOON"], $hc["smtp_$MOON"]), 'its connection test results are gone, the other\'s stay');
    $sc = $json('suggest_cache');
    pm_t_assert(!isset($sc["$SUN|zomba"]) && isset($sc["$MOON|zomba"]) && isset($sc['travel|lilongwe']), 'its search memory is gone, the others stay');
    $s = pm_load('settings', 'pm_default_settings');
    pm_t_assert(!isset($s['brands'][$SUN]) && isset($s['brands'][$MOON]), 'its settings block is gone');
    pm_t_eq(array_values($s['wa_templates']), array_values(array_filter($setBefore['wa_templates'], fn($t) => ($t['brand'] ?? '') !== $SUN)), 'its WhatsApp templates are gone, every other business\'s stay');
    unset($s['brands'], $s['wa_templates'], $setBefore['brands'], $setBefore['wa_templates']);
    pm_t_eq($s, $setBefore, 'every other setting is as it was');
    pm_t_assert(!is_file("$D/link_$SUN.json") && !is_file("$D/" . pm_run_state_file($SUN)) && !is_dir("$D/brandkit/$SUN") && !is_file("$D/thumbs/$SUN.img") && !is_file($assetFile), 'its link preview, run state, brand kit, thumbnail and logo are deleted');
    pm_t_assert(is_file("$D/link_$MOON.json") && is_file("$D/" . pm_run_state_file($MOON)) && is_dir("$D/brandkit/$MOON") && is_file("$D/thumbs/$MOON.img"), 'the other business\'s files stay');
});

t('The backup holds what was removed, and nothing else', function () use (&$BACKUP, $SUN, $MOON, $pid) {
    pm_t_assert($BACKUP !== '' && is_file($BACKUP), 'a backup file was written: ' . basename($BACKUP));
    $b = json_decode((string)file_get_contents($BACKUP), true);
    pm_t_assert(($b['business'] ?? '') === 'Sunrise Solar' && ($b['id'] ?? '') === $SUN && !empty($b['deleted_at']), 'it says which business and when');
    $rm = (array)($b['removed'] ?? []);
    pm_t_eq([count($rm['leads.json'] ?? []), count($rm['leads_archive.json'] ?? []), count($rm['social_posts.json'] ?? [])], [3, 1, 2], 'the leads, archived lead and posts are in it');
    pm_t_assert(isset($rm['settings.json']['brands'][$SUN]) && isset($rm['agents_config.json'][$SUN]) && isset($rm['director.json'][$SUN]) && isset($rm['brands.json'][$SUN]), 'and its settings, agent settings, strategy and registry entry');
    pm_t_assert(in_array('link_sunrisesolar.json', $b['files_removed'], true) && in_array('brandkit', array_map('basename', array_merge($b['files_removed'], ['brandkit']))), 'and the list of files that went');
    pm_t_assert(!str_contains((string)file_get_contents($BACKUP), $MOON) && !str_contains((string)file_get_contents($BACKUP), 'moona'), 'nothing of any other business is in it');
});

t('Without a backup nothing is kept', function () use ($MOON, $D) {
    $n = count(glob("$D/deleted/*"));
    $r = pm_brand_delete($MOON, false);
    pm_t_assert($r['ok'] && $r['backup'] === '' && str_contains($r['message'], 'no backup was kept'), 'deleted: ' . $r['message']);
    pm_t_eq(count(glob("$D/deleted/*")), $n, 'and no file was added to data/deleted');
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel'], 'only the two core businesses remain');
    $L = pm_leads();
    pm_t_assert(isset($L['pm1'], $L['pm2'], $L['tm1']) && !array_filter($L, fn($l) => !in_array($l['brand'] ?? 'promanaged', ['promanaged', 'travel'], true)), 'and their leads are all still there, none of an added business is');
});

t('A hidden business can be brought back, or deleted', function () use ($mkBiz, $seed, $D) {
    $H = $mkBiz(['name' => 'Hidden Hardware']);
    $seed($H, 'hid');
    pm_t_assert(!pm_brand_unarchive($H) && !pm_brand_unarchive('promanaged') && !pm_brand_unarchive('nosuch'), 'a business that is not hidden, a core one and an unknown one cannot be "brought back"');
    pm_brand_archive($H);
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel'], 'hidden: out of the menus');
    pm_t_assert(pm_brand_unarchive($H) && pm_brand_ids() === ['promanaged', 'travel', $H] && count(array_filter(pm_leads(), fn($l) => $l['brand'] === $H)) === 3, 'brought back: in the menus again, with its leads');
    pm_brand_archive($H);
    pm_t_assert(!isset(pm_brands_custom()[$H]) && count(array_filter(pm_leads(), fn($l) => $l['brand'] === $H)) === 3, 'hidden again: its leads are kept');
    ob_start();
    pm_view_hidden_businesses('tok123');
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'Hidden businesses') && str_contains($h, 'Hidden Hardware') && str_contains($h, 'brand_unhide') && str_contains($h, 'Bring it back') && str_contains($h, 'Delete for good') && str_contains($h, 'name="confirm_name"'), 'the hidden list offers both');
    pm_t_assert(str_contains($h, '3 leads') && str_contains($h, 'value="tok123"'), 'and says what deleting removes');
    $r = pm_brand_delete($H);
    pm_t_assert($r['ok'] && !isset(pm_brands_custom(true)[$H]) && !array_filter(pm_leads(), fn($l) => ($l['brand'] ?? '') === $H), 'a hidden business is deleted like any other');
    ob_start();
    pm_view_hidden_businesses('tok123');
    pm_t_eq(ob_get_clean(), '', 'with none hidden, nothing is drawn');
});

t('A business added again under the same name starts empty', function () use ($mkBiz, $json, $D) {
    $again = $mkBiz();
    pm_t_eq($again, 'sunrisesolar', 'the name is free again');
    pm_t_eq(count(array_filter(pm_leads(), fn($l) => $l['brand'] === $again)), 0, 'no old leads come back');
    pm_t_assert(!array_key_exists($again, $json('director')) && !isset($json('health_checks')["wa_$again"]) && !is_file("$D/link_$again.json") && !array_filter($json('social_posts'), fn($p) => $p['brand'] === $again), 'no old strategy, test results, link preview or posts');
    pm_t_eq($json('agents_config')[$again]['sectors'], ['schools', 'clinics'], 'its agent settings are the new ones');
});

t('A name that is also a setting or a data key never costs other data', function () use ($mkBiz, $json) {
    $cfg0 = $json('agents_config');
    $C = $mkBiz(['name' => 'Cities']);
    $R = $mkBiz(['name' => 'Rows']);
    pm_t_assert($C !== 'cities' && $R !== 'rows', "\"Cities\" and \"Rows\" get other ids ($C, $R), so they cannot overwrite ProManaged IT's own settings or a file's own keys");
    pm_t_eq($json('agents_config')['cities'], $cfg0['cities'], 'ProManaged IT\'s own list of cities is as it was');
    pm_t_assert(pm_brand_is_map('agents_config', ['cities' => ['Zomba']], 'cities') === false, 'and if such an id existed from before, its block is not mistaken for a setting');
    pm_t_assert(pm_brand_is_map('lead_offers', ['rows' => []], 'rows') === false && pm_brand_is_map('director', ['promanaged' => []], 'acme') === true && pm_brand_is_map('agents_config', [], 'acme') === true, 'a file that is not one block per business is not swept for blocks');
    pm_t_assert(pm_brand_delete($C, false)['ok'] && pm_brand_delete($R, false)['ok'], 'both can be deleted');
    pm_t_eq($json('agents_config')['cities'], $cfg0['cities'], 'still as it was');
});

t('The delete form asks for the name and offers the backup', function () use ($mkBiz) {
    $id = 'sunrisesolar';
    $f = pm_brand_delete_form($id, 'tok9');
    pm_t_assert(str_contains($f, 'name="action" value="brand_delete"') && str_contains($f, 'name="confirm_name"') && str_contains($f, 'name="keep_backup" value="1" checked') && str_contains($f, 'Sunrise Solar') && str_contains($f, 'value="tok9"'), 'a typed name, a backup box ticked by default, the CSRF token');
    pm_t_assert(str_contains($f, 'cannot be undone from the app') && str_contains($f, 'does not touch any other business'), 'and says what it does and does not do');
});

pm_t_done();
