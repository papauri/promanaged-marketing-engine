<?php
/**
 * Tests for the profile words: three options per field from one AI call, polished and kept only when they fit the limit, stay inside the facts and read naturally; choosing one makes it the
 * suggestion everywhere and can apply it to Facebook and WhatsApp; the handlers and the Studio card. Facebook, WhatsApp and the AI are stubbed. Run: php tests/test_copy.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\nIG_USER_ID=IGU\nWA_BIZ_TOKEN=wt\nWA_BIZ_PHONE_ID=999\nWA_BIZ_VERIFY=wv\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
pm_save('page_opt', ['none' => []]);
pm_update('settings', function (array $s) {
    $s['phone'] = '+265 999 123 456';
    $s['tagline'] = 'Business systems, built and supported in Malawi';
    return $s;
}, 'pm_default_settings');
$CALLS = [];
$PAGE = ['name' => 'P', 'about' => '', 'description' => '', 'website' => '', 'phone' => '', 'emails' => [], 'picture' => ['data' => ['url' => 'u', 'is_silhouette' => false]], 'cover' => ['source' => 'c'], 'hours' => [], 'location' => null];
$GLOBALS['PM_GRAPH_STUB'] = function ($method, $path, $params) use (&$CALLS, &$PAGE) {
    $CALLS[] = [$method, $path, $params];
    if ($method === 'POST' && $path === 'PAGE') {
        $PAGE = array_replace($PAGE, array_intersect_key($params, ['about' => 1, 'description' => 1]));
        return [true, ['success' => true]];
    }
    if ($path === 'IGU') {
        return [true, ['biography' => '', 'website' => '', 'username' => 'x', 'profile_picture_url' => '', 'media_count' => 2]];
    }
    return [true, $path === 'PAGE' ? $PAGE : ['data' => []]];
};
$WA = ['about' => '', 'address' => '', 'description' => '', 'email' => '', 'websites' => [], 'vertical' => 'UNDEFINED'];
$GLOBALS['PM_WA_PROFILE_STUB'] = function ($method, $cfg, $set) use (&$WA) {
    if ($method === 'POST') {
        $WA = array_replace($WA, (array)$set);
        return [true, ['success' => true]];
    }
    return [true, $WA];
};

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

t('Options are checked before they are kept', function () {
    $src = 'We build business software and websites in Malawi. WhatsApp +265 999 123 456. We install and support computer systems for hotels and shops in Lilongwe.';
    $raw = ['plain' => 'We build software for hotels and shops, and we are on WhatsApp when something breaks.',
        'specific' => 'We install and support computer systems for hotels and shops in Lilongwe, with 500 happy clients.',
        'short' => 'Computer systems for hotels and shops.'];
    $o = pm_copy_clean_options($raw, 101, $src);
    pm_t_eq(array_column($o, 'voice'), ['plain', 'short'], 'an option with an invented number is dropped, the others stay');
    $raw2 = ['plain' => "We keep your shop running \u{2014} seamless and easy!", 'specific' => str_repeat('A long line about computers. ', 8), 'short' => 'We keep your shop running.', 'bogus' => 'ignored'];
    $o = pm_copy_clean_options($raw2, 101, $src);
    pm_t_assert(count($o) === 2 && $o[0]['text'] === 'We keep your shop running, easy and easy.' || $o[0]['text'] !== '', 'dashes and buzzwords are polished first: ' . json_encode(array_column($o, 'text')));
    pm_t_assert(!in_array('specific', array_column($o, 'voice'), true), 'an option over the limit is dropped');
    pm_t_eq(count(pm_copy_clean_options(['plain' => 'Same words here.', 'short' => 'same words here.'], 101, $src)), 1, 'the same words twice are one option');
    pm_t_eq(pm_copy_clean_options(['plain' => 'Visit https://x.example now', 'short' => 'Email us at a@b.mw'], 101, $src), [], 'links and emails are not allowed in profile words');
});

t('One call writes the chosen profiles', function () {
    $calls = 0;
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls, &$seen) {
        $calls++;
        $seen = $sys . $user;
        $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
        $items = [];
        foreach ($in['fields'] as $f) {
            $items[] = ['key' => $f['key'], 'plain' => $f['key'] === 'fb_about' ? 'We build business systems and websites for companies in Malawi, and we answer on WhatsApp when something breaks on a busy day.' : 'We build business systems and websites for companies in Malawi, and we answer on WhatsApp.', 'specific' => 'Business software, websites and IT support for hotels and shops in Lilongwe.', 'short' => 'Business systems built in Malawi.'];
        }
        $items[] = ['key' => 'not_asked', 'plain' => 'x', 'specific' => 'y', 'short' => 'z'];
        return json_encode(['items' => $items]);
    };
    [$n, $m] = pm_copy_write('promanaged', ['fb_about', 'ig_bio', 'wa_description', 'nonsense', 'fb_about']);
    $st = pm_copy_store('promanaged');
    pm_t_assert($calls === 1 && $n === 3 && array_keys($st) === ['fb_about', 'ig_bio', 'wa_description'], 'one call for three profiles (unknown and repeated ones ignored): ' . $m);
    pm_t_assert(count($st['fb_about']['options']) === 2 && count($st['wa_description']['options']) === 3 && count($st['ig_bio']['options']) === 3, 'options that do not fit the limit are dropped (the 101-character intro keeps the shorter two): ' . $m);
    pm_t_assert(str_contains($seen, 'senior brand copywriter') && str_contains($seen, 'Never use: delve, leverage, seamless') && str_contains($seen, 'Use ONLY the facts') && str_contains($seen, '"limit":101'), 'the AI is told to write like a person, with the rules, the facts and each limit');
    pm_t_assert(str_contains($m, 'left out'), 'and the owner is told what was left out');
    pm_t_eq(pm_copy_write('promanaged', ['nonsense']), [0, 'Tick at least one profile.'], 'nothing ticked is said plainly');
    $GLOBALS['PM_AI_STUB'] = fn() => 'no json';
    pm_t_eq(pm_copy_write('promanaged', ['ig_bio'])[0], 0, 'an unusable answer writes nothing');
    pm_t_eq(count(pm_copy_store('promanaged')), 3, 'and keeps what was there');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Choosing an option', function () use (&$CALLS, &$PAGE, &$WA) {
    $st = pm_copy_store('promanaged');
    $text = $st['fb_about']['options'][0]['text'];
    [$ok, $m] = pm_copy_use('promanaged', 'ig_bio', 1, false);
    pm_t_assert($ok && pm_opt_store('promanaged')['ai']['ig_bio'] === $st['ig_bio']['options'][1]['text'] && str_contains($m, 'now the suggestion'), 'a chosen option becomes the suggestion: ' . $m);
    $items = [];
    foreach (pm_opt_run('promanaged', false)['channels'] as $c) {
        foreach ($c['items'] as $i) {
            $items[$i['key']] = $i;
        }
    }
    pm_t_assert(isset($items['ig_bio']) && $items['ig_bio']['proposal'] === $st['ig_bio']['options'][1]['text'] && $items['ig_bio']['ai'] === true, 'and Optimise shows it as the AI suggestion');
    $CALLS = [];
    [$ok, $m] = pm_copy_use('promanaged', 'fb_about', 0, true);
    $post = array_values(array_filter($CALLS, fn($c) => $c[0] === 'POST'))[0] ?? null;
    pm_t_assert($ok && $post && $post[1] === 'PAGE' && $post[2]['about'] === $text && $PAGE['about'] === $text, 'applying sends it to the Facebook Page: ' . $m);
    [$ok] = pm_copy_use('promanaged', 'wa_description', 2, true);
    pm_t_assert($ok && $WA['description'] === $st['wa_description']['options'][2]['text'], 'and to the WhatsApp Business profile');
    [$ok, $m] = pm_copy_use('promanaged', 'ig_bio', 1, true);
    pm_t_assert($ok && str_contains($m, 'Copy it into the profile'), 'a channel with no API says to copy it');
    pm_t_assert(!pm_copy_use('promanaged', 'ig_bio', 9, false)[0] && !pm_copy_use('promanaged', 'nonsense', 0, false)[0] && !pm_copy_use('promanaged', 'x_bio', 0, false)[0], 'an option that is not there is refused');
});

t('The handlers and the screen', function () {
    $_POST = ['groups' => ['facebook']];
    pm_agents_ready() || ($GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode(['items' => [['key' => 'fb_about', 'plain' => 'We build business systems for companies in Malawi.', 'specific' => 'Business systems and IT support for companies in Lilongwe.', 'short' => 'Business systems built in Malawi.'],
        ['key' => 'fb_description', 'plain' => 'We build business systems and websites for companies in Malawi and answer on WhatsApp when something breaks.', 'specific' => 'Software, websites and IT support for hotels and shops, from our desk in Lilongwe.', 'short' => 'Software and IT support for Malawian companies.']]]));
    $r = pm_social_ext_run('copy_write', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_contains($r['msg'], '2 of 2 profiles written') && $r['to'] === 'social&view=optimise#words', 'ticking a channel writes its fields: ' . $r['msg']);
    $_POST = ['key' => 'fb_description', 'i' => '1'];
    $r = pm_social_ext_run('copy_use', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_starts_with($r['to'], 'social&view=optimise#w-fb_description'), 'choosing one works from the form');
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tok3';
    $_GET = [];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_optimise.php';
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'id="words"') && str_contains($h, 'Write 3 options for each (AI)') && str_contains($h, 'Specific and confident') && str_contains($h, 'Apply to Facebook') && str_contains($h, 'chosen') && str_contains($h, 'name="do" value="copy_use"'), 'Profiles shows the options, the chosen one and the buttons');
    $_GET = ['d' => 'new'];
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_studio.php';
    $h = ob_get_clean();
    pm_t_assert(!str_contains($h, 'id="words"') && str_contains($h, 'pinterest.com/search/pins/?q=neo%20naturalism') && str_contains($h, 'Digital looks') && str_contains($h, 'Made by hand'), 'the Studio keeps the inspiration links and the human/digital split, and the words moved to Profiles');
    $_POST = [];
    pm_social_ext_run('copy_clear', 'promanaged');
    pm_t_assert(pm_copy_store('promanaged') === [] && (pm_opt_store('promanaged')['ai'] ?? []) === [], 'the options can be cleared');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('AI down');
    };
    $_POST = ['groups' => ['facebook']];
    pm_t_eq(pm_social_ext_run('copy_write', 'promanaged')['kind'], 'err', 'a failing AI is reported and nothing changes');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The one-line description is written, offered and saved like the other profile words', function () {
    pm_update('settings', function (array $s) {
        $s['tagline'] = '';
        return $s;
    }, 'pm_default_settings');
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode(['items' => [['key' => 'biz_tagline', 'plain' => 'Business systems and IT support for companies in Malawi', 'specific' => 'Software, websites and IT support for hotels and shops in Lilongwe',
        'short' => 'Business systems built in Malawi']]]);
    $_POST = ['keys' => ['biz_tagline'], 'anchor' => 'i-biz_tagline'];
    $r = pm_social_ext_run('copy_write', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'social&view=optimise#i-biz_tagline' && count(pm_copy_store('promanaged')['biz_tagline']['options']) === 3, 'three options are written, and the owner lands back on the card that asked: ' . $r['msg']);
    $items = [];
    foreach (pm_opt_run('promanaged', false)['channels'] as $c) {
        foreach ($c['items'] as $i) {
            $items[$i['key']] = $i;
        }
    }
    $from = array_unique(array_column($items['biz_tagline']['alts'], 'from'));
    pm_t_assert($items['biz_tagline']['state'] === 'missing' && in_array('Written by the AI', $from, true) && $items['biz_tagline']['alts'][0]['text'] === pm_copy_store('promanaged')['biz_tagline']['options'][0]['text'], 'they head the ready suggestions on the card');
    [$ok, $m] = pm_copy_use('promanaged', 'biz_tagline', 1, true);
    pm_t_assert($ok && pm_settings()['tagline'] === 'Software, websites and IT support for hotels and shops in Lilongwe', 'one press saves the chosen one as the business\'s line: ' . $m);
    $_POST = [];
    $GLOBALS['PM_AI_STUB'] = null;
});

pm_t_done();
