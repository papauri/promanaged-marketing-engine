<?php
/**
 * Deleting a business that was added in the app (ProManaged IT and Travel Malawi are the app's two core businesses and cannot be deleted; an added one can be hidden,
 * brought back, or deleted for good). Deleting removes everything the business owns: its leads and archived leads, posts and their pictures, offers, WhatsApp
 * campaigns and templates, strategy, settings, mailbox details, agent settings, its entry in the registry, its logo and cached files. By default a backup of what was
 * removed is written first to data/deleted/<id>-<date>.json, and if the backup cannot be written nothing is deleted. Other businesses' data is never touched.
 */

/** Files that hold one block per business, keyed by the business id (others of that shape are recognised by having a block for ProManaged IT or Travel Malawi). */
const PM_BRAND_MAP_FILES = ['ads_plan', 'agents_config', 'autopilot', 'director', 'engage_playbook', 'fb_judged', 'page_audit', 'page_cleanup', 'send_caps', 'social_page', 'partners', 'social_channels', 'offer_ideas', 'followup_job'];

/** Files left alone by the sweep: the registry and settings are edited by hand below, usage counters and the shared template are not a business's. */
const PM_BRAND_PURGE_SKIP_FILES = ['brands', 'settings', 'ai_usage_daily', 'history', 'template', 'suggest_cache', 'health_checks'];

/** Does this file keep one block per business, and may the block of $id be taken out of it? (A key that is really a setting of ProManaged IT, such as "cities" in agents_config, is never one.) */
function pm_brand_is_map(string $file, array $data, string $id): bool
{
    if ($file === 'agents_config' && function_exists('pm_agents_default_config') && array_key_exists($id, pm_agents_default_config())) {
        return false;
    }
    if (in_array($file, PM_BRAND_MAP_FILES, true)) {
        return true;
    }
    return is_array($data['promanaged'] ?? null) || is_array($data['travel'] ?? null);
}

/**
 * Takes out of one decoded file what belongs to $id: its block (when the file keeps one per business) and every row that carries brand = $id, down two levels.
 * What was taken is collected in $removed. Lists stay lists.
 */
function pm_brand_strip(array $d, string $id, bool $topKey, array &$removed, int $depth = 2): array
{
    $list = array_is_list($d);
    if ($topKey && array_key_exists($id, $d)) {
        $removed[$id] = $d[$id];
        unset($d[$id]);
    }
    foreach ($d as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        if (($v['brand'] ?? null) === $id) {
            $removed[] = $v;
            unset($d[$k]);
        } elseif ($depth > 0 && !isset($v['brand']) && $v !== []) {
            $d[$k] = pm_brand_strip($v, $id, false, $removed, $depth - 1);
        }
    }
    return $list ? array_values($d) : $d;
}

/** Counts a removal for the screen: what kind of thing it was, by the file it came from. */
function pm_brand_purge_kind(string $file): string
{
    return ['leads' => 'leads', 'leads_archive' => 'archived', 'social_posts' => 'posts', 'social_posts_archive' => 'posts', 'lead_offers' => 'offers', 'wa_campaigns' => 'campaigns'][$file] ?? 'other';
}

/**
 * What deleting $id would remove (apply = false) or removes (apply = true). ['counts' => [...], 'bundle' => file => removed, 'files' => [paths], 'post_ids' => [...]].
 * Only ever called with the id of a business from data/brands.json.
 */
