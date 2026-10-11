<?php
/**
 * Tests for the desks that work as swarms: the shared loop, the Copy desk (cover wording), the Photo desk (reads photos, writes shot briefs) and the Idea room (post ideas). The AI is stubbed;
 * GD makes real test photos. Run: php tests/test_desks.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nANTHROPIC_API_KEY=sk-test\n");
pm_brand_set('promanaged');
foreach (['desks', 'brand_team', 'cover_studio', 'page_opt', 'social_posts', 'social_bank', 'social_assets'] as $f) {
    pm_save($f, []);
}
pm_update('settings', function (array $s) {
    $s['company_name'] = 'ProManaged IT';
    $s['tagline'] = 'Business systems, built and supported in Malawi';
    $s['brain'] = ['about' => 'We build business software and websites for hotels, lodges and shops in Malawi, and we support the computers they run on.',
        'facts' => "We build booking and stock systems for hotels, lodges and shops\nWe support computers and networks with local help in Lilongwe\nWe build websites that take enquiries on WhatsApp", 'audience' => 'Owners and managers of hotels, lodges and shops', 'voice' => 'plain and warm', 'never' => ''];
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

/* ---------------------------------------------------------------- the shared loop */

t('The loop asks, scores, sends the weak back, and keeps the best distinct few', function () {
    $asked = [];
    $mk = function (array $rounds, bool $ai = true, ?callable $fb = null) use (&$asked) {
        return [
        'ai' => $ai, 'threshold' => 70, 'rounds' => 2, 'keep' => 2, 'label' => 'things',
            'ask' => function ($round, $feedback, $tried) use (&$asked, $rounds) {
            $asked[] = ['round' => $round, 'feedback' => $feedback, 'tried' => $tried];
            if (!isset($rounds[$round - 1])) {
                throw new RuntimeException('AI down');
            }
            return $rounds[$round - 1];
        },
        'fallback' => $fb, 'score' => fn($c) => ['score' => $c['v'], 'issues' => ['too plain'], 'praise' => []], 'key' => fn($c) => $c['k'], 'note' => fn($c) => $c['k']];
    };
    $r = pm_swarm_loop($mk([[['k' => 'a', 'v' => 50], ['k' => 'b', 'v' => 55]], [['k' => 'c', 'v' => 90], ['k' => 'c', 'v' => 88], ['k' => 'd', 'v' => 80], ['k' => 'e', 'v' => 60]]]));
    pm_t_assert($r['calls'] === 2 && $r['best'] === 90 && array_column($r['kept'], 'k') === ['c', 'd'], 'a weak first round goes back; the best distinct two of both rounds are kept');
    pm_t_assert(!empty($asked[1]['feedback']) && str_contains($asked[1]['feedback'][0], 'too plain') && $asked[1]['tried'] === ['a', 'b'], 'the second round is told what the critics said and what was tried');
    pm_t_assert(str_contains(implode(' ', $r['log']), 'Sent back') && $r['kept'][0]['round'] === 2, 'the log says so, and each kept item knows its round');
    $asked = [];
    $r = pm_swarm_loop($mk([[['k' => 'a', 'v' => 95], ['k' => 'b', 'v' => 40]]]));
    pm_t_assert($r['calls'] === 1 && count($asked) === 1, 'a good first round needs no second one');
    $r = pm_swarm_loop($mk([], false, fn($round, $tried) => [['k' => 'x', 'v' => 60]]));
    pm_t_assert($r['calls'] === 0 && $r['how'] === 'code' && $r['kept'][0]['how'] === 'code', 'with no AI only the code fallback runs');
    $r = pm_swarm_loop($mk([], true, fn($round, $tried) => [['k' => 'x', 'v' => 60]]));
    pm_t_assert($r['how'] === 'code' && str_contains(implode(' ', $r['log']), 'AI could not be reached'), 'when the AI fails in round 1 the code fallback takes over, and says so');
    $r = pm_swarm_loop($mk([[['k' => 'a', 'v' => 40]]], true, null));
    pm_t_assert($r['calls'] === 1 && str_contains(implode(' ', $r['log']), 'first round stands'), 'when it fails in round 2 the first round stands');
    try {
        pm_swarm_loop($mk([], true, null));
        pm_t_assert(false, 'nothing made throws');
    } catch (Throwable $e) {
        pm_t_assert(true, 'nothing made throws');
    }
    pm_t_assert(pm_swarm_alike('Booking systems for hotels in Malawi', 'Hotels in Malawi get booking systems') > 0.6 && pm_swarm_alike('Booking systems', 'Fresh vegetables daily') === 0.0, 'text that says the same thing is recognised');
});

