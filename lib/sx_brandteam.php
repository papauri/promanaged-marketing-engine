<?php
/**
 * The brand team: a small swarm that makes whole brand kits (the cover and profile picture look, the words on them, and the profile words for the channels) and works in a loop, so the owner
 * chooses between good options instead of building from nothing.
 *   Researcher       (code)  reads the facts, the voice, the brand colour, the design in use and the looks used lately
 *   Creative director (AI)   proposes several different kits in ONE call (style, colours, texture, the words)
 *   Copy editor      (code)  holds the words to the human-voice rules and the facts-only guard
 *   Art critic       (code)  scores every kit 0 to 100: readable, short, human, not a repeat, close to the brand colour, suits the business
 *   Producer         (code)  draws the best three as drafts the page can preview
 *   Copywriter       (AI)    writes the profile words (three options per channel) when they are missing
 * If the best kit scores under the bar, the critics' notes go back to the Creative director for a second round (at most two rounds, so at most three AI calls a run). With no AI key the team still
 * varies the look in code. Nothing is applied until the owner presses "Use this kit"; the scheduler only makes proposals, about once a month. Data: data/brand_team.json
 * {brand: {proposals: [{id, name, why, score, issues, praise, round, how, at, status new|used|dismissed}], runs: [{at, by, log, calls, tokens, best}], auto: bool}}. Auto-loaded by social_modules.php.
 */

const PM_BT_THRESHOLD = 78;    // a kit at or above this score needs no second round
const PM_BT_ROUNDS = 2;
const PM_BT_KEEP = 3;          // kits shown to the owner
const PM_BT_STALE_DAYS = 21;   // an unlooked-at proposal this old no longer counts as waiting
const PM_BT_REFRESH_DAYS = 35; // the scheduler makes a fresh set this often when the owner has a design in use
const PM_BT_STOCK = ['solutions', 'innovative', 'passionate', 'tailored', 'world-class', 'premier', 'one-stop', 'state of the art', 'synergy', 'next-level', 'leading provider', 'cutting edge'];
const PM_BT_TO = 'social&view=kit#team';

function pm_bt_store(string $brand): array
{
    return (array)(pm_load('brand_team', fn() => [])[$brand] ?? []) + ['proposals' => [], 'runs' => [], 'auto' => true];
}

