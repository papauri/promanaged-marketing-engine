<?php
/**
 * The editor: a second agent that reads the posts the planner's AI wrote before they reach the owner. The code part (free) scores each caption 0 to 100 for what makes a post read as written by a
 * machine or by a person who did not look at the facts: length, stock phrases and openers, the same opening as another post in the batch or a recent one, saying nothing from the facts, lists of
 * three, very long sentences. Posts under the bar are sent back, all together, in ONE more AI call (the copy chief) with the exact problems; each rewrite must pass the same guards (no new number,
 * link or claim) and must score clearly better, or the original stays. A batch whose posts all pass costs nothing extra. Nothing is published by this: posts still wait for approval.
 * Setting: the Plan's Publishing rules ("Have an editor review the posts", on by default). Each reviewed post carries ['editor' => [score, was, fixed, issues]]. Auto-loaded by social_modules.php.
 */

const PM_ED_THRESHOLD = 75;

const PM_ED_STOP = ['that', 'this', 'with', 'from', 'your', 'have', 'will', 'what', 'when', 'where', 'they', 'their', 'there', 'into', 'about', 'more', 'than', 'then', 'them', 'been', 'were', 'which', 'would', 'could', 'should', 'also', 'just', 'like', 'make', 'made', 'much', 'many', 'some', 'only', 'every', 'each', 'does', 'doing'];

function pm_editor_on(string $brand): bool
{
    return (bool)(pm_social_settings($brand)['editor_review'] ?? true);
}

/** The first three words of a caption, lower case: what makes two posts "open the same way". */
function pm_editor_opening(string $caption): string
{
    $w = preg_split('/\s+/u', mb_strtolower(trim((string)preg_replace('/[^\p{L}\p{N}\s\']/u', ' ', $caption))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return implode(' ', array_slice($w, 0, 3));
}

/** The words that carry meaning (four letters or more, not filler), lower case. */
function pm_editor_words(string $t): array
{
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) >= 4 && !in_array($x, PM_ED_STOP, true))));
}

/**
 * Scores one caption: [score 0..100, problems[]]. $slot is the planner's slot (its seed holds the facts), $others the openings of the other posts in the batch, $recent the openings of recent posts.
 */
function pm_editor_score(string $caption, array $slot, array $others, array $recent): array
{
    $score = 100;
    $issues = [];
    $n = count(preg_split('/\s+/u', trim($caption), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    if ($n < 25 || $n > 70) {
        $score -= 12;
        $issues[] = 'It is ' . $n . ' words: keep it between 25 and 70.';
    }
    $flags = pm_human_flags($caption, 'post');
    if ($flags) {
        $score -= min(20, 10 * count($flags));
        $issues[] = 'It reads as machine-written (' . implode(', ', array_slice($flags, 0, 3)) . ').';
    }
    if (preg_match('/^\s*(are you|do you|looking for|have you ever|imagine|in today|did you know|ever wondered|what if|picture this|welcome to)\b/i', $caption)) {
        $score -= 10;
        $issues[] = 'It opens with a stock opener: start with the specific thing instead.';
    }
    $open = pm_editor_opening($caption);
    if ($open !== '' && in_array($open, $others, true)) {
        $score -= 12;
        $issues[] = 'Another post in this batch opens with the same words ("' . $open . '").';
    } elseif ($open !== '' && in_array($open, $recent, true)) {
        $score -= 8;
        $issues[] = 'A recent post opened with the same words ("' . $open . '").';
    }
    $fw = pm_editor_words(implode(' ', array_map('strval', (array)($slot['seed']['facts'] ?? []))));
    if (count($fw) >= 4 && count(array_intersect(pm_editor_words($caption), $fw)) / min(8, count($fw)) < 0.15) {
        $score -= 15;
        $issues[] = 'It says nothing specific from the facts: use one concrete detail from them.';
    }
    if (preg_match_all('/\b[\p{L}\- ]{3,24},\s[\p{L}\- ]{3,24},?\s(?:and|or)\s[\p{L}\- ]{3,24}\b/u', $caption) >= 2) {
        $score -= 6;
        $issues[] = 'Two lists of three in one post: say one thing.';
    }
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($caption), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($sentences && $n / count($sentences) > 24) {
        $score -= 6;
        $issues[] = 'The sentences are long: shorten them.';
    }
    return [max(0, $score), $issues];
}

/** The openings of this business's recent posts. */
function pm_editor_recent(string $brand, int $n = 12): array
{
    $posts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && trim((string)($p['caption'] ?? '')) !== ''));
    usort($posts, fn($a, $b) => strcmp((string)($b['when'] ?? ''), (string)($a['when'] ?? '')));
    return array_values(array_filter(array_map(fn($p) => pm_editor_opening((string)$p['caption']), array_slice($posts, 0, $n))));
}

