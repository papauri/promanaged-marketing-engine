<?php
/**
 * Relearn a business (Brand > Relearn the business): any business already in the app (ProManaged IT, Travel Malawi or one added later) is studied again
 * with one press, in the background, by the agents together, and every change they would make waits for the owner to approve, edit or reject.
 *   Nia (Researcher)       reads the website again (up to five pages) and searches the web for the business's own pages, listings, reviews and news
 *                          (pm_brand_draft: one web search and one AI call, the same engine as adding a business; facts need a quote that is really there)
 *   Zola (Contact Finder)  compares every contact detail found (website, Facebook Page, WhatsApp Business profile) with what we have: missing or different
 *   Thandi (Social)        lists the business's pages found online and what the profiles still lack (the Profiles checklist, read by code)
 *   Amara (Director)       sums up what we may have missed and puts it in front of the owner
 * Proposals: contact details, the description and who buys, new offerings, new facts (with where each was found), online pages, kinds of buyer to look for,
 * who not to pitch, what to lead with, towns. The owner ticks what to approve, may edit any value, and presses Apply; nothing changes before. A rejected
 * proposal is remembered and not offered again. Gaps that need the owner (no opening hours anywhere, a profile without an email) are listed with where to fix them.
 * Data: data/relearn.json {brand: {state, started, beat, at, by, log[[who, text]], proposals{id: {...}}, gaps[[text, href]], questions[], note, decided{id: approved|rejected}, rejected{hash: date}}}.
 */

const PM_RL_GROUPS = ['contact' => 'Contact details', 'about' => 'What the business is', 'offer' => 'What it offers', 'facts' => 'Facts the writers may use', 'presence' => 'Pages about it online',
    'targeting' => 'Who to look for and how to approach them', 'towns' => 'Towns'];

function pm_rl_state(string $b, ?callable $fn = null): array
{
    if ($fn === null) {
        return (array)(pm_load('relearn', fn() => [])[$b] ?? []) + ['state' => ''];
    }
    $out = [];
    pm_update('relearn', function (array $all) use ($b, $fn, &$out) {
        $all[$b] = $out = $fn((array)($all[$b] ?? []));
        return $all;
    }, fn() => []);
    return $out;
}

/** A proposal's fingerprint, so a rejected one is never offered again. */
function pm_rl_hash(string $target, string $value): string
{
    return substr(md5($target . '|' . (function_exists('pm_study_norm') ? pm_study_norm($value) : mb_strtolower(trim($value)))), 0, 10);
}

/** Is the relearn of a business running now (a heartbeat in the last 10 minutes)? */
function pm_rl_busy(string $b): bool
{
    $st = pm_rl_state($b);
    return ($st['state'] ?? '') === 'running' && time() - (int)($st['beat'] ?? 0) < 600;
}

/** Starts the relearn in the background (the page answers at once). */
function pm_rl_start(string $b): bool
{
    if (!pm_brand_valid($b) || pm_rl_busy($b)) {
        return false;
    }
    $who = function_exists('pm_social_who') ? pm_social_who() : '';
    pm_rl_state($b, fn(array $s) => ['state' => 'running', 'started' => date('Y-m-d H:i:s'), 'beat' => time(), 'by' => $who ?: 'owner', 'log' => [], 'rejected' => (array)($s['rejected'] ?? [])]);
    if (PHP_SAPI === 'cli') {
        pm_rl_run($b);
        return true;
    }
    if (!pm_can_spawn()) {
        register_shutdown_function(function () use ($b) {
            pm_finish_response();
            pm_rl_run($b);
        });
        return true;
    }
    $script = __DIR__ . '/run_relearn.php';
    $php = pm_php_binary();
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" "' . $php . '" "' . $script . '" ' . $b . ' > NUL 2>&1', 'r'));
    } else {
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($b) . ' > /dev/null 2>&1 &');
    }
    return true;
}