function pm_bt_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('brand_team', function (array $all) use ($brand, $fn, &$out) {
        $out = $fn((array)($all[$brand] ?? []) + ['proposals' => [], 'runs' => [], 'auto' => true]);
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

/** Proposals the owner has not looked at yet and that are not stale. */
function pm_bt_pending(string $brand): array
{
    $min = date('Y-m-d H:i', time() - PM_BT_STALE_DAYS * 86400);
    return array_values(array_filter((array)pm_bt_store($brand)['proposals'], fn($p) => ($p['status'] ?? '') === 'new' && (string)($p['at'] ?? '') >= $min));
}

/* ---------------- the researcher ---------------- */

function pm_bt_brief(string $brand): array
{
    $f = pm_opt_facts($brand);
    $brain = pm_brain($brand);
    $st = pm_studio_store($brand);
    $cur = pm_studio_saved($brand) ?: pm_studio_default($brand);
    $recent = [];
    foreach (array_slice((array)$st['history'], 0, 8) as $h) {
        $d = $st['drafts'][$h['id'] ?? ''] ?? null;
        if (is_array($d) && !empty($d['style'])) {
            $recent[] = (string)$d['style'];
        }
    }
    $about = ' ' . $f['name'] . ' ' . $f['about'] . ' ' . implode(' ', $f['facts']);
    return ['f' => $f, 'name' => (string)$f['name'], 'voice' => trim((string)($brain['voice'] ?? '')), 'never' => trim((string)($brain['never'] ?? '')), 'accent' => (string)(pm_settings()['accent_color'] ?? '#17375E'),
        'hero' => pm_hero_path($brand) !== '', 'current' => $cur, 'recent_styles' => array_values(array_unique($recent)), 'tech' => (bool)preg_match('/\b(software|websites?|digital|computers?|laptops?|IT|apps?|systems?|tech\w*|online)\b/i', $about),
        'source' => pm_studio_facts_source($brand)];
}

/* ---------------- the creative director ---------------- */

/** [system, user] for one round: $n different kits, with the critics' notes from the round before. Pure: easy to test. */
function pm_bt_prompt(array $brief, int $n, array $feedback, array $tried): array
{
    $f = $brief['f'];
    $system = pm_agents_company_brief('tiny') . "\nYou are the creative director of a small brand team for " . $f['name'] . ' (Malawi). Propose ' . $n . ' DIFFERENT complete kits for its social media profile picture and cover: each with its own style and its own colour family, '
        . 'and its own wording. Code draws them; you only choose. A copy editor and an art critic will score every kit, and weak ones come back to you. '
        . pm_studio_art_rules() . ($brief['voice'] !== '' ? ' The owner describes the voice as: ' . mb_substr($brief['voice'], 0, 160) . '.' : '') . ($brief['never'] !== '' ? ' Never say: ' . mb_substr($brief['never'], 0, 160) . '.' : '');
    $user = json_encode(['business' => $f['name'], 'tagline' => $f['tagline'], 'about' => mb_substr($f['about'], 0, 400), 'audience' => mb_substr($f['audience'], 0, 200), 'facts' => array_slice($f['facts'], 0, 6),
        'website' => $f['site'], 'phone' => $f['phone'], 'brand_colour' => $brief['accent'], 'styles' => PM_SD_STYLES, 'profile_styles' => PM_SD_PSTYLES, 'textures' => array_keys(PM_SD_TEXTURES), 'photo_available' => $brief['hero'],
        'in_use_now' => ['style' => $brief['current']['style'], 'headline' => $brief['current']['headline']], 'do_not_repeat_styles' => array_values(array_unique(array_merge($brief['recent_styles'], $tried))),
        'critics_said' => $feedback], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"kits\":[{\"name\":\"two or three words\",\"why\":\"one sentence\",\"style\":\"\",\"pstyle\":\"\",\"palette\":{\"bg1\":\"#\",\"bg2\":\"#\",\"accent\":\"#\",\"ink\":\"#\"},\"texture\":\"\",\"align\":\"left|center|right\",\"eyebrow\":\"\",\"headline\":\"\",\"subline\":\"\",\"whisper\":[],\"monogram\":\"\"}]}";
    return [$system, $user];
}

/** One AI call: kits as concepts [name, why, spec, softened, how]. Throws when the AI cannot be reached. */
function pm_bt_concepts(string $brand, array $brief, int $n, array $feedback, array $tried): array
{
    [$sys, $usr] = pm_bt_prompt($brief, $n, $feedback, $tried);
    $out = pm_agent_json(pm_claude($sys, $usr, false, 380 * $n + 250, 'write'));
    $list = is_array($out) ? (array)($out['kits'] ?? []) : [];
    $made = [];
    foreach (array_slice($list, 0, $n + 1) as $k) {
        if (!is_array($k) || empty($k['style'])) {
            continue;
        }
        [$spec, $softened] = pm_studio_accept($brand, $k, $brief['current']);
        $clean = fn($v, $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : ''))), 0, $max);
        $made[] = ['name' => $clean($k['name'] ?? '', 40) ?: (PM_SD_STYLES[$spec['style']] ?? 'Kit'), 'why' => pm_human_polish($clean($k['why'] ?? '', 160), 'profile'), 'spec' => $spec, 'softened' => $softened, 'how' => 'ai'];
    }
    if (!$made) {
        throw new RuntimeException('The AI did not return any kits.');
    }
    return $made;
}