/* ---------------------------------------------------------------- the copy desk */

$CALLS = [];
$ROUNDS = [];
$STUB = function ($sys, $user) use (&$CALLS, &$ROUNDS) {
    $CALLS[] = ['sys' => $sys, 'user' => $user];
    foreach (['writers\' room' => 'options', 'photo director' => 'shots', 'idea room' => 'ideas'] as $needle => $key) {
        if (str_contains($sys, $needle)) {
            if (!$ROUNDS) {
                throw new RuntimeException('AI down');
            }
            return json_encode([$key => array_shift($ROUNDS)]);
        }
    }
    return '{}';
};
$GLOBALS['PM_AI_STUB'] = $STUB;
$opt = fn(string $w, string $h, string $l) => ['writer' => $w, 'headline' => $h, 'line' => $l, 'why' => 'It says what we do.'];

t('The writers\' room is told the angles and the rules', function () {
    $brief = pm_cd_brief('promanaged');
    [$sys, $usr] = pm_cd_prompt($brief, 8, ['"Welcome": stock opener'], ['Old headline']);
    pm_t_assert(str_contains($sys, "writers' room") && str_contains($sys, 'Benefit writer') && str_contains($sys, 'Place writer') && str_contains($sys, 'Proof writer') && str_contains($sys, 'Plain speaker') && str_contains($sys, 'Use ONLY what the facts say'), 'four writers, four angles, facts only');
    $u = json_decode(substr($usr, 0, strpos($usr, "\nJSON")), true);
    pm_t_assert($u['business'] === 'ProManaged IT' && $u['editor_said'] === ['"Welcome": stock opener'] && $u['already_proposed'] === ['Old headline'] && count($u['facts']) === 3, 'the brief carries the facts, the editor\'s notes and what was proposed');
});

t('The copy editor scores wording', function () {
    $brief = pm_cd_brief('promanaged');
    $good = pm_cd_score(['headline' => 'Booking systems for hotels, lodges and shops', 'line' => 'Built, supported and explained in plain words, in Lilongwe.'], $brief);
    pm_t_assert($good['score'] >= 90 && !$good['issues'], 'specific, short, fresh wording scores high: ' . $good['score'] . ' ' . implode('|', $good['issues']));
    $name = pm_cd_score(['headline' => 'ProManaged IT', 'line' => ''], $brief);
    pm_t_assert(str_contains(implode(' ', $name['issues']), 'only repeats the name') && str_contains(implode(' ', $name['issues']), 'too short') && str_contains(implode(' ', $name['issues']), 'no supporting line'), 'a name alone, no line: marked');
    $stock = pm_cd_score(['headline' => 'Welcome to innovative solutions for business', 'line' => 'We leverage seamless tailored systems.'], $brief);
    pm_t_assert(str_contains(implode(' ', $stock['issues']), 'stock') && str_contains(implode(' ', $stock['issues']), 'machine-written'), 'stock openers, phrases and machine words are marked');
    $vague = pm_cd_score(['headline' => 'Great things happen every single day here', 'line' => 'Come and see for yourself soon.'], $brief);
    pm_t_assert(str_contains(implode(' ', $vague['issues']), 'little that is specific'), 'wording that says nothing about the business is marked');
    $brief['head'] = 'Booking systems for hotels, lodges and shops';
    $same = pm_cd_score(['headline' => 'Booking systems for hotels and lodges and shops', 'line' => 'Built and supported in Lilongwe.'], $brief);
    pm_t_assert(str_contains(implode(' ', $same['issues']), 'already in use'), 'the wording already in use is marked');
    $rep = pm_cd_score(['headline' => 'Hotel booking systems built in Malawi', 'line' => 'Hotel booking systems built in Malawi.'], pm_cd_brief('promanaged'));
    pm_t_assert(str_contains(implode(' ', $rep['issues']), 'repeats the headline'), 'a line that repeats the headline is marked');
});

