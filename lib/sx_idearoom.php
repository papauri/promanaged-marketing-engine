<?php
/**
 * The idea room: post ideas for the idea bank, from four specialists in ONE AI call: the Educator (one useful thing the audience can do or know), the Storyteller (a small true moment from the facts),
 * the Proof-finder (turns a fact into evidence) and the Local watcher (a season, an occasion or a place in Malawi, only from the facts). The Idea editor (code, free) scores each idea 0 to 100: a
 * clear angle, something specific from the facts, not already in the bank or posted lately, no stock phrases; weak ones go back for one more round. The owner adds the ones they like to the idea
 * bank (Social > Content), where the planner can write posts from them. With no AI the room offers ideas built from the owner's own facts. Data: data/desks.json {brand: {ideas: {options, runs}}}.
 * Auto-loaded by social_modules.php.
 */

const PM_IR_THRESHOLD = 76;
const PM_IR_KEEP = 6;
const PM_IR_TO = 'social&view=content#ideas';
const PM_IR_SPECIALISTS = ['Educator' => 'one useful thing the audience can do or know, taught in a sentence', 'Storyteller' => 'a small, true moment from the facts, told plainly', 'Proof-finder' => 'a fact turned into evidence a customer can check',
    'Local watcher' => 'a season, an occasion or a place in Malawi that the facts connect to'];

function pm_ir_brief(string $brand): array
{
    $b = pm_cd_brief($brand);
    $bank = pm_bank_get($brand);
    $b['have'] = array_values(array_filter(array_map(fn($i) => trim((string)($i['text'] ?? '')), (array)$bank['ideas'])));
    $b['recent'] = array_values(array_filter(array_map(fn($p) => (string)($p['caption'] ?? ''), array_slice(array_reverse(array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand))), 0, 12))));
    return $b;
}

