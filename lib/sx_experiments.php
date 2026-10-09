<?php
/**
 * Measure, part 4: one small experiment at a time, judged by the scoreboard (no AI). The Director picks the next one from a FIXED menu when none
 * is running; posts planned meanwhile alternate between the two arms (post['exp'], post['arm']); when each arm has enough scored posts the result
 * is stored as plain figures and a one-line learning the planner reads.
 * Data: data/social_experiments.json {rows:[{id,brand,key,hypothesis,metric,field,arms:{A,B},start,min_posts,status active|done,verdict,figures,learning,end}]}.
 * A is the current way, B the challenger. Verdict 'keep' = B clearly did better (use it more); 'drop' = B did not clearly beat A; 'too early' = not enough posts yet.
 */

const PM_SX_EXP_MENU = [
    'cta_question_vs_whatsapp' => ['field' => 'cta', 'hypothesis' => 'A question at the end gets more response than a WhatsApp call to action',
        'A' => ['label' => 'Ask a question', 'value' => 'comment', 'instruction' => 'CTA: ask a question'],
        'B' => ['label' => 'WhatsApp us', 'value' => 'whatsapp', 'instruction' => 'CTA: invite a WhatsApp message']],
    'hook_english_vs_chichewa' => ['field' => 'lang', 'hypothesis' => 'A Chichewa hook gets more response than an English hook',
        'A' => ['label' => 'English hook', 'value' => 'en', 'instruction' => 'Hook: plain English'],
        'B' => ['label' => 'Chichewa hook', 'value' => 'ny', 'instruction' => 'Hook: open with one of the owner-supplied Chichewa lines (never translate yourself)']],
    'format_image_vs_video' => ['field' => 'format', 'hypothesis' => 'A short video gets more response than a picture post',
        'A' => ['label' => 'Picture post', 'value' => 'image', 'instruction' => 'Format: picture post'],
        'B' => ['label' => 'Short video', 'value' => 'reel', 'instruction' => 'Format: short video (reel)']],
    'hour_0730_vs_1830' => ['field' => 'hour', 'hypothesis' => 'Evening posts (18:30) get more response than early posts (07:30)',
        'A' => ['label' => '07:30', 'value' => '07:30', 'instruction' => 'Post time: 07:30'],
        'B' => ['label' => '18:30', 'value' => '18:30', 'instruction' => 'Post time: 18:30']],
];

function pm_sx_exp_rows(): array
{
    return array_values((array)(pm_load('social_experiments', fn() => ['rows' => []])['rows'] ?? []));
}

/** Changes the experiment rows under the lock. $fn(array $rows): array. */
function pm_sx_exp_update(callable $fn): void
{
    pm_update('social_experiments', fn(array $d) => ['rows' => array_values($fn((array)($d['rows'] ?? [])))], fn() => ['rows' => []]);
}

function pm_experiments(string $brand): array
{
    return array_values(array_filter(pm_sx_exp_rows(), fn($r) => ($r['brand'] ?? '') === $brand));
}

function pm_experiment_active(string $brand): ?array
{
    foreach (pm_experiments($brand) as $r) {
        if (($r['status'] ?? '') === 'active') {
            return $r;
        }
    }
    return null;
}

/** Starts an experiment from the fixed menu. Returns the row, or null when one is already running or the key is unknown. */
function pm_experiment_start(string $brand, string $key, int $minPosts = 4): ?array
{
    $m = PM_SX_EXP_MENU[$key] ?? null;
    if (!$m || pm_experiment_active($brand)) {
        return null;
    }
    $row = ['id' => 'x' . substr(md5($brand . $key . microtime(true)), 0, 8), 'brand' => $brand, 'key' => $key, 'hypothesis' => $m['hypothesis'], 'metric' => 'eng', 'field' => $m['field'],
        'arms' => ['A' => $m['A'], 'B' => $m['B']], 'start' => date('Y-m-d'), 'min_posts' => max(2, $minPosts), 'status' => 'active', 'verdict' => '', 'figures' => [], 'learning' => ''];
    $started = null;
    pm_sx_exp_update(function (array $rows) use ($row, $brand, &$started) {
        foreach ($rows as $r) {
            if (($r['brand'] ?? '') === $brand && ($r['status'] ?? '') === 'active') {
                return $rows; // someone started one meanwhile
            }
        }
        $rows[] = $row;
        $started = $row;
        return $rows;
    });
    return $started;
}

/** Posts that belong to an experiment, by arm: counts of every post planned for it, whatever its state. */
function pm_sx_exp_counts(string $id, string $brand): array
{
    $c = ['A' => 0, 'B' => 0];
    foreach (pm_sx_posts($brand) as $p) {
        if (($p['exp'] ?? '') === $id && isset($c[$p['arm'] ?? ''])) {
            $c[$p['arm']]++;
        }
    }
    return $c;
}