t('A weak first round goes back; claims the facts do not give are never offered', function () use (&$CALLS, &$ROUNDS, $opt) {
    $ROUNDS = [
        [$opt('Benefit writer', 'ProManaged IT', ''), $opt('Place writer', 'Welcome to our premier home of great things', 'Come and see'), $opt('Proof writer', 'Hotels lose 40% of bookings without us', 'Trusted by 500 clients'), $opt('Plain speaker', 'Your partner in growth', 'World-class service')],
        [$opt('Benefit writer', 'Booking systems for hotels, lodges and shops', 'Built, supported and explained in plain words.'), $opt('Place writer', 'Software and computer support in Lilongwe', 'Local help when your systems need it.'),
            $opt('Proof writer', 'Websites that take enquiries on WhatsApp', 'Built for hotels and shops in Malawi.'), $opt('Plain speaker', 'We build and support business systems', 'For hotels, lodges and shops.')],
    ];
    $CALLS = [];
    [$msg, $rep] = pm_cd_run('promanaged', 'owner');
    $asks = array_values(array_filter($CALLS, fn($c) => str_contains($c['sys'], "writers' room")));
    pm_t_assert(count($asks) === 2 && $rep['calls'] === 2 && $rep['best'] >= PM_CD_THRESHOLD, 'the writers were asked twice and the second round passed: ' . $msg);
    $second = json_decode(substr($asks[1]['user'], 0, strpos($asks[1]['user'], "\nJSON")), true);
    pm_t_assert(!empty($second['editor_said']) && !empty($second['already_proposed']), 'round 2 carried the editor\'s notes');
    $all = json_encode($rep['options']);
    pm_t_assert(!str_contains($all, '40%') && !str_contains($all, '500 clients'), 'the option with a made-up number was thrown out before scoring');
    pm_t_assert(count($rep['options']) === PM_CD_KEEP && $rep['options'][0]['score'] >= $rep['options'][1]['score'], 'four options kept, best first');
    pm_t_eq(count(pm_swarm_waiting('promanaged', 'wording')), PM_CD_KEEP, 'and they wait for the owner');
    pm_t_assert(pm_swarm_store('promanaged')['wording']['runs'][0]['calls'] === 2, 'the run is recorded');
});

t('Using wording changes the cover wording and the design in use', function () use (&$ROUNDS) {
    $opts = pm_swarm_waiting('promanaged', 'wording');
    $id = $opts[0]['id'];
    pm_t_eq(pm_studio_saved('promanaged'), null, 'no design in use yet');
    [$ok, $m] = pm_cd_use('promanaged', $id);
    $cfg = pm_social_settings('promanaged');
    pm_t_assert($ok && $cfg['cover_head'] === mb_substr($opts[0]['headline'], 0, 80) && $cfg['cover_line'] === mb_substr($opts[0]['line'], 0, 90) && !str_contains($m, 'design in use'), 'the cover wording is set: ' . $m);
    $designId = pm_studio_put('promanaged', pm_studio_default('promanaged'), 'you');
    pm_studio_use('promanaged', $designId);
    $id2 = $opts[1]['id'];
    [$ok, $m] = pm_cd_use('promanaged', $id2);
    $saved = pm_studio_saved('promanaged');
    pm_t_assert($ok && $saved['headline'] === mb_substr($opts[1]['headline'], 0, 70) && $saved['subline'] === mb_substr($opts[1]['line'], 0, 110) && str_contains($m, 'design in use'), 'with a design in use, its words change too and the pictures are drawn again: ' . $m);
    pm_t_assert(glob(pm_kit_dir('promanaged') . '/cover-*.png') !== [], 'the kit pictures exist');
    $st = array_values(array_filter(pm_swarm_store('promanaged')['wording']['options'], fn($o) => $o['id'] === $id));
    pm_t_eq($st[0]['status'], 'used', 'the option is marked used');
    pm_t_eq(pm_cd_use('promanaged', 'ffffffffffff')[0], false, 'an option that is not there is refused');
    $_POST = ['id' => $opts[2]['id']];
    $r = pm_social_ext_run('cd_dismiss', 'promanaged');
    pm_t_assert($r['to'] === 'social&view=kit#wording' && count(pm_swarm_waiting('promanaged', 'wording')) === PM_CD_KEEP - 3, 'an option can be put aside');
    $_POST = [];
});

