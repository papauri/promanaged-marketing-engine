<?php
/**
 * The copy desk: the writers' room for the cover wording (the headline and the supporting line on every cover, and the Facebook cover text). Four writers with four angles each write two options
 * in ONE AI call: the Benefit writer (what the customer gets), the Place writer (a sense of Malawi and where the business is), the Proof writer (a concrete fact it can back) and the Plain speaker
 * (the shortest honest version). The Copy editor (code, free) scores every option 0 to 100; weak ones go back for one more round; the owner chooses. The facts-only guard refuses any option that adds
 * a number, link or claim the facts do not give. With no AI the desk offers the owner's own words (tagline, first fact, about line) re-cut for a cover.
 * "Use this wording" sets the cover wording, rewrites the words of the design in use, and redraws every platform's pictures. Data: data/desks.json {brand: {wording: {options, runs}}}. Auto-loaded.
 */

const PM_CD_THRESHOLD = 80;
const PM_CD_KEEP = 4;
const PM_CD_WRITERS = ['Benefit writer' => 'what the customer gets, in plain words', 'Place writer' => 'a sense of Malawi and of where the business is, only from the facts',
    'Proof writer' => 'one concrete fact the business can back (free, direct, supported, how it works), only from the facts', 'Plain speaker' => 'the shortest honest version: how you would say it across a counter'];
const PM_CD_TO = 'social&view=kit#wording';

function pm_cd_brief(string $brand): array
{
    $f = pm_opt_facts($brand);
    $cfg = pm_social_settings($brand);
    $brain = pm_brain($brand);
    $saved = pm_studio_saved($brand);
    return ['f' => $f, 'name' => (string)$f['name'], 'voice' => trim((string)($brain['voice'] ?? '')), 'never' => trim((string)($brain['never'] ?? '')),
        'head' => $saved ? (string)$saved['headline'] : trim((string)$cfg['cover_head']), 'line' => $saved ? (string)$saved['subline'] : trim((string)$cfg['cover_line']),
        'source' => pm_studio_facts_source($brand), 'factwords' => pm_editor_words(implode(' ', array_merge([$f['name'], $f['tagline'], $f['about'], $f['audience']], $f['facts'])))];
}

