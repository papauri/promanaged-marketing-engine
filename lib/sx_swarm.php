<?php
/**
 * The agency's desks work as swarms: several specialists propose, code critics score every proposal, weak ones go back for another round, and the owner chooses. This file is the shared part:
 *   pm_swarm_loop($o)           the loop: ask, score, send the weak ones back (a few rounds at most), keep the best distinct few
 *   pm_swarm_store/update       one file, data/desks.json {brand: {desk: {options: [...], runs: [...], ...}}}, for every desk below
 *   pm_swarm_clean($text, $max) plain one-line text from an AI answer
 *   pm_swarm_dedupe             how alike two pieces of text are (shared meaning words)
 * The desks: the Brand team (lib/sx_brandteam.php, kits), the Copy desk (lib/sx_copydesk.php, cover wording), the Photo desk (lib/sx_photodesk.php, the cover photo and shot briefs) and the Idea room
 * (lib/sx_idearoom.php, post ideas). Every desk only proposes: nothing is applied or published until the owner presses its button. With no AI key a desk falls back to what code can do.
 * Auto-loaded by social_modules.php.
 */

function pm_swarm_store(string $brand): array
{
    return (array)(pm_load('desks', fn() => [])[$brand] ?? []);
}

/** Locked change of one desk's data: $fn(array $desk): array. */
function pm_swarm_update(string $brand, string $desk, callable $fn): array
{
    $out = [];
    pm_update('desks', function (array $all) use ($brand, $desk, $fn, &$out) {
        $cur = (array)($all[$brand][$desk] ?? []) + ['options' => [], 'runs' => []];
        $out = $fn($cur);
        $all[$brand][$desk] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

function pm_swarm_clean(mixed $v, int $max): string
{
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : ''))), 0, $max);
}

/** 0..1: the share of meaning words two texts have in common (the smaller text decides). */
function pm_swarm_alike(string $a, string $b): float
{
    $x = pm_editor_words($a);
    $y = pm_editor_words($b);
    if (!$x || !$y) {
        return 0.0;
    }
    return count(array_intersect($x, $y)) / min(count($x), count($y));
}

/**
 * The loop. $o:
 *   ai        bool      may the AI be asked (false: only $fallback runs)
 *   ask       callable  (int $round, array $feedback, array $tried): list of candidates (arrays); may throw when the AI cannot be reached
 *   fallback  ?callable (int $round, array $tried): list of candidates made in code (used when there is no AI, or when the AI fails in round 1)
 *   score     callable  (array $candidate): ['score' => 0..100, 'issues' => [], 'praise' => []]
 *   key       callable  (array $candidate): string, what makes two candidates "the same"
 *   note      callable  (array $candidate): string, one line the next round is told about a weak candidate
 *   threshold int, rounds int, keep int, label string (shown in the log)
 * Returns ['kept' => candidates with score/issues/praise/round/how, 'log' => [...], 'calls' => int, 'best' => int, 'how' => 'ai'|'code']. Throws when nothing at all could be made.
 */
function pm_swarm_loop(array $o): array
{
    $ai = !empty($o['ai']);
    $threshold = (int)($o['threshold'] ?? 78);
    $rounds = max(1, (int)($o['rounds'] ?? 2));
    $keep = max(1, (int)($o['keep'] ?? 3));
    $label = (string)($o['label'] ?? 'candidates');
    $log = [];
    $all = [];
    $tried = [];
    $feedback = [];
    $calls = 0;
    $best = 0;
    $how = $ai ? 'ai' : 'code';
    for ($round = 1; $round <= $rounds; $round++) {
        try {
            if ($ai) {
                $made = (array)$o['ask']($round, $feedback, $tried);
                $calls++;
            } else {
                $made = isset($o['fallback']) ? (array)$o['fallback']($round, $tried) : [];
            }
        } catch (Throwable $e) {
            if ($round > 1 || !isset($o['fallback'])) {
                $log[] = 'Round ' . $round . ' could not run (' . mb_substr($e->getMessage(), 0, 90) . ')' . ($all ? ': the first round stands.' : '.');
                break;
            }
            $log[] = 'The AI could not be reached (' . mb_substr($e->getMessage(), 0, 90) . '): the team worked in code instead.';
            $ai = false;
            $how = 'code';
            $made = (array)$o['fallback']($round, $tried);
        }
        $roundBest = 0;
        foreach ($made as $c) {
            $r = $o['score']($c);
            $c = $c + $r;
            $c['round'] = $round;
            $c['how'] = $c['how'] ?? ($ai ? 'ai' : 'code');
            $all[] = $c;
            $tried[] = (string)$o['key']($c);
            $roundBest = max($roundBest, (int)$c['score']);
        }
        $best = max($best, $roundBest);
        $log[] = 'Round ' . $round . ': ' . count($made) . ' ' . $label . ($ai ? ' from the specialists' : ' made in code') . ', best ' . $roundBest . ' of 100.';
        if (!$ai || $roundBest >= $threshold || $round === $rounds) {
            break;
        }
        usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
        $feedback = [];
        foreach (array_slice($all, 0, 3) as $c) {
            $feedback[] = (string)$o['note']($c) . ': ' . implode(' ', array_slice((array)$c['issues'], 0, 3));
        }
        $log[] = 'Sent back for another round with the critics\' notes.';
    }
    if (!$all) {
        throw new RuntimeException('Nothing could be made.');
    }
    usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
    $kept = [];
    $seen = [];
    foreach ($all as $c) {
        $k = (string)$o['key']($c);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = 1;
        $kept[] = $c;
        if (count($kept) >= $keep) {
            break;
        }
    }
    return ['kept' => $kept, 'log' => $log, 'calls' => $calls, 'best' => (int)$kept[0]['score'], 'how' => $how];
}

/** The options of a desk that are waiting for the owner. */
function pm_swarm_waiting(string $brand, string $desk, int $staleDays = 21): array
{
    $min = date('Y-m-d H:i', time() - $staleDays * 86400);
    return array_values(array_filter((array)(pm_swarm_store($brand)[$desk]['options'] ?? []), fn($p) => ($p['status'] ?? '') === 'new' && (string)($p['at'] ?? '') >= $min));
}

/** Marks one option of a desk: used, dismissed. */
function pm_swarm_mark(string $brand, string $desk, string $id, string $status): void
{
    pm_swarm_update($brand, $desk, function (array $d) use ($id, $status) {
        foreach ($d['options'] as $k => $p) {
            if (($p['id'] ?? '') === $id) {
                $d['options'][$k]['status'] = $status;
            }
        }
        return $d;
    });
}

function pm_swarm_run_record(string $brand, string $desk, array $newOptions, array $run): void
{
    pm_swarm_update($brand, $desk, function (array $d) use ($newOptions, $run) {
        foreach ($d['options'] as $k => $p) { // a set nobody chose from is replaced by the new one
            if (($p['status'] ?? '') === 'new') {
                $d['options'][$k]['status'] = 'dismissed';
            }
        }
        $d['options'] = array_slice(array_merge($newOptions, $d['options']), 0, 12);
        $d['runs'] = array_slice(array_merge([$run], (array)$d['runs']), 0, 8);
        return $d;
    });
}