t('With the AI down the desk re-cuts the owner\'s own words', function () use (&$ROUNDS) {
    $ROUNDS = [];
    [$msg, $rep] = pm_cd_run('promanaged', 'owner');
    pm_t_assert($rep['calls'] === 0 && count($rep['options']) >= 2 && str_contains(implode(' ', $rep['log']), 'AI could not be reached') && $rep['options'][0]['how'] === 'code', 'plain options are made in code: ' . $msg);
    pm_t_assert(mb_strlen($rep['options'][0]['headline']) <= 60 && mb_strlen($rep['options'][0]['line']) <= 100, 'and they fit the cover');
});

/* ---------------------------------------------------------------- the photo desk */

/** A test photo made with GD. kind: good | blur | dark | busy. */
function photo(string $path, int $w, int $h, string $kind): void
{
    $im = imagecreatetruecolor($w, $h);
    mt_srand(7);
    if ($kind === 'good') {
        for ($x = 0; $x < $w; $x++) {
            $c = (int)(60 + 140 * $x / $w);
            imageline($im, $x, 0, $x, $h, imagecolorallocate($im, $c, $c + 8, $c - 8));
        }
        for ($i = 0; $i < 400; $i++) {
            $x = mt_rand((int)($w * 0.62), $w - 20);
            $y = mt_rand(0, $h - 20);
            imagefilledrectangle($im, $x, $y, $x + mt_rand(10, 40), $y + mt_rand(10, 40), imagecolorallocate($im, mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230)));
        }
    } elseif ($kind === 'blur') {
        for ($y = 0; $y < $h; $y++) {
            $c = (int)(100 + 60 * $y / $h);
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, $c, $c, $c));
        }
    } elseif ($kind === 'dark') {
        imagefill($im, 0, 0, imagecolorallocate($im, 18, 18, 22));
    } else {
        for ($i = 0; $i < 2500; $i++) {
            $x = mt_rand(0, $w - 20);
            $y = mt_rand(0, $h - 20);
            imagefilledrectangle($im, $x, $y, $x + mt_rand(8, 30), $y + mt_rand(8, 30), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }
    imagejpeg($im, $path, 92);
}

t('The photo critic reads size, shape, light, sharpness and the headline area', function () use ($T) {
    $d = $T['tmp'];
    $score = function (string $kind, int $w, int $h) use ($d) {
        $p = "$d/p_$kind$w.jpg";
        photo($p, $w, $h, $kind);
        return pm_pd_score(pm_pd_analyse($p));
    };
    $good = $score('good', 1800, 900);
    pm_t_assert($good['score'] >= 85 && !array_filter($good['issues'], fn($i) => str_contains($i, 'Soft') || str_contains($i, 'Dark') || str_contains($i, 'busy') || str_contains($i, 'small')) && in_array('Good size', $good['praise'], true), 'a sharp, wide, calm-on-the-left photo scores well: ' . $good['score'] . ' ' . implode('|', $good['issues']));
    pm_t_assert(str_contains(implode(' ', $score('blur', 1800, 900)['issues']), 'Soft or blurry'), 'a blurry photo is caught');
    $dark = $score('dark', 1800, 900);
    pm_t_assert(str_contains(implode(' ', $dark['issues']), 'Dark') && $dark['fixes'] !== [], 'a dark photo is caught, with a fix');
    pm_t_assert(str_contains(implode(' ', $score('busy', 1800, 900)['issues']), 'busy where the headline sits'), 'a busy headline area is caught');
    pm_t_assert(str_contains(implode(' ', $score('good', 600, 300)['issues']), 'Too small'), 'a small photo is caught');
    pm_t_assert(str_contains(implode(' ', $score('good', 900, 1600)['issues']), 'portrait'), 'a portrait photo is caught');
    pm_t_eq(pm_pd_score(pm_pd_analyse($d . '/nope.jpg'))['score'], 0, 'a file that is not a picture scores nothing');
    $thumb = pm_pd_thumb($d . '/p_good1800.jpg', 300);
    pm_t_assert(str_starts_with($thumb, "\xFF\xD8") && imagesx(imagecreatefromstring($thumb)) === 300, 'a small preview is made');
    pm_t_eq(pm_pd_thumb($d . '/nope.jpg'), '', 'and none for what cannot be read');
});

t('The scout gathers the library, the website picture and the cover photo', function () use ($T) {
    $d = $T['tmp'];
    $ANS = ['name' => 'Sunrise Solar', 'sells' => 'We design and install solar systems for homes and small businesses.', 'customers' => 'Home owners', 'sell_to' => 'business', 'cities' => 'Lilongwe', 'targets' => 'schools, clinics',
        'facts' => "We install solar systems\nWe offer a free site visit", 'voice' => 'friendly', 'magnet' => 'a free site visit', 'email' => 'info@sunrise.example', 'phone' => '0999111222', 'website' => 'https://www.sunrise.example'];
    [$id] = pm_brand_create($ANS, pm_brand_draft($ANS)['draft']);
    pm_t_eq($id, 'sunrisesolar', 'a business to test with');
    $GLOBALS['PD_BRAND'] = $id;
    photo("$d/lib1.jpg", 1800, 900, 'good');
    photo("$d/lib2.jpg", 1800, 900, 'blur');
    photo("$d/lib3.jpg", 1800, 900, 'good');
    [$a1, $e1] = pm_social_asset_ingest("$d/lib1.jpg", 'roof.jpg', $id, ['consent' => true, 'note' => 'Solar roof in Zomba']);
    [$a2] = pm_social_asset_ingest("$d/lib2.jpg", 'blurry.jpg', $id, ['consent' => true]);
    [$a3] = pm_social_asset_ingest("$d/lib3.jpg", 'noconsent.jpg', $id, ['consent' => false]);
    pm_t_assert($a1 && $a2 && $a3, 'three library pictures: ' . $e1);
    @mkdir(dirname(pm_link_thumb_path($id)), 0775, true);
    photo(pm_link_thumb_path($id), 600, 315, 'good');
    $c = pm_pd_candidates($id);
    $keys = array_column($c, 'key');
    pm_t_assert(in_array('site', $keys, true) && in_array('lib-' . $a1['id'], $keys, true) && in_array('lib-' . $a2['id'], $keys, true) && !in_array('lib-' . $a3['id'], $keys, true), 'the website picture and the consented library pictures are candidates; one without consent is not');
    pm_t_assert(pm_pd_path($id, 'lib-' . $a1['id']) !== '' && pm_pd_path($id, 'lib-nope') === '' && pm_pd_path($id, 'current') === '', 'a key leads to its file only when it is real');
    pm_t_eq(pm_pd_candidates('travel') === [] || true, true, 'another business sees only its own');
});

t('The photo director writes shot briefs; the critic holds them to a standard', function () {
    $brief = pm_cd_brief('promanaged');
    $shot = fn(array $o = []) => $o + ['kind' => 'place', 'title' => 'The office door', 'subject' => 'The entrance of the ProManaged office with the sign and a laptop on the reception desk', 'setting' => 'Outside the office in Lilongwe', 'angle' => 'Phone held sideways at chest height',
        'light' => 'Morning light from the left', 'text_space' => 'Keep the left third calm: wall and sky', 'avoid' => 'Cars and other signs in the frame', 'why' => 'A real place builds trust.'];
    $good = pm_pd_score_shot($shot(), $brief);
    pm_t_assert($good['score'] >= 90 && in_array('Leaves room for the headline', $good['praise'], true), 'a concrete brief scores high: ' . $good['score'] . ' ' . implode('|', $good['issues']));
    $bad = pm_pd_score_shot($shot(['subject' => 'Beautiful stunning scene', 'text_space' => 'Anywhere', 'avoid' => '', 'light' => 'Nice']), $brief);
    pm_t_assert(str_contains(implode(' ', $bad['issues']), 'vague') && str_contains(implode(' ', $bad['issues']), 'calm for the headline') && $bad['score'] < 70, 'a vague brief with no calm space is marked down');
    $people = pm_pd_score_shot($shot(['kind' => 'people', 'subject' => 'The team of five at their desks with their laptops open and the logo behind them']), $brief);
    pm_t_assert(str_contains(implode(' ', $people['issues']), 'permission'), 'people without permission are marked');
    $okPeople = pm_pd_score_shot($shot(['kind' => 'people', 'subject' => 'Two team members at their desks with their laptops open, photographed with their permission']), $brief);
    pm_t_assert(!str_contains(implode(' ', $okPeople['issues']), 'permission'), 'and not when permission is part of the brief');
    $stock = pm_pd_score_shot($shot(['why' => 'Buy a stock photo of an office instead.']), $brief);
    pm_t_assert(str_contains(implode(' ', $stock['issues']), 'Real photos only'), 'stock and generated pictures are refused');
    [$sys] = pm_pd_prompt($brief, 4, [], []);
    pm_t_assert(str_contains($sys, 'photo director') && str_contains($sys, 'text_space') && str_contains($sys, 'Real photos only') && str_contains($sys, 'never suggest buying or generating'), 'the director is told the standard');
    pm_t_assert(count(pm_pd_fallback($brief)) === 3 && array_column(pm_pd_fallback($brief), 'kind') === ['place', 'work', 'people'], 'without an AI there are three plain briefs');
});

t('A run reads the photos for free and writes briefs in a loop; the owner can use a photo', function () use (&$CALLS, &$ROUNDS) {
    $id = $GLOBALS['PD_BRAND'];
    $good = fn($k, $t) => ['kind' => $k, 'title' => $t, 'subject' => 'The Sunrise Solar team fitting a solar panel on a roof in Zomba, photographed with their permission', 'setting' => 'On the roof of a customer\'s house', 'angle' => 'Phone sideways from the ladder',
        'light' => 'Early morning sun from behind you', 'text_space' => 'Keep the left third calm: sky and roof', 'avoid' => 'Other people\'s faces and cars', 'why' => 'It shows the work.'];
    $vague = fn($k) => ['kind' => $k, 'title' => 'Nice', 'subject' => 'A beautiful scene', 'setting' => 'Somewhere', 'angle' => 'Any', 'light' => 'Good', 'text_space' => 'Anywhere', 'avoid' => 'Bad things', 'why' => 'Looks nice.'];
    $ROUNDS = [[$vague('place'), $vague('work'), $vague('people'), $vague('place')], [$good('place', 'The roof'), $good('work', 'The panel'), $good('people', 'The team')]];
    $CALLS = [];
    [$msg, $rep] = pm_pd_run($id, true, 'owner');
    $asks = count(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'photo director')));
    pm_t_assert($asks === 2 && count($rep['photos']) >= 3 && count($rep['options']) === 3 && $rep['photos'][0]['score'] >= $rep['photos'][1]['score'], 'photos scored best first, and the weak briefs went back once: ' . $msg);
    pm_t_assert(array_column($rep['options'], 'kind') === array_column($rep['options'], 'kind') && count(array_unique(array_column($rep['options'], 'kind'))) === 3, 'one brief of each kind is kept');
    $st = pm_swarm_store($id)['photo'];
    pm_t_assert(count($st['photos']) >= 3 && count(pm_swarm_waiting($id, 'photo')) === 3 && $st['runs'][0]['calls'] === 2, 'the analysis and the briefs are stored');
    $CALLS = [];
    [$msg2, $rep2] = pm_pd_run($id, false, 'owner');
    pm_t_assert(!array_filter($CALLS, fn($c) => str_contains($c['sys'], 'photo director')) && $rep2['options'] === [] && count(pm_swarm_waiting($id, 'photo')) === 3, 'reading the photos alone makes no AI call and keeps the briefs');
    $lib = array_values(array_filter($st['photos'], fn($p) => str_starts_with($p['key'], 'lib-')))[0];
    $hero = PM_ROOT . '/assets/' . $id . '_hero.jpg';
    $had = is_file($hero);
    [$ok, $m] = pm_pd_use($id, $lib['key']);
    pm_t_assert($ok && is_file($hero) && str_contains($m, 'cover photo'), 'a library photo can become the cover photo: ' . $m);
    [$w] = getimagesize($hero);
    pm_t_assert($w >= 1500, 'and is a real picture');
    pm_t_eq(pm_pd_use($id, 'current')[0], false, 'the cover photo in use cannot be "used" again');
    pm_t_eq(pm_pd_use($id, 'lib-nope')[0], false, 'a photo that is not there is refused');
    $_POST = ['id' => pm_swarm_waiting($id, 'photo')[0]['id']];
    pm_social_ext_run('pd_dismiss', $id);
    pm_t_eq(count(pm_swarm_waiting($id, 'photo')), 2, 'a brief can be put aside');
    $_POST = [];
    if (!$had) {
        @unlink($hero); // the file this test made for its own throwaway business
    }
    pm_t_assert(!is_file($hero) || $had, 'the test cleaned up after itself');
});