/** [system, user] for one round. Pure: easy to test. */
function pm_cd_prompt(array $brief, int $n, array $feedback, array $tried): array
{
    $f = $brief['f'];
    $writers = [];
    foreach (PM_CD_WRITERS as $w => $angle) {
        $writers[] = $w . ': ' . $angle;
    }
    $system = pm_agents_company_brief('tiny') . "\nYou run the writers' room for " . $f['name'] . ' (Malawi). The cover of its social media pages has a headline (up to 60 characters) and one supporting line (up to 100 characters). '
        . 'Four writers each give ' . max(1, intdiv($n, 4)) . ' options with their own angle: ' . implode('; ', $writers) . '. Make every option clearly different from the others. A copy editor scores each option, and weak ones come back to you. '
        . (defined('PM_MARKETING_PSYCH') ? PM_MARKETING_PSYCH . ' ' : '')
        . 'Use ONLY what the facts say: no number, price, award, ranking, guarantee or claim that is not in them, no link, hashtag, emoji or exclamation mark, never "best" or "leading". '
        . PM_HUMAN_RULES . ($brief['voice'] !== '' ? ' The owner describes the voice as: ' . mb_substr($brief['voice'], 0, 160) . '.' : '') . ($brief['never'] !== '' ? ' Never say: ' . mb_substr($brief['never'], 0, 160) . '.' : '') . ' Reply JSON only.';
    $user = json_encode(['business' => $f['name'], 'tagline' => $f['tagline'], 'about' => mb_substr($f['about'], 0, 400), 'audience' => mb_substr($f['audience'], 0, 200), 'facts' => array_slice($f['facts'], 0, 8),
        'town' => $f['address_real'] ? $f['address'] : 'Malawi', 'wording_in_use' => ['headline' => $brief['head'], 'line' => $brief['line']], 'already_proposed' => array_slice($tried, 0, 10), 'editor_said' => $feedback], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"options\":[{\"writer\":\"\",\"headline\":\"\",\"line\":\"\",\"why\":\"one short sentence\"}]}";
    return [$system, $user];
}

/** One AI call: options [writer, headline, line, why]. Options that fail the facts-only guard are not offered. Throws when the AI cannot be reached. */
function pm_cd_ask(string $brand, array $brief, int $n, array $feedback, array $tried): array
{
    [$sys, $usr] = pm_cd_prompt($brief, $n, $feedback, $tried);
    $out = pm_agent_json(pm_claude($sys, $usr, false, 230 * $n + 250, 'write'));
    $made = [];
    foreach (array_slice((array)(is_array($out) ? ($out['options'] ?? []) : []), 0, $n + 2) as $o) {
        if (!is_array($o)) {
            continue;
        }
        $h = pm_human_polish(pm_swarm_clean($o['headline'] ?? '', 60), 'profile');
        $l = pm_human_polish(pm_swarm_clean($o['line'] ?? '', 100), 'profile');
        if ($h === '' || !pm_opt_ai_ok($h, 60, $brief['source']) || ($l !== '' && !pm_opt_ai_ok($l, 100, $brief['source']))) {
            continue; // a claim or a number the facts do not give is never offered
        }
        $w = pm_swarm_clean($o['writer'] ?? '', 30);
        $made[] = ['writer' => isset(PM_CD_WRITERS[$w]) ? $w : 'Writer', 'headline' => $h, 'line' => $l, 'why' => pm_human_polish(pm_swarm_clean($o['why'] ?? '', 140), 'profile')];
    }
    if (!$made) {
        throw new RuntimeException('The writers did not return any usable option.');
    }
    return $made;
}

/** No AI: the owner's own words, re-cut for a cover. */
function pm_cd_fallback(string $brand, array $brief): array
{
    $f = $brief['f'];
    $clip = fn(string $t, int $n) => mb_strlen($t) <= $n ? $t : rtrim(mb_substr($t, 0, $n - 1), " ,;:-") . '…';
    $firstSentence = fn(string $t) => trim(preg_split('/(?<=[.!?])\s+/u', trim($t))[0] ?? '');
    $facts = array_values(array_filter(array_map('trim', $f['facts'])));
    $cand = [];
    if ($f['tagline'] !== '') {
        $cand[] = [$f['tagline'], $facts[0] ?? $firstSentence($f['about'])];
    }
    if ($facts) {
        $cand[] = [$facts[0], $f['tagline'] !== '' ? $f['tagline'] : ($facts[1] ?? '')];
    }
    if ($f['about'] !== '') {
        $cand[] = [$firstSentence($f['about']), $facts[1] ?? ''];
    }
    $made = [];
    foreach ($cand as [$h, $l]) {
        $h = $clip(rtrim($h, '.'), 60);
        $l = $clip(rtrim($l, '.'), 100);
        if ($h !== '') {
            $made[] = ['writer' => 'Plain speaker', 'headline' => $h, 'line' => $l, 'why' => 'Your own words, cut to fit a cover.', 'how' => 'code'];
        }
    }
    return $made;
}

/** The copy editor: scores an option 0 to 100. Code only. */
function pm_cd_score(array $c, array $brief): array
{
    $score = 100;
    $issues = [];
    $praise = [];
    $h = (string)$c['headline'];
    $l = (string)$c['line'];
    $hw = count(preg_split('/\s+/u', $h, -1, PREG_SPLIT_NO_EMPTY));
    if (mb_strlen($h) > 60) {
        $score -= 8;
        $issues[] = 'The headline is long and may be cut on the cover.';
    }
    if ($hw < 3) {
        $score -= 10;
        $issues[] = 'The headline is too short to say anything.';
    } elseif ($hw > 11) {
        $score -= 6;
        $issues[] = 'The headline has too many words for a cover.';
    } else {
        $praise[] = 'Short and clear';
    }
    if ($l === '') {
        $score -= 4;
        $issues[] = 'There is no supporting line.';
    } elseif (mb_strlen($l) > 100) {
        $score -= 5;
        $issues[] = 'The supporting line is long.';
    }
    if (mb_strtolower($h) === mb_strtolower($brief['name'])) {
        $score -= 12;
        $issues[] = 'The headline only repeats the name: say what the business does.';
    }
    $flags = pm_human_flags($h . ' ' . $l, 'profile');
    if ($flags) {
        $score -= min(20, 10 * count($flags));
        $issues[] = 'The words read as machine-written (' . implode(', ', array_slice($flags, 0, 3)) . ').';
    }
    foreach (PM_BT_STOCK as $w) {
        if (stripos($h . ' ' . $l, $w) !== false) {
            $score -= 8;
            $issues[] = 'The words use a stock phrase ("' . $w . '").';
            break;
        }
    }
    if (preg_match('/^\s*(welcome to|your partner|experience the|discover the|unlock)\b/i', $h)) {
        $score -= 8;
        $issues[] = 'The headline opens with a stock opener.';
    }
    $ws = pm_editor_words($h . ' ' . $l);
    if (count($ws) >= 3 && count(array_intersect($ws, $brief['factwords'])) / count($ws) < 0.25) {
        $score -= 12;
        $issues[] = 'It says little that is specific to this business.';
    } else {
        $praise[] = 'Specific to the business';
    }
    if ($brief['head'] !== '' && pm_swarm_alike($h, $brief['head']) >= 0.8) {
        $score -= 15;
        $issues[] = 'The same as the wording already in use.';
    } else {
        $praise[] = 'Fresh wording';
    }
    if ($l !== '' && pm_swarm_alike($h, $l) >= 0.7) {
        $score -= 8;
        $issues[] = 'The supporting line repeats the headline.';
    }
    return ['score' => max(0, $score), 'issues' => $issues, 'praise' => array_values(array_unique($praise))];
}

function pm_cd_id(array $c): string
{
    return substr(md5(mb_strtolower($c['headline'] . '|' . $c['line'])), 0, 12);
}

/** One run of the desk. Returns [message, report]. Throws only when nothing could be made. */
function pm_cd_run(string $brand, string $by = 'owner'): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $t0 = function_exists('pm_usage_mark') ? pm_usage_mark() : 0;
        $brief = pm_cd_brief($brand);
        $res = pm_swarm_loop(['ai' => pm_agents_ready(), 'label' => 'options', 'threshold' => PM_CD_THRESHOLD, 'rounds' => 2, 'keep' => PM_CD_KEEP,
            'ask' => fn($round, $fb, $tried) => pm_cd_ask($brand, $brief, $round === 1 ? 8 : 6, $fb, $tried),
            'fallback' => fn($round, $tried) => pm_cd_fallback($brand, $brief),
            'score' => fn($c) => pm_cd_score($c, $brief),
            'key' => fn($c) => implode(' ', array_slice(pm_editor_words($c['headline']), 0, 4)) ?: $c['headline'],
            'note' => fn($c) => '"' . $c['headline'] . '"']);
        $opts = [];
        foreach ($res['kept'] as $c) {
            $opts[] = ['id' => pm_cd_id($c), 'writer' => $c['writer'], 'headline' => $c['headline'], 'line' => $c['line'], 'why' => $c['why'] ?? '', 'score' => $c['score'], 'issues' => array_slice($c['issues'], 0, 3),
                'praise' => array_slice($c['praise'], 0, 3), 'round' => $c['round'], 'how' => $c['how'], 'at' => date('Y-m-d H:i'), 'status' => 'new'];
        }
        $res['log'][] = 'The Copy editor kept ' . count($opts) . ' for you to choose from.';
        $tokens = function_exists('pm_usage_mark') ? max(0, pm_usage_mark() - $t0) : 0;
        pm_swarm_run_record($brand, 'wording', $opts, ['at' => date('Y-m-d H:i'), 'by' => $by, 'log' => $res['log'], 'calls' => $res['calls'], 'tokens' => $tokens, 'best' => $res['best'], 'how' => $res['how']]);
        $msg = 'The copy desk made ' . count($opts) . ' wording option' . (count($opts) === 1 ? '' : 's') . ' (best ' . $res['best'] . ' of 100) in ' . ($res['how'] === 'ai' ? $res['calls'] . ' AI call' . ($res['calls'] === 1 ? '' : 's') : 'code, no AI') . '.';
        pm_agent_log('Copy desk', $msg . ' Waiting for your choice.');
        return [$msg, ['log' => $res['log'], 'options' => $opts, 'calls' => $res['calls'], 'tokens' => $tokens, 'best' => $res['best']]];
    } finally {
        pm_brand_set($prev);
    }
}