/** One agent's line: into the relearn log and the agents' conversation. */
function pm_rl_say(string $b, string $who, string $text): void
{
    pm_rl_state($b, function (array $s) use ($who, $text) {
        $s['log'][] = [$who, $text];
        $s['beat'] = time();
        return $s;
    });
    function_exists('pm_talk') && pm_talk($b, $who, $text, $who === 'director' ? 'owner' : 'director');
}

/** The whole relearn: the agents' work, then the proposals and gaps saved for the owner. Never throws. */
function pm_rl_run(string $b): array
{
    $was = pm_brand();
    pm_brand_set($b);
    try {
        if (!function_exists('pm_opt_facts') && is_file(__DIR__ . '/social_modules.php')) {
            require_once __DIR__ . '/social_modules.php';
        }
        $GLOBALS['PM_AI_REF'] = 'relearn';
        $a = pm_study_answers_for($b);
        $site = pm_study_url((string)$a['website']);
        pm_rl_say($b, 'research', $site !== '' ? 'I am reading ' . pm_study_host($site) . ' again and searching the web for everything about ' . pm_brand_name($b) . '.' : 'There is no website saved, so I am searching the web for ' . pm_brand_name($b) . '.');
        $r = pm_brand_draft($a);
        $draft = (array)$r['draft'];
        $bundle = (array)$r['pages'];
        $read = array_values(array_filter(array_map(fn($p) => pm_study_host((string)($p['url'] ?? '')), (array)($bundle['pages'] ?? []))));
        pm_rl_say($b, 'research', 'I read ' . count($read) . ' page' . (count($read) === 1 ? '' : 's') . ($read ? ' (' . implode(', ', array_slice(array_unique($read), 0, 5)) . ')' : '') . ($r['ai'] ? ' and studied them.' : '; the AI could not study them, so only what code can check is used.') . ($r['note'] !== '' ? ' ' . $r['note'] : ''));
        $rejected = (array)(pm_rl_state($b)['rejected'] ?? []);
        $props = pm_rl_proposals($b, $draft, $bundle, $rejected, (bool)$r['ai']);
        $by = array_count_values(array_column($props, 'group'));
        $contacts = $by['contact'] ?? 0;
        pm_rl_say($b, 'contact', $contacts ? 'I found ' . $contacts . ' contact detail' . ($contacts === 1 ? '' : 's') . ' we are missing or that differ from what we have: ' . implode(', ', array_map(fn($p) => mb_strtolower($p['label']), array_filter($props, fn($p) => $p['group'] === 'contact'))) . '.' : 'Every contact detail I could find matches what we have.');
        $gaps = pm_rl_gaps($b);
        $pres = $by['presence'] ?? 0;
        pm_rl_say($b, 'social', ($pres ? 'I found ' . $pres . ' page' . ($pres === 1 ? '' : 's') . ' about us online that we have not saved. ' : 'No new pages about us online. ') . ($gaps ? count($gaps) . ' thing' . (count($gaps) === 1 ? '' : 's') . ' on our profiles still need' . (count($gaps) === 1 ? 's' : '') . ' you: ' . implode('; ', array_slice(array_column($gaps, 0), 0, 3)) . '.' : 'Our profiles have what they need.'));
        $sum = $props ? count($props) . ' change' . (count($props) === 1 ? '' : 's') . ' wait for your approval (' . implode(', ', array_map(fn($g, $n) => $n . ' ' . mb_strtolower(PM_RL_GROUPS[$g]), array_keys($by), $by)) . ').' : 'Nothing new to change.';
        pm_rl_say($b, 'director', 'We studied ' . pm_brand_name($b) . ' again. ' . $sum . (count($draft['questions'] ?? []) ? ' We also have ' . count($draft['questions']) . ' question' . (count($draft['questions']) === 1 ? '' : 's') . ' only you can answer.' : ''));
        $props && function_exists('pm_tb_post') && pm_tb_post($b, 'director', 'owner', 'We studied the business again: ' . $sum . ' Brand > Relearn the business.', 'relearn');
        return pm_rl_state($b, fn(array $s) => array_replace($s, ['state' => 'done', 'at' => date('Y-m-d H:i'), 'beat' => time(), 'proposals' => $props, 'gaps' => $gaps,
            'questions' => array_values((array)($draft['questions'] ?? [])), 'note' => (string)$r['note'], 'ai' => (bool)$r['ai'], 'decided' => []]));
    } catch (Throwable $e) {
        return pm_rl_state($b, fn(array $s) => array_replace($s, ['state' => 'failed', 'beat' => time(), 'error' => mb_substr($e->getMessage(), 0, 200)]));
    } finally {
        pm_brand_set($was);
    }
}

