<?php
/**
 * Tests for today's engagement playbook staying current: a past day's playbook is carried over (what is done is dropped, no AI), written again only when the people or leads changed,
 * a quiet day still gives a playbook for today, a failed try is not repeated every page load, the morning job, and the Growth screen refreshing it. Facebook and the AI are stubbed.
 * Run: php tests/test_playbook.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
$GLOBALS['PM_GRAPH_STUB'] = fn() => [true, ['data' => []]];
pm_save('engage_playbook', ['none' => []]);
pm_save('leads', []);

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$store = fn() => (array)(pm_load('engage_playbook', fn() => [])['promanaged'] ?? []);
$tasks = fn() => [['n' => 0, 'ref' => 'l:a', 'who' => 'Grace', 'why' => 'wrote back', 'when' => '09:00', 'channel' => 'whatsapp', 'message' => 'Hello Grace', 'goal' => 'a call', 'link' => '', 'done' => true],
    ['n' => 1, 'ref' => 'l:b', 'who' => 'Peter', 'why' => 'quiet', 'when' => '10:30', 'channel' => 'email', 'message' => 'Hello Peter', 'goal' => 'a reply', 'link' => '', 'done' => false],
    ['n' => 2, 'ref' => 'l:c', 'who' => 'Joyce', 'why' => 'asked', 'when' => '14:00', 'channel' => 'call', 'message' => 'Hello Joyce', 'goal' => 'a visit', 'link' => '', 'done' => false]];

t('A past playbook is carried over without AI', function () use ($today, $yesterday, $tasks) {
    $pb = ['at' => $yesterday . ' 08:00', 'day' => $yesterday, 'sig' => 'x', 'focus' => 'Answer buyers first', 'tasks' => $tasks(), 'moves' => [['when' => '18:00', 'what' => 'Post a tip']], 'times' => ['hours' => [18], 'note' => 'n']];
    $c = pm_playbook_carry($pb, $today);
    pm_t_assert($c['day'] === $today && $c['carried'] === 2 && $c['carried_from'] === $yesterday && array_column($c['tasks'], 'who') === ['Peter', 'Joyce'] && array_column($c['tasks'], 'n') === [0, 1], 'what was done is gone, what is open stays, and it is renumbered');
    pm_t_assert($c['focus'] === 'Answer buyers first' && count($c['moves']) === 1, 'the focus and the page moves stay while tasks remain');
    $all = $pb;
    foreach ($all['tasks'] as &$t) {
        $t['done'] = true;
    }
    unset($t);
    $e = pm_playbook_carry($all, $today);
    pm_t_assert($e['tasks'] === [] && $e['carried'] === 0 && str_contains($e['focus'], 'Nothing new today') && $e['moves'] === [], 'with everything done it says so instead of repeating itself');
    pm_t_eq(pm_playbook_carry($c, $today), $c, 'the same day changes nothing');
});

t('The playbook is made current by itself', function () use ($today, $yesterday, $store) {
    pm_t_eq(pm_playbook_ensure('travel'), 'no Page connected', 'a business with no Page is left alone');
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls) {
        $calls++;
        return json_encode(['focus' => 'Reply to the people who wrote.', 'tasks' => [['ref' => 'l:rp1', 'who' => 'Grace Phiri', 'why' => 'She answered our email', 'when' => '09:30', 'channel' => 'whatsapp', 'message' => 'Hello Grace, thank you for writing back. Could we speak this week?', 'goal' => 'Book a call']],
            'page_moves' => [['when' => '18:00', 'what' => 'Post a tip']]]);
    };
    $ago = fn(int $d) => date('Y-m-d H:i', strtotime("-$d days"));
    pm_save('leads', ['rp1' => ['id' => 'rp1', 'brand' => 'promanaged', 'name' => 'Grace Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'status' => 'replied', 'email' => 'g@x.mw', 'phone' => '', 'contact' => 'Grace Phiri', 'updated' => $ago(1), 'notes' => [], 'thread' => [], 'sent' => []]]);
    pm_t_eq(pm_playbook_ensure('promanaged'), '1 task(s)', 'with a warm lead and no playbook, one is written');
    pm_t_assert($calls === 1 && $store()['day'] === $today && count($store()['tasks']) === 1 && $store()['sig'] !== '', 'one AI call, today\'s playbook, with its fingerprint');
    pm_t_eq(pm_playbook_ensure('promanaged'), 'current', 'asked again the same day it does nothing');
    // tomorrow, nothing changed
    pm_update('engage_playbook', function ($all) use ($yesterday) {
        $all['promanaged']['day'] = $yesterday;
        $all['promanaged']['at'] = $yesterday . ' 07:00';
        $all['promanaged']['tasks'][0]['done'] = false;
        return $all;
    }, fn() => []);
    pm_t_eq(pm_playbook_ensure('promanaged'), '1 task(s), 1 carried over', 'the next day, with nothing new, the open task is carried over');
    pm_t_assert($calls === 1 && $store()['day'] === $today && $store()['carried'] === 1, 'with no new AI call');
    // the day after, and Grace's task was done
    pm_update('engage_playbook', function ($all) use ($yesterday) {
        $all['promanaged']['day'] = $yesterday;
        $all['promanaged']['tasks'][0]['done'] = true;
        unset($all['promanaged']['tried']);
        return $all;
    }, fn() => []);
    pm_t_eq(pm_playbook_ensure('promanaged'), '0 task(s)', 'a day after everything was done the playbook is empty, honestly');
    pm_t_assert($calls === 1 && str_contains($store()['focus'], 'Nothing new today'), 'again without AI');
    // the people changed: a new warm lead
    pm_update('engage_playbook', function ($all) use ($yesterday) {
        $all['promanaged']['day'] = $yesterday;
        unset($all['promanaged']['tried']);
        return $all;
    }, fn() => []);
    $l = pm_leads();
    $l['rp2'] = ['id' => 'rp2', 'brand' => 'promanaged', 'name' => 'Second Lodge', 'type' => 'lodge', 'city' => 'Mzuzu', 'status' => 'replied', 'email' => 's@x.mw', 'phone' => '', 'contact' => 'Peter', 'updated' => $ago(0), 'notes' => [], 'thread' => [], 'sent' => []];
    pm_save('leads', $l);
    pm_playbook_ensure('promanaged');
    pm_t_assert($calls === 2 && $store()['day'] === $today && empty($store()['carried_from']), 'a new warm lead means a new playbook, written with the AI once');
    // a lead that has gone quiet for longer is news too (the fingerprint includes how long they have been quiet)
    $sig1 = $store()['sig'];
    $l = pm_leads();
    $l['rp1']['updated'] = $ago(7);
    pm_save('leads', $l);
    pm_update('engage_playbook', function ($all) use ($yesterday) {
        $all['promanaged']['day'] = $yesterday;
        unset($all['promanaged']['tried']);
        return $all;
    }, fn() => []);
    pm_playbook_ensure('promanaged');
    pm_t_assert($store()['sig'] !== $sig1 && $calls === 3, 'a lead that has been quiet for days changes the fingerprint');
});

t('A quiet day, a failed try, and the morning job', function () use ($today, $yesterday, $store) {
    pm_save('leads', []);
    pm_save('engage_playbook', ['promanaged' => ['at' => $yesterday . ' 08:00', 'day' => $yesterday, 'sig' => 'old', 'focus' => 'x', 'tasks' => [], 'moves' => [], 'times' => []]]);
    pm_t_eq(pm_playbook_ensure('promanaged'), 'quiet day', 'with nobody to engage, it still becomes today\'s playbook');
    pm_t_assert($store()['day'] === $today && $store()['tasks'] === [] && str_contains($store()['focus'], 'Nobody to engage today'), 'and says so');
    // a failing AI is not retried on every page load
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function () use (&$calls) {
        $calls++;
        throw new RuntimeException('AI down');
    };
    pm_save('leads', ['q' => ['id' => 'q', 'brand' => 'promanaged', 'name' => 'Q Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'status' => 'replied', 'email' => 'q@x.mw', 'phone' => '', 'contact' => '', 'updated' => date('Y-m-d H:i'), 'notes' => [], 'thread' => [], 'sent' => []]]);
    pm_save('engage_playbook', ['promanaged' => ['at' => $yesterday . ' 08:00', 'day' => $yesterday, 'sig' => 'old', 'focus' => 'x', 'tasks' => [], 'moves' => [], 'times' => []]]);
    $r = pm_playbook_ensure('promanaged');
    pm_t_assert(str_starts_with($r, 'could not: ') && $calls === 1, 'a failed try is reported: ' . $r);
    pm_t_eq([pm_playbook_ensure('promanaged'), $calls], ['tried recently', 1], 'and not repeated on the next page load');
    pm_t_eq($store()['day'], $yesterday, 'the old playbook is kept meanwhile');
    // the morning job
    $GLOBALS['PM_AI_STUB'] = null;
    pm_save('engage_playbook', ['promanaged' => ['at' => $yesterday . ' 08:00', 'day' => $yesterday, 'sig' => 'old', 'focus' => 'x', 'tasks' => [], 'moves' => [], 'times' => []]]);
    pm_save('leads', []);
    $out = pm_job_playbook('promanaged');
    $h = (int)date('G');
    pm_t_assert(($h >= 6 && $h <= 18 && $out === 'playbook quiet day') || (($h < 6 || $h > 18) && $out === ''), 'the scheduler job refreshes it in the morning and afternoon, and rests at night: "' . $out . '"');
    pm_t_eq(pm_job_playbook('promanaged'), '', 'and says nothing once it is current');
});

t('The Growth screen', function () use ($today, $yesterday, $tasks) {
    pm_save('leads', []);
    pm_save('engage_playbook', ['promanaged' => ['at' => $yesterday . ' 08:00', 'day' => $yesterday, 'sig' => 'old', 'focus' => 'x', 'tasks' => $tasks(), 'moves' => [], 'times' => ['hours' => [], 'note' => 'n']]]);
    pm_brand_set('promanaged');
    $want = $today; // (the view uses $today for its own list)
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tok1';
    $_GET = ['view' => 'growth'];
    ob_start();
    try {
        require dirname(__DIR__) . '/lib/view_social_growth.php';
    } catch (Throwable $e) {
        echo 'ERR ' . $e->getMessage();
    }
    $h = ob_get_clean();
    $pb = (array)(pm_load('engage_playbook', fn() => [])['promanaged'] ?? []);
    pm_t_assert($pb['day'] === $want, 'opening the screen made the playbook today\'s');
    pm_t_assert(!str_contains($h, 'This playbook is from') && !str_contains($h, 'ERR '), 'it does not tell the owner to do it by hand');
});

pm_t_done();