/** Makes an option the cover wording everywhere: the settings, the words of the design in use, and the pictures. Returns [ok, message]. */
function pm_cd_use(string $brand, string $id): array
{
    $p = null;
    foreach ((array)(pm_swarm_store($brand)['wording']['options'] ?? []) as $q) {
        if (($q['id'] ?? '') === $id) {
            $p = $q;
        }
    }
    if (!$p) {
        return [false, 'That option is not there any more. Ask the writers again.'];
    }
    $cfg = pm_social_settings($brand);
    $cfg['cover_head'] = mb_substr((string)$p['headline'], 0, 80);
    $cfg['cover_line'] = mb_substr((string)$p['line'], 0, 90);
    pm_social_settings_save($brand, $cfg);
    $extra = '';
    if (pm_studio_saved($brand)) {
        pm_studio_update($brand, function (array $d) use ($p) {
            $d['design']['headline'] = mb_substr((string)$p['headline'], 0, 70);
            $d['design']['subline'] = mb_substr((string)$p['line'], 0, 110);
            return $d;
        });
        $extra = ' The design in use now says it too, and the kit pictures were drawn again.';
    }
    if (pm_studio_can_draw()) {
        pm_studio_redraw_kit($brand);
    }
    pm_swarm_mark($brand, 'wording', $id, 'used');
    return [true, 'The cover now says "' . $p['headline'] . '".' . $extra];
}

/* ---------------- form handlers ---------------- */

function pm_do_cd_run(string $vb): array
{
    @set_time_limit(180);
    try {
        [$msg] = pm_cd_run($vb, 'owner');
    } catch (Throwable $e) {
        return ['msg' => 'The copy desk could not make options: ' . mb_substr($e->getMessage(), 0, 160), 'kind' => 'err', 'to' => PM_CD_TO];
    }
    return ['msg' => $msg . ' Pick one below.', 'kind' => 'ok', 'to' => PM_CD_TO];
}

function pm_do_cd_use(string $vb): array
{
    [$ok, $m] = pm_cd_use($vb, preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => PM_CD_TO];
}

function pm_do_cd_dismiss(string $vb): array
{
    pm_swarm_mark($vb, 'wording', preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')), 'dismissed');
    return ['msg' => 'Put aside.', 'kind' => 'ok', 'to' => PM_CD_TO];
}