/**
 * Reviews the captions the AI wrote. $copies: slot index => copy (caption, headline ...), $slots and $aiIdx as the planner has them. Returns [copies (rewritten where the editor did better), report],
 * report = slot index => [score, was, fixed, issues]. One extra AI call at most, and only when a caption scores under the bar. Throws only when the AI call fails (the caller keeps the originals).
 */
function pm_editor_review(string $brand, array $slots, array $copies, array $aiIdx): array
{
    $recent = pm_editor_recent($brand);
    $open = [];
    foreach ($copies as $i => $c) {
        $open[$i] = pm_editor_opening((string)$c['caption']);
    }
    $report = [];
    $weak = [];
    foreach ($copies as $i => $c) {
        [$sc, $iss] = pm_editor_score((string)$c['caption'], $slots[$i], array_values(array_diff_key($open, [$i => 1])), $recent);
        $report[$i] = ['score' => $sc, 'was' => $sc, 'fixed' => false, 'issues' => array_slice($iss, 0, 3)];
        if ($sc < PM_ED_THRESHOLD) {
            $weak[$i] = $iss;
        }
    }
    if (!$weak) {
        return [$copies, $report];
    }
    $f = pm_opt_facts($brand);
    $voice = trim((string)(pm_brain($brand)['voice'] ?? ''));
    $system = pm_agents_company_brief('tiny') . "\nYou are the copy chief for " . $f['name'] . ' (Malawi). An editor flagged the social posts below. Rewrite ONLY those posts to fix the listed problems. Keep every fact and the call to action; '
        . 'add no number, name, place, price, link, hashtag or emoji that is not already in the post or its facts; 25 to 70 words, plain warm English, one idea; give every post a different opening, and none of the recent openings. '
        . PM_HUMAN_RULES . ($voice !== '' ? ' The owner describes the voice as: ' . mb_substr($voice, 0, 140) . '.' : '') . ' Reply JSON only.';
    $items = [];
    foreach ($weak as $i => $iss) {
        $items[] = ['i' => $i, 'caption' => $copies[$i]['caption'], 'problems' => array_slice($iss, 0, 4), 'facts' => array_slice((array)($slots[$i]['seed']['facts'] ?? []), 0, 5), 'cta' => (string)($slots[$i]['cta'] ?? '')];
    }
    $user = json_encode(['posts' => $items, 'recent_openings' => array_slice($recent, 0, 8), 'other_openings' => array_values($open)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nJSON: {\"edits\":[{\"i\":0,\"caption\":\"\"}]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 300 + 230 * count($items), 'write'));
    foreach ((array)(is_array($out) ? ($out['edits'] ?? []) : []) as $e) {
        if (!is_array($e) || !isset($e['i']) || !isset($weak[(int)$e['i']])) {
            continue;
        }
        $i = (int)$e['i'];
        $new = pm_human_polish(pm_sx_clean_ai((string)($e['caption'] ?? '')), 'post');
        $source = $copies[$i]['caption'] . ' ' . implode(' ', array_map('strval', (array)($slots[$i]['seed']['facts'] ?? []))) . ' ' . implode(' ', $f['facts']) . ' ' . $f['name'] . ' ' . $f['tagline'] . ' ' . $f['about'];
        if ($new === '' || !pm_opt_ai_ok($new, 900, $source)) {
            continue;
        }
        [$sc, $iss] = pm_editor_score($new, $slots[$i], array_values(array_diff_key($open, [$i => 1])), $recent);
        if ($sc >= $report[$i]['was'] + 5) {
            $copies[$i]['caption'] = $new;
            $open[$i] = pm_editor_opening($new);
            $report[$i] = ['score' => $sc, 'was' => $report[$i]['was'], 'fixed' => true, 'issues' => array_slice($iss, 0, 3)];
        }
    }
    return [$copies, $report];
}

/** Switches the editor review on or off for the business on screen (Social > Plan > Publishing rules). */
function pm_do_editor_toggle(string $vb): array
{
    $on = !empty($_POST['on']);
    $cfg = pm_social_settings($vb);
    $cfg['editor_review'] = $on;
    pm_social_settings_save($vb, $cfg);
    return ['msg' => $on ? 'An editor will review the posts the AI plans, and send weak ones back once.' : 'Posts go straight from the planner to you.', 'kind' => 'ok', 'to' => 'social#rules'];
}
