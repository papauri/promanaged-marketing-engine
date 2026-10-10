<?php
/**
 * Tests for the suggestions that appear while typing in "find a business": what we already know (ranked, one row per business, the right business's lead opens),
 * and the web search (filtered, cached, limited, never an error). Runs on a TEMP COPY of data/ with the AI stubbed.
 * Run: php tests/test_suggest.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

$GLOBALS['mkLead'] = $mk = fn(string $id, string $brand, string $name, string $city, array $x = []) => $x + ['id' => $id, 'brand' => $brand, 'name' => $name, 'type' => 'hotel', 'city' => $city, 'status' => 'drafted', 'score' => 70];
pm_save('leads', [
    'a1' => $mk('a1', 'promanaged', 'Sunbird Capital Hotel', 'Lilongwe', ['score' => 90]),
    'a2' => $mk('a2', 'promanaged', 'The Sunset Lodge', 'Mangochi', ['score' => 60]),
    'a3' => $mk('a3', 'promanaged', 'Mount Sunrise Cafe', 'Zomba'),
    'a4' => $mk('a4', 'promanaged', 'Blend Lodge & Kitchen', 'Zomba', ['status' => 'qualified']),
    't1' => $mk('t1', 'travel', 'Sunbird Livingstonia Beach', 'Salima', ['type' => 'resort']),
    't2' => $mk('t2', 'travel', 'Blend Lodge & Kitchen', 'Zomba'),
]);
pm_save('suggest_cache', []); // the copy of the real data may hold real searches
pm_save('leads_archive', ['z1' => $mk('z1', 'promanaged', 'Sungrove Guest House', 'Blantyre', ['status' => 'lost'])]);
pm_save('history', [['ref' => 'PM-1', 'business' => 'Sunbird Capital Hotel', 'status' => 'Emailed'], ['ref' => 'PM-2', 'business' => 'Sunway Pharmacy', 'status' => 'Emailed']]);
$ac = pm_load('agents_config', 'pm_agents_default_config');
$ac['existing_clients'] = ['Sunflower Bakery'];
pm_save('agents_config', $ac);

t('Matching: starts with, every word starts a word, appears inside, and nothing else', function () {
    pm_t_eq([pm_suggest_rank('sunb', 'Sunbird Capital Hotel'), pm_suggest_rank('SUNBIRD cap', 'Sunbird Capital Hotel'), pm_suggest_rank('cap hot', 'Sunbird Capital Hotel'), pm_suggest_rank('bird', 'Sunbird Capital Hotel'), pm_suggest_rank('zzz', 'Sunbird Capital Hotel')],
        [0, 0, 1, 2, null], 'prefix 0, a prefix typed in any case 0, each word a word-start 1, inside 2, no match null');
    pm_t_eq([pm_suggest_rank('blend lodge &', 'Blend Lodge & Kitchen'), pm_suggest_rank('', 'x'), pm_suggest_rank('x', '')], [0, null, null], 'punctuation does not matter, and empty never matches');
});

t('What we already know, best first, across every business', function () {
    $r = pm_suggest_local('sun', 'promanaged');
    $names = array_column($r, 'name');
    pm_t_eq($names[0], 'Sunbird Capital Hotel', 'the best-scoring name that starts with it is first');
    pm_t_assert(in_array('Sunbird Livingstonia Beach', $names, true) && in_array('Sungrove Guest House', $names, true) && in_array('Sunway Pharmacy', $names, true) && in_array('Sunflower Bakery', $names, true), 'leads of the other business, archived leads, businesses we sent a proposal to and existing clients all appear');
    pm_t_assert(array_search('Mount Sunrise Cafe', $names) > array_search('Sungrove Guest House', $names), 'a name that only has the text inside a later word comes after names that start with it');
    pm_t_eq(count(array_keys($names, 'Sunbird Capital Hotel', true)), 1, 'a business that is a lead and also in an old proposal is one suggestion, not two');
    $by = array_column($r, null, 'name');
    pm_t_assert(str_starts_with($by['Sunbird Capital Hotel']['href'], '?tab=agents&brand=promanaged&lead=a1') && $by['Sunbird Capital Hotel']['meta'] === 'Your lead · Lilongwe · hotel · drafted', 'your own lead opens that lead, and says what it is: ' . $by['Sunbird Capital Hotel']['meta']);
    pm_t_assert($by['Sunbird Livingstonia Beach']['href'] === '' && $by['Sunbird Livingstonia Beach']['meta'] === 'Lead of Travel Malawi · Salima · resort · drafted', 'a lead of the other business only fills the box: ' . $by['Sunbird Livingstonia Beach']['meta']);
    pm_t_eq([$by['Sungrove Guest House']['meta'], $by['Sunway Pharmacy']['meta'], $by['Sunflower Bakery']['meta']], ['Archived lead · Blantyre · hotel', 'You sent a proposal', 'Existing client'], 'archived leads, proposals and clients are labelled as such');
    $tv = pm_suggest_local('blend', 'travel');
    pm_t_assert(count($tv) === 1 && str_contains($tv[0]['href'], 'brand=travel&lead=t2'), 'the same name in two businesses opens the lead of the business on screen: ' . json_encode($tv));
    pm_t_eq(array_column(pm_suggest_local('blend', 'promanaged'), 'name'), ['Blend Lodge & Kitchen'], 'and is one row either way');
    pm_t_eq([pm_suggest_local('s', 'promanaged'), pm_suggest_local('', 'promanaged'), pm_suggest_local(str_repeat('a', 90), 'promanaged')], [[], [], []], 'one letter, nothing, or a very long text gives nothing');
    pm_t_eq(pm_suggest_local('zzzqqq', 'promanaged'), [], 'no match gives nothing');
    pm_t_eq(count(pm_suggest_local('s', 'promanaged', 3)) + count(pm_suggest_local('su', 'promanaged', 3)), 3, 'the list is capped');
});

t('The web search: real names that match, held to the same rules, cached, limited and never an error', function () {
    $calls = 0;
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user, $tier = '') use (&$calls, &$seen) {
        $calls++;
        $seen = $sys . $user;
        return json_encode([
            ['name' => 'Kuthengo Lodge', 'city' => 'Mangochi', 'type' => 'lodge', 'website' => 'https://www.kuthengo-lodge.example/rooms'],
            ['name' => 'The Kuthengo Camp <b>', 'city' => 'Zomba<script>', 'type' => 'camp', 'website' => 'not a site'],
            ['name' => 'Unrelated Hotel', 'city' => 'Blantyre', 'type' => 'hotel', 'website' => ''],
            ['name' => 'Kuthengo Lodge', 'city' => 'Mangochi', 'type' => 'lodge', 'website' => ''],
            'junk', ['name' => ['x']],
        ]);
    };
    $r = pm_suggest_web('kuthe', 'promanaged');
    pm_t_eq($calls, 1, 'one AI call');
    pm_t_eq(array_column($r, 'name'), ['Kuthengo Lodge', 'The Kuthengo Camp'], 'names that do not match what was typed, repeats and junk are dropped, and tags are stripped');
    pm_t_assert($r[0]['meta'] === 'From a web search · Mangochi · lodge · kuthengo-lodge.example' && $r[0]['href'] === '' && !str_contains($r[1]['city'], '<') && $r[1]['site'] === '', 'each is labelled as from a web search, shows its website host, never opens a lead, and an address that is not one is dropped: ' . $r[0]['meta']);
    pm_t_assert(str_contains($seen, 'whose name starts with or contains') && str_contains($seen, 'Never invent') && str_contains($seen, 'kuthe'), 'the AI is told to list only businesses a page confirms, and never to invent one');
    pm_suggest_web('kuthe', 'promanaged');
    pm_suggest_web('  KUTHE ', 'promanaged');
    pm_t_eq($calls, 1, 'asking again for the same text, in any case or spacing, uses the cache (no second AI call)');
    pm_suggest_web('kuthe', 'travel');
    pm_t_assert($calls === 2 && str_contains($seen, 'places to stay in Malawi'), 'another business asks again, and Travel Malawi only asks for places to stay');
    // anything we already know is not repeated as a web suggestion
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode([['name' => 'Sunbird Capital Hotel', 'city' => 'Lilongwe', 'type' => 'hotel', 'website' => ''], ['name' => 'Sunbird Mzuzu Hotel', 'city' => 'Mzuzu', 'type' => 'hotel', 'website' => '']]);
    pm_t_eq(array_column(pm_suggest_web('sunbird', 'promanaged'), 'name'), ['Sunbird Mzuzu Hotel'], 'a business already in your leads is not offered again from the web');
    // too short, too long, no AI, an AI that fails or answers badly
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function () use (&$calls) { $calls++; return '[]'; };
    pm_t_eq([pm_suggest_web('ab', 'promanaged'), pm_suggest_web('abc', 'promanaged'), pm_suggest_web(str_repeat('z', 61), 'promanaged')], [[], [], []], 'under 4 characters (or over 60) is never sent to the AI');
    pm_t_eq($calls, 0, 'so no AI call is made');
    $GLOBALS['PM_AI_STUB'] = fn() => throw new RuntimeException('quota exceeded');
    pm_t_eq(pm_suggest_web('failing', 'promanaged'), [], 'an AI that fails gives an empty list, not an error');
    $GLOBALS['PM_AI_STUB'] = fn() => 'Sorry, I cannot help.';
    pm_t_eq(pm_suggest_web('garbage', 'promanaged'), [], 'and so does one that does not answer in JSON');
    $GLOBALS['PM_AI_STUB'] = null;
    pm_t_eq(pm_suggest_web('no ai here', 'promanaged'), [], 'with no AI set up there is simply no web part');
    // the hourly limit: 60 fresh searches, then nothing until the hour passes
    $GLOBALS['PM_AI_STUB'] = function () use (&$calls) { $calls++; return '[]'; };
    $calls = 0;
    for ($i = 0; $i < 70; $i++) {
        pm_suggest_web('limit test ' . $i, 'promanaged');
    }
    pm_t_assert($calls >= 40 && $calls <= 60, "no more than 60 web searches an hour ($calls made of 70 tried)");
    $GLOBALS['PM_AI_STUB'] = null;
});

t('A business a web search found once is suggested at once next time, without asking again', function () {
    $r = pm_suggest_local('kuthen', 'promanaged');
    $by = array_column($r, null, 'name');
    pm_t_assert(isset($by['Kuthengo Lodge']) && $by['Kuthengo Lodge']['meta'] === 'Found on the web before · Mangochi · lodge' && $by['Kuthengo Lodge']['href'] === '', 'it comes from what was found before, labelled so, and only fills the box: ' . json_encode($r));
    pm_t_eq(count(array_keys(array_column($r, 'name'), 'Kuthengo Lodge', true)), 1, 'once, even though two searches found it');
    pm_save('leads', ['k1' => $GLOBALS['mkLead']('k1', 'promanaged', 'Kuthengo Lodge', 'Mangochi')] + pm_leads());
    $r = pm_suggest_local('kuthen', 'promanaged');
    $by = array_column($r, null, 'name');
    pm_t_assert($by['Kuthengo Lodge']['href'] !== '' && str_starts_with($by['Kuthengo Lodge']['meta'], 'Your lead'), 'and once it is your lead, the lead wins over the memory of the search');
});

pm_t_done();