/** No AI: the look varied in code (style, colours, texture), the wording unchanged. */
function pm_bt_concepts_code(string $brand, array $brief, int $n, array $tried): array
{
    $made = [];
    $seen = array_values(array_unique(array_merge($brief['recent_styles'], $tried, [$brief['current']['style']])));
    for ($i = 0; $i < $n * 4 && count($made) < $n; $i++) {
        $spec = pm_studio_shuffle($brand, $brief['current']);
        $key = $spec['style'] . '|' . $spec['bg1'];
        if (in_array($spec['style'], $seen, true) && $i < $n * 2) {
            continue; // prefer styles not used lately
        }
        if (isset($made[$key])) {
            continue;
        }
        $made[$key] = ['name' => (PM_SD_STYLES[$spec['style']] ?? 'Kit'), 'why' => 'Varied in code (style, colours and grain); the wording stays as it is.', 'spec' => $spec, 'softened' => false, 'how' => 'code'];
        $seen[] = $spec['style'];
    }
    return array_values($made);
}

/* ---------------- the copy editor and the art critic ---------------- */

/** Scores one kit 0 to 100 and says why: ['score', 'issues' => [what is wrong], 'praise' => [what is good]]. Code only. */
function pm_bt_critic(array $c, array $brief): array
{
    $spec = $c['spec'];
    $score = 100;
    $issues = [];
    $praise = [];
    $contrast = min(pm_sd_contrast(pm_sd_hex($spec['ink']), pm_sd_hex($spec['bg1'])), pm_sd_contrast(pm_sd_hex($spec['ink']), pm_sd_hex($spec['bg2'])));
    if ($contrast < 4.5) {
        $score -= 15;
        $issues[] = 'The writing is hard to read on the background (contrast ' . round($contrast, 1) . ' to 1).';
    } else {
        $praise[] = 'Easy to read';
    }
    $head = trim((string)$spec['headline']);
    $sub = trim((string)$spec['subline']);
    $hw = count(preg_split('/\s+/u', $head, -1, PREG_SPLIT_NO_EMPTY));
    if (mb_strlen($head) > 60) {
        $score -= 8;
        $issues[] = 'The headline is long and may be cut on the cover.';
    }
    if ($hw < 3) {
        $score -= 8;
        $issues[] = 'The headline is too short to say anything.';
    } elseif ($hw > 11) {
        $score -= 6;
        $issues[] = 'The headline has too many words for a cover.';
    }
    if ($sub === '') {
        $score -= 4;
        $issues[] = 'There is no supporting line.';
    } elseif (mb_strlen($sub) > 100) {
        $score -= 5;
        $issues[] = 'The supporting line is long.';
    }
    if (mb_strtolower($head) === mb_strtolower($brief['name']) || $head === '') {
        $score -= 10;
        $issues[] = 'The headline only repeats the name: say what the business does.';
    }
    $flags = pm_human_flags($head . ' ' . $sub, 'profile');
    if ($flags) {
        $score -= min(20, 10 * count($flags));
        $issues[] = 'The words read as machine-written (' . implode(', ', array_slice($flags, 0, 3)) . ').';
    }
    foreach (PM_BT_STOCK as $w) {
        if (stripos($head . ' ' . $sub, $w) !== false) {
            $score -= 8;
            $issues[] = 'The words use a stock phrase ("' . $w . '").';
            break;
        }
    }
    if (!empty($spec['_dropped'])) {
        $score -= 12;
        $issues[] = 'Some of its words were refused because they added a claim or a number (' . implode(', ', (array)$spec['_dropped']) . ').';
    }
    if (!empty($c['softened'])) {
        $score -= 6;
        $issues[] = 'The colours had to be softened to look natural: choose earthy ones.';
    }
    if (in_array($spec['style'], PM_SD_DIGITAL, true) && !$brief['tech']) {
        $score -= 10;
        $issues[] = 'A glowing, digital look does not suit this business.';
    }
    $cur = $brief['current'];
    if ($spec['style'] === ($cur['style'] ?? '')) {
        $score -= 12;
        $issues[] = 'The same style as the one already in use.';
    } elseif (in_array($spec['style'], $brief['recent_styles'], true)) {
        $score -= 6;
        $issues[] = 'A style used recently.';
    } else {
        $praise[] = 'A fresh style';
    }
    if (strtolower($spec['bg1']) === strtolower((string)($cur['bg1'] ?? ''))) {
        $score -= 6;
        $issues[] = 'The same background colour as the one in use.';
    }
    $brandHue = pm_sd_hsl(pm_sd_hex($brief['accent']))[0];
    $near = 360;
    foreach (['accent', 'bg1', 'bg2', 'ink'] as $k) { // something in the kit should tie back to the brand colour (a navy ink on clay and paper does)
        [$h, $s] = pm_sd_hsl(pm_sd_hex($spec[$k]));
        if ($s < 0.12) {
            continue; // a neutral says nothing either way
        }
        $d = abs($h - $brandHue);
        $near = min($near, min($d, 360 - $d));
    }
    if ($near > 70) {
        $score -= 4;
        $issues[] = 'Nothing in it ties back to the brand colour.';
    } else {
        $praise[] = 'Ties back to the brand colour';
    }
    if (!$flags && !array_filter($issues, fn($i) => str_starts_with($i, 'The words') || str_starts_with($i, 'The headline'))) {
        $praise[] = 'Words read naturally';
    }
    return ['score' => max(0, $score), 'issues' => $issues, 'praise' => array_values(array_unique($praise))];
}