/** Every change the study suggests, against what is saved now: [id => [id, group, label, current, value, src, why, target, kind]]. Rejected ones are left out. */
function pm_rl_proposals(string $b, array $draft, array $bundle, array $rejected, bool $ai = true): array
{
    $raw = pm_load('settings', 'pm_default_settings');
    $blk = pm_brand_block($raw, $b);
    $brain = pm_brain($b);
    $cfg = pm_agents_config($b);
    $site = (string)(($bundle['pages'][0]['url'] ?? '') ?: ($bundle['url'] ?? 'the website'));
    $out = [];
    $add = function (string $group, string $label, string $target, string $current, string $value, string $src, string $why, string $kind = 'add') use (&$out, $rejected) {
        $value = trim($value);
        if ($value === '' || isset($rejected[pm_rl_hash($target, $value)])) {
            return;
        }
        $id = pm_rl_hash($target, $value);
        $out[$id] = ['id' => $id, 'group' => $group, 'label' => $label, 'current' => trim($current), 'value' => $value, 'src' => $src, 'why' => $why, 'target' => $target, 'kind' => $kind];
    };
    $digits = fn(string $v) => substr((string)preg_replace('/\D/', '', $v), -9);

    // contact details: from the website, the Facebook Page and the WhatsApp Business profile
    $seen = ['phone' => [], 'email' => [], 'website' => [], 'address' => []];
    foreach ((array)($bundle['phones'] ?? []) as $p) {
        $seen['phone'][] = [(string)$p, $site];
    }
    foreach ((array)($bundle['emails'] ?? []) as $e) {
        $seen['email'][] = [(string)$e, $site];
    }
    if (function_exists('pm_opt_fb_read') && pm_social_cfg($b)['ready']) {
        [$ok, $pg] = pm_opt_fb_read($b);
        if ($ok) {
            $fb = 'our Facebook Page';
            ($pg['phone'] ?? '') !== '' && $seen['phone'][] = [(string)$pg['phone'], $fb];
            foreach ((array)($pg['emails'] ?? []) as $e) {
                $seen['email'][] = [(string)$e, $fb];
            }
            ($pg['website'] ?? '') !== '' && $seen['website'][] = [(string)$pg['website'], $fb];
            $loc = (array)($pg['location'] ?? []);
            $street = trim(implode(', ', array_filter([(string)($loc['street'] ?? ''), (string)($loc['city'] ?? '')])));
            $street !== '' && $seen['address'][] = [$street, $fb];
        }
    }
    if (function_exists('pm_opt_wa_profile')) {
        [$ok, $wa] = pm_opt_wa_profile($b);
        if ($ok && is_array($wa)) {
            ($wa['email'] ?? '') !== '' && $seen['email'][] = [(string)$wa['email'], 'our WhatsApp Business profile'];
            ($wa['address'] ?? '') !== '' && $seen['address'][] = [(string)$wa['address'], 'our WhatsApp Business profile'];
            foreach ((array)($wa['websites'] ?? []) as $w) {
                $seen['website'][] = [(string)$w, 'our WhatsApp Business profile'];
            }
        }
    }
    $labels = ['phone' => 'Phone / WhatsApp number', 'email' => 'Business email', 'website' => 'Website', 'address' => 'Town and street'];
    foreach ($seen as $k => $list) {
        $cur = trim((string)($blk[$k] ?? ''));
        foreach ($list as [$v, $from]) {
            $v = trim($v);
            $same = match ($k) {
                'phone' => $cur !== '' && $digits($cur) === $digits($v),
                'email' => strcasecmp($cur, $v) === 0,
                'website' => $cur !== '' && pm_study_host(pm_study_url($cur)) === pm_study_host(pm_study_url($v)),
                default => $cur !== '' && pm_study_same($cur, $v),
            };
            if ($same || ($k === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) || ($k === 'phone' && strlen($digits($v)) < 9)) {
                continue;
            }
            $add('contact', $labels[$k], 'detail:' . $k, $cur, $v, $from, $cur === '' ? 'We do not have it yet.' : ucfirst($from) . ' shows a different one: check which is right before you approve.', $cur === '' ? 'add' : 'change');
            if ($cur === '') {
                break; // one suggestion to fill a gap is enough
            }
        }
    }
    if (trim((string)($blk['whatsapp'] ?? '')) === '' && trim((string)($blk['phone'] ?? '')) === '' && !empty($bundle['phones'])) {
        $add('contact', 'WhatsApp number', 'detail:whatsapp', '', (string)$bundle['phones'][0], $site, 'Customers on WhatsApp need a number to message.');
    }

    // what the business is
    foreach (['about' => 'What the business does and for whom', 'audience' => 'Who buys'] as $k => $label) {
        $new = trim((string)($draft[$k] ?? ''));
        $cur = trim((string)($brain[$k] ?? ''));
        if ($new !== '' && pm_study_norm($new) !== pm_study_norm($cur) && !pm_study_same($new, $cur)) {
            $add('about', $label, 'brain:' . $k, $cur, $new, 'the study of ' . pm_study_host($site ?: 'your answers'), 'Written from what the pages and your answers say. Approve it, edit it, or keep yours.', $cur === '' ? 'add' : 'change');
        }
    }
    if (trim((string)($blk['tagline'] ?? '')) === '' && function_exists('pm_opt_tagline_alts') && function_exists('pm_opt_facts')) {
        $t = (string)(pm_opt_tagline_alts(pm_opt_facts($b), [], [])[0]['text'] ?? '');
        $t !== '' && $add('about', 'One-line description', 'detail:tagline', '', $t, 'your own description', 'Most profiles show this line under the name.');
    }

    // what it offers: an offering the study found that is not in our list
    foreach ($ai ? (array)($draft['offerings'] ?? []) : [] as $o) { // without the AI the list is only our own words cut up: nothing new to offer
        $o = trim((string)$o);
        if ($o !== '' && !array_filter((array)$cfg['offerings'], fn($x) => pm_study_same((string)$x, $o) || pm_study_same(explode(':', (string)$x)[0], explode(':', $o)[0]))) {
            $add('offer', 'Something it offers', 'offering', '', $o, 'the study', 'Not in the list every agent and writer reads (Sales > Targeting and messages).');
        }
    }

    // facts, each with where it was found
    $known = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$brain['facts']) ?: [])));
    foreach (pm_study_diff($b, $draft)['facts'] as $f) { // only what a page says that we do not: our own lines come back cut up and are not new
        if (($f['src'] ?? 'you') === 'you' || array_filter($known, fn($k) => pm_study_same($k, (string)$f['text']))) {
            continue;
        }
        $add('facts', 'Fact', 'fact', '', (string)$f['text'], (string)$f['src'], 'Copied from this page and checked in code.');
    }

    // pages about it online
    $have = array_map(fn($p) => rtrim((string)($p['url'] ?? ''), '/'), (array)(((array)($blk['profile'] ?? []))['presence'] ?? []));
    foreach ((array)($draft['presence'] ?? []) as $p) {
        if (!in_array(rtrim((string)$p['url'], '/'), $have, true) && ($p['read'] ?? true) !== false) {
            $add('presence', ucfirst((string)($p['what'] ?: $p['platform'] ?: 'Page')), 'presence', '', (string)$p['url'], (string)($p['platform'] ?? ''), 'Saved pages are linked from the profiles and read by the agents.');
        }
    }

    // who to look for, who not to pitch, what to lead with, towns
    $diff = pm_study_diff($b, $draft);
    $words = fn(string $t) => array_unique(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($t)) ?: [], fn($w) => mb_strlen($w) >= 4 && !in_array($w, ['and', 'with', 'other'], true)));
    $searched = $words(implode(' ', (array)$cfg['sectors']));
    foreach ($diff['segments'] as $s) {
        $w = $words((string)$s['name']);
        if ($w && count(array_intersect($w, $searched)) * 2 >= count($w)) {
            continue; // mostly kinds we already search for ("hotels, lodges and resorts" when we look for "hotels and lodges")
        }
        $add('targeting', 'Kind of buyer to look for', 'segment', '', (string)$s['name'], 'the study', trim((string)($s['why'] ?? '')) ?: 'A kind of buyer the scouts do not search for yet.');
    }
    foreach ($diff['skip'] as $x) {
        $add('targeting', 'Not a fit (never pitched)', 'skip', '', (string)$x, 'the study', 'The agents skip these.');
    }
    foreach ($diff['angles'] as $x) {
        $add('targeting', 'What to lead with', 'angle', '', (string)$x, 'the study', 'The qualifier and writers lead with it.');
    }
    foreach ($diff['cities'] as $c) {
        $add('towns', 'Town to search in', 'city', '', (string)$c['name'], 'the study', trim((string)($c['why'] ?? '')) ?: 'Not in the towns we search yet.');
    }
    return $out;
}