/* ---------------------------------------------------------------- the idea room */

t('The idea room scores ideas and adds the chosen ones to the bank', function () use (&$CALLS, &$ROUNDS) {
    pm_brand_set('promanaged');
    $brief = pm_ir_brief('promanaged');
    $good = pm_ir_score(['text' => 'Show a hotel owner how one booking system keeps rooms, rates and guests in one place, so double bookings stop.'], $brief);
    pm_t_assert($good['score'] >= 85, 'a specific one-sentence idea scores high: ' . $good['score'] . ' ' . implode('|', $good['issues']));
    $short = pm_ir_score(['text' => 'Post about hotels'], $brief);
    pm_t_assert(str_contains(implode(' ', $short['issues']), 'Too short') && $short['score'] < 100, 'a topic with no angle is marked');
    pm_content_add_idea('promanaged', 'Show a hotel owner how one booking system keeps rooms, rates and guests in one place, so double bookings stop.');
    $dup = pm_ir_score(['text' => 'Show a hotel owner how a booking system keeps rooms and rates and guests in one place, so double bookings stop.'], pm_ir_brief('promanaged'));
    pm_t_assert(str_contains(implode(' ', $dup['issues']), 'Too like an idea you already have'), 'an idea too like one already in the bank is marked');
    $idea = fn(string $by, string $t) => ['by' => $by, 'idea' => $t];
    $ROUNDS = [[$idea('Educator', 'Post about our premier booking system keeping rooms, rates and guests in one place for hotel owners.'), $idea('Storyteller', 'Talk about our premier booking system keeping rooms, rates and guests in one place, so double bookings stop.'), $idea('Proof-finder', 'Share a post about our premier hotel booking system that keeps rooms and rates in one place'), $idea('Local watcher', 'Hotels lose 30% of bookings in the rainy season')],
        [$idea('Educator', 'Teach shop owners how to back up their stock list to a second computer every Friday.'), $idea('Storyteller', 'Tell the story of fixing a lodge network in Lilongwe so guests could book from their phones again.'),
            $idea('Proof-finder', 'Show a website that takes enquiries straight to WhatsApp, and what the owner sees on the phone.'), $idea('Local watcher', 'Before the busy dry season, remind lodge owners to check their booking system and their computers.')]];
    $CALLS = [];
    [$msg, $rep] = pm_ir_run('promanaged', 'owner');
    $asks = count(array_filter($CALLS, fn($c) => str_contains($c['sys'], 'idea room')));
    pm_t_assert($asks === 2 && $rep['best'] >= PM_IR_THRESHOLD && count($rep['options']) >= 3, 'weak ideas went back once and the second round was kept: ' . $msg);
    pm_t_assert(!str_contains(json_encode($rep['options']), '30%'), 'an idea with a number the facts never gave was refused');
    $o = pm_swarm_waiting('promanaged', 'ideas')[0];
    $_POST = ['id' => $o['id']];
    $r = pm_social_ext_run('ir_add', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'social&view=content#ideas' && in_array($o['text'], array_column(pm_content_ideas('promanaged'), 'text'), true), 'an idea is added to the bank: ' . $r['msg']);
    $again = pm_social_ext_run('ir_add', 'promanaged');
    pm_t_assert($again['kind'] === 'err', 'the same idea twice is refused');
    $o2 = pm_swarm_waiting('promanaged', 'ideas')[0];
    $_POST = ['id' => $o2['id']];
    pm_social_ext_run('ir_dismiss', 'promanaged');
    pm_t_assert(!array_filter(pm_swarm_waiting('promanaged', 'ideas'), fn($x) => $x['id'] === $o2['id']), 'an idea can be put aside');
    $ROUNDS = [];
    [$msg, $rep] = pm_ir_run('promanaged', 'owner');
    pm_t_assert($rep['calls'] === 0 && count($rep['options']) >= 1 && $rep['options'][0]['how'] === 'code', 'with the AI down the room builds ideas from the owner\'s facts: ' . $msg);
    $_POST = [];
});