function pm_brand_purge(string $id, bool $apply): array
{
    $res = ['counts' => ['leads' => 0, 'archived' => 0, 'posts' => 0, 'offers' => 0, 'campaigns' => 0, 'other' => 0], 'bundle' => [], 'files' => [], 'post_ids' => []];
    if (!preg_match('/^[a-z][a-z0-9]{1,15}$/', $id) || isset(PM_BUILTIN_BRANDS[$id]) || !isset(pm_brands_custom(true)[$id])) { // only a business from the registry, never ProManaged IT or Travel Malawi
        return $res;
    }
    foreach (glob(PM_DATA . '/*.json') ?: [] as $path) {
        $name = basename($path, '.json');
        if (in_array($name, PM_BRAND_PURGE_SKIP_FILES, true) || str_starts_with($name, 'link_') || str_starts_with($name, 'agent_run')) {
            continue;
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            continue;
        }
        $removed = [];
        $topKey = pm_brand_is_map($name, $data, $id);
        pm_brand_strip($data, $id, $topKey, $removed);
        if (!$removed) {
            continue;
        }
        $res['bundle'][$name . '.json'] = $removed;
        $res['counts'][pm_brand_purge_kind($name)] += count($removed);
        foreach ($removed as $r) {
            if (is_array($r) && in_array($name, ['social_posts', 'social_posts_archive'], true) && !empty($r['id'])) {
                $res['post_ids'][] = (string)$r['id'];
            }
        }
        if ($apply) {
            pm_update($name, function (array $cur) use ($id, $name) {
                $gone = [];
                return pm_brand_strip($cur, $id, pm_brand_is_map($name, $cur, $id), $gone);
            }, fn() => []);
        }
    }
    // settings: the business's block and its WhatsApp templates; the search memory; the registry entry
    $raw = pm_load('settings', 'pm_default_settings');
    if (isset($raw['brands'][$id])) {
        $res['bundle']['settings.json']['brands'][$id] = $raw['brands'][$id];
        $res['counts']['other']++;
    }
    $tpl = array_values(array_filter((array)($raw['wa_templates'] ?? []), fn($t) => ($t['brand'] ?? '') === $id));
    if ($tpl) {
        $res['bundle']['settings.json']['wa_templates'] = $tpl;
    }
    $disk = fn(string $f) => (array)(is_file(PM_DATA . "/$f.json") ? json_decode((string)file_get_contents(PM_DATA . "/$f.json"), true) : []);
    $mine = array_filter($disk('suggest_cache'), fn($k) => str_starts_with((string)$k, $id . '|'), ARRAY_FILTER_USE_KEY); // the search memory of the "find a business" boxes
    if ($mine) {
        $res['bundle']['suggest_cache.json'] = $mine;
    }
    $ok = preg_quote($id, '/');
    $checks = array_filter($disk('health_checks'), fn($k) => preg_match('/^[a-z]+_' . $ok . '$/', (string)$k) === 1, ARRAY_FILTER_USE_KEY); // "wa_acme", "smtp_acme": its last connection tests
    if ($checks) {
        $res['bundle']['health_checks.json'] = $checks;
    }
    $reg = pm_brands_custom(true)[$id] ?? null;
    if ($reg) {
        $res['bundle']['brands.json'] = [$id => $reg];
    }
    if ($apply) {
        pm_update('settings', function (array $s) use ($id) {
            unset($s['brands'][$id]);
            $s['wa_templates'] = array_values(array_filter((array)($s['wa_templates'] ?? []), fn($t) => ($t['brand'] ?? '') !== $id));
            return $s;
        }, 'pm_default_settings');
        if ($mine) {
            pm_update('suggest_cache', fn(array $c) => array_diff_key($c, $mine), fn() => []);
        }
        if ($checks) {
            pm_update('health_checks', fn(array $c) => array_diff_key($c, $checks), fn() => []);
        }
        pm_update('brands', function (array $all) use ($id) {
            unset($all[$id]);
            return $all;
        }, fn() => []);
    }
    // files and folders that belong to it
    foreach ([PM_DATA . '/link_' . $id . '.json', PM_DATA . '/' . pm_run_state_file($id)] as $f) {
        is_file($f) && $res['files'][] = $f;
    }
    foreach (array_merge(glob(PM_DATA . '/thumbs/' . $id . '.*') ?: [], glob(PM_ROOT . '/assets/brand_' . $id . '_*') ?: []) as $f) {
        $res['files'][] = $f;
    }
    foreach (array_unique($res['post_ids']) as $pid) {
        foreach (preg_match('/^[a-f0-9]{20}$/', $pid) ? (glob(PM_DATA . '/social/' . $pid . '*') ?: []) : [] as $f) {
            $res['files'][] = $f;
        }
    }
    $kit = PM_DATA . '/brandkit/' . $id;
    if (is_dir($kit)) {
        $res['files'][] = $kit;
    }
    $res['counts']['other'] += count($res['files']);
    if ($apply) {
        foreach ($res['files'] as $f) {
            if (is_dir($f)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $x) {
                    $x->isDir() ? @rmdir($x->getPathname()) : @unlink($x->getPathname());
                }
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
    }
    return $res;
}

/** What deleting a business would remove, for the confirmation: ['leads','archived','posts','offers','campaigns','other']. */
function pm_brand_delete_plan(string $id): array
{
    return pm_brand_purge($id, false)['counts'];
}

/** Is an agent run going for this business right now? */
function pm_brand_run_busy(string $id): bool
{
    $f = PM_DATA . '/' . pm_run_state_file($id);
    $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($r) && ($r['state'] ?? '') === 'running' && time() - (int)($r['beat'] ?? 0) <= 900;
}

/**
 * Deletes a business that was added in the app, hidden or not. With $backup (the default) everything removed is first written to data/deleted/<id>-<date>.json, and
 * nothing is deleted if that fails. Returns ['ok' => bool, 'message' => string, 'counts' => [...], 'backup' => path or ''].
 */
function pm_brand_delete(string $id, bool $backup = true): array
{
    $row = pm_brands_custom(true)[$id] ?? null;
    if (!$row || isset(PM_BUILTIN_BRANDS[$id])) {
        return ['ok' => false, 'message' => 'Only a business you added can be deleted. ProManaged IT and Travel Malawi are the two core businesses.', 'counts' => [], 'backup' => ''];
    }
    $name = pm_brand_name($id);
    if (pm_brand_run_busy($id)) {
        return ['ok' => false, 'message' => "The agents are running for $name right now. Wait for the run to finish, then delete it.", 'counts' => [], 'backup' => ''];
    }
    $plan = pm_brand_purge($id, false);
    $path = '';
    if ($backup) {
        $dir = PM_DATA . '/deleted';
        $path = $dir . '/' . $id . '-' . date('Ymd-His') . '.json';
        $json = json_encode(['business' => $name, 'id' => $id, 'deleted_at' => date('Y-m-d H:i:s'), 'removed' => $plan['bundle'], 'files_removed' => array_map('basename', $plan['files'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || $json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
            return ['ok' => false, 'message' => "The backup of $name could not be written, so nothing was deleted. Untick the backup box to delete without one.", 'counts' => [], 'backup' => ''];
        }
    }
    $done = pm_brand_purge($id, true);
    return ['ok' => true, 'message' => $name . ' was deleted for good' . ($backup ? '. A backup of its data is in data/deleted/' . basename($path) : ' (no backup was kept)') . '.', 'counts' => $done['counts'], 'backup' => $path];
}

/** Brings a hidden business back into the menus. */
function pm_brand_unarchive(string $id): bool
{
    if (!isset(pm_brands_custom(true)[$id]) || isset(pm_brands_custom()[$id])) {
        return false;
    }
    pm_update('brands', function (array $all) use ($id) {
        unset($all[$id]['archived']);
        return $all;
    }, fn() => []);
    return true;
}

/** "3 leads, 2 posts and 12 other items": what deleting would remove, in words. */
function pm_brand_plan_words(array $c): string
{
    $bits = [];
    foreach (['leads' => 'lead', 'archived' => 'archived lead', 'posts' => 'post', 'offers' => 'offer', 'campaigns' => 'WhatsApp campaign'] as $k => $w) {
        if (!empty($c[$k])) {
            $bits[] = (int)$c[$k] . ' ' . $w . ((int)$c[$k] === 1 ? '' : 's');
        }
    }
    $bits[] = 'its settings, mailbox details and other saved items';
    return implode(', ', $bits);
}

/** The delete form for one business: what goes, a backup tick box, and the name typed to confirm. */
function pm_brand_delete_form(string $id, string $csrf): string
{
    $name = pm_brand_name($id);
    $c = pm_brand_delete_plan($id);
    return '<form method="post" onsubmit="return confirm(\'Delete ' . pm_h(addslashes($name)) . ' for good?\')"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="brand_delete"><input type="hidden" name="id" value="' . pm_h($id) . '">'
        . '<p class="hint" style="margin-top:0">This removes <b>' . pm_h(pm_brand_plan_words($c)) . '</b>. It does not touch any other business, and it cannot be undone from the app.</p>'
        . '<label class="check"><input type="checkbox" name="keep_backup" value="1" checked> Keep a backup file of its data first (in data/deleted/, so it can be restored by hand)</label>'
        . '<label>Type the business name to confirm: <b>' . pm_h($name) . '</b></label><input type="text" name="confirm_name" required autocomplete="off" placeholder="' . pm_h($name) . '">'
        . '<div class="btns"><button class="btn danger">Delete ' . pm_h($name) . ' for good</button></div></form>';
}

/** Settings > hidden businesses: bring one back, or delete it for good. Nothing is drawn when none is hidden. */
function pm_view_hidden_businesses(string $csrf): void
{
    $hid = array_filter(pm_brands_custom(true), fn($r) => !empty($r['archived']));
    if (!$hid) {
        return;
    }
    echo '<details class="card" id="hiddenbiz"><summary><b>Hidden businesses</b> <span class="muted">(' . count($hid) . ')</span></summary>'
        . '<p class="hint">A hidden business has no daily agent run and is not in the menus; its leads and posts are kept. Bring it back, or delete it for good.</p>';
    foreach ($hid as $id => $r) {
        $name = pm_brand_name((string)$id);
        echo '<div class="card" style="margin:8px 0"><div class="wahead"><div><b>' . pm_h($name) . '</b> <span class="muted">· hidden ' . pm_h(substr((string)$r['archived'], 0, 10)) . '</span></div>'
            . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="brand_unhide"><input type="hidden" name="id" value="' . pm_h((string)$id) . '"><button class="btn small">Bring it back</button></form></div>'
            . '<details class="more"><summary>Delete for good</summary>' . pm_brand_delete_form((string)$id, $csrf) . '</details></div>';
    }
    echo '</details>';
}