/** What the profiles still lack that only the owner can fix: [[text, href]] (the Profiles checklist, read by code). */
function pm_rl_gaps(string $b): array
{
    if (!function_exists('pm_opt_run')) {
        return [];
    }
    $out = [];
    try {
        foreach (pm_opt_run($b, false)['channels'] as $ck => $c) {
            foreach ($c['items'] as $i) {
                if ($i['state'] === 'missing' && $i['weight'] >= 2 && !str_starts_with($i['key'], 'biz_')) {
                    $out[] = [$c['label'] . ': ' . lcfirst($i['title']) . ' is missing', '?tab=social&view=optimise&brand=' . rawurlencode($b) . '#c-' . $ck];
                }
            }
        }
    } catch (Throwable) {
    }
    return array_slice($out, 0, 12);
}

/** Writes one approved proposal into the business. */
function pm_rl_write(string $b, array $p, string $value): void
{
    $t = (string)$p['target'];
    if (str_starts_with($t, 'detail:') || str_starts_with($t, 'brain:') || $t === 'fact' || $t === 'presence' || in_array($t, ['skip', 'angle', 'segment'], true)) {
        pm_update('settings', function (array $s) use ($b, $t, $p, $value) {
            $blk = pm_brand_block($s, $b);
            $prof = (array)($blk['profile'] ?? []);
            if (str_starts_with($t, 'detail:')) {
                $blk[substr($t, 7)] = $value;
            } elseif (str_starts_with($t, 'brain:')) {
                $blk['brain'] = (array)($blk['brain'] ?? []) + pm_brain($b);
                $blk['brain'][substr($t, 6)] = $value;
            } elseif ($t === 'fact') {
                $blk['brain'] = (array)($blk['brain'] ?? []) + pm_brain($b);
                $facts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$blk['brain']['facts']) ?: [])));
                $facts[] = $value;
                $blk['brain']['facts'] = implode("\n", array_slice(array_values(array_unique($facts)), 0, 20));
            } elseif ($t === 'presence') {
                $prof['presence'][] = ['url' => $value, 'platform' => (string)$p['src'], 'what' => (string)$p['label']];
            } else {
                $tg = (array)($prof['targeting'] ?? []) + ['segments' => [], 'skip' => [], 'angles' => []];
                if ($t === 'segment') {
                    $tg['segments'][] = ['name' => $value, 'why' => (string)$p['why'], 'signals' => [], 'terms' => []];
                } else {
                    $tg[$t === 'skip' ? 'skip' : 'angles'][] = $value;
                }
                $prof['targeting'] = $tg;
            }
            $prof && $blk['profile'] = $prof;
            pm_brand_block_put($s, $b, $blk);
            return $s;
        }, 'pm_default_settings');
    }
    if (in_array($t, ['offering', 'segment', 'city'], true)) { // the agents' own lists
        $key = ['offering' => 'offerings', 'segment' => 'sectors', 'city' => 'cities'][$t];
        $list = array_values((array)(pm_agents_config($b)[$key] ?? []));
        $list[] = $value;
        pm_update('agents_config', function (array $c) use ($b, $key, $list) {
            if ($b === 'promanaged') {
                $c[$key] = $list;
            } else {
                $c[$b] = (array)($c[$b] ?? []);
                $c[$b][$key] = $list;
            }
            return $c;
        }, 'pm_agents_default_config');
    }
}