/**
 * For the i-th post of a planning batch: which arm it should test. Arms alternate (the smaller side first), counting posts already planned.
 * Returns null when no experiment is running or both arms already have the posts they need. ['id','arm','instruction','field','value'].
 */
function pm_experiment_for_slot(string $brand, int $i): ?array
{
    $e = pm_experiment_active($brand);
    if (!$e) {
        return null;
    }
    $c = pm_sx_exp_counts($e['id'], $brand);
    if (min($c) >= (int)$e['min_posts']) {
        return null;
    }
    for ($k = 0; $k <= $i; $k++) {
        $arm = $c['A'] <= $c['B'] ? 'A' : 'B';
        if ($k < $i) {
            $c[$arm]++;
        }
    }
    if ($c[$arm] >= (int)$e['min_posts'] + 1) {
        return null;
    }
    return ['id' => $e['id'], 'arm' => $arm, 'instruction' => (string)$e['arms'][$arm]['instruction'], 'field' => (string)$e['field'], 'value' => (string)$e['arms'][$arm]['value']];
}

/** Which arm a scored post really was in: what it actually did where that can be seen (call to action, format, hour), else the arm it was planned for. */
function pm_sx_exp_arm(array $e, array $row): ?string
{
    $A = $e['arms']['A']['value'];
    $B = $e['arms']['B']['value'];
    switch ($e['field']) {
        case 'cta':
            return $row['cta'] === $A ? 'A' : ($row['cta'] === $B ? 'B' : null);
        case 'format':
            return $row['format'] === $A ? 'A' : ($row['format'] === $B ? 'B' : null);
        case 'hour':
            $h = (int)substr($row['time'], 0, 2) * 60 + (int)substr($row['time'], 3, 2);
            $da = abs($h - ((int)substr($A, 0, 2) * 60 + (int)substr($A, 3, 2)));
            $db = abs($h - ((int)substr($B, 0, 2) * 60 + (int)substr($B, 3, 2)));
            return min($da, $db) <= 120 ? ($da <= $db ? 'A' : 'B') : null;
        default:
            return in_array($row['arm'], ['A', 'B'], true) ? $row['arm'] : null;
    }
}

function pm_sx_sd(array $v): float
{
    $n = count($v);
    if ($n < 2) {
        return 0.0;
    }
    $m = array_sum($v) / $n;
    return sqrt(array_sum(array_map(fn($x) => ($x - $m) ** 2, $v)) / ($n - 1));
}

/**
 * Judges an experiment from the scoreboard rows (7-day numbers only). Returns verdict keep|drop|too early, plain figures and a sentence.
 * B must beat A by at least 15% AND by 1.5 standard errors; anything less is "no clear difference" (drop), because 4 posts a side can be luck.
 */
function pm_experiment_eval(string $id): array
{
    $e = null;
    foreach (pm_sx_exp_rows() as $r) {
        if (($r['id'] ?? '') === $id) {
            $e = $r;
        }
    }
    if (!$e) {
        return ['verdict' => 'too early', 'figures' => [], 'summary' => 'Unknown experiment.', 'winner' => ''];
    }
    $by = ['A' => [], 'B' => []];
    $leads = ['A' => 0, 'B' => 0];
    foreach (pm_sx_rows($e['brand'])['rows'] as $r) {
        if ($r['exp'] === $e['id'] && ($arm = pm_sx_exp_arm($e, $r))) {
            $by[$arm][] = $r['eng'];
            $leads[$arm] += $r['leads'];
        }
    }
    $fig = [];
    foreach ($by as $arm => $v) {
        $fig[$arm] = ['label' => $e['arms'][$arm]['label'], 'n' => count($v), 'avg_eng' => $v ? round(array_sum($v) / count($v), 1) : null, 'leads' => $leads[$arm]];
    }
    $min = (int)$e['min_posts'];
    $lA = $fig['A']['label'];
    $lB = $fig['B']['label'];
    if (min($fig['A']['n'], $fig['B']['n']) < $min) {
        return ['verdict' => 'too early', 'figures' => $fig, 'winner' => '',
            'summary' => "Too early: $lA has {$fig['A']['n']} scored post(s), $lB has {$fig['B']['n']}; each needs $min with 7-day numbers."];
    }
    $a = $fig['A']['avg_eng'];
    $b = $fig['B']['avg_eng'];
    $se = sqrt(pm_sx_sd($by['A']) ** 2 / count($by['A']) + pm_sx_sd($by['B']) ** 2 / count($by['B']));
    $diff = $b - $a;
    $lift = $a > 0 ? round($diff / $a * 100) : ($b > 0 ? 100 : 0);
    $clear = abs($lift) >= 15 && abs($diff) >= 1.5 * $se;
    $n = "({$fig['A']['n']} vs {$fig['B']['n']} posts, average engagement $a vs $b)";
    if ($clear && $diff > 0) {
        return ['verdict' => 'keep', 'figures' => $fig, 'winner' => 'B', 'lift' => $lift, 'summary' => "$lB beat $lA by $lift% $n: use $lB more."];
    }
    if ($clear) {
        return ['verdict' => 'drop', 'figures' => $fig, 'winner' => 'A', 'lift' => $lift, 'summary' => "$lA beat $lB by " . abs($lift) . "% $n: keep $lA."];
    }
    return ['verdict' => 'drop', 'figures' => $fig, 'winner' => '', 'lift' => $lift, 'summary' => "No clear difference between $lA and $lB $n: either is fine; test something else."];
}