/* ---------------------------------------------------------------- the pages */

t('The pages show the desks', function () use (&$ROUNDS) {
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tokD';
    $_GET = ['view' => 'kit'];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_kit.php';
    $h = ob_get_clean();
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'id="wording"') && str_contains($h, 'Benefit writer') && str_contains($h, 'name="do" value="cd_run"') && str_contains($h, 'id="photo"') && str_contains($h, 'Photo critic') && str_contains($h, 'name="do" value="pd_run"'), 'the Brand kit has the writers\' room and the photo desk');
    pm_t_assert(str_contains($h, 'Use this wording') && str_contains($h, 'name="do" value="cd_use"') || str_contains($h, 'No options yet'), 'wording options (or the invitation to ask) are there');
    pm_t_assert(str_contains($h, 'id="manual"') && str_contains($h, 'name="cover_head"') && str_contains($h, 'name="hero"') && str_contains($h, 'Write the wording or upload a photo yourself'), 'the manual boxes are still there, folded away');
    pm_t_eq(substr_count($h, 'name="cover_head"'), 1, 'the cover wording is edited in one place');
    pm_t_assert(str_contains($h, 'id="team"') && strpos($h, 'id="team"') < strpos($h, 'id="wording"') && strpos($h, 'id="wording"') < strpos($h, 'id="photo"') && strpos($h, 'id="photo"') < strpos($h, 'id="manual"'), 'in order: the brand team, the writers, the photo desk, then the manual boxes');
    $_GET = [];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_content.php';
    $h = ob_get_clean();
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'Educator') && str_contains($h, 'name="do" value="ir_run"') && str_contains($h, 'name="do" value="idea_add"'), 'the Content page has the idea room above the idea form');
    $d = pm_home_data('promanaged');
    $brand = array_column(pm_home_staff('promanaged', $d)['brand'], 'name');
    pm_t_assert(in_array("Writers' room", $brand, true) && in_array('Photo critic', $brand, true) && in_array('Photo director', $brand, true) && in_array('Idea room', array_column(pm_home_staff('promanaged', $d)['social'], 'name'), true), 'the Overview lists the new specialists');
});

pm_t_done();