/**
 * The owner's decisions: $ok = ids approved, $vals = edited values, $reject = ids turned down. Returns a sentence. An approved value is written, a rejected one is
 * remembered (never offered again); the agents are told what changed.
 */
function pm_rl_decide(string $b, array $ok, array $vals, array $reject): string
{
    $st = pm_rl_state($b);
    $props = (array)($st['proposals'] ?? []);
    $done = [];
    $no = [];
    foreach ($props as $id => $p) {
        if (in_array($id, $ok, true)) {
            $v = trim(strip_tags((string)($vals[$id] ?? $p['value'])));
            if ($v === '') {
                continue;
            }
            if ($p['target'] === 'detail:email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            pm_rl_write($b, $p, mb_substr($v, 0, $p['group'] === 'about' ? 600 : 300));
            $done[$id] = $p;
        } elseif (in_array($id, $reject, true)) {
            $no[$id] = $p;
        }
    }
    pm_rl_state($b, function (array $s) use ($done, $no) {
        foreach ($done as $id => $p) {
            $s['decided'][$id] = 'approved';
            unset($s['proposals'][$id]);
        }
        foreach ($no as $id => $p) {
            $s['decided'][$id] = 'rejected';
            $s['rejected'][$id] = date('Y-m-d');
            unset($s['proposals'][$id]);
        }
        return $s;
    });
    if ($done) {
        $by = array_count_values(array_map(fn($p) => PM_RL_GROUPS[$p['group']], $done));
        $line = pm_brand_name($b) . ' was updated: ' . implode(', ', array_map(fn($g, $n) => $n . ' in ' . mb_strtolower($g), array_keys($by), $by)) . '.';
        function_exists('pm_tb_post') && pm_tb_post($b, 'director', 'all', $line . ' Use the new details from today.', 'relearn:applied');
        function_exists('pm_tb_take') && pm_tb_take($b, 'relearn');
        function_exists('pm_talk') && pm_talk($b, 'director', $line);
        function_exists('pm_brain_cache_clear') && pm_brain_cache_clear();
    }
    return ($done ? count($done) . ' change' . (count($done) === 1 ? '' : 's') . ' approved and saved' : 'Nothing was approved') . ($no ? ', ' . count($no) . ' turned down (they will not be suggested again)' : '') . '.';
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_do_rl_start(string $vb): array
{
    if (!pm_agents_ready()) {
        return ['msg' => 'The AI needs a key in .env first (Operations > Health and backups).', 'kind' => 'err', 'to' => 'social&view=relearn'];
    }
    $ok = pm_rl_start($vb);
    return ['msg' => $ok ? 'The team is studying ' . pm_brand_name($vb) . ' again. This takes a minute or two; this page fills in by itself.' : 'It is already being studied: wait for it to finish.', 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=relearn'];
}

function pm_do_rl_decide(string $vb): array
{
    $ids = fn(string $k) => array_values(array_filter(array_map(fn($x) => preg_replace('/[^a-f0-9]/', '', (string)$x), (array)($_POST[$k] ?? []))));
    $ok = $ids('ok');
    $reject = !empty($_POST['reject_rest']) ? array_values(array_diff(array_keys((array)(pm_rl_state($vb)['proposals'] ?? [])), $ok)) : $ids('no');
    $msg = pm_rl_decide($vb, $ok, array_map('strval', (array)($_POST['val'] ?? [])), $reject);
    return ['msg' => $msg, 'kind' => 'ok', 'to' => 'social&view=relearn'];
}