/** Plain learnings from finished experiments, newest first (read by pm_social_learnings and the planner). */
function pm_experiment_learnings(string $brand): array
{
    $o = [];
    foreach (array_reverse(pm_experiments($brand)) as $r) {
        if (($r['status'] ?? '') === 'done' && trim((string)($r['learning'] ?? '')) !== '' && ($r['verdict'] ?? '') !== 'stopped') {
            $o[] = (string)$r['learning'];
        }
    }
    return $o;
}

/** Hourly: closes the running experiment when it has a verdict (or after 60 days without enough posts). */
function pm_job_experiments(string $brand): string
{
    $e = pm_experiment_active($brand);
    if (!$e) {
        return '';
    }
    $ev = pm_experiment_eval($e['id']);
    $old = strtotime($e['start']) < time() - 60 * 86400;
    if ($ev['verdict'] === 'too early' && !$old) {
        return '';
    }
    pm_sx_exp_update(function (array $rows) use ($e, $ev, $old) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $e['id']) {
                $rows[$i]['status'] = 'done';
                $rows[$i]['verdict'] = $ev['verdict'] === 'too early' ? 'inconclusive' : $ev['verdict'];
                $rows[$i]['figures'] = $ev['figures'];
                $rows[$i]['end'] = date('Y-m-d');
                $rows[$i]['learning'] = $ev['verdict'] === 'too early' ? '' : $e['arms']['A']['label'] . ' vs ' . $e['arms']['B']['label'] . ': ' . $ev['summary'];
            }
        }
        return $rows;
    });
    return 'experiment finished: ' . $ev['summary'];
}

/**
 * The Director's pick: when no experiment is running, start the next one from the fixed menu (skipping any run in the last 90 days, and the
 * Chichewa test while the owner has not given any Chichewa lines). Needs the Page to be posting. No AI.
 */
function pm_experiment_autostart(string $brand): ?array
{
    if (pm_experiment_active($brand)) {
        return null;
    }
    if (!count(array_filter(pm_sx_posts($brand), fn($p) => in_array($p['status'] ?? '', ['published', 'approved', 'draft'], true) && strtotime((string)($p['when'] ?? '')) > time() - 21 * 86400))) {
        return null;
    }
    $recent = [];
    foreach (pm_experiments($brand) as $r) {
        if (strtotime((string)($r['start'] ?? '')) > time() - 90 * 86400 || ($r['status'] ?? '') === 'active') {
            $recent[$r['key'] ?? ''] = true;
        }
    }
    $chichewa = true;
    if (function_exists('pm_bank_get')) {
        try {
            $chichewa = (bool)(pm_bank_get($brand)['chichewa'] ?? []);
        } catch (Throwable) {
        }
    } else {
        $chichewa = false;
    }
    foreach (PM_SX_EXP_MENU as $key => $m) {
        if (isset($recent[$key]) || ($m['field'] === 'lang' && !$chichewa)) {
            continue;
        }
        return pm_experiment_start($brand, $key);
    }
    return null;
}

function pm_do_experiment_start(string $vb): array
{
    $key = (string)($_POST['key'] ?? '');
    $e = pm_experiment_start($vb, $key);
    return $e ? ['msg' => 'Experiment started: ' . $e['hypothesis'] . '. The next planned posts alternate between the two ways.', 'kind' => 'ok', 'to' => 'social&view=results']
        : ['msg' => pm_experiment_active($vb) ? 'Finish or stop the running experiment first.' : 'Unknown experiment.', 'kind' => 'err', 'to' => 'social&view=results'];
}

function pm_do_experiment_stop(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $ok = false;
    pm_sx_exp_update(function (array $rows) use ($id, $vb, &$ok) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id && ($r['brand'] ?? '') === $vb && ($r['status'] ?? '') === 'active') {
                $rows[$i]['status'] = 'done';
                $rows[$i]['verdict'] = 'stopped';
                $rows[$i]['end'] = date('Y-m-d');
                $ok = true;
            }
        }
        return $rows;
    });
    return ['msg' => $ok ? 'Experiment stopped. Nothing was concluded from it.' : 'No running experiment found.', 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=results'];
}
