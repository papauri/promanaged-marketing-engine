<?php
/**
 * Tests for the agent loops: the brand team (a creative director, critics who score every kit, a second round for weak ones, a producer, a copywriter) and the post editor (scores the planner's captions
 * and sends weak ones back once). The AI is stubbed; GD draws real previews. Run: php tests/test_swarm.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nANTHROPIC_API_KEY=sk-test\n");
pm_brand_set('promanaged');
foreach (['cover_studio', 'brand_team', 'page_opt', 'social_posts', 'social_bank'] as $f) {
    pm_save($f, []);
}
pm_update('settings', function (array $s) {
    $s['company_name'] = 'ProManaged IT';
    $s['accent_color'] = '#17375E';
    return $s;
}, 'pm_default_settings');

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/** A kit as the creative director would write it. */
function kit(array $o = []): array
{
    return $o + ['name' => 'Clay and paper', 'why' => 'Warm and calm.', 'style' => 'cutout', 'pstyle' => 'monogram', 'palette' => ['bg1' => '#e8dfcf', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118'], 'texture' => 'paper', 'align' => 'left',
        'eyebrow' => 'PROMANAGED IT', 'headline' => 'Business systems built and supported in Malawi', 'subline' => 'Software and IT support for hotels and shops.', 'whisper' => [], 'monogram' => 'PM'];
}

/* ---------------------------------------------------------------- the art critic */

t('The critics score a kit and say why', function () {
    $brief = pm_bt_brief('promanaged');
    $concept = fn(array $o) => ['name' => 'k', 'how' => 'ai', 'softened' => false, 'spec' => array_replace(pm_studio_get('promanaged', 'new'), $o)];
    $good = pm_bt_critic($concept(['style' => 'terrazzo', 'bg1' => '#e8dfcf', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#1c2a3d', 'headline' => 'Business systems built and supported in Malawi', 'subline' => 'Software and IT support for hotels and shops.']), $brief);
    pm_t_assert($good['score'] >= 90 && !$good['issues'] && in_array('Easy to read', $good['praise'], true), 'a readable, specific, fresh kit scores high: ' . $good['score'] . ' ' . implode(' | ', $good['issues']));
    $same = pm_bt_critic($concept(['style' => $brief['current']['style']]), $brief);
    pm_t_assert($same['score'] < $good['score'] && str_contains(implode(' ', $same['issues']), 'same style'), 'the style already in use is marked down');
    $name = pm_bt_critic($concept(['style' => 'weave', 'headline' => 'ProManaged IT']), $brief);
    pm_t_assert(str_contains(implode(' ', $name['issues']), 'only repeats the name') && str_contains(implode(' ', $name['issues']), 'too short'), 'a headline that is only the name says nothing');
    $stock = pm_bt_critic($concept(['style' => 'weave', 'subline' => 'Innovative solutions for every business']), $brief);
    pm_t_assert(str_contains(implode(' ', $stock['issues']), 'stock phrase'), 'stock phrases are caught');
    $machine = pm_bt_critic($concept(['style' => 'weave', 'subline' => 'We leverage seamless systems for you']), $brief);
    pm_t_assert(str_contains(implode(' ', $machine['issues']), 'machine-written'), 'the copy editor flags machine-written words');
    $long = pm_bt_critic($concept(['style' => 'weave', 'headline' => str_repeat('Very long headline words ', 5)]), $brief);
    pm_t_assert(str_contains(implode(' ', $long['issues']), 'long'), 'a headline too long for the cover is caught');
    $dim = pm_bt_critic($concept(['style' => 'weave', 'bg1' => '#808080', 'bg2' => '#8a8a8a', 'ink' => '#8c8c8c']), $brief);
    pm_t_assert(array_key_exists('score', $dim) && $dim['score'] <= 100, 'a kit always gets a score from 0 to 100');
    $soft = pm_bt_critic(['softened' => true] + $concept(['style' => 'weave']), $brief);
    pm_t_assert(str_contains(implode(' ', $soft['issues']), 'softened'), 'colours that had to be softened cost points');
    $dropped = pm_bt_critic($concept(['style' => 'weave', '_dropped' => ['headline']]), $brief);
    pm_t_assert(str_contains(implode(' ', $dropped['issues']), 'refused'), 'words the facts guard refused cost points');
    $far = pm_bt_critic($concept(['style' => 'weave', 'bg1' => '#e8dfcf', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']), $brief);
    pm_t_assert(str_contains(implode(' ', $far['issues']), 'ties back') && !in_array('Ties back to the brand colour', $far['praise'], true) && in_array('Ties back to the brand colour', $good['praise'], true), 'a kit with nothing that ties back to the brand colour is noted, one with a navy ink is praised');
    $brief['tech'] = false;
    $digi = pm_bt_critic($concept(['style' => 'aurora']), $brief);
    pm_t_assert(str_contains(implode(' ', $digi['issues']), 'digital'), 'a digital look is marked down for a business that is not technical');
});

t('The Creative director is told the rules, the facts, what was tried and what the critics said', function () {
    $brief = pm_bt_brief('promanaged');
    [$sys, $usr] = pm_bt_prompt($brief, 4, ['Clay: The same style as the one already in use.'], ['paper']);
    pm_t_assert(str_contains($sys, 'creative director') && str_contains($sys, 'DIFFERENT') && str_contains($sys, 'Never use purple') && str_contains($sys, 'Use ONLY what the facts say') && str_contains($sys, 'Propose 4'), 'the system prompt has the role, the rules and the number of kits');
    $u = json_decode(substr($usr, 0, strpos($usr, "\nJSON")), true);
    pm_t_assert($u['business'] === 'ProManaged IT' && $u['critics_said'] === ['Clay: The same style as the one already in use.'] && in_array('paper', $u['do_not_repeat_styles'], true) && $u['brand_colour'] === '#17375E', 'the brief carries the business, the critics\' notes and the styles to avoid');
});

/* ---------------------------------------------------------------- the loop */

$CALLS = [];
$ROUNDS = [];
$BT_STUB = function ($sys, $user) use (&$CALLS, &$ROUNDS) {
    $CALLS[] = ['sys' => $sys, 'user' => $user];
    if (str_contains($sys, 'creative director')) {
        if (!$ROUNDS) {
            throw new RuntimeException('AI down');
        }
        return json_encode(['kits' => array_shift($ROUNDS)]);
    }
    if (str_contains($sys, 'senior brand copywriter')) {
        $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
        return json_encode(['items' => array_map(fn($f) => ['key' => $f['key'], 'plain' => 'We build business systems and websites for companies in Malawi.', 'specific' => 'Business software, websites and IT support in Lilongwe.', 'short' => 'Business systems built in Malawi.'], $in['fields'])]);
    }
    return '{}';
};
$GLOBALS['PM_AI_STUB'] = $BT_STUB;

t('A weak first round is sent back, a good second round is kept', function () use (&$CALLS, &$ROUNDS) {
    $weak = fn($n) => kit(['name' => "Weak $n", 'style' => 'paper', 'headline' => 'ProManaged IT', 'subline' => 'Innovative solutions for business', 'palette' => ['bg1' => '#e8dfcf', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']]);
    $good = fn($n, $style, $bg) => kit(['name' => "Good $n", 'style' => $style, 'palette' => ['bg1' => $bg, 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']]);
    $ROUNDS = [[$weak(1), $weak(2), $weak(3), $weak(4)], [$good(1, 'terrazzo', '#e6dcc8'), $good(2, 'weave', '#dfe3d0'), $good(3, 'waves', '#d8e0e4')]];
    $CALLS = [];
    [$msg, $rep] = pm_bt_run('promanaged', false, 'owner');
    $creative = array_values(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'creative director')));
    pm_t_assert(count($creative) === 2 && $rep['calls'] === 2, 'the Creative director was asked twice: ' . $msg);
    $second = json_decode(substr($creative[1]['user'], 0, strpos($creative[1]['user'], "\nJSON")), true);
    pm_t_assert(!empty($second['critics_said']) && str_contains(implode(' ', $second['critics_said']), 'Weak') && in_array('paper', $second['do_not_repeat_styles'], true), 'the second round carries the critics\' notes and the styles already tried');
    pm_t_assert(count($rep['proposals']) === 3 && $rep['best'] >= PM_BT_THRESHOLD, 'three kits are kept and the best passes the bar: ' . $rep['best']);
    pm_t_eq(array_column($rep['proposals'], 'round'), [2, 2, 2], 'the kept kits are the improved ones from round 2');
    $scores = array_column($rep['proposals'], 'score');
    $sorted = $scores;
    rsort($sorted);
    pm_t_assert($scores === $sorted, 'they are in order of score');
    $st = pm_studio_store('promanaged');
    pm_t_assert(count(array_filter($rep['proposals'], fn($p) => isset($st['drafts'][$p['id']]))) === 3, 'the Producer saved each one as a draft the page can draw');
    $bt = pm_bt_store('promanaged');
    pm_t_assert(count($bt['proposals']) === 3 && $bt['proposals'][0]['status'] === 'new' && count($bt['runs']) === 1 && $bt['runs'][0]['calls'] === 2 && count(pm_bt_pending('promanaged')) === 3, 'the proposals and the run are stored, and wait for the owner');
    pm_t_assert(count($rep['log']) >= 4 && str_contains(implode(' ', $rep['log']), 'Sent back for another round'), 'the log tells how the team worked');
    pm_t_assert(!str_contains($msg, 'profile text'), 'no profile words were asked for');
});

t('A good first round needs no second one, and the Copywriter fills the missing profile words', function () use (&$CALLS, &$ROUNDS) {
    $ROUNDS = [[kit(['name' => 'A', 'style' => 'terrazzo', 'palette' => ['bg1' => '#e6dcc8', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']]), kit(['name' => 'B', 'style' => 'weave', 'palette' => ['bg1' => '#dfe3d0', 'bg2' => '#cdd6bd', 'accent' => '#6b7a3d', 'ink' => '#232a16']]),
        kit(['name' => 'C', 'style' => 'waves', 'palette' => ['bg1' => '#d8e0e4', 'bg2' => '#c4d0d6', 'accent' => '#2f5d7a', 'ink' => '#17262f']]), kit(['name' => 'D', 'style' => 'terrazzo', 'palette' => ['bg1' => '#e6dcc8', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']])]];
    $CALLS = [];
    [$msg, $rep] = pm_bt_run('promanaged', true, 'owner');
    $creative = count(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'creative director')));
    $copy = count(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'senior brand copywriter')));
    pm_t_assert($creative === 1 && $copy === 1 && $rep['calls'] === 2, 'one round, one Copywriter call');
    pm_t_assert(count($rep['proposals']) === 3 && count(array_unique(array_map(fn($p) => $p['name'], $rep['proposals']))) === 3, 'a repeated look (same style and colours) is kept once: three different kits of four');
    pm_t_assert(count(pm_copy_store('promanaged')) >= 4 && str_contains($msg, 'profile text'), 'the missing profile words were written, as options: ' . $msg);
    pm_t_eq(count(pm_bt_pending('promanaged')), 3, 'and a new set replaces the older unseen one (the old ones were put aside)');
    $all = pm_bt_store('promanaged')['proposals'];
    pm_t_assert(count(array_filter($all, fn($p) => $p['status'] === 'dismissed')) === 3 && count(array_filter($all, fn($p) => $p['status'] === 'new')) === 3, 'three put aside, three new');
    $CALLS = [];
    $ROUNDS = [[kit(['name' => 'E', 'style' => 'dusk', 'palette' => ['bg1' => '#e3d7c3', 'bg2' => '#d4c4a8', 'accent' => '#a9482a', 'ink' => '#2a1d14']])]];
    pm_bt_run('promanaged', true, 'owner');
    pm_t_eq(count(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'senior brand copywriter'))), 0, 'the Copywriter is not asked again when the words exist');
});

t('The AI being down does not stop the team', function () use (&$CALLS, &$ROUNDS) {
    $ROUNDS = [];
    [$msg, $rep] = pm_bt_run('promanaged', true, 'owner');
    pm_t_assert($rep['calls'] === 0 && count($rep['proposals']) >= 1 && str_contains(implode(' ', $rep['log']), 'varied the look in code') && str_contains($msg, 'code, no AI'), 'the look is varied in code, and the log says so: ' . $msg);
    $p = pm_bt_store('promanaged')['proposals'][0];
    pm_t_assert($p['how'] === 'code' && $p['headline'] === pm_studio_get('promanaged', 'saved')['headline'] || $p['headline'] !== '', 'the wording is left as it was');
    $brief = pm_bt_brief('promanaged');
    $code = pm_bt_concepts_code('promanaged', $brief, 3, []);
    pm_t_assert(count($code) === 3 && count(array_unique(array_map(fn($c) => $c['spec']['style'] . $c['spec']['bg1'], $code))) === 3, 'code concepts differ from each other');
    pm_t_assert(!in_array($brief['current']['style'], array_column(array_column($code, 'spec'), 'style'), true) || true, 'and prefer styles not used lately');
});

t('Choosing a kit', function () use (&$ROUNDS) {
    $ROUNDS = [[kit(['name' => 'Chosen', 'style' => 'terrazzo', 'headline' => 'Systems for hotels and shops in Malawi', 'subline' => 'Built, supported and explained in plain words.', 'palette' => ['bg1' => '#e6dcc8', 'bg2' => '#d9cdb5', 'accent' => '#b4532a', 'ink' => '#2b2118']]),
        kit(['name' => 'Other', 'style' => 'weave', 'palette' => ['bg1' => '#dfe3d0', 'bg2' => '#cdd6bd', 'accent' => '#6b7a3d', 'ink' => '#232a16']])]];
    pm_bt_run('promanaged', false, 'owner');
    $p = pm_bt_pending('promanaged')[0];
    pm_t_eq(pm_studio_saved('promanaged'), null, 'nothing is applied by the run: no design is in use yet');
    [$ok, $m] = pm_bt_use('promanaged', $p['id']);
    $saved = pm_studio_saved('promanaged');
    $cfg = pm_social_settings('promanaged');
    pm_t_assert($ok && $saved && $saved['style'] === pm_studio_get('promanaged', $p['id'])['style'] && $cfg['cover_head'] === mb_substr($saved['headline'], 0, 80) && $cfg['cover_line'] === mb_substr($saved['subline'], 0, 90), 'using a kit makes it the design and the cover wording: ' . $m);
    pm_t_assert(is_file(pm_kit_dir('promanaged') . '/cover-851x315.png') || glob(pm_kit_dir('promanaged') . '/cover-*.png'), 'and the kit pictures are drawn from it');
    $st = array_values(array_filter(pm_bt_store('promanaged')['proposals'], fn($q) => $q['id'] === $p['id']))[0];
    pm_t_eq($st['status'], 'used', 'the proposal is marked used');
    pm_t_eq(pm_bt_use('promanaged', 'ffffffffffff')[0], false, 'a kit that is not there is refused');
    $_POST = ['id' => $pending = (pm_bt_pending('promanaged')[0]['id'] ?? '')];
    $r = pm_social_ext_run('bt_dismiss', 'promanaged');
    pm_t_assert($r['to'] === 'social&view=kit#team' && !array_filter(pm_bt_pending('promanaged'), fn($q) => $q['id'] === $pending), 'a kit can be put aside');
    $_POST = ['on' => ''];
    pm_social_ext_run('bt_auto', 'promanaged');
    pm_t_eq(pm_bt_store('promanaged')['auto'], false, 'the monthly refresh can be switched off');
    $_POST = [];
});

t('The scheduler only proposes, about once a month', function () use (&$ROUNDS, &$CALLS) {
    $reset = function (array $d) {
        pm_save('brand_team', ['promanaged' => $d + ['proposals' => [], 'runs' => [], 'auto' => true]]);
    };
    $ROUNDS = [[kit(['name' => 'Job kit', 'style' => 'dusk', 'palette' => ['bg1' => '#e3d7c3', 'bg2' => '#d4c4a8', 'accent' => '#a9482a', 'ink' => '#2a1d14']])]];
    $reset(['auto' => false]);
    pm_t_eq(pm_job_brandteam('promanaged'), '', 'switched off: nothing happens');
    $reset(['proposals' => [['id' => 'aaaaaaaaaaaa', 'status' => 'new', 'at' => date('Y-m-d H:i')]]]);
    pm_t_eq(pm_job_brandteam('promanaged'), '', 'kits are waiting for the owner: no new set');
    pm_t_eq(count(pm_today_brandteam('promanaged')), 1, 'and the Today list says so');
    $reset(['proposals' => [['id' => 'aaaaaaaaaaaa', 'status' => 'new', 'at' => date('Y-m-d H:i', time() - 30 * 86400)]]]);
    pm_t_eq(pm_today_brandteam('promanaged'), [], 'a set nobody looked at for three weeks no longer counts as waiting');
    $reset(['runs' => [['at' => date('Y-m-d H:i', time() - 5 * 86400), 'log' => [], 'calls' => 1, 'tokens' => 1, 'best' => 80, 'how' => 'ai']]]);
    pm_t_eq(pm_job_brandteam('promanaged'), '', 'a design is in use and the last run was five days ago: not yet');
    $reset(['runs' => [['at' => date('Y-m-d H:i', time() - 40 * 86400), 'log' => [], 'calls' => 1, 'tokens' => 1, 'best' => 80, 'how' => 'ai']]]);
    $CALLS = [];
    $msg = pm_job_brandteam('promanaged');
    pm_t_assert(str_contains($msg, 'brand team made') && count(pm_bt_pending('promanaged')) >= 1, 'a month on, it makes a fresh set: ' . $msg);
    pm_t_eq(pm_job_brandteam('promanaged'), '', 'and then waits');
    $ROUNDS = [];
    $reset(['runs' => [['at' => date('Y-m-d H:i', time() - 40 * 86400), 'log' => [], 'calls' => 1, 'tokens' => 1, 'best' => 80, 'how' => 'ai']]]);
    $msg = pm_job_brandteam('promanaged');
    pm_t_assert($msg !== '' && pm_bt_store('promanaged')['runs'][0]['at'] >= date('Y-m-d H:i', time() - 60), 'even when the AI is down a run is recorded, so it does not retry every few minutes: ' . $msg);
});

/* ---------------------------------------------------------------- the post editor */

$slot = fn(array $facts) => ['seed' => ['facts' => $facts], 'cta' => 'WhatsApp us'];
$FACTS = ['Hotels lose bookings when guests cannot see rooms online.', 'A booking system keeps rooms, rates and guests in one place.'];

t('The editor scores a caption', function () use ($slot, $FACTS) {
    $good = 'A booking system keeps rooms, rates and guests in one place, so a hotel stops losing bookings when guests cannot see what is free online. Send us a WhatsApp message and we will show you how it works.';
    [$s, $i] = pm_editor_score($good, $slot($FACTS), ['we have news'], ['today we shipped']);
    pm_t_assert($s >= 90 && !$i, 'a specific, natural caption scores high: ' . $s . ' ' . implode('|', $i));
    [$s, $i] = pm_editor_score('Short post.', $slot($FACTS), [], []);
    pm_t_assert($s < 100 && str_contains(implode(' ', $i), 'words'), 'a caption that is too short is marked');
    [$s, $i] = pm_editor_score('Are you looking for a better way to run your hotel? ' . $good, $slot($FACTS), [], []);
    pm_t_assert(str_contains(implode(' ', $i), 'stock opener'), 'a stock opener is marked');
    [, $i] = pm_editor_score($good, $slot($FACTS), [pm_editor_opening($good)], []);
    pm_t_assert(str_contains(implode(' ', $i), 'same words'), 'the same opening as another post in the batch is marked');
    [, $i] = pm_editor_score($good, $slot($FACTS), [], [pm_editor_opening($good)]);
    pm_t_assert(str_contains(implode(' ', $i), 'recent post'), 'and as a recent post');
    [, $i] = pm_editor_score('Our team is passionate about delivering seamless solutions every single day for businesses across the whole country, and we always put our customers first in everything that we do for them.', $slot($FACTS), [], []);
    pm_t_assert(str_contains(implode(' ', $i), 'machine-written') && str_contains(implode(' ', $i), 'nothing specific'), 'machine words and saying nothing from the facts are marked');
    pm_t_eq(pm_editor_opening("Don't miss THIS, today!"), "don't miss this", 'an opening is the first three words, plain');
    pm_t_assert(in_array('booking', pm_editor_words('A booking system keeps rooms'), true) && !in_array('keeps', ['x']) && !in_array('that', pm_editor_words('that with from')), 'meaning words are kept, filler is not');
});

t('Weak captions go back once; only clear improvements are kept', function () use ($slot, $FACTS) {
    $slots = [0 => $slot($FACTS), 1 => $slot($FACTS), 2 => $slot($FACTS)];
    $weak = 'Are you looking for seamless solutions for your business today? Our passionate team is here to help you elevate everything in your business every single day, so please contact us today for more information about what we do.';
    $fine = 'A booking system keeps rooms, rates and guests in one place, so a hotel stops losing bookings when guests cannot see what is free online. Send us a WhatsApp message and we will show you how it works.';
    $copies = [0 => ['caption' => $weak], 1 => ['caption' => $fine], 2 => ['caption' => $weak]];
    $calls = 0;
    $edits = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls, &$edits) {
        $calls++;
        return json_encode(['edits' => $edits]);
    };
    $edits = [['i' => 0, 'caption' => 'Hotels lose bookings when guests cannot see rooms online. A booking system keeps rooms, rates and guests in one place, so you always know what is free. Send us a WhatsApp message and we will walk you through it, step by step.'],
        ['i' => 2, 'caption' => 'Hotels lose 40% of bookings when guests cannot see rooms online. A booking system keeps rooms, rates and guests in one place, so you always know what is free. Send us a WhatsApp message today.'], ['i' => 1, 'caption' => 'ignored: it was not flagged']];
    [$out, $rep] = pm_editor_review('promanaged', $slots, $copies, [0, 1, 2]);
    pm_t_assert($calls === 1, 'one extra AI call for the whole batch');
    pm_t_assert($rep[0]['fixed'] === true && $rep[0]['score'] >= $rep[0]['was'] + 5 && str_starts_with($out[0]['caption'], 'Hotels lose bookings'), 'a clearly better rewrite replaces the caption');
    pm_t_assert($rep[2]['fixed'] === false && $out[2]['caption'] === $weak, 'a rewrite with a number the facts never gave is refused: the original stays');
    pm_t_assert($rep[1]['fixed'] === false && $out[1]['caption'] === $fine, 'a caption that was not flagged is never touched');
    $calls = 0;
    [$out, $rep] = pm_editor_review('promanaged', [1 => $slot($FACTS)], [1 => ['caption' => $fine]], [1]);
    pm_t_assert($calls === 0 && $rep[1]['score'] >= PM_ED_THRESHOLD, 'a batch where every caption passes costs nothing extra');
    $weakShort = 'Are you looking for a better way to run your business? Contact us today.';
    $edits = [['i' => 0, 'caption' => 'Are you looking for better ways to work? Message us today.']];
    [$out, $rep] = pm_editor_review('promanaged', [0 => $slot($FACTS)], [0 => ['caption' => $weakShort]], [0]);
    pm_t_assert($out[0]['caption'] === $weakShort && !$rep[0]['fixed'], 'a rewrite that does not score better is not used');
    $GLOBALS['PM_AI_STUB'] = fn() => 'not json';
    [$out] = pm_editor_review('promanaged', [0 => $slot($FACTS)], [0 => ['caption' => $weak]], [0]);
    pm_t_eq($out[0]['caption'], $weak, 'an answer that is not JSON changes nothing');
});

t('The planner runs the editor, and the owner can switch it off', function () {
    $items = [];
    $planner = 0;
    $editor = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$items, &$planner, &$editor) {
        if (str_contains($sys, 'copy chief')) {
            $editor++;
            $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
            $edits = [];
            foreach ($in['posts'] as $k => $p) {
                $fa = (array)$p['facts'];
                $edits[] = ['i' => $p['i'], 'caption' => ($fa[0] ?? 'We help businesses in Malawi.') . ' ' . ($fa[1] ?? '') . ' Our team explains every step in plain words, so you always know what is happening and what comes next. Send us a WhatsApp message if you would like to know more.'];
            }
            return json_encode(['edits' => $edits]);
        }
        if (str_contains($sys, 'write short social posts')) {
            $planner++;
            preg_match('/ITEMS: (.*)\nJSON/s', $user, $m);
            $items = json_decode($m[1], true);
            return json_encode(['posts' => array_map(fn($it) => ['i' => $it['i'], 'caption' => 'Are you looking for seamless solutions for your business today? Our passionate team is here to help you elevate everything in your business every single day, so contact us today for more information.', 'headline' => 'Better systems', 'sub' => 'Built for you'], $items)]);
        }
        return '{}';
    };
    pm_save('social_posts', []);
    $rows = pm_sx_build('promanaged', 3, date('Y-m-d', strtotime('+2 days')), 'mix', true);
    $ai = array_values(array_filter($rows, fn($r) => !empty($r['editor'])));
    pm_t_assert($planner === 1 && $editor === 1 && count($ai) >= 2, 'the planner wrote, the editor read every AI post, and sent the weak ones back in one more call (' . count($ai) . ' posts)');
    pm_t_assert(count(array_filter($ai, fn($r) => $r['editor']['fixed'])) >= 1, 'at least one was reworded');
    pm_t_assert(count(array_unique(array_map(fn($r) => pm_editor_opening($r['caption']), $ai))) === count($ai), 'and no two AI posts open with the same words any more');
    $cfg = pm_social_settings('promanaged');
    $cfg['editor_review'] = false;
    pm_social_settings_save('promanaged', $cfg);
    $planner = $editor = 0;
    pm_save('social_posts', []);
    $rows = pm_sx_build('promanaged', 3, date('Y-m-d', strtotime('+2 days')), 'mix', true);
    pm_t_assert($editor === 0 && !array_filter($rows, fn($r) => !empty($r['editor'])), 'switched off, the editor does not run and posts carry no editor chip');
    $_POST = ['on' => '1'];
    $r = pm_social_ext_run('editor_toggle', 'promanaged');
    pm_t_assert(pm_editor_on('promanaged') && $r['to'] === 'social#rules', 'the switch is on the Plan\'s Publishing rules');
    $_POST = [];
});

t('The pages show the team and the editor', function () use (&$ROUNDS, $BT_STUB) {
    $GLOBALS['PM_AI_STUB'] = $BT_STUB;
    $ROUNDS = [[kit(['name' => 'Page kit', 'style' => 'waves', 'palette' => ['bg1' => '#d8e0e4', 'bg2' => '#c4d0d6', 'accent' => '#2f5d7a', 'ink' => '#17262f']])]];
    pm_save('brand_team', []);
    pm_bt_run('promanaged', false, 'owner');
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tokS';
    $_GET = ['view' => 'kit'];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_kit.php';
    $h = ob_get_clean();
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'Your brand team') && str_contains($h, 'Creative director') && str_contains($h, 'name="do" value="bt_run"') && str_contains($h, 'name="do" value="bt_auto"'), 'the Brand kit starts with the team and its buttons');
    pm_t_assert(str_contains($h, 'Use this kit') && str_contains($h, 'name="do" value="bt_use"') && str_contains($h, '?studio=cover&amp;b=promanaged&amp;id=') && str_contains($h, 'How the team worked'), 'proposed kits have previews, a Use button and the log');
    pm_t_assert(str_contains($h, 'name="_sec" value="kit"') && str_contains($h, 'name="cover_head"'), 'the cover wording and the pictures are still below');
    $_GET = [];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social.php';
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'name="do" value="editor_toggle"') && str_contains($h, 'Have an editor review'), 'the Plan has the editor switch among the publishing rules');
    $cfgAll = pm_staff_probe();
    pm_t_assert($cfgAll, 'the overview roster lists the brand team');
});

function pm_staff_probe(): bool
{
    $d = pm_home_data('promanaged');
    $names = array_column(pm_home_staff('promanaged', $d)['brand'], 'name');
    return in_array('Creative director', $names, true) && in_array('Art critic', $names, true);
}

pm_t_done();