function pm_ir_prompt(array $brief, int $n, array $feedback, array $tried): array
{
    $f = $brief['f'];
    $sp = [];
    foreach (PM_IR_SPECIALISTS as $k => $v) {
        $sp[] = $k . ': ' . $v;
    }
    $system = pm_agents_company_brief('tiny') . "\nYou run the idea room for " . $f['name'] . ' (Malawi). Four specialists each give ' . max(1, intdiv($n, 4)) . ' ideas for social posts: ' . implode('; ', $sp) . '. '
        . 'An idea is one sentence (8 to 30 words) that a writer can turn into a post: a clear angle, something specific from the facts. Use ONLY the facts for what is true: no number, price, result or claim that is not in them; no hashtag, link or emoji. '
        . 'Make every idea different from the others and from the ones the business already has. ' . PM_HUMAN_RULES . ' An editor scores each idea and sends weak ones back. Reply JSON only.';
    $user = json_encode(['business' => $f['name'], 'about' => mb_substr($f['about'], 0, 400), 'audience' => mb_substr($f['audience'], 0, 200), 'facts' => array_slice($f['facts'], 0, 8), 'town' => $f['address_real'] ? $f['address'] : 'Malawi',
        'ideas_we_already_have' => array_slice($brief['have'], 0, 12), 'already_proposed' => array_slice($tried, 0, 10), 'editor_said' => $feedback], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"ideas\":[{\"by\":\"\",\"idea\":\"\"}]}";
    return [$system, $user];
}

function pm_ir_ask(string $brand, array $brief, int $n, array $feedback, array $tried): array
{
    [$sys, $usr] = pm_ir_prompt($brief, $n, $feedback, $tried);
    $out = pm_agent_json(pm_claude($sys, $usr, false, 120 * $n + 250, 'write'));
    $made = [];
    foreach (array_slice((array)(is_array($out) ? ($out['ideas'] ?? []) : []), 0, $n + 2) as $o) {
        if (!is_array($o)) {
            continue;
        }
        $t = pm_human_polish(pm_swarm_clean($o['idea'] ?? '', 240), 'post');
        if ($t === '' || !pm_opt_ai_ok($t, 240, $brief['source'])) {
            continue; // a claim or a number the facts do not give is never offered
        }
        $by = pm_swarm_clean($o['by'] ?? '', 20);
        $made[] = ['by' => isset(PM_IR_SPECIALISTS[$by]) ? $by : 'Idea room', 'text' => $t];
    }
    if (!$made) {
        throw new RuntimeException('The idea room did not return any usable idea.');
    }
    return $made;
}

/** No AI: ideas from the owner's own facts. */
function pm_ir_fallback(array $brief): array
{
    $made = [];
    foreach (array_slice($brief['f']['facts'], 0, 6) as $fact) {
        $fact = rtrim(trim((string)$fact), '.');
        if ($fact !== '') {
            $made[] = ['by' => 'Proof-finder', 'text' => 'Explain this in plain words for a customer: ' . $fact . '.', 'how' => 'code'];
        }
    }
    return $made;
}

function pm_ir_score(array $c, array $brief): array
{
    $score = 100;
    $issues = [];
    $praise = [];
    $t = (string)$c['text'];
    $n = count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
    if ($n < 6) {
        $score -= 15;
        $issues[] = 'Too short to write a post from.';
    } elseif ($n > 34) {
        $score -= 8;
        $issues[] = 'Too long for an idea: one sentence.';
    }
    $flags = pm_human_flags($t, 'post');
    if ($flags) {
        $score -= min(20, 10 * count($flags));
        $issues[] = 'It reads as machine-written (' . implode(', ', array_slice($flags, 0, 3)) . ').';
    }
    foreach (PM_BT_STOCK as $w) {
        if (stripos($t, $w) !== false) {
            $score -= 8;
            $issues[] = 'It uses a stock phrase ("' . $w . '").';
            break;
        }
    }
    $ws = pm_editor_words($t);
    if (count($ws) >= 3 && count(array_intersect($ws, $brief['factwords'])) / count($ws) < 0.2) {
        $score -= 14;
        $issues[] = 'It says little that is specific to this business.';
    } else {
        $praise[] = 'Specific to the business';
    }
    foreach ($brief['have'] as $h) {
        if (pm_swarm_alike($t, $h) >= 0.7) {
            $score -= 20;
            $issues[] = 'Too like an idea you already have.';
            break;
        }
    }
    foreach ($brief['recent'] as $r) {
        if ($ws && count(array_intersect($ws, pm_editor_words($r))) / count($ws) >= 0.6) {
            $score -= 8;
            $issues[] = 'A recent post already covers this.';
            break;
        }
    }
    if (preg_match('/^\s*(post about|share a post|talk about|write about)\b/i', $t)) {
        $score -= 8;
        $issues[] = 'It names a topic but no angle.';
    } elseif (!$issues) {
        $praise[] = 'A clear angle';
    }
    return ['score' => max(0, $score), 'issues' => $issues, 'praise' => array_values(array_unique($praise))];
}

function pm_ir_run(string $brand, string $by = 'owner'): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $t0 = function_exists('pm_usage_mark') ? pm_usage_mark() : 0;
        $brief = pm_ir_brief($brand);
        $res = pm_swarm_loop(['ai' => pm_agents_ready(), 'label' => 'ideas', 'threshold' => PM_IR_THRESHOLD, 'rounds' => 2, 'keep' => PM_IR_KEEP,
            'ask' => fn($round, $fb, $tried) => pm_ir_ask($brand, $brief, $round === 1 ? 8 : 6, $fb, $tried),
            'fallback' => fn($round, $tried) => pm_ir_fallback($brief),
            'score' => fn($c) => pm_ir_score($c, $brief),
            'key' => fn($c) => implode(' ', array_slice(pm_editor_words($c['text']), 0, 5)) ?: $c['text'],
            'note' => fn($c) => '"' . mb_substr($c['text'], 0, 60) . '"']);
        $opts = [];
        foreach ($res['kept'] as $c) {
            $opts[] = ['id' => substr(md5(mb_strtolower($c['text'])), 0, 12), 'by' => $c['by'], 'text' => $c['text'], 'score' => $c['score'], 'issues' => array_slice($c['issues'], 0, 2), 'praise' => array_slice($c['praise'], 0, 2),
                'round' => $c['round'], 'how' => $c['how'], 'at' => date('Y-m-d H:i'), 'status' => 'new'];
        }
        $res['log'][] = 'The Idea editor kept ' . count($opts) . ' for you to choose from.';
        $tokens = function_exists('pm_usage_mark') ? max(0, pm_usage_mark() - $t0) : 0;
        pm_swarm_run_record($brand, 'ideas', $opts, ['at' => date('Y-m-d H:i'), 'by' => $by, 'log' => $res['log'], 'calls' => $res['calls'], 'tokens' => $tokens, 'best' => $res['best'], 'how' => $res['how']]);
        $msg = 'The idea room made ' . count($opts) . ' idea' . (count($opts) === 1 ? '' : 's') . ' (best ' . $res['best'] . ' of 100) in ' . ($res['how'] === 'ai' ? $res['calls'] . ' AI call' . ($res['calls'] === 1 ? '' : 's') : 'code, no AI') . '.';
        pm_agent_log('Idea room', $msg);
        return [$msg, ['log' => $res['log'], 'options' => $opts, 'calls' => $res['calls'], 'tokens' => $tokens, 'best' => $res['best']]];
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- form handlers ---------------- */

function pm_do_ir_run(string $vb): array
{
    @set_time_limit(180);
    try {
        [$msg] = pm_ir_run($vb, 'owner');
    } catch (Throwable $e) {
        return ['msg' => 'The idea room could not make ideas: ' . mb_substr($e->getMessage(), 0, 160), 'kind' => 'err', 'to' => PM_IR_TO];
    }
    return ['msg' => $msg . ' Add the ones you like to your ideas.', 'kind' => 'ok', 'to' => PM_IR_TO];
}

function pm_do_ir_add(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $text = '';
    foreach ((array)(pm_swarm_store($vb)['ideas']['options'] ?? []) as $o) {
        if (($o['id'] ?? '') === $id) {
            $text = (string)$o['text'];
        }
    }
    if ($text === '') {
        return ['msg' => 'That idea is not there any more. Ask the idea room again.', 'kind' => 'err', 'to' => PM_IR_TO];
    }
    [$ok, $m] = pm_content_add_idea($vb, $text, '');
    if ($ok) {
        pm_swarm_mark($vb, 'ideas', $id, 'used');
    }
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => PM_IR_TO];
}

function pm_do_ir_dismiss(string $vb): array
{
    pm_swarm_mark($vb, 'ideas', preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')), 'dismissed');
    return ['msg' => 'Put aside.', 'kind' => 'ok', 'to' => PM_IR_TO];
}