/* ---------------- the loop ---------------- */

/**
 * One run of the team. Returns [message, report]; report = [log, proposals, calls, tokens, best]. Throws only when nothing at all could be made.
 * $withWords: the Copywriter also writes the profile words that are missing (one more AI call).
 */
function pm_bt_run(string $brand, bool $withWords = true, string $by = 'owner'): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        if (!pm_studio_can_draw()) {
            throw new RuntimeException('This server cannot draw pictures (GD with FreeType and the fonts are needed: see Operations > Connections).');
        }
        $t0 = function_exists('pm_usage_mark') ? pm_usage_mark() : 0;
        $brief = pm_bt_brief($brand);
        $ai = pm_agents_ready();
        $log = [];
        $all = [];
        $tried = [];
        $feedback = [];
        $calls = 0;
        $best = 0;
        for ($round = 1; $round <= PM_BT_ROUNDS; $round++) {
            $n = $round === 1 ? 4 : 3;
            try {
                $made = $ai ? pm_bt_concepts($brand, $brief, $n, $feedback, $tried) : pm_bt_concepts_code($brand, $brief, $n, $tried);
                $calls += $ai ? 1 : 0;
            } catch (Throwable $e) {
                if ($round > 1) {
                    $log[] = 'Round ' . $round . ' could not run (' . mb_substr($e->getMessage(), 0, 90) . '): the first round stands.';
                    break;
                }
                $log[] = 'The AI could not be reached (' . mb_substr($e->getMessage(), 0, 90) . '): the team varied the look in code instead.';
                $ai = false;
                $made = pm_bt_concepts_code($brand, $brief, $n, $tried);
            }
            $roundBest = 0;
            foreach ($made as $c) {
                $c += pm_bt_critic($c, $brief);
                $c['round'] = $round;
                $all[] = $c;
                $tried[] = (string)$c['spec']['style'];
                $roundBest = max($roundBest, $c['score']);
            }
            $best = max($best, $roundBest);
            $log[] = 'Round ' . $round . ': ' . count($made) . ' kit' . (count($made) === 1 ? '' : 's') . ($ai ? ' from the Creative director' : ' made in code') . ', best ' . $roundBest . ' of 100.';
            if (!$ai || $roundBest >= PM_BT_THRESHOLD || $round === PM_BT_ROUNDS) {
                break;
            }
            usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
            $feedback = [];
            foreach (array_slice($all, 0, 2) as $c) {
                $feedback[] = $c['name'] . ': ' . implode(' ', array_slice($c['issues'], 0, 3));
            }
            $log[] = 'Sent back for another round with the critics\' notes.';
        }
        if (!$all) {
            throw new RuntimeException('The team could not make any kit.');
        }
        usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
        $keep = [];
        $seen = [];
        foreach ($all as $c) {
            $key = $c['spec']['style'] . '|' . $c['spec']['bg1'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $keep[] = $c;
            if (count($keep) >= PM_BT_KEEP) {
                break;
            }
        }
        $props = [];
        foreach ($keep as $c) {
            $id = pm_studio_put($brand, $c['spec'], 'team');
            $props[] = ['id' => $id, 'name' => $c['name'], 'why' => $c['why'], 'score' => $c['score'], 'issues' => array_slice($c['issues'], 0, 4), 'praise' => array_slice($c['praise'], 0, 4), 'round' => $c['round'], 'how' => $c['how'],
                'headline' => (string)$c['spec']['headline'], 'subline' => (string)$c['spec']['subline'], 'at' => date('Y-m-d H:i'), 'status' => 'new'];
        }
        $log[] = 'The Producer drew ' . count($props) . ' for you to look at; the best scores ' . $props[0]['score'] . '.';
        $words = '';
        if ($withWords && $ai) {
            $store = pm_copy_store($brand);
            $need = array_values(array_filter(['fb_about', 'fb_description', 'ig_bio', 'wa_about', 'wa_description'], fn($k) => empty($store[$k]['options'])));
            if ($need) {
                try {
                    [$nw, $mw] = pm_copy_write($brand, $need);
                    $calls++;
                    $words = $nw . ' profile text' . ($nw === 1 ? '' : 's') . ' written';
                    $log[] = 'The Copywriter wrote the missing profile words (three options each): see Brand > Profiles.';
                } catch (Throwable $e) {
                    $log[] = 'The Copywriter could not write the profile words (' . mb_substr($e->getMessage(), 0, 90) . ').';
                }
            }
        }
        $tokens = function_exists('pm_usage_mark') ? max(0, pm_usage_mark() - $t0) : 0;
        $run = ['at' => date('Y-m-d H:i'), 'by' => $by, 'log' => $log, 'calls' => $calls, 'tokens' => $tokens, 'best' => $props[0]['score'], 'how' => $ai ? 'ai' : 'code'];
        pm_bt_update($brand, function (array $d) use ($props, $run) {
            $old = array_values(array_filter((array)$d['proposals'], fn($p) => ($p['status'] ?? '') !== 'new' || (string)($p['at'] ?? '') >= date('Y-m-d H:i', time() - PM_BT_STALE_DAYS * 86400)));
            foreach ($old as $k => $p) { // an older set that was never looked at is replaced by the new one
                if (($p['status'] ?? '') === 'new') {
                    $old[$k]['status'] = 'dismissed';
                }
            }
            $d['proposals'] = array_slice(array_merge($props, $old), 0, 9);
            $d['runs'] = array_slice(array_merge([$run], (array)$d['runs']), 0, 10);
            return $d;
        });
        $msg = 'The brand team made ' . count($props) . ' kit' . (count($props) === 1 ? '' : 's') . ' (best ' . $props[0]['score'] . ' of 100) in ' . ($ai ? $calls . ' AI call' . ($calls === 1 ? '' : 's') : 'code, no AI') . ($words !== '' ? '; ' . $words : '') . '.';
        pm_agent_log('Brand team', $msg . ' Waiting for your choice.');
        return [$msg, ['log' => $log, 'proposals' => $props, 'calls' => $calls, 'tokens' => $tokens, 'best' => $props[0]['score']]];
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- the owner chooses ---------------- */

/** Makes a proposed kit the design (and the cover wording) for every platform. Returns [ok, message]. */
function pm_bt_use(string $brand, string $id): array
{
    $p = null;
    foreach ((array)pm_bt_store($brand)['proposals'] as $q) {
        if (($q['id'] ?? '') === $id) {
            $p = $q;
        }
    }
    if (!$p || !isset(pm_studio_store($brand)['drafts'][$id])) {
        return [false, 'That kit is not there any more. Ask the team for new ones.'];
    }
    [$ok, $m] = pm_studio_use($brand, $id);
    if (!$ok) {
        return [false, $m];
    }
    $spec = pm_studio_get($brand, $id);
    $cfg = pm_social_settings($brand);
    $cfg['cover_head'] = mb_substr((string)$spec['headline'], 0, 80);
    $cfg['cover_line'] = mb_substr((string)$spec['subline'], 0, 90);
    pm_social_settings_save($brand, $cfg);
    pm_bt_update($brand, function (array $d) use ($id) {
        foreach ($d['proposals'] as $k => $q) {
            if (($q['id'] ?? '') === $id) {
                $d['proposals'][$k]['status'] = 'used';
            }
        }
        return $d;
    });
    return [true, '"' . $p['name'] . '" is now the design and the cover wording for every profile picture and cover. ' . $m];
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_do_bt_run(string $vb): array
{
    @set_time_limit(240);
    try {
        [$msg] = pm_bt_run($vb, !empty($_POST['words']), 'owner');
    } catch (Throwable $e) {
        return ['msg' => 'The brand team could not make kits: ' . mb_substr($e->getMessage(), 0, 160), 'kind' => 'err', 'to' => PM_BT_TO];
    }
    return ['msg' => $msg . ' Look at them below, then press Use this kit on the one you like.', 'kind' => 'ok', 'to' => PM_BT_TO];
}

function pm_do_bt_use(string $vb): array
{
    [$ok, $m] = pm_bt_use($vb, preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => PM_BT_TO];
}

function pm_do_bt_dismiss(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    pm_bt_update($vb, function (array $d) use ($id) {
        foreach ($d['proposals'] as $k => $q) {
            if (($q['id'] ?? '') === $id && ($q['status'] ?? '') === 'new') {
                $d['proposals'][$k]['status'] = 'dismissed';
            }
        }
        return $d;
    });
    return ['msg' => 'Put aside.', 'kind' => 'ok', 'to' => PM_BT_TO];
}

function pm_do_bt_auto(string $vb): array
{
    $on = !empty($_POST['on']);
    pm_bt_update($vb, function (array $d) use ($on) {
        $d['auto'] = $on;
        return $d;
    });
    return ['msg' => $on ? 'The team will make a fresh set of kits about once a month. It only proposes: nothing changes until you press Use this kit.' : 'The team will only work when you ask.', 'kind' => 'ok', 'to' => PM_BT_TO];
}

/* ---------------- the scheduler ---------------- */

/** Once a month, when there is nothing waiting: a fresh set of kits for the owner to look at. Never applies anything. */
function pm_job_brandteam(string $brand): string
{
    if (!pm_agents_ready() || !pm_studio_can_draw()) {
        return '';
    }
    $st = pm_bt_store($brand);
    if (empty($st['auto']) || pm_bt_pending($brand)) {
        return '';
    }
    $last = (string)($st['runs'][0]['at'] ?? '');
    $age = $last === '' ? 9999 : intdiv(max(0, time() - (int)strtotime($last)), 86400);
    if ($age < (pm_studio_saved($brand) ? PM_BT_REFRESH_DAYS : 7)) {
        return '';
    }
    try {
        return pm_bt_run($brand, true, 'scheduler')[0];
    } catch (Throwable $e) {
        pm_bt_update($brand, function (array $d) use ($e) {
            array_unshift($d['runs'], ['at' => date('Y-m-d H:i'), 'by' => 'scheduler', 'log' => ['Could not run: ' . mb_substr($e->getMessage(), 0, 120)], 'calls' => 0, 'tokens' => 0, 'best' => 0, 'how' => 'error']);
            $d['runs'] = array_slice($d['runs'], 0, 10);
            return $d;
        });
        return 'brand team could not run: ' . mb_substr($e->getMessage(), 0, 100);
    }
}

function pm_today_brandteam(string $brand): array
{
    $n = count(pm_bt_pending($brand));
    return $n ? [['The brand team has ' . $n . ' new kit' . ($n === 1 ? '' : 's') . ' for you to look at', '?tab=social&view=kit#team', 0]] : [];
}
